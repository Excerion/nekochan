<?php
/**
 * Yotsuba Imageboard - Main Front Controller & Views
 */
require_once __DIR__ . '/config.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

$pdo = get_db();
$moderatorMode = moderator_mode_enabled();
$adminMode = admin_mode_enabled();
reject_if_banned();

// Sidebar filter form: /?filter=1&boards[]=a&boards[]=b -> /a+b/
if (isset($_GET['filter'])) {
    $requested = $_GET['boards'] ?? [];
    if (!is_array($requested)) {
        $requested = [$requested];
    }

    $validNames = $pdo->query("SELECT name FROM boards ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    $requestedSet = [];
    foreach ($requested as $name) {
        $name = strtolower(trim((string)$name));
        if ($name !== '') {
            $requestedSet[$name] = true;
        }
    }

    $selected = [];
    foreach ($validNames as $name) {
        if (isset($requestedSet[$name])) {
            $selected[] = $name;
        }
    }

    if ($selected === []) {
        header('Location: ' . url_board_expression('*'), true, 302);
    } else {
        header('Location: ' . url_board_expression(implode('+', $selected)), true, 302);
    }
    exit;
}

$renderModeratorControls = static function (array $post) use ($moderatorMode): void {
    if (!$moderatorMode) {
        return;
    }

    $postId = (int)$post['id'];
    echo '<div class="mod-post-controls" aria-label="Moderator controls for post ' . $postId . '">';
    echo '<label><input form="mod-actions" type="checkbox" name="post_ids[]" value="' . $postId . '" id="mod-post-' . $postId . '"> Post #' . $postId . '</label>';
    echo '</div>';
};
$renderPostIp = static function (array $post) use ($moderatorMode): void {
    if (!$moderatorMode) {
        return;
    }
    echo '<span class="mod-post-ip">IP: ' . htmlspecialchars(format_poster_ip($post['poster_ip'] ?? '')) . '</span>';
};
$moderatorPaginationUrl = static function (int $targetPage) use ($moderatorMode, $adminMode): string {
    if (!$moderatorMode && !$adminMode) {
        return '?page=' . $targetPage;
    }

    $query = [];
    if (isset($_GET['board']) && is_string($_GET['board'])) {
        $query['board'] = $_GET['board'];
    }
    if (isset($_GET['thread']) && is_scalar($_GET['thread']) && ctype_digit((string)$_GET['thread'])) {
        $query['thread'] = (int)$_GET['thread'];
    }
    $query['page'] = $targetPage;
    return $adminMode ? admin_url($query) : moderator_url($query);
};

// --- ROUTE RESOLUTION ---
$viewMode = 'home'; // 'home', 'board', 'aggregate', 'thread'
$currentThreadId = null;
$boardFilter = [];
$excludedBoardFilter = [];
$boardExpression = '';
$allBoardsExpression = false;
$returnBoardExpression = null;
$targetPostBoard = 'random'; // Default target board for posting
$page = max(1, (int)($_GET['page'] ?? 1));

// Parse query parameters or clean path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = get_base_path();
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove base path from current path
if (strpos($path, $basePath) === 0) {
    $subPath = substr($path, strlen($basePath));
} else {
    $subPath = ltrim($path, '/');
}
$subPath = trim($subPath, '/');

// Keep the explicit all-board aggregate as the canonical homepage URL.
if ($subPath === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url_board_expression('*'), true, 302);
    exit;
}

$applyBoardExpression = function (string $rawExpression) use (
    &$viewMode,
    &$boardFilter,
    &$excludedBoardFilter,
    &$boardExpression,
    &$allBoardsExpression,
    &$targetPostBoard
): void {
    $parsedExpression = parse_board_expression(trim(rawurldecode($rawExpression)));
    $boardFilter = $parsedExpression['included'];
    $excludedBoardFilter = $parsedExpression['excluded'];
    $allBoardsExpression = $parsedExpression['includes_all'];
    $expressionParts = $allBoardsExpression ? ['*'] : $boardFilter;
    foreach ($excludedBoardFilter as $excludedBoard) {
        $expressionParts[] = '-' . $excludedBoard;
    }
    $boardExpression = implode('+', $expressionParts);

    // Keep the homepage URL as "/" while giving "/*/" the same view.
    if ($allBoardsExpression && empty($excludedBoardFilter)) {
        $viewMode = 'home';
        $targetPostBoard = 'random';
    } elseif (
        $allBoardsExpression
        || !empty($excludedBoardFilter)
        || count($boardFilter) > 1
        || empty($boardFilter)
    ) {
        $viewMode = 'aggregate';
        $targetPostBoard = $boardFilter[0] ?? 'random';
    } elseif (count($boardFilter) === 1) {
        $viewMode = 'board';
        $targetPostBoard = $boardFilter[0];
    }
};

if ($subPath === 'boards' || (isset($_GET['view']) && $_GET['view'] === 'boards')) {
    $viewMode = 'boardlist';
} elseif (isset($_GET['thread']) && is_numeric($_GET['thread'])) {
    $viewMode = 'thread';
    $currentThreadId = (int)$_GET['thread'];
    if (isset($_GET['board']) && trim($_GET['board']) !== '') {
        $returnBoardExpression = trim(rawurldecode($_GET['board']));
    }
} elseif (preg_match('#^(.+)/res/(\d+)#', $subPath, $m)) {
    $viewMode = 'thread';
    $returnBoardExpression = trim(rawurldecode($m[1]));
    $currentThreadId = (int)$m[2];
} elseif (preg_match('#^res/(\d+)#', $subPath, $m)) {
    $viewMode = 'thread';
    $currentThreadId = (int)$m[1];
} elseif (isset($_GET['board']) && trim($_GET['board']) !== '') {
    $applyBoardExpression($_GET['board']);
} elseif ($subPath !== '' && $subPath !== 'index.php') {
    // Check if clean URL matches /board1/, /board1+board2/, or a boolean expression.
    $parts = explode('/', $subPath);
    $firstSegment = rawurldecode($parts[0]);
    if ($firstSegment !== '' && !in_array($firstSegment, ['install.php', 'post.php', 'board.php', 'mod.php', 'admin.php', 'uploads', 'data', 'style.css', 'script.js', 'boards'], true)) {
        $applyBoardExpression($firstSegment);
    }
}

// Global boards list (for bottom 4-column display & top sidebar)
$allBoardsStmt = $pdo->query("SELECT id, name, description, nsfw FROM boards ORDER BY name ASC");
$allBoards = $allBoardsStmt->fetchAll();
$allBoardNames = array_column($allBoards, 'name');
$adminBoard = null;
$adminOtherBoards = [];
$adminPostCount = 0;
if ($adminMode && $viewMode === 'board' && !empty($boardFilter[0])) {
    foreach ($allBoards as $candidateBoard) {
        if ($candidateBoard['name'] === $boardFilter[0]) {
            $adminBoard = $candidateBoard;
        } else {
            $adminOtherBoards[] = $candidateBoard;
        }
    }
    if ($adminBoard) {
        $adminCountStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE board_id = :id');
        $adminCountStmt->execute([':id' => (int)$adminBoard['id']]);
        $adminPostCount = (int)$adminCountStmt->fetchColumn();
    }
}
$filterSourceExpression = ($viewMode === 'thread')
    ? $returnBoardExpression
    : $boardExpression;
$selectedFilterBoards = selected_filter_boards($filterSourceExpression, $allBoardNames);

// Visible posts accumulator for the right sidebar
$visiblePostsForSidebar = [];

// Track view data
$threadsData = [];
$singleThread = null;
$totalPages = 1;
$pageTitle = 'Home - Aggregate';

if ($viewMode === 'thread') {
    // --- THREAD VIEW ---
    $tStmt = $pdo->prepare("
        SELECT p.*, b.name AS board_name, b.nsfw AS board_nsfw
        FROM posts p
        JOIN boards b ON p.board_id = b.id
        WHERE p.id = :id AND p.thread_id IS NULL
    ");
    $tStmt->execute([':id' => $currentThreadId]);
    $singleThread = $tStmt->fetch();

    if (!$singleThread) {
        http_response_code(404);
        die("Thread not found or deleted.");
    }

    $targetPostBoard = $singleThread['board_name'];
    $pageTitle = '/' . htmlspecialchars($targetPostBoard) . '/ - ' . ($singleThread['title'] ? htmlspecialchars($singleThread['title']) : 'Thread #' . $currentThreadId);

    // Fetch all comments in this thread (both Level 2 and Level 3)
    $cStmt = $pdo->prepare("
        SELECT p.*, b.name AS board_name
        FROM posts p
        JOIN boards b ON p.board_id = b.id
        WHERE p.thread_id = :tid
        ORDER BY p.created_at ASC
    ");
    $cStmt->execute([':tid' => $currentThreadId]);
    $allComments = $cStmt->fetchAll();

    // Group into Level 2 (top-level) and Level 3 (reply-to-reply)
    $commentTrees = [];
    $level3Comments = [];

    foreach ($allComments as $c) {
        if ($c['reply_to_id'] === null) {
            $commentTrees[$c['id']] = [
                'op_reply' => $c,
                'replies' => []
            ];
        } else {
            $level3Comments[] = $c;
        }
    }

    // Attach Level 3 comments to their Level 2 parents
    foreach ($level3Comments as $c) {
        $parentId = $c['reply_to_id'];
        if (isset($commentTrees[$parentId])) {
            $commentTrees[$parentId]['replies'][] = $c;
        } else {
            // In case parent was deleted or not found, display as top level
            $commentTrees[$c['id']] = [
                'op_reply' => $c,
                'replies' => []
            ];
        }
    }

    // Accumulate visible posts for sidebar (OPs only)
    $visiblePostsForSidebar[] = $singleThread;
} elseif ($viewMode === 'boardlist') {
    $pageTitle = 'Boards';
} else {
    // --- BOARD, AGGREGATE, OR HOMEPAGE VIEW ---
    $whereClause = "WHERE p.thread_id IS NULL";
    $params = [];

    if ($viewMode === 'board') {
        $whereClause .= " AND b.name = :bname";
        $params[':bname'] = $boardFilter[0];
        $pageTitle = '/' . htmlspecialchars($boardFilter[0]) . '/';
    } elseif ($viewMode === 'aggregate') {
        $boardConditions = [];

        if (!$allBoardsExpression && !empty($boardFilter)) {
            $inPlaceholders = [];
            foreach ($boardFilter as $idx => $bname) {
                $ph = ':b_' . $idx;
                $inPlaceholders[] = $ph;
                $params[$ph] = $bname;
            }
            $boardConditions[] = 'b.name IN (' . implode(',', $inPlaceholders) . ')';
        }

        if (!empty($excludedBoardFilter)) {
            $excludePlaceholders = [];
            foreach ($excludedBoardFilter as $idx => $bname) {
                $ph = ':exclude_b_' . $idx;
                $excludePlaceholders[] = $ph;
                $params[$ph] = $bname;
            }
            $boardConditions[] = 'b.name NOT IN (' . implode(',', $excludePlaceholders) . ')';
        }

        if (!empty($boardConditions)) {
            $whereClause .= ' AND ' . implode(' AND ', $boardConditions);
        }
        $pageTitle = '/' . htmlspecialchars($boardExpression) . '/';
    } else {
        $pageTitle = 'Homepage - All Boards';
        $targetPostBoard = 'random';
    }

    // Count total threads for pagination
    $countSql = "
        SELECT COUNT(p.id) AS total
        FROM posts p
        JOIN boards b ON p.board_id = b.id
        $whereClause
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalThreads = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalThreads / POSTS_PER_PAGE));
    $offset = ($page - 1) * POSTS_PER_PAGE;

    // Fetch threads for current page
    $threadsSql = "
        SELECT p.*, b.name AS board_name
        FROM posts p
        JOIN boards b ON p.board_id = b.id
        $whereClause
        ORDER BY p.bumped_at DESC, p.id DESC
        LIMIT " . POSTS_PER_PAGE . " OFFSET " . $offset . "
    ";
    $threadsStmt = $pdo->prepare($threadsSql);
    $threadsStmt->execute($params);
    $threadsList = $threadsStmt->fetchAll();

    // For each thread, fetch total replies count and 5 most recent comments
    foreach ($threadsList as $thread) {
        $visiblePostsForSidebar[] = $thread;

        // Total replies count
        $cntStmt = $pdo->prepare("SELECT COUNT(id) FROM posts WHERE thread_id = :tid");
        $cntStmt->execute([':tid' => $thread['id']]);
        $replyCount = (int)$cntStmt->fetchColumn();

        $preview = overview_comment_preview($pdo, (int)$thread['id']);

        $threadsData[] = [
            'op' => $thread,
            'reply_count' => $replyCount,
            'omitted_count' => max(0, $replyCount - $preview['shown']),
            'trees' => $preview['trees']
        ];
    }
}

// Sort visible threads for sidebar the same way the current page is ordered:
// by the most recent bump, not by the OP's creation time.
usort($visiblePostsForSidebar, function ($a, $b) {
    $bumpOrder = strtotime($b['bumped_at'] ?? $b['created_at']) <=> strtotime($a['bumped_at'] ?? $a['created_at']);
    return $bumpOrder !== 0 ? $bumpOrder : ((int)$b['id'] <=> (int)$a['id']);
});
$sidebarRecentPosts = array_slice($visiblePostsForSidebar, 0, SIDEBAR_RECENT_POSTS_LIMIT);

// Use Yotsuba B colours only when every board represented by this view is SFW.
$themeNsfw = true;
if ($viewMode === 'thread') {
    $themeNsfw = (int)($singleThread['board_nsfw'] ?? 1) === 1;
} else {
    $themeBoards = [];
    foreach ($allBoards as $board) {
        $boardName = $board['name'];
        $isExcluded = in_array($boardName, $excludedBoardFilter, true);

        if ($viewMode === 'board') {
            $isIncluded = $boardName === ($boardFilter[0] ?? '');
        } elseif ($viewMode === 'home' || $allBoardsExpression || empty($boardFilter)) {
            $isIncluded = true;
        } else {
            $isIncluded = in_array($boardName, $boardFilter, true);
        }

        if ($isIncluded && !$isExcluded) {
            $themeBoards[] = $board;
        }
    }

    if (!empty($themeBoards)) {
        $themeNsfw = false;
        foreach ($themeBoards as $board) {
            if ((int)($board['nsfw'] ?? 0) === 1) {
                $themeNsfw = true;
                break;
            }
        }
    }
}
$themeClass = $themeNsfw ? 'yotsuba' : 'yotsuba-b';

// Pre-fill query values for thread reply button jump
$prefillQuote = isset($_GET['quote']) ? (int)$_GET['quote'] : null;
$prefillReplyTo = isset($_GET['reply_to']) ? (int)$_GET['reply_to'] : null;
$threadLinkContext = $boardExpression !== ''
    ? $boardExpression
    : ($returnBoardExpression !== null && $returnBoardExpression !== '' ? $returnBoardExpression : null);
$formReturnBoardExpression = $viewMode === 'thread'
    ? $returnBoardExpression
    : $threadLinkContext;

$returnLinks = [];
if ($viewMode === 'thread') {
    $nativeBoard = $singleThread['board_name'] ?? $targetPostBoard;
    $nativeUrl = url_board($nativeBoard);
    $nativeLabel = '/' . $nativeBoard . '/';
    $multiReturn = false;

    if ($returnBoardExpression !== null && $returnBoardExpression !== '') {
        $parsedReturn = parse_board_expression($returnBoardExpression);
        $multiReturn = $parsedReturn['includes_all']
            || !empty($parsedReturn['excluded'])
            || count($parsedReturn['included']) > 1;
        if ($multiReturn) {
            $returnLinks[] = [
                'url' => url_board_expression($returnBoardExpression),
                'label' => '/' . $returnBoardExpression . '/',
            ];
            $returnLinks[] = [
                'url' => $nativeUrl,
                'label' => $nativeLabel,
            ];
        } else {
            $returnLinks[] = [
                'url' => url_board_expression($returnBoardExpression),
                'label' => '/' . $returnBoardExpression . '/',
            ];
        }
    } else {
        $returnLinks[] = [
            'url' => $nativeUrl,
            'label' => $nativeLabel,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Yotsuba</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(get_base_path()) ?>style.css">
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(get_base_path()) ?>favicon.svg">
    <link rel="alternate icon" href="<?= htmlspecialchars(get_base_path()) ?>favicon.png">
</head>
<body class="<?= htmlspecialchars($themeClass) ?>">

    <!-- Top Navigation Bar -->
    <div id="top-nav" class="board-nav">
        [
        <a href="<?= htmlspecialchars(url_board_expression('*')) ?>">all</a>
        <?php foreach ($allBoards as $b): ?>
            / <a href="<?= htmlspecialchars(url_board($b['name'])) ?>"><?= htmlspecialchars($b['name']) ?></a>
        <?php endforeach; ?>
        ]
    </div>
    <?php if ($adminMode): ?>
        <div class="moderator-banner">
            <span><strong>Admin mode</strong> — open a single board to rename it, change its description or NSFW flag, delete it, or merge it into another board.</span>
            <form method="post" action="<?= htmlspecialchars(admin_url()) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="mod-logout-button">Log out</button>
            </form>
        </div>
        <?php if (($adminFlashMessage ?? '') !== ''): ?>
            <div class="mod-status-message" role="status"><?= htmlspecialchars($adminFlashMessage) ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($moderatorMode): ?>
        <div class="moderator-banner">
            <span><strong>Moderator mode</strong> — deleting an OP removes its entire thread; deleting only its file keeps the thread.</span>
            <form method="post" action="<?= htmlspecialchars(moderator_url()) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($moderatorCsrfToken) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="mod-logout-button">Log out</button>
            </form>
        </div>
        <?php if ($moderatorFlashMessage !== ''): ?>
            <div class="mod-status-message" role="status"><?= htmlspecialchars($moderatorFlashMessage) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Page Header / Title -->
    <div class="header">
        <h1 class="board-title">
            <?php if ($viewMode === 'thread'): ?>
                <a href="<?= htmlspecialchars(url_board($targetPostBoard)) ?>">/<?= htmlspecialchars($targetPostBoard) ?>/</a>
            <?php elseif ($viewMode === 'board'): ?>
                /<?= htmlspecialchars($boardFilter[0]) ?>/
            <?php elseif ($viewMode === 'aggregate'): ?>
                /<?= htmlspecialchars($boardExpression) ?>/
            <?php elseif ($viewMode === 'boardlist'): ?>
                Boards
            <?php else: ?>
                /all/ - All Boards
            <?php endif; ?>
        </h1>
        <div class="board-subtitle">
            <?php if ($viewMode === 'thread'): ?>
                Thread #<?= (int)$singleThread['id'] ?>
            <?php elseif ($viewMode === 'board'): ?>
                <?php
                    $bDesc = '';
                    foreach ($allBoards as $b) {
                        if ($b['name'] === $boardFilter[0]) {
                            $bDesc = $b['description'];
                            break;
                        }
                    }
                    echo htmlspecialchars($bDesc);
                ?>
            <?php elseif ($viewMode === 'aggregate'): ?>
                <?php if ($allBoardsExpression && !empty($excludedBoardFilter)): ?>
                    aggregate view: all except <?= htmlspecialchars(implode(', ', $excludedBoardFilter)) ?>
                <?php elseif (!$allBoardsExpression && !empty($excludedBoardFilter)): ?>
                    aggregate view: <?= htmlspecialchars(implode(', ', $boardFilter)) ?> except <?= htmlspecialchars(implode(', ', $excludedBoardFilter)) ?>
                <?php else: ?>
                    Aggregated view: <?= htmlspecialchars(implode(', ', $boardFilter)) ?>
                <?php endif; ?>
            <?php elseif ($viewMode === 'boardlist'): ?>
                Every board, in alphabetical order
            <?php else: ?>
                Aggregate of all boards
            <?php endif; ?>
        </div>
    </div>

    <hr class="header-divider">

    <!-- Main Container with Sidebar -->
    <?php if ($viewMode !== 'boardlist'): ?>
    <div class="layout-wrapper">
        
        <!-- Center Content: Threads -->
        <main class="content-area">

            <?php if ($adminBoard): ?>
                <section class="admin-board-panel" aria-label="Board administration">
                    <h2>Board settings for /<?= htmlspecialchars($adminBoard['name']) ?>/</h2>
                    <p class="admin-board-note"><?= (int)$adminPostCount ?> post<?= $adminPostCount === 1 ? '' : 's' ?> on this board. Deleting or merging removes the board.</p>
                    <form method="post" action="<?= htmlspecialchars($adminActionUrl) ?>" class="admin-board-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken) ?>">
                        <input type="hidden" name="action" value="update_board">
                        <input type="hidden" name="board_id" value="<?= (int)$adminBoard['id'] ?>">
                        <label class="admin-field">
                            <span>Name</span>
                            <input type="text" name="name" required maxlength="32" pattern="[A-Za-z0-9_-]+" value="<?= htmlspecialchars($adminBoard['name']) ?>" class="input-text">
                        </label>
                        <label class="admin-field">
                            <span>Description</span>
                            <textarea name="description" rows="3" maxlength="2000" class="textarea-box"><?= htmlspecialchars((string)$adminBoard['description']) ?></textarea>
                        </label>
                        <label class="admin-nsfw">
                            <input type="checkbox" name="nsfw" value="1"<?= ((int)$adminBoard['nsfw'] === 1) ? ' checked' : '' ?>>
                            NSFW
                        </label>
                        <button type="submit" class="btn-submit">Save board</button>
                    </form>
                    <div class="admin-board-row">
                        <form method="post" action="<?= htmlspecialchars($adminActionUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken) ?>">
                            <input type="hidden" name="action" value="delete_board">
                            <input type="hidden" name="board_id" value="<?= (int)$adminBoard['id'] ?>">
                            <button type="submit" class="btn-submit admin-delete-button"
                                onclick="return confirm(<?= htmlspecialchars(json_encode('Delete /' . $adminBoard['name'] . '/ and all ' . (int)$adminPostCount . ' post(s) on it? This cannot be undone.'), ENT_QUOTES) ?>);">
                                Delete board
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars($adminActionUrl) ?>" class="admin-merge-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken) ?>">
                            <input type="hidden" name="action" value="merge_board">
                            <input type="hidden" name="board_id" value="<?= (int)$adminBoard['id'] ?>">
                            <button type="submit" class="btn-submit"<?= $adminOtherBoards === [] ? ' disabled' : '' ?>
                                onclick="return confirmAdminMerge(this.form, <?= htmlspecialchars(json_encode($adminBoard['name']), ENT_QUOTES) ?>);">
                                Merge into
                            </button>
                            <select name="target_board"<?= $adminOtherBoards === [] ? ' disabled' : '' ?> aria-label="Board to merge into">
                                <?php if ($adminOtherBoards === []): ?>
                                    <option value="">No other boards</option>
                                <?php else: ?>
                                    <?php foreach ($adminOtherBoards as $otherBoard): ?>
                                        <option value="<?= (int)$otherBoard['id'] ?>">/<?= htmlspecialchars($otherBoard['name']) ?>/</option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </form>
                    </div>
                </section>
                <script>
                    function confirmAdminMerge(form, sourceName) {
                        var select = form.elements.target_board;
                        if (!select || select.disabled || select.value === '') {
                            return false;
                        }
                        var option = select.options[select.selectedIndex];
                        var targetName = option ? option.text : '';
                        return confirm('Move every post from /' + sourceName + '/ to ' + targetName + ' and then delete /' + sourceName + '/? This cannot be undone.');
                    }
                </script>
            <?php endif; ?>

            <?php if ($viewMode === 'thread'): ?>
                <!-- THREAD VIEW -->
                <div class="thread-container" id="t<?= (int)$singleThread['id'] ?>">
                    <div class="return-link">
                        <?php foreach ($returnLinks as $returnLink): ?>
                            <a href="<?= htmlspecialchars($returnLink['url']) ?>">&larr; Return to <?= htmlspecialchars($returnLink['label']) ?></a>
                        <?php endforeach; ?>
                    </div>

                    <!-- OP Post -->
                    <div class="post op-post" id="p<?= (int)$singleThread['id'] ?>">
                        <?php if (!empty($singleThread['file_path'])): ?>
                            <div class="file-info">
                                File: <a href="<?= htmlspecialchars(get_base_path() . $singleThread['file_path']) ?>" target="_blank"><?= htmlspecialchars($singleThread['file_original_name']) ?></a>
                                (<?= format_bytes((int)$singleThread['file_size']) ?>, <?= htmlspecialchars($singleThread['file_ext']) ?>)
                            </div>
                            <div class="file-container">
                                <?php if ($singleThread['media_type'] === 'image'): ?>
                                    <a href="<?= htmlspecialchars(get_base_path() . $singleThread['file_path']) ?>" class="image-expand" target="_blank">
                                        <img src="<?= htmlspecialchars(get_base_path() . ($singleThread['thumb_path'] ?: $singleThread['file_path'])) ?>" alt="Thumbnail" class="post-thumb">
                                    </a>
                                <?php elseif ($singleThread['media_type'] === 'video'): ?>
                                    <video src="<?= htmlspecialchars(get_base_path() . $singleThread['file_path']) ?>" controls class="post-media-player" preload="metadata"></video>
                                <?php elseif ($singleThread['media_type'] === 'audio'): ?>
                                    <audio src="<?= htmlspecialchars(get_base_path() . $singleThread['file_path']) ?>" controls class="post-audio-player"></audio>
                                <?php else: ?>
                                    <div class="file-badge">
                                        <span class="file-icon">&#128196;</span>
                                        <a href="<?= htmlspecialchars(get_base_path() . $singleThread['file_path']) ?>" target="_blank">Download <?= htmlspecialchars($singleThread['file_ext']) ?></a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="post-header">
                            <?php if (!empty($singleThread['title'])): ?>
                                <span class="post-title"><?= htmlspecialchars($singleThread['title']) ?></span>
                            <?php endif; ?>
                            <span class="post-name"><?= htmlspecialchars($singleThread['name']) ?></span>
                            <span class="post-date"><?= date('m/d/y(D)H:i:s', strtotime($singleThread['created_at'])) ?></span>
                            <span class="post-num"><a href="#p<?= (int)$singleThread['id'] ?>">No.<?= (int)$singleThread['id'] ?></a></span>
                            <?php $renderPostIp($singleThread); ?>
                        </div>

                        <?php $renderModeratorControls($singleThread); ?>
                         <div class="post-body <?= (($singleThread['media_type'] ?? '') === 'image') ? 'post-body-with-image' : 'post-body-no-image' ?>">
                            <?= format_comment($singleThread['comment'], (int)$singleThread['id']) ?>
                        </div>
                         <div class="comment-reply-btn-box">
                             <button type="button" class="btn-reply" onclick="replyToOp()">Reply to Thread</button>
                         </div>
                        <div class="clear-post"></div>
                    </div>

                    <!-- Comment Trees (Level 2 Top-Level Comments & Level 3 Nested Replies) -->
                    <div class="replies-wrapper">
                        <?php foreach ($commentTrees as $tree): ?>
                            <?php $l2 = $tree['op_reply']; ?>
                            <div class="comment-tree" id="tree-<?= (int)$l2['id'] ?>">
                                
                                <!-- Level 2: Top-Level Reply -->
                                <div class="reply-container" id="pc<?= (int)$l2['id'] ?>">
                                    <div class="reply-arrow">&gt;&gt;</div>
                                    <div class="post reply-post" id="p<?= (int)$l2['id'] ?>">
                                        <div class="post-header">
                                            <?php if (!empty($l2['title'])): ?>
                                                <span class="post-title"><?= htmlspecialchars($l2['title']) ?></span>
                                            <?php endif; ?>
                                            <span class="post-name"><?= htmlspecialchars($l2['name']) ?></span>
                                            <span class="post-date"><?= date('m/d/y(D)H:i:s', strtotime($l2['created_at'])) ?></span>
                                            <span class="post-num"><a href="#p<?= (int)$l2['id'] ?>">No.<?= (int)$l2['id'] ?></a></span>
                                            <?php $renderPostIp($l2); ?>
                                        </div>

                                        <?php $renderModeratorControls($l2); ?>
                                        <?php if (!empty($l2['file_path'])): ?>
                                            <div class="file-info">
                                                File: <a href="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" target="_blank"><?= htmlspecialchars($l2['file_original_name']) ?></a>
                                                (<?= format_bytes((int)$l2['file_size']) ?>)
                                            </div>
                                            <div class="file-container">
                                                <?php if ($l2['media_type'] === 'image'): ?>
                                                    <a href="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" class="image-expand" target="_blank">
                                                        <img src="<?= htmlspecialchars(get_base_path() . ($l2['thumb_path'] ?: $l2['file_path'])) ?>" alt="Thumbnail" class="post-thumb">
                                                    </a>
                                                <?php elseif ($l2['media_type'] === 'video'): ?>
                                                    <video src="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" controls class="post-media-player" preload="metadata"></video>
                                                <?php elseif ($l2['media_type'] === 'audio'): ?>
                                                    <audio src="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" controls class="post-audio-player"></audio>
                                                <?php else: ?>
                                                    <div class="file-badge">
                                                        <span class="file-icon">&#128196;</span>
                                                        <a href="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" target="_blank">Download <?= htmlspecialchars($l2['file_ext']) ?></a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                         <div class="post-body <?= (($l2['media_type'] ?? '') === 'image') ? 'post-body-with-image' : 'post-body-no-image' ?>">
                                            <?= format_comment($l2['comment'], (int)$singleThread['id']) ?>
                                        </div>
                                        <div class="comment-reply-btn-box">
                                            <button type="button" class="btn-reply" onclick="replyToComment(<?= (int)$l2['id'] ?>, <?= (int)$l2['id'] ?>)">Reply</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Level 3: Nested Replies under this Top-Level Reply -->
                                <?php if (!empty($tree['replies'])): ?>
                                    <div class="nested-replies-group">
                                        <?php
                                        $nestedCount = count($tree['replies']);
                                        $nestedIndex = 0;
                                        foreach ($tree['replies'] as $l3):
                                            $nestedIndex++;
                                            $isLastNested = $nestedIndex === $nestedCount;
                                        ?>
                                            <div class="reply-container reply-nested" id="pc<?= (int)$l3['id'] ?>">
                                                <div class="reply-arrow">&gt;&gt;</div>
                                                <div class="post reply-post" id="p<?= (int)$l3['id'] ?>">
                                                    <div class="post-header">
                                                        <?php if (!empty($l3['title'])): ?>
                                                            <span class="post-title"><?= htmlspecialchars($l3['title']) ?></span>
                                                        <?php endif; ?>
                                                        <span class="post-name"><?= htmlspecialchars($l3['name']) ?></span>
                                                        <span class="post-date"><?= date('m/d/y(D)H:i:s', strtotime($l3['created_at'])) ?></span>
                                                        <span class="post-num"><a href="#p<?= (int)$l3['id'] ?>">No.<?= (int)$l3['id'] ?></a></span>
                                                        <?php $renderPostIp($l3); ?>
                                                    </div>

                                                    <?php $renderModeratorControls($l3); ?>
                                                    <?php if (!empty($l3['file_path'])): ?>
                                                        <div class="file-info">
                                                            File: <a href="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" target="_blank"><?= htmlspecialchars($l3['file_original_name']) ?></a>
                                                            (<?= format_bytes((int)$l3['file_size']) ?>)
                                                        </div>
                                                        <div class="file-container">
                                                            <?php if ($l3['media_type'] === 'image'): ?>
                                                                <a href="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" class="image-expand" target="_blank">
                                                                    <img src="<?= htmlspecialchars(get_base_path() . ($l3['thumb_path'] ?: $l3['file_path'])) ?>" alt="Thumbnail" class="post-thumb">
                                                                </a>
                                                            <?php elseif ($l3['media_type'] === 'video'): ?>
                                                                <video src="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" controls class="post-media-player" preload="metadata"></video>
                                                            <?php elseif ($l3['media_type'] === 'audio'): ?>
                                                                <audio src="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" controls class="post-audio-player"></audio>
                                                            <?php else: ?>
                                                                <div class="file-badge">
                                                                    <span class="file-icon">&#128196;</span>
                                                                    <a href="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" target="_blank">Download <?= htmlspecialchars($l3['file_ext']) ?></a>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                     <div class="post-body <?= (($l3['media_type'] ?? '') === 'image') ? 'post-body-with-image' : 'post-body-no-image' ?>">
                                                        <?= format_comment($l3['comment'], (int)$singleThread['id']) ?>
                                                    </div>
                                                     <div class="comment-reply-btn-box">
                                                         <button type="button" class="btn-reply" onclick="replyToComment(<?= (int)$l2['id'] ?>, <?= (int)$l3['id'] ?>)">Reply</button>
                                                         <?php if ($nestedCount >= 3 && $isLastNested): ?>
                                                             <button type="button" class="btn-reply" onclick="replyToComment(<?= (int)$l2['id'] ?>, <?= (int)$l2['id'] ?>)">Reply to parent</button>
                                                         <?php endif; ?>
                                                     </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php else: ?>

                <!-- OVERVIEW MODE (HOMEPAGE, BOARD, OR AGGREGATE) -->
                <?php if (empty($threadsData)): ?>
                    <div class="notice-box">
                        No threads found in this view. Use the form below to create the first thread!
                    </div>
                <?php endif; ?>

                <?php foreach ($threadsData as $tData): ?>
                    <?php $op = $tData['op']; ?>
                    <div class="thread-container" id="t<?= (int)$op['id'] ?>">
                        <!-- OP Post -->
                        <div class="post op-post overview-op" id="p<?= (int)$op['id'] ?>">
                            <?php if (!empty($op['file_path'])): ?>
                                <div class="file-info">
                                    File: <a href="<?= htmlspecialchars(get_base_path() . $op['file_path']) ?>" target="_blank"><?= htmlspecialchars($op['file_original_name']) ?></a>
                                    (<?= format_bytes((int)$op['file_size']) ?>, <?= htmlspecialchars($op['file_ext']) ?>)
                                </div>
                                <div class="file-container">
                                    <?php if ($op['media_type'] === 'image'): ?>
                                        <a href="<?= htmlspecialchars(get_base_path() . $op['file_path']) ?>" class="image-expand" target="_blank">
                                            <img src="<?= htmlspecialchars(get_base_path() . ($op['thumb_path'] ?: $op['file_path'])) ?>" alt="Thumbnail" class="post-thumb">
                                        </a>
                                    <?php elseif ($op['media_type'] === 'video'): ?>
                                        <video src="<?= htmlspecialchars(get_base_path() . $op['file_path']) ?>" controls class="post-media-player" preload="metadata"></video>
                                    <?php elseif ($op['media_type'] === 'audio'): ?>
                                        <audio src="<?= htmlspecialchars(get_base_path() . $op['file_path']) ?>" controls class="post-audio-player"></audio>
                                    <?php else: ?>
                                        <div class="file-badge">
                                            <span class="file-icon">&#128196;</span>
                                            <a href="<?= htmlspecialchars(get_base_path() . $op['file_path']) ?>" target="_blank">Download <?= htmlspecialchars($op['file_ext']) ?></a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="post-header">
                                <?php if ($viewMode === 'home' || $viewMode === 'aggregate'): ?>
                                    <span class="post-title"><a href="<?= htmlspecialchars(url_board($op['board_name'])) ?>" class="post-board-link"><?= htmlspecialchars($op['board_name']) ?></a> / <?= htmlspecialchars($op['title']) ?></span>
                                <?php else: ?>
                                    <?php if (!empty($op['title'])): ?>
                                        <span class="post-title"><?= htmlspecialchars($op['title']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <span class="post-name"><?= htmlspecialchars($op['name']) ?></span>
                                <span class="post-date"><?= date('m/d/y(D)H:i:s', strtotime($op['created_at'])) ?></span>
                                <span class="post-num"><a href="<?= htmlspecialchars(url_thread((int)$op['id'], $threadLinkContext)) ?>#p<?= (int)$op['id'] ?>">No.<?= (int)$op['id'] ?></a></span>
                                <?php $renderPostIp($op); ?>
                                <span class="post-view-thread">[<a href="<?= htmlspecialchars(url_thread((int)$op['id'], $threadLinkContext)) ?>">View Thread</a>]</span>
                            </div>

                            <?php $renderModeratorControls($op); ?>
                             <div class="post-body <?= (($op['media_type'] ?? '') === 'image') ? 'post-body-with-image' : 'post-body-no-image' ?>">
                                <?= format_comment_preview(
                                    $op['comment'],
                                    (int)$op['id'],
                                    20,
                                    url_thread((int)$op['id'], $threadLinkContext),
                                    'View full thread'
                                ) ?>
                            </div>
                             <div class="comment-reply-btn-box">
                                 <a href="<?= htmlspecialchars(url_thread((int)$op['id'], $threadLinkContext)) ?>#post-form" class="btn-reply-link">Reply</a>
                             </div>
                            <div class="clear-post"></div>
                        </div>

                        <!-- Omitted posts notice -->
                        <?php if ($tData['omitted_count'] > 0): ?>
                            <div class="omitted-notice">
                                <?= (int)$tData['omitted_count'] ?> replies omitted.
                                <a href="<?= htmlspecialchars(url_thread((int)$op['id'], $threadLinkContext)) ?>">Click here to view full thread.</a>
                            </div>
                        <?php endif; ?>

                        <!-- Recent top-level comments (nested replies stay nested) -->
                        <div class="replies-wrapper">
                            <?php foreach ($tData['trees'] as $tree): ?>
                                <?php $l2 = $tree['op_reply']; ?>
                                <div class="comment-tree" id="tree-<?= (int)$l2['id'] ?>">
                                    
                                    <!-- Level 2: Top-Level Reply -->
                                    <div class="reply-container" id="pc<?= (int)$l2['id'] ?>">
                                        <div class="reply-arrow">&gt;&gt;</div>
                                        <div class="post reply-post" id="p<?= (int)$l2['id'] ?>">
                                            <div class="post-header">
                                                <?php if (!empty($l2['title'])): ?>
                                                    <span class="post-title"><?= htmlspecialchars($l2['title']) ?></span>
                                                <?php endif; ?>
                                                <span class="post-name"><?= htmlspecialchars($l2['name']) ?></span>
                                                <span class="post-date"><?= date('m/d/y(D)H:i:s', strtotime($l2['created_at'])) ?></span>
                                                <span class="post-num"><a href="<?= htmlspecialchars(url_thread((int)$op['id'], $threadLinkContext)) ?>#p<?= (int)$l2['id'] ?>">No.<?= (int)$l2['id'] ?></a></span>
                                                <?php $renderPostIp($l2); ?>
                                            </div>

                                            <?php $renderModeratorControls($l2); ?>
                                            <?php if (!empty($l2['file_path'])): ?>
                                                <div class="file-info">
                                                    File: <a href="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" target="_blank"><?= htmlspecialchars($l2['file_original_name']) ?></a>
                                                </div>
                                                <div class="file-container">
                                                    <?php if ($l2['media_type'] === 'image'): ?>
                                                        <a href="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" class="image-expand" target="_blank">
                                                            <img src="<?= htmlspecialchars(get_base_path() . ($l2['thumb_path'] ?: $l2['file_path'])) ?>" alt="Thumbnail" class="post-thumb">
                                                        </a>
                                                    <?php elseif (($l2['media_type'] ?? '') === 'video'): ?>
                                                        <video src="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" controls class="post-media-player" preload="metadata"></video>
                                                    <?php elseif (($l2['media_type'] ?? '') === 'audio'): ?>
                                                        <audio src="<?= htmlspecialchars(get_base_path() . $l2['file_path']) ?>" controls class="post-audio-player"></audio>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                             <div class="post-body <?= (($l2['media_type'] ?? '') === 'image') ? 'post-body-with-image' : 'post-body-no-image' ?>">
                                                <?= format_comment_preview(
                                                    $l2['comment'],
                                                    (int)$op['id'],
                                                    10,
                                                    url_thread((int)$op['id'], $threadLinkContext) . '#p' . (int)$l2['id'],
                                                    'View this comment in thread'
                                                ) ?>
                                            </div>
                                             <div class="comment-reply-btn-box">
                                                 <a href="<?= htmlspecialchars(url_thread_reply((int)$op['id'], $threadLinkContext, (int)$l2['id'], (int)$l2['id'])) ?>" class="btn-reply-link">Reply</a>
                                             </div>
                                        </div>
                                    </div>

                                    <!-- Level 3: Nested Replies under this Top-Level Reply -->
                                    <?php if (!empty($tree['replies'])): ?>
                                        <div class="nested-replies-group">
                                            <?php
                                            foreach ($tree['replies'] as $l3):
                                            ?>
                                                <div class="reply-container reply-nested" id="pc<?= (int)$l3['id'] ?>">
                                                    <div class="reply-arrow">&gt;&gt;</div>
                                                    <div class="post reply-post" id="p<?= (int)$l3['id'] ?>">
                                                        <div class="post-header">
                                                            <?php if (!empty($l3['title'])): ?>
                                                                <span class="post-title"><?= htmlspecialchars($l3['title']) ?></span>
                                                            <?php endif; ?>
                                                            <span class="post-name"><?= htmlspecialchars($l3['name']) ?></span>
                                                            <span class="post-date"><?= date('m/d/y(D)H:i:s', strtotime($l3['created_at'])) ?></span>
                                                            <span class="post-num"><a href="<?= htmlspecialchars(url_thread((int)$op['id'], $threadLinkContext)) ?>#p<?= (int)$l3['id'] ?>">No.<?= (int)$l3['id'] ?></a></span>
                                                            <?php $renderPostIp($l3); ?>
                                                        </div>

                                                    <?php $renderModeratorControls($l3); ?>
                                                    <?php if (!empty($l3['file_path'])): ?>
                                                        <div class="file-info">
                                                            File: <a href="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" target="_blank"><?= htmlspecialchars($l3['file_original_name']) ?></a>
                                                        </div>
                                                        <div class="file-container">
                                                            <?php if ($l3['media_type'] === 'image'): ?>
                                                                <a href="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" class="image-expand" target="_blank">
                                                                    <img src="<?= htmlspecialchars(get_base_path() . ($l3['thumb_path'] ?: $l3['file_path'])) ?>" alt="Thumbnail" class="post-thumb">
                                                                </a>
                                                            <?php elseif (($l3['media_type'] ?? '') === 'video'): ?>
                                                                <video src="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" controls class="post-media-player" preload="metadata"></video>
                                                            <?php elseif (($l3['media_type'] ?? '') === 'audio'): ?>
                                                                <audio src="<?= htmlspecialchars(get_base_path() . $l3['file_path']) ?>" controls class="post-audio-player"></audio>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                         <div class="post-body <?= (($l3['media_type'] ?? '') === 'image') ? 'post-body-with-image' : 'post-body-no-image' ?>">
                                                            <?= format_comment_preview(
                                                                $l3['comment'],
                                                                (int)$op['id'],
                                                                10,
                                                                url_thread((int)$op['id'], $threadLinkContext) . '#p' . (int)$l3['id'],
                                                                'View this comment in thread'
                                                            ) ?>
                                                        </div>
                                                         <div class="comment-reply-btn-box">
                                                             <a href="<?= htmlspecialchars(url_thread_reply((int)$op['id'], $threadLinkContext, (int)$l2['id'], (int)$l3['id'])) ?>" class="btn-reply-link">Reply</a>
                                                         </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                    <hr class="thread-divider">
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($moderatorPaginationUrl($page - 1)) ?>" class="page-link">&laquo; Previous</a>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="page-current">[<?= $p ?>]</span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($moderatorPaginationUrl($p)) ?>" class="page-link"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($moderatorPaginationUrl($page + 1)) ?>" class="page-link">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </main>

        <!-- Right Sidebar -->
        <aside class="sidebar-right">
            
            <!-- Quick Tools -->
            <div class="sidebar-section">
                <div class="sidebar-header">Quick Tools</div>
                <ul class="sidebar-links">
                    <li><a href="#post-form">&rarr; <?= ($viewMode === 'thread') ? 'Reply to Thread' : 'New Thread' ?></a></li>
                    <li><a href="#board-list">&rarr; Full Board List</a></li>
                </ul>
            </div>

            <!-- Titles of the 20 Most Recent Threads (Synchronized with Current View) -->
            <div class="sidebar-section">
                <div class="sidebar-header">Recent Threads in View</div>
                <div class="sidebar-subtext">Threads visible on this page, ordered by latest bump:</div>
                <?php if (empty($sidebarRecentPosts)): ?>
                    <div class="sidebar-empty">No posts on this page.</div>
                <?php else: ?>
                    <ul class="sidebar-post-list">
                        <?php foreach ($sidebarRecentPosts as $sp): ?>
                            <?php
                                $displayTitle = trim($sp['title'] ?? '');
                                if ($displayTitle === '') {
                                    $snippet = strip_tags($sp['comment']);
                                    $snippet = preg_replace('/\s+/', ' ', $snippet);
                                    $displayTitle = mb_substr($snippet, 0, 30);
                                    if (mb_strlen($snippet) > 30) $displayTitle .= '...';
                                    if ($displayTitle === '') $displayTitle = '[No Title]';
                                }
                            ?>
                            <li>
                                <a href="#p<?= (int)$sp['id'] ?>" class="sidebar-anchor-link">
                                    <span class="sidebar-post-title"><?= htmlspecialchars($displayTitle) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Board filter (replaces Top Boards list) -->
            <div class="sidebar-section">
                <div class="sidebar-header">Filter Boards</div>
                <div class="sidebar-subtext">Check boards, then Filter:</div>
                <form method="get" action="<?= htmlspecialchars($adminMode ? admin_url() : ($moderatorMode ? moderator_url() : get_base_path())) ?>" id="board-filter-form" class="board-filter-form">
                    <input type="hidden" name="filter" value="1">
                    <label class="board-filter-toggle" for="board-filter-toggle-all">
                        <input type="checkbox" id="board-filter-toggle-all">
                        Select all / deselect all
                    </label>
                    <ul class="sidebar-board-list board-filter-list">
                        <?php foreach ($allBoards as $b): ?>
                            <?php $isChecked = in_array($b['name'], $selectedFilterBoards, true); ?>
                            <li>
                                <label class="board-filter-item" for="board-filter-<?= htmlspecialchars($b['name']) ?>">
                                    <input type="checkbox" id="board-filter-<?= htmlspecialchars($b['name']) ?>" name="boards[]" value="<?= htmlspecialchars($b['name']) ?>"<?= $isChecked ? ' checked' : '' ?>>
                                    <span>/<?= htmlspecialchars($b['name']) ?>/</span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="submit" class="btn-submit">Filter</button>
                </form>
            </div>

        </aside>

    </div>
    <?php endif; ?>

    <!-- Bottom Section: post form, board list, new board — shared left edge -->
    <footer class="footer-area">
        <div class="bottom-stack">
            <?php if ($moderatorMode && $viewMode !== 'boardlist'): ?>
                <form id="mod-actions" class="mod-actions-form" method="post" action="<?= htmlspecialchars($moderatorActionUrl) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($moderatorCsrfToken) ?>">
                    <p>Check posts above, then choose an action. Deleting an OP removes the whole thread; deleting files leaves the posts. Ban sends you to a confirmation page.</p>
                    <div class="mod-action-buttons">
                        <button type="submit" name="action" value="delete_files" class="btn-submit"
                            onclick="return confirm('Permanently delete files attached to the selected posts? The posts themselves will remain.');">
                            Delete files
                        </button>
                        <button type="submit" name="action" value="delete_posts" class="btn-submit mod-delete-posts-button"
                            onclick="return confirm('Permanently delete the selected posts? Deleting an OP also deletes its entire thread.');">
                            Delete posts
                        </button>
                        <button type="submit" name="action" value="prepare_ban" class="btn-submit mod-ban-button">
                            Ban IPs
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($viewMode !== 'boardlist'): ?>
            <!-- Post Form (aligned with board list / new board) -->
            <div id="post-form" class="post-form-box">
                <div class="post-form-title">
                    <?php if ($viewMode === 'thread'): ?>
                        Reply to Thread #<?= (int)$singleThread['id'] ?> (<span id="reply-mode-label">Top-Level Comment</span>)
                    <?php else: ?>
                        Create New Thread on 
                        <strong>/<?= htmlspecialchars($targetPostBoard) ?>/</strong>
                        <?php if ($viewMode === 'home'): ?>
                            <em>(Homepage submissions post to /random/)</em>
                        <?php elseif ($viewMode === 'aggregate'): ?>
                            <em>(Dynamic aggregate submissions post to first board /<?= htmlspecialchars($targetPostBoard) ?>/)</em>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <form method="POST" action="<?= htmlspecialchars(get_base_path()) ?>post.php" enctype="multipart/form-data">
                    <?php if ($moderatorMode): ?>
                        <input type="hidden" name="moderator" value="1">
                    <?php endif; ?>
                    <?php if ($adminMode): ?>
                        <input type="hidden" name="admin" value="1">
                    <?php endif; ?>
                    <input type="hidden" name="board" value="<?= htmlspecialchars($targetPostBoard) ?>">
                    <?php if ($formReturnBoardExpression !== null && $formReturnBoardExpression !== ''): ?>
                        <input type="hidden" name="return_board" value="<?= htmlspecialchars($formReturnBoardExpression) ?>">
                    <?php endif; ?>
                    <?php if ($viewMode === 'thread'): ?>
                        <input type="hidden" name="thread_id" value="<?= (int)$singleThread['id'] ?>">
                        <input type="hidden" name="reply_to_id" id="form-reply-to-id" value="<?= $prefillReplyTo ? (int)$prefillReplyTo : '' ?>">
                    <?php endif; ?>

                    <table class="post-table">
                        <tr>
                            <td class="field-label">Name</td>
                            <td>
                                <div class="name-row">
                                    <input type="text" name="name" placeholder="Anonymous" class="input-text">
                                    <?php if ($viewMode !== 'thread'): ?>
                                        <span class="field-tail"><button type="submit" class="btn-submit post-inline-submit">Create thread</button></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if ($viewMode !== 'thread'): ?>
                        <tr>
                            <td class="field-label">Subject<span class="label-star">*</span></td>
                            <td>
                                <input type="text" name="title" class="input-text" required>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="field-label">Comment</td>
                            <td>
                                <textarea name="comment" id="form-comment" rows="5" cols="48" class="textarea-box" <?= ($viewMode !== 'thread') ? 'required' : '' ?>><?php
                                    if ($prefillQuote) {
                                        echo '>>' . (int)$prefillQuote . "\n";
                                    }
                                ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td class="field-label">File<?php if ($viewMode !== 'thread'): ?><span class="label-star">*</span><?php endif; ?></td>
                            <td>
                                <div class="file-field">
                                    <input type="file" name="file" class="input-file" <?= ($viewMode !== 'thread') ? 'required' : '' ?>>
                                    <div class="file-hint">
                                        Allowed: jpg, png, gif, svg, webp, webm, ogg, mp4, pdf, epub, txt, sh, py, c, etc.
                                    </div>
                                    <?php if ($viewMode !== 'thread'): ?>
                                        <div class="file-required-line"><span class="required-badge">* Required</span></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if ($viewMode === 'thread'): ?>
                        <tr>
                            <td></td>
                            <td>
                                <button type="submit" class="btn-submit">Post Reply</button>
                                <button type="button" class="btn-reset-reply" onclick="replyToOp()">Cancel Sub-Reply</button>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </form>
            </div>
            <?php endif; ?>

            <!-- Full Board List in Alphabetical Order -->
            <?php
                $arrangedBoards = arrange_board_columns($allBoards, $viewMode !== 'boardlist');
                $boardColumns = $arrangedBoards['columns'];
                $boardColumnCount = max(1, (int)$arrangedBoards['column_count']);
                $boardListTruncated = $arrangedBoards['truncated'];
                $boardListWidthClass = $boardColumnCount <= 3 ? 'board-list-narrow' : 'board-list-wide';
            ?>
        <div id="board-list" class="full-board-list-box <?= $boardListWidthClass ?>">
            <h3 class="board-list-title"><?= $viewMode === 'boardlist' ? 'All Boards' : 'All Boards (Alphabetical)' ?></h3>
            <p class="board-list-all"><a href="<?= htmlspecialchars(url_board_expression('*')) ?>"><strong>All</strong></a></p>
            <div class="board-columns-grid cols-<?= (int)$boardColumnCount ?>">
                <?php foreach ($boardColumns as $columnBoards): ?>
                    <div class="board-column">
                        <?php if ($columnBoards !== []): ?>
                            <ul>
                                <?php foreach ($columnBoards as $b): ?>
                                    <li>
                                        <a href="<?= htmlspecialchars(url_board($b['name'])) ?>">
                                            <strong><?= htmlspecialchars(ucfirst($b['name'])) ?></strong>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($boardListTruncated): ?>
                <p class="board-list-more"><a href="<?= htmlspecialchars(url_board_directory()) ?>">Full board list</a></p>
            <?php endif; ?>
        </div>

        <?php if ($viewMode === 'home' || $viewMode === 'boardlist'): ?>
            <!-- New Board Form (at the bottom of homepage only) -->
            <div class="new-board-box">
                <h3 class="new-board-title">Create a New Board</h3>
                <form method="POST" action="<?= htmlspecialchars(get_base_path()) ?>board.php">
                    <?php if ($moderatorMode): ?>
                        <input type="hidden" name="moderator" value="1">
                    <?php endif; ?>
                    <?php if ($adminMode): ?>
                        <input type="hidden" name="admin" value="1">
                    <?php endif; ?>
                    <table class="new-board-table">
                        <tr>
                            <td class="field-label">Name</td>
                            <td>
                                <input type="text" name="name" required placeholder="e.g. videogames, technology, music" class="input-text" pattern="[a-zA-Z0-9_\-]+" title="Alphanumeric characters, hyphens or underscores">
                                <span class="field-hint">(Single word)</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="field-label">Description</td>
                            <td>
                                <textarea name="description" rows="3" cols="45" class="textarea-box" placeholder="Board description (optional)"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td class="field-label">Content</td>
                            <td>
                                <label>
                                    <input type="checkbox" name="nsfw" value="1">
                                    Mark this board as NSFW
                                </label>
                                <div class="field-hint">NSFW boards use the current Yotsuba theme.</div>
                            </td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>
                                <button type="submit" class="btn-submit">Create Board</button>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        <?php endif; ?>
        </div>

        <div class="footer-bottom">
            <p>Powered by PHP & SQLite - Classic Yotsuba Imageboard</p>
        </div>
    </footer>

    <script src="<?= htmlspecialchars(get_base_path()) ?>script.js"></script>
</body>
</html>
