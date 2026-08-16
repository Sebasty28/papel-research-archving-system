-- 001_init_idempotent.sql (FINAL)
SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS `papel_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `papel_db`;

CREATE TABLE IF NOT EXISTS users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    user_role ENUM('super_admin', 'admin', 'faculty', 'student') NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS research_papers (
    paper_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(500) NOT NULL,
    author_names TEXT NOT NULL,
    year INT NOT NULL,
    abstract TEXT,
    keywords TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT,
    uploaded_by INT NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    current_status ENUM('draft','pending_faculty','pending_admin','pending_super_admin','approved','declined','archived') DEFAULT 'draft',
    is_imrad_complete BOOLEAN DEFAULT FALSE,
    CONSTRAINT fk_papers_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supporting_documents (
    doc_id INT PRIMARY KEY AUTO_INCREMENT,
    paper_id INT NOT NULL,
    document_type ENUM('ethics_clearance','consent_form','data_collection','other') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_docs_paper FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approval_workflow (
    workflow_id INT PRIMARY KEY AUTO_INCREMENT,
    paper_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    review_level ENUM('faculty','admin','super_admin') NOT NULL,
    status ENUM('pending','approved','declined') DEFAULT 'pending',
    feedback TEXT,
    reviewed_at TIMESTAMP NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_workflow_paper FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE CASCADE,
    CONSTRAINT fk_workflow_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS imrad_checklist (
    checklist_id INT PRIMARY KEY AUTO_INCREMENT,
    paper_id INT NOT NULL,
    has_introduction BOOLEAN DEFAULT FALSE,
    has_methods BOOLEAN DEFAULT FALSE,
    has_results BOOLEAN DEFAULT FALSE,
    has_discussion BOOLEAN DEFAULT FALSE,
    has_abstract BOOLEAN DEFAULT FALSE,
    has_references BOOLEAN DEFAULT FALSE,
    checked_by INT NULL,
    checked_at TIMESTAMP NULL,
    CONSTRAINT fk_imrad_paper FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE CASCADE,
    CONSTRAINT fk_imrad_checked_by FOREIGN KEY (checked_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    paper_id INT NULL,
    notification_type ENUM('submission','approval','decline','reminder','comment') NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_paper FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics (
    analytics_id INT PRIMARY KEY AUTO_INCREMENT,
    paper_id INT NOT NULL,
    view_count INT DEFAULT 0,
    download_count INT DEFAULT 0,
    approval_date TIMESTAMP NULL,
    time_to_approval INT NULL COMMENT 'Days from submission to approval',
    CONSTRAINT fk_analytics_paper FOREIGN KEY (paper_id) REFERENCES research_papers(paper_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_schedule (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    scheduled_time TIME NOT NULL COMMENT '10:00 and 15:30',
    last_sent TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    CONSTRAINT fk_notif_sched_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Indexes (idempotent)
SET @e := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_papers' AND INDEX_NAME='idx_papers_status');
SET @sql := IF(@e=0, 'CREATE INDEX idx_papers_status ON research_papers(current_status)', 'SELECT "ok"'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_papers' AND INDEX_NAME='idx_papers_year');
SET @sql := IF(@e=0, 'CREATE INDEX idx_papers_year ON research_papers(year)', 'SELECT "ok"'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @e := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_papers' AND INDEX_NAME='ft_papers_text');
SET @sql := IF(@e=0, 'CREATE FULLTEXT INDEX ft_papers_text ON research_papers(title,abstract,keywords)', 'SELECT "ok"'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
