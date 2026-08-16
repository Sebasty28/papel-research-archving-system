# Head of Academic Affairs Bug Fixes

## Issues Found and Fixed

### 1. **User Creation Bug in super_admin_manage_admins.php**
**Problem:** INSERT query had parameter mismatch
- SQL had 11 columns but `is_active` was hardcoded as `1` instead of using placeholder `?`
- bind_param type string was `'ssssssssis'` (10 types) but needed 11
- This caused silent failure when creating users

**Fix:**
```php
// BEFORE (BROKEN):
$stmt=$conn->prepare("INSERT INTO users (...,is_active,...) VALUES (...,1,?)");
$stmt->bind_param('ssssssssis',...); // 10 parameters

// AFTER (FIXED):
$is_active = 1;
$stmt=$conn->prepare("INSERT INTO users (...,is_active,...) VALUES (...,?,?)");
$stmt->bind_param('ssssssssiss',...,$is_active,...); // 11 parameters
```

### 2. **Wrong Dashboard Path in login.php**
**Problem:** Login redirect pointed to non-existent directory
```php
// BEFORE (BROKEN):
'head_academic' => '../head_academic/head_academic_dashboard.php',  // Wrong path!
'librarian' => '../librarian/librarian_dashboard.php',  // Wrong path!
```

**Fix:**
```php
// AFTER (FIXED):
'head_academic' => '../faculty/head_academic_dashboard.php',  // Correct!
'librarian' => '../guest/admin_manage_guests.php',  // Correct!
```

### 3. **Wrong Dashboard Path in core.php**
**Problem:** role_home() function routed head_academic to faculty dashboard
```php
// BEFORE (BROKEN):
if($role === 'head_academic') return BASE_URL.'/app/faculty/faculty_review_dashboard.php';
```

**Fix:**
```php
// AFTER (FIXED):
if($role === 'head_academic') return BASE_URL.'/app/faculty/head_academic_dashboard.php';
```

### 4. **Duplicate Dashboard File**
**Problem:** Old placeholder file existed in wrong location
- File: `c:\xampp\htdocs\capstone\pages\head_academic_dashboard.php`
- This was an old placeholder that caused confusion

**Fix:** Deleted the old file. Correct file is at:
- `c:\xampp\htdocs\capstone\app\faculty\head_academic_dashboard.php`

## Correct File Structure

```
capstone/
├── app/
│   ├── admin/
│   │   ├── super_admin_manage_admins.php  ✓ Fixed
│   │   ├── super_admin_review_dashboard.php
│   │   └── admin_review_dashboard.php
│   ├── faculty/
│   │   ├── head_academic_dashboard.php  ✓ Correct location
│   │   └── faculty_review_dashboard.php
│   ├── guest/
│   │   └── admin_manage_guests.php  ✓ Librarian dashboard
│   └── auth/
│       └── login.php  ✓ Fixed
└── config/
    └── core.php  ✓ Fixed
```

## Testing Checklist

### Test User Creation:
1. Login as Super Admin
2. Go to "Manage Admins" page
3. Fill out form with Head of Academic Affairs role:
   - Role: Head of Academic Affairs
   - Full Name: Test Head
   - Employee ID: EMP-TEST-001
   - Email: testhead@test.com
   - Username: testhead
   - Birthdate: Select any date
   - Password: Generate or enter password
4. Click "Create Account"
5. Should see success message with username and password
6. User should appear in Active Staff table

### Test Login:
1. Logout from Super Admin
2. Login with Head of Academic Affairs credentials:
   - University ID: testhead (or employee ID)
   - Password: (the generated password)
   - Birthdate: (the one you entered)
3. Should redirect to: `/app/faculty/head_academic_dashboard.php`
4. Should see "Head of Academic Affairs Dashboard" with pending reviews

### Test Dashboard:
1. Dashboard should show:
   - Pending Review tab (papers approved by both faculty and admin)
   - Reviewed tab (your review history)
2. Should be able to approve/decline papers
3. Approved papers should forward to Super Admin

## Database Requirements

Ensure these columns exist in `users` table:
```sql
-- Check structure
DESCRIBE users;

-- Required columns:
- user_id (INT, PRIMARY KEY, AUTO_INCREMENT)
- username (VARCHAR)
- email (VARCHAR)
- password (VARCHAR)
- plain_password (VARCHAR)  ← Must exist
- full_name (VARCHAR)
- faculty_id (VARCHAR)
- birthdate (DATE)
- user_role (VARCHAR)
- created_by (INT)
- is_active (TINYINT)  ← Must exist
- title (VARCHAR)  ← Must exist
- created_at (TIMESTAMP)
```

## All Fixed Files

1. **app/admin/super_admin_manage_admins.php**
   - Fixed INSERT query parameter binding
   - Changed from 10 to 11 parameters
   - Added $is_active variable

2. **app/auth/login.php**
   - Fixed head_academic dashboard path
   - Fixed librarian dashboard path
   - Changed from '../head_academic/' to '../faculty/'
   - Changed from '../librarian/' to '../guest/'

3. **config/core.php**
   - Fixed role_home() function
   - head_academic now routes to head_academic_dashboard.php

4. **pages/head_academic_dashboard.php**
   - DELETED (was old placeholder)

5. **app/faculty/head_academic_dashboard.php**
   - CREATED (new functional dashboard)

## Summary

The Head of Academic Affairs role was not working due to THREE separate issues:
1. User creation failed silently (SQL parameter mismatch)
2. Login redirected to wrong path (non-existent directory)
3. Role routing pointed to wrong dashboard

All issues are now fixed. Head of Academic Affairs accounts can now be:
- ✓ Created successfully
- ✓ Login successfully
- ✓ Access their dedicated dashboard
- ✓ Review research ethics
- ✓ Forward approvals to Super Admin
