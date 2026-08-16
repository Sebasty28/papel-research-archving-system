<?php
require_once __DIR__.'/../config/core.php';
require_login();
$u = current_user();
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param('i', $u['user_id']);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_read') {
        $notif_id = (int)($_POST['notification_id'] ?? 0);
        if ($notif_id> 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $notif_id, $u['user_id']);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit;
        }
    }
}