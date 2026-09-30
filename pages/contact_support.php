<?php
require_once __DIR__.'/../config/core.php';
$u = current_user();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';

/* The two topics that are requests rather than messages. Both name an account
   and a desk, so both are recorded and put in front of that desk; anything else
   is a message and goes to the shared mailbox as it always did. */
const SUPPORT_REQUEST_KINDS = [
    'Forgotten Password' => 'password',
    'Account Issue'      => 'account',
];

$handlers = support_handlers();

/* Who issued the account an ID names, asked while the form is being filled in.
   The name box used to offer every adviser on the roll and only find out on
   submit whether the one picked was the right one; now the ID is put to the
   database first and the only name offered is the one that account is actually
   on. Nothing is returned for a desk the form would not have offered anyway,
   so this says no more than the page already prints. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_handler'])) {
    header('Content-Type: application/json');
    if (!csrf_valid()) { echo json_encode(['ok' => false, 'reason' => 'expired']); exit; }

    $role  = trim($_POST['requester_role'] ?? '');
    $ident = trim($_POST['requester_ident'] ?? '');
    $uid   = support_account_for($role, $ident);
    if (!$uid) { echo json_encode(['ok' => false, 'reason' => 'no_account']); exit; }

    /* Whether what is at the top of the form belongs to the same account. Only
       which field disagrees is said, never what is on record: the answer to
       "is this the name" must not become a way of asking "what is the name". */
    $who = support_identity_matches($uid, trim($_POST['name'] ?? ''), trim($_POST['email'] ?? ''));

    $creator = support_creator_of($uid);
    if (!$creator) {
        echo json_encode(['ok' => false, 'reason' => 'no_desk',
                          'name_ok' => $who['name'], 'email_ok' => $who['email']]);
        exit;
    }

    /* A student the Coordinator enrolled is one the form only offers advisers
       for. There is nothing to verify against in that case, so the name is
       withheld and the box falls back to the roll: whatever is chosen is
       overridden by the record on submit. */
    if (!array_key_exists($creator['role'], support_handler_roles_for($role))) {
        echo json_encode(['ok' => true, 'offerable' => false,
                          'name_ok' => $who['name'], 'email_ok' => $who['email']]);
        exit;
    }

    echo json_encode(['ok' => true, 'offerable' => true,
        'name_ok' => $who['name'], 'email_ok' => $who['email'],
        'handler' => [
            'id' => $creator['id'], 'name' => $creator['name'], 'role' => $creator['role'],
        ]]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    $kind = SUPPORT_REQUEST_KINDS[$subject] ?? null;

    if ($name === '' || $email === '' || $message === '') {
        flash('error', 'Please fill in all required fields.');
        header('Location: contact_support.php');
        exit;
    }

    /* Every topic the form offers is now a request about an account, so there is
       no longer a general branch that posts prose to a mailbox. A submission
       with no topic, or one invented by hand, is refused: it has none of the
       facts a desk would need. */
    if ($kind === null) {
        flash('error', 'Please choose what your request is about.');
        header('Location: contact_support.php');
        exit;
    }

    /* A request. Every one of these has to say who is asking, about which
       account, and who is being asked: without them the desk that receives it
       has a paragraph of prose and no way to act on it. */
    $rRole  = trim($_POST['requester_role'] ?? '');
    $rIdent = trim($_POST['requester_ident'] ?? '');
    $hRole  = trim($_POST['handler_role'] ?? '');
    $hId    = (int)($_POST['handler_user_id'] ?? 0);

    $roleOk = array_key_exists($rRole, support_requester_roles());
    /* Only the desks that could have issued this kind of account, so a
       Coordinator is not asked to choose between an adviser and themselves. The
       posted choice is a statement of what they believe; the record below is
       what decides. */
    $handlerOk = $roleOk
              && array_key_exists($hRole, support_handler_roles_for($rRole))
              && in_array($hId, array_column($handlers[$hRole] ?? [], 'id'), true);

    if (!$roleOk || $rIdent === '' || !$handlerOk) {
        flash('error', 'Please complete every field: your role, your ID, and who set up your account.');
        header('Location: contact_support.php?subject=' . rawurlencode($subject));
        exit;
    }

    $conn = db();

    /* The account the request is about has to exist, and has to be the kind of
       account they say it is. A request naming no real account is one nobody can
       act on: the desk it reaches has a name, a number that matches nothing, and
       no roll to open. It is refused here rather than passed on. The same lookup
       answers the name box while the form is being filled in. */
    $rUserId = support_account_for($rRole, $rIdent);

    if (!$rUserId) {
        /* Not escaped here: the flash is escaped where it is printed. */
        flash('error', 'We could not find a ' . support_requester_roles()[$rRole]
            . ' account with the ID ' . $rIdent . '. Check the ID on your account and the role '
            . 'you chose, then try again.');
        header('Location: contact_support.php?subject=' . rawurlencode($subject));
        exit;
    }

    /* The ID names the account; the name and the address at the top of the form
       have to be that same account's. Which one is wrong is said, because the
       fix differs, but never what is on record. */
    $who = support_identity_matches($rUserId, $name, $email);
    if (!$who['name'] || !$who['email']) {
        $wrong = !$who['name'] && !$who['email']
            ? 'The name and email address you gave are not the ones'
            : (!$who['name'] ? 'The name you gave is not the one'
                             : 'The email address you gave is not the one');
        flash('error', $wrong . ' on the account for that ID. '
            . 'Use the details the account was set up with.');
        header('Location: contact_support.php?subject=' . rawurlencode($subject));
        exit;
    }

    /* A deactivated or expired account is still an account, and being unable to
       sign in is exactly when somebody writes in. It goes through.

       Who it actually goes to. The account itself records who issued it, and
       that is the only desk that can reissue it, so the record decides. */
    $creator = support_creator_of($rUserId);
    if ($creator) {
        /* Checked rather than quietly corrected. Naming somebody else's adviser
           is worth saying out loud: it is usually a wrong ID or the wrong role
           further up the form, and silently sending it to the right person hid
           that. The name they picked is not revealed as wrong-or-right by the
           message, only that it does not match. */
        $offerable = array_key_exists($creator['role'], support_handler_roles_for($rRole));
        if ($offerable && $hId !== $creator['id']) {
            flash('error', 'That is not the person who set up your account. Choose the one who '
                . 'issued it, which may not be the adviser you work with now, and check the ID '
                . 'you gave above.');
            header('Location: contact_support.php?subject=' . rawurlencode($subject));
            exit;
        }
        /* Where the truth was not on the list, there was nothing to get right:
           a student enrolled by the Coordinator is only ever offered advisers.
           Those go to the record without complaint. */
        $hRole = $creator['role'];
        $hId   = $creator['id'];
    }

    $ins = $conn->prepare(
        "INSERT INTO support_requests
            (kind, requester_name, requester_email, requester_role, requester_ident,
             requester_user_id, handler_role, handler_user_id, message, created_at)
         VALUES (?,?,?,?,?,?,?,?,?, NOW())");
    $ins->bind_param('sssssisis', $kind, $name, $email, $rRole, $rIdent,
                     $rUserId, $hRole, $hId, $message);
    $ok = $ins->execute();
    $ins->close();

    if (!$ok) {
        flash('error', 'Your request could not be recorded just now. Please try again shortly.');
        header('Location: contact_support.php');
        exit;
    }

    /* The desk is told in the app, so it is waiting for them next time they
       sign in rather than sitting in a mailbox somebody has to remember to
       check. The message says what is being asked and by whom, and no more. */
    $note = $conn->prepare(
        "INSERT INTO notifications (user_id, paper_id, notification_type, message)
         VALUES (?, NULL, 'support', ?)");
    $what = $kind === 'password' ? 'a new password' : 'a correction to their account details';
    $msg  = $name . ' (' . (support_requester_roles()[$rRole] ?? $rRole) . ', ' . $rIdent
          . ') has asked you for ' . $what . '.';
    $note->bind_param('is', $hId, $msg);
    $note->execute();
    $note->close();

    /* And by email as well, since a request nobody sees is a request unanswered.
       It goes to the desk that has to act on it, at their own address: this used
       to go to the office mailbox instead, where it sat next to every other
       message and in front of nobody who could answer it. The office address is
       kept only as a fallback, for a handler with no email on file. */
    $handlerName  = $creator['name'] ?? '';
    $handlerEmail = '';
    $hLook = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1");
    $hLook->bind_param('i', $hId);
    $hLook->execute();
    if ($hRow = $hLook->get_result()->fetch_assoc()) {
        if ($handlerName === '') $handlerName = (string)$hRow['full_name'];
        $handlerEmail = trim((string)$hRow['email']);
    }
    $hLook->close();
    if ($handlerName === '') {
        foreach ($handlers[$hRole] as $h) if ($h['id'] === $hId) $handlerName = $h['name'];
    }

    $body  = email_para('Good day ' . $handlerName . ',');
    $body .= email_para(($kind === 'password' ? 'A new password' : 'A correction to an account')
           . ' has been asked of you through ' . APP_NAME . '. You set up this account, so you '
           . 'are the only one who can ' . ($kind === 'password' ? 'issue a new password for it.'
                                                                : 'correct it.'));
    $body .= email_details([
        'Requested by' => $name,
        'Role'         => support_requester_roles()[$rRole] ?? $rRole,
        'ID'           => $rIdent,
        'Reply to'     => $email,
    ], ['ID']);
    $body .= email_para($message);
    $body .= email_action('Open it in ' . APP_NAME, BASE_URL . '/app/support_requests.php');

    $sent = send_email(
        $handlerEmail !== '' ? $handlerEmail : SUPPORT_EMAIL,
        ($kind === 'password' ? 'Password reset requested: ' : 'Account correction requested: ') . $name,
        $body);

    flash('success', $handlerName
        ? 'Your request has been sent to ' . $handlerName . ', who will be told about it in '
          . APP_NAME . ($sent
              ? ' and by email.'
              : '. The email could not be sent just now, so they may only see it when they '
                . 'next sign in.')
        : 'Your request has been recorded.');
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
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The extra questions the two request topics ask. Set inside a tinted block so
   it reads as one section that appeared, rather than as fields that have been
   sprinkled through the form. */
.request-block {
    padding: 1rem 1.125rem;
    margin-bottom: 1rem;
    background: var(--cream);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
}
.request-block-head {
    margin: 0 0 .25rem;
    font-family: var(--font-head);
    font-size: .875rem; font-weight: 500; color: var(--maroon);
}
.request-block-sub {
    margin: 0 0 .875rem;
    font-size: .75rem; color: var(--grey); line-height: 1.6;
}
.request-block .mb-form:last-child { margin-bottom: 0; }
.form-hint { display: block; margin-top: .25rem; font-size: .75rem; color: var(--grey); }
/* Said under the field it is about, in the colour the rest of the site uses for
   something that has to be put right before it will go. */
.form-hint.is-bad { color: var(--bad-text); }
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* ===== Hero ===== */
/* ===== Layout ===== */
.contact-flat { background: none; padding: 0; }
/* Trimmed where it costs nothing to read, so the page still clears the fold on
   a shorter laptop screen than this one. */
.page-intro { margin-bottom: .625rem; }
.contact-layout .page-card-body { padding: 1rem 1.25rem; }
/* Against the left of the shell rather than centred in it, and only as wide
   as it needs to be: the card grows sideways when a topic is chosen. */
.contact-layout { width: fit-content; max-width: 100%; margin: 0; }

/* The form proper, and beside it the column the extra questions open in. The
   form column keeps its width either way, so nothing already on screen moves
   when the questions appear. */
.contact-form { display: flex; align-items: flex-start; gap: 1.5rem; }
/* Sized so the two columns and the gap between them come to exactly the width
   of the shell once the card's own padding is taken off: open, the card fills
   the page rather than stopping short of it. */
.form-main { width: 656px; max-width: 100%; }
.form-side { width: 400px; }
.form-side .request-block:last-child { margin-bottom: 0; }

/* Wide enough for the two blocks of questions to stand beside each other as
   well as beside the form. Stacked they are the tallest thing on the page and
   the foot of the card falls below the fold; side by side the whole page is
   one screenful. */
@media(min-width:1024px) {
    /* Shares of what there is rather than fixed widths, so the three columns
       hold from a small laptop up to a wide desktop. */
    .form-main { width: auto; flex: 1 1 440px; min-width: 0; }
    .form-side {
        width: auto;
        flex: 1 1 560px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
    }
    .form-side .request-block { margin-bottom: 0; }
}


/* ===== Form ===== */
.form-label { font-size: .8125rem; font-weight: 400; color: var(--ink); margin-bottom: .375rem; display: block; }
.form-control, .form-select {
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .625rem .875rem; font-size: .9rem; font-family: inherit;
    color: var(--ink); background: var(--white);
    transition: border-color .2s, box-shadow .2s; width: 100%;
}
.form-control:focus, .form-select:focus { outline: none; border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(129,4,3,.1); }
textarea.form-control { resize: vertical; min-height: 104px; }
.btn-submit {
    width: 100%; padding: .75rem 1rem;
    background: var(--maroon); color: #fff;
    border: none; border-radius: var(--r-control, 4px);
    font-size: .9375rem; font-weight: 400; cursor: pointer;
    font-family: inherit; transition: background .2s;
    display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.btn-submit:hover { background: var(--dark-maroon); }
/* The same button, sized for the header strip rather than the width of a column. */
.btn-submit-head { width: auto; margin-left: auto; padding: .5rem 1rem; font-size: .875rem; }
/* The header strip paints every icon in it maroon, for the ones that sit beside
   a heading on white. On the button that is maroon on maroon, so the paper
   plane disappeared: in here the icon takes the button's own colour. */
.page-card-header .btn-submit i[class*="bi-"] { color: inherit; }
.mb-form { margin-bottom: .75rem; }

/* ===== Alert ===== */
.alert-box { padding: .875rem 1rem; border-radius: var(--r-card, 8px); font-size: .875rem; margin-bottom: 1.25rem; }
.alert-box.success { background: #d1fae5; color: #065f46; }
.alert-box.error   { background: #fee2e2; color: #991b1b; }

@media(max-width:900px) {
    /* No room for a second column: the questions go back under the form. */
    .contact-layout { width: auto; }
    .contact-form { flex-direction: column; }
    .form-main, .form-side { width: 100%; }
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
        <?php /* Says what the form can now do, since both topics it offers go to a
                 named person rather than to a shared mailbox. */ ?>
        <p>Ask for a new password, or for a correction to your account.</p>
    </div>

    <?php /* No tinted panel behind the card: it has edges of its own, as on
             the Help Center. */ ?>
    <div class="page-shell contact-flat">

    <?php if ($m = flash('success')): ?>
        <div class="alert-box success"><i class="bi bi-check-circle me-2"></i><?= e($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('error')): ?>
        <div class="alert-box error"><i class="bi bi-exclamation-circle me-2"></i><?= e($m) ?></div>
    <?php endif; ?>

    <div class="contact-layout">

        <!-- Contact form -->
        <div class="page-card">
        <div class="page-card-header">
            <h2>Send a Message</h2>
            <?php /* At the top of the card rather than the foot of the form. It is
                     outside the <form>, so it names the form it posts instead of
                     being inside it. */ ?>
            <button type="submit" form="supportForm" class="btn-submit btn-submit-head">
                <i class="bi bi-send"></i> Send Concern
            </button>
        </div>
        <div class="page-card-body">
            
            <form method="post" class="contact-form" id="supportForm">
                <?php /* The questions a topic asks open in a column of their own
                         beside the form, rather than in the middle of it: put back
                         in line they pushed the message box and the button down the
                         page, so choosing a topic read as the form having moved. */ ?>
                <div class="form-main">
                    <?= csrf_field() ?>
                    <div class="mb-form">
                        <label class="form-label">Full Name <span style="color:var(--maroon)">*</span></label>
                        <input type="text" name="name" id="fullNameBox" class="form-control" placeholder="Enter your full name" required
                               value="<?= $u ? e($u['full_name']) : '' ?>">
                        <small class="form-hint is-bad" id="nameNote" hidden></small>
                    </div>
                    <div class="mb-form">
                        <label class="form-label">Email Address <span style="color:var(--maroon)">*</span></label>
                        <input type="email" name="email" id="emailBox" class="form-control" placeholder="Enter your email address" required
                               value="<?= $u ? e($u['email'] ?? '') : '' ?>">
                        <small class="form-hint is-bad" id="emailNote" hidden></small>
                    </div>
                    <div class="mb-form">
                        <label class="form-label">Subject <span style="color:var(--maroon)">*</span></label>
                        <?php $presetSubject = trim($_GET['subject'] ?? ''); ?>
                        <?php /* Upload Problem, Approval Inquiry and Other are gone: all
                                 three were general prose to a mailbox. A paper already has
                                 a review desk and a place to say what is wrong with it. */ ?>
                        <select name="subject" id="subjectSelect" class="form-select" required>
                            <option value="">Select a topic…</option>
                            <option value="Forgotten Password" <?= $presetSubject === 'Forgotten Password' ? 'selected' : '' ?>>Forgotten Password</option>
                            <option value="Account Issue" <?= $presetSubject === 'Account Issue' ? 'selected' : '' ?>>Account Issue</option>
                        </select>
                    </div>

                    <div class="mb-form">
                        <label class="form-label">Message <span style="color:var(--maroon)">*</span></label>
                        <textarea name="message" id="messageBox" class="form-control"
                                  placeholder="How can we help you?" required></textarea>
                    </div>
                </div>

                <?php /* Always on show, so the form does not rearrange itself as a
                         topic is chosen. Both topics ask them, and a request without
                         them is a paragraph nobody can act on; only whether they are
                         required follows the topic. */ ?>
                <div id="requestFields" class="form-side">
                    <div class="request-block">
                        <p class="request-block-head">About your account</p>

                        <div class="mb-form">
                            <label class="form-label">Your role <span style="color:var(--maroon)">*</span></label>
                            <select name="requester_role" id="requesterRole" class="form-select">
                                <option value="">Select your role…</option>
                                <?php foreach (support_requester_roles() as $val => $label): ?>
                                    <option value="<?= e($val) ?>"
                                        <?= ($u && ($u['user_role'] ?? '') === $val) ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-form">
                            <label class="form-label">Your ID <span style="color:var(--maroon)">*</span></label>
                            <input type="text" name="requester_ident" id="requesterIdent" class="form-control"
                                   placeholder="Your Student ID or Faculty ID">
                            <small class="form-hint" id="identHint">The number on your account.</small>
                        </div>
                    </div>

                    <div class="request-block">
                        <p class="request-block-head">Who set up your account</p>
                        <p class="request-block-sub">
                            A new password or a correction can only be issued by the desk that made
                            your account, so only the desks that could have made yours are offered.
                            If you are not sure, choose the one you deal with.
                        </p>

                        <div class="mb-form">
                            <label class="form-label">Their role <span style="color:var(--maroon)">*</span></label>
                            <?php /* Rebuilt from the role chosen above: a Coordinator's account can
                                     only have come from the Director, and offering them an adviser
                                     and themselves as well was the confusing part. */ ?>
                            <select name="handler_role" id="handlerRole" class="form-select">
                                <option value="">Choose your role first…</option>
                            </select>
                        </div>

                        <div class="mb-form">
                            <label class="form-label">Their name <span style="color:var(--maroon)">*</span></label>
                            <?php /* Filled from the role above rather than listing everybody at
                                     once, so the choice is short and cannot be mismatched. */ ?>
                            <select name="handler_user_id" id="handlerName" class="form-select" disabled>
                                <option value="">Choose a role first…</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    </div>
    </div><!-- /.page-shell -->

</div>

<script nonce="<?= $nonce ?>">
/* The people who can be asked, grouped by desk. Names only, and only of active
   accounts: see support_handlers() for why that is safe to put on a public
   page. */
const SUPPORT_HANDLERS = <?= json_encode($handlers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/* Which of those desks could have issued each kind of account. Mirrors
   support_handler_roles_for(), which is what the submitted form is checked
   against; this copy only decides what is offered. */
const HANDLER_ROLES_FOR = <?= json_encode(
    array_map('support_handler_roles_for', array_combine(
        array_keys(support_requester_roles()), array_keys(support_requester_roles()))),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

const MESSAGE_PLACEHOLDER = {
    'Forgotten Password': 'Anything that helps them find you, and when you last signed in.',
    'Account Issue': 'What is wrong on your account, and what it should say instead.',
    '': 'How can we help you?'
};

(function () {
    const TOKEN     = (document.querySelector('input[name="_token"]') || {}).value || '';
    const subject   = document.getElementById('subjectSelect');
    const block     = document.getElementById('requestFields');
    const fullName  = document.getElementById('fullNameBox');
    const emailBox  = document.getElementById('emailBox');
    const nameNote  = document.getElementById('nameNote');
    const emailNote = document.getElementById('emailNote');
    const rRole     = document.getElementById('requesterRole');
    const rIdent    = document.getElementById('requesterIdent');
    const identHint = document.getElementById('identHint');
    const hRole     = document.getElementById('handlerRole');
    const hName     = document.getElementById('handlerName');
    const message   = document.getElementById('messageBox');
    if (!subject || !block) return;

    const NEEDS_DETAIL = ['Forgotten Password', 'Account Issue'];

    /* Required only while they are on screen. A hidden field marked required is
       one the browser refuses to submit and refuses to explain, because it
       cannot scroll to something that is not displayed. */
    function setRequired(on) {
        [rRole, rIdent, hRole, hName].forEach(function (el) {
            if (!el) return;
            if (on) el.setAttribute('required', 'required');
            else el.removeAttribute('required');
        });
    }

    function onSubject() {
        const wanted = NEEDS_DETAIL.indexOf(subject.value) !== -1;
        setRequired(wanted);
        message.placeholder = MESSAGE_PLACEHOLDER[subject.value] || MESSAGE_PLACEHOLDER[''];
    }

    /* The ID box names whichever kind of account was chosen. Before a role is
       chosen it must stay neutral: an example of one kind reads as an
       instruction to give that kind. */
    function identHintText() {
        if (!rRole.value) return 'The number on your account.';
        return rRole.value === 'student'
            ? 'The Student ID on your account.'
            : 'The Faculty ID on your account.';
    }

    /* The hint under the ID says what to put there, or what is wrong with what
       is there. Marked on the box as well: the name dropdown goes empty and
       disabled when no account is found, and a disabled field is one the
       browser skips, so without this the form would let a request with no desk
       on it be sent and answered with a flash. */
    function sayIdent(bad, text) {
        if (!rIdent || !identHint) return;
        identHint.textContent = bad ? text : identHintText();
        identHint.classList.toggle('is-bad', !!bad);
        rIdent.setCustomValidity(bad ? text : '');
    }

    function onRequesterRole() {
        offerHandlerRoles();
        if (!rIdent) return;
        rIdent.placeholder = !rRole.value ? 'Your Student ID or Faculty ID'
                           : (rRole.value === 'student' ? 'e.g. 2023-00056-BN-0' : 'e.g. FC-00122');
        sayIdent(false, '');
    }

    /* Only the desks that could have set up this kind of account. Where that
       leaves exactly one, it is chosen outright: there is nothing to decide, and
       a dropdown holding a single answer only invites a wrong one. */
    function offerHandlerRoles() {
        if (!hRole) return;
        const roles = HANDLER_ROLES_FOR[rRole.value] || {};
        const keys  = Object.keys(roles);
        hRole.innerHTML = '';

        if (!rRole.value) {
            hRole.disabled = true;
            hRole.appendChild(new Option('Choose your role first…', ''));
        } else if (keys.length === 1) {
            hRole.disabled = false;
            hRole.appendChild(new Option(roles[keys[0]], keys[0], true, true));
        } else {
            hRole.disabled = false;
            hRole.appendChild(new Option('Select a role…', ''));
            keys.forEach(function (k) { hRole.appendChild(new Option(roles[k], k)); });
        }
        onHandlerRole();
    }

    /* What the ID given above turned out to be. Until it has been put to the
       database there is nothing to offer: the whole point of asking is that only
       the desk this account is actually on can reissue it. */
    let state = { kind: 'need_id' };   // need_id | checking | none | matched | roll
    let match = null;                  // {id, name, role} once one is found
    let seq   = 0;                     // only the newest answer is allowed to paint

    function only(text) {
        hName.innerHTML = '';
        hName.disabled = true;
        hName.appendChild(new Option(text, ''));
    }

    /* The names for the chosen desk. Rebuilt rather than filtered so a name from
       a previously chosen role cannot be left selected. */
    function paintNames() {
        if (!hRole.value)              return only('Choose a role first…');
        if (state.kind === 'need_id')  return only('Enter your ID above first…');
        if (state.kind === 'checking') return only('Checking that ID…');
        if (state.kind === 'none')     return only('No account with that ID');

        if (state.kind === 'matched') {
            if (match.role !== hRole.value) return only('Not the desk that issued this ID');
            hName.innerHTML = '';
            hName.disabled = false;
            hName.appendChild(new Option(match.name, match.id, true, true));
            return;
        }

        /* The account was found but its desk is not one this form offers, so
           there is nothing to check a name against: the roll it is. */
        const people = SUPPORT_HANDLERS[hRole.value] || [];
        hName.innerHTML = '';
        hName.disabled = people.length === 0;
        // One person holding the desk is not a choice either.
        if (people.length === 1) {
            hName.appendChild(new Option(people[0].name, people[0].id, true, true));
            return;
        }
        hName.appendChild(new Option(
            people.length ? 'Select a name…' : 'Nobody is listed for that role', ''));
        people.forEach(function (p) { hName.appendChild(new Option(p.name, p.id)); });
    }

    function onHandlerRole() { paintNames(); }

    /* Asks the page itself who issued the account. A failed request leaves the
       roll on offer rather than an empty box: the submitted choice is checked
       against the record either way, so a dropped connection must not be the
       thing that stops somebody asking for help. */
    /* The name and the address are the account's too, or the request is somebody
       writing in about a roll they are not on. Marked against the field itself,
       so the browser will not send it until it is put right. */
    function sayField(input, note, bad, text) {
        if (!input || !note) return;
        note.hidden = !bad;
        note.textContent = bad ? text : '';
        input.setCustomValidity(bad ? text : '');
    }

    function clearIdentityMarks() {
        sayField(fullName, nameNote, false, '');
        sayField(emailBox, emailNote, false, '');
    }

    function markIdentity(d) {
        sayField(fullName, nameNote, d.name_ok === false,
                 'This is not the name on the account for that ID.');
        sayField(emailBox, emailNote, d.email_ok === false,
                 'This is not the email address on the account for that ID.');
    }

    function checkIdent() {
        const role  = rRole  ? rRole.value : '';
        const ident = rIdent ? rIdent.value.trim() : '';
        const mine  = ++seq;

        if (!role || !ident) {
            match = null; state = { kind: 'need_id' };
            clearIdentityMarks(); sayIdent(false, ''); paintNames(); return;
        }

        state = { kind: 'checking' };
        paintNames();

        fetch(location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                _token: TOKEN, lookup_handler: '1',
                requester_role: role, requester_ident: ident,
                name: fullName ? fullName.value : '',
                email: emailBox ? emailBox.value : ''
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (mine !== seq) return;                   // a newer ID is being checked
            /* Nothing was found, so there is nothing for the name to disagree
               with: one complaint about the ID beats three about everything. */
            if (d.reason === 'no_account' || d.name_ok === undefined) clearIdentityMarks();
            else markIdentity(d);

            sayIdent(d.reason === 'no_account', 'No account has that ID. Check the ID and the role above.');

            if (!d.ok)             { match = null; state = { kind: 'none' }; }
            else if (!d.offerable) { match = null; state = { kind: 'roll' }; }
            else {
                match = d.handler;
                state = { kind: 'matched' };
                // The desk is known, so there is nothing left to choose there.
                if (hRole.value !== match.role
                    && hRole.querySelector('option[value="' + match.role + '"]')) {
                    hRole.value = match.role;
                }
            }
            paintNames();
        })
        .catch(function () {
            if (mine !== seq) return;
            match = null; state = { kind: 'roll' };
            clearIdentityMarks();          // the submit checks all three anyway
            sayIdent(false, '');
            paintNames();
        });
    }

    let typing = null;
    function onIdentTyped() {
        clearTimeout(typing);
        typing = setTimeout(checkIdent, 450);
    }

    subject.addEventListener('change', onSubject);
    if (rRole)  rRole.addEventListener('change', function () { onRequesterRole(); checkIdent(); });
    if (hRole)  hRole.addEventListener('change', onHandlerRole);
    if (rIdent) {
        rIdent.addEventListener('input', function () { sayIdent(false, ''); onIdentTyped(); });
        rIdent.addEventListener('change', checkIdent);
        rIdent.addEventListener('blur', checkIdent);
    }
    /* Typing clears the mark at once: a field the browser is refusing to send,
       still complaining about what it said a moment ago, reads as stuck. */
    [[fullName, nameNote], [emailBox, emailNote]].forEach(function (pair) {
        if (!pair[0]) return;
        pair[0].addEventListener('input', function () {
            sayField(pair[0], pair[1], false, '');
            onIdentTyped();
        });
        pair[0].addEventListener('change', checkIdent);
        pair[0].addEventListener('blur', checkIdent);
    });

    onSubject();
    onRequesterRole();
    checkIdent();
})();
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
