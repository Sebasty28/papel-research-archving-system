<?php
/**
 * Give back the kind to notifications that lost it.
 *
 * notifications.notification_type is an ENUM, and MariaDB is not in strict
 * mode here, so a value outside the list is truncated to an empty string while
 * the insert still reports success. PaperService::approvePaper() passed
 * 'progress', which is not one of the values, so every "your paper has been
 * approved" notice was stored with no kind at all. The notification centre
 * reads the kind for the icon and the label, so those rows showed as a generic
 * notice rather than an approval.
 *
 * The code now passes 'approval'. This repairs what was already written.
 *
 * Only rows whose type is empty are touched, and each is matched on what it
 * says before it is changed, so nothing is guessed at. Safe to run twice.
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (!in_array(current_user()['user_role'], ['super_admin', 'admin'], true)) {
        http_response_code(403);
        exit('Staff only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();

$rows = $conn->query(
    "SELECT notification_id, user_id, paper_id, message, created_at
       FROM notifications WHERE notification_type = ''")->fetch_all(MYSQLI_ASSOC);

if (!$rows) {
    exit("  skipped  no notification is missing its kind\n");
}

$set = $conn->prepare("UPDATE notifications SET notification_type = ? WHERE notification_id = ?");
$fixed = $left = 0;

foreach ($rows as $r) {
    /* What the row says is what it is. An approval is the only kind this fault
       could have produced, but reading the message rather than assuming means a
       row that got here some other way is reported instead of mislabelled. */
    $type = stripos($r['message'], 'approved') !== false ? 'approval' : '';

    if ($type === '') {
        printf("  left     #%-5d %s\n", $r['notification_id'], mb_substr($r['message'], 0, 60));
        $left++;
        continue;
    }

    $set->bind_param('si', $type, $r['notification_id']);
    $set->execute();
    printf("  %-8s #%-5d user %-5d %s\n", $type, $r['notification_id'], $r['user_id'],
        mb_substr($r['message'], 0, 55));
    $fixed++;
}

echo "\n$fixed repaired, $left left alone.\n";
