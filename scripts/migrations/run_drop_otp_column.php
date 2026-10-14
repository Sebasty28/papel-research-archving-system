<?php
/**
 * Remove users.is_otp_exempt.
 *
 * Signing in once took an ID, a password and a six-digit code sent by email.
 * The code step was switched off long ago and has now been taken out of
 * app/auth/login.php entirely, so this column is the last of it: a flag saying
 * which accounts were allowed to skip a step that no longer exists. Nothing in
 * the code has read it since the step was disabled, and a column that nothing
 * reads is one more thing for the next person to wonder about.
 *
 * The accounts that carried the flag are printed before it goes, because the
 * column is the only place that was recorded. If the step is ever brought
 * back it will want its own column anyway, with whatever the rule is then.
 *
 * Safe to run twice.
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (current_user()['user_role'] !== 'super_admin') {
        http_response_code(403);
        exit('Director only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();

$has = $conn->query("SHOW COLUMNS FROM users LIKE 'is_otp_exempt'");
if (!$has || $has->num_rows === 0) {
    exit("  skipped  users.is_otp_exempt is already gone\n");
}

$marked = $conn->query(
    "SELECT user_id, full_name, user_role FROM users WHERE is_otp_exempt <> 0")
    ->fetch_all(MYSQLI_ASSOC);

if ($marked) {
    echo "Accounts that carried the flag, for the record:\n";
    foreach ($marked as $r) {
        printf("  %-5d %-32s %s\n", $r['user_id'], $r['full_name'], $r['user_role']);
    }
    echo "\n";
}

if ($conn->query("ALTER TABLE users DROP COLUMN is_otp_exempt")) {
    printf("  dropped  users.is_otp_exempt (%d had it set)\n", count($marked));
} else {
    exit('  FAILED   ' . $conn->error . "\n");
}
