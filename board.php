<?php
/**
 * Yotsuba Imageboard - Board Creation Handler
 */
require_once __DIR__ . '/config.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

enable_moderator_mode_from_request();
enable_admin_mode_from_request();

reject_if_banned();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url_home());
    exit;
}

$rawName = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$isNsfw = isset($_POST['nsfw']) && $_POST['nsfw'] === '1' ? 1 : 0;

$error = null;

// Normalize board name
$slug = strtolower($rawName);
$slug = preg_replace('/\s+/', '-', $slug);

// Validate board name
if ($slug === '') {
    $error = 'Board name cannot be empty.';
} elseif (!preg_match('/^[a-z0-9_-]{1,32}$/', $slug)) {
    $error = 'Board name must be 1-32 alphanumeric characters (hyphens and underscores allowed).';
} elseif (in_array($slug, ['res', 'install', 'uploads', 'data', 'api', 'post', 'board', 'index', 'style', 'script', 'mod', 'admin', 'boards'], true)) {
    $error = 'The board name "' . htmlspecialchars($slug) . '" is reserved.';
} else {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id FROM boards WHERE name = :name");
    $stmt->execute([':name' => $slug]);
    if ($stmt->fetch()) {
        $error = 'Board "/' . htmlspecialchars($slug) . '/" already exists.';
    } else {
        $insert = $pdo->prepare("INSERT INTO boards (name, description, nsfw) VALUES (:name, :desc, :nsfw)");
        $insert->execute([
            ':name' => $slug,
            ':desc' => $description,
            ':nsfw' => $isNsfw
        ]);

        // Redirect to new board
        header('Location: ' . url_board($slug));
        exit;
    }
}

// Display error if validation failed
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error Creating Board</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .error-box {
            max-width: 500px;
            margin: 50px auto;
            background: #F0E0D6;
            border: 1px solid #D9BFB7;
            padding: 20px;
            font-family: arial, helvetica, sans-serif;
        }
        .error-title { color: #800000; font-size: 18px; font-weight: bold; margin-bottom: 10px; }
    </style>
</head>
<body class="yotsuba">
    <div class="error-box">
        <div class="error-title">Board Creation Error</div>
        <p><?= htmlspecialchars($error) ?></p>
        <p><a href="javascript:history.back()">&larr; Go Back</a></p>
    </div>
</body>
</html>
