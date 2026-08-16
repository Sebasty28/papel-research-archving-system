<?php
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../config/groq_config.php';
/* The Head of Academic Programs used to read these same figures inline on their
   own dashboard. That dashboard is now a list, so the numbers live here — the
   page is unscoped, which is the whole-institution view that role oversees. */
require_role(['admin','super_admin','faculty','head_academic']);
$conn = db();
$u = current_user();

/* What counts as a submission.
 *
 * A draft is a student's private workspace — it can sit half-written for weeks
 * and nobody has been asked to look at it. Counting those alongside real
 * submissions made three papers out of one, so analytics only counts work that
 * has actually been handed in.
 *
 * "Handed in" means it is past draft state, or it has at least one review step
 * behind it — the second half matters because a paper returned for revision
 * goes back to draft, and it was still submitted.
 */
const SUBMITTED = "(%1\$s.current_status <> 'draft'
                    OR EXISTS (SELECT 1 FROM approval_workflow w WHERE w.paper_id = %1\$s.paper_id))";
$submitted = function (string $alias = 'research_papers') {
    return sprintf(SUBMITTED, $alias);
};

/* ---------------------------------------------------------------------------
 * Filters
 *
 * Everything below answers the same question narrowed four ways: which year,
 * which program, which kind of paper, what stage it reached. They live in the
 * query string so a particular view can be bookmarked or sent to someone, and
 * so the browser's back button steps through them.
 * ------------------------------------------------------------------------ */
$fYear    = trim($_GET['year']    ?? '');
$fProgram = trim($_GET['program'] ?? '');
$fType    = trim($_GET['type']    ?? '');
$fStatus  = trim($_GET['status']  ?? '');
$fQuery   = trim($_GET['q']       ?? '');

$FROM  = "FROM research_papers rp LEFT JOIN users u ON u.user_id = rp.uploaded_by";
$where = [$submitted('rp')];
$args  = [];
$types = '';

if ($fYear !== '' && ctype_digit($fYear)) {
    $where[] = "YEAR(rp.upload_date) = ?";   $args[] = (int)$fYear;  $types .= 'i';
}
if ($fProgram !== '') { $where[] = "u.program = ?";          $args[] = $fProgram; $types .= 's'; }
if ($fType    !== '') { $where[] = "rp.paper_type = ?";      $args[] = $fType;    $types .= 's'; }
if ($fStatus  !== '') { $where[] = "rp.current_status = ?";  $args[] = $fStatus;  $types .= 's'; }
if ($fQuery   !== '') {
    $where[] = "(rp.title LIKE ? OR rp.author_names LIKE ? OR u.full_name LIKE ?)";
    $like = '%' . $fQuery . '%';
    array_push($args, $like, $like, $like); $types .= 'sss';
}
$WHERE = 'WHERE ' . implode(' AND ', $where);
$filtered = ($fYear !== '' || $fProgram !== '' || $fType !== '' || $fStatus !== '' || $fQuery !== '');

/** Run a query with the active filters bound, plus any extra parameters. */
$run = function (string $sql, array $extra = [], string $extraTypes = '')
       use ($conn, $args, $types) {
    $st = $conn->prepare($sql);
    if (!$st) { return null; }
    $all = array_merge($args, $extra);
    $t   = $types . $extraTypes;
    if ($t !== '') {
        // bind_param wants references, so the values are handed over by reference.
        $refs = [$t];
        foreach ($all as $k => $_) { $refs[] = &$all[$k]; }
        call_user_func_array([$st, 'bind_param'], $refs);
    }
    $st->execute();
    return $st->get_result();
};
$rows = function ($res) { return $res ? $res->fetch_all(MYSQLI_ASSOC) : []; };
$one  = function ($res, $key = 'v', $default = 0) {
    if (!$res) { return $default; }
    $r = $res->fetch_assoc();
    return $r === null || $r[$key] === null ? $default : $r[$key];
};

/* ---- What the filter boxes can offer -------------------------------------
   Only values that exist, so the lists never grow stale or offer a year with
   nothing behind it. Drawn from the whole roll, not the filtered set, so
   changing one filter does not empty the others. */
$all = "FROM research_papers rp LEFT JOIN users u ON u.user_id = rp.uploaded_by WHERE " . $submitted('rp');
$optYears = array_column($rows($conn->query(
    "SELECT DISTINCT YEAR(rp.upload_date) v $all ORDER BY v DESC")), 'v');
$optPrograms = array_column($rows($conn->query(
    "SELECT DISTINCT u.program v $all AND u.program IS NOT NULL AND u.program <> '' ORDER BY v")), 'v');
$optTypes = array_column($rows($conn->query(
    "SELECT DISTINCT rp.paper_type v $all AND rp.paper_type IS NOT NULL AND rp.paper_type <> '' ORDER BY v")), 'v');
$optStatuses = array_column($rows($conn->query(
    "SELECT DISTINCT rp.current_status v $all ORDER BY v")), 'v');

/* ---- Headline figures ---- */
$totalSubs = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE"));
$approved  = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE AND rp.current_status = 'approved'"));
$pending   = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE AND rp.current_status IN
                             ('pending_faculty','pending_admin','pending_admin_l1',
                              'pending_head_academic','pending_super_admin')"));
$returned  = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE AND rp.current_status = 'draft'"));
$avgDays   = $conn->query("SELECT ROUND(AVG(time_to_approval),1) v FROM analytics WHERE time_to_approval IS NOT NULL");
$avgDays   = $avgDays ? ($avgDays->fetch_assoc()['v'] ?? null) : null;
$approvalPct = $totalSubs > 0 ? round(($approved / $totalSubs) * 100) : 0;

// Drafts nobody has been asked to look at yet — counted apart, never inside.
$openDrafts = (int)$one($conn->query(
    "SELECT COUNT(*) v FROM research_papers rp WHERE NOT " . $submitted('rp')));

/* ---- Timeline -------------------------------------------------------------
   The granularity follows the data. With no year chosen the chart is one point
   per year, which stays readable however long the repository has been running;
   choose a year and it becomes one point per month of that year. */
$byMonth = ($fYear !== '' && ctype_digit($fYear));
$timelineData = $rows($run(
    $byMonth
        ? "SELECT DATE_FORMAT(rp.upload_date, '%Y-%m') AS bucket, COUNT(*) AS count
           $FROM $WHERE GROUP BY bucket ORDER BY bucket"
        : "SELECT YEAR(rp.upload_date) AS bucket, COUNT(*) AS count
           $FROM $WHERE GROUP BY bucket ORDER BY bucket"));

/* ---- This month against last ---- */
$thisMonth = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE
    AND MONTH(rp.upload_date) = MONTH(NOW()) AND YEAR(rp.upload_date) = YEAR(NOW())"));
$lastMonth = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE
    AND MONTH(rp.upload_date) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
    AND YEAR(rp.upload_date)  = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))"));
$monthDelta = $thisMonth - $lastMonth;

/* ---- Breakdowns ---- */
$paperTypeStats = $rows($run(
    "SELECT COALESCE(NULLIF(rp.paper_type,''),'Unspecified') AS paper_type, COUNT(*) AS count
     $FROM $WHERE GROUP BY paper_type ORDER BY count DESC"));
$totalPapers = array_sum(array_column($paperTypeStats, 'count'));

$stats = $rows($run(
    "SELECT u.program,
            COUNT(*) AS total_papers,
            SUM(rp.current_status = 'approved') AS approved,
            SUM(rp.current_status = 'draft')    AS revisions
     $FROM $WHERE AND u.program IS NOT NULL AND u.program <> ''
     GROUP BY u.program ORDER BY total_papers DESC"));

$approvalByType = $rows($run(
    "SELECT COALESCE(NULLIF(rp.paper_type,''),'Unspecified') AS paper_type,
            COUNT(*) AS total,
            SUM(rp.current_status = 'approved') AS approved
     $FROM $WHERE GROUP BY paper_type HAVING total > 0 ORDER BY total DESC"));
foreach ($approvalByType as &$_r) {
    $_r['rate'] = $_r['total'] > 0 ? round(($_r['approved'] / $_r['total']) * 100, 1) : 0;
}
unset($_r);

/* ---- Every submission, one row each ---------------------------------------
   The part that matters once there are years of these: sortable, searchable,
   and paged, so the page stays the same size whether it is describing ten
   papers or ten thousand. Sorting is done in the database, not in the browser,
   because sorting one page of results would only reorder that page. */
$SORTS = [
    'date'    => ['rp.upload_date',    'Submitted'],
    'title'   => ['rp.title',          'Title'],
    'student' => ['u.full_name',       'Student'],
    'program' => ['u.program',         'Program'],
    'type'    => ['rp.paper_type',     'Type'],
    'status'  => ['rp.current_status', 'Status'],
];
$sort = array_key_exists($_GET['sort'] ?? '', $SORTS) ? $_GET['sort'] : 'date';
$dir  = strtolower($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';

$perPage = 25;
$listTotal = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE"));
$pages = max(1, (int)ceil($listTotal / $perPage));
$page  = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$papers = $rows($run(
    "SELECT rp.paper_id, rp.title, rp.paper_type, rp.current_status, rp.upload_date,
            rp.author_names, u.full_name AS student, u.program
     $FROM $WHERE
     ORDER BY {$SORTS[$sort][0]} $dir, rp.paper_id DESC
     LIMIT ? OFFSET ?", [$perPage, $offset], 'ii'));

/* Keep the current filters when building any other link on the page. */
$qs = function (array $changes = []) use ($fYear, $fProgram, $fType, $fStatus, $fQuery, $sort, $dir, $page) {
    $base = ['year' => $fYear, 'program' => $fProgram, 'type' => $fType, 'status' => $fStatus,
             'q' => $fQuery, 'sort' => $sort, 'dir' => strtolower($dir), 'page' => $page];
    $merged = array_filter(array_merge($base, $changes), function ($v) { return $v !== '' && $v !== null; });
    return $merged ? '?' . http_build_query($merged) : '?';
};

/** A column heading that sorts, and shows which way it is sorting. */
$sortHead = function (string $key, string $align = '') use ($SORTS, $sort, $dir, $qs) {
    $next = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
    $arrow = $sort !== $key ? '' :
        ($dir === 'ASC' ? '<span class="an-sort-dir">&uarr;</span>' : '<span class="an-sort-dir">&darr;</span>');
    printf('<th class="%s"><a class="an-sort%s" href="%s">%s%s</a></th>',
        e($align), $sort === $key ? ' is-on' : '',
        e($qs(['sort' => $key, 'dir' => $next, 'page' => 1])),
        e($SORTS[$key][1]), $arrow);
};

// AI reads the filtered figures, so the wording follows whatever is on screen.
$aiInsight = '';
if (!empty($stats)) {
    try {
        $aiInsight = generate_analytics_insight($stats, defined('GROQ_API_KEY_SUPERADMIN') ? GROQ_API_KEY_SUPERADMIN : null);
    } catch (Exception $e) {
        $aiInsight = 'AI analysis temporarily unavailable.';
    }
}

/* ---- CSV, of exactly what is on screen ---- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="papel_analytics_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['PAPEL Analytics — ' . date('Y-m-d H:i')]);
    if ($filtered) {
        fputcsv($out, ['Filtered by',
            'Year: '    . ($fYear    ?: 'all'),
            'Program: ' . ($fProgram ?: 'all'),
            'Type: '    . ($fType    ?: 'all'),
            'Status: '  . ($fStatus  ?: 'all'),
            'Search: '  . ($fQuery   ?: '—')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Submissions', $totalSubs]);
    fputcsv($out, ['Approved', $approved]);
    fputcsv($out, ['In review', $pending]);
    fputcsv($out, ['Returned for revision', $returned]);
    fputcsv($out, []);
    fputcsv($out, ['BY PROGRAM']);
    fputcsv($out, ['Program', 'Submissions', 'Approved', 'In revision']);
    foreach ($stats as $r) {
        fputcsv($out, [$r['program'], $r['total_papers'], $r['approved'], $r['revisions']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['BY PAPER TYPE']);
    fputcsv($out, ['Type', 'Submissions', 'Share (%)']);
    foreach ($paperTypeStats as $r) {
        fputcsv($out, [$r['paper_type'], $r['count'],
            $totalPapers > 0 ? round(($r['count'] / $totalPapers) * 100, 1) : 0]);
    }
    fputcsv($out, []);
    fputcsv($out, [$byMonth ? 'SUBMISSIONS BY MONTH' : 'SUBMISSIONS BY YEAR']);
    fputcsv($out, [$byMonth ? 'Month' : 'Year', 'Submissions']);
    foreach ($timelineData as $r) { fputcsv($out, [$r['bucket'], $r['count']]); }
    fputcsv($out, []);
    fputcsv($out, ['EVERY SUBMISSION']);
    fputcsv($out, ['Submitted', 'Title', 'Student', 'Program', 'Type', 'Status']);
    $everything = $rows($run("SELECT rp.title, rp.paper_type, rp.current_status, rp.upload_date,
                                     u.full_name AS student, u.program
                              $FROM $WHERE ORDER BY {$SORTS[$sort][0]} $dir"));
    foreach ($everything as $r) {
        fputcsv($out, [substr((string)$r['upload_date'], 0, 10), $r['title'], $r['student'],
                       $r['program'], $r['paper_type'], $r['current_status']]);
    }
    fclose($out);
    exit;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>"
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"
        integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g"
        crossorigin="anonymous"></script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Everything here comes from the site's tokens, so the page follows whichever
   palette and theme the reader has chosen. */
body { background: var(--white); display: flex; flex-direction: column; min-height: 100vh; }

.an-wrap { padding: 1.5rem 0 3rem; }
.an-head { display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
.an-head h1 {
    font-family: var(--font-head); font-size: 1.375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .2rem;
}
.an-head p { font-size: .8125rem; color: var(--grey); margin: 0; max-width: 44rem; line-height: 1.6; }
.an-head .an-actions { margin-left: auto; display: flex; gap: .5rem; flex-wrap: wrap; }

/* ---- Filters ---- */
.an-filters {
    background: var(--cream); border-radius: 10px;
    padding: .75rem .875rem; margin-bottom: 1.125rem;
}
.an-filter-row { display: flex; flex-wrap: wrap; gap: .625rem; align-items: flex-end; }
.an-filter { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.an-filter label {
    font-size: .625rem; text-transform: uppercase; letter-spacing: .04em; color: var(--grey);
}
.an-filter select, .an-filter input {
    border: 1px solid var(--border); border-radius: 8px;
    padding: .4rem .6rem; font-family: var(--font-body); font-size: .8125rem;
    color: var(--ink); background: var(--white); min-width: 9rem;
}
.an-filter select {
    appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 2rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right .75rem center;
}
.an-filter input:focus, .an-filter select:focus { border-color: var(--maroon); outline: none; }
.an-filter-search input { min-width: 14rem; }
.an-filter-go { display: flex; gap: .375rem; }
/* What is currently narrowing the page, and how to undo it. */
.an-active {
    display: flex; flex-wrap: wrap; align-items: center; gap: .375rem;
    margin-top: .625rem; padding-top: .625rem; border-top: 1px solid var(--border);
    font-size: .6875rem; color: var(--grey);
}
.an-chip {
    display: inline-flex; align-items: center; gap: .25rem;
    background: var(--white); border: 1px solid var(--maroon); border-radius: 999px;
    padding: .15rem .5rem; font-size: .6875rem; color: var(--maroon);
}
.an-chip a { color: var(--maroon); text-decoration: none; font-weight: 600; line-height: 1; }
.an-clear { margin-left: auto; color: var(--maroon); font-size: .6875rem; }

/* ---- Headline figures ---- */
.an-tiles {
    display: grid; gap: .875rem; margin-bottom: 1.125rem;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}
.an-tile { background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: .875rem 1rem; }
.an-tile-label {
    font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--grey); display: block; margin-bottom: .25rem;
}
.an-tile-value {
    font-family: var(--font-head); font-size: 1.625rem; font-weight: 600;
    color: var(--maroon); line-height: 1.1; font-variant-numeric: tabular-nums;
}
.an-tile-note { display: block; font-size: .6875rem; color: var(--grey); margin-top: .2rem; }
.an-tile-note.is-up { color: #1b5e35; }
.an-tile-note.is-down { color: var(--dark-maroon); }

/* ---- Cards ---- */
.an-card { background: var(--white); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 1.125rem; overflow: hidden; }
.an-card-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .8rem 1.125rem; border-bottom: 1px solid var(--border);
    font-family: var(--font-head); font-size: .875rem; font-weight: 600; color: var(--maroon);
}
.an-card-head .material-symbols-outlined { font-size: 18px; }
.an-card-head .an-hint { margin-left: auto; font-family: var(--font-body); font-size: .6875rem; color: var(--grey); font-weight: 400; }
.an-card-body { padding: 1.125rem; }

.an-ai-body { display: flex; gap: .75rem; align-items: flex-start; }
.an-ai-body .material-symbols-outlined { color: var(--maroon); font-size: 20px; flex: 0 0 auto; }
.an-ai-text { font-size: .875rem; color: var(--ink); line-height: 1.7; }
.an-ai-empty { font-size: .8125rem; color: var(--grey); }

/* ---- Charts ---- */
.an-grid { display: grid; gap: 1.125rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); }
.an-chart { position: relative; height: 15rem; }
.an-expandable { cursor: zoom-in; }
.an-empty { padding: 2.5rem 1rem; text-align: center; color: var(--grey); font-size: .8125rem; }
.an-empty .material-symbols-outlined { font-size: 32px; display: block; margin: 0 auto .5rem; opacity: .5; }

/* ---- Tables ---- */
.an-scroll { overflow-x: auto; }
.an-table { width: 100%; border-collapse: collapse; font-size: .8125rem; color: var(--ink); }
.an-table th {
    text-align: left; white-space: nowrap; padding: .6rem .75rem;
    background: var(--cream); font-size: .6875rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .04em; color: var(--grey);
    border-bottom: 1px solid var(--border);
}
.an-table td { padding: .65rem .75rem; border-bottom: 1px solid var(--border); vertical-align: top; }
.an-table tbody tr:last-child td { border-bottom: none; }
.an-table tbody tr:hover { background: var(--cream); }
.an-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.an-strong { color: var(--maroon); font-weight: 500; }
.an-bar { display: block; height: 4px; border-radius: 2px; background: var(--cream); margin-top: .3rem; }
.an-bar span { display: block; height: 100%; border-radius: 2px; background: var(--maroon); }

/* A heading you can sort by. */
.an-sort { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: .25rem; }
.an-sort:hover { color: var(--maroon); }
.an-sort.is-on { color: var(--maroon); }
.an-sort-dir { font-size: .75rem; }
.an-title { font-weight: 500; color: var(--ink); }
.an-sub { display: block; font-size: .6875rem; color: var(--grey); margin-top: .15rem; }
.an-when { white-space: nowrap; color: var(--grey); font-size: .75rem; }
.an-status {
    display: inline-block; padding: .1rem .45rem; border-radius: 999px;
    background: var(--cream); color: var(--maroon);
    font-size: .625rem; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap;
}
.an-status.is-approved { background: #e7f6ed; color: #1b5e35; }
.an-status.is-returned { background: #fdeaea; color: var(--dark-maroon); }

/* ---- Paging ---- */
.an-paging {
    display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
    padding: .75rem 1.125rem; border-top: 1px solid var(--border);
    font-size: .75rem; color: var(--grey);
}
.an-paging .an-pages { margin-left: auto; display: flex; gap: .375rem; align-items: center; }
.an-page {
    border: 1px solid var(--border); border-radius: 6px; background: var(--white);
    color: var(--maroon); text-decoration: none; padding: .25rem .6rem; font-size: .75rem;
}
.an-page:hover { background: var(--cream); border-color: var(--soft-maroon); }
.an-page.is-off { color: var(--grey); pointer-events: none; opacity: .5; }

/* ---- Expanded chart ---- */
.an-modal {
    position: fixed; inset: 0; z-index: 20000;
    display: flex; align-items: center; justify-content: center; padding: 1.5rem;
    background: rgba(51, 0, 0, .45); opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.an-modal.is-open { opacity: 1; pointer-events: auto; }
.an-modal-panel {
    width: 100%; max-width: 56rem; background: var(--white);
    border-radius: 12px; box-shadow: 0 18px 48px rgba(51, 0, 0, .28);
    overflow: hidden; display: flex; flex-direction: column; max-height: calc(100vh - 3rem);
}
.an-modal-head { display: flex; align-items: center; gap: .5rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--maroon); }
.an-modal-head h2 { font-family: var(--font-head); font-size: 1rem; font-weight: 500; color: var(--maroon); margin: 0; flex: 1 1 auto; }
.an-modal-close { border: none; background: none; cursor: pointer; padding: .15rem; color: var(--grey); border-radius: 6px; display: inline-flex; }
.an-modal-close:hover { background: var(--cream); color: var(--maroon); }
.an-modal-panel:focus { outline: none; }
.an-modal-body { padding: 1.25rem; overflow: auto; }
.an-modal-body .an-chart { height: min(60vh, 26rem); }

@media (max-width: 700px) {
    .an-head .an-actions { margin-left: 0; width: 100%; }
    .an-filter select, .an-filter input, .an-filter-search input { min-width: 0; width: 100%; }
    .an-filter { flex: 1 1 100%; }
}
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e(role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Analytics</span>
    </div>
</div>

<main class="wrap an-wrap">

    <div class="an-head">
        <div>
            <h1>Analytics</h1>
            <p>
                Every paper handed in for review.
                <?php if ($openDrafts): ?>
                    <?= number_format($openDrafts) ?>
                    <?= $openDrafts === 1 ? 'draft is' : 'drafts are' ?>
                    still being written and <?= $openDrafts === 1 ? 'is' : 'are' ?> not counted.
                <?php endif; ?>
            </p>
        </div>
        <div class="an-actions">
            <a class="btn-sm-outline" href="<?= e($qs(['export' => 'csv'])) ?>">
                <span class="material-symbols-outlined mi-18">download</span> Export CSV
            </a>
        </div>
    </div>

    <!-- ---- Filters ---- -->
    <form class="an-filters" method="get">
        <div class="an-filter-row">
            <div class="an-filter">
                <label for="fYear">Year</label>
                <select name="year" id="fYear">
                    <option value="">All years</option>
                    <?php foreach ($optYears as $y): ?>
                        <option value="<?= e($y) ?>" <?= (string)$fYear === (string)$y ? 'selected' : '' ?>><?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="an-filter">
                <label for="fProgram">Program</label>
                <select name="program" id="fProgram">
                    <option value="">All programs</option>
                    <?php foreach ($optPrograms as $p): ?>
                        <option value="<?= e($p) ?>" <?= $fProgram === $p ? 'selected' : '' ?>>
                            <?= e(function_exists('program_code') ? program_code($p) : $p) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="an-filter">
                <label for="fType">Paper type</label>
                <select name="type" id="fType">
                    <option value="">All types</option>
                    <?php foreach ($optTypes as $t): ?>
                        <option value="<?= e($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="an-filter">
                <label for="fStatus">Stage</label>
                <select name="status" id="fStatus">
                    <option value="">Any stage</option>
                    <?php foreach ($optStatuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="an-filter an-filter-search">
                <label for="fQ">Search</label>
                <input type="search" name="q" id="fQ" value="<?= e($fQuery) ?>"
                       placeholder="Title, author or student">
            </div>
            <div class="an-filter an-filter-go">
                <button type="submit" class="btn-sm-maroon">
                    <span class="material-symbols-outlined mi-18">filter_alt</span> Apply
                </button>
            </div>
        </div>

        <?php if ($filtered): ?>
            <div class="an-active">
                <span>Showing</span>
                <?php
                $chips = [];
                if ($fYear !== '')    { $chips['year']    = $fYear; }
                if ($fProgram !== '') { $chips['program'] = function_exists('program_code') ? program_code($fProgram) : $fProgram; }
                if ($fType !== '')    { $chips['type']    = $fType; }
                if ($fStatus !== '')  { $chips['status']  = ucwords(str_replace('_', ' ', $fStatus)); }
                if ($fQuery !== '')   { $chips['q']       = '“' . $fQuery . '”'; }
                ?>
                <?php foreach ($chips as $key => $label): ?>
                    <span class="an-chip"><?= e($label) ?>
                        <a href="<?= e($qs([$key => '', 'page' => 1])) ?>" title="Remove">&times;</a>
                    </span>
                <?php endforeach; ?>
                <a class="an-clear" href="analytics_dashboard.php">Clear all</a>
            </div>
        <?php endif; ?>
    </form>

    <!-- ---- Headline figures ---- -->
    <div class="an-tiles">
        <div class="an-tile">
            <span class="an-tile-label">Submissions</span>
            <span class="an-tile-value"><?= number_format($totalSubs) ?></span>
            <?php if ($openDrafts && !$filtered): ?>
                <span class="an-tile-note"><?= number_format($openDrafts) ?> more still in draft</span>
            <?php endif; ?>
        </div>
        <div class="an-tile">
            <span class="an-tile-label">Approved</span>
            <span class="an-tile-value"><?= number_format($approved) ?></span>
            <span class="an-tile-note"><?= (int)$approvalPct ?>% of these submissions</span>
        </div>
        <div class="an-tile">
            <span class="an-tile-label">In review</span>
            <span class="an-tile-value"><?= number_format($pending) ?></span>
        </div>
        <div class="an-tile">
            <span class="an-tile-label">Returned</span>
            <span class="an-tile-value"><?= number_format($returned) ?></span>
            <span class="an-tile-note">sent back for revision</span>
        </div>
        <div class="an-tile">
            <span class="an-tile-label">This month</span>
            <span class="an-tile-value"><?= number_format($thisMonth) ?></span>
            <?php if ($lastMonth || $thisMonth): ?>
                <span class="an-tile-note <?= $monthDelta > 0 ? 'is-up' : ($monthDelta < 0 ? 'is-down' : '') ?>">
                    <?php if ($monthDelta > 0): ?><?= $monthDelta ?> more than last month
                    <?php elseif ($monthDelta < 0): ?><?= abs($monthDelta) ?> fewer than last month
                    <?php else: ?>Same as last month<?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="an-tile">
            <span class="an-tile-label">Average time to approval</span>
            <span class="an-tile-value"><?= $avgDays !== null ? e($avgDays) : '—' ?></span>
            <span class="an-tile-note"><?= $avgDays !== null ? 'days' : 'not recorded yet' ?></span>
        </div>
    </div>

    <!-- ---- What the numbers say ---- -->
    <div class="an-card">
        <div class="an-card-head">
            <span class="material-symbols-outlined">auto_awesome</span> What the numbers say
            <span class="an-hint">Written by AI from the figures shown</span>
        </div>
        <div class="an-card-body">
            <?php if (trim((string)$aiInsight) !== ''): ?>
                <div class="an-ai-body">
                    <span class="material-symbols-outlined">insights</span>
                    <div class="an-ai-text"><?= nl2br(e($aiInsight)) ?></div>
                </div>
            <?php else: ?>
                <p class="an-ai-empty">
                    There is not enough here to summarise. Once a few papers have been through
                    review, a written summary of the figures will appear.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---- Charts ---- -->
    <div class="an-grid">
        <div class="an-card">
            <div class="an-card-head">
                <span class="material-symbols-outlined">show_chart</span>
                Submissions <?= $byMonth ? 'by month' : 'by year' ?>
                <span class="an-hint"><?= $byMonth ? e($fYear) : 'pick a year for months' ?></span>
            </div>
            <div class="an-card-body">
                <?php if ($timelineData): ?>
                    <div class="an-chart an-expandable" data-chart="timeline" title="Click to enlarge">
                        <canvas id="timelineChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">show_chart</span>Nothing to plot.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="an-card">
            <div class="an-card-head"><span class="material-symbols-outlined">calendar_month</span> This month against last</div>
            <div class="an-card-body">
                <div class="an-chart an-expandable" data-chart="monthly" title="Click to enlarge">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <div class="an-card">
            <div class="an-card-head"><span class="material-symbols-outlined">check_circle</span> Approval rate by paper type</div>
            <div class="an-card-body">
                <?php if ($approvalByType): ?>
                    <div class="an-chart an-expandable" data-chart="approval" title="Click to enlarge">
                        <canvas id="approvalRateChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">check_circle</span>Nothing to compare.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="an-card">
            <div class="an-card-head"><span class="material-symbols-outlined">donut_small</span> Paper types</div>
            <div class="an-card-body">
                <?php if ($paperTypeStats): ?>
                    <div class="an-chart an-expandable" data-chart="types" title="Click to enlarge">
                        <canvas id="paperTypeChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">donut_small</span>Nothing to plot.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ---- Summary tables. Small by nature, so these sort in the browser. ---- -->
    <div class="an-grid">
        <div class="an-card">
            <div class="an-card-head"><span class="material-symbols-outlined">category</span> By paper type</div>
            <div class="an-scroll">
                <table class="an-table js-sortable">
                    <thead>
                        <tr>
                            <th data-sort="text">Type</th>
                            <th class="an-num" data-sort="num">Papers</th>
                            <th class="an-num" data-sort="num">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$paperTypeStats): ?>
                        <tr><td colspan="3"><div class="an-empty">Nothing to show.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($paperTypeStats as $row): ?>
                        <?php $pct = $totalPapers > 0 ? round(($row['count'] / $totalPapers) * 100, 1) : 0; ?>
                        <tr>
                            <td>
                                <a href="<?= e($qs(['type' => $row['paper_type'] === 'Unspecified' ? '' : $row['paper_type'], 'page' => 1])) ?>"
                                   class="an-sort"><?= e($row['paper_type']) ?></a>
                            </td>
                            <td class="an-num an-strong" data-value="<?= (int)$row['count'] ?>"><?= number_format((int)$row['count']) ?></td>
                            <td class="an-num" data-value="<?= $pct ?>">
                                <?= $pct ?>%
                                <span class="an-bar"><span style="width: <?= min(100, $pct) ?>%"></span></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="an-card">
            <div class="an-card-head"><span class="material-symbols-outlined">school</span> By program</div>
            <div class="an-scroll">
                <table class="an-table js-sortable">
                    <thead>
                        <tr>
                            <th data-sort="text">Program</th>
                            <th class="an-num" data-sort="num">Papers</th>
                            <th class="an-num" data-sort="num">Approved</th>
                            <th class="an-num" data-sort="num">Returned</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$stats): ?>
                        <tr><td colspan="4"><div class="an-empty">Nothing to show.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($stats as $row): ?>
                        <tr>
                            <td>
                                <a href="<?= e($qs(['program' => $row['program'], 'page' => 1])) ?>" class="an-sort"
                                   title="<?= e($row['program']) ?>">
                                    <?= e(function_exists('program_code') ? program_code($row['program']) : $row['program']) ?>
                                </a>
                            </td>
                            <td class="an-num an-strong" data-value="<?= (int)$row['total_papers'] ?>"><?= number_format((int)$row['total_papers']) ?></td>
                            <td class="an-num" data-value="<?= (int)$row['approved'] ?>"><?= number_format((int)$row['approved']) ?></td>
                            <td class="an-num" data-value="<?= (int)$row['revisions'] ?>"><?= number_format((int)$row['revisions']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ---- Every submission ---- -->
    <div class="an-card">
        <div class="an-card-head">
            <span class="material-symbols-outlined">list</span> Every submission
            <span class="an-hint"><?= number_format($listTotal) ?> in total &middot; newest first unless you say otherwise</span>
        </div>
        <div class="an-scroll">
            <table class="an-table">
                <thead>
                    <tr>
                        <?php $sortHead('title'); ?>
                        <?php $sortHead('student'); ?>
                        <?php $sortHead('program'); ?>
                        <?php $sortHead('type'); ?>
                        <?php $sortHead('status'); ?>
                        <?php $sortHead('date'); ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$papers): ?>
                    <tr><td colspan="6">
                        <div class="an-empty">
                            <span class="material-symbols-outlined">search_off</span>
                            Nothing matches those filters.
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($papers as $p): ?>
                    <?php
                    $st = $p['current_status'];
                    $cls = $st === 'approved' ? 'is-approved' : ($st === 'draft' ? 'is-returned' : '');
                    $label = $st === 'draft' ? 'Returned' : ucwords(str_replace(['pending_', '_'], ['', ' '], $st));
                    ?>
                    <tr>
                        <td>
                            <span class="an-title"><?= e($p['title'] ?: 'Untitled') ?></span>
                            <?php if (!empty($p['author_names'])): ?>
                                <span class="an-sub"><?= e($p['author_names']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($p['student'] ?: '—') ?></td>
                        <td title="<?= e($p['program'] ?? '') ?>">
                            <?= e($p['program'] ? (function_exists('program_code') ? program_code($p['program']) : $p['program']) : '—') ?>
                        </td>
                        <td><?= e($p['paper_type'] ?: '—') ?></td>
                        <td><span class="an-status <?= e($cls) ?>"><?= e($label) ?></span></td>
                        <td class="an-when"><?= e(date('M j, Y', strtotime($p['upload_date']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($listTotal > 0): ?>
        <div class="an-paging">
            <span>
                Showing <?= number_format($offset + 1) ?>&ndash;<?= number_format(min($offset + $perPage, $listTotal)) ?>
                of <?= number_format($listTotal) ?>
            </span>
            <?php if ($pages > 1): ?>
            <span class="an-pages">
                <a class="an-page <?= $page <= 1 ? 'is-off' : '' ?>" href="<?= e($qs(['page' => max(1, $page - 1)])) ?>">Previous</a>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <a class="an-page <?= $page >= $pages ? 'is-off' : '' ?>" href="<?= e($qs(['page' => min($pages, $page + 1)])) ?>">Next</a>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</main>

<div class="an-modal" id="chartModal" role="dialog" aria-modal="true" aria-labelledby="chartModalTitle">
    <div class="an-modal-panel" id="chartModalPanel" tabindex="-1">
        <div class="an-modal-head">
            <span class="material-symbols-outlined">zoom_in</span>
            <h2 id="chartModalTitle">Chart</h2>
            <button type="button" class="an-modal-close" id="chartModalClose" aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="an-modal-body"><div class="an-chart"><canvas id="modalChart"></canvas></div></div>
    </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

    /* ---- The small tables sort in the browser ----
       They hold one row per programme or per paper type, so there is never a
       second page for a click to be sorting only part of. The big table below
       sorts in the database instead, for exactly that reason. */
    document.querySelectorAll('table.js-sortable').forEach(function (table) {
        var body = table.tBodies[0];
        table.querySelectorAll('th[data-sort]').forEach(function (th, col) {
            var dir = 0;
            th.style.cursor = 'pointer';
            th.addEventListener('click', function () {
                dir = dir === 1 ? -1 : 1;
                table.querySelectorAll('th[data-sort]').forEach(function (o) {
                    if (o !== th) { o.querySelector('.an-sort-dir') && o.querySelector('.an-sort-dir').remove(); }
                });
                var mark = th.querySelector('.an-sort-dir');
                if (!mark) { mark = document.createElement('span'); mark.className = 'an-sort-dir'; th.appendChild(mark); }
                mark.textContent = dir === 1 ? ' \u2191' : ' \u2193';

                var rows = [].slice.call(body.rows).filter(function (r) { return r.cells.length > 1; });
                var numeric = th.dataset.sort === 'num';
                rows.sort(function (a, b) {
                    var x = a.cells[col], y = b.cells[col];
                    if (!x || !y) { return 0; }
                    if (numeric) {
                        return (parseFloat(x.dataset.value || x.textContent) -
                                parseFloat(y.dataset.value || y.textContent)) * dir;
                    }
                    return x.textContent.trim().localeCompare(y.textContent.trim()) * dir;
                });
                rows.forEach(function (r) { body.appendChild(r); });
            });
        });
    });

    if (typeof Chart === 'undefined') { return; }

    /* Colours come from the theme, read fresh each time so a change of palette
       or of light/dark is followed rather than baked in at first paint. */
    var token = function (name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v || '').trim() || fallback;
    };

    var data = <?= json_encode([
        'timeline' => array_map(function ($r) use ($byMonth) {
            return ['label' => $byMonth ? date('M', strtotime($r['bucket'] . '-01')) : (string)$r['bucket'],
                    'value' => (int)$r['count']];
        }, $timelineData),
        'monthly'  => [['label' => 'Last month', 'value' => $lastMonth],
                       ['label' => 'This month', 'value' => $thisMonth]],
        'approval' => array_map(function ($r) {
            return ['label' => $r['paper_type'], 'value' => (float)$r['rate']];
        }, $approvalByType),
        'types'    => array_map(function ($r) {
            return ['label' => $r['paper_type'], 'value' => (int)$r['count']];
        }, $paperTypeStats),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    var labels = function (k) { return data[k].map(function (d) { return d.label; }); };
    var values = function (k) { return data[k].map(function (d) { return d.value; }); };

    function build() {
        var maroon = token('--maroon', '#820707'),
            soft   = token('--soft-maroon', '#b17d7d'),
            dark   = token('--dark-maroon', '#630000'),
            ink    = token('--ink', '#330000'),
            grey   = token('--grey', '#9f9f9f'),
            border = token('--border', '#e6d4d4'),
            cream  = token('--cream', '#fff5f5');
        var series = [maroon, soft, dark, '#a85a5a', '#d3a3a3', '#7a3b3b', '#c98c8c'];

        Chart.defaults.font.family = token('--font-body', 'Inter') + ', Inter, system-ui, sans-serif';
        Chart.defaults.font.size = 11;
        Chart.defaults.color = grey;

        var axes = {
            x: { grid: { display: false }, ticks: { color: grey, autoSkip: true, maxRotation: 0 } },
            y: { beginAtZero: true, grid: { color: border }, border: { display: false },
                 ticks: { color: grey, precision: 0 } }
        };
        var noLegend = { legend: { display: false } };
        var tip = { backgroundColor: ink, titleColor: '#fff', bodyColor: '#fff',
                    padding: 10, cornerRadius: 6, displayColors: false };

        return {
            timeline: function () {
                return { type: 'line',
                    data: { labels: labels('timeline'), datasets: [{
                        data: values('timeline'), borderColor: maroon, backgroundColor: cream,
                        fill: true, tension: .35, pointBackgroundColor: maroon,
                        pointRadius: 3, pointHoverRadius: 5, borderWidth: 2 }] },
                    options: { responsive: true, maintainAspectRatio: false,
                               plugins: Object.assign({ tooltip: tip }, noLegend), scales: axes } };
            },
            monthly: function () {
                return { type: 'bar',
                    data: { labels: labels('monthly'), datasets: [{
                        data: values('monthly'), backgroundColor: [soft, maroon],
                        borderRadius: 6, maxBarThickness: 64 }] },
                    options: { responsive: true, maintainAspectRatio: false,
                               plugins: Object.assign({ tooltip: tip }, noLegend), scales: axes } };
            },
            approval: function () {
                return { type: 'bar',
                    data: { labels: labels('approval'), datasets: [{
                        data: values('approval'), backgroundColor: maroon,
                        borderRadius: 6, maxBarThickness: 40 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: Object.assign({ tooltip: Object.assign({}, tip, {
                            callbacks: { label: function (c) { return c.parsed.x + '% approved'; } } }) }, noLegend),
                        scales: {
                            x: { beginAtZero: true, max: 100, grid: { color: border }, border: { display: false },
                                 ticks: { color: grey, callback: function (v) { return v + '%'; } } },
                            y: { grid: { display: false }, ticks: { color: grey } } } } };
            },
            types: function () {
                return { type: 'doughnut',
                    data: { labels: labels('types'), datasets: [{
                        data: values('types'), backgroundColor: series,
                        borderColor: token('--white', '#fff'), borderWidth: 2 }] },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
                        plugins: { legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10,
                                   usePointStyle: true, pointStyle: 'circle', color: ink, padding: 12 } },
                                   tooltip: tip } } };
            }
        };
    }

    var titles = { timeline: 'Submissions over time', monthly: 'This month against last',
                   approval: 'Approval rate by paper type', types: 'Paper types' };
    var canvases = { timeline: 'timelineChart', monthly: 'monthlyChart',
                     approval: 'approvalRateChart', types: 'paperTypeChart' };

    var configs = build();
    var drawn = {};
    var instance = null, openKey = null, opener = null;

    function drawAll() {
        Object.keys(configs).forEach(function (key) {
            var el = document.getElementById(canvases[key]);
            if (!el) { return; }
            if (drawn[key]) { drawn[key].destroy(); }
            drawn[key] = new Chart(el, configs[key]());
        });
    }
    drawAll();

    new MutationObserver(function () {
        configs = build();
        drawAll();
        if (instance && openKey) {
            instance.destroy();
            instance = new Chart(document.getElementById('modalChart'), configs[openKey]());
        }
    }).observe(document.documentElement, {
        attributes: true, attributeFilter: ['data-mode', 'data-color', 'data-theme']
    });

    /* ---- The larger view ---- */
    var modal = document.getElementById('chartModal');
    var modalTitle = document.getElementById('chartModalTitle');

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
        if (instance) { instance.destroy(); instance = null; }
        openKey = null;
        if (opener) { opener.focus(); opener = null; }
    }

    document.querySelectorAll('.an-expandable').forEach(function (box) {
        box.setAttribute('tabindex', '0');
        box.setAttribute('role', 'button');
        var open = function () {
            var key = box.dataset.chart;
            if (!configs[key]) { return; }
            modalTitle.textContent = titles[key] || 'Chart';
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            if (instance) { instance.destroy(); }
            instance = new Chart(document.getElementById('modalChart'), configs[key]());
            openKey = key;
            opener = box;
            document.getElementById('chartModalPanel').focus();
        };
        box.addEventListener('click', open);
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
    });

    document.getElementById('chartModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) { closeModal(); }
    });
});
</script>
<?php require ROOT_PATH.'/includes/scroll_jump.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>