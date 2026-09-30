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
 *
 * Also opened from the file viewer on the paper pages (includes/pdf_dock.php),
 * with ?paper=<id> or ?doc=<id>: the same query app/paper_file.php takes. The
 * document is then fetched from there rather than handed across, and the same
 * rules apply: no download or print, and no Drive link. Access is checked here
 * only to name the tab; paper_file.php checks it again before a byte leaves.
 */
require_once '../../config/core.php';
require_login();
$u = current_user();

$src = $srcName = $srcError = null;
$qPaper = (int)($_GET['paper'] ?? 0);
$qDoc   = (int)($_GET['doc'] ?? 0);
if ($qPaper > 0 || $qDoc > 0) {
    $conn = db();
    $doc  = null;
    if ($qDoc > 0) {
        $st = $conn->prepare("SELECT paper_id, document_type FROM supporting_documents WHERE doc_id = ?");
        $st->bind_param('i', $qDoc);
        $st->execute();
        $doc = $st->get_result()->fetch_assoc();
        $st->close();
        $qPaper = $doc ? (int)$doc['paper_id'] : 0;
    }
    $paper = null;
    if ($qPaper > 0) {
        $st = $conn->prepare("SELECT paper_id, title, uploaded_by, current_status FROM research_papers WHERE paper_id = ?");
        $st->bind_param('i', $qPaper);
        $st->execute();
        $paper = $st->get_result()->fetch_assoc();
        $st->close();
        // An archived manuscript, as app/paper_file.php allows.
        if (!$paper && !$doc) {
            $st = $conn->prepare("SELECT paper_id, title, uploaded_by, current_status FROM papers_archive WHERE paper_id = ?");
            $st->bind_param('i', $qPaper);
            $st->execute();
            $paper = $st->get_result()->fetch_assoc();
            $st->close();
            if ($paper) $paper['_archived'] = true;
        }
    }
    if ($paper && paper_file_viewable($u, $paper, (bool)$doc)) {
        $src     = $doc ? paper_file_src('doc', $qDoc) : paper_file_src('paper', $qPaper);
        $srcName = $doc ? supporting_doc_label($doc['document_type'] ?? '') . ' · ' . $paper['title'] : $paper['title'];
    } else {
        $srcError = 'You do not have access to this file.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($srcName ?? 'PDF Preview') ?> · <?= e(APP_NAME) ?></title>
<?php require ROOT_PATH.'/includes/site_head.php'; ?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-pdf-view.css')) ?>">
<style nonce="<?= csp_nonce() ?>">
html, body { height: 100%; }
/* A paper opened from a paper page is not for printing: the same rule as the
   panel it came from. (A student's own file, before upload, is theirs.) */
@media print { body.is-paper #viewer-scroll { display: none !important; } }
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
    /* White text on it, so the non-lifting token. */
    background: var(--maroon-surface);
    color: #fff;
}
/* A paper's tab wears the same bar as the panel it was opened from
   (.pdf-dock-head in includes/pdf_dock.php), so the two read as one viewer. */
body.is-paper .viewer-bar { background: var(--maroon-surface-hover); }
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
/* The display rule below would otherwise overrule the hidden attribute. */
.viewer-tools button[hidden] { display: none; }
.viewer-tools button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 .5rem;
    border: none;
    border-radius: var(--r-control, 4px);
    background: none;
    color: #fff;
    font-family: inherit;
    font-size: .75rem;
    cursor: pointer;
    transition: background .15s;
}
.viewer-tools button:hover { background: rgba(255,255,255,.18); }
.viewer-tools button.active { background: rgba(255,255,255,.28); }

/* The pages are drawn onto canvases we own, inside a scroller we size: the
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
/* A paper opened from a paper page sits on the shared dark stage, as in the
   panel it came from (papel-pdf-view.css); these are the upload preview's. */
#viewer-body.is-dark #viewer-status { color: var(--pdf-stage-text); }
#viewer-body:not(.is-dark) #viewer-scroll::-webkit-scrollbar { width: 12px; height: 12px; }
#viewer-body:not(.is-dark) #viewer-scroll::-webkit-scrollbar-track { background: var(--cream); }
#viewer-body:not(.is-dark) #viewer-scroll::-webkit-scrollbar-thumb {
    background: var(--soft-maroon);
    border-radius: var(--r-card, 8px);
    border: 3px solid var(--cream);
}
#viewer-body:not(.is-dark) #viewer-scroll::-webkit-scrollbar-thumb:hover { background: var(--maroon); }
</style>
</head>
<body<?= $src ? ' class="is-paper"' : '' ?>>

<div class="viewer-bar">
    <span class="material-symbols-outlined mi-20">picture_as_pdf</span>
    <span class="viewer-name" id="viewer-name"><?= e($srcName ?? 'PDF Preview') ?></span>
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
        <button type="button" id="viewerFullscreen" title="Full screen" aria-label="Full screen" hidden>
            <span class="material-symbols-outlined mi-20">fullscreen</span>
        </button>
        <button type="button" id="viewerClose" title="Close this tab" aria-label="Close this tab">
            <span class="material-symbols-outlined mi-20">close</span>
        </button>
    </div>
</div>

<?php /* A paper starts busy: the ring turns while it is fetched. */ ?>
<div id="viewer-body" class="papel-pdf-stage<?= $src ? ' is-dark is-busy' : '' ?>">
    <div id="viewer-scroll" class="papel-pdf-scroll" tabindex="0"></div>
    <div class="papel-pdf-spinner" role="status" aria-label="Loading"></div>
    <div id="viewer-status"><?= e($src ? '' : ($srcError ?? 'Waiting for the document…')) ?></div>
</div>

<script src="<?= e(asset_url('assests/js/pdfjs/pdf.min.js')) ?>"></script>
<script src="<?= e(asset_url('assests/js/papel-pdf-view.js')) ?>"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';

    var ORIGIN = window.location.origin;
    var WORKER = <?= json_encode(asset_url('assests/js/pdfjs/pdf.worker.min.js'), JSON_UNESCAPED_SLASHES) ?>;
    // Set when this tab was opened for a paper; see the header.
    var SRC       = <?= json_encode($src, JSON_UNESCAPED_SLASHES) ?>;
    var SRC_ERROR = <?= json_encode($srcError) ?>;
    var statusEl  = document.getElementById('viewer-status');
    var scrollEl  = document.getElementById('viewer-scroll');
    var bodyEl    = document.getElementById('viewer-body');
    function setBusy(on) { bodyEl.classList.toggle('is-busy', on); }

    var view = papelPdfView.create({
        scroller:  scrollEl,
        status:    statusEl,
        workerSrc: WORKER,
        failMessage: SRC ? 'This file could not be shown. Please try again.' : undefined,
        loadingText: '',          // the ring says it (papel-pdf-view.css)
        onBusy:    setBusy,
        onZoom: function (z) {
            document.getElementById('viewerZoomLevel').textContent = Math.round(z * 100) + '%';
        },
        onMode: function (mode) {
            var btn = document.getElementById('viewerPanMode');
            var panning = (mode === 'pan');
            btn.classList.toggle('active', panning);
            btn.setAttribute('aria-pressed', panning ? 'true' : 'false');
            btn.title = panning
                ? 'Drag mode: press T or Esc, or click, to select text instead'
                : 'Select mode: press H, or click, to drag the page instead';
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
        view.hint('Select mode: drag across the text to copy it');
    });
    document.getElementById('viewerClose').addEventListener('click', function () { window.close(); });

    // The whole tab, full screen. Esc, or the same button, comes back out.
    var fsBtn = document.getElementById('viewerFullscreen');
    if (document.fullscreenEnabled) {
        fsBtn.hidden = false;
        fsBtn.addEventListener('click', function () {
            if (document.fullscreenElement) document.exitFullscreen();
            else document.documentElement.requestFullscreen().catch(function () {});
        });
        document.addEventListener('fullscreenchange', function () {
            var on = !!document.fullscreenElement;
            fsBtn.querySelector('.material-symbols-outlined').textContent = on ? 'fullscreen_exit' : 'fullscreen';
            fsBtn.title = on ? 'Exit full screen' : 'Full screen';
            fsBtn.setAttribute('aria-label', fsBtn.title);
        });
    }

    if (SRC) {
        /* A paper: fetched through app/paper_file.php, which answers only a
           request carrying this header, and drawn here. No right-click on the
           pages, which are pictures, so no "Save image as". */
        scrollEl.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        fetch(SRC, { headers: { 'X-PAPEL-Viewer': '1' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) {
                    return res.text().then(function (why) { throw new Error(why || 'This file could not be opened.'); });
                }
                return res.arrayBuffer();
            })
            .then(function (bytes) { return view.load(bytes); })
            .catch(function (err) {
                setBusy(false);
                statusEl.textContent = err && err.message && err.message.length < 200
                    ? err.message : 'This file could not be opened. Please try again.';
            });
        return;
    }
    if (SRC_ERROR) return;               // already said, in the status line

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
        statusEl.textContent = 'Open this view from the upload page so it can hand over the document.';
    }
})();
</script>
</body>
</html>
