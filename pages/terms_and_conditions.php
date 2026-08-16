<?php
require_once __DIR__ . '/../config/core.php';
start_session_once();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms &amp; Conditions · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
.updated-note { font-size: .8125rem; color: var(--grey); font-style: italic; margin-bottom: 1rem; }
.lead-text { color: var(--ink); font-size: 1rem; line-height: 1.75; }
.terms-section { margin-top: 2rem; }
.terms-section:first-of-type { margin-top: 0; }
.terms-section h2 {
    font-size: 1.0625rem; font-weight: 400; color: var(--ink);
    margin-bottom: .625rem; display: flex; align-items: baseline; gap: .5rem;
}
.terms-section h2 .num {
    color: var(--maroon); font-weight: 400; font-size: .95rem;
}
.terms-section p { color: var(--ink); line-height: 1.75; margin-bottom: .75rem; }
.terms-section ul { margin: .5rem 0 .75rem; padding-left: 0; list-style: none; }
.terms-section li {
    color: var(--ink); line-height: 1.7; margin-bottom: .55rem;
    padding-left: 1.5rem; position: relative;
}
.terms-section li::before {
    content: ''; position: absolute; left: .25rem; top: .6rem;
    width: 6px; height: 6px; border-radius: 50%; background: var(--soft-maroon);
}
.terms-section li strong, .terms-section p strong { color: var(--ink); }

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
        <span class="crumb-current">Terms &amp; Conditions</span>
    </div>
</div>

<!-- ===== Body ===== -->
<div class="page-body">

    <div class="page-intro">
        <h1>Terms &amp; Conditions</h1>
        <p>The rules that govern your use of the PUP Bi&ntilde;an Digital Research Repository.</p>
    </div>

    <div class="page-shell">


    <div class="page-card">
        <div class="page-card-body">
        <p class="updated-note">Last updated: <?= date('F d, Y') ?></p>
        <p class="lead-text">Welcome to <strong><?= e(APP_NAME) ?></strong>, the official digital research repository of the <em>Polytechnic University of the Philippines – Biñan Campus</em>. These Terms &amp; Conditions govern your access to and use of the platform. By logging in or using any part of the system, you agree to be bound by the terms below.</p>
        </div>
    </div>

    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-file-earmark-text"></i>
            <h2>The Agreement</h2>
        </div>
        <div class="page-card-body">
        

        <div class="terms-section">
            <h2><span class="num">1.</span> Acceptance of These Terms</h2>
            <p>By accessing <?= e(APP_NAME) ?> — whether to browse the public repository or to log in to an account — you accept these Terms &amp; Conditions in full. If you do not agree with any part of these terms, please discontinue use of the platform.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">2.</span> Accounts &amp; Access</h2>
            <p>PAPEL does not offer public self-registration. Accounts are provisioned within the institution's role hierarchy:</p>
            <ul>
                <li><strong>Students</strong> are onboarded by their faculty adviser.</li>
                <li><strong>Faculty advisers</strong> are created by the Research Coordinator.</li>
                <li><strong>Administrators</strong> are created by the Director.</li>
                <li><strong>Guests</strong> are issued temporary, time-limited credentials by an administrator to view the repository.</li>
            </ul>
            <p>You log in using your assigned credentials together with the required verification details for your role (for example, students and staff verify with their date of birth). You are responsible for keeping your credentials confidential and for all activity that occurs under your account. Each account may only be used by the individual it was issued to, and only through the login section that matches its role.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">3.</span> Research Submissions</h2>
            <p>When you upload a paper and its supporting documents, you certify that:</p>
            <ul>
                <li>The work is your own original research and properly attributes any sources used.</li>
                <li>You have secured any required ethics clearances and consent or permission forms.</li>
                <li>The submission does not infringe the copyright or intellectual property rights of others.</li>
                <li>The information you provide — including title, authors, abstract, program, and year — is accurate and complete.</li>
            </ul>
            <p>You retain ownership of your work and remain responsible for its content and accuracy.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">4.</span> The Review &amp; Approval Process</h2>
            <p>Submitted papers are not published automatically. Each submission passes through a multi-stage review — your faculty adviser, the Research Coordinator, the Head of Academic Programs, and finally the Director. You agree that:</p>
            <ul>
                <li>A reviewer at any stage may approve your paper and forward it onward, or return it to you with feedback for correction and resubmission.</li>
                <li>A paper only becomes visible in the public repository after it receives final approval from the Director.</li>
                <li>Declined submissions are returned to draft, and their uploaded files may be permanently removed from cloud storage to conserve space. You should keep your own copy of any work you submit.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h2><span class="num">5.</span> Intellectual Property &amp; Publishing License</h2>
            <p>Authors retain the copyright to their research. By submitting to <?= e(APP_NAME) ?> and receiving approval, you grant the university a non-exclusive, royalty-free license to store, preserve, and make your work accessible through the repository for academic, educational, and reference purposes. Approved papers may be made publicly viewable, and the university may display associated metadata (such as title, authors, abstract, and program) for discovery and search.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">6.</span> File Storage</h2>
            <p>Uploaded documents are stored on the institution's cloud storage (Google Drive) connected to the system. Approved papers are made available for online viewing. The university takes reasonable measures to safeguard stored files but does not guarantee uninterrupted availability of third-party storage services.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">7.</span> Acceptable Use</h2>
            <p>When using PAPEL, you agree <strong>not</strong> to:</p>
            <ul>
                <li>Upload malware, viruses, or any harmful or corrupted files.</li>
                <li>Submit content that is unlawful, plagiarized, or that violates another person's rights.</li>
                <li>Attempt to access accounts, data, or dashboards you are not authorized to use.</li>
                <li>Interfere with, probe, or attempt to disrupt the security or normal operation of the system.</li>
                <li>Share your account credentials or use another person's account.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h2><span class="num">8.</span> Data Privacy</h2>
            <p>Personal information collected by the system — such as names, email addresses, student IDs, programs, and dates of birth — is used solely to operate the repository, verify identities, route submissions, and send notifications. Our handling of personal data is described further in our <a href="privacy.php">Privacy Policy</a>.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">9.</span> Disclaimer</h2>
            <p>The materials and research papers on <?= e(APP_NAME) ?> are provided "as is" for academic reference. While submissions are reviewed before publication, the university makes no warranty as to the accuracy, completeness, or fitness for any particular purpose of the content, and is not liable for any use made of it.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">10.</span> Changes to These Terms</h2>
            <p>The university may update these Terms &amp; Conditions from time to time to reflect changes in the system or its policies. The "Last updated" date above indicates the latest revision. Continued use of the platform after changes are posted constitutes your acceptance of the revised terms.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">11.</span> Contact</h2>
            <p>If you have questions about these Terms &amp; Conditions, please reach out through our <a href="contact_support.php">Contact Support</a> page.</p>
        </div>
        </div>
    </div>
    </div><!-- /.page-shell -->

</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
