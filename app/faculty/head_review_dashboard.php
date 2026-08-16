<?php
/**
 * Head of Academic Programs — oversight desk.
 *
 * Read-only by design. The approval chain ends at the Research Coordinator
 * (Research Adviser -> Research Coordinator -> Approved); this desk exists so
 * the Head can see what has been published, across every program. There is no
 * approve or return control here, and adding one would put a fourth step back
 * into a workflow that was deliberately shortened. Papers still moving through
 * review are not shown either — they belong to the desks deciding on them.
 */
require_once '../../config/core.php';
/* Two kinds of account do this job: the head_academic role, and an admin
   recorded at level 2. Both land here — a level-1 admin is the Research
   Coordinator and belongs on their own desk, so they are sent back to it. */
require_role(['head_academic', 'admin']);
if (current_user()['user_role'] === 'admin' && (int)(current_user()['admin_level'] ?? 1) !== 2) {
    header('Location: ' . BASE_URL . '/app/admin/admin_review_dashboard.php');
    exit;
}
require_once '../../config/workflow.php';
require_once '../../config/gdrive_config.php';
require_once '../../app/models/AnalyticsService.php';

$conn = db();
$u    = current_user();
$SELF = 'head_review_dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'export_analytics') {
        $exp_s = $conn->query(
            "SELECT u.program, COUNT(rp.paper_id) AS total_papers,
                    SUM(CASE WHEN rp.current_status = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN rp.current_status = 'draft' THEN 1 ELSE 0 END) AS revisions
             FROM research_papers rp JOIN users u ON rp.uploaded_by = u.user_id
             WHERE u.user_role = 'student' AND u.program IS NOT NULL GROUP BY u.program");
        $exp_stats = $exp_s ? $exp_s->fetch_all(MYSQLI_ASSOC) : [];
        $exp_p = $conn->query(
            "SELECT paper_type, COUNT(*) AS count FROM research_papers GROUP BY paper_type ORDER BY count DESC");
        $exp_pt = $exp_p ? $exp_p->fetch_all(MYSQLI_ASSOC) : [];
        (new AnalyticsService())->exportAnalytics($exp_stats, $exp_pt, 'hap_analytics');
        exit;
    }
    header("Location: $SELF");
    exit;
}

$pc = $conn->query("SELECT COUNT(DISTINCT program) AS n FROM users WHERE program IS NOT NULL AND program <> ''");
$program_count = (int)($pc->fetch_assoc()['n'] ?? 0);

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
    'title' => 'My Dashboard',
    'role'  => 'Head of Academic Programs',
    'blurb' => 'Every paper the Research Coordinator has published, across all '
               . ($program_count ?: '') . ' programs. This desk reads — approving is the Coordinator\'s step.',
    'scope' => ['sql' => '1', 'params' => [], 'types' => ''],
    'tabs'  => [
        'published' => ['label' => 'Published', 'icon' => 'verified',
                        'where' => "rp.current_status = 'approved'"],
    ],
    'review'  => null,        // read-only: no approve, no return
    'primary' => ['href' => BASE_URL.'/analytics/analytics_dashboard.php', 'icon' => 'insights', 'label' => 'Analytics'],
    'quick' => [
        ['href' => BASE_URL.'/analytics/analytics_dashboard.php', 'icon' => 'insights', 'label' => 'Analytics',
         'desc' => 'Output by program, paper type and month'],
        ['href' => BASE_URL.'/archive/index.php?browse=1', 'icon' => 'menu_book', 'label' => 'Public Repository',
         'desc' => 'What readers see'],
        ['href' => BASE_URL.'/notifications/notification_center.php', 'icon' => 'notifications', 'label' => 'Notifications',
         'desc' => 'Every alert sent to you'],
    ],
    'cards' => [
        ['id' => 'exportCard', 'title' => 'Export', 'html' => $export_card],
    ],
    'empty' => ['icon' => 'inbox', 'text' => 'No papers match this view yet.'],
];

require ROOT_PATH.'/includes/review_console.php';
