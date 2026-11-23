<?php
/**
 * Fingerprints for the manuscript PDFs filed before the upload page kept one.
 *
 * The upload page refuses a PDF whose SHA-256 is already on file
 * (paper_duplicate_problem() in config/core.php), and a paper filed before
 * paper_manuscripts.file_hash existed has none, so it cannot be recognised until
 * it is given one. Google Drive already holds a SHA-256 of every binary file, so
 * this asks Drive for it (metadata only: nothing is downloaded) and keeps it.
 *
 * Read-only against Drive, and it only fills an empty fingerprint. Safe to run
 * twice. A file Drive cannot be asked about is listed and left empty.
 *
 *     php scripts/backfill_manuscript_hashes.php
 *
 * A server with no shell gets the same result from the Storage Folder page, which
 * records Drive's checksum as it checks each PDF.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../config/core.php';
require_once __DIR__ . '/../config/gdrive_config.php';

if (!paper_manuscripts_have_hash()) {
    fwrite(STDERR, "paper_manuscripts.file_hash does not exist. Run scripts/migrations/run_manuscript_file_hash_migration.php first.\n");
    exit(1);
}
if (!is_gdrive_connected()) {
    fwrite(STDERR, "Google Drive is not connected on this copy of the site.\n");
    exit(1);
}

$conn = db();
$rows = $conn->query(
    "SELECT manuscript_id, paper_id, format, gdrive_file_id
       FROM paper_manuscripts
      WHERE file_hash IS NULL AND gdrive_file_id IS NOT NULL AND gdrive_file_id <> ''
      ORDER BY manuscript_id"
)->fetch_all(MYSQLI_ASSOC);

if (!$rows) {
    echo "Nothing to do: every manuscript with a file on Drive already has a fingerprint.\n";
    exit(0);
}

$service = new Google_Service_Drive(get_gdrive_client());
$filled = 0; $missing = [];
foreach ($rows as $r) {
    try {
        $f = $service->files->get($r['gdrive_file_id'], ['fields' => 'id,sha256Checksum', 'supportsAllDrives' => true]);
        $sum = (string)$f->getSha256Checksum();
    } catch (Throwable $e) {
        $missing[] = "paper {$r['paper_id']} ({$r['format']}): " . $e->getMessage();
        continue;
    }
    manuscript_hash_remember($r['gdrive_file_id'], $sum);
    $has = $conn->query("SELECT file_hash FROM paper_manuscripts WHERE manuscript_id = " . (int)$r['manuscript_id'])->fetch_assoc();
    if (!empty($has['file_hash'])) {
        $filled++;
    } else {
        $missing[] = "paper {$r['paper_id']} ({$r['format']}): Drive gave no checksum";
    }
}

echo "Fingerprinted $filled of " . count($rows) . " manuscript file(s).\n";
foreach ($missing as $m) echo "  not done: $m\n";
exit($missing ? 2 : 0);
