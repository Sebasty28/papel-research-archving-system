<?php
require_once __DIR__ . '/ArchiveRepository.php';
require_once __DIR__ . '/../../includes/public_repository_filters.php';

class ArchiveService {
    private $archiveRepo;
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
        $this->archiveRepo = new ArchiveRepository($dbConnection);
    }

    public function archivePaper(int $paperId, int $archivedBy): bool {
        try {
            $this->conn->begin_transaction();
            
            $paper = $this->archiveRepo->getActivePaper($paperId);
            if (!$paper) {
                $this->conn->rollback();
                return false;
            }
            
            $this->archiveRepo->insertToArchive($paper, $archivedBy);
            $this->archiveRepo->deleteActive($paperId);
            
            $this->conn->commit();
            public_repository_filter_cache_after_change($this->conn);
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Archive error: ' . $e->getMessage());
            return false;
        }
    }

    public function restorePaper(int $paperId): bool {
        try {
            $this->conn->begin_transaction();
            
            $paper = $this->archiveRepo->getArchivedPaper($paperId);
            if (!$paper) {
                $this->conn->rollback();
                return false;
            }
            
            $this->archiveRepo->insertToActive($paper);
            $this->archiveRepo->deleteArchive($paperId);
            
            $this->conn->commit();
            public_repository_filter_cache_after_change($this->conn);
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log('Restore error: ' . $e->getMessage());
            return false;
        }
    }
}
