<?php
class PaperRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getPapersByStatus(array $statuses, $limit = null) {
        $in = str_repeat('?,', count($statuses) - 1) . '?';
        $sql = "SELECT p.*, u.full_name as student_name, u.program 
                FROM research_papers p 
                JOIN users u ON u.user_id=p.uploaded_by 
                WHERE p.current_status IN ($in) 
                ORDER BY p.upload_date DESC";
        if ($limit) $sql .= " LIMIT " . (int)$limit;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($statuses)), ...$statuses);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getDeclinedPapers($reviewLevel) {
        $sql = "SELECT p.*, u.full_name as student_name, 
                (SELECT feedback FROM approval_workflow WHERE paper_id=p.paper_id AND status='declined' AND review_level=? ORDER BY reviewed_at DESC LIMIT 1) as feedback
                FROM research_papers p 
                JOIN users u ON u.user_id=p.uploaded_by 
                WHERE p.current_status='draft' 
                AND EXISTS (SELECT 1 FROM approval_workflow aw WHERE aw.paper_id=p.paper_id AND aw.status='declined' AND aw.review_level=?) 
                ORDER BY p.upload_date DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ss', $reviewLevel, $reviewLevel);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getSupportingDocs($paperId) {
        $stmt = $this->conn->prepare("SELECT document_type, file_path, gdrive_file_id FROM supporting_documents WHERE paper_id = ? ORDER BY doc_id DESC");
        $stmt->bind_param('i', $paperId);
        $stmt->execute();
        $result = $stmt->get_result();

        $docs = [];
        $seen = [];
        while($row = $result->fetch_assoc()) {
            if ((!empty($row['file_path']) || !empty($row['gdrive_file_id'])) && !in_array($row['document_type'], $seen, true)) {
                $docs[] = $row;
                $seen[] = $row['document_type'];
            }
        }
        return $docs;
    }

    public function getProgramAnalytics($approvedOnly = false) {
        $cond = $approvedOnly ? "rp.current_status='approved'" : "rp.current_status IN ('approved', 'pending_super_admin', 'pending_head_academic')";
        $sql = "SELECT u.program, COUNT(rp.paper_id) as total_papers";
        if (!$approvedOnly) {
            $sql .= ", SUM(CASE WHEN $cond THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN rp.current_status='draft' THEN 1 ELSE 0 END) as revisions";
        }
        $sql .= " FROM research_papers rp JOIN users u ON rp.uploaded_by=u.user_id WHERE u.user_role='student' AND u.program IS NOT NULL ";
        if ($approvedOnly) $sql .= "AND rp.current_status='approved' ";
        $sql .= "GROUP BY u.program";
        
        $res = $this->conn->query($sql);
        $data = []; if ($res) while($row = $res->fetch_assoc()) $data[] = $row; return $data;
    }

    public function getPaperTypeStats($approvedOnly = false) {
        $cond = $approvedOnly ? "rp.current_status='approved'" : "u.user_role='student' AND u.program IS NOT NULL";
        $res = $this->conn->query("SELECT rp.paper_type, COUNT(*) as count FROM research_papers rp JOIN users u ON u.user_id=rp.uploaded_by WHERE $cond GROUP BY rp.paper_type ORDER BY count DESC");
        $data = []; if ($res) while($row = $res->fetch_assoc()) $data[] = $row; return $data;
    }

    public function getTimelineData($approvedOnly = false) {
        $cond = $approvedOnly ? "rp.current_status='approved'" : "u.user_role='student'";
        $res = $this->conn->query("SELECT DATE_FORMAT(rp.upload_date, '%Y-%m') as month, COUNT(*) as count FROM research_papers rp JOIN users u ON u.user_id=rp.uploaded_by WHERE $cond AND rp.upload_date>= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month");
        $data = []; if ($res) while($row = $res->fetch_assoc()) $data[] = $row; return $data;
    }

    public function getApprovalRateByType($superAdminFlow = false) {
        if ($superAdminFlow) {
            $sql = "SELECT rp.paper_type, COUNT(DISTINCT rp.paper_id) as total, SUM(CASE WHEN rp.current_status IN ('approved', 'pending_admin') THEN 1 ELSE 0 END) as approved FROM research_papers rp JOIN users u ON rp.uploaded_by=u.user_id WHERE u.user_role='student' AND u.program IS NOT NULL AND (rp.current_status IN ('approved', 'pending_admin') OR (rp.current_status='draft' AND EXISTS (SELECT 1 FROM approval_workflow aw WHERE aw.paper_id=rp.paper_id AND aw.status='declined' AND aw.review_level IN ('faculty', 'admin')))) GROUP BY rp.paper_type HAVING total> 0";
        } else {
            $sql = "SELECT rp.paper_type, COUNT(*) as total, SUM(CASE WHEN rp.current_status IN ('approved', 'pending_super_admin', 'pending_head_academic') THEN 1 ELSE 0 END) as approved FROM research_papers rp JOIN users u ON u.user_id=rp.uploaded_by WHERE u.user_role='student' GROUP BY rp.paper_type";
        }
        $res = $this->conn->query($sql);
        $data = []; 
        if ($res) while($row = $res->fetch_assoc()) {
            $row['rate'] = $row['total']> 0 ? round(($row['approved']/$row['total'])*100, 1) : 0;
            $data[] = $row;
        }
        return $data;
    }

    public function getMonthlyComparison($approvedOnly = false) {
        $cond = $approvedOnly ? "current_status='approved' AND " : "";
        $thisMonth = $this->conn->query("SELECT COUNT(*) as count FROM research_papers WHERE $cond MONTH(upload_date) = MONTH(NOW()) AND YEAR(upload_date) = YEAR(NOW())")->fetch_assoc()['count'];
        $lastMonth = $this->conn->query("SELECT COUNT(*) as count FROM research_papers WHERE $cond MONTH(upload_date) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) AND YEAR(upload_date) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))")->fetch_assoc()['count'];
        return ['thisMonth' => $thisMonth, 'lastMonth' => $lastMonth];
    }

    public function getReportsData() {
        $query = "SELECT p.paper_id, p.title, p.author_names, p.year, p.paper_type, u.program, p.upload_date, p.gdrive_file_id, p.file_path, 'Active' as archive_status 
                  FROM research_papers p LEFT JOIN users u ON p.uploaded_by = u.user_id 
                  WHERE p.current_status IN ('approved', 'pending_super_admin', 'pending_head_academic', 'pending_admin_l2')";
        try {
            $archivedQ = " UNION ALL SELECT pa.paper_id, pa.title, pa.author_names, pa.year, pa.paper_type, u.program, pa.upload_date, pa.gdrive_file_id, pa.file_path, 'Archived' as archive_status FROM papers_archive pa LEFT JOIN users u ON pa.uploaded_by = u.user_id";
            $res = $this->conn->query($query . $archivedQ . " ORDER BY upload_date DESC");
            if ($res) {
                $data = []; while($row = $res->fetch_assoc()) $data[] = $row; return $data;
            }
        } catch (Exception $e) {}
        
        $res = $this->conn->query($query . " ORDER BY upload_date DESC");
        $data = []; if ($res) while($row = $res->fetch_assoc()) $data[] = $row; return $data;
    }
}