<?php
/**
 * The gate a first sign-in is held at.
 *
 * An account is created by somebody else and its first password is generated
 * and emailed (includes/password_generator.php), so until the person sets one
 * of their own, the password that opens their account has been written down,
 * sent over mail and seen by whoever created it. The first time they sign in
 * they land here and nothing else on the site will open until they are done:
 * see "The first sign-in, and the password gate" in config/core.php for the
 * rule, and password_gate() for how every other page is closed.
 *
 * PAPEL_PASSWORD_GATE is defined before require_login(), or this page would
 * send itself to itself for ever.
 *
 * There is no navbar. A gate with links out of it is not a gate, and every one
 * of those links would only bounce back here, which reads as a site that has
 * broken rather than as one asking for something. What is here instead is the
 * brand, the form, and Sign out, which is the one way past that is not setting
 * a password. The shell is still the shared one: includes/site_head.php for the
 * tokens and the theme, includes/page_theme.php for the card and the fields,
 * and includes/password_form.php for the boxes, which is the same form
 * pages/settings.php draws.
 */
define('PAPEL_PASSWORD_GATE', true);
require_once __DIR__ . '/../config/core.php';
require_login();

$u     = current_user();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';

/* Not a second, lesser Settings. Anybody not being held here is sent to their
   own desk: the one place to change a password by choice is Settings. */
if (!password_gate_held()) {
    header('Location: ' . role_home((string)($u['user_role'] ?? '')));
    exit;
}

$notify_upline = password_upline((int)$u['user_id']);
$notify_role   = $notify_upline ? account_position($notify_upline) : '';

/* Before any output, so the redirect is safe. The rules are the ones Settings
   uses (password_change_apply(), config/core.php), so the gate cannot ask for
   a different password from the one that page would accept. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_verify();
    $done = password_change_apply($u,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? ''));

    if ($done['ok']) {
        /* The gate comes down first: role_home() is a page this would
           otherwise bounce straight back here. */
        password_gate_clear();
        /* And the next page offers the view chooser (includes/view_welcome.php,
           printed with the footer, which reads this and clears it). Here rather
           than at sign-in, because this is the one moment we know the account is
           new to the person using it: they have just set the password that was
           emailed to them. Anybody who closes it without choosing finds it again
           in Settings > Appearance. */
        $_SESSION['papel_view_tour'] = 1;
        flash('success', $done['message']);
        header('Location: ' . role_home((string)($u['user_role'] ?? '')));
        exit;
    }
    flash('error', $done['message']);
    header('Location: change_password.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose a password · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<?php require_once ROOT_PATH.'/includes/password_form.php'; ?>
<style nonce="<?= e($nonce) ?>">
/* A bar with the letterhead and the way out, in place of the navbar: the same
   pale ground the navbar has, so the page still opens the way the site does.
   The mark is site_head.php's own .brand-mark, the stencil painted in the
   palette's accent, and not a second logo drawn here; the name beside it is
   plain text, because .brand-word is placed absolutely and drawn out on hover,
   which is a flourish for a bar with somewhere to go. */
.gate-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem;
    padding: .55rem 1.25rem;
    background: var(--white);
    border-bottom: 1px solid var(--border);
}
.gate-head { display: inline-flex; align-items: center; gap: .5rem; }
.gate-name {
    font-family: var(--font-head), serif;
    font-size: 1.0625rem; font-weight: 600; letter-spacing: .01em;
    color: var(--maroon);
}
.gate-bar .btn-sm-outline { text-decoration: none; }
.gate-bar .btn-sm-outline .material-symbols-outlined { font-size: 17px; vertical-align: -3px; }
/* The card is the page: nothing else is offered, so it sits in the middle
   rather than at the top of an otherwise empty shell. */
.gate-body { max-width: 34rem; margin: 2.5rem auto; padding: 0 1rem; }
.gate-lede { margin: 0 0 1rem; font-size: .875rem; line-height: 1.6; color: var(--ink); }
.gate-who {
    margin: 0 0 1.25rem; padding: .625rem .75rem;
    border-radius: var(--r-card, 8px);
    background: var(--cream); font-size: .8125rem; color: var(--ink);
}
.gate-who strong { font-weight: 600; }
@media (max-width: 600px) {
    .gate-body { margin: 1.25rem auto; }
}
</style>
</head>
<body>
<div class="gate-bar">
    <span class="gate-head">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="gate-name"><?= e(APP_NAME) ?></span>
    </span>
    <a class="btn-sm-outline" href="<?= e(BASE_URL) ?>/app/auth/logout.php">
        <span class="material-symbols-outlined" aria-hidden="true">logout</span> Sign out
    </a>
</div>

<div class="gate-body">
    <?php if ($m = flash('error')): ?>
        <div class="alert error"><?= e($m) ?></div>
    <?php endif; ?>
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined" aria-hidden="true">key</span>
            <h2>Choose your own password</h2>
        </div>
        <div class="page-card-body">
            <p class="gate-lede">
                Welcome to <?= e(APP_NAME) ?>, <?= e((string)($u['full_name'] ?? '')) ?>.
                The password you have just signed in with was generated for you and sent by
                email, so it is not yours alone. Choose one only you know to carry on.
            </p>
            <p class="gate-who">
                Signed in as <strong><?= e((string)($u['full_name'] ?? '')) ?></strong>
                (<?= e(account_position($u)) ?>).
            </p>
            <form class="pw-form" method="post" action="change_password.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <?php password_form_fields('At least 8 characters, and not the one you were sent.'); ?>
                <?php if ($notify_upline): ?>
                    <p class="pw-hint pw-notify">
                        <span class="material-symbols-outlined">info</span>
                        <?= e($notify_upline['full_name']) ?> (<?= e($notify_role) ?>) will be told
                        that you set it, but never what you set it to.
                    </p>
                <?php endif; ?>
                <button type="submit" class="btn-page">Save password and continue</button>
            </form>
        </div>
    </div>
</div>

<?php /* No site_footer.php: it carries the navigation scripts and the footer's
         own links, and this page has nowhere to send anybody. The three it
         does want are asked for by name. */ ?>
<?php require_once ROOT_PATH.'/includes/loading_bar.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_dismiss.php'; ?>
<?php require_once ROOT_PATH.'/includes/focus_ring.php'; ?>
</body>
</html>
