<?php
/**
 * A file opened on the record's own page rather than in another tab.
 *
 * Clicking a file tile (.pd-file[data-pdf-src]) shows the PDF in a panel, so
 * the reader keeps the checklist, the sections and the decision in view while
 * they read. Clicking the tile of the file that is showing closes the panel
 * again; another tile switches to its file.
 *
 * The pages are drawn here, by assests/js/papel-pdf-view.js, from bytes that
 * app/paper_file.php passes through from Google Drive as they are asked for.
 * This used to frame Drive's own viewer, which stopped after page one (a
 * spinner, and never a request for page two) for anyone not signed in to
 * Google, on files set to block download. Drawing them here also means no
 * download or print button, and no Drive link on the page for anyone to take
 * away. The renderer (320 KB, and its worker) is fetched on the first open, so
 * a page nobody reads a file on never pays for it.
 *
 * Include once, before the footer, on any page with .pd-file tiles.
 *
 * Optional, before the include:
 *   $PDF_DOCK_HOME      = '#pd-sec-manuscript';     // a selector
 *   $PDF_DOCK_HOME_NAME = 'the Manuscript section'; // for the button titles
 * The panel then opens inside that element, in the flow of the page, and the
 * reader can dock it to the left or right of the window, with the buttons in
 * its header, or by dragging the header to a side. Every paper page gives it a
 * home: the public view, the review desk's paper and the student's own paper.
 * Without one the panel simply opens docked on the right.
 *   $PDF_DOCK_SIDES = ['right'];                      // default ['left', 'right']
 * limits the sides it may be docked to, by button or by drag. The upload page
 * keeps the left for its Corpus chat, which docks there.
 *   $PDF_DOCK_CARD = '.some-card';                    // a selector
 * makes a docked panel a card beside the page instead of a strip down the
 * window's edge: its top level with that element's, a gap (--pdf-dock-gap) from
 * the bars, the footer and the window's edge, and the cards' rounded corners. As
 * the page scrolls it keeps that gap under the bars. Only where the panel shares
 * the screen; on a phone it covers the page as ever.
 *
 * A file the reader has on their own machine, not uploaded yet, opens in the
 * same panel from script, drawn straight from memory: nothing is fetched to
 * show it, and nothing reaches the server or Google Drive.
 *     window.papelPdfDock.openFile(file, 'name for the header');
 *     window.papelPdfDock.close() / .minimise() / .restore() / .forget() / .isOpen()
 *     window.papelPdfDock.place()   'inline', 'left' or 'right' while open, else null
 *     window.papelPdfDock.dockTo(where)   moves an open panel with a home
 * Calls made before the page has finished loading are held until it has.
 *
 * Optional, before the include:
 *   $PDF_DOCK_MINIMISE = true;
 * adds a tab on the right edge of the window that brings the panel back after
 * it is closed or put away (.minimise(), from the page's own script). Only
 * without a home: a panel with one already has its section to go back to. The
 * upload page uses it, so the paper being handed in stays one click away from
 * every step of the form. There is no Minimise button in the header: next to
 * Close it was a second way of doing the same thing, since the tab brings a
 * closed panel back just the same.
 */
$PDF_DOCK_HOME      = $PDF_DOCK_HOME ?? null;
$PDF_DOCK_HOME_NAME = $PDF_DOCK_HOME_NAME ?? 'the page';
$PDF_DOCK_MINIMISE  = !empty($PDF_DOCK_MINIMISE) && !$PDF_DOCK_HOME;
$PDF_DOCK_CARD      = $PDF_DOCK_CARD ?? null;
$PDF_DOCK_SIDES     = array_values(array_intersect(['left', 'right'], (array)($PDF_DOCK_SIDES ?? ['left', 'right'])));
?>
<?php /* Versioned, so a change to the viewer reaches browsers holding the old copy. */ ?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-pdf-view.css')) ?>">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Starting width. Dragging the panel's edge overwrites this on :root in pixels,
   so the panel and the margin that keeps the record clear of it always move
   together: there is one number, not two that could disagree. */
:root { --pdf-dock-w: min(46vw, 46rem); }

.pdf-dock {
    position: fixed;
    /* Under the navbar, and above the footer once that is in view. Docked to the
       right with room beside the breadcrumb links, it starts right under the
       navbar and sits over the strip, whose own right-hand end it takes over, so
       the panel's top never moves as the page scrolls. Anywhere else (docked to
       the left, or too narrow to leave the links clear) it starts below the strip
       instead, and follows it up as it scrolls away. Both edges are measured
       from the page (placeTop below); the fallbacks only matter if that never
       runs. */
    top: var(--pdf-dock-top, 57px);
    right: 0;
    bottom: var(--pdf-dock-bottom, 0px);
    /* Those two are only the resting values. placeTop() writes the live edges as
       plain top and bottom on the panel itself, not as variables on <html>: a
       variable on the root is inherited by every element, so each write made the
       browser re-check the style of the whole page, text layer and all, and with
       a thesis open that was 50 to 100ms a time, on every step of a scroll. */
    width: var(--pdf-dock-w);
    z-index: 900;
    display: none;
    flex-direction: column;
    /* Nothing under the header and the stage, which between them cover the whole
       panel. It was white, and a white ground under them showed at the inner edge
       as a pale hairline wherever that edge fell between two device pixels (at
       110% to 175% zoom, in the window widths where it did), now that the panel
       sits over the maroon strip. */
    background: transparent;
    /* No border on the inner edge either. It was a 1px pale line, invisible beside
       the white page, but over the strip it showed as a thin light seam between
       the strip and the panel's own maroon header. The header, the dark stage and
       the shadow below already set the panel apart. */
    box-shadow: -4px 0 24px rgba(51, 0, 0, .10);
}
.pdf-dock.is-open { display: flex; }

/* ----- As a card ($PDF_DOCK_CARD) --------------------------------------
   Docked, a card beside the page rather than a strip down the window's edge.
   Its top and bottom are written by placeTop(), like the strip's; only the
   side, the frame and the corners are here. The frame is the cards' own
   hairline: the edge never meets the maroon bars (there is always the gap),
   so the seam that ruled a border out for the strip does not arise. */
:root { --pdf-dock-gap: 16px; }
@media (min-width: 901px) {
    .pdf-dock.as-card:not(.is-inline) {
        right: var(--pdf-dock-gap);
        border: 1px solid var(--border);
        border-radius: var(--r-card, 8px);
        overflow: hidden;           /* the header and the pages take the corners too */
        box-shadow: 0 6px 24px rgba(51, 0, 0, .10);
    }
    .pdf-dock.as-card.is-left:not(.is-inline) { right: auto; left: var(--pdf-dock-gap); }
    /* Inside the frame now, or the corner would clip it. */
    .pdf-dock.as-card:not(.is-inline) .pdf-dock-grip { left: 0; }
    .pdf-dock.as-card.is-left:not(.is-inline) .pdf-dock-grip { left: auto; right: 0; }
}
.pdf-dock-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .625rem .875rem;
    /* The token the breadcrumb strip and the footer use, so the panel's bar is
       one of the site's maroon bands rather than a lighter red of its own (on
       --maroon-surface it was the lighter, and read as a seam against them). */
    background: var(--maroon-surface-hover); color: #fff;
    font-size: .8125rem;
}
/* The file-type icon. This was a style attribute, which the CSP drops, so it
   was rendering at the 24px default rather than the 18px it asked for and
   sitting taller than the text beside it. */
.pdf-dock-head > .material-symbols-outlined { font-size: 18px; }
.pdf-dock-name {
    flex: 1 1 auto; min-width: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    /* The tools after it go to the header's right-hand end even when a page caps
       the name's width (the upload page does, at 18rem): without this the spare
       room fell after them, and they sat in the middle of the bar. */
    margin-right: auto;
}
.pdf-dock-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.75rem; height: 1.75rem; flex: 0 0 auto;
    border: none; border-radius: var(--r-control, 4px); background: none; color: #fff;
    cursor: pointer; text-decoration: none;
}
.pdf-dock-btn:hover { background: rgba(255, 255, 255, .18); color: #fff; }
.pdf-dock-btn .material-symbols-outlined { font-size: 18px; }
.pdf-dock-btn[hidden] { display: none; }
.pdf-dock-btn:disabled { opacity: .45; cursor: default; background: none; }

/* Zoom, and where in the document the reader is. */
.pdf-dock-page {
    flex: 0 0 auto; font-size: .75rem; opacity: .85;
    font-variant-numeric: tabular-nums; white-space: nowrap;
}
.pdf-dock-zoom {
    display: inline-flex; align-items: center; flex: 0 0 auto;
    padding-right: .35rem; margin-right: .1rem;
    border-right: 1px solid rgba(255, 255, 255, .25);
}
.pdf-dock-zoom-level {
    min-width: 3.1rem; height: 1.75rem; padding: 0 .25rem;
    border: none; border-radius: var(--r-control, 4px); background: none; color: #fff;
    font: inherit; font-size: .75rem; font-variant-numeric: tabular-nums; cursor: pointer;
}
.pdf-dock-zoom-level:hover { background: rgba(255, 255, 255, .18); }

/* The pages. The stage is positioned so the renderer's hint sits over the
   pages without scrolling away with them. */
.pdf-dock-stage { position: relative; flex: 1 1 auto; min-height: 0; display: flex; }
.pdf-dock-scroll {
    flex: 1 1 auto; min-width: 0;
    overflow: auto;
    padding: 1rem;
    text-align: center;
    /* Colours come from the shared dark stage (papel-pdf-view.css). The gutter
       is kept because a scrollbar that came and went would change the pages'
       width, and each change redraws every page. */
    scrollbar-gutter: stable;
}
.pdf-dock-scroll:focus-visible { outline: 2px solid var(--pdf-scroll-thumb, #fff); outline-offset: -2px; }
.pdf-dock-status {
    position: absolute; left: 50%; top: 45%; transform: translate(-50%, -50%);
    max-width: 80%;
    padding: .6rem 1rem;
    border-radius: var(--r-card, 8px);
    background: var(--white); color: var(--ink);
    box-shadow: 0 4px 14px rgba(51, 0, 0, .12);
    font-size: .8125rem; text-align: center;
}
.pdf-dock-status:empty { display: none; }

/* Full screen: the panel itself fills the screen, from wherever it was. The
   browser already pins a full-screen element to the whole screen; the frame
   and the docking controls just have nothing to do there. */
.pdf-dock:fullscreen { border: 0; border-radius: 0; box-shadow: none; }
.pdf-dock:fullscreen .pdf-dock-grip,
.pdf-dock:fullscreen .pdf-dock-to,
.pdf-dock:fullscreen .pdf-dock-grab { display: none !important; }
.pdf-dock:fullscreen .pdf-dock-head { cursor: default; }

/* A narrow panel runs out of header before it runs out of buttons: the page
   count goes first, then the zoom, which Ctrl + scroll still does. Measured on
   the panel, not the window, since a docked panel is narrow on a wide screen. */
.pdf-dock { container-type: inline-size; }
@container (max-width: 600px) { .pdf-dock-page { display: none; } }
@container (max-width: 470px) { .pdf-dock-zoom { display: none; } }

/* No printing the paper from the page: the browser's print would otherwise
   happily put every drawn page on paper. */
@media print {
    .pdf-dock-scroll { display: none !important; }
}

/* The inner edge is a handle. Only the width can be dragged: the panel is
   pinned top and bottom, so there is nothing else to change. */
.pdf-dock-grip {
    position: absolute;
    top: 0; left: -3px; bottom: 0;
    width: 8px;
    cursor: col-resize;
    background: none;
    border: none;
    padding: 0;
    z-index: 2;
}
.pdf-dock-grip::before {
    content: '';
    position: absolute;
    top: 0; bottom: 0; left: 3px;
    width: 2px;
    background: var(--soft-maroon);
    opacity: 0;
    transition: opacity .15s;
}
.pdf-dock-grip:hover::before,
.pdf-dock-grip:focus-visible::before,
.pdf-dock.is-resizing .pdf-dock-grip::before { opacity: 1; }
.pdf-dock-grip:focus-visible { outline: none; }

/* The pages have pointer handling of their own (text selection, dragging to
   pan) so it is switched off while the panel itself is being resized or
   moved, and a drag across them is only ever that. */
.pdf-dock.is-resizing .pdf-dock-scroll,
.pdf-dock.is-dragging .pdf-dock-scroll { pointer-events: none; }
body.pdf-resizing, body.pdf-resizing * {
    cursor: col-resize !important;
    user-select: none !important;
    -webkit-user-select: none !important;
}
/* Nothing animates while dragging, or the panel lags behind the cursor. */
.pdf-dock.is-resizing,
body.pdf-resizing > main.wrap { transition: none !important; }

/* The record makes room on its right rather than hiding under the panel, and
   its left edge stays exactly where it was: the margin below is the one the
   centred wrapper already had, worked out rather than replaced, so nothing
   slides sideways as the panel opens. (Padding the <body> was the first
   attempt: it moved the whole document, sticky header included, and grew a
   horizontal scrollbar. Shifting the wrapper leaves the header alone.)

   The crumb strip is deliberately left out of this. Its links sit at the far
   left, the panel is at the far right, and the two cannot reach each other:
   shifting it only moved "Home" away from where it belongs. */
body.pdf-docked > main.wrap {
    margin-left: max(0px, calc((100% - var(--wrap)) / 2));
    margin-right: calc(var(--pdf-dock-w) + 1.25rem);
    max-width: none;
    /* The margins decide the width now. An explicit width (body > main is
       width:100%) would be the full width of the body whatever the margins
       say, and the page would grow a scrollbar exactly as wide as the panel.
       min-width is released so this flex item may shrink that far. */
    width: auto;
    min-width: 0;
    transition: margin-right .18s ease;
}
/* The panel already starts below the header, so nothing needs to move it. */

/* Which file is being shown. */
.pd-file.is-showing { border-color: var(--maroon); background: var(--cream); }

/* ----- With a home ($PDF_DOCK_HOME) --------------------------------------
   The panel is one element that never moves in the document once it has
   reached its home. Docking and undocking only swap classes: the drawn pages
   stay where they are, and the renderer redraws them at the new width with
   the reader's place kept, rather than starting the document over. */

/* In the page: an ordinary block in its section. The script sets it to one
   page tall once the first page is drawn (fitInline); this height is only
   what it has while that page is on its way. */
.pdf-dock.is-inline {
    position: relative;
    /* !important because a panel pinned to a side has its top and bottom written
       straight onto it by placeTop() (inline, which would otherwise win), and one
       that has come back to its section must ignore whatever was left there. */
    top: auto !important; bottom: auto !important; right: auto; left: auto;
    width: auto;
    height: min(80vh, 56rem);
    z-index: auto;
    margin-top: 1rem;
    /* A card in the page, so it keeps a ground, a frame and its rounded corners. */
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    overflow: hidden;
    box-shadow: none;
    /* Scrolled into view below the sticky header, not underneath it. */
    scroll-margin-top: calc(var(--pdf-dock-top, 57px) + 1rem);
}
.pdf-dock.is-inline .pdf-dock-grip { display: none; }
/* Folding the section folds its preview with it. The home is the card itself
   rather than card_collapse.php's inner wrapper, so that a panel docked to a
   side is not taken away with the section it came from. */
[data-collapsed="1"] > .pdf-dock.is-inline { display: none; }

/* On the left: the right-hand panel, mirrored. */
.pdf-dock.is-left {
    right: auto; left: 0;
    box-shadow: 4px 0 24px rgba(51, 0, 0, .10);
}
.pdf-dock.is-left .pdf-dock-grip { left: auto; right: -3px; }
.pdf-dock.is-left .pdf-dock-grip::before { left: auto; right: 3px; }

body.pdf-docked-left > main.wrap {
    margin-left: calc(var(--pdf-dock-w) + 1.25rem);
    margin-right: max(0px, calc((100% - var(--wrap)) / 2));
    max-width: none;
    width: auto;
    min-width: 0;
    transition: margin-left .18s ease;
}
/* The panel starts below the breadcrumb strip now, so the crumbs no longer
   need to make room for it on this side. */

/* The bars are in front of the panel, and the panel is behind them: from the
   front, the navbar, then the breadcrumb strip and the footer, then the panel.

   Its top and bottom are set from script as the page scrolls, and the browser
   scrolls a page on its own thread while that script runs a frame or two
   behind. On a quick scroll the panel's edge is therefore briefly where a bar
   has already arrived. With the panel in front, that showed as the panel lying
   over the strip and over the footer, flickering back into place; with the
   bars in front, the edge just goes under them and the seam stays shut.

   The navbar's menus (the bell, the avatar, the phone nav sheet) hang below
   the bar, into the space a docked panel starts in. The bar is its own
   stacking context, and at the panel's own level (900) it lost to the panel,
   which comes later in the page, so the menus opened underneath it and could
   not be seen. It stays above the strip too, which scrolls up under it. */
body.pdf-docked .site-header,
body.pdf-docked-left .site-header { z-index: 903; }
body.pdf-docked .crumb-bar,
body.pdf-docked-left .crumb-bar,
body.pdf-docked .site-footer,
body.pdf-docked-left .site-footer { position: relative; z-index: 902; }
/* The one exception: docked to the right with the breadcrumb links clear of the
   panel (placeTop sets this class), the panel starts at the navbar and sits over
   the strip. Its top is then the navbar's own edge, which never moves, so there is
   no edge for a scroll to leave behind, and the strip goes back to its ordinary
   level, under the panel. */
body.pdf-docked.pdf-over-strip .crumb-bar { z-index: 1; }

/* The header is the handle for docking by drag. */
.pdf-dock-grab { display: none; }
.pdf-dock-drop {
    position: fixed; z-index: 905;
    display: none; align-items: center; justify-content: center;
    pointer-events: none;
    border: 2px dashed var(--maroon);
    border-radius: var(--r-card, 8px);
    background: color-mix(in srgb, var(--maroon) 12%, transparent);
}
.pdf-dock-drop.is-showing { display: flex; }
.pdf-dock-drop span {
    padding: .45rem .9rem;
    border-radius: 999px;
    background: var(--maroon); color: #fff;
    font-size: .8125rem; font-weight: 600;
    box-shadow: 0 4px 14px rgba(51, 0, 0, .2);
}
body.pdf-dragging, body.pdf-dragging * {
    cursor: grabbing !important;
    user-select: none !important;
    -webkit-user-select: none !important;
}
@media (min-width: 901px) {
    .pdf-dock.has-home .pdf-dock-head { cursor: grab; touch-action: none; }
    .pdf-dock.has-home .pdf-dock-head .pdf-dock-btn { cursor: pointer; }
    .pdf-dock.has-home .pdf-dock-grab { display: inline-flex; font-size: 18px; opacity: .75; }
}

@media (max-width: 900px) {
    :root { --pdf-dock-w: 100vw; }
    .pdf-dock { max-width: none; }
    /* The panel covers the page at this width, so there is nothing to shift. */
    body.pdf-docked > main.wrap {
        margin-left: auto; margin-right: auto; width: 100%;
    }
    /* Too narrow for a page and a panel side by side: a panel with a home
       stays in it. */
    .pdf-dock-to { display: none !important; }
}

/* ----- Put away ($PDF_DOCK_MINIMISE) -------------------------------------
   The tab that brings a minimised panel back. Dressed exactly as the
   Accessibility tab on the same edge (includes/accessibility.php), in the
   panel's own bar colour: it used to be --maroon, a brighter red than both,
   and read as a third, unrelated thing rather than this panel folded away. */
.pdf-dock-restore {
    position: fixed;
    top: 50%;
    right: 0;
    transform: translateY(-50%);
    z-index: 1150;          /* over the page and a docked chat, under dialogs */
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .25rem;
    padding: .75rem .5rem;
    border: none;
    border-radius: var(--r-control, 4px) 0 0 var(--r-control, 4px);
    background: var(--maroon-surface-hover);
    color: #fff;
    font-family: var(--font-body);
    font-size: .625rem;
    letter-spacing: .04em;
    cursor: pointer;
    box-shadow: -2px 0 10px rgba(51, 0, 0, .22);
    transition: background .15s, padding .15s;
}
.pdf-dock-restore[hidden] { display: none; }
.pdf-dock-restore:hover { background: var(--maroon-surface); padding-right: .75rem; }
.pdf-dock-restore:focus-visible { outline: 2px solid #fff; outline-offset: -4px; }
.pdf-dock-restore .material-symbols-outlined { font-size: 20px; }
.pdf-dock-restore-label { writing-mode: vertical-rl; text-orientation: mixed; }
@media print { .pdf-dock-restore { display: none !important; } }
</style>

<div class="pdf-dock<?= $PDF_DOCK_HOME ? ' has-home' : '' ?><?= $PDF_DOCK_CARD ? ' as-card' : '' ?>" id="pdfDock" role="region" aria-label="Document preview"
     <?php if ($PDF_DOCK_CARD): ?>data-card="<?= e($PDF_DOCK_CARD) ?>"<?php endif; ?>
     <?php if ($PDF_DOCK_HOME): ?>data-home="<?= e($PDF_DOCK_HOME) ?>" data-home-name="<?= e($PDF_DOCK_HOME_NAME) ?>" data-sides="<?= e(implode(' ', $PDF_DOCK_SIDES)) ?>"<?php endif; ?>>
    <div class="pdf-dock-head">
        <?php if ($PDF_DOCK_HOME): ?>
            <span class="material-symbols-outlined pdf-dock-grab" aria-hidden="true"
                  title="Drag to a side of the window to dock">drag_indicator</span>
        <?php endif; ?>
        <span class="material-symbols-outlined">picture_as_pdf</span>
        <span class="pdf-dock-name" id="pdfDockName">Document</span>
        <span class="pdf-dock-page" id="pdfDockPage" aria-live="polite"></span>
        <span class="pdf-dock-zoom">
            <button type="button" class="pdf-dock-btn" id="pdfDockZoomOut"
                    title="Zoom out" aria-label="Zoom out"><span class="material-symbols-outlined">zoom_out</span></button>
            <button type="button" class="pdf-dock-zoom-level" id="pdfDockZoomLevel"
                    title="Fit to width" aria-label="Zoom level; fit to width">100%</button>
            <button type="button" class="pdf-dock-btn" id="pdfDockZoomIn"
                    title="Zoom in" aria-label="Zoom in"><span class="material-symbols-outlined">zoom_in</span></button>
        </span>
        <button type="button" class="pdf-dock-btn" id="pdfDockNewTab"
                title="Open in a new tab" aria-label="Open in a new tab"><span class="material-symbols-outlined">open_in_new</span></button>
        <button type="button" class="pdf-dock-btn" id="pdfDockFullscreen" hidden
                title="Full screen" aria-label="Full screen"><span class="material-symbols-outlined">fullscreen</span></button>
        <?php if ($PDF_DOCK_HOME): ?>
            <?php /* The button for where the panel already is is hidden, so
                     each one always means "move it there". */ ?>
            <?php if (in_array('left', $PDF_DOCK_SIDES, true)): ?>
            <button type="button" class="pdf-dock-btn pdf-dock-to" data-dock-to="left"
                    title="Dock on the left" aria-label="Dock the preview on the left of the window"><span class="material-symbols-outlined">dock_to_left</span></button>
            <?php endif; ?>
            <button type="button" class="pdf-dock-btn pdf-dock-to" data-dock-to="inline"
                    title="Put back in <?= e($PDF_DOCK_HOME_NAME) ?>" aria-label="Put the preview back in <?= e($PDF_DOCK_HOME_NAME) ?>"><span class="material-symbols-outlined">dock_to_bottom</span></button>
            <?php if (in_array('right', $PDF_DOCK_SIDES, true)): ?>
            <button type="button" class="pdf-dock-btn pdf-dock-to" data-dock-to="right"
                    title="Dock on the right" aria-label="Dock the preview on the right of the window"><span class="material-symbols-outlined">dock_to_right</span></button>
            <?php endif; ?>
        <?php endif; ?>
        <?php /* No download or print, and the Drive link is deliberately not on
                 the page. "Open in a new tab" is PAPEL's own full-window viewer
                 (app/student/pdf_viewer.php), under the same rules. */ ?>
        <button type="button" class="pdf-dock-btn" id="pdfDockClose"
                title="Close preview" aria-label="Close preview"><span class="material-symbols-outlined">close</span></button>
    </div>
    <?php /* is-dark: the pages on dark grey with a white scrollbar, as Google
             Drive shows them; see papel-pdf-view.css. */ ?>
    <div class="pdf-dock-stage papel-pdf-stage is-dark">
        <div class="pdf-dock-scroll papel-pdf-scroll" id="pdfDockScroll" tabindex="0"
             aria-label="Document pages"></div>
        <?php /* Loading is the ring; the status box only ever carries a problem. */ ?>
        <div class="papel-pdf-spinner" role="status" aria-label="Loading"></div>
        <div class="pdf-dock-status" id="pdfDockStatus" role="alert"></div>
    </div>
    <button type="button" class="pdf-dock-grip" id="pdfDockGrip"
            role="separator" aria-orientation="vertical"
            title="Drag to resize, or use the arrow keys"
            aria-label="Resize the preview panel"></button>
</div>
<?php if ($PDF_DOCK_HOME): ?>
<div class="pdf-dock-drop" id="pdfDockDrop" aria-hidden="true"><span id="pdfDockDropLabel"></span></div>
<?php endif; ?>
<?php if ($PDF_DOCK_MINIMISE): ?>
<?php /* Brings the panel back after it has been minimised or closed. Shown only
         while there is something to bring back. */ ?>
<button type="button" class="pdf-dock-restore" id="pdfDockRestore" hidden
        title="Show the PDF preview" aria-label="Show the PDF preview">
    <span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>
    <span class="pdf-dock-restore-label">PDF</span>
</button>
<?php endif; ?>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
/* The API is in place the moment this is parsed; the panel behind it is only
   built once the page has loaded. Anything asked of it before then is held
   and done then, in the order it was asked. */
var impl = null, queue = [];
function later(name) {
    return function () {
        if (impl) return impl[name].apply(impl, arguments);
        queue.push([name, arguments]);
    };
}
window.papelPdfDock = {
    openFile: later('openFile'),
    close:    later('close'),
    minimise: later('minimise'),
    restore:  later('restore'),
    forget:   later('forget'),
    isOpen:   function () { return !!impl && impl.isOpen(); },
    // Questions, answered now: before load there is no panel, so nowhere and no move.
    place:    function () { return impl ? impl.place() : null; },
    dockTo:   function (to) { return !!impl && impl.dockTo(to); }
};

document.addEventListener('DOMContentLoaded', function () {
    var dock   = document.getElementById('pdfDock');
    var name   = document.getElementById('pdfDockName');
    var scroll = document.getElementById('pdfDockScroll');
    var status = document.getElementById('pdfDockStatus');
    var pageEl = document.getElementById('pdfDockPage');
    var zoomEl = document.getElementById('pdfDockZoomLevel');
    var stage  = scroll.parentNode;
    var restoreTab = document.getElementById('pdfDockRestore');   // $PDF_DOCK_MINIMISE only

    // The loading ring (papel-pdf-view.css), on from the click until page one is up.
    function setBusy(on) { stage.classList.toggle('is-busy', on); }

    /* ----- The renderer, fetched the first time a file is opened ----- */
    var ASSETS = <?= json_encode([
        'pdfjs'  => asset_url('assests/js/pdfjs/pdf.min.js'),
        'worker' => asset_url('assests/js/pdfjs/pdf.worker.min.js'),
        'view'   => asset_url('assests/js/papel-pdf-view.js'),
    ], JSON_UNESCAPED_SLASHES) ?>;
    var view = null, viewReady = null, fetching = null, showingSrc = null;
    var showingFile = null;   // a local File being shown, when it is not a server file
    var last = null;          // {file, src, name}: what the restore tab brings back

    /* Said when a document cannot be drawn. The renderer reads it when that
       happens, so it is switched to suit where each file came from: a file
       not yet handed in can still be handed in, and the student should know. */
    var FAIL_SERVER = 'This file could not be shown. Please try again.';
    var FAIL_LOCAL  = 'This PDF could not be previewed here. You can still upload it.';
    var viewOptions = null;

    /* Loaded only if the page does not have it already. Running PDF.js a second
       time builds a second copy of it and swaps the global out from under any
       view still holding the first. */
    function need(src, present) { return present ? Promise.resolve() : loadScript(src); }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    function viewer() {
        if (!viewReady) {
            viewReady = need(ASSETS.pdfjs, typeof pdfjsLib !== 'undefined').then(function () {
                return need(ASSETS.view, typeof papelPdfView !== 'undefined');
            }).then(function () {
                view = papelPdfView.create(viewOptions = {
                    scroller: scroll,
                    status: status,
                    workerSrc: ASSETS.worker,
                    failMessage: FAIL_SERVER,
                    intro: 'Press H to drag the page, T to select text. Ctrl + scroll zooms.',
                    loadingText: '',                  // the ring says it
                    onBusy: setBusy,
                    onZoom: function (z) { zoomEl.textContent = Math.round(z * 100) + '%'; }
                });
                return view;
            });
            viewReady.catch(function () { viewReady = null; });   // let a later open try again
        }
        return viewReady;
    }

    function paintPage() {
        var total = view ? view.pageCount() : 0;
        pageEl.textContent = total ? 'Page ' + (view.currentPage() || 1) + ' of ' + total : '';
    }
    var paintQueued = false;
    scroll.addEventListener('scroll', function () {
        if (paintQueued) return;
        paintQueued = true;
        requestAnimationFrame(function () { paintQueued = false; paintPage(); });
    }, { passive: true });

    /* Fetch a file through app/paper_file.php and draw it. The header is what
       that script checks for; a browser pointed straight at the address never
       sends it, and is refused. A newer open cancels an older one still on
       its way, so a quick second click never paints the first file. */
    function show(src) {
        if (fetching) fetching.abort();
        var mine = fetching = new AbortController();
        showingSrc = src;
        showingFile = null;
        pageEl.textContent = '';
        status.textContent = '';
        // On before the renderer is even fetched: the first open waits for it too.
        setBusy(true);
        return viewer().then(function (v) {
            v.clear();                                // which turns the ring off…
            setBusy(true);                            // …so on again for the fetch
            viewOptions.failMessage = FAIL_SERVER;
            return fetch(src, { headers: { 'X-PAPEL-Viewer': '1' }, credentials: 'same-origin',
                                cache: 'no-store', signal: mine.signal });
        }).then(function (res) {
            if (!res.ok) {
                return res.text().then(function (why) {
                    throw new Error(why || 'This file could not be opened.');
                });
            }
            return res.arrayBuffer();
        }).then(function (bytes) {
            if (mine !== fetching) return;
            // Overtaken while drawing: the newer file's count, not this one's.
            return view.load(bytes).then(function () { if (mine === fetching) paintPage(); });
        }).catch(function (err) {
            if ((err && err.name === 'AbortError') || mine !== fetching) return;
            setBusy(false);
            status.textContent = err && err.message && err.message.length < 200
                ? err.message : 'This file could not be opened. Please try again.';
        });
    }

    /* A file from the reader's own machine: the same drawing, with no fetch.
       Its bytes are read here rather than handed to the renderer as a File, so
       the check that a newer open has not overtaken this one still stands in
       front of the drawing, as it does for a fetch. The controller is never
       given to a request; it is the token show() already uses. */
    function showFile(file) {
        if (fetching) fetching.abort();
        var mine = fetching = new AbortController();
        showingSrc = null;
        showingFile = file;
        pageEl.textContent = '';
        status.textContent = '';
        setBusy(true);
        return viewer().then(function (v) {
            if (mine !== fetching) return null;
            v.clear();
            setBusy(true);
            viewOptions.failMessage = FAIL_LOCAL;
            return file.arrayBuffer();
        }).then(function (bytes) {
            if (!bytes || mine !== fetching) return;
            return view.load(bytes).then(function () { if (mine === fetching) paintPage(); });
        }).catch(function () {
            if (mine !== fetching) return;
            setBusy(false);
            status.textContent = FAIL_LOCAL;
        });
    }

    function stopShowing() {
        if (fetching) { fetching.abort(); fetching = null; }
        if (view) view.destroy();
        setBusy(false);
        showingSrc = null;
        showingFile = null;
        pageEl.textContent = '';
        status.textContent = '';
    }

    /* A new tab, on PAPEL's full-window viewer. It takes the same query as
       app/paper_file.php (?paper= or ?doc=), so the file's own address gives
       it; the Drive link never enters into it. */
    var VIEWER = <?= json_encode(BASE_URL . '/app/student/pdf_viewer.php', JSON_UNESCAPED_SLASHES) ?>;
    document.getElementById('pdfDockNewTab').addEventListener('click', function () {
        if (showingFile) { popOutFile(showingFile, name.textContent); return; }
        if (!showingSrc) return;
        window.open(VIEWER + showingSrc.slice(showingSrc.indexOf('?')), '_blank', 'noopener');
    });

    /* A local file has no address to open. The new tab says when it is ready
       and the file is passed across in memory, same origin only, so it never
       reaches the server. Not 'noopener' here: the tab needs window.opener to
       ask for the file. */
    function popOutFile(file, label) {
        var w = window.open(VIEWER, '_blank');
        if (!w) {
            var msg = 'Your browser blocked the new tab. Allow pop-ups for this site to open the full view.';
            if (typeof window.papelAlert === 'function') window.papelAlert(msg);
            else if (typeof window.papelNote === 'function') window.papelNote(msg, false);
            return;
        }
        var origin = window.location.origin;
        function handOver(e) {
            if (e.origin !== origin || e.source !== w || !e.data || e.data.type !== 'papel-pdf-ready') return;
            window.removeEventListener('message', handOver);
            w.postMessage({ type: 'papel-pdf-file', file: file, name: label || file.name }, origin);
        }
        window.addEventListener('message', handOver);
        // Given up on if the tab never reports in.
        setTimeout(function () { window.removeEventListener('message', handOver); }, 30000);
    }

    // Full screen: the panel itself, wherever it sits. Esc or the button again comes back.
    var fsBtn = document.getElementById('pdfDockFullscreen');
    function fullscreen() { return document.fullscreenElement === dock; }
    if (document.fullscreenEnabled) {
        fsBtn.hidden = false;
        fsBtn.addEventListener('click', function () {
            if (fullscreen()) document.exitFullscreen();
            else dock.requestFullscreen().catch(function () {});
        });
        document.addEventListener('fullscreenchange', function () {
            var on = fullscreen();
            fsBtn.querySelector('.material-symbols-outlined').textContent = on ? 'fullscreen_exit' : 'fullscreen';
            fsBtn.title = on ? 'Exit full screen' : 'Full screen';
            fsBtn.setAttribute('aria-label', fsBtn.title);
        });
    }

    document.getElementById('pdfDockZoomIn').addEventListener('click', function () { if (view) view.zoomIn(); });
    document.getElementById('pdfDockZoomOut').addEventListener('click', function () { if (view) view.zoomOut(); });
    zoomEl.addEventListener('click', function () { if (view) view.resetZoom(); });
    // No "Save image as…" on a page: the pages are pictures, and that would
    // hand one over whole.
    scroll.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    /* Where the panel opens, if the page gave it a home; see the header. */
    var homeSel  = dock.getAttribute('data-home');
    var home     = homeSel ? document.querySelector(homeSel) : null;
    var homeName = dock.getAttribute('data-home-name') || 'the page';
    // The sides it may be docked to ($PDF_DOCK_SIDES).
    var sides    = (dock.getAttribute('data-sides') || 'left right').split(' ');
    // What a docked card lines its top up with ($PDF_DOCK_CARD), and its gap.
    var cardSel  = dock.getAttribute('data-card');
    var cardEl   = cardSel ? document.querySelector(cardSel) : null;
    // --pdf-dock-gap, read from the stylesheet so a page can set its own.
    var GAP      = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--pdf-dock-gap')) || 16;
    function asCard() { return !!cardEl && window.innerWidth > 900; }
    // 'right' is the only place a panel without a home ever goes.
    var mode = home ? 'inline' : 'right';

    /* Where the panel sits. It ends above the footer once the footer scrolls
       into view, rather than lying over it, and it starts under the navbar.

       Docked to the right with the breadcrumb links clear of it, that is right
       under the navbar, over the strip: the navbar is sticky, so the top is a
       fixed edge and nothing about the scroll can move it. Docked to the left (the
       links are at that end), or too narrow to leave them clear, it starts below
       the strip as the upload page's docked chat does, and the top follows the
       strip up as it scrolls away.

       The edges are worked out again on every scroll and resize, and whenever the
       page itself changes height. Whether the panel may sit over the strip depends
       on widths, not on the scroll, so that is only decided when something that
       could change it has happened (placeTop(true)): opening, docking, a resize,
       a change in the page's height or in the panel's width. */
    var header  = document.querySelector('.site-header');
    var crumbs  = document.querySelector('.crumb-bar');
    var footer  = document.querySelector('.site-footer');
    var band    = { top: 57, bottom: 0 };
    var written = { top: null, bottom: null, header: null };
    var over    = false;      // sitting over the breadcrumb strip

    // How far across the window the breadcrumb links reach.
    function crumbReach() {
        var reach = 0;
        if (crumbs) {
            crumbs.querySelectorAll('.crumb-inner > *').forEach(function (el) {
                reach = Math.max(reach, el.getBoundingClientRect().right);
            });
        }
        return reach;
    }
    // Whether a panel this wide, on the right, would leave every link uncovered.
    function clearOfCrumbs(w) { return crumbReach() + 16 <= viewW() - w; }

    function leaveStrip() {
        if (!over) return;
        over = false;
        document.body.classList.remove('pdf-over-strip');
        written.top = null;
    }
    function decideOver() {
        // A card keeps a gap under the bars, so it never sits over the strip.
        var next = !asCard() && !!crumbs && beside() && mode === 'right' && wide()
                && clearOfCrumbs(dock.getBoundingClientRect().width);
        if (next === over) return;
        over = next;
        document.body.classList.toggle('pdf-over-strip', over);
        written.top = null;           // the top edge is about to move, so it is written again
    }

    /* The panel's own edges: the bars' band, or, as a card, the gap inside it
       and a top no higher than the card it lines up with. One more measurement
       on a scroll, of a box the browser has just laid out anyway. */
    function cardEdges() {
        if (!asCard()) return { top: band.top, bottom: band.bottom };
        var cardTop = Math.round(cardEl.getBoundingClientRect().top);
        return { top: Math.max(band.top + GAP, cardTop), bottom: band.bottom + GAP };
    }

    function placeTop(measure) {
        var headerEdge = header ? Math.max(0, Math.round(header.getBoundingClientRect().bottom)) : 0;
        if (measure) decideOver();
        if (header || crumbs) {
            var top = header ? header.getBoundingClientRect().bottom : 0;
            if (crumbs && !over) top = Math.max(top, crumbs.getBoundingClientRect().bottom);
            band.top = Math.max(0, Math.round(top));
        }
        // A footer a page has hidden is no footer: its top would read 0, the whole window.
        band.bottom = footer && footer.getClientRects().length
            ? Math.max(0, Math.round(window.innerHeight - footer.getBoundingClientRect().top))
            : 0;
        /* The two edges go onto the panel itself as plain top and bottom, and only
           while it is pinned to the window: in its section it is an ordinary block,
           and the stylesheet keeps it that way (see .is-inline). They used to be
           custom properties on <html>, which every element inherits, so each
           change re-checked the style of the whole page and its drawn text; with a
           thesis open that took 50 to 100ms, on every step of a scroll, and the
           panel trailed the bars by several hundred milliseconds. Written only
           when changed: this runs on every scroll. */
        if (mode !== 'inline') {
            var edge = cardEdges();
            if ((header || crumbs || edge) && edge.top !== written.top) {
                written.top = edge.top;
                dock.style.top = edge.top + 'px';
            }
            if (edge.bottom !== written.bottom) {
                written.bottom = edge.bottom;
                dock.style.bottom = edge.bottom + 'px';
            }
        }
        /* What is left on <html> is the navbar's own edge, which a panel in its
           section scrolls clear of (scroll-margin-top). It moves only when the
           bar's height does, so it is written almost never. */
        if (header && headerEdge !== written.header) {
            written.header = headerEdge;
            document.documentElement.style.setProperty('--pdf-dock-top', headerEdge + 'px');
        }
    }
    placeTop();
    window.addEventListener('resize', placeTop);
    /* Every scroll, of whatever is doing the scrolling, heard on the way down
       (capture), since a scroll inside an element does not bubble. This was the
       window's own scroll event only, and a page that scrolls inside <body>
       instead never sends one: the panel kept the edges it opened with, with a
       gap under the navbar and the footer beneath it. That is how it was
       reported, and it is reproduced by making <body> the scroller. A scroll
       inside the panel (its pages) moves neither band and is left out.
       Measured at once rather than on a queued frame: a browser sends scroll at
       most once a frame already. */
    document.addEventListener('scroll', function (e) {
        if (e.target !== document && dock.contains(e.target)) return;
        placeTop();
    }, { capture: true, passive: true });
    /* Web fonts land after this runs and the header grows as they do, which
       would leave the seam open on the one page load that matters. The body is
       watched as well: a step of a form, or a section folding, moves the footer
       without any scroll at all. */
    if (window.ResizeObserver) {
        if (header) { new ResizeObserver(function () { placeTop(true); }).observe(header); }
        new ResizeObserver(function () { placeTop(true); }).observe(document.body);
    }
    /* And looked at again twice a second for as long as the panel is beside
       the page. The report above came from a machine whose cause could not be
       pinned down here, so an edge moved some way not covered above is put
       right within half a second instead of staying wrong until the next
       scroll. It costs three measurements, and writes nothing unless an edge
       has moved. Started and stopped with the panel's own open and close. */
    var bandWatch = null;
    function watchBands(on) {
        if (on && !bandWatch) bandWatch = setInterval(function () { placeTop(true); }, 500);
        if (!on && bandWatch) { clearInterval(bandWatch); bandWatch = null; }
    }

    /* Announced so a page can get its own furniture out of the way: the
       public paper view shuts its contents rail, which shares this side. With
       a home, "open" means docked beside the page, and a panel sitting in its
       section announces nothing: it is not in anything's way. */
    function announce(name) {
        // 'open' and 'close' are exactly when the panel starts and stops being beside the page.
        if (name === 'open') watchBands(true);
        if (name === 'close') watchBands(false);
        document.dispatchEvent(new CustomEvent('papel:pdf-dock-' + name));
    }
    function beside() { return dock.classList.contains('is-open') && mode !== 'inline'; }

    function showInline() {
        dock.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    /* Without a home the panel only ever sits on the right. Announced only when
       it actually appears: a second "open" with no "close" between them leaves
       a page that put something away on the first unable to bring it back. */
    function openBeside() {
        if (dock.classList.contains('is-open')) return;
        dock.classList.add('is-open');
        document.body.classList.add('pdf-docked');
        placeTop(true);                 // the page has just made room, which can move the footer
        announce('open');
    }

    /* ----- In its section, one page tall -----
       A fixed height (80% of the window) cut the first page off partway down
       at most widths, so a whole page was never in view without scrolling
       inside the frame. In its section the panel is now exactly as tall as
       one page drawn at the frame's width, plus its header and the stage's
       padding, so the page it opens on is all there. Worked out from the
       drawn page's shape rather than its size, so zooming in does not make
       the frame grow with it; until a page is drawn, the stylesheet's height
       stands in. Docked to a side it is pinned top and bottom instead, and
       the height is handed back to the stylesheet. */
    var dockHead = dock.querySelector('.pdf-dock-head');
    function fitInline() {
        if (mode !== 'inline' || !dock.classList.contains('is-open')) {
            dock.style.height = '';
            return;
        }
        var page = scroll.querySelector('.papel-pdf-page');
        if (!page || !page.offsetWidth || !page.offsetHeight) { return; }
        var cs     = getComputedStyle(scroll);
        var across = scroll.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        var tall   = across * (page.offsetHeight / page.offsetWidth);
        var stage  = tall + parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom);
        var frame  = dock.offsetHeight - dock.clientHeight;           // its border
        dock.style.height = Math.ceil((dockHead ? dockHead.offsetHeight : 0) + stage + frame) + 'px';
    }
    // Once per frame at most: a redraw replaces every page at once.
    var fitQueued = false;
    function queueFit() {
        if (fitQueued) return;
        fitQueued = true;
        requestAnimationFrame(function () { fitQueued = false; fitInline(); });
    }
    // Pages drawn (a new file, or a redraw at a new width), or the window resized.
    if (window.MutationObserver) { new MutationObserver(queueFit).observe(scroll, { childList: true }); }
    window.addEventListener('resize', queueFit);

    /* Move a panel with a home between its section and the two sides. */
    function setMode(next) {
        var was  = beside();
        var open = dock.classList.contains('is-open');
        mode = next;
        dock.classList.toggle('is-inline', next === 'inline');
        dock.classList.toggle('is-left', next === 'left');
        document.body.classList.toggle('pdf-docked', open && next === 'right');
        document.body.classList.toggle('pdf-docked-left', open && next === 'left');
        dock.querySelectorAll('[data-dock-to]').forEach(function (b) {
            b.hidden = b.getAttribute('data-dock-to') === next;
        });
        /* Pinned to a side it is given its edges (the cache is cleared so they are
           written now); back in its section it gives them up. Its width is settled
           first, because whether it may sit over the breadcrumb strip depends on it. */
        written.top = written.bottom = null;
        if (next !== 'right') leaveStrip();
        if (next !== 'inline') { applyStored(); placeTop(true); }
        else { dock.style.top = ''; dock.style.bottom = ''; }
        fitInline();
        var now = beside();
        if (now && !was) announce('open');
        if (was && !now) announce('close');
    }

    /* Which tile's file is showing. While it is, that tile is the panel's off
       switch, and its tooltip says so rather than offering to open it again. */
    function markShowing(link) {
        document.querySelectorAll('.pd-file.is-showing').forEach(function (el) {
            el.classList.remove('is-showing');
            if (el.hasAttribute('data-open-title')) el.title = el.getAttribute('data-open-title');
        });
        if (!link) return;
        link.classList.add('is-showing');
        if (!link.hasAttribute('data-open-title')) link.setAttribute('data-open-title', link.title || '');
        var label = link.querySelector('.pd-file-name');
        link.title = 'Close ' + (label ? label.textContent.trim() : 'the preview');
    }

    function close() {
        if (fullscreen()) document.exitFullscreen();
        var was = beside();
        dock.classList.remove('is-open');
        document.body.classList.remove('pdf-docked', 'pdf-docked-left');
        leaveStrip();
        stopShowing();                    // drop the pages and anything still loading
        markShowing(null);
        if (!home || was) announce('close');
        // Opened again, it opens in its section, wherever it was left.
        if (home) setMode('inline');
        fitInline();                      // closed: the height goes back to the stylesheet
        /* Every close, wherever the panel was: 'close' above only means it has
           stopped taking room beside the page. A page that opens the preview
           with its section (archive/view_paper.php) folds that section again. */
        announce('shut');
        syncRestoreTab();
    }

    // The tab is up only while the panel is not, and only with something to bring back.
    function syncRestoreTab() {
        if (!restoreTab) return;
        restoreTab.hidden = dock.classList.contains('is-open') || !last;
        clearOfA11y();
    }

    /* Both tabs are parked half way down the right edge by default, and the
       Accessibility one is in front, so it covered this one completely: a click
       on "PDF" opened the accessibility menu, and with the panel closed there
       was no way back to it. This one stands just above that one (or below it,
       with no room above) whenever the two would meet, wherever it has been
       dragged to; anywhere else it keeps its own place. */
    function clearOfA11y() {
        if (!restoreTab || restoreTab.hidden) return;
        var st = restoreTab.style;
        st.top = ''; st.transform = '';
        var other = document.getElementById('a11y-toggle');
        var a = other ? other.getBoundingClientRect() : null;
        // The page's width, not the window's: that one includes the scrollbar.
        if (!a || !a.width || !a.height || a.right < document.documentElement.clientWidth - 2) return;
        var t = restoreTab.getBoundingClientRect(), gap = 8;
        if (t.bottom + gap <= a.top || t.top >= a.bottom + gap) return;
        var top = a.top - gap - t.height;
        if (top < gap) top = a.bottom + gap;
        st.top = Math.round(top) + 'px';
        st.transform = 'none';
    }
    if (restoreTab) {
        window.addEventListener('resize', clearOfA11y);
        document.addEventListener('papel:a11y-tab', clearOfA11y);
        // After the Accessibility tab is let go: its own handler places it first.
        document.addEventListener('pointerup', function () { setTimeout(clearOfA11y, 50); });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a.pd-file[data-pdf-src]');
        if (!link || e.button !== 0) return;
        var src = link.getAttribute('data-pdf-src');

        e.preventDefault();
        // The tile already showing closes it; any other tile switches to its
        // file and leaves the panel wherever the reader put it.
        if (dock.classList.contains('is-open') && link.classList.contains('is-showing')) {
            close();
            return;
        }
        markShowing(link);

        var label = link.querySelector('.pd-file-name');
        name.textContent = label ? label.textContent.trim() : 'Document';
        last = { file: null, src: src, name: name.textContent };
        /* Into its home on the first open, not on load: card_collapse.php
           wraps a card's contents at load, and arriving after it puts the
           panel beside that wrapper rather than inside it. Moved before it is
           shown, so the pages are first drawn at the width they will have. */
        if (home && dock.parentNode !== home) home.appendChild(dock);
        placeTop();                       // in case the header has changed height
        var switching = src !== showingSrc;

        if (home) {
            var wasOpen = dock.classList.contains('is-open');
            dock.classList.add('is-open');
            setMode(wasOpen ? mode : 'inline');
            if (mode === 'inline') showInline();
        } else {
            openBeside();
        }
        // Drawn once the panel is laid out, so the first pass has a width.
        if (switching) show(src);
    });

    document.getElementById('pdfDockClose').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !dock.classList.contains('is-open')) return;
        // In full screen, Esc belongs to the browser: it leaves full screen and
        // the panel stays open behind it.
        if (document.fullscreenElement) return;
        /* A dialog in front of the panel takes the key, not the panel behind it.
           Both ways are checked: a dialog whose handler ran first has already
           taken its "open" class off and says so by preventing the default; one
           whose handler runs after this one is still open. */
        if (e.defaultPrevented) return;
        if (document.querySelector('.ad-backdrop.open, .papel-dialog-backdrop.open, .modal.show')) return;
        // Open but out of sight, behind another tab of the page's side card: not this key's.
        if (dock.classList.contains('side-hidden')) return;
        close();
    });

    /* ----- Resizing, width only -----
       The panel is pinned top and bottom, so its width is the one thing worth
       dragging. Below 900px it fills the window and there is nothing to drag,
       which is also why a remembered width is only ever applied above that. */
    var grip = document.getElementById('pdfDockGrip');
    var KEY  = 'papel.pdfDockWidth';
    var MIN  = 280;

    /* The panel stops at half the window. Past that the record is the thing
       being squeezed, and the record is what the reader is here to fill in:
       the preview is beside it to be read from, not to take the page over. */
    function maxWidth() {
        return Math.max(MIN, Math.min(window.innerWidth * 0.5, window.innerWidth - 420));
    }
    function wide()     { return window.innerWidth > 900; }
    /* The width a fixed panel is laid out in. innerWidth counts the page's
       scrollbar too, which put the dragged edge that far off the cursor. */
    function viewW()    { return document.documentElement.clientWidth || window.innerWidth; }

    function setWidth(px, remember) {
        px = Math.round(Math.min(Math.max(px, MIN), maxWidth()));
        document.documentElement.style.setProperty('--pdf-dock-w', px + 'px');
        grip.setAttribute('aria-valuenow', String(px));
        if (remember) { try { localStorage.setItem(KEY, String(px)); } catch (err) {} }
        // A wider panel may reach the breadcrumb links, a narrower one may clear them.
        placeTop(true);
        return px;
    }

    function applyStored() {
        if (!wide()) {
            // Let the stylesheet's full-width rule take over again.
            document.documentElement.style.removeProperty('--pdf-dock-w');
            return;
        }
        var saved = 0;
        try { saved = parseInt(localStorage.getItem(KEY), 10) || 0; } catch (err) {}
        if (saved) setWidth(saved, false);
    }
    applyStored();
    window.addEventListener('resize', function () {
        applyStored();
        // Narrowed past the point where a page and a panel fit side by side.
        if (home && !wide() && mode !== 'inline') setMode('inline');
    });

    var dragging = false;

    grip.addEventListener('pointerdown', function (e) {
        if (!wide()) return;
        dragging = true;
        grip.setPointerCapture(e.pointerId);
        dock.classList.add('is-resizing');
        document.body.classList.add('pdf-resizing');
        e.preventDefault();
    });

    grip.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        // On the right the width is what is left of the window from the
        // cursor across; on the left it is where the cursor is.
        // A card stands the gap off the window's edge, so its width is measured from there.
        var off = asCard() ? GAP : 0;
        setWidth(mode === 'left' ? e.clientX - off : viewW() - off - e.clientX, false);
    });

    function endDrag(e) {
        if (!dragging) return;
        dragging = false;
        try { grip.releasePointerCapture(e.pointerId); } catch (err) {}
        dock.classList.remove('is-resizing');
        document.body.classList.remove('pdf-resizing');
        setWidth(dock.getBoundingClientRect().width, true);   // keep it for next time
    }
    grip.addEventListener('pointerup', endDrag);
    grip.addEventListener('pointercancel', endDrag);

    // Keyboard, for anyone not using a pointer.
    grip.addEventListener('keydown', function (e) {
        if (!wide()) return;
        var step = e.shiftKey ? 64 : 16;
        var now  = dock.getBoundingClientRect().width;
        // The arrow moves the edge being dragged, not the panel's own
        // measurement, so which arrow widens depends on the side.
        var wider = mode === 'left' ? 'ArrowRight' : 'ArrowLeft';
        var narrower = mode === 'left' ? 'ArrowLeft' : 'ArrowRight';
        if (e.key === wider)             { setWidth(now + step, true); e.preventDefault(); }
        else if (e.key === narrower)     { setWidth(now - step, true); e.preventDefault(); }
        else if (e.key === 'Home')       { setWidth(MIN, true);        e.preventDefault(); }
    });

    /* ----- From script: a local file, and putting the panel away ----- */
    function openFile(file, label) {
        if (!file) return;
        markShowing(null);                       // no tile asked for this one
        name.textContent = label || file.name || 'Document';
        last = { file: file, src: null, name: name.textContent };
        if (home && dock.parentNode !== home) home.appendChild(dock);
        placeTop();
        var switching = file !== showingFile;
        if (home) {
            var wasOpen = dock.classList.contains('is-open');
            dock.classList.add('is-open');
            setMode(wasOpen ? mode : 'inline');
            if (mode === 'inline') showInline();
        } else {
            openBeside();
        }
        // The same file, only minimised, is still drawn: it comes back as it was.
        if (switching) showFile(file);
        syncRestoreTab();
    }

    /* Put away, not closed: the pages stay drawn behind the tab, so bringing it
       back is instant and keeps the reader's place. They are redrawn at the
       right width when it reappears, since the renderer watches its own size.
       A panel with a home has its section to go back to instead. */
    function minimise() {
        if (home || !dock.classList.contains('is-open')) return;
        if (fullscreen()) document.exitFullscreen();
        dock.classList.remove('is-open');
        document.body.classList.remove('pdf-docked', 'pdf-docked-left');
        leaveStrip();
        announce('close');
        syncRestoreTab();
    }

    function restore() {
        if (home || dock.classList.contains('is-open') || !last) return;
        if (showingFile || showingSrc) {         // minimised: still drawn
            openBeside();
            syncRestoreTab();
            return;
        }
        // Closed: drawn again from what it was last showing.
        if (last.file) { openFile(last.file, last.name); return; }
        name.textContent = last.name;
        openBeside();
        show(last.src);
        syncRestoreTab();
    }

    // Nothing to bring back any more: the file was taken away.
    function forget() {
        last = null;
        if (dock.classList.contains('is-open')) close();
        else stopShowing();
        syncRestoreTab();
    }

    if (restoreTab) restoreTab.addEventListener('click', restore);

    impl = {
        openFile: openFile,
        close: function () {
            if (dock.classList.contains('is-open')) close();
            else { stopShowing(); syncRestoreTab(); }   // minimised: only the pages behind it to drop
        },
        minimise: minimise,
        restore:  restore,
        forget:   forget,
        isOpen:   function () { return dock.classList.contains('is-open'); },
        // Where an open panel is: in its section ('inline') or docked to a side.
        place:    function () { return dock.classList.contains('is-open') ? mode : null; },
        /* For a page that moves the panel itself (the upload page lends it to
           the right while a section fills the window). Only an open panel with a
           home, only to a side it may have, and only to a side on a screen wide
           enough to share: below that the panel would cover the page. */
        dockTo:   function (to) {
            if (!home || !dock.classList.contains('is-open') || to === mode) return false;
            if (to !== 'inline' && (sides.indexOf(to) === -1 || !wide())) return false;
            setMode(to);
            return true;
        }
    };
    queue.splice(0).forEach(function (call) { impl[call[0]].apply(impl, call[1]); });

    if (!home) return;

    /* ----- Docking, for a panel with a home -----
       By button: each one moves the panel where its icon shows. */
    dock.querySelectorAll('[data-dock-to]').forEach(function (b) {
        b.addEventListener('click', function () {
            var to = b.getAttribute('data-dock-to');
            setMode(to);
            if (to === 'inline') showInline();
        });
    });

    /* By drag: take the panel by its header and let go over a side. The
       window is read in thirds of a sort (the outer quarter on each side
       docks there, anything between puts it back in its section) and a
       marker shows which one the cursor is over before it is let go. */
    var head      = dock.querySelector('.pdf-dock-head');
    var drop      = document.getElementById('pdfDockDrop');
    var dropLabel = document.getElementById('pdfDockDropLabel');
    var LABELS    = { left: 'Dock on the left', right: 'Dock on the right', inline: 'Put back in ' + homeName };
    var pull      = null;

    // A side it may not be docked to is the section, as the middle of the window is.
    function zoneAt(x) {
        var w = viewW();
        if (x < w * 0.25 && sides.indexOf('left') !== -1) return 'left';
        if (x > w * 0.75 && sides.indexOf('right') !== -1) return 'right';
        return 'inline';
    }

    function dockWidth() {
        if (mode !== 'inline') return dock.getBoundingClientRect().width;
        var saved = 0;
        try { saved = parseInt(localStorage.getItem(KEY), 10) || 0; } catch (err) {}
        var px = saved || Math.min(window.innerWidth * 0.46, 736);
        return Math.min(Math.max(px, MIN), maxWidth());
    }

    // Where the panel would land, drawn on the window. Placed from script,
    // which the CSP allows, rather than with style attributes, which it drops.
    function showDrop(zone) {
        placeTop();
        // Where the panel would actually sit: right under the navbar, over the
        // strip, on the right when the links stay clear of it; below the strip
        // anywhere else.
        var W = viewW(), w = dockWidth();
        var headerEdge = header ? Math.round(header.getBoundingClientRect().bottom) : 0;
        var stripEdge  = crumbs ? Math.max(headerEdge, Math.round(crumbs.getBoundingClientRect().bottom)) : headerEdge;
        var top  = (zone === 'right' && crumbs && wide() && clearOfCrumbs(w)) ? headerEdge : stripEdge;
        var tall = window.innerHeight - top - band.bottom;
        var off  = 0;
        // Where a card would land: inside the gap, and no higher than its card.
        if (asCard() && zone !== 'inline') {
            top  = Math.max(stripEdge + GAP, Math.round(cardEl.getBoundingClientRect().top));
            tall = window.innerHeight - top - band.bottom - GAP;
            off  = GAP;
        }
        var box = zone === 'left'  ? [off, top, w, tall]
                : zone === 'right' ? [W - w - off, top, w, tall]
                :                    [W * 0.25 + 16, top + 16, W * 0.5 - 32, tall - 32];
        drop.style.left   = box[0] + 'px';
        drop.style.top    = box[1] + 'px';
        drop.style.width  = box[2] + 'px';
        drop.style.height = box[3] + 'px';
        dropLabel.textContent = LABELS[zone];
        drop.classList.add('is-showing');
    }

    head.addEventListener('pointerdown', function (e) {
        if (!wide() || e.button !== 0 || e.target.closest('button, a') || document.fullscreenElement) return;
        pull = { x: e.clientX, y: e.clientY, moving: false, zone: mode };
        // Keeps the moves coming once the cursor leaves the header.
        try { head.setPointerCapture(e.pointerId); } catch (err) {}
    });

    head.addEventListener('pointermove', function (e) {
        if (!pull) return;
        if (!pull.moving) {
            // A few pixels of slack, so a click on the header is only a click.
            if (Math.abs(e.clientX - pull.x) + Math.abs(e.clientY - pull.y) < 6) return;
            pull.moving = true;
            dock.classList.add('is-dragging');
            document.body.classList.add('pdf-dragging');
        }
        var zone = zoneAt(e.clientX);
        if (zone !== pull.zone || !drop.classList.contains('is-showing')) {
            pull.zone = zone;
            showDrop(zone);
        }
    });

    function letGo(e) {
        if (!pull) return;
        var p = pull;
        pull = null;
        try { head.releasePointerCapture(e.pointerId); } catch (err) {}
        if (!p.moving) return;
        dock.classList.remove('is-dragging');
        document.body.classList.remove('pdf-dragging');
        drop.classList.remove('is-showing');
        // A cancelled drag (the window lost the pointer) leaves it where it was.
        if (e.type === 'pointerup' && p.zone !== mode) {
            setMode(p.zone);
            if (p.zone === 'inline') showInline();
        }
    }
    head.addEventListener('pointerup', letGo);
    head.addEventListener('pointercancel', letGo);
});
})();
</script>
