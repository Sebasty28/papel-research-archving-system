<?php
/** The numbered pager used by the repository and every paper desk. */
function paper_pager_html(int $page, int $totalPages, int $start, int $end, int $total, string $unit, callable $href): string {
    if ($totalPages <= 1) return '';
    $window = 10;
    $from = max(1, min($page - 4, $totalPages - $window + 1));
    $to = min($totalPages, $from + $window - 1);
    ob_start();
    ?>
    <nav class="papel-pager" aria-label="Pages of results">
        <div class="pager-nums">
            <?php if ($page > 1): ?>
                <a class="pg-step" href="<?= e($href($page - 1)) ?>" rel="prev">
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span><span class="pg-word">Previous</span>
                </a>
            <?php endif; ?>
            <?php for ($i = $from; $i <= $to; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="pg-num is-here" aria-current="page"><?= $i ?></span>
                <?php else: ?>
                    <a class="pg-num" href="<?= e($href($i)) ?>" aria-label="Page <?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a class="pg-step" href="<?= e($href($page + 1)) ?>" rel="next">
                    <span class="pg-word">Next</span><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                </a>
            <?php endif; ?>
        </div>
        <p class="pager-count">Showing <?= $total ? $start : 0 ?>-<?= $end ?> of <?= number_format($total) ?> <?= e($unit) ?></p>
    </nav>
    <?php
    return ob_get_clean();
}
