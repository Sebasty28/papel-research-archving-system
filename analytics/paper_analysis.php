<?php
require_once __DIR__.'/../config/core.php';
require_login();
$conn = db();
$u = current_user();

$paper_id = (int)($_GET['id'] ?? 0);
if ($paper_id <= 0) {
    header('Location: ' . role_home($u['user_role']));
    exit;
}

// Get paper details
$stmt = $conn->prepare("SELECT p.*, u.full_name as student_name 
                        FROM research_papers p 
                        JOIN users u ON u.user_id = p.uploaded_by 
                        WHERE p.paper_id = ?");
$stmt->bind_param('i', $paper_id);
$stmt->execute();
$paper = $stmt->get_result()->fetch_assoc();

if (!$paper) {
    header('Location: ' . role_home($u['user_role']));
    exit;
}

// Check permissions
$is_owner = ($paper['uploaded_by'] == $u['user_id']);
$is_admin = in_array($u['user_role'], ['admin', 'super_admin', 'faculty']);
$is_guest = ($u['user_role'] === 'guest' && $paper['current_status'] === 'approved');

if (!$is_owner && !$is_admin && !$is_guest) {
    http_response_code(403);
    exit('Access denied');
}

// Get approval history
$stmt = $conn->prepare("SELECT aw.review_level, aw.status, aw.feedback, aw.reviewed_at, u.full_name as reviewer_name 
                        FROM approval_workflow aw 
                        JOIN users u ON u.user_id = aw.reviewer_id 
                        WHERE aw.paper_id = ? 
                        ORDER BY aw.reviewed_at DESC");
$stmt->bind_param('i', $paper_id);
$stmt->execute();
$approvals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paper Analysis · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.ai-section { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
.stat-card { background: #f8f9fa; padding: 15px; margin-bottom: 15px; }
.metadata-badge { background: #e3f2fd; color: #1976d2; padding: 4px 12px; border-radius: 12px; display: inline-block; margin: 4px; }
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="container">
  <a class="btn btn-sm btn-outline-secondary mt-3 mb-2" href="javascript:history.back()">← Back</a>
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h1 class="h4 mb-3"><?= e($paper['title']) ?></h1>
      
      <div class="row mb-3">
        <div class="col-md-6">
          <strong>Authors:</strong> <?= e($paper['author_names']) ?>
        </div>
        <div class="col-md-3">
          <strong>Year:</strong> <?= e($paper['year']) ?>
        </div>
        <div class="col-md-3">
          <strong>Status:</strong> 
          <span class="badge bg-<?php 
            if($paper['current_status']==='draft') echo 'danger';
            elseif($paper['current_status']==='pending_faculty') echo 'warning';
            elseif($paper['current_status']==='pending_admin') echo 'info';
            elseif($paper['current_status']==='approved') echo 'success';
            else echo 'secondary';
          ?>"><?= ucfirst(str_replace('_', ' ', $paper['current_status'])) ?></span>
        </div>
      </div>

      <?php if ($paper['keywords']): ?>
      <div class="mb-3">
        <strong>Keywords:</strong><br>
        <?php foreach(explode(',', $paper['keywords']) as $keyword): ?>
          <span class="metadata-badge"><?= e(trim($keyword)) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($paper['abstract']): ?>
      <div class="mb-3">
        <strong>Abstract:</strong>
        <p class="mt-2" style="text-align: justify;"><?= nl2br(e($paper['abstract'])) ?></p>
      </div>
      <?php endif; ?>

      <div class="mt-3">
        <?php if (!empty($paper['gdrive_file_id'])): ?>
            <a href="https://drive.google.com/file/d/<?= e($paper['gdrive_file_id']) ?>/view" target="_blank" class="btn btn-primary">
              Open Research PDF (GDrive)
            </a>
        <?php else: ?>
            <a href="<?= e($paper['file_path']) ?>" target="_blank" class="btn btn-primary">
              Open Research PDF
            </a>
        <?php endif; ?>
        <small class="text-muted ms-2">Size: <?= number_format($paper['file_size']/1024/1024, 2) ?> MB</small>
      </div>
    </div>
  </div>

  <?php if ($paper['ai_summary'] || $paper['ai_methodology']): ?>
  <div class="ai-section">
    <h2 class="h5 mb-3">AI-Generated Analysis</h2>
    
    <?php if ($paper['ai_summary']): ?>
    <div class="mb-3">
      <h6>Research Summary</h6>
      <p class="mb-0"><?= e($paper['ai_summary']) ?></p>
    </div>
    <?php endif; ?>

    <div class="row text-white">
      <?php if ($paper['ai_research_field']): ?>
      <div class="col-md-6 mb-2">
        <strong>Research Field:</strong> <?= e($paper['ai_research_field']) ?>
      </div>
      <?php endif; ?>
      
      <?php if ($paper['ai_methodology']): ?>
      <div class="col-md-6 mb-2">
        <strong>Methodology:</strong> <?= e($paper['ai_methodology']) ?>
      </div>
      <?php endif; ?>
      
      <?php if ($paper['ai_sample_size']): ?>
      <div class="col-md-6 mb-2">
        <strong>Sample Size:</strong> <?= e($paper['ai_sample_size']) ?>
      </div>
      <?php endif; ?>
      
      <?php if ($paper['ai_statistical_methods']): ?>
      <div class="col-md-6 mb-2">
        <strong>Statistical Methods:</strong> <?= e($paper['ai_statistical_methods']) ?>
      </div>
      <?php endif; ?>
      
      <?php if ($paper['ai_variables']): ?>
      <div class="col-md-12 mb-2">
        <strong>Main Variables:</strong> <?= e($paper['ai_variables']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($paper['ai_methodology'] || $paper['ai_sample_size'] || $paper['ai_statistical_methods']): ?>
  <div class="card mb-4">
    <div class="card-body">
      <h2 class="h5 mb-3">Statistical Descriptive Summary</h2>
      
      <?php if ($paper['ai_methodology']): ?>
      <div class="stat-card">
        <h6 class="text-primary mb-2">Research Design</h6>
        <p class="mb-0"><?= e($paper['ai_methodology']) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($paper['ai_sample_size']): ?>
      <div class="stat-card">
        <h6 class="text-primary mb-2">Sample Size</h6>
        <p class="mb-0"><?= e($paper['ai_sample_size']) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($paper['ai_statistical_methods']): ?>
      <div class="stat-card">
        <h6 class="text-primary mb-2">Statistical Methods Applied</h6>
        <p class="mb-0"><?= e($paper['ai_statistical_methods']) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($paper['ai_variables']): ?>
      <div class="stat-card">
        <h6 class="text-primary mb-2">Variables Under Study</h6>
        <p class="mb-0"><?= e($paper['ai_variables']) ?></p>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$is_guest && !empty($approvals)): ?>
  <div class="card">
    <div class="card-body">
      <h2 class="h5 mb-3">Approval History</h2>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Level</th>
              <th>Reviewer</th>
              <th>Status</th>
              <th>Feedback</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($approvals as $approval): ?>
            <tr>
              <td><?= ucfirst($approval['review_level']) ?></td>
              <td><?= e($approval['reviewer_name'] ?? '') ?></td>
              <td>
                <span class="badge bg-<?= $approval['status'] === 'approved' ? 'success' : 'danger' ?>">
                  <?= ucfirst($approval['status']) ?>
                </span>
              </td>
              <td><?= $approval['feedback'] ? e($approval['feedback']) : '-' ?></td>
              <td><?= $approval['reviewed_at'] ? e($approval['reviewed_at']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>