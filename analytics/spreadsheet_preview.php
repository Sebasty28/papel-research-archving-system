<?php
/* Spreadsheet-shaped preview of the same rows written to the Analytics sheet. */
if (!defined('PAPEL_ANALYTICS_EXPORT_PREVIEW')) {
    http_response_code(404);
    exit;
}

$sheetRows = [
    ['kind' => 'title', 'cells' => ['PAPEL Analytics']],
    ['kind' => 'subtitle', 'cells' => [$filterLine . '  ·  ' . $when]],
    ['kind' => 'blank', 'cells' => []],
];
$addSection = function (string $name, array $headers, array $dataRows) use (&$sheetRows): void {
    $sheetRows[] = ['kind' => 'blank', 'cells' => []];
    $sheetRows[] = ['kind' => 'section', 'cells' => [$name]];
    $sheetRows[] = ['kind' => 'heading', 'cells' => $headers];
    foreach ($dataRows as $row) $sheetRows[] = ['kind' => 'data', 'cells' => $row];
};
$addSection('Summary', ['Measure', 'Papers'], $summary);
$addSection('By programme', ['Programme', 'Submissions', 'Approved', 'In revision'],
    array_map(fn($r) => [$r['program'], (int)$r['total_papers'], (int)$r['approved'], (int)$r['revisions']], $stats));
$addSection('By paper type', ['Type', 'Submissions', 'Percentage of papers (%)'],
    array_map(fn($r) => [paper_type_label((string)$r['paper_type']), (int)$r['count'],
        $totalPapers > 0 ? round(($r['count'] / $totalPapers) * 100, 1) : 0], $paperTypeStats));
$addSection($byMonth ? 'Submissions by month' : 'Submissions by year',
    [$byMonth ? 'Month' : 'Year', 'Submissions'],
    array_map(fn($r) => [$r['bucket'], (int)$r['count']], $timelineData));
$addSection('Every submission', ['Submitted', 'Title', 'Student', 'Programme', 'Type', 'Status'],
    array_map(fn($r) => [substr((string)$r['upload_date'], 0, 10), $r['title'], $r['student'],
        program_code((string)$r['program']) ?: $r['program'], paper_type_label((string)$r['paper_type']),
        ucwords(str_replace('_', ' ', (string)$r['current_status']))], $everything));
$columnLetters = ['A', 'B', 'C', 'D', 'E', 'F'];
?>
<div class="an-sheet-preview">
    <div class="an-sheet-formula">
        <span class="an-sheet-reference">A1</span>
        <span class="an-sheet-fx" aria-hidden="true">fx</span>
        <span class="an-sheet-value">PAPEL Analytics</span>
    </div>
    <div class="an-sheet-pages">
        <div class="an-sheet-page" id="anPreviewAnalyticsSheet" role="tabpanel" aria-labelledby="anPreviewAnalyticsTab">
            <table class="an-sheet-grid" aria-label="Analytics spreadsheet preview">
                <thead><tr><th class="an-sheet-corner" aria-hidden="true"></th>
                    <?php foreach ($columnLetters as $letter): ?><th scope="col"><?= $letter ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($sheetRows as $index => $row): ?>
                    <tr class="an-sheet-row-<?= e($row['kind']) ?>">
                        <th scope="row" class="an-sheet-row-number"><?= $index + 1 ?></th>
                        <?php foreach ($columnLetters as $column => $letter): ?>
                            <?php $value = (string)($row['cells'][$column] ?? ''); ?>
                            <td data-cell-ref="<?= $letter . ($index + 1) ?>" data-cell-value="<?= e($value) ?>"><?= e($value) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($figures): ?>
        <div class="an-sheet-page" id="anPreviewFiguresSheet" role="tabpanel" aria-labelledby="anPreviewFiguresTab" hidden>
            <table class="an-sheet-grid" aria-label="Figures spreadsheet preview">
                <thead><tr><th class="an-sheet-corner" aria-hidden="true"></th>
                    <?php foreach ($columnLetters as $letter): ?><th scope="col"><?= $letter ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <tr class="an-sheet-row-section"><th scope="row" class="an-sheet-row-number">1</th>
                        <td colspan="6" data-cell-ref="A1" data-cell-value="Figures">Figures</td></tr>
                    <?php $figureRow = 2; foreach ($figures as $figure): ?>
                        <?php if ($figure['type'] !== 'png') continue; ?>
                        <tr><th scope="row" class="an-sheet-row-number"><?= $figureRow ?></th>
                            <td colspan="6" data-cell-ref="A<?= $figureRow ?>" data-cell-value="<?= e($figure['caption']) ?>">
                                <figure class="an-sheet-figure">
                                    <img alt="<?= e($figure['caption']) ?>" src="data:image/png;base64,<?= base64_encode($figure['data']) ?>">
                                    <figcaption><?= e($figure['caption']) ?></figcaption>
                                </figure>
                            </td>
                        </tr>
                        <?php $figureRow++; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="an-sheet-tabs" role="tablist" aria-label="Spreadsheet sheets">
        <button type="button" class="an-sheet-tab is-active" id="anPreviewAnalyticsTab" role="tab"
                aria-selected="true" aria-controls="anPreviewAnalyticsSheet">Analytics</button>
        <?php if ($figures): ?>
        <button type="button" class="an-sheet-tab" id="anPreviewFiguresTab" role="tab"
                aria-selected="false" aria-controls="anPreviewFiguresSheet">Figures</button>
        <?php endif; ?>
    </div>
</div>
