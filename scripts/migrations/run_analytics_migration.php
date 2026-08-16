<?php
require_once __DIR__.'/core.php';
$conn = db();

echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous"><div class="container mt-5"><div class="card"><div class="card-body"><pre>';
echo "Running analytics table migration...\n\n";

$sql = "CREATE TABLE IF NOT EXISTS analytics (
    paper_id INT PRIMARY KEY,
    view_count INT DEFAULT 0,
    download_count INT DEFAULT 0,
    last_viewed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if($conn->query($sql)) echo "✅ Table 'analytics' created/verified successfully.\n";
else echo "❌ Error: ".$conn->error."\n";

echo '</pre><a href="index.php" class="btn btn-primary mt-3">Go Home</a></div></div></div>';
?>