-- Run in the staging or production database using phpMyAdmin. Safe to repeat.
CREATE TABLE IF NOT EXISTS gdrive_file_inventory (
        file_id VARCHAR(255) NOT NULL,
        owner_email VARCHAR(255) NOT NULL DEFAULT '',
        owner_name VARCHAR(255) NOT NULL DEFAULT '',
        drive_id VARCHAR(255) NOT NULL DEFAULT '',
        parent_id VARCHAR(255) NOT NULL DEFAULT '',
        file_name VARCHAR(500) NOT NULL DEFAULT '',
        access_state VARCHAR(20) NOT NULL DEFAULT 'unchecked',
        checked_by_email VARCHAR(255) NOT NULL DEFAULT '',
        checked_at DATETIME NULL,
        owner_checked_at DATETIME NULL,
        PRIMARY KEY (file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT 'Google Drive PDF account inventory is ready' AS result;
