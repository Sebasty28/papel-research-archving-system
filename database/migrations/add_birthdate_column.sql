-- Add birthdate column to users table for authentication
ALTER TABLE users ADD COLUMN birthdate DATE NULL AFTER email;