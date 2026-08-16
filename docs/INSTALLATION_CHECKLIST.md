# Installation Checklist

## Pre-Installation

- [ ] Backup database
  ```bash
  mysqldump -u root -p capstone > backup_$(date +%Y%m%d).sql
  ```

- [ ] Backup files
  ```bash
  # Copy entire capstone folder
  cp -r capstone capstone_backup_$(date +%Y%m%d)
  ```

- [ ] Verify PHP version (7.4+)
  ```bash
  php -v
  ```

- [ ] Verify MySQL version (5.7+)
  ```bash
  mysql --version
  ```

---

## Installation Steps

### Step 1: Run Migration (5 minutes)

- [ ] Open terminal/command prompt
- [ ] Navigate to capstone folder
  ```bash
  cd c:\xampp\htdocs\capstone
  ```

- [ ] Run migration script
  ```bash
  php run_migration.php
  ```

- [ ] Verify output shows:
  - ✅ papers_archive table created
  - ✅ Indexes added
  - ✅ No errors

**Expected Output:**
```
=== Database Migration Runner ===

📦 Creating papers_archive table...
  ✓ Executed: CREATE TABLE IF NOT EXISTS papers_archive...
  ✓ Executed: ALTER TABLE research_papers ADD INDEX...
  ✓ Executed: ALTER TABLE users ADD INDEX...
✅ Migration completed!

📊 Checking indexes...
  research_papers indexes: 3 found
  users indexes: 2 found

📈 Table Statistics:
  Active approved papers: 150
  Archived papers: 0
  Papers ready to archive (>5 years): 0

✅ All checks complete!
```

---

### Step 2: Verify Security (2 minutes)

- [ ] Run security audit
  ```bash
  php security_audit.php
  ```

- [ ] Verify output shows:
  - ✅ All files secure
  - ✅ No SQL injection vulnerabilities
  - ✅ Security score: 100/100

**Expected Output:**
```
=== SQL Injection Security Audit ===

✅ core.php - SECURE (15 prepared statements)
✅ admin_review_dashboard.php - SECURE (8 prepared statements)
✅ faculty_review_dashboard.php - SECURE (6 prepared statements)
✅ super_admin_review_dashboard.php - SECURE (4 prepared statements)
✅ admin_manage_faculty.php - SECURE (5 prepared statements)
✅ faculty_manage_students.php - SECURE (5 prepared statements)
✅ super_admin_manage_admins.php - SECURE (4 prepared statements)
✅ student_upload_ai.php - SECURE (12 prepared statements)
✅ archive_handler.php - SECURE (8 prepared statements)

=== Summary ===
Files checked: 9
Secure files: 9
Vulnerable files: 0
Prepared statements found: 67

✅ No SQL injection vulnerabilities found!
✅ All queries use prepared statements
✅ System is secure against SQL injection attacks

=== Security Score ===
🏆 Excellent: 100/100

=== Audit Complete ===
```

---

### Step 3: Test Archive Feature (3 minutes)

- [ ] Login as Admin
  - Username: (your admin username)
  - Password: (your admin password)

- [ ] Navigate to "Approved Papers" section

- [ ] Click "Archive" button on any paper

- [ ] Verify success message appears
  - Expected: "Paper archived successfully."

- [ ] Check database
  ```sql
  -- Open phpMyAdmin or MySQL command line
  SELECT COUNT(*) FROM papers_archive;
  -- Should show 1 or more
  ```

- [ ] Verify paper removed from approved list

---

### Step 4: Test Performance (2 minutes)

- [ ] Open browser developer tools (F12)

- [ ] Go to Network tab

- [ ] Refresh admin dashboard

- [ ] Check page load time
  - Before: ~500ms
  - After: ~50ms (should be much faster)

- [ ] Verify no errors in console

---

### Step 5: Setup Auto-Archive (Optional, 5 minutes)

#### Windows Task Scheduler:

- [ ] Open Task Scheduler
  - Press Win+R, type `taskschd.msc`, press Enter

- [ ] Click "Create Basic Task"

- [ ] Name: "Auto Archive Papers"

- [ ] Trigger: Daily at 12:00 AM

- [ ] Action: Start a program
  - Program: `C:\xampp\php\php.exe`
  - Arguments: `C:\xampp\htdocs\capstone\cron\auto_archive_papers.php`

- [ ] Click Finish

- [ ] Test manually:
  ```bash
  php cron/auto_archive_papers.php
  ```

#### Linux/Mac Cron:

- [ ] Open crontab
  ```bash
  crontab -e
  ```

- [ ] Add line:
  ```
  0 0 * * * php /path/to/capstone/cron/auto_archive_papers.php
  ```

- [ ] Save and exit

- [ ] Test manually:
  ```bash
  php cron/auto_archive_papers.php
  ```

---

## Post-Installation Verification

### Database Checks:

- [ ] Verify tables exist
  ```sql
  SHOW TABLES LIKE 'papers_archive';
  -- Should return 1 row
  ```

- [ ] Verify indexes
  ```sql
  SHOW INDEX FROM research_papers;
  -- Should show idx_current_status, idx_upload_date, idx_uploaded_by
  
  SHOW INDEX FROM users;
  -- Should show idx_is_active, idx_user_role
  
  SHOW INDEX FROM papers_archive;
  -- Should show idx_archived_date, idx_uploaded_by, idx_paper_type, idx_year
  ```

- [ ] Check table sizes
  ```sql
  SELECT 
    table_name,
    table_rows,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
  FROM information_schema.TABLES
  WHERE table_schema = 'capstone'
  AND table_name IN ('research_papers', 'papers_archive');
  ```

### Application Checks:

- [ ] Login as Student
  - Verify dashboard loads
  - Verify no errors

- [ ] Login as Faculty
  - Verify dashboard loads
  - Verify pending papers show
  - Verify no errors

- [ ] Login as Admin
  - Verify dashboard loads
  - Verify approved papers show
  - Verify archive button works
  - Verify no errors

- [ ] Login as Super Admin
  - Verify dashboard loads
  - Verify approved papers show
  - Verify archive button works
  - Verify no errors

### Performance Checks:

- [ ] Dashboard loads in < 100ms
- [ ] Archive action completes in < 500ms
- [ ] No slow query warnings in MySQL log
- [ ] No PHP errors in error log

---

## Troubleshooting

### Issue: Migration fails

**Symptoms:**
- Error: "Table already exists"
- Error: "Permission denied"

**Solutions:**
- [ ] Check if tables already exist
  ```sql
  SHOW TABLES LIKE 'papers_archive';
  ```
- [ ] Verify MySQL user has CREATE TABLE permission
- [ ] Check database name in config.php

---

### Issue: Archive button doesn't work

**Symptoms:**
- Click archive, nothing happens
- Error message appears

**Solutions:**
- [ ] Check papers_archive table exists
- [ ] Verify archive_handler.php is included
- [ ] Check PHP error log
  ```bash
  tail -f C:\xampp\php\logs\php_error_log
  ```
- [ ] Verify database connection

---

### Issue: Security audit shows vulnerabilities

**Symptoms:**
- Security score < 100
- Vulnerable files listed

**Solutions:**
- [ ] Re-download fixed files
- [ ] Verify all changes were applied
- [ ] Check for custom modifications
- [ ] Run audit again

---

### Issue: Queries still slow

**Symptoms:**
- Dashboard takes > 500ms to load
- Archive list slow

**Solutions:**
- [ ] Verify indexes were created
  ```sql
  SHOW INDEX FROM research_papers;
  ```
- [ ] Optimize tables
  ```sql
  OPTIMIZE TABLE research_papers;
  OPTIMIZE TABLE papers_archive;
  ```
- [ ] Check table sizes
- [ ] Verify papers were moved to archive

---

## Rollback Procedure (If Needed)

### Step 1: Restore Database
```bash
mysql -u root -p capstone < backup_YYYYMMDD.sql
```

### Step 2: Restore Files
```bash
# Copy backup folder back
cp -r capstone_backup_YYYYMMDD/* capstone/
```

### Step 3: Verify
- [ ] Login to application
- [ ] Check all features work
- [ ] Verify data intact

---

## Success Criteria

✅ All checks passed:
- [ ] Migration completed successfully
- [ ] Security audit shows 100/100
- [ ] Archive feature works
- [ ] Performance improved (< 100ms load time)
- [ ] No errors in logs
- [ ] All user roles can login
- [ ] Auto-archive cron job configured (optional)

---

## Documentation Reference

- **QUICK_START.md** - Quick installation guide
- **SECURITY_IMPROVEMENTS.md** - Detailed documentation
- **IMPLEMENTATION_SUMMARY.md** - Complete summary
- **BEFORE_AFTER.md** - Visual comparison
- **This file** - Step-by-step checklist

---

## Support

**Need help?**

1. Check troubleshooting section above
2. Review documentation files
3. Run security audit: `php security_audit.php`
4. Check error logs
5. Verify database structure

---

## Completion

Date completed: _______________

Completed by: _______________

Notes:
_________________________________
_________________________________
_________________________________

**Status: [ ] Complete [ ] Incomplete [ ] Issues**

---

**🎉 Congratulations! Your system is now secure and optimized!**
