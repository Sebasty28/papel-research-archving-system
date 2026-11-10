<?php
/* A light, browser-readable rendering of the same data sent to Word or Excel. */
if (!defined('PAPEL_ANALYTICS_EXPORT_PREVIEW')) {
    http_response_code(404);
    exit;
}

if ($exportFormat === 'xlsx') {
    require __DIR__ . '/spreadsheet_preview.php';
    return;
}
$previewTable = function (array $headers, array $tableRows): void {
    echo '<div class="an-preview-table-wrap"><table class="an-preview-table"><thead><tr>';
    foreach ($headers as $header) echo '<th>' . e((string)$header) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($tableRows as $tableRow) {
        echo '<tr>';
        foreach ($tableRow as $cell) echo '<td>' . e((string)($cell ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
$previewFigures = function () use ($figures): void {
    foreach ($figures as $figureIndex => $figure) {
        if ($figure['type'] !== 'png') continue;
        echo '<figure class="an-preview-figure" data-figure-index="' . $figureIndex . '"><img alt="' . e($figure['caption']) .
             '" src="data:image/png;base64,' . base64_encode($figure['data']) . '">';
        echo '<figcaption>' . e($figure['caption']) . '</figcaption></figure>';
    }
};
?>
<div class="an-doc-preview">
<div class="an-doc-ruler" aria-hidden="true"></div>
<article class="an-preview-document is-word">
    <h3>PAPEL Analytics</h3>
    <p class="an-preview-subtitle"><?= e($filterLine) ?> &middot; <?= e($when) ?></p>

    <?php if ($aiInsight !== '' && stripos($aiInsight, 'unavailable') === false): ?>
        <section><h4>What the figures say</h4><p class="an-preview-insight"><?= nl2br(e(strip_tags($aiInsight))) ?></p></section>
    <?php endif; ?>

    <section>
        <h4>Summary</h4>
        <?php $previewTable(['Measure', 'Papers'], array_map(fn($r) => [$r[0], number_format((int)$r[1])], $summary)); ?>
    </section>
    <section>
        <h4>By programme</h4>
        <?php $previewTable(['Programme', 'Submissions', 'Approved', 'In revision'],
            array_map(fn($r) => [$r['program'], (int)$r['total_papers'], (int)$r['approved'], (int)$r['revisions']], $stats)); ?>
    </section>
    <section>
        <h4>By paper type</h4>
        <?php $previewTable(['Type', 'Submissions', 'Percentage'],
            array_map(fn($r) => [paper_type_label((string)$r['paper_type']), (int)$r['count'],
                ($totalPapers > 0 ? round(($r['count'] / $totalPapers) * 100, 1) : 0) . '%'], $paperTypeStats)); ?>
    </section>
    <section>
        <h4>Submissions by <?= $byMonth ? 'month' : 'year' ?></h4>
        <?php $previewTable([$byMonth ? 'Month' : 'Year', 'Submissions'],
            array_map(fn($r) => [$r['bucket'], (int)$r['count']], $timelineData)); ?>
    </section>

    <?php if ($figures): ?>
        <section><h4>Figures</h4><?php $previewFigures(); ?></section>
    <?php endif; ?>

    <section>
        <h4>Every submission</h4>
        <?php $previewTable(['Submitted', 'Title', 'Student', 'Programme', 'Status'],
            array_map(fn($r) => [substr((string)$r['upload_date'], 0, 10), $r['title'], $r['student'],
                program_code((string)$r['program']) ?: $r['program'],
                ucwords(str_replace('_', ' ', (string)$r['current_status']))], $everything)); ?>
    </section>
</article>
</div>
