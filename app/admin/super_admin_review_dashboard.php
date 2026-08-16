<?php
/**
 * Director — oversight and records desk.
 *
 * The Director takes no part in approving. The chain ends at the Research
 * Coordinator (Research Adviser -> Research Coordinator -> Approved), so this
 * desk reads what came out of it. What is the Director's alone stays here:
 * archiving a paper out of public view, the Drive folder every upload lands in,
 * and the administrator accounts.
 */
require_once '../../config/core.php';
require_role(['super_admin']);
require_once '../../config/workflow.php';
require_once '../../config/gdrive_config.php';
require_once '../../archive/archive_handler.php';

$conn = db();
$u    = current_user();
$SELF = 'super_admin_review_dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $paper_id = (int)($_POST['paper_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if ($action === 'archive' && $paper_id > 0) {
        /* Archiving is a records decision about what stays publicly visible,
           not a step in the review — which is why it is the one paper action
           left on this desk. */
        if (archive_paper($paper_id, $u['user_id'])) {
            flash('success', 'Paper archived. It is no longer in the public repository.');
        } else {
            flash('error', 'That paper could not be archived.');
        }

    } elseif ($action === 'export_analytics') {
        $analytics = $conn->query(
            "SELECT u.program, COUNT(rp.paper_id) AS total_papers
             FROM research_papers rp JOIN users u ON rp.uploaded_by = u.user_id
             WHERE rp.current_status = 'approved' AND u.program IS NOT NULL
             GROUP BY u.program");
        $ptStats = $conn->query(
            "SELECT paper_type, COUNT(*) AS count FROM research_papers
             WHERE current_status = 'approved' GROUP BY paper_type ORDER BY count DESC");

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=director_analytics_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['PROGRAM PERFORMANCE (APPROVED PAPERS)']);
        fputcsv($out, ['Program', 'Approved Papers Count']);
        while ($row = $analytics->fetch_assoc()) fputcsv($out, [$row['program'], $row['total_papers']]);
        fputcsv($out, []);
        fputcsv($out, ['PAPER TYPE DISTRIBUTION']);
        fputcsv($out, ['Type', 'Count']);
        while ($row = $ptStats->fetch_assoc()) fputcsv($out, [$row['paper_type'], $row['count']]);
        fclose($out);
        exit;
    }

    header("Location: $SELF");
    exit;
}

$ac = $conn->query("SELECT COUNT(*) AS n FROM users WHERE user_role IN ('admin','super_admin','head_academic','librarian')");
$admin_count = (int)($ac->fetch_assoc()['n'] ?? 0);

$arc = $conn->query("SELECT COUNT(*) AS n FROM papers_archive");
$archived_count = $arc ? (int)($arc->fetch_assoc()['n'] ?? 0) : 0;

/* ---- The Director's own cards ------------------------------------------ */
ob_start(); ?>
<form method="post" action="<?= e($SELF) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="export_analytics">
    <button type="submit" class="sidebar-link"
            title="Approved papers by program and paper type, as a spreadsheet.">Export CSV</button>
</form>
<?php $export_card = ob_get_clean();

$RC = [
    'self'  => $SELF,
    'title' => 'My Dashboard',
    'role'  => 'Director',
    'blurb' => 'Every paper in the public repository. Approving belongs to the Research Coordinator; '
             . 'what is yours is the record — archiving a paper, the storage folder and the administrator accounts.',
    'scope' => ['sql' => '1', 'params' => [], 'types' => ''],
    'tabs'  => [
        'published' => ['label' => 'Published', 'icon' => 'verified',
                        'where' => "rp.current_status = 'approved'"],
    ],
    'review'  => null,        // read-only: approving is not this desk's step
    'primary' => ['href' => 'super_admin_manage_admins.php', 'icon' => 'admin_panel_settings', 'label' => 'Manage Admins'],
    'quick' => [
        ['href' => 'super_admin_manage_admins.php', 'icon' => 'admin_panel_settings', 'label' => 'Manage Admins',
         'desc' => $admin_count . ' staff ' . ($admin_count === 1 ? 'account' : 'accounts') . ' — add, edit or reset one'],
        ['href' => BASE_URL.'/analytics/analytics_dashboard.php', 'icon' => 'insights', 'label' => 'Analytics',
         'desc' => 'Submissions, approval rates and trends'],
        ['href' => BASE_URL.'/archive/index.php?browse=1', 'icon' => 'menu_book', 'label' => 'Public Repository',
         'desc' => $archived_count ? $archived_count . ' paper(s) archived out of it' : 'What readers see'],
        ['href' => 'gdrive_settings.php', 'icon' => 'folder', 'label' => 'Storage Folder',
         'desc' => 'Where uploaded papers are kept in Google Drive'],
        ['href' => BASE_URL.'/notifications/notification_center.php', 'icon' => 'notifications', 'label' => 'Notifications',
         'desc' => 'Every alert sent to you'],
    ],
    'cards' => [
        ['id' => 'exportCard', 'title' => 'Export', 'html' => $export_card],
    ],
    // Archiving applies to what is published, so the control only appears there.
    'card_extra' => function (array $r) use ($SELF): string {
        if ($r['current_status'] !== 'approved') return '';
        ob_start(); ?>
        <form method="post" action="<?= e($SELF) ?>" class="js-confirm-form rc-spacer"
              data-title="Archive this paper?"
              data-icon="inventory_2"
              data-body="It comes out of the public repository and is kept in the archive record. You can find it there afterwards."
              data-ok="Archive it"
              data-cancel="Leave it published">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="paper_id" value="<?= (int)$r['paper_id'] ?>">
            <button type="submit" class="btn-sm-outline">
                <span class="material-symbols-outlined mi-18">inventory_2</span> Archive
            </button>
        </form>
        <?php return ob_get_clean();
    },
    'empty' => ['icon' => 'inbox', 'text' => 'No papers match this view yet.'],
];

require ROOT_PATH.'/includes/review_console.php';
