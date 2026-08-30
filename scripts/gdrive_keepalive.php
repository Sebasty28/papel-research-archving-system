<?php
/**
 * Keep the Google Drive connection alive, and say plainly when it is not.
 *
 * The app already refreshes its access token whenever one expires, so in normal
 * use nothing here is needed. This exists for the two ways a connection dies
 * quietly between uses:
 *
 *   - Google revokes a refresh token that has gone six months without being
 *     used. A repository that is busy in March and quiet over the summer can
 *     come back in September to a dead connection.
 *   - A refresh token issued while the OAuth consent screen is in "Testing"
 *     expires after seven days no matter what. Nothing in this file can stop
 *     that; see the note it prints.
 *
 * Run it weekly and neither can take you by surprise. On Windows, Task
 * Scheduler; anywhere else, cron:
 *
 *     0 3 * * 1  php /path/to/capstone/scripts/gdrive_keepalive.php
 *
 * It touches nothing but the stored token, and prints one line when all is
 * well.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../config/gdrive_config.php';

$before = get_system_gdrive_token();
if (!$before) {
    fwrite(STDERR, "Google Drive has never been connected. Connect it from the\n"
                 . "Director's Storage Folder page, then run this again.\n");
    exit(1);
}
if (empty($before['refresh_token'])) {
    fwrite(STDERR, "The stored token has no refresh token, so it cannot be kept\n"
                 . "alive. Reconnect from the Storage Folder page: the app asks\n"
                 . "for offline access, which is what produces one.\n");
    exit(1);
}

/* Force a refresh rather than waiting for expiry, which is the point: an
   unused refresh token is one Google eventually throws away. */
try {
    $client = get_gdrive_client();
    $new = $client->fetchAccessTokenWithRefreshToken($before['refresh_token']);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not reach Google: " . $e->getMessage() . "\n");
    exit(1);
}

if (isset($new['error'])) {
    $err = (string)$new['error'];
    gdrive_note_refresh($err, (string)($new['error_description'] ?? ''));
    fwrite(STDERR, "The refresh was refused: $err\n");
    if ($err === 'invalid_grant') {
        fwrite(STDERR,
            "\nThat means the refresh token itself is dead, not expired. The usual\n"
          . "causes, in order:\n"
          . "  1. The OAuth consent screen is still in Testing. Google expires\n"
          . "     refresh tokens after 7 days in that mode, whatever the code does.\n"
          . "     Publish the app in Google Cloud Console to stop it.\n"
          . "  2. Somebody removed the app's access from their Google account.\n"
          . "  3. The Google account's password was changed.\n"
          . "Reconnect from the Storage Folder page to fix it.\n");
    }
    exit(1);
}

save_system_gdrive_token($client->getAccessToken());
gdrive_note_refresh('ok', 'keepalive');

$t = get_system_gdrive_token();
printf("Google Drive connection refreshed. Access token good for %d minutes.%s",
       (int)(($t['expires_in'] ?? 3600) / 60), PHP_EOL);
printf("Refresh token still held: %s%s",
       !empty($t['refresh_token']) ? 'yes' : 'NO', PHP_EOL);
exit(0);
