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

// Drafts nobody has been asked to look at yet: counted apart, never inside.
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

// AI reads the filtered figures, so the wording follows whatever is on screen.
$aiInsight = '';
if (!empty($stats)) {
    try {
        /* No key of its own any more. Every AI feature draws on the one
           key pool, so this reads the session's engine and the other keys
           stand behind it: an insight is no longer lost because the one key
           this page used to hold was spent. */
        $aiInsight = generate_analytics_insight($stats);
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
    fputcsv($out, ['PAPEL Analytics, ' . date('Y-m-d H:i')]);
    if ($filtered) {
        fputcsv($out, ['Filtered by',
            'Year: '    . ($fYear    ?: 'all'),
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
        if ($fYear)    $bits[] = 'Year ' . $fYear;
        if ($fProgram) $bits[] = program_code($fProgram) ?: $fProgram;
        if ($fType)    $bits[] = paper_type_label($fType);
        if ($fStatus)  $bits[] = str_replace('_', ' ', $fStatus);
        if ($fQuery)   $bits[] = 'matching "' . $fQuery . '"';
        $filterLine = 'Filtered by ' . implode(' · ', $bits);
    }

    $everything = $rows($run("SELECT rp.title, rp.paper_type, rp.current_status, rp.upload_date,
                                     u.full_name AS student, u.program
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

        $x->section($byMonth ? 'Submissions by month' : 'Submissions by year');
        $x->headRow([$byMonth ? 'Month' : 'Year', 'Submissions']);
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
        $d = new DocxWriter();
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

        $d->heading($byMonth ? 'Submissions by month' : 'Submissions by year');
        $d->table([[$byMonth ? 'Month' : 'Year', 2, 'left'], ['Submissions', 1, 'right']],
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
    $pdf = new PdfWriter('PAPEL Analytics', $filterLine . '  -  ' . $when);

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

    $pdf->heading($byMonth ? 'Submissions by month' : 'Submissions by year');
    $pdf->table([[$byMonth ? 'Month' : 'Year', 259, 'l'], ['Submissions', 240, 'r']],
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
    'timeline' => array_map(function ($r) use ($byMonth) {
        return ['label' => $byMonth ? date('M', strtotime($r['bucket'] . '-01')) : (string)$r['bucket'],
                'value' => (int)$r['count']];
    }, $timelineData),
    'monthly'  => [['label' => 'Last month', 'value' => $lastMonth],
                   ['label' => 'This month', 'value' => $thisMonth]],
    'approval' => array_map(function ($r) {
        return ['label' => paper_type_label($r['paper_type']), 'value' => (float)$r['rate']];
    }, $approvalByType),
    'types'    => array_map(function ($r) {
        return ['label' => paper_type_label($r['paper_type']), 'value' => (int)$r['count']];
    }, $paperTypeStats),
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
.an-doc-preview { max-width: 50rem; margin: 0 auto; }
.an-doc-ruler { height: 1.25rem; margin-bottom: .5rem; border: 1px solid #ddd;
    background: repeating-linear-gradient(to right, #f8f9fa 0, #f8f9fa 15px, #c7ccce 16px, #f8f9fa 17px, #f8f9fa 32px); }
.an-preview-document { min-height: 66rem; margin: 0 auto; padding: 4.5rem 4rem; background: #fff;
    color: #302626; box-shadow: 0 3px 18px rgba(50, 20, 20, .16); font: 400 .8125rem/1.6 Arial, sans-serif; }
.an-preview-document h3 { margin: 0; color: #820707; font: 600 1.5rem/1.3 var(--font-head); }
.an-preview-document h4 { margin: 0 0 .5rem; color: #630000; font: 600 1rem/1.3 var(--font-head); }
.an-preview-document section { margin-top: 1.5rem; }
.an-preview-document .an-preview-subtitle { color: #6e6a6e; margin: .25rem 0 1.25rem; }
.an-preview-document .an-preview-insight { text-align: justify; }
.an-preview-table-wrap { max-width: 100%; overflow-x: auto; }
.an-preview-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .75rem; }
.an-preview-table th, .an-preview-table td { padding: .45rem .55rem; text-align: left; vertical-align: top; border-bottom: 1px solid #e6d4d4; }
.an-preview-table th { position: relative; background: #820707; color: #fff; white-space: nowrap; }
.an-preview-figure { margin: 1rem auto; max-width: 45rem; text-align: center; }
.an-preview-figure img { display: block; width: 100%; background: #fff; }
html[data-mode="dark"] .an-export-preview img { filter: none !important; }
.an-preview-figure figcaption { margin-top: .25rem; color: #6e6a6e; font-size: .75rem; }

/* Interactive layout for CSV, Excel and Word export previews. */
.an-sheet-preview { display: flex; flex-direction: column; height: 100%; min-height: 0;
    background: #fff; color: #202124; font: 400 .75rem/1.3 Arial, sans-serif; }
.an-sheet-formula { display: flex; align-items: center; gap: .75rem; flex: 0 0 auto;
    min-height: 2.25rem; border-bottom: 1px solid #dadce0; padding: .35rem .75rem; }
.an-sheet-reference { min-width: 3rem; border-right: 1px solid #dadce0; color: #5f6368; }
.an-sheet-fx { color: #5f6368; font-style: italic; font-weight: 700; }
.an-sheet-value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-sheet-pages { flex: 1 1 auto; min-height: 0; overflow: auto; }
.an-sheet-page[hidden] { display: none; }
.an-sheet-grid { width: max-content; border-collapse: separate; border-spacing: 0;
    table-layout: fixed; background: #fff; }
.an-sheet-grid th, .an-sheet-grid td { height: 1.9rem; padding: .3rem .5rem;
    border-right: 1px solid #e0e3e7; border-bottom: 1px solid #e0e3e7;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: left; }
.an-sheet-grid thead th { position: sticky; top: 0; z-index: 2; background: #f8f9fa;
    color: #5f6368; text-align: center; font-weight: 500; }
.an-sheet-grid .an-sheet-corner { position: sticky; left: 0; z-index: 4; width: 3rem; }
.an-sheet-grid .an-sheet-row-number { position: sticky; left: 0; z-index: 1;
    width: 3rem; background: #f8f9fa; color: #5f6368; text-align: center; font-weight: 400; }
.an-sheet-grid td { width: 10.5rem; cursor: cell; }
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
.an-filter-row { display: flex; flex-wrap: wrap; gap: .625rem; align-items: flex-end; }
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
    display: grid; gap: .875rem; margin-bottom: 1.125rem;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
}
.an-tile { background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px); padding: .875rem 1rem; }
.an-tile-label {
    font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--grey); display: block; margin-bottom: .25rem;
}
.an-tile-value {
    font-family: var(--font-head); font-size: 1.625rem; font-weight: 600;
    color: var(--maroon); line-height: 1.1; font-variant-numeric: tabular-nums;
}
.an-tile-note { display: block; font-size: .6875rem; color: var(--grey); margin-top: .2rem; }
.an-tile-note.is-up { color: var(--ok-text); }
.an-tile-note.is-down { color: var(--bad-text); }

/* ---- Cards ---- */
.an-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px); margin-bottom: 1.125rem; overflow: hidden; }
.an-card-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .8rem 1.125rem; border-bottom: 1px solid var(--border);
    font-family: var(--font-head); font-size: .875rem; font-weight: 600; color: var(--maroon);
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
.an-card-body { padding: 1.125rem; }

.an-ai-body { display: flex; gap: .75rem; align-items: flex-start; }
.an-ai-body .material-symbols-outlined { color: var(--maroon); font-size: 20px; flex: 0 0 auto; }
.an-ai-text { font-size: .875rem; color: var(--ink); line-height: 1.7; text-align: justify; }
.an-ai-empty { font-size: .8125rem; color: var(--grey); }

/* ---- Charts ---- */
.an-grid { display: grid; gap: 1.125rem; align-items: start; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); }
.an-chart { position: relative; height: 15rem; }
.an-expandable { cursor: zoom-in; }
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
.an-scroll { overflow-x: auto; overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; }
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
.an-submissions.is-loading .an-table tbody { opacity: .55; }
.an-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.an-table--types th, .an-table--types td,
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
    .an-grid { grid-template-columns: minmax(0, 1fr); gap: .75rem; }
    .an-card { min-width: 0; margin-bottom: .75rem; }
    .an-card-head { flex-wrap: wrap; padding: .75rem; }
    .an-card-head .an-hint { order: 3; flex-basis: 100%; margin-left: 0; }
    .an-card-head .an-card-toggle, .an-card-head .an-hint + .an-card-toggle { order: 2; margin-left: auto; }
    .an-card-body { padding: .75rem; }
    .an-table--types { min-width: 28rem; }
    .an-table--programs { min-width: 34rem; }
    .an-table--submissions { min-width: 58rem; }
    .an-paging { padding: .75rem; }
}
@media (prefers-reduced-motion: reduce) {
    .an-table-glide::before, .an-table-glide::after { transition: none; }
}
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assests/css/analytics-word-editor.css?v=1">
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

    <div id="anResults">
    <script type="application/json" id="anChartData"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

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
            <span class="an-tile-value"><?= $avgDays !== null ? e($avgDays) : 'N/A' ?></span>
            <span class="an-tile-note"><?= $avgDays !== null ? 'days' : 'not recorded yet' ?></span>
        </div>
    </div>

    <!-- ---- What the numbers say ---- -->
    <div class="an-card" data-an-card="insight">
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
        <div class="an-card" data-an-card="timeline">
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

        <div class="an-card" data-an-card="monthly">
            <div class="an-card-head"><span class="material-symbols-outlined">calendar_month</span> This month against last</div>
            <div class="an-card-body">
                <div class="an-chart an-expandable" data-chart="monthly" title="Click to enlarge">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <div class="an-card" data-an-card="approval">
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

        <div class="an-card" data-an-card="types">
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
        <div class="an-card" data-an-card="by-type">
            <div class="an-card-head"><span class="material-symbols-outlined">category</span> By paper type</div>
            <div class="an-table-glide">
            <div class="an-scroll">
                <table class="an-table an-table--types js-sortable">
                    <thead>
                        <tr>
                            <th data-sort="text" data-help="The kind of research paper, such as a thesis or undergraduate thesis." aria-description="The kind of research paper, such as a thesis or undergraduate thesis." tabindex="0">Type</th>
                            <th class="an-num" data-sort="num" data-help="Number of submitted papers of this type in the current results." aria-description="Number of submitted papers of this type in the current results." tabindex="0">Papers</th>
                            <th class="an-num" data-sort="num" data-help="Share of the current submissions that belong to this paper type." aria-description="Share of the current submissions that belong to this paper type." tabindex="0">Percentage</th>
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
                                   class="an-sort"><?= e(paper_type_label($row['paper_type'])) ?></a>
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
        </div>

        <div class="an-card" data-an-card="by-program">
            <div class="an-card-head"><span class="material-symbols-outlined">school</span> By program</div>
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

    /* Match the paper view's folding cards. Keep the chosen state when an
       in-place filter update replaces the result cards. */
    var cardStates = Object.create(null);
    var cardLabels = {
        insight: 'What the numbers say', timeline: 'Submissions over time',
        monthly: 'This month against last', approval: 'Approval rate by paper type',
        types: 'Paper types', 'by-type': 'By paper type',
        'by-program': 'By program', submissions: 'Every submission'
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
        var filterNames = ['year', 'program', 'type', 'status', 'q'];
        var renderedFilters = filterKey(location.href);
        var errorBox = document.createElement('p');
        errorBox.className = 'an-filter-error';
        errorBox.setAttribute('role', 'alert');
        errorBox.hidden = true;
        filters.appendChild(errorBox);

        function filterKey(href) {
            var params = new URL(href, location.href).searchParams;
            return filterNames.map(function (name) { return params.get(name) || ''; }).join('\u0000');
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
                    filterNames.forEach(function (name) {
                        filters.elements.namedItem(name).value = nextFilters.elements.namedItem(name).value;
                    });
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
            var target = new URL(location.href);
            filterNames.forEach(function (name) { target.searchParams.delete(name); });
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
        filters.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', applyFilters);
        });
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
            var link = event.target.closest('.an-active a, .an-table--types tbody a.an-sort, .an-table--programs tbody a.an-sort');
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

    /* ---- The small tables sort in the browser ----
       They hold one row per programme or per paper type, so there is never a
       second page for a click to be sorting only part of. The big table below
       sorts in the database instead, for exactly that reason. */
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

    /* Colours come from the theme, read fresh each time so a change of palette
       or of light/dark is followed rather than baked in at first paint. */
    var token = function (name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v || '').trim() || fallback;
    };

    var data = JSON.parse(document.getElementById('anChartData').textContent);

    var labels = function (k) { return data[k].map(function (d) { return d.label; }); };
    var values = function (k) { return data[k].map(function (d) { return d.value; }); };

    function build(palette) {
        var color = function (name, fallback) { return palette ? (palette[name] || fallback) : token(name, fallback); };
        var maroon = color('--maroon', '#820707'),
            soft   = color('--soft-maroon', '#b17d7d'),
            dark   = color('--dark-maroon', '#630000'),
            ink    = color('--ink', '#330000'),
            grey   = color('--grey', '#9f9f9f'),
            border = color('--border', '#e6d4d4'),
            cream  = color('--cream', '#fff5f5');
        var series = [maroon, soft, dark, '#a85a5a', '#d3a3a3', '#7a3b3b', '#c98c8c'];

        if (!palette) {
            Chart.defaults.font.family = token('--font-body', 'Inter') + ', Inter, system-ui, sans-serif';
            Chart.defaults.font.size = 11;
            Chart.defaults.color = grey;
        }

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
                        borderColor: color('--white', '#fff'), borderWidth: 2 }] },
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
    window.papelAnalyticsExportFigures = function (format) {
        var light = {
            '--maroon': '#820707', '--soft-maroon': '#b17d7d',
            '--dark-maroon': '#630000', '--ink': '#330000',
            '--grey': '#6e6a6e', '--border': '#e6d4d4',
            '--cream': '#fff5f5', '--white': '#ffffff'
        };
        var exportConfigs = build(light);
        var captions = {
            timeline: 'Submissions over time', monthly: 'Submissions by month',
            approval: 'Approval rate', types: 'Papers by type'
        };
        return Object.keys(canvases).map(function (key) {
            if (!document.getElementById(canvases[key])) return null;
            var canvas = document.createElement('canvas');
            canvas.width = 1000;
            canvas.height = 500;
            var config = exportConfigs[key]();
            config.options.responsive = false;
            config.options.animation = false;
            config.plugins = [{
                id: 'exportWhiteBackground',
                beforeDraw: function (chart) {
                    var context = chart.ctx;
                    context.save();
                    context.fillStyle = '#ffffff';
                    context.fillRect(0, 0, chart.width, chart.height);
                    context.restore();
                }
            }];
            var chart = new Chart(canvas, config);
            var url = canvas.toDataURL(format === 'pdf' ? 'image/jpeg' : 'image/png', 0.92);
            chart.destroy();
            return { caption: captions[key], url: url, width: canvas.width, height: canvas.height };
        }).filter(Boolean);
    };
    document.addEventListener('analytics:cardopened', function (event) {
        var chart = drawn[event.detail.key];
        if (chart) requestAnimationFrame(function () { chart.resize(); });
    });

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

    function bindExpandables() {
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
    }
    bindExpandables();
    document.addEventListener('analytics:updated', function () {
        if (modal.classList.contains('is-open')) closeModal();
        Object.keys(drawn).forEach(function (key) { drawn[key].destroy(); });
        drawn = {};
        data = JSON.parse(document.getElementById('anChartData').textContent);
        configs = build();
        drawAll();
        bindExpandables();
    });

    document.getElementById('chartModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) { closeModal(); }
    });
});
</script>
<?php require ROOT_PATH.'/includes/scroll_jump.php'; ?>
<script nonce="<?= csp_nonce() ?>" src="<?= BASE_URL ?>/assests/js/analytics-word-editor.js?v=1"></script>
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
    previewBody.addEventListener('pointerdown', function (event) {
        var handle = event.target.closest('.an-resize-handle');
        if (!handle || event.button !== 0) return;
        event.preventDefault();
        event.stopPropagation();
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
            document.body.classList.remove('an-preview-resizing', 'is-row');
        }
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', finish);
        handle.addEventListener('pointercancel', finish);
    });
    previewBody.addEventListener('dblclick', function (event) {
        var handle = event.target.closest('.an-resize-handle.is-column');
        if (!handle) return;
        event.preventDefault();
        var table = handle.closest('table');
        var index = Number(handle.dataset.resizeIndex);
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
                    wordEditor = window.PapelAnalyticsWordEditor(previewBody);
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
