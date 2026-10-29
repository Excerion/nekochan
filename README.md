# Yotsuba Imageboard (PHP & SQLite)

A lightweight, zero-dependency, self-contained imageboard built with native PHP and SQLite, featuring the classic 4chan Yotsuba theme.

---

## Key Features

- **Installation Wizard (`install.php`)**: One-click installer for shared hosting that validates PHP requirements, sets up the SQLite schema, creates indices, and seeds the initial `/random/` board.
- **Strict 3-Level Post Hierarchy**:
  1. **Level 1**: Thread OP (Title & media upload required).
  2. **Level 2**: Top-Level Replies (comments to thread OP).
  3. **Level 3**: Indented Replies-to-Replies (nested comments under a top-level reply). Replies targeting a Level 3 comment automatically clamp to Level 2 to prevent endless nesting.
- **Reply Action Flow**:
   - **Beneath OP**: `Reply` button opens the thread, scrolls to `#post-form`, and sets up a top-level reply with an empty textarea.
   - **Bottom of Comment Tree**: `Reply` button opens the thread, scrolls to `#post-form`, pre-fills `>>[post number]` into the textarea, and binds the reply to that specific top-level comment tree.
- **Dynamic Board Aggregation**:
  - Full board names (e.g. `/random/`, `/videogames/`, `/technology/`).
   - Custom aggregation URLs: `/random+videogames/` displays threads from both boards.
   - Boolean board expressions support `*` for all boards and `-boardname` exclusions, such as `/*+-politics/` or `/-politics/`.
   - `/` redirects to the canonical all-board aggregate at `/*/`.
   - Thread URLs preserve their originating board context, e.g. `/random+videogames/res/123/`, including the Return link and post redirects.
  - When posting from a dynamic view, it automatically submits to the **first board** in the list.
- **Homepage Overview**:
  - Aggregates threads across **all boards**.
  - Form submissions from the homepage post directly to `/random/`.
   - OP previews on board overview pages are limited to 20 source lines; full thread pages show the complete post.
   - Overview reply previews show the 3 most recent top-level comments and, under each, the 2 most recent nested replies. If those top-level comments have no nested replies, the preview shows up to 5 most recent top-level replies instead. Nested replies are never shown as top-level on board overviews.
- **Synchronized Right Sidebar**:
  - **Quick Tools**: Anchor links to `[New Thread]` (`#post-form`) and `[Full Board List]` (`#board-list`).
   - **Recent Threads in View**: Displays the titles of the 20 most recent threads *visible on the current page*, ordered by `bumped_at` like the main thread list and linking via in-page anchors (`#p[id]`) to scroll directly to the OP.
  - **Filter Boards**: Checkbox list of every board. Checking names and pressing Filter goes to `/board1+board2/`. A top toggle selects or deselects all boards. Homepage (`/*/`) shows every board with none checked; other URLs pre-check the boards in the path. Clicking a board title toggles its checkbox.
- **4-Column Alphabetical Footer Directory**:
  - Alphabetical list of all boards split into 4 balanced columns.
  - "New Board" form at the very bottom with name and rectangular description box (description may be left empty).
- **Moderator Panel (`mod.php`)**: Password-only (no username). First visit sets a password with confirmation; later visits require that password. Once signed in, every board, aggregate, and thread page has a matching moderator view. Links, filters, pagination, posting, and new-board creation stay on `mod.php` while keeping the current board expression. **Delete files** removes attachments from checked posts; **Delete posts** removes the posts (an OP wipes its thread). **Ban IPs** opens a confirmation page for a reason (and optional length) before the ban is applied. Poster IPs are logged at post time and shown to mods. Banned visitors see a 4chan-style "You are banned" page. Delete/ban buttons sit above the new thread form. All moderator URLs require the password.
- **Supported File & Media Formats**:
  - **Images**: `jpg`, `jpeg`, `png`, `gif`, `webp`, `svg` (GD thumbnails generated for raster images; full-size toggle on click).
  - **Videos & Audio**: `webm`, `mp4`, `ogg` (native HTML5 media players).
  - **Documents & Code**: `pdf`, `epub`, `txt`, `sh`, `py`, `c`, `cpp`, `h`, `js`, `html`, `css`, `json`, `md`.
- **Basic Markdown & Formatting**:
  - `**bold**` &rarr; **bold**
  - `*italic*` &rarr; *italic*
  - `~~strike~~` &rarr; ~~strike~~
  - `` `code` `` &rarr; `code`
  - `>greentext` &rarr; green quotation line
  - `>>[post number]` &rarr; quote link
  - Auto-linked URLs (`https://...`)

---

## Shared Hosting Deployment

1. Upload all files into your `public_html` (or a subdirectory).
2. Ensure `uploads/` and `data/` directories are writable by the web server (`chmod 775` or `chmod 777`).
3. Visit `http://your-domain.com/install.php` in your browser.
4. Click **Start Installation** to initialize the database and create `/random/`.
5. Enjoy your imageboard! Direct requests to `/` will load the homepage.

---

## Local Development & Testing

To run locally using PHP's built-in web server:

```bash
php -S 127.0.0.1:8080 .router.php
```

To run the automated verification test suite:

```bash
php test_suite.php
```
