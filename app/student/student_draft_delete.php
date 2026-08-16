<?php
/**
 * Delete one of the signed-in student's own drafts.
 *
 * Deliberately narrow. The WHERE clause carries three conditions — the paper
 * id, the owner, and a status of 'draft' — so this can only ever remove an
 * unsubmitted paper belonging to the person asking. A paper that has been sent
 * for review, or that belongs to somebody else, is untouchable here whatever is
 * posted.
 */
require_once '../../config/core.php';
require_role(['student']);
csrf_verify();

$conn = db();
$u = current_user();
$paper_id = (int)($_POST['paper_id'] ?? 0);

/* Send the student back to the tab they were looking at. Whitelisted rather
   than echoed back, so the posted value cannot steer the redirect anywhere. */
$tab = $_POST['tab'] ?? 'drafts';
if (!in_array($tab, ['approved', 'process', 'declined', 'drafts'], true)) $tab = 'drafts';
$back = 'student_dashboard.php?tab=' . $tab;

if ($paper_id <= 0) {
    header('Location: ' . $back);
    exit;
}

// Read it first: the row is needed to tidy up its file, and to be sure the
// student is allowed to remove it before anything is destroyed.
$find = $conn->prepare(
    "SELECT paper_id, title, file_path
       FROM research_papers
      WHERE paper_id = ? AND uploaded_by = ? AND current_status = 'draft'
      LIMIT 1"
);
$find->bind_param('ii', $paper_id, $u['user_id']);
$find->execute();
$draft = $find->get_result()->fetch_assoc();

if (!$draft) {
    // Either it is not theirs, or it is no longer a draft. Same answer both
    // ways: nothing is revealed about which.
    flash('error', 'That item could not be found. It may already have been submitted or removed.');
    header('Location: ' . $back);
    exit;
}

// Supporting documents first, so no rows are orphaned by the delete below.
$docs = $conn->prepare("SELECT file_path FROM supporting_documents WHERE paper_id = ?");
$docs->bind_param('i', $paper_id);
$docs->execute();
$docPaths = [];
foreach ($docs->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if (!empty($row['file_path'])) $docPaths[] = $row['file_path'];
}

$conn->begin_transaction();
try {
    $delDocs = $conn->prepare("DELETE FROM supporting_documents WHERE paper_id = ?");
    $delDocs->bind_param('i', $paper_id);
    $delDocs->execute();

    $delFlow = $conn->prepare("DELETE FROM approval_workflow WHERE paper_id = ?");
    $delFlow->bind_param('i', $paper_id);
    $delFlow->execute();

    // The ownership and status conditions are repeated here, so even a race
    // that submitted the paper a moment ago cannot lose it to this delete.
    $del = $conn->prepare(
        "DELETE FROM research_papers
          WHERE paper_id = ? AND uploaded_by = ? AND current_status = 'draft'"
    );
    $del->bind_param('ii', $paper_id, $u['user_id']);
    $del->execute();

    if ($del->affected_rows !== 1) {
        throw new Exception('Item was not removed.');
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    flash('error', 'That item could not be deleted. Please try again.');
    header('Location: ' . $back);
    exit;
}

/**
 * Only once the row is gone do the files go, and only files that sit inside
 * this student's own upload folders. A stored path is data, and data that is
 * used to build a filesystem path is checked before it is trusted.
 */
$base = realpath(__DIR__ . '/uploads');
foreach (array_merge([$draft['file_path']], $docPaths) as $stored) {
    if (empty($stored)) continue;

    // Paths are recorded either as a site URL or relative to this directory.
    $relative = $stored;
    $marker = '/app/student/';
    $at = strpos($relative, $marker);
    if ($at !== false) $relative = substr($relative, $at + strlen($marker));
    $relative = ltrim($relative, '/');

    $candidate = realpath(__DIR__ . '/' . $relative);
    if ($candidate === false || $base === false) continue;
    // Must resolve inside uploads/ — this is what stops "../" walking out.
    if (strpos($candidate, $base . DIRECTORY_SEPARATOR) !== 0) continue;
    if (is_file($candidate)) @unlink($candidate);
}

flash('success', 'Deleted: ' . $draft['title']);
header('Location: ' . $back);
exit;
