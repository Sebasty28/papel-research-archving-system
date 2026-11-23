<?php
/**
 * paper_manuscripts.file_hash: a SHA-256 for every manuscript PDF.
 *
 * The upload page refuses a PDF whose content is already on file, and it can
 * only do that by comparing something. The name is no use (two students both
 * call a paper final.pdf, and a renamed copy is still the same paper), so what
 * is kept is the hash of the bytes. NULL is a paper filed before it was kept;
 * scripts/backfill_manuscript_hashes.php fills those from Google Drive.
 *
 * Safe to run twice: it looks for the column and the index first.
 *
 *     php scripts/migrations/run_manuscript_file_hash_migration.php
 *
 * A server with no shell runs scripts/migrations/sql/2026-11-23_manuscript_file_hash.sql
 * in phpMyAdmin instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../../config/core.php';

$conn = db();
$db   = $conn->real_escape_string($conn->query('SELECT DATABASE() d')->fetch_assoc()['d']);

if (!paper_manuscripts_ready()) {
    fwrite(STDERR, "paper_manuscripts does not exist yet. Run run_paper_manuscripts_migration.php first.\n");
    exit(1);
}

$has = (int)$conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'paper_manuscripts' AND COLUMN_NAME = 'file_hash'"
)->fetch_assoc()['c'];

if ($has > 0) {
    echo "paper_manuscripts.file_hash already exists.\n";
} elseif ($conn->query(
    "ALTER TABLE paper_manuscripts
       ADD COLUMN file_hash CHAR(64) NULL DEFAULT NULL
       COMMENT 'SHA-256 of the PDF, to refuse the same file filed twice; NULL for a paper filed before it was kept'
       AFTER file_size")) {
    echo "added paper_manuscripts.file_hash\n";
} else {
    fwrite(STDERR, "failed: " . $conn->error . "\n");
    exit(1);
}

$idx = (int)$conn->query(
    "SELECT COUNT(*) c FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'paper_manuscripts' AND INDEX_NAME = 'idx_file_hash'"
)->fetch_assoc()['c'];

if ($idx > 0) {
    echo "the index idx_file_hash already exists.\n";
} elseif ($conn->query("ALTER TABLE paper_manuscripts ADD KEY idx_file_hash (file_hash)")) {
    echo "added the index idx_file_hash\n";
} else {
    fwrite(STDERR, "failed: " . $conn->error . "\n");
    exit(1);
}
