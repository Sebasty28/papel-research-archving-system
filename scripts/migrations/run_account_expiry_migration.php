<?php
/**
 * How long a student account is meant to last.
 *
 * A student needs their account for as long as they are still studying, and no
 * longer. Their section says how far through they are, so it also says how much
 * time is left: a first year has five academic years ahead, a fourth year two,
 * and a ladderized intake two.
 *
 * The date is stored rather than worked out on the fly, so an account's life
 * does not silently change if the rule is ever adjusted, and so an adviser can
 * see the date the account will actually stop working.
 *
 * Existing students are backfilled from their section and academic year.
 * Safe to run twice.
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (!in_array(current_user()['user_role'], ['super_admin', 'admin', 'faculty'], true)) {
        http_response_code(403);
        exit('Staff only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();

$has = $conn->query("SHOW COLUMNS FROM users LIKE 'expires_on'");
if ($has && $has->num_rows > 0) {
    echo "  skipped  expires_on already exists\n";
} elseif ($conn->query("ALTER TABLE users ADD COLUMN expires_on DATE NULL AFTER section")) {
    echo "  added    expires_on\n";
} else {
    exit("  FAILED   expires_on: " . $conn->error . "\n");
}

echo "\nBackfilling students from their section and academic year...\n";

$rows = $conn->query(
    "SELECT user_id, full_name, section, academic_year, created_at
     FROM users WHERE user_role = 'student'")->fetch_all(MYSQLI_ASSOC);

$set = $conn->prepare("UPDATE users SET expires_on = ? WHERE user_id = ?");
$done = $skipped = 0;

foreach ($rows as $r) {
    $when = student_expiry_date($r['section'], $r['academic_year'], $r['created_at']);
    if ($when === null) {
        printf("  %-26s no rule for section %s\n", $r['full_name'], $r['section'] ?: '(none)');
        $skipped++;
        continue;
    }
    $set->bind_param('si', $when, $r['user_id']);
    $set->execute();
    printf("  %-26s %-11s %s  ->  %s\n",
        $r['full_name'], $r['section'], $r['academic_year'] ?: '(no a.y.)', $when);
    $done++;
}

echo "\n$done set, $skipped left alone.\n";
