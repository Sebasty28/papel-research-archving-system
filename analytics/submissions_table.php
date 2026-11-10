<?php
/* Shared by the full analytics page and its in-place sort/page response. */
if (!defined('PAPEL_ANALYTICS_SUBMISSIONS')) {
    http_response_code(404);
    exit;
}
?>
<div class="an-card an-submissions" id="anSubmissions" data-an-card="submissions">
    <div class="an-card-head">
        <span class="material-symbols-outlined">list</span> Every submission
        <span class="an-hint"><?= number_format($listTotal) ?> in total &middot; newest first unless you say otherwise</span>
    </div>
    <div class="an-table-glide">
        <div class="an-scroll">
            <table class="an-table an-table--submissions">
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
                            <?php if ($st === 'approved'): ?>
                                <a class="an-title" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$p['paper_id'] ?>"><?= e($p['title'] ?: 'Untitled') ?></a>
                            <?php else: ?>
                                <span class="an-title"><?= e($p['title'] ?: 'Untitled') ?></span>
                            <?php endif; ?>
                            <?php if (!empty($p['author_names'])): ?>
                                <span class="an-sub"><?= e($p['author_names']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($p['student'] ?: 'N/A') ?></td>
                        <td title="<?= e($p['program'] ?? '') ?>">
                            <?= e($p['program'] ? (function_exists('program_code') ? program_code($p['program']) : $p['program']) : 'N/A') ?>
                        </td>
                        <td><?= e($p['paper_type'] ? paper_type_label($p['paper_type']) : 'N/A') ?></td>
                        <td><span class="an-status <?= e($cls) ?>"><?= e($label) ?></span></td>
                        <td class="an-when"><?= e(date('M j, Y', strtotime($p['upload_date']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
