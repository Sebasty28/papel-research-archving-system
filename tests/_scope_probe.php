<?php
/**
 * Facts about a paper's sections, for tests/m10_section_scope.py.
 *
 *     php tests/_scope_probe.php paper <paper_id>
 *     php tests/_scope_probe.php archive <paper_id> <archived_by_user_id>
 *     php tests/_scope_probe.php restore <paper_id>
 *     php tests/_scope_probe.php notes <user_id>
 *     php tests/_scope_probe.php drive_clean <paper_id> [<paper_id> ...]
 *
 * Each prints one JSON object. These exist because the sections are stored as
 * JSON inside one column, and the mysql client's batch output escapes slashes,
 * quotes and newlines in a way that makes that JSON painful to read back from
 * outside PHP.
 */
require_once __DIR__ . '/../config/core.php';
require_once __DIR__ . '/../config/gdrive_config.php';
require_once __DIR__ . '/../app/models/ArchiveRepository.php';
require_once __DIR__ . '/../app/models/ArchiveService.php';

$mode = $argv[1] ?? '';
$conn = db();

function out($v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }

/** True when a Drive folder has a file in it at any depth (a sub-folder alone does not count). */
function folder_tree_has_files(Google_Service_Drive $service, string $folderId): bool
{
    $page = null;
    do {
        $r = $service->files->listFiles([
            'q'         => "'" . $folderId . "' in parents and trashed=false",
            'fields'    => 'nextPageToken,files(id,mimeType)',
            'pageSize'  => 100,
            'pageToken' => $page,
        ]);
        foreach ($r->getFiles() as $kid) {
            if ($kid->getMimeType() !== 'application/vnd.google-apps.folder') { return true; }
            if (folder_tree_has_files($service, $kid->getId())) { return true; }
        }
        $page = $r->getNextPageToken();
    } while ($page);
    return false;
}

if ($mode === 'paper') {
    $id = (int)($argv[2] ?? 0);
    foreach (['research_papers', 'papers_archive'] as $table) {
        $q = $conn->prepare("SELECT paper_id, title, current_status, abstract, imrad_content FROM $table WHERE paper_id = ?");
        $q->bind_param('i', $id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$row) { continue; }

        $decoded  = json_decode((string)$row['imrad_content'], true);
        $filled   = [];
        if (is_array($decoded)) {
            foreach (paper_section_labels() as $key => $_) {
                // Lengths, not text: the tests need to know whether a section is
                // filled, and a whole thesis in the output is only noise.
                $filled[$key] = mb_strlen(trim(strip_tags((string)($decoded[$key] ?? ''))));
            }
        }
        out([
            'table'     => $table,
            'status'    => $row['current_status'],
            'has_key'   => is_array($decoded) && array_key_exists(PAPER_SCOPE_KEY, $decoded),
            'scope'     => is_array($decoded) ? ($decoded[PAPER_SCOPE_KEY] ?? null) : null,
            'sections'  => $filled,
            'abstract'  => mb_substr((string)$row['abstract'], 0, 120),
            'info_kind' => paper_scope_info($row['imrad_content'])['kind'],
        ]);
    }
    out(['table' => null]);
}

if ($mode === 'archive') {
    $svc = new ArchiveService($conn);
    out(['ok' => $svc->archivePaper((int)$argv[2], (int)$argv[3])]);
}

if ($mode === 'restore') {
    $svc = new ArchiveService($conn);
    out(['ok' => $svc->restorePaper((int)$argv[2])]);
}

if ($mode === 'notes') {
    $q = $conn->prepare("SELECT message FROM notifications WHERE user_id = ? ORDER BY notification_id DESC LIMIT 5");
    $q->bind_param('i', $argv[2]);
    $q->execute();
    out(array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 'message'));
}

if ($mode === 'drive_clean') {
    /* Takes back what a real submission put on Drive: the files first, by the
       app's own bookkeeping, then any now-empty folder whose name starts with
       AUTOTEST. A folder is only ever removed when it is empty and carries that
       prefix, so nothing that belongs to a real paper can be reached. */
    $deleted = ['files' => 0, 'folders' => 0];
    foreach (array_slice($argv, 2) as $pid) {
        foreach (paper_drive_file_ids((int)$pid) as $fid) {
            if (delete_from_gdrive($fid)) { $deleted['files']++; }
        }
    }
    $service = new Google_Service_Drive(get_gdrive_client());

    /* Then any file still named for a test paper. The app's own bookkeeping is
       keyed by the paper's row, and a row can be gone before this runs (the
       accounts are torn down with their papers), so a file whose paper nobody
       remembers would otherwise stay on Drive for good. The paper's PDF is named
       after its title, and every test title starts with AUTOTEST. */
    $stray = $service->files->listFiles([
        'q'        => "mimeType!='application/vnd.google-apps.folder' and trashed=false and name contains 'AUTOTEST'",
        'fields'   => 'files(id,name)',
        'pageSize' => 100,
    ]);
    foreach ($stray->getFiles() as $f) {
        if (strpos($f->getName(), 'AUTOTEST') !== 0) { continue; }
        if (delete_from_gdrive($f->getId())) { $deleted['files']++; }
    }

    $folders = $service->files->listFiles([
        'q'        => "mimeType='application/vnd.google-apps.folder' and trashed=false and name contains 'AUTOTEST'",
        'fields'   => 'files(id,name)',
        'pageSize' => 100,
    ]);
    foreach ($folders->getFiles() as $f) {
        if (strpos($f->getName(), 'AUTOTEST') !== 0) { continue; }
        /* Empty, or holding nothing but empty folders: a paper that carried supporting
           documents leaves its "Supporting Documents" folder behind inside the one it
           made, so a check for no children at all never reached it. Anything with a
           file anywhere beneath it is left alone. */
        if (!folder_tree_has_files($service, $f->getId()) && delete_from_gdrive($f->getId())) { $deleted['folders']++; }
    }
    out($deleted);
}

out(['error' => 'unknown mode']);
