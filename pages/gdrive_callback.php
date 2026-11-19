<?php
require_once '../config/core.php';
require_once '../config/gdrive_config.php';

require_login();
$u    = current_user();
$redirect = role_home($u['user_role']);

if (isset($_GET['code'])) {
    try {
        if (!can_manage_research_operations($u)) {
            throw new RuntimeException('Only the Director or Research Coordinator can connect Google Drive.');
        }
        $client = get_gdrive_client();
        $token  = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (!isset($token['error'])) {
            $client->setAccessToken($token);
            $email = '';
            $name = '';
            try {
                $about = (new Google_Service_Drive($client))->about->get(['fields' => 'user(displayName,emailAddress)']);
                if ($about->getUser()) {
                    $email = (string)$about->getUser()->getEmailAddress();
                    $name = (string)$about->getUser()->getDisplayName();
                }
            } catch (Throwable $e) {
                error_log('GDrive connected account could not be identified: ' . $e->getMessage());
            }
            save_system_gdrive_token($token);
            if (!gdrive_record_connection($email, $name, (int)$u['user_id'])) {
                flash('error', 'Google Drive connected, but its account history could not be saved.');
            } elseif ($email === '') {
                flash('error', 'Google Drive connected, but Google did not provide the account email for the history.');
            }
            flash('success', 'Google Drive connected. Check the storage folder below before students submit papers.');
            $redirect = BASE_URL . '/app/admin/gdrive_settings.php';
        } else {
            throw new Exception('Google authentication failed: ' . ($token['error_description'] ?? $token['error']));
        }
    } catch (Throwable $e) {
        flash('error', 'Failed to connect Google Drive: ' . $e->getMessage());
    }
}

header('Location: ' . $redirect);
exit;
