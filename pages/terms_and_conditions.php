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
<title>Terms and Conditions · <?= e(APP_NAME) ?></title>
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
.page-card-body p, .terms-section li { text-align: justify; }
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
        <span class="crumb-current">Terms and Conditions</span>
    </div>
</div>

<!-- ===== Body ===== -->
<main class="page-body">
    <div class="page-intro">
        <h1>PAPEL Terms and Conditions</h1>
        <p>Rules for using the repository, submitting research, and accessing published work.</p>
    </div>

    <div class="page-shell resource-shell legal-shell">
        <div class="page-card">
            <div class="page-card-body">
                <p class="updated-note">Last Updated: November 7, 2026</p>
                <p class="lead-text">PAPEL is a digital research repository for the Polytechnic University of the Philippines – Biñan Campus. These Terms and Conditions explain the rules for using the repository, submitting research, and accessing published work. Please read them before using PAPEL.</p>
            </div>
        </div>

        <div class="page-card">
            <div class="page-card-header">
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                <h2>The Agreement</h2>
            </div>
            <div class="page-card-body">
                <section class="terms-section">
                    <h2><span class="num">1.</span> Who may use PAPEL</h2>
                    <p>Visitors may browse material made available in the public repository. Students, faculty, staff, and approved guests may use the features available to their assigned roles.</p>
                    <p>PAPEL does not offer public account registration. Authorized university personnel create accounts and issue temporary Library Pass credentials. You must use only an account issued to you, keep your credentials confidential, and promptly report suspected unauthorized access through Contact Support.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">2.</span> Research submissions</h2>
                    <p>When you submit a paper or supporting document, you confirm that, to the best of your knowledge:</p>
                    <ul>
                        <li>The information you provide is accurate and complete.</li>
                        <li>The work identifies its authors and properly acknowledges sources.</li>
                        <li>You have obtained any required ethics clearance, consent, and permission to include third-party material.</li>
                        <li>You have the authority to submit the work and authorize its proposed repository use on behalf of all copyright holders, including coauthors where applicable.</li>
                    </ul>
                    <p>You remain responsible for the content you submit. PAPEL may return a submission for correction or decline material that violates these terms, university policy, or applicable law.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">3.</span> Review and publication</h2>
                    <p>Student submissions pass through a Subject Adviser review followed by a Research Coordinator review. A reviewer may forward the submission or return it with feedback. A student submission is published only after the Research Coordinator gives final approval.</p>
                    <p>Authorized institutional roles may have a different publication path for their own submissions. Where PAPEL permits direct publication, the person using that permission remains responsible for checking the work and its publication settings before publishing it.</p>
                    <p>Publication does not mean that PAPEL or the university guarantees the accuracy, originality, or conclusions of a paper.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">4.</span> What the public can see</h2>
                    <p>Before publication, the submission interface should identify the visibility selected for the work. If <em>Abstract only</em> is selected, the public repository displays the approved descriptive information and abstract, but does not make the full manuscript publicly available through that setting. If a <em>full-text</em> option is selected and approved, the manuscript may be available for public viewing as indicated in the interface.</p>
                    <p>Descriptive information may include the paper’s title, authors, abstract, keywords, academic program, paper type, and year. Supporting documents, such as consent forms and ethics clearances, are used for review and should not be made public unless the submitter is separately told and the disclosure is authorized.</p>
                    <p>If the published record does not match its approved visibility setting, contact PAPEL promptly to request a review.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">5.</span> Copyright and permission to use submitted work</h2>
                    <p>Authors retain their copyright. Submission does not transfer ownership of a paper to PAPEL or the university.</p>
                    <p>By submitting work that is approved for the repository, you grant the university a non-exclusive, royalty-free permission to store it, make preservation copies, organize and index it, display its descriptive information, and provide access to it according to the approved visibility setting. This permission is for academic, educational, research, and archival purposes.</p>
                    <p>You must have permission from coauthors and other rights holders for the uses you authorize. If you believe a published work infringes your rights or was made public without the required authority, contact PAPEL so the record can be reviewed and, where appropriate, restricted or removed from public view.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">6.</span> Storage and availability</h2>
                    <p>PAPEL uses the institution’s connected cloud storage to hold submitted files. The university takes reasonable steps to protect and preserve repository records, but maintenance, technical faults, or third-party service interruptions may temporarily affect access. Keep your own copy of every file you submit.</p>
                    <p>A returned or declined submission may lose its uploaded files as part of the repository workflow. Approved records may be retained for archival purposes under applicable university policy. The <a href="<?= e(BASE_URL) ?>/pages/privacy.php">Privacy Policy</a> explains how personal information is handled.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">7.</span> AI-assisted features</h2>
                    <p>PAPEL may use AI-assisted tools to extract details from a paper, suggest metadata, or generate a summary. These results can contain errors. Submitters and reviewers should check them before relying on them or approving a record. PAPEL’s use of an external AI processing service is described in the Privacy Policy.</p>
                    <p>AI output does not replace academic review, authorship checks, or the submitter’s responsibility for the work.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">8.</span> Acceptable use</h2>
                    <p>You must not:</p>
                    <ul>
                        <li>Upload malware or files intended to disrupt the service.</li>
                        <li>Submit unlawful, plagiarized, or infringing material.</li>
                        <li>Access or attempt to access another person’s account or information without authorization.</li>
                        <li>Interfere with the security or normal operation of PAPEL.</li>
                        <li>Share assigned credentials or use another person’s account.</li>
                        <li>Download, copy, or redistribute repository material in a way that violates copyright, the stated access restrictions, or applicable law.</li>
                    </ul>
                    <p>PAPEL may restrict access or refer suspected misuse to the appropriate university office for review.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">9.</span> Privacy and requests</h2>
                    <p>PAPEL processes account information, submissions, review records, and usage information as described in its <a href="<?= e(BASE_URL) ?>/pages/privacy.php">Privacy Policy</a>. The Privacy Policy explains the purposes of processing, service providers, retention, and how users can make privacy requests.</p>
                    <p>These Terms and Conditions do not replace any separate notice, permission, or consent that may be required for a particular use of personal information.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">10.</span> Changes to these terms</h2>
                    <p>PAPEL may update these terms when its features or university policies change. The last updated date above identifies the version currently in force. Material changes should be brought to users’ attention before they take effect; where a new acknowledgment or permission is required, PAPEL should request it separately. If you do not agree with revised terms, stop using the affected features and contact PAPEL about your account or submitted work.</p>
                </section>

                <section class="terms-section">
                    <h2><span class="num">11.</span> Contact</h2>
                    <p>For account, submission, copyright, or terms questions, use <a href="<?= e(BASE_URL) ?>/pages/contact_support.php">Contact Support</a>. For privacy requests, use the contact route identified in the Privacy Policy.</p>
                </section>

                <p>These terms operate alongside applicable university policies and Philippine law.</p>
            </div>
        </div>
    </div>
</main>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
