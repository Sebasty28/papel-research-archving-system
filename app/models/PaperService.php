<?php
class PaperService {
    public function approvePaper($paperId, $reviewerId, $reviewLevel, $targetStatus, $message) {
        add_workflow($paperId, $reviewerId, $reviewLevel, 'approved', '');
        set_status($paperId, $targetStatus);

        /* Stamp how long the paper took, once it is actually approved.
           analytics.time_to_approval and the tile that reads it have both
           existed all along, but nothing ever wrote the column, so the figure
           always showed "not recorded yet". Measured from upload to this
           moment, in days, and only on the final approval — an adviser signing
           off is a step along the way, not the end of it. */
        if ($targetStatus === 'approved') {
            $this->recordTimeToApproval((int)$paperId);
        }

        $studentId = paper_owner($paperId);
        if ($studentId) create_notification($studentId, $paperId, 'progress', $message);
    }

    /**
     * Days from upload to approval, written to the analytics row.
     *
     * Never allowed to interrupt an approval: a paper being approved matters,
     * a statistic about it does not. Anything unexpected is logged and
     * swallowed.
     */
    private function recordTimeToApproval(int $paperId): void {
        try {
            $conn = db();
            $q = $conn->prepare(
                "SELECT GREATEST(TIMESTAMPDIFF(HOUR, upload_date, NOW()), 0) / 24 AS days
                   FROM research_papers WHERE paper_id = ?");
            $q->bind_param('i', $paperId);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            if (!$row || $row['days'] === null) return;

            $days = round((float)$row['days'], 2);
            /* The analytics row is created on first view, so it may not exist
               yet — this has to be able to make one. */
            $up = $conn->prepare(
                "INSERT INTO analytics (paper_id, time_to_approval) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE time_to_approval = VALUES(time_to_approval)");
            $up->bind_param('id', $paperId, $days);
            $up->execute();
        } catch (Throwable $e) {
            error_log('time_to_approval not recorded for paper ' . $paperId . ': ' . $e->getMessage());
        }
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