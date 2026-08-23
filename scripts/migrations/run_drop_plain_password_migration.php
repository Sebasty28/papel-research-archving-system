<?php
/**
 * Stop keeping everyone's password in clear text.
 *
 * Every account was stored twice: once as a password_hash(), and once as
 * users.plain_password — the password itself — purely so the three management
 * consoles could print it in a Password column. That put every password in the
 * system in reach of anyone standing at the screen, anyone in a screen share,
 * and anyone holding a copy of the database or a backup of it. Passwords get
 * reused, so the exposure did not stop at this system.
 *
 * Nothing is lost by dropping it. A new account's credentials are emailed to
 * its owner at creation, and Reset is performed by a staff member who types
 * the new password, so they already know it. Nobody needs to look one up.
 *
 * guest_sessions.plain_password is deliberately left alone: a guest pass is a
 * shared, expiring credential the Librarian has to be able to read out, which
 * is the whole point of the feature.
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

$has = $conn->query("SHOW COLUMNS FROM users LIKE 'plain_password'");
if (!$has || $has->num_rows === 0) {
    exit("  skipped  users.plain_password is already gone\n");
}

/* Worth saying out loud how many were exposed, so the scale is on the record
   rather than implied. */
$row = $conn->query(
    "SELECT COUNT(*) AS n FROM users WHERE plain_password IS NOT NULL AND plain_password <> ''"
)->fetch_assoc();
printf("  found    %d account(s) storing a readable password\n", (int)$row['n']);

if ($conn->query("ALTER TABLE users DROP COLUMN plain_password")) {
    echo "  dropped  users.plain_password\n\n";
    echo "Those passwords still work — only the readable copy is gone.\n";
    echo "Anyone who has forgotten theirs needs a Reset from their console.\n";
} else {
    exit("  FAILED   " . $conn->error . "\n");
}
