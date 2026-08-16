<?php
require_once __DIR__ . '/../config/core.php';
require_login();
$u = current_user();
$conn = db();
$nonce = function_exists('csp_nonce') ? csp_nonce() : '';

// ---- Change password ----
// Runs before any output so the redirect below is safe.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_verify();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $u['user_id']);
    $stmt->execute();
    $hash = $stmt->get_result()->fetch_assoc()['password'] ?? '';
    $stmt->close();

    if (!password_verify($current, $hash)) {
        flash('error', 'Your current password is incorrect.');
    } elseif (strlen($new) < 8) {
        flash('error', 'Your new password must be at least 8 characters long.');
    } elseif ($new !== $confirm) {
        flash('error', 'The new passwords you entered do not match.');
    } elseif ($new === $current) {
        flash('error', 'Your new password must be different from your current one.');
    } else {
        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $up = $conn->prepare("UPDATE users SET password = ?, plain_password = NULL WHERE user_id = ?");
        $up->bind_param('si', $new_hash, $u['user_id']);
        $up->execute();
        $up->close();
        flash('success', 'Your password has been updated.');
    }
    header('Location: settings.php');
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

$id_label = 'Username';
$id_value = $profile['username'] ?? '';
if (!empty($profile['student_id'])) { $id_label = 'Student ID'; $id_value = $profile['student_id']; }
elseif (!empty($profile['faculty_id'])) { $id_label = 'Faculty ID'; $id_value = $profile['faculty_id']; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/page_theme.php'; ?>
<style nonce="<?= $nonce ?>">
/* Profile grid */
.profile-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1rem 2rem; }
.profile-field { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.profile-field dt { font-size: .6875rem; font-weight: 400; text-transform: uppercase; letter-spacing: .5px; color: var(--grey); }
.profile-field dd { font-size: .875rem; color: var(--ink); word-break: break-word; }
/* A date on its own invites "why then?" — the reason sits under it. */
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

.segmented { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; flex-shrink: 0; }
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
.segmented input:checked + label { background: var(--maroon); color: #fff; }

/* Toggle switch */
.switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch .slider {
    position: absolute;
    inset: 0;
    background: #E2DCDC;
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

/* Password form (fields themselves come from the shared .page-field styles) */
.pw-form { max-width: 420px; }
.pw-hint { font-size: .75rem; color: var(--grey); margin-top: .3rem; }

@media (max-width: 700px) {
    .profile-grid { grid-template-columns: 1fr; }
    .option-row { flex-direction: column; align-items: flex-start; gap: .625rem; }
    .page-intro h1 { font-size: 1.375rem; }
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
        <p>Manage your account details, appearance, and notification preferences.</p>
    </div>

    <?php if ($m = flash('error')): ?>
        <div class="alert error"><?= e($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('success')): ?>
        <div class="alert success"><?= e($m) ?></div>
    <?php endif; ?>

    <div class="page-shell">

    <!-- ===== Profile ===== -->
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined">account_circle</span>
            <h2>Profile</h2>
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
                    <dd><?= !empty($profile['created_at']) ? e(date('F j, Y', strtotime($profile['created_at']))) : '—' ?></dd>
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
            <p class="page-note" style="margin-top:1rem;">
                Need a correction to your name, program, or email?
                <a href="<?= e(BASE_URL) ?>/pages/contact_support.php?subject=Profile%20Correction">Contact support</a>.
            </p>
        </div>
    </div>

    <!-- ===== Appearance ===== -->
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined">palette</span>
            <h2>Appearance</h2>
            <span class="hint">Saved on this device</span>
        </div>
        <div class="page-card-body">
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
                    <strong>Colour</strong>
                    <span>The palette the whole site is drawn in. Light and dark apply to each one.</span>
                </div>
                <div class="segmented" id="colourChoices"></div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Theme</strong>
                    <span>Light, dark, or whatever this device is set to.</span>
                </div>
                <div class="segmented">
                    <input type="radio" name="qs_theme" id="theme_system" value="system"><label for="theme_system">System</label>
                    <input type="radio" name="qs_theme" id="theme_light" value="light"><label for="theme_light">Light</label>
                    <input type="radio" name="qs_theme" id="theme_dark" value="dark"><label for="theme_dark">Dark</label>
                </div>
            </div>
            <div class="option-row">
                <div class="option-text">
                    <strong>Accessibility tools</strong>
                    <span>Text size, contrast, dyslexia-friendly font, and reading guide.</span>
                </div>
                <button type="button" class="btn-page" id="openA11yBtn">Open</button>
            </div>
        </div>
    </div>

    <!-- ===== Notifications ===== -->
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined">notifications</span>
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
            <p class="page-note" style="margin-top:1rem;">
                These control in-app alerts only. System emails required for the review
                workflow are always sent.
            </p>
        </div>
    </div>

    <!-- ===== Security ===== -->
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined">lock</span>
            <h2>Security</h2>
        </div>
        <div class="page-card-body">
            <form class="pw-form" method="post" action="settings.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="page-field">
                    <label for="current_password">Current password</label>
                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                </div>
                <div class="page-field">
                    <label for="new_password">New password</label>
                    <input type="password" name="new_password" id="new_password" required minlength="8" autocomplete="new-password">
                    <p class="pw-hint">At least 8 characters.</p>
                </div>
                <div class="page-field">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn-page">Update password</button>
            </form>
        </div>
    </div>

    </div><!-- /.page-shell -->

</div>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function () {
    function getStored(key, fallback) {
        try { return localStorage.getItem(key) || fallback; } catch (err) { return fallback; }
    }

    // "System" has to be resolved against the device before CSS can use it.
    function resolveMode(theme) {
        if (theme === 'dark' || theme === 'light') return theme;
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark' : 'light';
    }

    // Appearance — shares the same storage keys as the browse page's
    // Quick Settings panel, so the two stay in sync.
    var density = getStored('papel_density', 'default');
    var theme = getStored('papel_theme', 'light');
    document.documentElement.setAttribute('data-density', density);
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-mode', resolveMode(theme));
    document.querySelectorAll('input[name="qs_density"]').forEach(function (i) { i.checked = (i.value === density); });
    document.querySelectorAll('input[name="qs_theme"]').forEach(function (i) { i.checked = (i.value === theme); });

    /* Colour. The same five palettes and the same storage key the Quick
       Settings panel uses, so changing it in either place is the same act. */
    var COLOURS = [
        ['maroon', 'PUP Maroon'], ['lightblue', 'PUP Light Blue'], ['blue', 'PUP Blue'],
        ['white', 'PUP White Modern'], ['classic', 'PUP Old Classic']
    ];
    var colour = getStored('papel_color', 'maroon');
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

    document.addEventListener('change', function (e) {
        var input = e.target;
        if (input.name === 'qs_density') {
            document.documentElement.setAttribute('data-density', input.value);
            try { localStorage.setItem('papel_density', input.value); } catch (err) {}
        } else if (input.name === 'qs_theme') {
            document.documentElement.setAttribute('data-theme', input.value);
            document.documentElement.setAttribute('data-mode', resolveMode(input.value));
            try { localStorage.setItem('papel_theme', input.value); } catch (err) {}
        } else if (input.name === 'qs_color') {
            document.documentElement.setAttribute('data-color', input.value);
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
            // opened — which is why the button appeared to do nothing.
            e.stopPropagation();
            if (window.papelAccessibility) {
                window.papelAccessibility.open();
            } else {
                var toggle = document.getElementById('a11y-toggle');
                if (toggle) toggle.click();
            }
        });
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
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
