-- Add Google Drive file ID column to research_papers table
ALTER TABLE research_papers 
ADD COLUMN gdrive_file_id VARCHAR(255) NULL AFTER file_path;
