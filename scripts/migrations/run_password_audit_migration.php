<?php
/**
 * A record of who changed their own password, and when.
 *
 * The notice sent to whoever created the account says a change happened, but a
 * notice is read once and gone. Somebody supervising a roll of accounts needs
 * the list: who has changed theirs, when they last did it, and how often. That
 * is a different question from "what happened today", and the notifications
 * table is the wrong place to ask it — its messages are prose, and reading a
 * history out of them means parsing sentences.
 *
 * One row per change. Nothing here records the password, old or new: the point
 * is that an unexplained change can be spotted, not that anybody can read a
 * credential out of the audit.
 *
 * Also widens notifications.notification_type, which had no value meaning "this
 * is about an account rather than a paper". Without it a password notice was
 * filed as a 'reminder' and could not be told apart from one.
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

// ---- 1. the table ----------------------------------------------------------
$has = $conn->query("SHOW TABLES LIKE 'password_changes'");
if ($has && $has->num_rows > 0) {
    echo "  skipped  password_changes already exists\n";
} else {
    $sql = "CREATE TABLE password_changes (
                change_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    INT(11)      NOT NULL,
                changed_at DATETIME     NOT NULL,
                PRIMARY KEY (change_id),
                KEY idx_user (user_id),
                KEY idx_changed_at (changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($conn->query($sql)) {
        echo "  created  password_changes\n";
    } else {
        echo "  FAILED   password_changes: " . $conn->error . "\n";
    }
}

// ---- 2. a notification type that means "about an account" ------------------
$col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'notification_type'");
$row = $col ? $col->fetch_assoc() : null;
if (!$row) {
    echo "  FAILED   notifications.notification_type not found\n";
} elseif (strpos((string)$row['Type'], "'security'") !== false) {
    echo "  skipped  notification_type already allows 'security'\n";
} else {
    $sql = "ALTER TABLE notifications MODIFY notification_type
            ENUM('submission','approval','decline','reminder','comment','security') NOT NULL";
    if ($conn->query($sql)) {
        echo "  altered  notification_type now allows 'security'\n";
    } else {
        echo "  FAILED   notification_type: " . $conn->error . "\n";
    }
}

echo "Done.\n";
