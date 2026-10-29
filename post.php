<?php
/**
 * Yotsuba Imageboard - Post & Reply Processing
 */
require_once __DIR__ . '/config.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

enable_moderator_mode_from_request();
enable_admin_mode_from_request();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url_home());
    exit;
}

$pdo = get_db();
reject_if_banned();

$boardName = trim($_POST['board'] ?? '');
$threadId = !empty($_POST['thread_id']) ? (int)$_POST['thread_id'] : null;
$replyToId = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
$returnBoardExpression = trim($_POST['return_board'] ?? '');

$name = trim($_POST['name'] ?? '');
if ($name === '') {
    $name = 'Anonymous';
}

$title = trim($_POST['title'] ?? '');
$comment = trim($_POST['comment'] ?? '');

$error = null;

try {
    $isNewThread = ($threadId === null);

    if ($isNewThread) {
        // --- NEW THREAD VALIDATION ---
        // 1. Post title is REQUIRED for new threads
        if ($title === '') {
            throw new Exception('Post title cannot be empty for new threads.');
        }

        // 2. Image/Media attachment is REQUIRED for new threads
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Image or media file is required for new threads.');
        }

        // 3. Comment cannot be empty
        if ($comment === '') {
            throw new Exception('Post comment cannot be empty.');
        }

        // 4. Determine destination board
        if ($boardName === '') {
            $boardName = 'random';
        }

        $bStmt = $pdo->prepare("SELECT id FROM boards WHERE name = :name");
        $bStmt->execute([':name' => $boardName]);
        $board = $bStmt->fetch();

        if (!$board) {
            // Fallback to random if board not found
            $bStmt->execute([':name' => 'random']);
            $board = $bStmt->fetch();
            if (!$board) {
                throw new Exception('Target board "' . htmlspecialchars($boardName) . '" does not exist.');
            }
        }
        $boardId = (int)$board['id'];

        // Process file upload
        $fileInfo = handle_file_upload($_FILES['file'], true);
        if (!$fileInfo) {
            throw new Exception('File upload was required but processing failed.');
        }

        // Insert thread
        $stmt = $pdo->prepare("
            INSERT INTO posts (
                board_id, thread_id, reply_to_id, name, title, comment,
                file_path, thumb_path, file_original_name, file_size, file_ext, media_type,
                poster_ip, created_at, bumped_at
            ) VALUES (
                :board_id, NULL, NULL, :name, :title, :comment,
                :file_path, :thumb_path, :file_original_name, :file_size, :file_ext, :media_type,
                :poster_ip, datetime('now'), datetime('now')
            )
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':name' => $name,
            ':title' => $title,
            ':comment' => $comment,
            ':file_path' => $fileInfo['file_path'],
            ':thumb_path' => $fileInfo['thumb_path'],
            ':file_original_name' => $fileInfo['file_original_name'],
            ':file_size' => $fileInfo['file_size'],
            ':file_ext' => $fileInfo['file_ext'],
            ':media_type' => $fileInfo['media_type'],
            ':poster_ip' => client_ip()
        ]);

        $newPostId = (int)$pdo->lastInsertId();

        // Redirect to new thread view
        header('Location: ' . url_thread(
            $newPostId,
            $returnBoardExpression !== '' ? $returnBoardExpression : null
        ));
        exit;

    } else {
        // --- REPLY VALIDATION (Level 2 or Level 3) ---
        // Verify parent thread exists
        $tStmt = $pdo->prepare("SELECT id, board_id FROM posts WHERE id = :id AND thread_id IS NULL");
        $tStmt->execute([':id' => $threadId]);
        $thread = $tStmt->fetch();

        if (!$thread) {
            throw new Exception('Thread #' . $threadId . ' does not exist or has been deleted.');
        }
        $boardId = (int)$thread['board_id'];

        // Comment or file required
        $hasFile = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($comment === '' && !$hasFile) {
            throw new Exception('Please enter a comment or attach a file.');
        }

        // Determine Level 2 vs Level 3 reply hierarchy
        // Strict 3-level rule:
        // Level 1: Thread (thread_id IS NULL)
        // Level 2: Top-level reply (thread_id = threadId, reply_to_id = NULL)
        // Level 3: Reply to reply (thread_id = threadId, reply_to_id = parentLevel2CommentId)
        $resolvedReplyToId = null;

        if ($replyToId !== null && $replyToId > 0) {
            // Fetch target comment
            $rStmt = $pdo->prepare("SELECT id, thread_id, reply_to_id FROM posts WHERE id = :id");
            $rStmt->execute([':id' => $replyToId]);
            $targetComment = $rStmt->fetch();

            if ($targetComment) {
                if ($targetComment['thread_id'] === null) {
                    // Replying to OP directly -> Top-level comment
                    $resolvedReplyToId = null;
                } elseif ($targetComment['reply_to_id'] === null) {
                    // Replying to a top-level comment -> Level 3 reply nested under target comment
                    $resolvedReplyToId = (int)$targetComment['id'];
                } else {
                    // Replying to an existing reply-to-reply -> Clamped to target's parent level 2 comment!
                    $resolvedReplyToId = (int)$targetComment['reply_to_id'];
                }
            }
        }

        // Process file upload (optional for replies)
        $fileInfo = null;
        if ($hasFile) {
            $fileInfo = handle_file_upload($_FILES['file'], false);
        }

        // Insert reply
        $stmt = $pdo->prepare("
            INSERT INTO posts (
                board_id, thread_id, reply_to_id, name, title, comment,
                file_path, thumb_path, file_original_name, file_size, file_ext, media_type,
                poster_ip, created_at, bumped_at
            ) VALUES (
                :board_id, :thread_id, :reply_to_id, :name, :title, :comment,
                :file_path, :thumb_path, :file_original_name, :file_size, :file_ext, :media_type,
                :poster_ip, datetime('now'), datetime('now')
            )
        ");

        $stmt->execute([
            ':board_id' => $boardId,
            ':thread_id' => $threadId,
            ':reply_to_id' => $resolvedReplyToId,
            ':name' => $name,
            ':title' => $title,
            ':comment' => $comment,
            ':file_path' => $fileInfo['file_path'] ?? null,
            ':thumb_path' => $fileInfo['thumb_path'] ?? null,
            ':file_original_name' => $fileInfo['file_original_name'] ?? null,
            ':file_size' => $fileInfo['file_size'] ?? 0,
            ':file_ext' => $fileInfo['file_ext'] ?? null,
            ':media_type' => $fileInfo['media_type'] ?? null,
            ':poster_ip' => client_ip()
        ]);

        $newPostId = (int)$pdo->lastInsertId();

        // Bump the thread
        $bumpStmt = $pdo->prepare("UPDATE posts SET bumped_at = datetime('now') WHERE id = :id");
        $bumpStmt->execute([':id' => $threadId]);

        // Redirect to thread view anchored to the new post
        header('Location: ' . url_thread(
            $threadId,
            $returnBoardExpression !== '' ? $returnBoardExpression : null
        ) . '#p' . $newPostId);
        exit;
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Post Error</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .error-box {
            max-width: 500px;
            margin: 50px auto;
            background: #F0E0D6;
            border: 1px solid #D9BFB7;
            padding: 20px;
            font-family: arial, helvetica, sans-serif;
            font-size: 13px;
        }
        .error-title { color: #800000; font-size: 18px; font-weight: bold; margin-bottom: 10px; }
    </style>
</head>
<body class="yotsuba">
    <div class="error-box">
        <div class="error-title">Post Error</div>
        <p><?= htmlspecialchars($error) ?></p>
        <p><a href="javascript:history.back()">&larr; Go Back</a></p>
    </div>
</body>
</html>
