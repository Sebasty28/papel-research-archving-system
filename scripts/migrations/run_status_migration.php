<?php
require_once __DIR__.'/core.php';
$conn = db();
echo "<pre>Updating status enum...\n";
$conn->query("ALTER TABLE research_papers MODIFY COLUMN current_status ENUM('draft', 'pending_faculty', 'pending_admin', 'pending_head_academic', 'pending_super_admin', 'approved', 'declined', 'archived') DEFAULT 'draft'");
echo "✅ Migration complete. 'pending_head_academic' status added.</pre>";
echo '<a href="index.php">Go Home</a>';
?>