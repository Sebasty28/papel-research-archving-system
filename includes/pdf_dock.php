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
 */
$PDF_DOCK_HOME      = $PDF_DOCK_HOME ?? null;
$PDF_DOCK_HOME_NAME = $PDF_DOCK_HOME_NAME ?? 'the page';
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
    /* Measured from the header itself when the panel opens, rather than the
       60px this used to assume: the header is not that tall on every page or
       at every width, and the difference showed as a band of blank page above
       the panel. The fallback only matters if the header is missing. */
    top: var(--pdf-dock-top, 57px);
    right: 0;
    bottom: 0;
    width: var(--pdf-dock-w);
    z-index: 900;
    display: none;
    flex-direction: column;
    background: var(--white);
    border-left: 1px solid var(--border);
    box-shadow: -4px 0 24px rgba(51, 0, 0, .10);
}
.pdf-dock.is-open { display: flex; }
.pdf-dock-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .625rem .875rem;
    /* The same token .crumb-bar uses. The two sit edge to edge across the top
       of the page, and on --maroon-surface this bar was the lighter of the
       two, which read as a seam rather than one strip. */
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
    top: auto; right: auto; bottom: auto; left: auto;
    width: auto;
    height: min(80vh, 56rem);
    z-index: auto;
    margin-top: 1rem;
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
    border-left: 0; border-right: 1px solid var(--border);
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
/* On this side the panel does reach the crumbs, so they make room too. */
body.pdf-docked-left .crumb-bar { padding-left: calc(var(--pdf-dock-w) + .25rem); }

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
</style>

<div class="pdf-dock<?= $PDF_DOCK_HOME ? ' has-home' : '' ?>" id="pdfDock" role="region" aria-label="Document preview"
     <?php if ($PDF_DOCK_HOME): ?>data-home="<?= e($PDF_DOCK_HOME) ?>" data-home-name="<?= e($PDF_DOCK_HOME_NAME) ?>"<?php endif; ?>>
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
            <button type="button" class="pdf-dock-btn pdf-dock-to" data-dock-to="left"
                    title="Dock on the left" aria-label="Dock the preview on the left of the window"><span class="material-symbols-outlined">dock_to_left</span></button>
            <button type="button" class="pdf-dock-btn pdf-dock-to" data-dock-to="inline"
                    title="Put back in <?= e($PDF_DOCK_HOME_NAME) ?>" aria-label="Put the preview back in <?= e($PDF_DOCK_HOME_NAME) ?>"><span class="material-symbols-outlined">dock_to_bottom</span></button>
            <button type="button" class="pdf-dock-btn pdf-dock-to" data-dock-to="right"
                    title="Dock on the right" aria-label="Dock the preview on the right of the window"><span class="material-symbols-outlined">dock_to_right</span></button>
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

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var dock   = document.getElementById('pdfDock');
    var name   = document.getElementById('pdfDockName');
    var scroll = document.getElementById('pdfDockScroll');
    var status = document.getElementById('pdfDockStatus');
    var pageEl = document.getElementById('pdfDockPage');
    var zoomEl = document.getElementById('pdfDockZoomLevel');
    var stage  = scroll.parentNode;

    // The loading ring (papel-pdf-view.css), on from the click until page one is up.
    function setBusy(on) { stage.classList.toggle('is-busy', on); }

    /* ----- The renderer, fetched the first time a file is opened ----- */
    var ASSETS = <?= json_encode([
        'pdfjs'  => asset_url('assests/js/pdfjs/pdf.min.js'),
        'worker' => asset_url('assests/js/pdfjs/pdf.worker.min.js'),
        'view'   => asset_url('assests/js/papel-pdf-view.js'),
    ], JSON_UNESCAPED_SLASHES) ?>;
    var view = null, viewReady = null, fetching = null, showingSrc = null;

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
            viewReady = loadScript(ASSETS.pdfjs).then(function () {
                return loadScript(ASSETS.view);
            }).then(function () {
                view = papelPdfView.create({
                    scroller: scroll,
                    status: status,
                    workerSrc: ASSETS.worker,
                    failMessage: 'This file could not be shown. Please try again.',
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
        pageEl.textContent = '';
        status.textContent = '';
        // On before the renderer is even fetched: the first open waits for it too.
        setBusy(true);
        return viewer().then(function (v) {
            v.clear();                                // which turns the ring off…
            setBusy(true);                            // …so on again for the fetch
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
            return view.load(bytes).then(paintPage);
        }).catch(function (err) {
            if ((err && err.name === 'AbortError') || mine !== fetching) return;
            setBusy(false);
            status.textContent = err && err.message && err.message.length < 200
                ? err.message : 'This file could not be opened. Please try again.';
        });
    }

    function stopShowing() {
        if (fetching) { fetching.abort(); fetching = null; }
        if (view) view.destroy();
        setBusy(false);
        showingSrc = null;
        pageEl.textContent = '';
        status.textContent = '';
    }

    /* A new tab, on PAPEL's full-window viewer. It takes the same query as
       app/paper_file.php (?paper= or ?doc=), so the file's own address gives
       it; the Drive link never enters into it. */
    var VIEWER = <?= json_encode(BASE_URL . '/app/student/pdf_viewer.php', JSON_UNESCAPED_SLASHES) ?>;
    document.getElementById('pdfDockNewTab').addEventListener('click', function () {
        if (!showingSrc) return;
        window.open(VIEWER + showingSrc.slice(showingSrc.indexOf('?')), '_blank', 'noopener');
    });

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
    // 'right' is the only place a panel without a home ever goes.
    var mode = home ? 'inline' : 'right';

    /* The panel starts where the header ends. The header is sticky at the top
       of the window, so its bottom edge is its height and scrolling does not
       move it; a resize can change it, and that is what is watched. */
    var header = document.querySelector('.site-header');
    function placeTop() {
        if (!header) { return; }
        var bottom = Math.max(0, Math.round(header.getBoundingClientRect().bottom));
        document.documentElement.style.setProperty('--pdf-dock-top', bottom + 'px');
    }
    placeTop();
    window.addEventListener('resize', placeTop);
    /* Web fonts land after this runs and the header grows as they do, which
       would leave the seam open on the one page load that matters. */
    if (window.ResizeObserver && header) { new ResizeObserver(placeTop).observe(header); }

    /* Announced so a page can get its own furniture out of the way: the
       public paper view shuts its contents rail, which shares this side. With
       a home, "open" means docked beside the page, and a panel sitting in its
       section announces nothing: it is not in anything's way. */
    function announce(name) {
        document.dispatchEvent(new CustomEvent('papel:pdf-dock-' + name));
    }
    function beside() { return dock.classList.contains('is-open') && mode !== 'inline'; }

    function showInline() {
        dock.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
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
        if (next !== 'inline') { placeTop(); applyStored(); }
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
            dock.classList.add('is-open');
            document.body.classList.add('pdf-docked');
            announce('open');
        }
        // Drawn once the panel is laid out, so the first pass has a width.
        if (switching) show(src);
    });

    document.getElementById('pdfDockClose').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        // In full screen, Esc belongs to the browser: it leaves full screen and
        // the panel stays open behind it.
        if (e.key === 'Escape' && dock.classList.contains('is-open') && !document.fullscreenElement) close();
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
        setWidth(mode === 'left' ? e.clientX : viewW() - e.clientX, false);
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

    function zoneAt(x) {
        var w = viewW();
        if (x < w * 0.25) return 'left';
        if (x > w * 0.75) return 'right';
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
        var top = header ? Math.max(0, header.getBoundingClientRect().bottom) : 57;
        var W = viewW(), H = window.innerHeight, w = dockWidth();
        var box = zone === 'left'  ? [0, top, w, H - top]
                : zone === 'right' ? [W - w, top, w, H - top]
                :                    [W * 0.25 + 16, top + 16, W * 0.5 - 32, H - top - 32];
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
</script>
