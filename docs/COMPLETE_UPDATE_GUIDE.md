# COMPLETE UPDATE GUIDE: Two-Level Admin System

## Step 1: Run Migration (REQUIRED FIRST)
```
http://localhost/capstone/scripts/migrations/run_admin_level_migration.php
```

## Step 2: Update All Dashboard Files

### FILE 1: `app/admin/admin_review_dashboard.php`

**A) Add at top (line ~5, after require statements):**
```php
require_once '../../includes/progress_tracker.php';
```

**B) Update query section (line ~50-60):**
```php
// OLD CODE - REMOVE:
$rows = $conn->query("SELECT p.paper_id,p.title,p.file_path,p.gdrive_file_id,u.full_name as student_name,p.upload_date, u.program
                      FROM research_papers p
                      JOIN users u ON u.user_id=p.uploaded_by
                      WHERE p.current_status='pending_admin'
                      ORDER BY p.upload_date DESC");

// NEW CODE - ADD:
$admin_level = $u['admin_level'] ?? 1;
if ($admin_level == 1) {
    $rows = $conn->query("SELECT p.paper_id,p.title,p.file_path,p.gdrive_file_id,u.full_name as student_name,p.upload_date, u.program
                          FROM research_papers p
                          JOIN users u ON u.user_id=p.uploaded_by
                          WHERE p.current_status IN ('pending_admin_l1', 'pending_admin')
                          ORDER BY p.upload_date DESC");
} else {
    $rows = $conn->query("SELECT p.paper_id,p.title,p.file_path,p.gdrive_file_id,u.full_name as student_name,p.upload_date, u.program
                          FROM research_papers p
                          JOIN users u ON u.user_id=p.uploaded_by
                          WHERE p.current_status='pending_admin_l2'
                          ORDER BY p.upload_date DESC");
}
```

**C) Update approval action (line ~30-40):**
```php
// OLD CODE - REMOVE:
if($action==='approve'){
    add_workflow($paper_id, $u['user_id'], 'admin', 'approved', $feedback);
    set_status($paper_id,'pending_head_academic');
    
    $student_id = paper_owner($paper_id);
    if($student_id){ create_notification($student_id,$paper_id,'progress','Paper approved by Admin. Forwarded to Head of Academic Affairs.'); }
    
    flash('success','Paper forwarded to Head of Academic Affairs.');
}

// NEW CODE - ADD:
if($action==='approve'){
    $admin_level = $u['admin_level'] ?? 1;
    
    if ($admin_level == 1) {
        add_workflow($paper_id, $u['user_id'], 'admin', 'approved', $feedback);
        set_status($paper_id,'pending_admin_l2');
        
        $student_id = paper_owner($paper_id);
        if($student_id){ 
            create_notification($student_id,$paper_id,'progress','Paper approved by Research Coordinator (Level 1). Forwarded to HAP (Level 2).'); 
        }
        
        flash('success','Paper forwarded to Admin Level 2 (HAP).');
    } else {
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

**D) Update user greeting (find the line with "Research Coordinator"):**
```php
// OLD:
<span>Hello, <?= e($u['full_name']) ?>! (Research Coordinator)</span>

// NEW:
<?php $level_text = ($u['admin_level'] ?? 1) == 1 ? 'Level 1 - Research Coordinator' : 'Level 2 - HAP'; ?>
<span>Hello, <?= e($u['full_name']) ?>! (<?= $level_text ?>)</span>
```

**E) Replace ALL progress tracker HTML with:**
```php
<?php render_progress_tracker($p['current_status']); ?>
```

---

### FILE 2: `app/faculty/faculty_review_dashboard.php`

**A) Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**B) Update approval status (find set_status line):**
```php
// OLD:
set_status($paper_id,'pending_admin');

// NEW:
set_status($paper_id,'pending_admin_l1');
```

**C) Update notification message:**
```php
// OLD:
create_notification($student_id,$paper_id,'progress','Paper approved by Faculty. Forwarded to Research Coordinator.');

// NEW:
create_notification($student_id,$paper_id,'progress','Paper approved by Faculty. Forwarded to Research Coordinator (Level 1).');
```

**D) Replace progress tracker HTML with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

### FILE 3: `app/student/student_dashboard.php`

**A) Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**B) Replace progress tracker HTML with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

### FILE 4: `app/faculty/head_review_dashboard.php`

**A) Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**B) Replace progress tracker HTML with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

### FILE 5: `app/admin/super_admin_review_dashboard.php`

**A) Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**B) Replace progress tracker HTML with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

## Step 3: Test the Complete Flow

1. **Login as Super Admin**
   - Go to Manage Admins
   - Create Admin Level 1 user
   - Create Admin Level 2 user

2. **Submit Paper as Student**
   - Upload a research paper
   - Status should be: `draft`

3. **Approve as Faculty**
   - Paper moves to: `pending_admin_l1`
   - Progress tracker shows Faculty ✅, Admin L1 🟡

4. **Approve as Admin Level 1**
   - Paper moves to: `pending_admin_l2`
   - Progress tracker shows Faculty ✅, Admin L1 ✅, Admin L2 🟡

5. **Approve as Admin Level 2**
   - Paper moves to: `pending_head_academic`
   - Progress tracker shows Faculty ✅, Admin L1 ✅, Admin L2 ✅, Head 🟡

6. **Approve as Head**
   - Paper moves to: `pending_super_admin`
   - Progress tracker shows 4 stages ✅, Director 🟡

7. **Approve as Director**
   - Paper moves to: `approved`
   - Progress tracker shows all 5 stages ✅

---

## Quick Reference: Status Flow

```
draft
  ↓
pending_faculty (Faculty reviewing)
  ↓
pending_admin_l1 (Admin Level 1 reviewing)
  ↓
pending_admin_l2 (Admin Level 2 / HAP reviewing)
  ↓
pending_head_academic (Head reviewing)
  ↓
pending_super_admin (Director reviewing)
  ↓
approved (Published)
```

---

## Troubleshooting

**Problem:** Admin Level 1 sees no papers
- **Solution:** Make sure faculty is setting status to `pending_admin_l1` (not `pending_admin`)

**Problem:** Progress tracker shows wrong stage
- **Solution:** Check the paper's `current_status` in database matches expected value

**Problem:** Old papers stuck at `pending_admin`
- **Solution:** Run this SQL:
  ```sql
  UPDATE research_papers SET current_status = 'pending_admin_l1' WHERE current_status = 'pending_admin';
  ```

**Problem:** Admin level not showing in user list
- **Solution:** Make sure migration ran successfully and `admin_level` column exists

---

## Summary of Changes

✅ Database: Added `admin_level` column and new statuses
✅ Progress Tracker: Now shows 5 stages instead of 4
✅ Admin Dashboard: Checks admin level and shows appropriate papers
✅ Faculty Dashboard: Sends to `pending_admin_l1` instead of `pending_admin`
✅ All Dashboards: Use new progress tracker component

**Total Files Modified:** 7 files
**Total Time:** ~15 minutes
