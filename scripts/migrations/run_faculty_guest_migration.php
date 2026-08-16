<?php
$conn = new mysqli('localhost', 'root', '', 'papel_db');
if($conn->connect_error) die('Connection failed');

$sqls = [
    "ALTER TABLE users ADD COLUMN faculty_id VARCHAR(50) NULL AFTER title",
    "ALTER TABLE users MODIFY COLUMN user_role ENUM('super_admin','admin','faculty','student','guest') NOT NULL DEFAULT 'student'",
    "CREATE TABLE IF NOT EXISTS guest_sessions (
      session_id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expire_time DATETIME NOT NULL,
      FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )"
];

foreach($sqls as $sql){
    if($conn->query($sql)){
        echo "OK: " . substr($sql, 0, 50) . "...\n";
    } else {
        if(strpos($conn->error, 'Duplicate') !== false || strpos($conn->error, 'already exists') !== false){
            echo "SKIP: " . substr($sql, 0, 50) . "...\n";
        } else {
            echo "ERROR: " . $conn->error . "\n";
        }
    }
}
$conn->close();
echo "\nMigration complete\n";
