<?php
/**
 * The head's position is the "Head of Academic Programs".
 *
 * staff_positions() listed it as "Head of Academic Affairs" while every page
 * around it said "Head of Academic Programs": the Director's console offered
 * the old name in its Position list and translated it to the new one as it drew
 * its tabs. There is now one name, and a position is stored as written, so rows
 * made before this carry the old one in users.title and, where a Director
 * handed it out as a second post, in users.secondary_position.
 *
 * Reading an old row keeps working either way (staff_position_aliases() in
 * config/core.php), which is the tolerant path for a copy of the site this has
 * not been run on. What it does not survive is a page that prints the stored
 * title unchanged, which is what this closes.
 *
 * Safe to run twice: the second run finds nothing to rename and says 0.
 *
 *     php scripts/migrations/run_head_academic_programs_migration.php
 *
 * A server with no shell runs
 * scripts/migrations/sql/2026-11-12_head_academic_programs.sql in phpMyAdmin
 * instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../../config/core.php';

$conn = db();
$db   = $conn->query('SELECT DATABASE() d')->fetch_assoc()['d'];

$from = 'Head of Academic Affairs';
$to   = 'Head of Academic Programs';

/* secondary_position is asked about rather than assumed: a copy that has not
   run 2026-11-12_secondary_position.sql has no such column, and naming it in an
   UPDATE would end the run instead of skipping the step. */
foreach (['title', 'secondary_position'] as $col) {
    $exists = $conn->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db) . "'
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = '" . $conn->real_escape_string($col) . "'"
    )->fetch_assoc()['c'];
    if (!(int)$exists) {
        echo "users.$col is not on this copy; nothing to rename.\n";
        continue;
    }

    $stmt = $conn->prepare("UPDATE users SET $col = ? WHERE $col = ?");
    $stmt->bind_param('ss', $to, $from);
    $stmt->execute();
    echo "users.$col: renamed {$stmt->affected_rows} row(s) to $to\n";
}

echo "done.\n";
