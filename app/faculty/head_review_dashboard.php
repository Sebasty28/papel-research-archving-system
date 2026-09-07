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

$conn = db();
$u    = current_user();
$SELF = 'head_review_dashboard.php';

$pc = $conn->query("SELECT COUNT(DISTINCT program) AS n FROM users WHERE program IS NOT NULL AND program <> ''");
$program_count = (int)($pc->fetch_assoc()['n'] ?? 0);

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
        ['href' => BASE_URL.'/app/student/student_upload_ai.php', 'icon' => 'upload_file', 'label' => 'Upload Paper',
         'desc' => 'Add a paper of your own. It is published straight away, with no review'],
        ['href' => BASE_URL.'/analytics/analytics_dashboard.php', 'icon' => 'insights', 'label' => 'Analytics',
         'desc' => 'Output by program, paper type and month'],
        ['href' => BASE_URL.'/archive/index.php?browse=1', 'icon' => 'menu_book', 'label' => 'Public Repository',
         'desc' => 'What readers see'],
        ['href' => BASE_URL.'/notifications/notification_center.php', 'icon' => 'notifications', 'label' => 'Notifications',
         'desc' => 'Every alert sent to you'],
    ],
    'empty' => ['icon' => 'inbox', 'text' => 'No papers match this view yet.'],
];

require ROOT_PATH.'/includes/review_console.php';
