<?php
/**
 * The book viewer: a paper's book taken off the shelf and held up to look at.
 *
 * Pressing a book on the public repository's Shelf view or in My Library opens
 * this. The book flies from where it stood to the middle of the window as a
 * whole object, all six faces of it (the hardbound cover, the back, the spine
 * with its stamping, the page edges top, bottom and fore-edge), turns once to
 * show itself, and can then be turned any way by dragging: it glides on when
 * let go, the way a thing spun by hand does. Pressing it sets it down into the
 * details card, the pop-up My Library always had (the book small on the left,
 * what the paper is on the right, Read and Save under it), and pressing the
 * small book there picks it up again.
 *
 * One component for both pages, so the book is held the same way wherever it
 * was taken from: the repository and My Library each print their books and
 * then call window.papelBookViewer.open(book, info) with what they know about
 * the paper. The board and its stamping are the shared hardbound cover
 * (includes/book_covers.php): the viewer copies the front face from the book
 * that was pressed, so it is that same cover, bigger.
 *
 * Things it has to respect, each met before somewhere on this site:
 *   - It is a <dialog>: the top layer, Escape, and focus held, with no z-index
 *     to pick on a page that has a banner, a strip and a countdown already.
 *   - The page behind is held still on <html>, never on <body> (html.bv-locked).
 *   - A drag follows the pointer on the document, never with
 *     setPointerCapture: capture retargets the click that follows, which is
 *     exactly what made the keyword strip's chips stop answering.
 *   - A drag that ends off the book raises a click on the dialog, which is the
 *     backdrop's "close": that one click is swallowed.
 *   - Nothing it changes on every frame is written on :root: the transforms go
 *     on the two elements that move.
 *
 * Include near the end of <body>, after the page's own books, once
 * book_covers_style() has printed in <head>.
 */

if (defined('PAPEL_BOOK_VIEWER_PRINTED')) { return; }
define('PAPEL_BOOK_VIEWER_PRINTED', true);
?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-book-viewer.css')) ?>">

<dialog class="bv" id="bookViewer" aria-labelledby="bvTitle">
    <button type="button" class="bv-x" data-bv-close aria-label="Close">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
    </button>

    <?php /* The bookmark. No data-save-paper until a book is in the window:
             the shared script finds its buttons by that attribute, and an
             empty one would answer for a paper that is not there. */ ?>
    <button type="button" class="save-paper bv-save" aria-pressed="false"
            aria-label="Save to My Library" title="Save to My Library" hidden>
        <span class="material-symbols-outlined" aria-hidden="true">bookmark</span>
    </button>

    <div class="bv-card">
        <button type="button" class="bv-close" data-bv-close aria-label="Close">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
        <div class="bv-slot"><div class="bv-slot-book"></div></div>
        <div class="bv-body">
            <p class="bv-kicker"></p>
            <h2 class="bv-title" id="bvTitle"></h2>
            <p class="bv-authors"></p>
            <p class="bv-abstract"></p>
            <p class="bv-note"></p>
            <div class="bv-actions"></div>
        </div>
    </div>

    <div class="bv-layer">
        <div class="bv-mover">
            <div class="bv-floor" aria-hidden="true"></div>
            <?php /* role="button" and a label, because it is the control that
                     sets the book down into the card and picks it up again, and
                     the arrow keys turn it. */ ?>
            <div class="bv-book" tabindex="0" role="button"
                 aria-label="The book. Drag or use the arrow keys to turn it; press Enter to open its details.">
                <div class="bv-face bv-front bk-face"></div>
                <div class="bv-face bv-back" aria-hidden="true"></div>
                <div class="bv-face bv-spine" aria-hidden="true"><div class="bv-spine-text"></div></div>
                <div class="bv-face bv-edge" aria-hidden="true"></div>
                <div class="bv-face bv-top" aria-hidden="true"></div>
                <div class="bv-face bv-bottom" aria-hidden="true"></div>
                <?php /* Not a .bv-face: that class hides a face turned away,
                         and a ribbon standing out of the top of a book is
                         seen from behind it as well as in front. */ ?>
                <div class="bv-ribbon" aria-hidden="true"></div>
            </div>
        </div>
    </div>

    <button type="button" class="bv-nav bv-prev" data-bv-step="-1" aria-label="Previous paper" hidden>
        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
    </button>
    <button type="button" class="bv-nav bv-next" data-bv-step="1" aria-label="Next paper" hidden>
        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
    </button>

    <p class="bv-hint" aria-hidden="true">
        <span class="material-symbols-outlined">3d_rotation</span><span class="bv-hint-words">Drag to turn the book &middot; click it to see the details</span>
    </p>
</dialog>

<script src="<?= e(asset_url('assests/js/papel-book-viewer.js')) ?>"></script>
