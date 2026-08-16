<?php
require_once __DIR__.'/config/core.php';
start_session_once();
$u = current_user();

if ($u) {
    header('Location: ' . role_home($u['user_role']));
    exit;
}

header('Location: ' . BASE_URL . '/archive/index.php');
exit;