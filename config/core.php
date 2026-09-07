<?php
// core.php (FINAL)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/../app/helpers/UploadHelper.php';
require_once __DIR__ . '/../includes/recaptcha.php';

function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
        /* reCAPTCHA is fetched from google.com and framed from google.com, so
           those origins are opened only while the feature actually has keys —
           an unused allowance is still an allowance. */
        $rc      = (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== ''
                 && defined('RECAPTCHA_SECRET_KEY') && RECAPTCHA_SECRET_KEY !== '');
        $rcScript = $rc ? ' https://www.google.com https://www.gstatic.com' : '';
        $rcFrame  = $rc ? ' https://www.google.com' : '';

        $csp = "default-src 'self'; " .
               "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://unpkg.com{$rcScript}; " .
               "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
               "img-src 'self' data: blob: https://lh3.googleusercontent.com; " .
               "connect-src 'self' https://api.groq.com https://www.googleapis.com; " .
               "frame-src 'self' https://drive.google.com{$rcFrame}; " .
               "frame-ancestors 'self'; " .
               "form-action 'self'; " .
               "base-uri 'self'; " .
               "object-src 'none'; " .
               "media-src 'none'; " .
               "manifest-src 'self'; " .
               "upgrade-insecure-requests;";
        if (!headers_sent()) {
            header("Content-Security-Policy: " . $csp);
            header_remove('X-Powered-By'); // ID-016
        }
    }
    return $nonce;
}
csp_nonce(); // Ensure header is sent early

function start_session_once(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'secure'   => COOKIE_SECURE,
      'httponly' => true,
      'samesite' => 'Lax'
    ]);
    session_name('papel_sid');
    session_start();
  }
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    if (DB_SSL_CA !== '') {
      /* A hosted database: a port that is not 3306, and a connection that has
         to be encrypted and verified against the provider's CA. Built the long
         way round because ssl_set() has to be called on an initialised handle
         before it connects — the one-line constructor connects immediately and
         leaves no room to say any of this. */
      $conn = mysqli_init();
      $conn->ssl_set(null, null, DB_SSL_CA, null, null);
      @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null,
                           MYSQLI_CLIENT_SSL);
    } else {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    }
    if ($conn->connect_errno) {
      $ref = generate_error_ref();
      log_error_detail($ref, 'Database Connection Error', 'Connection failed: ' . $conn->connect_error, __FILE__, __LINE__);
      show_safe_error($ref, 500);

    }
    $conn->set_charset(DB_CHARSET);
  }
  return $conn;
}

/**
 * Tidy a name that was typed in capitals.
 *
 * People fill forms in caps out of habit, and the roll then reads as
 * SEBASTIAN RAFAEL BELANDO beside Rayver S. Reyes. The entry is accepted either
 * way; this only decides how it is written down.
 *
 * The rule is deliberately narrow: a name is re-cased ONLY when it contains no
 * lowercase letter at all. The moment somebody has typed even one, they have
 * made a choice about their own name, and it is left exactly as it is. That is
 * what keeps McDonald, de la Cruz, van Dijk and dela Peña intact, none of which
 * a general title-caser would survive.
 *
 * Within an all-capitals name:
 *   - words are capitalised after a space, a hyphen and an apostrophe, so
 *     MARIA-JOSE becomes Maria-Jose and O'BRIEN becomes O'Brien;
 *   - initials keep their stop, M. stays M.;
 *   - generational suffixes and the particles that belong to a surname are put
 *     back the way they are conventionally written, since "Iii" and "Dela" are
 *     both wrong.
 */
function normalize_person_name(?string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return '';

    // Somebody has already chosen a casing. Leave it alone.
    if (preg_match('/\p{Ll}/u', $name)) return $name;

    $out = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

    /* mb_convert_case does not break on hyphens or apostrophes, so those are
       handled here rather than left as Maria-jose and O'brien. */
    $out = preg_replace_callback('/[-\'’]\p{Ll}/u', function ($m) {
        return mb_strtoupper($m[0], 'UTF-8');
    }, $out);

    /* Suffixes and surname particles, written the way they are meant to be.
       Matched on whole words only, so a Jrmaine is not turned into a Jr. */
    $fixed = [
        'Ii' => 'II', 'Iii' => 'III', 'Iv' => 'IV', 'Vi' => 'VI', 'Vii' => 'VII',
        'Jr' => 'Jr', 'Sr' => 'Sr',
    ];
    $out = preg_replace_callback('/\b(' . implode('|', array_keys($fixed)) . ')\b/u',
        function ($m) use ($fixed) { return $fixed[$m[1]] ?? $m[1]; }, $out);

    return $out;
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }

// Validate student ID format (alphanumeric, 6-20 characters, allows hyphens and underscores)
function validate_student_id(string $student_id): array {
  $student_id = trim($student_id);
  
  if (empty($student_id)) {
    return ['valid' => false, 'message' => 'Student ID is required.'];
  }
  
  // Check length (typically 6-20 characters)
  if (strlen($student_id) < 6 || strlen($student_id)> 20) {
    return ['valid' => false, 'message' => 'Student ID must be between 6 and 20 characters.'];
  }
  
  // Allow alphanumeric, hyphens, underscores, and dots
  if (!preg_match('/^[A-Za-z0-9._-]+$/', $student_id)) {
    return ['valid' => false, 'message' => 'Student ID can only contain letters, numbers, dots, hyphens, and underscores.'];
  }
  
  // Must contain at least one letter and one number
  if (!preg_match('/[A-Za-z]/', $student_id) || !preg_match('/[0-9]/', $student_id)) {
    return ['valid' => false, 'message' => 'Student ID must contain at least one letter and one number.'];
  }
  
  return ['valid' => true, 'message' => ''];
}

function csrf_token(): string {
  start_session_once();
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(hash_hmac('sha256', random_bytes(32), CSRF_KEY));
  }
  return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_token" value="'.e(csrf_token()).'">'; }
/**
 * Does this POST carry the session's CSRF token?
 *
 * The same comparison csrf_verify() makes, for the pages that show their own
 * error inline rather than flashing and redirecting — archive/login.php keeps
 * its message in $error, so a redirect would throw it away.
 */
function csrf_valid(): bool {
  start_session_once();
  return isset($_POST['_token'], $_SESSION['csrf'])
      && hash_equals($_SESSION['csrf'], $_POST['_token']);
}

/**
 * Rejects a POST whose CSRF token does not match the session's.
 *
 * @param string|null $redirect Where to send the visitor when the check fails.
 *        A mismatch is usually a stale form rather than an attack — signing out
 *        clears the token, so any page left open beforehand now carries an old
 *        one. Given somewhere to go, this says so and lets them try again
 *        instead of dead-ending on a bare error page. Without it (JSON
 *        endpoints, where a redirect would only confuse the caller) the request
 *        is refused outright, as before.
 */
function csrf_verify(?string $redirect = null): void {
  start_session_once();
  if (csrf_valid()) {
    return;
  }

  if ($redirect !== null) {
    // A fresh token is issued with the next page, so the retry will work.
    flash('error', 'Your session expired before that was submitted. Please try again.');
    header('Location: ' . $redirect);
    exit;
  }

  http_response_code(419);
  exit('Invalid CSRF token');
}

function flash(string $key, string $msg = null): ?string {
  start_session_once();
  if ($msg === null) {
    if (!empty($_SESSION['flash'][$key])) { $m = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $m; }
    return null;
  }
  $_SESSION['flash'][$key] = $msg; return null;
}

/* ---------------------------------------------------------------------------
   Slowing down password guessing.

   Sign-in is ID + password — no username — and the IDs are guessable by
   design: student numbers run in sequence, staff IDs follow FAC-2026-00n. With
   nothing counting failures, a script could work through passwords against a
   known ID as fast as the server answered.

   Two scopes are counted, because either one alone has a hole:

     acct:<id>|<ip>  one account from one machine. Keyed on the machine as well
                     as the account so that hammering somebody else's ID cannot
                     lock them out of their own session — a lockout that anyone
                     can trigger on anyone is its own denial of service.
     ip:<ip>         any account from one machine, which catches the same script
                     walking a list of IDs instead of a list of passwords.

   A lock expires by itself. Nothing here needs an administrator to undo it.
   --------------------------------------------------------------------------- */
const LOGIN_WINDOW_MINUTES  = 15;   // failures older than this stop counting
const LOGIN_LOCK_MINUTES    = 15;   // how long a lock lasts
const LOGIN_MAX_PER_ACCOUNT = 5;    // one account from one machine
const LOGIN_MAX_PER_IP      = 20;   // any account from one machine

function login_client_ip(): string {
  /* REMOTE_ADDR only. A forwarded-for header is written by the client and can
     say anything, so trusting it would let an attacker pick a fresh scope on
     every request and walk straight past the counter. */
  return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** The scopes a sign-in attempt counts against, with the cap for each. */
function login_throttle_scopes(string $identifier): array {
  $ip = login_client_ip();
  return [
    'acct:' . mb_strtolower(trim($identifier)) . '|' . $ip => LOGIN_MAX_PER_ACCOUNT,
    'ip:' . $ip                                            => LOGIN_MAX_PER_IP,
  ];
}

/**
 * Seconds left on a lock, or null when the attempt may go ahead.
 */
function login_throttle_locked_for(string $identifier): ?int {
  $conn = db();
  $worst = null;
  foreach (array_keys(login_throttle_scopes($identifier)) as $scope) {
    $q = $conn->prepare(
      "SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs
         FROM login_attempts
        WHERE scope = ? AND locked_until IS NOT NULL AND locked_until > NOW()");
    $q->bind_param('s', $scope);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if ($row && (int)$row['secs'] > 0) {
      $worst = max((int)$worst, (int)$row['secs']);
    }
  }
  return $worst;
}

/** Record one failed attempt, locking the scope if it has run out of tries. */
function login_throttle_record_failure(string $identifier): void {
  $conn = db();

  /* Rows are never read again once their window and lock have both passed, and
     there is no cron to sweep them, so an occasional tidy keeps the table from
     growing for the life of the system. Once in fifty is often enough. */
  if (random_int(1, 50) === 1) {
    $conn->query("DELETE FROM login_attempts
                   WHERE last_at < NOW() - INTERVAL 1 DAY
                     AND (locked_until IS NULL OR locked_until < NOW())");
  }

  foreach (login_throttle_scopes($identifier) as $scope => $cap) {
    /* A row older than the window starts again from one, so an honest typo
       last week does not count towards today's total. */
    $stmt = $conn->prepare(
      "INSERT INTO login_attempts (scope, attempts, first_at, last_at)
            VALUES (?, 1, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
            attempts = IF(first_at < NOW() - INTERVAL ? MINUTE, 1, attempts + 1),
            first_at = IF(first_at < NOW() - INTERVAL ? MINUTE, NOW(), first_at),
            last_at  = NOW()");
    $win = LOGIN_WINDOW_MINUTES;
    $stmt->bind_param('sii', $scope, $win, $win);
    $stmt->execute();

    $lock = $conn->prepare(
      "UPDATE login_attempts
          SET locked_until = NOW() + INTERVAL ? MINUTE
        WHERE scope = ? AND attempts >= ?");
    $mins = LOGIN_LOCK_MINUTES;
    $lock->bind_param('isi', $mins, $scope, $cap);
    $lock->execute();
  }
}

/** A correct password wipes the slate for that account. */
function login_throttle_clear(string $identifier): void {
  $conn = db();
  foreach (array_keys(login_throttle_scopes($identifier)) as $scope) {
    if (strpos($scope, 'acct:') !== 0) { continue; }   // the per-IP tally stands
    $del = $conn->prepare("DELETE FROM login_attempts WHERE scope = ?");
    $del->bind_param('s', $scope);
    $del->execute();
  }
}

/** What to tell someone who is locked out. */
function login_throttle_message(int $seconds): string {
  $mins = max(1, (int)ceil($seconds / 60));
  return 'Too many sign-in attempts. Please try again in '
       . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
}

/* just_logged_in is read once, by the very next page this session renders
   (includes/site_header.php), to decide whether to pop the "what's new"
   notification card in the centre of the screen rather than leaving it
   folded into the bell's corner dropdown. site_header.php unsets it the
   moment it reads it, so it never shows again until the next sign-in. */
function login_user(array $u): void {
    start_session_once();
    session_regenerate_id(true);
    $_SESSION['user'] = $u;
    $_SESSION['just_logged_in'] = true;
}
/**
 * Is the guest pass behind this session still issued?
 *
 * A guest signs in once and the pass's expiry is copied into the session; every
 * page after that only compared that timestamp against the clock. Nothing ever
 * looked at the row again, so revoking a pass did not end the session it had
 * created: the Librarian saw the pass disappear from their console while the
 * guest carried on browsing until the cookie died. "Revoke" has to mean now.
 *
 * Returns true for anyone who is not a guest, so the ordinary sign-in path is
 * untouched.
 */
function guest_pass_still_valid(): bool {
  if (empty($_SESSION['guest_login'])) { return true; }

  // The cheap check first: no query needed once the clock has passed.
  if (!empty($_SESSION['guest_expire']) && time() > (int)$_SESSION['guest_expire']) {
    return false;
  }

  $conn = db();
  if (!empty($_SESSION['guest_id'])) {
    $q = $conn->prepare("SELECT 1 FROM guest_sessions WHERE guest_id = ? AND expires_at > NOW() LIMIT 1");
    $q->bind_param('i', $_SESSION['guest_id']);
  } else {
    /* Sessions created before guest_id was recorded fall back to the username,
       so nobody is thrown out by the upgrade itself. */
    $name = (string)($_SESSION['user']['username'] ?? '');
    if ($name === '') { return false; }
    $q = $conn->prepare("SELECT 1 FROM guest_sessions WHERE username = ? AND expires_at > NOW() LIMIT 1");
    $q->bind_param('s', $name);
  }
  $q->execute();
  return (bool)$q->get_result()->fetch_row();
}

function current_user(): ?array {
  start_session_once();

  /* Checked once per request, and only for guests. Clearing the session rather
     than redirecting keeps this out of the way of logout.php and the login
     pages, which would otherwise be at risk of bouncing in a loop: the page
     simply sees nobody signed in and does whatever it already does about that. */
  static $guestChecked = false;
  if (!$guestChecked && !empty($_SESSION['guest_login'])) {
    $guestChecked = true;
    if (!guest_pass_still_valid()) {
      $_SESSION = [];
      return null;
    }
  }

  return $_SESSION['user'] ?? null;
}
function require_login(): void { if (!current_user()) { header('Location: '.BASE_URL.'/archive/index.php?login_modal=1'); exit; } }
function require_role(array $roles): void { require_login(); $u=current_user(); if(!$u||!in_array($u['user_role'],$roles,true)){ http_response_code(403); exit('Forbidden'); } }
function role_home(string $role): string {
  if($role === 'super_admin') return BASE_URL.'/app/admin/super_admin_review_dashboard.php';
  if($role === 'admin') {
    /* A level-2 admin is the Head of Academic Programs, and there is one desk
       for that job — the same one the head_academic role uses. It used to have
       a second, near-identical page of its own. */
    $u = current_user();
    if (($u['admin_level'] ?? 1) == 2) {
      return BASE_URL.'/app/faculty/head_review_dashboard.php';
    }
    return BASE_URL.'/app/admin/admin_review_dashboard.php';
  }
  if($role === 'faculty') return BASE_URL.'/app/faculty/faculty_review_dashboard.php';
  if($role === 'student') return BASE_URL.'/app/student/student_dashboard.php';
  if($role === 'librarian') return BASE_URL.'/app/librarian/librarian_manage_guests.php';
  if($role === 'head_academic') return BASE_URL.'/app/faculty/head_review_dashboard.php';
  if($role === 'guest') return BASE_URL.'/archive/index.php';
  return BASE_URL.'/index.php';
}

/**
 * The date a paper's research was completed, formatted for display.
 *
 * research_date holds the full date when it is known. Papers uploaded before
 * that field existed only recorded a year, so those fall back to the year on
 * its own rather than inventing a month and day.
 */
function paper_date_display(?string $research_date, $year = null): string {
    if (!empty($research_date) && $research_date !== '0000-00-00') {
        $ts = strtotime($research_date);
        if ($ts) return date('F j, Y', $ts);
    }
    return $year ? (string)(int)$year : '';
}

/**
 * The staff positions an account can hold.
 *
 * A position is what a person is called; the database stores that as a
 * user_role plus, for the two kinds of admin, an admin_level. The mapping was
 * written out separately in FacultyManagementService and AdminManagementService
 * and again in each page's dropdown, so adding a position meant remembering all
 * four. It lives here now.
 *
 * Who may create whom is decided by the page, not by this list:
 *   Research Coordinator  ->  Research Adviser, Librarian
 *   Director              ->  Research Coordinator, Head of Academic Affairs, Librarian
 */
function staff_positions(): array {
    return [
        'Research Adviser'         => ['role' => 'faculty',   'level' => 1],
        'Research Coordinator'     => ['role' => 'admin',     'level' => 1],
        'Head of Academic Affairs' => ['role' => 'admin',     'level' => 2],
        'Librarian'                => ['role' => 'librarian', 'level' => 0],
    ];
}

/** The user_role and admin_level a position maps to, or null if unrecognised. */
function staff_position_map(?string $position): ?array {
    $position = trim((string)$position);
    return staff_positions()[$position] ?? null;
}

/**
 * What to call an account, worked out from what the database holds.
 *
 * Two kinds of record mean Head of Academic Programs — the head_academic role,
 * and an admin at level 2 — so both answer with the same name.
 */
function staff_position_of(array $user): string {
    $role  = $user['user_role'] ?? '';
    $level = (int)($user['admin_level'] ?? 1);
    if ($role === 'head_academic') return 'Head of Academic Affairs';
    if ($role === 'librarian')     return 'Librarian';
    if ($role === 'faculty')       return 'Research Adviser';
    if ($role === 'admin')         return $level === 2 ? 'Head of Academic Affairs' : 'Research Coordinator';
    return trim((string)($user['title'] ?? '')) ?: 'Staff';
}

/**
 * What to call an account, whoever holds it.
 *
 * There were two of these and neither covers everybody:
 *
 *   staff_position_of() is for staff, and its return values are the keys the
 *   Director's console groups by, so it cannot be widened without moving that
 *   page's furniture. A student or the Director falls past every branch in it
 *   and comes out as "Staff".
 *
 *   role_label() knows the Director but calls a Research Adviser a "Faculty
 *   Adviser" and a Head of Academic Programs a "Records Officer", neither of
 *   which is what the rest of the system calls them.
 *
 * This one is for any list that can hold anybody — a roll of password changes,
 * a notice about an account — and uses the names the project uses everywhere
 * else. Neither of the other two is changed: both are correct where they are.
 */
function account_position(array $user): string {
    $role  = (string)($user['user_role'] ?? '');
    $level = (int)($user['admin_level'] ?? 1);

    if ($role === 'student')       return 'Student';
    if ($role === 'faculty')       return 'Research Adviser';
    if ($role === 'librarian')     return 'Librarian';
    if ($role === 'head_academic') return 'Head of Academic Programs';
    if ($role === 'super_admin')   return 'Director';
    if ($role === 'admin') {
        return $level === 2 ? 'Head of Academic Programs' : 'Research Coordinator';
    }
    if ($role === 'guest')         return 'Guest';
    // Anything unrecognised is named after itself rather than guessed at.
    return trim((string)($user['title'] ?? '')) ?: ucwords(str_replace('_', ' ', $role));
}

/**
 * The ID an account is known by, and what that ID is called.
 *
 * The column is chosen by role, never by which one happens to hold something:
 * student_id is a student's, faculty_id belongs to everyone on the staff. The
 * two places that used to take whichever was non-empty both showed a Research
 * Adviser's staff number under the heading "Student ID", because some staff
 * rows still carry a value in student_id left by an older creation path.
 *
 * That stray value is read as a last resort rather than shown as nothing, since
 * it is a staff number wherever it is stored and the account still has to be
 * identifiable. It is reported as a Faculty ID either way, because that is what
 * it is.
 *
 * @return array{label:string, value:string}
 */
function account_identifier(array $user): array {
    $role = (string)($user['user_role'] ?? '');
    $student = trim((string)($user['student_id'] ?? ''));
    $staff   = trim((string)($user['faculty_id'] ?? ''));

    if ($role === 'student') {
        return ['label' => 'Student ID', 'value' => $student];
    }
    if ($role === 'guest') {
        return ['label' => 'Guest username', 'value' => trim((string)($user['username'] ?? ''))];
    }

    $label = ($role === 'librarian') ? 'Librarian ID' : 'Faculty ID';
    return ['label' => $label, 'value' => $staff !== '' ? $staff : $student];
}

/* Who may be asking for one. Only the roles that hold an account somebody else
   issued: a guest has no account to correct and nobody to ask. Shared by the
   form that takes the request and the page that lists what has arrived. */
function support_requester_roles(): array {
    return [
        'student'       => 'Student',
        'faculty'       => 'Research Adviser',
        'librarian'     => 'Librarian',
        'admin'         => 'Research Coordinator',
        'head_academic' => 'Head of Academic Programs',
    ];
}

/* The desks a request can be addressed to.

   Not every role: only the three that issue and correct accounts, which is the
   same chain a password change is reported along. Keyed by the value the form
   posts, so the label and the lookup cannot drift apart. */
function support_handler_roles(): array {
    return [
        'faculty'     => 'Research Adviser',
        'admin'       => 'Research Coordinator',
        'super_admin' => 'Director',
    ];
}

/**
 * The desks that could have set up a given kind of account.
 *
 * Accounts are issued down a chain: an adviser or the Coordinator enrols a
 * student, the Coordinator creates advisers and librarians, the Director
 * creates the Coordinator and the Head of Academic Programs. Offering all three
 * desks to everybody meant a Coordinator was asked to choose between an adviser,
 * themselves and the Director, when only one of those can ever have made their
 * account.
 *
 * @return array<string, string> role => label, a subset of support_handler_roles()
 */
function support_handler_roles_for(string $requesterRole): array {
    $all = support_handler_roles();
    switch ($requesterRole) {
        case 'student':
            /* The adviser, and only the adviser: that is who a student deals
               with, and asking them to weigh up the Coordinator instead is the
               choice they are least placed to make. The Coordinator does enrol
               students, and where one did, the request is routed to them by
               support_creator_of() regardless of what was offered here. */
            $keep = ['faculty'];
            break;
        case 'faculty':
        case 'librarian':
            /* The Coordinator creates both, but the Director can and does too:
               the librarian on this system was made by the Director. */
            $keep = ['admin', 'super_admin'];
            break;
        case 'admin':
        case 'head_academic':
            $keep = ['super_admin'];
            break;
        default:
            $keep = array_keys($all);
    }
    return array_intersect_key($all, array_flip($keep));
}

/**
 * Who actually created an account, when that person can still act on it.
 *
 * `created_by` is the record of who issued the account, and it is the honest
 * answer to "who do I ask". It is not always usable: the desk may have been
 * deactivated, or may hold a role that cannot issue a password (a Head of
 * Academic Programs reads, it does not enrol). In those cases the Director
 * answers, being the desk of last resort.
 *
 * @return array{id:int, name:string, role:string}|null
 */
function support_creator_of(int $userId): ?array {
    $conn = db();
    $stmt = $conn->prepare(
        "SELECT c.user_id, c.full_name, c.user_role, c.admin_level, c.is_active
           FROM users u
           JOIN users c ON c.user_id = u.created_by
          WHERE u.user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($c && (int)$c['is_active'] === 1) {
        $role = (string)$c['user_role'];
        // Level 2 is the Head of Academic Programs, which is not a desk that issues.
        $ok = $role === 'faculty' || $role === 'super_admin'
           || ($role === 'admin' && (int)$c['admin_level'] !== 2);
        if ($ok) {
            return ['id' => (int)$c['user_id'], 'name' => (string)$c['full_name'], 'role' => $role];
        }
    }

    $res = $conn->query(
        "SELECT user_id, full_name FROM users
          WHERE user_role = 'super_admin' AND is_active = 1
          ORDER BY user_id LIMIT 1");
    $dir = $res ? $res->fetch_assoc() : null;
    return $dir
        ? ['id' => (int)$dir['user_id'], 'name' => (string)$dir['full_name'], 'role' => 'super_admin']
        : null;
}

/**
 * The people currently holding one of those desks.
 *
 * Names only, and only of accounts that are still active: this list is shown on
 * the public contact form so somebody who has lost their password can say who
 * their adviser is. It carries nothing that is not already on a paper's record
 * or a review desk.
 *
 * @return array<string, array<int, array{id:int, name:string}>> role => people
 */
function support_handlers(): array {
    $out = [];
    foreach (array_keys(support_handler_roles()) as $role) $out[$role] = [];

    $conn = db();
    $res = $conn->query(
        "SELECT user_id, full_name, user_role, admin_level
           FROM users
          WHERE is_active = 1
            AND (user_role IN ('faculty','super_admin')
                 OR (user_role = 'admin' AND admin_level = 1))
          ORDER BY full_name");
    if (!$res) return $out;

    while ($r = $res->fetch_assoc()) {
        $role = $r['user_role'];
        if (!isset($out[$role])) continue;
        $out[$role][] = ['id' => (int)$r['user_id'], 'name' => (string)$r['full_name']];
    }
    return $out;
}

/**
 * The fields of an account worth telling its owner about, and what to call them.
 *
 * Kept in one place because three consoles write to these rows and each knows
 * only its own subset; the diff below has to read the same way whichever desk
 * made the change.
 */
function account_notice_fields(): array {
    return [
        'full_name'     => 'Name',
        'email'         => 'Email',
        'student_id'    => 'Student ID',
        'faculty_id'    => 'Faculty ID',
        'program'       => 'Programme',
        'academic_year' => 'Academic year',
        'section'       => 'Section',
        'expires_on'    => 'Account expires',
    ];
}

/**
 * What an account looks like right now, for comparing against afterwards.
 *
 * Taken before and after the write rather than from the posted form, so it works
 * the same for the two consoles that edit through a service and cannot see the
 * columns being set.
 */
function account_snapshot(int $userId): array {
    if ($userId <= 0) return [];
    $cols = implode(', ', array_keys(account_notice_fields()));
    $conn = db();
    $stmt = $conn->prepare("SELECT $cols FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

/**
 * Tell somebody what was just changed on their account.
 *
 * Both ways at once: an email they can keep, and a notification waiting in the
 * app. A student whose password has just been reset cannot sign in to read a
 * notification, and a person whose email address was wrong cannot be reached by
 * email — one of the two will land.
 *
 * The new password is included when there is one. That is a deliberate choice
 * and not a small one: it puts a working password in a mailbox. It is here
 * because the alternative was worse in practice — the password was read out or
 * messaged instead, and the person often never learned their account had been
 * touched at all. Anyone reading this later should know the trade was made
 * knowingly.
 *
 * @return bool whether anything was worth sending
 */
function account_change_notice(int $userId, array $before, array $after,
                               string $newPassword = '', string $byName = ''): bool {
    $labels  = account_notice_fields();
    $changes = [];

    foreach ($labels as $col => $label) {
        $was = trim((string)($before[$col] ?? ''));
        $now = trim((string)($after[$col]  ?? ''));
        if ($was === $now || $now === '') continue;
        $changes[$label] = $now;
    }
    if ($newPassword !== '') {
        $changes['Password'] = $newPassword;
        /* On the same roll as a self-change in Settings, so a desk reading it
           sees every new password this account has had, whoever issued it. */
        $actor = current_user();
        password_change_record($userId, (int)($actor['user_id'] ?? 0) ?: null);
    }
    if (!$changes) return false;

    $to = trim((string)($after['email'] ?? ''));
    $who = trim($byName);
    $line = 'Your ' . APP_NAME . ' account was updated'
          . ($who !== '' ? ' by ' . $who : '') . '.';

    // ---- the notification: one line in the list, the detail behind it -------
    $detail = [];
    foreach ($changes as $label => $value) $detail[] = $label . ': ' . $value;
    $summary = $line . ' ' . (count($changes) === 1
        ? 'The ' . strtolower((string)array_key_first($changes)) . ' was changed.'
        : count($changes) . ' details were changed.');
    create_notification_raw($userId, 'account', $summary . "\n" . implode("\n", $detail));

    // ---- and the email -----------------------------------------------------
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return true;

    $body  = email_para('Good day ' . ($after['full_name'] ?? '') . ',');
    $body .= email_para($line . ' Here is what it now says.');
    $body .= email_details($changes, isset($changes['Password']) ? ['Password'] : []);
    if (isset($changes['Password'])) {
        $body .= email_para('Sign in with that password, then change it to one of your own '
            . 'from Settings. Anyone who can read this message can read the password in it.');
    }
    $body .= email_para('If you did not ask for this, say so to whoever set up your account, '
        . 'as soon as you can.');
    $body .= email_action('Sign in to ' . APP_NAME, BASE_URL . '/archive/index.php');

    send_email($to, 'Your ' . APP_NAME . ' account was updated', $body);

    /* When the address itself changed, the old one is told too: otherwise the
       only warning that an account has moved goes to the address it moved to. */
    $old = trim((string)($before['email'] ?? ''));
    if ($old !== '' && $old !== $to && filter_var($old, FILTER_VALIDATE_EMAIL)) {
        send_email($old, 'Your ' . APP_NAME . ' account was updated', $body);
    }
    return true;
}

/**
 * Note that an account's password changed, and who changed it.
 *
 * Written from two places that look nothing alike: Settings, where somebody
 * changes their own, and the three management consoles, where a desk resets one
 * for somebody who cannot get in. Both belong on the same roll — what the reader
 * wants to know is when this account last had a new password, however it came
 * about — so both come through here.
 *
 * @param int|null $byUserId who did it; null means they did it themselves
 */
function password_change_record(int $userId, ?int $byUserId = null): void {
    if ($userId <= 0) return;
    $conn = db();
    $stmt = $conn->prepare(
        "INSERT INTO password_changes (user_id, changed_by, changed_at) VALUES (?,?, NOW())");
    $by = $byUserId ?: $userId;      // their own doing, when nobody else is named
    $stmt->bind_param('ii', $userId, $by);
    $stmt->execute();
    $stmt->close();
}

/**
 * A notification with no email attached.
 *
 * create_notification() sends one as well, which is right for the review
 * workflow but wrong here: account_change_notice() writes its own, and using
 * the other would send two messages saying different things.
 */
function create_notification_raw(int $userId, string $type, string $message): void {
    $conn = db();
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, paper_id, notification_type, message)
         VALUES (?, NULL, ?, ?)");
    $stmt->bind_param('iss', $userId, $type, $message);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear the requests an action has just answered.
 *
 * A request is a question: "give me a new password", "my surname is wrong". Once
 * the password has been reset or the details saved, the question has been
 * answered and the row is of no further use — leaving it would have every desk
 * working a list that only ever grows, with no way to tell what is still owed.
 *
 * Deleted rather than flagged: the request carries nothing the account and its
 * own audit trail do not already hold, and password_changes records the reset.
 *
 * The notification that announced it is deliberately left alone. That is a log
 * of what happened, and it stays true after the request is gone.
 *
 * @param  string $kind 'password' or 'account'
 * @return int          how many were cleared
 */
function support_requests_clear(int $userId, string $kind): int {
    if ($userId <= 0 || !in_array($kind, ['password', 'account'], true)) return 0;
    $conn = db();

    /* Read before deleting: the row carries the address to write to, and the
       person who asked is the one party who otherwise never hears that anything
       happened. */
    $find = $conn->prepare(
        "SELECT * FROM support_requests WHERE requester_user_id = ? AND kind = ?");
    $find->bind_param('is', $userId, $kind);
    $find->execute();
    $rows = $find->get_result()->fetch_all(MYSQLI_ASSOC);
    $find->close();
    foreach ($rows as $r) support_request_settled_email($r);

    $stmt = $conn->prepare(
        "DELETE FROM support_requests WHERE requester_user_id = ? AND kind = ?");
    $stmt->bind_param('is', $userId, $kind);
    $stmt->execute();
    $gone = $stmt->affected_rows;
    $stmt->close();
    return (int)$gone;
}

/**
 * Tell whoever asked that their request has been dealt with.
 *
 * Sent to the address they gave on the form rather than the one on the account:
 * somebody asking to have a wrong email corrected cannot be written to at the
 * wrong email.
 *
 * The new password is never in here. It is shown once, to the person who issued
 * it, and it is theirs to hand over — putting it in an email would undo that
 * and put it somewhere it can be read later.
 */
function support_request_settled_email(array $r): bool {
    $to = trim((string)($r['requester_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $password = ($r['kind'] ?? '') === 'password';
    $body  = email_para('Good day ' . ($r['requester_name'] ?? '') . ',');
    $body .= email_para($password
        ? 'Your request for a new password has been dealt with. A new password has been '
          . 'issued for your account and the person who set it up will give it to you. '
          . 'Nobody can read your old one back to you, which is why a new one was made.'
        : 'The correction you asked for has been made to your account. Sign in and check '
          . 'that everything now reads as it should.');
    $body .= email_details([
        'Requested' => date('j F Y, g:i A', strtotime((string)($r['created_at'] ?? 'now'))),
        'About'     => $password ? 'A new password' : 'A correction to your details',
    ]);
    /* Points at the person who acted, not at an office: they are the one who can
       undo it, and they are named on the request the reader already sent. */
    $body .= email_para('If you did not ask for this, say so to the person who set up your '
        . 'account, as soon as you can.');

    return send_email($to,
        $password ? 'Your new password is ready' : 'Your account has been corrected',
        $body);
}

/**
 * The console that answers for a given kind of account.
 *
 * A request is about somebody's account, and acting on it means opening the
 * roll that account sits on. Which roll depends on who is asking, not on who is
 * reading.
 */
function support_console_for(string $requesterRole): string {
    if ($requesterRole === 'student') {
        return BASE_URL . '/app/faculty/faculty_manage_students.php';
    }
    if ($requesterRole === 'faculty' || $requesterRole === 'librarian') {
        return BASE_URL . '/app/admin/admin_manage_faculty.php';
    }
    return BASE_URL . '/app/admin/super_admin_manage_admins.php';
}

/**
 * Whether a student currently holds an open grant on one paper's manuscript.
 *
 * Lazy expiry: a lapsed grant's row is left exactly as it was written and
 * simply stops counting once expires_at is in the past, rather than being
 * flipped or deleted by a job that has to run on schedule. guest_sessions and
 * student_expiry_date() both work the same way.
 */
function student_manuscript_access(int $studentUserId, int $paperId): bool {
    $conn = db();
    $stmt = $conn->prepare(
        "SELECT 1 FROM manuscript_requests
          WHERE student_user_id = ? AND paper_id = ? AND status = 'granted' AND expires_at > NOW()
          LIMIT 1");
    $stmt->bind_param('ii', $studentUserId, $paperId);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

/** How many manuscripts a student currently holds open access to, across every paper. */
function student_manuscript_active_count(int $studentUserId): int {
    $conn = db();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM manuscript_requests
          WHERE student_user_id = ? AND status = 'granted' AND expires_at > NOW()");
    $stmt->bind_param('i', $studentUserId);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int)$n;
}

/** The most recent request a student has made for one paper's manuscript, or null. */
function student_manuscript_request(int $studentUserId, int $paperId): ?array {
    $conn = db();
    $stmt = $conn->prepare(
        "SELECT * FROM manuscript_requests
          WHERE student_user_id = ? AND paper_id = ?
          ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('ii', $studentUserId, $paperId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * The programmes PUP Biñan offers, and the short code each is known by.
 *
 * The full name is what the database stores and what a student sees on their
 * own record; the code is what fits in a table column or a filter chip. This
 * map lived inside the Manage Students page, which meant the analytics tables
 * had to print the whole name — "Bachelor of Science in Information
 * Technology" in a column beside a date.
 */
function programs_map(): array {
    return [
        'Bachelor of Science in Information Technology'   => 'BSIT',
        'Bachelor of Science in Industrial Engineering'   => 'BSIE',
        'Bachelor of Science in Computer Engineering'     => 'BSCPE',
        'Bachelor of Secondary Education major in English'        => 'BSED-ENG',
        'Bachelor of Secondary Education major in Social Studies' => 'BSED-SS',
        'Bachelor of Elementary Education'                => 'BEED',
        'Bachelor of Science in Psychology'               => 'BSPSYCH',
        'Diploma in Information Technology'               => 'DIT',
        'Diploma in Computer Engineering Technology'      => 'DCPET',
        'Bachelor of Science in Business Administration major in Human Resource Management' => 'BSBA-HRM',
    ];
}

/** The short code for a programme, or the name itself if it is not a known one. */
function program_code(?string $program): string {
    $program = trim((string)$program);
    if ($program === '') return '';
    return programs_map()[$program] ?? $program;
}

/**
 * How many academic years a student account is meant to last.
 *
 * The section says how far through the course someone is, so it also says how
 * much longer they will need the account: a first year has five ahead of them,
 * a second year four, and so on down to two for a fourth year. A ladderized
 * intake is two years by its own reckoning.
 *
 * Anything the rule does not recognise returns null — an unfamiliar section is
 * a reason to leave the account alone, not to guess a date and lock someone out.
 */
function student_account_years(?string $section): ?int {
    $section = trim((string)$section);
    if ($section === '') return null;
    if (strcasecmp($section, 'ladderized') === 0) return 2;
    // "4-1", "4 - 1", "4A" — only the year level in front matters.
    if (preg_match('/^\s*([1-4])\b/', $section, $m)) {
        return 6 - (int)$m[1];       // 1 -> 5, 2 -> 4, 3 -> 3, 4 -> 2
    }
    return null;
}

/**
 * The date a student account stops working.
 *
 * Counted in academic years from the one they were enrolled in, because that is
 * what the life is expressed in — a first year starting A.Y. 26-27 is good
 * through A.Y. 30-31, so the account lapses at the end of July 2031. With no
 * academic year on file the account's own creation date stands in for it.
 *
 * Returns null when the section carries no rule, meaning "no expiry".
 */
function student_expiry_date(?string $section, ?string $academicYear, ?string $createdAt = null): ?string {
    $years = student_account_years($section);
    if ($years === null) return null;

    $startYear = null;
    if (preg_match('/(\d{2,4})\s*[-\/]\s*\d{2,4}/', (string)$academicYear, $m)) {
        $startYear = (int)$m[1];
        if ($startYear < 100) $startYear += 2000;
    }
    if ($startYear === null) {
        // An academic year begins mid-calendar-year, so anything made before
        // June belongs to the intake that started the previous calendar year.
        $ts = $createdAt ? strtotime($createdAt) : time();
        if (!$ts) $ts = time();
        $startYear = (int)date('n', $ts) >= 6 ? (int)date('Y', $ts) : (int)date('Y', $ts) - 1;
    }
    return sprintf('%04d-07-31', $startYear + $years);
}

// Human-readable job title for a role, used under the user's name in the
// header dropdown and on the settings page.
function role_label(?array $u): string {
  if (!$u) return 'Guest';
  $role = $u['user_role'] ?? '';
  if ($role === 'super_admin')   return 'Director';
  if ($role === 'head_academic') return 'Head of Academic Programs';
  if ($role === 'faculty')       return 'Faculty Adviser';
  if ($role === 'student')       return 'Student Researcher';
  if ($role === 'librarian')     return 'Librarian';
  if ($role === 'guest')         return 'Guest';
  if ($role === 'admin') {
    return (($u['admin_level'] ?? 1) == 2) ? 'Records Officer' : 'Research Coordinator';
  }
  return ucwords(str_replace('_', ' ', (string)$role));
}

// ---- Helpers for workflow/notifications ----
function get_user(int $user_id): ?array {
  $conn = db();
  $stmt = $conn->prepare("SELECT user_id, username, email, full_name, user_role, created_by, is_active FROM users WHERE user_id=? LIMIT 1");
  $stmt->bind_param('i',$user_id); $stmt->execute(); $res=$stmt->get_result();
  return $res->fetch_assoc() ?: null;
}

function creator_of(int $user_id): ?int {
  $conn = db();
  $stmt = $conn->prepare("SELECT created_by FROM users WHERE user_id=?");
  $stmt->bind_param('i',$user_id); $stmt->execute(); $stmt->bind_result($cid); $stmt->fetch(); return $cid ?: null;
}

function create_notification(int $user_id, ?int $paper_id, string $type, string $message): void {
  $conn = db();
  $stmt = $conn->prepare("INSERT INTO notifications (user_id, paper_id, notification_type, message) VALUES (?,?,?,?)");
  $stmt->bind_param('iiss',$user_id,$paper_id,$type,$message);
  $stmt->execute();

  // ── Also send a real Gmail email to the user ──
  try {
    $recipient = get_user($user_id);
    if ($recipient && !empty($recipient['email'])) {
      // Map notification types to friendly email subject lines
      $subjects = [
        'submission'  => 'New Paper Submission Requires Your Review',
        'progress'    => 'Your Paper Has Been Approved & Forwarded',
        'approved'    => 'Congratulations! Your Paper Has Been Fully Approved',
        'decline'     => 'Your Paper Needs Revisions',
        'reminder'    => 'PAPEL Reminder: Papers Pending Your Action',
      ];
      $subject = $subjects[$type] ?? ('PAPEL Notification: ' . ucfirst($type));
      send_email($recipient['email'], $subject, $message);
    }
  } catch (\Throwable $e) {
    // Never let email failure block the main workflow
    error_log("Email notification failed for user {$user_id}: " . $e->getMessage());
  }
}

// Send email using PHPMailer (Gmail SMTP) with fallback to log
/**
 * Wraps email content in a branded, email-safe HTML layout using the system
 * palette (maroon / gold). Uses table layout + inline styles so it renders
 * consistently across mail clients (Gmail, Outlook, Apple Mail).
 *
 * @param string $bodyHtml  The message content (HTML; line breaks already handled).
 * @param string $heading    Optional heading shown above the content.
 */
/**
 * The building blocks a message body is written from.
 *
 * The bodies used to be plain text glued together with newlines, so a set of
 * credentials arrived as a run of "Label: value" lines that read nothing like
 * the letterhead around them. These keep the four account emails consistent
 * with each other and with email_layout(), without every caller carrying its
 * own table markup.
 *
 * Everything returns a single line: send_email() runs the body through
 * nl2br(), and a newline inside the markup would come out as a stray break.
 */
function email_para(string $text): string {
    return '<p style="margin:0 0 14px;">' . htmlspecialchars($text) . '</p>';
}

/**
 * A labelled list, the way a form or a record is set out.
 *
 * @param array $pairs  label => value. A value listed in $mono is set in a
 *                      monospaced face, which is what an ID or a password
 *                      wants so that a letter O and a zero can be told apart.
 */
function email_details(array $pairs, array $mono = []): string {
    $ink   = '#2B2422';
    $muted = '#6E6663';
    $out = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
         . 'style="margin:0 0 16px;font-size:14px;">';
    foreach ($pairs as $label => $value) {
        if ($value === null || $value === '') continue;
        $face = in_array($label, $mono, true)
            ? 'font-family:Consolas,"Courier New",monospace;letter-spacing:.3px;'
            : '';
        $out .= '<tr>'
              . '<td style="padding:3px 18px 3px 0;color:' . $muted . ';white-space:nowrap;">'
              . htmlspecialchars($label) . '</td>'
              . '<td style="padding:3px 0;color:' . $ink . ';' . $face . '">'
              . htmlspecialchars((string)$value) . '</td>'
              . '</tr>';
    }
    return $out . '</table>';
}

/** A single call to action, set as a plain link rather than a coloured pill. */
function email_action(string $label, string $url): string {
    return '<p style="margin:0 0 14px;">' . htmlspecialchars($label) . ' '
         . '<a href="' . htmlspecialchars($url) . '" '
         . 'style="color:#820707;text-decoration:underline;">'
         . htmlspecialchars($url) . '</a></p>';
}

function email_layout(string $bodyHtml, string $heading = ''): string {
  $appName = defined('APP_NAME') ? APP_NAME : 'PAPEL';
  $year    = date('Y');

  /* PUP maroon, the same values the site itself uses (--accent and
     --accent-dark in includes/theme.php), so a message looks like it came from
     the system a reader has just been using. */
  $maroon   = '#820707';
  $maroonDk = '#630000';
  $ink      = '#2B2422';   // body text
  $muted    = '#6E6663';   // secondary text
  $rule     = '#DED5D3';   // hairlines
  $ground   = '#F1EDEC';   // behind the sheet

  /* A serif for the institutional lines and headings, a system sans for
     running text. Georgia is one of the few faces that is genuinely present
     across mail clients, and it carries the academic register the plain
     sans-serif was missing. */
  $serif = "Georgia,'Times New Roman',Times,serif";
  $sans  = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

  $headingHtml = $heading !== ''
    ? '<tr><td style="padding:34px 44px 0;font-family:' . $serif . ';">
         <h1 style="margin:0;color:' . $maroon . ';font-size:21px;font-weight:normal;line-height:1.4;">'
           . htmlspecialchars($heading) . '</h1>
         <div style="border-top:2px solid ' . $maroon . ';width:38px;margin-top:14px;font-size:0;line-height:0;">&nbsp;</div>
       </td></tr>'
    : '';

  return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>:root{color-scheme:light;supported-color-schemes:light;}</style>
</head>
<body style="margin:0;padding:0;background-color:' . $ground . ';-webkit-text-size-adjust:100%;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $ground . ';">
    <tr><td align="center" style="padding:32px 16px;">

      <!-- The sheet. Square corners and a hairline border rather than a
           rounded, shadowed card: this is correspondence from a university,
           and it should read like a letter rather than a notification. -->
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border:1px solid ' . $rule . ';">

        <!-- Letterhead. The institution is named first, the system second. -->
        <tr><td style="background-color:' . $maroon . ';padding:26px 44px 22px;" bgcolor="' . $maroon . '">
          <div style="font-family:' . $serif . ';font-size:15px;font-weight:normal;color:#ffffff;line-height:1.45;">
            Polytechnic University of the Philippines
          </div>
          <div style="font-family:' . $sans . ';font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#E8C9C9;line-height:1.4;margin-top:3px;">
            Bi&ntilde;an Campus
          </div>
        </td></tr>

        <!-- A narrower band carries the system name, so the two identities stay
             distinct instead of competing in one block. -->
        <tr><td style="background-color:' . $maroonDk . ';padding:11px 44px;" bgcolor="' . $maroonDk . '">
          <div style="font-family:' . $sans . ';font-size:12px;letter-spacing:3px;text-transform:uppercase;color:#ffffff;line-height:1;">
            ' . htmlspecialchars($appName) . '
            <span style="color:#C98C8C;letter-spacing:0;text-transform:none;font-size:12px;">&nbsp; Research Repository</span>
          </div>
        </td></tr>

        ' . $headingHtml . '

        <!-- Content -->
        <tr><td style="padding:' . ($heading !== '' ? '20px' : '34px') . ' 44px 34px;font-family:' . $sans . ';font-size:15px;line-height:1.7;color:' . $ink . ';">
          <div style="color:' . $ink . ';">' . $bodyHtml . '</div>
        </td></tr>

        <!-- Sign-off -->
        <tr><td style="padding:0 44px 30px;font-family:' . $sans . ';font-size:15px;line-height:1.7;color:' . $ink . ';">
          <div style="border-top:1px solid ' . $rule . ';padding-top:18px;">
            <div style="font-family:' . $serif . ';font-size:15px;color:' . $maroon . ';">The Research Office</div>
            <div style="font-size:13px;color:' . $muted . ';margin-top:2px;">PUP Bi&ntilde;an Campus</div>
          </div>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background-color:#FAF7F6;border-top:1px solid ' . $rule . ';padding:18px 44px 20px;font-family:' . $sans . ';font-size:11px;line-height:1.7;color:' . $muted . ';">
          This message was sent automatically. Please do not reply to it.<br>
          &copy; ' . $year . ' Polytechnic University of the Philippines, Bi&ntilde;an Campus. All rights reserved.
        </td></tr>

      </table>

    </td></tr>
  </table>
</body>
</html>';
}

/**
 * Send one email. Returns whether it actually went.
 *
 * This used to return void, so every caller announced "credentials sent to
 * email" whether or not anything was sent — and SMTP authentication has been
 * failing, so for a long time nothing was. A caller that cannot tell the
 * difference cannot warn anybody, which is the worst of both.
 *
 * A failed send still writes the message to the notifications log, so the
 * content is recoverable; false means "assume they did not receive it".
 */
function send_email(string $to, string $subject, string $body): bool {
  // Load Composer autoloader if available
  if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
  }

  // If class not loaded by composer, try manual paths
  if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
      if (file_exists(__DIR__ . '/../mailer/PHPMailer.php')) {
        require_once __DIR__ . '/../mailer/Exception.php';
        require_once __DIR__ . '/../mailer/PHPMailer.php';
        require_once __DIR__ . '/../mailer/SMTP.php';
      }
  }

  if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->Password   = SMTP_PASS;
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = SMTP_PORT;

      /* The authenticated account, not a hardcoded one. Gmail will not send as
         an address the session has not been authorised for, and the two had
         drifted apart: this said pupbinanRepository@, while SMTP_USER is the
         clarionlessonview account the password belongs to. */
      $mail->setFrom(SMTP_USER, APP_NAME);
      $mail->addAddress($to);
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = email_layout(nl2br($body), $subject);
      $mail->AltBody = strip_tags($body);

      $mail->send();
      return true;
    } catch (Exception $e) {
      error_log("Mailer Error: {$mail->ErrorInfo}");
    }
  }

  // Fallback: Log to file if PHPMailer missing or failed
  $logDir = __DIR__ . '/notifications';
  if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
  }
  $log = $logDir . '/notifications.log';
  @file_put_contents($log, '[' . now() . "] to={$to} subj={$subject}\n{$body}\n\n", FILE_APPEND);
  return false;   // logged, but nobody received it
}

function paper_owner(int $paper_id): ?int {
  $conn = db(); $stmt=$conn->prepare("SELECT uploaded_by FROM research_papers WHERE paper_id=?");
  $stmt->bind_param('i',$paper_id); $stmt->execute(); $stmt->bind_result($uid); $stmt->fetch(); return $uid ?: null;
}

function set_status(int $paper_id, string $status): void {
  $conn = db(); $stmt = $conn->prepare("UPDATE research_papers SET current_status=? WHERE paper_id=?");
  $stmt->bind_param('si',$status,$paper_id); $stmt->execute();
}

function add_workflow(int $paper_id, int $reviewer_id, string $level, string $status, string $feedback=''): void {
  $conn = db();
  $stmt=$conn->prepare("INSERT INTO approval_workflow (paper_id,reviewer_id,review_level,status,feedback,reviewed_at) VALUES (?,?,?,?,?, CASE WHEN ? IN ('approved','declined') THEN NOW() ELSE NULL END)");
  $stmt->bind_param('iissss',$paper_id,$reviewer_id,$level,$status,$feedback,$status);
  $stmt->execute();
}

/**
 * Binary search to optimize data fetching/checking in sorted arrays
 */
function binary_search_exists(string $needle, array $haystack): bool {
    $low = 0;
    $high = count($haystack) - 1;
    while ($low <= $high) {
        $mid = (int)(($low + $high) / 2);
        if ($haystack[$mid] === $needle) {
            return true;
        }
        if ($needle < $haystack[$mid]) {
            $high = $mid - 1;
        } else {
            $low = $mid + 1;
        }
    }
    return false;
}

/**
 * Allow-list sanitiser for the rich text produced by the upload page's section
 * editors.
 *
 * The editors post HTML, so this is the boundary where untrusted markup stops.
 * Anything not named below — script, style, iframe, event handlers, javascript:
 * URLs, inline colours and fonts — is dropped, while the tags a student can
 * actually produce with the toolbar are preserved.
 *
 * @param string $html Raw HTML from a contenteditable surface
 * @return string Sanitised HTML, safe to store and to echo unescaped
 */
function rich_text_sanitize(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    // Tags the toolbar can produce, plus the ones browsers substitute for them.
    $allowedTags = [
        'p', 'br', 'div', 'span',
        'b', 'strong', 'i', 'em', 'u', 'strike', 's', 'del', 'sub', 'sup',
        'ul', 'ol', 'li', 'blockquote', 'a',
        // Tables: results are usually reported as one, and pasting a table from
        // Word or a PDF has to survive with its rows and columns intact.
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        // Pictures: a screenshot of an instrument, a chart, a photograph of a
        // setup. Only ones this site stores are kept, see $safeImageSrc below.
        'img',
    ];
    // Spans and merges are structural — losing them scrambles the table — so
    // these two survive the attribute purge on cells, clamped to sane values.
    $cellSpanAttrs = ['colspan', 'rowspan'];
    // Removed outright, contents and all. Every other disallowed tag is merely
    // unwrapped so its words survive — but the text inside these is code, not
    // prose, and must not end up rendered as body copy.
    $dropTags = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'noscript',
        'template', 'svg', 'math', 'head', 'title', 'link', 'meta', 'base',
        'form', 'input', 'button', 'select', 'textarea', 'option',
    ];
    // Almost no attribute survives: a cell's colspan/rowspan, a link's href,
    // and a cell's text-align. Dropping style otherwise is what keeps url(),
    // expression() and behaviour hacks out, and it means pasted markup cannot
    // bring Word's shading, fonts or spacing along with it.
    //
    // Alignment is allowed on table cells alone. Body text is justified for
    // every paper and cannot be changed, but a column of figures reads wrongly
    // unless it can be centred or set right.
    $allowedAlign = ['left', 'right', 'center', 'justify'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // The meta charset keeps DOMDocument from mangling UTF-8; the wrapper gives
    // a single node to walk without <html><body> being added to the output.
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="papel-rt-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    if (!$ok) return '';

    $root = $doc->getElementById('papel-rt-root');
    if (!$root) return '';


    // Only schemes that cannot execute. Relative and anchor links are fine;
    // javascript:, data: and vbscript: are the ones that turn a link into code.
    /* Something that at least looks like a host: labels separated by dots,
       ending in a real suffix, optionally with a port and a path. */
    $hostPattern = '/^[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,}(?::\d{1,5})?(?:[\/?#]\S*)?$/i';

    $safeHref = function ($href) use ($hostPattern) {
        $raw = trim((string)$href);
        if ($raw === '') return null;
        // Characters browsers ignore when working out a scheme, so
        // "java\tscript:" cannot smuggle itself past the check.
        $probe = preg_replace('/[\s\x00-\x1F]/', '', $raw);

        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $m)) {
            /* No scheme. This used to be kept as a relative address, which is
               how any word at all passed as a link. A bare host is what people
               type, so it is accepted and given the scheme it was missing;
               anything else is prose, not an address. */
            return preg_match($hostPattern, $probe) ? 'https://' . $probe : null;
        }

        $scheme = strtolower($m[1]);
        if ($scheme === 'mailto') {
            return preg_match('/^mailto:[^\s@]+@[a-z0-9.\-]+\.[a-z]{2,}$/i', $probe) ? $probe : null;
        }
        if ($scheme !== 'http' && $scheme !== 'https') return null;
        return preg_match($hostPattern, preg_replace('#^https?://#i', '', $probe)) ? $probe : null;
    };

    /* An image is only kept if it points at a file this site stores.

       A remote src would mean every reader of the paper fetches a picture from
       somewhere else, which leaks who is reading what and leaves the paper
       broken the day that host goes away. The upload endpoint is the only thing
       that writes into this directory and it names the files itself, so the
       shape of a legitimate path is known exactly and can simply be matched. */
    $safeImageSrc = function ($src) {
        $s = trim((string)$src);
        if ($s === '') return null;
        // A scheme, or a protocol-relative "//host/...", is somewhere else.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $s) || strpos($s, '//') === 0) return null;
        if (strpos($s, '..') !== false) return null;
        if (!preg_match('#(?:^|/)uploads/section_images/\d+/[a-f0-9]{32}\.(png|jpe?g|gif|webp)$#i', $s)) {
            return null;
        }
        return $s;
    };

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $dropTags, $cellSpanAttrs, $safeHref, $safeImageSrc, $allowedAlign) {
        $child = $node->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child->nodeType === XML_TEXT_NODE) { $child = $next; continue; }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                // Comments, processing instructions, CDATA — none belong here.
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, $dropTags, true)) {
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            if (!in_array($tag, $allowedTags, true)) {
                // Unwrap rather than delete, so the words inside a stray <font>
                // or <table> are not silently lost along with the tag.
                $first = $child->firstChild;
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $child = ($first !== null) ? $first : $next;
                continue;
            }

            $isCell  = ($tag === 'td' || $tag === 'th');
            $isLink  = ($tag === 'a');
            $isImage = ($tag === 'img');

            /* A paragraph inside a cell is where the browser puts the alignment
               when the cell holds one, so its text-align has to survive as well
               or centring a column comes undone at the next save. Outside a
               cell it is still dropped: body text is justified for every
               paper. */
            $inCellBlock = false;
            if ($tag === 'p' || $tag === 'div') {
                for ($a = $child->parentNode; $a !== null; $a = $a->parentNode) {
                    if ($a->nodeType !== XML_ELEMENT_NODE) continue;
                    $an = strtolower($a->nodeName);
                    if ($an === 'td' || $an === 'th') { $inCellBlock = true; break; }
                    if ($an === 'table') break;
                }
            }
            $href    = null;
            $src     = null;

            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);

                if ($isCell && in_array($name, $cellSpanAttrs, true)) {
                    $span = (int)$attr->nodeValue;
                    // A cell claiming hundreds of columns is either broken paste
                    // or an attempt to blow up the layout of every page it lands on.
                    if ($span > 1 && $span <= 100) {
                        $child->setAttribute($name, (string)$span);
                    } else {
                        $child->removeAttribute($name);
                    }
                    continue;
                }

                if ($isLink && $name === 'href') {
                    $href = $safeHref($attr->nodeValue);
                    $child->removeAttribute($attr->nodeName);
                    continue;
                }

                if ($isImage && $name === 'src') {
                    $src = $safeImageSrc($attr->nodeValue);
                    $child->removeAttribute($attr->nodeName);
                    continue;
                }
                if ($isImage && $name === 'alt') {
                    // Kept because it is the only description a screen reader
                    // has, but bounded: a pasted alt can be a whole paragraph.
                    $child->setAttribute('alt', mb_substr(trim((string)$attr->nodeValue), 0, 300));
                    continue;
                }

                /* Two declarations survive, on the elements that can mean them:
                   how a cell's text is aligned, and how wide a column is, which
                   comes from dragging a table's border. Everything else in a
                   style attribute is dropped — fonts and colours would carry
                   Word's shading into the repository, and url() is an attack
                   surface. */
                if (($isCell || $inCellBlock || $tag === 'table' || $isImage) && $name === 'style') {
                    $keep = [];
                    foreach (explode(';', (string)$attr->nodeValue) as $decl) {
                        if (strpos($decl, ':') === false) continue;
                        list($prop, $value) = explode(':', $decl, 2);
                        $prop  = trim(strtolower($prop));
                        $value = trim(strtolower($value));

                        // Alignment is a cell's business only — body text is
                        // justified for every paper, by the stylesheet.
                        if (($isCell || $inCellBlock) && $prop === 'text-align'
                            && in_array($value, $allowedAlign, true)) {
                            $keep['text-align'] = 'text-align:' . $value;
                        }
                        /* How a table is placed in the box. auto and 0 only:
                           that is all centring one needs, and it cannot carry a
                           url() or a length big enough to push the page about. */
                        if ($tag === 'table' && ($prop === 'margin-left' || $prop === 'margin-right')
                            && ($value === 'auto' || $value === '0')) {
                            $keep[$prop] = $prop . ':' . $value;
                        }

                        // A width means a column, or how large a picture is set.
                        if ($prop === 'width' && !$inCellBlock && ($isCell || $tag === 'table' || $isImage)
                            && preg_match('/^([\d.]+)(%|px)$/', $value, $m)
                            && (float)$m[1] > 0 && (float)$m[1] <= 2000) {
                            $keep['width'] = 'width:' . (float)$m[1] . $m[2];
                        }
                    }
                    if ($keep) $child->setAttribute('style', implode(';', $keep));
                    else $child->removeAttribute('style');
                    continue;
                }

                // Everything else goes, style attributes included.
                $child->removeAttribute($attr->nodeName);
            }

            if ($isImage) {
                if ($src === null) {
                    // Void element: there is nothing inside it to preserve, so
                    // unlike a stray <font> it goes rather than being unwrapped.
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }
                $child->setAttribute('src', $src);
                if (!$child->hasAttribute('alt')) $child->setAttribute('alt', '');
                // Never let a picture in the middle of a paragraph be the thing
                // that blocks the first paint of a paper's page.
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('decoding', 'async');
            }

            if ($isLink) {
                if ($href === null) {
                    // No usable destination: keep the words, drop the link.
                    $first = $child->firstChild;
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    $child = ($first !== null) ? $first : $next;
                    continue;
                }
                $child->setAttribute('href', $href);
                // Opening in a new tab without noopener hands the new page a
                // handle back to this one via window.opener.
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
            }

            $walk($child);
            $child = $next;
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Public URL for a file stored under the project's uploads directory.
 *
 * Section images are referenced from HTML that is rendered by pages at four
 * different depths, so a path relative to the page cannot work, and an absolute
 * one would bake in whichever host name happened to be in use when the picture
 * was pasted. The path component of BASE_URL gives a root-relative URL that
 * survives both.
 *
 * @param string $relative Path from the project root, e.g. "uploads/x/y.png"
 * @return string Root-relative URL, e.g. "/capstone/uploads/x/y.png"
 */
function upload_url(string $relative): string {
    $base = rtrim((string)parse_url(BASE_URL, PHP_URL_PATH), '/');
    return $base . '/' . ltrim($relative, '/');
}

/* A picture uploaded into a draft that is then abandoned has nothing pointing
   at it, and nothing else will ever delete it. This is how long one is left
   alone before it counts as abandoned: the picture is on disk from the moment
   it is pasted, but it only appears in the database at the next autosave, and
   a student may sit on an unsaved draft for a long time. A day is far longer
   than that gap and short enough to keep the folder honest. */
const SECTION_IMAGE_GRACE_HOURS = 24;

/**
 * Delete pictures under uploads/section_images that no paper refers to.
 *
 * A picture is kept if its file name appears in the stored section HTML of any
 * paper, draft or archived row. Anything else is an orphan: the draft it was
 * pasted into was discarded, or the student deleted the picture again before
 * saving.
 *
 * Files newer than the grace period are never touched, whatever the database
 * says. That window is the whole safety of this function: between pasting a
 * picture and the autosave that records it, the only thing pointing at the file
 * is a contenteditable in somebody's browser.
 *
 * Safe to run at any time, and safe to run twice.
 *
 * @param bool $dryRun Report what would go without deleting anything
 * @param int  $graceHours How long a file is left alone before it can be swept
 * @return array{scanned:int,referenced:int,removed:int,kept_recent:int,freed:int,names:string[]}
 */
function section_images_sweep(bool $dryRun = false, int $graceHours = SECTION_IMAGE_GRACE_HOURS): array {
    $out = ['scanned' => 0, 'referenced' => 0, 'removed' => 0,
            'kept_recent' => 0, 'freed' => 0, 'names' => []];

    $root = ROOT_PATH . '/uploads/section_images';
    if (!is_dir($root)) return $out;

    /* Every file name any paper mentions. Read from the section HTML itself
       rather than from a table of uploads, because the HTML is the only record
       of which pictures a paper actually still uses: one deleted from a section
       and saved leaves no trace anywhere else. */
    $referenced = [];
    $conn = db();
    foreach (['research_papers', 'papers_archive'] as $table) {
        /* A literal, not input. The LIKE keeps the scan off the rows that
           cannot possibly mention a picture, which is nearly all of them; it
           matches whether or not the slashes around it were escaped. */
        $res = $conn->query(
            "SELECT imrad_content FROM `{$table}`
             WHERE imrad_content LIKE '%section_images%'");
        if (!$res) continue;
        while ($row = $res->fetch_assoc()) {
            /* The column holds the sections as JSON, and json_encode() escapes
               forward slashes, so what is actually stored is
               uploads\/section_images\/154\/x.png. Undoing that first is what
               makes the pattern below match anything at all. */
            $content = str_replace('\\/', '/', (string)$row['imrad_content']);
            if (preg_match_all('#uploads/section_images/\d+/([a-f0-9]{32}\.(?:png|jpe?g|gif|webp))#i',
                               $content, $m)) {
                foreach ($m[1] as $name) $referenced[strtolower($name)] = true;
            }
        }
        $res->free();
    }
    $out['referenced'] = count($referenced);

    $cutoff = time() - max(1, $graceHours) * 3600;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (!is_file($file)) continue;
            $out['scanned']++;
            if (isset($referenced[strtolower(basename($file))])) continue;
            if (filemtime($file) > $cutoff) { $out['kept_recent']++; continue; }

            $size = (int)filesize($file);
            $out['names'][] = basename(dirname($file)) . '/' . basename($file);
            if ($dryRun || @unlink($file)) {
                $out['removed']++;
                $out['freed'] += $size;
            }
        }
        // A student who has no pictures left needs no folder.
        if (!$dryRun && !(glob($dir . '/*') ?: [])) @rmdir($dir);
    }
    return $out;
}

/**
 * Plain-text form of rich text, for the columns that are searched, compared and
 * shown as card previews. Block tags become line breaks so words do not run
 * together once the markup is gone.
 */
function rich_text_to_plain(string $html): string {
    if (trim($html) === '') return '';
    // Cells are separated, not stacked — without this the row "Usability | 4.03"
    // collapses to "Usability4.03" in search text and card previews.
    $text = preg_replace('#</(td|th)\s*>#i', ' ', $html);
    $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/blockquote|/tr|/table|/caption)\s*/?>#i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);          // &nbsp;
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * An exception whose message was written for the person using the page, and is
 * therefore safe to show verbatim.
 *
 * safe_error_message() deliberately throws away exception text so internals are
 * never leaked to a browser. That is right for unexpected failures, but it also
 * swallowed messages we wrote *for* the student — "the AI service is rate
 * limited, wait a minute" arrived as "something went wrong". Throwing this type
 * marks a message as intended for display; everything else stays generic.
 */
class UserFacingException extends Exception {}

/**
 * The paper types a student may choose, in the order the form offers them.
 *
 * Both upload forms build their dropdown from this and the submit handler
 * checks what came back against it. The options and the whitelist used to be
 * written out separately in three places, which is how a type could be offered
 * on the form and then refused on submission.
 */
function paper_types(): array {
    return [
        'capstone'         => 'Capstone Project',
        'undergrad_thesis' => 'Undergraduate Thesis',
        'conference'       => 'Conference Paper',
        'journal'          => 'Journal Article',
        'project'          => 'Feasibility Study',
    ];
}

/**
 * Types no longer offered, but still carried by papers already submitted.
 *
 * Dropping one from paper_types() stops anyone choosing it again. Its name has
 * to stay here regardless: the archive holds papers filed under these, and
 * without a name they would show a bare code on every page that displays them.
 */
/**
 * The manuscript formats a paper can be handed in as.
 *
 * Both may be chosen: a paper submitted in both forms is one paper with two
 * files, not two papers.
 */
function manuscript_types(): array
{
    return ['IMRAD', 'Full Manuscript'];
}

/**
 * What the manuscript_type column should hold for a given submission.
 *
 * The form posts an array now, and the column is a varchar holding the chosen
 * names separated by a comma. Anything not on the list is dropped rather than
 * stored, so a hand-made post cannot put arbitrary text on the record.
 */
function manuscript_types_posted($posted): string
{
    $allowed = manuscript_types();
    $picked  = [];
    foreach ((array)$posted as $one) {
        $one = trim((string)$one);
        if (in_array($one, $allowed, true) && !in_array($one, $picked, true)) {
            $picked[] = $one;
        }
    }
    /* Papers filed before this was a choice of two carry a single older name;
       leaving an unrecognised value alone would be kinder than blanking it,
       but nothing posts one, so an empty result here means nothing was ticked
       and the caller refuses the submission. */
    return implode(', ', $picked);
}

function paper_types_retired(): array {
    return [
        'research' => 'Research Paper',
        'article'  => 'Article',
        'thesis'   => 'Thesis',
    ];
}

/**
 * Paper types that must be submitted with ethics clearance, a consent form and
 * a data-collection tool.
 *
 * These are the types that involve human participants and original data
 * gathering. A journal article, conference paper or write-up of existing work
 * may still attach the same documents, but is not blocked without them.
 *
 * 'research' is retired and cannot be chosen any more, but papers already
 * filed under it are still read by this, so it stays.
 */
function paper_type_needs_documents(?string $paperType): bool {
    return in_array(strtolower(trim((string)$paperType)), ['research', 'capstone'], true);
}

/** Human-readable name for a paper type, for use in messages. */
function paper_type_label(?string $paperType): string {
    $labels = paper_types() + paper_types_retired();
    $key = strtolower(trim((string)$paperType));
    return $labels[$key] ?? ucwords(str_replace('_', ' ', (string)$paperType));
}

/**
 * The written sections of a paper, in the order they are asked for and shown.
 *
 * The upload form builds its editors from this, the submit handler validates
 * against it, the draft is saved from it and the student's paper page renders
 * from it. Four lists of the same five names had already appeared before this
 * existed; adding a section then meant editing every one of them and the paper
 * silently losing whatever was missed. Add a section here and it appears
 * everywhere at once.
 *
 * The keys are also the form field names and the keys inside imrad_content.
 */
function paper_section_labels(): array {
    return [
        'abstract'           => 'Abstract',
        'introduction'       => 'Introduction',
        'methodology'        => 'Methodology',
        'results_discussion' => 'Results and Discussion',
        'conclusion'         => 'Conclusion',
        'references'         => 'References',
    ];
}

/**
 * An APA 7th-edition reference for a paper, built from what was filed.
 *
 * The archive is the paper's only publisher, so this always cites it as an
 * institutional record rather than guessing at a journal entry — even a paper
 * whose own "Paper Status" field says Published was never issued a DOI or a
 * volume/issue here, so there is nothing to cite it as except the archive.
 *
 * Names are stored "Given Middle. Surname" (Filipino order), one string per
 * author separated by a comma, semicolon or "and"/"&" — the same split
 * archive/index.php already uses for its author-suggestion search. APA wants
 * "Surname, G. M.", so the last space-separated token is taken as the surname
 * and everything before it is reduced to initials.
 */
function paper_apa_citation(array $paper, string $url = ''): string {
    $rawNames = trim((string)($paper['author_names'] ?? ''));
    $names = array_values(array_filter(array_map('trim',
        preg_split('/\s*(?:,|;|\band\b|&)\s*/i', $rawNames)
    )));

    $authors = array_map(function (string $name): string {
        $parts = preg_split('/\s+/', trim($name));
        if (count($parts) < 2) return $name;
        $surname = array_pop($parts);
        $initials = array_filter(array_map(function (string $p): string {
            $p = trim($p, '.');
            return $p === '' ? '' : mb_strtoupper(mb_substr($p, 0, 1)) . '.';
        }, $parts));
        return $initials ? $surname . ', ' . implode(' ', $initials) : $surname;
    }, $names);

    $authorList = '';
    $n = count($authors);
    if ($n === 1) {
        $authorList = $authors[0];
    } elseif ($n === 2) {
        $authorList = $authors[0] . ' & ' . $authors[1];
    } elseif ($n > 2) {
        $last = array_pop($authors);
        $authorList = implode(', ', $authors) . ', & ' . $last;
    }

    $year = '';
    if (!empty($paper['research_date']) && $paper['research_date'] !== '0000-00-00') {
        $ts = strtotime((string)$paper['research_date']);
        if ($ts) $year = date('Y', $ts);
    }
    if ($year === '' && !empty($paper['year'])) $year = (string)(int)$paper['year'];
    if ($year === '') $year = 'n.d.';

    $title = trim((string)($paper['title'] ?? ''));
    $typeLabel = !empty($paper['paper_type']) ? paper_type_label((string)$paper['paper_type']) : 'Thesis';
    $status = strtolower(trim((string)($paper['publication_status'] ?? '')));
    $bracket = (strpos($status, 'unpublish') !== false || $status === '')
        ? 'Unpublished ' . $typeLabel
        : $typeLabel;

    $institution = 'Polytechnic University of the Philippines – Biñan Campus';

    $citation = ($authorList !== '' ? $authorList . ' ' : '')
        . '(' . $year . '). '
        . ($title !== '' ? $title . ' ' : '')
        . '[' . $bracket . ']. '
        . $institution . '.';
    if ($url !== '') $citation .= ' ' . $url;

    return $citation;
}

/**
 * Where a notification takes you when you click it.
 *
 * A notification is always about a paper, and every role has its own place to
 * read that paper: the author has their record of it, a reviewer has the page
 * they decide on it from, and everyone else has the published version. Sending
 * them all to the same URL would land most of them on a page they are not
 * allowed to open.
 *
 * Falls back to the reader's own dashboard when the notification carries no
 * paper — a reminder, say — so a click is never a dead end.
 */
/**
 * Split a notification into the line the list shows and the detail behind it.
 *
 * An account notice carries its detail from the second line on — which can hold
 * a freshly issued password — so the list shows the first line only and the
 * rest opens in a dialog. There is no paper to send the reader to for one of
 * these, which is the other reason it opens where it is.
 *
 * Both lists ask this rather than each splitting the message its own way; they
 * disagreed before, and the full-screen one printed the password inline.
 *
 * @return array{summary:string, detail:string, is_account:bool}
 */
function notification_parts(array $n): array
{
    $isAccount = ($n['notification_type'] ?? '') === 'account';
    $message   = (string)($n['message'] ?? '');
    if (!$isAccount) {
        return ['summary' => $message, 'detail' => '', 'is_account' => false];
    }
    $lines   = preg_split('/\r\n|\r|\n/', $message);
    $summary = (string)array_shift($lines);
    return [
        'summary'    => $summary,
        'detail'     => implode("\n", $lines),
        'is_account' => true,
    ];
}

function notification_link(?int $paperId, string $role, string $type = ''): string {
    /* A notice about an account, not a paper. It goes to the roll the reader
       supervises, opened on the tab that lists password changes, because the
       useful next question after "somebody changed theirs" is "who else, and
       how often". Anyone without a roll to supervise goes to their own desk. */
    /* Somebody is asking this reader to do something to an account. The list
       of what is waiting is the useful place to land, not a paper and not a
       dashboard. */
    if ($type === 'support') {
        return BASE_URL . '/app/support_requests.php';
    }

    if ($type === 'security') {
        $u = current_user();
        $level = (int)($u['admin_level'] ?? 1);
        if ($role === 'faculty')     return BASE_URL . '/app/faculty/faculty_manage_students.php?tab=passwords';
        if ($role === 'super_admin') return BASE_URL . '/app/admin/super_admin_manage_admins.php?tab=passwords';
        if ($role === 'admin' && $level !== 2) return BASE_URL . '/app/admin/admin_manage_faculty.php?tab=passwords';
        return role_home($role);
    }

    /* A manuscript request is about a published paper somebody else wrote, not
       the reader's own submission — the student case below sends to
       paper_details.php, which is for a student's own papers and would be the
       wrong page here, so this has to be checked first. */
    if ($type === 'manuscript') {
        if ($role === 'librarian') return BASE_URL . '/app/librarian/manuscript_requests.php';
        if ($role === 'student' && $paperId) return BASE_URL . '/archive/view_paper.php?id=' . $paperId;
        return role_home($role);
    }

    if (!$paperId) return role_home($role);

    switch ($role) {
        case 'student':
            return BASE_URL . '/app/student/paper_details.php?id=' . $paperId;
        case 'faculty':
        case 'admin':
            return BASE_URL . '/app/review_paper.php?id=' . $paperId;
        default:
            // Oversight roles and anyone else read the published version.
            return BASE_URL . '/archive/view_paper.php?id=' . $paperId;
    }
}

/**
 * What a role's own landing page is called.
 *
 * Used wherever one page links to "your dashboard" — the public repository's
 * sidebar most of all, where a reviewer following a link labelled "My
 * Dashboard" arrives at something the page itself calls a Review Desk. The
 * name travels with the link instead.
 */
function role_home_label(string $role): string {
    if ($role === 'faculty') return 'Review Desk';
    /* A guest has no dashboard to go back to: role_home('guest') is the public
       repository, so calling it "My Dashboard" pointed at a page that is not
       theirs and does not exist for them. */
    if ($role === 'guest') return 'Public Repository';
    if ($role === 'admin') {
        // Records Officers (level 2) have their own page, not the review desk.
        $u = current_user();
        return (($u['admin_level'] ?? 1) == 2) ? 'My Dashboard' : 'Review Desk';
    }
    return 'My Dashboard';
}

/**
 * Which checklist groups apply to a paper, from the format the student chose.
 *
 * A paper written in IMRaD has no numbered chapters, so asking a reviewer to
 * confirm "Chapter 4" is asking about something that does not exist — and the
 * unticked box then shows on the student's record as though a piece were
 * missing. A full manuscript has both: the chapters, and the IMRaD sections
 * within them.
 *
 * Anything unrecognised (older papers stored no format at all) shows both,
 * because hiding a group we are unsure about would quietly lose information.
 */
function paper_checklist_groups(?string $manuscriptType): array {
    $type = strtoupper(trim((string)$manuscriptType));
    if ($type === 'IMRAD') return ['full' => false, 'imrad' => true];
    return ['full' => true, 'imrad' => true];
}

/**
 * A link that opens a paper or one of its supporting documents.
 *
 * Google Drive is preferred when the file is there. The local column is
 * awkward, because two conventions are stored in it: rows written by the
 * current uploader hold a full "http://host/capstone/app/student/uploads/..."
 * URL, while older rows hold "uploads/..." relative to app/student/. Prefixing
 * BASE_URL blindly breaks the first kind and drops "app/student" from the
 * second, so both shapes are handled here once instead of being guessed at by
 * each page that shows a file.
 */
function paper_file_url(?string $driveId, ?string $path): ?string {
    if (!empty($driveId) && function_exists('get_gdrive_link')) {
        return get_gdrive_link($driveId);
    }
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('#^https?://#i', $path)) return $path;          // already absolute
    return rtrim(BASE_URL, '/') . '/app/student/' . ltrim($path, '/');
}

/**
 * Where a stored paper file actually lives on this machine.
 *
 * Uploads used to be recorded as a fully qualified URL — BASE_URL with the
 * relative path glued on — so a row carried the address of the machine it was
 * uploaded from. Two things went wrong with that. Anything that needed the file
 * rather than a link built a nonsense path
 * ("…/archive/../http://localhost/capstone/…") and simply failed: the download
 * button answered 404 and the AI text extraction silently found nothing. And
 * moving the project to another computer, another port, or serving it over the
 * LAN left every one of those rows pointing at somewhere that no longer exists.
 *
 * New rows store the relative path. This accepts either form, because the old
 * rows are still out there, and returns null rather than a path that escapes
 * the upload folder — a stored path is data, and data used to build a
 * filesystem path is checked before it is trusted.
 */
function paper_file_disk_path(?string $stored): ?string {
    $stored = trim((string)$stored);
    if ($stored === '') return null;

    /* Reduce whatever was stored to the part after app/student/. An old row is
       an absolute URL; a newer one is already relative. */
    $marker = '/app/student/';
    $at = strpos($stored, $marker);
    if ($at !== false) {
        $relative = substr($stored, $at + strlen($marker));
    } elseif (preg_match('#^https?://#i', $stored)) {
        // An absolute URL from somewhere else entirely — not ours to open.
        return null;
    } else {
        $relative = $stored;
    }
    $relative = ltrim($relative, '/');
    if ($relative === '') return null;

    $base = realpath(__DIR__ . '/../app/student/uploads');
    $full = realpath(__DIR__ . '/../app/student/' . $relative);
    if ($base === false || $full === false) return null;

    // Must resolve inside uploads/ — this is what stops "../" walking out.
    if (strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) return null;

    return is_file($full) ? $full : null;
}

/**
 * What a supporting document is called on screen.
 *
 * The copyright document is stored with an empty document_type: the column is
 * an enum that has no 'copyright_doc' member, so the value the uploader writes
 * is coerced to ''. Until that column is widened, an empty type means the
 * copyright document — which is how the rest of the site already reads it.
 */
function supporting_doc_label(?string $type): string {
    $labels = [
        'ethics_clearance' => 'Ethical Clearance',
        'consent_form'     => 'Consent Form',
        'data_collection'  => 'Data Collection Tool',
        'copyright_doc'    => 'Copyright / IP Document',
        'other'            => 'Copyright / IP Document',
        ''                 => 'Copyright / IP Document',
    ];
    $key = strtolower(trim((string)$type));
    return $labels[$key] ?? ucwords(str_replace('_', ' ', (string)$type));
}
