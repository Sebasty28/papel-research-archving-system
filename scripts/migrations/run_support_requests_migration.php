<?php
/**
 * Somewhere for support requests to live.
 *
 * Two of the topics on the contact form are not really messages: a forgotten
 * password and a correction to somebody's details are both asking a particular
 * desk to do a particular thing to a particular account. Sent as prose to one
 * shared mailbox they arrive without the facts needed to act — who is asking,
 * which account, and who is meant to deal with it — and there is no list of
 * what is still outstanding.
 *
 * A row per request, carrying those facts, so the desk that has to act can see
 * what is waiting for them.
 *
 * Nothing here holds a password. A forgotten one cannot be recovered; the whole
 * point of the request is that a new one has to be issued.
 *
 * Also widens notifications.notification_type with 'support', so a request can
 * be told apart from a paper notice and sent to the right page when clicked.
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
$has = $conn->query("SHOW TABLES LIKE 'support_requests'");
if ($has && $has->num_rows > 0) {
    echo "  skipped  support_requests already exists\n";
} else {
    $sql = "CREATE TABLE support_requests (
                request_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind              ENUM('password','account') NOT NULL,
                requester_name    VARCHAR(200) NOT NULL,
                requester_email   VARCHAR(150) NOT NULL,
                requester_role    VARCHAR(32)  NOT NULL,
                requester_ident   VARCHAR(50)  NOT NULL,
                /* Filled when the ID given matches a live account. Left null
                   rather than guessed at: a request from somebody who mistyped
                   their ID is still a request, and the desk can find them. */
                requester_user_id INT(11)      NULL,
                handler_role      VARCHAR(32)  NOT NULL,
                handler_user_id   INT(11)      NOT NULL,
                message           TEXT         NOT NULL,
                created_at        DATETIME     NOT NULL,
                PRIMARY KEY (request_id),
                KEY idx_handler (handler_user_id),
                KEY idx_created (created_at),
                KEY idx_kind (kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($conn->query($sql)) {
        echo "  created  support_requests\n";
    } else {
        echo "  FAILED   support_requests: " . $conn->error . "\n";
    }
}

// ---- a notification type that means "somebody is asking you for something" --
$col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'notification_type'");
$row = $col ? $col->fetch_assoc() : null;
if (!$row) {
    echo "  FAILED   notifications.notification_type not found\n";
} elseif (strpos((string)$row['Type'], "'support'") !== false) {
    echo "  skipped  notification_type already allows 'support'\n";
} else {
    $sql = "ALTER TABLE notifications MODIFY notification_type
            ENUM('submission','approval','decline','reminder','comment','security','support') NOT NULL";
    if ($conn->query($sql)) {
        echo "  altered  notification_type now allows 'support'\n";
    } else {
        echo "  FAILED   notification_type: " . $conn->error . "\n";
    }
}

echo "Done.\n";
