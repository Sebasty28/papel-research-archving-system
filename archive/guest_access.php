<?php
require_once __DIR__.'/../config/core.php';
start_session_once();

if (!isset($_SESSION['temp_guest_creds'])) {
    header('Location: login.php');
    exit;
}

$creds = $_SESSION['temp_guest_creds'];

// Auto-login
$conn = db();
$stmt = $conn->prepare("SELECT guest_id, username, expires_at FROM guest_sessions WHERE username=?");
$stmt->bind_param('s', $creds['username']);
$stmt->execute();
$guest = $stmt->get_result()->fetch_assoc();

if ($guest) {
    // Create a virtual user session for the guest
    $u = [
        'user_id' => 0, // Guests don't have a real user_id in users table
        'username' => $guest['username'],
        'email' => '',
        'full_name' => 'Guest User',
        'user_role' => 'guest'
    ];
    login_user($u);
    // Set session expiry based on DB record
    $_SESSION['guest_expire'] = strtotime($guest['expires_at']);
    $_SESSION['guest_login'] = true;
}

// Clear temp creds
unset($_SESSION['temp_guest_creds']);

header('Location: index.php');
exit;