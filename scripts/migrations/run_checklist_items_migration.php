<?php
/**
 * Room for a review checklist that is not the eleven fixed ones.
 *
 * paper_checklist held eleven yes/no columns, one run for IMRaD and one for
 * chapters. The Research Adviser can now pick a list (IMRaD, Undergraduate
 * Thesis, Capstone Project, Feasibility Study, or a blank one) and add, remove
 * or rename sections for the one paper, so what is recorded is the list itself:
 * its sections and which were ticked, as JSON in items_json.
 *
 * Rows written before this keep their eleven flags and are read as they always
 * were (paper_checklist_load in config/core.php), so nothing is converted and
 * nothing is lost. The columns are left in place for that reason.
 *
 * A server without this column still works: the checklist is limited to the three
 * lists the old columns can hold, and cannot be edited. The same statement as SQL,
 * for a host that has only phpMyAdmin, is in scripts/migrations/sql/.
 *
 * Safe to run twice.
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (!in_array(current_user()['user_role'], ['super_admin', 'admin'], true)) {
        http_response_code(403);
        exit('Staff only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();

$has = $conn->query("SHOW COLUMNS FROM paper_checklist LIKE 'items_json'");
if ($has && $has->num_rows > 0) {
    echo "  skipped  paper_checklist.items_json already exists\n";
} elseif ($conn->query("ALTER TABLE paper_checklist ADD COLUMN items_json TEXT NULL AFTER full_references")) {
    echo "  added    paper_checklist.items_json\n";
} else {
    exit("  FAILED   items_json: " . $conn->error . "\n");
}

$n = (int)($conn->query("SELECT COUNT(*) AS n FROM paper_checklist")->fetch_assoc()['n'] ?? 0);
echo "\n$n checklist(s) already recorded keep their eleven flags and are read as before.\n";
