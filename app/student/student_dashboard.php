<?php
require_once '../../config/core.php';
require_role(['student']);
require_once '../../config/workflow.php';
$conn = db();
$u = current_user();

// ---- Search suggestions (typeahead over the student's own papers) ------
if (isset($_GET['ajax_search'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    try {
        $term = "%$q%";
        $out  = [];
        $s = $conn->prepare("SELECT DISTINCT title FROM research_papers WHERE uploaded_by = ? AND title LIKE ? LIMIT 6");
        $s->bind_param('is', $u['user_id'], $term);
        $s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $out[] = $r['title'];
        $s->close();

        if (count($out) < 8) {
            $k = $conn->prepare("SELECT keywords FROM research_papers WHERE uploaded_by = ? AND keywords LIKE ? LIMIT 10");
            $k->bind_param('is', $u['user_id'], $term);
            $k->execute();
            foreach ($k->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                if (empty($r['keywords'])) continue;
                foreach (explode(',', $r['keywords']) as $kw) {
                    $kw = trim($kw);
                    if ($kw !== '' && stripos($kw, $q) !== false && !in_array($kw, $out, true) && count($out) < 8) {
                        $out[] = $kw;
                    }
                }
            }
            $k->close();
        }
        echo json_encode(array_values(array_unique($out)));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ---- View state -------------------------------------------------------
$tabs = [
    'approved' => 'Approved',
    'process'  => 'Under Review',
    'declined' => 'Needs Revision',
    'drafts'   => 'Drafts',
];
$tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'approved';

$search       = trim($_GET['q'] ?? '');
$filter_type  = trim($_GET['type'] ?? '');
$filter_year  = (int)($_GET['year'] ?? 0);
$filter_month = (int)($_GET['month'] ?? 0);
$filter_day   = (int)($_GET['day'] ?? 0);
// Whitelisted so it is safe to interpolate into ORDER BY
$sort_dir   = (($_GET['sort'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$sort_param = $sort_dir === 'ASC' ? 'asc' : 'desc';

$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ---- WHERE clause -----------------------------------------------------
$declined_exists = "EXISTS (SELECT 1 FROM approval_workflow aw WHERE aw.paper_id = rp.paper_id AND aw.status = 'declined')";
$process_list    = "'" . implode("','", workflow_process_statuses()) . "'";

$where  = "rp.uploaded_by = ?";
$params = [$u['user_id']];
$types  = 'i';

switch ($tab) {
    case 'approved': $where .= " AND rp.current_status = 'approved'"; break;
    case 'process':  $where .= " AND rp.current_status IN ($process_list)"; break;
    case 'declined': $where .= " AND rp.current_status = 'draft' AND $declined_exists"; break;
    case 'drafts':   $where .= " AND rp.current_status = 'draft' AND NOT $declined_exists"; break;
}
if ($search) {
    $where .= " AND (rp.title LIKE ? OR rp.keywords LIKE ? OR rp.abstract LIKE ?)";
    $t = "%$search%"; array_push($params, $t, $t, $t); $types .= 'sss';
}
if ($filter_type)  { $where .= " AND rp.paper_type = ?";        $params[] = $filter_type;  $types .= 's'; }
if ($filter_year)  { $where .= " AND COALESCE(YEAR(rp.research_date), rp.year) = ?"; $params[] = $filter_year; $types .= 'i'; }
if ($filter_month >= 1 && $filter_month <= 12) { $where .= " AND MONTH(rp.research_date) = ?"; $params[] = $filter_month; $types .= 'i'; }
if ($filter_day   >= 1 && $filter_day   <= 31) { $where .= " AND DAY(rp.research_date) = ?";   $params[] = $filter_day;   $types .= 'i'; }

// ---- Counts per tab (for the tab labels) ------------------------------
$counts = ['approved' => 0, 'process' => 0, 'declined' => 0, 'drafts' => 0];
$cs = $conn->prepare(
    "SELECT
        SUM(rp.current_status = 'approved') AS approved,
        SUM(rp.current_status IN ($process_list)) AS process,
        SUM(rp.current_status = 'draft' AND $declined_exists) AS declined,
        SUM(rp.current_status = 'draft' AND NOT $declined_exists) AS drafts
     FROM research_papers rp WHERE rp.uploaded_by = ?"
);
$cs->bind_param('i', $u['user_id']);
$cs->execute();
if ($row = $cs->get_result()->fetch_assoc()) {
    foreach ($counts as $k => $_) $counts[$k] = (int)($row[$k] ?? 0);
}
$cs->close();

// ---- Total for the active view ----------------------------------------
$ct = $conn->prepare("SELECT COUNT(*) AS total FROM research_papers rp WHERE $where");
$ct->bind_param($types, ...$params);
$ct->execute();
$total_papers = (int)($ct->get_result()->fetch_assoc()['total'] ?? 0);
$ct->close();
$total_pages = max(1, (int)ceil($total_papers / $per_page));
$start_item  = $offset + 1;
$end_item    = min($offset + $per_page, $total_papers);

// ---- Page of results ---------------------------------------------------
$sql = "SELECT rp.paper_id, rp.title, rp.author_names, rp.year, rp.abstract, rp.keywords,
               rp.upload_date, rp.research_date, rp.paper_type, rp.publication_status, rp.current_status
        FROM research_papers rp
        WHERE $where
        ORDER BY COALESCE(rp.research_date, MAKEDATE(rp.year, 1)) $sort_dir
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types . 'ii', ...array_merge($params, [$per_page, $offset]));
$stmt->execute();
$result = $stmt->get_result();
$papers = $result->fetch_all(MYSQLI_ASSOC);

// ---- Reviewer names + decline feedback for the papers on this page -----
$reviewers = [];   // paper_id => [review_level => full_name]
$feedback  = [];   // paper_id => latest decline feedback
if ($papers) {
    $ids = array_column($papers, 'paper_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $bt  = str_repeat('i', count($ids));

    $rv = $conn->prepare(
        "SELECT aw.paper_id, aw.review_level, us.full_name
         FROM approval_workflow aw
         JOIN users us ON us.user_id = aw.reviewer_id
         WHERE aw.paper_id IN ($in) AND aw.status = 'approved'
         ORDER BY aw.reviewed_at ASC"
    );
    $rv->bind_param($bt, ...$ids);
    $rv->execute();
    foreach ($rv->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $reviewers[$r['paper_id']][$r['review_level']] = $r['full_name'];
    }
    $rv->close();

    $fb = $conn->prepare(
        "SELECT paper_id, feedback FROM approval_workflow
         WHERE paper_id IN ($in) AND status = 'declined' AND feedback IS NOT NULL
         ORDER BY reviewed_at ASC"
    );
    $fb->bind_param($bt, ...$ids);
    $fb->execute();
    foreach ($fb->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $feedback[$r['paper_id']] = $r['feedback'];   // last one wins = most recent
    }
    $fb->close();
}

// The student's Research Adviser is the faculty member who created their
// account — shown even before that adviser has acted on a paper.
$adviser_name = null;
$ad = $conn->prepare("SELECT f.full_name FROM users s JOIN users f ON f.user_id = s.created_by WHERE s.user_id = ?");
$ad->bind_param('i', $u['user_id']);
$ad->execute();
$adviser_name = $ad->get_result()->fetch_assoc()['full_name'] ?? null;
$ad->close();

// Distinct years/types for the sidebar filters
$years_res = $conn->prepare("SELECT DISTINCT COALESCE(YEAR(research_date), year) AS year FROM research_papers WHERE uploaded_by = ? AND (research_date IS NOT NULL OR year IS NOT NULL) ORDER BY year DESC");
$years_res->bind_param('i', $u['user_id']); $years_res->execute();
$years = array_column($years_res->get_result()->fetch_all(MYSQLI_ASSOC), 'year');

$type_labels = [
    'research' => 'Research Paper', 'capstone' => 'Capstone Project', 'thesis' => 'Thesis',
    'conference' => 'Conference Paper', 'journal' => 'Journal Article',
    'article' => 'Article', 'project' => 'Project',
];

// Query string carried across pagination / tab links
function dash_qs(array $over = []) {
    $base = [
        'tab'  => $_GET['tab']  ?? null, 'q'    => $_GET['q']    ?? null,
        'type' => $_GET['type'] ?? null, 'year' => $_GET['year'] ?? null,
        'month'=> $_GET['month']?? null, 'day'  => $_GET['day']  ?? null,
        'sort' => $_GET['sort'] ?? null, 'page' => $_GET['page'] ?? null,
    ];
    return http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
}

$ajax_results = isset($_GET['ajax']) && $_GET['ajax'] === '1';
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/browse_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Delete sits apart from the actions, at the far end of the row, so it is never
   the button next to the one you meant to press. */
.card-actions .draft-delete-form { margin-left: auto; }
.btn-card-delete {
    background: none; border: 1px solid transparent; border-radius: 6px;
    padding: .35rem; color: var(--grey); cursor: pointer;
    display: inline-flex; align-items: center; transition: color .15s, background .15s, border-color .15s;
}
.btn-card-delete:hover { color: var(--maroon); background: #fdeaea; border-color: var(--soft-maroon); }
.btn-card-delete:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
/* Withdraw control — plain for now, styling to follow. */
.cancel-window { font-size: .6875rem; color: var(--grey); align-self: center; }
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- Breadcrumb -->
<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="student_dashboard.php">My Dashboard</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current"><?= e($tabs[$tab]) ?></span>
    </div>
</div>

<main class="wrap layout">
    <div class="main-col" id="mainCol">
        <?php ob_start(); ?>

        <div class="dash-shell">

            <!-- Search + upload -->
            <div class="dash-top">
                <div class="search-shell">
                    <form action="student_dashboard.php" method="get" id="searchForm">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                        <?php if ($filter_type): ?><input type="hidden" name="type" value="<?= e($filter_type) ?>"><?php endif; ?>
                        <?php if ($sort_param): ?><input type="hidden" name="sort" value="<?= e($sort_param) ?>"><?php endif; ?>
                        <div class="search-form">
                            <button type="submit" class="btn-search-icon" aria-label="Search">
                                <span class="material-symbols-outlined">search</span>
                            </button>
                            <input class="search-input" type="search" name="q" id="searchInput"
                                   data-suggest-url="student_dashboard.php?ajax_search=1"
                                   value="<?= e($search) ?>" placeholder="Click To Search" autocomplete="off">
                        </div>
                        <div id="searchSuggestions" class="suggestions-dropdown"></div>
                    </form>
                </div>
                <a href="student_upload_ai.php" class="btn-upload">
                    <span class="material-symbols-outlined">upload</span> Upload Paper
                </a>
            </div>

            <!-- Tabs + toolbar -->
            <div class="dash-bar">
                <div class="dash-tabs">
                    <?php foreach ($tabs as $key => $label): ?>
                        <?php if ($key === 'drafts' && $counts['drafts'] === 0 && $tab !== 'drafts') continue; ?>
                        <a class="dash-tab <?= $tab === $key ? 'active' : '' ?>"
                           href="student_dashboard.php?<?= dash_qs(['tab' => $key, 'page' => null]) ?>">
                            <?= e($label) ?> <span class="count"><?= (int)$counts[$key] ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php /* Refresh clears the search box and every filter, then
                             reloads the list for the tab you are on. */ ?>
                    <a class="toolbar-btn" href="student_dashboard.php?tab=<?= e($tab) ?>" title="Refresh — clears search and filters">
                        <span class="material-symbols-outlined">refresh</span>
                    </a>
                    <a class="toolbar-btn" href="<?= e(BASE_URL) ?>/pages/help_center.php" title="Help">
                        <span class="material-symbols-outlined">help</span>
                    </a>
                    <div class="quick-settings">
                        <button class="toolbar-btn" type="button" id="quickSettingsBtn" title="Quick Settings" aria-haspopup="true" aria-expanded="false">
                            <span class="material-symbols-outlined">settings</span>
                        </button>
                        <div class="quick-settings-dropdown" id="quickSettingsDropdown">
                            <div class="qs-header">
                                <span>Quick Settings</span>
                                <button type="button" class="qs-close" id="quickSettingsClose" aria-label="Close"><span class="material-symbols-outlined mi-18">close</span></button>
                            </div>
                            <div class="qs-section">
                                <a class="qs-link" id="quickSettingsFull" href="<?= e(BASE_URL.'/pages/settings.php') ?>">View Full Settings</a>
                            </div>
                            <div class="qs-section">
                                <span class="qs-section-label">Density</span>
                                <label class="qs-radio"><input type="radio" name="qs_density" value="default"> Default</label>
                                <label class="qs-radio"><input type="radio" name="qs_density" value="comfortable"> Comfortable</label>
                                <label class="qs-radio"><input type="radio" name="qs_density" value="compact"> Compact</label>
                            </div>
                            <div class="qs-section">
                                <span class="qs-section-label">Theme</span>
                                <label class="qs-radio"><input type="radio" name="qs_theme" value="system"> System</label>
                                <label class="qs-radio"><input type="radio" name="qs_theme" value="light"> Light</label>
                                <label class="qs-radio"><input type="radio" name="qs_theme" value="dark"> Dark</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="toolbar-right">
                    <span>Showing items <?= $total_papers ? $start_item : 0 ?>-<?= $end_item ?> of <?= number_format($total_papers) ?></span>
                    <?php if ($page > 1): ?>
                        <a class="toolbar-btn" href="student_dashboard.php?<?= dash_qs(['page' => $page - 1]) ?>" aria-label="Previous page"><span class="material-symbols-outlined">chevron_left</span></a>
                    <?php else: ?>
                        <span class="toolbar-btn disabled"><span class="material-symbols-outlined">chevron_left</span></span>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a class="toolbar-btn" href="student_dashboard.php?<?= dash_qs(['page' => $page + 1]) ?>" aria-label="Next page"><span class="material-symbols-outlined">chevron_right</span></a>
                    <?php else: ?>
                        <span class="toolbar-btn disabled"><span class="material-symbols-outlined">chevron_right</span></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Paper list -->
            <div class="paper-list is-scrollable">
                <?php foreach ($papers as $r): ?>
                    <?php
                    $pid    = (int)$r['paper_id'];
                    $status = (string)($r['current_status'] ?? '');
                    $fb     = $feedback[$pid] ?? null;
                    $steps  = workflow_progress_steps($status, (bool)$fb);
                    $done   = 0;
                    foreach ($steps as $s) if ($s['state'] === 'done') $done++;
                    $fill   = count($steps) > 1 ? ($done > 0 ? (min($done, count($steps) - 1) / (count($steps) - 1)) * 100 : 0) : 0;
                    $rv     = $reviewers[$pid] ?? [];
                    ?>
                    <article class="paper-card">
                        <div class="card-head">
                            <div>
                                <?php
                                /* The title opens the author's own record of the submission —
                                   what they filed, the reviewer's feedback and the checklist.
                                   Not the repository page, which is written for a reader looking
                                   a published paper up.

                                   A returned paper carries the status 'draft' too, but it has a
                                   decline on record and plenty worth reading, so it links like
                                   the rest. Only a draft that has never been submitted stays as
                                   plain text — there is nothing to show yet, and its Continue
                                   editing button is the way in. */
                                $isDraftOnly = ($status === 'draft' && !$fb);
                                ?>
                                <h2 class="paper-title">
                                    <?php if ($isDraftOnly): ?>
                                        <?= e($r['title']) ?>
                                    <?php else: ?>
                                        <a href="paper_details.php?id=<?= $pid ?>"><?= e($r['title']) ?></a>
                                    <?php endif; ?>
                                </h2>
                                <?php if (!empty($r['author_names'])): ?>
                                    <p class="paper-authors"><?= e($r['author_names']) ?></p>
                                <?php endif; ?>
                                <div class="paper-meta">
                                    <span><?= e(paper_date_display($r['research_date'] ?? null, $r['year'] ?? null)) ?></span>
                                    <?php if (!empty($r['paper_type'])): ?>
                                        <span class="sep">•</span>
                                        <span><?= e($type_labels[$r['paper_type']] ?? ucfirst($r['paper_type'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['publication_status'])): ?>
                                        <span class="sep">•</span>
                                        <span><?= e($r['publication_status']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-status">
                                <span>Status: <span class="status-value"><?= e(workflow_status_badge_text($status, (bool)$fb)) ?></span></span>
                                <?php if (!$isDraftOnly): ?>
                                    <a class="paper-action" href="paper_details.php?id=<?= $pid ?>">View Details</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-track">
                            <div class="track">
                                <div class="track-line"><div class="track-line-fill" style="width: <?= (int)round($fill) ?>%"></div></div>
                                <?php foreach ($steps as $s): ?>
                                    <div class="track-step <?= e($s['state']) ?>">
                                        <span class="track-dot">
                                            <?php if ($s['state'] === 'done'): ?>
                                                <span class="material-symbols-outlined">check</span>
                                            <?php elseif ($s['state'] === 'current'): ?>
                                                <span class="material-symbols-outlined">more_horiz</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="track-label"><?= e($s['label']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="card-people">
                                <?php $adviser = $rv['faculty'] ?? $adviser_name; ?>
                                <?php if (!empty($adviser)): ?>
                                    <div>Research Adviser: <span class="who"><?= e($adviser) ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($rv['admin'])): ?>
                                    <div>Research Coordinator: <span class="who"><?= e($rv['admin']) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($fb && $status === 'draft'): ?>
                            <div class="card-feedback">Revision feedback: <?= e($fb) ?></div>
                        <?php endif; ?>

                        <?php
                        /* Withdrawing a submission. With the adviser it is a window
                           that closes; with the coordinator it is one that opens
                           after they have had it a day. Either way the note says
                           which, so the button never appears or vanishes without
                           explanation. Plain styling for now. */
                        $cancel = submission_cancel_state($r, $conn);
                        if ($cancel['allowed'] || $cancel['unlocks']):
                        ?>
                            <div class="card-actions">
                                <?php if ($cancel['allowed']): ?>
                                    <form method="post" action="student_cancel_submission.php" class="cancel-submit-form"
                                          data-title="<?= e($r['title']) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="paper_id" value="<?= $pid ?>">
                                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                        <button type="submit" class="btn-sm-outline">
                                            <span class="material-symbols-outlined mi-18">undo</span>
                                            Cancel submission
                                        </button>
                                    </form>
                                    <span class="cancel-window">
                                        <?php if ($cancel['stage'] === 'faculty'): ?>
                                            Available until <?= e(date('M j, g:i a', $cancel['deadline'])) ?>
                                        <?php else: ?>
                                            The Research Coordinator has had this since
                                            <?= e(date('M j', $cancel['started'])) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="cancel-window"><?= e($cancel['reason']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($status === 'draft'): ?>
                            <?php /* No submit button here. Sending a paper for review is a
                                     one-way step, and a card list is exactly where a stray
                                     click happens — it is done deliberately from the upload
                                     page instead, after the work has been reviewed. */ ?>
                            <?php
                            /* A paper carrying revision feedback was sent back by a
                               reviewer, so the work ahead is correcting and returning
                               it — not the same task as finishing a draft that has
                               never been seen. The label says which. */
                            $editLabel = $fb ? 'Edit and Re-submit' : 'Continue editing';
                            ?>
                            <div class="card-actions">
                                <a class="btn-sm-maroon" href="student_upload_ai.php?draft=<?= $pid ?>">
                                    <span class="material-symbols-outlined mi-18">edit</span>
                                    <?= $editLabel ?>
                                </a>
                                <form method="post" action="student_draft_delete.php" class="draft-delete-form"
                                      data-title="<?= e($r['title']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="paper_id" value="<?= $pid ?>">
                                    <?php /* So deleting from Needs Revision returns there, not to Drafts. */ ?>
                                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                    <button type="submit" class="btn-card-delete" title="Delete this item"
                                            aria-label="Delete &quot;<?= e($r['title']) ?>&quot;">
                                        <span class="material-symbols-outlined mi-18">delete</span>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <?php if (!$papers): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">search_off</span>
                        <p>No papers found matching your criteria.</p>
                        <?php $has_filters = $search || $filter_type || $filter_year || $filter_month || $filter_day; ?>
                        <?php if ($has_filters): ?>
                            <a href="student_dashboard.php?tab=<?= e($tab) ?>">Clear filters</a>
                        <?php else: ?>
                            <a href="student_upload_ai.php">Upload a paper</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Held back until the sidebar has been captured too, so an AJAX
        // response can refresh both columns in one round trip.
        $main_col_html = ob_get_clean();
        echo $main_col_html;
        ?>
    </div>

    <!-- Right sidebar -->
    <aside class="sidebar-right" id="sidebarCol">
        <?php ob_start(); ?>
        <div class="sidebar-card" id="browseCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="browseCard">Browse</button>
                <span class="card-header-tools">
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="browseCard" aria-label="Collapse Browse"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <div class="sidebar-card-body">
                <a href="<?= e(BASE_URL) ?>/archive/index.php?browse=1" class="sidebar-link">Public Repository</a>
                <a href="student_upload_ai.php" class="sidebar-link">Upload Paper</a>
                <a href="student_dashboard.php?tab=drafts" class="sidebar-link <?= $tab === 'drafts' ? 'active' : '' ?>">View Drafts</a>
            </div>
        </div>

        <div class="sidebar-card" id="filterCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="filterCard">Filter</button>
                <span class="card-header-tools">
                    <?php /* A crossed-out funnel, not a plain X — the X sits next to a
                             collapse chevron and reads as "close the card". */ ?>
                    <a class="card-tool" href="student_dashboard.php?tab=<?= e($tab) ?>" title="Clear all filters" aria-label="Clear all filters"><span class="material-symbols-outlined">filter_alt_off</span></a>
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="filterCard" aria-label="Collapse Filter"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <form id="filterForm" action="student_dashboard.php" method="get">
                <?php if ($search): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                <?php /* Tabs without a Status radio (Drafts) would otherwise be
                        dropped from the form and fall back to Approved. Placed
                        before the radios so a radio choice wins on submit. */ ?>
                <?php if (!in_array($tab, ['approved','process','declined'], true)): ?>
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <?php endif; ?>

                <div class="filter-section">
                    <span class="filter-section-label">Paper Type</span>
                    <label class="filter-radio">
                        <input type="radio" name="type" value="" <?= $filter_type === '' ? 'checked' : '' ?>> All Types
                    </label>
                    <?php foreach (['capstone', 'research', 'thesis'] as $tv): ?>
                        <label class="filter-radio">
                            <input type="radio" name="type" value="<?= e($tv) ?>" <?= $filter_type === $tv ? 'checked' : '' ?>>
                            <?= e($type_labels[$tv] ?? ucfirst($tv)) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="filter-section">
                    <span class="filter-section-label">Status</span>
                    <?php foreach (['approved' => 'Approved', 'process' => 'Under Review', 'declined' => 'Needs Revision'] as $k => $label): ?>
                        <label class="filter-radio">
                            <input type="radio" name="tab" value="<?= e($k) ?>" <?= $tab === $k ? 'checked' : '' ?>> <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="filter-section">
                    <span class="filter-section-label">Order By</span>
                    <label class="filter-radio"><input type="radio" name="sort" value="asc"  <?= $sort_param === 'asc'  ? 'checked' : '' ?>> Ascending</label>
                    <label class="filter-radio"><input type="radio" name="sort" value="desc" <?= $sort_param === 'desc' ? 'checked' : '' ?>> Descending</label>
                </div>

                <div class="filter-section">
                    <span class="filter-section-label">Date</span>
                    <div class="date-stack">
                        <select name="year" class="filter-select">
                            <option value="">All Year</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y ?>" <?= $filter_year == $y ? 'selected' : '' ?>><?= (int)$y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="month" class="filter-select">
                            <option value="">All Month</option>
                            <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                                <option value="<?= $mo ?>" <?= $filter_month === $mo ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$mo,1)) ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="day" class="filter-select">
                            <option value="">All Date</option>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= $filter_day === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <?php
        $sidebar_html = ob_get_clean();
        if ($ajax_results) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json');
            echo json_encode([
                'html'    => $main_col_html,
                'sidebar' => $sidebar_html,   // keeps the filter controls in step
                'crumb'   => $tabs[$tab],
                'title'   => 'My Dashboard · ' . APP_NAME,
            ]);
            exit;
        }
        echo $sidebar_html;
        ?>
    </aside>
</main>

<!-- Confirmation for a delete, in the site's own styling rather than the
     browser's. Destructive and irreversible, so it names the draft. -->
<div class="papel-dialog-backdrop" id="draftDeleteDialog" role="dialog" aria-modal="true" aria-labelledby="draftDeleteTitle">
  <div class="papel-dialog">
    <div class="papel-dialog-head">
      <span class="material-symbols-outlined" id="draftDeleteIcon">delete</span>
      <h2 id="draftDeleteTitle">Delete this item?</h2>
    </div>
    <div class="papel-dialog-body" id="draftDeleteBody">
      Deleting <strong id="draftDeleteName">this item</strong> also removes any files
      attached to it. This cannot be undone.
    </div>
    <div class="papel-dialog-foot">
      <button type="button" class="btn-sm-outline" id="draftDeleteCancel">Keep it</button>
      <button type="button" class="btn-sm-maroon" id="draftDeleteOk">Delete item</button>
    </div>
  </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

// ===== AJAX result loading — search, tabs, filters and pagination swap
// just the results column instead of reloading the page. =====
var mainCol = document.getElementById('mainCol');
// searchForm is resolved per-submit via delegation (it is inside #mainCol)
// filterForm is resolved per-change via delegation (the sidebar is swapped too)

var loadingTimer = null;
function setLoading(on) {
    if (!mainCol) return;
    clearTimeout(loadingTimer);
    if (on) loadingTimer = setTimeout(function () { mainCol.classList.add('is-loading'); }, 200);
    else mainCol.classList.remove('is-loading');
}

function loadResults(url, push) {
    if (!mainCol) { window.location.href = url; return; }
    setLoading(true);
    fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + 'ajax=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('failed'); return r.json(); })
        .then(function (data) {
            mainCol.innerHTML = data.html;
            // Re-render the sidebar too so the filter controls always reflect
            // the state the server just applied (e.g. selects back to "All").
            if (data.sidebar) {
                var side = document.getElementById('sidebarCol');
                if (side) side.innerHTML = data.sidebar;
            }
            setLoading(false);
            if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
            if (data.crumb) {
                var crumb = document.querySelector('.crumb-current');
                if (crumb) crumb.textContent = data.crumb;
            }
            if (data.title) document.title = data.title;
            if (push !== false) history.pushState({ papelAjax: true }, '', url);
        })
        .catch(function () { window.location.href = url; });
}

// Tabs, pagination, refresh and the sidebar's clear-filters link all live in
// swapped-out markup, so they are bound by delegation.
document.addEventListener('click', function (e) {
    var link = e.target.closest('#mainCol a[href], #filterCard a[href]');
    if (link) {
        var href = link.getAttribute('href');
        if (href && href.indexOf('student_dashboard.php') === 0) {
            e.preventDefault();
            loadResults(href);
        }
    }
});

// The search form lives inside #mainCol, so an AJAX swap replaces it and any
// directly-bound handler would be left on a detached node — delegate instead.
document.addEventListener('submit', function (e) {
    var form = e.target.closest('#searchForm');
    if (!form) return;
    e.preventDefault();
    loadResults('student_dashboard.php?' + new URLSearchParams(new FormData(form)).toString());
});

// The sidebar is re-rendered on every swap too, so the filter form is bound
// by delegation for the same reason as the search form.
document.addEventListener('change', function (e) {
    var form = e.target.closest('#filterForm');
    if (!form) return;
    if (e.target.matches('input[type="radio"], select')) {
        loadResults('student_dashboard.php?' + new URLSearchParams(new FormData(form)).toString());
    }
});
window.addEventListener('popstate', function () { loadResults(window.location.href, false); });

/* Deleting a draft cannot be undone, so it is confirmed first — in the site's
   own dialog rather than the browser's — and the title is named in the
   question, because "are you sure?" alone does not tell you which card you
   clicked. Delegated, since the list is swapped by AJAX. */
const delDialog      = document.getElementById('draftDeleteDialog');
const delDialogTitle = document.getElementById('draftDeleteTitle');
const delIcon        = document.getElementById('draftDeleteIcon');
const delBody        = document.getElementById('draftDeleteBody');
const delOk          = document.getElementById('draftDeleteOk');
const delCancel      = document.getElementById('draftDeleteCancel');
let pendingForm = null;

function closeDeleteDialog() {
    delDialog.classList.remove('open');
    document.body.style.overflow = '';
    pendingForm = null;
}

document.addEventListener('submit', function (e) {
    const form = e.target.closest('.draft-delete-form, .cancel-submit-form');
    if (!form || form.dataset.confirmed === '1') return;

    e.preventDefault();
    pendingForm = form;

    // Withdrawing is reversible — the paper returns as a draft — so it is
    // worded as a question rather than a warning about losing anything.
    const withdrawing = form.classList.contains('cancel-submit-form');
    delDialogTitle.textContent = withdrawing ? 'Withdraw this submission?' : 'Delete this item?';
    delIcon.textContent = withdrawing ? 'undo' : 'delete';
    delBody.innerHTML = withdrawing
        ? 'This takes <strong id="draftDeleteName"></strong> back out of review and returns it '
          + 'to your drafts. Your reviewers will be told. You can submit it again afterwards.'
        : 'Deleting <strong id="draftDeleteName"></strong> also removes any files attached '
          + 'to it. This cannot be undone.';
    delDialog.querySelector('#draftDeleteName').textContent = form.dataset.title || 'this item';
    delOk.textContent = withdrawing ? 'Withdraw it' : 'Delete item';
    delCancel.textContent = withdrawing ? 'Leave it in review' : 'Keep it';

    delDialog.classList.add('open');
    document.body.style.overflow = 'hidden';
    delCancel.focus();          // the safe option is the one under the cursor
});

delOk.addEventListener('click', function () {
    if (!pendingForm) return;
    const form = pendingForm;
    form.dataset.confirmed = '1';
    closeDeleteDialog();
    form.submit();
});
delCancel.addEventListener('click', closeDeleteDialog);
delDialog.addEventListener('click', function (e) { if (e.target === delDialog) closeDeleteDialog(); });
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && delDialog.classList.contains('open')) closeDeleteDialog();
});

});
</script>
<?php require ROOT_PATH.'/includes/browse_console_js.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
