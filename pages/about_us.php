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
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* ===== Role table ===== */
.role-table { width: 100%; border-collapse: collapse; margin-top: 1rem; border-radius: var(--r-control, 4px); overflow: hidden; }
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
    border-radius: var(--r-badge, 2px); font-size: .75rem; font-weight: 400;
    background: rgba(129,4,3,.08); color: var(--maroon); white-space: nowrap;
}

/* ===== One page, read straight down =====
   No card around each part, and no panel behind them: headings and text, the
   way a page of writing is set. The parts keep their ids, so a link to one
   (about_us.php#why) still lands on it. */
.about-doc section + section { margin-top: 2.25rem; }
.about-doc h2 {
    font-family: var(--font-head); font-size: 1.125rem; font-weight: 500;
    color: var(--maroon); margin-bottom: .625rem;
}
.about-doc p {
    color: var(--ink); font-size: .875rem; line-height: 1.8; margin-bottom: .875rem;
    /* The full width of the page before a line breaks, and both edges flush.
       Hyphenation comes with justified text: without it the browser has only
       the word spaces to stretch, and a long word left rivers of white down
       the middle of a paragraph. */
    text-align: justify;
    -webkit-hyphens: auto;
    hyphens: auto;
}
.about-doc p:last-child { margin-bottom: 0; }
/* The name in the running text, marked without shouting: the same treatment
   the cards gave it. */
.about-doc strong { font-weight: 400; color: var(--maroon); }

/* ===== The three points under Why PAPEL =====
   Three short blocks side by side, not three little cards: the boxes were the
   last thing left with an edge on a page that no longer has any. */
.info-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 1rem; }
.info-tile {
    background: none; border: 0; padding: 0;
    display: flex; flex-direction: column; gap: .4rem;
}
.info-tile .tile-icon { font-size: 1.5rem; color: var(--maroon); }
.info-tile .tile-title { font-weight: 400; font-size: .9rem; color: var(--ink); }
.info-tile .tile-desc { font-size: .875rem; color: var(--ink); line-height: 1.6; }
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
        <p>The PUP Bi&ntilde;an Digital Research Repository, preserving and sharing the intellectual outputs of our academic community.</p>
    </div>

    <?php /* One page: the three parts read in order, one after another, with
             nothing boxed. The ids stay, so about_us.php#why still lands on
             that part. */ ?>
    <div class="about-doc">

    <section id="sec-mission">
        <h2>Our Mission</h2>
        <p><strong>PAPEL</strong> (PUP Biñan Digital Research Repository) is a centralized archiving platform built for the <em>Polytechnic University of the Philippines – Biñan Campus</em>. Born out of a need for structured, accessible, and sustainable academic record-keeping, PAPEL serves as the digital home for the diverse research outputs of our student body.</p>
    </section>

    <section id="sec-purpose">
        <h2>Our Purpose</h2>
        <p>In the fast-evolving landscape of higher education, the preservation of knowledge is paramount. <strong>PAPEL</strong> aims to eliminate the barriers of physical storage and fragmented data by providing a seamless interface where students can upload, and the administration can manage, scholarly works. We are dedicated to fostering a culture of research excellence and ensuring that every study contributes to the growing intellectual capital of the Sintang Paaralan.</p>
    </section>

    <!-- ===== PAPEL Ecosystem section hidden for now =====
         Bringing it back means setting it out like the parts above: a section
         with a heading and its text, rather than the card it still is here.
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined">groups</span>
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
                    <td>Uploads research papers and supporting documents, submits them to their faculty adviser, and tracks each submission's status, resubmitting a corrected copy whenever a paper is returned.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Faculty Adviser</span></td>
                    <td>Creates and manages their own student accounts, and is the first reviewer of every submission, either approving and forwarding the paper to the Research Coordinator, or returning it to the student with feedback.</td>
                </tr>
                <tr>
                    <td><span class="role-badge">Research Coordinator</span></td>
                    <td>Creates and manages faculty accounts, and reviews papers endorsed by advisers, approving and forwarding them to the Head of Academic Programs, or returning them with feedback.</td>
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
            <span class="material-symbols-outlined">account_tree</span>
            <h2>How a Paper Gets Published</h2>
        </div>
        <div class="page-card-body">
        
        <p>Every submission travels through a structured, four-stage review pipeline before it appears in the public repository. At any stage a reviewer may return the paper with feedback so the student can correct and resubmit, ensuring only vetted research is published.</p>
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

    <section id="sec-why">
        <h2>Why PAPEL</h2>
        <?php /* Material Symbols, like the rest of the site. These were
                 Bootstrap Icons, and bi-leaf is not in the set, so the
                 Sustainability tile had a blank where its icon should be. */ ?>
        <div class="info-tiles">
            <div class="info-tile">
                <div class="tile-icon"><span class="material-symbols-outlined">public</span></div>
                <div class="tile-title">Accessibility</div>
                <div class="tile-desc">A 24/7 digital library for the PUP Biñan community, available anytime from any device.</div>
            </div>
            <div class="info-tile">
                <div class="tile-icon"><span class="material-symbols-outlined">verified_user</span></div>
                <div class="tile-title">Security</div>
                <div class="tile-desc">A tiered access system that ensures research data is handled by the right people at the right level.</div>
            </div>
            <div class="info-tile">
                <div class="tile-icon"><span class="material-symbols-outlined">eco</span></div>
                <div class="tile-title">Sustainability</div>
                <div class="tile-desc">Reducing the environmental footprint of physical archiving while future-proofing our research records.</div>
            </div>
        </div>
    </section>

    </div><!-- /.about-doc -->

</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
