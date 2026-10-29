<?php
/**
 * Yotsuba Imageboard - Shared Hosting Web Installer
 */
require_once __DIR__ . '/config.php';

$isCli = (php_sapi_name() === 'cli');
$installed = is_installed();

$errors = [];
$checks = [];

// 1. PHP Version check
$checks['PHP Version (>= 7.4)'] = version_compare(PHP_VERSION, '7.4.0', '>=');
if (!$checks['PHP Version (>= 7.4)']) {
    $errors[] = 'PHP 7.4.0 or higher is required. Current version: ' . PHP_VERSION;
}

// 2. PDO SQLite check
$checks['PDO SQLite Extension'] = extension_loaded('pdo_sqlite');
if (!$checks['PDO SQLite Extension']) {
    $errors[] = 'PDO SQLite extension (pdo_sqlite) is required.';
}

// 3. GD Library check (Thumbnails)
$checks['GD Extension (Thumbnails)'] = extension_loaded('gd');
if (!$checks['GD Extension (Thumbnails)']) {
    $checks['GD Extension (Thumbnails)'] = false; // Warning, not fatal
}

// 4. Data directory writable
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0777, true);
}
$checks['Data Directory Writable'] = is_writable(DATA_DIR);
if (!$checks['Data Directory Writable']) {
    $errors[] = 'The data directory (' . DATA_DIR . ') is not writable.';
}

// 5. Uploads directory writable
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0777, true);
}
$checks['Uploads Directory Writable'] = is_writable(UPLOAD_DIR);
if (!$checks['Uploads Directory Writable']) {
    $errors[] = 'The uploads directory (' . UPLOAD_DIR . ') is not writable.';
}

$action = $_POST['action'] ?? ($_GET['action'] ?? ($isCli ? 'install' : ''));
$success = false;
$message = '';

if ($action === 'install' && empty($errors)) {
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $pdo->exec("PRAGMA journal_mode = WAL;");

        // Create boards table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS boards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT DEFAULT '',
                nsfw INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Create posts table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_id INTEGER NOT NULL,
                thread_id INTEGER DEFAULT NULL,
                reply_to_id INTEGER DEFAULT NULL,
                name TEXT DEFAULT 'Anonymous',
                title TEXT DEFAULT '',
                comment TEXT NOT NULL,
                file_path TEXT DEFAULT NULL,
                thumb_path TEXT DEFAULT NULL,
                file_original_name TEXT DEFAULT NULL,
                file_size INTEGER DEFAULT 0,
                file_ext TEXT DEFAULT NULL,
                media_type TEXT DEFAULT NULL,
                poster_ip TEXT NOT NULL DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                bumped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            );
        ");

        // Create indexes
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_thread_id ON posts(thread_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_board_id ON posts(board_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_bumped_at ON posts(bumped_at);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_posts_reply_to ON posts(reply_to_id);");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ip_bans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                reason TEXT NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME DEFAULT NULL
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ip_bans_ip ON ip_bans(ip)");

        // Seed initial board: random
        $stmt = $pdo->prepare("SELECT id FROM boards WHERE name = 'random'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $insert = $pdo->prepare("INSERT INTO boards (name, description, nsfw) VALUES ('random', 'Random discussion', 1)");
            $insert->execute();
        }

        // Create lock file
        file_put_contents(LOCK_FILE, "Installed on " . date('Y-m-d H:i:s') . "\n");

        $success = true;
        $installed = true;
        $message = "Installation completed successfully! Initial board '/random/' created.";

        if ($isCli) {
            echo "OK: " . $message . "\n";
            exit(0);
        }
    } catch (Exception $e) {
        $errors[] = "Database error: " . $e->getMessage();
        if ($isCli) {
            echo "ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Yotsuba Imageboard - Installation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="alternate icon" href="favicon.png">
    <style>
        .install-box {
            max-width: 650px;
            margin: 40px auto;
            background: #F0E0D6;
            border: 1px solid #D9BFB7;
            padding: 20px 25px;
            font-family: arial, helvetica, sans-serif;
            font-size: 13px;
            color: #000;
        }
        .install-box h1 {
            color: #800000;
            font-size: 20px;
            margin-top: 0;
            border-bottom: 2px solid #800000;
            padding-bottom: 5px;
        }
        .check-item {
            padding: 6px 0;
            border-bottom: 1px dashed #D9BFB7;
            display: flex;
            justify-content: space-between;
        }
        .check-ok { color: #117743; font-weight: bold; }
        .check-warn { color: #d97706; font-weight: bold; }
        .check-fail { color: #c00; font-weight: bold; }
        .alert {
            padding: 10px;
            margin: 15px 0;
            border: 1px solid;
        }
        .alert-success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .btn-install {
            background: #EA8;
            border: 1px solid #800000;
            color: #800000;
            font-weight: bold;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 13px;
        }
        .btn-install:hover {
            background: #f0c090;
        }
    </style>
</head>
<body class="yotsuba">
    <div class="install-box">
        <h1>Yotsuba Imageboard Installer</h1>
        <p>Welcome! This installer initializes the SQLite database, checks system compatibility for shared hosting, and seeds the default <code>/random/</code> board.</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Success!</strong> <?= htmlspecialchars($message) ?>
            </div>
            <p><a href="<?= htmlspecialchars(get_base_path()) ?>" style="font-weight: bold; color: #34345C;">&rarr; Go to Homepage</a></p>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Please resolve the following issues before continuing:</strong>
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($installed): ?>
                <div class="alert alert-success">
                    <strong>Notice:</strong> The imageboard is already installed.
                </div>
                <p>
                    <a href="<?= htmlspecialchars(get_base_path()) ?>" style="font-weight: bold; color: #34345C;">&rarr; Enter Imageboard</a>
                </p>
            <?php endif; ?>

            <h3>System Requirements Check</h3>
            <div style="margin-bottom: 20px;">
                <?php foreach ($checks as $title => $ok): ?>
                    <div class="check-item">
                        <span><?= htmlspecialchars($title) ?></span>
                        <?php if ($title === 'GD Extension (Thumbnails)' && !$ok): ?>
                            <span class="check-warn">MISSING (Images will work without thumbnails)</span>
                        <?php elseif ($ok): ?>
                            <span class="check-ok">[ OK ]</span>
                        <?php else: ?>
                            <span class="check-fail">[ FAILED ]</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($errors)): ?>
                <form method="POST" action="install.php">
                    <input type="hidden" name="action" value="install">
                    <button type="submit" class="btn-install"><?= $installed ? 'Re-install / Reset Database' : 'Start Installation' ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
