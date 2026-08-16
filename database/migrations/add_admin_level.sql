-- Add admin_level column to users table
-- Level 1 = Research Coordinator (approves first)
-- Level 2 = HAP (Head of Academic Programs) - approves after Level 1

ALTER TABLE users 
ADD COLUMN IF NOT EXISTS admin_level TINYINT DEFAULT 1 
COMMENT '1=Research Coordinator, 2=HAP (Head of Academic Programs)';

-- Add admin_level to approval_workflow for tracking
ALTER TABLE approval_workflow 
ADD COLUMN IF NOT EXISTS admin_level TINYINT DEFAULT NULL 
COMMENT 'Admin level that reviewed (1 or 2)';

-- Update research_papers status enum to include two admin levels
ALTER TABLE research_papers 
MODIFY COLUMN current_status ENUM(
  'draft',
  'pending_faculty',
  'pending_admin_l1',
  'pending_admin_l2',
  'pending_head_academic',
  'pending_super_admin',
  'approved',
  'declined',
  'archived'
) DEFAULT 'draft';

-- Update existing admin users to level 1 by default
UPDATE users SET admin_level = 1 WHERE user_role = 'admin' AND admin_level IS NULL;

-- Update existing pending_admin status to pending_admin_l1
UPDATE research_papers SET current_status = 'pending_admin_l1' WHERE current_status = 'pending_admin';
