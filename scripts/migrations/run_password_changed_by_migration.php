<?php
/**
 * Record who changed a password, not only that it changed.
 *
 * password_changes was written from one place: Settings, where somebody changes
 * their own. Now a reset on a management console writes to it as well, so the
 * two have to be told apart — otherwise the roll reads "Sebastian changed his
 * password three times" when an adviser reset it twice.
 *
 * Every row that exists today came from Settings, so a null changed_by means
 * "themselves" and the display treats it that way. New rows always name someone.
 *
 * Safe to run twice: it checks for the column first.
 *
 * Run it from the command line:
 *     php scripts/migrations/run_password_changed_by_migration.php
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_role(['super_admin']);
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();
$say  = function (string $line) { echo $line, PHP_EOL; };

$say('password_changes: adding changed_by');

$has = $conn->query("SHOW TABLES LIKE 'password_changes'");
if (!$has || $has->num_rows === 0) {
    $say('  password_changes does not exist yet.');
    $say('  Run run_password_audit_migration.php first.');
    exit(1);
}

$col = $conn->query("SHOW COLUMNS FROM password_changes LIKE 'changed_by'");
if ($col && $col->num_rows > 0) {
    $say('  already there, nothing to do.');
} else {
    /* Nullable and not a foreign key: the row is a record of something that
       happened, and it should outlive the account of whoever did it, the same
       way papers_archive outlives its papers. */
    $sql = "ALTER TABLE password_changes
            ADD COLUMN changed_by INT NULL DEFAULT NULL AFTER user_id";
    if ($conn->query($sql)) {
        $say('  added.');
    } else {
        $say('  failed: ' . $conn->error);
        exit(1);
    }
}

$rows = $conn->query("SELECT COUNT(*) c FROM password_changes")->fetch_assoc()['c'];
$say('  rows on file: ' . $rows . ' (any without a name are read as self-changes)');
$say('Done.');
