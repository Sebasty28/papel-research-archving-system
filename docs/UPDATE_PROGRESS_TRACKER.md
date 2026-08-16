# Progress Tracker Update Instructions

## What Changed
The progress tracker now shows **5 stages** instead of 4:
1. Faculty (Research Adviser)
2. Admin L1 (Research Coordinator) - NEW
3. Admin L2 (HAP) - NEW
4. Head of Academic Affairs
5. Director (Super Admin)

## Files to Update

### 1. Student Dashboard
**File:** `app/student/student_dashboard.php`

**Add at top (after require statements):**
```php
require_once '../../includes/progress_tracker.php';
```

**Replace old progress tracker HTML with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

### 2. Faculty Dashboard
**File:** `app/faculty/faculty_review_dashboard.php`

**Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**In the pending section, replace the progress tracker div with:**
```php
<?php render_progress_tracker('pending_faculty'); ?>
```

**In the approved section, replace with:**
```php
<?php render_progress_tracker($p['current_status']); ?>
```

---

### 3. Admin Dashboard (Level 1 & 2)
**File:** `app/admin/admin_review_dashboard.php`

**Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**In pending section:**
```php
<?php 
$admin_level = $u['admin_level'] ?? 1;
$tracker_status = $admin_level == 1 ? 'pending_admin_l1' : 'pending_admin_l2';
render_progress_tracker($tracker_status); 
?>
```

**In approved section:**
```php
<?php render_progress_tracker($ap['current_status']); ?>
```

---

### 4. Head of Academic Affairs Dashboard
**File:** `app/faculty/head_review_dashboard.php`

**Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**Replace progress tracker with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

### 5. Super Admin Dashboard
**File:** `app/admin/super_admin_review_dashboard.php`

**Add at top:**
```php
require_once '../../includes/progress_tracker.php';
```

**Replace progress tracker with:**
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

## Quick Find & Replace

### Old Progress Tracker Code (Remove this):
```html
<div class="progress-tracker">
  <div class="progress-line"><div class="progress-line-fill step2"></div></div>
  <div class="progress-step">
    <div class="step-circle completed"><?= $icon_green ?></div>
    <div class="step-label completed">Research Adviser</div>
  </div>
  <div class="progress-step">
    <div class="step-circle current"><?= $icon_yellow ?></div>
    <div class="step-label current">Research Coordinator</div>
  </div>
  <div class="progress-step">
    <div class="step-circle default">3</div>
    <div class="step-label default">Head of Academic Affairs</div>
  </div>
  <div class="progress-step">
    <div class="step-circle default">4</div>
    <div class="step-label default">Director</div>
  </div>
</div>
```

### New Code (Replace with this):
```php
<?php render_progress_tracker($paper['current_status']); ?>
```

---

## Testing Checklist

After updating all files:
- [ ] Student can see their paper's progress
- [ ] Faculty sees "pending_faculty" stage highlighted
- [ ] Admin L1 sees "pending_admin_l1" stage highlighted
- [ ] Admin L2 sees "pending_admin_l2" stage highlighted
- [ ] Head sees "pending_head_academic" stage highlighted
- [ ] Director sees "pending_super_admin" stage highlighted
- [ ] Approved papers show all 5 stages completed (green)

---

## Status Mapping Reference

| Status | Stage Highlighted |
|--------|------------------|
| `draft` | None |
| `pending_faculty` | Stage 1 (Faculty) |
| `pending_admin_l1` or `pending_admin` | Stage 2 (Admin L1) |
| `pending_admin_l2` | Stage 3 (Admin L2) |
| `pending_head_academic` | Stage 4 (Head) |
| `pending_super_admin` | Stage 5 (Director) |
| `approved` | All 5 stages completed |
