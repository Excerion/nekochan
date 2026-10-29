<?php
/**
 * Password-protected board administration.
 * Uses the same password as the moderator panel. On a single board's
 * overview, the name, description, and NSFW flag can be edited. The board
 * can be deleted (with its posts) or merged into another board.
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

function admin_page_url(array $overrides = []): string {
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
    if (isset($_GET['view']) && $_GET['view'] === 'boards') {
        $query['view'] = 'boards';
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
    return admin_url($query);
}

function admin_delete_upload(?string $storedPath): bool {
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

function admin_board_slug(string $raw): string {
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/\s+/', '-', $slug) ?? '';
    return $slug;
}

function admin_reserved_board_names(): array {
    return ['res', 'install', 'uploads', 'data', 'api', 'post', 'board', 'index', 'style', 'script', 'mod', 'admin', 'boards'];
}

function admin_find_board(PDO $pdo, int $boardId): ?array {
    $stmt = $pdo->prepare('SELECT id, name, description, nsfw FROM boards WHERE id = :id');
    $stmt->execute([':id' => $boardId]);
    $board = $stmt->fetch();
    return $board ?: null;
}

function admin_redirect(string $message, array $overrides = []): void {
    $_SESSION['admin_flash'] = $message;
    header('Location: ' . admin_page_url($overrides));
    exit;
}

$csrfToken = $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
$authError = '';

if (
    ($_SESSION['mod_authenticated'] ?? false) === true
    && isset($_SESSION['mod_last_activity'])
    && time() - (int)$_SESSION['mod_last_activity'] > 1800
) {
    unset($_SESSION['mod_authenticated'], $_SESSION['mod_last_activity']);
    $_SESSION['admin_flash'] = 'Your session expired. Sign in again.';
}

$isAuthenticated = ($_SESSION['mod_authenticated'] ?? false) === true;
if ($isAuthenticated) {
    $_SESSION['mod_last_activity'] = time();
}

$passwordHash = $pdo->query('SELECT password_hash FROM moderator_auth WHERE id = 1')->fetchColumn();
$hasPassword = is_string($passwordHash) && $passwordHash !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(400);
        exit('Invalid request token. Reload the page and try again.');
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
        header('Location: ' . admin_url());
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
            $insert = $pdo->prepare('
                INSERT OR IGNORE INTO moderator_auth (id, password_hash)
                VALUES (1, :password_hash)
            ');
            $insert->execute([':password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['admin_flash'] = 'Password set. Sign in to open board administration.';
            header('Location: ' . admin_url());
            exit;
        }
    } elseif ($hasPassword && !$isAuthenticated && $action === 'login') {
        $password = $_POST['password'] ?? '';
        if (is_string($password) && password_verify($password, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['mod_authenticated'] = true;
            $_SESSION['mod_last_activity'] = time();
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['admin_flash'] = 'Signed in to board administration.';
            $loginOverrides = [];
            if (!isset($_GET['board']) && !isset($_GET['thread']) && !isset($_GET['view'])) {
                $loginOverrides['board'] = '*';
            }
            header('Location: ' . admin_page_url($loginOverrides));
            exit;
        }

        usleep(250000);
        $authError = 'Password was incorrect.';
    } elseif ($isAuthenticated && in_array($action, ['update_board', 'delete_board', 'merge_board'], true)) {
        $boardId = filter_var($_POST['board_id'] ?? '', FILTER_VALIDATE_INT);
        $board = (is_int($boardId) && $boardId > 0) ? admin_find_board($pdo, $boardId) : null;
        if ($board === null) {
            admin_redirect('That board no longer exists.', ['board' => '*', 'thread' => null]);
        }

        try {
            if ($action === 'update_board') {
                $slug = admin_board_slug(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
                $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
                if (function_exists('mb_substr')) {
                    $description = mb_substr($description, 0, 2000);
                } else {
                    $description = substr($description, 0, 2000);
                }
                $isNsfw = (($_POST['nsfw'] ?? '') === '1') ? 1 : 0;

                if ($slug === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $slug)) {
                    admin_redirect('Board name must be 1-32 letters, numbers, hyphens, or underscores.', ['board' => $board['name'], 'thread' => null]);
                }
                if (in_array($slug, admin_reserved_board_names(), true)) {
                    admin_redirect('The board name "/' . $slug . '/" is reserved.', ['board' => $board['name'], 'thread' => null]);
                }

                $taken = $pdo->prepare('SELECT id FROM boards WHERE name = :name AND id != :id');
                $taken->execute([':name' => $slug, ':id' => $board['id']]);
                if ($taken->fetch()) {
                    admin_redirect('Board "/' . $slug . '/" already exists.', ['board' => $board['name'], 'thread' => null]);
                }

                $update = $pdo->prepare('UPDATE boards SET name = :name, description = :description, nsfw = :nsfw WHERE id = :id');
                $update->execute([
                    ':name' => $slug,
                    ':description' => $description,
                    ':nsfw' => $isNsfw,
                    ':id' => $board['id'],
                ]);
                admin_redirect('Saved /' . $slug . '/.', ['board' => $slug, 'thread' => null]);
            }

            if ($action === 'delete_board') {
                $files = $pdo->prepare('SELECT file_path, thumb_path FROM posts WHERE board_id = :id');
                $files->execute([':id' => $board['id']]);
                $failedFiles = 0;
                foreach ($files->fetchAll() as $file) {
                    foreach (['file_path', 'thumb_path'] as $column) {
                        if (!admin_delete_upload(is_string($file[$column] ?? null) ? $file[$column] : null)) {
                            $failedFiles++;
                        }
                    }
                }

                $pdo->prepare('DELETE FROM boards WHERE id = :id')->execute([':id' => $board['id']]);
                $message = 'Deleted /' . $board['name'] . '/ and its posts.';
                if ($failedFiles > 0) {
                    $message .= ' ' . $failedFiles . ' file(s) could not be removed from disk.';
                }
                admin_redirect($message, ['board' => '*', 'thread' => null, 'page' => null]);
            }

            if ($action === 'merge_board') {
                $targetId = filter_var($_POST['target_board'] ?? '', FILTER_VALIDATE_INT);
                $target = (is_int($targetId) && $targetId > 0) ? admin_find_board($pdo, $targetId) : null;
                if ($target === null || (int)$target['id'] === (int)$board['id']) {
                    admin_redirect('Choose a different board to merge into.', ['board' => $board['name'], 'thread' => null]);
                }

                $pdo->beginTransaction();
                $pdo->prepare('UPDATE posts SET board_id = :target WHERE board_id = :source')
                    ->execute([':target' => $target['id'], ':source' => $board['id']]);
                $pdo->prepare('DELETE FROM boards WHERE id = :id')->execute([':id' => $board['id']]);
                $pdo->commit();

                admin_redirect(
                    'Moved posts from /' . $board['name'] . '/ to /' . $target['name'] . '/ and deleted /' . $board['name'] . '/.',
                    ['board' => $target['name'], 'thread' => null, 'page' => null]
                );
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin board action failed: ' . $error->getMessage());
            admin_redirect('The board could not be updated. Check the page and try again.', ['board' => $board['name'], 'thread' => null]);
        }
    }
}

if (!$hasPassword || !$isAuthenticated) {
    $flashMessage = $_SESSION['admin_flash'] ?? '';
    unset($_SESSION['admin_flash']);
    $pageMode = $hasPassword ? 'login' : 'setup';
    $pageTitle = $pageMode === 'setup' ? 'Set administrator password' : 'Board administration sign in';
    $formAction = admin_page_url();
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
                <p>Choose a password with at least 12 characters. No username is used. The same password opens the moderator panel.</p>
                <form method="post" action="<?= htmlspecialchars($formAction) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="setup_password">
                    <label for="admin-password">Password</label>
                    <input id="admin-password" type="password" name="password" required minlength="12" maxlength="1024" autocomplete="new-password">
                    <label for="admin-password-confirm">Confirm password</label>
                    <input id="admin-password-confirm" type="password" name="password_confirmation" required minlength="12" maxlength="1024" autocomplete="new-password">
                    <button type="submit" class="btn-submit">Set password</button>
                </form>
            <?php else: ?>
                <p>Enter the moderator password to browse boards and change their settings.</p>
                <form method="post" action="<?= htmlspecialchars($formAction) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="login">
                    <label for="admin-password">Password</label>
                    <input id="admin-password" type="password" name="password" required maxlength="1024" autocomplete="current-password">
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
if (!isset($_GET['thread']) && !isset($_GET['board']) && (($_GET['view'] ?? '') !== 'boards')) {
    $_GET['board'] = '*';
}

$GLOBALS['adminMode'] = true;
$adminMode = true;
$adminCsrfToken = $csrfToken;
$adminActionUrl = admin_page_url();
$adminFlashMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
require __DIR__ . '/index.php';
