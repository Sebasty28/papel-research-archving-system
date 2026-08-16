<?php
/**
 * Research Adviser's review desk.
 *
 * First stop in the workflow: the adviser reads what their own students submit
 * and either forwards it to the Research Coordinator or sends it back with
 * feedback. The page itself is the shared review console — only the scope, the
 * tabs and this role's own tools are described here.
 */
require_once '../../config/core.php';
require_once '../../includes/validation.php';
require_role(['faculty']);
require_once '../../config/gdrive_config.php';
require_once '../../app/models/AnalyticsService.php';

$conn = db();
$u    = current_user();
$SELF = 'faculty_review_dashboard.php';

/* ---- Decisions --------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $paper_id = (int)($_POST['paper_id'] ?? 0);
    $action   = $_POST['action'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    if ($action === 'export_analytics') {
        $exp_s = $conn->prepare(
            "SELECT u.program, COUNT(rp.paper_id) AS total_papers,
                    SUM(CASE WHEN rp.current_status IN ('approved','pending_admin') THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN rp.current_status='draft' THEN 1 ELSE 0 END) AS revisions
             FROM research_papers rp JOIN users u ON rp.uploaded_by = u.user_id
             WHERE u.user_role='student' AND u.program IS NOT NULL AND u.created_by = ?
             GROUP BY u.program");
        $exp_s->bind_param('i', $u['user_id']);
        $exp_s->execute();
        $exp_stats = $exp_s->get_result()->fetch_all(MYSQLI_ASSOC);

        $exp_p = $conn->prepare(
            "SELECT rp.paper_type, COUNT(*) AS count
             FROM research_papers rp JOIN users u ON u.user_id = rp.uploaded_by
             WHERE u.created_by = ? GROUP BY rp.paper_type ORDER BY count DESC");
        $exp_p->bind_param('i', $u['user_id']);
        $exp_p->execute();
        $exp_pt = $exp_p->get_result()->fetch_all(MYSQLI_ASSOC);

        (new AnalyticsService())->exportAnalytics($exp_stats, $exp_pt, 'faculty_analytics');
        exit;
    }

    if ($paper_id <= 0) { header("Location: $SELF"); exit; }

    /* A paper only leaves this desk if it arrived at it: the adviser's own
       student, still waiting on the adviser. Without this check a crafted post
       could push someone else's paper through. */
    $own = $conn->prepare(
        "SELECT 1 FROM research_papers rp JOIN users u ON u.user_id = rp.uploaded_by
         WHERE rp.paper_id = ? AND u.created_by = ? AND rp.current_status = 'pending_faculty'");
    $own->bind_param('ii', $paper_id, $u['user_id']);
    $own->execute();
    if (!$own->get_result()->fetch_row()) {
        flash('error', 'That paper is not waiting for your review.');
        header("Location: $SELF"); exit;
    }
    $own->close();

    if ($action === 'decline') {
        if ($feedback === '') {
            flash('error', 'Feedback is required when returning a paper.');
            header("Location: $SELF"); exit;
        }
        $validation = validate_feedback($feedback);
        if (!$validation['valid']) {
            flash('error', $validation['error']);
            header("Location: $SELF"); exit;
        }
    }

    if ($action === 'approve') {
        // What the adviser confirmed was present, kept with the paper.
        $flags = [];
        foreach (['imrad_intro','imrad_method','imrad_result','imrad_discussion','imrad_references',
                  'full_ch1','full_ch2','full_ch3','full_ch4','full_ch5','full_references'] as $f) {
            $flags[] = isset($_POST[$f]) ? 1 : 0;
        }
        $stmt = $conn->prepare(
            "INSERT INTO paper_checklist
                (paper_id, imrad_intro, imrad_method, imrad_result, imrad_discussion, imrad_references,
                 full_ch1, full_ch2, full_ch3, full_ch4, full_ch5, full_references)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiiiiiiiiiii', $paper_id, ...$flags);
        $stmt->execute();
        $stmt->close();

        add_workflow($paper_id, $u['user_id'], 'faculty', 'approved', $feedback);
        set_status($paper_id, 'pending_admin');

        $admin_id = creator_of($u['user_id']);
        if ($admin_id) {
            $student_id   = paper_owner($paper_id);
            $student_name = 'a student';
            if ($student_id && ($s_user = get_user($student_id))) $student_name = $s_user['full_name'];
            create_notification($admin_id, $paper_id, 'submission',
                "Research Adviser approved {$student_name}'s paper. Pending your review.");
        }
        flash('success', 'Paper forwarded to the Research Coordinator.');

    } elseif ($action === 'decline') {
        add_workflow($paper_id, $u['user_id'], 'faculty', 'declined', $feedback);
        set_status($paper_id, 'draft');
        // Free the storage — a returned paper is re-uploaded, not reused.
        purge_paper_drive_files($paper_id);
        if ($student_id = paper_owner($paper_id)) {
            create_notification($student_id, $paper_id, 'decline',
                'Your Research Adviser returned your paper: ' . $feedback);
        }
        flash('success', 'Paper returned to the student with your feedback.');
    }

    header("Location: $SELF");
    exit;
}

/* ---- The desk ---------------------------------------------------------- */
$declined_exists = "EXISTS (SELECT 1 FROM approval_workflow aw WHERE aw.paper_id = rp.paper_id AND aw.status = 'declined')";

// How many students this adviser has, for the sidebar.
$sc = $conn->prepare("SELECT COUNT(*) AS n FROM users WHERE created_by = ? AND user_role = 'student'");
$sc->bind_param('i', $u['user_id']);
$sc->execute();
$student_count = (int)($sc->get_result()->fetch_assoc()['n'] ?? 0);
$sc->close();

ob_start(); ?>
<form method="post" action="<?= e($SELF) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="export_analytics">
    <button type="submit" class="sidebar-link"
            title="A spreadsheet of your students' submissions by program and paper type.">Export CSV</button>
</form>
<?php $export_card = ob_get_clean();

$RC = [
    'self'  => $SELF,
    'title' => 'Review Desk',
    'role'  => 'Research Adviser',
    'blurb' => 'Papers from the students you advise. Approving one sends it to the Research Coordinator; returning it puts it back in the student\'s drafts with your feedback.',
    'scope' => ['sql' => 'u.created_by = ?', 'params' => [$u['user_id']], 'types' => 'i'],
    'tabs'  => [
        'queue'     => ['label' => 'Waiting for you', 'icon' => 'rate_review', 'act' => true,
                        'where' => "rp.current_status = 'pending_faculty'",
                        'empty' => 'Nothing is waiting for your review. Submissions from your students land here.',
                        'empty_icon' => 'task_alt'],
        'forwarded' => ['label' => 'With Coordinator', 'icon' => 'forward',
                        'where' => "rp.current_status IN ('pending_admin','pending_admin_l1')",
                        'empty' => 'Nothing is with the Research Coordinator at the moment.'],
        'published' => ['label' => 'Published',        'icon' => 'verified',
                        'where' => "rp.current_status = 'approved'",
                        'empty' => 'None of your students\' papers have been published yet.'],
        'returned'  => ['label' => 'Returned',         'icon' => 'undo',
                        'where' => "rp.current_status = 'draft' AND $declined_exists",
                        'empty' => 'You have not sent any paper back for revision.'],
    ],
    'review'       => 'faculty',
    'checklist'    => true,
    'approve_label'=> 'Approve and forward',
    'approve_lead' => 'This forwards the paper to the Research Coordinator, who makes the final decision.',
    'primary'      => ['href' => 'faculty_manage_students.php', 'icon' => 'group', 'label' => 'My Students'],
    'quick' => [
        ['href' => 'faculty_manage_students.php', 'icon' => 'group', 'label' => 'My Students',
         'desc' => $student_count . ' student ' . ($student_count === 1 ? 'account' : 'accounts') . ' — add, edit or reset one'],
        ['href' => BASE_URL.'/analytics/analytics_dashboard.php', 'icon' => 'insights', 'label' => 'Analytics',
         'desc' => 'Submissions by program, paper type and month'],
        ['href' => BASE_URL.'/archive/index.php?browse=1', 'icon' => 'menu_book', 'label' => 'Public Repository',
         'desc' => 'Everything published so far'],
        ['href' => BASE_URL.'/notifications/notification_center.php', 'icon' => 'notifications', 'label' => 'Notifications',
         'desc' => 'Every alert sent to you'],
    ],
    'cards' => [
        ['id' => 'exportCard', 'title' => 'Export', 'html' => $export_card],
    ],
    'empty' => ['icon' => 'inbox', 'text' => 'Nothing here right now.'],
];

require ROOT_PATH.'/includes/review_console.php';
