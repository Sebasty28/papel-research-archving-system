<?php
/**
 * A paper's PDF, for PAPEL's own viewer and nothing else.
 *
 * The file tiles on the three paper pages open their file in
 * includes/pdf_dock.php, which draws the pages itself with
 * assests/js/papel-pdf-view.js. It used to frame Google Drive's viewer instead,
 * and that stopped after the first page (a spinner, and no request for page
 * two) for anyone not signed in to Google, on files set to block download.
 *
 * The bytes come from Drive at the moment they are asked for and are passed
 * straight on: nothing is kept on this server, in memory or on disk. Older
 * papers that never reached Drive are read from where they already are.
 *
 * Only the viewer may ask. It sends X-PAPEL-Viewer, a header no browser adds
 * to an address it is simply pointed at, so pasting this URL into the address
 * bar is refused instead of opening the file with a Save button beside it.
 * That is not a lock (the pages have to reach the reader's browser for anyone
 * to read them, as they did with Drive's viewer) but it keeps the ordinary
 * ways of downloading shut.
 *
 *   ?paper=<paper_id>   the manuscript
 *   ?doc=<doc_id>       a supporting document
 */
require_once __DIR__ . '/../config/core.php';
require_once __DIR__ . '/../config/gdrive_config.php';

function paper_file_refuse(int $code, string $why): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $why;
    exit;
}

if (($_SERVER['HTTP_X_PAPEL_VIEWER'] ?? '') !== '1') {
    paper_file_refuse(403, 'Open this file from its page in PAPEL.');
}

$u = current_user();
if (!$u) {
    paper_file_refuse(403, 'Sign in to open this file.');
}
/* Nothing below touches the session. Letting go of it now means a slow file
   does not hold up every other page this person opens while it downloads. */
session_write_close();

$conn    = db();
$docId   = (int)($_GET['doc'] ?? 0);
$paperId = (int)($_GET['paper'] ?? 0);
$file    = null;

if ($docId > 0) {
    $st = $conn->prepare("SELECT paper_id, file_path, gdrive_file_id FROM supporting_documents WHERE doc_id = ?");
    $st->bind_param('i', $docId);
    $st->execute();
    $file = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$file) paper_file_refuse(404, 'This file could not be found.');
    $paperId = (int)$file['paper_id'];
}
if ($paperId <= 0) {
    paper_file_refuse(404, 'This file could not be found.');
}

$st = $conn->prepare("SELECT paper_id, uploaded_by, current_status, file_path, gdrive_file_id
                        FROM research_papers WHERE paper_id = ?");
$st->bind_param('i', $paperId);
$st->execute();
$paper = $st->get_result()->fetch_assoc();
$st->close();

/* An archived paper has left research_papers but can still be read by the
   people the public page lets read it, exactly as archive/view_paper.php
   falls back to the archive. */
if (!$paper && $docId === 0) {
    $st = $conn->prepare("SELECT paper_id, uploaded_by, current_status, file_path, gdrive_file_id
                            FROM papers_archive WHERE paper_id = ?");
    $st->bind_param('i', $paperId);
    $st->execute();
    $paper = $st->get_result()->fetch_assoc();
    $st->close();
    if ($paper) $paper['_archived'] = true;
}
if (!$paper) {
    paper_file_refuse(404, 'This file could not be found.');
}
if (!paper_file_viewable($u, $paper, $docId > 0)) {
    paper_file_refuse(403, 'You do not have access to this file.');
}
$file = $file ?? $paper;

// The file's own bytes, however they are reached, go out with the same headers.
function paper_file_headers(?int $size): void {
    while (ob_get_level() > 0) ob_end_clean();     // stream it, do not buffer it
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    // Another site cannot pull this into its own page with the reader's cookies.
    header('Cross-Origin-Resource-Policy: same-origin');
    if ($size !== null) header('Content-Length: ' . $size);
}

set_time_limit(180);

$driveId = trim((string)($file['gdrive_file_id'] ?? ''));
if ($driveId !== '') {
    [$send, $size] = stream_from_gdrive($driveId);
    if ($send) {
        paper_file_headers($size);
        $send();
        exit;
    }
    // Drive would not give it up; an older paper may still have a copy on disk.
}

$disk = paper_file_disk_path($file['file_path'] ?? null);
if ($disk !== null) {
    paper_file_headers((int)filesize($disk));
    readfile($disk);
    exit;
}

paper_file_refuse($driveId !== '' ? 502 : 404, $driveId !== ''
    ? 'The file could not be fetched from Google Drive just now. Please try again.'
    : 'This file could not be found.');
