<?php
require_once __DIR__.'/../config/core.php';
/* Called before any output for what it does on the way: it starts the session
   and drops a revoked guest pass while headers can still be sent. The page no
   longer needs the user itself — that was for the chat box — and the header
   looks it up on its own. */
current_user();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Help Center · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* ===== Hero ===== */
/* ===== FAQ Accordion ===== */
.faq-list { display: flex; flex-direction: column; gap: .625rem; }
.faq-item { border: 1px solid var(--border); border-radius: var(--r-card, 8px); overflow: hidden; }
.faq-trigger {
    width: 100%; display: flex; align-items: center; justify-content: space-between;
    padding: 1.125rem 1.25rem; background: var(--white);
    border: none; text-align: left; cursor: pointer; font-family: inherit;
    font-size: .9375rem; font-weight: 400; color: var(--ink);
    transition: background .15s; gap: 1rem;
}
.faq-trigger:hover { background: var(--cream); }
.faq-trigger.open { background: var(--cream); color: var(--maroon); border-bottom: 1px solid var(--border); }
.faq-trigger .faq-icon { flex-shrink: 0; font-size: 1rem; color: var(--grey); transition: transform .25s; }
.faq-trigger.open .faq-icon { transform: rotate(45deg); color: var(--maroon); }
.faq-body { display: none; padding: 1.125rem 1.25rem; background: var(--white); color: var(--ink); font-size: .9375rem; line-height: 1.75; }
.faq-body.open { display: block; }
.faq-body ol, .faq-body ul { padding-left: 1.25rem; margin-top: .5rem; }
.faq-body li { margin-bottom: .5rem; }
.faq-body p { margin: 0 0 .75rem; }
.faq-body p:last-child { margin-bottom: 0; }
.faq-body a { color: var(--maroon); text-decoration: underline; text-underline-offset: 2px; }
.faq-body a:hover { color: var(--dark-maroon); }
/* An aside rather than an instruction: true and worth knowing, but not part of
   what the reader came here to do. */
.faq-body .faq-note {
    margin-top: 1rem; padding-top: .75rem;
    border-top: 1px solid var(--border);
    font-size: .8125rem; color: var(--grey);
}

/* Marked when a link brought the reader straight to it, so the thing they were
   sent to see is obvious among a column of identical rows. It stays marked:
   a highlight that fades while somebody is still reading is worse than none. */
.faq-item.is-called-out { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(129,4,3,.08); }

/* ===== Layout: the questions, with the quick links in a column beside them ===== */
.help-layout {
    display: grid;
    grid-template-columns: 14rem minmax(0, 1fr);
    gap: .75rem;
    align-items: start;
}
/* Each card is alone in its column, so the gap page_theme puts under a
   stacked card would only pad out the grid — and, on a phone where the two
   stack, double the gap between them. */
.help-layout > .page-card { margin-bottom: 0; }

/* ===== Quick links =====
   A short list rather than the row of tiles they were: three one-line rows,
   an icon and a name each. The names say where they go; the descriptions
   under them repeated it at three times the height. Hover marks a row with
   the soft bar down its left edge, the way the navbar underlines a link. */
.help-links { padding: .375rem 0; }
.help-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: .625rem;
    padding: .625rem 1.25rem;
    color: var(--ink);
    font-size: .875rem;
    text-decoration: none;
    transition: color .15s, background-color .15s;
}
.help-link .material-symbols-outlined { font-size: 20px; color: var(--grey); transition: color .15s; }
.help-link::before {
    content: '';
    position: absolute;
    left: 0;
    top: .4rem;
    bottom: .4rem;
    width: 3px;
    border-radius: 0 2px 2px 0;
    background: var(--soft-maroon);
    transform: scaleY(0);
    transition: transform .2s ease, background-color .2s ease;
}
.help-link:hover { color: var(--dark-maroon); }
.help-link:hover .material-symbols-outlined { color: var(--maroon); }
.help-link:hover::before { transform: scaleY(1); }
/* The card clips (overflow:hidden, for its rounded corners), and the site's
   ring is drawn 2px outside with !important — its sides would be cut off. */
.help-links .help-link:focus-visible { outline-offset: -2px !important; border-radius: var(--r-control, 4px); }
/* Arriving from "Forgot password?": this is the reader's likely next step. */
.help-link.is-called-out { color: var(--maroon); background: color-mix(in srgb, var(--maroon) 6%, transparent); }
.help-link.is-called-out .material-symbols-outlined { color: var(--maroon); }
.help-link.is-called-out::before { transform: scaleY(1); background: var(--maroon); }
@media (prefers-reduced-motion: reduce) {
    .help-link::before { transition: none; }
}
@media (max-width: 700px) {
    .help-layout { grid-template-columns: 1fr; }
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
        <span class="crumb-current">Help Center</span>
    </div>
</div>

<!-- ===== Body ===== -->
<div class="page-body">

    <div class="page-intro">
        <h1>Help Center</h1>
        <p>Guides and answers for using the repository.</p>
    </div>

    <div class="page-shell help-layout">

    <?php /* The quick links in the left column, where the section list
             stood — one list of places to go, beside the answers, rather
             than a second card to switch to. First in the markup as well as
             on screen, so the keyboard meets them in the order they are seen;
             on a phone they stack above the questions. */ ?>
    <nav class="page-card" aria-labelledby="help-links-title">
        <div class="page-card-header">
            <span class="material-symbols-outlined">bolt</span>
            <h2 id="help-links-title">Quick Links</h2>
        </div>
        <div class="help-links">
            <a href="../archive/index.php?browse=1" class="help-link">
                <span class="material-symbols-outlined">search</span> Browse Repository
            </a>
            <a href="contact_support.php" class="help-link is-support">
                <span class="material-symbols-outlined">mail</span> Contact Support
            </a>
            <a href="about_us.php" class="help-link">
                <span class="material-symbols-outlined">info</span> About PAPEL
            </a>
        </div>
    </nav>

    <section class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined">help</span>
            <h2>Frequently Asked Questions</h2>
        </div>
        <div class="page-card-body">
        
        <div class="faq-list" id="faqList">

            <div class="faq-item">
                <button class="faq-trigger" type="button" data-faq="q1">
                    How do I upload my research paper?
                    <i class="bi bi-plus faq-icon"></i>
                </button>
                <div class="faq-body" id="q1">
                    Go to the <strong>Upload Research</strong> page from your student dashboard. You will need your research paper in PDF format (max 50 MB). You can use the "Extract with AI" feature to auto-fill details like Title, Abstract, and Keywords. Don't forget to attach required documents such as Ethics Clearance and Consent Forms.
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-trigger" type="button" data-faq="q2">
                    What is the approval process?
                    <i class="bi bi-plus faq-icon"></i>
                </button>
                <div class="faq-body" id="q2">
                    Once uploaded, your paper goes through a 3-step review process:
                    <ol>
                        <li><strong>Faculty Review:</strong> Your professor checks the content and format.</li>
                        <li><strong>Admin Review:</strong> The Research Office verifies compliance.</li>
                        <li><strong>Super Admin:</strong> Final approval for archiving.</li>
                    </ol>
                    You can track the status at any time in "My Library" on your dashboard.
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-trigger" type="button" data-faq="q3">
                    My paper was declined. What should I do?
                    <i class="bi bi-plus faq-icon"></i>
                </button>
                <div class="faq-body" id="q3">
                    Check the feedback provided by the reviewer in your dashboard notification. Edit your paper to address the comments, then re-upload or update your submission. The reviewer will be notified automatically.
                </div>
            </div>

            <div class="faq-item">
                <button class="faq-trigger" type="button" data-faq="q4">
                    Can guests access the repository without logging in?
                    <i class="bi bi-plus faq-icon"></i>
                </button>
                <div class="faq-body" id="q4">
                    Yes. The public archive allows browsing and searching research papers without an account. However, to view full paper details and read the manuscript itself, you must log in using a Guest, Student, or Faculty account.
                </div>
            </div>

            <?php /* Linked to directly from the sign-in panel, so it carries an id
                     of its own rather than relying on the question's position. */ ?>
            <div class="faq-item" id="forgot-password">
                <button class="faq-trigger" type="button" data-faq="q5">
                    I forgot my password. How do I reset it?
                    <i class="bi bi-plus faq-icon"></i>
                </button>
                <div class="faq-body" id="q5">
                    <p><strong>If you still know your password</strong> and simply want a new one,
                    you can change it yourself: sign in, open <a href="settings.php#password">Settings</a>
                    and choose Password. You will be asked for your current password, then the
                    new one twice.</p>

                    <p><strong>If you have forgotten it</strong>, it cannot be recovered — passwords
                    are stored scrambled and nobody, including the Research Office, can read yours.
                    A new one has to be issued by whoever set up your account:</p>

                    <ul>
                        <li><strong>Students</strong> — your Research Adviser.</li>
                        <li><strong>Research Advisers and the Librarian</strong> — the Research Coordinator.</li>
                        <li><strong>Research Coordinator and the Head of Academic Programs</strong> — the Director.</li>
                    </ul>

                    <p>They will give you a new password to sign in with, which you should change to
                    one of your own straight afterwards. You can also ask through
                    <a href="contact_support.php" class="js-support-link">Contact Support</a>, which
                    asks who set up your account and sends the request straight to them.</p>

                    <p class="faq-note">Whenever a password is changed, the person who created the
                    account is told that it happened. They are never shown the password itself.</p>
                </div>
            </div>

        </div>
        </div>
    </section>

    </div><!-- /.page-shell -->

</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function () {

    // FAQ accordion
    document.querySelectorAll('.faq-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.dataset.faq;
            var body = document.getElementById(targetId);
            var isOpen = this.classList.contains('open');
            // Close all
            document.querySelectorAll('.faq-trigger').forEach(function (b) { b.classList.remove('open'); });
            document.querySelectorAll('.faq-body').forEach(function (b) { b.classList.remove('open'); });
            // Open this one unless it was already open
            if (!isOpen) {
                this.classList.add('open');
                body.classList.add('open');
            }
        });
    });

    /* Arriving from the sign-in panel's "Forgot password?" opens that answer,
       brings it into view, and marks Contact
       Support in the quick links beside it, which is where the reader is most
       likely headed next. A fragment on its own would scroll to a closed
       accordion and look like nothing had happened. */
    function openFromHash() {
        var id = (location.hash || '').replace('#', '');
        if (!id) return false;
        var item = document.getElementById(id);
        if (!item || !item.classList.contains('faq-item')) return false;

        var trigger = item.querySelector('.faq-trigger');
        if (!trigger) return false;
        if (!trigger.classList.contains('open')) trigger.click();
        item.scrollIntoView({ block: 'center', behavior: 'smooth' });
        item.classList.add('is-called-out');

        var support = document.querySelector('.help-link.is-support');
        if (support) support.classList.add('is-called-out');
        return true;
    }

    /* Every question starts closed, so the page opens on the list of them
       rather than on the first answer; only a link that asked for one
       (#forgot-password) opens anything. */
    openFromHash();
    window.addEventListener('hashchange', openFromHash);

});
</script>
</body>
</html>
