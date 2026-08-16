# Capstone RMS — Path Fix Guide

> **59 files · 139 individual changes**  
> Complete reference for correcting all broken file paths after the folder restructure.

---

## The Golden Rules

These three rules cover 100% of the changes in this guide.

| Rule | What it means |
|------|---------------|
| **RULE 1** — All config files live in `config/` | `core.php`, `config.php`, `gdrive_config.php`, `groq_config.php`, `mail_config.php` |
| **RULE 2** — Count folder depth → that many `../` | `app/student/` = depth 2 = `../../config/core.php` &nbsp;&nbsp; `archive/` = depth 1 = `../config/core.php` |
| **RULE 3** — `config/` files use `__DIR__` not relative `../` | Inside `config/`, use `__DIR__ . '/config.php'` and `__DIR__ . '/../vendor/autoload.php'` |

---

## Recommended Fix Order

Fix files in this order to avoid cascading errors. Start with the foundation, then work outward.

1. `config/core.php`
2. `config/gdrive_config.php`
3. `config/groq_config.php`
4. `archive/` files (6 files)
5. `app/auth/` files — login, logout, reset (3 files)
6. `app/admin/` files (4 files)
7. `app/faculty/` files (3 files)
8. `app/student/` files (5 files)
9. `app/guest/` files (1 file)
10. `ai/` files (3 files)
11. `analytics/` files (3 files)
12. `notifications/` files (4 files)
13. `pages/` files (9 files)
14. `scripts/migrations/` files (10 files)
15. `scripts/utilities/` files (4 files)
16. `database/seeds/` files (1 file)

> ⚠️ **Fix `config/core.php` FIRST.** It is required by almost every other file. If `core.php` still has broken paths, nothing else will work even after you fix the individual files.

---

## Summary

| Folder | Files | Depth | Changes |
|--------|-------|-------|---------|
| `config/` | 3 | `1 (use __DIR__)` | 8 |
| `archive/` | 6 | `1 (use ../)` | 14 |
| `app/admin/` | 4 | `2 (use ../../)` | 13 |
| `app/auth/` | 3 | `2 (use ../../)` | 4 |
| `app/faculty/` | 3 | `2 (use ../../)` | 9 |
| `app/guest/` | 1 | `2 (use ../../)` | 3 |
| `app/student/` | 5 | `2 (use ../../)` | 12 |
| `ai/` | 3 | `1 (use ../)` | 4 |
| `analytics/` | 3 | `1 (use ../)` | 6 |
| `notifications/` | 2 | `1 (use ../)` | 2 |
| `notifications/cron/` | 2 | `2 (use ../../)` | 3 |
| `pages/` | 9 | `1 (use ../)` | 20 |
| `scripts/migrations/` | 10 | `2 (use ../../)` | 10 |
| `scripts/utilities/` | 4 | `2 (use ../../)` | 7 |
| `database/seeds/` | 1 | `2 (use ../../)` | 1 |

---

## Detailed Fix Tables

---

### `config/` — depth 1 — use `__DIR__`

> ⚠️ **CRITICAL — fix these first.** All other files depend on `core.php`.  
> Note: Lines 135–137 in `core.php` reference `/phpmailer/src/` which does **not exist** — your mailer files are at `mailer/` root level (no `src/` subfolder).

#### `config/core.php` — 8 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L3 | `require_once('/config.php')` | `require_once(__DIR__ . '/config.php')` |
| L129 | `require('/vendor/autoload.php')` | `require(__DIR__ . '/../vendor/autoload.php')` |
| L135 | `require('/phpmailer/src/Exception.php')` | ⚠️ `require(__DIR__ . '/../mailer/Exception.php')` |
| L136 | `require('/phpmailer/src/PHPMailer.php')` | ⚠️ `require(__DIR__ . '/../mailer/PHPMailer.php')` |
| L137 | `require('/phpmailer/src/SMTP.php')` | ⚠️ `require(__DIR__ . '/../mailer/SMTP.php')` |
| L140 | `require('/phpmailer/Exception.php')` | `require(__DIR__ . '/../mailer/Exception.php')` |
| L141 | `require('/phpmailer/PHPMailer.php')` | `require(__DIR__ . '/../mailer/PHPMailer.php')` |
| L142 | `require('/phpmailer/SMTP.php')` | `require(__DIR__ . '/../mailer/SMTP.php')` |

#### `config/gdrive_config.php` — 2 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/config.php')` | `require_once(__DIR__ . '/config.php')` |
| L3 | `require_once('/vendor/autoload.php')` | `require_once(__DIR__ . '/../vendor/autoload.php')` |

#### `config/groq_config.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L4 | `require('/vendor/autoload.php')` | `require(__DIR__ . '/../vendor/autoload.php')` |

---

### `archive/` — depth 1 — use `../`

#### `archive/archive_handler.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L3 | `require_once('/core.php')` | `require_once('../config/core.php')` |

#### `archive/guest_access.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |

#### `archive/home.php` — 4 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |
| L3 | `require_once('/../gdrive_config.php')` | `require_once('../config/gdrive_config.php')` |
| L924 | `include('/../includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L775 | `src="../logo.png"` | `src="../assests/images/logo.png"` |

#### `archive/index.php` — 4 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |
| L3 | `require_once('/../gdrive_config.php')` | `require_once('../config/gdrive_config.php')` |
| L954 | `include('/../includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L779 | `src="../logo.png"` | `src="../assests/images/logo.png"` |

#### `archive/login.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |
| L934 | `include('/../includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L1767 | `include('/../includes/accessibility.php')` | `include('../includes/accessibility.php')` |

#### `archive/view_paper.php` — 5 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |
| L3 | `require_once('/../groq_config.php')` | `require_once('../config/groq_config.php')` |
| L4 | `require_once('/../gdrive_config.php')` | `require_once('../config/gdrive_config.php')` |
| L833 | `include('/../includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L634 | `src="../logo.png"` | `src="../assests/images/logo.png"` |

---

### `app/admin/` — depth 2 — use `../../`

#### `app/admin/admin_manage_faculty.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L1022 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |
| L579 | `src="logo.png"` | `src="../../assests/images/logo.png"` |

#### `app/admin/admin_review_dashboard.php` — 5 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/groq_config.php')` | `require_once('../../config/groq_config.php')` |
| L4 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L1269 | `include('/includes/back_button.php')` | `include('../../includes/back_button.php')` |
| L1484 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |

#### `app/admin/super_admin_manage_admins.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

#### `app/admin/super_admin_review_dashboard.php` — 5 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L4 | `require_once('/archive_handler.php')` | `require_once('../../archive/archive_handler.php')` |
| L5 | `require_once('/groq_config.php')` | `require_once('../../config/groq_config.php')` |
| L1306 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |

---

### `app/auth/` — depth 2 — use `../../`

#### `app/auth/login.php` — 2 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L922 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |

#### `app/auth/logout.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

#### `app/auth/reset_superadmin.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

---

### `app/faculty/` — depth 2 — use `../../`

#### `app/faculty/faculty_manage_students.php` — 4 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L1065 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |
| L586 | `src="logo.png"` | `src="../../assests/images/logo.png"` |

#### `app/faculty/faculty_review_dashboard.php` — 6 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/validation.php')` | `require_once('../../includes/validation.php')` |
| L4 | `require_once('/groq_config.php')` | `require_once('../../config/groq_config.php')` |
| L5 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L1322 | `include('/includes/back_button.php')` | `include('../../includes/back_button.php')` |
| L1610 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |

#### `app/faculty/head_review_dashboard.php` — 2 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |

---

### `app/guest/` — depth 2 — use `../../`

#### `app/guest/admin_manage_guests.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L920 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |
| L517 | `src="logo.png"` | `src="../../assests/images/logo.png"` |

---

### `app/student/` — depth 2 — use `../../`

#### `app/student/student_chatbot.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

#### `app/student/student_dashboard.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L1572 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |

#### `app/student/student_submit.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

#### `app/student/student_upload.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L219 | `src="logo.png"` | `src="../../assests/images/logo.png"` |

#### `app/student/student_upload_ai.php` — 7 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L4 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L5 | `require_once('/groq_config.php')` | `require_once('../../config/groq_config.php')` |
| L6 | `require_once('/gdrive_config.php')` | `require_once('../../config/gdrive_config.php')` |
| L2196 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |
| L339 | `src="js/input-validation.js"` | `src="../../assests/js/input-validation.js"` |
| L1811 | `src="ailogo.png"` | `src="../../assests/images/ailogo.png"` |
| L1834 | `src="ailogo.png"` | `src="../../assests/images/ailogo.png"` |

---

### `ai/` — depth 1 — use `../`

#### `ai/ai_extract.php` — 2 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L3 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L4 | `require_once('/groq_config.php')` | `require_once('../config/groq_config.php')` |

#### `ai/groq_config.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L4 | `require('/vendor/autoload.php')` | `require('../vendor/autoload.php')` |

#### `ai/help_chatbot.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |

---

### `analytics/` — depth 1 — use `../`

#### `analytics/analytics_dashboard.php` — 4 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L3 | `require_once('/groq_config.php')` | `require_once('../config/groq_config.php')` |
| L572 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L226 | `src="logo.png"` | `src="../assests/images/logo.png"` |

#### `analytics/dashboard.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |

#### `analytics/paper_analysis.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L359 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L151 | `src="logo.png"` | `src="../assests/images/logo.png"` |

---

### `notifications/` — depth 1 — use `../`

#### `notifications/notification_center.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/../core.php')` | `require_once('../config/core.php')` |

#### `notifications/notifications_handler.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |

---

### `notifications/cron/` — depth 2 — use `../../`

#### `notifications/cron/auto_archive_papers.php` — 2 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L5 | `require_once('/../core.php')` | `require_once('../../config/core.php')` |
| L6 | `require_once('/../archive_handler.php')` | `require_once('../../archive/archive_handler.php')` |

#### `notifications/cron/send_notifications.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L3 | `require_once('/../core.php')` | `require_once('../../config/core.php')` |

---

### `pages/` — depth 1 — use `../`

#### `pages/about_us.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L223 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L122 | `src="logo.png"` | `src="../assests/images/logo.png"` |

#### `pages/contact_support.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L248 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L134 | `src="logo.png"` | `src="../assests/images/logo.png"` |

#### `pages/download.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |

#### `pages/gdrive_callback.php` — 2 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../config/gdrive_config.php')` |

#### `pages/help_center.php` — 5 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L322 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L150 | `src="logo.png"` | `src="../assests/images/logo.png"` |
| L232 | `src="ailogo.png"` | `src="../assests/images/ailogo.png"` |
| L243 | `src="ailogo.png"` | `src="../assests/images/ailogo.png"` |

#### `pages/index.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |

#### `pages/privacy.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L232 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L115 | `src="logo.png"` | `src="../assests/images/logo.png"` |

#### `pages/terms_and_conditions.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L216 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |
| L117 | `src="logo.png"` | `src="../assests/images/logo.png"` |

#### `pages/view_paper.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../config/core.php')` |
| L3 | `require_once('/gdrive_config.php')` | `require_once('../config/gdrive_config.php')` |
| L170 | `include('/includes/accessibility.php')` | `include('../includes/accessibility.php')` |

---

### `scripts/migrations/` — depth 2 — use `../../`

All 10 migration runner files have the same single fix each.

| File | Line | Original (broken) | Replace with (fixed) |
|------|------|-------------------|----------------------|
| `run_analytics_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_checklist_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_gdrive_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_guest_table_setup.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_migration.php` | L3 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_notification_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_plain_password_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_status_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_student_id_migration.php` | L3 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| `run_upload_update_migration.php` | L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

---

### `scripts/utilities/` — depth 2 — use `../../`

#### `scripts/utilities/diag.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

#### `scripts/utilities/oauth_test_users.php` — 3 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L440 | `include('/includes/back_button.php')` | `include('../../includes/back_button.php')` |
| L441 | `include('/includes/accessibility.php')` | `include('../../includes/accessibility.php')` |

#### `scripts/utilities/ping_session.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

#### `scripts/utilities/test_email_final.php` — 4 changes

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |
| L17 | `require('/phpmailer/Exception.php')` | `require('../../mailer/Exception.php')` |
| L18 | `require('/phpmailer/PHPMailer.php')` | `require('../../mailer/PHPMailer.php')` |
| L19 | `require('/phpmailer/SMTP.php')` | `require('../../mailer/SMTP.php')` |

---

### `database/seeds/` — depth 2 — use `../../`

#### `database/seeds/install_super_admin.php` — 1 change

| Line | Original (broken) | Replace with (fixed) |
|------|-------------------|----------------------|
| L2 | `require_once('/core.php')` | `require_once('../../config/core.php')` |

---

## After All Fixes

1. Run `composer install` from the project root to regenerate `vendor/`
2. Test the login flow first — it depends on `core.php` → `config.php` → DB connection
3. Test a student upload to verify `gdrive_config.php` and asset paths are correct
4. Check the notification cron scripts manually to confirm `archive_handler.php` resolves

> **Note on `assests/` spelling** — all image paths in this guide use `assests/` (with the extra `s`) to match the actual folder name on disk. If you rename the folder to `assets/`, update all image paths accordingly.
