<?php
require_once '../../config/core.php';
require_once '../../app/models/AdminManagementService.php';
require_role(['super_admin']);
$conn = db();
$u = current_user();

$adminService = new AdminManagementService($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        /* The Drive folder is set on its own page — Storage Folder in the nav —
           so this page no longer carries a second copy of that form. */
        if ($action === 'toggle_active') {
            $user_id = (int) ($_POST['user_id'] ?? 0);
            if ($user_id > 0 && $adminService->toggleStatus($user_id))
                flash('success', 'User status updated.');
        } elseif ($action === 'delete_user') {
            $user_id = (int) ($_POST['user_id'] ?? 0);
            if ($user_id > 0 && $adminService->deleteUser($user_id))
                flash('success', 'User deleted permanently.');
            else
                flash('error', 'User not found.');
        } elseif ($action === 'reset_password') {
            $user_id = (int) ($_POST['user_id'] ?? 0);
            $new_pass = $_POST['new_password'] ?? '';
            $before = account_snapshot($user_id);
            if ($user_id > 0 && $new_pass && $adminService->resetPassword($user_id, $new_pass)) {
                flash('success', 'Password reset successfully.');
                // Answered: whatever was asked about this password is settled.
                support_requests_clear($user_id, 'password');
                // And they are told, with the password they now have to use.
                account_change_notice($user_id, $before, account_snapshot($user_id),
                                      $new_pass, (string)($u['full_name'] ?? ''));
                // The only time this value is ever shown; nothing stores it.
                flash('new_password', json_encode([
                    'who' => trim($_POST['full_name'] ?? ''), 'pw' => $new_pass]));
            } else {
                flash('error', 'Failed to reset password.');
            }
        } elseif ($action === 'update_user') {
            /* Same panel, same fields — only the action underneath it changed.
               A blank password means "leave theirs alone". */
            $edited = (int) ($_POST['user_id'] ?? 0);
            $before = account_snapshot($edited);
            $adminService->updateAdmin($_POST, $edited);
            flash('success', trim($_POST['full_name'] ?? 'That account') . "'s details were saved.");
            // Answered: the details asked about have been saved.
            support_requests_clear($edited, 'account');
            account_change_notice($edited, $before, account_snapshot($edited),
                                  trim((string)($_POST['password'] ?? '')),
                                  (string)($u['full_name'] ?? ''));
        } elseif ($action === 'create_user') {
            /* The position chosen on the form decides the role and the level;
               the birthdate column is set to null by createAdmin itself. */
            $msg = $adminService->createAdmin($_POST, $u['user_id']);
            flash('success', $msg);
            flash('new_password', json_encode([
                'who' => trim($_POST['full_name'] ?? ''), 'pw' => $_POST['password'] ?? '']));
        }
    } catch (InvalidArgumentException $e) {
        /* Something about what was typed. These messages are written for the
           person filling the form in, so they are shown as they are. Anything
           else is still swallowed and logged. */
        flash('error', $e->getMessage());
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Cannot delete') !== false)
            flash('error', 'Cannot delete user. They may have associated data.');
        else {
            error_log('Super admin manage error: ' . $e->getMessage());
            flash('error', 'An unexpected error occurred. Please try again.');
        }
    }
    header('Location: super_admin_manage_admins.php');
    exit;
}

/* The Director fills three positions. What the database stores for each is in
   staff_positions(); the Head of Academic Programs is listed from both kinds of
   record, because head_academic and an admin at level 2 mean the same job. */
$OFFERED = ['Research Coordinator', 'Head of Academic Affairs', 'Librarian'];
$byPosition = $adminService->getStaffByPosition($OFFERED);

/**
 * One position's accounts, in the shape the other management pages use.
 *
 * Every position is listed the same way; an archived account keeps its position
 * and is marked on the row, so a status column is not needed to say so.
 */
function admin_table(array $rows, string $position): void {
    ?>
    <div class="mgmt-panel">
        <div class="mgmt-scroll">
            <table class="mgmt-table">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th><?= e($position) ?> ID</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="3">
                            <div class="mgmt-empty">
                                <span class="material-symbols-outlined">badge</span>
                                No <?= e($position) ?> accounts yet. Create one on the left.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php $off = !(int)$r['is_active']; ?>
                    <tr class="<?= $off ? 'mgmt-row-off' : '' ?>"
                        data-user-id="<?= (int)$r['user_id'] ?>"
                        data-full-name="<?= e($r['full_name']) ?>"
                        data-faculty-id="<?= e($r['faculty_id'] ?? '') ?>"
                        data-email="<?= e($r['email']) ?>"
                        data-position="<?= e(staff_position_of($r)) ?>">
                        <td class="mgmt-name">
                            <?= e($r['full_name']) ?>
                            <?php if ($off): ?><span class="mgmt-tag">Archived</span><?php endif; ?>
                            <span class="mgmt-sub" title="<?= e($r['email']) ?>"><?= e($r['email']) ?></span>
                        </td>
                        <td class="mgmt-id">
                            <?= e($r['faculty_id'] ?: '—') ?>
                            <span class="mgmt-sub">Added <?= e(date('M j, Y', strtotime($r['created_at']))) ?></span>
                        </td>
                        <td>
                            <div class="mgmt-actions">
                                <?php if ($off): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                        <button class="mgmt-act"
                                                data-confirm="Restore <?= e($r['full_name']) ?>? They will be able to sign in again.">Restore
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="mgmt-act js-edit">Edit</button>
                                    <button class="mgmt-act" data-bs-toggle="modal"
                                            data-bs-target="#resetModal<?= (int)$r['user_id'] ?>">Reset
                                    </button>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                        <button class="mgmt-act"
                                                data-confirm="Archive <?= e($r['full_name']) ?>? They will not be able to sign in until you restore them.">Archive
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                    <button class="mgmt-act is-danger"
                                            data-confirm="Permanently delete <?= e($r['full_name']) ?>? This cannot be undone.">Delete
                                    </button>
                                </form>
                            </div>

                            <?php if (!$off): ?>
                            <div class="modal fade" id="resetModal<?= (int)$r['user_id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reset password for <?= e($r['full_name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                            <div class="modal-body">
                                                <div class="mgmt-field">
                                                    <label>New password</label>
                                                    <input type="text" name="new_password" minlength="6"
                                                           placeholder="Min 6 chars, 1 uppercase, 1 number" required>
                                                    <small class="mgmt-hint">
                                                        <?= e($r['full_name']) ?> will need this to sign in. Tell them
                                                        yourself. Resetting does not email it.
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn-sm-outline" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn-sm-maroon">Reset password</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
/* Who on this roll has changed their own password. The tab that shows it is
   shared with the other two consoles, so the columns and the wording cannot
   drift apart between them. */
require_once ROOT_PATH.'/includes/password_audit_tab.php';
/* Every one of them: the Director is the desk of last resort, and is the
   only reader with nobody above them to escalate to. */
$password_rows = password_audit_rows(['admin', 'head_academic', 'librarian']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Admins · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_page.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e(role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Manage Admins</span>
    </div>
</div>

<main class="wrap mgmt-wrap">

    <div class="mgmt-head">
        <h1>Manage Admins</h1>
        <p>Create and manage Research Coordinator, Head of Academic Programs and Librarian accounts.</p>
    </div>

        <?php flash_banner(); ?>
    <?php
    /* The new password, shown once and only here. Reads through flash(), so a
       refresh does not bring it back. */
    require ROOT_PATH.'/includes/password_once.php';
    ?>

    <div class="mgmt-grid">

        <!-- ============ Create / edit ============ -->
        <?php /* No heading row, as on My Students: the page title says what
                 this is for, and in edit mode the note at the top of the form
                 names who is being edited. */ ?>
        <section class="mgmt-panel" id="formPanel" aria-label="Staff account form">
            <div class="mgmt-panel-body">
                <form method="post" class="js-manage-form" id="adminForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="formAction" value="create_user">
                    <input type="hidden" name="user_id" id="formUserId" value="">

                    <div class="mgmt-editing-note">
                        <span class="material-symbols-outlined">edit</span>
                        <span>Editing <strong id="editingWho"></strong>. Leave the password blank to keep the
                              one they already have.</span>
                    </div>

                    <div class="mgmt-field">
                        <label for="position">Position <span class="req">*</span></label>
                        <select name="position" id="position" required>
                            <option value="">Select position</option>
                            <?php foreach ($OFFERED as $pos): ?>
                                <option value="<?= e($pos) ?>"><?= e($pos) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="mgmt-hint">
                            The Research Coordinator approves papers; the Head of Academic Programs
                            and the Librarian read what is published.
                        </small>
                    </div>

                    <div class="mgmt-field">
                        <label for="full_name">Full name <span class="req">*</span></label>
                        <input type="text" name="full_name" id="full_name"
                               placeholder="e.g. Dr. Maria Santos" required>
                    </div>

                    <!-- The label names the role being created, and follows the
                         level chosen above it — the same box is a Coordinator's
                         ID or a HAP's depending on that answer. -->
                    <div class="mgmt-field">
                        <label for="faculty_id">
                            <span id="idLabel">Research Coordinator ID</span> <span class="req">*</span>
                        </label>
                        <input type="text" name="faculty_id" id="faculty_id"
                               placeholder="e.g. COORDINATOR-01" required>
                        <small class="mgmt-hint">This is what they sign in with.</small>
                    </div>

                    <div class="mgmt-field">
                        <label for="email">Email <span class="req">*</span></label>
                        <input type="email" name="email" id="email" placeholder="admin@example.com" required>
                    </div>

                    <div class="mgmt-field">
                        <label for="password">Password <span class="req" id="passReq">*</span></label>
                        <div class="mgmt-with-btn">
                            <input type="text" name="password" id="password" minlength="6"
                                   placeholder="Min 6 chars, 1 uppercase, 1 number" required>
                            <button type="button" class="mgmt-act" id="generatePasswordBtn">
                                <span class="material-symbols-outlined mi-18">autorenew</span> Generate
                            </button>
                        </div>
                        <small class="mgmt-hint" id="passHint">At least one uppercase letter and one number.</small>
                    </div>

                    <div class="mgmt-form-actions">
                        <button type="submit" class="btn-sm-maroon mgmt-submit" id="formSubmit">
                            <span class="material-symbols-outlined mi-18" id="formSubmitIcon">add</span>
                            <span id="formSubmitText">Create account</span>
                        </button>
                        <button type="button" class="btn-sm-outline mgmt-submit" id="formCancel" hidden>
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- ============ Roll ============
             Split by level rather than by status, because that is the question
             asked of this page. An archived account keeps its level and says so
             on the row instead. -->
        <section>
            <div class="mgmt-tabs" role="tablist">
                <?php $first = true; foreach ($OFFERED as $i => $pos): ?>
                    <button type="button" class="mgmt-tab <?= $first ? 'is-on' : '' ?>"
                            data-pane="pane<?= $i ?>" role="tab">
                        <?= e($pos === 'Head of Academic Affairs' ? 'Head of Academic Programs' : $pos) ?>
                        <span class="count"><?= count($byPosition[$pos]) ?></span>
                    </button>
                <?php $first = false; endforeach; ?>
                <?php password_audit_tab($password_rows); ?>

                <span class="mgmt-sort" id="sortChips">
                    <span class="mgmt-chips-label">Sort</span>
                    <button type="button" class="mgmt-chip is-on" data-sort="newest">Newest</button>
                    <button type="button" class="mgmt-chip" data-sort="az">A&ndash;Z</button>
                    <button type="button" class="mgmt-chip" data-sort="za">Z&ndash;A</button>
                </span>
            </div>

            <?php foreach ($OFFERED as $i => $pos): ?>
                <div id="pane<?= $i ?>" class="js-pane" <?= $i === 0 ? '' : 'hidden' ?>>
                    <?php admin_table($byPosition[$pos], $pos); ?>
                </div>
            <?php endforeach; ?>

            <?php password_audit_pane($password_rows, 'staff'); ?>
        </section>

    </div>
</main>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

    /* The Generate button lives in includes/password_generator.php — it needs
       the name and ID fields, which are shared by all three create forms. */

    /* The ID field names whichever role is being created, so it re-labels when
       the level changes — and when a row is loaded for editing. */
    var positionSelect = document.getElementById('position');
    var idLabel = document.getElementById('idLabel');
    var idInput = document.getElementById('faculty_id');

    /* The ID box names whichever position is being filled. */
    var ID_LABELS = {
        'Research Coordinator':     ['Research Coordinator ID', 'e.g. COORDINATOR-01'],
        'Head of Academic Affairs': ['Head of Academic Programs ID', 'e.g. HAP-2026-001'],
        'Librarian':                ['Librarian ID', 'e.g. LIB-2026-001']
    };
    function relabelId() {
        var pair = ID_LABELS[positionSelect.value] || ['Staff ID', 'e.g. STF-2026-001'];
        idLabel.textContent = pair[0];
        idInput.placeholder = pair[1];
    }
    positionSelect.addEventListener('change', relabelId);

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

    /* ---- Sorting ----
       One control on the tab row, ordering both levels at once. */
    function wirePane(pane) {
        var body = pane.querySelector('tbody');
        var rows = [].slice.call(pane.querySelectorAll('tbody tr[data-full-name]'));
        rows.forEach(function (row, i) { row.dataset.rank = i; });   // as sent: newest first

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

    /* ---- Edit mode ---- */
    var form   = document.getElementById('adminForm');
    var panel  = document.getElementById('formPanel');
    var cancel = document.getElementById('formCancel');
    var pw     = document.getElementById('password');

    function toCreateMode() {
        panel.classList.remove('is-editing');
        document.getElementById('formSubmitIcon').textContent = 'add';
        document.getElementById('formSubmitText').textContent = 'Create account';
        document.getElementById('passReq').hidden = false;
        document.getElementById('passHint').textContent =
            'At least one uppercase letter and one number.';
        pw.required = true;
        pw.placeholder = 'Min 6 chars, 1 uppercase, 1 number';
        cancel.hidden = true;
        form.reset();
        // form.reset() restores the markup's values, not these — set them after.
        document.getElementById('formAction').value = 'create_user';
        document.getElementById('formUserId').value = '';
        relabelId();          // the level went back to blank with the reset
        document.querySelectorAll('tr.is-editing')
                .forEach(function (r) { r.classList.remove('is-editing'); });
    }

    function toEditMode(row) {
        var d = row.dataset;
        document.getElementById('full_name').value   = d.fullName || '';
        document.getElementById('faculty_id').value  = d.facultyId || '';
        document.getElementById('email').value       = d.email || '';
        positionSelect.value = d.position || '';
        relabelId();
        pw.value = '';

        document.getElementById('formAction').value = 'update_user';
        document.getElementById('formUserId').value = d.userId;
        document.getElementById('formSubmitIcon').textContent = 'save';
        document.getElementById('formSubmitText').textContent = 'Save changes';
        document.getElementById('editingWho').textContent = d.fullName || 'this account';
        // A blank password here means "keep the current one", so it cannot be required.
        document.getElementById('passReq').hidden = true;
        document.getElementById('passHint').textContent =
            'Leave blank to keep their current password.';
        pw.required = false;
        pw.placeholder = 'Unchanged';
        cancel.hidden = false;
        panel.classList.add('is-editing');

        document.querySelectorAll('tr.is-editing')
                .forEach(function (r) { r.classList.remove('is-editing'); });
        row.classList.add('is-editing');

        // Remember what the row looked like, so Save can say what changed.
        if (window.papelEditSnapshot) { window.papelEditSnapshot(form); }
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById('full_name').focus();
    }

    document.querySelectorAll('.js-edit').forEach(function (btn) {
        btn.addEventListener('click', function () { toEditMode(btn.closest('tr')); });
    });
    cancel.addEventListener('click', toCreateMode);
});
</script>
<?php require ROOT_PATH.'/includes/password_generator.php';
require_once ROOT_PATH.'/includes/action_dialogs.php';
require ROOT_PATH.'/includes/manage_save_confirm.php';
require ROOT_PATH.'/includes/scroll_jump.php';
require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>