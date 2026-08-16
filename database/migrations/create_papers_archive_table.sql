-- Create papers_archive table for better performance
CREATE TABLE IF NOT EXISTS papers_archive (
  paper_id INT PRIMARY KEY,
  title VARCHAR(500) NOT NULL,
  author_names TEXT,
  year INT,
  abstract TEXT,
  keywords TEXT,
  file_path VARCHAR(500),
  file_size INT,
  uploaded_by INT NOT NULL,
  paper_type VARCHAR(50),
  gdrive_file_id VARCHAR(255),
  ai_summary TEXT,
  ai_methodology TEXT,
  ai_sample_size VARCHAR(100),
  ai_statistical_methods TEXT,
  ai_variables TEXT,
  ai_research_field VARCHAR(255),
  upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  archived_date DATETIME NOT NULL,
  archived_by INT,
  INDEX idx_archived_date (archived_date),
  INDEX idx_uploaded_by (uploaded_by),
  INDEX idx_paper_type (paper_type),
  INDEX idx_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add indexes to research_papers for better query performance
ALTER TABLE research_papers 
ADD INDEX IF NOT EXISTS idx_current_status (current_status),
ADD INDEX IF NOT EXISTS idx_upload_date (upload_date),
ADD INDEX IF NOT EXISTS idx_uploaded_by (uploaded_by);

-- Add indexes to users table
ALTER TABLE users 
ADD INDEX IF NOT EXISTS idx_is_active (is_active),
ADD INDEX IF NOT EXISTS idx_user_role (user_role);
