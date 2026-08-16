<?php
// Migration runner - applies database migrations
require_once __DIR__.'/core.php';

$conn = db();

echo "=== Database Migration Runner ===\n\n";

// Check if papers_archive table exists
$result = $conn->query("SHOW TABLES LIKE 'papers_archive'");
if($result->num_rows> 0){
    echo "✅ papers_archive table already exists\n";
} else {
    echo "📦 Creating papers_archive table...\n";
    
    $migration = file_get_contents(__DIR__.'/database/migrations/create_papers_archive_table.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $migration)));
    
    foreach($statements as $sql){
        if(empty($sql)) continue;
        try {
            $conn->query($sql);
            echo "  ✓ Executed: " . substr($sql, 0, 50) . "...\n";
        } catch(Exception $e){
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "✅ Migration completed!\n";
}

// Verify indexes
echo "\n📊 Checking indexes...\n";
$indexes = $conn->query("SHOW INDEX FROM research_papers WHERE Key_name IN ('idx_current_status', 'idx_upload_date', 'idx_uploaded_by')");
echo "  research_papers indexes: " . $indexes->num_rows . " found\n";

$indexes = $conn->query("SHOW INDEX FROM users WHERE Key_name IN ('idx_is_active', 'idx_user_role')");
echo "  users indexes: " . $indexes->num_rows . " found\n";

// Show table stats
echo "\n📈 Table Statistics:\n";
$stats = $conn->query("SELECT 
    (SELECT COUNT(*) FROM research_papers WHERE current_status='approved') as active_papers,
    (SELECT COUNT(*) FROM papers_archive) as archived_papers,
    (SELECT COUNT(*) FROM research_papers WHERE current_status='approved' AND DATEDIFF(NOW(), upload_date)>= 1825) as papers_to_archive
");
$row = $stats->fetch_assoc();
echo "  Active approved papers: {$row['active_papers']}\n";
echo "  Archived papers: {$row['archived_papers']}\n";
echo "  Papers ready to archive (>5 years): {$row['papers_to_archive']}\n";

if($row['papers_to_archive']> 0){
    echo "\n⚠️  Run cron/auto_archive_papers.php to archive old papers\n";
}

echo "\n✅ All checks complete!\n";
