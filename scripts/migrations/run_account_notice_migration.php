<?php
/**
 * Let a notification be about the account itself.
 *
 * When a desk edits somebody's account — a new password, a corrected surname, a
 * move up a year — the person it belongs to is now told, and told what changed.
 * That is neither a paper ('submission', 'approval'…) nor a warning about a
 * password somebody else set ('security'), so it gets its own type.
 *
 * Safe to run twice: it looks at the column definition first and does nothing if
 * 'account' is already in the list.
 *
 * Run it from the command line:
 *     php scripts/migrations/run_account_notice_migration.php
 */
require_once __DIR__ . '/../../config/core.php';

/* Over HTTP this is the Director's alone. On the command line there is no
   session to check, and whoever holds the shell already has the database. */
if (PHP_SAPI !== 'cli') {
    require_role(['super_admin']);
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();
$say  = function (string $line) { echo $line, PHP_EOL; };

$say('Notification type: adding "account"');

$col = $conn->query("SHOW COLUMNS FROM notifications LIKE 'notification_type'")->fetch_assoc();
if (!$col) {
    $say('  notifications.notification_type is missing. Nothing to do.');
    exit(1);
}

if (strpos($col['Type'], "'account'") !== false) {
    $say('  already there, nothing to do.');
} else {
    /* Rebuilt from what is there rather than written out from memory: the list
       has grown twice already, and a hardcoded ALTER would quietly drop
       whichever type was added last. */
    $inside = substr($col['Type'], strlen('enum('), -1);
    $sql = "ALTER TABLE notifications MODIFY notification_type "
         . "enum($inside,'account') NOT NULL";
    if ($conn->query($sql)) {
        $say('  added. The list is now: ' . $inside . ",'account'");
    } else {
        $say('  failed: ' . $conn->error);
        exit(1);
    }
}

$say('Done.');
