<?php
require_once '../../config/core.php';
require_role(['admin', 'super_admin', 'librarian']);
$conn = db();
$u = current_user();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    if (isset($_POST['action']) && $_POST['action'] === 'create_guest') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'A valid email address is required to send credentials.');
            header('Location: admin_manage_guests.php');
            exit;
        }

        $duration = (int)($_POST['duration'] ?? 2);
        if ($duration < 1) $duration = 1;
        if ($duration> 24) $duration = 24;
        
        // Generate random username
        $random_suffix = bin2hex(random_bytes(4));
        $username = 'guest_' . $random_suffix;
        
        // Generate random password
        $password_chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $password_chars[random_int(0, strlen($password_chars) - 1)];
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $expires_at = date('Y-m-d H:i:s', strtotime("+$duration hours"));
        
        $stmt = $conn->prepare("INSERT INTO guest_sessions (username, password, plain_password, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $username, $password_hash, $password, $expires_at);
        
        if ($stmt->execute()) {
            // Send credentials via email
            $subject = "Guest Access Credentials - " . APP_NAME;
            $body = "Hello,<br><br>You have been granted guest access to the " . APP_NAME . " repository.<br><br>";
            $body .= "<strong>Username:</strong> $username<br>";
            $body .= "<strong>Password:</strong> $password<br>";
            $body .= "<strong>Valid Until:</strong> " . date('F j, Y g:i A', strtotime($expires_at)) . "<br><br>";
            $body .= "Login here: <a href='" . BASE_URL . "/archive/login.php'>" . BASE_URL . "/archive/login.php</a>";
            send_email($email, $subject, $body);

            flash('success', "Guest account created and sent to <strong>$email</strong>!<br>Username: <strong>$username</strong><br>Password: <strong>$password</strong><br>Expires: $expires_at");
        } else {
            flash('error', 'Failed to create guest account.');
        }
        header('Location: admin_manage_guests.php');
        exit;
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete_guest') {
        $guest_id = (int)$_POST['guest_id'];
        $stmt = $conn->prepare("DELETE FROM guest_sessions WHERE guest_id = ?");
        $stmt->bind_param('i', $guest_id);
        $stmt->execute();
        flash('success', 'Guest session revoked.');
        header('Location: admin_manage_guests.php');
        exit;
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'cleanup') {
        $conn->query("DELETE FROM guest_sessions WHERE expires_at < NOW()");
        flash('success', 'Expired sessions cleaned up.');
        header('Location: admin_manage_guests.php');
        exit;
    }
}

// Get active and expired guests
$activeGuests = $conn->query("SELECT * FROM guest_sessions WHERE expires_at>= NOW() ORDER BY created_at DESC");
$expiredGuests = $conn->query("SELECT * FROM guest_sessions WHERE expires_at < NOW() ORDER BY created_at DESC");

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Guests · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/Luz0x+O0E7kE2Eir3D" crossorigin="anonymous">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { height: 100%; }
body { 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    background-color: #fcf8f7;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* Header/nav/footer CSS now lives in includes/site_head.php */

/* Main Content Wrapper */
.main-wrapper {
    flex: 1 0 auto;
    width: 100%;
    padding: 2rem 0;
}

/* Page Header */
.page-header {
    margin-bottom: 2rem;
}
.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #810403;
    margin-bottom: 0.5rem;
}
.page-subtitle {
    color: #64748b;
    font-size: 0.95rem;
}

/* Cards */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
    background: white;
}
.card-header {
    background: linear-gradient(135deg, #810403, #dca92c);
    color: white;
    border: none;
    border-radius: 12px 12px 0 0 !important;
    padding: 1.25rem 1.5rem;
    font-weight: 700;
    font-size: 1.1rem;
}
.card-body {
    padding: 1.5rem;
}

/* Form Styling */
.form-label {
    font-weight: 600;
    color: #334155;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}
.form-control, .form-select {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.625rem 0.875rem;
    font-size: 0.9375rem;
    transition: all 0.2s;
}
.form-control:focus, .form-select:focus {
    border-color: #810403;
    box-shadow: 0 0 0 3px rgba(129, 4, 3, 0.1);
}

/* Table Styling */
.table-responsive {
    border-radius: 8px;
    overflow-x: auto;
}
.table {
    margin-bottom: 0;
}
.table thead th {
    background-color: #fcf8f7;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #dca92c;
    padding: 1rem 0.75rem;
    white-space: nowrap;
}
.table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.875rem;
}
.table tbody tr:hover {
    background-color: #f8fafc;
}
.table tbody tr:last-child td {
    border-bottom: none;
}

/* Tabs */
.nav-tabs {
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 1.5rem;
}
.nav-tabs .nav-link {
    border: none;
    color: #64748b;
    font-weight: 600;
    padding: 0.75rem 1.5rem;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}
.nav-tabs .nav-link:hover {
    color: #810403;
    border-color: transparent;
    background-color: #f1f5f9;
}
.nav-tabs .nav-link.active {
    color: #810403;
    background-color: transparent;
    border-bottom-color: #810403;
}

/* Buttons */
.btn-primary {
    background: linear-gradient(135deg, #810403, #a52a2a);
    border: none;
    font-weight: 600;
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    transition: all 0.3s;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(129, 4, 3, 0.3);
}
.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
    border-radius: 6px;
}
.btn-secondary {
    background-color: #64748b;
    border: none;
}
.btn-secondary:hover {
    background-color: #475569;
}
.btn-success {
    background-color: #10b981;
    border: none;
}
.btn-success:hover {
    background-color: #059669;
}
.btn-danger {
    background-color: #ef4444;
    border: none;
}
.btn-danger:hover {
    background-color: #dc2626;
}
.btn-warning {
    background-color: #dca92c;
    border: none;
    color: #1e293b;
}
.btn-warning:hover {
    background-color: #b8860b;
    color: #1e293b;
}
.btn-outline-secondary {
    border: 2px solid #e2e8f0;
    color: #64748b;
    font-weight: 600;
}
.btn-outline-secondary:hover {
    background-color: #f1f5f9;
    border-color: #cbd5e1;
    color: #475569;
}
.btn-dark {
    background-color: #810403;
    border: none;
}
.btn-dark:hover {
    background-color: #0f172a;
}

/* Badges */
.badge {
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.75rem;
}

/* Info Box */
.info-box {
    background: linear-gradient(135deg, #fcf8f7, #fff8e1);
    padding: 1rem 1.25rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}
.info-box i {
    color: #810403;
    font-size: 1.25rem;
}

/* Responsive Design */
@media(max-width: 992px) {
    .page-title {
        font-size: 1.75rem;
    }
    .table thead th,
    .table tbody td {
        font-size: 0.8125rem;
        padding: 0.75rem 0.5rem;
    }
}

@media(max-width: 768px) {
    .main-wrapper {
        padding: 1rem 0;
    }
    .page-title {
        font-size: 1.5rem;
    }
    .card-body {
        padding: 1rem;
    }
    .table {
        font-size: 0.75rem;
    }
    .table thead th,
    .table tbody td {
        padding: 0.5rem 0.25rem;
    }
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
}

/* Additional Utilities */
.text-muted { color: #64748b !important; }
.text-danger { color: #ef4444 !important; }
.text-success { color: #10b981 !important; }
.text-primary { color: #810403 !important; }

        </style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- Main Content -->
<div class="main-wrapper">
    <div class="container-fluid px-4">
        <!-- Page Header -->
        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h1 class="page-title"><i class="bi bi-person-badge" style="color: #810403;"></i> Guest Access Management</h1>
                <p class="page-subtitle">Generate temporary access credentials for external users</p>
            </div>
            <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cleanup">
                <button class="btn btn-outline-secondary">
                    <i class="bi bi-trash3"></i> Cleanup Expired
                </button>
            </form>
        </div>

        <!-- Info Box -->
        <div class="info-box">
            <div class="d-flex align-items-start gap-3">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    <strong>Guest Access Information:</strong>
                    <p class="mb-0 mt-1">Guest accounts provide temporary, read-only access to the research repository. Credentials are automatically generated and emailed to the recipient. Sessions expire after the specified duration.</p>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if($m=flash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($m) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if($m=flash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= $m ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Content Grid -->
        <div class="row g-4">
            <!-- Create Guest Form -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-person-plus-fill me-2"></i>Generate Guest Access
                    </div>
                    <div class="card-body">
                        <form method="post" id="createGuestForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_guest">
                            
                            <div class="mb-3">
                                <label class="form-label">Recipient Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" required placeholder="guest@example.com">
                                <small class="form-text text-muted">Credentials will be sent to this email address</small>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Access Duration <span class="text-danger">*</span></label>
                                <select name="duration" class="form-select">
                                    <option value="1">1 Hour</option>
                                    <option value="2" selected>2 Hours</option>
                                    <option value="4">4 Hours</option>
                                    <option value="8">8 Hours</option>
                                    <option value="12">12 Hours</option>
                                    <option value="24">24 Hours</option>
                                </select>
                                <small class="form-text text-muted">Access will automatically expire after this period</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send-fill me-2"></i>Generate & Send Credentials
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart-fill me-2" style="color: #810403;"></i>Session Statistics</h6>
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="p-3 bg-light rounded">
                                    <h3 class="text-success mb-0"><?= $activeGuests->num_rows ?></h3>
                                    <small class="text-muted">Active</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="p-3 bg-light rounded">
                                    <h3 class="text-secondary mb-0"><?= $expiredGuests->num_rows ?></h3>
                                    <small class="text-muted">Expired</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Guest Tables -->
            <div class="col-lg-8">
                <!-- Tabs -->
                <ul class="nav nav-tabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#activeTab">
                            <i class="bi bi-check-circle me-1"></i>Active Sessions (<?= $activeGuests->num_rows ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#expiredTab">
                            <i class="bi bi-x-circle me-1"></i>Expired Sessions (<?= $expiredGuests->num_rows ?>)
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Active Sessions Tab -->
                    <div class="tab-pane fade show active" id="activeTab">
                        <div class="card">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Username</th>
                                                <th>Password</th>
                                                <th>Created</th>
                                                <th>Expires</th>
                                                <th>Time Left</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if($activeGuests && $activeGuests->num_rows> 0): 
                                                $activeGuests->data_seek(0);
                                                while($row = $activeGuests->fetch_assoc()): 
                                                $expires_timestamp = strtotime($row['expires_at']);
                                                $time_left = $expires_timestamp - time();
                                                $hours_left = floor($time_left / 3600);
                                                $minutes_left = floor(($time_left % 3600) / 60);
                                            ?>
                                            <tr>
                                                <td class="fw-semibold">
                                                    <i class="bi bi-person-circle me-1" style="color: #810403;"></i>
                                                    <?= e($row['username']) ?>
                                                </td>
                                                <td><code style="color: #810403;"><?= e($row['plain_password']) ?></code></td>
                                                <td><?= e(date('M d, Y g:i A', strtotime($row['created_at']))) ?></td>
                                                <td><?= e(date('M d, Y g:i A', strtotime($row['expires_at']))) ?></td>
                                                <td>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-clock-fill me-1"></i>
                                                        <?php if($hours_left> 0): ?>
                                                            <?= $hours_left ?>h <?= $minutes_left ?>m
                                                        <?php else: ?>
                                                            <?= $minutes_left ?>m
                                                        <?php endif; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="post" class="d-inline form-confirm" data-confirm="Revoke this guest session?">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_guest">
                                                        <input type="hidden" name="guest_id" value="<?= $row['guest_id'] ?>">
                                                        <button class="btn btn-danger btn-sm" title="Revoke Access">
                                                            <i class="bi bi-x-circle"></i> Revoke
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endwhile; else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox display-4 d-block mb-3"></i>
                                                    No active guest sessions
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expired Sessions Tab -->
                    <div class="tab-pane fade" id="expiredTab">
                        <div class="card">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Username</th>
                                                <th>Password</th>
                                                <th>Created</th>
                                                <th>Expired</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if($expiredGuests && $expiredGuests->num_rows> 0): 
                                                $expiredGuests->data_seek(0);
                                                while($row = $expiredGuests->fetch_assoc()): 
                                            ?>
                                            <tr class="table-secondary">
                                                <td class="text-muted">
                                                    <i class="bi bi-person-circle me-1"></i>
                                                    <?= e($row['username']) ?>
                                                </td>
                                                <td><code class="text-muted"><?= e($row['plain_password']) ?></code></td>
                                                <td class="text-muted"><?= e(date('M d, Y g:i A', strtotime($row['created_at']))) ?></td>
                                                <td class="text-muted"><?= e(date('M d, Y g:i A', strtotime($row['expires_at']))) ?></td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <i class="bi bi-clock-history"></i> Expired
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="post" class="d-inline form-confirm" data-confirm="Permanently delete this guest session?">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_guest">
                                                        <input type="hidden" name="guest_id" value="<?= $row['guest_id'] ?>">
                                                        <button class="btn btn-danger btn-sm" title="Delete">
                                                            <i class="bi bi-trash me-1"></i>Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endwhile; else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox display-4 d-block mb-3"></i>
                                                    No expired guest sessions
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>"  src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.form-confirm').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });
});
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>







