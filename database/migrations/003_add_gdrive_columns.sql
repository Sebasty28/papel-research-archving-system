-- Add gdrive_file_id column to research_papers table
ALTER TABLE research_papers 
ADD COLUMN gdrive_file_id VARCHAR(255) NULL AFTER file_path;

-- Add gdrive_file_id to supporting_documents table
ALTER TABLE supporting_documents 
ADD COLUMN gdrive_file_id VARCHAR(255) NULL AFTER file_path;
