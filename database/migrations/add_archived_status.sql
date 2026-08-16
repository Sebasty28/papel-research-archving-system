-- Add archived status to research papers
ALTER TABLE research_papers 
ADD COLUMN archived_date DATETIME NULL AFTER current_status;

-- Update current_status enum to include 'archived'
ALTER TABLE research_papers 
MODIFY COLUMN current_status ENUM('draft','pending_faculty','pending_admin','approved','archived') DEFAULT 'draft';
