# Role-Based Dashboard System

## Overview
Each user role now has a dedicated dashboard with specific functionalities tailored to their responsibilities.

## Role Dashboards

### 1. Super Admin / Director
**Dashboard:** `app/admin/super_admin_review_dashboard.php`
**Capabilities:**
- Final approval of research ethics (after HAP approval)
- Manage approved papers archive
- View analytics and statistics
- Create and manage all staff roles (HAP, Research Coordinator, Librarian, Research Adviser)
- Access to `super_admin_manage_admins.php` for user management

**Workflow Position:** Final approver in the chain

---

### 2. Research Coordinator (Admin)
**Dashboard:** `app/admin/admin_review_dashboard.php`
**Capabilities:**
- Review and approve/decline research papers submitted by students
- Manage faculty accounts via `admin_manage_faculty.php`
- Manage guest accounts via `app/guest/admin_manage_guests.php`
- Forward approved papers to Head of Academic Affairs
- Same review capabilities as faculty

**Workflow Position:** Second reviewer (after faculty)

---

### 3. HAP (Head of Academic Programs)
**Dashboard:** `app/faculty/head_academic_dashboard.php`
**Capabilities:**
- Review research ethics that have been approved by BOTH:
  - Faculty (Research Adviser)
  - Research Coordinator (Admin)
- Approve or decline research ethics
- Forward approved research ethics to Super Admin/Director for final approval
- View review history

**Workflow Position:** Third reviewer (after faculty and research coordinator)

**Key Features:**
- Only sees papers approved by both faculty AND research coordinator
- Focuses specifically on research ethics review
- Acts as quality control before director's final approval

---

### 4. Research Adviser (Faculty)
**Dashboard:** `app/faculty/faculty_review_dashboard.php`
**Capabilities:**
- Review research papers from assigned students
- Approve or decline submissions
- Manage student accounts via `faculty_manage_students.php`
- Provide feedback on submissions
- Forward approved papers to Research Coordinator

**Workflow Position:** First reviewer in the chain

---

### 5. Librarian
**Dashboard:** `app/guest/admin_manage_guests.php`
**Capabilities:**
- Create guest accounts for external users
- Generate temporary access credentials
- Set access duration (1-24 hours)
- Send credentials via email
- Revoke guest access
- Manage active and expired guest sessions
- View session statistics

**Special Features:**
- Guest accounts are temporary and auto-expire
- Guests have read-only access to the research archive
- Credentials are randomly generated and emailed

---

### 6. Student
**Dashboard:** `app/student/student_dashboard.php`
**Capabilities:**
- Submit research papers
- Track submission status
- View feedback from reviewers
- Upload documents
- Access chatbot assistance

---

### 7. Guest
**Dashboard:** `archive/index.php`
**Capabilities:**
- Read-only access to approved research papers
- Search and browse the archive
- View paper details
- Temporary access (expires based on librarian settings)

---

## Approval Workflow

```
Student Submission
    ↓
Faculty (Research Adviser) Review
    ↓ (if approved)
Research Coordinator (Admin) Review
    ↓ (if approved)
HAP Review (Research Ethics)
    ↓ (if approved)
Super Admin / Director Final Approval
    ↓
Archived in Repository
```

## Database Tables

### Users Table
- `user_role` field determines dashboard access:
  - `super_admin`
  - `admin` (Research Coordinator)
  - `head_academic` (HAP)
  - `faculty` (Research Adviser)
  - `librarian`
  - `student`
  - `guest`

### Approval Workflow Table
- Tracks review history
- `review_level` field: 'faculty', 'admin', 'head_academic' (HAP), 'super_admin'
- `status` field: 'pending', 'approved', 'declined'

### Guest Sessions Table
- Stores temporary guest credentials
- Auto-expires based on `expires_at` timestamp
- Stores plain password for email delivery

## Key Files

### Core Configuration
- `config/core.php` - Role routing and authentication
- `config/config.php` - Database and app settings

### Admin Dashboards
- `app/admin/super_admin_review_dashboard.php` - Director dashboard
- `app/admin/super_admin_manage_admins.php` - Staff management
- `app/admin/admin_review_dashboard.php` - Research Coordinator dashboard
- `app/admin/admin_manage_faculty.php` - Faculty management

### Faculty Dashboards
- `app/faculty/head_academic_dashboard.php` - HAP dashboard
- `app/faculty/faculty_review_dashboard.php` - Research Adviser dashboard
- `app/faculty/faculty_manage_students.php` - Student management

### Guest Management
- `app/guest/admin_manage_guests.php` - Librarian dashboard for guest accounts

### Student Dashboard
- `app/student/student_dashboard.php` - Student submission portal

### Archive
- `archive/index.php` - Public/guest research archive

## Security Features

1. **Role-based Access Control**
   - `require_role()` function enforces access restrictions
   - Each dashboard checks user role before rendering

2. **CSRF Protection**
   - All forms include CSRF tokens
   - Verified on submission

3. **Session Management**
   - Secure session handling
   - Auto-logout on inactivity

4. **Guest Account Security**
   - Time-limited access
   - Auto-expiration
   - Revocable credentials
   - Read-only permissions

## Notifications System

All roles receive notifications for:
- Paper submissions
- Approval/decline decisions
- Status changes
- Pending reviews

Notifications are role-specific and contextual.

## Email System

Automated emails sent for:
- Account creation (all roles)
- Guest credential delivery
- Approval/decline notifications
- Password resets

Uses PHPMailer with Gmail SMTP configuration.
