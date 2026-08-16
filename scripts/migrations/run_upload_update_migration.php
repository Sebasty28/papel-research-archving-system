<?php
require_once __DIR__.'/core.php';
$conn = db();

echo "<pre>Running upload logic migration...\n";

$sqls = [
    "ALTER TABLE research_papers ADD COLUMN research_type VARCHAR(100) NULL AFTER paper_type",
    "ALTER TABLE research_papers ADD COLUMN manuscript_type VARCHAR(100) NULL AFTER research_type",
    "ALTER TABLE research_papers ADD COLUMN publication_status ENUM('published', 'unpublished') DEFAULT 'unpublished' AFTER current_status",
    "ALTER TABLE research_papers ADD COLUMN publication_location VARCHAR(255) NULL AFTER publication_status",
    "ALTER TABLE research_papers ADD COLUMN program_category VARCHAR(100) NULL AFTER author_names",
    "ALTER TABLE research_papers ADD COLUMN source_code_path VARCHAR(255) NULL AFTER file_path"
];

foreach($sqls as $sql) {
    try {
        $conn->query($sql);
        echo "✅ Executed: " . substr($sql, 0, 50) . "...\n";
    } catch (Exception $e) {
        // Ignore duplicate column errors
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        } else {
            echo "ℹ️  Column already exists.\n";
        }
    }
}

echo "\nDone.</pre>";
echo '<a href="student_upload_ai.php" class="btn btn-primary">Go to Upload</a>';
?>