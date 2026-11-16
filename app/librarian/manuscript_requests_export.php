<?php
/** Librarian-only export of the manuscript request queue and its history. */
require_once __DIR__ . '/../../config/core.php';
require_role(['librarian']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

$format = (string)($_POST['format'] ?? '');
$allowedFormats = ['csv', 'xlsx', 'docx', 'pdf'];
$allowedScopes = ['pending' => 'Pending', 'granted' => 'Granted', 'history' => 'History'];
$scopes = array_values(array_unique(array_intersect(
    array_keys($allowedScopes), array_map('strval', (array)($_POST['scopes'] ?? []))
)));
if (!in_array($format, $allowedFormats, true) || !$scopes || !manuscript_requests_ready()) {
    http_response_code(400);
    exit('Choose a format and at least one available request group.');
}

$conn = db();
$sections = [];
foreach ($scopes as $scope) {
    $condition = $scope === 'pending' ? "r.status = 'pending'"
               : ($scope === 'granted' ? "r.status = 'granted' AND r.expires_at > NOW()"
                  : "r.status = 'denied' OR (r.status = 'granted' AND r.expires_at <= NOW())");
    $sql = "SELECT r.*, s.full_name AS student_name, s.student_id AS student_no,
                   s.email AS student_email, s.program AS student_program,
                   COALESCE(p.title, pa.title, 'Untitled paper') AS paper_title
            FROM manuscript_requests r
            JOIN users s ON s.user_id = r.student_user_id
            LEFT JOIN research_papers p ON p.paper_id = r.paper_id
            LEFT JOIN papers_archive pa ON pa.paper_id = r.paper_id
            WHERE ($condition) ORDER BY r.created_at ASC";
    $result = $conn->query($sql);
    $sections[$scope] = $result->fetch_all(MYSQLI_ASSOC);
}

$title = 'PAPEL Librarian · Manuscript Requests';
$when = date('j F Y, g:i A');
$subtitle = implode(', ', array_map(fn($scope) => $allowedScopes[$scope], $scopes)) . ' · ' . $when;
$stamp = date('Y-m-d');
$filename = 'librarian_manuscript_requests_' . $stamp . '.' . $format;
$head = ['Status', 'Who is asking', 'Student ID', 'Gmail', 'Academic Program', 'Paper', 'Requested', 'Granted', 'Expires', 'Outcome'];
$rows = [];
foreach ($sections as $scope => $requests) {
    $rows[$scope] = [];
    foreach ($requests as $r) {
        $state = manuscript_request_state($r);
        $outcome = $scope === 'history'
            ? ($state === 'revoked' ? 'Revoked' : ($state === 'denied' ? 'Denied' : 'Expired'))
            : ($scope === 'granted' ? 'Access open' : 'Awaiting review');
        $rows[$scope][] = [
            ucfirst($scope), (string)$r['student_name'], (string)$r['student_no'],
            (string)$r['student_email'], (string)$r['student_program'],
            (string)$r['paper_title'], (string)$r['created_at'],
            (string)($r['granted_at'] ?? ''), (string)($r['expires_at'] ?? ''), $outcome,
        ];
    }
}
$summary = [];
foreach ($sections as $scope => $requests) $summary[] = [$allowedScopes[$scope], count($requests)];

header('Cache-Control: no-store, private');
header('Content-Disposition: attachment; filename="' . $filename . '"');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, [$title, $when]);
    fputcsv($out, [$subtitle]);
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    foreach ($summary as $row) fputcsv($out, $row);
    foreach ($rows as $scope => $items) {
        fputcsv($out, []);
        fputcsv($out, [strtoupper($allowedScopes[$scope])]);
        fputcsv($out, $head);
        foreach ($items as $row) {
            // Keep names and titles from becoming formulas in spreadsheet apps.
            fputcsv($out, array_map(function ($value) {
                $value = (string)$value;
                return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
            }, $row));
        }
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    require_once ROOT_PATH . '/includes/xlsx_writer.php';
    $x = new XlsxWriter('Manuscript Requests');
    $x->widths([12, 24, 18, 28, 28, 48, 20, 20, 20, 18]);
    $x->title($title, $subtitle);
    $x->section('Summary');
    $x->headRow(['Status', 'Requests']);
    foreach ($summary as $row) $x->row($row);
    foreach ($rows as $scope => $items) {
        $x->section($allowedScopes[$scope]);
        $x->headRow($head);
        foreach ($items as $row) $x->row($row);
    }
    $bytes = $x->output();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} elseif ($format === 'docx') {
    require_once ROOT_PATH . '/includes/docx_writer.php';
    $d = new DocxWriter();
    $d->title($title, $subtitle);
    $d->heading('Summary');
    $d->table([['Status', 3, 'left'], ['Requests', 1, 'right']], $summary);
    // Keep the portrait report readable; CSV and Excel retain the extra dates and ID.
    $columns = [
        ['Status', 2, 'left'], ['Who is asking', 3, 'left'], ['Gmail', 3, 'left'],
        ['Academic Program', 3, 'left'], ['Paper', 5, 'left'],
        ['Requested', 2, 'left'], ['Outcome', 2, 'left'],
    ];
    foreach ($rows as $scope => $items) {
        $d->heading($allowedScopes[$scope]);
        $d->table($columns, array_map(fn($r) => [$r[0], $r[1], $r[3], $r[4], $r[5], $r[6], $r[9]], $items));
    }
    $bytes = $d->output();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
} else {
    require_once ROOT_PATH . '/includes/pdf_writer.php';
    $pdf = new PdfWriter($title, $subtitle);
    $pdf->heading('Summary');
    $pdf->table([['Status', 320, 'l'], ['Requests', 179, 'r']], $summary);
    // A readable portrait report keeps the primary request fields in the table.
    $pdfHead = [
        ['Status', 51, 'l'], ['Who is asking', 72, 'l'], ['Gmail', 85, 'l'],
        ['Academic Program', 62, 'l'], ['Paper', 121, 'l'], ['Requested', 55, 'l'], ['Outcome', 53, 'l'],
    ];
    foreach ($rows as $scope => $items) {
        $pdf->heading($allowedScopes[$scope]);
        $pdf->table($pdfHead, array_map(fn($r) => [$r[0], $r[1], $r[3], $r[4], $r[5], $r[6], $r[9]], $items));
    }
    $bytes = $pdf->output();
    header('Content-Type: application/pdf');
}
header('Content-Length: ' . strlen($bytes));
echo $bytes;
