<?php
/**
 * Shared review console: the student dashboard's layout, for the four roles
 * that read papers rather than write them.
 *
 * A review desk is the student dashboard with different verbs: same search row,
 * same tab strip with counts, same card list, same right-hand sidebar. Only the
 * questions differ, whose papers, which statuses, and what the reader is
 * allowed to do about it. So the page itself lives here once and each role
 * describes its own desk in a $RC array, rather than four near-copies drifting
 * apart the first time one of them is touched.
 *
 * The calling page authenticates, handles its own POSTs, fills in $RC, and
 * includes this file last. Everything from <!DOCTYPE> to </html> comes from
 * here.
 *
 * $RC keys
 *   self      string  this page's filename, for links back to itself
 *   title     string  browser title and breadcrumb leaf
 *   role      string  the role's name, shown to the reader
 *   blurb     string  one line on what this desk is for
 *   scope     array   ['sql'=>, 'params'=>, 'types'=>]: narrows every query to
 *                     the papers this role may see. May reference rp and u.
 *   tabs      array   key => ['label'=>, 'where'=>, 'act'=>bool, 'src'=>string]
 *                     'act' marks the tab whose cards carry review controls.
 *                     'src' is 'papers' (the default) or 'archive': see
 *                     rc_from_for() for why a tab can read a second table.
 *   review    string  'faculty' | 'admin' | null: the review_level written to
 *                     approval_workflow; null makes the desk read-only.
 *   checklist bool    show the section checklist when approving
 *   primary   array   ['href'=>,'icon'=>,'label'=>]: the button beside search
 *   quick     array   list of ['href'=>,'icon'=>,'label'=>,'desc'=>]: the
 *                     role's own tools, listed first in the sidebar
 *   cards     array   list of ['id'=>,'title'=>,'html'=>]: extra sidebar cards
 *                     for powers that are this role's alone
 *   card_extra callable  fn(array $paper, string $src): string, returning extra
 *                     controls on a card, again for one role's own powers. $src
 *                     is the active tab's source, so a control can tell a live
 *                     paper from an archived one.
 *   empty     array   ['icon'=>,'text'=>] for an empty list
 */

if (!isset($RC) || !is_array($RC)) {
    throw new RuntimeException('review_console.php requires a $RC configuration array.');
}
require_once ROOT_PATH.'/config/workflow.php';
require_once ROOT_PATH.'/config/gdrive_config.php';

$RC += [
    'role'      => '',
    'blurb'     => '',
    'review'    => null,
    'checklist' => false,
    'primary'   => null,
    'quick'     => [],
    'cards'     => [],
    'card_extra'=> null,
    'empty'     => ['icon' => 'inbox', 'text' => 'Nothing here right now.'],
    'scope'     => ['sql' => '1', 'params' => [], 'types' => ''],
];

$conn = $conn ?? db();
$u    = $u    ?? current_user();

$rc_self  = $RC['self'];
$rc_scope = $RC['scope'] + ['sql' => '1', 'params' => [], 'types' => ''];

/**
 * Where a tab's papers are read from.
 *
 * Archiving is not a status: it moves the row to papers_archive and deletes it
 * from research_papers, so an archived paper cannot be found by any WHERE on
 * the live table. A tab marked 'src' => 'archive' reads the other table
 * instead. Every column these queries name travels with the paper (see
 * ArchiveRepository::ARCHIVED_COLUMNS), so only the FROM changes.
 *
 * The uploader is joined loosely there on purpose: papers_archive is meant to
 * outlive what it names, and an inner join would quietly drop an archived paper
 * whose author's account has gone.
 */
function rc_from_for(string $src): string {
    return $src === 'archive'
        ? "FROM papers_archive rp LEFT JOIN users u ON u.user_id = rp.uploaded_by"
        : "FROM research_papers rp JOIN users u ON u.user_id = rp.uploaded_by";
}

/* Which tab is open decides which table everything below reads, so this is
   settled before the first query rather than with the rest of the view state. */
$rc_tabs = $RC['tabs'];
$tab     = isset($_GET['tab']) && isset($rc_tabs[$_GET['tab']]) ? $_GET['tab'] : array_key_first($rc_tabs);
$rc_src  = $rc_tabs[$tab]['src'] ?? 'papers';
$rc_from = rc_from_for($rc_src);

/* ---- Search suggestions ------------------------------------------------
   Titles first, then author names, both inside this role's scope: a desk
   must never suggest a paper its owner is not allowed to open. */
if (isset($_GET['ajax_search'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    try {
        $term = "%$q%";
        $out  = [];
        $sql  = "SELECT DISTINCT rp.title AS v $rc_from
                 WHERE ({$rc_scope['sql']}) AND rp.title LIKE ? LIMIT 6";
        $s = $conn->prepare($sql);
        $s->bind_param($rc_scope['types'].'s', ...array_merge($rc_scope['params'], [$term]));
        $s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $out[] = $r['v'];
        $s->close();

        if (count($out) < 8) {
            $sql = "SELECT DISTINCT u.full_name AS v $rc_from
                    WHERE ({$rc_scope['sql']}) AND u.full_name LIKE ? LIMIT 4";
            $s = $conn->prepare($sql);
            $s->bind_param($rc_scope['types'].'s', ...array_merge($rc_scope['params'], [$term]));
            $s->execute();
            foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                if (!in_array($r['v'], $out, true)) $out[] = $r['v'];
            }
            $s->close();
        }
        echo json_encode(array_values(array_unique($out)));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

/* ---- View state -------------------------------------------------------- */
// Shared by the filter bar's own select and the export's "filtered by" line
// further down, so the two can never disagree about what a type is called.
$rcTypeLabels = ['capstone' => 'Capstone Project', 'research' => 'Research Paper', 'thesis' => 'Thesis'];

$search        = trim($_GET['q'] ?? '');
$filter_type   = trim($_GET['type'] ?? '');
$filter_prog   = trim($_GET['program'] ?? '');
$filter_year   = (int)($_GET['year'] ?? 0);
$filter_month  = (int)($_GET['month'] ?? 0);
$filter_day    = (int)($_GET['day'] ?? 0);
// The student's own year-and-section string (e.g. "4-1"), not the paper's
// research year above: a free-text match rather than a dropdown, since
// there is no fixed list of sections to offer.
$filter_section = trim($_GET['section'] ?? '');
// The school year the student's section was for (e.g. "26-27"): a
// different thing from $filter_year above, which is when the paper itself
// was completed. Its own parameter, so the two never fight over one.
$filter_ay = trim($_GET['ay'] ?? '');
/* Three orders, whitelisted, so the choice can be dropped straight into
   ORDER BY. 'wait' is the one a desk with a backlog actually needs: the
   paper that has sat longest without a decision, first. A tab that can be
   acted on opens that way unless the reader says otherwise; the reading
   tabs keep newest-first, which is how a library is browsed. */
$rc_sorts   = ['desc', 'asc', 'wait'];
$rc_act_tab = !empty($rc_tabs[$tab]['act']) && $RC['review'];
$sort_param = in_array($_GET['sort'] ?? '', $rc_sorts, true)
            ? $_GET['sort']
            : ($rc_act_tab ? 'wait' : 'desc');
// Kept for the markup that still reads it; the clause itself is below.
$sort_dir   = $sort_param === 'asc' ? 'ASC' : 'DESC';
$rc_order   = [
    'desc' => 'COALESCE(rp.research_date, rp.upload_date) DESC',
    'asc'  => 'COALESCE(rp.research_date, rp.upload_date) ASC',
    'wait' => 'rc_waiting_since ASC',
][$sort_param];

/* How long a paper has been sitting where it is. The last decision anybody
   recorded is when it arrived at the desk that holds it now; a paper nobody
   has touched has been waiting since it was handed in. Selected as well as
   sorted on, so the card can say it in days. */
$rc_waiting = 'COALESCE((SELECT MAX(aw.reviewed_at) FROM approval_workflow aw'
            . ' WHERE aw.paper_id = rp.paper_id), rp.upload_date)';

/* How many rows at a time. Ten is a short page for a desk holding two
   hundred papers, and the reader is the one who knows which they want. */
$rc_per_sizes = [10, 25, 50];
$per_page = (int)($_GET['per'] ?? 0);
if (!in_array($per_page, $rc_per_sizes, true)) $per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* ---- WHERE ------------------------------------------------------------- */
$where  = "({$rc_scope['sql']})";
$params = $rc_scope['params'];
$types  = $rc_scope['types'];

$where .= " AND (" . $rc_tabs[$tab]['where'] . ")";

if ($search) {
    $where .= " AND (rp.title LIKE ? OR rp.keywords LIKE ? OR rp.abstract LIKE ? OR u.full_name LIKE ?)";
    $t = "%$search%"; array_push($params, $t, $t, $t, $t); $types .= 'ssss';
}
if ($filter_type)    { $where .= " AND rp.paper_type = ?"; $params[] = $filter_type; $types .= 's'; }
if ($filter_prog)    { $where .= " AND u.program = ?";     $params[] = $filter_prog; $types .= 's'; }
if ($filter_section) { $where .= " AND u.section LIKE ?";  $params[] = "%$filter_section%"; $types .= 's'; }
if ($filter_ay)      { $where .= " AND u.academic_year = ?"; $params[] = $filter_ay; $types .= 's'; }
if ($filter_year) { $where .= " AND COALESCE(YEAR(rp.research_date), rp.year) = ?"; $params[] = $filter_year; $types .= 'i'; }
if ($filter_month >= 1 && $filter_month <= 12) { $where .= " AND MONTH(rp.research_date) = ?"; $params[] = $filter_month; $types .= 'i'; }
if ($filter_day   >= 1 && $filter_day   <= 31) { $where .= " AND DAY(rp.research_date) = ?";   $params[] = $filter_day;   $types .= 'i'; }

/* ---- Counts per tab ---------------------------------------------------- */
/* One query per table rather than one for the lot: the tabs no longer all
   count rows of the same thing. */
$counts   = [];
$rc_bySrc = [];
foreach ($rc_tabs as $k => $def) {
    $counts[$k] = 0;
    $rc_bySrc[$def['src'] ?? 'papers'][$k] = $def['where'];
}
foreach ($rc_bySrc as $src => $defs) {
    $sums = [];
    foreach ($defs as $k => $w) $sums[] = "SUM($w) AS `$k`";
    $cs = $conn->prepare("SELECT " . implode(', ', $sums) . " " . rc_from_for($src)
                       . " WHERE ({$rc_scope['sql']})");
    if ($rc_scope['types'] !== '') $cs->bind_param($rc_scope['types'], ...$rc_scope['params']);
    $cs->execute();
    if ($row = $cs->get_result()->fetch_assoc()) {
        foreach ($defs as $k => $_) $counts[$k] = (int)($row[$k] ?? 0);
    }
    $cs->close();
}

/* ---- Export: CSV, Excel, Word, PDF: the same four formats and the same
   includes/*_writer.php classes analytics/analytics_dashboard.php exports
   with, applied to a desk's own papers instead of the whole repository's
   figures. There are no charts to carry here, which is what let the other
   three formats stay a plain POST rather than analytics' canvas-capturing
   one.

   $where is exactly what built the list above (this tab, this search,
   these filters) so export always means what is currently in view, never
   the whole desk, the same promise analytics' own CSV link makes. CSV is a
   plain GET link for the same reason analytics' is: reading data out is not
   a state change, so it does not need a token and can be right-clicked,
   opened in a new tab, or bookmarked. The other three post, since a
   database export several kilobytes wide belongs behind CSRF the way any
   other POST-only action here does. */
$rcExportFormat = $_GET['export'] ?? ($_POST['export'] ?? '');
if (in_array($rcExportFormat, ['csv', 'xlsx', 'docx', 'pdf'], true)) {
    if ($rcExportFormat !== 'csv') csrf_verify();

    $exq = $conn->prepare("SELECT rp.title, rp.paper_type, rp.current_status, rp.upload_date,
                                   COALESCE(NULLIF(u.full_name, ''), NULLIF(rp.author_names, ''),
                                            'Account removed') AS student,
                                   u.program AS student_program,
                                   u.section AS student_section, u.academic_year AS student_ay
                            $rc_from WHERE $where
                            ORDER BY COALESCE(rp.research_date, rp.upload_date) DESC");
    if ($types !== '') $exq->bind_param($types, ...$params);
    $exq->execute();
    $exportRows = $exq->get_result()->fetch_all(MYSQLI_ASSOC);
    $exq->close();

    $filterBits = [];
    if ($filter_type)    $filterBits[] = $rcTypeLabels[$filter_type] ?? $filter_type;
    if ($filter_prog)    $filterBits[] = function_exists('program_code') ? program_code($filter_prog) : $filter_prog;
    if ($filter_section) $filterBits[] = 'Section ' . $filter_section;
    if ($filter_ay)      $filterBits[] = 'A.Y. ' . $filter_ay;
    if ($filter_year)    $filterBits[] = (string)$filter_year;
    if ($search)          $filterBits[] = 'matching "' . $search . '"';
    $filterLine = $filterBits
        ? 'Filtered by ' . implode(' · ', $filterBits)
        : 'Everything under ' . ($rc_tabs[$tab]['label'] ?? 'this view');

    $summaryRows = [];
    foreach ($rc_tabs as $k => $def) $summaryRows[] = [$def['label'], $counts[$k] ?? 0];

    $exportTitle = 'PAPEL ' . ($RC['role'] ?: $RC['title'] ?: 'Review Desk');
    $when  = date('j F Y, g:i A');
    $stamp = date('Y-m-d');
    $slug  = preg_replace('/[^a-z0-9]+/i', '_', strtolower($RC['role'] ?: 'review_desk'));

    $tableRows = array_map(function ($r) {
        return [
            substr((string)$r['upload_date'], 0, 10),
            $r['title'],
            $r['student'],
            function_exists('program_code') ? program_code((string)$r['student_program']) : (string)$r['student_program'],
            (string)$r['student_section'],
            (string)$r['student_ay'],
            ucwords(str_replace('_', ' ', (string)$r['current_status'])),
        ];
    }, $exportRows);
    $tableHead = ['Submitted', 'Title', 'Student', 'Course', 'Grade and Section', 'Academic Year', 'Status'];

    if ($rcExportFormat === 'csv') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '_' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, [$exportTitle . ', ' . $when]);
        fputcsv($out, [$filterLine]);
        fputcsv($out, []);
        fputcsv($out, ['SUMMARY']);
        foreach ($summaryRows as $r) fputcsv($out, $r);
        fputcsv($out, []);
        fputcsv($out, ['PAPERS']);
        fputcsv($out, $tableHead);
        foreach ($tableRows as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }

    if ($rcExportFormat === 'xlsx') {
        require_once ROOT_PATH . '/includes/xlsx_writer.php';
        $x = new XlsxWriter('Review Desk');
        $x->widths([14, 42, 22, 12, 16, 12, 16]);
        $x->title($exportTitle, $filterLine . '  ·  ' . $when);
        $x->section('Summary');
        $x->headRow(['Status', 'Papers']);
        foreach ($summaryRows as $r) $x->row($r);
        $x->section('Papers');
        $x->headRow($tableHead);
        foreach ($tableRows as $r) $x->row($r);
        $bytes = $x->output();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $slug . '_' . $stamp . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    if ($rcExportFormat === 'docx') {
        require_once ROOT_PATH . '/includes/docx_writer.php';
        $d = new DocxWriter();
        $d->title($exportTitle, $filterLine . '  ·  ' . $when);
        $d->heading('Summary');
        $d->table([['Status', 3, 'left'], ['Papers', 1, 'right']], $summaryRows);
        $d->heading('Papers');
        $d->table([
            ['Submitted', 2, 'left'], ['Title', 6, 'left'], ['Student', 3, 'left'],
            ['Course', 2, 'left'], ['Section', 2, 'left'], ['A.Y.', 2, 'left'], ['Status', 2, 'left'],
        ], $tableRows);
        $bytes = $d->output();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $slug . '_' . $stamp . '.docx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    // pdf
    require_once ROOT_PATH . '/includes/pdf_writer.php';
    $pdf = new PdfWriter($exportTitle, $filterLine . '  -  ' . $when);
    $pdf->heading('Summary');
    $pdf->table([['Status', 320, 'l'], ['Papers', 179, 'r']], $summaryRows);
    $pdf->heading('Papers');
    $pdf->table([
        ['Submitted', 60, 'l'], ['Title', 160, 'l'], ['Student', 90, 'l'],
        ['Course', 55, 'l'], ['Section', 45, 'l'], ['A.Y.', 40, 'l'], ['Status', 49, 'l'],
    ], $tableRows);
    $bytes = $pdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $slug . '_' . $stamp . '.pdf"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/* ---- Total for the active view ----------------------------------------- */
$ct = $conn->prepare("SELECT COUNT(*) AS total $rc_from WHERE $where");
if ($types !== '') $ct->bind_param($types, ...$params);
$ct->execute();
$total_papers = (int)($ct->get_result()->fetch_assoc()['total'] ?? 0);
$ct->close();
$total_pages = max(1, (int)ceil($total_papers / $per_page));
$start_item  = $offset + 1;
$end_item    = min($offset + $per_page, $total_papers);

/* ---- The page of results ------------------------------------------------ */
$sql = "SELECT rp.paper_id, rp.title, rp.author_names, rp.year, rp.keywords, rp.abstract,
               rp.upload_date, rp.research_date, rp.paper_type, rp.manuscript_type, rp.current_status,
               rp.file_path, rp.gdrive_file_id,
               $rc_waiting AS rc_waiting_since,
               u.user_id AS author_id, u.program AS author_program,
               /* The archive is joined loosely, because papers_archive is meant to
                  outlive the accounts it names: a paper whose author has since been
                  deleted has no u.full_name at all. The card prints this in three
                  places and e() refuses null, so the fallback is made here, once,
                  rather than guarded at each of them. The paper's own author list is
                  the honest second answer; failing that, say so plainly. */
               COALESCE(NULLIF(u.full_name, ''), NULLIF(rp.author_names, ''),
                        'Account removed') AS author_name
        $rc_from
        WHERE $where
        ORDER BY $rc_order
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types.'ii', ...array_merge($params, [$per_page, $offset]));
$stmt->execute();
$papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---- Reviewers, feedback and attachments for this page ------------------ */
$reviewers = [];   // paper_id => review_level => name
$feedback  = [];   // paper_id => latest decline feedback
$docs      = [];   // paper_id => list of supporting documents
$manuscripts = []; // paper_id => format => the paper's manuscript file rows
$scopes    = [];   // paper_id => which sections the author chose to submit (paper_scope_info)
if ($papers) {
    $ids = array_column($papers, 'paper_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $bt  = str_repeat('i', count($ids));

    $rv = $conn->prepare(
        "SELECT aw.paper_id, aw.review_level, us.full_name
         FROM approval_workflow aw JOIN users us ON us.user_id = aw.reviewer_id
         WHERE aw.paper_id IN ($in) AND aw.status = 'approved'
         ORDER BY aw.reviewed_at ASC");
    $rv->bind_param($bt, ...$ids); $rv->execute();
    foreach ($rv->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $reviewers[$r['paper_id']][$r['review_level']] = $r['full_name'];
    }
    $rv->close();

    $fb = $conn->prepare(
        "SELECT paper_id, feedback FROM approval_workflow
         WHERE paper_id IN ($in) AND status = 'declined' AND feedback IS NOT NULL
         ORDER BY reviewed_at ASC");
    $fb->bind_param($bt, ...$ids); $fb->execute();
    foreach ($fb->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $feedback[$r['paper_id']] = $r['feedback'];   // last wins = most recent
    }
    $fb->close();

    $dq = $conn->prepare(
        "SELECT doc_id, paper_id, document_type, " . supporting_doc_name_sql() . ", file_path, gdrive_file_id
         FROM supporting_documents WHERE paper_id IN ($in) ORDER BY doc_id ASC");
    $dq->bind_param($bt, ...$ids); $dq->execute();
    foreach ($dq->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $docs[$r['paper_id']][] = $r;
    }
    $dq->close();

    // The manuscript files of every card on this page, in one query.
    $manuscripts = paper_manuscript_rows($ids);   // paper_id => format => row

    /* Which sections each author chose to submit, in one query for the page. The
       main query does not carry imrad_content, which holds every section in
       full, so it is fetched here for the ids on screen and reduced to the few
       words the card needs. The archive keeps the same column (it is on
       ArchiveRepository's list), so an archived paper reads from its own table. */
    $scopeTable = $rc_src === 'archive' ? 'papers_archive' : 'research_papers';
    $sq = $conn->prepare("SELECT paper_id, imrad_content FROM $scopeTable WHERE paper_id IN ($in)");
    $sq->bind_param($bt, ...$ids); $sq->execute();
    foreach ($sq->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $scopes[(int)$r['paper_id']] = paper_scope_info($r['imrad_content']);
    }
    $sq->close();
}

/* ---- Sidebar filter vocabularies --------------------------------------- */
$yq = $conn->prepare("SELECT DISTINCT COALESCE(YEAR(rp.research_date), rp.year) AS y $rc_from
                      WHERE ({$rc_scope['sql']}) AND (rp.research_date IS NOT NULL OR rp.year IS NOT NULL)
                      ORDER BY y DESC LIMIT 30");
if ($rc_scope['types'] !== '') $yq->bind_param($rc_scope['types'], ...$rc_scope['params']);
$yq->execute();
$years = array_filter(array_column($yq->get_result()->fetch_all(MYSQLI_ASSOC), 'y'));
$yq->close();

$pq = $conn->prepare("SELECT DISTINCT u.program AS p $rc_from
                      WHERE ({$rc_scope['sql']}) AND u.program IS NOT NULL AND u.program <> ''
                      ORDER BY p ASC LIMIT 30");
if ($rc_scope['types'] !== '') $pq->bind_param($rc_scope['types'], ...$rc_scope['params']);
$pq->execute();
$programs = array_column($pq->get_result()->fetch_all(MYSQLI_ASSOC), 'p');
$pq->close();

$ayq = $conn->prepare("SELECT DISTINCT u.academic_year AS ay $rc_from
                      WHERE ({$rc_scope['sql']}) AND u.academic_year IS NOT NULL AND u.academic_year <> ''
                      ORDER BY ay DESC LIMIT 30");
if ($rc_scope['types'] !== '') $ayq->bind_param($rc_scope['types'], ...$rc_scope['params']);
$ayq->execute();
$academicYears = array_column($ayq->get_result()->fetch_all(MYSQLI_ASSOC), 'ay');
$ayq->close();

/** The query string carried across tabs, filters and pagination. */
function rc_qs(array $over = []): string {
    $base = [
        'tab'  => $_GET['tab']  ?? null, 'q'       => $_GET['q']       ?? null,
        'type' => $_GET['type'] ?? null, 'program' => $_GET['program'] ?? null,
        'year' => $_GET['year'] ?? null, 'month'   => $_GET['month']   ?? null,
        'day'  => $_GET['day']  ?? null, 'sort'    => $_GET['sort']    ?? null,
        'page' => $_GET['page'] ?? null, 'section' => $_GET['section'] ?? null,
        'ay'   => $_GET['ay']   ?? null, 'per'     => $_GET['per']     ?? null,
    ];
    return http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== ''));
}

/**
 * Where a reviewer opens a paper's file or one of its attachments: PAPEL's own
 * viewer, in a tab of its own. This used to be the Google Drive link, which sat
 * outside everything else on the site: no rule about who may read the file
 * applied to it, and it was the one place the Drive address was handed out. The
 * viewer asks app/paper_file.php, which applies the same rules as every paper
 * page, and the desk's own scopes (an adviser's students, the published and
 * archived papers the Head and the Director read) are inside them.
 */
function rc_open_link(string $fileSrc): string {
    return paper_viewer_url($fileSrc);
}

$ajax_results = isset($_GET['ajax']) && $_GET['ajax'] === '1';
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($RC['title']) ?> · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/browse_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Whether the filter strip is folded, applied before the first paint:
   read afterwards, a folded desk would draw the strip and then take it
   away, and the whole list under it would jump. */
(function () {
    var v;
    try { v = localStorage.getItem('papel_desk_tools'); } catch (e) {}
    if (v === 'folded') document.documentElement.classList.add('rc-tools-folded');
})();
</script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ---- What this desk is, said once at the top ---- */
.rc-intro { margin-bottom: 1.25rem; }
.rc-intro h1 {
    font-family: var(--font-head); font-size: 1.25rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .2rem;
}
.rc-intro p { font-size: .8125rem; color: var(--grey); margin: 0; line-height: 1.6; }

/* ---- Filter bar, above the search field ----
   Styled after analytics/analytics_dashboard.php's own .an-filters (same
   layout, same rounded cream strip) so a reviewer who has used one has
   already used the other. It differs in one way on purpose: there is no
   Apply button. Analytics is a report someone sits and studies, so batching
   several changes before re-running it is normal; a desk is scanned in
   passing, and every change here is meant to be seen immediately, so each
   one submits itself (see the change/submit handlers a little further down,
   which route this the same way the sidebar's own #filterForm already does:
   an AJAX swap of #mainCol and #sidebarCol, not a full reload). */
.rc-filters { position: relative; background: var(--cream); border-radius: var(--r-card, 8px); padding: .75rem .875rem; margin-bottom: 1.25rem; }

/* ---- Folding the top of the desk away ----
   One chevron for the whole preamble: who the desk belongs to, what it is
   for, and the four filters with the Export button. A reviewer who already
   knows all three reads none of them again, and on a laptop they are the
   difference between seeing four papers and seeing eight.

   It sits in a row of its own above them rather than inside either, because
   what it hides includes the thing it would otherwise sit in. The row is
   26px whichever way it is set, so opening and closing moves the list and
   nothing else. */
.rc-fold-bar { display: flex; justify-content: flex-end; min-height: 26px; }
.rc-fold {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .15rem .25rem; border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon); cursor: pointer;
    transition: background .15s;
}
.rc-fold:hover { background: var(--cream); }
.rc-fold:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.rc-fold .material-symbols-outlined { transition: transform .18s ease; }
/* Folded, the chevron is the only thing left saying what it does, so it
   says it. Open, the heading beside it says it already. */
.rc-fold-word { display: none; font-size: .75rem; font-weight: 500; }

html.rc-tools-folded .rc-intro,
html.rc-tools-folded .rc-filter-row,
html.rc-tools-folded #rcExportForm { display: none; }
html.rc-tools-folded .rc-fold .material-symbols-outlined { transform: rotate(180deg); }
html.rc-tools-folded .rc-fold-word { display: inline; }
/* The strip stays only to carry the chips: a filtered desk that looks
   unfiltered is worse than one control too many. With the row gone it is a
   line of chips, so it is padded as one rather than as a panel. */
html.rc-tools-folded .rc-filters { padding: .3rem .5rem; margin-bottom: .75rem; }
html.rc-tools-folded .rc-filters:not(:has(.rc-active)) { display: none; }
html.rc-tools-folded .rc-active { margin-top: 0; padding-top: 0; border-top: 0; }
.rc-filter-row { display: flex; flex-wrap: wrap; gap: .625rem; align-items: flex-end; }
/* Un-boxes the <form> so its .rc-filter fields sit directly in the flex row
   above, as though the form were never there: form-ness (submission, field
   association) is unaffected, only its own box is. */
.rc-filter-form { display: contents; }
/* Each field grows to fill the row instead of sitting at its minimum width
   with the leftover space going nowhere: "maximize the size of this". */
.rc-filter { display: flex; flex-direction: column; gap: .2rem; min-width: 9rem; flex: 1 1 9rem; }
.rc-filter-section { flex: 1 1 12rem; }
/* The one field that does not grow, and the reason any of the others can:
   free space goes to their flex-basis instead of sitting after this one.
   Held to the row's own bottom edge like the others, but pinned to its
   right end rather than following the last field in from the left. */
.rc-filter-action { flex: 0 0 auto; margin-left: auto; }
.rc-filter label {
    font-size: .625rem; text-transform: uppercase; letter-spacing: .04em; color: var(--grey);
}
/* One fixed height for every control in the bar (field or button) so nothing
   reads as slightly taller or shorter than its neighbours. */
.rc-filter select, .rc-filter input, .rc-filter-action button {
    height: 2.375rem; box-sizing: border-box;
}
.rc-filter select, .rc-filter input {
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .4rem .6rem; font-family: var(--font-body); font-size: .8125rem;
    color: var(--ink); background: var(--white); width: 100%;
}
.rc-filter select {
    appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 2rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right .75rem center;
}
.rc-filter input:focus, .rc-filter select:focus { border-color: var(--maroon); outline: none; }
/* What is currently narrowing the list, and how to undo it. */
.rc-active {
    display: flex; flex-wrap: wrap; align-items: center; gap: .375rem;
    margin-top: .625rem; padding-top: .625rem; border-top: 1px solid var(--border);
    font-size: .6875rem; color: var(--grey);
}
.rc-chip {
    display: inline-flex; align-items: center; gap: .25rem;
    background: var(--white); border: 1px solid var(--maroon); border-radius: var(--r-control, 4px);
    padding: .15rem .5rem; font-size: .6875rem; color: var(--maroon);
}
.rc-chip a { color: var(--maroon); text-decoration: none; font-weight: 600; line-height: 1; }
.rc-clear { margin-left: auto; color: var(--maroon); font-size: .6875rem; }

/* ---- Export dropdown ----
   Styled after analytics/analytics_dashboard.php's own .an-export, so the
   two controls behave and look like one feature rather than two that
   happen to share a name. */
.rc-export { position: relative; }
.rc-export-menu {
    position: absolute; top: calc(100% + 6px); right: 0; z-index: 1100;
    width: 15rem; padding: .35rem;
    background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md, 0 6px 18px rgba(51,0,0,.14));
}
.rc-export-menu[hidden] { display: none; }
.rc-export-menu > a,
.rc-export-menu > button {
    display: flex; align-items: center; gap: .5rem; width: 100%;
    padding: .5rem .6rem; border: none; border-radius: var(--r-control, 4px);
    background: none; font-family: inherit; text-align: left; text-decoration: none;
    color: var(--ink); cursor: pointer;
}
.rc-export-menu > a:hover,
.rc-export-menu > button:hover { background: var(--cream); }
.rc-export-menu .material-symbols-outlined { color: var(--maroon); flex: 0 0 auto; }
.rc-export-menu strong { display: block; font-weight: 600; }
.rc-export-menu small { display: block; font-size: .6875rem; color: var(--grey); margin-top: .05rem; }
.rc-export.is-busy #rcExportBtn { opacity: .65; pointer-events: none; }

/* ---- Review controls on a card ---- */
.rc-actions { margin-top: .875rem; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

/* ---- The line above the list ----
   What is on screen, how much of it to show, and the pager. Each is given
   room of its own: unspaced they read as one long sentence with numbers
   scattered through it. */
.toolbar-right { gap: .5rem; flex: 0 0 auto; }
.rc-count { font-size: .75rem; color: var(--grey); white-space: nowrap; }
.rc-per {
    display: inline-flex; align-items: center; gap: .125rem;
    font-size: .75rem; color: var(--grey); white-space: nowrap;
    /* The rule marks where the count ends and the control begins. */
    padding-left: .5rem; border-left: 1px solid var(--border);
}
.rc-per-label { margin-right: .25rem; }
.rc-per a, .rc-per-on {
    min-width: 1.35rem; padding: .1rem .25rem;
    border-radius: var(--r-control, 4px);
    text-align: center; text-decoration: none;
}
.rc-per a { color: var(--grey); }
.rc-per a:hover { color: var(--maroon); background: var(--cream); }
/* Marked by weight and colour, with no box of its own. It used to be a white
   chip with a border, which read as a chip while this strip was tinted; the
   console sits on the page now, and a white box on a white page is an outline
   around nothing. */
.rc-per-on {
    color: var(--maroon); font-weight: 600;
    padding: .05rem .2rem;
}
/* The arrows are the pager, so they sit apart from the numbers that are not. */
.toolbar-right .toolbar-btn:first-of-type { margin-left: .25rem; }
/* What actually decides whether the toolbar fits is how many tabs the desk has,
   and that is not something a width query can see: the console is the same width
   on the Director's two-tab desk as on the Coordinator's five-tab one. So the
   count gives way from the fourth tab onwards, measured on the markup itself.
   It is the one of the three that can go, since the list below already shows
   what is on screen and the pager says where in it you are; the Director and
   anyone else with room keeps it. */
.dash-bar:has(.dash-tabs .dash-tab:nth-child(4)) .rc-count { display: none; }
.dash-bar:has(.dash-tabs .dash-tab:nth-child(4)) .rc-per { padding-left: 0; border-left: 0; }

/* And on a console too narrow even for two tabs and the count, measured on the
   console rather than the window: a desk sits beside a sidebar and has far less
   room than the viewport suggests, so the old window rule only noticed at 700px,
   by which point the toolbar had been wrapping for hundreds of pixels. */
@container desk (max-width: 620px) {
    .rc-count { display: none; }
    .rc-per { padding-left: 0; border-left: 0; }
}
/* Where container queries are not understood, the old window rule still
   applies, so such a browser behaves exactly as it did before. */
@supports not (container-type: inline-size) {
    @media (max-width: 700px) {
        .rc-count { display: none; }
        .rc-per { padding-left: 0; border-left: 0; }
    }
}

/* ---- How long it has been waiting ----
   Only shown where somebody is expected to act. On a reading tab it would
   be a number with nothing to do about it. */
.rc-waited {
    display: inline-flex; align-items: center; gap: .25rem;
    font-size: .75rem; color: var(--grey);
}
.rc-waited .material-symbols-outlined { font-size: 15px; }
/* A week is the point at which it stops being a queue and starts being a
   backlog, so that is where it is marked rather than merely stated. */
.rc-waited.is-stale { color: var(--bad-text); }

/* ---- Rows: the parts only a desk has ----
   The layout itself is in console_shell.php, with the card it rearranges.
   These are the pieces no other list carries. */

/* How long it has waited is the one thing a queue is read by, so it goes to
   the front of the meta line and is never the part that gets clipped. */
html[data-view="rows"] .paper-list .paper-meta .rc-waited { order: -1; flex: 0 0 auto; }

/* Smaller buttons, so the decision still fits beside the title rather than
   taking half the row from it. */
html[data-view="rows"] .paper-list .rc-actions .btn-sm-maroon,
html[data-view="rows"] .paper-list .rc-actions .btn-sm-outline {
    padding: .3rem .55rem; font-size: .7rem;
}
html[data-view="rows"] .paper-list .rc-actions { margin-top: 0; flex-wrap: nowrap; }
/* Named as direct children as well as inside the body wrapper: that wrapper
   is built by script, and a page of results fetched by AJAX is laid out
   before the script gets to it. */
html[data-view="rows"] .paper-list .paper-card > .rc-feedback,
html[data-view="rows"] .paper-list .paper-card > .rc-docs,
html[data-view="rows"] .paper-list .paper-card > .rc-reviewers { display: none; }
/* The title is the link to the record, so the link beside the status would
   only be saying it twice; the row already carries the decision. */
html[data-view="rows"] .paper-list .card-status .paper-action { display: none; }

.rc-actions .rc-spacer { margin-left: auto; }
.rc-docs { display: flex; gap: .375rem; flex-wrap: wrap; margin-top: .625rem; }
.rc-doc-chip {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .25rem .5rem; border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    font-size: .6875rem; color: var(--ink); text-decoration: none; background: var(--white);
}
.rc-doc-chip:hover { border-color: var(--soft-maroon); color: var(--maroon); background: var(--cream); }
.rc-doc-chip .material-symbols-outlined { font-size: 14px; }
.rc-none { font-size: .6875rem; color: var(--grey); }

/* ---- The approve / return dialog ---- */
.rc-dialog { max-width: 34rem; }
.rc-dialog .papel-dialog-body { max-height: 65vh; overflow-y: auto; }
.rc-lead { margin: 0 0 .875rem; }
.rc-paper-name { font-weight: 500; color: var(--maroon); }
.rc-field { display: block; margin-top: .875rem; }
.rc-field span.rc-label { display: block; font-size: .75rem; font-weight: 500; color: var(--ink); margin-bottom: .35rem; }
.rc-field textarea {
    width: 100%; border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .625rem .75rem; font-family: var(--font-body); font-size: .8125rem;
    color: var(--ink); resize: vertical; background: var(--white);
}
.rc-field textarea:focus { outline: none; border-color: var(--maroon); }
.rc-hint { display: block; font-size: .6875rem; color: var(--grey); margin-top: .35rem; }
.rc-checklist { border: 1px solid var(--border); border-radius: var(--r-card, 8px); padding: .75rem .875rem; }
.rc-check-group + .rc-check-group { margin-top: .75rem; padding-top: .75rem; border-top: 1px solid var(--border); }
.rc-check-head {
    display: flex; align-items: center; justify-content: space-between; gap: .5rem;
    font-size: .75rem; font-weight: 500; color: var(--maroon); margin-bottom: .35rem;
}
.rc-check-all {
    background: none; border: none; padding: 0; cursor: pointer;
    font-family: inherit; font-size: .6875rem; color: var(--grey); text-decoration: underline;
}
.rc-check-all:hover { color: var(--maroon); }
.rc-check-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .25rem .75rem; }
.rc-check { display: flex; align-items: center; gap: .4rem; font-size: .75rem; color: var(--ink); cursor: pointer; }
.rc-check input { accent-color: var(--maroon); }

/* The sidebar's own tools use .sidebar-link from browse_console.php, so they
   match the Browse card everywhere else on the site. Only the small print that
   sits under a card's heading is styled here. */
/* A card body is a list of links; anything else in it lines up with them
   rather than sitting at the card's edge. */
.rc-card-note { font-size: .6875rem; color: var(--grey); line-height: 1.45; margin: 0 0 .25rem; padding: 0 .75rem; }
.sidebar-card-body form { margin: 0; }
.sidebar-card-body .filter-select { width: calc(100% - 1.5rem); margin: .25rem .75rem; }

@media (max-width: 600px) {
    /* One field per line: the same reason .rc-filter-action drops its
       margin-left:auto here: a right-pinned button on a row of its own
       would just leave the same wasted space on the left instead. */
    .rc-filter, .rc-filter-action { flex: 1 1 100%; margin-left: 0; min-width: 0; }
    .rc-actions .rc-spacer { margin-left: 0; }
}
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e($rc_self) ?>"><?= e($RC['title']) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current"><?= e($rc_tabs[$tab]['label']) ?></span>
    </div>
</div>

<main class="wrap layout">
    <?php /* data-card-console marks a page whose #mainCol renders .paper-card
             items, for browse_console_js.php's buildCardDetailSection():
             this div itself survives every AJAX swap (only its innerHTML is
             replaced), unlike .paper-card, which vanishes on whatever tab
             the current filters happen to return zero results for. Without
             a marker that survives that, "Card Details" would flicker out
             of Quick Settings on an empty tab like Waiting for you. */ ?>
    <div class="main-col" id="mainCol" data-card-console="1" data-view-console="1">
        <?php ob_start(); ?>

        <?php /* Forwarding, returning and approving all set a message and land
                 back here. Until this was added the desk never read it, so it
                 sat in the session and turned up on whichever page the reviewer
                 opened next. */ ?>
        <?php require_once ROOT_PATH.'/includes/flash_banner.php'; flash_banner(); ?>

        <?php /* Folds the heading, the blurb and the filter strip away
                 together, and the answer is kept per device beside the view
                 and the density: a desk somebody reads rather than filters
                 should not have to be folded again on every visit. The state
                 lives on <html> (set before the first paint, at the head of
                 this file), so it also survives the AJAX swaps that replace
                 this column whole on every tab and filter change. */ ?>
        <div class="rc-fold-bar">
            <button type="button" class="rc-fold" id="rcFoldTools"
                    aria-controls="rcIntro rcFilterRow" aria-expanded="true">
                <?php /* The desk's own name is not repeated here: the crumb bar
                         above already carries it, and a heading somebody has just
                         hidden should not come back in miniature. What the word
                         says is what the chevron will do. */ ?>
                <span class="rc-fold-word" aria-hidden="true">Show heading and filters</span>
                <span class="material-symbols-outlined mi-18">expand_less</span>
            </button>
        </div>

        <?php if ($RC['role'] || $RC['blurb']): ?>
        <div class="rc-intro" id="rcIntro">
            <?php if ($RC['role']): ?><h1><?= e($RC['role']) ?></h1><?php endif; ?>
            <?php if ($RC['blurb']): ?><p><?= e($RC['blurb']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        /* Program / Paper Type / Grade and Section / Academic Year, styled
           after Analytics: see the .rc-filters rule above for why this
           applies itself instead of waiting for an Apply click. The Paper
           Type options are the same three the sidebar Filter card already
           offers (both post to the same 'type' parameter), so the two stay
           interchangeable rather than drifting into two different ideas of
           what a paper type is.

           The paper's own research year (what the sidebar's Date section
           still filters by) is not offered here any more: Academic Year,
           beside Grade and Section, is the year that actually pairs with a
           section: the year the student was in when they were in it, not
           the year their paper happened to be finished. It is carried as a
           hidden field below so it survives a change made here instead of
           being silently cleared. */
        ?>
        <div class="rc-filters">
            <div class="rc-filter-row" id="rcFilterRow">
            <?php /* display:contents on the form itself (see .rc-filter-form
                     below) puts its fields directly into this flex row, so
                     the export control (its own <form>, since Excel/Word/PDF
                     post) can sit beside them as an ordinary sibling. Forms
                     cannot nest, which ruled out the simpler option of just
                     dropping that markup inside this one. */ ?>
            <form class="rc-filter-form js-rc-filter-form" id="rcFiltersForm" action="<?= e($rc_self) ?>" method="get">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <?php if ($filter_year): ?><input type="hidden" name="year" value="<?= e($filter_year) ?>"><?php endif; ?>
                <?php if ($sort_param === 'asc'): ?><input type="hidden" name="sort" value="asc"><?php endif; ?>
                <?php if ($filter_month): ?><input type="hidden" name="month" value="<?= e($filter_month) ?>"><?php endif; ?>
                <?php if ($filter_day): ?><input type="hidden" name="day" value="<?= e($filter_day) ?>"><?php endif; ?>

                <div class="rc-filter">
                    <label for="rcType">Paper type</label>
                    <select name="type" id="rcType">
                        <option value="">All types</option>
                        <?php foreach ($rcTypeLabels as $tv => $tl): ?>
                            <option value="<?= e($tv) ?>" <?= $filter_type === $tv ? 'selected' : '' ?>><?= e($tl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($programs): ?>
                <div class="rc-filter">
                    <label for="rcProgram">Course</label>
                    <select name="program" id="rcProgram">
                        <option value="">All courses</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= e($p) ?>" <?= $filter_prog === $p ? 'selected' : '' ?>>
                                <?= e(function_exists('program_code') ? program_code($p) : $p) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="rc-filter rc-filter-section">
                    <label for="rcSection">Grade and Section</label>
                    <input type="search" name="section" id="rcSection" value="<?= e($filter_section) ?>" placeholder="e.g. 4-1">
                </div>
                <div class="rc-filter">
                    <label for="rcAy">Academic Year</label>
                    <select name="ay" id="rcAy">
                        <option value="">All years</option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= e($ay) ?>" <?= $filter_ay === $ay ? 'selected' : '' ?>><?= e($ay) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php /* CSV is a plain link (see the export block above for why)
                     so it needs nothing from JS. The other three are posted by
                     the click handler further down, onto the hidden form that
                     follows this widget. */ ?>
            <div class="rc-export rc-filter-action" id="rcExport">
                <button type="button" class="btn-sm-outline" id="rcExportBtn"
                        aria-haspopup="menu" aria-expanded="false">
                    <span class="material-symbols-outlined mi-18">download</span>
                    Export
                    <span class="material-symbols-outlined mi-18">expand_more</span>
                </button>
                <div class="rc-export-menu" id="rcExportMenu" role="menu" hidden>
                    <a role="menuitem" href="<?= e($rc_self) ?>?<?= rc_qs(['export' => 'csv']) ?>">
                        <span class="material-symbols-outlined mi-18">table_view</span>
                        <span><strong>CSV</strong><small>Plain table, opens anywhere</small></span>
                    </a>
                    <button type="button" role="menuitem" data-export="xlsx">
                        <span class="material-symbols-outlined mi-18">grid_on</span>
                        <span><strong>Excel</strong><small>Styled sheet, ready to filter</small></span>
                    </button>
                    <button type="button" role="menuitem" data-export="docx">
                        <span class="material-symbols-outlined mi-18">description</span>
                        <span><strong>Word</strong><small>A written report</small></span>
                    </button>
                    <button type="button" role="menuitem" data-export="pdf">
                        <span class="material-symbols-outlined mi-18">picture_as_pdf</span>
                        <span><strong>PDF</strong><small>Ready to print or hand in</small></span>
                    </button>
                </div>
            </div>
            </div>
            <form method="post" id="rcExportForm" data-no-pill action="<?= e($rc_self) ?>?<?= rc_qs() ?>" hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="export" id="rcExportFormat" value="">
            </form>

            <?php
            $rcChips = [];
            if ($filter_prog)    $rcChips[] = ['label' => (function_exists('program_code') ? program_code($filter_prog) : $filter_prog), 'href' => e($rc_self) . '?' . rc_qs(['program' => null, 'page' => null])];
            if ($filter_type)    $rcChips[] = ['label' => $rcTypeLabels[$filter_type] ?? $filter_type, 'href' => e($rc_self) . '?' . rc_qs(['type' => null, 'page' => null])];
            if ($filter_section) $rcChips[] = ['label' => 'Section ' . $filter_section, 'href' => e($rc_self) . '?' . rc_qs(['section' => null, 'page' => null])];
            if ($filter_ay)      $rcChips[] = ['label' => 'A.Y. ' . $filter_ay, 'href' => e($rc_self) . '?' . rc_qs(['ay' => null, 'page' => null])];
            if ($filter_year)    $rcChips[] = ['label' => (int)$filter_year, 'href' => e($rc_self) . '?' . rc_qs(['year' => null, 'page' => null])];
            if ($search)          $rcChips[] = ['label' => '"' . $search . '"', 'href' => e($rc_self) . '?' . rc_qs(['q' => null, 'page' => null])];
            ?>
            <?php if ($rcChips): ?>
            <div class="rc-active">
                <span>Filtered by</span>
                <?php foreach ($rcChips as $chip): ?>
                    <span class="rc-chip"><?= e($chip['label']) ?> <a href="<?= $chip['href'] ?>" aria-label="Remove this filter">&times;</a></span>
                <?php endforeach; ?>
                <a class="rc-clear" href="<?= e($rc_self) ?>?tab=<?= e($tab) ?>">Clear all</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="dash-shell">

            <div class="dash-top">
                <div class="search-shell">
                    <form action="<?= e($rc_self) ?>" method="get" id="searchForm">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                        <?php if ($filter_type): ?><input type="hidden" name="type" value="<?= e($filter_type) ?>"><?php endif; ?>
                        <?php if ($sort_param): ?><input type="hidden" name="sort" value="<?= e($sort_param) ?>"><?php endif; ?>
                        <div class="search-form">
                            <button type="submit" class="btn-search-icon" aria-label="Search">
                                <span class="material-symbols-outlined">search</span>
                            </button>
                            <input class="search-input" type="search" name="q" id="searchInput"
                                   data-suggest-url="<?= e($rc_self) ?>?ajax_search=1"
                                   value="<?= e($search) ?>" placeholder="Search titles, authors, keywords" autocomplete="off">
                        </div>
                        <div id="searchSuggestions" class="suggestions-dropdown"></div>
                    </form>
                </div>
                <?php if ($RC['primary']): ?>
                    <a href="<?= e($RC['primary']['href']) ?>" class="btn-upload">
                        <span class="material-symbols-outlined"><?= e($RC['primary']['icon']) ?></span>
                        <?= e($RC['primary']['label']) ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="dash-bar">
                <div class="dash-tabs">
                    <?php foreach ($rc_tabs as $key => $def): ?>
                        <a class="dash-tab <?= $tab === $key ? 'active' : '' ?>"
                           href="<?= e($rc_self) ?>?<?= rc_qs(['tab' => $key, 'page' => null]) ?>">
                            <?= e($def['label']) ?> <span class="count"><?= (int)$counts[$key] ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a class="toolbar-btn" href="<?= e($rc_self) ?>?tab=<?= e($tab) ?>" title="Refresh (clears search and filters)">
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
                        </div>
                    </div>
                </div>

                <div class="toolbar-right">
                    <?php /* Three things that were running into one another: what is on
                             screen, how much of it to show, and how to move. Said in that
                             order, with a rule between the first two and the arrows kept
                             to the end where a pager belongs. */ ?>
                    <span class="rc-count">Showing <?= $total_papers ? $start_item : 0 ?>&ndash;<?= $end_item ?> of <?= number_format($total_papers) ?></span>
                    <?php /* A plain link per size rather than a select: the list is fetched
                             by AJAX from the same query string everything else here uses. */ ?>
                    <span class="rc-per">
                        <span class="rc-per-label">Show</span>
                        <?php foreach ($rc_per_sizes as $size): ?>
                            <?php if ($size === $per_page): ?>
                                <span class="rc-per-on"><?= $size ?></span>
                            <?php else: ?>
                                <a href="<?= e($rc_self) ?>?<?= rc_qs(['per' => $size, 'page' => null]) ?>"><?= $size ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </span>
                    <?php if ($page > 1): ?>
                        <a class="toolbar-btn" href="<?= e($rc_self) ?>?<?= rc_qs(['page' => $page - 1]) ?>" aria-label="Previous page"><span class="material-symbols-outlined">chevron_left</span></a>
                    <?php else: ?>
                        <span class="toolbar-btn disabled"><span class="material-symbols-outlined">chevron_left</span></span>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a class="toolbar-btn" href="<?= e($rc_self) ?>?<?= rc_qs(['page' => $page + 1]) ?>" aria-label="Next page"><span class="material-symbols-outlined">chevron_right</span></a>
                    <?php else: ?>
                        <span class="toolbar-btn disabled"><span class="material-symbols-outlined">chevron_right</span></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="paper-list is-scrollable">
                <?php foreach ($papers as $r): ?>
                    <?php
                    $pid       = (int)$r['paper_id'];
                    $status    = (string)$r['current_status'];
                    $fb        = $feedback[$pid] ?? null;
                    $steps     = workflow_progress_steps($status, (bool)$fb);
                    $done      = 0; foreach ($steps as $s) if ($s['state'] === 'done') $done++;
                    $fill      = count($steps) > 1 ? ($done - 1) / (count($steps) - 1) * 100 : 0;
                    $fill      = max(0, min(100, $fill));
                    $rv        = $reviewers[$pid] ?? [];
                    /* One link per file that is there: "Open paper" for a paper
                       with one, "Open IMRAD" and "Open Full Manuscript" for a
                       paper handed in both ways. */
                    $mEntries  = paper_manuscript_entries($pid, $r, $manuscripts[$pid] ?? []);
                    $openLinks = [];
                    foreach ($mEntries as $m) {
                        if (!$m['src']) continue;
                        $openLinks[] = [
                            'href'  => rc_open_link($m['src']),
                            'label' => count($mEntries) > 1
                                ? 'Open ' . manuscript_format_label((string)$m['format'])
                                : 'Open paper',
                        ];
                    }
                    $actionable = !empty($rc_tabs[$tab]['act']) && $RC['review'];
                    ?>
                    <article class="paper-card">
                        <div class="card-head">
                            <div class="paper-info">
                                <?php /* The title opens the full record: what was filed, the sections
                                         as written, the files, and the checklist. Reviewers get it
                                         live; the read-only desks get the same page without controls. */ ?>
                                <h2 class="paper-title">
                                    <?php /* The review page reads research_papers, which an archived
                                             paper has left, so its record is opened on the repository
                                             page instead: that one falls back to papers_archive. */ ?>
                                    <?php if ($rc_src === 'archive'): ?>
                                        <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>"><?= e($r['title']) ?></a>
                                    <?php elseif (in_array($u['user_role'], ['faculty', 'admin'], true)): ?>
                                        <a href="<?= e(BASE_URL) ?>/app/review_paper.php?id=<?= $pid ?>"><?= e($r['title']) ?></a>
                                    <?php elseif ($status === 'approved'): ?>
                                        <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>"><?= e($r['title']) ?></a>
                                    <?php else: ?>
                                        <span><?= e($r['title']) ?></span>
                                    <?php endif; ?>
                                </h2>
                                <p class="paper-authors"><?= e($r['author_names'] ?: $r['author_name']) ?></p>
                                <div class="paper-meta">
                                    <span><?= e(function_exists('paper_type_label') ? paper_type_label($r['paper_type']) : ucfirst((string)$r['paper_type'])) ?></span>
                                    <?php if ($r['author_program']): ?>
                                        <span class="sep">•</span><span><?= e($r['author_program']) ?></span>
                                    <?php endif; ?>
                                    <span class="sep">•</span>
                                    <span>Submitted <?= e(date('M j, Y', strtotime($r['upload_date']))) ?></span>
                                    <?php /* "Abstract only" or "3 of 6 sections", for a paper whose author
                                             left sections out on purpose. Nothing for a complete one. The
                                             markup is built and escaped by paper_scope_chip(). */
                                    $scopeChip = paper_scope_chip($scopes[(int)$r['paper_id']] ?? paper_scope_info(null));
                                    if ($scopeChip !== ''): ?>
                                        <span class="sep">•</span><?= $scopeChip ?>
                                    <?php endif; ?>
                                    <?php /* Only where somebody is expected to act: on a reading tab
                                             this would be a number with nothing to do about it. */ ?>
                                    <?php if ($actionable && !empty($r['rc_waiting_since'])):
                                        $waitedDays = (int)floor((time() - strtotime($r['rc_waiting_since'])) / 86400); ?>
                                        <span class="rc-waited <?= $waitedDays >= 7 ? 'is-stale' : '' ?>">
                                            <span class="material-symbols-outlined">schedule</span>
                                            <?= $waitedDays < 1 ? 'Waiting today'
                                                : 'Waiting ' . $waitedDays . ' day' . ($waitedDays === 1 ? '' : 's') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-status">
                                <span>Status: <span class="status-value"><?= e(workflow_status_badge_text($status, (bool)$fb)) ?></span></span>
                                <?php foreach ($openLinks as $ol): ?>
                                    <a class="paper-action" href="<?= e($ol['href']) ?>" target="_blank" rel="noopener"><?= e($ol['label']) ?></a>
                                <?php endforeach; ?>
                                <?php /* Not offered on an archived paper: it is out of the public
                                         repository, which is what archiving it meant. */ ?>
                                <?php if ($status === 'approved' && $rc_src !== 'archive'): ?>
                                    <a class="paper-action" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>">View in repository</a>
                                <?php elseif ($rc_src === 'archive'): ?>
                                    <a class="paper-action" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>">Open record</a>
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
                                <div>Student: <span class="who"><?= e($r['author_name']) ?></span></div>
                                <?php if (!empty($rv['faculty'])): ?>
                                    <div>Research Adviser: <span class="who"><?= e($rv['faculty']) ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($rv['admin'])): ?>
                                    <div>Research Coordinator: <span class="who"><?= e($rv['admin']) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($fb): ?>
                            <div class="card-feedback">Revision feedback: <?= e($fb) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($docs[$pid])): ?>
                            <div class="rc-docs">
                                <?php foreach ($docs[$pid] as $d): ?>
                                    <?php if (!paper_file_url($d['gdrive_file_id'] ?? null, $d['file_path'] ?? null)) continue; ?>
                                    <a class="rc-doc-chip" href="<?= e(rc_open_link(paper_file_src('doc', (int)$d['doc_id']))) ?>" target="_blank" rel="noopener">
                                        <span class="material-symbols-outlined">attach_file</span>
                                        <?= e(supporting_doc_label($d['document_type'] ?? '', $d['document_name'] ?? null)) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php $extra = $RC['card_extra'] ? ($RC['card_extra'])($r, $rc_src) : ''; ?>
                        <?php if ($actionable || $extra): ?>
                            <div class="rc-actions">
                                <?php if ($actionable): ?>
                                    <button type="button" class="btn-sm-maroon js-rc-act"
                                            data-act="approve" data-paper="<?= $pid ?>"
                                            data-title="<?= e($r['title']) ?>" data-student="<?= e($r['author_name']) ?>"
                                            data-format="<?= e((string)($r['manuscript_type'] ?? '')) ?>">
                                        <span class="material-symbols-outlined mi-18">check_circle</span>
                                        <?= e($RC['approve_label'] ?? 'Approve') ?>
                                    </button>
                                    <button type="button" class="btn-sm-outline js-rc-act"
                                            data-act="decline" data-paper="<?= $pid ?>"
                                            data-title="<?= e($r['title']) ?>" data-student="<?= e($r['author_name']) ?>"
                                            data-format="<?= e((string)($r['manuscript_type'] ?? '')) ?>">
                                        <span class="material-symbols-outlined mi-18">undo</span>
                                        Return with feedback
                                    </button>
                                <?php endif; ?>
                                <?= $extra ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <?php if (!$papers): ?>
                    <?php /* An empty queue is the normal state of a review desk, not an
                             error, so each tab says what its own emptiness means. */ ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined"><?= e($rc_tabs[$tab]['empty_icon'] ?? $RC['empty']['icon']) ?></span>
                        <p><?= e($rc_tabs[$tab]['empty'] ?? $RC['empty']['text']) ?></p>
                        <?php if ($search || $filter_type || $filter_prog || $filter_year || $filter_month || $filter_day): ?>
                            <a href="<?= e($rc_self) ?>?tab=<?= e($tab) ?>">Clear filters</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Held back until the sidebar is captured too, so one AJAX round trip
        // can refresh both columns.
        $main_col_html = ob_get_clean();
        echo $main_col_html;
        ?>
    </div>

    <aside class="sidebar-right" id="sidebarCol">
        <?php ob_start(); ?>
        <?php /* First, and the same card the repository and the student
                 dashboard show, so moving between a desk and the archive does
                 not change what the sidebar offers. */ ?>
        <?php require_once ROOT_PATH.'/includes/browse_card.php'; ?>
        <?= browse_card_html($u ?? current_user()) ?>

        <?php if ($RC['quick']): ?>
        <div class="sidebar-card" id="quickCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="quickCard">What you can do</button>
                <span class="card-header-tools">
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="quickCard" aria-label="Collapse"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <div class="sidebar-card-body">
                <?php /* Plain text links, the same as the Browse card on the student
                         dashboard and the public repository: a sidebar list reads
                         faster without an icon beside every line. The longer
                         description stays on hover. */ ?>
                <?php
                /* The Browse card above now carries the repository link on
                   every console, so a desk that also lists it here would show
                   it twice. Filtered rather than removed from the four desks'
                   own lists, so this keeps holding if one of them changes. */
                $browse_seen = [];
                foreach (browse_card_links($u ?? current_user()) as $bl) {
                    $browse_seen[strtolower(trim($bl['label']))] = true;
                    $path = (string)parse_url($bl['href'], PHP_URL_PATH);
                    $browse_seen[strtolower(basename($path))] = true;
                }
                foreach ($RC['quick'] as $q):
                    $qLabel = strtolower(trim((string)($q['label'] ?? '')));
                    $qFile  = strtolower(basename((string)parse_url((string)($q['href'] ?? ''), PHP_URL_PATH)));
                    if (empty($q['external'])
                        && (isset($browse_seen[$qLabel]) || isset($browse_seen[$qFile]))) {
                        continue;
                    }
                ?>
                    <a class="sidebar-link" href="<?= e($q['href']) ?>"
                       <?= !empty($q['desc']) ? 'title="'.e($q['desc']).'"' : '' ?>
                       <?= !empty($q['external']) ? ' target="_blank" rel="noopener"' : '' ?>><?= e($q['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($RC['cards'] as $card): ?>
        <div class="sidebar-card" id="<?= e($card['id']) ?>">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="<?= e($card['id']) ?>"><?= e($card['title']) ?></button>
                <span class="card-header-tools">
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="<?= e($card['id']) ?>" aria-label="Collapse"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <div class="sidebar-card-body"><?= $card['html'] ?></div>
        </div>
        <?php endforeach; ?>

        <div class="sidebar-card" id="filterCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="filterCard">Filter</button>
                <span class="card-header-tools">
                    <a class="card-tool" href="<?= e($rc_self) ?>?tab=<?= e($tab) ?>" title="Clear all filters" aria-label="Clear all filters"><span class="material-symbols-outlined">filter_alt_off</span></a>
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="filterCard" aria-label="Collapse Filter"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <form id="filterForm" class="js-rc-filter-form" action="<?= e($rc_self) ?>" method="get">
                <?php if ($search): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                <input type="hidden" name="tab" value="<?= e($tab) ?>">

                <div class="filter-section">
                    <span class="filter-section-label">Paper Type</span>
                    <label class="filter-radio">
                        <input type="radio" name="type" value="" <?= $filter_type === '' ? 'checked' : '' ?>> All Types
                    </label>
                    <?php foreach (['capstone' => 'Capstone Project', 'research' => 'Research Paper', 'thesis' => 'Thesis'] as $tv => $tl): ?>
                        <label class="filter-radio">
                            <input type="radio" name="type" value="<?= e($tv) ?>" <?= $filter_type === $tv ? 'checked' : '' ?>> <?= e($tl) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php if ($programs): ?>
                <div class="filter-section">
                    <span class="filter-section-label">Program</span>
                    <select name="program" class="filter-select">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= e($p) ?>" <?= $filter_prog === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="filter-section">
                    <span class="filter-section-label">Order By</span>
                    <?php /* Waiting longest is what a queue is read by, so it is first and
                             it is what an actionable tab opens on. The other two order by
                             the paper's own date, which is how a shelf is browsed. */ ?>
                    <label class="filter-radio"><input type="radio" name="sort" value="wait" <?= $sort_param === 'wait' ? 'checked' : '' ?>> Waiting longest</label>
                    <label class="filter-radio"><input type="radio" name="sort" value="desc" <?= $sort_param === 'desc' ? 'checked' : '' ?>> Newest first</label>
                    <label class="filter-radio"><input type="radio" name="sort" value="asc"  <?= $sort_param === 'asc'  ? 'checked' : '' ?>> Oldest first</label>
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
                'sidebar' => $sidebar_html,
                'crumb'   => $rc_tabs[$tab]['label'],
                'title'   => $RC['title'].' · '.APP_NAME,
            ]);
            exit;
        }
        echo $sidebar_html;
        ?>
    </aside>
</main>

<!-- Any form marked js-confirm-form is asked about first, in the site's own
     dialog rather than the browser's. The form carries its own wording, so one
     dialog covers whatever a role can do to a paper. -->
<div class="papel-dialog-backdrop" id="rcConfirm" role="dialog" aria-modal="true" aria-labelledby="rcConfirmTitle">
  <div class="papel-dialog">
    <div class="papel-dialog-head">
      <span class="material-symbols-outlined" id="rcConfirmIcon">help</span>
      <h2 id="rcConfirmTitle">Are you sure?</h2>
    </div>
    <div class="papel-dialog-body" id="rcConfirmBody"></div>
    <div class="papel-dialog-foot">
      <button type="button" class="btn-sm-outline" id="rcConfirmNo">Cancel</button>
      <button type="button" class="btn-sm-maroon" id="rcConfirmYes">Continue</button>
    </div>
  </div>
</div>

<?php if ($RC['review']): ?>
<!-- One dialog serves both decisions. Approving moves a paper forward and
     returning it sends it back to the student, so both are confirmed here
     rather than firing straight off a click in a list. -->
<div class="papel-dialog-backdrop" id="rcDialog" role="dialog" aria-modal="true" aria-labelledby="rcDialogTitle">
  <div class="papel-dialog rc-dialog">
    <form method="post" action="<?= e($rc_self) ?>" id="rcForm">
      <?= csrf_field() ?>
      <input type="hidden" name="paper_id" id="rcPaperId" value="">
      <input type="hidden" name="action"   id="rcAction"  value="">
      <div class="papel-dialog-head">
        <span class="material-symbols-outlined" id="rcDialogIcon">check_circle</span>
        <h2 id="rcDialogTitle">Approve this paper?</h2>
      </div>
      <div class="papel-dialog-body">
        <p class="rc-lead" id="rcLead"></p>

        <?php if ($RC['checklist']): ?>
        <div class="rc-checklist" id="rcChecklist">
            <div class="rc-check-group" data-group-box="imrad">
                <div class="rc-check-head">
                    <span>IMRaD sections present</span>
                    <button type="button" class="rc-check-all" data-group="imrad">Tick all</button>
                </div>
                <div class="rc-check-grid" data-group="imrad">
                    <label class="rc-check"><input type="checkbox" name="imrad_intro"> Introduction</label>
                    <label class="rc-check"><input type="checkbox" name="imrad_method"> Methodology</label>
                    <label class="rc-check"><input type="checkbox" name="imrad_result"> Results</label>
                    <label class="rc-check"><input type="checkbox" name="imrad_discussion"> Discussion</label>
                    <label class="rc-check"><input type="checkbox" name="imrad_references"> References</label>
                </div>
            </div>
            <div class="rc-check-group" data-group-box="full">
                <div class="rc-check-head">
                    <span>Full manuscript chapters</span>
                    <button type="button" class="rc-check-all" data-group="full">Tick all</button>
                </div>
                <div class="rc-check-grid" data-group="full">
                    <label class="rc-check"><input type="checkbox" name="full_ch1"> Chapter 1</label>
                    <label class="rc-check"><input type="checkbox" name="full_ch2"> Chapter 2</label>
                    <label class="rc-check"><input type="checkbox" name="full_ch3"> Chapter 3</label>
                    <label class="rc-check"><input type="checkbox" name="full_ch4"> Chapter 4</label>
                    <label class="rc-check"><input type="checkbox" name="full_ch5"> Chapter 5</label>
                    <label class="rc-check"><input type="checkbox" name="full_references"> References</label>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <label class="rc-field">
            <span class="rc-label" id="rcFeedbackLabel">Note for the student</span>
            <textarea name="feedback" id="rcFeedback" rows="4"></textarea>
            <span class="rc-hint" id="rcHint"></span>
        </label>
      </div>
      <div class="papel-dialog-foot">
        <button type="button" class="btn-sm-outline" id="rcCancel">Cancel</button>
        <button type="submit" class="btn-sm-maroon" id="rcOk">Approve</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

var SELF = <?= json_encode($rc_self) ?>;

// ===== Search, tabs, filters and pagination swap the results column instead
// of reloading the page: the same behaviour the student dashboard has. =====
var mainCol = document.getElementById('mainCol');
var loadingTimer = null;

function setLoading(on) {
    if (!mainCol) return;
    clearTimeout(loadingTimer);
    if (on) loadingTimer = setTimeout(function () { mainCol.classList.add('is-loading'); }, 200);
    else mainCol.classList.remove('is-loading');
}

/* The chevron over the filter strip. Delegated, because the column it
   sits in is replaced wholesale by every AJAX swap, and what it toggles
   is a class on <html>, which those swaps never touch. */
function rcSyncFold() {
    var folded = document.documentElement.classList.contains('rc-tools-folded');
    var btn = document.getElementById('rcFoldTools');
    if (!btn) return;
    btn.setAttribute('aria-expanded', folded ? 'false' : 'true');
    var say = folded ? 'Show the heading, filters and export'
                     : 'Hide the heading, filters and export';
    btn.title = say;
    btn.setAttribute('aria-label', say);
}
document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#rcFoldTools')) return;
    var folded = document.documentElement.classList.toggle('rc-tools-folded');
    try { localStorage.setItem('papel_desk_tools', folded ? 'folded' : 'open'); } catch (err) {}
    rcSyncFold();
});
/* Straight away when the page is already parsed, which it is: this block sits
   at the end of the body, and waiting for DOMContentLoaded there meant a desk
   that opened folded announced itself as expanded. */
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', rcSyncFold);
else rcSyncFold();

function loadResults(url, push) {
    if (!mainCol) { window.location.href = url; return; }
    setLoading(true);
    fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + 'ajax=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('failed'); return r.json(); })
        .then(function (data) {
            mainCol.innerHTML = data.html;
            if (data.sidebar) {
                var side = document.getElementById('sidebarCol');
                if (side) side.innerHTML = data.sidebar;
            }
            setLoading(false);
            /* Rebuilds and re-ticks the whole of Quick Settings, the switch
               between cards and rows included: the panel holding it lives
               inside the column this swap replaces. */
            if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
            rcSyncFold();          // the strip came back with the column
            if (data.crumb) {
                var crumb = document.querySelector('.crumb-current');
                if (crumb) crumb.textContent = data.crumb;
            }
            if (data.title) document.title = data.title;
            if (push !== false) history.pushState({ papelAjax: true }, '', url);
        })
        .catch(function () { window.location.href = url; });
}

// The list, the tabs and the sidebar are all replaced on each swap, so every
// control inside them is bound by delegation rather than directly.
document.addEventListener('click', function (e) {
    var link = e.target.closest('#mainCol a[href], #filterCard a[href]');
    if (!link) return;
    var href = link.getAttribute('href');
    if (href && href.indexOf(SELF) === 0) { e.preventDefault(); loadResults(href); }
});

document.addEventListener('submit', function (e) {
    // #searchForm is the field beside the primary button; .js-rc-filter-form
    // is both filter bars (the top one has a search box of its own, whose
    // Enter key would otherwise submit a real GET and leave the AJAX path).
    var form = e.target.closest('#searchForm, .js-rc-filter-form');
    if (!form) return;
    e.preventDefault();
    loadResults(SELF + '?' + new URLSearchParams(new FormData(form)).toString());
});

document.addEventListener('change', function (e) {
    var form = e.target.closest('.js-rc-filter-form');
    if (!form) return;
    if (e.target.matches('input[type="radio"], input[type="search"], select')) {
        loadResults(SELF + '?' + new URLSearchParams(new FormData(form)).toString());
    }
});
window.addEventListener('popstate', function () { loadResults(window.location.href, false); });

// ===== Export dropdown =====
// Same open/close/outside-click behaviour as analytics_dashboard.php's own
// #anExport: Excel/Word/PDF simply set the hidden form's format and submit
// it, with none of that page's canvas-to-image work, since a review desk has
// no charts to carry along.
/* Every handler here is delegated off document and every element is looked up
   at the moment of the click, because all of this markup lives inside #mainCol,
   which loadResults() replaces wholesale on every tab, filter, page-size and
   refresh click. Bound directly, as this was, the listeners went out with the
   nodes they were attached to: the first AJAX refresh of the desk left an
   Export button that opened nothing and could not be revived without reloading
   the page. */
(function () {
    function el(id) { return document.getElementById(id); }

    function shut() {
        var menu = el('rcExportMenu'), btn = el('rcExportBtn');
        if (menu) menu.hidden = true;
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        var btn = e.target.closest('#rcExportBtn');
        if (btn) {
            e.stopPropagation();
            var menu = el('rcExportMenu');
            if (!menu) return;
            menu.hidden = !menu.hidden;
            btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
            return;
        }

        var item = e.target.closest('#rcExportMenu button[data-export]');
        if (item) {
            var wrap = el('rcExport'), form = el('rcExportForm'), fmt = el('rcExportFormat');
            if (!wrap || !form || !fmt) return;
            shut();
            wrap.classList.add('is-busy');
            fmt.value = item.getAttribute('data-export');
            form.submit();
            /* The page never navigates for a download, so nothing else would
               bring the button back: the same reason includes/loading_bar.php
               gives its own beforeunload counter a timed fallback. */
            setTimeout(function () { wrap.classList.remove('is-busy'); }, 2500);
            return;
        }

        var wrapNow = el('rcExport');
        if (wrapNow && !wrapNow.contains(e.target)) shut();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') shut();
    });
})();

// ===== Confirm-before-submit, for anything a role can do to a paper =====
var cfg   = document.getElementById('rcConfirm');
var cfgOk = document.getElementById('rcConfirmYes');
var cfgNo = document.getElementById('rcConfirmNo');
var pendingForm = null;

function closeConfirm() {
    cfg.classList.remove('open');
    document.body.style.overflow = '';
    pendingForm = null;
}

document.addEventListener('submit', function (e) {
    var form = e.target.closest('.js-confirm-form');
    if (!form || form.dataset.confirmed === '1') return;
    e.preventDefault();
    pendingForm = form;
    document.getElementById('rcConfirmTitle').textContent = form.dataset.title || 'Are you sure?';
    document.getElementById('rcConfirmIcon').textContent  = form.dataset.icon  || 'help';
    document.getElementById('rcConfirmBody').textContent  = form.dataset.body  || '';
    cfgOk.textContent = form.dataset.ok || 'Continue';
    cfgNo.textContent = form.dataset.cancel || 'Cancel';
    cfg.classList.add('open');
    document.body.style.overflow = 'hidden';
    cfgNo.focus();                     // the safe option is the one under the cursor
});

cfgOk.addEventListener('click', function () {
    if (!pendingForm) return;
    var form = pendingForm;
    form.dataset.confirmed = '1';
    closeConfirm();
    form.submit();
});
cfgNo.addEventListener('click', closeConfirm);
cfg.addEventListener('click', function (e) { if (e.target === cfg) closeConfirm(); });
document.addEventListener('keydown', function (e) {
    // Prevented, so the document panel behind it (includes/pdf_dock.php) stays open.
    if (e.key === 'Escape' && cfg.classList.contains('open')) { e.preventDefault(); closeConfirm(); }
});

<?php if ($RC['review']): ?>
// ===== The review decision =====
var dlg   = document.getElementById('rcDialog');
var dTitle = document.getElementById('rcDialogTitle');
var dIcon  = document.getElementById('rcDialogIcon');
var dLead  = document.getElementById('rcLead');
var dList  = document.getElementById('rcChecklist');
var dFbLbl = document.getElementById('rcFeedbackLabel');
var dFb    = document.getElementById('rcFeedback');
var dHint  = document.getElementById('rcHint');
var dOk    = document.getElementById('rcOk');
var dPaper = document.getElementById('rcPaperId');
var dAct   = document.getElementById('rcAction');

var APPROVE_LEAD = <?= json_encode($RC['approve_lead'] ?? 'This paper moves on to the next stage of review.') ?>;
var APPROVE_BTN  = <?= json_encode($RC['approve_label'] ?? 'Approve') ?>;

function closeDialog() {
    dlg.classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-rc-act');
    if (!btn) return;

    var approving = btn.dataset.act === 'approve';
    dPaper.value = btn.dataset.paper;
    dAct.value   = btn.dataset.act;

    dTitle.textContent = approving ? APPROVE_BTN + ' this paper?' : 'Return this paper?';
    dIcon.textContent  = approving ? 'check_circle' : 'undo';
    dLead.innerHTML    = (approving ? APPROVE_LEAD : 'This sends the paper back to the student as a draft so they can revise and submit it again.')
        + ' <span class="rc-paper-name"></span>';
    dLead.querySelector('.rc-paper-name').textContent = '“' + btn.dataset.title + '” by ' + btn.dataset.student + '.';

    if (dList) {
        dList.style.display = approving ? '' : 'none';
        /* A paper written in IMRaD has no numbered chapters, so it is not asked
           about them: an unticked "Chapter 4" would otherwise show on the
           student's record as though part of the paper were missing. */
        var imradOnly = (btn.dataset.format || '').toUpperCase() === 'IMRAD';
        var fullBox = dList.querySelector('[data-group-box="full"]');
        if (fullBox) {
            fullBox.style.display = imradOnly ? 'none' : '';
            fullBox.querySelectorAll('input[type="checkbox"]').forEach(function (b) {
                if (imradOnly) b.checked = false;      // nothing hidden is submitted as ticked
            });
        }
    }

    // Returning a paper without saying why leaves the student guessing, so the
    // note is required there and optional when approving.
    dFbLbl.textContent = approving ? 'Note for the student (optional)' : 'What needs to change';
    dHint.textContent  = approving ? 'Anything you write here is passed on with the approval.'
                                   : 'Required. The student sees this on their dashboard.';
    dFb.required = !approving;
    dFb.value = '';
    dOk.textContent = approving ? APPROVE_BTN : 'Return to student';

    dlg.classList.add('open');
    document.body.style.overflow = 'hidden';
    dFb.focus();
});

document.getElementById('rcCancel').addEventListener('click', closeDialog);
dlg.addEventListener('click', function (e) { if (e.target === dlg) closeDialog(); });
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && dlg.classList.contains('open')) { e.preventDefault(); closeDialog(); }
});

// "Tick all" for a checklist group: the common case is that everything is
// present, and ticking eleven boxes one at a time invites skipping the read.
dlg.addEventListener('click', function (e) {
    var all = e.target.closest('.rc-check-all');
    if (!all) return;
    var grid = dlg.querySelector('.rc-check-grid[data-group="' + all.dataset.group + '"]');
    if (!grid) return;
    var boxes = grid.querySelectorAll('input[type="checkbox"]');
    var turnOn = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
    Array.prototype.forEach.call(boxes, function (b) { b.checked = turnOn; });
    all.textContent = turnOn ? 'Clear all' : 'Tick all';
});
<?php endif; ?>

});
</script>
<?php require ROOT_PATH.'/includes/browse_console_js.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
