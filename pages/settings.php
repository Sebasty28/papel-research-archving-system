<?php
require_once __DIR__ . '/../config/core.php';
require_login();
$u = current_user();
$conn = db();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';

/* Who hears about a password being changed: the desk that created the account.
   password_upline() in config/core.php, which the gate page reads too. */
$notify_upline = password_upline((int)$u['user_id']);

// account_position() in core.php names every role the way the rest of the
// system does; this page used to carry its own copy of that list.
$notify_role = $notify_upline ? account_position($notify_upline) : '';

// ---- Change password ----
// Runs before any output so the redirect below is safe. The checking, the
// update, the roll and the notice are password_change_apply() in
// config/core.php, which pages/change_password.php calls too: one set of
// rules, so the gate a first sign-in is held at cannot ask for a different
// password from the one this page would accept.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_verify();
    $done = password_change_apply($u,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? ''));
    flash($done['ok'] ? 'success' : 'error', $done['message']);
    // Back to the Password section, which is where the result is about.
    header('Location: settings.php#password');
    exit;
}

// ---- Profile data (read-only; managed by an administrator) ----
$stmt = $conn->prepare("SELECT username, email, full_name, user_role, admin_level, program, student_id, faculty_id, section, academic_year, expires_on, created_at, last_login FROM users WHERE user_id = ?");
$stmt->bind_param('i', $u['user_id']);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

/* A student account is given a life when it is created, worked out from the
   section they are in. Staff accounts have none, so the field only appears for
   students who have a date on file. */
$expiry_ts   = ($profile['user_role'] ?? '') === 'student' && !empty($profile['expires_on'])
             ? strtotime($profile['expires_on']) : null;
$expiry_past = $expiry_ts && $expiry_ts < strtotime('today');
$expiry_soon = $expiry_ts && !$expiry_past && $expiry_ts < strtotime('+120 days');

/* Chosen by role rather than by which column holds something: a member of
   staff whose number sits in student_id was being shown it as a "Student ID". */
$identity = account_identifier($profile);
$id_label = $identity['value'] !== '' ? $identity['label'] : 'Username';
$id_value = $identity['value'] !== '' ? $identity['value'] : ($profile['username'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<?php /* The three password boxes, their eye and their styles: shared with
         pages/change_password.php, the gate a first sign-in is held at. */ ?>
<?php require_once ROOT_PATH.'/includes/password_form.php'; ?>
<?php require_once ROOT_PATH.'/includes/page_sections.php'; ?>
<style nonce="<?= $nonce ?>">
/* Profile grid */
.profile-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1rem 2rem; }
.profile-field { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.profile-field dt { font-size: .6875rem; font-weight: 400; text-transform: uppercase; letter-spacing: .5px; color: var(--grey); }
.profile-field dd { font-size: .875rem; color: var(--ink); word-break: break-word; }
/* A date on its own invites "why then?": the reason sits under it. */
.profile-hint { display: block; font-size: .6875rem; color: var(--grey); margin-top: .15rem; line-height: 1.5; }
.profile-field dd.profile-warn { color: var(--dark-maroon); font-weight: 500; }

/* Option rows (appearance + notifications) */
.option-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    padding: .875rem 0;
    border-bottom: 1px solid var(--border);
}
.option-row:last-child { border-bottom: none; padding-bottom: 0; }
.option-row:first-child { padding-top: 0; }
.option-text { min-width: 0; }
.option-text strong { display: block; font-size: .875rem; font-weight: 400; color: var(--ink); }
.option-text span { font-size: .8125rem; color: var(--grey); }
/* Two buttons on one row, kept together and at their own width. */
.option-actions { display: flex; gap: .5rem; flex-shrink: 0; }

.segmented { display: inline-flex; border: 1px solid var(--border); border-radius: var(--r-card, 8px); overflow: hidden; flex-shrink: 0; }
.segmented input { position: absolute; opacity: 0; pointer-events: none; }
.segmented label {
    padding: .4rem .875rem;
    font-size: .8125rem;
    color: var(--ink);
    cursor: pointer;
    background: var(--white);
    border-right: 1px solid var(--border);
    transition: background .15s, color .15s;
}
.segmented label:last-of-type { border-right: none; }
.segmented label:hover { background: var(--cream); }
.segmented input:checked + label { background: var(--maroon-surface); color: #fff; }

/* Toggle switch */
.switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch .slider {
    position: absolute;
    inset: 0;
    background: var(--control-empty);
    box-shadow: inset 0 0 0 1px var(--control-rim);
    border-radius: 999px;
    cursor: pointer;
    transition: background .2s;
}
.switch .slider::before {
    content: '';
    position: absolute;
    height: 18px; width: 18px;
    left: 3px; top: 3px;
    background: var(--white);
    border-radius: 50%;
    transition: transform .2s;
}
.switch input:checked + .slider { background: var(--maroon); }
.switch input:checked + .slider::before { transform: translateX(18px); }

/* The password form's own styles are includes/password_form.php's. */

/* The closing note under a card's content. These were style="margin-top"
   attributes, which the CSP drops, so the gap they asked for never showed. */
.settings-note { margin-top: 1rem; }

/* Keep explanatory copy even across the card without stretching labels or values. */
.page-intro p, .option-text span, .settings-note, .pw-notify, .profile-hint {
    text-align: justify;
    text-justify: inter-word;
}
.option-text span { display: block; }

@media (max-width: 700px) {
    .profile-grid { grid-template-columns: 1fr; }
    .option-row { flex-direction: column; align-items: flex-start; gap: .625rem; }
    .page-intro h1 { font-size: 1.375rem; }
    .page-card-header { flex-wrap: wrap; row-gap: .25rem; }
    .page-card-header .hint {
        flex-basis: 100%;
        margin-left: 0;
        line-height: 1.4;
        text-align: left;
    }
}
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- Breadcrumb -->
<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Settings</span>
    </div>
</div>

<div class="page-body">

    <div class="page-intro">
        <h1>Settings</h1>
        <p>Your personal information, password, appearance and notifications.</p>
    </div>

    <?php if ($m = flash('error')): ?>
        <div class="alert error"><?= e($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('success')): ?>
        <div class="alert success"><?= e($m) ?></div>
    <?php endif; ?>

    <div class="page-shell page-sections">

    <?php /* The four sections, one card showing at a time. Buttons rather
             than links: they switch what is on this page instead of going
             anywhere, though each also sets the address's #fragment so a
             section can be linked to and survives a reload. */ ?>
    <div class="page-sections-glide">
    <nav class="page-sections-nav" role="tablist" aria-label="Settings sections" aria-orientation="vertical">
        <button type="button" class="page-sections-tab" role="tab" id="tab-personal" aria-controls="sec-personal" aria-selected="true" data-section="personal">
            <span class="material-symbols-outlined">badge</span> Personal Information
        </button>
        <button type="button" class="page-sections-tab" role="tab" id="tab-password" aria-controls="sec-password" aria-selected="false" data-section="password">
            <span class="material-symbols-outlined">lock</span> Password
        </button>
        <button type="button" class="page-sections-tab" role="tab" id="tab-appearance" aria-controls="sec-appearance" aria-selected="false" data-section="appearance">
            <span class="material-symbols-outlined">palette</span> Appearance
        </button>
        <button type="button" class="page-sections-tab" role="tab" id="tab-notifications" aria-controls="sec-notifications" aria-selected="false" data-section="notifications">
            <span class="material-symbols-outlined">notifications</span> Notifications
        </button>
    </nav>
    </div>

    <div class="page-sections-panels">

    <!-- ===== Personal information ===== -->
    <section class="page-card" id="sec-personal" role="tabpanel" aria-labelledby="tab-personal">
        <div class="page-card-header">
            <h2>Personal Information</h2>
            <span class="hint">Managed by your administrator</span>
        </div>
        <div class="page-card-body">
            <dl class="profile-grid">
                <div class="profile-field">
                    <dt>Full name</dt>
                    <dd><?= e($profile['full_name'] ?? '') ?></dd>
                </div>
                <div class="profile-field">
                    <dt>Role</dt>
                    <dd><span class="page-chip"><?= e(role_label($u)) ?></span></dd>
                </div>
                <div class="profile-field">
                    <dt><?= e($id_label) ?></dt>
                    <dd><?= e((string)$id_value) ?></dd>
                </div>
                <div class="profile-field">
                    <dt>Email</dt>
                    <dd><?= e($profile['email'] ?? '') ?></dd>
                </div>
                <?php if (!empty($profile['program'])): ?>
                <div class="profile-field">
                    <dt>Program</dt>
                    <dd><?= e($profile['program']) ?></dd>
                </div>
                <?php endif; ?>
                <div class="profile-field">
                    <dt>Member since</dt>
                    <dd><?= !empty($profile['created_at']) ? e(date('F j, Y', strtotime($profile['created_at']))) : 'Unknown' ?></dd>
                </div>
                <div class="profile-field">
                    <dt>Last sign-in</dt>
                    <dd><?= !empty($profile['last_login']) ? e(date('F j, Y g:i A', strtotime($profile['last_login']))) : 'This is your first session' ?></dd>
                </div>
                <?php if ($expiry_ts): ?>
                <div class="profile-field">
                    <dt><?= $expiry_past ? 'Account expired' : 'Account active until' ?></dt>
                    <dd class="<?= $expiry_past || $expiry_soon ? 'profile-warn' : '' ?>">
                        <?= e(date('F j, Y', $expiry_ts)) ?>
                        <?php
                        /* Say where the date comes from, or it reads as arbitrary.
                           A student account lasts as long as the course they are
                           part-way through, which their section records. */
                        $sect = trim((string)($profile['section'] ?? ''));
                        $yrs  = function_exists('student_account_years') ? student_account_years($sect) : null;
                        ?>
                        <span class="profile-hint">
                            <?php if ($expiry_past): ?>
                                Ask your research adviser to renew it.
                            <?php elseif ($yrs && $sect !== ''): ?>
                                <?= (int)$yrs ?> years from section <?= e($sect) ?>.
                                Your adviser renews it when you move up a year.
                            <?php else: ?>
                                Your research adviser renews it when you move up a year.
                            <?php endif; ?>
                        </span>
                    </dd>
                </div>
                <?php endif; ?>
            </dl>
            <p class="page-note settings-note">
                Need a correction to your name, program, or email?
                <a href="<?= e(BASE_URL) ?>/pages/contact_support.php?subject=Profile%20Correction">Contact support</a>.
            </p>
        </div>
    </section>

    <!-- ===== Password ===== -->
    <section class="page-card" id="sec-password" role="tabpanel" aria-labelledby="tab-password">
        <div class="page-card-header">
            <h2>Password</h2>
        </div>
        <div class="page-card-body">
            <?php
            /* Typed out rather than clicked past, and the reader is told who
               hears about it before they commit rather than afterwards. */
            $pw_confirm = 'You are about to change the password on your own account.';
            if ($notify_upline) {
                $pw_confirm .= ' ' . $notify_upline['full_name']
                            . ', the ' . $notify_role . ' who set up your account, will be told that'
                            . ' you changed it. They are not shown the password itself.';
            }
            ?>
            <form class="pw-form" method="post" action="settings.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <?php password_form_fields(); ?>
                <?php if ($notify_upline): ?>
                    <p class="pw-hint pw-notify">
                        <span class="material-symbols-outlined">info</span>
                        <?= e($notify_upline['full_name']) ?> (<?= e($notify_role) ?>) will be told
                        that you changed it, but never what you changed it to.
                    </p>
                <?php endif; ?>
                <?php /* The question is asked on the button, not the form: a form-wide
                         data-confirm claims every click inside it, the eyes included. */ ?>
                <button type="submit" class="btn-page btn-confirm"
                        data-confirm="<?= e($pw_confirm) ?>"
                        data-confirm-match="<?= e((string)($u['full_name'] ?? '')) ?>"
                        data-confirm-input="Type your full name to confirm">Update password</button>
            </form>
        </div>
    </section>

    <!-- ===== Appearance ===== -->
    <section class="page-card" id="sec-appearance" role="tabpanel" aria-labelledby="tab-appearance">
        <div class="page-card-header">
            <h2>Appearance</h2>
            <span class="hint">Saved on this device</span>
        </div>
        <div class="page-card-body">
            <div class="option-row">
                <div class="option-text">
                    <strong>Result view</strong>
                    <span>How research listings are laid out: a card for each paper, or one line each for scanning a long list.</span>
                </div>
                <div class="segmented">
                    <input type="radio" name="qs_view" id="view_cards" value="cards"><label for="view_cards">Cards</label>
                    <input type="radio" name="qs_view" id="view_rows" value="rows"><label for="view_rows">Rows</label>
                </div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Result density</strong>
                    <span>How much spacing to use in research listings.</span>
                </div>
                <div class="segmented">
                    <input type="radio" name="qs_density" id="density_default" value="default"><label for="density_default">Default</label>
                    <input type="radio" name="qs_density" id="density_comfortable" value="comfortable"><label for="density_comfortable">Comfortable</label>
                    <input type="radio" name="qs_density" id="density_compact" value="compact"><label for="density_compact">Compact</label>
                </div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Theme Colour</strong>
                    <span>The palette the whole site is drawn in. Old Night is the dark one; choosing it is how the site goes dark.</span>
                </div>
                <div class="segmented" id="colourChoices"></div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>How papers are shown</strong>
                    <?php /* The same dialog a new account is offered on its first
                             page (includes/view_welcome.php), with a picture of
                             each view: the words "Hardbounds" and "Bookshelf" are
                             not ones anybody can picture, which is the whole
                             reason that dialog has screenshots in it. */ ?>
                    <span>Cards, Rows, Hardbounds or Bookshelf, with a picture of each. The same choice as the View list in Quick Settings.</span>
                </div>
                <div class="option-actions">
                    <button type="button" class="btn-page-outline" data-view-tour>Choose&hellip;</button>
                </div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Zen Mode</strong>
                    <?php /* The same control as the one in the profile menu,
                             printed by includes/zen_mode.php and wired by the
                             one delegated handler there, so there is no second
                             opinion about what the switch is or what it does.
                             It says what it puts away, because a reader who
                             finds the footer gone should be able to find out
                             here why. */ ?>
                    <span>Puts the page's furniture away: the breadcrumb strip, the footer, the photo behind the navbar, the accessibility bar, the back-to-top button and the repository's banner, with the side panel folded to its rail. The navbar stays, and this switch is in the profile menu too.</span>
                </div>
                <div class="option-actions"><?php zen_switch('row'); ?></div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Accessibility tools</strong>
                    <?php /* The bar's default is not the same on every screen,
                             so the line says which way round it is here: the
                             button beside it is labelled by what it will do,
                             and on a phone that is "Show bar" on a first
                             visit. */ ?>
                    <span>Text size, contrast, dyslexia-friendly font, and reading guide. They are always here, under Open. The bar at the side of the screen is a shortcut to them: off on a phone, where the reading runs edge to edge, and on elsewhere until you hide it.</span>
                </div>
                <div class="option-actions">
                    <?php /* Labelled by what it will do; the script sets it to
                             "Show bar" when the bar is already hidden. */ ?>
                    <button type="button" class="btn-page-outline" id="a11yTabBtn">Hide bar</button>
                    <button type="button" class="btn-page" id="openA11yBtn">Open</button>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== Notifications ===== -->
    <section class="page-card" id="sec-notifications" role="tabpanel" aria-labelledby="tab-notifications">
        <div class="page-card-header">
            <h2>Notifications</h2>
            <span class="hint">Saved on this device</span>
        </div>
        <div class="page-card-body">
            <div class="option-row">
                <div class="option-text">
                    <strong>Submission updates</strong>
                    <span>Alert me when a paper is approved, returned, or forwarded.</span>
                </div>
                <label class="switch">
                    <input type="checkbox" class="js-pref-toggle" data-pref="notify_submissions" checked>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Repository announcements</strong>
                    <span>News about the repository, maintenance, and new features.</span>
                </div>
                <label class="switch">
                    <input type="checkbox" class="js-pref-toggle" data-pref="notify_announcements" checked>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Show unread badge</strong>
                    <span>Display the unread counter on the notification bell.</span>
                </div>
                <label class="switch">
                    <input type="checkbox" class="js-pref-toggle" data-pref="notify_badge" checked>
                    <span class="slider"></span>
                </label>
            </div>
            <p class="page-note settings-note">
                These control in-app alerts only. System emails required for the review
                workflow are always sent.
            </p>
        </div>
    </section>

    </div><!-- /.page-sections-panels -->

    </div><!-- /.page-shell -->

</div>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function () {
    function getStored(key, fallback) {
        try { return localStorage.getItem(key) || fallback; } catch (err) { return fallback; }
    }

    // The section list (one card at a time) is includes/page_sections.php.

    // Appearance: shares the same storage keys as the browse page's
    // Quick Settings panel, so the two stay in sync.
    // The eye on each password box is includes/password_form.php's, delegated.

    /* Cards or rows, the same key and the same attribute the Quick Settings
       panel on every browsing page uses, so setting it in either place is the
       same act. papel_desk_view is the key it started life under, when only
       the review desks offered it. */
    var view = getStored('papel_view', '') || getStored('papel_desk_view', '');
    view = (view === 'rows') ? 'rows' : 'cards';
    document.documentElement.setAttribute('data-view', view);
    document.querySelectorAll('input[name="qs_view"]').forEach(function (i) { i.checked = (i.value === view); });

    var density = getStored('papel_density', 'default');
    document.documentElement.setAttribute('data-density', density);
    document.querySelectorAll('input[name="qs_density"]').forEach(function (i) { i.checked = (i.value === density); });

    /* Theme colour. The same two palettes and the same storage key the Quick
       Settings panel uses, so changing it in either place is the same act.
       Whether the site is light or dark follows from which one is chosen -
       there is no separate switch. */
    var COLOURS = [
        ['classic', 'Old Classic'], ['old-night', 'Old Night']
    ];
    var DARK_COLOURS = { 'old-night': 1 };
    var WAS_DARK = { 'modern-dark': 1, 'quiet-dark': 1 };
    function modeFor(c) { return DARK_COLOURS[c] ? 'dark' : 'light'; }

    // Old Classic is the default: a first-time reader with nothing stored
    // yet lands there.
    var colour = getStored('papel_color', 'classic');
    // A withdrawn palette, or an old dark preference, lands somewhere sensible:
    // anyone who had a dark site keeps one.
    if (!COLOURS.some(function (c) { return c[0] === colour; })) {
        colour = (WAS_DARK[colour] || getStored('papel_theme', '') === 'dark') ? 'old-night' : 'classic';
        try { localStorage.setItem('papel_color', colour); } catch (err) {}
    }
    try { localStorage.removeItem('papel_theme'); } catch (err) {}
    var host = document.getElementById('colourChoices');
    if (host) {
        COLOURS.forEach(function (c) {
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'qs_color';
            input.id = 'colour_' + c[0];
            input.value = c[0];
            input.checked = (c[0] === colour);

            var label = document.createElement('label');
            label.setAttribute('for', input.id);
            label.textContent = c[1];

            host.appendChild(input);
            host.appendChild(label);
        });
    }
    document.documentElement.setAttribute('data-color', colour);
    document.documentElement.setAttribute('data-mode', modeFor(colour));

    document.addEventListener('change', function (e) {
        var input = e.target;
        if (input.name === 'qs_view') {
            document.documentElement.setAttribute('data-view', input.value);
            try { localStorage.setItem('papel_view', input.value); } catch (err) {}
        } else if (input.name === 'qs_density') {
            document.documentElement.setAttribute('data-density', input.value);
            try { localStorage.setItem('papel_density', input.value); } catch (err) {}
        } else if (input.name === 'qs_color') {
            document.documentElement.setAttribute('data-color', input.value);
            document.documentElement.setAttribute('data-mode', modeFor(input.value));
            try { localStorage.setItem('papel_color', input.value); } catch (err) {}
        } else if (input.classList.contains('js-pref-toggle')) {
            try { localStorage.setItem('papel_' + input.dataset.pref, input.checked ? '1' : '0'); } catch (err) {}
        }
    });

    // Notification preferences
    document.querySelectorAll('.js-pref-toggle').forEach(function (input) {
        var saved = getStored('papel_' + input.dataset.pref, '1');
        input.checked = (saved !== '0');
    });

    // Hand off to the floating accessibility widget
    var openA11yBtn = document.getElementById('openA11yBtn');
    if (openA11yBtn) {
        openA11yBtn.addEventListener('click', function (e) {
            // Stop here: left to bubble, this same click reaches <html> and the
            // widget reads it as a click outside itself, closing what it just
            // opened, which is why the button appeared to do nothing.
            e.stopPropagation();
            if (window.papelAccessibility) {
                // With the bar hidden, the panel opens against this button.
                window.papelAccessibility.open(openA11yBtn);
            } else {
                var toggle = document.getElementById('a11y-toggle');
                if (toggle) toggle.click();
            }
        });
    }

    /* Hide or bring back the Accessibility bar on the edge of every page.
       The widget owns the switch (includes/accessibility.php) and applies it
       before the bar is drawn; this button only flips it and says which way
       the next press goes. */
    var a11yTabBtn = document.getElementById('a11yTabBtn');
    /* The widget's own answer, because the two widths keep separate switches:
       a phone has no bar until it is asked for, a wider screen has one until
       it is hidden. The widget is pulled in with the footer, below this
       script, so the first paint here happens before it exists and reads the
       classes itself on the same terms; it says so on arrival
       (papel:a11y-tab, listened for below) and the label is painted again from
       the real answer. */
    function a11yTabHidden() {
        if (window.papelAccessibility && window.papelAccessibility.tabHidden) {
            return window.papelAccessibility.tabHidden();
        }
        var html = document.documentElement;
        return window.matchMedia('(max-width: 900px)').matches
            ? !html.classList.contains('a11y-tab-phone')
            :  html.classList.contains('a11y-tab-hidden');
    }
    function paintA11yTabBtn() {
        a11yTabBtn.textContent = a11yTabHidden() ? 'Show bar' : 'Hide bar';
    }
    if (a11yTabBtn) {
        paintA11yTabBtn();
        a11yTabBtn.addEventListener('click', function () {
            var hide = !a11yTabHidden();
            if (window.papelAccessibility && window.papelAccessibility.setTabHidden) {
                window.papelAccessibility.setTabHidden(hide);
            } else {
                document.documentElement.classList.toggle('a11y-tab-hidden', hide);
                try {
                    if (hide) localStorage.setItem('papel_a11y_tab_hidden', '1');
                    else      localStorage.removeItem('papel_a11y_tab_hidden');
                } catch (err) {}
            }
            paintA11yTabBtn();
        });
        // Switched in another tab: the widget follows, and so does the label.
        window.addEventListener('storage', function (e) {
            if (e.key === 'papel_a11y_tab_hidden' ||
                e.key === 'papel_a11y_tab_phone') paintA11yTabBtn();
        });
        /* Or on this page, from the tab's own right-click menu: the storage
           event above only ever fires in the other tabs. */
        document.addEventListener('papel:a11y-tab', paintA11yTabBtn);
    }

    // Client-side confirm-match check before the round trip
    var pwForm = document.querySelector('.pw-form');
    if (pwForm) {
        pwForm.addEventListener('submit', function (e) {
            var np = document.getElementById('new_password');
            var cp = document.getElementById('confirm_password');
            if (np.value !== cp.value) {
                e.preventDefault();
                cp.setCustomValidity('Passwords do not match.');
                cp.reportValidity();
            } else {
                cp.setCustomValidity('');
            }
        });
    }
});
</script>
<?php /* The typed confirmation on the password form comes from here. Without
         it the data-confirm attributes are just markup nobody reads. */ ?>
<?php require_once ROOT_PATH.'/includes/action_dialogs.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
