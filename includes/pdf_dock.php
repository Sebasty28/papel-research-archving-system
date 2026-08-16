<?php
/**
 * A file opened beside the record rather than in another tab.
 *
 * Clicking a file in Uploaded Files slides a panel in from the left and shows
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
    top: 60px;                      /* clears the site header */
    left: 0;
    bottom: 0;
    width: var(--pdf-dock-w);
    z-index: 900;
    display: none;
    flex-direction: column;
    background: var(--white);
    border-right: 1px solid var(--border);
    box-shadow: 4px 0 24px rgba(51, 0, 0, .10);
}
.pdf-dock.is-open { display: flex; }
.pdf-dock-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .625rem .875rem;
    background: var(--maroon); color: #fff;
    font-size: .8125rem;
}
.pdf-dock-name {
    flex: 1 1 auto; min-width: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pdf-dock-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.75rem; height: 1.75rem; flex: 0 0 auto;
    border: none; border-radius: 6px; background: none; color: #fff;
    cursor: pointer; text-decoration: none;
}
.pdf-dock-btn:hover { background: rgba(255, 255, 255, .18); color: #fff; }
.pdf-dock-btn .material-symbols-outlined { font-size: 18px; }
.pdf-dock iframe {
    flex: 1 1 auto; width: 100%; border: 0; background: #525659;
}

/* The right edge is a handle. Only the width can be dragged — the panel is
   pinned top and bottom, so there is nothing else to change. */
.pdf-dock-grip {
    position: absolute;
    top: 0; right: -3px; bottom: 0;
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
body.pdf-resizing .crumb-inner,
body.pdf-resizing > main.wrap { transition: none !important; }

/* The record slides out of the panel's way rather than hiding under it.
   Only the content columns move. Padding the <body> was the first attempt and
   it pushed the whole document sideways — the sticky header went with it, so
   the brand ended up off the right edge, and the page grew a horizontal
   scrollbar because a full-width layout plus that padding is wider than the
   window. Shifting the wrappers instead leaves the header where it belongs. */
body.pdf-docked .crumb-inner,
body.pdf-docked > main.wrap {
    margin-left: calc(var(--pdf-dock-w) + 1.25rem);
    margin-right: 1.25rem;
    max-width: none;
    transition: margin-left .18s ease;
}
/* The panel already starts below the header, so nothing needs to move it. */

/* Which file is being shown. */
.pd-file.is-showing { border-color: var(--maroon); background: var(--cream); }

/* The Back link is stepped out into the left margin on a wide screen, which is
   the very space the panel takes. With the panel open it comes back in line so
   the two cannot overlap. */
body.pdf-docked .pd-back { margin-left: 0; }

@media (max-width: 900px) {
    :root { --pdf-dock-w: 100vw; }
    .pdf-dock { max-width: none; }
    /* The panel covers the page at this width, so there is nothing to shift. */
    body.pdf-docked .crumb-inner,
    body.pdf-docked > main.wrap { margin-left: auto; margin-right: auto; }
}
</style>

<div class="pdf-dock" id="pdfDock" role="region" aria-label="Document preview">
    <div class="pdf-dock-head">
        <span class="material-symbols-outlined" style="font-size:18px;">picture_as_pdf</span>
        <span class="pdf-dock-name" id="pdfDockName">Document</span>
        <a class="pdf-dock-btn" id="pdfDockOpen" href="#" target="_blank" rel="noopener"
           title="Open in a new tab"><span class="material-symbols-outlined">open_in_new</span></a>
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
    var open  = document.getElementById('pdfDockOpen');

    function close() {
        dock.classList.remove('is-open');
        document.body.classList.remove('pdf-docked');
        frame.src = 'about:blank';        // stop the viewer loading in the background
        document.querySelectorAll('.pd-file.is-showing').forEach(function (el) {
            el.classList.remove('is-showing');
        });
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
        open.href = href;
        frame.src = href;
        dock.classList.add('is-open');
        document.body.classList.add('pdf-docked');
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
        // The panel starts at the left edge, so the cursor's x *is* the width.
        setWidth(e.clientX, false);
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
        if (e.key === 'ArrowLeft')       { setWidth(now - step, true); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { setWidth(now + step, true); e.preventDefault(); }
        else if (e.key === 'Home')       { setWidth(MIN, true);        e.preventDefault(); }
    });
});
</script>
