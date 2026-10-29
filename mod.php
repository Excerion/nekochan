<?php
/**
 * Password-protected moderator view and post/file controls.
 */
require_once __DIR__ . '/config.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

moderator_start_session();

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$pdo = get_db();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS moderator_auth (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        password_hash TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

function moderator_checked_ids(string $field): array {
    $values = $_POST[$field] ?? [];
    if (!is_array($values)) {
        return [];
    }

    $ids = [];
    foreach (array_slice($values, 0, 5000) as $value) {
        if (is_string($value) || is_int($value)) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) {
                $ids[$id] = $id;
            }
        }
    }
    return array_values($ids);
}

function moderator_delete_upload(?string $storedPath): bool {
    if ($storedPath === null || $storedPath === '') {
        return true;
    }
    if (!preg_match('~^uploads/([^/\\\\]+)$~D', $storedPath, $matches)) {
        return false;
    }

    $uploadRoot = realpath(UPLOAD_DIR);
    if ($uploadRoot === false) {
        return false;
    }

    $candidate = $uploadRoot . DIRECTORY_SEPARATOR . $matches[1];
    if (!file_exists($candidate) && !is_link($candidate)) {
        return true;
    }
    if (is_link($candidate)) {
        return @unlink($candidate);
    }

    $resolvedPath = realpath($candidate);
    if (
        $resolvedPath === false
        || !str_starts_with($resolvedPath, $uploadRoot . DIRECTORY_SEPARATOR)
        || !is_file($resolvedPath)
    ) {
        return false;
    }

    return @unlink($resolvedPath);
}

function moderator_remove_uploads(array $storedPaths): int {
    $failed = 0;
    foreach (array_unique($storedPaths) as $storedPath) {
        if (!moderator_delete_upload(is_string($storedPath) ? $storedPath : null)) {
            $failed++;
        }
    }
    return $failed;
}

function moderator_page_url(array $overrides = []): string {
    $query = [];
    if (isset($_GET['board']) && is_string($_GET['board'])) {
        $query['board'] = substr($_GET['board'], 0, 256);
    }
    if (isset($_GET['thread']) && is_scalar($_GET['thread']) && ctype_digit((string)$_GET['thread'])) {
        $query['thread'] = (int)$_GET['thread'];
    }
    if (isset($_GET['page']) && is_scalar($_GET['page']) && ctype_digit((string)$_GET['page'])) {
        $query['page'] = max(1, (int)$_GET['page']);
    }
    foreach (['reply_to', 'quote'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key]) && ctype_digit((string)$_GET[$key])) {
            $query[$key] = (int)$_GET[$key];
        }
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return moderator_url($query);
}

function moderator_refresh_bump(PDO $pdo, array $threadIds): void {
    $stmt = $pdo->prepare("
        UPDATE posts
        SET bumped_at = CASE
            WHEN COALESCE(
                (SELECT MAX(c.created_at) FROM posts c WHERE c.thread_id = posts.id),
                posts.created_at
            ) > posts.created_at
            THEN (SELECT MAX(c.created_at) FROM posts c WHERE c.thread_id = posts.id)
            ELSE posts.created_at
        END
        WHERE id = :thread_id AND thread_id IS NULL
    ");
    foreach (array_unique($threadIds) as $threadId) {
        $stmt->execute([':thread_id' => (int)$threadId]);
    }
}

function moderator_delete_selected_files(PDO $pdo, array $ids): array {
    $filePaths = [];
    $changedPosts = 0;
    $pdo->beginTransaction();

    try {
        $select = $pdo->prepare("SELECT file_path, thumb_path FROM posts WHERE id = :id");
        $clear = $pdo->prepare("
            UPDATE posts
            SET file_path = NULL,
                thumb_path = NULL,
                file_original_name = NULL,
                file_size = 0,
                file_ext = NULL,
                media_type = NULL
            WHERE id = :id AND (file_path IS NOT NULL OR thumb_path IS NOT NULL)
        ");

        foreach ($ids as $id) {
            $select->execute([':id' => $id]);
            $post = $select->fetch();
            if (!$post || (empty($post['file_path']) && empty($post['thumb_path']))) {
                continue;
            }
            if (!empty($post['file_path'])) {
                $filePaths[] = $post['file_path'];
            }
            if (!empty($post['thumb_path'])) {
                $filePaths[] = $post['thumb_path'];
            }
            $clear->execute([':id' => $id]);
            $changedPosts += $clear->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [$changedPosts, moderator_remove_uploads($filePaths)];
}

function moderator_delete_selected_posts(PDO $pdo, array $ids): array {
    $selectedPosts = [];
    $select = $pdo->prepare("SELECT id, thread_id, file_path, thumb_path FROM posts WHERE id = :id");
    foreach ($ids as $id) {
        $select->execute([':id' => $id]);
        $post = $select->fetch();
        if ($post) {
            $selectedPosts[] = $post;
        }
    }

    $threadIdsToDelete = [];
    foreach ($selectedPosts as $post) {
        if ($post['thread_id'] === null) {
            $threadIdsToDelete[(int)$post['id']] = true;
        }
    }

    $filePaths = [];
    $deletedCount = 0;
    $threadsToRefresh = [];
    $pdo->beginTransaction();

    try {
        $threadFiles = $pdo->prepare("
            SELECT file_path, thumb_path
            FROM posts
            WHERE id = :id OR thread_id = :id
        ");
        $deleteThread = $pdo->prepare("DELETE FROM posts WHERE id = :id OR thread_id = :id");
        $replyFiles = $pdo->prepare("
            SELECT file_path, thumb_path
            FROM posts
            WHERE id = :id OR reply_to_id = :id
        ");
        $deletePost = $pdo->prepare("DELETE FROM posts WHERE id = :id OR reply_to_id = :id");

        foreach (array_keys($threadIdsToDelete) as $threadId) {
            $threadFiles->execute([':id' => $threadId]);
            foreach ($threadFiles->fetchAll() as $post) {
                if (!empty($post['file_path'])) {
                    $filePaths[] = $post['file_path'];
                }
                if (!empty($post['thumb_path'])) {
                    $filePaths[] = $post['thumb_path'];
                }
            }
            $deleteThread->execute([':id' => $threadId]);
            $deletedCount += $deleteThread->rowCount();
        }

        foreach ($selectedPosts as $post) {
            $threadId = $post['thread_id'] === null ? null : (int)$post['thread_id'];
            if ($threadId === null || isset($threadIdsToDelete[$threadId])) {
                continue;
            }
            $replyFiles->execute([':id' => (int)$post['id']]);
            foreach ($replyFiles->fetchAll() as $related) {
                if (!empty($related['file_path'])) {
                    $filePaths[] = $related['file_path'];
                }
                if (!empty($related['thumb_path'])) {
                    $filePaths[] = $related['thumb_path'];
                }
            }
            $deletePost->execute([':id' => (int)$post['id']]);
            $deletedCount += $deletePost->rowCount();
            $threadsToRefresh[$threadId] = $threadId;
        }

        moderator_refresh_bump($pdo, array_values($threadsToRefresh));
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        $deletedCount,
        moderator_remove_uploads($filePaths),
        array_keys($threadIdsToDelete)
    ];
}

function moderator_ban_targets(PDO $pdo, array $ids): array {
    $byIp = [];
    $unknown = [];
    $select = $pdo->prepare("SELECT id, poster_ip FROM posts WHERE id = :id");
    foreach ($ids as $id) {
        $select->execute([':id' => $id]);
        $post = $select->fetch();
        if (!$post) {
            continue;
        }
        $ip = trim((string)$post['poster_ip']);
        if ($ip === '' || $ip === '0.0.0.0') {
            $unknown[] = (int)$post['id'];
            continue;
        }
        if (!isset($byIp[$ip])) {
            $byIp[$ip] = [];
        }
        $byIp[$ip][] = (int)$post['id'];
    }
    return ['ips' => $byIp, 'unknown' => $unknown];
}

function moderator_ban_expires_at(string $length): ?string {
    $now = time();
    switch ($length) {
        case '1d':
            return date('Y-m-d H:i:s', $now + 86400);
        case '3d':
            return date('Y-m-d H:i:s', $now + 86400 * 3);
        case '7d':
            return date('Y-m-d H:i:s', $now + 86400 * 7);
        case '30d':
            return date('Y-m-d H:i:s', $now + 86400 * 30);
        default:
            return null;
    }
}

function moderator_render_ban_form(
    array $targets,
    string $csrfToken,
    string $formAction,
    string $cancelUrl,
    string $error = '',
    string $reasonValue = '',
    string $lengthValue = 'permanent'
): void {
    $reasonValue = $reasonValue !== '' ? $reasonValue : (string)($_POST['reason'] ?? '');
    $lengthValue = in_array($lengthValue, ['permanent', '1d', '3d', '7d', '30d'], true)
        ? $lengthValue
        : 'permanent';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue IP ban - Yotsuba</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(get_base_path()) ?>style.css">
</head>
<body class="yotsuba">
    <main class="mod-auth-box ban-confirm-box">
        <h1>Issue IP ban</h1>
        <?php if ($error !== ''): ?>
            <p class="mod-error" role="alert"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <p>The following IP address(es) will be banned. Enter a reason, then confirm.</p>
        <table class="ban-target-table">
            <thead>
                <tr><th>IP</th><th>Posts</th></tr>
            </thead>
            <tbody>
                <?php foreach ($targets['ips'] as $ip => $postIds): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string)$ip) ?></code></td>
                        <td><?php
                            $labels = [];
                            foreach ($postIds as $postId) {
                                $labels[] = 'No.' . (int)$postId;
                            }
                            echo htmlspecialchars(implode(', ', $labels));
                        ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($targets['unknown'])): ?>
            <p class="field-hint">No IP was logged for post(s)
                <?php
                    $unknownLabels = [];
                    foreach ($targets['unknown'] as $postId) {
                        $unknownLabels[] = 'No.' . (int)$postId;
                    }
                    echo htmlspecialchars(implode(', ', $unknownLabels));
                ?>; those cannot be banned.</p>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialchars($formAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="confirm_ban">
            <label for="ban-reason">Reason</label>
            <textarea id="ban-reason" name="reason" rows="4" required maxlength="500" placeholder="Shown to the banned poster"><?= htmlspecialchars($reasonValue) ?></textarea>
            <label for="ban-length">Length</label>
            <select id="ban-length" name="length">
                <option value="permanent"<?= $lengthValue === 'permanent' ? ' selected' : '' ?>>Permanent</option>
                <option value="1d"<?= $lengthValue === '1d' ? ' selected' : '' ?>>1 day</option>
                <option value="3d"<?= $lengthValue === '3d' ? ' selected' : '' ?>>3 days</option>
                <option value="7d"<?= $lengthValue === '7d' ? ' selected' : '' ?>>1 week</option>
                <option value="30d"<?= $lengthValue === '30d' ? ' selected' : '' ?>>1 month</option>
            </select>
            <div class="mod-action-buttons">
                <button type="submit" class="btn-submit mod-ban-button">Confirm ban</button>
                <a class="mod-cancel-ban" href="<?= htmlspecialchars($cancelUrl) ?>">Cancel</a>
            </div>
        </form>
    </main>
</body>
</html>
    <?php
}

$csrfToken = $_SESSION['mod_csrf'] ??= bin2hex(random_bytes(32));
$authError = '';

if (
    ($_SESSION['mod_authenticated'] ?? false) === true
    && isset($_SESSION['mod_last_activity'])
    && time() - (int)$_SESSION['mod_last_activity'] > 1800
) {
    unset($_SESSION['mod_authenticated'], $_SESSION['mod_last_activity']);
    $_SESSION['mod_flash'] = 'Your moderator session expired. Sign in again.';
}

$isAuthenticated = ($_SESSION['mod_authenticated'] ?? false) === true;
if ($isAuthenticated) {
    $_SESSION['mod_last_activity'] = time();
}

$passwordHash = $pdo->query("SELECT password_hash FROM moderator_auth WHERE id = 1")->fetchColumn();
$hasPassword = is_string($passwordHash) && $passwordHash !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(400);
        exit('Invalid request token. Reload the moderator page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'logout' && $isAuthenticated) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => 'Strict'
            ]);
        }
        session_destroy();
        header('Location: ' . moderator_url());
        exit;
    }

    if (!$hasPassword && $action === 'setup_password') {
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirmation'] ?? '';

        if (!is_string($password) || strlen($password) < 12) {
            $authError = 'Choose a password with at least 12 characters.';
        } elseif (strlen($password) > 1024) {
            $authError = 'Password is too long.';
        } elseif (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $authError = 'The password confirmation does not match.';
        } else {
            $insert = $pdo->prepare("
                INSERT OR IGNORE INTO moderator_auth (id, password_hash)
                VALUES (1, :password_hash)
            ");
            $insert->execute([':password_hash' => password_hash($password, PASSWORD_DEFAULT)]);

            if ($insert->rowCount() === 1) {
                $_SESSION['mod_flash'] = 'Password set. Sign in to open the moderator panel.';
                header('Location: ' . moderator_url());
                exit;
            }

            $authError = 'A moderator password has already been set. Sign in below.';
            $hasPassword = true;
        }
    } elseif ($hasPassword && !$isAuthenticated && $action === 'login') {
        $password = $_POST['password'] ?? '';
        if (is_string($password) && password_verify($password, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['mod_authenticated'] = true;
            $_SESSION['mod_last_activity'] = time();
            $_SESSION['mod_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['mod_flash'] = 'Signed in to moderator mode.';
            $loginOverrides = [];
            if (!isset($_GET['board']) && !isset($_GET['thread'])) {
                $loginOverrides['board'] = '*';
            }
            header('Location: ' . moderator_page_url($loginOverrides));
            exit;
        }

        usleep(250000);
        $authError = 'Password was incorrect.';
    } elseif ($hasPassword && $isAuthenticated && $action === 'prepare_ban') {
        $ids = moderator_checked_ids('post_ids');
        if ($ids === []) {
            $_SESSION['mod_flash'] = 'Select at least one post to ban its poster.';
            header('Location: ' . moderator_page_url());
            exit;
        }
        $targets = moderator_ban_targets($pdo, $ids);
        if ($targets['ips'] === []) {
            $_SESSION['mod_flash'] = 'None of the selected posts have a logged IP address.';
            header('Location: ' . moderator_page_url());
            exit;
        }
        $_SESSION['mod_ban_post_ids'] = $ids;
        moderator_render_ban_form(
            $targets,
            $csrfToken,
            moderator_page_url(),
            moderator_page_url()
        );
        exit;
    } elseif ($hasPassword && $isAuthenticated && $action === 'confirm_ban') {
        $ids = $_SESSION['mod_ban_post_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            $_SESSION['mod_flash'] = 'Ban request expired. Select posts again.';
            header('Location: ' . moderator_page_url());
            exit;
        }
        $cleanIds = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $cleanIds[] = $n;
            }
        }
        $ids = $cleanIds;
        $reason = trim((string)($_POST['reason'] ?? ''));
        $length = is_string($_POST['length'] ?? null) ? $_POST['length'] : 'permanent';
        $targets = moderator_ban_targets($pdo, $ids);
        if ($reason === '' || strlen($reason) > 500) {
            moderator_render_ban_form(
                $targets,
                $csrfToken,
                moderator_page_url(),
                moderator_page_url(),
                'Enter a ban reason (max 500 characters).',
                $reason,
                $length
            );
            exit;
        }
        if ($targets['ips'] === []) {
            unset($_SESSION['mod_ban_post_ids']);
            $_SESSION['mod_flash'] = 'None of the selected posts have a logged IP address.';
            header('Location: ' . moderator_page_url());
            exit;
        }
        $expires = moderator_ban_expires_at($length);
        $insert = $pdo->prepare("
            INSERT INTO ip_bans (ip, reason, created_at, expires_at)
            VALUES (:ip, :reason, datetime('now'), :expires)
        ");
        foreach (array_keys($targets['ips']) as $ip) {
            $insert->execute([
                ':ip' => $ip,
                ':reason' => $reason,
                ':expires' => $expires
            ]);
        }
        unset($_SESSION['mod_ban_post_ids']);
        $count = count($targets['ips']);
        $_SESSION['mod_flash'] = 'Banned ' . $count . ' IP address' . ($count === 1 ? '' : 'es') . '.';
        header('Location: ' . moderator_page_url());
        exit;
    } elseif ($hasPassword && $isAuthenticated && in_array($action, ['delete_files', 'delete_posts'], true)) {
        try {
            if ($action === 'delete_files') {
                [$changedPosts, $failedFiles] = moderator_delete_selected_files(
                    $pdo,
                    moderator_checked_ids('post_ids')
                );
                $_SESSION['mod_flash'] = $changedPosts > 0
                    ? "Removed attachments from {$changedPosts} post(s)." . ($failedFiles ? " {$failedFiles} file(s) could not be removed from disk." : '')
                    : 'No selected posts had an attached file.';
            } else {
                [$deletedPosts, $failedFiles, $deletedThreads] = moderator_delete_selected_posts(
                    $pdo,
                    moderator_checked_ids('post_ids')
                );
                $_SESSION['mod_flash'] = $deletedPosts > 0
                    ? "Deleted {$deletedPosts} post(s)." . ($failedFiles ? " {$failedFiles} file(s) could not be removed from disk." : '')
                    : 'No selected posts were found.';
            }

            $redirectOverrides = [];
            if (
                $action === 'delete_posts'
                && isset($_GET['thread'])
                && in_array((int)$_GET['thread'], $deletedThreads ?? [], true)
            ) {
                $redirectOverrides['thread'] = null;
                $redirectOverrides['board'] = is_string($_GET['board'] ?? null)
                    ? $_GET['board']
                    : '*';
            }
            header('Location: ' . moderator_page_url($redirectOverrides));
            exit;
        } catch (Throwable $error) {
            error_log('Moderator action failed: ' . $error->getMessage());
            $_SESSION['mod_flash'] = 'The moderation action could not be completed. Check the page and try again.';
            header('Location: ' . moderator_page_url());
            exit;
        }
    }
}

if (!$hasPassword || !$isAuthenticated) {
    $flashMessage = $_SESSION['mod_flash'] ?? '';
    unset($_SESSION['mod_flash']);
    $pageMode = $hasPassword ? 'login' : 'setup';
    $pageTitle = $pageMode === 'setup' ? 'Set moderator password' : 'Moderator sign in';
    $formAction = moderator_page_url();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($pageTitle) ?> - Yotsuba</title>
        <link rel="stylesheet" href="<?= htmlspecialchars(get_base_path()) ?>style.css">
    </head>
    <body class="yotsuba">
        <main class="mod-auth-box">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <?php if ($flashMessage !== ''): ?>
                <p class="mod-message"><?= htmlspecialchars($flashMessage) ?></p>
            <?php endif; ?>
            <?php if ($authError !== ''): ?>
                <p class="mod-error" role="alert"><?= htmlspecialchars($authError) ?></p>
            <?php endif; ?>
            <?php if ($pageMode === 'setup'): ?>
                <p>Choose a password with at least 12 characters. No username is used.</p>
                <form method="post" action="<?= htmlspecialchars($formAction) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="setup_password">
                    <label for="mod-password">Password</label>
                    <input id="mod-password" type="password" name="password" required minlength="12" maxlength="1024" autocomplete="new-password">
                    <label for="mod-password-confirm">Confirm password</label>
                    <input id="mod-password-confirm" type="password" name="password_confirmation" required minlength="12" maxlength="1024" autocomplete="new-password">
                    <button type="submit" class="btn-submit">Set password</button>
                </form>
            <?php else: ?>
                <p>Enter the moderator password to browse and manage posts.</p>
                <form method="post" action="<?= htmlspecialchars($formAction) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="login">
                    <label for="mod-password">Password</label>
                    <input id="mod-password" type="password" name="password" required maxlength="1024" autocomplete="current-password">
                    <button type="submit" class="btn-submit">Sign in</button>
                </form>
            <?php endif; ?>
            <p class="mod-back-link"><a href="<?= htmlspecialchars(url_board_expression('*')) ?>">Return to the imageboard</a></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

foreach (['board', 'thread', 'page'] as $parameter) {
    if (isset($_GET[$parameter]) && !is_scalar($_GET[$parameter])) {
        unset($_GET[$parameter]);
    }
}
if (isset($_GET['board'])) {
    $_GET['board'] = substr((string)$_GET['board'], 0, 256);
}
if (isset($_GET['thread']) && !ctype_digit((string)$_GET['thread'])) {
    unset($_GET['thread']);
}
if (!isset($_GET['thread']) && !isset($_GET['board'])) {
    $_GET['board'] = '*';
}

$moderatorMode = true;
$moderatorCsrfToken = $csrfToken;
$moderatorActionUrl = moderator_page_url();
$moderatorFlashMessage = $_SESSION['mod_flash'] ?? '';
unset($_SESSION['mod_flash']);
require __DIR__ . '/index.php';