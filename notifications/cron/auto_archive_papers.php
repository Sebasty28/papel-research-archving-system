<?php
// Auto-archive papers older than 5 years (1825 days)
// Run this script daily via cron job: 0 0 * * * php /path/to/auto_archive_papers.php

require_once __DIR__.'/../../config/core.php';
require_once __DIR__.'/../../archive/archive_handler.php';
$conn = db();

// Get papers that are approved and older than 5 years
$stmt = $conn->query("SELECT paper_id FROM research_papers 
                      WHERE current_status='approved' 
                      AND DATEDIFF(NOW(), upload_date)>= 1825");

$archived_count = 0;
while($row = $stmt->fetch_assoc()){
  if(archive_paper($row['paper_id'], 0)){ // 0 = system auto-archive
    $archived_count++;
  }
}

error_log("Auto-archive: Archived $archived_count papers older than 5 years");
echo "Archived $archived_count papers\n";
