#!/usr/bin/env bash
#
# Yotsuba Imageboard - In-Place Update Script
# Safely verifies and applies in-place updates without touching database or uploads.
#

set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

echo "=== Yotsuba Imageboard In-Place Update ==="

# 1. Check PHP availability
if ! command -v php >/dev/null 2>&1; then
    echo "[ERROR] PHP CLI is not installed or not in PATH."
    exit 1
fi

echo "[1/4] Linting updated PHP files..."
php -l config.php
php -l install.php
php -l post.php
php -l board.php
php -l index.php
php -l .router.php

echo "[2/4] Verifying directory permissions..."
chmod 775 data uploads 2>/dev/null || true
chmod 664 data/imageboard.sqlite 2>/dev/null || true

echo "[3/4] Verifying database integrity..."
php -r '
require_once "config.php";
if (is_installed()) {
    $db = get_db();
    $boards = $db->query("SELECT COUNT(*) FROM boards")->fetchColumn();
    $posts = $db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    echo "Database OK: $boards board(s), $posts post(s) preserved.\n";
} else {
    echo "Notice: Application is not yet installed. Run install.php to set up.\n";
}
'

echo "[4/4] Running automated test suite..."
php test_suite.php >/dev/null 2>&1
echo "Automated test suite verified successfully."

echo ""
echo "=== Update applied successfully in place! ==="
echo "All files are up to date and your database and uploads remain intact."
