<?php
/**
 * Research Coordinator's review desk.
 *
 * The final gate. A paper the Research Adviser has forwarded is published to
 * the public repository here, or returned to the student. Nobody approves after
 * this — the Head of Academic Programs and the Director read what comes out of
 * it. The page is the shared review console; this file describes the desk.
 */
require_once '../../config/core.php';
require_role(['admin']);
require_once '../../app/models/PaperRepository.php';
require_once '../../app/models/PaperService.php';
require_once '../../app/models/AnalyticsService.php';

$conn = db();
$u    = current_user();

/* The session can be stale about which kind of admin this is, and Records
   Officers have their own dashboard. Read the level from the database. */
$al = $conn->prepare("SELECT admin_level FROM users WHERE user_id = ?");
$al->bind_param('i', $u['user_id']);
$al->execute();
if ($row = $al->get_result()->fetch_assoc()) $u['admin_level'] = (int)$row['admin_level'];
$al->close();
if (($u['admin_level'] ?? 1) == 2) { header('Location: '.BASE_URL.'/app/faculty/head_review_dashboard.php'); exit; }

$SELF = 'admin_review_dashboard.php';

/* ---- Decisions --------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $paper_id = (int)($_POST['paper_id'] ?? 0);
    $action   = $_POST['action'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    $paperRepo    = new PaperRepository($conn);
    $paperService = new PaperService();

    if ($action === 'export_analytics') {
        (new AnalyticsService())->exportAnalytics(
            $paperRepo->getProgramAnalytics(), $paperRepo->getPaperTypeStats(), 'analytics_report');
        exit;
    }

    if ($paper_id <= 0) { header("Location: $SELF"); exit; }

    /* Only a paper the adviser has already forwarded is this desk's to decide. */
    $ready = $conn->prepare(
        "SELECT 1 FROM research_papers WHERE paper_id = ? AND current_status IN ('pending_admin','pending_admin_l1')");
    $ready->bind_param('i', $paper_id);
    $ready->execute();
    if (!$ready->get_result()->fetch_row()) {
        flash('error', 'That paper is not waiting for your review.');
        header("Location: $SELF"); exit;
    }
    $ready->close();

    try {
        if ($action === 'decline') {
            if ($feedback === '') throw new Exception('Feedback required to decline.');
            $paperService->declinePaper($paper_id, $u['user_id'], 'admin', $feedback,
                'The Research Coordinator returned your paper: ' . $feedback);
            flash('success', 'Paper returned to the student with your feedback.');
        } elseif ($action === 'approve') {
            // Publishing happens here and only here.
            $paperService->approvePaper($paper_id, $u['user_id'], 'admin', 'approved',
                'Your paper has been approved by the Research Coordinator and is now in the public repository.');
            flash('success', 'Paper approved and published. It is now in the Published tab.');
        }
    } catch (Exception $ex) {
        if ($ex->getMessage() === 'Feedback required to decline.') {
            flash('error', 'Feedback is required when returning a paper.');
        } else {
            error_log('Coordinator review error: ' . $ex->getMessage());
            flash('error', 'Something went wrong. Please try again.');
        }
    }

    header("Location: $SELF");
    exit;
}

/* ---- The desk ---------------------------------------------------------- */
$declined_exists = "EXISTS (SELECT 1 FROM approval_workflow aw WHERE aw.paper_id = rp.paper_id AND aw.status = 'declined')";

$fc = $conn->query("SELECT COUNT(*) AS n FROM users WHERE user_role = 'faculty'");
$faculty_count = (int)($fc->fetch_assoc()['n'] ?? 0);

ob_start(); ?>
<form method="post" action="<?= e($SELF) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="export_analytics">
    <button type="submit" class="sidebar-link"
            title="Every submission by program and paper type, as a spreadsheet.">Export CSV</button>
</form>
<?php $export_card = ob_get_clean();

$RC = [
    'self'  => $SELF,
    'title' => 'Review Desk',
    'role'  => 'Research Coordinator',
    'blurb' => 'The final review. Approving a paper publishes it to the public repository; returning it puts it back with the student, with your feedback.',
    'scope' => ['sql' => '1', 'params' => [], 'types' => ''],
    'tabs'  => [
        'queue'     => ['label' => 'Waiting for you', 'icon' => 'gavel', 'act' => true,
                        'where' => "rp.current_status IN ('pending_admin','pending_admin_l1')",
                        'empty' => 'Nothing is waiting for you. Papers arrive here once a Research Adviser approves them.',
                        'empty_icon' => 'task_alt'],
        'adviser'   => ['label' => 'With Advisers',   'icon' => 'schedule',
                        'where' => "rp.current_status = 'pending_faculty'",
                        'empty' => 'No paper is sitting with a Research Adviser right now.'],
        'published' => ['label' => 'Published',       'icon' => 'verified',
                        'where' => "rp.current_status = 'approved'",
                        'empty' => 'Nothing has been published yet.'],
        'returned'  => ['label' => 'Returned',        'icon' => 'undo',
                        'where' => "rp.current_status = 'draft' AND $declined_exists",
                        'empty' => 'No paper has been sent back for revision.'],
    ],
    'review'        => 'admin',
    'checklist'     => false,
    'approve_label' => 'Approve and publish',
    'approve_lead'  => 'This publishes the paper to the public repository, where anyone can read it. It is the last step.',
    'primary'       => ['href' => 'admin_manage_faculty.php', 'icon' => 'diversity_3', 'label' => 'Manage Faculty'],
    'quick' => [
        ['href' => 'admin_manage_faculty.php', 'icon' => 'diversity_3', 'label' => 'Manage Faculty',
         'desc' => $faculty_count . ' adviser ' . ($faculty_count === 1 ? 'account' : 'accounts') . ' — add, edit or reset one'],
        ['href' => BASE_URL.'/analytics/analytics_dashboard.php', 'icon' => 'insights', 'label' => 'Analytics',
         'desc' => 'Submissions, approval rates and trends'],
        ['href' => BASE_URL.'/archive/index.php?browse=1', 'icon' => 'menu_book', 'label' => 'Public Repository',
         'desc' => 'What readers see once you approve'],
        ['href' => BASE_URL.'/notifications/notification_center.php', 'icon' => 'notifications', 'label' => 'Notifications',
         'desc' => 'Every alert sent to you'],
    ],
    'cards' => [
        ['id' => 'exportCard', 'title' => 'Export', 'html' => $export_card],
    ],
    'empty' => ['icon' => 'inbox', 'text' => 'Nothing here right now.'],
];

require ROOT_PATH.'/includes/review_console.php';
