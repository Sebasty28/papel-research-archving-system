<?php
require_once '../../config/core.php';
require_role(['faculty']);
$conn = db();
$u = current_user();

$notifs_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$notifs_stmt->bind_param('i', $u['user_id']);
$notifs_stmt->execute();
$notifs = $notifs_stmt->get_result();

$uc_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id=? AND is_read=0");
$uc_stmt->bind_param('i', $u['user_id']);
$uc_stmt->execute();
$unread_count = $uc_stmt->get_result()->fetch_assoc()['count'] ?? 0;

$pending_stmt = $conn->prepare("SELECT COUNT(*) as c FROM research_papers rp JOIN users u ON rp.uploaded_by=u.user_id WHERE u.faculty_id=? AND rp.current_status='pending_faculty'");
$pending_stmt->bind_param('i', $u['user_id']);
$pending_stmt->execute();
$pending_count = $pending_stmt->get_result()->fetch_assoc()['c'] ?? 0;

$student_stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as c FROM users WHERE faculty_id=? AND user_role='student'");
$student_stmt->bind_param('i', $u['user_id']);
$student_stmt->execute();
$student_count = $student_stmt->get_result()->fetch_assoc()['c'] ?? 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Faculty Dashboard · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style nonce="<?= csp_nonce() ?>">
:root { --gold: #dca92c; --ivory: #fcf8f7; }
* { box-sizing: border-box; }
body { font-family: 'IBM Plex Sans', 'Segoe UI', sans-serif; background: var(--ivory); min-height: 100vh; display: flex; flex-direction: column; }

.page-hero {
  background: linear-gradient(135deg, var(--maroon) 0%, #5a0302 100%);
  padding: 2.5rem 0 3.5rem;
  position: relative;
  overflow: hidden;
}
.page-hero::after {
  content: '';
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 40px;
  background: var(--ivory);
  clip-path: ellipse(55% 100% at 50% 100%);
}
.hero-title { font-family: 'Crimson Pro', Georgia, serif; color: #fff; font-size: 2.25rem; font-weight: 700; }
.hero-sub { color: rgba(255,255,255,0.75); font-size: 1rem; }
.gold-accent { display: inline-block; width: 40px; height: 4px; background: var(--gold); border-radius: 2px; margin-bottom: 1rem; }

.stat-card {
  background: #fff;
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: 0 4px 12px rgba(0,0,0,0.07);
  border: 1px solid #e8e6e3;
  border-top: 4px solid transparent;
  border-image: linear-gradient(90deg, #810403, #dca92c) 1;
  transition: transform .2s, box-shadow .2s;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
.stat-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 0.75rem; }
.stat-icon.maroon { background: rgba(129,4,3,0.1); color: var(--maroon); }
.stat-icon.gold { background: rgba(220,169,44,0.15); color: #b8860b; }
.stat-icon.blue { background: rgba(59,130,246,0.1); color: #3b82f6; }
.stat-number { font-size: 2rem; font-weight: 700; color: #1a1a1a; line-height: 1; }
.stat-label { color: #6b7280; font-size: 0.85rem; margin-top: 0.25rem; }

.action-card {
  background: #fff;
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: 0 4px 12px rgba(0,0,0,0.07);
  border: 1px solid #e8e6e3;
  border-top: 4px solid transparent;
  border-image: linear-gradient(90deg, #810403, #dca92c) 1;
  text-decoration: none;
  color: inherit;
  display: block;
  transition: transform .2s, box-shadow .2s;
}
.action-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); color: inherit; }
.action-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin-bottom: 1rem; background: linear-gradient(135deg, var(--maroon), #5a0302); color: #fff; }
.action-title { font-weight: 600; font-size: 1rem; margin-bottom: 0.25rem; }
.action-desc { color: #6b7280; font-size: 0.82rem; }
.badge-pill { background: var(--maroon); color: #fff; border-radius: 99px; padding: 0.2rem 0.55rem; font-size: 0.72rem; font-weight: 600; }

.notif-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.07); border: 1px solid #e8e6e3; border-top: 4px solid transparent; border-image: linear-gradient(90deg, #810403, #dca92c) 1; overflow: hidden; }
.notif-header { padding: 1rem 1.25rem; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; justify-content: space-between; }
.notif-header h6 { font-weight: 700; margin: 0; }
.notif-item { padding: 0.75rem 1.25rem; border-bottom: 1px solid #f5f5f5; display: flex; gap: 0.75rem; align-items: flex-start; }
.notif-item:last-child { border-bottom: none; }
.notif-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--maroon); margin-top: 5px; flex-shrink: 0; }
.notif-text { font-size: 0.83rem; color: #374151; }
.notif-time { font-size: 0.75rem; color: #9ca3af; }
.notif-empty { padding: 1.5rem; text-align: center; color: #9ca3af; font-size: 0.88rem; }

</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="page-hero">
  <div class="container">
    <div class="gold-accent"></div>
    <div class="hero-title">Welcome back, <?= e(explode(' ', $u['full_name'])[0]) ?>!</div>
    <div class="hero-sub">Faculty Dashboard · <?= e(APP_NAME) ?></div>
  </div>
</div>

<div class="container" style="margin-top: -1.5rem; position: relative; z-index: 1;">

  <!-- Stats Row -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
      <div class="stat-card">
        <div class="stat-icon maroon"><i class="bi bi-file-earmark-check"></i></div>
        <div class="stat-number"><?= $pending_count ?></div>
        <div class="stat-label">Papers Awaiting Review</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="stat-card">
        <div class="stat-icon gold"><i class="bi bi-people"></i></div>
        <div class="stat-number"><?= $student_count ?></div>
        <div class="stat-label">Students Under You</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-bell"></i></div>
        <div class="stat-number"><?= $unread_count ?></div>
        <div class="stat-label">Unread Notifications</div>
      </div>
    </div>
  </div>

  <div class="row g-4">

    <!-- Quick Actions -->
    <div class="col-lg-8">
      <h6 class="fw-bold mb-3" style="color: var(--maroon);">Quick Actions</h6>
      <div class="row g-3">
        <div class="col-sm-6">
          <a href="faculty_review_dashboard.php" class="action-card">
            <div class="action-icon"><i class="bi bi-clipboard2-check"></i></div>
            <div class="action-title">Review Papers <?php if($pending_count > 0): ?><span class="badge-pill"><?= $pending_count ?></span><?php endif; ?></div>
            <div class="action-desc">Review and approve student research submissions assigned to you.</div>
          </a>
        </div>
        <div class="col-sm-6">
          <a href="faculty_manage_students.php" class="action-card">
            <div class="action-icon"><i class="bi bi-person-lines-fill"></i></div>
            <div class="action-title">Manage Students</div>
            <div class="action-desc">View and manage student accounts under your faculty supervision.</div>
          </a>
        </div>
        <div class="col-sm-6">
          <a href="../../archive/index.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg,#2563eb,#1e40af);"><i class="bi bi-book"></i></div>
            <div class="action-title">Browse Archive</div>
            <div class="action-desc">Explore the public research paper archive of PUP Biñan.</div>
          </a>
        </div>
        <div class="col-sm-6">
          <a href="../../analytics/analytics_dashboard.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg,#059669,#047857);"><i class="bi bi-bar-chart-line"></i></div>
            <div class="action-title">Analytics</div>
            <div class="action-desc">View program performance and paper submission analytics.</div>
          </a>
        </div>
      </div>
    </div>

    <!-- Recent Notifications -->
    <div class="col-lg-4">
      <div class="notif-card">
        <div class="notif-header">
          <h6>Recent Notifications</h6>
          <?php if($unread_count > 0): ?>
          <span class="badge-pill"><?= $unread_count ?> new</span>
          <?php endif; ?>
        </div>
        <?php if($notifs && $notifs->num_rows > 0): while($n = $notifs->fetch_assoc()): ?>
        <div class="notif-item">
          <?php if(!$n['is_read']): ?><div class="notif-dot"></div><?php endif; ?>
          <div>
            <div class="notif-text"><?= e($n['message']) ?></div>
            <div class="notif-time"><?= date('M j, g:i a', strtotime($n['created_at'])) ?></div>
          </div>
        </div>
        <?php endwhile; else: ?>
        <div class="notif-empty"><i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;"></i>No notifications yet</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
