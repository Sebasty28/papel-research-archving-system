<?php
/** Current pending manuscript requests for the Librarian's live notice. */
/* Watching a queue is not account activity. The session must still expire
   after the normal idle window while this browser tab remains open. */
define('PAPEL_SESSION_PEEK', true);
require_once __DIR__ . '/../../config/core.php';
require_role(['librarian']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!manuscript_requests_ready()) {
    echo json_encode(['latest_id' => 0, 'pending_count' => 0, 'new_count' => 0, 'requests' => []]);
    exit;
}

$since = max(0, (int)($_GET['since'] ?? 0));
$conn = db();
$counts = $conn->prepare("SELECT COALESCE(MAX(request_id), 0) AS latest_id,
                                  COUNT(*) AS pending_count,
                                  SUM(request_id > ?) AS new_count
                           FROM manuscript_requests WHERE status = 'pending'");
$counts->bind_param('i', $since);
$counts->execute();
$totals = $counts->get_result()->fetch_assoc() ?: [];
$counts->close();

$rows = $conn->prepare("SELECT r.request_id, r.paper_id, r.created_at,
                              COALESCE(NULLIF(s.full_name, ''), 'Student') AS student_name,
                              COALESCE(p.title, pa.title, 'Untitled paper') AS paper_title
                       FROM manuscript_requests r
                       JOIN users s ON s.user_id = r.student_user_id
                       LEFT JOIN research_papers p ON p.paper_id = r.paper_id
                       LEFT JOIN papers_archive pa ON pa.paper_id = r.paper_id
                       WHERE r.status = 'pending' AND r.request_id > ?
                       ORDER BY r.request_id DESC LIMIT 100");
$rows->bind_param('i', $since);
$rows->execute();
$requests = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
$rows->close();

echo json_encode([
    'latest_id' => (int)($totals['latest_id'] ?? 0),
    'pending_count' => (int)($totals['pending_count'] ?? 0),
    'new_count' => (int)($totals['new_count'] ?? 0),
    'requests' => $requests,
], JSON_INVALID_UTF8_SUBSTITUTE);
