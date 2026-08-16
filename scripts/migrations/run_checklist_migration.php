<?php
require_once __DIR__.'/core.php';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous"><div class="container mt-5"><div class="card"><div class="card-body"><pre>';
$conn = db();

$sql = "CREATE TABLE IF NOT EXISTS paper_checklist (
    checklist_id INT PRIMARY KEY AUTO_INCREMENT,
    paper_id INT NOT NULL,
    imrad_intro BOOLEAN DEFAULT 0,
    imrad_method BOOLEAN DEFAULT 0,
    imrad_result BOOLEAN DEFAULT 0,
    imrad_discussion BOOLEAN DEFAULT 0,
    imrad_references BOOLEAN DEFAULT 0,
    full_ch1 BOOLEAN DEFAULT 0,
    full_ch2 BOOLEAN DEFAULT 0,
    full_ch3 BOOLEAN DEFAULT 0,
    full_ch4 BOOLEAN DEFAULT 0,
    full_ch5 BOOLEAN DEFAULT 0,
    full_references BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_paper (paper_id),
    CONSTRAINT fk_checklist_paper FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if($conn->query($sql)) echo "Checklist table created/verified successfully.\n";
else echo "Error: ".$conn->error."\n";

echo '</pre><a href="faculty_review_dashboard.php" class="btn btn-primary mt-3">Back to Dashboard</a></div></div></div>';
?>