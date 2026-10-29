<?php
/**
 * Yotsuba Imageboard - Configuration and Helper Utilities
 */

// Error reporting settings (shared hosting friendly)
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

// Paths and Constants
define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('DB_FILE', DATA_DIR . '/imageboard.sqlite');
define('LOCK_FILE', DATA_DIR . '/installed.lock');

define('POSTS_PER_PAGE', 20);
define('OVERVIEW_TOPLEVEL_PREVIEW', 3);
define('OVERVIEW_TOPLEVEL_PREVIEW_FLAT', 5);
define('OVERVIEW_NESTED_PREVIEW', 2);
define('SIDEBAR_RECENT_POSTS_LIMIT', 20);

define('THUMB_MAX_W_OP', 250);
define('THUMB_MAX_H_OP', 250);
define('THUMB_MAX_W_REPLY', 125);
define('THUMB_MAX_H_REPLY', 125);

// Allowed file extensions
const ALLOWED_EXTENSIONS = [
    // Raster & Vector Images
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
    // Video & Audio
    'webm', 'mp4', 'ogg',
    // Documents & Ebooks
    'pdf', 'epub', 'txt', 'md',
    // Code & Scripts
    'sh', 'py', 'c', 'cpp', 'h', 'js', 'css', 'html', 'json'
];

/**
 * Check if the application is installed.
 */
function is_installed(): bool {
    return file_exists(LOCK_FILE) && file_exists(DB_FILE);
}

/**
 * Get the SQLite database connection using PDO.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists(DB_FILE)) {
            header('Location: install.php');
            exit;
        }
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $pdo->exec("PRAGMA journal_mode = WAL;");

        // Add board safety metadata to databases created before NSFW support.
        $boardColumns = $pdo->query("PRAGMA table_info(boards)")->fetchAll();
        $hasNsfwColumn = false;
        foreach ($boardColumns as $column) {
            if (($column['name'] ?? '') === 'nsfw') {
                $hasNsfwColumn = true;
                break;
            }
        }
        if (!$hasNsfwColumn) {
            $pdo->exec("ALTER TABLE boards ADD COLUMN nsfw INTEGER NOT NULL DEFAULT 0");
        }
        $pdo->exec("UPDATE boards SET nsfw = 1 WHERE name = 'random'");

        $postColumns = $pdo->query("PRAGMA table_info(posts)")->fetchAll();
        $hasPosterIp = false;
        foreach ($postColumns as $column) {
            if (($column['name'] ?? '') === 'poster_ip') {
                $hasPosterIp = true;
                break;
            }
        }
        if (!$hasPosterIp) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN poster_ip TEXT NOT NULL DEFAULT ''");
        }

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
    }
    return $pdo;
}

/**
 * Client IP used for posting logs and bans. REMOTE_ADDR only — headers are spoofable.
 */
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '0.0.0.0';
}

function format_poster_ip(?string $ip): string {
    $ip = trim((string)$ip);
    return $ip === '' ? 'unknown' : $ip;
}

function find_active_ip_ban(?string $ip = null): ?array {
    $ip = $ip ?? client_ip();
    if ($ip === '' || $ip === '0.0.0.0') {
        return null;
    }

    $stmt = get_db()->prepare("
        SELECT id, ip, reason, created_at, expires_at
        FROM ip_bans
        WHERE ip = :ip
          AND (expires_at IS NULL OR expires_at > datetime('now'))
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':ip' => $ip]);
    $ban = $stmt->fetch();
    return $ban ?: null;
}

function render_banned_page(array $ban): void {
    $reason = trim((string)($ban['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'No reason given.';
    }
    $issued = !empty($ban['created_at'])
        ? date('Y-m-d H:i:s', strtotime((string)$ban['created_at']))
        : 'unknown';
    $expires = !empty($ban['expires_at'])
        ? date('Y-m-d H:i:s', strtotime((string)$ban['expires_at']))
        : null;
    $ip = format_poster_ip($ban['ip'] ?? client_ip());
    $css = htmlspecialchars(get_base_path() . 'style.css', ENT_QUOTES, 'UTF-8');
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You are banned.</title>
    <link rel="stylesheet" href="<?= $css ?>">
</head>
<body class="banned-page">
    <div class="banned-wrap">
        <div class="banned-stamp" aria-hidden="true">BANNED</div>
        <h1>You are banned. ;_;</h1>
        <p>You have been banned from posting on this imageboard for the following reason:</p>
        <blockquote class="banned-reason"><?= htmlspecialchars($reason) ?></blockquote>
        <table class="banned-info">
            <tr>
                <th>Ban date</th>
                <td><?= htmlspecialchars($issued) ?></td>
            </tr>
            <tr>
                <th>Expires</th>
                <td><?= $expires === null ? 'This ban is permanent.' : htmlspecialchars($expires) ?></td>
            </tr>
            <tr>
                <th>Your IP</th>
                <td><?= htmlspecialchars($ip) ?></td>
            </tr>
        </table>
        <p class="banned-note">Please note that evading your ban will result in a permanent one.</p>
    </div>
</body>
</html>
    <?php
}

/**
 * Block banned visitors from the public board. Moderator panel stays reachable.
 */
function reject_if_banned(): void {
    if (moderator_mode_enabled()) {
        return;
    }
    if (!is_installed()) {
        return;
    }
    $ban = find_active_ip_ban();
    if ($ban === null) {
        return;
    }
    render_banned_page($ban);
    exit;
}

/**
 * Determine site root URL path for relative navigation.
 */
function get_base_path(): string {
    static $base = null;
    if ($base !== null) return $base;

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = dirname($scriptName);
    $base = rtrim($dir, '/\\');
    if ($base === '') {
        $base = '/';
    } else {
        $base .= '/';
    }
    return $base;
}

/**
 * Check whether clean URL rewrites are active or fallback to query params.
 */
function use_clean_urls(): bool {
    // If Apache mod_rewrite passed HTTP_MOD_REWRITE or request URI indicates clean URLs
    if (isset($_SERVER['HTTP_MOD_REWRITE']) && $_SERVER['HTTP_MOD_REWRITE'] === 'On') {
        return true;
    }
    return true; // Default to clean URLs supported by .htaccess
}

/**
 * Build a moderator URL while preserving the requested board/thread context.
 */
function moderator_url(array $query = []): string {
    $url = get_base_path() . 'mod.php';
    if ($query === []) {
        return $url;
    }
    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Board-administration URLs. Browsing stays on admin.php.
 */
function admin_url(array $query = []): string {
    $url = get_base_path() . 'admin.php';
    if ($query === []) {
        return $url;
    }
    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Append query parameters to a URL that may already have a query string.
 */
function url_add_query(string $url, array $params): string {
    if ($params === []) {
        return $url;
    }
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Check whether the current request was opened through the authenticated moderator panel.
 */
function moderator_mode_enabled(): bool {
    return ($GLOBALS['moderatorMode'] ?? false) === true;
}

/**
 * True while the request is rendering the authenticated board-admin interface.
 */
function admin_mode_enabled(): bool {
    return ($GLOBALS['adminMode'] ?? false) === true;
}

/**
 * Start the named moderator session if it is not already active.
 */
function moderator_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800');
    session_name('YotsubaModerator');

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    );
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => get_base_path(),
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

/**
 * True when the current request has a live, unexpired moderator login.
 */
function moderator_session_authenticated(): bool {
    moderator_start_session();

    if (($_SESSION['mod_authenticated'] ?? false) !== true) {
        return false;
    }

    if (
        isset($_SESSION['mod_last_activity'])
        && time() - (int)$_SESSION['mod_last_activity'] > 1800
    ) {
        unset($_SESSION['mod_authenticated'], $_SESSION['mod_last_activity']);
        return false;
    }

    $_SESSION['mod_last_activity'] = time();
    return true;
}

/**
 * Honor a posted "stay in moderator view" flag only after password login.
 */
function enable_moderator_mode_from_request(): void {
    $flag = $_POST['moderator'] ?? '';
    if (!is_string($flag) || $flag !== '1') {
        return;
    }

    if (!moderator_session_authenticated()) {
        header('Location: ' . moderator_url());
        exit;
    }

    $GLOBALS['moderatorMode'] = true;
}

/**
 * Honor a posted "stay in admin view" flag only after the moderator password login.
 */
function enable_admin_mode_from_request(): void {
    $flag = $_POST['admin'] ?? '';
    if (!is_string($flag) || $flag !== '1') {
        return;
    }

    if (!moderator_session_authenticated()) {
        header('Location: ' . admin_url());
        exit;
    }

    $GLOBALS['adminMode'] = true;
}

/**
 * Generate URL for a single board or dynamic aggregation.
 */
function url_board(string $boardSlug): string {
    if (admin_mode_enabled()) {
        return admin_url(['board' => $boardSlug]);
    }
    if (moderator_mode_enabled()) {
        return moderator_url(['board' => $boardSlug]);
    }
    $base = get_base_path();
    return $base . rawurlencode($boardSlug) . '/';
}

/**
 * Generate a URL for a board aggregate expression.
 */
function url_board_expression(string $expression): string {
    if (admin_mode_enabled()) {
        return admin_url(['board' => trim($expression)]);
    }
    if (moderator_mode_enabled()) {
        return moderator_url(['board' => trim($expression)]);
    }
    $encodedExpression = rawurlencode(trim($expression));
    $encodedExpression = str_replace(['%2B', '%2A'], ['+', '*'], $encodedExpression);
    return get_base_path() . $encodedExpression . '/';
}

/**
 * Generate a URL for a thread, optionally preserving its board context.
 */
function url_thread(int $threadId, ?string $boardExpression = null, array $extraQuery = []): string {
    if (admin_mode_enabled() || moderator_mode_enabled()) {
        $query = ['thread' => $threadId];
        if ($boardExpression !== null && trim($boardExpression) !== '') {
            $query['board'] = trim($boardExpression);
        }
        $base = admin_mode_enabled() ? admin_url($query) : moderator_url($query);
        return url_add_query($base, $extraQuery);
    }
    $base = get_base_path();
    if ($boardExpression !== null && trim($boardExpression) !== '') {
        $encodedExpression = rawurlencode(trim($boardExpression));
        $encodedExpression = str_replace(['%2B', '%2A'], ['+', '*'], $encodedExpression);
        $url = $base . $encodedExpression . '/res/' . $threadId . '/';
    } else {
        $url = $base . 'res/' . $threadId . '/';
    }
    return url_add_query($url, $extraQuery);
}

function url_thread_reply(int $threadId, ?string $boardExpression, int $replyToId, int $quoteId): string {
    return url_thread($threadId, $boardExpression, [
        'reply_to' => $replyToId,
        'quote' => $quoteId,
    ]) . '#post-form';
}

/**
 * How many boards go in each footer column.
 *
 * Two columns until 20, a third from 21, a fourth from 31. Columns stay at
 * 10 until the 41st board, then they split evenly with extras on the left.
 */
function board_column_counts(int $boardCount): array {
    if ($boardCount <= 20) {
        $columns = 2;
    } elseif ($boardCount <= 30) {
        $columns = 3;
    } else {
        $columns = 4;
    }

    $sizes = array_fill(0, $columns, 0);
    if ($boardCount <= 0) {
        return $sizes;
    }

    if ($boardCount > 40) {
        $base = intdiv($boardCount, $columns);
        $extra = $boardCount % $columns;
        for ($i = 0; $i < $columns; $i++) {
            $sizes[$i] = $base + ($i < $extra ? 1 : 0);
        }
        return $sizes;
    }

    if ($columns === 2 && $boardCount <= 10) {
        $sizes[0] = intdiv($boardCount + 1, 2);
        $sizes[1] = $boardCount - $sizes[0];
        return $sizes;
    }

    $remaining = $boardCount;
    for ($i = 0; $i < $columns - 1; $i++) {
        $take = min(10, $remaining);
        $sizes[$i] = $take;
        $remaining -= $take;
    }
    $sizes[$columns - 1] = $remaining;
    return $sizes;
}

/**
 * Split a board directory into columns. The compact footer stops at 80 boards.
 */
function arrange_board_columns(array $items, bool $capAt80): array {
    $truncated = false;
    if ($capAt80 && count($items) > 80) {
        $items = array_slice($items, 0, 80);
        $truncated = true;
    }

    $counts = board_column_counts(count($items));
    $columns = [];
    $offset = 0;
    foreach ($counts as $count) {
        $columns[] = array_slice($items, $offset, $count);
        $offset += $count;
    }

    return [
        'columns' => $columns,
        'column_count' => count($columns),
        'truncated' => $truncated,
    ];
}

/**
 * Page that lists every board plus the new-board form.
 */
function url_board_directory(): string {
    if (admin_mode_enabled()) {
        return admin_url(['view' => 'boards']);
    }
    if (moderator_mode_enabled()) {
        return moderator_url(['view' => 'boards']);
    }
    return get_base_path() . 'boards/';
}

/**
 * Generate homepage URL.
 */
function url_home(): string {
    if (admin_mode_enabled() || moderator_mode_enabled()) {
        return url_board_expression('*');
    }
    return get_base_path();
}

/**
 * Parse a board expression used by aggregate views.
 *
 * Examples:
 * - random+videogames      include two boards
 * - *                      include every board
 * - *+-politics            include every board except politics
 * - -politics              include every board except politics
 */
function parse_board_expression(string $expression): array {
    // Treat "*-board" as the compact form of "*+-board".
    $expression = preg_replace('/\*(?=-)/', '*+', $expression) ?? $expression;
    $tokens = preg_split('/[+\s]+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);
    $included = [];
    $excluded = [];
    $includesAll = false;

    foreach ($tokens as $token) {
        if ($token === '*') {
            $includesAll = true;
        } elseif (strlen($token) > 1 && $token[0] === '-') {
            $excluded[] = substr($token, 1);
        } elseif ($token !== '-') {
            $included[] = $token;
        }
    }

    return [
        'included' => array_values(array_unique($included)),
        'excluded' => array_values(array_unique($excluded)),
        'includes_all' => $includesAll
    ];
}

/**
 * Boards that should appear checked in the sidebar filter, based on the URL.
 * Homepage / all-boards (*) has none selected even though it shows every board.
 */
function selected_filter_boards(?string $expression, array $allBoardNames): array {
    if ($expression === null) {
        return [];
    }

    $expression = trim($expression);
    if ($expression === '' || $expression === '*') {
        return [];
    }

    $parsed = parse_board_expression($expression);
    $excluded = $parsed['excluded'];

    if ($parsed['includes_all'] && $excluded === []) {
        return [];
    }

    if ($parsed['includes_all'] || ($parsed['included'] === [] && $excluded !== [])) {
        return array_values(array_filter(
            $allBoardNames,
            static fn(string $name): bool => !in_array($name, $excluded, true)
        ));
    }

    return array_values(array_filter(
        $allBoardNames,
        static fn(string $name): bool => in_array($name, $parsed['included'], true)
            && !in_array($name, $excluded, true)
    ));
}

/**
 * Categorize file extension into media type.
 */
function get_media_type(string $ext): string {
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
        return 'image';
    }
    if (in_array($ext, ['webm', 'mp4'])) {
        return 'video';
    }
    if (in_array($ext, ['ogg'])) {
        return 'audio';
    }
    if (in_array($ext, ['pdf', 'epub', 'txt', 'md'])) {
        return 'document';
    }
    if (in_array($ext, ['sh', 'py', 'c', 'cpp', 'h', 'js', 'css', 'html', 'json'])) {
        return 'code';
    }
    return 'file';
}

/**
 * Format bytes into human readable format.
 */
function format_bytes(int $bytes, int $precision = 1): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Safe markdown and imageboard syntax parser.
 * Supports:
 * - HTML entity escaping
 * - Greentext (>quote)
 * - Quote links (>>123)
 * - Basic markdown: **bold**, *italic*, ~~strikethrough~~, `code`
 * - Auto-linking URLs
 * - Preserving line breaks
 */
function format_comment(string $text, ?int $currentThreadId = null): string {
    // 1. Sanitize HTML
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // 2. Greentext & Quotes line-by-line processing
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    $processedLines = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        // Greentext line: starts with '>' but not '>>[0-9]'
        if (strpos($trimmed, '&gt;') === 0 && !preg_match('/^&gt;&gt;\d+/', $trimmed)) {
            $line = '<span class="greentext">' . $line . '</span>';
        }
        $processedLines[] = $line;
    }
    $text = implode("\n", $processedLines);

    // 3. Quote references >>1234
    $text = preg_replace_callback('/&gt;&gt;(\d+)/', function ($matches) use ($currentThreadId) {
        $postId = (int)$matches[1];
        // In-page anchor link
        return '<a href="#p' . $postId . '" class="quote-link">&gt;&gt;' . $postId . '</a>';
    }, $text);

    // 4. Basic Markdown
    // Code blocks / inline code: `code`
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // Bold: **text** or __text__
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);
    // Italics: *text* or _text_
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text);
    $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $text);
    // Strikethrough: ~~text~~
    $text = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $text);

    // 5. Auto-link URLs safely
    $text = preg_replace(
        '/(https?:\/\/[^\s<"\'&]+)/i',
        '<a href="$1" target="_blank" rel="nofollow noopener noreferrer">$1</a>',
        $text
    );

    // 6. Convert newlines to <br>
    return nl2br($text, false);
}

/**
 * Format an overview OP while keeping long posts from dominating the board.
 * Full thread pages continue to use format_comment() without this limit.
 */
function format_comment_preview(
    string $text,
    ?int $currentThreadId = null,
    int $maxLines = 20,
    ?string $moreUrl = null,
    string $moreLabel = 'View more'
): string {
    $normalizedText = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $normalizedText);

    if (count($lines) <= $maxLines) {
        return format_comment($normalizedText, $currentThreadId);
    }

    $preview = implode("\n", array_slice($lines, 0, $maxLines));
    $formattedPreview = format_comment($preview, $currentThreadId)
        . '<br><span class="comment-truncated">...</span>';

    if ($moreUrl !== null && $moreUrl !== '') {
        $formattedPreview .= '<br><a class="comment-truncated-link" href="'
            . htmlspecialchars($moreUrl, ENT_QUOTES, 'UTF-8')
            . '">' . htmlspecialchars($moreLabel, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    return $formattedPreview;
}

/**
 * Board/home overview: up to 3 most recent top-level replies, each with their
 * 2 most recent nested replies. If none of those top-level replies have nested
 * comments, show up to 5 most recent top-level replies instead. Nested replies
 * are never promoted to top-level in the preview.
 *
 * @return array{trees: list<array{op_reply: array, replies: list<array>}>, shown: int}
 */
function overview_comment_preview(PDO $pdo, int $threadId): array {
    $l2Stmt = $pdo->prepare("
        SELECT p.*, b.name AS board_name
        FROM posts p
        JOIN boards b ON p.board_id = b.id
        WHERE p.thread_id = :tid AND p.reply_to_id IS NULL
        ORDER BY p.created_at DESC
        LIMIT " . (int)OVERVIEW_TOPLEVEL_PREVIEW_FLAT . "
    ");
    $l2Stmt->execute([':tid' => $threadId]);
    $level2 = $l2Stmt->fetchAll();

    if ($level2 === []) {
        return ['trees' => [], 'shown' => 0];
    }

    $ids = [];
    foreach ($level2 as $post) {
        $ids[] = (int)$post['id'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $l3Stmt = $pdo->prepare("
        SELECT p.*, b.name AS board_name
        FROM posts p
        JOIN boards b ON p.board_id = b.id
        WHERE p.reply_to_id IN ($placeholders)
        ORDER BY p.created_at DESC
    ");
    $l3Stmt->execute($ids);

    $nestedByParent = [];
    foreach ($l3Stmt->fetchAll() as $row) {
        $parentId = (int)$row['reply_to_id'];
        if (!isset($nestedByParent[$parentId])) {
            $nestedByParent[$parentId] = [];
        }
        $nestedByParent[$parentId][] = $row;
    }

    $topLevelCount = OVERVIEW_TOPLEVEL_PREVIEW;
    $includeNested = false;
    foreach (array_slice($level2, 0, OVERVIEW_TOPLEVEL_PREVIEW) as $l2) {
        if (!empty($nestedByParent[(int)$l2['id']])) {
            $includeNested = true;
            break;
        }
    }
    if (!$includeNested) {
        $topLevelCount = OVERVIEW_TOPLEVEL_PREVIEW_FLAT;
    }

    $selected = array_reverse(array_slice($level2, 0, $topLevelCount));
    $trees = [];
    $shown = 0;
    foreach ($selected as $l2) {
        $replies = [];
        if ($includeNested) {
            $children = $nestedByParent[(int)$l2['id']] ?? [];
            $replies = array_reverse(array_slice($children, 0, OVERVIEW_NESTED_PREVIEW));
        }
        $trees[] = [
            'op_reply' => $l2,
            'replies' => $replies,
        ];
        $shown += 1 + count($replies);
    }

    return ['trees' => $trees, 'shown' => $shown];
}

/**
 * Generate GD thumbnail for raster images.
 */
function create_thumbnail(string $sourcePath, string $destPath, int $maxW, int $maxH, string $ext): bool {
    if (!extension_loaded('gd')) {
        return false;
    }

    $imgInfo = @getimagesize($sourcePath);
    if (!$imgInfo) {
        return false;
    }

    $srcW = $imgInfo[0];
    $srcH = $imgInfo[1];
    $mime = $imgInfo['mime'];

    // If image is already smaller than max dimensions, return original as thumb
    if ($srcW <= $maxW && $srcH <= $maxH) {
        return @copy($sourcePath, $destPath);
    }

    // Calculate aspect ratio
    $ratio = min($maxW / $srcW, $maxH / $srcH);
    $dstW = max(1, (int)round($srcW * $ratio));
    $dstH = max(1, (int)round($srcH * $ratio));

    $srcImg = null;
    switch ($mime) {
        case 'image/jpeg':
            $srcImg = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $srcImg = @imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $srcImg = @imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $srcImg = @imagecreatefromwebp($sourcePath);
            }
            break;
    }

    if (!$srcImg) {
        return false;
    }

    $dstImg = imagecreatetruecolor($dstW, $dstH);

    // Preserve transparency for PNG, GIF, WebP
    if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
        imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
    }

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    $success = false;
    switch ($mime) {
        case 'image/jpeg':
            $success = @imagejpeg($dstImg, $destPath, 85);
            break;
        case 'image/png':
            $success = @imagepng($dstImg, $destPath, 8);
            break;
        case 'image/gif':
            $success = @imagegif($dstImg, $destPath);
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) {
                $success = @imagewebp($dstImg, $destPath, 85);
            }
            break;
    }

    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return $success;
}

/**
 * Handle file upload processing.
 */
function handle_file_upload(array $fileData, bool $isOp): ?array {
    if (!isset($fileData['error']) || $fileData['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($fileData['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed with error code: ' . $fileData['error']);
    }

    $originalName = basename($fileData['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        throw new Exception('File type not allowed: .' . htmlspecialchars($ext));
    }

    // Generate unique random filename to prevent collision and script execution
    $randomName = time() . '_' . bin2hex(random_bytes(6));
    $storedFileName = $randomName . '.' . $ext;
    $targetPath = UPLOAD_DIR . '/' . $storedFileName;

    if (!@move_uploaded_file($fileData['tmp_name'], $targetPath)) {
        if (!@copy($fileData['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save uploaded file to destination.');
        }
    }

    $mediaType = get_media_type($ext);
    $thumbFileName = null;
    $thumbPath = null;

    // Create thumbnail if raster image
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $thumbName = 'thumb_' . $randomName . '.' . ($ext === 'webp' ? 'webp' : ($ext === 'png' ? 'png' : 'jpg'));
        $thumbDest = UPLOAD_DIR . '/' . $thumbName;
        $maxW = $isOp ? THUMB_MAX_W_OP : THUMB_MAX_W_REPLY;
        $maxH = $isOp ? THUMB_MAX_H_OP : THUMB_MAX_H_REPLY;

        if (create_thumbnail($targetPath, $thumbDest, $maxW, $maxH, $ext)) {
            $thumbFileName = $thumbName;
        }
    }

    return [
        'file_path' => 'uploads/' . $storedFileName,
        'thumb_path' => $thumbFileName ? 'uploads/' . $thumbFileName : null,
        'file_original_name' => $originalName,
        'file_size' => (int)$fileData['size'],
        'file_ext' => $ext,
        'media_type' => $mediaType
    ];
}
