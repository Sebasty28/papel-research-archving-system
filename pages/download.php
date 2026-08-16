<?php
require_once __DIR__.'/../config/core.php';
require_login();
$u = current_user();
$conn = db();

$paper_id = (int)($_GET['id'] ?? 0);
if ($paper_id <= 0) { http_response_code(400); echo 'Bad request.'; exit; }

// Check permissions
// Only Super Admin and Research Coordinator (admin) have global download privileges
$can_download = in_array($u['user_role'], ['super_admin', 'admin', 'librarian']);

// Owners can always download their own
if (!$can_download) {
    $stmt = $conn->prepare("SELECT uploaded_by FROM research_papers WHERE paper_id=?");
    $stmt->bind_param('i', $paper_id);
    $stmt->execute();
    $stmt->bind_result($owner);
    if ($stmt->fetch() && $owner == $u['user_id']) {
        $can_download = true;
    }
    $stmt->close();
}

if (!$can_download) {
    http_response_code(403);
    echo 'You do not have permission to download this file.';
    exit;
}

// Get file path
$stmt = $conn->prepare("SELECT file_path, title FROM research_papers WHERE paper_id=?");
$stmt->bind_param('i', $paper_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if ($res && file_exists($res['file_path'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . safe_name($res['title']) . '.pdf"');
    readfile($res['file_path']);
    exit;
}
http_response_code(404);
echo 'The requested file could not be found.';
exit;