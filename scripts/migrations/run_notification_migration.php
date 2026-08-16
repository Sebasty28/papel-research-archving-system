<?php
require_once __DIR__.'/core.php';
$conn = db();
echo '<pre>';
try {
    $conn->query("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message");
    echo "Added is_read column.\n";
} catch (Exception $e) {
    echo "Column likely exists or error: " . $e->getMessage() . "\n";
}
echo "Done.";