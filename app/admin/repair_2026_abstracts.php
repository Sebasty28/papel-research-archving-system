<?php
/** One-time bridge for filling abstracts on the owner-imported 2026 corpus. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function reply(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'Method not allowed']);
if (time() >= strtotime('2026-11-18 00:00:00 +08:00')) reply(410, ['error' => 'Closed']);
$key = (string)($_SERVER['HTTP_X_PAPEL_ABSTRACTS_KEY'] ?? '');
if ($key === '' || !hash_equals('8a6c085df042b396e506c5303f665b6667d0b2443a51ea7d4c02a36689a42a0e', hash('sha256', $key))) {
    reply(404, ['error' => 'Not found']);
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 150000) reply(413, ['error' => 'Invalid size']);
$input = json_decode($raw, true);
if (!is_array($input) || !isset($input['entries']) || !is_array($input['entries']) || count($input['entries']) !== 29) {
    reply(400, ['error' => 'Exactly 29 entries required']);
}
require_once __DIR__ . '/../../config/core.php';
$programs = programs_map();
$conn = db();
try {
    $seen = [];
    $validated = [];
    $select = $conn->prepare('SELECT p.paper_id, p.title, p.year, p.program_category, p.current_status, p.keywords, p.abstract, u.full_name FROM research_papers p JOIN users u ON u.user_id = p.uploaded_by WHERE p.paper_id = ?');
    foreach ($input['entries'] as $entry) {
        if (!is_array($entry)) throw new InvalidArgumentException('Invalid entry');
        $id = (int)($entry['paper_id'] ?? 0);
        $title = trim((string)($entry['title'] ?? ''));
        $programCode = (string)($entry['program_code'] ?? '');
        $year = (int)($entry['year'] ?? 0);
        $uploader = trim((string)($entry['uploader'] ?? ''));
        $abstract = trim((string)($entry['abstract'] ?? ''));
        if ($id < 203 || $id > 231 || isset($seen[$id]) || $title === '' || $uploader === ''
            || mb_strlen($abstract) < 400 || mb_strlen($abstract) > 6000 || !in_array($year, [2025, 2026], true)) {
            throw new InvalidArgumentException('Invalid metadata');
        }
        $seen[$id] = true;
        if ($programCode === 'BSPYSCH') $programCode = 'BSPSYCH';
        $program = array_search($programCode, $programs, true);
        if ($program === false) throw new InvalidArgumentException('Invalid program');
        $select->bind_param('i', $id);
        $select->execute();
        $found = $select->get_result()->fetch_assoc();
        if (!$found || $found['title'] !== $title || (int)$found['year'] !== $year
            || $found['program_category'] !== $program || $found['full_name'] !== $uploader
            || $found['current_status'] !== 'approved' || trim((string)$found['keywords']) === '') {
            throw new RuntimeException('Paper identity or keyword mismatch at ID ' . $id);
        }
        if ((string)$found['abstract'] !== '' && (string)$found['abstract'] !== $abstract) {
            throw new RuntimeException('Existing abstract differs at ID ' . $id);
        }
        $validated[] = [$id, $abstract];
    }
    $select->close();
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE research_papers SET abstract = ? WHERE paper_id = ? AND current_status = 'approved' AND (abstract IS NULL OR abstract = '' OR abstract = ?)");
        $changed = 0;
        foreach ($validated as [$id, $abstract]) {
            $update->bind_param('sis', $abstract, $id, $abstract);
            $update->execute();
            $changed += $update->affected_rows;
        }
        $update->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    reply(200, ['processed' => count($validated), 'changed' => $changed]);
} catch (InvalidArgumentException $e) {
    reply(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('2026 abstract repair: ' . $e->getMessage());
    reply(500, ['error' => $e->getMessage()]);
}
