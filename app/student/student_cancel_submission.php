<?php
/**
 * Withdraw a submitted paper, returning it to the author as a draft.
 *
 * Allowed only inside the window described by submission_cancel_state(): while
 * the paper is still sitting with a reviewer who has not acted, and within 24
 * hours of it reaching them. The window is re-checked here rather than trusted
 * from the page, because the button may have been rendered hours ago.
 *
 * Approvals already given are left in place. Only the pending row — the one
 * that says "someone is waiting to look at this" — is removed, since after a
 * withdrawal nobody is.
 */
require_once '../../config/core.php';
require_role(['student']);
csrf_verify();

$conn = db();
$u = current_user();
$paper_id = (int)($_POST['paper_id'] ?? 0);

$tab = $_POST['tab'] ?? 'process';
if (!in_array($tab, ['approved', 'process', 'declined', 'drafts'], true)) $tab = 'process';
$back = 'student_dashboard.php?tab=' . $tab;

if ($paper_id <= 0) { header('Location: ' . $back); exit; }

// The paper must be this student's, and must still be under review.
$find = $conn->prepare(
    "SELECT paper_id, title, current_status, upload_date
       FROM research_papers
      WHERE paper_id = ? AND uploaded_by = ?
      LIMIT 1"
);
$find->bind_param('ii', $paper_id, $u['user_id']);
$find->execute();
$paper = $find->get_result()->fetch_assoc();

if (!$paper) {
    flash('error', 'That paper could not be found.');
    header('Location: ' . $back);
    exit;
}

$state = submission_cancel_state($paper, $conn);
if (!$state['allowed']) {
    flash('error', $state['reason'] !== ''
        ? $state['reason']
        : 'This submission can no longer be withdrawn.');
    header('Location: ' . $back);
    exit;
}

// Tell them before the rows change, while the reviewer list is still intact.
$audience = submission_cancel_audience($paper_id, (int)$u['user_id'], $conn);

$conn->begin_transaction();
try {
    // Nobody is waiting on it any more.
    $clear = $conn->prepare("DELETE FROM approval_workflow WHERE paper_id = ? AND status = 'pending'");
    $clear->bind_param('i', $paper_id);
    $clear->execute();

    // Back to the author as a draft. The status is repeated in the WHERE clause
    // so a reviewer approving at this exact moment wins rather than being undone.
    $revert = $conn->prepare(
        "UPDATE research_papers
            SET current_status = 'draft'
          WHERE paper_id = ? AND uploaded_by = ?
            AND current_status IN ('pending_faculty', 'pending_admin', 'pending_admin_l1')"
    );
    $revert->bind_param('ii', $paper_id, $u['user_id']);
    $revert->execute();

    if ($revert->affected_rows !== 1) {
        throw new Exception('The paper moved on before it could be withdrawn.');
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    flash('error', 'This submission could not be withdrawn — a reviewer may have just acted on it.');
    header('Location: ' . $back);
    exit;
}

/**
 * Notify everyone who was holding it or had already passed it on. Sent after
 * the commit: a notification about something that did not happen is worse than
 * a missing one.
 */
$who = $u['full_name'] ?? 'A student';
$message = $who . ' withdrew their submission "' . $paper['title'] . '" and returned it to draft.';
foreach ($audience as $reviewerId) {
    create_notification($reviewerId, $paper_id, 'submission', $message);
}

flash('success', 'Submission withdrawn. "' . $paper['title'] . '" is back in your drafts.');
header('Location: student_dashboard.php?tab=drafts');
exit;
