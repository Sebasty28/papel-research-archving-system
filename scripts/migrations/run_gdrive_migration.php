<?php
require_once __DIR__.'/core.php';
$conn = db();

$sql = "ALTER TABLE research_papers ADD COLUMN gdrive_file_id VARCHAR(255) NULL AFTER file_path";

if($conn->query($sql)){
    echo "Column added successfully\n";
} else {
    if(strpos($conn->error, 'Duplicate column') !== false){
        echo "Column already exists\n";
    } else {
        echo "Error: " . $conn->error . "\n";
    }
}
