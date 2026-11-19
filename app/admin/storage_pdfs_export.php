<?php
/**
 * The Storage Folder's PDF lists as a file: one section per Google account, each
 * PDF with the same access answer the page shows (gdrive_inventory_access()).
 *
 * The same writers, and the same title, summary and sections layout, as the
 * librarian's request export and the Director's review export. A POST with the
 * token, never a GET, so a link cannot be used to make somebody download it.
 */
require_once __DIR__ . '/../../config/core.php';
// The page's own gate: the Director and the Research Coordinator, never a bare
// role check, which would also let the Head of Academic Programs in.
require_research_operations();
require_once __DIR__ . '/../../config/gdrive_config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

$format = (string)($_POST['format'] ?? '');
$allowedFormats = ['csv', 'xlsx', 'docx', 'pdf'];
// What the dialog offers, against the lists' own categories.
$scopeCategories = ['current' => ['current'], 'previous' => ['previous', 'other'], 'unconfirmed' => ['shared', 'unknown']];
$scopes = array_values(array_intersect(array_keys($scopeCategories), array_map('strval', (array)($_POST['scopes'] ?? []))));
$onlyAttention = ($_POST['only'] ?? 'all') === 'attention';
if (!in_array($format, $allowedFormats, true) || !$scopes) {
    http_response_code(400);
    exit('Choose a format and at least one kind of account.');
}
$categories = [];
foreach ($scopes as $scope) $categories = array_merge($categories, $scopeCategories[$scope]);

$identity = gdrive_connected_identity();
$view = gdrive_inventory_view($identity['verified'] ? $identity['email'] : '');
$groups = [];
foreach ($view['groups'] as $key => $group) {
    if (!in_array($group['category'], $categories, true)) continue;
    if ($onlyAttention) {
        $group['files'] = array_values(array_filter($group['files'], function ($file) {
            return gdrive_inventory_access($file)['key'] !== 'ok';
        }));
    }
    if ($group['files']) $groups[$key] = $group;
}
$rows = gdrive_inventory_export_rows($groups);

$title = 'PAPEL Storage Folder · PDFs by Google account';
$when = date('j F Y, g:i A');
$subtitle = ($identity['verified'] ? 'Connected account: ' . $identity['email'] : 'Connected account could not be confirmed with Google')
          . ($onlyAttention ? ' · Only PDFs that are not accessible' : '') . ' · ' . $when;
$filename = 'papel_storage_pdf_lists_' . date('Y-m-d') . '.' . $format;

$summaryHead = ['Account', 'List', 'PDFs', 'Accessible', 'View only', 'Not accessible', 'Not checked'];
$summary = [];
foreach ($groups as $group) {
    $c = gdrive_inventory_access_counts($group['files']);
    $summary[] = [$group['label'], gdrive_inventory_category_label($group['category']), count($group['files']),
        $c['ok'], $c['limited'], $c['gone'], $c['unknown']];
}
$head = ['Account', 'List', 'Paper', 'PDF', 'File name', 'Access', 'Last access check', 'Owner confirmed', 'Drive link'];
$sectionName = function (array $group): string {
    return gdrive_inventory_category_label($group['category']) . ': ' . $group['label'];
};
// A portrait page cannot hold nine columns; CSV and Excel keep them all.
$narrow = function (array $r): array { return [$r[2], $r[3], $r[4], $r[5], $r[6]]; };
$empty = $onlyAttention ? 'Every PDF in the chosen lists is accessible with the connected account.'
                        : 'There are no PDFs in the chosen lists.';

header('Cache-Control: no-store, private');
header('Content-Disposition: attachment; filename="' . $filename . '"');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    // Keep titles and file names from becoming formulas in a spreadsheet app.
    $safe = function (array $row): array {
        return array_map(function ($value) {
            $value = (string)$value;
            return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
        }, $row);
    };
    fputcsv($out, [$title, $when]);
    fputcsv($out, [$subtitle]);
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, $summaryHead);
    foreach ($summary as $row) fputcsv($out, $safe($row));
    if (!$groups) { fputcsv($out, []); fputcsv($out, [$empty]); }
    foreach ($groups as $key => $group) {
        fputcsv($out, []);
        fputcsv($out, [strtoupper($sectionName($group))]);
        fputcsv($out, $head);
        foreach ($rows[$key] as $row) fputcsv($out, $safe($row));
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    require_once ROOT_PATH . '/includes/xlsx_writer.php';
    $x = new XlsxWriter('PDFs by account');
    $x->widths([30, 18, 48, 26, 40, 16, 18, 16, 50]);
    $x->title($title, $subtitle);
    $x->section('Summary');
    $x->headRow($summaryHead);
    foreach ($summary as $row) $x->row($row);
    if (!$groups) $x->note($empty);
    foreach ($groups as $key => $group) {
        $x->section($sectionName($group));
        $x->headRow($head);
        foreach ($rows[$key] as $row) $x->row($row);
    }
    $bytes = $x->output();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} elseif ($format === 'docx') {
    require_once ROOT_PATH . '/includes/docx_writer.php';
    $d = new DocxWriter();
    $d->title($title, $subtitle);
    $d->heading('Summary');
    $d->table([['Account', 4, 'left'], ['List', 3, 'left'], ['PDFs', 1, 'right'], ['Accessible', 2, 'right'],
        ['View only', 2, 'right'], ['Not accessible', 2, 'right'], ['Not checked', 2, 'right']], $summary);
    if (!$groups) $d->paragraph($empty, true);
    foreach ($groups as $key => $group) {
        $d->heading($sectionName($group));
        $d->table([['Paper', 5, 'left'], ['PDF', 3, 'left'], ['File name', 3, 'left'], ['Access', 2, 'left'],
            ['Last access check', 2, 'left']], array_map($narrow, $rows[$key]));
    }
    $bytes = $d->output();
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
} else {
    require_once ROOT_PATH . '/includes/pdf_writer.php';
    $pdf = new PdfWriter($title, $subtitle);
    $pdf->heading('Summary');
    // 9pt: a heading cell is clipped, not wrapped, and "Not accessible" needs the room.
    $pdf->table([['Account', 140, 'l'], ['List', 74, 'l'], ['PDFs', 34, 'r'], ['Accessible', 56, 'r'],
        ['View only', 50, 'r'], ['Not accessible', 75, 'r'], ['Not checked', 70, 'r']], $summary, 9.0);
    if (!$groups) $pdf->paragraph($empty);
    foreach ($groups as $key => $group) {
        $pdf->heading($sectionName($group));
        $pdf->table([['Paper', 170, 'l'], ['PDF', 95, 'l'], ['File name', 85, 'l'], ['Access', 64, 'l'],
            ['Last checked', 85, 'l']], array_map($narrow, $rows[$key]), 9.0);
    }
    $bytes = $pdf->output();
    header('Content-Type: application/pdf');
}
header('Content-Length: ' . strlen($bytes));
echo $bytes;
