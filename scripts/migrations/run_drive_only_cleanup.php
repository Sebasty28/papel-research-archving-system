<?php
/**
 * Remove local PDF copies that Google Drive already holds.
 *
 * Submitted papers live on Drive. The local file is only the staging copy the
 * Drive upload reads from, and is now deleted as soon as the upload succeeds —
 * but papers submitted before that change still have theirs, so every one of
 * them is stored twice. This clears the duplicates.
 *
 * Nothing is deleted on trust. For each paper the Drive copy is fetched back
 * and checked to be a real PDF of a sensible size first; only then does the
 * local file go. A paper whose Drive copy cannot be fetched, or that has no
 * Drive id at all — some archived rows never got one — keeps its local file and
 * is reported, because for those the local copy is the only copy there is.
 *
 * Drafts are left alone entirely: a draft is not on Drive, so its local file is
 * the work itself.
 *
 * Safe to run twice. Nothing in the database changes — file_path still records
 * where the file was, and papers are read through the viewer, which opens them
 * on Drive.
 */
require_once __DIR__ . '/../../config/core.php';
require_once __DIR__ . '/../../config/gdrive_config.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (current_user()['user_role'] !== 'super_admin') {
        http_response_code(403);
        exit('Director only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();

if (!is_gdrive_connected()) {
    exit("  ABORTED  Google Drive is not connected — nothing checked, nothing deleted.\n");
}

$rows = [];
foreach (['research_papers', 'papers_archive'] as $table) {
    $t = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$t || $t->num_rows === 0) continue;
    foreach ($conn->query("SELECT paper_id, title, file_path, gdrive_file_id FROM `$table`")
                  ->fetch_all(MYSQLI_ASSOC) as $r) {
        $r['_table'] = $table;
        $rows[] = $r;
    }
}
foreach ($conn->query("SELECT doc_id AS paper_id, document_type AS title, file_path, gdrive_file_id
                       FROM supporting_documents")->fetch_all(MYSQLI_ASSOC) as $r) {
    $r['_table'] = 'supporting_documents';
    $rows[] = $r;
}

$freed = $deleted = $keptNoDrive = $keptUnverified = $absent = 0;

foreach ($rows as $r) {
    $local = paper_file_disk_path($r['file_path'] ?? null);
    if ($local === null) { $absent++; continue; }        // already gone, or never local

    $label = substr(preg_replace('/\s+/', ' ', (string)$r['title']), 0, 38);

    if (empty($r['gdrive_file_id'])) {
        printf("  keeping   %-38s no Drive copy — this is the only one\n", $label);
        $keptNoDrive++;
        continue;
    }

    $bytes = download_from_gdrive($r['gdrive_file_id']);
    $ok = $bytes !== null && strlen($bytes) > 100 && substr($bytes, 0, 4) === '%PDF';
    if (!$ok) {
        printf("  keeping   %-38s Drive copy could not be verified\n", $label);
        $keptUnverified++;
        continue;
    }

    $size = filesize($local) ?: 0;
    if (@unlink($local)) {
        printf("  removed   %-38s %s freed (Drive has %s)\n",
               $label, number_format($size) . ' B', number_format(strlen($bytes)) . ' B');
        $deleted++;
        $freed += $size;
    } else {
        printf("  FAILED    %-38s could not delete %s\n", $label, $local);
    }
}

/* Directories that held nothing but those files. rmdir only removes an empty
   one, so anything still holding a file is left exactly as it is. */
$base = realpath(__DIR__ . '/../../app/student/uploads/research');
if ($base !== false) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isDir()) { @rmdir($item->getPathname()); }
    }
}

echo "\n";
printf("%d removed, %s freed.\n", $deleted, number_format($freed) . ' bytes');
if ($keptNoDrive)    printf("%d kept — no Drive copy exists.\n", $keptNoDrive);
if ($keptUnverified) printf("%d kept — Drive copy could not be read back.\n", $keptUnverified);
printf("%d had no local file already.\n", $absent);
