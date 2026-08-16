# Quick Setup Guide: Two-Level Admin System

## Step 1: Run the Migration
1. Open your browser and go to:
   ```
   http://localhost/capstone/scripts/migrations/run_admin_level_migration.php
   ```
2. You should see "✅ Admin Level Migration Successful!"

## Step 2: Create Admin Users
1. Login as Super Admin
2. Go to "Manage Admins"
3. Create two admin users:
   - **Admin Level 1** (Research Coordinator) - Select "Level 1" from dropdown
   - **Admin Level 2** (HAP) - Select "Level 2" from dropdown

## Step 3: Test the System

### What Happens Now:
1. **Student submits paper** → Status: `draft`
2. **Faculty approves** → Status: `pending_admin_l1`
3. **Admin Level 1 approves** → Status: `pending_admin_l2`
4. **Admin Level 2 (HAP) approves** → Status: `pending_head_academic`
5. **HAP approves** → Status: `pending_super_admin`
6. **Director approves** → Status: `approved`

## What's Already Done:
✅ Database migration file created
✅ Migration runner script created
✅ Super Admin page updated to set admin levels
✅ Admin level badge shows in user list (L1 or L2)

## What You Need to Update:

### File: `app/admin/admin_review_dashboard.php`

**Line ~50-60** - Update the query to check admin level:
```php
// Get current admin's level
$admin_level = $u['admin_level'] ?? 1;

// List pending for this admin level
if ($admin_level == 1) {
    // Level 1 sees pending_admin_l1
    $rows = $conn->query("SELECT p.paper_id,p.title,p.file_path,p.gdrive_file_id,u.full_name as student_name,p.upload_date, u.program
                          FROM research_papers p
                          JOIN users u ON u.user_id=p.uploaded_by
                          WHERE p.current_status='pending_admin_l1'
                          ORDER BY p.upload_date DESC");
} else {
    // Level 2 sees pending_admin_l2
    $rows = $conn->query("SELECT p.paper_id,p.title,p.file_path,p.gdrive_file_id,u.full_name as student_name,p.upload_date, u.program
                          FROM research_papers p
                          JOIN users u ON u.user_id=p.uploaded_by
                          WHERE p.current_status='pending_admin_l2'
                          ORDER BY p.upload_date DESC");
}
```

**Line ~30-40** - Update approval action:
```php
if($action==='approve'){
    $admin_level = $u['admin_level'] ?? 1;
    
    if ($admin_level == 1) {
        // Level 1 forwards to Level 2
        add_workflow($paper_id, $u['user_id'], 'admin', 'approved', $feedback);
        set_status($paper_id,'pending_admin_l2');
        
        $student_id = paper_owner($paper_id);
        if($student_id){ 
            create_notification($student_id,$paper_id,'progress','Paper approved by Research Coordinator (Level 1). Forwarded to HAP (Level 2).'); 
        }
        
        flash('success','Paper forwarded to Admin Level 2 (HAP).');
    } else {
        // Level 2 forwards to Head of Academic Affairs
        add_workflow($paper_id, $u['user_id'], 'admin', 'approved', $feedback);
        set_status($paper_id,'pending_head_academic');
        
        $student_id = paper_owner($paper_id);
        if($student_id){ 
            create_notification($student_id,$paper_id,'progress','Paper approved by HAP (Level 2). Forwarded to HAP.'); 
        }
        
        flash('success','Paper forwarded to HAP.');
    }
}
```

**Line ~200** - Update the user greeting:
```php
$level_text = ($u['admin_level'] ?? 1) == 1 ? 'Level 1 - Research Coordinator' : 'Level 2 - HAP';
// Then in HTML:
<span>Hello, <?= e($u['full_name']) ?>! (<?= $level_text ?>)</span>
```

### File: `app/faculty/faculty_review_dashboard.php`

**Find the approval section** (around line 30-50) and change:
```php
// OLD:
set_status($paper_id,'pending_admin');

// NEW:
set_status($paper_id,'pending_admin_l1');
```

**Update notification message:**
```php
// OLD:
create_notification($student_id,$paper_id,'progress','Paper approved by Faculty. Forwarded to Research Coordinator.');

// NEW:
create_notification($student_id,$paper_id,'progress','Paper approved by Faculty. Forwarded to Research Coordinator (Level 1).');
```

## That's It!

After making these changes:
1. Test by submitting a paper as a student
2. Approve as faculty
3. Login as Admin Level 1 and approve
4. Login as Admin Level 2 and approve
5. Verify the paper moves through all stages correctly

## Troubleshooting

**If you see "pending_admin" in database:**
- The migration should have updated these to "pending_admin_l1"
- If not, run this SQL manually:
  ```sql
  UPDATE research_papers SET current_status = 'pending_admin_l1' WHERE current_status = 'pending_admin';
  ```

**If admin_level column doesn't exist:**
- Make sure you ran the migration script
- Check if there were any errors in the migration output
