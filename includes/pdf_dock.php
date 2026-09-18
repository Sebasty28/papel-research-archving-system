<?php
/**
 * A file opened beside the record rather than in another tab.
 *
 * Clicking a file in Uploaded Files slides a panel in from the right and shows
 * the PDF there, so the reader keeps the checklist, the sections and the
 * decision in view while they read. It is Google Drive's own viewer in an
 * iframe — the file already lives there, so nothing is downloaded, copied or
 * re-hosted to display it, and the CSP already allows drive.google.com as a
 * frame source.
 *
 * Files with no Drive copy have nothing for Drive to show, so those keep the
 * ordinary behaviour and open in a new tab.
 *
 * Include once, before the footer, on any page with .pd-file links.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Starting width. Dragging the panel's edge overwrites this on :root in pixels,
   so the panel and the margin that keeps the record clear of it always move
   together — there is one number, not two that could disagree. */
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
.pdf-dock iframe {
    flex: 1 1 auto; width: 100%; border: 0; background: #525659;
}

/* The inner edge is a handle. Only the width can be dragged — the panel is
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

/* An iframe swallows the pointer, so the drag would stop the moment the cursor
   crossed onto the document. It is switched off for the duration. */
.pdf-dock.is-resizing iframe { pointer-events: none; }
body.pdf-resizing, body.pdf-resizing * {
    cursor: col-resize !important;
    user-select: none !important;
    -webkit-user-select: none !important;
}
/* Nothing animates while dragging, or the panel lags behind the cursor. */
.pdf-dock.is-resizing,
body.pdf-resizing > main.wrap { transition: none !important; }

/* The record makes room on its right rather than hiding under the panel, and
   its left edge stays exactly where it was — the margin below is the one the
   centred wrapper already had, worked out rather than replaced, so nothing
   slides sideways as the panel opens. (Padding the <body> was the first
   attempt: it moved the whole document, sticky header included, and grew a
   horizontal scrollbar. Shifting the wrapper leaves the header alone.)

   The crumb strip is deliberately left out of this. Its links sit at the far
   left, the panel is at the far right, and the two cannot reach each other —
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

@media (max-width: 900px) {
    :root { --pdf-dock-w: 100vw; }
    .pdf-dock { max-width: none; }
    /* The panel covers the page at this width, so there is nothing to shift. */
    body.pdf-docked > main.wrap {
        margin-left: auto; margin-right: auto; width: 100%;
    }
}
</style>

<div class="pdf-dock" id="pdfDock" role="region" aria-label="Document preview">
    <div class="pdf-dock-head">
        <span class="material-symbols-outlined">picture_as_pdf</span>
        <span class="pdf-dock-name" id="pdfDockName">Document</span>
        <?php /* No "open in a new tab" here: the Drive viewer inside the frame
                 has its own, a few pixels below this one. */ ?>
        <button type="button" class="pdf-dock-btn" id="pdfDockClose"
                title="Close preview" aria-label="Close preview"><span class="material-symbols-outlined">close</span></button>
    </div>
    <iframe id="pdfDockFrame" title="Document preview" src="about:blank"
            allow="autoplay"></iframe>
    <button type="button" class="pdf-dock-grip" id="pdfDockGrip"
            role="separator" aria-orientation="vertical"
            title="Drag to resize — or use the arrow keys"
            aria-label="Resize the preview panel"></button>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var dock  = document.getElementById('pdfDock');
    var frame = document.getElementById('pdfDockFrame');
    var name  = document.getElementById('pdfDockName');

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

    /* Announced so a page can get its own furniture out of the way — the
       public paper view shuts its contents rail, which shares this side. */
    function announce(name) {
        document.dispatchEvent(new CustomEvent('papel:pdf-dock-' + name));
    }

    function close() {
        dock.classList.remove('is-open');
        document.body.classList.remove('pdf-docked');
        frame.src = 'about:blank';        // stop the viewer loading in the background
        document.querySelectorAll('.pd-file.is-showing').forEach(function (el) {
            el.classList.remove('is-showing');
        });
        announce('close');
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a.pd-file[href]');
        if (!link) return;

        var href = link.getAttribute('href');
        // Only Drive's own preview can be framed; anything else opens as before.
        if (!href || href.indexOf('drive.google.com') === -1) return;
        // Let the usual modifiers still open a tab.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;

        e.preventDefault();
        document.querySelectorAll('.pd-file.is-showing').forEach(function (el) {
            el.classList.remove('is-showing');
        });
        link.classList.add('is-showing');

        var label = link.querySelector('.pd-file-name');
        name.textContent = label ? label.textContent.trim() : 'Document';
        frame.src = href;
        placeTop();                       // in case the header has changed height
        dock.classList.add('is-open');
        document.body.classList.add('pdf-docked');
        announce('open');
    });

    document.getElementById('pdfDockClose').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dock.classList.contains('is-open')) close();
    });

    /* ----- Resizing, width only -----
       The panel is pinned top and bottom, so its width is the one thing worth
       dragging. Below 900px it fills the window and there is nothing to drag,
       which is also why a remembered width is only ever applied above that. */
    var grip = document.getElementById('pdfDockGrip');
    var KEY  = 'papel.pdfDockWidth';
    var MIN  = 280;

    /* The panel stops at half the window. Past that the record is the thing
       being squeezed, and the record is what the reader is here to fill in —
       the preview is beside it to be read from, not to take the page over. */
    function maxWidth() {
        return Math.max(MIN, Math.min(window.innerWidth * 0.5, window.innerWidth - 420));
    }
    function wide()     { return window.innerWidth > 900; }

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
    window.addEventListener('resize', applyStored);

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
        // The panel ends at the right edge, so its width is what is left of
        // the window from the cursor across.
        setWidth(window.innerWidth - e.clientX, false);
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
        // Leftwards is wider now that the panel is on the right: the arrow
        // moves the edge being dragged, not the panel's own measurement.
        if (e.key === 'ArrowLeft')       { setWidth(now + step, true); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { setWidth(now - step, true); e.preventDefault(); }
        else if (e.key === 'Home')       { setWidth(MIN, true);        e.preventDefault(); }
    });
});
</script>
