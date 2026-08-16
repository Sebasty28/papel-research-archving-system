<?php
/**
 * Academic year and section for student accounts.
 *
 * A student's year-and-section is how faculty actually refer to them — "4-1",
 * "Ladderized" — and the academic year says which intake that belongs to. The
 * users table had nowhere to put either, so the create form could not ask.
 *
 * Both are free text on purpose: the create form suggests the usual values but
 * lets an adviser type whatever their programme really uses.
 *
 * Safe to run twice — an existing column is reported and skipped.
 */
require_once __DIR__ . '/../../config/core.php';

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    require_login();
    if (($GLOBALS['__u'] = current_user()) && !in_array(current_user()['user_role'], ['super_admin', 'admin'], true)) {
        http_response_code(403);
        exit('Administrators only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();

$steps = [
    'academic_year' => "ALTER TABLE users ADD COLUMN academic_year VARCHAR(20) NULL AFTER program",
    'section'       => "ALTER TABLE users ADD COLUMN section VARCHAR(40) NULL AFTER academic_year",
];

echo "Adding academic year and section to users...\n\n";

foreach ($steps as $column => $sql) {
    $exists = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
    if ($exists && $exists->num_rows > 0) {
        echo "  skipped  $column already exists\n";
        continue;
    }
    if ($conn->query($sql)) {
        echo "  added    $column\n";
    } else {
        echo "  FAILED   $column: " . $conn->error . "\n";
    }
}

echo "\nDone.\n";
