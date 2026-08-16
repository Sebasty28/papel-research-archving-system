<?php
require_once '../../config/core.php';
require_once '../models/FacultyManagementService.php';
require_role(['admin']);
$conn=db();
$u=current_user();

$facultyService = new FacultyManagementService($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';
  
  try {
      if ($action === 'toggle_active') {
          $user_id = (int)($_POST['user_id'] ?? 0);
          if ($user_id> 0 && $facultyService->toggleFacultyStatus($user_id)) {
              flash('success', 'User status updated.');
          }
      } elseif ($action === 'delete_user') {
          $user_id = (int)($_POST['user_id'] ?? 0);
          if ($user_id> 0) {
              if ($facultyService->deleteFaculty($user_id)) flash('success', 'User deleted permanently.');
              else flash('error', 'User not found.');
          }
      } elseif ($action === 'update_user') {
          /* Same panel, same fields — only the action underneath it changed.
             A blank password means "leave theirs alone". */
          $message = $facultyService->updateFaculty([
              'full_name'  => $_POST['full_name'] ?? '',
              'email'      => $_POST['email'] ?? '',
              'faculty_id' => $_POST['faculty_id'] ?? '',
              'password'   => $_POST['password'] ?? '',
          ], (int)($_POST['user_id'] ?? 0));
          flash($message ? 'success' : 'error',
                $message ? trim($_POST['full_name'] ?? 'That adviser') . "'s details were saved."
                         : 'Could not save those changes. Please try again.');
      } elseif ($action === 'reset_password') {
          $user_id = (int)($_POST['user_id'] ?? 0);
          $new_pass = $_POST['new_password'] ?? '';
          if ($user_id> 0 && $new_pass) {
              if ($facultyService->resetFacultyPassword($user_id, $new_pass)) flash('success', 'Password reset successfully.');
              else flash('error', 'Failed to reset password.');
          }
      } else {
          // Create User Action
          $data = [
              'full_name' => trim($_POST['full_name'] ?? ''),
              'email' => trim($_POST['email'] ?? ''),
              'username' => trim($_POST['username'] ?? ''),
              'password' => $_POST['password'] ?? '',
              'title' => trim($_POST['title'] ?? ''),
              'faculty_id' => trim($_POST['faculty_id'] ?? ''),
              'birthdate' => trim($_POST['birthdate'] ?? '')
          ];
          $message = $facultyService->createFaculty($data, $u['user_id']);
          flash('success', $message);
      }
  } catch (InvalidArgumentException $e) {
      /* Something about what was typed. These messages are written for the
         person filling the form in, so they are shown as they are — a duplicate
         email saying "an unexpected error occurred" gives them nothing to act
         on. Anything else is still swallowed and logged. */
      flash('error', $e->getMessage());
  } catch (Exception $e) {
      if (strpos($e->getMessage(), 'Cannot delete') !== false) {
          flash('error', 'Cannot delete user. They may have associated data.');
      } else {
          error_log('Admin faculty management error: ' . $e->getMessage());
          flash('error', 'An unexpected error occurred. Please try again.');
    }
  }
  header('Location: admin_manage_faculty.php'); exit;
}

try {
  // Read into arrays: the roll is walked more than once, and the tab counts
  // have to be known before either table is drawn.
  $active   = $facultyService->getActiveFaculty()->fetch_all(MYSQLI_ASSOC);
  $archived = $facultyService->getInactiveFaculty()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
  if (strpos($e->getMessage(), "Unknown column 'plain_password'") !== false) {
    error_log('Database migration required: plain_password column is missing from users table. Run scripts/migrations/run_plain_password_migration.php to fix.');
    flash('error', 'A system update is required. Please contact your administrator.');
    header('Location: admin_manage_faculty.php'); exit;
  }
  throw $e;
}

/**
 * One tab's worth of advisers, in the same shape Manage Students uses: name and
 * email together, ID and the date it was made together, then borderless word
 * actions. The two tabs differ only in what those actions are.
 */
function staff_table(array $rows, string $which): void {
    ?>
    <div class="mgmt-panel">
        <div class="mgmt-scroll">
            <table class="mgmt-table">
                <thead>
                    <tr>
                        <th>Adviser</th>
                        <th>Faculty ID</th>
                        <th>Position</th>
                        <th>Password</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5">
                            <div class="mgmt-empty">
                                <span class="material-symbols-outlined">
                                    <?= $which === 'archived' ? 'archive' : 'badge' ?>
                                </span>
                                <?= $which === 'archived'
                                        ? 'Nothing archived.'
                                        : 'No adviser accounts yet. Create one on the left.' ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $which === 'archived' ? 'mgmt-row-off' : '' ?>"
                        data-user-id="<?= (int)$r['user_id'] ?>"
                        data-full-name="<?= e($r['full_name']) ?>"
                        data-faculty-id="<?= e($r['faculty_id'] ?? '') ?>"
                        data-email="<?= e($r['email']) ?>">
                        <td class="mgmt-name">
                            <?= e($r['full_name']) ?>
                            <span class="mgmt-sub" title="<?= e($r['email']) ?>"><?= e($r['email']) ?></span>
                        </td>
                        <td class="mgmt-id">
                            <?= e($r['faculty_id'] ?: '—') ?>
                            <span class="mgmt-sub">Added <?= e(date('M j, Y', strtotime($r['created_at']))) ?></span>
                        </td>
                        <td class="mgmt-prog"><?= e($r['title'] ?: 'Research Adviser') ?></td>
                        <td class="mgmt-pass"><?= e($r['plain_password'] ?? '—') ?></td>
                        <td>
                            <div class="mgmt-actions">
                                <?php if ($which === 'archived'): ?>
                                    <form method="post">
                                        <?= csrf_field(); ?>
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
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                        <button class="mgmt-act"
                                                data-confirm="Archive <?= e($r['full_name']) ?>? They will not be able to sign in until you restore them.">Archive
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="post">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                    <button class="mgmt-act is-danger"
                                            data-confirm="Permanently delete <?= e($r['full_name']) ?>? This cannot be undone.">Delete
                                    </button>
                                </form>
                            </div>

                            <?php if ($which !== 'archived'): ?>
                            <div class="modal fade" id="resetModal<?= (int)$r['user_id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reset password &mdash; <?= e($r['full_name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                                            <div class="modal-body">
                                                <div class="mgmt-field">
                                                    <label>New password</label>
                                                    <input type="text" name="new_password" minlength="6"
                                                           placeholder="Min 6 chars, 1 uppercase, 1 number" required>
                                                    <small class="mgmt-hint">
                                                        <?= e($r['full_name']) ?> will need this to sign in. Tell them
                                                        yourself &mdash; resetting does not email it.
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Staff · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_page.php'; ?>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e(role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Research Advisers</span>
    </div>
</div>

<main class="wrap mgmt-wrap">

    <div class="mgmt-head">
        <h1>Research Advisers</h1>
        <p>Create and manage the adviser accounts who review student research.</p>
    </div>

    <?php if ($m = flash('error')): ?>
        <div class="mgmt-flash is-bad" role="alert">
            <span class="material-symbols-outlined">error</span><span><?= e($m) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($m = flash('success')): ?>
        <div class="mgmt-flash is-good" role="status">
            <span class="material-symbols-outlined">check_circle</span><span><?= e($m) ?></span>
        </div>
    <?php endif; ?>

    <div class="mgmt-grid">

        <!-- ============ Create / edit ============
             One panel does both, exactly as on Manage Students. -->
        <section class="mgmt-panel" id="formPanel">
            <div class="mgmt-panel-head">
                <span class="material-symbols-outlined" id="formIcon">person_add</span>
                <span id="formTitle">New adviser account</span>
            </div>
            <div class="mgmt-panel-body">
                <form method="post" class="js-manage-form" id="staffForm">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="create_user">
                    <input type="hidden" name="user_id" id="formUserId" value="">
                    <!-- Every account made here is an adviser; the other staff roles
                         are created on the Director's Manage Admins page. -->
                    <input type="hidden" name="title" value="Research Adviser">

                    <div class="mgmt-editing-note">
                        <span class="material-symbols-outlined">edit</span>
                        <span>Editing <strong id="editingWho"></strong>. Leave the password blank to keep the
                              one they already have.</span>
                    </div>

                    <div class="mgmt-field">
                        <label for="full_name">Full name <span class="req">*</span></label>
                        <input type="text" name="full_name" id="full_name" pattern="[A-Za-z\s.]+"
                               title="Full name cannot contain numbers"
                               placeholder="e.g. Dr. Maria Santos" required>
                    </div>

                    <div class="mgmt-field">
                        <label for="faculty_id">Faculty ID <span class="req">*</span></label>
                        <input type="text" name="faculty_id" id="faculty_id"
                               placeholder="e.g. FAC-2026-001" required>
                        <small class="mgmt-hint">This is what they sign in with.</small>
                    </div>

                    <div class="mgmt-field">
                        <label for="email">Email <span class="req">*</span></label>
                        <input type="email" name="email" id="email" placeholder="adviser@example.com" required>
                    </div>

                    <div class="mgmt-field">
                        <label>Position</label>
                        <input type="text" value="Research Adviser" disabled>
                        <small class="mgmt-hint">
                            Coordinators, the Head of Academic Programs and Librarians are created by the Director.
                        </small>
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
                            <span id="formSubmitText">Create adviser account</span>
                        </button>
                        <button type="button" class="btn-sm-outline mgmt-submit" id="formCancel" hidden>
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- ============ Roll ============ -->
        <section>
            <div class="mgmt-tabs" role="tablist">
                <button type="button" class="mgmt-tab is-on" data-pane="activePane" role="tab">
                    Active advisers
                    <span class="count"><?= count($active) ?></span>
                </button>
                <button type="button" class="mgmt-tab" data-pane="archivedPane" role="tab">
                    Archived
                    <span class="count"><?= count($archived) ?></span>
                </button>

                <span class="mgmt-sort" id="sortChips">
                    <span class="mgmt-chips-label">Sort</span>
                    <button type="button" class="mgmt-chip is-on" data-sort="newest">Newest</button>
                    <button type="button" class="mgmt-chip" data-sort="az">A&ndash;Z</button>
                    <button type="button" class="mgmt-chip" data-sort="za">Z&ndash;A</button>
                </span>
            </div>

            <div id="activePane" class="js-pane">
                <?php staff_table($active, 'active'); ?>
            </div>

            <div id="archivedPane" class="js-pane" hidden>
                <?php staff_table($archived, 'archived'); ?>
            </div>
        </section>

    </div>
</main>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

    /* Twelve characters with an uppercase and a digit guaranteed, then shuffled
       so those two are not always in front. */
    var genBtn = document.getElementById('generatePasswordBtn');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            var upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
                digits = '0123456789',
                all = 'abcdefghijklmnopqrstuvwxyz' + upper + digits + '!@#$%',
                pick = function (s) { return s.charAt(Math.floor(Math.random() * s.length)); },
                pass = pick(upper) + pick(digits);
            for (var i = 2; i < 12; i++) { pass += pick(all); }
            document.getElementById('password').value =
                pass.split('').sort(function () { return Math.random() - 0.5; }).join('');
        });
    }

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
       There is no cohort to filter advisers by, so this page takes the sort
       control from Manage Students without the chip steps beneath it. One
       control on the tab row, ordering both tabs at once. */
    function wirePane(pane) {
        var body = pane.querySelector('tbody');
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

    /* ---- Edit mode ----
       The panel on the left is the only form there is, so editing fills it in
       and changes what it posts to. Cancel puts it back exactly as it was. */
    var form   = document.getElementById('staffForm');
    var panel  = document.getElementById('formPanel');
    var cancel = document.getElementById('formCancel');
    var pw     = document.getElementById('password');

    function toCreateMode() {
        panel.classList.remove('is-editing');
        document.getElementById('formTitle').textContent = 'New adviser account';
        document.getElementById('formIcon').textContent = 'person_add';
        document.getElementById('formSubmitIcon').textContent = 'add';
        document.getElementById('formSubmitText').textContent = 'Create adviser account';
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
        document.querySelectorAll('tr.is-editing')
                .forEach(function (r) { r.classList.remove('is-editing'); });
    }

    function toEditMode(row) {
        var d = row.dataset;
        document.getElementById('full_name').value  = d.fullName || '';
        document.getElementById('faculty_id').value = d.facultyId || '';
        document.getElementById('email').value      = d.email || '';
        pw.value = '';

        document.getElementById('formAction').value = 'update_user';
        document.getElementById('formUserId').value = d.userId;
        document.getElementById('formTitle').textContent = 'Edit adviser account';
        document.getElementById('formIcon').textContent = 'edit';
        document.getElementById('formSubmitIcon').textContent = 'save';
        document.getElementById('formSubmitText').textContent = 'Save changes';
        document.getElementById('editingWho').textContent = d.fullName || 'this adviser';
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
<?php require ROOT_PATH.'/includes/action_dialogs.php';
require ROOT_PATH.'/includes/manage_save_confirm.php';
require ROOT_PATH.'/includes/scroll_jump.php';
require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>