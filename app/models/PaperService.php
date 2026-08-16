<?php
class PaperService {
    public function approvePaper($paperId, $reviewerId, $reviewLevel, $targetStatus, $message) {
        add_workflow($paperId, $reviewerId, $reviewLevel, 'approved', '');
        set_status($paperId, $targetStatus);
        $studentId = paper_owner($paperId);
        if ($studentId) create_notification($studentId, $paperId, 'progress', $message);
    }

    public function declinePaper($paperId, $reviewerId, $reviewLevel, $feedback, $message) {
        add_workflow($paperId, $reviewerId, $reviewLevel, 'declined', $feedback);
        set_status($paperId, 'draft');
        // Free Drive storage — a declined paper cannot be resubmitted, so its files are no longer needed.
        if (!function_exists('purge_paper_drive_files')) {
            require_once __DIR__ . '/../../config/gdrive_config.php';
        }
        purge_paper_drive_files((int)$paperId);
        $studentId = paper_owner($paperId);
        if ($studentId) create_notification($studentId, $paperId, 'decline', $message);
    }

    public function archivePaper($paperId, $userId) {
        if (!function_exists('archive_paper')) require_once __DIR__ . '/../../archive/archive_handler.php';
        if (archive_paper($paperId, $userId)) {
            return true;
        }
        throw new Exception("Failed to archive paper.");
    }
}