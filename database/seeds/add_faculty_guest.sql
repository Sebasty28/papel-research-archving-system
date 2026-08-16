-- Add faculty_id column
ALTER TABLE users ADD COLUMN faculty_id VARCHAR(50) NULL AFTER title;

-- Add guest/visitor role support
ALTER TABLE users MODIFY COLUMN user_role ENUM('super_admin','admin','faculty','student','guest') NOT NULL DEFAULT 'student';

-- Add session tracking for guests
CREATE TABLE IF NOT EXISTS guest_sessions (
  session_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expire_time DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);
