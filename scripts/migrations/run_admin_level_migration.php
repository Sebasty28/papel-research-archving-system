<?php
require_once '../../config/core.php';
require_role(['super_admin']);

$conn = db();
$sql = file_get_contents(__DIR__ . '/../../database/migrations/add_admin_level.sql');

// Split by semicolon and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = true;
$errors = [];

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    
    try {
        if (!$conn->query($stmt)) {
            $errors[] = $conn->error;
            $success = false;
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        $success = false;
    }
}

if ($success) {
    echo "<h2>✅ Admin Level Migration Successful!</h2>";
    echo "<p>The admin_level column has been added to the users table.</p>";
    echo "<p><a href='../../app/admin/super_admin_manage_admins.php'>Go to Manage Admins</a></p>";
} else {
    echo "<h2>❌ Migration Failed</h2>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
}
