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
<title>Privacy Policy · <?= e(APP_NAME) ?></title>
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
.terms-section h2 .num { color: var(--maroon); font-weight: 400; font-size: .95rem; }
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
.terms-section a { color: var(--maroon); font-weight: 400; }

/* ===== Data table ===== */
.data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; border-radius: 8px; overflow: hidden; }
.data-table th {
    background: var(--cream); padding: .8rem 1rem; text-align: left;
    font-size: .8125rem; font-weight: 400; text-transform: uppercase;
    letter-spacing: .5px; color: var(--ink); border-bottom: 2px solid var(--border);
}
.data-table td { padding: .8rem 1rem; border-bottom: 1px solid var(--border); color: var(--ink); vertical-align: top; }
.data-table tr:last-child td { border-bottom: none; }
.data-table td:first-child { font-weight: 400; color: var(--ink); white-space: nowrap; }

@media(max-width:600px) {
.data-table th, .data-table td { padding: .65rem .6rem; font-size: .85rem; }
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
        <span class="crumb-current">Privacy</span>
    </div>
</div>

<!-- ===== Body ===== -->
<div class="page-body">

    <div class="page-intro">
        <h1>Privacy Policy</h1>
        <p>How the repository collects, uses, and protects your information.</p>
    </div>

    <div class="page-shell">


    <div class="page-card">
        <div class="page-card-body">
        <p class="updated-note">Last updated: <?= date('F d, Y') ?></p>
        <p class="lead-text">At <strong><?= e(APP_NAME) ?></strong>, the official digital research repository of the <em>Polytechnic University of the Philippines – Biñan Campus</em>, we respect your privacy and handle your information responsibly. This Privacy Policy explains what data the system collects, why we collect it, who it is shared with, and the choices available to you.</p>
        </div>
    </div>

    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-shield-lock"></i>
            <h2>Your Privacy</h2>
        </div>
        <div class="page-card-body">
        

        <div class="terms-section">
            <h2><span class="num">1.</span> Information We Collect</h2>
            <p>The system collects only the information needed to operate the repository and manage the review process:</p>
            <table class="data-table">
                <thead>
                    <tr><th style="width:32%">Category</th><th>What it includes</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Account details</td>
                        <td>Full name, username, email address, and assigned role. Students also have a Student ID; students and staff provide a date of birth used to verify identity at login.</td>
                    </tr>
                    <tr>
                        <td>Academic details</td>
                        <td>Your academic program, used to organize and categorize submissions.</td>
                    </tr>
                    <tr>
                        <td>Research submissions</td>
                        <td>Uploaded papers and supporting documents, along with their title, author names, abstract, keywords, paper type, and year.</td>
                    </tr>
                    <tr>
                        <td>Review &amp; feedback</td>
                        <td>The status of each submission and the feedback reviewers provide as a paper moves through the workflow.</td>
                    </tr>
                    <tr>
                        <td>Usage record</td>
                        <td>The date and time of your most recent login, kept for account management.</td>
                    </tr>
                    <tr>
                        <td>Guest access</td>
                        <td>For guest visitors, an email address and temporary login credentials that automatically expire.</td>
                    </tr>
                </tbody>
            </table>
            <p>We do not collect more than this — the system does not run third-party advertising trackers or build marketing profiles.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">2.</span> How We Use Your Information</h2>
            <ul>
                <li><strong>Authentication:</strong> To verify your identity at login using your credentials and the required verification details for your role.</li>
                <li><strong>Submission &amp; review:</strong> To route your papers to the correct reviewers and track each submission through the approval workflow.</li>
                <li><strong>Notifications:</strong> To inform you about the status of your submissions and important account or system updates.</li>
                <li><strong>Publishing:</strong> To make approved papers available in the public repository for academic reference.</li>
                <li><strong>AI assistance:</strong> To generate summaries and extract research details (such as methodology and keywords) that help organize and describe your paper.</li>
                <li><strong>Preservation:</strong> To securely store and archive the institution's research outputs.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h2><span class="num">3.</span> Third-Party Services</h2>
            <p>To provide its features, the system relies on a small number of trusted service providers that process data on our behalf:</p>
            <ul>
                <li><strong>Cloud file storage (Google Drive):</strong> Uploaded papers and supporting documents are stored on the institution's connected Google Drive.</li>
                <li><strong>AI processing service:</strong> The text of an uploaded paper or its abstract may be sent to an AI provider to generate summaries and metadata. This content is processed only to produce those results.</li>
                <li><strong>Email delivery:</strong> Email is used to send notifications and to deliver guest access credentials.</li>
            </ul>
            <p>These providers receive only the data necessary to perform their function and are not permitted to use it for their own purposes.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">4.</span> How Your Information Is Shared</h2>
            <p>We do not sell your personal data. Information is shared only as follows:</p>
            <ul>
                <li><strong>Within the review hierarchy:</strong> Your faculty adviser, the Research Coordinator, the Head of Academic Programs, and the Director can view the submissions and the author details needed to evaluate your work.</li>
                <li><strong>Public repository:</strong> Once a paper receives final approval, its content and descriptive details (such as title, authors, abstract, program, and year) become publicly viewable.</li>
                <li><strong>Legal requirements:</strong> We may disclose information where required by law or to protect the rights, safety, and integrity of the university community and the platform.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h2><span class="num">5.</span> Data Retention</h2>
            <ul>
                <li>Account information is retained while your account remains active within the institution.</li>
                <li>Approved papers are preserved as part of the university's research archive and may be archived rather than deleted.</li>
                <li>When a submission is declined, its uploaded files may be permanently removed from cloud storage to conserve space — so you should always keep your own copy of any work you submit.</li>
                <li>Guest credentials expire automatically and expired guest sessions may be cleared by an administrator.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h2><span class="num">6.</span> How We Protect Your Data</h2>
            <p>The system applies reasonable technical and organizational safeguards, including:</p>
            <ul>
                <li>Passwords stored only as secure one-way hashes — never in plain readable form for standard accounts.</li>
                <li>Role-based access control, so each user only reaches the dashboards and data appropriate to their role.</li>
                <li>Protection against cross-site request forgery (CSRF) and a Content Security Policy to reduce common web attacks.</li>
                <li>Server-side session management for authenticated access.</li>
            </ul>
            <p>No online system can be guaranteed completely secure, but we work to protect your information against unauthorized access, alteration, or disclosure.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">7.</span> Cookies &amp; Sessions</h2>
            <p>The platform uses a session cookie that is essential for keeping you logged in while you use the system. It is not used for advertising or cross-site tracking. Your browser may also store your accessibility preferences (such as text size or contrast) locally on your device for your convenience.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">8.</span> Your Rights &amp; Choices</h2>
            <p>As a user of the system, you may:</p>
            <ul>
                <li>Access your profile information and the research you have uploaded.</li>
                <li>Request corrections to inaccurate personal information through your faculty adviser or an administrator.</li>
                <li>Request that your research be removed from public view, subject to university policy on academic records.</li>
            </ul>
        </div>

        <div class="terms-section">
            <h2><span class="num">9.</span> Changes to This Policy</h2>
            <p>We may update this Privacy Policy as the system evolves or as policies change. The "Last updated" date above shows the latest revision. Your continued use of the platform after changes are posted constitutes acceptance of the updated policy.</p>
        </div>

        <div class="terms-section">
            <h2><span class="num">10.</span> Contact Us</h2>
            <p>If you have questions or requests regarding this Privacy Policy or your personal data, please reach the Research Office through our <a href="contact_support.php">Contact Support</a> page.</p>
        </div>
        </div>
    </div>
    </div><!-- /.page-shell -->

</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
