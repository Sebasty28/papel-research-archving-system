<?php
/**
 * Full-window PDF viewer.
 *
 * Opened from the upload page's preview panel. It renders with the same
 * papel-pdf-view module the panel uses, so a paper looks identical in both.
 *
 * The file is never uploaded to reach this page: the tab announces itself to
 * the window that opened it and the File object is handed across in memory,
 * same-origin. Nothing is written to the server or to Google Drive until the
 * upload form itself is submitted.
 */
require_once '../../config/core.php';
require_login();
$u = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PDF Preview · <?= e(APP_NAME) ?></title>
<?php require ROOT_PATH.'/includes/site_head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assests/css/papel-pdf-view.css">
<style nonce="<?= csp_nonce() ?>">
html, body { height: 100%; }
body {
    margin: 0;
    background: var(--cream);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.viewer-bar {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: .5rem;
    height: 52px;
    padding: 0 1rem;
    background: var(--maroon);
    color: #fff;
}
.viewer-name {
    flex: 1 1 auto;
    min-width: 0;
    font-family: var(--font-head);
    font-size: .875rem;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.viewer-tools { display: flex; align-items: center; gap: .25rem; flex: 0 0 auto; }
.viewer-tools button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 .5rem;
    border: none;
    border-radius: 6px;
    background: none;
    color: #fff;
    font-family: inherit;
    font-size: .75rem;
    cursor: pointer;
    transition: background .15s;
}
.viewer-tools button:hover { background: rgba(255,255,255,.18); }
.viewer-tools button.active { background: rgba(255,255,255,.28); }

/* The pages are drawn onto canvases we own, inside a scroller we size — the
   same arrangement the upload page's side panel uses. */
/* The stage owns the remaining height; the scroller fills it. Splitting them
   gives the hint toast a positioned ancestor that does not scroll away. */
#viewer-body {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
}
#viewer-scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: auto;
    padding: 1.25rem;
    text-align: center;
}
/* Page, text-layer and cursor styling comes from the shared stylesheet; only
   the roomier spacing of the full-window view is set here. */
.papel-pdf-page { margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(51, 0, 0, .18); }
#viewer-status {
    padding: 2rem 1rem;
    text-align: center;
    font-size: .875rem;
    color: var(--grey);
}
#viewer-status:empty { display: none; }
#viewer-scroll::-webkit-scrollbar { width: 12px; height: 12px; }
#viewer-scroll::-webkit-scrollbar-track { background: var(--cream); }
#viewer-scroll::-webkit-scrollbar-thumb {
    background: var(--soft-maroon);
    border-radius: 6px;
    border: 3px solid var(--cream);
}
#viewer-scroll::-webkit-scrollbar-thumb:hover { background: var(--maroon); }
</style>
</head>
<body>

<div class="viewer-bar">
    <span class="material-symbols-outlined mi-20">picture_as_pdf</span>
    <span class="viewer-name" id="viewer-name">PDF Preview</span>
    <div class="viewer-tools">
        <button type="button" id="viewerPanMode" title="Drag to move the page" aria-label="Drag to move the page" aria-pressed="false">
            <span class="material-symbols-outlined mi-20">pan_tool</span>
        </button>
        <button type="button" id="viewerZoomOut" title="Zoom out (Ctrl + mouse wheel)" aria-label="Zoom out">
            <span class="material-symbols-outlined mi-20">zoom_out</span>
        </button>
        <button type="button" id="viewerZoomLevel" title="Reset to fit width" aria-label="Reset zoom to fit width">100%</button>
        <button type="button" id="viewerZoomIn" title="Zoom in (Ctrl + mouse wheel)" aria-label="Zoom in">
            <span class="material-symbols-outlined mi-20">zoom_in</span>
        </button>
        <button type="button" id="viewerClose" title="Close this tab" aria-label="Close this tab">
            <span class="material-symbols-outlined mi-20">close</span>
        </button>
    </div>
</div>

<div id="viewer-body" class="papel-pdf-stage">
    <div id="viewer-scroll" class="papel-pdf-scroll" tabindex="0"></div>
    <div id="viewer-status">Waiting for the document…</div>
</div>

<script src="<?= BASE_URL ?>/assests/js/pdfjs/pdf.min.js"></script>
<script src="<?= BASE_URL ?>/assests/js/papel-pdf-view.js"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';

    var ORIGIN = window.location.origin;
    var WORKER = <?= json_encode(BASE_URL . '/assests/js/pdfjs/pdf.worker.min.js', JSON_UNESCAPED_SLASHES) ?>;

    var view = papelPdfView.create({
        scroller:  document.getElementById('viewer-scroll'),
        status:    document.getElementById('viewer-status'),
        workerSrc: WORKER,
        onZoom: function (z) {
            document.getElementById('viewerZoomLevel').textContent = Math.round(z * 100) + '%';
        },
        onMode: function (mode) {
            var btn = document.getElementById('viewerPanMode');
            var panning = (mode === 'pan');
            btn.classList.toggle('active', panning);
            btn.setAttribute('aria-pressed', panning ? 'true' : 'false');
            btn.title = panning
                ? 'Drag mode — press T or Esc, or click, to select text instead'
                : 'Select mode — press H, or click, to drag the page instead';
            btn.querySelector('.material-symbols-outlined').textContent =
                panning ? 'pan_tool' : 'text_select_start';
        }
    });

    document.getElementById('viewerZoomIn').addEventListener('click', function () { view.zoomIn(); });
    document.getElementById('viewerZoomOut').addEventListener('click', function () { view.zoomOut(); });
    document.getElementById('viewerZoomLevel').addEventListener('click', function () { view.resetZoom(); });
    document.getElementById('viewerPanMode').addEventListener('click', function () { view.toggleMode(); });
    view.setMode('select');   // paint the button's initial state

    // Escape leaves drag mode and returns to selecting text, matching the
    // preview panel. There is no panel to close here, so that is all it does.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (view.getMode() !== 'pan') return;
        e.preventDefault();
        view.setMode('select');
        view.hint('Select mode — drag across the text to copy it');
    });
    document.getElementById('viewerClose').addEventListener('click', function () { window.close(); });

    // The upload page holds the chosen file in memory. Only messages from this
    // same origin are accepted, and only the file itself is ever passed.
    window.addEventListener('message', function (e) {
        if (e.origin !== ORIGIN || !e.data || e.data.type !== 'papel-pdf-file') return;
        if (e.data.name) document.getElementById('viewer-name').textContent = e.data.name;
        view.load(e.data.file);
    });

    if (window.opener) {
        window.opener.postMessage({ type: 'papel-pdf-ready' }, ORIGIN);
    } else {
        document.getElementById('viewer-status').textContent =
            'Open this view from the upload page so it can hand over the document.';
    }
})();
</script>
</body>
</html>
