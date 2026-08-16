# Two-Level Admin Approval System Implementation

## Overview
This system adds a hierarchical approval process for research papers:
- **Admin Level 1** (Research Coordinator) - First approval stage
- **Admin Level 2** (HAP - Head of Academic Programs) - Second approval stage after Level 1

## Database Changes

### Migration File
Location: `database/migrations/add_admin_level.sql`

Adds:
- `admin_level` column to `users` table (1 or 2)
- `admin_level` column to `approval_workflow` table for tracking

### Run Migration
Execute: `scripts/migrations/run_admin_level_migration.php`

## Workflow Changes

### Current Status Flow
```
draft → pending_faculty → pending_admin → pending_head_academic → pending_super_admin → approved
```

### New Status Flow with Two-Level Admin
```
draft → pending_faculty → pending_admin_l1 → pending_admin_l2 → pending_head_academic → pending_super_admin → approved
```

## Key Changes Required

### 1. Update `research_papers` table status enum
Add new statuses: `pending_admin_l1` and `pending_admin_l2`

```sql
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
```

### 2. Admin Review Dashboard Logic

**For Admin Level 1 (Research Coordinator):**
- Views papers with status: `pending_admin_l1`
- On approval: Changes status to `pending_admin_l2`
- Notifies Admin Level 2 users

**For Admin Level 2 (HAP):**
- Views papers with status: `pending_admin_l2`
- On approval: Changes status to `pending_head_academic`
- Notifies Head of Academic Affairs

### 3. Faculty Approval Update
When faculty approves, set status to `pending_admin_l1` (instead of `pending_admin`)

### 4. User Interface Updates

**Super Admin - Manage Admins Page:**
- ✅ Already updated with admin_level dropdown
- Shows L1 or L2 badge in user list

**Admin Dashboard:**
- Check user's admin_level
- Show appropriate pending papers
- Update approval button text based on level

## Files Modified

1. ✅ `database/migrations/add_admin_level.sql` - Created
2. ✅ `scripts/migrations/run_admin_level_migration.php` - Created
3. ✅ `app/admin/super_admin_manage_admins.php` - Updated
4. ⏳ `database/migrations/add_admin_level.sql` - Need to add status enum update
5. ⏳ `app/admin/admin_review_dashboard.php` - Need to update query logic
6. ⏳ `app/faculty/faculty_review_dashboard.php` - Need to update approval status
7. ⏳ `config/core.php` - May need helper functions

## Implementation Steps

1. ✅ Run migration to add admin_level column
2. ⏳ Update status enum in research_papers table
3. ⏳ Update faculty approval to use pending_admin_l1
4. ⏳ Update admin dashboard to check admin_level
5. ⏳ Update notifications to mention level
6. ⏳ Update progress tracker UI to show both admin levels

## Testing Checklist

- [ ] Create Admin Level 1 user
- [ ] Create Admin Level 2 user
- [ ] Submit paper as student
- [ ] Approve as faculty (should go to Admin L1)
- [ ] Approve as Admin L1 (should go to Admin L2)
- [ ] Approve as Admin L2 (should go to Head of Academic Affairs)
- [ ] Verify notifications at each stage
- [ ] Test decline at each level
