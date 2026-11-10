<?php
/**
 * Saves a paper to the reader's library, or takes it out.
 *
 * Asked by fetch from the Save button (includes/save_paper.php), so every
 * answer is JSON, including the refusals: a redirect to the sign-in panel
 * would reach the button as a page of HTML it cannot read, and the reader
 * would see a bookmark that looked pressed over a paper that was not saved.
 *
 * POST  _token, paper_id, save (1 to save, 0 to remove)
 * 200   {"success": true,  "saved": true|false}
 * 4xx   {"success": false, "message": "..."}
 */
require_once '../config/core.php';

header('Content-Type: application/json');

$refuse = function (int $code, string $message): void {
    http_response_code($code);
    exit(json_encode(['success' => false, 'message' => $message]));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $refuse(405, 'Saving a paper is done from its Save button.');
}

$u = current_user();
if (!$u) {
    $refuse(401, 'Your session has ended. Sign in again to save this paper.');
}
/* A Library Pass is a session, not an account: there is no users row for a
   saved paper to belong to, and the pass ends. */
if (!user_has_library($u)) {
    $refuse(403, 'Saving papers needs an account.');
}

csrf_verify();

$paperId = (int)($_POST['paper_id'] ?? 0);
$want    = ($_POST['save'] ?? '1') === '1';

/* Taking one out is always allowed: a paper that has since been returned to
   its author is no longer readable, and its row should still be removable.
   Putting one in is allowed exactly when the paper can be read. */
if ($paperId <= 0 || ($want && !paper_is_readable($paperId))) {
    $refuse(404, 'That paper is not in the repository.');
}

paper_save_set((int)$u['user_id'], $paperId, $want);

echo json_encode(['success' => true, 'saved' => $want]);
