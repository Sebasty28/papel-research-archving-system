<?php
// Run student_id migration
require_once __DIR__.'/core.php';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous"><div class="container mt-5"><div class="card"><div class="card-body"><pre>';
$conn = db();

$sqls = [
    "ALTER TABLE users ADD COLUMN student_id VARCHAR(50) NULL AFTER program",
    "CREATE UNIQUE INDEX idx_student_id ON users(student_id)"
];

echo "Running student_id migration...\n\n";

foreach($sqls as $sql){
    if($conn->query($sql)){
        echo "✓ OK: " . substr($sql, 0, 60) . "...\n";
    } else {
        if(strpos($conn->error, 'Duplicate') !== false || strpos($conn->error, 'already exists') !== false || strpos($conn->error, 'Duplicate key') !== false){
            echo "⊘ SKIP (already exists): " . substr($sql, 0, 60) . "...\n";
        } else {
            echo "✗ ERROR: " . $conn->error . "\n";
        }
    }
}

$conn->close();
echo "\nMigration complete!\n";
echo "Note: If you see 'SKIP' messages, the column/index already exists - this is normal.\n";
echo '</pre><a href="login.php" class="btn btn-primary mt-3">Back to Login</a></div></div></div>';
