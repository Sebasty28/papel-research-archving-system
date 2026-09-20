<?php
require_once __DIR__ . '/config/core.php';

// 1. Security Check: Ensure only Super Admin can access this
require_role(['super_admin']);

$conn = db();
$u = current_user();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset']) && $_POST['confirm_reset'] === 'CONFIRM') {

    /* Typing CONFIRM proves intent, not origin: any page on the internet can
       post that word. Without this check a single link, followed while the
       Director had a live session, emptied the system. */
    csrf_verify();

    // Disable foreign key checks to prevent errors during deletion
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    /* Everything a paper leaves behind, in the order it was accumulated.
       The previous list had drifted a long way from the schema — five of its
       seven names did not exist, and the tables that actually hold a paper's
       trail were all missing, so a reset deleted the papers and left
       approval_workflow, the checklists and the supporting documents pointing
       at rows that were gone.

       papers_archive is cleared here too. It is deliberately allowed to
       outlive an individual paper, but this is a full reset: leaving it would
       keep publishing work whose authors were just deleted.

       Not cleared: users (handled below, so the Director survives),
       system_settings and gdrive_settings, which are configuration. */
    $tables = [
        'notifications',
        'notification_schedule',
        'research_papers',
        'papers_archive',
        'approval_workflow',
        'paper_checklist',
        'imrad_checklist',
        'supporting_documents',
        'paper_favorites',
        'analytics',
        'ai_processing_log',
        'ai_rate_limits',
        'storage_usage',
        'guest_sessions',
    ];

    $cleared_tables = [];
    foreach ($tables as $table) {
        // Check if table exists to avoid errors
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if ($check && $check->num_rows> 0) {
            $conn->query("TRUNCATE TABLE $table");
            $cleared_tables[] = $table;
        }
    }

    // Delete all users EXCEPT Super Admin
    // We assume 'super_admin' is the role identifier for your account
    $stmt = $conn->prepare("DELETE FROM users WHERE user_role != 'super_admin'");
    if ($stmt->execute()) {
        $deleted_users = $stmt->affected_rows;
        $message = "<div class='alert alert-success'>
                        <h4><i class='bi bi-check-circle'></i> System Reset Successful</h4>
                        <ul>
                            <li>Deleted <strong>$deleted_users</strong> user accounts.</li>
                            <li>Cleared data from: " . implode(', ', $cleared_tables) . "</li>
                            <li><strong>Super Admin accounts were preserved.</strong></li>
                        </ul>
                        <a href='app/admin/super_admin_review_dashboard.php' class='btn btn-primary mt-3'>Return to Dashboard</a>
                    </div>";
    } else {
        $message = "<div class='alert alert-danger'>Error deleting users: " . $conn->error . "</div>";
    }

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Data Reset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/Luz0x+O0E7kE2Eir3D" crossorigin="anonymous">
    <style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
        /* Disable text selection to prevent inspection */
        body {
            -webkit-user-select: none; /* Safari */
            -ms-user-select: none; /* IE 10 and IE 11 */
            user-select: none; /* Standard syntax */
        }
    </style>
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">

    <div class="container" style="max-width: 600px;">
        <?php if ($message): ?>
            <?= $message ?>
        <?php else: ?>
            <div class="card border-danger shadow-lg">
                <div class="card-header bg-danger text-white p-3">
                    <h3 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>DANGER: System Reset</h3>
                </div>
                <div class="card-body p-4 text-center">
                    <div class="mb-4 text-danger">
                        <i class="bi bi-trash3-fill" style="font-size: 4rem;"></i>
                    </div>
                    
                    <h4 class="card-title mb-3">Are you sure you want to wipe all data?</h4>
                    
                    <div class="alert alert-warning text-start">
                        <strong>This action will permanently delete:</strong>
                        <ul class="mb-0 mt-2">
                            <li>All Faculty, Student, and Staff accounts</li>
                            <li>All Research Papers and Submissions</li>
                            <li>All Notifications and Logs</li>
                        </ul>
                        <p class="mb-0 mt-2 small">Uploaded PDFs stay on disk under
                            <code>uploads/</code> and have to be cleared separately.</p>
                    </div>
                    
                    <p class="fw-bold text-success mb-4">
                        <i class="bi bi-shield-check me-1"></i> Super Admin accounts will be KEPT.
                    </p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label text-muted small">Type <strong>CONFIRM</strong> to proceed:</label>
                            <input type="text" name="confirm_reset" class="form-control text-center fw-bold" required pattern="CONFIRM" autocomplete="off">
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger btn-lg">
                                <i class="bi bi-radioactive me-2"></i>WIPE ALL DATA
                            </button>
                            <a href="app/admin/super_admin_review_dashboard.php" class="btn btn-light">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    // Right-click is kept off the page but left on links, as everywhere else
    // (see includes/accessibility.php); this page carries its own copy
    // because it renders without the site's shared includes.
    document.addEventListener('contextmenu', function (event) {
        if (event.target.closest && event.target.closest('a[href]')) { return; }
        event.preventDefault();
    });
</script>
</body>
</html>