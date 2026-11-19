<?php
// Only included after require_research_operations() by the Storage Folder page.
// The access answers are gdrive_inventory_access()'s, which the export reads too.
$accessIcons = ['ok' => 'check_circle', 'limited' => 'visibility', 'gone' => 'block', 'unknown' => 'help'];
$accessWords = ['ok' => 'accessible', 'limited' => 'view only', 'gone' => 'not accessible', 'unknown' => 'not checked'];
$accessTally = function (array $counts) use ($accessIcons, $accessWords): string {
    $out = '';
    foreach ($counts as $key => $n) {
        if (!$n) continue;
        $out .= '<span class="storage-tally is-' . $key . '" title="' . $n . ' ' . $accessWords[$key] . '">'
              . '<span class="material-symbols-outlined" aria-hidden="true">' . $accessIcons[$key] . '</span>'
              . $n . '<span class="storage-sr"> ' . $accessWords[$key] . '</span></span>';
    }
    return $out;
};
$allCounts = ['ok' => 0, 'limited' => 0, 'gone' => 0, 'unknown' => 0];
foreach ($pdfInventory['groups'] as $group) {
    foreach (gdrive_inventory_access_counts($group['files']) as $key => $n) $allCounts[$key] += $n;
}
?>
<div class="storage-pdf-data" data-ids="<?= e(json_encode($pdfInventory['ids'])) ?>" data-pending="<?= e(json_encode($pdfInventory['pending'])) ?>">
    <p class="storage-list-total"><?= $pdfInventory['total'] ?> distinct PDF<?= $pdfInventory['total'] === 1 ? '' : 's' ?> linked to papers in this site, including supporting documents and archived papers.</p>
    <?php if ($pdfInventory['total']): ?>
        <p class="storage-tallies" aria-label="Access with the connected account"><span>With the connected account:</span> <?= $accessTally($allCounts) ?></p>
    <?php endif; ?>
    <?php if (!$pdfInventory['groups']): ?>
        <p class="storage-empty">There are no Google Drive PDFs recorded in this site yet.</p>
    <?php endif; ?>
    <?php foreach ($pdfInventory['groups'] as $group): ?>
    <details class="storage-account" data-category="<?= e($group['category']) ?>" data-account="<?= e($group['key']) ?>" <?= in_array($group['category'], ['current', 'unknown'], true) ? 'open' : '' ?>>
        <summary>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $group['category'] === 'unknown' ? 'help_outline' : 'folder_open' ?></span>
            <span class="storage-account-name"><small><?= e(gdrive_inventory_category_label($group['category'])) ?></small><strong><?= e($group['label']) ?></strong></span>
            <span class="storage-account-tallies"><?= $accessTally(gdrive_inventory_access_counts($group['files'])) ?></span>
            <span class="storage-count"><?= count($group['files']) ?> PDF<?= count($group['files']) === 1 ? '' : 's' ?></span>
        </summary>
        <div class="storage-account-body">
            <?php if ($group['category'] === 'unknown'): ?>
                <p class="storage-hint">Older uploads did not record an account. Check PDF accounts below. If Google cannot identify a file, it stays here until an account with access is connected and the check is run again.</p>
            <?php elseif ($group['category'] === 'previous'): ?>
                <p class="storage-hint">This account was connected before. Its PDFs stay in that account unless ownership is transferred in Google Drive.</p>
            <?php endif; ?>
            <?php if (!$group['files']): ?><p class="storage-empty">No PDFs have been confirmed for this account yet.</p><?php endif; ?>
            <div class="storage-file-list">
            <?php foreach ($group['files'] as $file): $meta = $file['meta']; $access = gdrive_inventory_access($file); ?>
                <article class="storage-file" data-access="<?= e($access['key']) ?>" data-search="<?= e(strtolower($file['title'] . ' ' . ($meta['file_name'] ?? '') . ' ' . $group['label'] . ' ' . implode(' ', array_keys($file['kinds'])) . ' ' . $access['label'])) ?>">
                    <div class="storage-file-info">
                        <strong><?= e($file['title']) ?></strong>
                        <span class="storage-access is-<?= e($access['key']) ?>" title="<?= e($access['detail']) ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= $accessIcons[$access['key']] ?></span><?= e($access['label']) ?>
                        </span>
                        <?php if ($access['key'] !== 'ok'): ?><small class="storage-access-detail"><?= e($access['detail']) ?></small><?php endif; ?>
                        <span><?= e(implode(' · ', array_keys($file['kinds']))) ?></span>
                        <?php if (!empty($meta['file_name'])): ?><span><?= e($meta['file_name']) ?></span><?php endif; ?>
                        <?php if (!empty($meta['checked_at'])): ?><small>Last access check: <?= e(date('M j, Y g:i A', strtotime($meta['checked_at']))) ?> PHT</small><?php endif; ?>
                        <?php if (!empty($meta['owner_checked_at'])): ?><small>Owner last confirmed: <?= e(date('M j, Y', strtotime($meta['owner_checked_at']))) ?></small><?php endif; ?>
                    </div>
                    <div class="storage-file-links">
                        <a class="btn-sm-outline storage-drive-link" href="https://drive.google.com/file/d/<?= e(rawurlencode($file['file_id'])) ?>/view" target="_blank" rel="noopener noreferrer" aria-label="Open PDF: <?= e($file['title']) ?>"><?= gdrive_drive_mark_svg() ?><span class="storage-drive-word">Open PDF</span></a>
                        <?php if (!empty($meta['parent_id'])): ?><a class="storage-folder-link" href="https://drive.google.com/drive/folders/<?= e(rawurlencode($meta['parent_id'])) ?>" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined" aria-hidden="true">folder</span><span class="storage-folder-word">Open folder</span></a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="storage-file-pages" hidden>
                <button type="button" class="btn-sm-outline" data-page="previous">Previous</button>
                <span aria-live="polite"></span>
                <button type="button" class="btn-sm-outline" data-page="next">Next</button>
            </div>
        </div>
    </details>
    <?php endforeach; ?>
    <p class="storage-empty storage-no-match" hidden>No PDFs match this view or search.</p>
</div>
