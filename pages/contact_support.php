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
       facts a desk would need, and the office address is on this same page for
       anything the form does not cover. */
    if ($kind === null) {
        flash('error', 'Please choose what your request is about. For anything else, '
            . 'use the office details beside this form.');
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
       no roll to open. It is refused here rather than passed on.
       Which column is searched follows the rule the rest of the app keeps:
       student_id belongs to students, faculty_id to everybody else. */
    $col  = $rRole === 'student' ? 'student_id' : 'faculty_id';
    $look = $conn->prepare(
        "SELECT user_id, user_role, admin_level, is_active FROM users WHERE $col = ? LIMIT 1");
    $look->bind_param('s', $rIdent);
    $look->execute();
    $found = $look->get_result()->fetch_assoc();
    $look->close();

    /* Two roles do the Head of Academic Programs' job, so the claimed role is
       matched against what the account actually is rather than compared as a
       string. A Coordinator is admin level 1; a HAP is either head_academic or
       admin level 2. */
    $actual = $found['user_role'] ?? '';
    $level  = (int)($found['admin_level'] ?? 0);
    $claimOk = $found && (
        ($rRole === 'admin'         && $actual === 'admin' && $level !== 2) ||
        ($rRole === 'head_academic' && ($actual === 'head_academic'
                                        || ($actual === 'admin' && $level === 2))) ||
        (!in_array($rRole, ['admin', 'head_academic'], true) && $actual === $rRole)
    );

    /* One message for "no such ID" and for "that ID is not that kind of
       account", so an anonymous form cannot be used to ask which of the two it
       was. */
    if (!$claimOk) {
        /* Not escaped here: the flash is escaped where it is printed. The office
           address is in the panel beside this form, so it is not repeated in the
           message. */
        flash('error', 'We could not find a ' . support_requester_roles()[$rRole]
            . ' account with the ID ' . $rIdent . '. Check the ID on your account and the role '
            . 'you chose, then try again.');
        header('Location: contact_support.php?subject=' . rawurlencode($subject));
        exit;
    }

    /* A deactivated or expired account is still an account, and being unable to
       sign in is exactly when somebody writes in. It goes through. */
    $rUserId = (int)$found['user_id'];

    /* Who it actually goes to. The account itself records who issued it, and
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
/* What the chosen topic means, said before the fields rather than after. */
.subject-note {
    margin: .5rem 0 0;
    font-size: .75rem; color: var(--grey); line-height: 1.6;
}
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* ===== Hero ===== */
/* ===== Layout ===== */
.contact-layout { display: grid; grid-template-columns: 1fr 1.75fr; gap: 1.5rem; align-items: start; }

/* ===== Office info ===== */
.info-group { display: flex; flex-direction: column; gap: 1.25rem; }
.info-item { display: flex; gap: 1rem; align-items: flex-start; }
.info-item-icon { width: 36px; height: 36px; border-radius: var(--r-card, 8px); background: rgba(129,4,3,.08); color: var(--maroon); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; margin-top: .125rem; }
.info-item-label { font-size: .75rem; font-weight: 400; text-transform: uppercase; letter-spacing: .5px; color: var(--grey); margin-bottom: .25rem; }
.info-item-value { font-size: .9375rem; color: var(--ink); line-height: 1.5; }
.info-item-value a { color: var(--maroon); text-decoration: none; font-weight: 500; }
.info-item-value a:hover { text-decoration: underline; }

/* ===== Form ===== */
.form-label { font-size: .8125rem; font-weight: 400; color: var(--ink); margin-bottom: .375rem; display: block; }
.form-control, .form-select {
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .625rem .875rem; font-size: .9rem; font-family: inherit;
    color: var(--ink); background: var(--white);
    transition: border-color .2s, box-shadow .2s; width: 100%;
}
.form-control:focus, .form-select:focus { outline: none; border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(129,4,3,.1); }
textarea.form-control { resize: vertical; min-height: 130px; }
.btn-submit {
    width: 100%; padding: .75rem 1rem;
    background: var(--maroon); color: #fff;
    border: none; border-radius: var(--r-control, 4px);
    font-size: .9375rem; font-weight: 400; cursor: pointer;
    font-family: inherit; transition: background .2s;
    display: flex; align-items: center; justify-content: center; gap: .5rem;
}
.btn-submit:hover { background: var(--dark-maroon); }
.mb-form { margin-bottom: 1rem; }

/* ===== Alert ===== */
.alert-box { padding: .875rem 1rem; border-radius: var(--r-card, 8px); font-size: .875rem; margin-bottom: 1.25rem; }
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
        <?php /* Says what the form can now do, since both topics it offers go to a
                 named person rather than to a shared mailbox. */ ?>
        <p>Ask for a new password, or for a correction to your account. Anything else
           reaches us at the address beside this form.</p>
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
                    <label class="form-label">Subject <span style="color:var(--maroon)">*</span></label>
                    <?php $presetSubject = trim($_GET['subject'] ?? ''); ?>
                    <?php /* Upload Problem, Approval Inquiry and Other are gone: all
                             three were general prose to a mailbox. A paper already has
                             a review desk and a place to say what is wrong with it,
                             and anything this form does not cover has the office
                             address and telephone number beside it. */ ?>
                    <select name="subject" id="subjectSelect" class="form-select" required>
                        <option value="">Select a topic…</option>
                        <option value="Forgotten Password" <?= $presetSubject === 'Forgotten Password' ? 'selected' : '' ?>>Forgotten Password</option>
                        <option value="Account Issue" <?= $presetSubject === 'Account Issue' ? 'selected' : '' ?>>Account Issue</option>
                    </select>
                    <p class="subject-note" id="subjectNote" hidden></p>
                </div>

                <?php /* Shown only for the two topics that are requests about an
                         account. A request without these is a paragraph nobody can
                         act on, so they are required when they are visible and
                         ignored entirely when they are not. */ ?>
                <div id="requestFields" hidden>
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
                <div class="mb-form">
                    <label class="form-label">Message <span style="color:var(--maroon)">*</span></label>
                    <textarea name="message" id="messageBox" class="form-control"
                              placeholder="How can we help you?" required></textarea>
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

<script nonce="<?= $nonce ?>">
/* The people who can be asked, grouped by desk. Names only, and only of active
   accounts — see support_handlers() for why that is safe to put on a public
   page. */
const SUPPORT_HANDLERS = <?= json_encode($handlers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/* Which of those desks could have issued each kind of account. Mirrors
   support_handler_roles_for(), which is what the submitted form is checked
   against; this copy only decides what is offered. */
const HANDLER_ROLES_FOR = <?= json_encode(
    array_map('support_handler_roles_for', array_combine(
        array_keys(support_requester_roles()), array_keys(support_requester_roles()))),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

const SUBJECT_HELP = {
    'Forgotten Password':
        'You will be asked who set up your account, because only they can issue a new password. '
      + 'Nobody can read your old one back to you.',
    'Account Issue':
        'For corrections to your name, programme, section or other details on your account. '
      + 'Say what is wrong and what it should be.'
};

const MESSAGE_PLACEHOLDER = {
    'Forgotten Password': 'Anything that helps them find you, and when you last signed in.',
    'Account Issue': 'What is wrong on your account, and what it should say instead.',
    '': 'How can we help you?'
};

(function () {
    const subject   = document.getElementById('subjectSelect');
    const block     = document.getElementById('requestFields');
    const note      = document.getElementById('subjectNote');
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
        block.hidden = !wanted;
        setRequired(wanted);

        note.hidden = !SUBJECT_HELP[subject.value];
        note.textContent = SUBJECT_HELP[subject.value] || '';
        message.placeholder = MESSAGE_PLACEHOLDER[subject.value] || MESSAGE_PLACEHOLDER[''];
    }

    /* The ID box names whichever kind of account was chosen. Before a role is
       chosen it must stay neutral: an example of one kind reads as an
       instruction to give that kind. */
    function onRequesterRole() {
        offerHandlerRoles();
        if (!rIdent) return;
        if (!rRole.value) {
            rIdent.placeholder = 'Your Student ID or Faculty ID';
            identHint.textContent = 'The number on your account.';
            return;
        }
        const student = rRole.value === 'student';
        rIdent.placeholder = student ? 'e.g. 2023-00056-BN-0' : 'e.g. FC-00122';
        identHint.textContent = student
            ? 'The Student ID on your account.'
            : 'The Faculty ID on your account.';
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

    /* The names for the chosen desk. Rebuilt rather than filtered so a name from
       a previously chosen role cannot be left selected. */
    function onHandlerRole() {
        const people = SUPPORT_HANDLERS[hRole.value] || [];
        hName.innerHTML = '';
        if (!hRole.value) {
            hName.disabled = true;
            hName.appendChild(new Option('Choose a role first…', ''));
            return;
        }
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

    subject.addEventListener('change', onSubject);
    if (rRole) rRole.addEventListener('change', onRequesterRole);
    if (hRole) hRole.addEventListener('change', onHandlerRole);

    onSubject();
    onRequesterRole();
    onHandlerRole();
})();
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
