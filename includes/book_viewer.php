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
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ===== The window ===== */
html.bv-locked { overflow: hidden; }
.bv {
    position: fixed; inset: 0;
    width: 100vw; height: 100dvh; max-width: none; max-height: none;
    margin: 0; padding: 0; border: 0;
    color: var(--ink);
    overflow: hidden;
    /* The veil is the dialog's own background rather than ::backdrop, so the
       two modes ease from one tone to the other: darker while the book is held
       up, since a busy shelf behind it competes with it, and My Library's own
       tone under the card. Measured, not judged from a scaled-down capture
       (which reads far lighter than it is): white behind the darker veil
       comes out at about 68 of 255. */
    background: rgba(16, 7, 7, .78);
    transition: background-color .3s ease;
}
.bv::backdrop { background: transparent; }
.bv.is-details { background: rgba(20, 8, 8, .55); }
.bv[open] { animation: bv-in .2s ease-out; }
@keyframes bv-in { from { background-color: transparent; } }

/* The close, top right, while the book is held up; the card has its own. */
.bv-x {
    position: absolute; top: .75rem; right: .75rem; z-index: 3;
    display: inline-flex; padding: .35rem; border: 0; border-radius: 50%;
    background: rgba(0, 0, 0, .28); color: #fff; cursor: pointer;
}
.bv-x:hover { background: rgba(0, 0, 0, .45); }
.bv-x:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.bv.is-details .bv-x { display: none; }

/* The bookmark, top left, opposite the close: the one control on the book
   while it is held up, so a paper can be saved from here without setting it
   down into the card. It is the shared button (includes/save_paper.php), so
   one press answers for every copy of itself - the card's own Save, the mark
   on the shelf behind - and the ribbon follows it. In the clothes the close
   wears, because it stands on the veil and not on a card; the page's own
   tokens for a mark are drawn to be read on the page. Hidden with the book
   when it is set down, the card having a Save of its own, and printed at all
   only for a reader who has a library. */
.bv .bv-save {
    position: absolute; top: .75rem; left: .75rem; z-index: 3;
    display: inline-flex; padding: .35rem; border: 0; border-radius: 50%;
    background: rgba(0, 0, 0, .28); color: #fff; cursor: pointer;
}
.bv .bv-save:hover { background: rgba(0, 0, 0, .45); color: #fff; }
.bv .bv-save:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.bv .bv-save .material-symbols-outlined { font-size: 22px; }
/* --maroon-surface, the fill: --maroon is --accent-light, which under Old
   Night is plain white, so a disc filled from it under a white glyph is a
   white mark on a white disc. The same rule as the colour picker's radio in
   includes/theme.php, and the one that block states for Old Night. */
.bv .bv-save.is-saved { background: var(--maroon-surface); color: #fff; }
/* The page's --bad-text is a dark red, meant for a white card; on this veil
   it cannot be read, so the refusal is said in a pale tint of it and in the
   tooltip the shared script writes. */
.bv .bv-save.is-failed { color: #ffd2cc; }
.bv .bv-save[hidden], .bv.is-details .bv-save { display: none; }

.bv-hint {
    position: absolute; left: 0; right: 0; bottom: 1.25rem; z-index: 2;
    margin: 0; text-align: center; pointer-events: none;
    font-size: .8125rem; letter-spacing: .02em; color: #fff; opacity: .88;
    transition: opacity .2s;
}
.bv-hint .material-symbols-outlined { font-size: 16px; vertical-align: -3px; margin-right: .25rem; }
.bv.is-details .bv-hint { opacity: 0; }

/* ---- Previous and next, at the window's edges ----
   The way a file preview steps through a folder: a round arrow at each side,
   half way down, white on a dark disc so it reads on any shelf behind. Gone
   at either end of the shelf rather than greyed, since an arrow that does
   nothing is one more thing to read, and gone under the card, which is about
   one paper. */
.bv-nav {
    position: absolute; top: 50%; z-index: 3;
    display: inline-flex; align-items: center; justify-content: center;
    width: 3rem; height: 3rem; margin-top: -1.5rem;
    padding: 0; border: 0; border-radius: 50%;
    background: rgba(0, 0, 0, .38); color: #fff; cursor: pointer;
    transition: background .15s, opacity .2s;
}
.bv-nav:hover { background: rgba(0, 0, 0, .6); }
.bv-nav:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.bv-nav .material-symbols-outlined { font-size: 30px; }
/* In from the edges, clear of the Accessibility tab, which stands at the
   left edge of every page (36px) and showed under the arrow through the veil. */
.bv-prev { left: 3rem; }
.bv-next { right: 3rem; }
.bv-nav[hidden], .bv.is-details .bv-nav { display: none; }
/* While one book goes and the next comes, the mover fades as well as slides. */
.bv-mover { transition: opacity .2s ease; }
.bv-mover.is-away { opacity: 0; }

/* ===== The book, as an object =====
   Six faces about one centre: --w, --h and --d are its width, height and
   thickness, set from script to suit the window, and every face is placed
   from them. Its own perspective is part of its transform (written from
   script), so it is seen from the same place whether it is held up in the
   middle of the window or set down in the corner of the card. */
.bv-layer {
    position: absolute; inset: 0; z-index: 2;
    pointer-events: none;
}
.bv-mover {
    position: absolute; left: 50%; top: 50%;
    width: 0; height: 0;
    will-change: transform;
}
/* Its shadow on the floor, which stays on the floor however the book turns. */
.bv-floor {
    position: absolute;
    left: calc(var(--w) * -.62); width: calc(var(--w) * 1.24);
    top: calc(var(--h) / 2 + var(--w) * .05); height: calc(var(--w) * .2);
    background: radial-gradient(closest-side, rgba(0, 0, 0, .42), rgba(0, 0, 0, 0));
    pointer-events: none;
}
.bv.is-details .bv-floor { opacity: 0; transition: opacity .2s; }
.bv-book {
    position: absolute;
    left: calc(var(--w) / -2); top: calc(var(--h) / -2);
    width: var(--w); height: var(--h);
    transform-style: preserve-3d;
    pointer-events: auto;
    cursor: grab;
    touch-action: none;
    outline: none;
    will-change: transform;
}
.bv-book.is-held { cursor: grabbing; }
.bv.is-details .bv-book { cursor: zoom-in; }
.bv-face {
    position: absolute;
    backface-visibility: hidden; -webkit-backface-visibility: hidden;
}
.bv-front, .bv-back { left: 0; top: 0; width: var(--w); height: var(--h); }
.bv-front {
    transform: translateZ(calc(var(--d) / 2));
    border-radius: 2px 5px 5px 2px;
    overflow: hidden;
}
.bv-book:focus-visible .bv-front { box-shadow: 0 0 0 3px #fff, 0 0 0 6px var(--maroon); }

/* ---- Nothing cut on the held-up book ----
   The shelf's cover clamps the title, cuts a byline with an ellipsis and keeps
   every line to one. Held up, everything is stamped in full and wraps, and the
   type is sized by --t: the cover's own scale times --fit, which script brings
   down until it all fits the board. The margins keep --s, the board's scale,
   so the spine cloth and the hinge are the same width on the front as on the
   back. Prefixed with .bv so they outweigh the shared rules, :has() included. */
.bv .bv-front { --t: calc(var(--bk-scale) * var(--fit, 1)); justify-content: flex-start; }
.bv .bv-front .bk-band {
    font-size: calc(5.4px * var(--t));
    white-space: normal; overflow: visible; text-overflow: clip;
}
.bv .bv-front .bk-title {
    display: block; -webkit-line-clamp: unset; overflow: visible;
    font-size: calc(8.2px * var(--t));
}
.bv .bv-front .bk-kind {
    display: block; -webkit-line-clamp: unset; overflow: visible;
    margin-top: calc(7px * var(--t));
    font-size: calc(5.4px * var(--t));
}
.bv .bv-front .bk-foot { gap: calc(1.5px * var(--t)); padding-top: calc(6px * var(--t)); }
.bv .bv-front .bk-by-label { font-size: calc(4.6px * var(--t)); }
.bv .bv-front .bk-by,
.bv .bv-front .bk-mark {
    white-space: normal; overflow: visible; text-overflow: clip;
    line-height: 1.35;
}
.bv .bv-front .bk-by { font-size: calc(5.6px * var(--t)); }
.bv .bv-front .bk-program { margin-top: calc(5px * var(--t)); font-size: calc(5px * var(--t)); letter-spacing: .1em; }
.bv .bv-front .bk-year    { margin-top: calc(2px * var(--t)); font-size: calc(5.6px * var(--t)); }
/* The back board: the same leatherette, blank, as a bound paper's is. Turned
   half round, so the spine cloth that wraps onto it is drawn on its right,
   which is the book's left. */
.bv-back {
    transform: rotateY(180deg) translateZ(calc(var(--d) / 2));
    border-radius: 5px 2px 2px 5px;
    background-color: var(--cover);
    background-image:
        linear-gradient(242deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, 0) 36%,
                                rgba(0, 0, 0, 0) 62%, rgba(0, 0, 0, .22)),
        linear-gradient(270deg,
            transparent             calc(9px  * var(--bk-scale)),
            rgba(0, 0, 0, .34)      calc(9px  * var(--bk-scale)), rgba(0, 0, 0, .34)      calc(10px * var(--bk-scale)),
            rgba(255, 255, 255, .13) calc(10px * var(--bk-scale)), rgba(255, 255, 255, .13) calc(11px * var(--bk-scale)),
            transparent             calc(11px * var(--bk-scale))),
        linear-gradient(270deg, var(--cover-spine) 0 calc(7px * var(--bk-scale)), transparent calc(7px * var(--bk-scale))),
        radial-gradient(circle, rgba(255, 255, 255, .05) 0 .5px, transparent .9px),
        radial-gradient(circle, rgba(0, 0, 0, .055) 0 .5px, transparent 1px),
        radial-gradient(circle, rgba(0, 0, 0, .04) 0 .6px, transparent 1.1px);
    background-size: auto, auto, auto, 3px 3px, 5px 5px, 7px 7px;
    background-position: 0 0, 0 0, 0 0, 0 0, 1px 2px, 3px 1px;
}

/* The spine: the cloth, and what is stamped down it, the way the campus's
   bound papers are: the programme at the head, the title, the year at the
   foot, each between a pair of gold bars. */
.bv-spine, .bv-edge { left: calc((var(--w) - var(--d)) / 2); top: 0; width: var(--d); height: var(--h); }
.bv-spine {
    transform: rotateY(-90deg) translateZ(calc(var(--w) / 2));
    background-color: var(--cover-spine);
    background-image:
        linear-gradient(90deg, rgba(0, 0, 0, .28), rgba(255, 255, 255, .10) 45%, rgba(0, 0, 0, .30)),
        radial-gradient(circle, rgba(255, 255, 255, .05) 0 .5px, transparent .9px),
        radial-gradient(circle, rgba(0, 0, 0, .06) 0 .5px, transparent 1px);
    background-size: auto, 3px 3px, 5px 5px;
    color: var(--cover-spine-foil, var(--cover-foil));
    overflow: hidden;
}
.bv-spine-text {
    position: absolute; left: 50%; top: 50%;
    width: var(--h); height: var(--d);
    transform: translate(-50%, -50%) rotate(90deg);
    display: flex; align-items: center; justify-content: center;
    gap: calc(5px * var(--bk-scale));
    padding: 0 calc(9px * var(--bk-scale));
    font-family: var(--font-body), 'Inter', sans-serif;
    font-size: calc(5.2px * var(--bk-scale)); font-weight: 800;
    letter-spacing: .1em; text-transform: uppercase; white-space: nowrap;
    text-shadow: 0 1px 0 rgba(0, 0, 0, .35);
}
.bv-bars {
    flex: 0 0 auto;
    width: calc(3.5px * var(--bk-scale)); height: 62%;
    border-left: calc(.9px * var(--bk-scale)) solid currentColor;
    border-right: calc(.9px * var(--bk-scale)) solid currentColor;
}
/* The whole title, on up to two lines and as small as it has to be (script
   brings --fit down until it fits), rather than one line cut short. */
.bv-spine-title {
    flex: 1 1 auto; min-width: 0; max-height: 100%;
    overflow: hidden; text-align: center;
    white-space: normal; line-height: 1.15;
    font-size: calc(5.2px * var(--bk-scale) * var(--fit, 1));
}
.bv-spine-end { flex: 0 0 auto; }

/* The page edges. Paper is paper on a dark page too, so its ground is a
   literal white, drawn in the palette's own soft tone like the shelf's. */
.bv-edge {
    transform: rotateY(90deg) translateZ(calc(var(--w) / 2));
    background:
        linear-gradient(90deg, rgba(0, 0, 0, .14), rgba(0, 0, 0, 0) 18%, rgba(0, 0, 0, 0) 82%, rgba(0, 0, 0, .14)),
        repeating-linear-gradient(90deg,
            color-mix(in srgb, var(--soft-maroon) 6%, #fff) 0 1.4px,
            color-mix(in srgb, var(--soft-maroon) 32%, #fff) 1.4px 2.3px);
}
.bv-top, .bv-bottom { left: 0; top: calc((var(--h) - var(--d)) / 2); width: var(--w); height: var(--d); }
.bv-top    { transform: rotateX(90deg) translateZ(calc(var(--h) / 2)); }
.bv-bottom { transform: rotateX(-90deg) translateZ(calc(var(--h) / 2)); }
.bv-top, .bv-bottom {
    background:
        /* the spine at the left end, the boards along the long sides */
        linear-gradient(90deg, var(--cover-spine) 0 4%, transparent 4%),
        linear-gradient(0deg, var(--cover) 0 6%, transparent 6% 94%, var(--cover) 94%),
        repeating-linear-gradient(0deg,
            color-mix(in srgb, var(--soft-maroon) 6%, #fff) 0 1.4px,
            color-mix(in srgb, var(--soft-maroon) 30%, #fff) 1.4px 2.3px);
}

/* ---- The gap it left on the shelf ----
   A book held up here is not also standing on the shelf behind it. The one
   that was pressed is hidden for as long as it is in the window, so the
   flight reads as that book being pulled out rather than as a copy of it
   appearing: the board shows a gap where it stood, the way a shelf does when
   somebody has taken a paper off it.

   visibility, not display: the slot keeps its width, so the gap stays a gap
   and the books on either side do not slide along to close it. The class
   goes on the element the page handed over (.shelf-book here, .book in My
   Library), so a page needs no rule of its own; the shelf adds one for the
   bookmark above the slot, which would otherwise hang over the space. */
.bv-taken { visibility: hidden; }

/* ---- The bookmark ribbon ----
   A saved paper carries its ribbon up off the shelf with it: the same one
   includes/shelf_view.php tucks into the pages, at this book's size (every
   figure is the shelf's, times --bk-scale). It is a face of the book, in the
   book's own preserve-3d space at half its thickness, so the boards hide the
   part that is inside by depth rather than by a picture of depth, it turns
   with the book, and it goes down into the details card with it, that card's
   book being this same element made smaller.

   Shown while the window holds a Save button that says saved, which is the
   class the shared script keeps painted on every copy of itself
   (includes/save_paper.php). So it answers a press here, in the card, or on
   the shelf behind, with nothing to keep in step; a reader with no library
   has no such button and so no ribbon. Out of the book and above it until
   then, the way the shelf's slides in. */
.bv-ribbon {
    position: absolute;
    right: calc(13px * var(--bk-scale, 1));
    top: calc(-22px * var(--bk-scale, 1));
    width: calc(12px * var(--bk-scale, 1));
    height: calc(74px * var(--bk-scale, 1));
    clip-path: polygon(0 0, 50% 16%, 100% 0, 100% 100%, 0 100%);
    background:
        linear-gradient(90deg, rgba(0, 0, 0, .28), rgba(255, 255, 255, .14) 45%, rgba(0, 0, 0, .22)),
        var(--maroon-surface);
    pointer-events: none;
    opacity: 0;
    transform: translateZ(0) translateY(calc(-36px * var(--bk-scale, 1)));
    transition: transform .5s cubic-bezier(.2, .9, .25, 1), opacity .25s ease;
}
.bv:has(.save-paper.is-saved) .bv-ribbon {
    opacity: 1;
    transform: translateZ(0) translateY(0);
}

/* ===== The details card =====
   My Library's pop-up, the same layout and the same figures: the book small on
   the left (the slot here is only its place; the book itself is the one held
   up a moment ago, set down over it), the paper on the right. */
.bv-card {
    position: absolute; left: 50%; top: 50%; z-index: 1;
    transform: translate(-50%, -50%);
    width: min(44rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem);
    display: flex; gap: 1.5rem; padding: 1.5rem;
    border-radius: var(--r-card, 8px);
    background: var(--white); color: var(--ink);
    box-shadow: 0 18px 48px rgba(0, 0, 0, .35);
    overflow: auto; overscroll-behavior: contain;
    opacity: 0; visibility: hidden;
    transition: opacity .25s ease, visibility 0s linear .25s;
}
.bv.is-details .bv-card {
    opacity: 1; visibility: visible;
    /* After the book has most of the way to go, so the card arrives to meet it. */
    transition: opacity .28s ease .22s, visibility 0s;
}
/* The top padding is the ribbon's: a saved paper's bookmark stands out of the
   top of the book, and the book is set down here at half the size it was held
   up at, so the end needs about 31px of room above the board (22px at the
   shelf's 108px, times the slot's width over that). With the card's own
   1.5rem it was 2px short, and the swallowtail crossed the card's top edge
   into the veil - a sliver whose width changed with the window. The ribbon
   stands out of the book, not out of the card. Measured, not judged by eye. */
.bv-slot { flex: 0 0 auto; width: calc(150px + 34px); padding: 1rem 0 0 .5rem; }
.bv-slot-book { width: 150px; height: 216px; }
.bv-body { flex: 1 1 auto; min-width: 0; padding-right: 1.5rem; }
.bv-kicker { margin: 0 0 .375rem; font-size: .6875rem; letter-spacing: .06em; text-transform: uppercase; color: var(--grey); }
.bv-title { margin: 0; font-family: var(--font-head); font-size: 1.125rem; font-weight: 600; line-height: 1.3; color: var(--maroon); }
.bv-authors { margin: .375rem 0 0; font-size: .8125rem; color: var(--ink); }
.bv-abstract { margin: .875rem 0 0; font-size: .8125rem; line-height: 1.6; color: var(--ink); }
/* The card's prose is justified, as a bound paper's own page is: the three
   blocks that run to more than a line, and not the title or the kicker above
   them, which are headings and would only have their words pulled apart. The
   last line of each stays ranged left, which is what text-align does on its
   own and what text-align-last would undo.

   Measured on the abstract, the card being 326px wide on a phone and 424 on a
   desktop: the right edge goes from 58.6px of rag to 0 at 390px (38 to 0 at
   1280), and the price is the word space, which is 3.7px throughout a ranged
   edge and runs to 13.2px on the worst line here (10 at 1280). That is the
   bargain justification always is; at three and a half spaces on one line of
   eleven it is worth it, and anything wider would not be.

   `hyphens: auto` is asked for because it is what keeps those gaps down in a
   column this narrow, and it needs the document's language, which every page
   carrying this declares on <html> itself. It changed nothing in the figures
   above: headless Chrome has no hyphenation dictionary, so this is the
   unhyphenated case measured, and a browser that has one can only do better. */
.bv-authors, .bv-abstract, .bv-note {
    text-align: justify;
    hyphens: auto;
}
.bv-abstract:empty, .bv-note:empty, .bv-authors:empty, .bv-kicker:empty { display: none; }
.bv-note { margin: .875rem 0 0; font-size: .75rem; color: var(--grey); }
.bv-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .625rem; margin-top: 1rem; }
.bv-actions .save-paper.is-labelled { margin-top: 0; }
.bv-close {
    position: absolute; top: .625rem; right: .625rem;
    display: inline-flex; padding: .25rem; border: 0; background: none;
    color: var(--grey); cursor: pointer; border-radius: var(--r-control, 4px);
}
.bv-close:hover { color: var(--maroon); background: var(--cream); }

/* ===== Putting it back =====
   Closing is the flight run backwards: the book goes home to the gap it left
   on the shelf, turning back to the angle it stood at, and only when it has
   landed does the window close and the shelf book appear again (goHome() and
   takeDown() in the script below). So everything else in the window has to be
   out of the way while it travels - the veil, the card, the arrows, the hint
   and the two marks - or the book flies out from behind furniture that is
   still sitting there.

   These come after the rules they undo, `.bv.is-details .bv-card` among them,
   because they are the same weight and the later one wins. */
.bv.is-leaving { background: transparent; }
.bv.is-leaving .bv-card {
    opacity: 0; visibility: hidden;
    transition: opacity .18s ease, visibility 0s linear .18s;
}
.bv.is-leaving .bv-x,
.bv.is-leaving .bv-save,
.bv.is-leaving .bv-nav,
.bv.is-leaving .bv-hint,
.bv.is-leaving .bv-floor { opacity: 0; }
/* The colours these already eased stay named: a bare `opacity` here would
   replace the shared .save-paper transition on the bookmark. */
.bv-x, .bv-save { transition: opacity .18s ease, background .15s, color .15s; }
.bv-floor { transition: opacity .18s ease; }
.bv.is-leaving .bv-book { cursor: default; }

@media (max-width: 700px) {
    .bv-card { flex-direction: column; align-items: center; gap: 1rem; padding: 1.25rem 1rem; }
    .bv-slot-book { width: 120px; height: 173px; }
    .bv-slot { width: calc(120px + 34px); }
    .bv-body { padding-right: 0; width: 100%; }
    .bv-hint { font-size: .75rem; }
    /* The book is 62% of a phone's width, which leaves the arrows the edges. */
    .bv-nav { width: 2.5rem; height: 2.5rem; margin-top: -1.25rem; }
    .bv-nav .material-symbols-outlined { font-size: 26px; }
    .bv-prev { left: .35rem; }
    .bv-next { right: .35rem; }
}
@media (prefers-reduced-motion: reduce) {
    .bv[open], .bv-card, .bv.is-details .bv-card, .bv-hint, .bv-mover, .bv-ribbon { animation: none; transition: none; }
}
</style>

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

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    if (window.papelBookViewer) { return; }
    var dlg = document.getElementById('bookViewer');
    /* No <dialog> (a very old browser): the pages keep their plain links. */
    if (!dlg || typeof dlg.showModal !== 'function') { return; }

    var mover = dlg.querySelector('.bv-mover');
    var book  = dlg.querySelector('.bv-book');
    var front = dlg.querySelector('.bv-front');
    var spine = dlg.querySelector('.bv-spine-text');
    var card  = dlg.querySelector('.bv-card');
    var slot  = dlg.querySelector('.bv-slot-book');
    var hintW = dlg.querySelector('.bv-hint-words');
    var prevB = dlg.querySelector('.bv-prev');
    var nextB = dlg.querySelector('.bv-next');
    var saveB = dlg.querySelector('.bv-save');
    /* The shelf the book came off: the page's own books in their order, which
       one this is, and how to say what each one is. */
    var shelf = null;
    /* The book that is out of its place on that shelf while it is held here. */
    var taken = null;

    var still = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var touch = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);
    var HINT = touch ? 'Drag to turn the book · tap it to see the details'
                     : 'Drag to turn the book · click it to see the details';

    /* Where the book is and how it is turned. Every frame writes these two
       transforms and nothing else: one on the mover (where, how large) and one
       on the book (its own perspective, and the turn). */
    var at   = { tx: 0, ty: 0, s: 1, rx: 8, ry: -28 };
    var W    = 300;
    var mode = 'closed';      // 'inspect' | 'details' | 'moving' | 'leaving' | 'closed'
    /* The angle the book stood at on the shelf it came off, so it can be put
       back the way round it was: Hardbounds holds its books at -28deg and
       Bookshelf stands them spine out at 90. */
    var stood = -28;
    var info = null;
    var tweenId = 0, spinId = 0;
    var swallowClick = false;

    var HELD   = { rx: 8, ry: -28 };      // the angle it is held up at
    var LIFT   = -18;                      // a little above the middle, clear of the hint
    var SET_RY = -24;                      // the angle it lies at in the card, as My Library's did

    function apply() {
        mover.style.transform = 'translate(' + at.tx.toFixed(2) + 'px,' + at.ty.toFixed(2) + 'px) scale(' + at.s.toFixed(4) + ')';
        book.style.transform  = 'perspective(' + Math.round(W * 7) + 'px) rotateX(' + at.rx.toFixed(2) + 'deg) rotateY(' + at.ry.toFixed(2) + 'deg)';
    }

    /* As large as the window allows, up to a size a bound paper might be held
       at: never wider than most of a phone, never taller than the window less
       room for the hint and the close. Width to height is the shelf's 108:156,
       and the thickness a thick thesis's. */
    function size() {
        W = Math.round(Math.max(140, Math.min(300, window.innerWidth * 0.62, (window.innerHeight - 150) / 1.444)));
        book.style.setProperty('--w', W + 'px');
        book.style.setProperty('--h', Math.round(W * 1.444) + 'px');
        book.style.setProperty('--d', Math.round(W * 0.17) + 'px');
        book.style.setProperty('--bk-scale', (W / 108).toFixed(4));
        mover.style.setProperty('--w', W + 'px');
        mover.style.setProperty('--h', Math.round(W * 1.444) + 'px');
    }

    /* The centre of an element, measured from the middle of the window, which
       is where the mover's origin is. */
    function placeOf(el, width) {
        var r = el.getBoundingClientRect();
        return {
            tx: r.left + r.width / 2 - window.innerWidth / 2,
            ty: r.top + r.height / 2 - window.innerHeight / 2,
            s:  (width || r.width) / W
        };
    }

    function ease(k) { return k < .5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2; }

    /* Out of its place on the shelf, and the one before it back in its own.
       One at a time, because the arrows walk along the shelf: a book stepped
       past has been put back by then. */
    function takeDown(el) {
        if (taken === el) { return; }
        if (taken) { taken.classList.remove('bv-taken'); }
        taken = el || null;
        if (taken) { taken.classList.add('bv-taken'); }
    }

    function stopSpin() { if (spinId) { cancelAnimationFrame(spinId); spinId = 0; } }

    /* From where it is to `to`, over `ms`; the turn goes the short way round,
       so a book spun three times does not unwind all three on its way down. */
    function tween(to, ms, done) {
        stopSpin();
        if (tweenId) { cancelAnimationFrame(tweenId); tweenId = 0; }
        var from = { tx: at.tx, ty: at.ty, s: at.s, rx: at.rx, ry: at.ry };
        if (to.ry !== undefined) {
            var d = ((to.ry - from.ry) % 360 + 540) % 360 - 180;
            to.ry = from.ry + d;
        }
        if (still) {
            for (var k in to) { at[k] = to[k]; }
            apply();
            if (done) { done(); }
            return;
        }
        var t0 = performance.now();
        function frame(now) {
            var p = Math.min(1, (now - t0) / ms), e = ease(p);
            for (var key in to) { at[key] = from[key] + (to[key] - from[key]) * e; }
            apply();
            if (p < 1) { tweenId = requestAnimationFrame(frame); }
            else { tweenId = 0; if (done) { done(); } }
        }
        tweenId = requestAnimationFrame(frame);
    }

    /* Once round, slowly, when it first arrives: the book showing itself, back
       and spine and all. Anything the reader does stops it. */
    function showRound() {
        if (still) { return; }
        var from = at.ry, t0 = performance.now(), ms = 2600;
        function frame(now) {
            var p = Math.min(1, (now - t0) / ms);
            at.ry = from + 360 * ease(p);
            apply();
            spinId = p < 1 ? requestAnimationFrame(frame) : 0;
        }
        spinId = requestAnimationFrame(frame);
    }

    /* Let go while moving: it carries on, slowing, the way a thing spun by hand
       does. */
    function glide(vx, vy) {
        if (still) { return; }
        stopSpin();
        function frame() {
            vx *= 0.94; vy *= 0.9;
            at.ry += vx;
            at.rx = Math.max(-50, Math.min(50, at.rx + vy));
            apply();
            spinId = (Math.abs(vx) > 0.04 || Math.abs(vy) > 0.04) ? requestAnimationFrame(frame) : 0;
        }
        spinId = requestAnimationFrame(frame);
    }

    /* ---- What the card says ---- */
    function text(sel, value) { dlg.querySelector(sel).textContent = value || ''; }

    function fillCard() {
        text('.bv-kicker',   info.kicker);
        text('.bv-title',    info.title);
        text('.bv-authors',  info.authors);
        text('.bv-abstract', info.abstract);
        text('.bv-note',     info.note);

        var acts = dlg.querySelector('.bv-actions');
        acts.textContent = '';
        if (info.href) {
            var read = document.createElement('a');
            read.className = 'btn-sm-maroon';
            read.href = info.href;
            read.appendChild(icon('menu_book', 'mi-18'));
            read.appendChild(document.createTextNode(' Read paper'));
            acts.appendChild(read);
        } else if (info.signIn) {
            /* The site's own sign-in panel, opened by the delegated handler in
               site_footer.php; this window closes first (see the click handler
               below), or the panel would open underneath it. */
            var sign = document.createElement('button');
            sign.type = 'button';
            sign.className = 'btn-sm-maroon js-open-modal';
            sign.setAttribute('data-role', 'student');
            /* "now": no flight home, since the panel this opens would come up
               under the dialog while the book was still travelling. */
            sign.setAttribute('data-bv-close', 'now');
            sign.appendChild(icon('login', 'mi-18'));
            sign.appendChild(document.createTextNode(' Sign in to read'));
            acts.appendChild(sign);
        }
        if (info.saved === true || info.saved === false) {
            /* The shared Save button; includes/save_paper.php answers for it
               like any other, and tells every copy of it on the page. */
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'save-paper is-labelled' + (info.saved ? ' is-saved' : '');
            btn.setAttribute('data-save-paper', info.paperId);
            btn.setAttribute('aria-pressed', info.saved ? 'true' : 'false');
            btn.title = info.saved ? 'Remove from My Library' : 'Save to My Library';
            btn.appendChild(icon('bookmark'));
            var word = document.createElement('span');
            word.className = 'save-paper-label';
            word.textContent = info.saved ? 'Saved' : 'Save';
            btn.appendChild(word);
            acts.appendChild(btn);
        }
    }

    /* The bookmark on the held-up book, pointed at the paper in the window.
       It is painted here only as it is opened: from then on the shared script
       owns it like any other copy of itself, since it carries the same
       data-save-paper (includes/save_paper.php). A reader with no library
       (info.saved is neither true nor false) has none, and it is unhooked
       rather than left pointing at the last paper. */
    function fillSave() {
        var has = (info.saved === true || info.saved === false);
        saveB.hidden = !has;
        if (!has) { saveB.removeAttribute('data-save-paper'); return; }
        var say = info.saved ? 'Remove from My Library' : 'Save to My Library';
        saveB.setAttribute('data-save-paper', info.paperId);
        saveB.classList.toggle('is-saved', !!info.saved);
        saveB.classList.remove('is-failed');
        saveB.removeAttribute('aria-busy');
        saveB.setAttribute('aria-pressed', info.saved ? 'true' : 'false');
        saveB.setAttribute('aria-label', say);
        saveB.title = say;
    }

    function icon(name, extra) {
        var s = document.createElement('span');
        s.className = 'material-symbols-outlined' + (extra ? ' ' + extra : '');
        s.setAttribute('aria-hidden', 'true');
        s.textContent = name;
        return s;
    }

    /* The spine: programme at the head, the title, the year at the foot, each
       between a pair of bars, read from "BSIT · 2025" on the cover's foot. */
    function fillSpine() {
        spine.textContent = '';
        var parts = String(info.mark || '').split('·').map(function (p) { return p.trim(); })
                                         .filter(function (p) { return p !== ''; });
        function piece(cls, words) {
            var s = document.createElement('span');
            s.className = cls;
            s.textContent = words;
            spine.appendChild(s);
        }
        function bars() { var b = document.createElement('span'); b.className = 'bv-bars'; spine.appendChild(b); }
        if (parts[0]) { piece('bv-spine-end', parts[0]); bars(); }
        piece('bv-spine-title', info.title);
        if (parts[1]) { bars(); piece('bv-spine-end', parts[1]); }
    }

    /* The front, stamped in full. The shelf's book is 108px wide and says
       what fits ("Baliza et al.", "BSIE · 2026", a title cut after six
       lines); held up, the book has the room to say all of it, so it is
       stamped again from the details rather than copied: the whole title,
       every author, and the programme by its full name. Anything across the
       head of the shelf's cover (a request, a status) is kept. Where a page
       has not given the details, the copied face stands. */
    function fillFront() {
        if (!info.title || (info.authors === undefined && info.program === undefined)) { return; }
        var band = front.querySelector('.bk-band');
        var bandText = band ? band.textContent : '';
        front.textContent = '';
        function stamp(parent, cls, words) {
            if (!words) { return null; }
            var s = document.createElement('span');
            s.className = cls;
            s.textContent = words;
            parent.appendChild(s);
            return s;
        }
        stamp(front, 'bk-band', bandText);
        stamp(front, 'bk-title', info.title);
        stamp(front, 'bk-kind', info.kind);
        var foot = document.createElement('span');
        foot.className = 'bk-foot';
        if (info.authors) {
            stamp(foot, 'bk-by-label', 'Presented by');
            stamp(foot, 'bk-by', info.authors);
        }
        stamp(foot, 'bk-mark bk-program', info.program);
        stamp(foot, 'bk-mark bk-year', info.year);
        front.appendChild(foot);
    }

    /* All of it has to fit the board, and a paper can have a title of thirty
       words and eight authors. So the stamping is set at its own size and
       made smaller, a step at a time, until nothing runs off the bottom:
       small type is the price of nothing being cut. Measured on the book
       held up; set down into the card it is the same book made smaller, so
       it still fits. */
    function fit(el, prop, floor) {
        var f = 1;
        el.style.setProperty(prop, '1');
        while (f > floor && (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1)) {
            f = Math.round((f - .04) * 100) / 100;
            el.style.setProperty(prop, String(f));
        }
    }
    function fitAll() {
        fit(front, '--fit', .3);
        var title = spine.querySelector('.bv-spine-title');
        if (title) { fit(title, '--fit', .35); }
    }

    /* ---- Where on the shelf it is ---- */
    function showPlace() {
        var many = shelf && shelf.items.length > 1;
        prevB.hidden = !many || shelf.index <= 0;
        nextB.hidden = !many || shelf.index >= shelf.items.length - 1;
        hintW.textContent = (many ? (shelf.index + 1) + ' of ' + shelf.items.length + ' · ' : '') + HINT;
    }

    /* Everything that is about one paper rather than about the window: its
       cover, its spine and its card. */
    function dress(data) {
        info = data || {};
        info.paperId = String(info.paperId || '');
        /* The same cover as the book pressed, copied face and all; held up, it
           is stamped again in full (fillFront, once the dialog has a size). */
        book.setAttribute('data-cover', info.cover || 'other');
        front.innerHTML = info.face || '';
        fillSpine();
        fillCard();
        fillSave();
    }

    /* ---- Previous and next ----
       The book on view slides out to one side, turning as it goes, and the
       next comes in from the other, held up the way the first one was. Only
       while a book is held up: the card is about one paper. */
    function step(dir) {
        if (mode !== 'inspect' || !shelf) { return; }
        var to = shelf.index + dir;
        if (to < 0 || to >= shelf.items.length) { return; }
        var data = shelf.infoFor(shelf.items[to]);
        if (!data) { return; }
        mode = 'moving';
        stopSpin();
        var away = Math.round(W * 1.15);
        mover.classList.add('is-away');
        tween({ tx: -dir * away, ry: at.ry - dir * 40 }, 220, function () {
            shelf.index = to;
            takeDown(shelf.items[to]);
            dress(data);
            fillFront();
            fitAll();
            showPlace();
            at = { tx: dir * away, ty: LIFT, s: 1, rx: HELD.rx, ry: HELD.ry + dir * 40 };
            apply();
            mover.classList.remove('is-away');
            tween({ tx: 0, ry: HELD.ry }, 340, function () {
                mode = 'inspect';
                book.focus({ preventScroll: true });
            });
        });
    }

    /* ---- Taking it off the shelf ----
       nav, where a page gives it: { items: its books in order, index: which
       one this is, infoFor: what to say about any of them, turn: the angle
       the book stood at }. Without items there are no arrows; without a turn
       it starts as Hardbounds holds its books, -28deg. The Bookshelf view
       says 90: the book stood spine out, and comes off the shelf edge on,
       turning to its cover as it is pulled out, which is what that view is. */
    function open(source, data, nav) {
        shelf = (nav && nav.items && nav.items.length && typeof nav.infoFor === 'function')
              ? { items: nav.items, index: Math.max(0, nav.index | 0), infoFor: nav.infoFor }
              : null;
        dress(data);
        showPlace();
        mover.classList.remove('is-away');
        size();

        /* Where it was, measured before the page is held: taking the
           scrollbar away moves everything a little to the right. */
        /* By its height where the book is taller than this one's proportion
           (Bookshelf stands taller books), or the flight would start with a
           book shorter than the spine it is pulled from. */
        var from = source
            ? placeOf(source, Math.max(source.offsetWidth || 0, (source.offsetHeight || 0) / 1.444) || null)
            : { tx: 0, ty: 40, s: .4 };
        /* After it has been measured, and before the first frame: the book in
           the window is this book, so the shelf shows the gap it left. */
        takeDown(source);

        dlg.classList.remove('is-details');
        dlg.classList.add('is-inspect');
        document.documentElement.classList.add('bv-locked');
        dlg.showModal();
        /* Once it is open, since a closed dialog has no size to measure, and
           before the first frame is drawn, so nothing is seen at the wrong size. */
        fillFront();
        fitAll();

        stood = (nav && typeof nav.turn === 'number') ? nav.turn : -28;
        at = { tx: from.tx, ty: from.ty, s: from.s, rx: 0, ry: stood };
        apply();
        mode = 'moving';
        book.focus({ preventScroll: true });
        tween({ tx: 0, ty: LIFT, s: 1, rx: HELD.rx, ry: HELD.ry }, 620, function () {
            mode = 'inspect';
            showRound();
        });
    }

    /* ---- Setting it down into the card, and picking it up again ---- */
    function setDown() {
        mode = 'moving';
        dlg.classList.remove('is-inspect');
        dlg.classList.add('is-details');
        var to = placeOf(slot);
        tween({ tx: to.tx, ty: to.ty, s: to.s, rx: 0, ry: SET_RY }, 640, function () {
            mode = 'details';
            var first = dlg.querySelector('.bv-actions a, .bv-actions button') || dlg.querySelector('.bv-close');
            if (first) { first.focus({ preventScroll: true }); }
        });
    }
    function pickUp() {
        mode = 'moving';
        dlg.classList.remove('is-details');
        dlg.classList.add('is-inspect');
        tween({ tx: 0, ty: LIFT, s: 1, rx: HELD.rx, ry: HELD.ry }, 560, function () {
            mode = 'inspect';
            book.focus({ preventScroll: true });
        });
    }
    /* In the card the book lies over its slot, so it follows the slot when the
       window changes size or the card scrolls (a phone's card is taller than
       the window). */
    function settle() {
        if (mode !== 'details') { return; }
        var to = placeOf(slot);
        at.tx = to.tx; at.ty = to.ty; at.s = to.s;
        apply();
    }
    card.addEventListener('scroll', settle, { passive: true });
    window.addEventListener('resize', function () {
        if (mode === 'closed') { return; }
        size();
        fitAll();
        if (mode === 'details') { settle(); } else if (mode === 'inspect') { at.tx = 0; at.ty = LIFT; at.s = 1; apply(); }
    });

    /* ---- Turning it by hand ---- */
    var drag = null;
    book.addEventListener('pointerdown', function (e) {
        /* Only while it is being looked at or is lying in the card: not while
           it is flying in, stepping along the shelf or on its way home. */
        if (e.button !== 0 || (mode !== 'inspect' && mode !== 'details')) { return; }
        stopSpin();
        drag = { x: e.clientX, y: e.clientY, x0: e.clientX, y0: e.clientY, t: performance.now(), vx: 0, vy: 0, moved: false };
        book.classList.add('is-held');
        e.preventDefault();
    });
    /* On the document, never captured: see the head of this file. */
    document.addEventListener('pointermove', function (e) {
        if (!drag) { return; }
        var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
        if (Math.abs(e.clientX - drag.x0) + Math.abs(e.clientY - drag.y0) > 6) { drag.moved = true; }
        if (mode !== 'inspect' || !drag.moved) { drag.x = e.clientX; drag.y = e.clientY; return; }
        var now = performance.now(), dt = Math.max(8, now - drag.t);
        at.ry += dx * 0.5;
        at.rx = Math.max(-50, Math.min(50, at.rx - dy * 0.3));
        /* Degrees a frame, for the glide when it is let go. */
        drag.vx = dx * 0.5 / dt * 16;
        drag.vy = -dy * 0.3 / dt * 16;
        drag.x = e.clientX; drag.y = e.clientY; drag.t = now;
        apply();
    });
    document.addEventListener('pointerup', function () {
        if (!drag) { return; }
        var d = drag;
        drag = null;
        book.classList.remove('is-held');
        if (!d.moved) {
            if (mode === 'inspect') { setDown(); } else if (mode === 'details') { pickUp(); }
            return;
        }
        /* The click this drag is about to raise lands wherever the pointer was
           let go, the backdrop included, which would close the window. */
        swallowClick = true;
        setTimeout(function () { swallowClick = false; }, 0);
        /* A hand that stopped before letting go has nothing to carry on. */
        if (performance.now() - d.t > 80) { return; }
        if (mode === 'inspect') { glide(d.vx, d.vy); }
    });
    document.addEventListener('pointercancel', function () {
        drag = null;
        book.classList.remove('is-held');
    });

    book.addEventListener('keydown', function (e) {
        if (mode === 'moving' || mode === 'leaving') { return; }
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (mode === 'inspect') { setDown(); } else if (mode === 'details') { pickUp(); }
            return;
        }
        if (mode !== 'inspect') { return; }
        var turn = { ArrowLeft: [0, -20], ArrowRight: [0, 20], ArrowUp: [10, 0], ArrowDown: [-10, 0] }[e.key];
        if (!turn) { return; }
        e.preventDefault();
        stopSpin();
        tween({ rx: Math.max(-50, Math.min(50, at.rx + turn[0])), ry: at.ry + turn[1] }, 220);
    });

    /* Page Up and Page Down step along the shelf. Not the arrows, which turn
       the book when it has the focus, as they always have. */
    dlg.addEventListener('keydown', function (e) {
        if (mode === 'leaving') { return; }
        if (e.key === 'PageUp')   { e.preventDefault(); step(-1); }
        if (e.key === 'PageDown') { e.preventDefault(); step(1); }
    });

    /* ---- Putting it away ----
       The flight run backwards: the book goes home to the gap it left on the
       shelf, turning back to the way round it stood, and the window closes
       when it lands - so the paper is seen being put back where it was taken
       from, as it was seen being taken out. The shelf book is uncovered by
       the close handler below, at the moment the flying one arrives over it.

       Where there is nothing to fly to (no shelf book, or one the page has
       since taken away, which My Library does) the window simply closes. */
    function goHome() {
        if (mode === 'leaving' || mode === 'closed') { return; }
        var home = (taken && taken.isConnected && taken.getClientRects().length)
            ? placeOf(taken, Math.max(taken.offsetWidth || 0, (taken.offsetHeight || 0) / 1.444) || null)
            : null;
        if (!home) { dlg.close(); return; }
        mode = 'leaving';
        drag = null;
        book.classList.remove('is-held');
        dlg.classList.add('is-leaving');
        tween({ tx: home.tx, ty: home.ty, s: home.s, rx: 0, ry: stood }, 460, function () {
            dlg.close();
        });
    }

    dlg.addEventListener('click', function (e) {
        if (swallowClick) { e.preventDefault(); e.stopPropagation(); swallowClick = false; return; }
        if (mode === 'leaving') { return; }
        var stepper = e.target.closest ? e.target.closest('[data-bv-step]') : null;
        if (stepper) { step(parseInt(stepper.getAttribute('data-bv-step'), 10)); return; }
        var closer = e.target.closest ? e.target.closest('[data-bv-close]') : null;
        /* The dialog has no padding and the layer takes no pointer, so a click
           that lands on the dialog itself is a click on the space round the
           book or the card. */
        if (!closer && e.target !== dlg) { return; }
        /* data-bv-close="now" is for a control that does something else as
           soon as this window is gone: the "Sign in to read" button opens the
           site's own panel, and a panel opened half a second before a
           top-layer dialog closes opens underneath it. */
        if (closer && closer.getAttribute('data-bv-close') === 'now') { dlg.close(); }
        else { goHome(); }
    });
    /* Escape. The dialog's own answer is to close at once; it is the same act
       as pressing the X, so it takes the same flight. Pressed again while the
       book is on its way, it is let through and the window goes. */
    dlg.addEventListener('cancel', function (e) {
        if (mode === 'leaving') { return; }
        e.preventDefault();
        goHome();
    });
    dlg.addEventListener('close', function () {
        stopSpin();
        if (tweenId) { cancelAnimationFrame(tweenId); tweenId = 0; }
        drag = null;
        mode = 'closed';
        /* Back on the shelf, in the place it was taken from: at this moment
           the flying book is over that gap, so the two change places without
           anything being seen to move. */
        takeDown(null);
        dlg.classList.remove('is-leaving');
        document.documentElement.classList.remove('bv-locked');
        var id = info ? info.paperId : '';
        info = null;
        shelf = null;
        mover.classList.remove('is-away');
        /* Unhooked, or a paper saved elsewhere on the page would paint a
           button nobody can see, and its is-saved would stand the ribbon up
           on whatever book is opened next. */
        saveB.hidden = true;
        saveB.removeAttribute('data-save-paper');
        saveB.classList.remove('is-saved', 'is-failed');
        /* For a page that has something to tidy once the window is gone: My
           Library takes a book off its shelf that was unsaved while open. */
        document.dispatchEvent(new CustomEvent('papel:book-viewer-closed', { detail: { paperId: id } }));
    });

    window.papelBookViewer = {
        open: open,
        /* Whether this paper's book is the one in the window just now. */
        isOpenFor: function (id) { return dlg.open && info !== null && info.paperId === String(id); }
    };
})();
</script>
