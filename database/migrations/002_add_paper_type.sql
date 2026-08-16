-- Add paper_type column to research_papers table
ALTER TABLE research_papers 
ADD COLUMN paper_type VARCHAR(50) DEFAULT 'research' AFTER current_status;

-- Update existing records to have a default type
UPDATE research_papers SET paper_type = 'research' WHERE paper_type IS NULL;
