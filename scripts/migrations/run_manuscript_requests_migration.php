<?php
/**
 * Somewhere for manuscript access requests to live.
 *
 * The public repository shows a paper's record to everyone but its actual PDF
 * to staff only. A student who wants the file itself now asks a Librarian for
 * it — a row per request, carrying who asked, which paper, and what a
 * Librarian decided, so the desk can see what is waiting and a granted
 * request can be checked for "is this still open" without a cron job: the
 * grant carries its own expires_at, read lazily wherever access is checked,
 * the same way guest_sessions and student account expiry already work.
 *
 * Also widens notifications.notification_type with 'manuscript', the same
 * way run_support_requests_migration.php added 'support'.
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

// ---- the table -------------------------------------------------------------
$has = $conn->query("SHOW TABLES LIKE 'manuscript_requests'");
if ($has && $has->num_rows > 0) {
    echo "  skipped  manuscript_requests already exists\n";
} else {
    $sql = "CREATE TABLE manuscript_requests (
                request_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
                paper_id        INT(11)      NOT NULL,
                /* Not student_id: users already has a column by that name (the
                   school ID string used to log in), a different thing from this
                   users.user_id foreign key. */
                student_user_id INT(11)      NOT NULL,
                status          ENUM('pending','granted','denied') NOT NULL DEFAULT 'pending',
                duration_hours  TINYINT UNSIGNED NULL,
                granted_by      INT(11)      NULL,
                granted_at      DATETIME     NULL,
                expires_at      DATETIME     NULL,
                denied_by       INT(11)      NULL,
                denied_at       DATETIME     NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (request_id),
                KEY idx_student (student_user_id),
                KEY idx_paper (paper_id),
                KEY idx_status_expires (status, expires_at),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($conn->query($sql)) {
        echo "  created  manuscript_requests\n";
    } else {
        echo "  FAILED   manuscript_requests: " . $conn->error . "\n";
    }
}

// ---- a notification type for it --------------------------------------------
$col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'notification_type'");
$row = $col ? $col->fetch_assoc() : null;
if (!$row) {
    echo "  FAILED   notifications.notification_type not found\n";
} elseif (strpos((string)$row['Type'], "'manuscript'") !== false) {
    echo "  skipped  notification_type already allows 'manuscript'\n";
} else {
    $sql = "ALTER TABLE notifications MODIFY notification_type
            ENUM('submission','approval','decline','reminder','comment','security','support','account','manuscript') NOT NULL";
    if ($conn->query($sql)) {
        echo "  altered  notification_type now allows 'manuscript'\n";
    } else {
        echo "  FAILED   notification_type: " . $conn->error . "\n";
    }
}

echo "Done.\n";
