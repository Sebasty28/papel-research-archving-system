<?php
// Archive handler - moves papers to archive table
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../app/models/ArchiveService.php';

function archive_paper(int $paper_id, int $archived_by): bool {
    $archiveService = new ArchiveService(db());
    return $archiveService->archivePaper($paper_id, $archived_by);
}

function restore_paper(int $paper_id): bool {
    $archiveService = new ArchiveService(db());
    return $archiveService->restorePaper($paper_id);
}
