<?php
require_once '../config/core.php';
require_once '../config/gdrive_config.php';

require_login();
$u    = current_user();
$conn = db();
$redirect = role_home($u['user_role']);

if (isset($_GET['code'])) {
    try {
        $client = get_gdrive_client();
        $token  = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (!isset($token['error'])) {
            if (in_array($u['user_role'], ['admin', 'super_admin'])) {
                save_system_gdrive_token($token);
                flash('success', 'Google Drive connected. Students can submit papers again.');
                // Back to the page the connect button lives on, so the new
                // status is visible rather than only asserted.
                if ($u['user_role'] === 'super_admin') {
                    $redirect = BASE_URL . '/app/admin/gdrive_settings.php';
                }
            } else {
                flash('error', 'Only administrators can connect Google Drive.');
            }
        } else {
            throw new Exception('Google authentication failed: ' . ($token['error_description'] ?? $token['error']));
        }
    } catch (Exception $e) {
        flash('error', 'Failed to connect Google Drive: ' . $e->getMessage());
    }
}

header('Location: ' . $redirect);
exit;
