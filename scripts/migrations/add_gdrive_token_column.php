<?php
require_once '../../config/core.php';
$conn = db();

echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">';
echo '<div class="container mt-5"><div class="card"><div class="card-body">';
echo "<h3>Database Migration: Add gdrive_token</h3>";
echo "<pre>";

try {
    // Check if column exists first
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'gdrive_token'");
    if($check->num_rows == 0) {
        $conn->query("ALTER TABLE users ADD COLUMN gdrive_token TEXT NULL AFTER is_active");
        echo "✅ Column 'gdrive_token' added successfully.\n";
    } else {
        echo "ℹ️  Column 'gdrive_token' already exists.\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo "</pre>";
echo '<a href="../../app/student/student_upload_ai.php" class="btn btn-primary mt-3">Return to Upload Page</a>';
echo '</div></div></div>';
?>