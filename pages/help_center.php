<?php
require_once __DIR__.'/../config/core.php';
$u = current_user();
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
.quick-link-card.is-called-out {
    border-color: var(--maroon);
    background: rgba(129,4,3,.05);
    box-shadow: 0 0 0 3px rgba(129,4,3,.08);
}
.quick-link-card.is-called-out .qlc-title { color: var(--maroon); font-weight: 500; }

/* ===== Quick links grid ===== */
.quick-links { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; margin-top: 0; }
.quick-link-card {
    background: var(--cream); border: 1px solid var(--border); border-radius: var(--r-card, 8px);
    padding: 1.25rem; text-decoration: none; color: var(--ink);
    display: flex; flex-direction: column; gap: .5rem; transition: all .2s;
}
.quick-link-card:hover { border-color: var(--maroon); background: rgba(129,4,3,.03); color: var(--maroon); }
.quick-link-card .qlc-icon { font-size: 1.5rem; color: var(--maroon); }
.quick-link-card .qlc-title { font-weight: 400; font-size: .9rem; }
.quick-link-card .qlc-desc { font-size: .8125rem; color: var(--grey); }

/* ===== Chatbot ===== */
#chat-widget { position: fixed; bottom: 1.5rem; left: 1.5rem; z-index: 1000; font-family: 'Inter', sans-serif; }
#chat-button {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--maroon); color: white; border: none;
    box-shadow: 0 4px 16px rgba(129,4,3,.35);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 1.375rem; transition: transform .2s, box-shadow .2s;
}
#chat-button:hover { transform: scale(1.07); box-shadow: 0 6px 20px rgba(129,4,3,.4); }
#chat-window {
    display: none; position: absolute; bottom: 70px; left: 0;
    width: 360px; height: 520px; background: white;
    border-radius: var(--r-card, 8px); box-shadow: var(--shadow-md); border: 1px solid var(--border);
    flex-direction: column; overflow: hidden;
}
#chat-header { background: var(--maroon); color: white; padding: 1rem; font-weight: 400; font-size: .875rem; display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
#chat-messages { flex: 1; padding: 1rem; overflow-y: auto; background: var(--cream); display: flex; flex-direction: column; gap: .625rem; }
#chat-input-area { padding: .75rem 1rem; border-top: 1px solid var(--border); display: flex; gap: .5rem; background: white; }
.chat-msg { padding: .625rem .875rem; border-radius: var(--r-card, 8px); max-width: 82%; font-size: .875rem; line-height: 1.5; }
.chat-msg.user { background: var(--maroon); color: white; align-self: flex-end; border-bottom-right-radius: 3px; }
.chat-msg.bot { background: var(--white); color: var(--ink); align-self: flex-start; border: 1px solid var(--border); border-bottom-left-radius: 3px; }
#chat-close-btn { background: none; border: none; color: rgba(255,255,255,.8); cursor: pointer; font-size: 1.125rem; padding: 0; line-height: 1; }
#chat-close-btn:hover { color: #fff; }
#chat-send-btn { flex-shrink: 0; }

@media(max-width:900px) {
    .quick-links { grid-template-columns: 1fr 1fr; }
}
@media(max-width:600px) {
    .quick-links { grid-template-columns: 1fr; }
#chat-window { width: calc(100vw - 3rem); }
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

    <div class="page-shell">


    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-question-circle"></i>
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
                    you can change it yourself: sign in, open <a href="settings.php">Settings</a>
                    and use the Security card. You will be asked for your current password and to
                    type your full name to confirm.</p>

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
    </div>

    <div class="page-card">
        <div class="page-card-header">
            <i class="bi bi-lightning"></i>
            <h2>Quick Links</h2>
        </div>
        <div class="page-card-body">
        
        <div class="quick-links">
            <a href="../archive/index.php?browse=1" class="quick-link-card">
                <div class="qlc-icon"><i class="bi bi-search"></i></div>
                <div class="qlc-title">Browse Repository</div>
                <div class="qlc-desc">Search and explore all published research papers</div>
            </a>
            <a href="contact_support.php" class="quick-link-card is-support">
                <div class="qlc-icon"><i class="bi bi-envelope"></i></div>
                <div class="qlc-title">Contact Support</div>
                <div class="qlc-desc">Reach out to the Research Office directly</div>
            </a>
            <a href="about_us.php" class="quick-link-card">
                <div class="qlc-icon"><i class="bi bi-info-circle"></i></div>
                <div class="qlc-title">About PAPEL</div>
                <div class="qlc-desc">Learn more about the platform and our mission</div>
            </a>
        </div>
        </div>
    </div>
    </div><!-- /.page-shell -->

</div>

<!-- ===== AI Chatbot (logged-in users only) ===== -->
<?php if ($u): ?>
<div id="chat-widget">
    <div id="chat-window">
        <div id="chat-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-robot" style="font-size:1.125rem;"></i> PUPPY — AI Support
            </span>
            <div class="d-flex align-items-center gap-2">
                <select id="chatModelSelect" style="background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.25);border-radius:6px;padding:2px 6px;font-size:0.72rem;cursor:pointer;outline:none;">
                    <option value="1" style="color:#333;">Model 1</option>
                    <option value="2" style="color:#333;">Model 2</option>
                </select>
                <button type="button" id="chat-close-btn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div id="chat-messages">
            <div class="chat-msg bot">Hello <?= e($u['full_name']) ?>! I'm PUPPY, your AI support assistant. How can I help you today?</div>
        </div>
        <div id="chat-input-area">
            <input type="text" id="chat-input" class="form-control form-control-sm" placeholder="Type a message…">
            <button type="button" id="chat-send-btn" class="btn btn-sm" style="background:var(--maroon);color:#fff;white-space:nowrap;">Send</button>
        </div>
    </div>
    <button type="button" id="chat-button" title="Ask AI Assistant">
        <i class="bi bi-robot"></i>
    </button>
</div>
<?php endif; ?>

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

    /* Arriving from the sign-in panel's "Forgot password?" opens that answer
       rather than the first one, brings it into view, and marks the Contact
       Support card, which is where the reader is most likely headed next. A
       fragment on its own would scroll to a closed accordion and look like
       nothing had happened. */
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

        var support = document.querySelector('.quick-link-card.is-support');
        if (support) support.classList.add('is-called-out');
        return true;
    }

    // Open first FAQ by default, unless a link asked for a particular one.
    if (!openFromHash()) {
        var firstTrigger = document.querySelector('.faq-trigger');
        if (firstTrigger) firstTrigger.click();
    }
    window.addEventListener('hashchange', openFromHash);

    <?php if ($u): ?>
    // Chatbot
    var chatWin = document.getElementById('chat-window');
    var chatInput = document.getElementById('chat-input');

    function toggleChat() {
        var isOpen = chatWin.style.display === 'flex';
        chatWin.style.display = isOpen ? 'none' : 'flex';
        if (!isOpen) chatInput.focus();
    }

    document.getElementById('chat-button').addEventListener('click', toggleChat);
    document.getElementById('chat-close-btn').addEventListener('click', toggleChat);
    document.getElementById('chat-send-btn').addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', function (e) { if (e.key === 'Enter') sendMessage(); });

    async function sendMessage() {
        var msg = chatInput.value.trim();
        if (!msg) return;
        addMsg(msg, 'user');
        chatInput.value = '';
        try {
            var modelChoice = document.getElementById('chatModelSelect').value;
            var res = await fetch('help_chatbot.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message: msg, model_choice: modelChoice})
            });
            var data = await res.json();
            addMsg(data.reply || 'Error processing request', 'bot');
        } catch (e) {
            addMsg('Connection error. Please try again.', 'bot');
        }
    }

    function addMsg(text, sender) {
        var div = document.createElement('div');
        div.className = 'chat-msg ' + sender;
        div.textContent = text;
        var msgs = document.getElementById('chat-messages');
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }
    <?php endif; ?>

});
</script>
</body>
</html>
