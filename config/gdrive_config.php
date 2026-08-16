<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
if (!function_exists('db'))
    require_once __DIR__ . '/core.php';

if (!function_exists('load_env')) {
    function load_env($file = __DIR__ . '/../.env')
    {
        if (!file_exists($file)) return;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
}
load_env(__DIR__ . '/../.env');

define('GDRIVE_CLIENT_ID',        $_ENV['GDRIVE_CLIENT_ID']      ?? '');
define('GDRIVE_CLIENT_SECRET',    $_ENV['GDRIVE_CLIENT_SECRET']  ?? '');
define('GDRIVE_REDIRECT_URI',     $_ENV['GDRIVE_REDIRECT_URI']   ?? (defined('BASE_URL') ? BASE_URL . '/pages/gdrive_callback.php' : 'http://localhost/capstone/pages/gdrive_callback.php'));
define('GDRIVE_PARENT_FOLDER_ID', $_ENV['GDRIVE_PARENT_FOLDER_ID'] ?? '');
define('GDRIVE_SYSTEM_TOKEN_FILE', __DIR__ . '/gdrive_token.json');

// ── System token: stored in DB (survives deployments) + file as fallback ─────

function get_system_gdrive_token(): ?array
{
    try {
        $conn = db();
        $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='gdrive_system_token' LIMIT 1");
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row && !empty(trim($row['setting_value']))) {
                $t = json_decode($row['setting_value'], true);
                if ($t) return $t;
            }
        }
    } catch (Exception $e) {}

    if (file_exists(GDRIVE_SYSTEM_TOKEN_FILE)) {
        $t = json_decode(file_get_contents(GDRIVE_SYSTEM_TOKEN_FILE), true);
        if ($t) return $t;
    }

    return null;
}

function save_system_gdrive_token(array $token): void
{
    $json = json_encode($token);
    try {
        $conn = db();
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gdrive_system_token', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $json);
        $stmt->execute();
    } catch (Exception $e) {
        error_log('GDrive token DB save error: ' . $e->getMessage());
    }
    @file_put_contents(GDRIVE_SYSTEM_TOKEN_FILE, $json);
}

// ── Folder ID helpers ─────────────────────────────────────────────────────────

function get_gdrive_parent_folder_id(): string
{
    try {
        $conn = db();
        $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='gdrive_parent_folder_id' LIMIT 1");
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row && !empty(trim($row['setting_value'])))
                return extract_gdrive_folder_id(trim($row['setting_value']));
        }
    } catch (Exception $e) {}
    return extract_gdrive_folder_id(defined('GDRIVE_PARENT_FOLDER_ID') ? GDRIVE_PARENT_FOLDER_ID : '');
}

function extract_gdrive_folder_id(string $input): string
{
    $input = trim($input);
    if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $input, $m)) return $m[1];
    if (preg_match('#(?:[?&]id=|/file/d/|/d/|/open\?id=)([a-zA-Z0-9_-]+)#', $input, $m)) return $m[1];
    if (preg_match('#^[a-zA-Z0-9_-]+$#', $input)) return $input;
    return $input;
}

function update_gdrive_parent_folder_id(string $folderId, int $userId): bool
{
    $conn = db();
    $folderId = extract_gdrive_folder_id($folderId);
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('gdrive_parent_folder_id', ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)");
    $stmt->bind_param('si', $folderId, $userId);
    return $stmt->execute();
}

// ── OAuth client ──────────────────────────────────────────────────────────────

/**
 * Returns an authenticated Google_Client using the system OAuth token.
 * Auto-refreshes when expired and persists the new token.
 * $userId kept for backward compatibility — ignored; all uploads use system token.
 */
function get_gdrive_client($userId = null): Google_Client
{
    $client = new Google_Client();
    $client->setClientId(GDRIVE_CLIENT_ID);
    $client->setClientSecret(GDRIVE_CLIENT_SECRET);
    $client->setRedirectUri(GDRIVE_REDIRECT_URI);
    $client->addScope(Google_Service_Drive::DRIVE);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    $token = get_system_gdrive_token();
    if ($token) {
        $client->setAccessToken($token);
        if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (!isset($newToken['error'])) {
                save_system_gdrive_token($client->getAccessToken());
            }
        }
    }

    return $client;
}

/**
 * Returns true when the system token actually works.
 *
 * This used to answer "is there a refresh token in the database?", which stays
 * true long after Google has stopped honouring it. The effect was the worst
 * kind of failure: the upload page decided all was well and showed the form,
 * then every submission died at the Drive call with an error the student could
 * do nothing about. So the question asked here is now the real one — after
 * get_gdrive_client() has had its chance to refresh, do we hold a usable access
 * token? Cached per request, because several includes ask on one page load.
 */
function is_gdrive_connected($userId = null): bool
{
    static $answer = null;
    if ($answer !== null) return $answer;

    if (!get_system_gdrive_token()) return $answer = false;

    try {
        $client = get_gdrive_client();          // refreshes if the token allows it
        $t = $client->getAccessToken();
        // Still expired after that attempt means the refresh was refused.
        $answer = $t && !$client->isAccessTokenExpired();
    } catch (Throwable $e) {
        error_log('GDrive connection check failed: ' . $e->getMessage());
        $answer = false;
    }
    return $answer;
}

function get_gdrive_auth_url(): string
{
    $client = new Google_Client();
    $client->setClientId(GDRIVE_CLIENT_ID);
    $client->setClientSecret(GDRIVE_CLIENT_SECRET);
    $client->setRedirectUri(GDRIVE_REDIRECT_URI);
    $client->addScope(Google_Service_Drive::DRIVE);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    return $client->createAuthUrl();
}

function render_gdrive_signin_button(): void
{
    if (is_gdrive_connected()) {
        echo '<span class="badge text-bg-success px-3 py-2">
                <i class="bi bi-google me-1"></i> Google Drive Connected
              </span>';
    } else {
        echo '<a href="' . htmlspecialchars(get_gdrive_auth_url(), ENT_QUOTES) . '"
               class="btn btn-warning btn-sm fw-semibold">
                <i class="bi bi-google me-1"></i> Connect Google Drive
              </a>';
    }
}

/** No longer needed — kept as stub. */
function user_has_own_gdrive_token(int $userId): bool { return false; }

// ── Drive Operations ──────────────────────────────────────────────────────────

function get_or_create_folder(Google_Service_Drive $service, string $folderName, string $parentId = null): string
{
    $q = "name='" . addslashes($folderName) . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
    if ($parentId) $q .= " and '$parentId' in parents";

    $results = $service->files->listFiles(['q' => $q, 'fields' => 'files(id)']);
    if (count($results->files) > 0) return $results->files[0]->id;

    $meta = new Google_Service_Drive_DriveFile([
        'name'     => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents'  => $parentId ? [$parentId] : []
    ]);
    $folder = $service->files->create($meta, ['fields' => 'id']);
    return $folder->id;
}

function upload_to_gdrive(string $filePath, string $fileName, string $folderId = null, string $program = null, string $year = null, $userId = null): ?string
{
    if (!is_gdrive_connected())
        throw new Exception("Google Drive is not connected. An administrator must connect Google Drive first.");

    try {
        $service = new Google_Service_Drive(get_gdrive_client());

        $targetFolderId = $folderId;
        if ($program && $year) {
            $rootId = get_or_create_folder($service, 'Approved PAPERS', get_gdrive_parent_folder_id() ?: null);
            $programId = get_or_create_folder($service, $program, $rootId);
            $targetFolderId = get_or_create_folder($service, $year, $programId);
        }

        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name'                  => $fileName,
            'parents'               => $targetFolderId ? [$targetFolderId] : [],
            'viewersCanCopyContent' => false
        ]);

        $file = $service->files->create($fileMetadata, [
            'data'       => file_get_contents($filePath),
            'mimeType'   => 'application/pdf',
            'uploadType' => 'multipart',
            'fields'     => 'id'
        ]);

        $service->permissions->create($file->id, new Google_Service_Drive_Permission([
            'type' => 'anyone', 'role' => 'reader'
        ]));

        return $file->id;
    } catch (Exception $e) {
        error_log('GDrive upload error: ' . $e->getMessage());
        throw new Exception("Failed to upload file to Google Drive. Please try again.");
    }
}

function upload_paper_to_gdrive(string $filePath, string $fileName, string $program, string $year, string $paperType, string $paperTitle, string $rootFolderName = 'PAPEL - DOCUMENTS', $userId = null): ?string
{
    if (!is_gdrive_connected())
        throw new Exception("Google Drive is not connected. An administrator must connect Google Drive first.");

    try {
        $service = new Google_Service_Drive(get_gdrive_client());
        $parentFolderId = get_gdrive_parent_folder_id() ?: null;

        $rootId    = get_or_create_folder($service, $rootFolderName, $parentFolderId);
        $programId = get_or_create_folder($service, $program, $rootId);
        $yearId    = get_or_create_folder($service, $year, $programId);
        $typeId    = get_or_create_folder($service, ucfirst($paperType), $yearId);
        $paperId   = get_or_create_folder($service, $paperTitle, $typeId);

        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name'                  => $fileName,
            'parents'               => [$paperId],
            'viewersCanCopyContent' => false
        ]);

        $file = $service->files->create($fileMetadata, [
            'data'       => file_get_contents($filePath),
            'mimeType'   => 'application/pdf',
            'uploadType' => 'multipart',
            'fields'     => 'id'
        ]);

        $service->permissions->create($file->id, new Google_Service_Drive_Permission([
            'type' => 'anyone', 'role' => 'reader'
        ]));

        return $file->id;
    } catch (Exception $e) {
        error_log('GDrive paper upload error: ' . $e->getMessage());
        throw new Exception("Failed to upload paper to Google Drive. Please try again.");
    }
}

function upload_supporting_doc_to_gdrive(string $filePath, string $fileName, string $program, string $year, string $paperType, string $paperTitle, $userId = null): ?string
{
    if (!is_gdrive_connected())
        throw new Exception("Google Drive is not connected. An administrator must connect Google Drive first.");

    try {
        $service = new Google_Service_Drive(get_gdrive_client());
        $parentFolderId = get_gdrive_parent_folder_id() ?: null;

        $rootId       = get_or_create_folder($service, 'PAPEL - DOCUMENTS', $parentFolderId);
        $programId    = get_or_create_folder($service, $program, $rootId);
        $yearId       = get_or_create_folder($service, $year, $programId);
        $typeId       = get_or_create_folder($service, ucfirst($paperType), $yearId);
        $paperId      = get_or_create_folder($service, $paperTitle, $typeId);
        $supportingId = get_or_create_folder($service, 'Supporting Documents', $paperId);

        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name'                  => $fileName,
            'parents'               => [$supportingId],
            'viewersCanCopyContent' => false
        ]);

        $file = $service->files->create($fileMetadata, [
            'data'       => file_get_contents($filePath),
            'mimeType'   => 'application/pdf',
            'uploadType' => 'multipart',
            'fields'     => 'id'
        ]);

        $service->permissions->create($file->id, new Google_Service_Drive_Permission([
            'type' => 'anyone', 'role' => 'reader'
        ]));

        return $file->id;
    } catch (Exception $e) {
        error_log('GDrive supporting doc error: ' . $e->getMessage());
        throw new Exception("Failed to upload supporting document to Google Drive. Please try again.");
    }
}

function get_gdrive_link(string $fileId): string
{
    return "https://drive.google.com/file/d/{$fileId}/preview?v=" . date('Ymd');
}

/**
 * Permanently deletes a single file from Google Drive by its file ID.
 * Never throws — returns false and logs on failure so callers (e.g. the decline
 * flow) are not interrupted when Drive is unreachable.
 */
function delete_from_gdrive(string $fileId): bool
{
    $fileId = trim($fileId);
    if ($fileId === '') return false;

    if (!is_gdrive_connected()) {
        error_log('GDrive delete skipped (not connected) for file ' . $fileId);
        return false;
    }

    try {
        $service = new Google_Service_Drive(get_gdrive_client());
        $service->files->delete($fileId);
        return true;
    } catch (Exception $e) {
        // A 404 means the file is already gone — treat that as success.
        if (strpos($e->getMessage(), '404') !== false || stripos($e->getMessage(), 'notFound') !== false) {
            return true;
        }
        error_log('GDrive delete error for file ' . $fileId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Removes a paper's main PDF and all of its supporting documents from Google Drive,
 * then clears their gdrive_file_id references in the database. Used when a submission
 * is declined so rejected files stop consuming Drive storage. Returns the number of
 * Drive files successfully deleted.
 */
function purge_paper_drive_files(int $paperId): int
{
    if ($paperId <= 0) return 0;

    $deleted = 0;
    try {
        $conn = db();

        // Main paper file
        $stmt = $conn->prepare("SELECT gdrive_file_id FROM research_papers WHERE paper_id=? LIMIT 1");
        $stmt->bind_param('i', $paperId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && !empty($row['gdrive_file_id'])) {
            if (delete_from_gdrive($row['gdrive_file_id'])) {
                $deleted++;
                $upd = $conn->prepare("UPDATE research_papers SET gdrive_file_id=NULL WHERE paper_id=?");
                $upd->bind_param('i', $paperId);
                $upd->execute();
            }
        }

        // Supporting documents
        $docs = $conn->prepare("SELECT doc_id, gdrive_file_id FROM supporting_documents WHERE paper_id=? AND gdrive_file_id IS NOT NULL AND gdrive_file_id != ''");
        $docs->bind_param('i', $paperId);
        $docs->execute();
        $docRes = $docs->get_result();
        while ($doc = $docRes->fetch_assoc()) {
            if (delete_from_gdrive($doc['gdrive_file_id'])) {
                $deleted++;
                $du = $conn->prepare("UPDATE supporting_documents SET gdrive_file_id=NULL WHERE doc_id=?");
                $du->bind_param('i', $doc['doc_id']);
                $du->execute();
            }
        }
    } catch (Exception $e) {
        error_log('purge_paper_drive_files error for paper ' . $paperId . ': ' . $e->getMessage());
    }

    return $deleted;
}

/** No-op stub — system token handles all Drive access. */
function grant_gdrive_access(string $userEmail): array
{
    return ['success' => true, 'message' => 'System token handles all Drive access.'];
}
