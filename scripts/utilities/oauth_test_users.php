<?php
require_once '../../config/core.php';
require_role(['admin', 'super_admin', 'faculty']);
$conn = db();
$u = current_user();

// Get all active student emails
$students = $conn->query("SELECT user_id, full_name, email, created_at FROM users WHERE user_role='student' AND is_active=1 ORDER BY created_at DESC");

// Get all active faculty emails
$faculty = $conn->query("SELECT user_id, full_name, email, created_at FROM users WHERE user_role='faculty' AND is_active=1 ORDER BY created_at DESC");

// Get all active admin emails
$admins = $conn->query("SELECT user_id, full_name, email, created_at FROM users WHERE user_role IN ('admin','super_admin') AND is_active=1 ORDER BY created_at DESC");

// Count totals
$total_students = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_role='student' AND is_active=1")->fetch_assoc()['count'];
$total_faculty = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_role='faculty' AND is_active=1")->fetch_assoc()['count'];
$total_admins = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_role IN ('admin','super_admin') AND is_active=1")->fetch_assoc()['count'];
$total = $total_students + $total_faculty + $total_admins;

// Get notifications
$notifs = $conn->query("SELECT * FROM notifications WHERE user_id={$u['user_id']} ORDER BY created_at DESC LIMIT 5");
?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OAuth Test Users · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Theme adapted from Admin Dashboard */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; min-height: 100vh; background-color: #fcf8f7; position: relative; }
/* body::before { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255, 255, 255, 0.85); z-index: -1; } */

/* Header */
.header { background: linear-gradient(135deg, #810403, #dca92c); border-bottom: none; padding: 12px 40px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.logo { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 600; color: #fff; text-decoration: none; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
.logo-icon { width: 32px; height: 32px; background-color: #810403; border-radius: 4px; background-image: url('../../assests/images/logo.png'); background-size: cover; }
.nav { display: flex; gap: 30px; }
.nav a { text-decoration: none; color: rgba(255,255,255,0.9); font-size: 15px; padding: 8px 0; transition: all 0.3s; }
.nav a:hover { color: #fff; transform: translateY(-1px); }
.nav a.active { border-bottom: 2px solid #fff; font-weight: 600; color: #fff; }
.user-section { display: flex; align-items: center; gap: 20px; }
.notification-icon { cursor: pointer; font-size: 14px; position: relative; border: none; background: none; padding: 0; color: #fff; font-weight: 600; }
.user-info { display: flex; align-items: center; gap: 8px; color: #fff; font-weight: 500; }
.user-avatar { width: 32px; height: 32px; border-radius: 50%; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; background: rgba(255,255,255,0.2); }

/* Notification Dropdown */
.dropdown-menu { position: absolute; right: 0; top: 100%; z-index: 1060; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border: none; display: none; background: white; min-width: 300px; }
.dropdown-menu.show { display: block; animation: slideDown 0.2s ease-out; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* Footer */
.footer { 
    background-color: #810403;
    color: white;
    margin-top: auto;
    padding: 60px 0 20px 0;
    width: 100%;
    flex-shrink: 0;
}
.footer-content { 
    max-width: 1400px; 
    margin: 0 auto; 
    padding: 0 40px; 
    display: grid; 
    grid-template-columns: 2fr 1fr 1fr; 
    gap: 60px;
    margin-bottom: 40px;
}
.footer-logo { 
    display: flex; 
    align-items: center; 
    gap: 12px; 
    font-size: 28px; 
    font-weight: 800; 
    margin-bottom: 20px;
    color: #fff;
}
.footer-logo-icon { 
    width: 40px; 
    height: 40px; 
    background: linear-gradient(135deg, #fede0e, #dca92c); 
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: #810403;
    font-size: 20px;
}
.footer-description {
    color: rgba(255,255,255,0.8);
    line-height: 1.6;
    font-size: 15px;
    max-width: 400px;
}
.footer-title {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 20px;
    color: #dca92c;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.footer-link { 
    display: block; 
    color: rgba(255,255,255,0.75); 
    text-decoration: none; 
    margin-bottom: 12px; 
    font-size: 14px;
    transition: all 0.3s;
    padding-left: 0;
}
.footer-link:hover { 
    color: #dca92c;
    padding-left: 5px;
}
.footer-copyright { 
    text-align: center; 
    padding: 25px 40px;
    border-top: 1px solid rgba(255,255,255,0.1); 
    font-size: 14px; 
    color: rgba(255,255,255,0.6);
    background-color: rgba(0,0,0,0.1);
}

@media(max-width:900px){ 
    .footer-content{grid-template-columns:1fr 1fr;} 
    .footer { padding: 40px 0 20px 0; }
}
@media(max-width:600px){ 
    .footer-content{grid-template-columns:1fr; gap: 40px;} 
    .footer { padding: 30px 0 15px 0; }
}

/* Custom Button & Card Colors */
.btn-primary { background-color: #810403; border-color: #810403; }
.btn-primary:hover { background-color: #600302; border-color: #600302; }
.btn-info { background-color: #dca92c; border-color: #dca92c; color: #810403; }
.btn-info:hover { background-color: #b8860b; border-color: #b8860b; color: #810403; }
.card-header.bg-primary { background: linear-gradient(135deg, #810403, #600302) !important; color: white; }
.card-header.bg-success { background: linear-gradient(135deg, #dca92c, #b8860b) !important; color: #810403 !important; }
.card-header.bg-danger { background: linear-gradient(135deg, #be7d7c, #a0605f) !important; color: white; }

/* Alerts */
.alert { border-radius: 8px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
.alert-info { background: linear-gradient(135deg, #fcf8f7 0%, #fff8e1 100%); color: #810403; border-left: 4px solid #810403; }
.alert-warning { background: linear-gradient(135deg, #fff8e1 0%, #fede0e 100%); color: #92400e; border-left: 4px solid #dca92c; }
</style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="<?= role_home($u['user_role']) ?>" class="logo">
            <div class="logo-icon"></div>
            <?= e(APP_NAME) ?>
        </a>
        
        <nav class="nav">
            <?php if($u['user_role'] === 'faculty'): ?>
                <a href="https://sis8.pup.edu.ph/faculty/" target="_blank">PUPSIS</a>
                <a href="../../app/faculty/faculty_manage_students.php">Manage Students</a>
                <a href="oauth_test_users.php" class="active">OAuth Users</a>
                <a href="../../app/faculty/faculty_review_dashboard.php">Dashboard</a>
            <?php elseif($u['user_role'] === 'super_admin'): ?>
                <a href="https://sis8.pup.edu.ph/" target="_blank">PUPSIS</a>
                <a href="../../app/admin/super_admin_manage_admins.php">Admins</a>
                <a href="oauth_test_users.php" class="active">OAuth Users</a>
                <a href="../../app/admin/super_admin_review_dashboard.php">Dashboard</a>
            <?php else: ?>
                <a href="https://sis8.pup.edu.ph/" target="_blank">PUPSIS</a>
                <a href="../../app/admin/admin_manage_faculty.php">Manage Faculty</a>
                <a href="oauth_test_users.php" class="active">OAuth Users</a>
                <a href="../../app/guest/admin_manage_guests.php">Guests</a>
                <a href="../../app/admin/admin_review_dashboard.php">Dashboard</a>
            <?php endif; ?>
        </nav>
        
        <div class="user-section">
            <!-- Notification Bell -->
            <div class="dropdown">
                <button class="notification-icon" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    Notifications
                    <?php 
                    $unread_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id={$u['user_id']} AND is_read=0")->fetch_assoc()['count'] ?? 0;
                    if($unread_count> 0): 
                    ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
                        <?= $unread_count> 9 ? '9+' : $unread_count ?>
                    </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0" aria-labelledby="notificationDropdown" style="width: 350px; max-height: 450px; overflow-y: auto;">
                    <li><div class="dropdown-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #810403, #dca92c); color: white; padding: 1rem; margin: 0;"><span style="font-weight: 700;">Recent Updates</span><?php if($unread_count> 0): ?><span class="badge bg-white text-primary" style="font-size: 0.7rem;"><?= $unread_count ?> new</span><button id="markAllReadBtn" class="btn btn-link btn-sm p-0 text-warning" style="text-decoration: none; font-size: 0.7rem;">Mark all read</button><?php endif; ?></div></li>
                    <?php if($notifs && $notifs->num_rows> 0): $notifs->data_seek(0); while($n = $notifs->fetch_assoc()): ?>
                    <li><a href="#" class="dropdown-item notif-item px-3 py-2 border-bottom" data-notif-id="<?= $n['notification_id'] ?>" data-notif-msg="<?= htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8') ?>" data-notif-date="<?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?>" data-notif-read="<?= $n['is_read'] ?>" style="background: <?= $n['is_read'] ? '#ffffff' : '#f0f9ff' ?>;"><div class="d-flex align-items-start"><div class="me-2" style="font-size: 1.2rem;"><?php if(stripos($n['message'], 'approved') !== false) echo '✅'; elseif(stripos($n['message'], 'declined') !== false) echo '❌'; elseif(stripos($n['message'], 'submitted') !== false) echo '📤'; else echo '📢'; ?></div><div class="flex-grow-1"><p class="mb-1 small text-truncate" style="max-width: 250px; line-height: 1.4; color: #1e293b;"><?= e($n['message']) ?></p><small class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock"></i> <?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></small></div></div></a></li>
                    <?php endwhile; else: ?>
                    <li><div class="text-center py-5 text-muted"><p class="mb-0">No notifications yet</p></div></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="user-info">
                <span>Hello, <?= e($u['full_name']) ?>! (<?= ucfirst($u['user_role']) ?>)</span>
                <div class="user-avatar">User</div>
            </div>
            <a href="../../app/auth/logout.php" style="text-decoration:none; color:rgba(255,255,255,0.8); font-size:14px;">Logout</a>
        </div>
    </header>

<div class="container">
<div class="card mt-4">
<div class="card-body">
<h2 class="h5 mb-3">OAuth Test Users Management</h2>

<div class="alert alert-info">
<strong>Google Cloud Console Setup Required:</strong><br>
Since your OAuth app is in "Testing" mode, you must manually add user emails as test users in Google Cloud Console.<br>
<strong>Limit:</strong> 100 users maximum<br>
<strong>Current Users:</strong> <?= $total ?> / 100 (<?= $total_students ?> students + <?= $total_faculty ?> faculty + <?= $total_admins ?> admins)
</div>

<?php if($total> 100): ?>
<div class="alert alert-warning">
<strong>Warning:</strong> You have <?= $total ?> users but OAuth only allows 100 test users. 
You need to either:<br>
1. Publish your OAuth app (requires verification), or<br>
2. Limit total user accounts to 100
</div>
<?php endif; ?>

<div class="mb-3">
<h6>Quick Actions:</h6>
<button class="btn btn-primary btn-sm" id="copyAllEmailsBtn">Copy All Emails</button>
<button class="btn btn-success btn-sm" id="downloadCSVBtn">Download CSV</button>
<a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" class="btn btn-info btn-sm">Open Google Cloud Console</a>
</div>

<div class="card mb-3">
<div class="card-body">
<h6>How to Add Test Users:</h6>
<ol class="small mb-0">
<li>Click "Open Google Cloud Console" button above</li>
<li>Go to "OAuth consent screen" → "Test users" section</li>
<li>Click "+ ADD USERS"</li>
<li>Copy emails from the list below (or use "Copy All Emails" button)</li>
<li>Paste emails (one per line or comma-separated)</li>
<li>Click "SAVE"</li>
</ol>
</div>
</div>

<h6>All User Emails (<?= $total ?>):</h6>

<!-- Students -->
<div class="card mb-3">
<div class="card-header bg-primary text-white">
<strong>Students (<?= $total_students ?>)</strong>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead>
<tr>
<th>#</th>
<th>Name</th>
<th>Email</th>
<th>Created</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php $i = 1; while($s = $students->fetch_assoc()): ?>
<tr class="<?= $i> 100 ? 'table-warning' : '' ?>">
<td><?= $i ?></td>
<td><?= e($s['full_name']) ?></td>
<td><code><?= e($s['email']) ?></code></td>
<td><?= e($s['created_at']) ?></td>
<td>
<button class="btn btn-sm btn-outline-secondary copy-email-btn" data-email="<?= e($s['email']) ?>">Copy</button>
</td>
</tr>
<?php $i++; endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- Faculty -->
<div class="card mb-3">
<div class="card-header bg-success text-white">
<strong>Faculty (<?= $total_faculty ?>)</strong>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead>
<tr>
<th>#</th>
<th>Name</th>
<th>Email</th>
<th>Created</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php while($f = $faculty->fetch_assoc()): ?>
<tr>
<td><?= $i ?></td>
<td><?= e($f['full_name']) ?></td>
<td><code><?= e($f['email']) ?></code></td>
<td><?= e($f['created_at']) ?></td>
<td>
<button class="btn btn-sm btn-outline-secondary copy-email-btn" data-email="<?= e($f['email']) ?>">Copy</button>
</td>
</tr>
<?php $i++; endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- Admins -->
<div class="card mb-3">
<div class="card-header bg-danger text-white">
<strong>Admins & Super Admins (<?= $total_admins ?>)</strong>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead>
<tr>
<th>#</th>
<th>Name</th>
<th>Email</th>
<th>Created</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php while($a = $admins->fetch_assoc()): ?>
<tr>
<td><?= $i ?></td>
<td><?= e($a['full_name']) ?></td>
<td><code><?= e($a['email']) ?></code></td>
<td><?= e($a['created_at']) ?></td>
<td>
<button class="btn btn-sm btn-outline-secondary copy-email-btn" data-email="<?= e($a['email']) ?>">Copy</button>
</td>
</tr>
<?php $i++; endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>

<textarea id="emailList" class="form-control d-none"></textarea>
</div>
</div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>"  src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
function copyEmail(email) {
  navigator.clipboard.writeText(email).then(() => {
    alert('Email copied: ' + email);
  });
}

function copyEmails() {
  const emails = [];
  document.querySelectorAll('tbody code').forEach(el => {
    emails.push(el.textContent);
  });
  const text = emails.join('\n');
  navigator.clipboard.writeText(text).then(() => {
    alert('Copied ' + emails.length + ' emails to clipboard!\n\nPaste them in Google Cloud Console.');
  });
}

function downloadCSV() {
  const rows = [['#', 'Name', 'Email', 'Created']];
  let i = 1;
  document.querySelectorAll('tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    rows.push([
      i++,
      cells[1].textContent,
      cells[2].textContent,
      cells[3].textContent
    ]);
  });
  
  const csv = rows.map(row => row.map(cell => '"' + cell + '"').join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'oauth_test_users.csv';
  a.click();
}

document.addEventListener('DOMContentLoaded', function() {
    var copyAllBtn = document.getElementById('copyAllEmailsBtn');
    if (copyAllBtn) copyAllBtn.addEventListener('click', copyEmails);
    var downloadBtn = document.getElementById('downloadCSVBtn');
    if (downloadBtn) downloadBtn.addEventListener('click', downloadCSV);
    document.querySelectorAll('.copy-email-btn[data-email]').forEach(function(btn) {
        btn.addEventListener('click', function() { copyEmail(this.getAttribute('data-email')); });
    });
    var markAllBtn = document.getElementById('markAllReadBtn');
    if (markAllBtn) markAllBtn.addEventListener('click', function(e) { markAllRead(e); });
    document.querySelectorAll('.notif-item[data-notif-id]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            viewNotification(e, this.dataset.notifId, this.dataset.notifMsg, this.dataset.notifDate, this.dataset.notifRead);
        });
    });
});
</script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
function markAllRead(e) {
    e.preventDefault(); e.stopPropagation();
    fetch('../../notifications/notifications_handler.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=mark_all_read' })
    .then(res => res.json()).then(data => { if(data.success) location.reload(); });
}
function viewNotification(e, id, msg, date, isRead) {
    e.preventDefault();
    document.getElementById('notifModalBody').innerText = msg;
    document.getElementById('notifModalDate').innerText = date;
    new bootstrap.Modal(document.getElementById('notificationModal')).show();
    if(!isRead) {
        fetch('../../notifications/notifications_handler.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=mark_read&notification_id='+id })
        .then(res => res.json()).then(data => { e.target.closest('.dropdown-item').style.background = '#ffffff'; });
    }
}
</script>
<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header" style="background: linear-gradient(135deg, #810403, #dca92c); color: white;"><h5 class="modal-title">Notification Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="notifModalBody" style="white-space: pre-wrap;"></p><small class="text-muted" id="notifModalDate"></small></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<footer class="footer">
    <div class="footer-content">
        <div>
            <div class="footer-logo">
                <div class="footer-logo-icon">P</div>
                PAPEL
            </div>
            <p class="footer-description">
                A comprehensive student research library for submitting, tracking, and managing academic papers with ease.
            </p>
        </div>
        <div>
            <div class="footer-title">Platform</div>
            <a href="../../pages/about_us.php" class="footer-link">About Us</a>
            <a href="../../pages/terms_and_conditions.php" class="footer-link">Terms & Conditions</a>
            <a href="../../pages/privacy.php" class="footer-link">Privacy Policy</a>
        </div>
        <div>
            <div class="footer-title">Support</div>
            <a href="../../pages/help_center.php" class="footer-link">Help Center</a>
            <a href="../../pages/contact_support.php" class="footer-link">Contact Support</a>
            <a href="../../pages/faq.php" class="footer-link">FAQ</a>
        </div>
    </div>
    <div class="footer-copyright">© <?= date('Y') ?> Papel. All rights reserved.</div>
</footer>
<?php include '../../includes/back_button.php'; ?>
<?php include '../../includes/accessibility.php'; ?>
</body>
</html>
