<?php
/**
 * Automated Verification Test Suite for Yotsuba Imageboard (Updated)
 */

$port = 8099;
$baseUrl = 'http://127.0.0.1:' . $port;
$appDir = '/home/anti/.gemini/antigravity/scratch/yotsuba_imageboard';

// Clean old test database and lock to ensure fresh test from zero
@unlink($appDir . '/data/imageboard.sqlite');
@unlink($appDir . '/data/installed.lock');
array_map('unlink', glob($appDir . '/uploads/*'));

echo "Starting PHP built-in server on port $port...\n";
$serverProc = proc_open(
    "php -S 127.0.0.1:$port -t $appDir $appDir/.router.php",
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
usleep(300000); // 300ms delay

register_shutdown_function(function() use ($serverProc) {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
    }
});

function log_step($msg) {
    echo "\n=== $msg ===\n";
}

function assert_true($cond, $msg) {
    if ($cond) {
        echo " [PASS] $msg\n";
    } else {
        echo " [FAIL] $msg\n";
        exit(1);
    }
}

// Helper to make HTTP requests
function http_req($method, $path, $postFields = [], $files = []) {
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($files)) {
            $data = $postFields;
            foreach ($files as $name => $filePath) {
                $data[$name] = new CURLFile($filePath);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $response];
}

function get_test_db() {
    global $appDir;
    $pdo = new PDO('sqlite:' . $appDir . '/data/imageboard.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

log_step("1. Testing Installer (install.php)");
$res = http_req('POST', '/install.php', ['action' => 'install']);
assert_true($res['status'] === 200, "Installer returned 200 OK");
assert_true(strpos($res['body'], 'Installation completed successfully') !== false, "Installer reported success");
assert_true(file_exists($appDir . '/data/imageboard.sqlite'), "SQLite database file exists");
assert_true(file_exists($appDir . '/data/installed.lock'), "Lock file exists");

// Verify seeded random board
$stmt = get_test_db()->query("SELECT * FROM boards WHERE name = 'random'");
$randomBoard = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($randomBoard !== false, "Default /random/ board seeded");

log_step("2. Testing Board Creation (board.php)");
// Create 'videogames' with empty description
$res = http_req('POST', '/board.php', ['name' => 'videogames', 'description' => '']);
assert_true($res['status'] === 200, "Created /videogames/ board with empty description");
$stmt = get_test_db()->query("SELECT * FROM boards WHERE name = 'videogames'");
$vgBoard = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($vgBoard !== false && $vgBoard['description'] === '', "/videogames/ in DB with empty description");

// Create 'technology' with description
$res = http_req('POST', '/board.php', ['name' => 'technology', 'description' => 'Tech & programming']);
$stmt = get_test_db()->query("SELECT * FROM boards WHERE name = 'technology'");
$techBoard = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($techBoard !== false, "/technology/ created with description");

log_step("3. Testing Thread Validation (post.php)");
// Create a temporary dummy image
$testImgPath = sys_get_temp_dir() . '/test_img.png';
$im = imagecreatetruecolor(50, 50);
$bg = imagecolorallocate($im, 200, 50, 50);
imagefilledrectangle($im, 0, 0, 50, 50, $bg);
imagepng($im, $testImgPath);
imagedestroy($im);

// Test rejection: Thread without title
$res = http_req('POST', '/post.php', [
    'board' => 'random',
    'title' => '',
    'comment' => 'Thread without title'
], ['file' => $testImgPath]);
assert_true(strpos($res['body'], 'Post title cannot be empty') !== false, "Rejected thread with empty title");

// Test rejection: Thread without file attachment
$res = http_req('POST', '/post.php', [
    'board' => 'random',
    'title' => 'Thread title without file',
    'comment' => 'Missing attachment'
]);
assert_true(strpos($res['body'], 'Image or media file is required') !== false, "Rejected thread with missing file attachment");

log_step("4. Testing Valid Thread Creation");
// Post thread 1 to random
$res = http_req('POST', '/post.php', [
    'board' => 'random',
    'name' => 'Alice',
    'title' => 'Welcome Thread',
    'comment' => "Welcome to **Yotsuba**!\n>implying we are back\nCheck `code` out."
], ['file' => $testImgPath]);
assert_true($res['status'] === 200, "Created thread 1 on /random/");

$stmt = get_test_db()->query("SELECT * FROM posts WHERE id = 1");
$thread1 = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($thread1 !== false, "Thread 1 stored in DB");
assert_true($thread1['title'] === 'Welcome Thread', "Thread 1 title correct");
assert_true($thread1['file_path'] !== null, "Thread 1 image saved");
assert_true(file_exists($appDir . '/' . $thread1['file_path']), "Uploaded image file exists on disk");
assert_true($thread1['thumb_path'] !== null && file_exists($appDir . '/' . $thread1['thumb_path']), "Thumbnail generated on disk");

// Post thread 2 to videogames with a python file attachment
$testPyPath = sys_get_temp_dir() . '/game.py';
file_put_contents($testPyPath, "print('Game running')\n");
$res = http_req('POST', '/post.php', [
    'board' => 'videogames',
    'title' => 'Retro Games Thread',
    'comment' => "Favorite *16-bit* RPG?"
], ['file' => $testPyPath]);
assert_true($res['status'] === 200, "Created thread 2 on /videogames/ with Python code file");

$stmt = get_test_db()->query("SELECT * FROM posts WHERE id = 2");
$thread2 = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($thread2['media_type'] === 'code', "Python file correctly categorized as 'code'");

log_step("5. Testing 3-Level Hierarchy: Top-level Reply & Indented Reply");
// Level 2: Top-level reply to Thread 1 (no file, no title required)
$res = http_req('POST', '/post.php', [
    'thread_id' => 1,
    'name' => '', // blank -> should default to Anonymous
    'comment' => 'This is a top-level reply to OP.'
]);
$stmt = get_test_db()->query("SELECT * FROM posts WHERE id = 3");
$reply1 = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($reply1 !== false, "Reply 1 (post #3) stored");
assert_true($reply1['thread_id'] == 1, "Reply 1 belongs to thread #1");
assert_true($reply1['reply_to_id'] === null, "Reply 1 is top-level (reply_to_id is NULL)");
assert_true($reply1['name'] === 'Anonymous', "Blank name defaulted to Anonymous");

// Level 3: Reply to Level 2 reply (nested under post #3)
$res = http_req('POST', '/post.php', [
    'thread_id' => 1,
    'reply_to_id' => 3,
    'comment' => '>>3 This is an indented reply-to-reply (Level 3).'
]);
$stmt = get_test_db()->query("SELECT * FROM posts WHERE id = 4");
$reply2 = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($reply2 !== false, "Reply 2 (post #4) stored");
assert_true($reply2['thread_id'] == 1, "Reply 2 belongs to thread #1");
assert_true($reply2['reply_to_id'] == 3, "Reply 2 is nested under post #3 (Level 3)");

// Test Hierarchy Clamping: Reply targeting Level 3 post #4
$res = http_req('POST', '/post.php', [
    'thread_id' => 1,
    'reply_to_id' => 4,
    'comment' => '>>4 Replying to a level 3 comment. Should clamp to post #3!'
]);
$stmt = get_test_db()->query("SELECT * FROM posts WHERE id = 5");
$reply3 = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($reply3['reply_to_id'] == 3, "Reply depth clamped: reply_to_id clamped to Level 2 post #3!");

log_step("6. Testing Homepage Aggregate View (/all/)");
$res = http_req('GET', '/');
assert_true($res['status'] === 200, "Homepage loaded 200 OK");
assert_true(strpos($res['body'], '>all<') !== false, "Top nav uses 'all' instead of 'home'");
assert_true(strpos($res['body'], 'random / Welcome Thread') !== false, "Thread 1 title displays as 'random / Welcome Thread'");
assert_true(strpos($res['body'], 'videogames / Retro Games Thread') !== false, "Thread 2 title displays as 'videogames / Retro Games Thread'");
assert_true(strpos($res['body'], 'value="random"') !== false, "Homepage form submits to 'random'");
assert_true(strpos($res['body'], 'class="new-board-box"') !== false, "New board form appears at bottom of homepage");
assert_true(strpos($res['body'], '(Single word)') !== false, "New board form explains Name as '(Single word)'");

log_step("7. Testing Dynamic Board Aggregation (/random+videogames/)");
$res = http_req('GET', '/random+videogames/');
assert_true($res['status'] === 200, "Dynamic aggregate /random+videogames/ loaded 200 OK");
assert_true(strpos($res['body'], 'random / Welcome Thread') !== false, "Dynamic aggregate displays post from /random/");
assert_true(strpos($res['body'], 'videogames / Retro Games Thread') !== false, "Dynamic aggregate displays post from /videogames/");
assert_true(strpos($res['body'], 'value="random"') !== false, "Form submits to first board in list ('random')");
assert_true(strpos($res['body'], 'class="new-board-box"') === false, "New board form is NOT present on dynamic aggregate view");

// Test with inverse order /videogames+random/
$res = http_req('GET', '/videogames+random/');
assert_true(strpos($res['body'], 'value="videogames"') !== false, "Form submits to first board in list ('videogames')");

log_step("8. Testing Single Board View (/random/)");
$res = http_req('GET', '/random/');
assert_true($res['status'] === 200, "Single board /random/ loaded 200 OK");
assert_true(strpos($res['body'], 'Welcome Thread') !== false, "Single board displays thread");
assert_true(strpos($res['body'], 'random / Welcome Thread') === false, "Board title is omitted from thread title in single board view");
assert_true(strpos($res['body'], 'class="new-board-box"') === false, "New board form is NOT present on single board view");

log_step("9. Testing Single Thread View (/res/1/)");
$res = http_req('GET', '/res/1/');
assert_true($res['status'] === 200, "Thread 1 view loaded 200 OK");
assert_true(strpos($res['body'], 'Return to Random') !== false, "Return link is 'Return to Random' (omits slashes, capitalized)");
assert_true(strpos($res['body'], 'random / Welcome Thread') === false, "Board title is omitted from thread title in thread view");
assert_true(strpos($res['body'], '<strong>Yotsuba</strong>') !== false, "Markdown **bold** parsed into <strong>");
assert_true(strpos($res['body'], '<span class="greentext">&gt;implying we are back</span>') !== false, "Greentext parsed into <span class=\"greentext\">");
assert_true(strpos($res['body'], '<code>code</code>') !== false, "Markdown `code` parsed into <code>");
assert_true(strpos($res['body'], '<a href="#p3" class="quote-link">&gt;&gt;3</a>') !== false, "Quote >>3 parsed into in-page anchor link");
assert_true(strpos($res['body'], 'class="new-board-box"') === false, "New board form is NOT present on thread view");

log_step("10. Testing Synchronized Right Sidebar");
$res = http_req('GET', '/');
preg_match('/<ul class="sidebar-post-list">(.*?)<\/ul>/s', $res['body'], $sbMatches);
$sidebarContent = $sbMatches[1] ?? '';
assert_true(!empty($sidebarContent), "Sidebar post list exists");
assert_true(strpos($sidebarContent, 'Welcome Thread') !== false, "OP Welcome Thread appears in sidebar");
assert_true(strpos($sidebarContent, 'Retro Games Thread') !== false, "OP Retro Games Thread appears in sidebar");
assert_true(strpos($sidebarContent, 'sidebar-post-num') === false, "Sidebar does not display post IDs");
assert_true(strpos($sidebarContent, 'top-level reply to OP') === false, "Comments are NOT listed in sidebar recent posts (only OPs)");

log_step("11. Testing Full Board List in 4 Columns");
assert_true(strpos($res['body'], 'id="board-list"') !== false, "Full board list section exists");
assert_true(strpos($res['body'], 'board-columns-grid') !== false, "4-column grid layout exists");

preg_match('/<div id="board-list"[^>]*>(.*?)<\/div>\s*(?:<hr|<\/footer)/s', $res['body'], $blMatches);
$boardListContent = $blMatches[1] ?? '';
assert_true(!empty($boardListContent), "Found board-list section content");
// First board should be All
assert_true(strpos($boardListContent, '<strong>All</strong>') !== false, "'All' is displayed in the board list");
assert_true(strpos($boardListContent, '<strong>Random</strong>') !== false, "'Random' capitalized in the board list");
assert_true(strpos($boardListContent, '<strong>Technology</strong>') !== false, "'Technology' capitalized in the board list");
assert_true(strpos($boardListContent, '<strong>Videogames</strong>') !== false, "'Videogames' capitalized in the board list");
// No slashes or descriptions in the board list
assert_true(strpos($boardListContent, '<strong>/random/</strong>') === false, "No slashes around board titles in bottom list");
assert_true(strpos($boardListContent, '<strong>/Random/</strong>') === false, "No slashes around board titles in bottom list");
assert_true(strpos($boardListContent, 'class="board-desc"') === false, "No descriptions displayed in bottom list");

log_step("12. Testing Reply Buttons Placement and IDs");
// Check reply button inside comment
assert_true(strpos($res['body'], 'comment-reply-btn-box') !== false, "Reply button placed in comment-reply-btn-box inside comments");
// Post numbers in overview are links to thread
assert_true(strpos($res['body'], 'href="/res/1/#p1"') !== false || strpos($res['body'], 'href="http://127.0.0.1:8099/res/1/#p1"') !== false || preg_match('/href="[^"]*res\/1\/#p1"/', $res['body']), "Post ID links directly to thread post anchor from overview");

echo "\n============================================\n";
echo " ALL 12 TEST PHASES PASSED WITH FLYING COLORS!\n";
echo "============================================\n";
