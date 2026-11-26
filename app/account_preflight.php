<?php
/** Read-only check before account forms start the page-loading indicator. */
define('PAPEL_PASSWORD_GATE', true);
require_once __DIR__ . '/../config/core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function preflight_answer(?string $message = null, ?string $field = null): void {
    echo json_encode(['ok' => $message === null, 'message' => $message, 'field' => $field]);
    exit;
}
function preflight_taken(mysqli $conn, string $sql, string $types, array $values): bool {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}
function preflight_owner_name(mysqli $conn, string $col, string $value, int $exceptId = 0): string {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE $col = ? AND user_id <> ? LIMIT 1");
    $stmt->bind_param('si', $value, $exceptId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['full_name'] : '';
}
function preflight_password_problem(string $password, bool $required): ?string {
    if (!$required && $password === '') return null;
    return strlen($password) < 6 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)
        ? 'Password must be at least 6 characters and contain at least one uppercase letter and one number.' : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    http_response_code(403);
    preflight_answer('This form has expired. Refresh the page and try again.');
}
$u = current_user();
if (!$u) { http_response_code(401); preflight_answer('Your session has ended. Sign in again.'); }
$kind = (string)($_POST['_preflight_kind'] ?? '');
$action = (string)($_POST['action'] ?? '');
$conn = db();

if (in_array($kind, ['admin_reset', 'faculty_reset', 'student_reset'], true) && $action === 'reset_password') {
    $scope = substr($kind, 0, -6);
    if ($scope === 'admin' && !user_has_role($u, 'super_admin') ||
        $scope === 'faculty' && !is_research_coordinator($u) ||
        $scope === 'student' && !user_has_role($u, 'faculty')) {
        http_response_code(403); preflight_answer('You cannot reset this account password.');
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $roles = ['admin' => "('admin','head_academic','librarian')", 'faculty' => "('faculty','librarian')", 'student' => "('student')"];
    $extra = $scope === 'student' ? ' AND created_by=' . (int)$u['user_id'] : '';
    if (!preflight_taken($conn, 'SELECT user_id FROM users WHERE user_id=? AND user_role IN ' . $roles[$scope] . $extra . ' LIMIT 1', 'i', [$userId])) {
        preflight_answer('That account could not be found.');
    }
    if ($why = preflight_password_problem((string)($_POST['new_password'] ?? ''), true)) preflight_answer($why, 'new_password');
    preflight_answer();
}

if ($kind === 'password' && $action === 'change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $stmt = $conn->prepare('SELECT password FROM users WHERE user_id=?');
    $id = (int)$u['user_id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $hash = (string)($stmt->get_result()->fetch_assoc()['password'] ?? '');
    $stmt->close();
    if (!password_verify($current, $hash)) preflight_answer('Your current password is incorrect.', 'current_password');
    if (strlen($new) < 8) preflight_answer('Your new password must be at least 8 characters long.', 'new_password');
    if ($new !== $confirm) preflight_answer('The new passwords you entered do not match.', 'confirm_password');
    if ($new === $current) preflight_answer('Your new password must be different from your current one.', 'new_password');
    preflight_answer();
}

if ($kind === 'profile' && $action === 'update_profile') {
    if (!profile_self_editable($u)) preflight_answer('This account cannot edit its personal information.');
    $id = (int)$u['user_id'];
    $name = normalize_person_name((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    if ($why = person_name_problem($name)) preflight_answer(profile_rule_message($why), 'full_name');
    if ($why = account_email_problem($email)) preflight_answer(profile_rule_message($why), 'email');
    $owner = email_account_owner($email);
    if ($owner && (int)$owner['user_id'] !== $id) preflight_answer('This email belongs to ' . ($owner['full_name'] ?: 'another account') . '.', 'email');
    if (array_key_exists('faculty_id', $_POST)) {
        if (!profile_self_id_editable($u)) preflight_answer('You cannot change this Faculty ID.', 'faculty_id');
        $facultyId = staff_id_clean((string)$_POST['faculty_id']);
        $before = account_snapshot($id);
        if ($why = staff_id_problem($facultyId, 'Faculty ID', (string)($before['faculty_id'] ?? ''))) preflight_answer(profile_rule_message($why), 'faculty_id');
        if (strcasecmp(trim((string)($before['faculty_id'] ?? '')), $facultyId) !== 0 &&
            preflight_taken($conn, 'SELECT user_id FROM users WHERE user_id<>? AND (faculty_id=? OR student_id=? OR username=?) LIMIT 1', 'isss', [$id, $facultyId, $facultyId, $facultyId])) {
            $owner = preflight_owner_name($conn, 'faculty_id', $facultyId, $id);
            preflight_answer('This Faculty ID belongs to ' . ($owner ?: 'another account') . '.', 'faculty_id');
        }
    }
    preflight_answer();
}

if (!in_array($kind, ['admin', 'faculty', 'student'], true) || !in_array($action, ['create_user', 'update_user'], true)) {
    http_response_code(400); preflight_answer('This form could not be checked. Refresh and try again.');
}
if ($kind === 'admin' && !user_has_role($u, 'super_admin') ||
    $kind === 'faculty' && !is_research_coordinator($u) ||
    $kind === 'student' && !user_has_role($u, 'faculty')) {
    http_response_code(403); preflight_answer('You cannot manage these accounts.');
}
$editing = $action === 'update_user';
$userId = (int)($_POST['user_id'] ?? 0);
if ($editing) {
    $roles = ['admin' => "('admin','head_academic','librarian')", 'faculty' => "('faculty','librarian')", 'student' => "('student')"];
    $extra = $kind === 'student' ? ' AND created_by=' . (int)$u['user_id'] : '';
    $stmt = $conn->prepare('SELECT faculty_id FROM users WHERE user_id=? AND user_role IN ' . $roles[$kind] . $extra);
    $stmt->bind_param('i', $userId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) preflight_answer('That account could not be found.');
}
$name = normalize_person_name((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if ($why = person_name_problem($name)) preflight_answer($why, 'full_name');
if ($kind === 'student') {
    $number = trim((string)($_POST['student_id'] ?? ''));
    $year = trim((string)($_POST['academic_year'] ?? ''));
    $section = trim((string)($_POST['section'] ?? ''));
    $program = trim((string)($_POST['program'] ?? ''));
    if ($why = student_number_problem($number)) preflight_answer($why, 'student_id');
    if ($why = account_email_problem($email)) preflight_answer($why, 'email');
    if ($why = academic_year_problem($year)) preflight_answer($why, 'academic_year');
    if ($why = section_problem($section)) preflight_answer($why, 'section');
    if (!array_key_exists($program, programs_map())) preflight_answer('Choose an academic program from the list.', 'program');
    if (academic_year_is_future($year)) preflight_answer('That academic year has not started yet. The latest you can use is ' . academic_year_current() . '.', 'academic_year');
    if ($why = preflight_password_problem($password, !$editing)) preflight_answer($why, 'password');
    if (preflight_taken($conn, 'SELECT user_id FROM users WHERE user_id<>? AND student_id=? LIMIT 1', 'is', [$editing ? $userId : 0, $number])) {
        $owner = preflight_owner_name($conn, 'student_id', $number, $editing ? $userId : 0);
        preflight_answer('This Student ID belongs to ' . ($owner ?: 'another account') . '.', 'student_id');
    }
    if (preflight_taken($conn, 'SELECT user_id FROM users WHERE user_id<>? AND email=? LIMIT 1', 'is', [$editing ? $userId : 0, $email])) {
        $owner = preflight_owner_name($conn, 'email', $email, $editing ? $userId : 0);
        preflight_answer('This email belongs to ' . ($owner ?: 'another account') . '.', 'email');
    }
    if (!$editing) {
        $username = substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $number)), 0, 30);
        if ($username !== '' && preflight_taken($conn, 'SELECT user_id FROM users WHERE username=? LIMIT 1', 's', [$username])) preflight_answer('Username already exists.', 'student_id');
    }
    preflight_answer();
}
$facultyId = staff_id_clean((string)($_POST['faculty_id'] ?? ''));
if ($why = staff_id_problem($facultyId, 'Faculty ID', $editing ? (string)$row['faculty_id'] : '')) preflight_answer($why, 'faculty_id');
if ($why = account_email_problem($email)) preflight_answer($why, 'email');
if ($why = preflight_password_problem($password, !$editing)) preflight_answer($why, 'password');
$position = trim((string)($_POST[$kind === 'admin' ? 'position' : 'title'] ?? ''));
$map = staff_position_map($position);
if (!$map || ($kind === 'admin' && $map['role'] === 'faculty') ||
    ($kind === 'faculty' && !in_array($map['role'], ['faculty', 'librarian'], true))) preflight_answer('Choose a position.', $kind === 'admin' ? 'position' : 'title');
try { secondary_position_for($u, (string)($_POST['secondary_position'] ?? ''), $position); }
catch (InvalidArgumentException $e) { preflight_answer($e->getMessage(), 'secondary_position'); }
if (preflight_taken($conn, 'SELECT user_id FROM users WHERE user_id<>? AND email=? LIMIT 1', 'is', [$editing ? $userId : 0, $email])) {
    $owner = preflight_owner_name($conn, 'email', $email, $editing ? $userId : 0);
    preflight_answer('This email belongs to ' . ($owner ?: 'another account') . '.', 'email');
}
if (preflight_taken($conn, 'SELECT user_id FROM users WHERE user_id<>? AND faculty_id=? LIMIT 1', 'is', [$editing ? $userId : 0, $facultyId])) {
    $owner = preflight_owner_name($conn, 'faculty_id', $facultyId, $editing ? $userId : 0);
    preflight_answer('This ID belongs to ' . ($owner ?: 'another account') . '.', 'faculty_id');
}
if (!$editing) {
    $username = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $facultyId));
    if ($username !== '' && preflight_taken($conn, 'SELECT user_id FROM users WHERE username=? LIMIT 1', 's', [$username])) preflight_answer('This ID would create a username already in use.', 'faculty_id');
}
preflight_answer();
