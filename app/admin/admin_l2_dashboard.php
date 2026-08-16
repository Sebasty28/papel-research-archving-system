<?php
/**
 * Admin Level 2 (HAP) Dashboard
 * Shows only papers at pending_head_academic stage
 * Approves papers directly (status becomes 'approved')
 */
require_once '../../config/core.php';
require_role(['admin']);
$conn = db();
$u = current_user();

// Ensure admin_level is up to date from DB to prevent session caching issues
$al_stmt = $conn->prepare("SELECT admin_level FROM users WHERE user_id = ?");
$al_stmt->bind_param("i", $u['user_id']);
$al_stmt->execute();
$al_res = $al_stmt->get_result();
if ($al_row = $al_res->fetch_assoc()) {
    $u['admin_level'] = (int)$al_row['admin_level'];
}

// Check if user is Admin Level 2
$admin_level = $u['admin_level'] ?? 1;
if ($admin_level != 2) {
    header('Location: admin_review_dashboard.php');
    exit;
}

require_once '../../config/groq_config.php';
require_once '../../config/gdrive_config.php';
require_once '../../includes/progress_tracker.php';
require_once '../../app/models/PaperRepository.php';
require_once '../../app/models/PaperService.php';
require_once '../../app/models/AnalyticsService.php';

$paperRepo = new PaperRepository($conn);
$paperService = new PaperService();
$analyticsService = new AnalyticsService();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $paper_id = (int)($_POST['paper_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');
    
    if ($action === 'export_analytics') {
        $analyticsService->exportAnalytics($paperRepo->getProgramAnalytics(), $paperRepo->getPaperTypeStats(), 'hap_analytics');
    }

    if ($paper_id <= 0) {
        header('Location: admin_l2_dashboard.php');
        exit;
    }
    
    try {
        if ($action === 'decline') {
            if ($feedback === '') throw new Exception('Feedback required to decline.');
            $paperService->declinePaper($paper_id, $u['user_id'], 'head_academic', $feedback, 'Paper declined by HAP (Admin Level 2): ' . $feedback);
            flash('success', 'Paper sent back to student with feedback.');
        } elseif ($action === 'approve') {
            $paperService->approvePaper($paper_id, $u['user_id'], 'head_academic', 'approved', 'Paper approved by HAP (Admin Level 2). Final approval granted.');
            flash('success', 'Paper approved and marked as final.');
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    
    header('Location: admin_l2_dashboard.php');
    exit;
}

$rows = $paperRepo->getPapersByStatus(['pending_head_academic']);
$approvedRows = $paperRepo->getPapersByStatus(['pending_super_admin', 'approved'], 50);
$declinedRows = $paperRepo->getDeclinedPapers('admin');

// Notifications
$notifs = $conn->query("SELECT * FROM notifications WHERE user_id = {$u['user_id']} ORDER BY created_at DESC LIMIT 5");

$stats = $paperRepo->getProgramAnalytics();
$aiInsight = $analyticsService->getAiInsight($stats);

$paperTypeStats = $paperRepo->getPaperTypeStats();
$totalPapers = array_sum(array_column($paperTypeStats, 'count'));
$timelineData = $paperRepo->getTimelineData();
$approvalByType = $paperRepo->getApprovalRateByType();
$monthly = $paperRepo->getMonthlyComparison();
$thisMonth = $monthly['thisMonth'];
$lastMonth = $monthly['lastMonth'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HAP Dashboard (Admin Level 2) · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background-color: #fcf8f7; min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: linear-gradient(135deg, #810403, #dca92c); padding: 12px 40px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .logo { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 700; color: #fff; text-decoration: none; }
        .logo-icon { width: 40px; height: 40px; background-color: #810403; border-radius: 8px; background-image: url('../../assests/images/logo.png'); background-size: cover; }
        .nav { display: flex; gap: 32px; }
        .nav a { text-decoration: none; color: rgba(255,255,255,0.9); font-size: 16px; font-weight: 500; padding: 8px 0; transition: all 0.3s; }
        .nav a:hover, .nav a.active { color: #fff; }
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-info { display: flex; align-items: center; gap: 10px; color: #fff; font-weight: 600; font-size: 15px; }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.2); }
        .container { max-width: 1600px; margin: 0 auto; padding: 40px; flex: 1; }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 3px solid #e2e8f0; }
        .page-title { font-size: 36px; font-weight: 700; color: #810403; }
        .tabs { display: flex; gap: 20px; border-bottom: 3px solid #e2e8f0; margin-bottom: 30px; }
        .tab { padding: 14px 24px; font-size: 16px; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 4px solid transparent; margin-bottom: -3px; transition: all 0.3s; border-radius: 8px 8px 0 0; }
        .tab:hover { color: #810403; background-color: #f1f5f9; }
        .tab.active { color: #810403; border-bottom-color: #810403; font-weight: 700; background-color: rgba(129, 4, 3, 0.05); }
        .content-card { background: white; border-radius: 16px; padding: 32px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); margin-bottom: 30px; border: 1px solid #e2e8f0; }
        .custom-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .custom-table th { text-align: left; padding: 16px; border-bottom: 2px solid #e2e8f0; color: #475569; font-weight: 700; font-size: 15px; text-transform: uppercase; background-color: #f8fafc; }
        .custom-table td { padding: 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .custom-table tr:hover { background-color: #f8fafc; }
        .btn-action { padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .btn-primary { background: linear-gradient(135deg, #810403, #a52a2a); color: white; }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .btn-outline { border: 2px solid #e2e8f0; background: white; color: #64748b; }
        .alert { border-radius: 12px; border: none; padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid #10b981; }
        .alert-danger { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid #ef4444; }
        .badge { padding: 8px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .badge-info { background-color: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        
        /* Analytics Styles */
        .chart-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); height: 100%; border: 1px solid #e2e8f0; transition: all 0.3s ease; cursor: pointer; }
        .chart-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.12); transform: translateY(-4px); }
        .chart-title { font-size: 18px; font-weight: 700; color: #810403; margin-bottom: 8px; }
        .ai-insight-card { background: linear-gradient(135deg, #fcf8f7, #fff8e1); border-left: 5px solid #810403; padding: 24px; border-radius: 12px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(129, 4, 3, 0.1); }
        .ai-badge { background: linear-gradient(135deg, #fede0e, #dca92c); color: #810403; padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 8px rgba(220, 169, 44, 0.3); animation: shimmer 2s infinite; background-size: 200% 100%; }
        .ai-icon { font-size: 2.5rem; margin-right: 1rem; animation: float 3s ease-in-out infinite; }
        .ai-content { color: #810403; font-size: 1.1rem; line-height: 1.7; font-weight: 500; }
        @keyframes shimmer { 0% { background-position: -1000px 0; } 100% { background-position: 1000px 0; } }
        @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-10px); } }
    </style>
</head>
<body>
    <header class="header">
        <a href="<?= role_home($u['user_role']) ?>" class="logo">
            <div class="logo-icon"></div>
            <?= e(APP_NAME) ?>
        </a>
        <nav class="nav">
            <a href="https://sis8.pup.edu.ph/" target="_blank">PUPSIS</a>
            <a href="admin_l2_dashboard.php" class="active">Dashboard</a>
        </nav>
        <div class="user-section">
            <div class="user-info">
                <span>Hello, <?= e($u['full_name']) ?>! (HAP - Admin Level 2)</span>
                <div class="user-avatar"><i class="bi bi-person-fill"></i></div>
            </div>
            <a href="../auth/logout.php" style="text-decoration:none; color:rgba(255,255,255,0.9); font-size:14px; font-weight:600;">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="bi bi-speedometer2"></i> HAP Dashboard (Admin Level 2)</h1>
        </div>

        <div class="tabs">
            <div class="tab active" onclick="switchTab('pending', this)">
                <i class="bi bi-hourglass-split me-2"></i>Pending Review
            </div>
            <div class="tab" onclick="switchTab('declined', this)">
                <i class="bi bi-x-circle me-2"></i>Declined
            </div>
            <div class="tab" onclick="switchTab('approved', this)">
                <i class="bi bi-check-circle me-2"></i>Forwarded/Approved
            </div>
            <div class="tab" onclick="switchTab('analytics', this)">
                <i class="bi bi-bar-chart-line me-2"></i>Analytics
            </div>
        </div>

        <?php if($m=flash('error')): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($m) ?></div><?php endif; ?>
        <?php if($m=flash('success')): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= e($m) ?></div><?php endif; ?>

        <div id="pendingSection">
            <div class="content-card border-0 bg-transparent shadow-none p-0">
                <h2 class="h4 mb-4 fw-bold" style="color: #810403;"><i class="bi bi-hourglass-split me-2"></i>Pending HAP Review (Admin Level 2)</h2>
                
                <?php if($rows->num_rows > 0): ?>
                    <div class="row g-4">
                            <?php while($p = $rows->fetch_assoc()): 
                                $supporting_docs = $paperRepo->getSupportingDocs($p['paper_id']);
                                $checklist = $conn->query("SELECT * FROM paper_checklist WHERE paper_id=" . (int)$p['paper_id'])->fetch_assoc() ?? [];
                            ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="card-title fw-bold text-dark mb-1"><?= e($p['title']) ?></h5>
                            <p class="card-text text-muted small mb-0">
                                <i class="bi bi-person-circle me-1"></i> <?= e($p['student_name']) ?> &bull; 
                                <i class="bi bi-calendar3 me-1"></i> <?= e(date('M d, Y', strtotime($p['upload_date']))) ?>
                            </p>
                        </div>
                        <span class="badge bg-warning text-dark rounded-pill px-3">Pending HAP Review</span>
                    </div>
                    <div class="card-body px-4 py-3">
                        <!-- Progress Tracker -->
                        <div class="my-3 p-3 bg-light rounded-3">
                            <?php render_progress_tracker('pending_head_academic'); ?>
                        </div>

                        <!-- Documents Section -->
                        <div class="row mt-4 g-3">
                            <div class="col-md-6">
                                <h6 class="fw-bold small text-uppercase text-muted mb-2">Main Document</h6>
                                <?php if (!empty($p['gdrive_file_id'])): ?>
                                    <a class="btn btn-light border w-100 text-start d-flex align-items-center" target="_blank" href="<?= get_gdrive_link($p['gdrive_file_id']) ?>">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger fs-5 me-2"></i>
                                        <span>Research Paper.pdf</span>
                                        <i class="bi bi-box-arrow-up-right ms-auto text-muted"></i>
                                    </a>
                                <?php else: ?>
                                    <a class="btn btn-light border w-100 text-start d-flex align-items-center" target="_blank" href="../../<?= e($p['file_path']) ?>">
                                        <i class="bi bi-file-earmark-pdf-fill text-danger fs-5 me-2"></i>
                                        <span>Research Paper.pdf</span>
                                        <i class="bi bi-box-arrow-up-right ms-auto text-muted"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6 class="fw-bold small text-uppercase text-muted mb-2">Supporting Documents</h6>
                                <?php 
                                // Filter to show only Ethics Clearance for HAP
                                $visible_docs = array_filter($supporting_docs, function($d) {
                                    return $d['document_type'] === 'ethics_clearance';
                                });
                                if(empty($visible_docs)): ?>
                                    <div class="text-muted small fst-italic py-2">No Ethics Clearance attached.</div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach($visible_docs as $doc): ?>
                                            <?php if(!empty($doc['gdrive_file_id'])): ?>
                                            <a class="btn btn-sm btn-light border text-start d-flex align-items-center" target="_blank" href="<?= get_gdrive_link($doc['gdrive_file_id']) ?>">
                                                <i class="bi bi-paperclip text-primary me-2"></i>
                                                <span><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?></span>
                                            </a>
                                            <?php else: ?>
                                            <a class="btn btn-sm btn-light border text-start d-flex align-items-center text-muted" href="javascript:alert('This document is not available on Google Drive.')">
                                                <i class="bi bi-paperclip text-secondary me-2"></i>
                                                <span><?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?> (Local)</span>
                                            </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top-0 px-4 pb-4 pt-0">
                        <hr class="text-muted opacity-25 my-3">
                        <div class="d-flex gap-2 justify-content-end">
                            <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#checklist<?= $p['paper_id'] ?>">
                                <i class="bi bi-list-check me-1"></i> Checklist
                            </button>
                            <button class="btn btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#fb<?= $p['paper_id'] ?>">
                                <i class="bi bi-x-circle me-1"></i> Decline
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Approve and forward to Director?')">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="paper_id" value="<?= $p['paper_id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="btn btn-success text-white">
                                    <i class="bi bi-check-circle me-1"></i> Approve
                                </button>
                            </form>
                        </div>
                        
                        <!-- Collapsible Checklist -->
                        <div class="collapse mt-3" id="checklist<?= $p['paper_id'] ?>">
                            <div class="card card-body bg-light border-0 rounded-3">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-clipboard-check me-2"></i>Faculty Verified Checklist</h6>
                                    <span class="badge bg-secondary">Read Only</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="p-3 bg-white rounded border h-100">
                                            <h6 class="text-primary small fw-bold text-uppercase mb-3 border-bottom pb-2">IMRAD Format</h6>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['imrad_intro']) ? 'checked' : '' ?>><label class="form-check-label small">Introduction</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['imrad_method']) ? 'checked' : '' ?>><label class="form-check-label small">Methodology</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['imrad_result']) ? 'checked' : '' ?>><label class="form-check-label small">Result</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['imrad_discussion']) ? 'checked' : '' ?>><label class="form-check-label small">Discussion</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['imrad_references']) ? 'checked' : '' ?>><label class="form-check-label small">References</label></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="p-3 bg-white rounded border h-100">
                                            <h6 class="text-success small fw-bold text-uppercase mb-3 border-bottom pb-2">Full Research Format</h6>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['full_ch1']) ? 'checked' : '' ?>><label class="form-check-label small">Chapter 1</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['full_ch2']) ? 'checked' : '' ?>><label class="form-check-label small">Chapter 2</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['full_ch3']) ? 'checked' : '' ?>><label class="form-check-label small">Chapter 3</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['full_ch4']) ? 'checked' : '' ?>><label class="form-check-label small">Chapter 4</label></div>
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['full_ch5']) ? 'checked' : '' ?>><label class="form-check-label small">Chapter 5</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" disabled <?= !empty($checklist['full_references']) ? 'checked' : '' ?>><label class="form-check-label small">References</label></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Collapsible Feedback -->
                        <div class="collapse mt-3" id="fb<?= $p['paper_id'] ?>">
                            <div class="card card-body bg-light border-0 border-start border-4 border-danger rounded-3">
                                <form method="post">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="paper_id" value="<?= $p['paper_id'] ?>">
                                    <input type="hidden" name="action" value="decline">
                                    <h6 class="fw-bold text-danger mb-3"><i class="bi bi-chat-left-text me-2"></i>Provide Feedback for Revision</h6>
                                    <div class="form-floating mb-3">
                                        <textarea class="form-control" name="feedback" placeholder="Leave a comment here" style="height: 100px" required></textarea>
                                        <label>Detailed Feedback</label>
                                    </div>
                                    <div class="text-end">
                                        <button class="btn btn-danger fw-bold"><i class="bi bi-arrow-return-left me-1"></i>Send Back to Student</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                            <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-inbox text-muted" style="font-size: 3rem; opacity: 0.5;"></i>
                        </div>
                        <h5 class="text-muted">No pending papers for review</h5>
                        <p class="text-muted small">Great job! You're all caught up.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="declinedSection" style="display:none">
            <div class="content-card">
                <h2 class="h4 mb-4 fw-bold text-danger"><i class="bi bi-x-circle me-2"></i>Declined Papers</h2>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Student</th>
                                <th>Date</th>
                                <th>Feedback</th>
                                <th>PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($d = $declinedRows->fetch_assoc()): ?>
                            <tr>
                                <td><?= e($d['title']) ?></td>
                                <td><?= e($d['student_name']) ?></td>
                                <td><?= e(date('M d, Y', strtotime($d['upload_date']))) ?></td>
                                <td><small class="text-danger fw-bold"><?= e($d['feedback']) ?></small></td>
                                <td>
                                    <?php if (!empty($d['gdrive_file_id'])): ?>
                                        <a class="btn-action btn-primary" target="_blank" href="<?= get_gdrive_link($d['gdrive_file_id']) ?>">VIEW</a>
                                    <?php else: ?>
                                        <a class="btn-action btn-primary" target="_blank" href="../../<?= e($d['file_path']) ?>">Open PDF</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="approvedSection" style="display:none">
            <div class="content-card">
                <h2 class="h4 mb-4 fw-bold" style="color: #10b981;"><i class="bi bi-check-circle me-2"></i>Forwarded & Approved Papers</h2>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Student</th>
                                <th>Status Tracking</th>
                                <th>Date</th>
                                <th>View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($ap = $approvedRows->fetch_assoc()): ?>
                            <tr>
                                <td><?= e($ap['title']) ?></td>
                                <td><span class="badge badge-info"><?= e(ucfirst($ap['paper_type'] ?? 'research')) ?></span></td>
                                <td><?= e($ap['student_name']) ?></td>
                                <td><?php render_progress_tracker($ap['current_status']); ?></td>
                                <td><?= e(date('M d, Y', strtotime($ap['upload_date']))) ?></td>
                                <td>
                                    <?php if (!empty($ap['gdrive_file_id'])): ?>
                                        <a class="btn-action btn-primary" target="_blank" href="<?= get_gdrive_link($ap['gdrive_file_id']) ?>">VIEW</a>
                                    <?php else: ?>
                                        <a class="btn-action btn-primary" target="_blank" href="../../<?= e($ap['file_path']) ?>">Open PDF</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="analyticsSection" style="display:none">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h4 fw-bold mb-0"><i class="bi bi-graph-up me-2"></i>Research Analytics <span class="ai-badge">AI POWERED</span></h2>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="export_analytics">
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel me-2"></i>Export to Excel</button>
                    </form>
                </div>
                <?php if($aiInsight): ?>
                <div class="ai-insight-card mb-4">
                  <div class="d-flex align-items-center mb-3">
                    <span class="ai-icon">✨</span>
                    <h5 class="mb-0 fw-bold" style="color: #810403;">AI Analysis & Insights</h5>
                  </div>
                  <div class="ai-content"><?= nl2br(e($aiInsight)) ?></div>
                </div>
                <?php endif; ?>
                <div class="row g-4 mb-4">
                    <div class="col-md-8">
                        <div class="chart-card">
                            <h5 class="chart-title"><i class="bi bi-calendar3 me-2" style="color: #810403;"></i>Submission Timeline</h5>
                            <p class="text-muted small mb-3">Last 6 Months Trend</p>
                            <div class="chart-container" onclick="expandChart('timeline')"><canvas id="timelineChart" style="max-height: 300px;"></canvas></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="chart-card">
                            <h5 class="chart-title"><i class="bi bi-calendar-check me-2 text-success"></i>Monthly Comparison</h5>
                            <p class="text-muted small mb-3">Current vs Last Month</p>
                            <div class="chart-container" onclick="expandChart('monthly')"><canvas id="monthlyChart" style="max-height: 300px;"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h5 class="chart-title"><i class="bi bi-percent me-2 text-warning"></i>Approval Rate</h5>
                            <p class="text-muted small mb-3">By Paper Type</p>
                            <div class="chart-container" onclick="expandChart('approval')"><canvas id="approvalRateChart" style="max-height: 300px;"></canvas></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h5 class="chart-title"><i class="bi bi-pie-chart me-2 text-info"></i>Paper Type Distribution</h5>
                            <p class="text-muted small mb-3">All Submissions</p>
                            <div class="chart-container" onclick="expandChart('types')"><canvas id="paperTypeChart" style="max-height: 300px;"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="row g-4 mb-4">
                    <div class="col-12">
                        <div class="chart-card">
                            <h5 class="chart-title"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Paper Type Breakdown</h5>
                            <div class="table-responsive mt-3"><table class="custom-table">
                            <thead><tr><th>Type</th><th class="text-center">Count</th><th class="text-end">Percentage</th></tr></thead>
                            <tbody>
                            <?php foreach ($paperTypeStats as $stat): ?>
                            <tr>
                            <td><span class="badge badge-info"><?= e(ucfirst($stat['paper_type'] ?? 'Unknown')) ?></span></td>
                            <td class="text-center fw-bold"><?= $stat['count'] ?></td>
                            <td class="text-end"><div class="d-flex align-items-center justify-content-end gap-2"><div class="progress" style="width: 100px; height: 8px;"><div class="progress-bar" style="width: <?= $totalPapers > 0 ? round(($stat['count'] / $totalPapers) * 100, 1) : 0 ?>%; background: linear-gradient(90deg, #810403, #a52a2a);"></div></div><span class="fw-bold"><?= $totalPapers > 0 ? round(($stat['count'] / $totalPapers) * 100, 1) : 0 ?>%</span></div></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            </table></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chart Modal -->
        <div class="modal fade" id="chartModal" tabindex="-1">
          <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none;">
              <div class="modal-header" style="background: linear-gradient(135deg, #810403, #dca92c); color: white; border-radius: 16px 16px 0 0;">
                <h5 class="modal-title fw-bold" id="chartModalLabel"><i class="bi bi-bar-chart-line me-2"></i>Chart Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body" style="padding: 2rem;"><div style="height:600px"><canvas id="modalChartCanvas"></canvas></div></div>
            </div>
          </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const colors = ['#810403','#dca92c','#fede0e','#be7d7c','#10b981','#ef4444','#8b5cf6'];

    const chartData = {
      timeline: {
        labels: <?= json_encode(array_column($timelineData, 'month')) ?>,
        datasets: [{
          label: 'Submissions',
          data: <?= json_encode(array_column($timelineData, 'count')) ?>,
          borderColor: '#810403',
          backgroundColor: 'rgba(129, 4, 3, 0.1)',
          tension: 0.4,
          fill: true,
          borderWidth: 3
        }]
      },
      approval: {
        labels: <?= json_encode(array_map(fn($s) => ucfirst($s['paper_type']), $approvalByType)) ?>,
        datasets: [{
          label: 'Approval Rate %',
          data: <?= json_encode(array_column($approvalByType, 'rate')) ?>,
          backgroundColor: colors,
          indexAxis: 'y',
          borderRadius: 8
        }]
      },
      monthly: {
        labels: ['Last Month', 'This Month'],
        datasets: [{
          label: 'Submissions',
          data: [<?= $lastMonth ?>, <?= $thisMonth ?>],
          backgroundColor: ['#be7d7c', '#810403'],
          borderRadius: 8
        }]
      },
      types: {
        labels: <?= json_encode(array_map(fn($s) => ucfirst($s['paper_type'] ?? 'Unknown'), $paperTypeStats)) ?>,
        datasets: [{
          data: <?= json_encode(array_column($paperTypeStats, 'count')) ?>,
          backgroundColor: colors,
          borderWidth: 2,
          borderColor: '#fff'
        }]
      }
    };
    </script>
    <script src="../../assests/js/dashboard-common.js"></script>
</body>
</html>
