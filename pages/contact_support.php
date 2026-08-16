<?php
require_once __DIR__.'/../config/core.php';
$u = current_user();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name && $email && $message) {
        $body = "Support Request from: $name ($email)\n\nMessage:\n$message";
        send_email(SUPPORT_EMAIL, "Support: $subject", $body);
        flash('success', 'Your message has been sent. We will contact you shortly.');
    } else {
        flash('error', 'Please fill in all required fields.');
    }
    header('Location: contact_support.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contact Support · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* ===== Hero ===== */
/* ===== Layout ===== */
.contact-layout { display: grid; grid-template-columns: 1fr 1.75fr; gap: 1.5rem; align-items: start; }

/* ===== Office info ===== */
.info-group { display: flex; flex-direction: column; gap: 1.25rem; }
.info-item { display: flex; gap: 1rem; align-items: flex-start; }
.info-item-icon { width: 36px; height: 36px; border-radius: 8px; background: rgba(129,4,3,.08); color: var(--maroon); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; margin-top: .125rem; }
.info-item-label { font-size: .75rem; font-weight: 400; text-transform: uppercase; letter-spacing: .5px; color: var(--grey); margin-bottom: .25rem; }
.info-item-value { font-size: .9375rem; color: var(--ink); line-height: 1.5; }
.info-item-value a { color: var(--maroon); text-decoration: none; font-weight: 500; }
.info-item-value a:hover { text-decoration: underline; }

/* ===== Form ===== */
.form-label { font-size: .8125rem; font-weight: 400; color: var(--ink); margin-bottom: .375rem; display: block; }
.form-control, .form-select {
    border: 1px solid var(--border); border-radius: 8px;
    padding: .625rem .875rem; font-size: .9rem; font-family: inherit;
    color: var(--ink); background: var(--white);
    transition: border-color .2s, box-shadow .2s; width: 100%;
}
.form-control:focus, .form-select:focus { outline: none; border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(129,4,3,.1); }
textarea.form-control { resize: vertical; min-height: 130px; }
.btn-submit {
    width: 100%; padding: .75rem 1rem;
    background: var(--maroon); color: #fff;
    border: none; border-radius: 8px;
    font-size: .9375rem; font-weight: 400; cursor: pointer;
    font-family: inherit; transition: background .2s;
    display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.btn-submit:hover { background: var(--dark-maroon); }
.mb-form { margin-bottom: 1rem; }

/* ===== Alert ===== */
.alert-box { padding: .875rem 1rem; border-radius: 8px; font-size: .875rem; margin-bottom: 1.25rem; }
.alert-box.success { background: #d1fae5; color: #065f46; }
.alert-box.error   { background: #fee2e2; color: #991b1b; }

@media(max-width:900px) {
    .contact-layout { grid-template-columns: 1fr; }
}
@media(max-width:600px) {
}
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- ===== Hero ===== -->
<!-- Breadcrumb -->
<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Contact Support</span>
    </div>
</div>

<!-- ===== Body ===== -->
<div class="page-body">

    <div class="page-intro">
        <h1>Contact Support</h1>
        <p>Send us a message and the team will get back to you.</p>
    </div>

    <div class="page-shell">

    <?php if ($m = flash('success')): ?>
        <div class="alert-box success"><i class="bi bi-check-circle me-2"></i><?= e($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('error')): ?>
        <div class="alert-box error"><i class="bi bi-exclamation-circle me-2"></i><?= e($m) ?></div>
    <?php endif; ?>

    <div class="contact-layout">

        <!-- Office info -->
        <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-building"></i>
            <h2>Office Information</h2>
        </div>
        <div class="page-card-body">
            
            <div class="info-group">
                <div class="info-item">
                    <div class="info-item-icon"><i class="bi bi-geo-alt"></i></div>
                    <div>
                        <div class="info-item-label">Address</div>
                        <div class="info-item-value">Polytechnic University of the Philippines<br>Biñan Campus, Laguna</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-item-icon"><i class="bi bi-envelope"></i></div>
                    <div>
                        <div class="info-item-label">Email</div>
                        <div class="info-item-value"><a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-item-icon"><i class="bi bi-telephone"></i></div>
                    <div>
                        <div class="info-item-label">Phone</div>
                        <div class="info-item-value">09773407439</div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-item-icon"><i class="bi bi-clock"></i></div>
                    <div>
                        <div class="info-item-label">Office Hours</div>
                        <div class="info-item-value">Monday – Friday<br>8:00 AM – 5:00 PM</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

        <!-- Contact form -->
        <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-send"></i>
            <h2>Send a Message</h2>
        </div>
        <div class="page-card-body">
            
            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-form">
                    <label class="form-label">Full Name <span style="color:var(--maroon)">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="Enter your full name" required
                           value="<?= $u ? e($u['full_name']) : '' ?>">
                </div>
                <div class="mb-form">
                    <label class="form-label">Email Address <span style="color:var(--maroon)">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="Enter your email address" required
                           value="<?= $u ? e($u['email'] ?? '') : '' ?>">
                </div>
                <div class="mb-form">
                    <label class="form-label">Subject</label>
                    <?php $presetSubject = trim($_GET['subject'] ?? ''); ?>
                    <select name="subject" class="form-select">
                        <option value="">Select a topic…</option>
                        <option value="Forgotten Password" <?= $presetSubject === 'Forgotten Password' ? 'selected' : '' ?>>Forgotten Password</option>
                        <option value="Account Issue" <?= $presetSubject === 'Account Issue' ? 'selected' : '' ?>>Account Issue</option>
                        <option value="Upload Problem" <?= $presetSubject === 'Upload Problem' ? 'selected' : '' ?>>Upload Problem</option>
                        <option value="Approval Inquiry" <?= $presetSubject === 'Approval Inquiry' ? 'selected' : '' ?>>Approval Inquiry</option>
                        <option value="Other" <?= $presetSubject === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="mb-form">
                    <label class="form-label">Message <span style="color:var(--maroon)">*</span></label>
                    <textarea name="message" class="form-control" placeholder="How can we help you?" required></textarea>
                </div>
                <button type="submit" class="btn-submit">
                    <i class="bi bi-send"></i> Send Message
                </button>
            </form>
        </div>
    </div>

    </div>
    </div><!-- /.page-shell -->

</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
