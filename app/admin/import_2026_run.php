<?php
/**
 * Temporary, single-purpose bridge for the owner-authorized 2026 corpus import.
 * Remove this file from the site immediately after the import is verified.
 * PDF bytes go directly from the importer's computer to a Drive resumable upload
 * session created with PAPEL's own production Drive connection.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function import_reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') import_reply(405, ['error' => 'Method not allowed']);
if (time() >= strtotime('2026-11-18 00:00:00 +08:00')) import_reply(410, ['error' => 'Import closed']);
$key = (string)($_SERVER['HTTP_X_PAPEL_IMPORT_KEY'] ?? '');
if ($key === '' || !hash_equals('5c299fec838f784c611af82dd63eb684bc714de5be7487c4d5cd7242ca9420e7', hash('sha256', $key))) {
    import_reply(404, ['error' => 'Not found']);
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) import_reply(413, ['error' => 'Invalid request size']);
$input = json_decode($raw, true);
if (!is_array($input)) import_reply(400, ['error' => 'Invalid JSON']);

require_once __DIR__ . '/../../config/core.php';
require_once __DIR__ . '/../../config/gdrive_config.php';

function import_program(string $code): string {
    if ($code === 'BSPYSCH') $code = 'BSPSYCH'; // Source folder misspelling.
    foreach (programs_map() as $name => $short) {
        if ($short === $code) return $name;
    }
    throw new InvalidArgumentException('Unknown academic program');
}

function import_paper_input(array $input): array {
    $paper = $input['paper'] ?? null;
    if (!is_array($paper)) throw new InvalidArgumentException('Paper missing');
    $title = trim((string)($paper['title'] ?? ''));
    $authors = $paper['authors'] ?? null;
    $uploader = trim((string)($paper['uploader'] ?? ''));
    $programCode = (string)($paper['program_code'] ?? '');
    $program = import_program($programCode);
    $year = (int)($paper['year'] ?? 0);
    $type = (string)($paper['paper_type'] ?? '');
    if ($title === '' || mb_strlen($title) > 500 || !is_array($authors) || count($authors) < 1 || count($authors) > 20 || $year < 2025 || $year > 2026) {
        throw new InvalidArgumentException('Invalid paper metadata');
    }
    foreach ($authors as $author) {
        if (!is_string($author) || trim($author) === '' || mb_strlen($author) > 200) {
            throw new InvalidArgumentException('Invalid author name');
        }
    }
    if ($uploader !== trim($authors[0])) throw new InvalidArgumentException('Uploader must be the first author');
    if ($type !== ($programCode === 'BSIE' ? 'project' : 'undergrad_thesis')) {
        throw new InvalidArgumentException('Paper type does not match folder');
    }
    $files = $paper['files'] ?? null;
    if (!is_array($files) || count($files) < 1 || count($files) > 2 || ($files[0]['format'] ?? '') !== 'full') {
        throw new InvalidArgumentException('Full manuscript missing');
    }
    $formats = [];
    foreach ($files as $file) {
        if (!is_array($file)) throw new InvalidArgumentException('Invalid file entry');
        $format = (string)($file['format'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $md5 = (string)($file['md5'] ?? '');
        if (!in_array($format, ['full', 'imrad'], true) || isset($formats[$format]) || $size < 1000 || $size > 100 * 1024 * 1024 || !preg_match('/^[a-f0-9]{32}$/', $md5)) {
            throw new InvalidArgumentException('Invalid PDF metadata');
        }
        $formats[$format] = true;
    }
    return [$paper, $program];
}

function import_existing(mysqli $conn, string $title, string $program, int $year): ?array {
    $st = $conn->prepare('SELECT paper_id, title, current_status FROM research_papers WHERE program_category = ? AND year = ?');
    $st->bind_param('si', $program, $year);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $normal = static function (string $text): string {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text, 'UTF-8')) ?? '';
    };
    $needle = $normal($title);
    foreach ($rows as $row) {
        if ($needle === $normal((string)$row['title'])) return $row;
    }
    return null;
}

function import_folder(Google_Service_Drive $drive, array $paper): string {
    $parent = get_gdrive_parent_folder_id() ?: null;
    $root = get_or_create_folder($drive, 'PAPEL - DOCUMENTS', $parent);
    $code = $paper['program_code'] === 'BSPYSCH' ? 'BSPSYCH' : $paper['program_code'];
    $program = get_or_create_folder($drive, $code, $root);
    $year = get_or_create_folder($drive, (string)$paper['year'], $program);
    $type = get_or_create_folder($drive, ucfirst($paper['paper_type']), $year);
    return get_or_create_folder($drive, $paper['title'], $type);
}

try {
    $action = (string)($input['action'] ?? '');
    $conn = db();
    if ($action === 'preflight') {
        $r = $conn->query("SELECT COUNT(*) AS n FROM research_papers WHERE current_status='approved'")->fetch_assoc();
        import_reply(200, [
            'ready' => paper_manuscripts_ready() && is_gdrive_connected(),
            'paper_manuscripts' => paper_manuscripts_ready(),
            'drive_connected' => is_gdrive_connected(),
            'approved_count' => (int)$r['n'],
        ]);
    }
    [$paper, $program] = import_paper_input($input);
    $existing = import_existing($conn, $paper['title'], $program, (int)$paper['year']);
    if ($action === 'check') import_reply(200, ['existing' => $existing]);
    if ($existing) import_reply(409, ['error' => 'Paper already exists', 'existing' => $existing]);
    if (!paper_manuscripts_ready()) throw new RuntimeException('paper_manuscripts table missing');

    if ($action === 'start') {
        $format = (string)($input['format'] ?? '');
        $file = null;
        foreach ($paper['files'] as $candidate) {
            if ($candidate['format'] === $format) $file = $candidate;
        }
        if (!$file) throw new InvalidArgumentException('Format not in manifest');
        $client = get_gdrive_client();
        $drive = new Google_Service_Drive($client);
        $folder = import_folder($drive, $paper);
        $name = mb_substr($paper['title'], 0, 150) . ' - ' . ($format === 'full' ? 'Full Manuscript' : 'IMRAD') . '.pdf';
        $meta = new Google_Service_Drive_DriveFile(['name' => $name, 'mimeType' => 'application/pdf', 'parents' => [$folder], 'viewersCanCopyContent' => false]);
        $client->setDefer(true);
        $request = $drive->files->create($meta, ['fields' => 'id,name,size,md5Checksum,parents']);
        $media = new Google\Http\MediaFileUpload($client, $request, 'application/pdf', null, true, 4 * 1024 * 1024);
        $media->setFileSize((int)$file['size']);
        $url = $media->getResumeUri();
        $client->setDefer(false);
        import_reply(200, ['upload_url' => $url]);
    }
    if ($action !== 'commit') throw new InvalidArgumentException('Unknown action');
    $received = $input['uploaded_files'] ?? null;
    if (!is_array($received) || count($received) !== count($paper['files'])) throw new InvalidArgumentException('Missing uploaded files');
    $client = get_gdrive_client();
    $drive = new Google_Service_Drive($client);
    $folder = import_folder($drive, $paper);
    $verified = [];
    foreach ($paper['files'] as $expected) {
        $format = $expected['format'];
        $id = trim((string)($received[$format] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{10,255}$/', $id)) throw new InvalidArgumentException('Invalid Drive file ID');
        $found = $drive->files->get($id, ['fields' => 'id,name,size,mimeType,md5Checksum,parents,trashed']);
        if ($found->getTrashed() || (int)$found->getSize() !== (int)$expected['size']
            || strtolower((string)$found->getMd5Checksum()) !== strtolower($expected['md5'])
            || $found->getMimeType() !== 'application/pdf' || !in_array($folder, $found->getParents() ?: [], true)) {
            throw new RuntimeException('Uploaded PDF did not match the source file or folder');
        }
        $verified[$format] = ['id' => $id, 'size' => (int)$expected['size']];
    }

    $conn->begin_transaction();
    try {
        // Imported authors are inert account rows: they cannot sign in, and no
        // invented student ID or real email address is assigned to them.
        $identity = substr(hash('sha256', mb_strtolower($paper['uploader'] . "\0" . $program, 'UTF-8')), 0, 24);
        $username = 'archive26_' . $identity;
        $email = $username . '@example.invalid';
        $st = $conn->prepare('SELECT user_id, full_name, program, is_active FROM users WHERE username = ? LIMIT 1');
        $st->bind_param('s', $username);
        $st->execute();
        $user = $st->get_result()->fetch_assoc();
        $st->close();
        if ($user) {
            if ($user['full_name'] !== $paper['uploader'] || $user['program'] !== $program || (int)$user['is_active'] !== 0) {
                throw new RuntimeException('Archive account identity collision');
            }
            $userId = (int)$user['user_id'];
        } else {
            $password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $st = $conn->prepare("INSERT INTO users (username, email, password, full_name, user_role, program, is_active) VALUES (?, ?, ?, ?, 'student', ?, 0)");
            $st->bind_param('sssss', $username, $email, $password, $paper['uploader'], $program);
            $st->execute();
            $userId = $st->insert_id;
            $st->close();
        }
        $title = $paper['title'];
        $authors = implode('; ', array_map('trim', $paper['authors']));
        $year = (int)$paper['year'];
        $abstract = '';
        $keywords = '';
        $path = '';
        $size = $verified['full']['size'];
        $status = 'approved';
        $type = $paper['paper_type'];
        $driveId = $verified['full']['id'];
        $manuscript = isset($verified['imrad']) ? 'IMRAD,Full Manuscript' : 'Full Manuscript';
        $paperFormat = 'Manuscript';
        $st = $conn->prepare('INSERT INTO research_papers (title, author_names, year, abstract, keywords, file_path, file_size, uploaded_by, current_status, paper_type, gdrive_file_id, manuscript_type, program_category, paper_format) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->bind_param('ssisssiissssss', $title, $authors, $year, $abstract, $keywords, $path, $size, $userId, $status, $type, $driveId, $manuscript, $program, $paperFormat);
        $st->execute();
        $paperId = $st->insert_id;
        $st->close();
        $st = $conn->prepare('INSERT INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size) VALUES (?, ?, ?, ?, ?)');
        foreach ($paper['files'] as $file) {
            $format = $file['format'];
            $id = $verified[$format]['id'];
            $fileSize = $verified[$format]['size'];
            $st->bind_param('isssi', $paperId, $format, $path, $id, $fileSize);
            $st->execute();
        }
        $st->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    import_reply(200, ['paper_id' => $paperId, 'status' => 'approved', 'uploader' => $paper['uploader'], 'program' => $program]);
} catch (InvalidArgumentException $e) {
    import_reply(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('2026 corpus import: ' . $e->getMessage());
    import_reply(500, ['error' => mb_substr($e->getMessage(), 0, 300)]);
}
