<?php
require_once __DIR__.'/../config/core.php';
start_session_once();
$u = current_user();

// Logged-in users go to their dashboard; everyone else lands on the public archive
if ($u) {
    $dest = role_home($u['user_role']);
    header('Location: ' . $dest);
    exit;
}

header('Location: ../archive/index.php');
exit;
