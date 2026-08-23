<?php
/**
 * Guest passes, the Librarian's desk.
 *
 * Was app/guest/admin_manage_guests.php, which named it for a role that does
 * not own it: the Research Coordinator and the Director can reach it, but this
 * is the Librarian's job and their home page.
 *
 * Built on the same shell as the other three management consoles rather than
 * the Bootstrap markup it carried before, so the fonts, colours, tables,
 * tabs, sort control, flashes and dialogs are the shared ones and follow the
 * theme.
 *
 * A guest pass is unlike the accounts on the other consoles in two ways, and
 * the page is shaped around that: it has no name to edit, so there is no edit
 * mode, and its password is deliberately readable. A pass is a shared,
 * expiring credential the Librarian reads out or emails, not a personal
 * account, which is why guest_sessions keeps plain_password when the users
 * table no longer does.
 */
require_once '../../config/core.php';
require_role(['admin', 'super_admin', 'librarian']);
$conn = db();
$u = current_user();

const GUEST_MIN_HOURS = 1;
const GUEST_MAX_HOURS = 24;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_guest') {
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'A valid email address is required to send the credentials.');
            header('Location: librarian_manage_guests.php');
            exit;
        }

        $duration = (int)($_POST['duration'] ?? 2);
        $duration = max(GUEST_MIN_HOURS, min(GUEST_MAX_HOURS, $duration));

        $username = 'guest_' . bin2hex(random_bytes(4));

        /* No I, l, O or 0: this is read out loud and typed by hand more often
           than it is copied. */
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $hash       = password_hash($password, PASSWORD_DEFAULT);
        $expires_at = date('Y-m-d H:i:s', strtotime("+$duration hours"));

        $stmt = $conn->prepare(
            "INSERT INTO guest_sessions (username, password, plain_password, expires_at)
             VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $username, $hash, $password, $expires_at);

        if ($stmt->execute()) {
            $body  = email_para('Good day,');
            $body .= email_para('You have been granted temporary guest access to ' . APP_NAME
                   . ', the research repository of PUP Biñan Campus. Guest access is read only '
                   . 'and expires on the date shown below.');
            $body .= email_details([
                'Username'    => $username,
                'Password'    => $password,
                'Valid until' => date('F j, Y g:i A', strtotime($expires_at)),
            ], ['Username', 'Password']);
            $body .= email_action('Sign in at', BASE_URL . '/archive/login.php');

            $sent = send_email($email, 'Guest access to ' . APP_NAME, $body);

            flash('success', $sent
                ? 'Guest pass created and sent to ' . $email . '.'
                : 'Guest pass created, but the email to ' . $email . ' could not be sent. '
                  . 'Pass the credentials on yourself.');
            /* Shown once beneath the flash, the same block the other consoles
               use. The pass is listed in the table as well, so this is a
               convenience rather than the only sighting. */
            flash('new_password', json_encode([
                'who'  => $username,
                'pw'   => $password,
                'lost' => 'If it is lost, revoke this pass and issue a new one.',
            ]));
        } else {
            flash('error', 'That guest pass could not be created. Please try again.');
        }
        header('Location: librarian_manage_guests.php');
        exit;
    }

    if ($action === 'delete_guest') {
        $guest_id = (int)($_POST['guest_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM guest_sessions WHERE guest_id = ?");
        $stmt->bind_param('i', $guest_id);
        $stmt->execute();
        flash('success', $stmt->affected_rows > 0
            ? 'Guest pass revoked.'
            : 'That guest pass could not be found.');
        header('Location: librarian_manage_guests.php');
        exit;
    }

    if ($action === 'cleanup') {
        $conn->query("DELETE FROM guest_sessions WHERE expires_at < NOW()");
        $gone = $conn->affected_rows;
        flash('success', $gone > 0
            ? $gone . ' expired pass' . ($gone === 1 ? '' : 'es') . ' cleared.'
            : 'There was nothing expired to clear.');
        header('Location: librarian_manage_guests.php');
        exit;
    }
}

/* Read into arrays rather than holding the results open: each list is walked
   for its count before the tables are drawn. */
$active  = $conn->query("SELECT * FROM guest_sessions WHERE expires_at >= NOW() ORDER BY created_at DESC")
                ->fetch_all(MYSQLI_ASSOC);
$expired = $conn->query("SELECT * FROM guest_sessions WHERE expires_at <  NOW() ORDER BY created_at DESC")
                ->fetch_all(MYSQLI_ASSOC);

/** How much of a pass is left, in words. */
function guest_time_left(string $expiresAt): string {
    $left = strtotime($expiresAt) - time();
    if ($left <= 0) return 'Expired';
    $h = intdiv($left, 3600);
    $m = intdiv($left % 3600, 60);
    if ($h >= 24) { $d = intdiv($h, 24); return $d . ' day' . ($d === 1 ? '' : 's') . ' left'; }
    if ($h > 0)   return $h . 'h ' . $m . 'm left';
    return $m . 'm left';
}

/**
 * One tab's worth of passes, in the shape the other consoles use: the pass and
 * when it was made together, then the credential, then when it runs out, then
 * borderless word actions.
 */
function guest_table(array $rows, string $which): void {
    ?>
    <div class="mgmt-panel">
        <div class="mgmt-scroll">
            <table class="mgmt-table">
                <thead>
                    <tr>
                        <th>Guest pass</th>
                        <th>Password</th>
                        <th><?= $which === 'expired' ? 'Expired' : 'Expires' ?></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="4">
                            <div class="mgmt-empty">
                                <span class="material-symbols-outlined">
                                    <?= $which === 'expired' ? 'history' : 'badge' ?>
                                </span>
                                <?= $which === 'expired'
                                        ? 'Nothing has expired yet.'
                                        : 'No guest passes are active. Create one on the left.' ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $which === 'expired' ? 'mgmt-row-off' : '' ?>"
                        data-guest-id="<?= (int)$r['guest_id'] ?>"
                        data-full-name="<?= e($r['username']) ?>">
                        <td class="mgmt-name">
                            <?= e($r['username']) ?>
                            <span class="mgmt-sub">
                                Issued <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?>
                            </span>
                        </td>
                        <td class="mgmt-pass"><?= e($r['plain_password']) ?></td>
                        <td class="mgmt-id">
                            <?= e(date('M j, Y g:i A', strtotime($r['expires_at']))) ?>
                            <span class="mgmt-sub <?= $which === 'expired' ? 'is-lapsed' : '' ?>">
                                <?= e(guest_time_left($r['expires_at'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="mgmt-actions">
                                <form method="post">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_guest">
                                    <input type="hidden" name="guest_id" value="<?= (int)$r['guest_id'] ?>">
                                    <button class="mgmt-act is-danger"
                                            data-confirm="<?= $which === 'expired'
                                                ? 'Delete this expired pass? This cannot be undone.'
                                                : 'Revoke ' . e($r['username']) . '? They will be signed out and the pass will stop working.' ?>">
                                        <?= $which === 'expired' ? 'Delete' : 'Revoke' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Guest Passes · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_page.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
<style nonce="<?= csp_nonce() ?>">
/* A guest password is meant to be read off the screen, so it is set in the
   same monospaced face the one-time password block uses. */
.mgmt-pass {
    font-family: ui-monospace, SFMono-Regular, Consolas, Menlo, monospace;
    font-size: .8125rem; color: var(--ink);
}
.mgmt-row-off .mgmt-pass { color: var(--grey); }

/* The title and the page's one action, on a row that wraps rather than
   squeezing the description on a narrow screen. */
.mgmt-head-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.mgmt-head-action {
    display: inline-flex;
    align-items: center;
    gap: .375rem;
    flex: 0 0 auto;
    text-decoration: none;
}

/* Both of these were style attributes until it turned out the page's CSP drops
   them: style-src carries no 'unsafe-inline', so a style attribute is ignored
   exactly as an unnonced <style> element would be. Anything positional has to
   live in here. */

/* Clearing expired passes empties the table underneath, so the button sits
   above it as its own bar. The gap is the point: flush against the table it
   reads as the header row rather than as something acting on the whole table. */
.guest-sweep { margin-bottom: 1rem; }

/* The shared stylesheet keeps this note hidden until a panel is in edit mode.
   Guest passes cannot be edited, only issued and revoked, so that state never
   arrives here and the note stayed invisible. What it says is true whenever the
   form is on screen, so here it simply always shows. */
#formPanel .mgmt-editing-note { display: flex; }
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Guest Passes</span>
    </div>
</div>

<main class="wrap mgmt-wrap">

    <div class="mgmt-head">
        <div class="mgmt-head-row">
            <div>
                <h1>Guest Passes</h1>
                <p>Issue temporary, read-only access to the repository for visitors and external researchers.</p>
            </div>
            <?php /* This desk has no sidebar of links, so the one other thing a
                     Librarian can do lives here beside the title. */ ?>
            <a class="btn-sm-outline mgmt-head-action"
               href="<?= e(BASE_URL) ?>/app/student/student_upload_ai.php"
               title="Add a paper of your own. It is published straight away, with no review">
                <span class="material-symbols-outlined mi-18">upload_file</span>
                <span>Upload Paper</span>
            </a>
        </div>
    </div>

        <?php flash_banner(); ?>
    <?php require ROOT_PATH.'/includes/password_once.php'; ?>

    <div class="mgmt-grid">

        <!-- ============ Issue a pass ============ -->
        <section class="mgmt-panel" id="formPanel">
            <div class="mgmt-panel-head">
                <span class="material-symbols-outlined">badge</span>
                <span>New guest pass</span>
            </div>
            <div class="mgmt-panel-body">
                <form method="post" id="guestForm">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="create_guest">

                    <div class="mgmt-field">
                        <label for="email">Email <span class="req">*</span></label>
                        <input type="email" name="email" id="email"
                               placeholder="visitor@example.com" required>
                        <small class="mgmt-hint">The pass is sent here. It is not stored on the account.</small>
                    </div>

                    <div class="mgmt-field">
                        <label for="duration">Valid for <span class="req">*</span></label>
                        <select name="duration" id="duration" required>
                            <option value="1">1 hour</option>
                            <option value="2" selected>2 hours</option>
                            <option value="4">4 hours</option>
                            <option value="8">8 hours</option>
                            <option value="12">12 hours</option>
                            <option value="24">24 hours</option>
                        </select>
                        <small class="mgmt-hint">The pass stops working on its own when this runs out.</small>
                    </div>

                    <div class="mgmt-field">
                        <div class="mgmt-editing-note">
                            <span class="material-symbols-outlined">visibility</span>
                            <span>A guest can read published papers only. They cannot open a
                                  manuscript, submit, or see anything under review.</span>
                        </div>
                    </div>

                    <div class="mgmt-form-actions">
                        <button type="submit" class="btn-sm-maroon mgmt-submit">
                            <span class="material-symbols-outlined mi-18">add</span>
                            <span>Create pass</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- ============ The passes ============ -->
        <section>
            <div class="mgmt-tabs" role="tablist">
                <button type="button" class="mgmt-tab is-on" data-pane="activePane" role="tab">
                    Active passes
                    <span class="count"><?= count($active) ?></span>
                </button>
                <button type="button" class="mgmt-tab" data-pane="expiredPane" role="tab">
                    Expired
                    <span class="count"><?= count($expired) ?></span>
                </button>

                <span class="mgmt-sort" id="sortChips">
                    <span class="mgmt-chips-label">Sort</span>
                    <button type="button" class="mgmt-chip is-on" data-sort="newest">Newest</button>
                    <button type="button" class="mgmt-chip" data-sort="az">A&ndash;Z</button>
                    <button type="button" class="mgmt-chip" data-sort="za">Z&ndash;A</button>
                </span>
            </div>

            <div id="activePane" class="js-pane">
                <?php guest_table($active, 'active'); ?>
            </div>

            <div id="expiredPane" class="js-pane" hidden>
                <?php if ($expired): ?>
                    <div class="mgmt-form-actions guest-sweep">
                        <form method="post">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="cleanup">
                            <button class="btn-sm-outline mgmt-submit"
                                    data-confirm="Clear every expired pass? This cannot be undone.">
                                <span class="material-symbols-outlined mi-18">delete_sweep</span>
                                <span>Clear expired</span>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
                <?php guest_table($expired, 'expired'); ?>
            </div>
        </section>

    </div>
</main>

<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function () {

    // Tabs
    var tabs = document.querySelectorAll('.mgmt-tab');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                t.classList.toggle('is-on', t === tab);
                document.getElementById(t.dataset.pane).hidden = (t !== tab);
            });
        });
    });

    /* Sorting, the same control the other consoles carry: one set of chips on
       the tab row, ordering both tabs at once. */
    function wirePane(pane) {
        var body = pane.querySelector('tbody');
        if (!body) { return function () {}; }
        var rows = [].slice.call(pane.querySelectorAll('tbody tr[data-full-name]'));
        rows.forEach(function (row, i) { row.dataset.rank = i; });   // newest first, as sent

        return function (sort) {
            rows.slice().sort(function (a, b) {
                if (sort === 'newest') { return (+a.dataset.rank) - (+b.dataset.rank); }
                var cmp = (a.dataset.fullName || '').toLowerCase()
                          .localeCompare((b.dataset.fullName || '').toLowerCase());
                return sort === 'az' ? cmp : -cmp;
            }).forEach(function (row) { body.appendChild(row); });
        };
    }

    var sorters = [].map.call(document.querySelectorAll('.js-pane'), wirePane);
    var sortChips = document.querySelectorAll('#sortChips .mgmt-chip');
    sortChips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            sortChips.forEach(function (c) { c.classList.toggle('is-on', c === chip); });
            sorters.forEach(function (setSort) { setSort(chip.dataset.sort); });
        });
    });
});
</script>
<?php
require_once ROOT_PATH.'/includes/action_dialogs.php';
require ROOT_PATH.'/includes/scroll_jump.php';
require ROOT_PATH.'/includes/site_footer.php';
?>
</body>
</html>
