# Admin Level 2 (HAP) Dedicated Dashboard

## ✅ What Was Created

### 1. New Dashboard File
**File:** `app/admin/admin_l2_dashboard.php`

**Features:**
- Dedicated dashboard for Admin Level 2 (HAP) users only
- Shows ONLY papers with status `pending_admin_l2`
- Forwards approved papers to `pending_head_academic`
- Same look and feel as Research Coordinator dashboard
- Includes progress tracker showing all 5 stages
- Three tabs: Pending Review, Declined, Forwarded/Approved

### 2. Updated Core Routing
**File:** `config/core.php`

**Changes:**
- Updated `role_home()` function to check admin_level
- Admin Level 1 → `admin_review_dashboard.php`
- Admin Level 2 → `admin_l2_dashboard.php` (NEW)
- Automatic routing based on admin_level in user session

## 🔄 Workflow Hierarchy

```
Student Submits
    ↓
Faculty Approves
    ↓
Admin Level 1 (Research Coordinator) Reviews
    ↓ Approves → Status: pending_admin_l2
Admin Level 2 (HAP) Reviews ← NEW DASHBOARD
    ↓ Approves → Status: pending_head_academic
Head of Academic Affairs Reviews
    ↓
Director Reviews
    ↓
Approved & Published
```

## 📊 Dashboard Comparison

| Feature | Admin L1 Dashboard | Admin L2 Dashboard |
|---------|-------------------|-------------------|
| **File** | `admin_review_dashboard.php` | `admin_l2_dashboard.php` |
| **Shows Papers** | `pending_admin_l1` | `pending_admin_l2` |
| **Forwards To** | Admin Level 2 (HAP) | Head of Academic Affairs |
| **User Label** | "Research Coordinator" | "HAP - Admin Level 2" |
| **Access** | Admin Level 1 only | Admin Level 2 only |

## 🎯 Key Features

### Admin Level 2 Dashboard Includes:
1. **Pending Review Tab**
   - Shows papers approved by Admin L1
   - Status: `pending_admin_l2`
   - Progress tracker highlights Admin L2 stage
   - Approve/Decline actions

2. **Declined Tab**
   - Papers declined by Admin L2
   - Shows feedback given
   - Papers return to draft status

3. **Forwarded/Approved Tab**
   - Papers forwarded to Head
   - Papers approved by Head/Director
   - Full progress tracking

4. **Progress Tracker**
   - Shows all 5 stages
   - Highlights current stage (Admin L2)
   - Visual indicators for completed stages

## 🔐 Access Control

### Admin Level 1 Users:
- Login → Redirected to `admin_review_dashboard.php`
- See papers at `pending_admin_l1`
- Cannot access Admin L2 dashboard

### Admin Level 2 Users:
- Login → Redirected to `admin_l2_dashboard.php`
- See papers at `pending_admin_l2`
- Cannot access Admin L1 dashboard

### Security Check:
```php
// At top of admin_l2_dashboard.php
$admin_level = $u['admin_level'] ?? 1;
if ($admin_level != 2) {
    header('Location: admin_review_dashboard.php');
    exit;
}
```

## 📝 Testing Checklist

- [ ] Create Admin Level 2 user via Super Admin page
- [ ] Login as Admin L2 user
- [ ] Verify redirected to `admin_l2_dashboard.php`
- [ ] Submit paper as student
- [ ] Approve as Faculty
- [ ] Approve as Admin L1
- [ ] Verify paper appears in Admin L2 dashboard
- [ ] Approve as Admin L2
- [ ] Verify paper moves to `pending_head_academic`
- [ ] Check progress tracker shows correct stages

## 🚀 URLs

- **Admin Level 1 Dashboard:** `http://localhost/capstone/app/admin/admin_review_dashboard.php`
- **Admin Level 2 Dashboard:** `http://localhost/capstone/app/admin/admin_l2_dashboard.php`

## 📋 Status Flow

```
pending_faculty
    ↓ (Faculty approves)
pending_admin_l1
    ↓ (Admin L1 approves)
pending_admin_l2 ← Admin L2 Dashboard shows these
    ↓ (Admin L2 approves)
pending_head_academic
    ↓ (Head approves)
pending_super_admin
    ↓ (Director approves)
approved
```

## ✨ Benefits

1. **Clear Separation** - Each admin level has their own dashboard
2. **No Confusion** - Users only see papers relevant to their level
3. **Hierarchy Enforced** - Papers must go through L1 before L2
4. **Same UX** - Both dashboards have consistent design
5. **Automatic Routing** - System routes users to correct dashboard

## 🔧 Files Modified/Created

### Created:
1. `app/admin/admin_l2_dashboard.php` - New dedicated dashboard

### Modified:
1. `config/core.php` - Updated role_home() function

### No Changes Needed:
- Admin L1 dashboard remains unchanged
- All other dashboards remain unchanged
- Database structure already supports this (admin_level column)

## 🎉 Complete!

The hierarchy system is now fully implemented:
- Admin Level 1 and Admin Level 2 have separate dashboards
- Each sees only their relevant papers
- Automatic routing based on admin_level
- Clean separation of concerns
