<?php
/**
 * What the notification list can do to a notification: mark one read or unread,
 * mark the lot read, or delete one.
 *
 * Every branch is scoped to the signed-in user's own rows — `AND user_id = ?`
 * on each statement — so an id belonging to somebody else does nothing rather
 * than something.
 *
 * The token check is not optional here. Deleting is destructive and a plain
 * POST from another site would otherwise be enough to clear somebody's
 * notifications, so the endpoint answers 403 to a request that cannot prove it
 * came from this application.
 */
require_once __DIR__.'/../config/core.php';
require_login();
$u = current_user();
$conn = db();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

/* csrf_verify() flashes and redirects, which is for a page submitting a form.
   This one is only ever called from script, so it says no in the language the
   caller is already reading. */
if (!csrf_valid()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Stale session. Reload the page and try again.']);
    exit;
}

$action  = (string)($_POST['action'] ?? '');
$notifId = (int)($_POST['notification_id'] ?? 0);
$userId  = (int)$u['user_id'];

/** Every single-row action needs an id that belongs to this person. */
function notif_one(mysqli $conn, string $sql, int $notifId, int $userId): bool
{
    if ($notifId <= 0) { return false; }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $notifId, $userId);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

switch ($action) {
    case 'mark_all_read':
    case 'mark_all_unread':
        $toRead = ($action === 'mark_all_read') ? 1 : 0;
        $stmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE user_id = ? AND is_read <> ?");
        $stmt->bind_param('iii', $toRead, $userId, $toRead);
        $stmt->execute();
        $left = $conn->prepare("SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread
                                FROM notifications WHERE user_id = ?");
        $left->bind_param('i', $userId);
        $left->execute();
        $r = $left->get_result()->fetch_assoc();
        echo json_encode([
            'success' => true,
            'unread'  => (int)($r['unread'] ?? 0),
            'total'   => (int)($r['total'] ?? 0),
        ]);
        exit;

    case 'mark_read':
        $ok = notif_one($conn,
            "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?",
            $notifId, $userId);
        break;

    case 'mark_unread':
        $ok = notif_one($conn,
            "UPDATE notifications SET is_read = 0 WHERE notification_id = ? AND user_id = ?",
            $notifId, $userId);
        break;

    case 'delete':
        $ok = notif_one($conn,
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
            $notifId, $userId);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}

/* The count the badge should now show, so the caller does not have to guess
   after an action that may or may not have changed it. */
/* Both counts, because a delete moves the total as well as the unread tally
   and the full-screen list prints both. */
$cnt = $conn->prepare("SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread
                       FROM notifications WHERE user_id = ?");
$cnt->bind_param('i', $userId);
$cnt->execute();
$row = $cnt->get_result()->fetch_assoc();

echo json_encode([
    'success' => $ok,
    'unread'  => (int)($row['unread'] ?? 0),
    'total'   => (int)($row['total'] ?? 0),
]);
