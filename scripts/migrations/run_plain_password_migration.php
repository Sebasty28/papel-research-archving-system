<?php
require_once __DIR__.'/core.php';
$conn = db();
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous"><div class="container mt-5"><div class="card"><div class="card-body"><pre>';
try {
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'plain_password'");
    if ($result->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN plain_password VARCHAR(255) NULL AFTER password");
        echo "✅ Added plain_password column to users table.\n";
    } else {
        echo "ℹ️  Column plain_password already exists.\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
echo "</pre>";
echo '<a href="admin_manage_faculty.php" class="btn btn-primary mt-3">Back to Faculty Management</a></div></div></div>';
?>