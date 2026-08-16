<?php
require_once '../../config/core.php';
start_session_once();

// Check if user is a guest (either via session or role) before destroying session
$is_guest = isset($_SESSION['guest_login']) || (isset($_SESSION['user']) && isset($_SESSION['user']['user_role']) && $_SESSION['user']['user_role'] === 'guest');

// Check if coming from archive (via referrer or GET parameter)
$referrer = $_SERVER['HTTP_REFERER'] ?? '';
$from_archive = isset($_GET['from']) && $_GET['from'] === 'archive';
$from_archive = $from_archive || (strpos($referrer, '/archive/') !== false);

/* Destroy the session, and the cookie that points at it.
   Without clearing the cookie the browser keeps presenting the same id, so the
   next page reuses an emptied session — one with no CSRF token in it. Any login
   page left open from before then fails to submit, which reads as "Invalid CSRF
   token" rather than "you signed out". Dropping the cookie means the next
   request starts a clean session and is issued a fresh token. */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

// Always land on the public archive after logout
header('Location: '.BASE_URL.'/archive/index.php');
exit;
