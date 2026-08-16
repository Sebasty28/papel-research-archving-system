<?php
require_once '../../config/core.php';
require_once '../../config/gdrive_config.php';
require_role(['faculty']); $conn=db(); $u=current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  
  // Toggle active status
  if (isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id> 0) {
      $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id=? AND user_role='student'");
      $stmt->bind_param('i', $user_id);
      if ($stmt->execute() && $stmt->affected_rows> 0) {
        flash('success', 'User status updated.');
      }
    }
    header('Location: faculty_manage_students.php'); exit;
  }
  
  // Delete user action
  if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id> 0) {
      try {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND user_role='student'");
        $stmt->bind_param('i', $user_id);
        if ($stmt->execute() && $stmt->affected_rows> 0) { flash('success', 'User deleted permanently.'); }
        else { flash('error', 'User not found.'); }
      } catch (Exception $e) { flash('error', 'Cannot delete user. They may have associated data.'); }
    }
    header('Location: faculty_manage_students.php'); exit;
  }
  
  // Reset password action
  if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_pass = $_POST['new_password'] ?? '';
    
    if ($user_id> 0 && $new_pass) {
      if (strlen($new_pass) < 6 || !preg_match('/[A-Z]/', $new_pass) || !preg_match('/[0-9]/', $new_pass)) {
        flash('error', 'Password must be at least 6 characters and contain at least one uppercase letter and one number.');
        header('Location: faculty_manage_students.php'); exit;
      }
      $hash = password_hash($new_pass, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("UPDATE users SET password=?, plain_password=? WHERE user_id=? AND user_role='student'");
      $stmt->bind_param('ssi', $hash, $new_pass, $user_id);
      if ($stmt->execute() && $stmt->affected_rows> 0) {
        flash('success', 'Password reset successfully.');
      } else {
        flash('error', 'Failed to reset password.');
      }
    }
    header('Location: faculty_manage_students.php'); exit;
  }
  
  /* Edit an existing student.
     The same panel on the left does both jobs, so this shares the create form's
     fields — with two differences: the uniqueness checks have to ignore the row
     being edited, and a blank password means "leave it alone" rather than
     "reject this". */
  if (($_POST['action'] ?? '') === 'update_user') {
    $edit_id = (int)($_POST['user_id'] ?? 0);
    $full    = trim($_POST['full_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $sid     = trim($_POST['student_id'] ?? '');
    $ay      = substr(trim($_POST['academic_year'] ?? ''), 0, 20);
    $sect    = substr(trim($_POST['section'] ?? ''), 0, 40);
    $pass    = $_POST['password'] ?? '';

    $fail = function ($msg) {
      flash('error', $msg);
      header('Location: faculty_manage_students.php'); exit;
    };

    if (!$edit_id)                                  { $fail('That student could not be found.'); }
    if (!$full || !$email || !$program || !$ay || !$sect || !$sid) {
      $fail('Full name, Student ID, email, program, academic year and section are all needed.');
    }
    if (!preg_match('/^[A-Za-z\s.]+$/', $full))     { $fail('Full name cannot contain numbers.'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $fail('Invalid email format.'); }

    $check = $conn->prepare(
      "SELECT COUNT(*) AS cnt FROM users WHERE (email=? OR student_id=?) AND user_id<>?");
    $check->bind_param('ssi', $email, $sid, $edit_id);
    $check->execute();
    if ((int)$check->get_result()->fetch_assoc()['cnt'] > 0) {
      $fail('Another account already uses that email or Student ID.');
    }

    /* Moving someone up a year shortens what is left of their account, and
       moving their academic year shifts it — so the expiry is recomputed from
       whatever was just entered rather than left at whatever it was. */
    $expires = student_expiry_date($sect, $ay);

    if ($pass !== '') {
      if (strlen($pass) < 6 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
        $fail('Password must be at least 6 characters and contain at least one uppercase letter and one number.');
      }
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $up = $conn->prepare(
        "UPDATE users SET full_name=?, email=?, program=?, academic_year=?, section=?,
                          student_id=?, expires_on=?, password=?, plain_password=?
         WHERE user_id=? AND user_role='student'");
      $up->bind_param('sssssssssi', $full, $email, $program, $ay, $sect, $sid, $expires, $hash, $pass, $edit_id);
    } else {
      $up = $conn->prepare(
        "UPDATE users SET full_name=?, email=?, program=?, academic_year=?, section=?,
                          student_id=?, expires_on=?
         WHERE user_id=? AND user_role='student'");
      $up->bind_param('sssssssi', $full, $email, $program, $ay, $sect, $sid, $expires, $edit_id);
    }

    /* affected_rows is 0 when nothing actually changed, which is not a failure —
       only a statement that did not run is. */
    // The template escapes the flash on the way out, so it is stored as plain text.
    if ($up->execute()) { flash('success', $full . "'s details were saved."); }
    else                { flash('error', 'Could not save those changes. Please try again.'); }
    header('Location: faculty_manage_students.php'); exit;
  }

  // Create student action
  $full=trim($_POST['full_name']??''); $email=trim($_POST['email']??''); $usern='';   /* derived from the Student ID below - no longer asked for */ $pass=$_POST['password']??''; $program=trim($_POST['program']??''); $student_id=trim($_POST['student_id']??'');

  /* Year-and-section and the academic year it belongs to. The form suggests the
     usual values but does not limit them — a programme with its own naming (a
     ladderized intake, a lettered section) has to be able to say so. Only the
     length is enforced, so a stray paste cannot overflow the column. */
  $academic_year = substr(trim($_POST['academic_year'] ?? ''), 0, 20);
  $section       = substr(trim($_POST['section'] ?? ''), 0, 40);

  // Validate required fields
  if (!$full || !$email || !$pass || !$program || !$academic_year || !$section) {
    flash('error','Full name, Student ID, email, program, academic year, section and password are all needed.');
    header('Location: faculty_manage_students.php'); exit;
  }

  // Validate student_id if provided
  if ($student_id) {
    $validation = validate_student_id($student_id);
    if (!$validation['valid']) {
      flash('error', $validation['message']);
      header('Location: faculty_manage_students.php'); exit;
    }
    
    // Check if student_id already exists
    $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE student_id=?");
    $check_stmt->bind_param('s', $student_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row['cnt']> 0) {
      flash('error','Student ID already exists. Please use a different Student ID.');
      header('Location: faculty_manage_students.php'); exit;
    }
  }
  
  // Validate email format
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error','Invalid email format.');
    header('Location: faculty_manage_students.php'); exit;
  }
  
  /* The username is an internal handle now, not something anyone types. It is
     taken from the Student ID, reduced to the characters the column allows, so
     it stays unique for the same reason the Student ID is. */
  $usern = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $student_id));
  if ($usern === '') { $usern = 'user' . substr(bin2hex(random_bytes(4)), 0, 6); }
  $usern = substr($usern, 0, 30);
  
  // Validate password (min 6 chars, at least 1 uppercase, at least 1 number)
  if (strlen($pass) < 6 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
    flash('error','Password must be at least 6 characters and contain at least one uppercase letter and one number.');
    header('Location: faculty_manage_students.php'); exit;
  }
  
  // Check if username or email already exists
  $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE username=? OR email=?");
  $check_stmt->bind_param('ss', $usern, $email);
  $check_stmt->execute();
  $result = $check_stmt->get_result();
  $row = $result->fetch_assoc();
  if ($row['cnt']> 0) {
    flash('error','Username or email already exists.');
    header('Location: faculty_manage_students.php'); exit;
  }
  
  /* One statement either way: a missing Student ID goes in as NULL rather than
     an empty string, so the unique index still allows more than one of them. */
  $hash    = password_hash($pass, PASSWORD_DEFAULT);
  $sid     = $student_id !== '' ? $student_id : null;
  // How long they will need it, worked out from the section they are in.
  $expires = student_expiry_date($section, $academic_year);
  $stmt = $conn->prepare(
    "INSERT INTO users (username,email,password,plain_password,full_name,program,academic_year,section,expires_on,student_id,user_role,created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,'student',?)");
  $stmt->bind_param('ssssssssssi', $usern, $email, $hash, $pass, $full, $program,
                    $academic_year, $section, $expires, $sid, $u['user_id']);

  if($stmt->execute()){ 
    // Send credentials via email
    $emailBody = "Welcome to " . APP_NAME . "!\n\n";
    $emailBody .= "Your student account has been created.\n\n";
    $emailBody .= "Student ID: " . $student_id . "\n";
    $emailBody .= "Program: " . $program . "\n";
    $emailBody .= "Section: " . $section . "  (A.Y. " . $academic_year . ")\n";
    $emailBody .= "Password: " . $pass . "\n\n";
    $emailBody .= "Sign in with your Student ID at: " . BASE_URL . "/archive/index.php";
    
    send_email($email, "Your Account Credentials", $emailBody);
    
    flash('success','Student created and credentials sent to email.');
  } else { 
    flash('error','Failed to create student. Please try again.'); 
  }
  header('Location: faculty_manage_students.php'); exit;
}
try {
  /* Read into arrays rather than holding the result open: the roll is walked
     more than once — the section filter needs to know which sections actually
     appear before the table itself is drawn. */
  $cols = "user_id,full_name,email,username,plain_password,student_id,program,academic_year,section,expires_on,created_at,is_active";
  $roll = $conn->query("SELECT $cols FROM users WHERE user_role='student' ORDER BY created_at DESC")
               ->fetch_all(MYSQLI_ASSOC);

  /* An account past its date cannot be signed into, so listing it as active
     says something untrue. Expired and archived accounts sit together in the
     second tab — both are "not in use", and both are brought back from there:
     an archived one by restoring it, an expired one by editing it on to the
     year it is really in. Each row remembers which of the two it is. */
  $active = $archived = [];
  $today  = strtotime('today');
  foreach ($roll as $r) {
      $lapsed = !empty($r['expires_on']) && strtotime($r['expires_on']) < $today;
      if (!(int)$r['is_active'])  { $r['_state'] = 'archived'; $archived[] = $r; }
      elseif ($lapsed)            { $r['_state'] = 'expired';  $archived[] = $r; }
      else                        { $r['_state'] = 'active';   $active[]   = $r; }
  }
} catch (mysqli_sql_exception $e) {
  /* A column this page needs is not in the database yet. Say which migration
     puts it there rather than showing the visitor a stack trace. */
  $migrations = [
    'plain_password' => 'scripts/migrations/run_plain_password_migration.php',
    'academic_year'  => 'scripts/migrations/run_student_cohort_migration.php',
    'section'        => 'scripts/migrations/run_student_cohort_migration.php',
    'expires_on'     => 'scripts/migrations/run_account_expiry_migration.php',
  ];
  foreach ($migrations as $column => $script) {
    if (strpos($e->getMessage(), "Unknown column '$column'") !== false) {
      error_log("Database migration required: $column is missing from users. Run $script to fix.");
      flash('error', 'A system update is required. Please contact your administrator.');
      header('Location: faculty_manage_students.php'); exit;
    }
  }
  throw $e;
}

/* One list of programmes, used three times over: the dropdown on the form, the
   filter chips above the table, and the short code shown in each row. It lives
   in core.php now, because the analytics tables need the same short codes. */
$PROGRAMS = programs_map();

/* Suggestions, not rules. The academic year list begins at the intake now
   running and looks forward — accounts are being made for students starting
   now or soon, not for years already gone. The sections cover the four year
   levels plus the ladderized intake. Both fields stay open, so an adviser can
   still type a past year or a section this list has never heard of. */
$ay_start = (int)date('n') >= 6 ? (int)date('y') : (int)date('y') - 1;
$ACADEMIC_YEARS = [];
for ($i = 0; $i <= 3; $i++) {
    $ACADEMIC_YEARS[] = sprintf('%02d-%02d', $ay_start + $i, $ay_start + $i + 1);
}
$SECTIONS = [];
for ($year = 1; $year <= 4; $year++) {
    for ($n = 1; $n <= 3; $n++) { $SECTIONS[] = "$year-$n"; }
}
$SECTIONS[] = 'Ladderized';

/* What the section filter offers: the suggestions, plus anything an adviser
   has actually typed that is not among them, so a hand-entered section can
   still be filtered on. Ordered as the suggestions are, oddities last.
   Built from the whole roll, since both tabs carry the same filter bar — the
   chips with nobody behind them are hidden per tab by the page's own script. */
$used = [];
foreach ($roll as $r) {
    $s = trim((string)$r['section']);
    if ($s !== '') { $used[$s] = true; }
}
$SECTION_FILTERS = array_values(array_filter($SECTIONS, function ($s) use ($used) {
    return isset($used[$s]);
}));
foreach (array_keys($used) as $s) {
    if (!in_array($s, $SECTIONS, true)) { $SECTION_FILTERS[] = $s; }
}

/* And the academic years on the roll, for the third step of the filter — one
   section can hold students from more than one intake. Newest first, since a
   current class is looked for more often than an old one. */
$YEAR_FILTERS = [];
foreach ($roll as $r) {
    $y = trim((string)$r['academic_year']);
    if ($y !== '') { $YEAR_FILTERS[$y] = true; }
}
$YEAR_FILTERS = array_keys($YEAR_FILTERS);
rsort($YEAR_FILTERS);

/**
 * The filter bar, drawn the same way above either tab.
 *
 * Both tabs hold the same kind of rows, so both deserve the same way through
 * them. The chips are found by class rather than id, because there are now two
 * of every one of them on the page.
 */
function mgmt_filter_bar(array $programs, array $sections, array $years): void {
    ?>
    <div class="mgmt-chips js-prog-chips">
        <button type="button" class="mgmt-chip is-on" data-program="all">All</button>
        <?php foreach ($programs as $pname => $code): ?>
            <button type="button" class="mgmt-chip" data-program="<?= e($pname) ?>"><?= e($code) ?></button>
        <?php endforeach; ?>
    </div>

    <!-- Choosing a program asks for a section next, so a big roll can be
         narrowed to one class rather than one course. Hidden until a program
         has been picked, and only lists sections that exist in this tab. -->
    <div class="mgmt-chips mgmt-chips-sub js-sect-chips" hidden>
        <span class="mgmt-chips-label">Choose section</span>
        <button type="button" class="mgmt-chip is-on" data-section="all">All sections</button>
        <?php foreach ($sections as $s): ?>
            <button type="button" class="mgmt-chip" data-section="<?= e($s) ?>"><?= e($s) ?></button>
        <?php endforeach; ?>
    </div>

    <!-- And once a section is chosen, which intake. The same section comes
         round every year, so 4-1 on its own can hold students whose accounts
         were made in different academic years. -->
    <div class="mgmt-chips mgmt-chips-sub js-year-chips" hidden>
        <span class="mgmt-chips-label">Choose academic year</span>
        <button type="button" class="mgmt-chip is-on" data-year="all">All years</button>
        <?php foreach ($years as $y): ?>
            <button type="button" class="mgmt-chip" data-year="<?= e($y) ?>">A.Y. <?= e($y) ?></button>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * One student's row. Both tabs show the same facts; only the actions differ,
 * and those follow from why the account is in this tab at all.
 */
function mgmt_student_row(array $r, array $programs): void {
    $expTs   = !empty($r['expires_on']) ? strtotime($r['expires_on']) : null;
    $lapsed  = $expTs && $expTs < strtotime('today');
    $closing = $expTs && !$lapsed && $expTs < strtotime('+120 days');
    $state   = $r['_state'] ?? 'active';
    ?>
    <tr class="<?= $lapsed ? 'is-lapsed' : '' ?> <?= $state === 'archived' ? 'mgmt-row-off' : '' ?>"
        data-program="<?= e($r['program'] ?? '') ?>"
        data-section="<?= e($r['section'] ?? '') ?>"
        data-user-id="<?= (int)$r['user_id'] ?>"
        data-full-name="<?= e($r['full_name']) ?>"
        data-student-id="<?= e($r['student_id'] ?? '') ?>"
        data-email="<?= e($r['email']) ?>"
        data-academic-year="<?= e($r['academic_year'] ?? '') ?>">
        <td class="mgmt-name">
            <?= e($r['full_name']) ?>
            <?php if ($state === 'archived'): ?>
                <span class="mgmt-tag">Archived</span>
            <?php elseif ($state === 'expired'): ?>
                <span class="mgmt-tag is-lapsed">Expired</span>
            <?php endif; ?>
            <span class="mgmt-sub" title="<?= e($r['email']) ?>"><?= e($r['email']) ?></span>
        </td>
        <td class="mgmt-id">
            <?= e($r['student_id'] ?: '—') ?>
            <?php if ($expTs): ?>
                <span class="mgmt-sub <?= $lapsed ? 'is-lapsed' : ($closing ? 'is-closing' : '') ?>"
                      title="<?= e(student_account_years($r['section'])) ?>-year account for section <?= e($r['section']) ?>">
                    <?= $lapsed ? 'Expired' : 'Until' ?> <?= e(date('M j, Y', $expTs)) ?>
                </span>
            <?php else: ?>
                <span class="mgmt-sub">No expiry</span>
            <?php endif; ?>
        </td>
        <td class="mgmt-prog" title="<?= e($r['program'] ?? '') ?>">
            <?= e($programs[$r['program'] ?? ''] ?? ($r['program'] ?: '—')) ?>
        </td>
        <td class="mgmt-cohort">
            <?= e($r['section'] ?: '—') ?>
            <?php if (!empty($r['academic_year'])): ?>
                <span class="mgmt-sub">A.Y. <?= e($r['academic_year']) ?></span>
            <?php endif; ?>
        </td>
        <td class="mgmt-pass"><?= e($r['plain_password'] ?? '—') ?></td>
        <td>
            <div class="mgmt-actions">
                <?php if ($state === 'archived'): ?>
                    <form method="post">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                        <button class="mgmt-act"
                                data-confirm="Restore <?= e($r['full_name']) ?>? They will be able to sign in again.">Restore
                        </button>
                    </form>
                <?php else: ?>
                    <?php /* An expired account is renewed by editing it, so Edit stays. */ ?>
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

            <?php if ($state !== 'archived'): ?>
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
                                        <?= e($r['full_name']) ?> will need this to sign in. Tell them yourself &mdash;
                                        resetting does not email it.
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
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Students · <?= e(APP_NAME) ?></title>
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
        <span class="crumb-current">My Students</span>
    </div>
</div>

<main class="wrap mgmt-wrap">

    <div class="mgmt-head">
        <h1>My Students</h1>
        <p>Create and manage the student accounts under your supervision.</p>
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
             One panel does both. Editing fills these same boxes with the chosen
             student and swaps the action underneath, so there is never a second
             form to keep in step with this one. -->
        <section class="mgmt-panel" id="formPanel">
            <div class="mgmt-panel-head">
                <span class="material-symbols-outlined" id="formIcon">person_add</span>
                <span id="formTitle">New student account</span>
            </div>
            <div class="mgmt-panel-body">
                <form method="post" class="js-manage-form" id="createStudentForm">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="create_user">
                    <input type="hidden" name="user_id" id="formUserId" value="">

                    <div class="mgmt-editing-note">
                        <span class="material-symbols-outlined">edit</span>
                        <span>Editing <strong id="editingWho"></strong>. Leave the password blank to keep the
                              one they already have.</span>
                    </div>

                    <div class="mgmt-field">
                        <label for="full_name">Full name <span class="req">*</span></label>
                        <input type="text" name="full_name" id="full_name" pattern="[A-Za-z\s.]+"
                               title="Full name cannot contain numbers"
                               placeholder="e.g. Juan Dela Cruz" required>
                    </div>

                    <div class="mgmt-field">
                        <label for="student_id">Student ID <span class="req">*</span></label>
                        <input type="text" name="student_id" id="student_id"
                               pattern="[A-Za-z0-9._-]{6,20}" title="Student ID: 6-20 characters"
                               placeholder="e.g. 2026-00001-BN-0" required>
                        <small class="mgmt-hint">6&ndash;20 characters. This is what they sign in with.</small>
                    </div>

                    <div class="mgmt-field">
                        <label for="email">Email <span class="req">*</span></label>
                        <input type="email" name="email" id="email" placeholder="student@example.com" required>
                    </div>

                    <div class="mgmt-field">
                        <label for="program">Academic program <span class="req">*</span></label>
                        <select name="program" id="program" required>
                            <option value="">Select program</option>
                            <?php foreach ($PROGRAMS as $pname => $code): ?>
                                <option value="<?= e($pname) ?>"><?= e($code) ?> &mdash; <?= e($pname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mgmt-field">
                        <div class="mgmt-pair">
                            <div>
                                <label for="academic_year">
                                    Academic year <span class="req">*</span>
                                    <button type="button" class="mgmt-help js-help"
                                            aria-label="What is the academic year for?">?</button>
                                </label>
                                <input type="text" name="academic_year" id="academic_year"
                                       list="ayList" maxlength="20" placeholder="e.g. <?= e($ACADEMIC_YEARS[0]) ?>" required>
                            </div>
                            <div>
                                <label for="section">
                                    Section <span class="req">*</span>
                                    <button type="button" class="mgmt-help js-help"
                                            aria-label="What is the section for?">?</button>
                                </label>
                                <input type="text" name="section" id="section"
                                       list="sectionList" maxlength="40" placeholder="e.g. 4-1" required>
                            </div>
                        </div>
                        <small class="mgmt-hint">Pick a suggestion or type your own.</small>
                        <datalist id="ayList">
                            <?php foreach ($ACADEMIC_YEARS as $ay): ?><option value="<?= e($ay) ?>"><?php endforeach; ?>
                        </datalist>
                        <datalist id="sectionList">
                            <?php foreach ($SECTIONS as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                        </datalist>
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
                            <span id="formSubmitText">Create student account</span>
                        </button>
                        <button type="button" class="btn-sm-outline mgmt-submit" id="formCancel" hidden>
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- ============ Roll ============
     Two tabs over the same kind of rows, so both get the same filter bar and
     the same row renderer. The second holds accounts not in use — archived by
     hand, or lapsed because their date has passed. -->
        <section>
            <div class="mgmt-tabs" role="tablist">
                <button type="button" class="mgmt-tab is-on" data-pane="activePane" role="tab">
                    Active students
                    <span class="count"><?= count($active) ?></span>
                </button>
                <button type="button" class="mgmt-tab" data-pane="archivedPane" role="tab">
                    Archived / Expired
                    <span class="count"><?= count($archived) ?></span>
                </button>

                <!-- Sort rides on the tab row rather than under the chips: it is
                     not a way of choosing who to show, and it applies to both
                     tabs at once, so it belongs with them rather than inside
                     either one. -->
                <span class="mgmt-sort" id="sortChips">
                    <span class="mgmt-chips-label">Sort</span>
                    <button type="button" class="mgmt-chip is-on" data-sort="newest">Newest</button>
                    <button type="button" class="mgmt-chip" data-sort="az">A&ndash;Z</button>
                    <button type="button" class="mgmt-chip" data-sort="za">Z&ndash;A</button>
                </span>
            </div>

            <!-- In use -->
            <div id="activePane" class="js-pane">
                <?php mgmt_filter_bar($PROGRAMS, $SECTION_FILTERS, $YEAR_FILTERS); ?>

                <div class="mgmt-panel">
                    <div class="mgmt-scroll">
                        <table class="mgmt-table" id="activeStudentsTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                    <th>Program</th>
                                    <th>Section</th>
                                    <th>Password</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($active): ?>
                                <?php foreach ($active as $r) { mgmt_student_row($r, $PROGRAMS); } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="mgmt-empty">
                                            <span class="material-symbols-outlined">group</span>
                                            No student accounts yet. Create one on the left.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr class="js-no-match" hidden>
                                <td colspan="6">
                                    <div class="mgmt-empty">
                                        <span class="material-symbols-outlined">filter_alt_off</span>
                                        No students match that filter.
                                    </div>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Not in use: archived by hand, or lapsed -->
            <div id="archivedPane" class="js-pane" hidden>
                <?php mgmt_filter_bar($PROGRAMS, $SECTION_FILTERS, $YEAR_FILTERS); ?>

                <div class="mgmt-panel">
                    <div class="mgmt-scroll">
                        <table class="mgmt-table" id="archivedStudentsTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                    <th>Program</th>
                                    <th>Section</th>
                                    <th>Password</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($archived): ?>
                                <?php foreach ($archived as $r) { mgmt_student_row($r, $PROGRAMS); } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="mgmt-empty">
                                            <span class="material-symbols-outlined">archive</span>
                                            Nothing archived, and nothing expired.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr class="js-no-match" hidden>
                                <td colspan="6">
                                    <div class="mgmt-empty">
                                        <span class="material-symbols-outlined">filter_alt_off</span>
                                        No students match that filter.
                                    </div>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

    </div>
</main>

<!-- What the two cohort fields are for. Lives at the end of <body> rather than
     beside the fields, so nothing in the form's layout can box it in. -->
<div class="mgmt-help-backdrop" id="helpDialog" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
    <div class="mgmt-help-panel" id="helpPanel" tabindex="-1">
        <div class="mgmt-help-head">
            <span class="material-symbols-outlined">help</span>
            <h2 id="helpTitle">Academic year and section</h2>
            <button type="button" class="mgmt-help-close" id="helpClose" aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="mgmt-help-body">
            <p>
                <strong>Academic year</strong> is the school year this account is created in, written as
                the two calendar years it runs across. <em>26&ndash;27</em> means August 2026 to July 2027.
            </p>
            <p>
                <strong>Section</strong> is their year level and class. <em>4&ndash;1</em> is fourth year,
                section 1. Use <em>Ladderized</em> for a student on the ladderized programme.
            </p>
            <p class="mgmt-help-note">
                Together these decide how long the account lasts: five years for a first year,
                four for a second, three for a third, and two for a fourth year or a ladderized
                intake &mdash; counted from the academic year the account was created in. Moving a
                student up a year recalculates it.
            </p>
            <p>
                Both boxes suggest the usual values but accept anything you type, for a programme
                that names its sections differently.
            </p>
        </div>
        <div class="mgmt-help-foot">
            <button type="button" class="btn-sm-maroon" id="helpOk">Got it</button>
        </div>
    </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

    /* A Student ID with only letters, or only digits, is almost always a typo.
       Say so while it is being typed rather than after the form is sent. */
    var sid = document.getElementById('student_id');
    if (sid) {
        sid.addEventListener('input', function () {
            var v = this.value;
            var ok = v.length === 0 || (/[A-Za-z]/.test(v) && /[0-9]/.test(v));
            this.setCustomValidity(ok ? '' : 'Student ID needs at least one letter and one number.');
        });
    }

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

    /* ---- Filtering and sorting, per tab ----
       Programme, then section, then intake. Each row appears only once the step
       above it has been narrowed, and each offers only values some student in
       *this* tab actually has — offering 3-2 when nobody is in it just leads to
       an empty table, and the two tabs hold different people.

       Both tabs are wired by the same function, so they cannot drift apart. The
       value compared is the one on the row, not the text in the cell: the cell
       shows the short code, the row carries the full name the database stores. */
    function wirePane(pane) {
        var progChips = pane.querySelectorAll('.js-prog-chips .mgmt-chip[data-program]');
        var sectWrap  = pane.querySelector('.js-sect-chips');
        var sectChips = sectWrap ? sectWrap.querySelectorAll('.mgmt-chip') : [];
        var yearWrap  = pane.querySelector('.js-year-chips');
        var yearChips = yearWrap ? yearWrap.querySelectorAll('.mgmt-chip') : [];
        var noMatch   = pane.querySelector('.js-no-match');
        var body      = pane.querySelector('tbody');
        var rows      = [].slice.call(pane.querySelectorAll('tbody tr[data-program]'));
        var program = 'all', section = 'all', year = 'all', sort = 'newest';

        // The order the server sent, which is newest first.
        rows.forEach(function (row, i) { row.dataset.rank = i; });

        function matches(row, upTo) {
            if (program !== 'all' && row.dataset.program !== program) { return false; }
            if (upTo === 'program') { return true; }
            if (section !== 'all' && row.dataset.section !== section) { return false; }
            if (upTo === 'section') { return true; }
            return year === 'all' || row.dataset.academicYear === year;
        }

        function apply() {
            var shown = 0;
            rows.forEach(function (row) {
                var show = matches(row, 'year');
                row.hidden = !show;
                if (show) { shown++; }
            });
            if (noMatch) { noMatch.hidden = (shown > 0); }
        }

        /* Reorder the rows themselves rather than hiding and redrawing: the
           table keeps its state, and the "no match" row stays where it is
           because it is not among the rows being moved. */
        function reorder() {
            var order = rows.slice().sort(function (a, b) {
                if (sort === 'newest') {
                    return (+a.dataset.rank) - (+b.dataset.rank);
                }
                var an = (a.dataset.fullName || '').toLowerCase();
                var bn = (b.dataset.fullName || '').toLowerCase();
                var cmp = an.localeCompare(bn);
                return sort === 'az' ? cmp : -cmp;
            });
            order.forEach(function (row) { body.insertBefore(row, noMatch || null); });
        }

        /* Show a step only where there is something to choose between. */
        function refreshStep(wrap, chips, upTo, attr, key, visibleWhen) {
            if (!wrap) { return; }
            var here = {};
            rows.forEach(function (row) {
                if (matches(row, upTo)) {
                    var v = row.dataset[attr];
                    if (v) { here[v] = true; }
                }
            });
            var offered = 0;
            chips.forEach(function (c) {
                if (c.dataset[key] === 'all') { return; }
                var has = !!here[c.dataset[key]];
                c.hidden = !has;
                if (has) { offered++; }
            });
            wrap.hidden = !visibleWhen || offered === 0;
        }

        function refreshSections() {
            refreshStep(sectWrap, sectChips, 'program', 'section', 'section', program !== 'all');
        }
        function refreshYears() {
            // Only worth asking once a single section is in view.
            refreshStep(yearWrap, yearChips, 'section', 'academicYear', 'year', section !== 'all');
        }

        function pick(chips, chip) {
            chips.forEach(function (c) { c.classList.toggle('is-on', c === chip); });
        }
        function selectSection(chip) {
            section = chip ? chip.dataset.section : 'all';
            pick(sectChips, chip);
        }
        function selectYear(chip) {
            year = chip ? chip.dataset.year : 'all';
            pick(yearChips, chip);
        }
        function resetSection() {
            selectSection(sectWrap ? sectWrap.querySelector('.mgmt-chip[data-section="all"]') : null);
        }
        function resetYear() {
            selectYear(yearWrap ? yearWrap.querySelector('.mgmt-chip[data-year="all"]') : null);
        }

        progChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                program = chip.dataset.program;
                pick(progChips, chip);
                // A new programme starts over from all of its sections and intakes.
                resetSection();
                resetYear();
                refreshSections();
                refreshYears();
                apply();
            });
        });

        sectChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                selectSection(chip);
                resetYear();          // a different section has its own intakes
                refreshYears();
                apply();
            });
        });

        yearChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                selectYear(chip);
                apply();
            });
        });

        refreshSections();
        refreshYears();

        // The sort control lives on the tab row and drives every pane at once.
        return function (mode) { sort = mode; reorder(); };
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
    var form     = document.getElementById('createStudentForm');
    var panel    = document.getElementById('formPanel');
    var cancel   = document.getElementById('formCancel');
    var pwField  = document.getElementById('password');
    var fields   = {
        full_name:     document.getElementById('full_name'),
        student_id:    document.getElementById('student_id'),
        email:         document.getElementById('email'),
        program:       document.getElementById('program'),
        academic_year: document.getElementById('academic_year'),
        section:       document.getElementById('section')
    };

    function toCreateMode() {
        panel.classList.remove('is-editing');
        document.getElementById('formAction').value = 'create_user';
        document.getElementById('formUserId').value = '';
        document.getElementById('formTitle').textContent = 'New student account';
        document.getElementById('formIcon').textContent = 'person_add';
        document.getElementById('formSubmitIcon').textContent = 'add';
        document.getElementById('formSubmitText').textContent = 'Create student account';
        document.getElementById('passReq').hidden = false;
        document.getElementById('passHint').textContent =
            'At least one uppercase letter and one number.';
        pwField.required = true;
        pwField.placeholder = 'Min 6 chars, 1 uppercase, 1 number';
        cancel.hidden = true;
        form.reset();
        // form.reset() restores the markup's values, not these — set them after.
        document.getElementById('formAction').value = 'create_user';
        document.getElementById('formUserId').value = '';
        document.querySelectorAll('#activeStudentsTable tbody tr.is-editing')
                .forEach(function (r) { r.classList.remove('is-editing'); });
    }

    function toEditMode(row) {
        var d = row.dataset;
        fields.full_name.value     = d.fullName || '';
        fields.student_id.value    = d.studentId || '';
        fields.email.value         = d.email || '';
        fields.program.value       = d.program || '';
        fields.academic_year.value = d.academicYear || '';
        fields.section.value       = d.section || '';
        pwField.value = '';

        document.getElementById('formAction').value = 'update_user';
        document.getElementById('formUserId').value = d.userId;
        document.getElementById('formTitle').textContent = 'Edit student account';
        document.getElementById('formIcon').textContent = 'edit';
        document.getElementById('formSubmitIcon').textContent = 'save';
        document.getElementById('formSubmitText').textContent = 'Save changes';
        document.getElementById('editingWho').textContent = d.fullName || 'this student';
        // A blank password here means "keep the current one", so it cannot be required.
        document.getElementById('passReq').hidden = true;
        document.getElementById('passHint').textContent =
            'Leave blank to keep their current password.';
        pwField.required = false;
        pwField.placeholder = 'Unchanged';
        cancel.hidden = false;
        panel.classList.add('is-editing');

        document.querySelectorAll('#activeStudentsTable tbody tr.is-editing')
                .forEach(function (r) { r.classList.remove('is-editing'); });
        row.classList.add('is-editing');

        // Remember what the row looked like, so Save can say what changed.
        if (window.papelEditSnapshot) { window.papelEditSnapshot(form); }
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        fields.full_name.focus();
    }

    document.querySelectorAll('.js-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            toEditMode(btn.closest('tr'));
        });
    });
    cancel.addEventListener('click', toCreateMode);

    /* ---- What the cohort fields are for ----
       Both "?" buttons open the one explanation; it covers both fields, and
       whichever was asked from, the other is the next thing you want to know. */
    var help = document.getElementById('helpDialog');
    var helpOpener = null;

    function openHelp(from) {
        helpOpener = from;
        help.classList.add('is-open');
        /* Focus the panel, not a button inside it — a focused Close reads as
           already pressed. Tab still walks into the dialog from here. */
        document.getElementById('helpPanel').focus();
    }
    function closeHelp() {
        help.classList.remove('is-open');
        // Put the reader back where they were rather than at the top of the form.
        if (helpOpener) { helpOpener.focus(); helpOpener = null; }
    }

    document.querySelectorAll('.js-help').forEach(function (btn) {
        btn.addEventListener('click', function () { openHelp(btn); });
    });
    document.getElementById('helpClose').addEventListener('click', closeHelp);
    document.getElementById('helpOk').addEventListener('click', closeHelp);
    help.addEventListener('click', function (e) { if (e.target === help) { closeHelp(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && help.classList.contains('is-open')) { closeHelp(); }
    });

    refreshSections();
    refreshYears();
});
</script>
<?php require ROOT_PATH.'/includes/action_dialogs.php';
require ROOT_PATH.'/includes/manage_save_confirm.php';
require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>







