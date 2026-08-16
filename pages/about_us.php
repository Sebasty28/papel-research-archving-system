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
<title>About Us · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* ===== Role table ===== */
.role-table { width: 100%; border-collapse: collapse; margin-top: 1rem; border-radius: 8px; overflow: hidden; }
.role-table th {
    background: var(--cream); padding: .875rem 1rem;
    text-align: left; font-size: .8125rem; font-weight: 400;
    text-transform: uppercase; letter-spacing: .5px; color: var(--ink);
    border-bottom: 2px solid var(--border);
}
.role-table td { padding: .875rem 1rem; border-bottom: 1px solid var(--border); color: var(--ink); vertical-align: middle; }
.role-table tr:last-child td { border-bottom: none; }
.role-badge {
    display: inline-block; padding: .2rem .7rem;
    border-radius: 999px; font-size: .75rem; font-weight: 400;
    background: rgba(129,4,3,.08); color: var(--maroon); white-space: nowrap;
}

/* ===== Info tiles ===== */
.info-tiles { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; margin-top: 1.25rem; }
.info-tile {
    background: var(--cream); border: 1px solid var(--border);
    border-radius: 10px; padding: 1.25rem;
    display: flex; flex-direction: column; gap: .5rem;
}
.info-tile .tile-icon { font-size: 1.5rem; color: var(--maroon); }
.info-tile .tile-title { font-weight: 400; font-size: .9rem; color: var(--ink); }
.info-tile .tile-desc { font-size: .875rem; color: var(--ink); line-height: 1.6; }

@media(max-width:900px) {
    .info-tiles { grid-template-columns: 1fr 1fr; }
}
@media(max-width:600px) {
    .info-tiles { grid-template-columns: 1fr; }
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
        <span class="crumb-current">About PAPEL</span>
    </div>
</div>

<!-- ===== Body ===== -->
<div class="page-body">

    <div class="page-intro">
        <h1>About PAPEL</h1>
        <p>The PUP Bi&ntilde;an Digital Research Repository &mdash; preserving and sharing the intellectual outputs of our academic community.</p>
    </div>

    <div class="page-shell">


    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-info-circle"></i>
            <h2>Our Mission</h2>
        </div>
        <div class="page-card-body">
        
        <p><strong>PAPEL</strong> (PUP Biñan Digital Research Repository) is the official centralized archiving platform for the <em>Polytechnic University of the Philippines – Biñan Campus</em>. Born out of a need for structured, accessible, and sustainable academic record-keeping, PAPEL serves as the digital home for the diverse research outputs of our student body.</p>
        <h3>Our Purpose</h3>
        <p>In the fast-evolving landscape of higher education, the preservation of knowledge is paramount. <strong>PAPEL</strong> aims to eliminate the barriers of physical storage and fragmented data by providing a seamless interface where students can upload, and the administration can manage, scholarly works. We are dedicated to fostering a culture of research excellence and ensuring that every study contributes to the growing intellectual capital of the Sintang Paaralan.</p>
        </div>
    </div>

    <!-- ===== PAPEL Ecosystem section hidden for now =====
    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-people"></i>
            <h2>The PAPEL Ecosystem</h2>
        </div>
        <div class="page-card-body">
        
        <p>Our system is designed with a clear, hierarchical structure to maintain the integrity and security of the university's research data. Each role carries a distinct responsibility within the submission and review process:</p>
        <table class="role-table">
            <thead>
                <tr>
                    <th style="width:30%">Role</th>
                    <th>Responsibility</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="role-badge">Student</span></td>
                    <td>Uploads research papers and supporting documents, submits them to their faculty adviser, and tracks each submission's status — resubmitting a corrected copy whenever a paper is returned.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Faculty Adviser</span></td>
                    <td>Creates and manages their own student accounts, and is the first reviewer of every submission — either approving and forwarding the paper to the Research Coordinator, or returning it to the student with feedback.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Research Coordinator</span></td>
                    <td>Creates and manages faculty accounts, and reviews papers endorsed by advisers — approving and forwarding them to the Head of Academic Programs, or returning them with feedback.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Head of Academic Programs</span></td>
                    <td>Reviews papers forwarded by the Research Coordinator and either approves and forwards them to the Director for final sign-off, or returns them to the student with feedback.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Director</span></td>
                    <td>Manages administrator accounts and system settings, and gives the final approval that publishes a paper to the public repository.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Guest</span></td>
                    <td>A visitor issued time-limited credentials by an administrator to access the repository.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-diagram-3"></i>
            <h2>How a Paper Gets Published</h2>
        </div>
        <div class="page-card-body">
        
        <p>Every submission travels through a structured, four-stage review pipeline before it appears in the public repository. At any stage a reviewer may return the paper with feedback so the student can correct and resubmit — ensuring only vetted research is published.</p>
        <table class="role-table">
            <thead>
                <tr>
                    <th style="width:30%">Stage</th>
                    <th>What Happens</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="role-badge">1 · Submission</span></td>
                    <td>A student uploads their paper and submits it to their faculty adviser for review.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">2 · Adviser Review</span></td>
                    <td>The faculty adviser reviews the submission and forwards it to the Research Coordinator.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">3 · Coordinator Review</span></td>
                    <td>The Research Coordinator reviews the endorsed paper and forwards it to the Head of Academic Programs.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">4 · Academic Review</span></td>
                    <td>The Head of Academic Programs reviews the paper and forwards it to the Director.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">5 · Final Approval</span></td>
                    <td>The Director gives final approval, publishing the paper to the public repository. At any stage a reviewer may instead return the paper for correction.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
    ===== End hidden PAPEL Ecosystem section ===== -->

    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-stars"></i>
            <h2>Why PAPEL</h2>
        </div>
        <div class="page-card-body">
        
        <div class="info-tiles">
            <div class="info-tile">
                <div class="tile-icon"><i class="bi bi-globe2"></i></div>
                <div class="tile-title">Accessibility</div>
                <div class="tile-desc">A 24/7 digital library for the PUP Biñan community, available anytime from any device.</div>
            </div>
            <div class="info-tile">
                <div class="tile-icon"><i class="bi bi-shield-check"></i></div>
                <div class="tile-title">Security</div>
                <div class="tile-desc">A tiered access system that ensures research data is handled by the right people at the right level.</div>
            </div>
            <div class="info-tile">
                <div class="tile-icon"><i class="bi bi-leaf"></i></div>
                <div class="tile-title">Sustainability</div>
                <div class="tile-desc">Reducing the environmental footprint of physical archiving while future-proofing our research records.</div>
            </div>
        </div>
        </div>
    </div>
    </div><!-- /.page-shell -->

</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
