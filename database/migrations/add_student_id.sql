-- Add student_id column to users table
ALTER TABLE users ADD COLUMN student_id VARCHAR(50) NULL AFTER program;

-- Add unique index on student_id (only for non-null values)
CREATE UNIQUE INDEX idx_student_id ON users(student_id);
