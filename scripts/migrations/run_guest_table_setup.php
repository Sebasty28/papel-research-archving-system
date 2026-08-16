<?php
require_once __DIR__.'/core.php';
$conn = db();

echo "<pre>";
echo "Setting up guest_sessions table...\n";

// Drop table if exists to ensure schema matches current code
$conn->query("DROP TABLE IF EXISTS guest_sessions");

$sql = "CREATE TABLE guest_sessions (
    guest_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    plain_password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL
)";

if ($conn->query($sql)) {
    echo "✅ Table guest_sessions created successfully.\n";
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

echo "</pre>";
echo '<a href="archive/login.php" class="btn btn-primary">Go to Archive Login</a>';
?>