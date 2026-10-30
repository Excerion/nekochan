/**
 * Yotsuba Imageboard - Frontend Interactions
 */

// Scroll down smoothly to post form for replying to OP (top-level reply)
function replyToOp() {
    const replyToInput = document.getElementById('form-reply-to-id');
    const replyModeLabel = document.getElementById('reply-mode-label');
    const commentBox = document.getElementById('form-comment');

    if (replyToInput) {
        replyToInput.value = '';
    }
    if (replyModeLabel) {
        replyModeLabel.textContent = 'Top-Level Comment';
        replyModeLabel.style.color = '#117743';
    }

    scrollToElement('post-form');
    if (commentBox) {
        commentBox.focus();
    }
}

// Scroll down smoothly to post form for replying to a comment tree (Level 3 nested reply)
function replyToComment(topLevelCommentId, quotePostId) {
    const replyToInput = document.getElementById('form-reply-to-id');
    const replyModeLabel = document.getElementById('reply-mode-label');
    const commentBox = document.getElementById('form-comment');

    if (replyToInput) {
        replyToInput.value = topLevelCommentId;
    }
    if (replyModeLabel) {
        replyModeLabel.textContent = 'Reply to Comment #' + topLevelCommentId;
        replyModeLabel.style.color = '#800000';
    }

    if (commentBox) {
        const quoteText = '>>' + quotePostId + '\n';
        if (!commentBox.value.includes(quoteText.trim())) {
            commentBox.value = quoteText + commentBox.value;
        }
        commentBox.focus();
    }

    scrollToElement('post-form');
}

// Insert quote >>[id] on clicking post numbers
function insertQuote(postId) {
    const commentBox = document.getElementById('form-comment');
    if (commentBox) {
        const quoteText = '>>' + postId + '\n';
        const start = commentBox.selectionStart;
        const end = commentBox.selectionEnd;
        const val = commentBox.value;

        commentBox.value = val.substring(0, start) + quoteText + val.substring(end);
        commentBox.focus();
        commentBox.selectionStart = commentBox.selectionEnd = start + quoteText.length;
    }
}

// Smooth scroll to an element by ID
function scrollToElement(id) {
    const el = document.getElementById(id);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // Flash animation for visual focus
        el.classList.add('flash-target');
        setTimeout(() => el.classList.remove('flash-target'), 1500);
    }
}

function syncExpandedLayout(img) {
    const post = img.closest('.post');
    if (!post) {
        return;
    }
    if (!img.classList.contains('expanded')) {
        post.classList.remove('expanded-too-big');
        return;
    }

    const postWidth = post.clientWidth;
    const imageWidth = img.clientWidth || img.naturalWidth || 0;
    const tooBig = imageWidth > 0 && (imageWidth + 200 > postWidth || imageWidth > postWidth * 0.55);
    post.classList.toggle('expanded-too-big', tooBig);
}

document.addEventListener('DOMContentLoaded', () => {
    // Replies need a comment only when no file is attached. New threads keep the required attribute.
    const postForm = document.querySelector('#post-form form');
    const commentBox = document.getElementById('form-comment');
    const fileInput = postForm ? postForm.querySelector('input[type="file"][name="file"]') : null;
    const isReply = postForm && postForm.querySelector('input[name="thread_id"]');

    if (postForm && commentBox && fileInput && isReply) {
        const syncReplyCommentRequired = () => {
            const hasFile = fileInput.files && fileInput.files.length > 0;
            if (hasFile) {
                commentBox.removeAttribute('required');
                commentBox.setCustomValidity('');
            } else {
                commentBox.setAttribute('required', 'required');
            }
        };

        fileInput.addEventListener('change', syncReplyCommentRequired);
        postForm.addEventListener('submit', syncReplyCommentRequired);
        syncReplyCommentRequired();
    }

    // 1. Handle in-page anchor links with smooth scroll
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href').substring(1);
            if (!targetId) return;

            const targetElement = document.getElementById(targetId);
            if (targetElement) {
                e.preventDefault();
                targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                history.pushState(null, null, '#' + targetId);
            }
        });
    });

    // 2. Inline image expansion
    document.querySelectorAll('.image-expand').forEach(link => {
        link.addEventListener('click', function (e) {
            // Only expand if clicking directly on image (allow middle click / right click for new tab)
            if (e.which === 1 && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                e.preventDefault();
                const img = this.querySelector('.post-thumb');
                if (!img) return;

                const fullSrc = this.getAttribute('href');
                const thumbSrc = img.dataset.thumbSrc || img.getAttribute('src');

                if (!img.dataset.thumbSrc) {
                    img.dataset.thumbSrc = thumbSrc;
                }

                if (img.classList.contains('expanded')) {
                    img.classList.remove('expanded');
                    img.src = thumbSrc;
                    syncExpandedLayout(img);
                } else {
                    img.classList.add('expanded');
                    img.src = fullSrc;
                    if (img.complete) {
                        syncExpandedLayout(img);
                    } else {
                        img.addEventListener('load', () => syncExpandedLayout(img), { once: true });
                    }
                }
            }
        });
    });

    // 3. Pre-fill reply mode if loaded with reply_to in query
    const params = new URLSearchParams(window.location.search);
    if (params.has('reply_to')) {
        const topLevelId = params.get('reply_to');
        const replyModeLabel = document.getElementById('reply-mode-label');
        if (replyModeLabel) {
            replyModeLabel.textContent = 'Reply to Comment #' + topLevelId;
            replyModeLabel.style.color = '#800000';
        }
    }

    // 4. Sidebar board filter: select / deselect all
    const filterForm = document.getElementById('board-filter-form');
    if (filterForm) {
        const toggleAll = document.getElementById('board-filter-toggle-all');
        const saveFilterButton = document.getElementById('board-filter-save');
        const boardBoxes = Array.from(filterForm.querySelectorAll('input[name="boards[]"]'));

        const syncToggleAll = () => {
            if (!toggleAll || boardBoxes.length === 0) {
                return;
            }
            const checkedCount = boardBoxes.filter((box) => box.checked).length;
            toggleAll.checked = checkedCount === boardBoxes.length;
            toggleAll.indeterminate = checkedCount > 0 && checkedCount < boardBoxes.length;
        };

        if (toggleAll) {
            toggleAll.addEventListener('change', () => {
                boardBoxes.forEach((box) => {
                    box.checked = toggleAll.checked;
                });
                toggleAll.indeterminate = false;
            });
        }

        boardBoxes.forEach((box) => {
            box.addEventListener('change', syncToggleAll);
        });

        if (saveFilterButton) {
            saveFilterButton.addEventListener('click', () => {
                const bookmarkUrl = filterForm.dataset.bookmarkUrl || '';
                if (!bookmarkUrl) {
                    return;
                }

                const absoluteBookmarkUrl = new URL(bookmarkUrl, window.location.href).href;
                window.prompt('Bookmark this board filter overview URL:', absoluteBookmarkUrl);
            });
        }

        syncToggleAll();
    }

    window.addEventListener('resize', () => {
        document.querySelectorAll('.post-thumb.expanded').forEach((img) => {
            syncExpandedLayout(img);
        });
    });
});
