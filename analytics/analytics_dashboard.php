<?php
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../config/groq_config.php';
/* The Head of Academic Programs used to read these same figures inline on their
   own dashboard. That dashboard is now a list, so the numbers live here: the
   page is unscoped, which is the whole-institution view that role oversees. */
require_role(['admin','super_admin','faculty','head_academic']);
$conn = db();
$u = current_user();

/* What counts as a submission.
 *
 * A draft is a student's private workspace: it can sit half-written for weeks
 * and nobody has been asked to look at it. Counting those alongside real
 * submissions made three papers out of one, so analytics only counts work that
 * has actually been handed in.
 *
 * "Handed in" means it is past draft state, or it has at least one review step
 * behind it: the second half matters because a paper returned for revision
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
 * Everything below answers the same question narrowed by time, program,
 * paper type and review stage. They live in the
 * query string so a particular view can be bookmarked or sent to someone, and
 * so the browser's back button steps through them.
 * ------------------------------------------------------------------------ */
$fRange   = trim($_GET['range'] ?? 'all');
$fFrom    = trim($_GET['from'] ?? '');
$fTo      = trim($_GET['to'] ?? '');
if (!in_array($fRange, ['all', 'year', 'month', 'date', 'datetime'], true)) $fRange = 'all';
// Existing bookmarked ?year=YYYY links still open the same one-year view.
if (!isset($_GET['range']) && preg_match('/^\d{4}$/', (string)($_GET['year'] ?? ''))) {
    $fRange = 'year';
    $fFrom = $fTo = (string)$_GET['year'];
}
$fProgram = trim($_GET['program'] ?? '');
$fType    = trim($_GET['type']    ?? '');
$fStatus  = trim($_GET['status']  ?? '');
$fQuery   = trim($_GET['q']       ?? '');

$FROM  = "FROM research_papers rp LEFT JOIN users u ON u.user_id = rp.uploaded_by";
/* A paper's programme is the paper's own (program_category, what decides its
   filing folder and every report it appears in), and the uploader's account
   only for a paper that has none. Reading the account alone, as this page did,
   lost every paper a Librarian files for somebody else: the Librarian's account
   has no programme, so 24 of the 27 papers on file were in no programme at all,
   and the filter, Figure 5 and the tables knew only BSIT. Written from code and
   nothing posted, so it is safe to interpolate, like the whitelisted sort. */
$PROG  = paper_program_sql('rp', 'u');   // config/core.php; shared with the review desks
// The paper's type with retired aliases folded in ("thesis" is Undergraduate Thesis): see paper_type_sql().
$TYPE  = paper_type_sql('rp.paper_type');
/* Group by the expression, never by its alias: `GROUP BY program` is read as
   the users table's own column, which put all 24 of a Librarian's uploads in
   one group under whichever programme came first ("BSA 24"). */
$where = [$submitted('rp')];
$args  = [];
$types = '';

$rangeError = '';
$startAt = $endBefore = null;
$parseRangeValue = function (string $value, string $range, bool $upper): ?string {
    if ($value === '') return null;
    if ($range === 'year' && preg_match('/^\d{4}$/', $value) && (int)$value >= 1900 && (int)$value <= 9998) {
        return sprintf('%04d-01-01 00:00:00', (int)$value + ($upper ? 1 : 0));
    }
    $formats = ['month' => 'Y-m', 'date' => 'Y-m-d', 'datetime' => 'Y-m-d\TH:i'];
    if (!isset($formats[$range])) return null;
    $date = DateTimeImmutable::createFromFormat('!' . $formats[$range], $value);
    if (!$date || $date->format($formats[$range]) !== $value) return null;
    if ($upper) $date = $date->modify($range === 'month' ? '+1 month' : ($range === 'date' ? '+1 day' : '+1 minute'));
    return $date->format('Y-m-d H:i:s');
};
if ($fRange !== 'all') {
    $startAt = $parseRangeValue($fFrom, $fRange, false);
    $endBefore = $parseRangeValue($fTo, $fRange, true);
    if (($fFrom !== '' && $startAt === null) || ($fTo !== '' && $endBefore === null) ||
        ($startAt !== null && $endBefore !== null && $startAt >= $endBefore)) {
        $rangeError = 'Choose a valid time range with the start before the end.';
        $where[] = '1 = 0';
    } else {
        if ($startAt !== null) { $where[] = 'rp.upload_date >= ?'; $args[] = $startAt; $types .= 's'; }
        if ($endBefore !== null) { $where[] = 'rp.upload_date < ?'; $args[] = $endBefore; $types .= 's'; }
    }
}
if ($fProgram !== '') { $where[] = "$PROG = ?";              $args[] = $fProgram; $types .= 's'; }
// The shared type filter: Undergraduate Thesis also finds papers stored under the retired "thesis".
if ($fType    !== '') { $where[] = paper_type_filter_sql('rp.paper_type', $fType, $args, $types); }
if ($fStatus  !== '') { $where[] = "rp.current_status = ?";  $args[] = $fStatus;  $types .= 's'; }
if ($fQuery   !== '') {
    $where[] = "(rp.title LIKE ? OR rp.author_names LIKE ? OR u.full_name LIKE ?)";
    $like = '%' . $fQuery . '%';
    array_push($args, $like, $like, $like); $types .= 'sss';
}
$WHERE = 'WHERE ' . implode(' AND ', $where);
$timeFiltered = ($fRange !== 'all' && ($fFrom !== '' || $fTo !== ''));
$filtered = ($timeFiltered || $fProgram !== '' || $fType !== '' || $fStatus !== '' || $fQuery !== '');
$selection = [
    'time' => $timeFiltered ? ['unit' => $fRange, 'from' => $fFrom ?: null, 'to' => $fTo ?: null] : 'all time',
    'program' => $fProgram ?: 'all', 'paper_type' => $fType ?: 'all',
    'status' => $fStatus ?: 'all', 'text_search_active' => $fQuery !== '',
];

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
   The categorical choices come from the whole roll, so changing one filter
   does not empty the others. Time bounds may also select an empty period. */
$all = "FROM research_papers rp LEFT JOIN users u ON u.user_id = rp.uploaded_by WHERE " . $submitted('rp');
/* Every programme the campus offers, in the order programs_map() keeps them,
   and not only the ones somebody has submitted under: a list of one (BSIT) hid
   the other programmes as if they did not exist, and choosing one with nothing
   in it is itself an answer ("no submissions yet"). Any other value found on a
   submitter's account (Faculty Member, a retired or typed-in name) follows the
   map, so no paper on file becomes impossible to filter to. */
$optPrograms = program_filter_options(array_column($rows($conn->query(
    "SELECT DISTINCT $PROG v $all AND $PROG IS NOT NULL ORDER BY v")), 'v'));
$optTypes = array_column($rows($conn->query(
    "SELECT DISTINCT $TYPE v $all AND rp.paper_type IS NOT NULL AND rp.paper_type <> '' ORDER BY v")), 'v');
$optStatuses = array_column($rows($conn->query(
    "SELECT DISTINCT rp.current_status v $all ORDER BY v")), 'v');
$rangeInputType = ['all' => 'date', 'year' => 'number', 'month' => 'month',
                   'date' => 'date', 'datetime' => 'datetime-local'][$fRange];

/* ---- Headline figures ---- */
$totalSubs = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE"));
$approved  = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE AND rp.current_status = 'approved'"));
$pending   = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE AND rp.current_status IN
                             ('pending_faculty','pending_admin','pending_admin_l1',
                              'pending_head_academic','pending_super_admin')"));
$returned  = (int)$one($run("SELECT COUNT(*) v $FROM $WHERE AND rp.current_status = 'draft'"));
$avgDays   = $one($run("SELECT ROUND(AVG(a.avg_days),1) v $FROM
    JOIN (SELECT paper_id, AVG(time_to_approval) avg_days FROM analytics
          WHERE time_to_approval IS NOT NULL GROUP BY paper_id) a ON a.paper_id = rp.paper_id
    $WHERE"), 'v', null);
$approvalPct = $totalSubs > 0 ? round(($approved / $totalSubs) * 100) : 0;

// Drafts nobody has been asked to look at yet: counted apart, never inside.
$openDrafts = (int)$one($conn->query(
    "SELECT COUNT(*) v FROM research_papers rp WHERE NOT " . $submitted('rp')));

/* Keep a long span readable, and show days when a short date range is chosen. */
$timelineUnit = 'year';
if ($fRange === 'year' && $fFrom !== '' && $fFrom === $fTo) $timelineUnit = 'month';
elseif ($fRange === 'month') $timelineUnit = 'month';
elseif (in_array($fRange, ['date', 'datetime'], true)) {
    $spanDays = $startAt && $endBefore ? (strtotime($endBefore) - strtotime($startAt)) / 86400 : null;
    $timelineUnit = $spanDays !== null && $spanDays <= 95 ? 'day' : 'month';
}
$timelineBucket = [
    'year' => 'YEAR(rp.upload_date)',
    'month' => "DATE_FORMAT(rp.upload_date, '%Y-%m')",
    'day' => "DATE_FORMAT(rp.upload_date, '%Y-%m-%d')",
][$timelineUnit];
$timelineData = $rows($run("SELECT $timelineBucket AS bucket, COUNT(*) AS count
    $FROM $WHERE GROUP BY bucket ORDER BY bucket"));
$timelineUnitLabel = ['year' => 'year', 'month' => 'month', 'day' => 'day'][$timelineUnit];

// Keep months from different years distinct and show gaps as zero submissions.
$monthlyData = $rows($run("SELECT DATE_FORMAT(rp.upload_date, '%Y-%m') AS bucket, COUNT(*) AS count
    $FROM $WHERE GROUP BY bucket ORDER BY bucket"));
if ($monthlyData) {
    $monthCounts = array_column($monthlyData, 'count', 'bucket');
    $firstMonth = new DateTimeImmutable($monthlyData[0]['bucket'] . '-01');
    $lastMonth = new DateTimeImmutable($monthlyData[count($monthlyData) - 1]['bucket'] . '-01');
    $monthlyData = [];
    for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('+1 month')) {
        $bucket = $month->format('Y-m');
        $monthlyData[] = ['bucket' => $bucket, 'count' => (int)($monthCounts[$bucket] ?? 0)];
    }
}


/* ---- Breakdowns ---- */
$paperTypeStats = $rows($run(
    "SELECT COALESCE(NULLIF($TYPE,''),'Unspecified') AS paper_type, COUNT(*) AS count
     $FROM $WHERE GROUP BY COALESCE(NULLIF($TYPE,''),'Unspecified') ORDER BY count DESC"));
$totalPapers = array_sum(array_column($paperTypeStats, 'count'));

$stats = $rows($run(
    "SELECT $PROG AS program,
            COUNT(*) AS total_papers,
            SUM(rp.current_status = 'approved') AS approved,
            SUM(rp.current_status = 'draft')    AS revisions
     $FROM $WHERE AND $PROG IS NOT NULL
     GROUP BY $PROG ORDER BY total_papers DESC"));

$approvalByType = $rows($run(
    "SELECT COALESCE(NULLIF($TYPE,''),'Unspecified') AS paper_type,
            COUNT(*) AS total,
            SUM(rp.current_status = 'approved') AS approved
     $FROM $WHERE GROUP BY COALESCE(NULLIF($TYPE,''),'Unspecified') HAVING total > 0 ORDER BY total DESC"));
foreach ($approvalByType as &$_r) {
    $_r['rate'] = $_r['total'] > 0 ? round(($_r['approved'] / $_r['total']) * 100, 1) : 0;
}
unset($_r);

/* ---- Descriptive analytics -------------------------------------------------
   Enhanced breakdowns beyond the headline figures: how long each review stage
   takes, which research fields and methodologies are represented, submission
   timing patterns, and how complete the supporting documentation is. */

// Papers waiting longest in review (overdue)
$overduePapers = $rows($run(
    "SELECT rp.paper_id, rp.title, rp.current_status, rp.upload_date,
            DATEDIFF(NOW(), rp.upload_date) AS days_waiting,
            u.full_name AS student
     $FROM $WHERE AND rp.current_status IN ('pending_faculty','pending_admin','pending_admin_l1',
                                  'pending_head_academic','pending_super_admin')
     ORDER BY rp.upload_date ASC
     LIMIT 5"));

// Review bottleneck: which stage has the most papers queued
$stageQueue = $rows($run(
    "SELECT CASE WHEN rp.current_status = 'pending_admin_l1' THEN 'pending_admin'
                 ELSE rp.current_status END AS current_status, COUNT(*) AS queued
     $FROM $WHERE AND rp.current_status IN ('pending_faculty','pending_admin','pending_admin_l1',
                                  'pending_head_academic','pending_super_admin')
     GROUP BY CASE WHEN rp.current_status = 'pending_admin_l1' THEN 'pending_admin' ELSE rp.current_status END
     ORDER BY queued DESC"));
$statusLabelsMap = [
    'pending_faculty' => 'Subject Adviser review',
    'pending_admin' => 'Research Coordinator review',
    'pending_admin_l1' => 'Research Coordinator review',
    'pending_head_academic' => 'Head of Academic Programs review',
    'pending_super_admin' => 'Director review',
];

/* Keep each program separate so every course has its own slice. */
$programChart = $stats;
$unassignedPrograms = $totalSubs - array_sum(array_column($stats, 'total_papers'));
if ($unassignedPrograms > 0) {
    $programChart[] = ['program' => 'Unspecified program', 'total_papers' => $unassignedPrograms];
}

// Use recorded research fields; preserve specializations and identify missing values.
$researchFields = $rows($run("SELECT COALESCE(NULLIF(TRIM(rp.ai_research_field), ''), 'Unspecified') AS field,
    COUNT(*) AS count $FROM $WHERE GROUP BY field ORDER BY count DESC, field ASC"));

/* ---- Every submission, one row each ---------------------------------------
   The part that matters once there are years of these: sortable, searchable,
   and paged, so the page stays the same size whether it is describing ten
   papers or ten thousand. Sorting is done in the database, not in the browser,
   because sorting one page of results would only reorder that page. */
$SORTS = [
    'date'    => ['rp.upload_date',    'Submitted'],
    'title'   => ['rp.title',          'Title'],
    'student' => ['u.full_name',       'Student'],
    'program' => [$PROG,               'Program'],
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
            rp.author_names, u.full_name AS student, $PROG AS program
     $FROM $WHERE
     ORDER BY {$SORTS[$sort][0]} $dir, rp.paper_id DESC
     LIMIT ? OFFSET ?", [$perPage, $offset], 'ii'));

/* Keep the current filters when building any other link on the page. */
$qs = function (array $changes = []) use ($fRange, $fFrom, $fTo, $fProgram, $fType, $fStatus, $fQuery, $sort, $dir, $page) {
    $base = ['range' => $fRange === 'all' ? '' : $fRange,
             'from' => $fRange === 'all' ? '' : $fFrom, 'to' => $fRange === 'all' ? '' : $fTo,
             'program' => $fProgram, 'type' => $fType, 'status' => $fStatus,
             'q' => $fQuery, 'sort' => $sort, 'dir' => strtolower($dir), 'page' => $page];
    $merged = array_filter(array_merge($base, $changes), function ($v) { return $v !== '' && $v !== null; });
    return $merged ? '?' . http_build_query($merged) : '?';
};

/** A column heading that sorts, and shows which way it is sorting. */
$submissionHelp = [
    'title'   => 'The paper title, with its author names listed below.',
    'student' => 'The student account that submitted the paper.',
    'program' => 'The academic program of the student who submitted it.',
    'type'    => 'The kind of research paper, such as a thesis or undergraduate thesis.',
    'status'  => 'The paper’s current review or publication stage.',
    'date'    => 'The date the paper was recorded as uploaded in PAPEL.',
];
$sortHead = function (string $key, string $align = '') use ($SORTS, $sort, $dir, $qs, $submissionHelp) {
    $next = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
    $arrow = $sort !== $key ? '' :
        ($dir === 'ASC' ? '<span class="an-sort-dir">&uarr;</span>' : '<span class="an-sort-dir">&darr;</span>');
    printf('<th class="%s" data-help="%s" aria-description="%s"><a class="an-sort%s" href="%s">%s%s</a></th>',
        e($align), e($submissionHelp[$key]), e($submissionHelp[$key]), $sort === $key ? ' is-on' : '',
        e($qs(['sort' => $key, 'dir' => $next, 'page' => 1])),
        e($SORTS[$key][1]), $arrow);
};

/* Sorting and paging only need this table. Serve the same markup used by the
   full page, without rebuilding the charts or requesting another AI insight. */
define('PAPEL_ANALYTICS_SUBMISSIONS', true);
if (($_GET['_fragment'] ?? '') === 'submissions') {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/submissions_table.php';
    exit;
}

// Per-chart AI insights: one short observation beneath each of the six figures.
$chartInsights = [];
$chartDataSets = [
    'fields' => ['label' => 'Submissions by recorded research field (Unspecified means no field recorded)', 'data' => $researchFields],
    'monthly' => ['label' => 'Submissions per calendar month (month and year)', 'data' => $monthlyData],
    'timeline'   => ['label' => 'Submissions over time (by ' . $timelineUnitLabel . ')',   'data' => $timelineData],
    'approval'   => ['label' => 'Approval rate by paper type',                              'data' => $approvalByType],
    'types'      => ['label' => 'Paper type distribution',                                  'data' => $paperTypeStats],
    'programs'   => ['label' => 'Submissions by academic program',                          'data' => $programChart],
    'stageQueue' => ['label' => 'Current review queue by stage',                            'data' => $stageQueue],
];
// Section summaries share the same hourly cache and independent refresh endpoint.
$chartDataSets['overall-overview'] = ['label' => 'Overall submission insight across all overview charts', 'data' => $totalSubs ? [
    'selection' => $selection, 'submissions' => $totalSubs, 'approved' => $approved,
    'pending' => $pending, 'returned' => $returned, 'timeline' => $timelineData, 'monthly' => $monthlyData,
    'paper_types' => $paperTypeStats, 'programs' => $stats, 'research_fields' => $researchFields,
] : []];
$chartDataSets['overall-queue'] = ['label' => 'Overall review queue insight; zero pending means there is no current backlog',
    'data' => $totalSubs ? ['selection' => $selection, 'pending' => $pending, 'queue' => $stageQueue,
                          'longest_waiting_papers' => $overduePapers] : []];
require_once __DIR__ . '/insight_cache.php';
// Scope by the actual filter values (including search), never by pagination.
$insightScope = json_encode([$fRange, $fFrom, $fTo, $fProgram, $fType, $fStatus, $fQuery]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chart_insight'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    csrf_verify();
    $key = (string)$_POST['chart_insight'];
    if (!isset($chartDataSets[$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown chart.']);
        exit;
    }
    try {
        echo json_encode(analytics_chart_insight($insightScope, $key, $chartDataSets[$key], ($_POST['force'] ?? '') === '1'));
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(['error' => 'Insight unavailable. Please try again.']);
    }
    exit;
}
$chartInsightExpiry = [];
foreach ($chartDataSets as $key => $set) {
    // Exports reuse the page content and should not trigger AI requests.
    if (isset($_GET['export'])) continue;
    try {
        $entry = analytics_chart_insight($insightScope, $key, $set);
        $chartInsights[$key] = $entry['text'];
        $chartInsightExpiry[$key] = $entry['expires'];
    } catch (Throwable $e) {
        $chartInsights[$key] = '';
        $chartInsightExpiry[$key] = time() + analytics_insight_interval($key);
    }
}
$renderChartInsight = function (string $key) use ($chartInsights, $chartInsightExpiry, $chartDataSets) {
    if (empty($chartDataSets[$key]['data'])) return;
    ?>
    <div class="an-ai-body an-chart-insight" data-insight="<?= e($key) ?>" data-expires="<?= (int)($chartInsightExpiry[$key] ?? 0) ?>">
        <div class="an-insight-heading"><span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span><strong>AI insight</strong></div>
        <div class="an-insight-content"><div class="an-ai-text" aria-live="polite"><?= e(($chartInsights[$key] ?? '') ?: 'Insight unavailable. Try New insight.') ?></div>
        <div class="an-insight-controls"><small><?= analytics_insight_interval($key) === 86400 ? 'Updates automatically every day.' : 'Updates automatically every hour.' ?></small>
        <button type="button" class="btn btn-sm btn-outline-secondary an-new-insight">New insight</button>
        <small class="an-insight-status" role="status"></small></div></div>
    </div>
    <?php
};

$renderOverallInsight = function (string $key) use ($renderChartInsight, $chartDataSets) {
    ?>
    <div class="an-card an-overall-insight" data-an-card="<?= e($key) ?>">
        <div class="an-card-head"><span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span> Overall insight</div>
        <div class="an-card-body">
            <?php if ($chartDataSets[$key]['data']): $renderChartInsight($key); else: ?>
                <p class="an-ai-empty">No data available for an overall insight in this selection.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
$aiInsight = $chartInsights['overall-overview'] ?? '';

/* ---- CSV, of exactly what is on screen ---- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="papel_analytics_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['PAPEL Analytics, ' . date('Y-m-d H:i')]);
    if ($filtered) {
        fputcsv($out, ['Filtered by',
            'Time: '    . ($timeFiltered ? $fRange . ' ' . ($fFrom ?: 'earliest') . ' to ' . ($fTo ?: 'latest') : 'all'),
            'Program: ' . ($fProgram ?: 'all'),
            'Type: '    . ($fType    ? paper_type_label($fType) : 'all'),
            'Status: '  . ($fStatus  ?: 'all'),
            'Search: '  . ($fQuery   ?: 'none')]);
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
    fputcsv($out, ['Type', 'Submissions', 'Percentage (%)']);
    foreach ($paperTypeStats as $r) {
        fputcsv($out, [paper_type_label($r['paper_type']), $r['count'],
            $totalPapers > 0 ? round(($r['count'] / $totalPapers) * 100, 1) : 0]);
    }
    fputcsv($out, []);
    fputcsv($out, ['SUBMISSIONS BY ' . strtoupper($timelineUnitLabel)]);
    fputcsv($out, [ucfirst($timelineUnitLabel), 'Submissions']);
    foreach ($timelineData as $r) { fputcsv($out, [$r['bucket'], $r['count']]); }
    fputcsv($out, []);
    fputcsv($out, ['SUBMISSIONS PER MONTH']);
    fputcsv($out, ['Month', 'Submissions']);
    foreach ($monthlyData as $r) { fputcsv($out, [$r['bucket'], $r['count']]); }
    fputcsv($out, []);
    fputcsv($out, ['RESEARCH FIELDS']);
    fputcsv($out, ['Research field', 'Submissions']);
    foreach ($researchFields as $r) fputcsv($out, [$r['field'], $r['count']]);
    fputcsv($out, []);
    fputcsv($out, ['EVERY SUBMISSION']);
    fputcsv($out, ['Submitted', 'Title', 'Student', 'Program', 'Type', 'Status']);
    $everything = $rows($run("SELECT rp.title, rp.paper_type, rp.current_status, rp.upload_date,
                                     u.full_name AS student, $PROG AS program
                              $FROM $WHERE ORDER BY {$SORTS[$sort][0]} $dir"));
    foreach ($everything as $r) {
        fputcsv($out, [substr((string)$r['upload_date'], 0, 10), $r['title'], $r['student'],
                       $r['program'], paper_type_label($r['paper_type']), $r['current_status']]);
    }
    fclose($out);
    exit;
}

/* ---- The other three formats ----------------------------------------------
   These carry the charts, and a chart only exists in the browser: it is drawn
   on a canvas by Chart.js, and there is no GD on this server to redraw it. So
   the page posts the canvases here as images alongside the request, which is
   why these are a POST and the CSV is still a link.

   A request without figures is still answered, just without them, so the export
   does not fail outright if a chart has not finished drawing. */
$exportFormat = $_POST['export'] ?? '';
if (in_array($exportFormat, ['xlsx', 'docx', 'pdf'], true)) {
    csrf_verify();

    /* The same numbers the page is showing, gathered once for all three. */
    $when = date('j F Y, g:i A');
    $filterLine = 'All submissions';
    if ($filtered) {
        $bits = [];
        if ($timeFiltered) $bits[] = ucfirst($fRange) . ' ' . ($fFrom ?: 'earliest') . ' to ' . ($fTo ?: 'latest');
        if ($fProgram) $bits[] = program_code($fProgram) ?: $fProgram;
        if ($fType)    $bits[] = paper_type_label($fType);
        if ($fStatus)  $bits[] = str_replace('_', ' ', $fStatus);
        if ($fQuery)   $bits[] = 'matching "' . $fQuery . '"';
        $filterLine = 'Filtered by ' . implode(' · ', $bits);
    }

    $everything = $rows($run("SELECT rp.title, rp.paper_type, rp.current_status, rp.upload_date,
                                     u.full_name AS student, $PROG AS program
                              $FROM $WHERE ORDER BY {$SORTS[$sort][0]} $dir"));

    /* The figures, as sent by the page. Each is a data URL; anything that is
       not one of ours is ignored rather than trusted. */
    $figures = [];
    foreach (($_POST['figures'] ?? []) as $caption => $dataUrl) {
        if (!is_string($dataUrl)) continue;
        if (!preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~', $dataUrl, $m)) {
            continue;
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) < 64) continue;
        // 3 MB a figure is already generous for a chart; past that something is wrong.
        if (strlen($bin) > 3 * 1024 * 1024) continue;
        $figures[] = ['caption' => substr((string)$caption, 0, 120),
                      'type' => $m[1], 'data' => $bin,
                      'w' => (int)($_POST['figw'][$caption] ?? 0),
                      'h' => (int)($_POST['figh'][$caption] ?? 0)];
    }

    $summary = [
        ['Submissions', $totalSubs],
        ['Approved', $approved],
        ['In review', $pending],
        ['Returned for revision', $returned],
    ];
    $stamp = date('Y-m-d');

    if (($_POST['_preview'] ?? '') === '1' && in_array($exportFormat, ['xlsx', 'docx'], true)) {
        define('PAPEL_ANALYTICS_EXPORT_PREVIEW', true);
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/export_preview.php';
        exit;
    }

    if ($exportFormat === 'xlsx') {
        require_once ROOT_PATH . '/includes/xlsx_writer.php';
        $x = new XlsxWriter('Analytics', 'Figures');
        $x->widths([34, 16, 16, 16, 16, 18]);
        $x->title('PAPEL Analytics', $filterLine . '  ·  ' . $when);

        $x->section('Summary');
        $x->headRow(['Measure', 'Papers']);
        foreach ($summary as $rowPair) $x->row($rowPair);

        $x->section('By programme');
        $x->headRow(['Programme', 'Submissions', 'Approved', 'In revision']);
        foreach ($stats as $r) {
            $x->row([$r['program'], (int)$r['total_papers'], (int)$r['approved'],
                     (int)$r['revisions']]);
        }

        $x->section('By paper type');
        $x->headRow(['Type', 'Submissions', 'Percentage of papers (%)']);
        foreach ($paperTypeStats as $r) {
            $x->row([paper_type_label((string)$r['paper_type']), (int)$r['count'],
                     $totalPapers > 0 ? round(($r['count'] / $totalPapers) * 100, 1) : 0]);
        }

        $x->section('Submissions by ' . $timelineUnitLabel);
        $x->headRow([ucfirst($timelineUnitLabel), 'Submissions']);
        foreach ($timelineData as $r) $x->row([$r['bucket'], (int)$r['count']], [0]);

        $x->section('Every submission');
        $x->headRow(['Submitted', 'Title', 'Student', 'Programme', 'Type', 'Status']);
        foreach ($everything as $r) {
            $x->row([substr((string)$r['upload_date'], 0, 10), $r['title'], $r['student'],
                     program_code((string)$r['program']) ?: $r['program'],
                     paper_type_label((string)$r['paper_type']),
                     ucwords(str_replace('_', ' ', (string)$r['current_status']))], [0]);
        }

        foreach ($figures as $f) {
            if ($f['type'] === 'png') {
                $x->image($f['data'], max(1, $f['w']), max(1, $f['h']), $f['caption']);
            }
        }

        $bytes = $x->output();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="papel_analytics_' . $stamp . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    if ($exportFormat === 'docx') {
        require_once ROOT_PATH . '/includes/docx_writer.php';
        /* A Librarian and the administrators export landscape, always: see
           export_landscape() in config/core.php. The edited path below is the
           exception, since the preview's own control has said what shape the
           reader wants and that answer is already in the posted document. */
        $d = new DocxWriter(export_landscape());
        if (isset($_POST['word_document'])) {
            try {
                if (!is_string($_POST['word_document']) || strlen($_POST['word_document']) > 2 * 1024 * 1024) {
                    throw new InvalidArgumentException('Document too large.');
                }
                $edited = json_decode($_POST['word_document'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($edited)) throw new InvalidArgumentException('Invalid document.');
                $d->editedDocument($edited, $figures);
            } catch (InvalidArgumentException | JsonException | TypeError $e) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                exit('Could not export the edited Word document. Reopen the preview and try again.');
            }
        } else {
        $d->title('PAPEL Analytics', $filterLine . '  ·  ' . $when);

        if ($aiInsight !== '' && stripos($aiInsight, 'unavailable') === false) {
            $d->heading('What the figures say');
            $d->paragraph(strip_tags($aiInsight));
        }

        $d->heading('Summary');
        $d->table([['Measure', 3, 'left'], ['Papers', 1, 'right']],
                  array_map(fn($r) => [$r[0], number_format((int)$r[1])], $summary));

        $d->heading('By programme');
        $d->table([['Programme', 4, 'left'], ['Submissions', 1, 'right'],
                   ['Approved', 1, 'right'], ['In revision', 1, 'right']],
                  array_map(fn($r) => [$r['program'], (string)(int)$r['total_papers'],
                                       (string)(int)$r['approved'],
                                       (string)(int)$r['revisions']], $stats));

        $d->heading('By paper type');
        $d->table([['Type', 3, 'left'], ['Submissions', 1, 'right'], ['Percentage', 1, 'right']],
                  array_map(fn($r) => [paper_type_label((string)$r['paper_type']),
                                       (string)(int)$r['count'],
                                       ($totalPapers > 0
                                            ? round(($r['count'] / $totalPapers) * 100, 1)
                                            : 0) . '%'], $paperTypeStats));

        $d->heading('Submissions by ' . $timelineUnitLabel);
        $d->table([[ucfirst($timelineUnitLabel), 2, 'left'], ['Submissions', 1, 'right']],
                  array_map(fn($r) => [$r['bucket'], (string)(int)$r['count']], $timelineData));

        if ($figures) {
            $d->pageBreak();
            $d->heading('Figures');
            foreach ($figures as $f) {
                if ($f['type'] === 'png') {
                    $d->image($f['data'], max(1, $f['w']), max(1, $f['h']), $f['caption']);
                }
            }
        }

        $d->pageBreak();
        $d->heading('Every submission');
        $d->table([['Submitted', 2, 'left'], ['Title', 6, 'left'], ['Student', 3, 'left'],
                   ['Programme', 2, 'left'], ['Status', 2, 'left']],
                  array_map(fn($r) => [substr((string)$r['upload_date'], 0, 10), $r['title'],
                                       $r['student'],
                                       program_code((string)$r['program']) ?: $r['program'],
                                       ucwords(str_replace('_', ' ',
                                           (string)$r['current_status']))], $everything));

        }
        $bytes = $d->output();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="papel_analytics_' . $stamp . '.docx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    // pdf
    require_once ROOT_PATH . '/includes/pdf_writer.php';
    $pdf = new PdfWriter('PAPEL Analytics', $filterLine . '  -  ' . $when, export_landscape());

    if ($aiInsight !== '' && stripos($aiInsight, 'unavailable') === false) {
        $pdf->heading('What the figures say');
        $pdf->paragraph(strip_tags($aiInsight));
    }

    $pdf->heading('Summary');
    $pdf->table([['Measure', 320, 'l'], ['Papers', 179, 'r']],
                array_map(fn($r) => [$r[0], number_format((int)$r[1])], $summary));

    $pdf->heading('By programme');
    $pdf->table([['Programme', 239, 'l'], ['Submissions', 90, 'r'],
                 ['Approved', 85, 'r'], ['In revision', 85, 'r']],
                array_map(fn($r) => [$r['program'], (string)(int)$r['total_papers'],
                                     (string)(int)$r['approved'],
                                     (string)(int)$r['revisions']], $stats));

    $pdf->heading('By paper type');
    $pdf->table([['Type', 259, 'l'], ['Submissions', 120, 'r'], ['Percentage', 120, 'r']],
                array_map(fn($r) => [paper_type_label((string)$r['paper_type']),
                                     (string)(int)$r['count'],
                                     ($totalPapers > 0
                                        ? round(($r['count'] / $totalPapers) * 100, 1)
                                        : 0) . '%'], $paperTypeStats));

    $pdf->heading('Submissions by ' . $timelineUnitLabel);
    $pdf->table([[ucfirst($timelineUnitLabel), 259, 'l'], ['Submissions', 240, 'r']],
                array_map(fn($r) => [$r['bucket'], (string)(int)$r['count']], $timelineData));

    if ($figures) {
        $pdf->heading('Figures');
        foreach ($figures as $f) {
            if ($f['type'] === 'jpeg') $pdf->figure($f['data'], $f['caption']);
        }
    }

    $pdf->heading('Every submission');
    $pdf->table([['Submitted', 66, 'l'], ['Title', 205, 'l'], ['Student', 110, 'l'],
                 ['Programme', 60, 'l'], ['Status', 58, 'l']],
                array_map(fn($r) => [substr((string)$r['upload_date'], 0, 10), $r['title'],
                                     $r['student'],
                                     program_code((string)$r['program']) ?: $r['program'],
                                     ucwords(str_replace('_', ' ',
                                         (string)$r['current_status']))], $everything));

    $bytes = $pdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (($_POST['_preview'] ?? '') === '1' ? 'inline' : 'attachment') .
           '; filename="papel_analytics_' . $stamp . '.pdf"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
$chartData = [
    'fields' => array_map(function ($r) { return ['label' => $r['field'], 'value' => (int)$r['count']]; }, $researchFields),
    'monthly' => array_map(function ($r) {
        return ['label' => date('M Y', strtotime($r['bucket'] . '-01')), 'value' => (int)$r['count']];
    }, $monthlyData),
    'timeline' => array_map(function ($r) use ($timelineUnit) {
        $label = $timelineUnit === 'year' ? (string)$r['bucket'] :
                 ($timelineUnit === 'month' ? date('M Y', strtotime($r['bucket'] . '-01')) :
                  date('M j, Y', strtotime($r['bucket'])));
        return ['label' => $label,
                'value' => (int)$r['count']];
    }, $timelineData),
    'approval' => array_map(function ($r) {
        return ['label' => paper_type_label($r['paper_type']), 'value' => (float)$r['rate']];
    }, $approvalByType),
    'types'    => array_map(function ($r) {
        return ['label' => paper_type_label($r['paper_type']), 'value' => (int)$r['count']];
    }, $paperTypeStats),
    'status'   => [
        ['label' => 'Approved', 'value' => $approved],
        ['label' => 'In review', 'value' => $pending],
        ['label' => 'Returned', 'value' => $returned],
        ['label' => 'Other', 'value' => max(0, $totalSubs - $approved - $pending - $returned)],
    ],
    'programs' => array_map(function ($r) {
        return ['label' => $r['program'] === 'Unspecified program' ? 'Unspecified program' :
                (function_exists('program_code') ? program_code($r['program']) : $r['program']),
                'value' => (int)$r['total_papers']];
    }, $programChart),

    // Prescriptive analytics
    'stageQueue' => array_map(function ($r) use ($statusLabelsMap) {
        return ['label' => $statusLabelsMap[$r['current_status']] ?? $r['current_status'],
                'value' => (int)$r['queued']];
    }, $stageQueue),
];
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
.an-heading-line { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; margin-bottom: .2rem; }
.an-heading-line h1 { margin: 0; }
.an-ai-badge {
    display: inline-flex; align-items: center; gap: .25rem; min-height: 1.5rem;
    padding: .15rem .5rem; border: 1px solid var(--border); border-radius: 5px;
    background: var(--cream); color: var(--maroon);
    font: 600 .6875rem/1.2 var(--font-body); white-space: nowrap;
}
.an-ai-badge .material-symbols-outlined { font-size: 14px; }
.an-head p { font-size: .8125rem; color: var(--grey); margin: 0; max-width: 44rem; line-height: 1.6; }
/* ---- Export dropdown ----
   One button with four ways out of the page. The menu is a plain absolutely
   positioned panel rather than a native <select>, because each row carries a
   line of explanation and a select cannot hold one. */
.an-export { position: relative; }
.an-export-menu {
    position: absolute;
    top: calc(100% + .375rem);
    right: 0;
    z-index: 60;
    min-width: 17rem;
    padding: .3rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md);
}
.an-export-menu[hidden] { display: none; }
.an-export-menu > a,
.an-export-menu > button {
    display: flex; align-items: flex-start; gap: .625rem; width: 100%;
    padding: .5rem .625rem;
    border: 0; border-radius: var(--r-control, 4px);
    background: none; text-align: left; cursor: pointer;
    font-family: var(--font-body); font-size: .8125rem; color: var(--ink);
    text-decoration: none;
}
.an-export-menu > a:hover,
.an-export-menu > button:hover { background: var(--cream); }
.an-export-menu .material-symbols-outlined { color: var(--maroon); flex: 0 0 auto; }
.an-export-menu strong { display: block; font-weight: 600; }
.an-export-menu small { display: block; font-size: .6875rem; color: var(--grey); margin-top: .05rem; }
/* While a file is being built the button says so: an Excel export of a long
   roll takes a moment, and a button that does nothing visible gets pressed
   again. */
.an-export.is-busy #anExportBtn { opacity: .65; pointer-events: none; }

/* Export preview stays light so the file can be judged as it will print. */
html.an-export-preview-open { overflow: hidden; }
.an-export-preview {
    position: fixed; inset: 0; z-index: 1300;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem; background: rgba(29, 14, 14, .7);
}
.an-export-preview[hidden] { display: none; }
.an-export-preview-panel {
    display: flex; flex-direction: column; width: min(70rem, 100%);
    height: min(90vh, 62rem); min-height: 0; overflow: hidden;
    border: 1px solid #e6d4d4; border-radius: var(--r-card, 8px);
    background: #fff; color: #302626; box-shadow: 0 20px 55px rgba(0, 0, 0, .28);
}
.an-export-preview-head, .an-export-preview-foot {
    display: flex; align-items: center; gap: .75rem; flex: 0 0 auto;
    padding: .75rem 1rem; background: #fff;
}
.an-export-preview-head { border-bottom: 1px solid #e6d4d4; justify-content: space-between; flex-wrap: wrap; }
.an-export-preview-head h2 { margin: 0; color: #820707; font: 600 1rem/1.3 var(--font-head); }
.an-preview-zoom { display: inline-flex; align-items: center; gap: .25rem; color: #302626; }
.an-preview-zoom[hidden] { display: none; }
.an-preview-zoom button { min-width: 2rem; min-height: 2rem; border: 1px solid #d9caca;
    border-radius: 4px; background: #fff; color: #820707; cursor: pointer; font: 600 1rem Arial, sans-serif; }
.an-preview-zoom button:disabled { opacity: .4; cursor: default; }
.an-preview-zoom button:focus-visible { outline: 2px solid #820707; outline-offset: 2px; }
.an-preview-zoom-output { min-width: 3.25rem; text-align: center; font: 500 .8125rem Arial, sans-serif; }
.an-export-preview-foot { justify-content: flex-end; border-top: 1px solid #e6d4d4; }
.an-export-preview-foot button {
    border: 1px solid #cbaaaa; border-radius: var(--r-control, 4px);
    padding: .45rem .8rem; background: #fff; color: #820707;
    font: 500 .8125rem/1.3 var(--font-body); cursor: pointer;
}
.an-export-preview-foot .is-primary { background: #820707; border-color: #820707; color: #fff; }
.an-export-preview-foot button:disabled { opacity: .5; cursor: wait; }
.an-export-preview-foot button:focus-visible {
    outline: 2px solid #820707; outline-offset: 2px;
}
.an-export-preview-body {
    flex: 1 1 auto; min-height: 0; overflow: auto;
    padding: 1.25rem; background: #f6f3f3;
}
.an-export-preview-body.is-sheet { overflow: hidden; padding: 0; }
.an-export-preview-status { margin: .75rem; color: #6e6262; font-size: .8125rem; }
.an-export-preview-frame { display: block; width: 100%; height: 100%; min-height: 20rem; border: 0; background: #fff; }
.an-doc-preview { max-width: 51rem; margin: 0 auto; }
.an-doc-ruler { height: 1.25rem; margin-bottom: .5rem; border: 1px solid #ddd;
    background: repeating-linear-gradient(to right, #f8f9fa 0, #f8f9fa 15px, #c7ccce 16px, #f8f9fa 17px, #f8f9fa 32px); }
.an-preview-document { box-sizing: border-box; width: 51rem; max-width: 100%; min-height: 78rem; margin: 0 auto; padding: 4.5rem 4rem; background: #fff;
    color: #302626; box-shadow: 0 3px 18px rgba(50, 20, 20, .16); font: 400 11pt/1.6 Arial, sans-serif; }
.an-preview-document h3 { margin: 0; color: #820707; font: 700 11pt/1.3 Arial, sans-serif; }
.an-preview-document h4 { margin: 0 0 .5rem; color: #630000; font: 700 11pt/1.3 Arial, sans-serif; }
.an-preview-document section { margin-top: 1.5rem; }
.an-preview-document .an-preview-subtitle { color: #6e6a6e; margin: .25rem 0 1.25rem; }
.an-preview-document p { text-align: justify; }
.an-preview-table-wrap { max-width: 100%; overflow-x: auto; }
.an-preview-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 11pt; }
.an-preview-table th, .an-preview-table td { padding: .45rem .55rem; text-align: left; vertical-align: top; border-bottom: 1px solid #e6d4d4; }
.an-preview-table th { position: relative; background: #820707; color: #fff; white-space: nowrap; }
.an-preview-figure { margin: 1rem auto; max-width: 45rem; text-align: center; }
.an-preview-figure img { display: block; width: 100%; background: #fff; }
html[data-mode="dark"] .an-export-preview img { filter: none !important; }
.an-preview-figure figcaption { margin-top: .4rem; color: #820707; font: 700 11pt Arial, sans-serif; }

/* Interactive layout for CSV, Excel and Word export previews. */
.an-sheet-preview { display: flex; flex-direction: column; height: 100%; min-height: 0;
    background: #fff; color: #202124; font: 400 .75rem/1.3 Arial, sans-serif; }
.an-sheet-formula { display: flex; align-items: center; gap: .75rem; flex: 0 0 auto;
    min-height: 2.25rem; border-bottom: 1px solid #dadce0; padding: .35rem .75rem; }
.an-sheet-reference { min-width: 3rem; border-right: 1px solid #dadce0; color: #5f6368; }
.an-sheet-fx { color: #5f6368; font-style: italic; font-weight: 700; }
/* The formula bar is the one place a cell's whole value is meant to be
   readable, so it wraps rather than ending in an ellipsis: cutting it here
   left no way at all to read a long title. The owner's rule, 23 November
   2026, which the grid below follows too. */
.an-sheet-value { overflow-wrap: anywhere; }
.an-sheet-pages { flex: 1 1 auto; min-height: 0; overflow: auto; }
.an-sheet-page[hidden] { display: none; }
.an-sheet-grid { width: max-content; border-collapse: separate; border-spacing: 0;
    table-layout: fixed; background: #fff; }
/* A cell shows everything it holds, over as many lines as that takes, so the
   preview says what the file will contain. `height` is a floor on a table
   cell and not a cap, so a short row is the same 1.9rem it always was and
   only a long value makes its row taller. */
.an-sheet-grid th, .an-sheet-grid td { height: 1.9rem; padding: .3rem .5rem;
    border-right: 1px solid #e0e3e7; border-bottom: 1px solid #e0e3e7;
    white-space: normal; overflow-wrap: anywhere; text-align: left;
    vertical-align: top; }
/* The column letters and row numbers are labels, not data: one line each. */
.an-sheet-grid thead th, .an-sheet-grid .an-sheet-row-number { white-space: nowrap; }
.an-sheet-grid thead th { position: sticky; top: 0; z-index: 2; background: #f8f9fa;
    color: #5f6368; text-align: center; font-weight: 500; }
.an-sheet-grid .an-sheet-corner { position: sticky; left: 0; z-index: 4; width: 3rem; }
.an-sheet-grid .an-sheet-row-number { position: sticky; left: 0; z-index: 1;
    width: 3rem; background: #f8f9fa; color: #5f6368; text-align: center; font-weight: 400; }
.an-sheet-grid td { width: 10.5rem; cursor: cell; position: relative; }
/* The right edge of every body cell is a column grip, not just the letter
   heading. One painted edge per cell avoids thousands of extra DOM buttons. */
.an-sheet-grid tbody td:not([colspan])::after {
    content: ''; position: absolute; top: 0; right: 0; bottom: 0;
    width: 9px; cursor: col-resize;
}
.an-sheet-grid tbody td.is-dragging { cursor: col-resize; }
.an-sheet-grid td.is-selected { box-shadow: inset 0 0 0 2px #1a73e8; }
.an-resize-handle { position: absolute; z-index: 5; display: block; border: 0; border-radius: 0;
    padding: 0; background: transparent; touch-action: none; }
.an-resize-handle:hover, .an-resize-handle:focus-visible, .an-resize-handle.is-dragging { background: #1a73e8; }
.an-resize-handle.is-column { top: 0; right: 0; bottom: 0; width: 9px; cursor: col-resize; }
.an-resize-handle.is-row { right: 0; bottom: 0; left: 0; height: 9px; cursor: row-resize; }
.an-preview-table .an-resize-handle.is-column:hover,
.an-preview-table .an-resize-handle.is-column:focus-visible,
.an-preview-table .an-resize-handle.is-column.is-dragging { background: #cbaaaa; }
body.an-preview-resizing { cursor: col-resize; user-select: none; }
body.an-preview-resizing.is-row { cursor: row-resize; }
.an-sheet-row-title td:first-of-type { color: #820707; font-size: .9375rem; font-weight: 700; }
.an-sheet-row-subtitle td:first-of-type { color: #6e6a6e; }
.an-sheet-row-section td { background: #fff5f5; color: #630000; font-weight: 700; }
.an-sheet-row-heading td { background: #820707; color: #fff; font-weight: 600; }
.an-sheet-tabs { display: flex; gap: .25rem; align-items: stretch; flex: 0 0 auto;
    min-height: 2.5rem; padding: 0 .75rem; border-top: 1px solid #dadce0; background: #f8f9fa; }
.an-sheet-tab { border: 0; border-bottom: 2px solid transparent; padding: .4rem .85rem;
    background: transparent; color: #5f6368; font: 500 .75rem/1.3 Arial, sans-serif; cursor: pointer; }
.an-sheet-tab.is-active { border-bottom-color: #820707; background: #fff; color: #820707; }
.an-sheet-tab:focus-visible { outline: 2px solid #820707; outline-offset: -2px; }
.an-sheet-figure { max-width: 42rem; margin: .75rem auto; text-align: center; }
.an-sheet-figure img { display: block; width: 100%; background: #fff; }
.an-sheet-figure figcaption { margin-top: .25rem; color: #5f6368; }

.an-head .an-actions { margin-left: auto; display: flex; gap: .5rem; flex-wrap: wrap; }

/* ---- Filters ---- */
.an-filters {
    background: var(--cream); border-radius: var(--r-card, 8px);
    padding: .75rem .875rem; margin-bottom: 1.125rem;
}
.an-filters.is-loading { opacity: .65; }
.an-filter-error { margin: .625rem 0 0; color: var(--maroon); font-size: .75rem; }
.an-filter-row { display: flex; flex-wrap: wrap; gap: .625rem; align-items: flex-start; }
.an-filter { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.an-filter label {
    font-size: .625rem; text-transform: uppercase; letter-spacing: .04em; color: var(--grey);
}
.an-filter select, .an-filter input {
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .4rem .6rem; font-family: var(--font-body); font-size: .8125rem;
    color: var(--ink); background: var(--white); min-width: 9rem;
}
.an-filter select {
    appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 2rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right .75rem center;
}
.an-filter input:focus, .an-filter select:focus { border-color: var(--maroon); outline: none; }
.an-filter-search { flex: 1 1 14rem; }
.an-filter-search input { box-sizing: border-box; min-width: 14rem; width: 100%; }
.an-filter-help { color: var(--grey); font-size: .6875rem; }
.an-range-bounds { display: flex; gap: .625rem; flex-wrap: wrap; }
.an-range-bounds[hidden] { display: none; }
.an-range-bounds .an-filter input { min-width: 10rem; }
/* What is currently narrowing the page, and how to undo it. */
.an-active {
    display: flex; flex-wrap: wrap; align-items: center; gap: .375rem;
    margin-top: .625rem; padding-top: .625rem; border-top: 1px solid var(--border);
    font-size: .6875rem; color: var(--grey);
}
.an-chip {
    display: inline-flex; align-items: center; gap: .25rem;
    background: var(--white); border: 1px solid var(--maroon); border-radius: var(--r-control, 4px);
    padding: .15rem .5rem; font-size: .6875rem; color: var(--maroon);
}
.an-chip a { color: var(--maroon); text-decoration: none; font-weight: 600; line-height: 1; }
.an-clear { margin-left: auto; color: var(--maroon); font-size: .6875rem; }

/* ---- Headline figures ---- */
.an-tiles {
    display: grid; gap: .75rem; margin-bottom: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
}
.an-tile { min-width: 0; background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px); padding: 1rem; overflow: hidden; }
.an-tile-label {
    font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--grey); display: block; margin-bottom: .25rem;
}
.an-tile-value {
    font-family: var(--font-head); font-size: 1.625rem; font-weight: 600;
    color: var(--ink); line-height: 1.15; font-variant-numeric: tabular-nums;
    display: block; overflow-wrap: anywhere;
}
.an-tile-note { display: block; font-size: .6875rem; color: var(--grey); margin-top: .35rem; overflow-wrap: anywhere; }
.an-tile-note.is-up { color: var(--ok-text); }
.an-tile-note.is-down { color: var(--bad-text); }

/* ---- Cards ---- */
.an-card { min-width: 0; background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px); margin-bottom: 1rem; overflow: hidden; }
.an-card-head {
    display: flex; align-items: center; gap: .5rem; min-width: 0; flex-wrap: wrap;
    padding: .8rem 1.125rem; border-bottom: 1px solid var(--border);
    font-family: var(--font-head); font-size: .875rem; font-weight: 600; color: var(--ink);
    overflow-wrap: anywhere;
}
.an-card-head .material-symbols-outlined { font-size: 18px; }
.an-card-head .an-hint { margin-left: auto; font-family: var(--font-body); font-size: .6875rem; color: var(--grey); font-weight: 400; }
.an-card-toggle {
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto; margin-left: auto; padding: .15rem;
    border: 0; border-radius: var(--r-control, 4px);
    background: transparent; color: var(--maroon); cursor: pointer;
}
.an-card-toggle:hover { background: var(--cream); }
.an-card-toggle:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.an-card-toggle .material-symbols-outlined { font-size: 20px; transition: transform .18s ease; }
.an-card-head .an-hint + .an-card-toggle { margin-left: 0; }
.an-card[data-collapsed="1"] > .an-card-head { border-bottom: 0; }
.an-card[data-collapsed="1"] > .an-card-head .an-card-toggle .material-symbols-outlined { transform: rotate(-90deg); }
.an-card[data-collapsed="1"] > .an-card-content { display: none; }
.an-card-body { min-width: 0; padding: 1.125rem; }

.an-ai-body { display: flex; gap: .75rem; align-items: flex-start; }
.an-ai-body .material-symbols-outlined { color: var(--maroon); font-size: 20px; flex: 0 0 auto; }
.an-ai-text { max-width: 75ch; font-size: .875rem; color: var(--ink); line-height: 1.7; text-align: left; overflow-wrap: anywhere; }
.an-insight-content { flex: 1; min-width: 0; }
.an-chart-insight { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.an-insight-controls { display: flex; flex-wrap: wrap; align-items: center; gap: .65rem 1rem; margin-top: 1rem; }
.an-insight-controls small { color: var(--grey); font-size: .75rem; line-height: 1.5; }
.an-insight-controls .an-new-insight { flex: 0 0 auto; padding: .4rem .75rem; }
.an-insight-status:empty { display: none; }
.an-overall-insight { margin-bottom: 1.25rem; }
.an-overall-insight .an-chart-insight { margin: 0; padding: 0; border: 0; }
.an-overall-insight .an-ai-text { max-width: 90ch; }
.an-ai-text p { margin: 0; }
.an-ai-text p + p { margin-top: .75rem; }
.an-ai-text ol, .an-ai-text ul { margin: 0; padding-left: 1.4rem; }
.an-ai-text li + li { margin-top: .65rem; }
.an-ai-empty { font-size: .8125rem; color: var(--grey); }
.an-ai-card .an-ai-loading, .an-ai-card .an-ai-result { display: flex; gap: .75rem; align-items: flex-start; }
.an-ai-card .an-ai-loading { color: var(--grey); font-size: .8125rem; }
.an-ai-card .an-ai-result > .material-symbols-outlined { color: var(--maroon); font-size: 20px; flex: 0 0 auto; }
.an-ai-card [hidden] { display: none; }
.an-ai-spin { animation: an-ai-rotate 1s linear infinite; }
@keyframes an-ai-rotate { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .an-ai-spin { animation: none; } }

/* ---- Charts ---- */
.an-grid { display: grid; gap: 1rem; align-items: start; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.an-chart { position: relative; min-width: 0; height: 16rem; }
.an-chart canvas { max-width: 100%; }
.an-chart-card { box-shadow: 0 1px 3px rgba(51, 0, 0, .04); }
.an-chart-card .an-card-head { min-height: 3.25rem; background: var(--cream); }
.an-figure-number { display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto; min-width: 1.7rem; height: 1.7rem; padding: 0 .35rem; border-radius: 5px;
    background: var(--white); border: 1px solid var(--border); color: var(--maroon); font: 700 .75rem/1 Arial, sans-serif; }
.an-figure-caption { margin: .65rem 0 0; padding-top: .65rem; border-top: 1px solid var(--border);
    color: var(--grey); font-size: .75rem; line-height: 1.45; }
.an-expandable { cursor: zoom-in; }

/* ---- Choosing how a figure is drawn ----
   The button sits in the card's head beside the fold; its menu is one fixed
   element on <body> (the card clips its overflow), at 910 with the column help
   popover: above the navbar's 900 to 903, under the enlarged view's 20000. */
.an-view-btn {
    display: inline-flex; align-items: center; gap: .3rem; flex: 0 0 auto; margin-left: auto;
    padding: .25rem .4rem .25rem .5rem; border: 1px solid var(--border); border-radius: 5px;
    background: var(--white); color: var(--maroon); cursor: pointer;
    font: 600 .75rem/1.2 var(--font-body);
}
.an-view-btn:hover { border-color: var(--soft-maroon); }
.an-view-btn:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.an-view-btn .material-symbols-outlined { font-size: 16px; }
.an-view-btn .an-view-caret { font-size: 16px; color: var(--grey); }
.an-card-head .an-hint ~ .an-view-btn, .an-view-btn + .an-card-toggle { margin-left: 0; }
.an-view-menu {
    position: fixed; z-index: 910; width: min(21rem, calc(100vw - 1rem)); max-height: min(28rem, calc(100vh - 1rem));
    overflow: auto; overscroll-behavior: contain; padding: .35rem;
    border: 1px solid var(--border); border-radius: 5px; background: var(--white); color: var(--ink);
    box-shadow: 0 8px 24px rgba(51, 0, 0, .18);
}
.an-view-menu[hidden] { display: none; }
.an-view-menu-head { padding: .4rem .55rem .5rem; font: 600 .6875rem/1.3 var(--font-body); color: var(--grey);
    text-transform: uppercase; letter-spacing: .03em; }
.an-view-item {
    display: flex; align-items: flex-start; gap: .55rem; width: 100%; padding: .45rem .55rem;
    border: 0; border-radius: 5px; background: transparent; color: var(--ink); text-align: left; cursor: pointer;
    font: 500 .8125rem/1.35 var(--font-body);
}
.an-view-item:hover, .an-view-item:focus-visible { background: var(--cream); outline: none; }
.an-view-item[aria-checked="true"] { color: var(--maroon); font-weight: 600; }
.an-view-item > .material-symbols-outlined { font-size: 18px; color: var(--maroon); flex: 0 0 auto; margin-top: .05rem; }
.an-view-text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
.an-view-tag { flex: 0 0 auto; padding: .05rem .35rem; border: 1px solid var(--border); border-radius: 5px;
    font-size: .625rem; font-weight: 600; color: var(--grey); }
.an-view-tick { font-size: 18px; color: var(--maroon); flex: 0 0 auto; }
/* A figure drawn as a table fills the chart's own box and scrolls inside it. */
.an-view-tablebox { position: absolute; inset: 0; overflow: auto; }
.an-view-tablebox[hidden] { display: none; }
.an-view-table { width: 100%; }
.an-view-table .is-num { text-align: right; white-space: nowrap; }
.an-view-table .is-total td { font-weight: 600; border-top: 1px solid var(--border); }
.an-empty { padding: 2.5rem 1rem; text-align: center; color: var(--grey); font-size: .8125rem; }
.an-empty .material-symbols-outlined { font-size: 32px; display: block; margin: 0 auto .5rem; opacity: .5; }

/* ---- Tables ---- */
.an-table-glide { position: relative; min-width: 0; }
.an-table-glide::before, .an-table-glide::after {
    position: absolute; top: 0; z-index: 1;
    display: flex; align-items: center; width: 2rem; height: 2.25rem;
    font-family: 'Material Symbols Outlined'; font-size: 20px; line-height: 1;
    color: var(--maroon); pointer-events: none; opacity: 0;
    transition: opacity .18s ease;
}
.an-table-glide::before {
    content: '\e5cb'; left: 0; justify-content: flex-start; padding-left: .25rem;
    background: linear-gradient(to left, transparent, var(--cream) 60%);
}
.an-table-glide::after {
    content: '\e5cc'; right: 0; justify-content: flex-end; padding-right: .25rem;
    background: linear-gradient(to right, transparent, var(--cream) 60%);
}
.an-table-glide.can-left::before, .an-table-glide.can-right::after { opacity: 1; }
.an-scroll { max-height: 22rem; overflow: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
.an-submissions .an-scroll { max-height: 28rem; }
.an-table { width: 100%; border-collapse: collapse; font-size: .8125rem; color: var(--ink); }
.an-table th {
    position: sticky; top: 0; z-index: 2; text-align: left; white-space: nowrap; padding: .6rem .75rem;
    background: var(--cream); font-size: .6875rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .04em; color: var(--grey);
    border-bottom: 1px solid var(--border);
}
.an-table td { padding: .65rem .75rem; border-bottom: 1px solid var(--border); vertical-align: top; overflow-wrap: anywhere; }
.an-table tbody tr:last-child td { border-bottom: none; }
.an-table tbody tr:hover { background: var(--cream); }
.an-submissions.is-loading .an-table tbody { opacity: .55; }
.an-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.an-table--programs th, .an-table--programs td {
    text-align: center;
    vertical-align: middle;
}
.an-strong { color: var(--maroon); font-weight: 500; }
.an-bar { display: block; height: 4px; border-radius: var(--r-control, 4px); background: var(--cream); margin-top: .3rem; }
.an-bar span { display: block; height: 100%; border-radius: var(--r-control, 4px); background: var(--maroon); }

/* A heading you can sort by. */
.an-sort { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: .25rem; }
.an-sort:hover { color: var(--maroon); }
.an-sort.is-on { color: var(--maroon); }
.an-sort-dir { font-size: .75rem; }
.an-col-help-popover {
    position: fixed; z-index: 910; max-width: min(18rem, calc(100vw - 1.5rem));
    padding: .55rem .7rem; border: 1px solid var(--border);
    border-radius: var(--r-card, 8px); background: var(--white); color: var(--ink);
    box-shadow: 0 8px 24px rgba(51, 0, 0, .18);
    font: 400 .75rem/1.45 var(--font-body); text-align: left;
    text-transform: none; letter-spacing: 0; white-space: normal;
    pointer-events: none;
}
.an-title { font-weight: 500; color: var(--ink); }
.an-title[href] { color: var(--maroon); text-decoration: underline; text-underline-offset: 2px; }
.an-title[href]:hover, .an-title[href]:focus-visible { text-decoration-thickness: 2px; }
.an-title[href]:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.an-sub { display: block; font-size: .6875rem; color: var(--grey); margin-top: .15rem; }
.an-when { white-space: nowrap; color: var(--grey); font-size: .75rem; }
.an-status {
    display: inline-block; padding: .1rem .45rem; border-radius: var(--r-badge, 2px);
    background: var(--cream); color: var(--maroon);
    font-size: .625rem; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap;
}
.an-status.is-approved { background: var(--ok-bg); color: var(--ok-text); }
.an-status.is-returned { background: var(--bad-bg); color: var(--bad-text); }

/* ---- Paging ---- */
.an-paging {
    display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
    padding: .75rem 1.125rem; border-top: 1px solid var(--border);
    font-size: .75rem; color: var(--grey);
}
.an-paging .an-pages { margin-left: auto; display: flex; gap: .375rem; align-items: center; }
.an-page {
    border: 1px solid var(--border); border-radius: var(--r-control, 4px); background: var(--white);
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
    border-radius: var(--r-card, 8px); box-shadow: 0 18px 48px rgba(51, 0, 0, .28);
    overflow: hidden; display: flex; flex-direction: column; max-height: calc(100vh - 3rem);
}
.an-modal-head { display: flex; align-items: center; gap: .5rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--maroon); }
.an-modal-head h2 { font-family: var(--font-head); font-size: 1rem; font-weight: 500; color: var(--maroon); margin: 0; flex: 1 1 auto; }
.an-modal-close { border: none; background: none; cursor: pointer; padding: .15rem; color: var(--grey); border-radius: var(--r-card, 8px); display: inline-flex; }
.an-modal-close:hover { background: var(--cream); color: var(--maroon); }
.an-modal-panel:focus { outline: none; }
.an-modal-body { padding: 1.25rem; overflow: auto; }
.an-modal-body .an-chart { height: min(60vh, 26rem); }
/* Clear reading surfaces and a distinct control footer for every AI insight. */
.an-grid { gap: 1.5rem; }
.an-chart-card { box-shadow: 0 5px 18px rgba(51,0,0,.055); }
.an-card-head { padding: 1rem 1.15rem; gap: .65rem; }
.an-card-body { padding: 1.25rem; }
.an-chart { height: 20rem; }
.an-figure-caption { line-height: 1.65; margin-top: 1rem; }
.an-chart-insight.an-ai-body { display: block; padding: 0; margin-top: 1.25rem; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: var(--white); }
.an-insight-heading { display: flex; align-items: center; gap: .5rem; padding: .75rem 1rem; background: var(--cream); border-bottom: 1px solid var(--border); font-size: .8rem; letter-spacing: .02em; }
.an-insight-content .an-ai-text { padding: 1rem 1.15rem; font-size: .9rem; line-height: 1.85; max-width: 85ch; }
.an-insight-content .an-ai-text p + p { margin-top: .85rem; }
.an-insight-controls { margin: 0; padding: .85rem 1rem; border-top: 1px solid var(--border); background: var(--cream); gap: .75rem; }
.an-insight-controls > small:first-child { flex: 1 1 160px; }
.an-insight-controls .an-new-insight { border: 1px solid var(--maroon); color: var(--maroon); background: var(--white); font-weight: 600; border-radius: 6px; padding: .5rem .8rem; }
.an-insight-controls .an-new-insight:hover { background: var(--maroon); color: var(--white); }
.an-insight-status { flex-basis: 100%; }
.an-overall-insight .an-chart-insight { border: 1px solid var(--border); }
.an-overall-insight .an-insight-heading { display: none; }
.an-field-scroll { height: 20rem; overflow-y: auto; scrollbar-width: thin; }
@media(max-width: 600px) { .an-card-body { padding: .9rem; } .an-chart { height: 18rem; } }


/* Analytics controls share a restrained five-pixel radius. */
.an-filters, .an-filter select, .an-filter input, .an-chip,
.an-card-toggle, .an-figure-number, .an-bar, .an-bar span,
.an-status, .an-page, .an-col-help-popover, .an-modal-panel,
.an-modal-close, .an-export-menu, .an-export-preview-panel,
.an-export-preview-foot button { border-radius: 5px; }
.an-card-head .an-hint { min-width: 0; overflow-wrap: anywhere; }
.an-table--submissions { min-width: 58rem; }
.an-table--submissions td:first-child { min-width: 14rem; max-width: 22rem; }
.an-table--submissions td:nth-child(2) { min-width: 9rem; max-width: 14rem; }
.an-table--submissions td:nth-child(4) { min-width: 9rem; max-width: 14rem; }
@media (max-width: 900px) {
    .an-grid { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 700px) {
    .an-export-preview { padding: 0; }
    .an-export-preview-panel { width: 100%; height: 100dvh; border-radius: 0; }
    .an-export-preview-body { padding: .75rem; }
    .an-preview-document { padding: 1rem; }
    .an-wrap { padding: 1rem .75rem 2.5rem; }
    .an-head { gap: .75rem; }
    .an-head .an-actions { margin-left: 0; width: 100%; }
    .an-export-menu { left: 0; right: auto; min-width: min(17rem, calc(100vw - 1.5rem)); }
    .an-filter select, .an-filter input, .an-filter-search input { min-width: 0; width: 100%; }
    .an-filter { flex: 1 1 100%; }
    .an-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .625rem; }
    .an-tile { min-width: 0; padding: .75rem; }
    .an-tile-value { font-size: 1.4rem; }
    .an-grid { grid-template-columns: minmax(0, 1fr); gap: .75rem; }
    .an-card { min-width: 0; margin-bottom: .75rem; }
    .an-card-head { flex-wrap: wrap; padding: .75rem; }
    .an-card-head .an-hint { order: 3; flex-basis: 100%; margin-left: 0; }
    .an-card-head .an-view-btn { order: 2; margin-left: auto; }
    .an-card-head .an-card-toggle, .an-card-head .an-hint + .an-card-toggle { order: 2; margin-left: auto; }
    .an-card-head .an-view-btn + .an-card-toggle { margin-left: 0; }
    .an-card-body { padding: .75rem; }
    .an-table--programs { min-width: 34rem; }
    .an-table--submissions { min-width: 58rem; }
    .an-paging { padding: .75rem; }
}
@media (prefers-reduced-motion: reduce) {
    .an-table-glide::before, .an-table-glide::after { transition: none; }
}

/* ---- Analytics level sections ---------------------------------------------- */
.an-tabs {
    display: flex; align-items: flex-end; gap: .125rem;
    height: 38px; margin: 1.25rem 0 0; padding: 0 .25rem;
    border-radius: var(--r-card, 8px) var(--r-card, 8px) 0 0;
    background: var(--cream); position: relative;
}
.an-tabs[hidden], .an-tab-panel[hidden] { display: none; }
.an-tab {
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: .45rem;
    min-height: 38px; margin-bottom: -2px; padding: .4rem .65rem calc(.4rem + 2px);
    border: 1px solid transparent; border-bottom: 0;
    border-radius: var(--r-card, 8px) var(--r-card, 8px) 0 0;
    background: linear-gradient(to bottom, rgba(177,125,125,.08) calc(100% - 2px), transparent calc(100% - 2px));
    color: var(--grey); cursor: pointer;
    font: 400 .8125rem/1.3 var(--font-body); white-space: nowrap;
}
.an-tab .material-symbols-outlined { font-size: 1.1rem; }
.an-tab:hover { background: linear-gradient(to bottom, rgba(177,125,125,.16) calc(100% - 2px), transparent calc(100% - 2px)); color: var(--ink); }
.an-tab[aria-selected="true"], .an-tab[aria-selected="true"]:hover {
    position: relative; z-index: 1; background: var(--white); color: var(--ink);
}
.an-tab[aria-selected="true"]::before, .an-tab[aria-selected="true"]::after {
    content: ''; position: absolute; bottom: 0; width: 8.5px; height: 9.8px; pointer-events: none;
}
.an-tab[aria-selected="true"]::before { right: 100%; background: radial-gradient(circle at 0 0, transparent 7px, var(--white) 8px); }
.an-tab[aria-selected="true"]::after { left: 100%; background: radial-gradient(circle at 100% 0, transparent 7px, var(--white) 8px); }
.an-tab:first-child[aria-selected="true"]::before,
.an-tab + .an-tab[aria-selected="true"]::before,
.an-tab[aria-selected="true"]:has(+ .an-tab)::after { display: none; }
.an-tab:focus-visible { outline: 2px solid var(--maroon); outline-offset: -2px; }
.an-tab-panel { --an-accent: var(--maroon-surface); padding: 1rem; background: var(--white); border-radius: 0 0 var(--r-card, 8px) var(--r-card, 8px); }
.an-tab-panel > .an-section { margin-top: 0; }
.an-tab-panel > .an-grid:first-child { margin-top: 0; }
#anPanelPredictive { --an-accent: #0d7377; }
#anPanelPrescriptive { --an-accent: #2e7d32; }
#anPanelTables { --an-accent: #315a86; }
.an-tab-panel .an-card {
    border-top: 3px solid var(--an-accent);
    box-shadow: 0 2px 8px rgba(51, 0, 0, .06);
}
.an-tab-panel .an-card-head {
    background: color-mix(in srgb, var(--an-accent) 9%, var(--white));
    border-bottom-color: color-mix(in srgb, var(--an-accent) 20%, var(--border));
}
.an-tab-panel .an-card-head .material-symbols-outlined,
.an-tab-panel .an-figure-number { color: color-mix(in srgb, var(--an-accent) 65%, var(--ink)); }
.an-tab-panel .an-figure-number { border-color: color-mix(in srgb, var(--an-accent) 35%, var(--border)); }
.an-tab-panel .an-card-toggle, .an-tab-panel .an-view-btn { color: color-mix(in srgb, var(--an-accent) 65%, var(--ink)); }
.an-tab-panel .an-card-toggle:hover { background: color-mix(in srgb, var(--an-accent) 12%, var(--white)); }
.an-tab-panel .an-ai-result > .material-symbols-outlined { color: color-mix(in srgb, var(--an-accent) 65%, var(--ink)); }
.an-tab-panel .an-table th { background: color-mix(in srgb, var(--an-accent) 10%, var(--white)); color: var(--ink); }
.an-tab-panel .an-table tbody tr:nth-child(even) { background: color-mix(in srgb, var(--an-accent) 3%, var(--white)); }
.an-tab-panel .an-table tbody tr:hover { background: color-mix(in srgb, var(--an-accent) 10%, var(--white)); }
.an-grid--tables { grid-template-columns: minmax(0, 1fr); }
#anPanelOverview .an-tile { border-top: 3px solid var(--an-tile-accent, var(--maroon-surface)); box-shadow: 0 2px 8px rgba(51, 0, 0, .05); }
#anPanelOverview .an-tile-value { color: color-mix(in srgb, var(--an-tile-accent, var(--maroon-surface)) 65%, var(--ink)); }
#anPanelOverview .an-tile:nth-child(1) { --an-tile-accent: var(--maroon-surface); }
#anPanelOverview .an-tile:nth-child(2) { --an-tile-accent: #2e7d32; }
#anPanelOverview .an-tile:nth-child(3) { --an-tile-accent: #0d7377; }
#anPanelOverview .an-tile:nth-child(4) { --an-tile-accent: #9c3e20; }
#anPanelOverview .an-tile:nth-child(5) { --an-tile-accent: #315a86; }
#anPanelOverview .an-tile:nth-child(6) { --an-tile-accent: #6d3d83; }
#anPanelOverview .an-chart-card:nth-child(2) { --an-accent: #315a86; }
#anPanelOverview .an-chart-card:nth-child(3) { --an-accent: #2e7d32; }
#anPanelOverview .an-chart-card:nth-child(4) { --an-accent: #6d3d83; }
#anPanelOverview .an-chart-card:nth-child(5) { --an-accent: #0d7377; }
#anPanelOverview .an-chart-card:nth-child(6) { --an-accent: #9c3e20; }
@media (max-width: 700px) {
    .an-tabs { overflow-x: auto; overflow-y: hidden; scrollbar-width: thin; }
    .an-tab[aria-selected="true"]::before, .an-tab[aria-selected="true"]::after { display: none; }
    .an-tab-panel { padding: .75rem; }
}
.an-section { margin: 1.5rem 0 0; }
.an-section-head {
    display: flex; align-items: center; gap: .75rem;
    padding: .625rem 1rem; border-radius: .625rem .625rem 0 0;
    font: 600 1rem/1.4 var(--font-head); color: #fff;
}
.an-section-head .mi { font-size: 1.25rem; }
.an-section-head small { font-weight: 400; opacity: .85; font-size: .8125rem; margin-left: auto; }
.an-section-body { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1rem; padding: 1rem 0 0; }

/* Descriptive — maroon */
.an-section--desc .an-section-head { background: var(--an-accent); }
/* Predictive — teal/blue */
.an-section--pred .an-section-head { background: var(--an-accent); }
/* Prescriptive — green/amber */
.an-section--pres .an-section-head { background: var(--an-accent); }

/* Metric mini-cards inside sections */
.an-metric {
    background: var(--white); border: 1px solid var(--border); border-radius: .5rem;
    padding: .875rem 1rem; display: flex; flex-direction: column; gap: .25rem;
}
.an-metric-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--grey); font-weight: 600; }
.an-metric-value { font-size: 1.5rem; font-weight: 700; color: var(--ink); line-height: 1.2; }
.an-metric-sub { font-size: .75rem; color: var(--grey); }

.an-desc-metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: .75rem; margin-bottom: 1rem; }

/* Colour-coded metric highlights */
.an-metric--ok { border-left: 4px solid var(--ok-text); }
.an-metric--ok .an-metric-value { color: var(--ok-text); }
.an-metric--warn { border-left: 4px solid var(--warn-text); }
.an-metric--warn .an-metric-value { color: var(--warn-text); }
.an-metric--bad { border-left: 4px solid var(--bad-text); }
.an-metric--bad .an-metric-value { color: var(--bad-text); }
.an-metric--accent { border-left: 4px solid var(--maroon-surface); }
.an-metric--accent .an-metric-value { color: var(--maroon-surface); }
.an-metric--teal { border-left: 4px solid #0d7377; }
.an-metric--teal .an-metric-value { color: color-mix(in srgb, #0d7377 65%, var(--ink)); }

/* Recommendation cards (prescriptive) */
.an-rec { display: flex; gap: .75rem; padding: .875rem 1rem; border-radius: .5rem;
           background: var(--white); border: 1px solid var(--border); align-items: flex-start; }
.an-rec + .an-rec { margin-top: .5rem; }
.an-rec-icon { flex-shrink: 0; width: 2.25rem; height: 2.25rem; border-radius: 50%;
               display: grid; place-items: center; }
.an-rec-icon .mi { font-size: 1.125rem; color: #fff; }
.an-rec--high .an-rec-icon { background: var(--bad-text); }
.an-rec--medium .an-rec-icon { background: var(--warn-text); }
.an-rec--low .an-rec-icon { background: var(--ok-text); }
.an-rec-body { flex: 1; min-width: 0; }
.an-rec-title { font-weight: 600; font-size: .875rem; color: var(--ink); margin-bottom: .125rem; }
.an-rec-text { font-size: .8125rem; color: var(--grey); line-height: 1.5; }

/* Overdue table */
.an-overdue { width: 100%; border-collapse: collapse; font-size: .8125rem; }
.an-overdue th { text-align: left; padding: .5rem .625rem; font-weight: 600; color: var(--grey);
                 border-bottom: 2px solid var(--border); font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; }
.an-overdue td { padding: .5rem .625rem; border-bottom: 1px solid var(--border); color: var(--ink); }
.an-overdue .an-days-bad { color: var(--bad-text); font-weight: 700; }
.an-overdue .an-days-warn { color: var(--warn-text); font-weight: 600; }

/* Full-width card inside a section */
.an-section-body .an-card-full { grid-column: 1 / -1; }

@media (max-width: 900px) {
    .an-section-body { grid-template-columns: minmax(0,1fr); }
}
@media (max-width: 700px) {
    .an-section-head { font-size: .875rem; padding: .5rem .75rem; }
    .an-section-head small { display: none; }
    .an-metric-value { font-size: 1.25rem; }
    .an-rec { padding: .75rem; }
}
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assests/css/analytics-word-editor.css?v=3">
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
            <div class="an-heading-line">
                <h1>Analytics</h1>
                <span class="an-ai-badge"><span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span> AI-powered insights</span>
            </div>
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
            <div class="an-export" id="anExport">
                <button type="button" class="btn-sm-outline" id="anExportBtn"
                        aria-haspopup="menu" aria-expanded="false">
                    <span class="material-symbols-outlined mi-18">download</span>
                    Export
                    <span class="material-symbols-outlined mi-18">expand_more</span>
                </button>
                <div class="an-export-menu" id="anExportMenu" role="menu" hidden>
                    <?php /* CSV keeps a working download link if JavaScript is unavailable.
                             With JavaScript, each choice opens its preview first. */ ?>
                    <a role="menuitem" data-export="csv" href="<?= e($qs(['export' => 'csv'])) ?>">
                        <span class="material-symbols-outlined mi-18">table_view</span>
                        <span><strong>CSV</strong><small>Plain table, opens anywhere</small></span>
                    </a>
                    <button type="button" role="menuitem" data-export="xlsx">
                        <span class="material-symbols-outlined mi-18">grid_on</span>
                        <span><strong>Excel</strong><small>Styled sheets, with the charts</small></span>
                    </button>
                    <button type="button" role="menuitem" data-export="docx">
                        <span class="material-symbols-outlined mi-18">description</span>
                        <span><strong>Word</strong><small>A written report, with the charts</small></span>
                    </button>
                    <button type="button" role="menuitem" data-export="pdf">
                        <span class="material-symbols-outlined mi-18">picture_as_pdf</span>
                        <span><strong>PDF</strong><small>Ready to print or hand in</small></span>
                    </button>
                </div>
            </div>
            <form method="post" id="anExportForm" data-no-pill hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="export" id="anExportFormat" value="">
            </form>
        </div>
    </div>

    <!-- ---- Filters ---- -->
    <form class="an-filters" id="anFilters" method="get">
        <div class="an-filter-row">
            <div class="an-filter">
                <label for="fRange">Time range</label>
                <select name="range" id="fRange">
                    <option value="all" <?= $fRange === 'all' ? 'selected' : '' ?>>All time</option>
                    <option value="year" <?= $fRange === 'year' ? 'selected' : '' ?>>Years</option>
                    <option value="month" <?= $fRange === 'month' ? 'selected' : '' ?>>Months</option>
                    <option value="date" <?= $fRange === 'date' ? 'selected' : '' ?>>Dates</option>
                    <option value="datetime" <?= $fRange === 'datetime' ? 'selected' : '' ?>>Date and time</option>
                </select>
                <span class="an-filter-help">Based on submission time</span>
            </div>
            <div class="an-range-bounds" id="anRangeBounds" <?= $fRange === 'all' ? 'hidden' : '' ?>>
                <div class="an-filter">
                    <label for="fFrom">From</label>
                    <input name="from" id="fFrom" type="<?= e($rangeInputType) ?>" value="<?= e($fFrom) ?>"
                           <?= $fRange === 'year' ? 'min="1900" max="9998"' : '' ?> aria-label="Time range start">
                </div>
                <div class="an-filter">
                    <label for="fTo">To</label>
                    <input name="to" id="fTo" type="<?= e($rangeInputType) ?>" value="<?= e($fTo) ?>"
                           <?= $fRange === 'year' ? 'min="1900" max="9998"' : '' ?> aria-label="Time range end">
                </div>
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
                        <option value="<?= e($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= e(paper_type_label($t)) ?></option>
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
        </div>
        <?php if ($rangeError): ?><p class="an-filter-error an-range-error" role="alert"><?= e($rangeError) ?></p><?php endif; ?>

        <?php if ($filtered): ?>
            <div class="an-active">
                <span>Showing</span>
                <?php
                $chips = [];
                if ($timeFiltered) { $chips['time'] = ucfirst($fRange) . ': ' . ($fFrom ?: 'earliest') . ' to ' . ($fTo ?: 'latest'); }
                if ($fProgram !== '') { $chips['program'] = function_exists('program_code') ? program_code($fProgram) : $fProgram; }
                if ($fType !== '')    { $chips['type']    = $fType; }
                if ($fStatus !== '')  { $chips['status']  = ucwords(str_replace('_', ' ', $fStatus)); }
                if ($fQuery !== '')   { $chips['q']       = '“' . $fQuery . '”'; }
                ?>
                <?php foreach ($chips as $key => $label): ?>
                    <span class="an-chip"><?= e($label) ?>
                        <a href="<?= e($key === 'time' ? $qs(['range' => '', 'from' => '', 'to' => '', 'page' => 1]) : $qs([$key => '', 'page' => 1])) ?>" title="Remove">&times;</a>
                    </span>
                <?php endforeach; ?>
                <a class="an-clear" href="analytics_dashboard.php">Clear all</a>
            </div>
        <?php endif; ?>
    </form>

    <div id="anResults">
    <script type="application/json" id="anChartData"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <div class="an-tabs" role="tablist" aria-label="Analytics statistics" hidden>
        <button type="button" class="an-tab" role="tab" id="anTabOverview" aria-controls="anPanelOverview" aria-selected="true" tabindex="0" data-an-tab="overview"><span class="material-symbols-outlined" aria-hidden="true">dashboard</span> Overview</button>
        <button type="button" class="an-tab" role="tab" id="anTabPrescriptive" aria-controls="anPanelPrescriptive" aria-selected="false" tabindex="-1" data-an-tab="prescriptive"><span class="material-symbols-outlined" aria-hidden="true">lightbulb</span> Review queue</button>
        <button type="button" class="an-tab" role="tab" id="anTabTables" aria-controls="anPanelTables" aria-selected="false" tabindex="-1" data-an-tab="tables"><span class="material-symbols-outlined" aria-hidden="true">table_chart</span> Data tables</button>
    </div>

    <div class="an-tab-panel" role="tabpanel" id="anPanelOverview" aria-labelledby="anTabOverview" data-an-tab-panel="overview">

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
            <span class="an-tile-label">Average time to approval</span>
            <span class="an-tile-value"><?= $avgDays !== null ? e($avgDays) : 'N/A' ?></span>
            <span class="an-tile-note"><?= $avgDays !== null ? 'days' : 'not recorded yet' ?></span>
        </div>
    </div>

    <?php $renderOverallInsight('overall-overview'); ?>

    <!-- ---- Charts ---- -->
    <div class="an-grid">
        <div class="an-card an-chart-card" data-an-card="timeline">
            <div class="an-card-head">
                <span class="an-figure-number" aria-label="Figure 1">01</span>
                Submissions by <?= e($timelineUnitLabel) ?>
                <span class="an-hint"><?= $timeFiltered ? e(($fFrom ?: 'earliest') . ' to ' . ($fTo ?: 'latest')) : 'all time' ?></span>
            </div>
            <div class="an-card-body">
                <?php if ($timelineData): ?>
                    <div class="an-chart an-expandable" data-chart="timeline" title="Click to enlarge">
                        <canvas id="timelineChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">show_chart</span>Nothing to plot.</div>
                <?php endif; ?>
                <p class="an-figure-caption">Figure 1. Submissions over time</p>
                <?php $renderChartInsight('timeline'); ?>
            </div>
        </div>

        <div class="an-card an-chart-card" data-an-card="monthly">
            <div class="an-card-head"><span class="an-figure-number" aria-label="Figure 2">02</span> Submissions per month
                <span class="an-hint"><?= $timeFiltered ? 'selected period' : 'all time' ?></span>
            </div>
            <div class="an-card-body">
                <?php if ($monthlyData): ?>
                    <div class="an-chart an-expandable" data-chart="monthly" title="Click to enlarge">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">bar_chart</span>No submissions in this selection.</div>
                <?php endif; ?>
                <p class="an-figure-caption">Figure 2. Submitted papers per calendar month; months include the year.</p>
                <?php $renderChartInsight('monthly'); ?>
            </div>
        </div>

        <div class="an-card an-chart-card" data-an-card="approval">
            <div class="an-card-head"><span class="an-figure-number" aria-label="Figure 3">03</span> Approval rate by paper type</div>
            <div class="an-card-body">
                <?php if ($approvalByType): ?>
                    <div class="an-chart an-expandable" data-chart="approval" title="Click to enlarge">
                        <canvas id="approvalRateChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">check_circle</span>Nothing to compare.</div>
                <?php endif; ?>
                <p class="an-figure-caption">Figure 3. Percentage approved by paper type</p>
                <?php $renderChartInsight('approval'); ?>
            </div>
        </div>

        <div class="an-card an-chart-card" data-an-card="types">
            <div class="an-card-head"><span class="an-figure-number" aria-label="Figure 4">04</span> Paper types</div>
            <div class="an-card-body">
                <?php if ($paperTypeStats): ?>
                    <div class="an-chart an-expandable" data-chart="types" title="Click to enlarge">
                        <canvas id="paperTypeChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">donut_small</span>Nothing to plot.</div>
                <?php endif; ?>
                <p class="an-figure-caption">Figure 4. Share of submissions by paper type</p>
                <?php $renderChartInsight('types'); ?>
            </div>
        </div>

        <div class="an-card an-chart-card" data-an-card="programs">
            <div class="an-card-head"><span class="an-figure-number" aria-label="Figure 5">05</span> Submissions by program</div>
            <div class="an-card-body">
                <?php if ($programChart): ?>
                    <div class="an-chart an-expandable" data-chart="programs" title="Click to enlarge">
                        <canvas id="programChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="an-empty"><span class="material-symbols-outlined">bar_chart</span>Nothing to compare.</div>
                <?php endif; ?>
                <p class="an-figure-caption">Figure 5. Submitted papers by course: count and share of submissions</p>
                <?php $renderChartInsight('programs'); ?>
            </div>
        </div>
        <div class="an-card an-chart-card" data-an-card="fields">
            <div class="an-card-head"><span class="an-figure-number" aria-label="Figure 6">06</span> Submissions by research field</div>
            <div class="an-card-body">
                <?php if ($researchFields): ?>
                    <div class="an-field-scroll"><div class="an-chart an-expandable" data-chart="fields" title="Click to enlarge">
                        <canvas id="fieldsChart"></canvas>
                    </div></div>
                <?php else: ?>
                    <div class="an-empty">No submissions in this selection.</div>
                <?php endif; ?>
                <p class="an-figure-caption">Figure 6. Papers by recorded research field. Specializations are shown as recorded; missing fields are Unspecified.</p>
                <?php $renderChartInsight('fields'); ?>
            </div>
        </div>
    </div>

    </div><!-- Overview tab -->

    <!-- ==== PRESCRIPTIVE ANALYTICS =========================================== -->
    <div class="an-tab-panel" role="tabpanel" id="anPanelPrescriptive" aria-labelledby="anTabPrescriptive" data-an-tab-panel="prescriptive">
    <?php $renderOverallInsight('overall-queue'); ?>
    <div class="an-section an-section--pres">
        <div class="an-section-head">
            <span class="material-symbols-outlined mi">lightbulb</span>
            Review queue
            <small>Current status of papers in the selected period</small>
        </div>
        <div class="an-section-body">

            <!-- Papers waiting longest -->
            <?php if (!$stageQueue): ?>
                <div class="an-card an-card-full"><div class="an-card-body">No papers in the selected results are currently awaiting review.</div></div>
            <?php endif; ?>
            <?php if ($overduePapers): ?>
            <div class="an-card an-card-full" data-an-card="pres-overdue">
                <div class="an-card-head"><span class="material-symbols-outlined">pending_actions</span> Oldest submissions still in review</div>
                <div class="an-card-body">
                    <div class="an-scroll">
                    <table class="an-overdue">
                        <thead><tr><th>Paper</th><th>Student</th><th>Status</th><th>Days since upload</th></tr></thead>
                        <tbody>
                        <?php foreach ($overduePapers as $op):
                            $dw = (int)$op['days_waiting'];
                            $cls = ''; // Age since upload is not a review deadline.
                        ?>
                            <tr>
                                <td><?= e(mb_strimwidth($op['title'], 0, 50, '...')) ?></td>
                                <td><?= e($op['student'] ?? 'Unknown') ?></td>
                                <td><?= e($statusLabelsMap[$op['current_status']] ?? $op['current_status']) ?></td>
                                <td class="<?= $cls ?>"><?= $dw ?> day<?= $dw !== 1 ? 's' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stage queue breakdown -->
            <?php if ($stageQueue): ?>
            <div class="an-card an-card-full" data-an-card="pres-queue">
                <div class="an-card-head"><span class="an-figure-number" aria-label="Figure 7">07</span> Current review queue</div>
                <div class="an-card-body">
                    <div class="an-chart an-expandable" data-chart="stageQueue" title="Click to enlarge">
                        <canvas id="stageQueueChart"></canvas>
                    </div>
                    <p class="an-figure-caption">Figure 7. Papers currently waiting at each review stage</p>
                    <?php $renderChartInsight('stageQueue'); ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
    </div><!-- Prescriptive tab -->

    <div class="an-tab-panel" role="tabpanel" id="anPanelTables" aria-labelledby="anTabTables" data-an-tab-panel="tables">
    <!-- Program outcomes and individual records answer different questions. -->
    <div class="an-grid an-grid--tables">
        <div class="an-card" data-an-card="by-program">
            <div class="an-card-head"><span class="material-symbols-outlined">school</span> Program outcomes
                <span class="an-hint">One row per program, with approval and return counts</span>
            </div>
            <div class="an-table-glide">
            <div class="an-scroll">
                <table class="an-table an-table--programs js-sortable">
                    <thead>
                        <tr>
                            <th data-sort="text" data-help="The academic program of the students who submitted these papers." aria-description="The academic program of the students who submitted these papers." tabindex="0">Program</th>
                            <th class="an-num" data-sort="num" data-help="Number of submitted papers from this program in the current results." aria-description="Number of submitted papers from this program in the current results." tabindex="0">Papers</th>
                            <th class="an-num" data-sort="num" data-help="Number of these papers that have been approved for publication." aria-description="Number of these papers that have been approved for publication." tabindex="0">Approved</th>
                            <th class="an-num" data-sort="num" data-help="Number of these papers sent back to students for revision." aria-description="Number of these papers sent back to students for revision." tabindex="0">Returned</th>
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
    </div>

    <?php require __DIR__ . '/submissions_table.php'; ?>

    </div><!-- Data tables tab -->

    </div>

</main>

<div class="an-export-preview" id="anExportPreview" role="dialog" aria-modal="true"
     aria-labelledby="anExportPreviewTitle" hidden>
    <div class="an-export-preview-panel" tabindex="-1">
        <div class="an-export-preview-head">
            <h2 id="anExportPreviewTitle">Export preview</h2>
            <div class="an-preview-zoom" id="anExportPreviewZoom" aria-label="Preview zoom" hidden>
                <button type="button" id="anExportZoomOut" aria-label="Zoom out" title="Zoom out">−</button>
                <output class="an-preview-zoom-output" id="anExportZoomValue">100%</output>
                <button type="button" id="anExportZoomIn" aria-label="Zoom in" title="Zoom in">+</button>
                <button type="button" id="anExportZoomReset" aria-label="Reset zoom" title="Reset zoom">↺</button>
            </div>
        </div>
        <div class="an-export-preview-body" id="anExportPreviewBody"></div>
        <div class="an-export-preview-foot">
            <button type="button" id="anExportPreviewCancel">Cancel</button>
            <button type="button" class="is-primary" id="anExportPreviewDownload" disabled>Download</button>
        </div>
    </div>
</div>

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
    // Delegation survives filter updates, which replace the result cards.
    var insightToken = <?= json_encode(csrf_token()) ?>;
    async function refreshChartInsight(box, force) {
        if (box.dataset.busy) return;
        box.dataset.busy = '1';
        var button = box.querySelector('.an-new-insight');
        var status = box.querySelector('.an-insight-status');
        button.disabled = true;
        status.textContent = 'Updating?';
        var url = new URL(location.href);
        url.searchParams.delete('export');
        url.searchParams.delete('_fragment');
        try {
            var response = await fetch(url.href, {
                method: 'POST', credentials: 'same-origin',
                body: new URLSearchParams({_token: insightToken, chart_insight: box.dataset.insight, force: force ? '1' : '0'})
            });
            if (!response.ok) throw new Error('Request failed');
            var result = await response.json();
            if (!box.isConnected) return;
            if (result.text) formatAiText(box.querySelector('.an-ai-text'), result.text);
            box.dataset.expires = result.expires;
            status.textContent = result.failed ? 'Unable to generate a new insight. Please try again.' : 'Updated.';
        } catch (error) {
            status.textContent = 'Unable to update. Please try again.';
            box.dataset.expires = Math.floor(Date.now() / 1000) + (box.dataset.insight.startsWith('overall-') ? 3600 : 86400);
        } finally {
            delete box.dataset.busy;
            button.disabled = false;
        }
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.an-new-insight');
        if (button) refreshChartInsight(button.closest('[data-insight]'), true);
    });
    function refreshDueInsights() {
        if (document.hidden) return;
        document.querySelectorAll('[data-insight]').forEach(function (box) {
            if (Number(box.dataset.expires) <= Date.now() / 1000) refreshChartInsight(box, false);
        });
    }
    setInterval(refreshDueInsights, 60000);
    document.addEventListener('visibilitychange', refreshDueInsights);


    /* Match the paper view's folding cards. Keep the chosen state when an
       in-place filter update replaces the result cards. */
    var cardStates = Object.create(null);
    var cardLabels = { fields: 'Submissions by research field',
        insight: 'AI insight', timeline: 'Submissions over time',
        monthly: 'Submissions per month', approval: 'Approval rate by paper type',
        types: 'Paper types', status: 'Review status', programs: 'Submissions by program',
        'by-program': 'Program outcomes', submissions: 'Every submission',
        'desc-ai': 'AI descriptive summary', 'pred-ai': 'AI predictive summary',
        'pres-ai': 'AI prescriptive summary'
    };
    function bindCardToggles() {
        document.querySelectorAll('#anResults .an-card[data-an-card]').forEach(function (card) {
            if (card.querySelector(':scope > .an-card-content')) return;
            var head = card.querySelector(':scope > .an-card-head');
            if (!head) return;
            var key = card.dataset.anCard;
            var label = cardLabels[key] || 'this section';
            var content = document.createElement('div');
            content.className = 'an-card-content';
            content.id = 'an-card-content-' + key;
            while (head.nextSibling) content.appendChild(head.nextSibling);
            card.appendChild(content);

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'an-card-toggle';
            toggle.setAttribute('aria-controls', content.id);
            toggle.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">expand_more</span>';
            head.appendChild(toggle);

            function setOpen(open) {
                card.dataset.collapsed = open ? '0' : '1';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', (open ? 'Hide ' : 'Show ') + label);
                toggle.title = open ? 'Hide this section' : 'Show this section';
            }
            setOpen(cardStates[key] !== false);
            toggle.addEventListener('click', function () {
                var open = card.dataset.collapsed === '1';
                cardStates[key] = open;
                setOpen(open);
                if (open) document.dispatchEvent(new CustomEvent('analytics:cardopened', { detail: { key: key } }));
            });
        });
    }
    bindCardToggles();
    document.addEventListener('analytics:updated', bindCardToggles);

    /* AI text is untrusted plain text. Lay out lines as short paragraphs or
       numbered actions, and split a single dense paragraph into sentences. */
    function formatAiText(box, raw) {
        var lines = String(raw || '').replace(/\r\n?/g, '\n').split(/\n+/)
            .map(function (line) { return line.trim(); }).filter(Boolean);
        if (lines.length === 1 && 'Segmenter' in Intl) {
            var sentences = Array.from(new Intl.Segmenter(undefined, { granularity: 'sentence' }).segment(lines[0]),
                function (part) { return part.segment.trim(); }).filter(Boolean);
            if (sentences.length > 1) lines = sentences;
        }
        var fragment = document.createDocumentFragment();
        var list = null;
        lines.forEach(function (line) {
            var numbered = line.match(/^\d+[.)]\s+(.+)$/);
            var bulleted = line.match(/^(?:[-*•])\s+(.+)$/);
            if (numbered || bulleted) {
                var kind = numbered ? 'OL' : 'UL';
                if (!list || list.tagName !== kind) {
                    list = document.createElement(kind.toLowerCase());
                    fragment.appendChild(list);
                }
                var item = document.createElement('li');
                item.textContent = (numbered || bulleted)[1];
                list.appendChild(item);
            } else {
                list = null;
                var paragraph = document.createElement('p');
                paragraph.textContent = line;
                fragment.appendChild(paragraph);
            }
        });
        box.replaceChildren(fragment);
    }
    function formatVisibleAiText() {
        document.querySelectorAll('#anResults [data-insight] .an-ai-text').forEach(function (box) {
            formatAiText(box, box.textContent);
        });
    }
    formatVisibleAiText();
    document.addEventListener('analytics:updated', formatVisibleAiText);

    /* The explanation lives outside the horizontally scrolling tables, where
       overflow would otherwise cut it off. Delegation keeps newly filtered or
       sorted headings working without another set of listeners. */
    var helpPopover = document.createElement('div');
    helpPopover.id = 'anColHelpPopover';
    helpPopover.className = 'an-col-help-popover';
    helpPopover.setAttribute('role', 'tooltip');
    helpPopover.hidden = true;
    document.body.appendChild(helpPopover);
    var activeHelp = null;
    function hideHelp() {
        activeHelp = null;
        helpPopover.hidden = true;
    }
    function showHelp(heading) {
        if (!heading || !heading.isConnected) return;
        activeHelp = heading;
        helpPopover.textContent = heading.dataset.help || '';
        helpPopover.hidden = false;
        var anchor = heading.getBoundingClientRect();
        var box = helpPopover.getBoundingClientRect();
        var left = Math.max(12, Math.min(anchor.left + anchor.width / 2 - box.width / 2,
                                        window.innerWidth - box.width - 12));
        var top = anchor.bottom + 8;
        if (top + box.height > window.innerHeight - 12) top = anchor.top - box.height - 8;
        helpPopover.style.left = left + 'px';
        helpPopover.style.top = Math.max(12, top) + 'px';
    }
    document.addEventListener('pointerover', function (event) {
        if (event.pointerType === 'touch') return;
        var heading = event.target.closest('th[data-help]');
        if (heading && heading !== activeHelp) showHelp(heading);
    });
    document.addEventListener('pointerout', function (event) {
        var heading = event.target.closest('th[data-help]');
        if (heading && heading === activeHelp &&
            (!event.relatedTarget || !heading.contains(event.relatedTarget))) hideHelp();
    });
    document.addEventListener('focusin', function (event) {
        var heading = event.target.closest('th[data-help]');
        if (heading) showHelp(heading);
    });
    document.addEventListener('focusout', function (event) {
        if (activeHelp && activeHelp.contains(event.target) &&
            (!event.relatedTarget || !activeHelp.contains(event.relatedTarget))) hideHelp();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !helpPopover.hidden) hideHelp();
    });
    document.addEventListener('scroll', function () {
        if (helpPopover.hidden) return;
        if (activeHelp && activeHelp.contains(document.activeElement) && activeHelp.isConnected) {
            var clip = activeHelp.closest('.an-scroll');
            var heading = activeHelp.getBoundingClientRect();
            var edge = clip && clip.getBoundingClientRect();
            if (!edge || (heading.right > edge.left && heading.left < edge.right)) {
                showHelp(activeHelp);
                return;
            }
        }
        hideHelp();
    }, true);
    window.addEventListener('resize', hideHelp);
    document.addEventListener('analytics:beforeupdate', hideHelp);

    /* A change to any filter updates the whole view, including charts and the
       written insight. Search waits for a pause so each keystroke does not
       start a new page request. Keep table sorting, but return to page one. */
    var filters = document.getElementById('anFilters');
    var search = document.getElementById('fQ');
    var main = document.querySelector('main.an-wrap');
    var refreshSubmissions = null;
    if (filters && search) {
        var searchTimer = null;
        var pendingFilters = null;
        var filterNames = ['range', 'from', 'to', 'program', 'type', 'status', 'q'];
        var rangeMode = filters.elements.namedItem('range');
        var rangeFrom = filters.elements.namedItem('from');
        var rangeTo = filters.elements.namedItem('to');
        var rangeBounds = document.getElementById('anRangeBounds');
        function syncRangeInputs(clear) {
            var mode = rangeMode.value;
            var inputType = { year: 'number', month: 'month', date: 'date', datetime: 'datetime-local' }[mode] || 'date';
            rangeBounds.hidden = mode === 'all';
            [rangeFrom, rangeTo].forEach(function (input) {
                if (input.type !== inputType) input.type = inputType;
                if (clear || mode === 'all') input.value = '';
                input.disabled = mode === 'all';
                if (mode === 'year') { input.min = '1900'; input.max = '9998'; }
                else { input.removeAttribute('min'); input.removeAttribute('max'); }
                input.setCustomValidity('');
            });
        }
        syncRangeInputs(false);
        var renderedFilters = filterKey(location.href);
        var errorBox = document.createElement('p');
        errorBox.className = 'an-filter-error';
        errorBox.setAttribute('role', 'alert');
        errorBox.hidden = true;
        filters.appendChild(errorBox);

        function filterKey(href) {
            var params = new URL(href, location.href).searchParams;
            return filterNames.map(function (name) { return params.get(name) || (name === 'range' ? 'all' : ''); }).join('\u0000');
        }
        function cancelPending() {
            if (pendingFilters) pendingFilters.abort();
            pendingFilters = null;
            filters.classList.remove('is-loading');
            filters.removeAttribute('aria-busy');
        }
        function loadDashboard(href, addHistory) {
            var target = new URL(href, location.href);
            cancelPending();
            document.dispatchEvent(new Event('analytics:beforeupdate'));
            var controller = new AbortController();
            pendingFilters = controller;
            errorBox.hidden = true;
            filters.classList.add('is-loading');
            filters.setAttribute('aria-busy', 'true');
            fetch(target.href, { credentials: 'same-origin', signal: controller.signal })
                .then(function (response) {
                    if (!response.ok) throw new Error('Could not load analytics');
                    return response.text();
                })
                .then(function (html) {
                    if (pendingFilters !== controller) return;
                    var incoming = new DOMParser().parseFromString(html, 'text/html');
                    var nextResults = incoming.getElementById('anResults');
                    var nextFilters = incoming.getElementById('anFilters');
                    var nextExport = incoming.querySelector('#anExportMenu a[role="menuitem"]');
                    if (!nextResults || !nextFilters || !nextExport) throw new Error('Analytics response was incomplete');

                    document.getElementById('anResults').replaceWith(nextResults);
                    rangeMode.value = nextFilters.elements.namedItem('range').value;
                    syncRangeInputs(true);
                    filterNames.filter(function (name) { return name !== 'range'; }).forEach(function (name) {
                        filters.elements.namedItem(name).value = nextFilters.elements.namedItem(name).value;
                    });
                    var oldRangeError = filters.querySelector('.an-range-error');
                    if (oldRangeError) oldRangeError.remove();
                    var nextRangeError = nextFilters.querySelector('.an-range-error');
                    if (nextRangeError) { errorBox.textContent = nextRangeError.textContent; errorBox.hidden = false; }
                    var active = filters.querySelector('.an-active');
                    if (active) active.remove();
                    var nextActive = nextFilters.querySelector('.an-active');
                    if (nextActive) filters.insertBefore(nextActive, errorBox);
                    document.querySelector('#anExportMenu a[role="menuitem"]').href =
                        new URL(nextExport.getAttribute('href'), target.href).href;
                    renderedFilters = filterKey(target.href);
                    if (addHistory) history.pushState({ analyticsFilters: true }, '', target.href);
                    document.dispatchEvent(new Event('analytics:updated'));
                })
                .catch(function (error) {
                    if (error.name === 'AbortError' || pendingFilters !== controller) return;
                    errorBox.textContent = 'Could not update analytics. Please try again.';
                    errorBox.hidden = false;
                })
                .finally(function () {
                    if (pendingFilters !== controller) return;
                    pendingFilters = null;
                    filters.classList.remove('is-loading');
                    filters.removeAttribute('aria-busy');
                });
        }
        function applyFilters() {
            clearTimeout(searchTimer);
            rangeTo.setCustomValidity('');
            if (rangeMode.value !== 'all' && rangeFrom.value && rangeTo.value && rangeFrom.value > rangeTo.value) {
                rangeTo.setCustomValidity('The end must be on or after the start.');
            }
            if (!filters.reportValidity()) return;
            var target = new URL(location.href);
            filterNames.forEach(function (name) { target.searchParams.delete(name); });
            target.searchParams.delete('year');
            target.searchParams.delete('page');
            var values = new FormData(filters);
            filterNames.forEach(function (name) {
                var value = String(values.get(name) || '').trim();
                if (value) target.searchParams.set(name, value);
            });
            if (target.href === location.href && renderedFilters === filterKey(target.href)) {
                cancelPending();
                return;
            }
            loadDashboard(target.href, true);
        }
        rangeMode.addEventListener('change', function () { syncRangeInputs(true); applyFilters(); });
        filters.querySelectorAll('select:not(#fRange)').forEach(function (select) {
            select.addEventListener('change', applyFilters);
        });
        [rangeFrom, rangeTo].forEach(function (input) { input.addEventListener('change', applyFilters); });
        search.addEventListener('input', function () {
            cancelPending();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilters, 600);
        });
        search.addEventListener('search', applyFilters);
        filters.addEventListener('submit', function (event) {
            event.preventDefault();
            applyFilters();
        });
        main.addEventListener('click', function (event) {
            var link = event.target.closest('.an-active a, .an-table--programs tbody a.an-sort');
            if (!link || event.defaultPrevented || event.button !== 0 ||
                event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            loadDashboard(link.href, true);
        });
        window.addEventListener('popstate', function () {
            clearTimeout(searchTimer);
            if (filterKey(location.href) !== renderedFilters) loadDashboard(location.href, false);
            else if (refreshSubmissions) refreshSubmissions(location.href, false);
        });
    }

    /* The program summary has one row per program, so client-side sorting
       sees the whole table. The submission list sorts in the database. */
    function bindSmallSorts() {
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
                th.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        th.click();
                    }
                });
            });
        });
    }
    bindSmallSorts();
    document.addEventListener('analytics:updated', bindSmallSorts);

    /* This table is sorted across the full database, not just its current
       page. Request only its markup and keep the charts and filters in place. */
    var submissions = document.getElementById('anSubmissions');
    if (submissions && window.fetch && window.DOMParser && window.AbortController) {
        var pendingSubmission = null;
        document.addEventListener('analytics:beforeupdate', function () {
            if (pendingSubmission) pendingSubmission.abort();
            pendingSubmission = null;
        });
        function loadSubmissions(href, addHistory) {
            submissions = document.getElementById('anSubmissions');
            var destination = new URL(href, location.href);
            if (destination.origin !== location.origin || destination.pathname !== location.pathname) {
                location.assign(destination.href);
                return;
            }
            if (pendingSubmission) pendingSubmission.abort();
            var controller = new AbortController();
            pendingSubmission = controller;
            var fragment = new URL(destination.href);
            fragment.searchParams.set('_fragment', 'submissions');
            submissions.classList.add('is-loading');
            submissions.setAttribute('aria-busy', 'true');
            fetch(fragment.href, { credentials: 'same-origin', signal: controller.signal })
                .then(function (response) {
                    if (!response.ok) throw new Error('Could not load submissions');
                    return response.text();
                })
                .then(function (html) {
                    if (pendingSubmission !== controller) return;
                    var incoming = new DOMParser().parseFromString(html, 'text/html')
                        .getElementById('anSubmissions');
                    if (!incoming) throw new Error('Submissions were missing');

                    var table = submissions.querySelector('.an-table--submissions');
                    var nextTable = incoming.querySelector('.an-table--submissions');
                    var strip = submissions.querySelector('.an-scroll');
                    var left = strip.scrollLeft;
                    table.tHead.replaceWith(nextTable.tHead);
                    table.tBodies[0].replaceWith(nextTable.tBodies[0]);

                    var pager = submissions.querySelector('.an-paging');
                    var nextPager = incoming.querySelector('.an-paging');
                    if (pager && nextPager) pager.replaceWith(nextPager);
                    else if (pager) pager.remove();
                    else if (nextPager) submissions.appendChild(nextPager);

                    strip.scrollLeft = left;
                    strip.dispatchEvent(new Event('scroll'));
                    if (addHistory) history.pushState({ analyticsSubmissions: true }, '', destination.href);
                    var csv = document.querySelector('#anExportMenu a[role="menuitem"]');
                    if (csv) {
                        var csvUrl = new URL(destination.href);
                        csvUrl.searchParams.set('export', 'csv');
                        csv.href = csvUrl.href;
                    }
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') return;
                    if (addHistory) location.assign(destination.href);
                    else location.reload();
                })
                .finally(function () {
                    if (pendingSubmission !== controller) return;
                    pendingSubmission = null;
                    submissions.classList.remove('is-loading');
                    submissions.removeAttribute('aria-busy');
                });
        }
        refreshSubmissions = loadSubmissions;
        main.addEventListener('click', function (event) {
            var link = event.target.closest('a.an-sort, a.an-page');
            submissions = document.getElementById('anSubmissions');
            if (!link || !submissions.contains(link) || link.classList.contains('is-off') ||
                event.defaultPrevented || event.button !== 0 || event.metaKey ||
                event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (pendingFilters) return;
            loadSubmissions(link.href, true);
        });
    }

    /* Keep each table's chevrons in sync with the columns still off-screen.
       The table scrolls inside its card, leaving the page width unchanged. */
    var glideObservers = [], glideMarks = [];
    function bindGlides() {
        glideObservers.forEach(function (observer) { observer.disconnect(); });
        glideObservers = [];
        glideMarks = [];
        document.querySelectorAll('.an-table-glide').forEach(function (glide) {
            var strip = glide.querySelector('.an-scroll');
            if (!strip) return;
            function mark() {
                if (!strip.isConnected) return;
                var max = strip.scrollWidth - strip.clientWidth;
                glide.classList.toggle('can-left', strip.scrollLeft > 2);
                glide.classList.toggle('can-right', strip.scrollLeft < max - 2);
            }
            glideMarks.push(mark);
            strip.addEventListener('scroll', mark, { passive: true });
            if (window.ResizeObserver) {
                var observer = new ResizeObserver(mark);
                observer.observe(strip);
                observer.observe(strip.querySelector('table'));
                glideObservers.push(observer);
            }
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(mark);
            mark();
        });
    }
    window.addEventListener('resize', function () { glideMarks.forEach(function (mark) { mark(); }); });
    bindGlides();
    document.addEventListener('analytics:updated', bindGlides);

    if (typeof Chart === 'undefined') { return; }

    Chart.register({
        id: 'papelValueLabels',
        afterDatasetsDraw: function (chart) {
            var data = chart.data.datasets[0] && chart.data.datasets[0].data || [];
            if (!data.length) return;
            var ctx = chart.ctx, type = chart.config.type;
            ctx.save();
            var labelSize = chart.options.plugins && chart.options.plugins.papelValueLabels && chart.options.plugins.papelValueLabels.fontSize || 12;
            ctx.font = '600 ' + labelSize + 'px Arial, sans-serif';
            ctx.fillStyle = '#630000';
            if (type === 'doughnut') {
                var sum = data.reduce(function (total, value) { return total + Number(value || 0); }, 0);
                var area = chart.chartArea;
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.font = '700 20px Arial, sans-serif';
                ctx.fillText(String(sum), (area.left + area.right) / 2, (area.top + area.bottom) / 2);
            }
            ctx.restore();
        }
    });

    /* Colours come from the theme, read fresh each time so a change of palette
       or of light/dark is followed rather than baked in at first paint. */
    var token = function (name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v || '').trim() || fallback;
    };

    var data = JSON.parse(document.getElementById('anChartData').textContent);

    /* ---- Chart types the reader may choose ---------------------------------
       Every figure can be drawn more than one way, but not every way is honest
       for every figure. What decides it is the shape of the data, not taste:

         time   counts in time order          (Figure 1)
         pair   two counts set side by side   (Figure 3)
         rate   percentages that do NOT add   (Figure 3: each type's approval
                up to 100                      rate is out of its own papers)
         parts  counts that make one whole    (Figures 4, 5, 6)

       suitable() answers, for one figure as its data stands now, which kinds may
       be drawn and why the others may not. Only the suitable ones are offered in
       the menu (the owner's choice, 20 November 2026); the reasons stay here as
       the record of each rule. It is asked again whenever the filters change the
       data: a pie of one slice, or a line through one point, is refused then and
       only then. */
    var KINDS = {
        line:     { label: 'Line',             icon: 'show_chart' },
        area:     { label: 'Area',             icon: 'area_chart' },
        stepped:  { label: 'Stepped line',     icon: 'stacked_line_chart' },
        column:   { label: 'Column',           icon: 'bar_chart' },
        bar:      { label: 'Bar',              icon: 'align_horizontal_left' },
        dot:      { label: 'Dot plot',         icon: 'scatter_plot' },
        radar:    { label: 'Radar',            icon: 'radar' },
        doughnut: { label: 'Doughnut',         icon: 'donut_large' },
        pie:      { label: 'Pie',              icon: 'pie_chart' },
        stacked:  { label: '100% stacked bar', icon: 'view_week' },
        table:    { label: 'Table',            icon: 'table_rows' }
    };
    var ORDER = ['line', 'area', 'stepped', 'column', 'bar', 'dot', 'radar', 'doughnut', 'pie', 'stacked', 'table'];
    var SHAPE = { fields: 'parts', timeline: 'time', monthly: 'time', approval: 'rate', types: 'parts', status: 'parts', programs: 'parts' };
    // What each figure has always been drawn as, and what it opens as until someone chooses.
    var DEFAULTS = { fields: 'bar', timeline: 'area', monthly: 'column', approval: 'bar', types: 'doughnut', status: 'doughnut', programs: 'pie' };
    var COLUMNS = { fields: ['Research field', 'Submissions'],
        timeline: ['Period', 'Submissions'], monthly: ['Month', 'Submissions'],
        approval: ['Paper type', 'Approved'], types: ['Paper type', 'Submissions'],
        status: ['Status', 'Submissions'], programs: ['Program', 'Submissions']
    };
    var PIE_MAX = 7;   // the series palette has seven colours, and past that slices cannot be told apart

    function rowsOf(key) { return (data[key] || []).map(function (r) { return { label: String(r.label), value: Number(r.value) || 0 }; }); }

    function suitable(key) {
        var rows = rowsOf(key), shape = SHAPE[key], n = rows.length;
        var filled = rows.filter(function (r) { return r.value > 0; }).length;
        var why = {};
        var noOrder = shape === 'rate' ? 'Paper types have no order, so a line joining them would suggest a trend that is not there.'
                                       : 'These categories have no order, so a line joining them would suggest a trend that is not there.';
        if (shape === 'time') {
            ['pie', 'doughnut', 'stacked'].forEach(function (k) { why[k] = 'Time periods are not parts of one whole, and slices would hide the order they come in.'; });
            why.bar = 'Time reads left to right, so it is drawn upright: use Column.';
            why.radar = 'A radar joins the last period back to the first, as if time went round in a circle.';
            if (n < 2) ['line', 'area', 'stepped'].forEach(function (k) { why[k] = 'A line needs at least two periods; there is only one.'; });
        } else if (shape === 'pair') {
            ['line', 'area', 'stepped'].forEach(function (k) { why[k] = 'Two months are compared side by side, not followed as a trend.'; });
            ['pie', 'doughnut', 'stacked'].forEach(function (k) { why[k] = 'The two months are not parts of one whole.'; });
            why.radar = 'A radar needs at least three things to compare.';
        } else if (shape === 'rate') {
            ['line', 'area', 'stepped'].forEach(function (k) { why[k] = noOrder; });
            ['pie', 'doughnut', 'stacked'].forEach(function (k) { why[k] = 'Approval rates do not add up to 100%: each is out of its own papers, so they cannot be slices of one whole.'; });
            if (n < 3) why.radar = 'A radar needs at least three paper types.';
        } else {
            ['line', 'area', 'stepped'].forEach(function (k) { why[k] = noOrder; });
            why.radar = 'A radar is for scores on one shared scale; counts across categories read more truthfully as bars.';
            var slice = filled < 2 && key !== 'programs' ? 'Only one category has any papers, so the whole would be a single slice.'
                      : (filled > PIE_MAX && key !== 'programs' ? 'More than ' + PIE_MAX + ' slices cannot be told apart; a bar keeps every one readable.' : null);
            if (slice) ['pie', 'doughnut', 'stacked'].forEach(function (k) { why[k] = slice; });
        }
        return why;
    }

    var VIEW_KEY = 'papel_analytics_views';
    var chosen = {};
    try { chosen = JSON.parse(localStorage.getItem(VIEW_KEY) || '{}') || {}; } catch (e) { chosen = {}; }
    function remember() { try { localStorage.setItem(VIEW_KEY, JSON.stringify(chosen)); } catch (e) {} }
    try {
        if (!localStorage.getItem('papel_program_pie_v1')) {
            chosen.programs = 'pie';
            remember();
            localStorage.setItem('papel_program_pie_v1', '1');
        }
    } catch (e) {}


    /* What a figure is drawn as now: the reader's choice if it still suits the
       data, then the figure's usual kind if that does, then the first of the
       plain ones that does. The usual kind is checked like any other: under
       some filters Figure 6 has papers in one status only, and its doughnut is
       then a single ring that says nothing. A choice the data no longer suits is
       kept, not overwritten, so it comes back when the filters change again. */
    function kindFor(key) {
        var why = suitable(key);
        var want = chosen[key];
        if (want && KINDS[want] && !why[want]) return want;
        if (!why[DEFAULTS[key]]) return DEFAULTS[key];
        var fallback = ['column', 'bar', 'dot', 'table'].filter(function (k) { return !why[k]; });
        return fallback[0] || 'table';
    }

    function palette(pal) {
        var color = function (name, fallback) { return pal ? (pal[name] || fallback) : token(name, fallback); };
        var c = {
            maroon: color('--maroon', '#820707'), soft: color('--soft-maroon', '#b17d7d'),
            dark: color('--dark-maroon', '#630000'), ink: color('--ink', '#330000'),
            grey: color('--grey', '#9f9f9f'), border: color('--border', '#e6d4d4'),
            cream: color('--cream', '#fff5f5'), white: color('--white', '#fff')
        };
        c.fill = color('--maroon-surface', c.maroon);
        // Bright fixed palette for chart data — independent of the page theme.
        c.bar = '#0d7377';
        c.series = ['#087f8c', '#d97706', '#6d28d9', '#2563eb', '#be185d', '#15803d', '#9a3412', '#475569', '#0891b2', '#7c3aed', '#a16207', '#166534'];
        c.tip = { backgroundColor: c.ink, titleColor: '#fff', bodyColor: '#fff', padding: 10, cornerRadius: 5, displayColors: false };
        return c;
    }

    /* A name laid out over as many lines as it takes.
       The owner's rule (23 November 2026): text that does not fit is shown in
       full, never cut to an ellipsis. Chart.js draws an array of strings as a
       stack of lines wherever a label goes - a tick, a spoke, a legend entry -
       so a long programme or paper type is wrapped instead of shortened. A
       single word with nothing to break at is left whole, which is still the
       whole of it; one line comes back as a plain string, so anything that
       expects a label to be text still reads it. */
    function labelLines(text, n) {
        var out = [], line = '';
        String(text).split(' ').forEach(function (word) {
            var next = line === '' ? word : line + ' ' + word;
            if (next.length > n && line !== '') { out.push(line); line = word; }
            else { line = next; }
        });
        if (line !== '') out.push(line);
        return out.length > 1 ? out : (out[0] || '');
    }

    function config(key, kind, c) {
        var shape = SHAPE[key], rate = shape === 'rate';
        var rows = rowsOf(key);
        // A slice of nothing is not drawn; a bar of nothing is, since "none" is an answer there.
        if (kind === 'pie' || kind === 'doughnut' || kind === 'stacked') rows = rows.filter(function (r) { return r.value > 0; });
        var full = rows.map(function (r) { return r.label; });
        var wrapped = function (n) { return full.map(function (l) { return labelLines(l, n); }); };
        var vals = rows.map(function (r) { return r.value; });
        var total = vals.reduce(function (a, b) { return a + b; }, 0);
        var share = function (v) { return total ? Math.round(v / total * 1000) / 10 : 0; };
        var say = function (v) {
            if (rate) return v + '% approved';
            var s = v + (v === 1 ? ' paper' : ' papers');
            return shape === 'parts' && total ? s + ' (' + share(v) + '% of ' + total + ')' : s;
        };
        var catColor = function (i) {
            if (key === 'status') return ({ Approved: '#0d7377', 'In review': '#e8a838', Returned: '#e06040', Other: '#5b9bd5' })[full[i]] || c.series[i % 7];
            return c.series[i % c.series.length];
        };
        var tip = Object.assign({}, c.tip, { callbacks: {
            title: function (items) { return items.length ? full[items[0].dataIndex] : ''; },
            label: function (ctx) { return say(ctx.raw); }
        } });
        var valueAxis = { beginAtZero: true, grid: { color: c.border }, border: { display: false },
                          ticks: rate ? { color: c.grey, callback: function (v) { return v + '%'; } } : { color: c.grey, precision: 0 } };
        if (rate) valueAxis.max = 100;
        var catAxis = function (n) { return { grid: { display: false }, ticks: { color: c.grey, autoSkip: true, maxRotation: 0,
            callback: function (value) { return labelLines(this.getLabelForValue(value), n); } } }; };
        var base = function (type, dataset, extra) {
            return Object.assign({ type: type, data: { labels: full, datasets: [dataset] },
                options: { responsive: true, maintainAspectRatio: false,
                           plugins: { legend: { display: false }, tooltip: tip } } }, extra || {});
        };
        var legendBottom = { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true,
                             pointStyle: 'circle', color: c.ink, padding: 12 } };

        switch (kind) {
            case 'line': case 'area': case 'stepped': {
                var cfgLine = base('line', { data: vals, borderColor: c.bar, borderWidth: 2,
                    backgroundColor: kind === 'area' ? 'rgba(13,115,119,.12)' : 'transparent', fill: kind === 'area',
                    tension: kind === 'stepped' ? 0 : .35, cubicInterpolationMode: 'monotone',
                    stepped: kind === 'stepped' ? 'middle' : false,
                    pointBackgroundColor: c.bar, pointRadius: 3, pointHoverRadius: 5 });
                cfgLine.options.scales = { x: catAxis(14), y: valueAxis };
                return cfgLine;
            }
            case 'column': case 'bar': {
                var flat = kind === 'bar';
                var cfgBar = base('bar', { data: vals, borderRadius: 5,
                    backgroundColor: shape === 'parts' || rate ? vals.map(function (v, i) { return catColor(i); }) : (key === 'monthly' ? '#2563eb' : c.bar),
                    maxBarThickness: flat ? (full.length > 6 ? 20 : 40) : 64 });
                cfgBar.options.indexAxis = flat ? 'y' : 'x';
                cfgBar.options.scales = flat ? { x: valueAxis, y: catAxis(20) } : { x: catAxis(14), y: valueAxis };
                if (key === 'fields' && flat) cfgBar.options.scales.y.ticks.autoSkip = false;
                return cfgBar;
            }
            case 'dot': {
                // Points against a zero-based axis, no line between them: the comparison a bar makes, with less ink.
                var upright = shape === 'time';
                var cfgDot = base('line', { data: vals, showLine: false, pointRadius: 6, pointHoverRadius: 8,
                    pointBackgroundColor: c.bar, pointBorderColor: c.white, pointBorderWidth: 1.5 });
                cfgDot.options.indexAxis = upright ? 'x' : 'y';
                // Each point in the middle of its category's slot, as a bar would be, not on the slot's edge.
                var dotCats = Object.assign(catAxis(upright ? 14 : 20), { offset: true });
                cfgDot.options.scales = upright ? { x: dotCats, y: valueAxis } : { x: valueAxis, y: dotCats };
                cfgDot.options.layout = { padding: { right: 8 } };
                return cfgDot;
            }
            case 'radar': {
                var cfgRadar = base('radar', { data: vals, borderColor: c.bar, backgroundColor: 'rgba(13,115,119,.12)', borderWidth: 2,
                    pointBackgroundColor: c.bar, pointRadius: 3 });
                /* Each spoke is named with its own value, and the ring labels are
                   hidden: printed up the top spoke they sat on its point (a 100%
                   rate is drawn exactly where "100%" is written). */
                cfgRadar.data.labels = wrapped(16).map(function (l, i) {
                    /* The value joins the last line, so a wrapped spoke reads
                       as one label rather than a name with a figure adrift. */
                    var tail = ' · ' + (rate ? vals[i] + '%' : vals[i]);
                    return Array.isArray(l) ? l.slice(0, -1).concat(l[l.length - 1] + tail) : l + tail;
                });
                cfgRadar.options.scales = { r: { beginAtZero: true, max: rate ? 100 : undefined,
                    grid: { color: c.border }, angleLines: { color: c.border },
                    pointLabels: { color: c.ink, font: { size: 11 } },
                    ticks: { display: false, stepSize: rate ? 25 : undefined } } };
                return cfgRadar;
            }
            case 'pie': case 'doughnut': {
                var cfgPie = base(kind, { data: vals, backgroundColor: vals.map(function (v, i) { return catColor(i); }),
                    borderColor: c.white, borderWidth: 2 });
                cfgPie.data.labels = key === 'programs' ? rows.map(function (row) {
                    var total = vals.reduce(function (sum, value) { return sum + value; }, 0);
                    return labelLines(row.label + ': ' + row.value + ' (' + (total ? row.value / total * 100 : 0).toFixed(1) + '%)', 30);
                }) : wrapped(24);
                if (kind === 'doughnut') cfgPie.options.cutout = key === 'status' ? '62%' : '58%';
                cfgPie.options.plugins.legend = legendBottom;
                return cfgPie;
            }
            case 'stacked': {
                // One bar, 100 wide, each category its share of it: the pie's question asked along a ruler.
                var labs = wrapped(24);
                return { type: 'bar',
                    data: { labels: [''], datasets: rows.map(function (r, i) {
                        return { label: labs[i], data: [share(r.value)], count: r.value, backgroundColor: catColor(i),
                                 borderColor: c.white, borderWidth: 1, maxBarThickness: 56 };
                    }) },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: { legend: legendBottom, tooltip: Object.assign({}, c.tip, { callbacks: {
                            title: function (items) { return items.length ? full[items[0].datasetIndex] : ''; },
                            label: function (ctx) { return say(ctx.dataset.count); } } }) },
                        scales: { x: { stacked: true, beginAtZero: true, max: 100, grid: { color: c.border }, border: { display: false },
                                       ticks: { color: c.grey, callback: function (v) { return v + '%'; } } },
                                  y: { stacked: true, grid: { display: false }, ticks: { display: false } } } } };
            }
        }
        return null;
    }

    function tableHtml(key) {
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
        var rows = rowsOf(key), shape = SHAPE[key], head = COLUMNS[key];
        var total = rows.reduce(function (a, r) { return a + r.value; }, 0);
        var parts = shape === 'parts';
        var html = '<table class="an-table an-view-table"><thead><tr><th scope="col">' + esc(head[0]) + '</th><th scope="col" class="is-num">' + esc(head[1]) + '</th>'
                 + (parts ? '<th scope="col" class="is-num">Share</th>' : '') + '</tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr><td>' + esc(r.label) + '</td><td class="is-num">' + esc(shape === 'rate' ? r.value + '%' : String(r.value)) + '</td>'
                  + (parts ? '<td class="is-num">' + (total ? Math.round(r.value / total * 1000) / 10 : 0) + '%</td>' : '') + '</tr>';
        });
        if (parts) html += '<tr class="is-total"><td>Total</td><td class="is-num">' + total + '</td><td class="is-num">100%</td></tr>';
        return html + '</tbody></table>';
    }

    /* Draws one figure into a box holding a canvas: as a chart, or as a table
       in place of it. Returns the Chart, or null for a table. */
    function paint(box, key, c) {
        var canvas = box.querySelector('canvas');
        var kind = kindFor(key);
        if (key === 'fields') box.style.height = (kind === 'bar' ? Math.max(320, rowsOf(key).length * 76) : 360) + 'px';
        var tableBox = box.querySelector('.an-view-tablebox');
        if (kind === 'table') {
            if (!tableBox) { tableBox = document.createElement('div'); tableBox.className = 'an-view-tablebox'; box.appendChild(tableBox); }
            tableBox.innerHTML = tableHtml(key);
            tableBox.hidden = false;
            canvas.hidden = true;
            return null;
        }
        if (tableBox) tableBox.hidden = true;
        canvas.hidden = false;
        return new Chart(canvas, config(key, kind, c));
    }

    var titles = { fields: 'Figure 6. Submissions by research field', timeline: 'Figure 1. Submissions over time', monthly: 'Figure 2. Submissions per month',
                   approval: 'Figure 3. Approval rate by paper type', types: 'Figure 4. Paper types',
                   status: 'Figure 6. Review status', programs: 'Figure 5. Submissions by program' };
    var canvases = { fields: 'fieldsChart', timeline: 'timelineChart', monthly: 'monthlyChart',
                     approval: 'approvalRateChart', types: 'paperTypeChart',
                     status: 'statusChart', programs: 'programChart' };

    /* ---- Descriptive / Predictive / Prescriptive charts ---- */
    var extraCanvases = { stageQueue: 'stageQueueChart' };

    function drawExtra(c) {
        Object.keys(extraCanvases).forEach(function (key) {
            var el = document.getElementById(extraCanvases[key]);
            if (!el) return;
            var existing = Chart.getChart(el);
            if (existing) existing.destroy();

            var rows = (data[key] || []);
            if (key === 'trendLine') rows = data.trendLine || {};

            var tip = { backgroundColor: c.ink, titleColor: '#fff', bodyColor: '#fff',
                        borderColor: c.border, borderWidth: 1, cornerRadius: 6, padding: 10 };

            if (key === 'stageQueue') {
                new Chart(el, {
                    type: 'bar', data: { labels: rows.map(function (r) { return r.label; }),
                        datasets: [{ data: rows.map(function (r) { return r.value; }),
                            backgroundColor: rows.map(function (r, i) { return i === 0 ? '#e8a838' : c.bar; }),
                            borderRadius: 5, maxBarThickness: 48 }] },
                    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                        plugins: { legend: { display: false }, tooltip: tip },
                        scales: { x: { beginAtZero: true, grid: { color: c.border }, ticks: { color: c.grey, precision: 0 } },
                                  y: { grid: { display: false }, ticks: { color: c.grey } } } }
                });
            }
        });
    }

    var colors = palette();
    var drawn = {};
    var instance = null, openKey = null, opener = null;

    function setFont() {
        Chart.defaults.font.family = token('--font-body', 'Inter') + ', Inter, system-ui, sans-serif';
        Chart.defaults.font.size = 11;
        Chart.defaults.color = colors.grey;
    }
    setFont();

    function drawOne(key) {
        var el = document.getElementById(canvases[key]);
        if (!el) return;
        if (drawn[key]) { drawn[key].destroy(); }
        drawn[key] = paint(el.parentNode, key, colors);
    }
    function drawAll() { Object.keys(canvases).forEach(drawOne); }
    drawAll();
    drawExtra(colors);

    window.papelAnalyticsExportFigures = function (format) {
        var light = palette({
            '--maroon': '#820707', '--soft-maroon': '#b17d7d',
            '--dark-maroon': '#630000', '--ink': '#330000',
            '--grey': '#6e6a6e', '--border': '#e6d4d4',
            '--cream': '#fff5f5', '--white': '#ffffff'
        });
        var captions = { fields: 'Figure 6. Submissions by research field',
            timeline: 'Figure 1. Submissions over time', monthly: 'Figure 2. Submissions per month',
            approval: 'Figure 3. Percentage approved by paper type', types: 'Figure 4. Share of submissions by paper type',
            status: 'Figure 6. Share of submissions by review status',
            programs: 'Figure 5. Submitted papers by course: count and share of submissions'
        };
        return Object.keys(canvases).map(function (key) {
            if (!document.getElementById(canvases[key])) return null;
            /* The export carries the figure as the reader chose to see it. A table
               has no picture, so it goes out as the figure's usual chart, the
               document already holding the same numbers in its own tables. */
            var kind = kindFor(key) === 'table' ? DEFAULTS[key] : kindFor(key);
            var canvas = document.createElement('canvas');
            canvas.width = 1000;
            canvas.height = 500;
            var cfg = config(key, kind, light);
            cfg.options.responsive = false;
            cfg.options.animation = false;
            cfg.options.font = { family: 'Arial, sans-serif', size: 15 };
            cfg.options.plugins.papelValueLabels = { fontSize: 15 };
            cfg.plugins = [{
                id: 'exportWhiteBackground',
                beforeDraw: function (chart) {
                    var context = chart.ctx;
                    context.save();
                    context.fillStyle = '#ffffff';
                    context.fillRect(0, 0, chart.width, chart.height);
                    context.restore();
                }
            }];
            var chart = new Chart(canvas, cfg);
            var url = canvas.toDataURL(format === 'pdf' ? 'image/jpeg' : 'image/png', 0.92);
            chart.destroy();
            return { caption: captions[key], url: url, width: canvas.width, height: canvas.height };
        }).filter(Boolean);
    };
    document.addEventListener('analytics:cardopened', function (event) {
        var chart = drawn[event.detail.key];
        if (chart) requestAnimationFrame(function () { chart.resize(); });
    });

    /* ---- The larger view ---- */
    var modal = document.getElementById('chartModal');
    var modalTitle = document.getElementById('chartModalTitle');
    var modalBox = document.getElementById('modalChart').parentNode;

    function openModalChart() {
        if (instance) { instance.destroy(); instance = null; }
        instance = paint(modalBox, openKey, colors);
    }

    new MutationObserver(function () {
        colors = palette();
        setFont();
        drawAll();
        drawExtra(colors);
        if (openKey) openModalChart();
    }).observe(document.documentElement, {
        attributes: true, attributeFilter: ['data-mode', 'data-color', 'data-theme']
    });

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
        if (instance) { instance.destroy(); instance = null; }
        openKey = null;
        if (opener) { opener.focus(); opener = null; }
    }

    function bindExpandables() {
        document.querySelectorAll('.an-expandable').forEach(function (box) {
            if (box.dataset.anBound) return;
            box.dataset.anBound = '1';
            box.setAttribute('tabindex', '0');
            box.setAttribute('role', 'button');
            var open = function () {
                var key = box.dataset.chart;
                if (!SHAPE[key]) { return; }
                modalTitle.textContent = (titles[key] || 'Chart') + ' · ' + KINDS[kindFor(key)].label;
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
                openKey = key;
                openModalChart();
                opener = box;
                document.getElementById('chartModalPanel').focus();
            };
            box.addEventListener('click', open);
            box.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
            });
        });
    }
    bindExpandables();

    /* ---- The chooser on each figure ----
       A button in the card's head naming what the figure is drawn as, opening a
       menu of the kinds that suit its data and nothing else, the figure's usual
       kind marked. The menu is one element on
       <body>, fixed: the card clips its own overflow, so a menu inside it would
       be cut off at the card's edge. */
    var menu = document.createElement('div');
    menu.className = 'an-view-menu';
    menu.id = 'anViewMenu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    document.body.appendChild(menu);
    var menuFor = null;   // the button the menu is open for

    function buttonFace(btn, key) {
        var k = KINDS[kindFor(key)];
        btn.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">' + k.icon + '</span>'
                      + '<span class="an-view-name">' + k.label + '</span>'
                      + '<span class="material-symbols-outlined an-view-caret" aria-hidden="true">expand_more</span>';
        btn.setAttribute('aria-label', 'Chart type for ' + (titles[key] || 'this figure') + ': ' + k.label + '. Change');
    }

    function closeMenu(focusBack) {
        if (menu.hidden) return;
        menu.hidden = true;
        if (menuFor) {
            menuFor.setAttribute('aria-expanded', 'false');
            if (focusBack) menuFor.focus();
        }
        menuFor = null;
    }

    function placeMenu(btn) {
        var r = btn.getBoundingClientRect();
        menu.style.left = '0px'; menu.style.top = '0px';
        var w = menu.offsetWidth, h = menu.offsetHeight;
        var left = Math.min(Math.max(8, r.right - w), innerWidth - w - 8);
        var top = r.bottom + 6;
        if (top + h > innerHeight - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
        menu.style.left = Math.max(8, left) + 'px';
        menu.style.top = Math.max(8, top) + 'px';
    }

    function openMenu(btn) {
        var key = btn.dataset.anView;
        var why = suitable(key), now = kindFor(key);
        menu.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'an-view-menu-head';
        head.textContent = 'Show ' + (titles[key] || 'this figure').replace(/^Figure \d+\. /, '').toLowerCase() + ' as';
        menu.appendChild(head);
        // Only what this figure's data can honestly be drawn as; the rest are not offered at all.
        ORDER.filter(function (kind) { return !why[kind]; }).forEach(function (kind) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'an-view-item';
            item.setAttribute('role', 'menuitemradio');
            item.setAttribute('aria-checked', kind === now ? 'true' : 'false');
            item.dataset.kind = kind;
            item.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">' + KINDS[kind].icon + '</span>'
                + '<span class="an-view-text"><span class="an-view-label"></span></span>'
                + (kind === DEFAULTS[key] ? '<span class="an-view-tag">Usual</span>' : '')
                + (kind === now ? '<span class="material-symbols-outlined an-view-tick" aria-hidden="true">check</span>' : '');
            item.querySelector('.an-view-label').textContent = KINDS[kind].label;
            item.addEventListener('click', function () {
                chosen[key] = kind;
                remember();
                drawOne(key);
                buttonFace(btn, key);
                closeMenu(true);
            });
            menu.appendChild(item);
        });
        menu.hidden = false;
        menuFor = btn;
        btn.setAttribute('aria-expanded', 'true');
        placeMenu(btn);
        var first = menu.querySelector('.an-view-item[aria-checked="true"]') || menu.querySelector('.an-view-item');
        if (first) first.focus();
    }

    menu.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(menu.querySelectorAll('.an-view-item'));
        var at = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var next = items[(at + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
            if (next) next.focus();
        } else if (e.key === 'Home' || e.key === 'End') {
            e.preventDefault();
            (e.key === 'Home' ? items[0] : items[items.length - 1]).focus();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeMenu(true);
        } else if (e.key === 'Tab') {
            closeMenu(false);
        }
    });
    document.addEventListener('pointerdown', function (e) {
        if (!menu.hidden && !menu.contains(e.target) && e.target.closest('.an-view-btn') !== menuFor) closeMenu(false);
    }, true);
    /* A scroll moves the button, so the fixed menu follows it, and closes only
       once the button has left the window. Closing on any scroll looked right
       and was not: the page scrolls smoothly, and the tail of a scroll still
       running when the button was pressed shut the menu before it was seen. */
    document.addEventListener('scroll', function (e) {
        if (menu.hidden || !menuFor || menu.contains(e.target)) return;
        var r = menuFor.getBoundingClientRect();
        if (r.bottom < 0 || r.top > innerHeight) closeMenu(false); else placeMenu(menuFor);
    }, true);
    window.addEventListener('resize', function () { closeMenu(false); });

    function bindViewChoosers() {
        document.querySelectorAll('.an-chart-card[data-an-card]').forEach(function (card) {
            var key = card.dataset.anCard;
            if (!SHAPE[key] || !card.querySelector('.an-chart canvas')) return;   // an empty figure has nothing to redraw
            var head = card.querySelector('.an-card-head');
            if (!head) return;
            var btn = head.querySelector('.an-view-btn');
            if (!btn) {
                btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'an-view-btn';
                btn.dataset.anView = key;
                btn.setAttribute('aria-haspopup', 'menu');
                btn.setAttribute('aria-expanded', 'false');
                btn.setAttribute('aria-controls', 'anViewMenu');
                btn.title = 'Change the chart type';
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (menuFor === btn) closeMenu(true); else { closeMenu(false); openMenu(btn); }
                });
                head.insertBefore(btn, head.querySelector('.an-card-toggle'));
            }
            buttonFace(btn, key);
        });
    }
    bindViewChoosers();

    document.addEventListener('analytics:updated', function () {
        closeMenu(false);
        if (modal.classList.contains('is-open')) closeModal();
        Object.keys(drawn).forEach(function (key) { if (drawn[key]) drawn[key].destroy(); });
        drawn = {};
        data = JSON.parse(document.getElementById('anChartData').textContent);
        drawAll();
        drawExtra(colors);
        bindExpandables();
        bindViewChoosers();
    });

    /* Keep the selected statistics tab while filters replace the results.
       Charts are drawn before panels are hidden, then resized on reveal. */
    var activeAnalyticsTab = 'overview';
    function bindAnalyticsTabs() {
        var results = document.getElementById('anResults');
        var tablist = results.querySelector('.an-tabs');
        var tabs = Array.from(tablist.querySelectorAll('[data-an-tab]'));
        var panels = Array.from(results.querySelectorAll('[data-an-tab-panel]'));
        function showTab(name, focus) {
            if (!tabs.some(function (tab) { return tab.dataset.anTab === name; })) name = 'overview';
            activeAnalyticsTab = name;
            tabs.forEach(function (tab) {
                var selected = tab.dataset.anTab === name;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
                if (selected && focus) tab.focus();
            });
            panels.forEach(function (panel) { panel.hidden = panel.dataset.anTabPanel !== name; });
            var visible = panels.find(function (panel) { return panel.dataset.anTabPanel === name; });
            requestAnimationFrame(function () {
                if (!visible || !visible.isConnected) return;
                visible.querySelectorAll('canvas').forEach(function (canvas) {
                    var chart = Chart.getChart(canvas);
                    if (chart) chart.resize();
                });
            });
        }
        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { showTab(tab.dataset.anTab, false); });
            tab.addEventListener('keydown', function (event) {
                var next = index;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else return;
                event.preventDefault();
                showTab(tabs[next].dataset.anTab, true);
            });
        });
        tablist.hidden = false;
        showTab(activeAnalyticsTab, false);
    }
    bindAnalyticsTabs();
    document.addEventListener('analytics:updated', bindAnalyticsTabs);

    document.getElementById('chartModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) { closeModal(); }
    });
});
</script>
<?php require ROOT_PATH.'/includes/scroll_jump.php'; ?>
<script nonce="<?= csp_nonce() ?>" src="<?= BASE_URL ?>/assests/js/analytics-word-editor.js?v=3"></script>
<script nonce="<?= csp_nonce() ?>">
/* ---- Export -------------------------------------------------------------
   The charts live on canvases, so the server cannot draw them: there is no GD
   here, and even with it the server has no idea what Chart.js painted. The page
   therefore hands the pictures over with the request.

   Formats differ in what they can carry. Excel and Word embed a PNG happily.
   A PDF can carry a JPEG byte for byte, and would need the image decoded and
   re-compressed to take a PNG, so the canvas is asked for JPEG in that case. */
(function () {
    var wrap = document.getElementById('anExport');
    var btn  = document.getElementById('anExportBtn');
    var menu = document.getElementById('anExportMenu');
    var form = document.getElementById('anExportForm');
    var preview = document.getElementById('anExportPreview');
    var previewPanel = preview && preview.querySelector('.an-export-preview-panel');
    var previewTitle = document.getElementById('anExportPreviewTitle');
    var previewBody = document.getElementById('anExportPreviewBody');
    var previewDownload = document.getElementById('anExportPreviewDownload');
    var zoomControls = document.getElementById('anExportPreviewZoom');
    var zoomValue = document.getElementById('anExportZoomValue');
    if (!wrap || !btn || !menu || !form || !preview) return;
    var previewFormat = null, csvHref = null, previewRequest = null, opener = null;
    var previewZoom = 100;
    var wordEditor = null;

    function shut() { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.hidden = !menu.hidden;
        btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) shut();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') shut();
    });

    function closePreview() {
        if (previewRequest) previewRequest.abort();
        previewRequest = null;
        if (wordEditor) { wordEditor.destroy(); wordEditor = null; }
        document.body.classList.remove('an-preview-resizing', 'is-row');
        form.querySelectorAll('.js-word-document').forEach(function (field) { field.remove(); });
        preview.hidden = true;
        document.documentElement.classList.remove('an-export-preview-open');
        previewBody.replaceChildren();
        previewBody.classList.remove('is-sheet');
        zoomControls.hidden = true;
        previewDownload.disabled = true;
        previewFormat = null;
        wrap.classList.remove('is-busy');
        if (opener) { opener.focus(); opener = null; }
    }
    function prepareFigures(format) {
        form.querySelectorAll('.js-fig').forEach(function (input) { input.remove(); });
        document.getElementById('anExportFormat').value = format;
        if (typeof window.papelAnalyticsExportFigures !== 'function') return;
        window.papelAnalyticsExportFigures(format).forEach(function (figure) {
            [['figures', figure.url], ['figw', figure.width], ['figh', figure.height]]
                .forEach(function (pair) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.className = 'js-fig';
                    input.name = pair[0] + '[' + figure.caption + ']';
                    input.value = pair[1];
                    form.appendChild(input);
                });
        });
    }
    function showError() {
        previewBody.replaceChildren();
        var message = document.createElement('p');
        message.className = 'an-export-preview-status';
        message.setAttribute('role', 'alert');
        message.textContent = 'Could not build this preview. Please try again.';
        previewBody.appendChild(message);
    }
    function parseCsv(source) {
        var rows = [], row = [], value = '', quoted = false;
        source = source.replace(/^\uFEFF/, '');
        for (var i = 0; i < source.length; i++) {
            var char = source[i];
            if (char === '"') {
                if (quoted && source[i + 1] === '"') { value += '"'; i++; }
                else quoted = !quoted;
            } else if (char === ',' && !quoted) {
                row.push(value); value = '';
            } else if ((char === '\r' || char === '\n') && !quoted) {
                row.push(value); rows.push(row); row = []; value = '';
                if (char === '\r' && source[i + 1] === '\n') i++;
            } else {
                value += char;
            }
        }
        if (value !== '' || row.length) { row.push(value); rows.push(row); }
        return rows;
    }
    function columnName(index) {
        var name = '';
        for (index++; index > 0; index = Math.floor((index - 1) / 26)) {
            name = String.fromCharCode(65 + (index - 1) % 26) + name;
        }
        return name;
    }
    function csvSheet(source) {
        var rows = parseCsv(source);
        var columns = Math.max(6, rows.reduce(function (max, row) { return Math.max(max, row.length); }, 0));
        var sheet = document.createElement('div');
        sheet.className = 'an-sheet-preview';
        sheet.innerHTML = '<div class="an-sheet-formula"><span class="an-sheet-reference">A1</span>' +
            '<span class="an-sheet-fx" aria-hidden="true">fx</span><span class="an-sheet-value"></span></div>' +
            '<div class="an-sheet-pages"><div class="an-sheet-page" id="anPreviewCsvSheet" role="tabpanel" ' +
            'aria-labelledby="anPreviewCsvTab"></div></div>' +
            '<div class="an-sheet-tabs" role="tablist" aria-label="Spreadsheet sheets">' +
            '<button type="button" class="an-sheet-tab is-active" id="anPreviewCsvTab" role="tab" ' +
            'aria-selected="true" aria-controls="anPreviewCsvSheet">CSV</button></div>';
        var table = document.createElement('table');
        table.className = 'an-sheet-grid';
        table.setAttribute('aria-label', 'CSV spreadsheet preview');
        var thead = table.createTHead(), headRow = thead.insertRow();
        var corner = document.createElement('th');
        corner.className = 'an-sheet-corner';
        headRow.appendChild(corner);
        for (var col = 0; col < columns; col++) {
            var column = document.createElement('th');
            column.scope = 'col';
            column.textContent = columnName(col);
            headRow.appendChild(column);
        }
        var tbody = table.createTBody();
        rows.forEach(function (row, index) {
            var tr = tbody.insertRow();
            if (index === 0) tr.className = 'an-sheet-row-title';
            else if (row.length === 1 && /^[A-Z][A-Z ]+$/.test(row[0])) tr.className = 'an-sheet-row-section';
            var number = document.createElement('th');
            number.scope = 'row';
            number.className = 'an-sheet-row-number';
            number.textContent = index + 1;
            tr.appendChild(number);
            for (var c = 0; c < columns; c++) {
                var cell = tr.insertCell();
                var value = row[c] || '';
                cell.textContent = value;
                cell.dataset.cellRef = columnName(c) + (index + 1);
                cell.dataset.cellValue = value;
            }
        });
        sheet.querySelector('.an-sheet-page').appendChild(table);
        sheet.querySelector('.an-sheet-value').textContent = rows[0] ? rows[0][0] : '';
        return sheet;
    }
    function selectCell(cell) {
        var sheet = cell.closest('.an-sheet-preview');
        if (!sheet) return;
        var previous = sheet.querySelector('.an-sheet-grid td.is-selected');
        if (previous) previous.classList.remove('is-selected');
        cell.classList.add('is-selected');
        sheet.querySelector('.an-sheet-reference').textContent = cell.dataset.cellRef;
        sheet.querySelector('.an-sheet-value').textContent = cell.dataset.cellValue || '';
    }
    function setPreviewZoom(value) {
        var minimum = previewFormat === 'docx' ? 25 : 50;
        previewZoom = Math.max(minimum, Math.min(200, value));
        zoomValue.textContent = previewZoom + '%';
        document.getElementById('anExportZoomOut').disabled = previewZoom <= minimum;
        document.getElementById('anExportZoomIn').disabled = previewZoom >= 200;
        previewBody.querySelectorAll('.an-sheet-page, .an-doc-preview').forEach(function (page) {
            page.style.zoom = previewZoom + '%';
        });
    }
    document.getElementById('anExportZoomOut').addEventListener('click', function () { setPreviewZoom(previewZoom - 10); });
    document.getElementById('anExportZoomIn').addEventListener('click', function () { setPreviewZoom(previewZoom + 10); });
    document.getElementById('anExportZoomReset').addEventListener('click', function () { setPreviewZoom(100); });

    function resizeTableWidth(table) {
        var width = Array.from(table.querySelectorAll(':scope > colgroup > col'))
            .reduce(function (sum, col) { return sum + parseFloat(col.style.width || '0'); }, 0);
        table.style.width = width + 'px';
    }
    function addResizeHandle(host, direction, index) {
        var handle = document.createElement('button');
        handle.type = 'button';
        handle.className = 'an-resize-handle is-' + direction;
        handle.dataset.resizeDirection = direction;
        handle.dataset.resizeIndex = index;
        handle.setAttribute('aria-label', direction === 'column'
            ? 'Resize column ' + (host.textContent.trim() || index + 1)
            : 'Resize row ' + (index + 1));
        handle.title = direction === 'column'
            ? 'Drag to resize column; double-click to fit content'
            : 'Drag to resize row';
        host.appendChild(handle);
    }
    function prepareResizableTable(table) {
        var isSheet = table.classList.contains('an-sheet-grid');
        var headers = Array.from(table.tHead ? table.tHead.rows[0].cells : []);
        if (!headers.length || table.dataset.resizable === '1') return;
        var initialWidth = Math.max(table.getBoundingClientRect().width, 1);
        var count = headers.length;
        var group = document.createElement('colgroup');
        headers.forEach(function (_, index) {
            var col = document.createElement('col');
            var width = isSheet ? (index === 0 ? 48 : 176)
                : Math.max(80, Math.round(initialWidth / count));
            col.style.width = width + 'px';
            group.appendChild(col);
        });
        table.prepend(group);
        resizeTableWidth(table);
        headers.forEach(function (header, index) {
            if (!isSheet || index > 0) addResizeHandle(header, 'column', index);
        });
        if (isSheet) table.querySelectorAll('tbody .an-sheet-row-number').forEach(function (header, index) {
            addResizeHandle(header, 'row', index);
        });
        table.dataset.resizable = '1';
    }
    function prepareResizablePreview() {
        previewBody.querySelectorAll('.an-sheet-grid, .an-preview-table').forEach(prepareResizableTable);
    }
    function changeSize(handle, difference) {
        var table = handle.closest('table');
        var direction = handle.dataset.resizeDirection;
        var index = Number(handle.dataset.resizeIndex);
        if (direction === 'column') {
            var col = table.querySelectorAll(':scope > colgroup > col')[index];
            var width = Math.max(48, Math.min(800, parseFloat(col.dataset.startSize) + difference));
            col.style.width = width + 'px';
            resizeTableWidth(table);
        } else {
            var row = handle.closest('tr');
            row.style.height = Math.max(26, Math.min(400, parseFloat(row.dataset.startSize) + difference)) + 'px';
        }
    }
    function bodyColumnEdge(event) {
        var cell = event.target.closest('.an-sheet-grid tbody td');
        if (!cell || cell.colSpan !== 1) return null;
        var rect = cell.getBoundingClientRect();
        var edge = Math.max(7, 9 * previewZoom / 100);
        return event.clientX >= rect.right - edge && event.clientX <= rect.right ? cell : null;
    }
    previewBody.addEventListener('pointerdown', function (event) {
        var handle = event.target.closest('.an-resize-handle');
        var bodyCell = handle ? null : bodyColumnEdge(event);
        if ((!handle && !bodyCell) || event.button !== 0) return;
        event.preventDefault();
        event.stopPropagation();
        if (bodyCell) {
            handle = bodyCell;
            handle.dataset.resizeDirection = 'column';
            handle.dataset.resizeIndex = bodyCell.cellIndex;
        }
        var table = handle.closest('table');
        var direction = handle.dataset.resizeDirection;
        var index = Number(handle.dataset.resizeIndex);
        var col = direction === 'column' ? table.querySelectorAll(':scope > colgroup > col')[index] : null;
        var row = direction === 'row' ? handle.closest('tr') : null;
        var factor = previewZoom / 100;
        if (col) col.dataset.startSize = parseFloat(col.style.width);
        if (row) row.dataset.startSize = row.getBoundingClientRect().height / factor;
        var start = direction === 'column' ? event.clientX : event.clientY;
        handle.setPointerCapture(event.pointerId);
        handle.classList.add('is-dragging');
        document.body.classList.add('an-preview-resizing');
        document.body.classList.toggle('is-row', direction === 'row');
        function move(pointer) {
            changeSize(handle, ((direction === 'column' ? pointer.clientX : pointer.clientY) - start) / factor);
        }
        function finish() {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', finish);
            handle.removeEventListener('pointercancel', finish);
            handle.classList.remove('is-dragging');
            if (bodyCell) {
                delete handle.dataset.resizeDirection;
                delete handle.dataset.resizeIndex;
            }
            document.body.classList.remove('an-preview-resizing', 'is-row');
        }
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', finish);
        handle.addEventListener('pointercancel', finish);
    });
    previewBody.addEventListener('dblclick', function (event) {
        var handle = event.target.closest('.an-resize-handle.is-column');
        var bodyCell = handle ? null : bodyColumnEdge(event);
        if (!handle && !bodyCell) return;
        event.preventDefault();
        var table = (handle || bodyCell).closest('table');
        var index = handle ? Number(handle.dataset.resizeIndex) : bodyCell.cellIndex;
        var cells = Array.from(table.rows).map(function (row) { return row.cells[index]; })
            .filter(function (cell) { return cell && cell.colSpan === 1; });
        var width = Math.max(80, Math.min(800, cells.reduce(function (max, cell) {
            return Math.max(max, cell.scrollWidth + 16);
        }, 0)));
        table.querySelectorAll(':scope > colgroup > col')[index].style.width = width + 'px';
        resizeTableWidth(table);
    });
    previewBody.addEventListener('keydown', function (event) {
        var handle = event.target.closest('.an-resize-handle');
        if (!handle || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        var table = handle.closest('table');
        var direction = handle.dataset.resizeDirection;
        var index = Number(handle.dataset.resizeIndex);
        var step = event.shiftKey ? 20 : 5;
        if (direction === 'column') {
            var col = table.querySelectorAll(':scope > colgroup > col')[index];
            col.dataset.startSize = parseFloat(col.style.width);
            changeSize(handle, event.key === 'ArrowLeft' ? -step : step);
        } else {
            var row = handle.closest('tr');
            row.dataset.startSize = row.getBoundingClientRect().height / (previewZoom / 100);
            changeSize(handle, event.key === 'ArrowUp' ? -step : step);
        }
    });
    previewBody.addEventListener('click', function (event) {
        var tab = event.target.closest('.an-sheet-tab');
        if (tab) {
            var sheet = tab.closest('.an-sheet-preview');
            sheet.querySelectorAll('.an-sheet-tab').forEach(function (other) {
                var on = other === tab;
                other.classList.toggle('is-active', on);
                other.setAttribute('aria-selected', on ? 'true' : 'false');
                document.getElementById(other.getAttribute('aria-controls')).hidden = !on;
            });
            var first = document.getElementById(tab.getAttribute('aria-controls'))
                .querySelector('td[data-cell-ref]');
            if (first) selectCell(first);
            sheet.querySelector('.an-sheet-pages').scrollTop = 0;
            return;
        }
        var cell = event.target.closest('.an-sheet-grid td[data-cell-ref]');
        if (cell) selectCell(cell);
    });
    function openPreview(format, item) {
        shut();
        opener = item;
        previewFormat = format;
        csvHref = format === 'csv' ? item.href : null;
        previewTitle.textContent = ({ csv: 'CSV', xlsx: 'Excel', docx: 'Word', pdf: 'PDF' })[format] + ' preview';
        previewDownload.textContent = 'Download ' + ({ csv: 'CSV', xlsx: 'Excel', docx: 'Word', pdf: 'PDF' })[format];
        previewDownload.disabled = true;
        previewBody.textContent = 'Building preview…';
        zoomControls.hidden = format === 'pdf';
        setPreviewZoom(100);
        previewBody.classList.toggle('is-sheet', format === 'csv' || format === 'xlsx');
        preview.hidden = false;
        document.documentElement.classList.add('an-export-preview-open');
        previewPanel.focus();
        wrap.classList.add('is-busy');

        requestAnimationFrame(function () {
            if (preview.hidden || previewFormat !== format) return;
            try {
                if (format !== 'csv') prepareFigures(format);
            } catch (error) {
                showError();
                wrap.classList.remove('is-busy');
                return;
            }

            if (format === 'pdf') {
                var frame = document.createElement('iframe');
                frame.className = 'an-export-preview-frame';
                frame.name = 'anExportPreviewFrame';
                frame.title = 'PAPEL Analytics PDF preview';
                frame.addEventListener('load', function () { wrap.classList.remove('is-busy'); });
                previewBody.replaceChildren(frame);
                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = '_preview';
                field.value = '1';
                form.appendChild(field);
                form.target = frame.name;
                form.submit();
                form.removeAttribute('target');
                field.remove();
                previewDownload.disabled = false;
                return;
            }

            var controller = new AbortController();
            previewRequest = controller;
            var request = format === 'csv'
                ? fetch(csvHref, { credentials: 'same-origin', signal: controller.signal })
                : fetch(location.href, {
                    method: 'POST', body: (function () {
                        var data = new FormData(form);
                        data.set('_preview', '1');
                        return data;
                    })(), credentials: 'same-origin', signal: controller.signal
                });
            request.then(function (response) {
                if (!response.ok || new URL(response.url).pathname !== location.pathname) {
                    throw new Error('Preview request failed');
                }
                return response.text();
            }).then(function (result) {
                if (previewRequest !== controller || preview.hidden) return;
                if (format === 'csv') {
                    previewBody.replaceChildren(csvSheet(result));
                } else {
                    previewBody.innerHTML = result;
                }
                var first = previewBody.querySelector('.an-sheet-page:not([hidden]) td[data-cell-ref]');
                if (first) selectCell(first);
                prepareResizablePreview();
                if (format === 'docx' && typeof window.PapelAnalyticsWordEditor === 'function') {
                    wordEditor = window.PapelAnalyticsWordEditor(previewBody,
                        { orientation: <?= json_encode(export_landscape() ? 'landscape' : 'portrait') ?> });
                    setPreviewZoom(Math.min(100, Math.floor((previewBody.clientWidth - 40) / 794 * 100)));
                } else setPreviewZoom(100);
                previewDownload.disabled = false;
            }).catch(function (error) {
                if (error.name !== 'AbortError' && previewRequest === controller) showError();
            }).finally(function () {
                if (previewRequest === controller) {
                    previewRequest = null;
                    wrap.classList.remove('is-busy');
                }
            });
        });
    }

    menu.querySelectorAll('[data-export]').forEach(function (item) {
        item.addEventListener('click', function (event) {
            event.preventDefault();
            openPreview(item.getAttribute('data-export'), item);
        });
    });
    document.getElementById('anExportPreviewCancel').addEventListener('click', closePreview);
    preview.addEventListener('click', function (event) { if (event.target === preview) closePreview(); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !preview.hidden) closePreview();
    });
    previewDownload.addEventListener('click', function () {
        if (previewDownload.disabled || !previewFormat) return;
        if (previewFormat === 'csv') {
            var link = document.createElement('a');
            link.href = csvHref;
            document.body.appendChild(link);
            link.click();
            link.remove();
        } else {
            if (previewFormat === 'docx' && wordEditor) {
                var edited = document.createElement('input');
                edited.type = 'hidden'; edited.name = 'word_document'; edited.className = 'js-word-document';
                edited.value = JSON.stringify(wordEditor.serialise());
                form.appendChild(edited);
            }
            form.removeAttribute('target');
            form.submit();
        }
        closePreview();
    });
})();
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
