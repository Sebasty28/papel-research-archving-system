<?php
/**
 * Somewhere to count failed sign-ins.
 *
 * Sign-in is ID + password with no username, so the ID space is guessable by
 * design: student numbers run in sequence and staff IDs follow FAC-2026-00n.
 * With nothing counting attempts, a script could try passwords against a known
 * ID as fast as the server answered, and the password rule admits values as
 * short as "Papel1".
 *
 * One row per scope, where a scope is either "this account from this machine"
 * or "this machine, any account" — see login_throttle_* in config/core.php.
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

$has = $conn->query("SHOW TABLES LIKE 'login_attempts'");
if ($has && $has->num_rows > 0) {
    exit("  skipped  login_attempts already exists\n");
}

$sql = "CREATE TABLE login_attempts (
            scope        VARCHAR(190) NOT NULL,
            attempts     INT UNSIGNED NOT NULL DEFAULT 0,
            first_at     DATETIME     NOT NULL,
            last_at      DATETIME     NOT NULL,
            locked_until DATETIME     NULL,
            PRIMARY KEY (scope),
            KEY idx_last_at (last_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "  created  login_attempts\n";
} else {
    exit("  FAILED   " . $conn->error . "\n");
}
