<?php
/**
 * users.secondary_position: a second post the same person holds.
 *
 * The constitution lets one member of staff hold two positions at once, a
 * Research Coordinator who is also a Subject Adviser being the case that
 * prompted this. The account has one user_role, which decides almost
 * everything, so the second post is stored beside it as the position's own
 * name and resolved through staff_position_map() when it is needed.
 *
 * A VARCHAR rather than an ENUM, deliberately: see "An invalid ENUM value is
 * silently thrown away" in CLAUDE.md. A kind column that MariaDB will not
 * police is policed in PHP instead, where a wrong value fails loudly.
 *
 * Safe to run twice: it looks for the column first.
 *
 *     php scripts/migrations/run_secondary_position_migration.php
 *
 * A server with no shell runs scripts/migrations/sql/2026-11-12_secondary_position.sql
 * in phpMyAdmin instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../../config/core.php';

$conn = db();
$db   = $conn->query('SELECT DATABASE() d')->fetch_assoc()['d'];

$has = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db) . "'
        AND TABLE_NAME = 'users' AND COLUMN_NAME = 'secondary_position'"
)->fetch_assoc()['c'];

if ((int)$has > 0) {
    echo "users.secondary_position already exists. Nothing to do.\n";
} else {
    $sql = "ALTER TABLE users
              ADD COLUMN secondary_position VARCHAR(50) NULL DEFAULT NULL
              COMMENT 'A second post the same person holds, by name; see staff_positions()'
              AFTER admin_level";
    if ($conn->query($sql)) {
        echo "added users.secondary_position\n";
    } else {
        fwrite(STDERR, "failed: " . $conn->error . "\n");
        exit(1);
    }
}

/* The adviser was renamed in the same release. A stored title is read through
   staff_position_aliases() either way, but leaving the old name on file means
   every page that prints it unchanged says the wrong thing. */
$fixed = 0;
foreach ([['title', 'Research Adviser', 'Subject Adviser'],
          ['secondary_position', 'Research Adviser', 'Subject Adviser']] as [$col, $from, $to]) {
    $exists = $conn->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($db) . "'
            AND TABLE_NAME = 'users' AND COLUMN_NAME = '" . $conn->real_escape_string($col) . "'"
    )->fetch_assoc()['c'];
    if (!(int)$exists) { continue; }

    $stmt = $conn->prepare("UPDATE users SET $col = ? WHERE $col = ?");
    $stmt->bind_param('ss', $to, $from);
    $stmt->execute();
    $fixed += $stmt->affected_rows;
}
echo "renamed $fixed stored position(s) from Research Adviser to Subject Adviser\n";
echo "done.\n";
