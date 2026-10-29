<?php
// core.php (FINAL)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/../app/helpers/UploadHelper.php';
require_once __DIR__ . '/../includes/recaptcha.php';

/* A POST whose body PHP threw away before this file ran.
 *
 * When the body is larger than post_max_size, PHP discards it, prints a
 * warning of its own ("POST Content-Length of N bytes exceeds the limit")
 * and carries on with $_POST and $_FILES empty. Nothing after that can tell
 * the request apart from an empty one: every `$_POST['action']` test fails,
 * so no handler runs, the page renders as though it had been asked for
 * normally, and the browser gets HTML and HTTP 200 where it expected JSON.
 * On the upload page that surfaced as "Error connecting to server" about a
 * file the server had simply refused to accept, with nothing anywhere saying
 * so. The limits differ between this machine and staging, which is why it
 * only showed up there.
 *
 * Checked before anything else because it has to be: $_POST is how the rest
 * of the app decides what a request is. */
guard_discarded_post();

function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
        /* reCAPTCHA is fetched from google.com and framed from google.com, so
           those origins are opened only while the feature actually has keys:
           an unused allowance is still an allowance. */
        $rc      = (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== ''
                 && defined('RECAPTCHA_SECRET_KEY') && RECAPTCHA_SECRET_KEY !== '');
        $rcScript = $rc ? ' https://www.google.com https://www.gstatic.com' : '';
        $rcFrame  = $rc ? ' https://www.google.com' : '';

        /* The metadata proxy, and only when one is set: an unused allowance is
           still an allowance, the same reasoning as reCAPTCHA above. Only the
           origin is taken from the setting, never the path. */
        $proxyOrigin = '';
        if (defined('AI_PROXY_URL') && AI_PROXY_URL !== '') {
            $parts = parse_url(AI_PROXY_URL);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $proxyOrigin = ' ' . $parts['scheme'] . '://' . $parts['host']
                             . (empty($parts['port']) ? '' : ':' . (int)$parts['port']);
            }
        }

        $csp = "default-src 'self'; " .
               "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://unpkg.com{$rcScript}; " .
               "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
               "img-src 'self' data: blob: https://lh3.googleusercontent.com; " .
               "connect-src 'self' https://api.groq.com https://www.googleapis.com{$proxyOrigin}; " .
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

/* How long a session survives with nothing happening on it.
 *
 * Until this was set the answer was whatever the server happened to be
 * installed with: session.gc_maxlifetime, 1440 seconds on this one, swept by a
 * collector that runs on one request in a thousand. So a session could be
 * ended a minute after that mark or hours later, depending on how busy the
 * site was, and the same site on another host would behave differently again.
 * That is not a policy, it is a side effect.
 *
 * Thirty minutes, enforced on the request itself rather than left to the
 * collector, and settable in one place. A reviewer reading a long paper with
 * the desk open in another tab is not idle; somebody who walked away is.
 */
if (!defined('SESSION_IDLE_SECONDS')) {
  define('SESSION_IDLE_SECONDS', 1800);
}

function start_session_once(): void {
  if (session_status() === PHP_SESSION_NONE) {
    /* So the collector cannot take a session this considers alive. It is the
       sweeping process's own value that decides, and every request here comes
       through this function, so they all agree. */
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_SECONDS);
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'secure'   => COOKIE_SECURE,
      'httponly' => true,
      'samesite' => 'Lax'
    ]);
    session_name('papel_sid');
    session_start();

    /* Idle for longer than the window: ended here and now, so the rule holds
       whether or not the collector has run. 'seen' is written on every request,
       so idle means nothing at all was asked for in that time, not merely that
       nobody signed in again. */
    $wasSignedIn = !empty($_SESSION['user']) || !empty($_SESSION['guest_login']);
    if (!empty($_SESSION['seen']) && (time() - (int)$_SESSION['seen']) > SESSION_IDLE_SECONDS) {
      /* Nobody was signed in, so there is no session to end: what the wipe
         actually destroyed was the CSRF token of the sign-in form already on
         screen. The next submit then failed the check, the visitor was
         returned to the sign-in panel with no reason given, and only a second
         attempt worked, because by then the page carried a current token.
         Carrying the token across costs nothing: it proves a form came from
         this browser, and it guards no account while nobody is signed in. */
      $carry = $wasSignedIn ? [] : array_filter(['csrf' => $_SESSION['csrf'] ?? null]);
      $_SESSION = $carry;
      session_regenerate_id(true);
      if ($wasSignedIn) {
        /* Said on the sign-in panel, the same way require_role() explains the
           sign-out it causes: being returned to a login screen with no reason
           given reads as the site having lost the session by accident. */
        $_SESSION['flash']['login_error'] =
          'You were signed out after ' . (int)round(SESSION_IDLE_SECONDS / 60)
          . ' minutes with nothing happening. Please sign in again.';
      }
    }
    $_SESSION['seen'] = time();
  }
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    if (DB_SSL_CA !== '') {
      /* A hosted database: a port that is not 3306, and a connection that has
         to be encrypted and verified against the provider's CA. Built the long
         way round because ssl_set() has to be called on an initialised handle
         before it connects: the one-line constructor connects immediately and
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
 * error inline rather than flashing and redirecting: archive/login.php keeps
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
 *        A mismatch is usually a stale form rather than an attack: signing out
 *        clears the token, so any page left open beforehand now carries an old
 *        one. Given somewhere to go, this says so and lets them try again
 *        instead of dead-ending on a bare error page. Without it (JSON
 *        endpoints, where a redirect would only confuse the caller) the request
 *        is refused outright, as before.
 */
function csrf_verify(?string $redirect = null, string $flashKey = 'error'): void {
  start_session_once();
  if (csrf_valid()) {
    return;
  }

  if ($redirect !== null) {
    /* Flashed under the key the destination actually reads. 'error' is the
       general one that dashboards and management pages show; the sign-in panel
       shows 'login_error' and nothing else, so a sign-in refused here said
       nothing at all on arrival and then surfaced the message on whichever
       page next read 'error' - the dashboard, right after a sign-in that had
       just succeeded, reporting an expiry that was two requests old. */
    flash($flashKey, 'Your session expired before that was submitted. Please try again.');
    header('Location: ' . $redirect);
    exit;
  }

  /* 403, not 419. Apache does not know 419 (it is a Laravel convention, not an
     HTTP code) and normalises it to 500, so every expired token has been
     logged as a server fault on a server that was working correctly. */
  http_response_code(403);

  /* A background call gets this as JSON, because plain text is not something
     any of them can read. Every fetch on the upload page parses the answer as
     JSON, so a bare "Invalid CSRF token" made r.json() reject and the student
     was shown "Error connecting to server" about a server that had answered
     precisely and correctly. The wording says what actually happened, since a
     stale token means the sign-in behind the page is no longer the live one. */
  /* Sec-Fetch-Dest is the browser's own word for what asked, and it arrives
     even where a proxy has dropped the other two. Without it a refusal could
     still come back as the bare line below, which no fetch can read: that is
     how a 403 reached the upload page as "the server did not answer properly"
     with the reason nowhere to be seen. */
  $wantsJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
      || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
      || (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty');
  if ($wantsJson) {
    if (!headers_sent()) { header('Content-Type: application/json'); }
    exit(json_encode([
      'success' => false,
      'message' => 'This page has been open long enough that its security token has expired. '
                 . 'Reload the page and try again; your typed work is kept.',
      'error'   => 'csrf_expired',
    ]));
  }

  exit('Invalid CSRF token');
}

function flash(string $key, ?string $msg = null): ?string {
  start_session_once();
  if ($msg === null) {
    if (!empty($_SESSION['flash'][$key])) { $m = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $m; }
    return null;
  }
  $_SESSION['flash'][$key] = $msg; return null;
}

/* ---------------------------------------------------------------------------
   Slowing down password guessing.

   Sign-in is ID + password (no username) and the IDs are guessable by
   design: student numbers run in sequence, staff IDs follow FAC-2026-00n. With
   nothing counting failures, a script could work through passwords against a
   known ID as fast as the server answered.

   Two scopes are counted, because either one alone has a hole:

     acct:<id>|<ip>  one account from one machine. Keyed on the machine as well
                     as the account so that hammering somebody else's ID cannot
                     lock them out of their own session: a lockout that anyone
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
/* Sends a blocked request to the sign-in panel on the public repository:
   the role-tab modal (Student / Faculty-Admin / Guest), not the older
   standalone form at archive/login.php. That standalone page was the target
   briefly; it was moved here because the modal is the more complete
   experience (Forgot password, Terms & Conditions, role-specific ID
   labels) and duplicating that onto a second page would only drift out of
   sync with it over time. The trade caught in making that move: the
   repository sits behind the modal rather than being hidden entirely, so a
   pasted link to a protected page is answered with the archive in view, not
   a blank sign-in screen. See openLoginModal() in site_footer.php if that
   needs to open already expanded to cover it: it does not today.

   login_redirect() is the one place this destination is named: require_login()
   and require_role() both go through it, so every protected page in the
   system already lands there without needing its own change. */
function login_redirect(string $tab = 'student'): void {
  header('Location: '.BASE_URL.'/archive/index.php?login_modal=1&role='.rawurlencode($tab)); exit;
}
function require_login(): void { if (!current_user()) { login_redirect(); } }

/* A browser signed in as the wrong account used to get a bare "Forbidden":
   a student session opening a pasted Coordinator link, or a guest pass
   opening any dashboard. The sign-in panel cannot help there, because it is
   only drawn for someone signed out, so the session that cannot open the
   page is ended first and the panel opens on the tab the page is meant for
   (Faculty / Admin for a staff desk), with a line saying why.

   Only for a page being opened, never for a background request. fetch() and
   XHR carry Sec-Fetch-Mode: cors or same-origin, and ending the session from
   one of those would sign someone out mid-work over a request they never saw.
   Those still get the 403. A browser old enough to send no Sec-Fetch-Mode is
   treated as opening a page, which is also what curl looks like. */
function require_role(array $roles): void {
  $u = current_user();
  if ($u && in_array($u['user_role'], $roles, true)) { return; }

  $tab = in_array('student', $roles, true) ? 'student' : 'faculty';

  $opening_page = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
      && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate') === 'navigate';

  /* Signed out, and this is not somebody opening a page: answer it, do not
     redirect it. A page already open in another tab keeps working its autosaves
     and lookups after the session ends elsewhere, and sending those an HTML
     login panel with a 302 told them nothing they could act on: the JSON parse
     failed, the catch swallowed it, and the reader went on typing into a form
     that could no longer save a word of it. 401 says which of the two things is
     wrong, in a shape a script can read. */
  if (!$u && !$opening_page) {
    http_response_code(401);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'signed_out', 'signed_in' => false]));
  }
  if (!$u) { login_redirect($tab); }

  /* Not a page view, so the answer has to be something a script can read.
     This used to be the bare word Forbidden, which no fetch on the site can
     parse: the upload page reported "the server did not answer properly" and
     the reason never reached anyone. The 401 branch above has answered in
     JSON for a while; this is the same courtesy for the other refusal. */
  if (!$opening_page) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode([
      'success' => false,
      'error'   => 'wrong_role',
      'message' => 'This account is not allowed to do that. Sign in with the account that owns this work.',
    ]));
  }

  // A new id with nothing in it, so the flash below has somewhere to live and
  // the old session's file is deleted rather than left behind.
  $_SESSION = [];
  session_regenerate_id(true);
  flash('login_error', 'That page needs a different account, so you have been signed out. Sign in with an account that can open it.');
  login_redirect($tab);
}
function role_home(string $role): string {
  if($role === 'super_admin') return BASE_URL.'/app/admin/super_admin_review_dashboard.php';
  if($role === 'admin') {
    /* A level-2 admin is the Head of Academic Programs, and there is one desk
       for that job: the same one the head_academic role uses. It used to have
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
 * Two kinds of record mean Head of Academic Programs (the head_academic role,
 * and an admin at level 2) so both answer with the same name.
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
 * This one is for any list that can hold anybody (a roll of password changes,
 * a notice about an account) and uses the names the project uses everywhere
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
 * The account an ID names, when the ID really is that kind of account.
 *
 * Which column is searched follows the rule the rest of the app keeps:
 * student_id belongs to students, faculty_id to everybody else. Two roles do
 * the Head of Academic Programs' job, so the claimed role is matched against
 * what the account actually is rather than compared as a string: a Coordinator
 * is admin level 1, a HAP is either head_academic or admin level 2.
 *
 * One answer for "no such ID" and for "that ID is not that kind of account",
 * so it cannot be used to ask which of the two it was.
 *
 * @return int|null the user_id, or null when nothing matches
 */
function support_account_for(string $role, string $ident): ?int {
    $ident = trim($ident);
    if ($ident === '' || !array_key_exists($role, support_requester_roles())) return null;

    $col  = $role === 'student' ? 'student_id' : 'faculty_id';
    $stmt = db()->prepare(
        "SELECT user_id, user_role, admin_level FROM users WHERE $col = ? LIMIT 1");
    $stmt->bind_param('s', $ident);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $actual = (string)$row['user_role'];
    $level  = (int)($row['admin_level'] ?? 0);
    $ok = ($role === 'admin'         && $actual === 'admin' && $level !== 2)
       || ($role === 'head_academic' && ($actual === 'head_academic'
                                         || ($actual === 'admin' && $level === 2)))
       || (!in_array($role, ['admin', 'head_academic'], true) && $actual === $role);

    return $ok ? (int)$row['user_id'] : null;
}

/**
 * Do the name and the email given on the support form belong to that account?
 *
 * The ID says which account is being asked about; without this, anybody could
 * file a request in somebody else's name with their own address on it for the
 * reply. Checked field by field so the form can say which one is wrong.
 *
 * Names are compared loosely on purpose. Somebody who cannot sign in is being
 * asked to reproduce a string they last saw on a class list, so a middle name,
 * an initial, a comma or double spacing must not be what refuses them: an exact
 * match after flattening, or else the same first and last name, is enough.
 *
 * An account with no email on record has nothing to check against, so anything
 * passes there rather than nothing.
 *
 * @return array{name:bool, email:bool}
 */
function support_identity_matches(int $userId, string $name, string $email): array {
    $stmt = db()->prepare("SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['name' => false, 'email' => false];

    $flatten = static function (string $v): string {
        $v = mb_strtolower(trim($v));
        $v = str_replace(['.', ','], ' ', $v);
        return trim(preg_replace('/\s+/', ' ', $v));
    };

    $given  = $flatten($name);
    $onFile = $flatten((string)$row['full_name']);
    $nameOk = $given !== '' && $given === $onFile;
    if (!$nameOk && $given !== '' && $onFile !== '') {
        $a = explode(' ', $given);
        $b = explode(' ', $onFile);
        $nameOk = count($a) > 1 && count($b) > 1
               && $a[0] === $b[0] && end($a) === end($b);
    }

    $mailOnFile = mb_strtolower(trim((string)($row['email'] ?? '')));
    $emailOk = $mailOnFile === '' || mb_strtolower(trim($email)) === $mailOnFile;

    return ['name' => $nameOk, 'email' => $emailOk];
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
 * email: one of the two will land.
 *
 * The new password is included when there is one. That is a deliberate choice
 * and not a small one: it puts a working password in a mailbox. It is here
 * because the alternative was worse in practice: the password was read out or
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
 * for somebody who cannot get in. Both belong on the same roll (what the reader
 * wants to know is when this account last had a new password, however it came
 * about) so both come through here.
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
 * answered and the row is of no further use: leaving it would have every desk
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
 * it, and it is theirs to hand over: putting it in an email would undo that
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
 * Whether this database has the manuscript_requests table.
 *
 * It was added by run_manuscript_requests_migration.php and never made it into
 * database/schema.sql, so a copy of the site built from that file (staging) has
 * no such table. From PHP 8.1 a query on a missing table throws, and the public
 * paper page asks about a student's grant on every visit: every student who
 * opened a paper in the repository there got the "Something Went Wrong" page.
 * Until the table exists there are simply no requests and no grants, and the
 * pages say that requests are not open yet. scripts/migrations/sql/2026-10-27_manuscript_requests.sql
 * creates it from phpMyAdmin.
 */
function manuscript_requests_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $r = db()->query("SHOW TABLES LIKE 'manuscript_requests'");
        $ready = $r && $r->num_rows > 0;
    }
    return $ready;
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
    if (!manuscript_requests_ready()) return false;      // no table, so no grant can exist
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

/**
 * May this person read one of a paper's files in PAPEL's viewer?
 *
 * app/paper_file.php hands out the bytes, so it cannot rely on a tile having
 * been on the page; this repeats, in one place, the answer the three pages
 * with file tiles already give:
 *   - archive/view_paper.php: a published or archived paper's manuscript, for
 *     staff, and for a student holding an open grant from a Librarian;
 *   - app/review_paper.php: every file, of any paper for the Coordinator and of
 *     their own students' papers for a Research Adviser;
 *   - app/student/paper_details.php: every file of the student's own paper.
 * Supporting documents belong to the submission, not the published paper, so
 * the public page never offers them and they are not reachable through it.
 *
 * $paper needs paper_id, uploaded_by and current_status; '_archived' marks a
 * row read from papers_archive.
 */
function paper_file_viewable(array $u, array $paper, bool $supporting): bool {
    $uid  = (int)($u['user_id'] ?? 0);
    $role = (string)($u['user_role'] ?? '');
    if ($uid <= 0 || $role === 'guest') return false;

    $author = (int)($paper['uploaded_by'] ?? 0);
    if ($author === $uid) return true;
    if ($role === 'admin') return true;
    if ($role === 'faculty' && $author > 0 && (int)creator_of($author) === $uid) return true;
    if ($supporting) return false;

    $published = !empty($paper['_archived']) || ($paper['current_status'] ?? '') === 'approved';
    if (!$published) return false;
    if (in_array($role, ['faculty', 'super_admin', 'head_academic', 'librarian'], true)) return true;
    return $role === 'student' && student_manuscript_access($uid, (int)$paper['paper_id']);
}

/**
 * The largest upload this server will actually accept, in bytes.
 *
 * The smallest of three ceilings wins: PHP's upload_max_filesize, PHP's
 * post_max_size, and UPLOAD_MAX_MB, which stands for a limit the host applies
 * below PHP (free hosting caps every file at 10 MB in the file system, where
 * no ini setting reaches). Pages and validators ask this rather than carrying
 * their own number, because the figure was written into eight places and a
 * host change made all eight a lie at once.
 */
function max_upload_bytes(): int {
    $limits = [UPLOAD_MAX_MB * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $raw = trim((string)ini_get($key));
        if ($raw === '' || $raw === '-1') continue;   // unlimited, so not a ceiling
        $unit = strtolower($raw[strlen($raw) - 1]);
        $n = (int)$raw;
        if ($unit === 'g') $n *= 1024 * 1024 * 1024;
        elseif ($unit === 'm') $n *= 1024 * 1024;
        elseif ($unit === 'k') $n *= 1024;
        if ($n > 0) $limits[] = $n;
    }
    return (int)min($limits);
}

/** The same limit as a label for a reader: "10MB". */
function max_upload_label(): string {
    return floor(max_upload_bytes() / 1048576) . 'MB';
}

/**
 * The most one request may carry, in bytes: PHP's post_max_size. 0 when it is
 * unlimited.
 *
 * max_upload_bytes() is the ceiling for a single file. A paper handed in as
 * IMRAD and as Full Manuscript sends two of them in one request, together with
 * any supporting documents, and PHP refuses the whole request when they pass
 * this between them, however far each file is under its own limit. The upload
 * page adds the sizes up against this before it sends anything.
 */
function max_post_bytes(): int {
    $raw = trim((string)ini_get('post_max_size'));
    if ($raw === '' || $raw === '-1') return 0;
    $unit = strtolower($raw[strlen($raw) - 1]);
    $n = (int)$raw;
    if ($unit === 'g')      $n *= 1024 * 1024 * 1024;
    elseif ($unit === 'm')  $n *= 1024 * 1024;
    elseif ($unit === 'k')  $n *= 1024;
    return max(0, $n);
}

/** The same limit as a label for a reader: "40MB". */
function max_post_label(): string {
    return floor(max_post_bytes() / 1048576) . 'MB';
}

/**
 * The URL of one of the site's own static files, stamped with when it last
 * changed.
 *
 * Apache serves assests/ with a Last-Modified date and no Cache-Control, so a
 * browser keeps its copy for about a tenth of the file's age without asking
 * again, for a stylesheet untouched for a month, three or four days. An edit
 * then did not reach anyone holding the old copy: the file viewer's dark
 * background shipped and one reader went on seeing the light one. The stamp
 * moves whenever the file does, so the URL is new and the browser fetches it;
 * an unchanged file keeps its URL and stays cached.
 */
function asset_url(string $path): string {
    $path = ltrim($path, '/');
    $file = ROOT_PATH . '/' . $path;
    $stamp = is_file($file) ? (int)filemtime($file) : 0;
    return rtrim(BASE_URL, '/') . '/' . $path . ($stamp ? '?v=' . $stamp : '');
}

/**
 * Where PAPEL's viewer asks for a file: 'paper' for the paper's primary
 * manuscript, 'manuscript' for one of its files by paper_manuscripts.manuscript_id,
 * 'doc' for a supporting document.
 */
function paper_file_src(string $kind, int $id): string {
    $param = $kind === 'doc' ? 'doc' : ($kind === 'manuscript' ? 'manuscript' : 'paper');
    return rtrim(BASE_URL, '/') . '/app/paper_file.php?' . $param . '=' . $id;
}

/**
 * The full-window viewer's address for a file, given the file's own address
 * from paper_file_src(): the viewer takes the same query. For a link that opens
 * the file in a tab of its own, where the page has no panel to show it in.
 */
function paper_viewer_url(string $fileSrc): string {
    $q = strpos($fileSrc, '?');
    return rtrim(BASE_URL, '/') . '/app/student/pdf_viewer.php' . ($q === false ? '' : substr($fileSrc, $q));
}

/** How many manuscripts a student currently holds open access to, across every paper. */
function student_manuscript_active_count(int $studentUserId): int {
    if (!manuscript_requests_ready()) return 0;
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
    if (!manuscript_requests_ready()) return null;
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
 * had to print the whole name: "Bachelor of Science in Information
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
 * Anything the rule does not recognise returns null: an unfamiliar section is
 * a reason to leave the account alone, not to guess a date and lock someone out.
 */
function student_account_years(?string $section): ?int {
    $section = trim((string)$section);
    if ($section === '') return null;
    if (strcasecmp($section, 'ladderized') === 0) return 2;
    // "4-1", "4 - 1", "4A", only the year level in front matters.
    if (preg_match('/^\s*([1-4])\b/', $section, $m)) {
        return 6 - (int)$m[1];       // 1 -> 5, 2 -> 4, 3 -> 3, 4 -> 2
    }
    return null;
}

/**
 * The date a student account stops working.
 *
 * Counted in academic years from the one they were enrolled in, because that is
 * what the life is expressed in: a first year starting A.Y. 26-27 is good
 * through A.Y. 30-31, so the account lapses at the end of July 2031. With no
 * academic year on file the account's own creation date stands in for it.
 *
 * Returns null when the section carries no rule, meaning "no expiry".
 */
/**
 * The calendar year an academic year string starts in, or null if unreadable.
 *
 * Reads "26-27", "2026-2027" and "26/27" alike, since the field is typed by
 * hand. A two-digit year is read as this century.
 */
function academic_year_start_year(?string $ay): ?int {
    if (!preg_match('/(\d{2,4})\s*[-\/]\s*\d{2,4}/', (string)$ay, $m)) return null;
    $start = (int)$m[1];
    return $start < 100 ? $start + 2000 : $start;
}

/**
 * The academic year now running, written the way the forms write it.
 *
 * An academic year begins mid-calendar-year, so anything before June still
 * belongs to the intake that started the previous calendar year: the same rule
 * student_expiry_date() falls back on, kept in one place so the two cannot
 * drift apart.
 */
function academic_year_current(): string {
    $start = (int)date('n') >= 6 ? (int)date('y') : (int)date('y') - 1;
    return sprintf('%02d-%02d', $start, ($start + 1) % 100);
}

/**
 * Is this academic year one that has not started yet?
 *
 * An account's life is counted from the academic year written on it, so an
 * intake still to come would quietly hand the student an extra year before
 * their account expires. The year now running is as far forward as the forms
 * accept; past years stay open, because a roll is sometimes filled in late.
 */
function academic_year_is_future(?string $ay): bool {
    $start = academic_year_start_year($ay);
    if ($start === null) return false;          // unreadable is a separate complaint
    return $start > academic_year_start_year(academic_year_current());
}

function student_expiry_date(?string $section, ?string $academicYear, ?string $createdAt = null): ?string {
    $years = student_account_years($section);
    if ($years === null) return null;

    $startYear = academic_year_start_year($academicYear);
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

/**
 * An email address reduced to the inbox it actually reaches.
 *
 * Gmail ignores dots in the name and anything after a "+", so
 * juan.delacruz@gmail.com, juandelacruz@gmail.com and
 * JuanDelaCruz+papel@gmail.com are one inbox. Compared character for
 * character, the second and third passed for strangers to the first. Other
 * providers are compared whole: their rules differ, and guessing wrong would
 * merge two real people.
 */
function email_inbox_key(string $email): string {
  $email = strtolower(trim($email));
  $at = strrpos($email, '@');
  if ($at === false) return $email;
  $local  = substr($email, 0, $at);
  $domain = substr($email, $at + 1);
  if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
    $local  = str_replace('.', '', explode('+', $local, 2)[0]);
    $domain = 'gmail.com';
  }
  return $local . '@' . $domain;
}

/**
 * The account that already uses an email address (the same inbox, read as
 * email_inbox_key() reads it) or null. Inactive and expired accounts count:
 * the address still belongs to that person.
 */
function email_account_owner(string $email): ?array {
  $conn = db();
  /* email_inbox_key(), written in SQL so every account is compared on the
     same terms as the address being checked. */
  $stmt = $conn->prepare(
    "SELECT user_id, full_name, user_role, admin_level FROM users
      WHERE email IS NOT NULL AND email <> ''
        AND (CASE WHEN LOWER(SUBSTRING_INDEX(TRIM(email), '@', -1)) IN ('gmail.com', 'googlemail.com')
                  THEN CONCAT(REPLACE(SUBSTRING_INDEX(LOWER(SUBSTRING_INDEX(TRIM(email), '@', 1)), '+', 1), '.', ''),
                              '@gmail.com')
                  ELSE LOWER(TRIM(email)) END) = ?
      LIMIT 1");
  $key = email_inbox_key($email);
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
  return $row;
}

function creator_of(int $user_id): ?int {
  $conn = db();
  $stmt = $conn->prepare("SELECT created_by FROM users WHERE user_id=?");
  $stmt->bind_param('i',$user_id); $stmt->execute(); $stmt->bind_result($cid); $stmt->fetch(); return $cid ?: null;
}

/**
 * Post a notification, and email the same thing.
 *
 * $type must be one of the values notifications.notification_type accepts:
 * submission, approval, decline, reminder, comment, manuscript, security,
 * support, account. MariaDB is not in strict mode here, so anything else is
 * truncated to an empty string and the insert still reports success. That is
 * how every "approved and published" notice ended up typeless, and a typeless
 * row loses its icon and its label in the notification centre.
 *
 * $subject is for the types that mean two different things depending on who is
 * reading. An approval is "forwarded" to the student whose adviser has just
 * signed off and "published" to the one whose paper has reached the
 * repository; a manuscript notice is a request to a librarian and an answer to
 * a student. The map below is the fallback for everything that reads the same
 * way whoever receives it.
 */
function create_notification(int $user_id, ?int $paper_id, string $type,
                             string $message, string $subject = ''): void {
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
        'approval'    => 'Your Paper Has Been Approved',
        'decline'     => 'Your Paper Needs Revisions',
        'reminder'    => 'PAPEL Reminder: Papers Pending Your Action',
        'manuscript'  => 'About Your Manuscript Request',
      ];
      if ($subject === '') {
        $subject = $subjects[$type] ?? ('PAPEL Notification: ' . ucfirst($type));
      }

      /* Written as a letter rather than posted as a bare sentence. The message
         is one line, which is right for the bell but arrived in the letterhead
         with no greeting, nobody named and nothing to click: the reader was
         told their paper had been returned and left to find it themselves. */
      /* With no paper to open, the link is the reader's own desk, and
         role_home() works that out for an admin by reading admin_level from
         the session: here that is whoever triggered the notice, not the person
         being written to. A Head of Academic Programs would have been sent to
         the Coordinator's desk, and a Coordinator to the Head's. Level 2 is
         named by the other role that shares that desk, which role_home()
         answers without consulting the session. */
      $toRole = (string)($recipient['user_role'] ?? '');
      if (!$paper_id && $toRole === 'admin' && (int)($recipient['admin_level'] ?? 1) === 2) {
          $toRole = 'head_academic';
      }

      $body  = email_para('Good day ' . trim((string)($recipient['full_name'] ?? '')) . ',');
      $body .= email_para($message);
      $body .= email_action('Open it in ' . APP_NAME,
          notification_link($paper_id, $toRole, $type));

      send_email($recipient['email'], $subject, $body);
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

/**
 * The same message as plain text, for the copy that rides alongside the HTML.
 *
 * This was strip_tags(), which removes a tag without leaving anything in its
 * place: every paragraph ran into the next one, so the text copy opened
 * "Good day Maria,Your paper has been returned" and a credentials table came
 * out as one unbroken line of labels and values. A reader on a client that
 * prefers text, or a screen reader, got that version.
 *
 * Block ends become line breaks and table cells become a gap, then the
 * entities strip_tags() leaves behind are decoded, so a quoted title reads as
 * "A Study" rather than &quot;A Study&quot;.
 */
function email_plain(string $html): string {
    $text = preg_replace('#<br\s*/?>#i', "\n", $html);
    $text = preg_replace('#</(p|div|h1|h2|h3|tr|table)>#i', "\n", $text);
    // Label and value sit in separate cells; two spaces keep them apart.
    $text = preg_replace('#</t[dh]>#i', '  ', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Trailing spaces from the cell rule, and runs of blank lines from nesting.
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
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
 * email" whether or not anything was sent, and through a long spell of SMTP
 * authentication failures nothing was. A caller that cannot tell the
 * difference cannot warn anybody, which is the worst of both.
 *
 * Mail does go out from this machine at present, so anything that triggers a
 * notification reaches a real inbox. Test against addresses at example.com,
 * which is reserved and cannot receive mail.
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
      $mail->AltBody = email_plain($body);

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
 * Anything not named below (script, style, iframe, event handlers, javascript:
 * URLs, inline colours and fonts) is dropped, while the tags a student can
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
    // Spans and merges are structural (losing them scrambles the table) so
    // these two survive the attribute purge on cells, clamped to sane values.
    $cellSpanAttrs = ['colspan', 'rowspan'];
    // Removed outright, contents and all. Every other disallowed tag is merely
    // unwrapped so its words survive, but the text inside these is code, not
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
                // Comments, processing instructions, CDATA: none belong here.
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
                   style attribute is dropped: fonts and colours would carry
                   Word's shading into the repository, and url() is an attack
                   surface. */
                if (($isCell || $inCellBlock || $tag === 'table' || $isImage) && $name === 'style') {
                    $keep = [];
                    foreach (explode(';', (string)$attr->nodeValue) as $decl) {
                        if (strpos($decl, ':') === false) continue;
                        list($prop, $value) = explode(':', $decl, 2);
                        $prop  = trim(strtolower($prop));
                        $value = trim(strtolower($value));

                        // Alignment is a cell's business only: body text is
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
    // Cells are separated, not stacked, without this the row "Usability | 4.03"
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
 * swallowed messages we wrote *for* the student: "the AI service is rate
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
        'project'          => 'Feasibility Study',
    ];
}

/* ---- "Other", for a paper type or a manuscript type the lists do not name ------------
   Both dropdowns end in Other, the way a Google Form does: the student picks it and
   types what their paper is (a Case Study, a Policy Brief). What they type is kept
   with the paper and shown wherever the type is shown, in place of the word Other.

   Neither needs a column. paper_type and manuscript_type are already VARCHARs, so the
   typed name is stored in them with a marker in front ("other:Case Study",
   "Other: Policy Brief"), and every page that names a type goes through
   paper_type_label() or manuscript_type_label(), which take the marker off. A copy of
   the site whose database is behind the code needs nothing run for this. */

/** The value the paper type dropdown posts for Other, and the marker in front of a stored one. */
const PAPER_TYPE_OTHER        = 'other';
const PAPER_TYPE_OTHER_PREFIX = 'other:';
/* paper_type is VARCHAR(50), and the marker takes six of those. */
const PAPER_TYPE_OTHER_MAX    = 40;
/* manuscript_type is VARCHAR(100). */
const MANUSCRIPT_OTHER        = 'Other';
const MANUSCRIPT_OTHER_MAX    = 60;

/**
 * What a person typed beside Other, made safe to keep and to show: tags and control
 * characters gone, runs of white space made one, commas taken out (manuscript_type
 * is a comma-separated list, so a comma inside a name would split it in two), cut
 * to $max characters. Empty when nothing usable is left.
 */
function other_name_clean($raw, int $max): string {
    if (!is_scalar($raw)) return '';
    $text = strip_tags((string)$raw);
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    $text = str_replace(',', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim(mb_substr(trim($text), 0, $max));
}

/** At most four words: these boxes name a kind of document, not a sentence about one. */
const OTHER_NAME_MAX_WORDS = 4;

/**
 * Greetings and filler, refused when they are the whole of a name.
 *
 * Only when they are the whole of it: "Hi-Tech Study" is a name a student may really
 * mean, and one word of it matching should not take it away from them.
 */
function other_name_filler(): array {
    return ['hi', 'hii', 'hello', 'helo', 'hey', 'yo', 'hiya', 'good morning', 'good afternoon',
            'good evening', 'good day', 'thanks', 'thank you', 'please', 'test', 'testing', 'tests',
            'sample', 'example', 'none', 'na', 'n a', 'nil', 'null', 'idk', 'abc', 'xyz', 'dummy',
            'blank', 'nothing', 'random', 'try', 'trial', 'lorem ipsum', 'asdf', 'qwerty', 'zxcvbn'];
}

/**
 * Whether a name reads as something typed to get past the box rather than a name.
 *
 * Each rule is a shape a real name does not have, and the thresholds leave room for the
 * ones that come close: English reaches five consonants in "strengths" but not six, and
 * a short word with no vowel is an abbreviation rather than mashing. Letters only, so
 * punctuation and digits ("Chapter 5 Report") decide nothing.
 */
function other_name_looks_random(string $text): bool {
    $low     = mb_strtolower(trim($text), 'UTF-8');
    $letters = preg_replace('/[^a-z]/', '', $low) ?? '';
    if ($letters === '') return false;
    // One letter four times over.
    if (preg_match('/(.)\1{3,}/', $letters)) return true;
    /* A long run of very few letters: "adasdadsaddsd" and "sdfsdfdsfsd" are three letters
       shuffled, which nothing of that length that means anything is. */
    if (strlen($letters) >= 8 && count(array_unique(str_split($letters))) <= 4) return true;
    if (preg_match('/[bcdfghjklmnpqrstvwxz]{6,}/', $letters)) return true;
    foreach (['asdf', 'sdfg', 'dfgh', 'fghj', 'ghjk', 'qwer', 'wert', 'erty', 'rtyu', 'tyui',
              'zxcv', 'xcvb', 'cvbn'] as $run) {
        if (strpos($letters, $run) !== false) return true;
    }
    // A word of five letters or more with no vowel in it.
    foreach (preg_split('/[^a-z]+/', $low) ?: [] as $word) {
        if (strlen($word) >= 5 && !preg_match('/[aeiouy]/', $word)) return true;
    }
    return false;
}

/**
 * Why a name typed beside Other cannot be used, or null when it is fine.
 *
 * What goes in these boxes names a kind of document ("Policy Brief", "Case Study"), and
 * it is then what every reader sees for that paper: on its card, on its page and in the
 * filter. Left to itself the box took anything, so a greeting, keyboard mashing and a
 * whole sentence all went in and were published. $what names the box in the answer
 * ("paper type", "manuscript"), so the student is told which one to look at.
 */
function other_name_problem(string $name, string $what): ?string {
    $name = trim($name);
    if ($name === '') {
        return 'Please type what your ' . $what . ' is called.';
    }
    $letters = preg_replace('/[^\p{L}]+/u', '', $name) ?? '';
    if (mb_strlen($letters) < 2) {
        return 'Please write what your ' . $what . ' is called, in words.';
    }
    $words = preg_split('/\s+/u', $name) ?: [];
    if (count($words) > OTHER_NAME_MAX_WORDS) {
        return 'Please keep your ' . $what . ' to ' . OTHER_NAME_MAX_WORDS
             . ' words at most, the way "Policy Brief" and "Case Study" name one.';
    }
    $bare = preg_replace('/[^\p{L}\s]+/u', ' ', mb_strtolower($name, 'UTF-8')) ?? '';
    $bare = trim(preg_replace('/\s+/u', ' ', $bare) ?? '');
    if ($bare !== '' && in_array($bare, other_name_filler(), true)) {
        return 'Please write what your ' . $what . ' is called, not a greeting.';
    }
    if (other_name_looks_random($name)) {
        return 'That does not look like a name. Please write what your ' . $what
             . ' is called, like "Policy Brief" or "Case Study".';
    }
    return null;
}

/** The name typed for a paper type of Other ("Case Study"), or null when the type is not Other. */
function paper_type_other_name(?string $paperType): ?string {
    $type = (string)$paperType;
    if (stripos($type, PAPER_TYPE_OTHER_PREFIX) !== 0) return null;
    return other_name_clean(substr($type, strlen(PAPER_TYPE_OTHER_PREFIX)), PAPER_TYPE_OTHER_MAX);
}

/**
 * What the paper_type column should hold for what the form posted.
 *
 * $strict is a submission: an unknown code is refused (null), and Other with nothing
 * typed beside it is refused in words the student can act on. Not strict is a draft,
 * which keeps whatever has been answered so far: Other with no name yet is kept as
 * "other:", and an unknown code as nothing.
 */
function paper_type_from_post($code, $otherRaw, bool $strict): ?string {
    $code = strtolower(trim(is_scalar($code) ? (string)$code : ''));
    if (array_key_exists($code, paper_types())) return $code;
    if ($code === PAPER_TYPE_OTHER) {
        $name = other_name_clean($otherRaw, PAPER_TYPE_OTHER_MAX);
        if ($name === '' && $strict) {
            throw new UserFacingException('Please type your paper type in the box beside Other.');
        }
        /* Only on a submission. A draft keeps whatever has been typed so far, and a
           student stopping halfway through a word should not be told off for it. */
        if ($strict) {
            $problem = other_name_problem($name, 'paper type');
            if ($problem !== null) throw new UserFacingException($problem);
        }
        return PAPER_TYPE_OTHER_PREFIX . $name;
    }
    return $strict ? null : '';
}

/**
 * The folder a paper's files are filed under for its type, on disk and on Drive.
 *
 * Every typed type goes in the one folder "other". The name a student typed is not
 * a safe folder name: a colon cannot be in a Windows path at all (the first paper
 * filed as Other failed here, moving its PDF into "other:Case Study"), and a slash or
 * two dots would walk out of the uploads folder. The name is kept on the paper.
 */
function paper_type_folder(string $paperType): string {
    return paper_type_other_name($paperType) !== null ? PAPER_TYPE_OTHER : $paperType;
}

/**
 * The paper types to offer as a filter: the ones on the form, then Other, which
 * matches every paper whose author typed their own.
 */
function paper_type_filter_options(): array {
    return paper_types() + [PAPER_TYPE_OTHER => 'Other'];
}

/**
 * A WHERE fragment for filtering on a paper type, adding its value to $params and its
 * type to $types. "other" matches every typed one; anything else is matched exactly.
 */
function paper_type_filter_sql(string $column, string $value, array &$params, string &$types): string {
    if (strtolower($value) === PAPER_TYPE_OTHER) {
        $params[] = PAPER_TYPE_OTHER_PREFIX . '%';
        $types   .= 's';
        return "$column LIKE ?";
    }
    $params[] = $value;
    $types   .= 's';
    return "$column = ?";
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
function manuscript_types_posted($posted, $otherRaw = null): string
{
    /* Other stands on its own: a paper handed in as something the list does not
       name is that, and not also an IMRAD or a Full Manuscript (the page unticks the
       others when it is ticked, and this is the authority). It is stored with what was
       typed beside it, "Other: Policy Brief"; a draft may not have a name yet. */
    foreach ((array)$posted as $one) {
        if (trim((string)$one) === MANUSCRIPT_OTHER) {
            $name = other_name_clean($otherRaw, MANUSCRIPT_OTHER_MAX);
            return $name !== '' ? MANUSCRIPT_OTHER . ': ' . $name : MANUSCRIPT_OTHER;
        }
    }
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

/**
 * The key a manuscript format is filed under in paper_manuscripts.format.
 *
 * The names in manuscript_types() are what a student reads and may be reworded;
 * the key is what rows are matched on. Kept beside it so the two cannot drift.
 */
function manuscript_format_key(?string $name): ?string
{
    $name = trim((string)$name);
    if ($name === 'IMRAD')           return 'imrad';
    if ($name === 'Full Manuscript') return 'full';
    if ($name === MANUSCRIPT_OTHER || strpos($name, MANUSCRIPT_OTHER . ':') === 0) return 'other';
    return null;
}

/** The name a student reads for a format key. */
function manuscript_format_label(string $key): string
{
    if ($key === 'other') return MANUSCRIPT_OTHER;
    return $key === 'full' ? 'Full Manuscript' : 'IMRAD';
}

/** The name typed for a manuscript type of Other ("Policy Brief"), or null when it is not Other. */
function manuscript_other_name(?string $manuscriptType): ?string
{
    foreach (explode(',', (string)$manuscriptType) as $one) {
        $one = trim($one);
        if ($one === MANUSCRIPT_OTHER) return '';
        if (strpos($one, MANUSCRIPT_OTHER . ':') === 0) {
            return other_name_clean(substr($one, strlen(MANUSCRIPT_OTHER) + 1), MANUSCRIPT_OTHER_MAX);
        }
    }
    return null;
}

/**
 * The manuscript type as a reader should see it: "IMRAD, Full Manuscript", or for
 * Other the name the author typed ("Policy Brief"), never the stored marker.
 */
function manuscript_type_label(?string $manuscriptType): string
{
    $other = manuscript_other_name($manuscriptType);
    if ($other !== null) return $other !== '' ? $other : MANUSCRIPT_OTHER;
    return trim((string)$manuscriptType);
}

/**
 * The formats a paper says it was handed in as, as keys, in the order its files
 * are listed. Empty for an older paper that recorded none.
 */
function paper_declared_formats(?string $manuscriptType): array
{
    $found = [];
    foreach (explode(',', (string)$manuscriptType) as $one) {
        $key = manuscript_format_key($one);
        if ($key !== null) $found[$key] = true;
    }
    $out = [];
    foreach (['imrad', 'full', 'other'] as $key) {
        if (isset($found[$key])) $out[] = $key;
    }
    return $out;
}

/**
 * Which of a paper's formats is its primary file: the one research_papers'
 * own file_path / gdrive_file_id / file_size mirror.
 *
 * The full manuscript is the complete record, so it wins when there is one.
 * Everything that only needs "the paper's file" (the AI summary, archiving and
 * restoring, a ?paper= link already out in the world) reads those columns, so
 * every place that writes a paper's files has to keep them on this one.
 */
function paper_primary_format(array $formats): ?string
{
    if (in_array('full', $formats, true))  return 'full';
    if (in_array('imrad', $formats, true)) return 'imrad';
    if (in_array('other', $formats, true)) return 'other';
    return null;
}

/**
 * Whether paper_manuscripts exists yet.
 *
 * The code can reach a server before its migration does, and a page that cannot
 * read the table should go on showing the paper's own file, not stop. Asked
 * once per request.
 */
function paper_manuscripts_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $r = db()->query("SHOW TABLES LIKE 'paper_manuscripts'");
        $ready = $r && $r->num_rows > 0;
    }
    return $ready;
}

/**
 * The manuscript files of any number of papers, in one query.
 *
 * @return array<int, array<string, array>>  paper_id => format key => row
 */
function paper_manuscript_rows(array $paperIds): array
{
    $ids = [];
    foreach ($paperIds as $id) {
        if ((int)$id > 0) $ids[(int)$id] = (int)$id;
    }
    $ids = array_values($ids);
    if (!$ids || !paper_manuscripts_ready()) return [];

    $st = db()->prepare(
        "SELECT manuscript_id, paper_id, format, file_path, gdrive_file_id, file_size
           FROM paper_manuscripts
          WHERE paper_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
          ORDER BY paper_id, FIELD(format, 'imrad', 'full', 'other')");
    if (!$st) return [];
    $st->bind_param(str_repeat('i', count($ids)), ...$ids);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int)$r['paper_id']][$r['format']] = $r;
    }
    $st->close();
    return $out;
}

/**
 * The manuscript files of one paper, as the pages list them.
 *
 * One entry per file: key, format ('imrad', 'full' or null), label, src (the
 * viewer address, null when the file is not there), required and size in bytes.
 *
 * Two files are told apart by name, "Manuscript (IMRAD)" and "Manuscript (Full
 * Manuscript)". One file, or none, is simply "Manuscript" as it always was, so a
 * paper filed before there could be two reads exactly as it did. That holds even
 * for an older paper ticked as both with one file: which of the two that file
 * is was never recorded, and a label that guessed would be wrong half the time.
 *
 * A paper with no row of its own to read (filed before paper_manuscripts, or
 * before the migration reached this server) falls back to its own file columns.
 * $rows may be passed when a page has already fetched them for a whole list.
 */
function paper_manuscript_entries(int $paperId, array $paper, ?array $rows = null): array
{
    if ($rows === null) {
        $rows = paper_manuscript_rows([$paperId])[$paperId] ?? [];
    }

    $present = [];
    foreach (['imrad', 'full', 'other'] as $key) {
        $r = $rows[$key] ?? null;
        if ($r && paper_file_url($r['gdrive_file_id'] ?? null, $r['file_path'] ?? null)) {
            $present[$key] = $r;
        }
    }

    if (count($present) > 1) {
        $out = [];
        foreach ($present as $key => $r) {
            $out[] = [
                'key'      => 'manuscript_' . $key,
                'format'   => $key,
                'label'    => 'Manuscript (' . manuscript_format_label($key) . ')',
                'src'      => paper_file_src('manuscript', (int)$r['manuscript_id']),
                'required' => true,
                'size'     => (int)($r['file_size'] ?? 0),
            ];
        }
        return $out;
    }
    if (count($present) === 1) {
        $key = array_keys($present)[0];
        return [[
            'key'      => 'manuscript',
            'format'   => $key,
            'label'    => 'Manuscript',
            'src'      => paper_file_src('manuscript', (int)$present[$key]['manuscript_id']),
            'required' => true,
            'size'     => (int)($present[$key]['file_size'] ?? 0),
        ]];
    }

    $has = paper_file_url($paper['gdrive_file_id'] ?? null, $paper['file_path'] ?? null);
    return [[
        'key'      => 'manuscript',
        'format'   => null,
        'label'    => 'Manuscript',
        'src'      => $has ? paper_file_src('paper', $paperId) : null,
        'required' => true,
        'size'     => (int)($paper['file_size'] ?? 0),
    ]];
}

function paper_types_retired(): array {
    return [
        'research'   => 'Research Paper',
        'article'    => 'Article',
        'thesis'     => 'Thesis',
        // No longer offered (the department files capstones, theses and feasibility studies).
        'conference' => 'Conference Paper',
        'journal'    => 'Journal Article',
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
    $other = paper_type_other_name($paperType);
    if ($other !== null) return $other !== '' ? $other : 'Other';
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
 * The reserved key inside imrad_content that records which of the written
 * sections the author chose to submit.
 *
 * It lives with the sections rather than in a column of its own, for a reason
 * that is easy to lose. Archiving deletes the row from research_papers and
 * writes it into papers_archive from one explicit column list
 * (ArchiveRepository), and restoring reads the same list back. imrad_content is
 * already on that list, so whatever rides inside it survives both with no change
 * anywhere, and no server needs a migration run before it can take this code. A
 * new column would have had to be added to two tables, to that list and to its
 * bind types, and a server missing any one of them would have failed on
 * archiving.
 *
 * Every reader loops over paper_section_labels() and indexes into the decoded
 * object, so a key that is not one of those is invisible to all of them.
 */
const PAPER_SCOPE_KEY = '_scope';

/**
 * Reads a choice of sections from whatever carried it: the comma-separated
 * string the form posts, or a list. Null means nothing valid was chosen.
 *
 * The result is always in the order paper_section_labels() gives, whatever order
 * it arrived in, and always includes the abstract: the cards, the search and the
 * AI all read the abstract column, so a paper without one cannot be listed.
 * Anything that is not a known section is dropped, so a hand-made request cannot
 * invent one.
 */
/**
 * What the form posts for "none of the six": a paper written entirely in sections of
 * its author's own (Custom), Abstract left out. An empty field already means "nothing
 * chosen yet", so "none" needs a word of its own.
 */
const SECTION_SCOPE_NONE = '_none';

/*
 * $forceAbstract is the rule for every paper but one kind. The Abstract may be left out
 * only by an author submitting sections of their own (Custom), and the caller is the one
 * that knows whether there are any: it asks with false, and puts the Abstract back
 * itself when there are none (section_scope_for()).
 */
function section_scope_parse($raw, bool $forceAbstract = true): ?array {
    if (is_string($raw) && trim($raw) === SECTION_SCOPE_NONE) {
        return $forceAbstract ? ['abstract'] : [];
    }
    if (is_string($raw)) { $raw = explode(',', $raw); }
    if (!is_array($raw)) { return null; }
    if (!$raw && !$forceAbstract) { return []; }

    $known  = array_keys(paper_section_labels());
    $picked = [];
    foreach ($raw as $key) {
        if (!is_scalar($key)) { continue; }
        $key = trim((string)$key);
        if (in_array($key, $known, true)) { $picked[$key] = true; }
    }
    if (!$picked) { return null; }
    if ($forceAbstract) { $picked['abstract'] = true; }

    return array_values(array_filter($known, function ($k) use ($picked) {
        return isset($picked[$k]);
    }));
}

/**
 * The choice, or every section when none was made. That is what a page opened
 * before this existed posts, so it keeps meaning "all of them".
 */
function section_scope_resolve($raw, bool $forceAbstract = true): array {
    return section_scope_parse($raw, $forceAbstract) ?? array_keys(paper_section_labels());
}

/**
 * The standard sections a post asks for, with the Abstract left out only where the
 * author has sections of their own to stand in for it. Anything else gets the Abstract
 * back: the cards, the search and the AI read it, and a paper with no written section
 * at all would have nothing on its page.
 *
 * $resolve is a submission (nothing chosen means all six); otherwise a draft, where
 * nothing chosen stays null.
 */
function section_scope_for($raw, $customRaw, bool $resolve): ?array {
    $scope = $resolve ? section_scope_resolve($raw, false) : section_scope_parse($raw, false);
    if ($scope === null || in_array('abstract', $scope, true)) { return $scope; }
    if (paper_custom_list($customRaw, $scope)) { return $scope; }
    return $resolve ? section_scope_resolve($raw) : section_scope_parse($raw);
}

/**
 * The choice stored with a paper, or null when none was ever recorded.
 *
 * Null covers a paper filed before the choice existed and a draft saved before
 * one was made. It is not the same as "all sections", and callers must not
 * treat it as a claim about what the author meant.
 */
function paper_section_scope($imradContent): ?array {
    if (!is_string($imradContent) || $imradContent === '') { return null; }
    $decoded = json_decode($imradContent, true);
    if (!is_array($decoded) || !array_key_exists(PAPER_SCOPE_KEY, $decoded)) { return null; }
    /* A paper with sections of its own may have been filed without the Abstract
       (section_scope_for()), so its list is read as it stands. Any other is repaired
       the way a post is, Abstract put back, an empty list unrecorded. */
    return section_scope_parse($decoded[PAPER_SCOPE_KEY], !paper_custom_sections($imradContent));
}

/** "A", "A and B", "A, B and C". */
function paper_scope_list(array $names): string {
    $names = array_values($names);
    $n = count($names);
    if ($n === 0) { return ''; }
    if ($n === 1) { return $names[0]; }
    return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$n - 1];
}

/**
 * The author's own sections, kept beside the six standard ones.
 *
 * A Feasibility Study has an Executive Summary and five Aspects, a thesis has
 * chapters, and neither is helped by boxes named Methodology and Results and
 * Discussion. The author can add sections of their own name in the question
 * "Which sections will you submit?"; each gets an editor, is required like a
 * ticked standard one, and is shown wherever the written sections are shown.
 *
 * They live inside imrad_content under this reserved key, for the same reason
 * the choice does (see PAPER_SCOPE_KEY): archive and restore copy that column
 * whole, and no server needs a migration run before it can take this code.
 *
 *   "_custom": [ {"key": "custom_1", "label": "Executive Summary", "html": "<p>...</p>"}, ... ]
 *
 * No such key means no sections of their own, so every paper filed before this
 * existed reads exactly as it did.
 */
const PAPER_CUSTOM_KEY = '_custom';
const PAPER_CUSTOM_MAX = 12;
/* More keys than sections. A section the author is taking out while it still holds text is
   kept, struck through, until they confirm, and keeps its key meanwhile; sections added in
   the same breath need keys of their own. Twelve live plus twelve waiting is the most that
   can be at once. */
const PAPER_CUSTOM_KEY_MAX = 24;

/** Whether a string is the key of an author's section: custom_1 to custom_24 and nothing else. */
function paper_custom_key_valid($key): bool {
    return is_string($key) && preg_match('/^custom_([1-9][0-9]?)$/', $key, $m) && (int)$m[1] <= PAPER_CUSTOM_KEY_MAX;
}

/**
 * The ready-made lists the question offers to start from. They are the lists
 * the adviser's checklist already uses, so a study is asked for the sections
 * it is reviewed against. Full Manuscript and Undergraduate Thesis are the same
 * chapters; both are offered because people look for either name.
 */
function paper_custom_presets(): array {
    $t = paper_checklist_templates();
    return [
        /* The form's own five after the Abstract, as sections of the author's own, so they
           keep the order the author gives them. Only a row called Abstract is filed as the
           real Abstract (the upload page's picker), since the cards and the search read it. */
        ['id' => 'imrad',            'name' => 'IMRAD',                'items' => array_values(array_diff(paper_section_labels(), ['Abstract']))],
        ['id' => 'full_manuscript',  'name' => 'Full Manuscript',      'items' => array_values($t['undergrad_thesis']['items'])],
        ['id' => 'undergrad_thesis', 'name' => 'Undergraduate Thesis', 'items' => array_values($t['undergrad_thesis']['items'])],
        ['id' => 'feasibility',      'name' => 'Feasibility Study',    'items' => array_values($t['feasibility']['items'])],
    ];
}

/**
 * The names an author posted for their own sections, made into what may be
 * kept: [['key', 'label'], ...] in the order given. The page's own limits are a
 * convenience; this is the authority, so it never trusts them and never throws:
 * whatever is not usable is dropped.
 *
 *   - a key must be custom_1 to custom_24, once
 *   - the name has its tags and control characters stripped, runs of white
 *     space made one, and is cut to a line's length (the checklist's rule)
 *   - a name used twice is kept once, whatever the case
 *   - a name that is the same as a standard section the author is also
 *     submitting is dropped, since that box already covers it. One that matches
 *     a standard section they left out stays: that is how a thesis puts its
 *     References after its chapters rather than before them
 *   - at most PAPER_CUSTOM_MAX
 *
 * $raw is the posted JSON, or the list itself; $scope the standard keys in play.
 */
function paper_custom_list($raw, array $scope): array {
    if (is_string($raw)) { $raw = json_decode($raw, true); }
    if (!is_array($raw)) { return []; }

    $taken = [];
    foreach (paper_section_labels() as $key => $label) {
        if (in_array($key, $scope, true)) { $taken[mb_strtolower($label)] = true; }
    }

    $out  = [];
    $keys = [];
    foreach ($raw as $entry) {
        if (count($out) >= PAPER_CUSTOM_MAX) { break; }
        if (!is_array($entry) || !paper_custom_key_valid($entry['key'] ?? null)) { continue; }
        if (isset($keys[$entry['key']])) { continue; }
        $label = paper_checklist_label_clean($entry['label'] ?? '');
        if ($label === '') { continue; }
        $fold = mb_strtolower($label);
        if (isset($taken[$fold])) { continue; }
        $keys[$entry['key']] = true;
        $taken[$fold] = true;
        $out[] = ['key' => $entry['key'], 'label' => $label];
    }
    return $out;
}

/** Whether sanitised section HTML holds nothing the author wrote: no text, no picture, no table. */
function paper_section_html_is_empty(string $clean): bool {
    return rich_text_to_plain($clean) === '' && !preg_match('/<(img|table)\b/i', $clean);
}

/**
 * The author's own sections as they are to be stored: the names cleaned as
 * above, each body sanitised like any other section. With $require the body of
 * every one must be written (a submission); without it an empty one is kept as
 * it is (a draft, which keeps whatever has been typed so far).
 *
 * Returns [['key', 'label', 'html'], ...]. Throws UserFacingException, in words
 * the student can act on, for a body left empty under $require.
 */
function paper_custom_clean($raw, array $post, array $scope, bool $require): array {
    $out = [];
    foreach (paper_custom_list($raw, $scope) as $c) {
        $clean = rich_text_sanitize((string)($post[$c['key']] ?? ''));
        if ($require && paper_section_html_is_empty($clean)) {
            throw new UserFacingException('Please write the ' . $c['label'] . ' section.');
        }
        $out[] = ['key' => $c['key'], 'label' => $c['label'], 'html' => $clean];
    }
    return $out;
}

/**
 * The author's own sections stored with a paper: [['key', 'label', 'html'], ...],
 * empty for a paper that has none. Anything in the stored object that does not
 * pass the same tests as a post is ignored, so a damaged row cannot break a page.
 */
function paper_custom_sections($imradContent): array {
    if (!is_string($imradContent) || $imradContent === '') { return []; }
    $decoded = json_decode($imradContent, true);
    if (!is_array($decoded) || !is_array($decoded[PAPER_CUSTOM_KEY] ?? null)) { return []; }

    $out  = [];
    $seen = [];
    foreach ($decoded[PAPER_CUSTOM_KEY] as $entry) {
        if (count($out) >= PAPER_CUSTOM_MAX) { break; }
        if (!is_array($entry) || !paper_custom_key_valid($entry['key'] ?? null) || isset($seen[$entry['key']])) { continue; }
        $label = paper_checklist_label_clean($entry['label'] ?? '');
        if ($label === '') { continue; }
        $seen[$entry['key']] = true;
        $out[] = ['key' => $entry['key'], 'label' => $label, 'html' => is_string($entry['html'] ?? null) ? $entry['html'] : ''];
    }
    return $out;
}

/**
 * What a page shows of a paper's written sections, in the order it shows them:
 * the six standard ones, then the author's own.
 *
 *   labels    key => name, every section the paper can have (the pages loop over this)
 *   sections  key => HTML, only those with something in them
 *   legacy    true when there were none and the abstract column stands in
 *
 * Three pages used to build this themselves, each from the six names alone.
 * A section that holds only a picture or a table counts as written, as it does
 * when it is submitted.
 */
function paper_sections_for_display($imradContent, $abstract): array {
    $labels   = paper_section_labels();
    $sections = [];

    $decoded = (is_string($imradContent) && $imradContent !== '') ? json_decode($imradContent, true) : null;
    if (is_array($decoded)) {
        foreach ($labels as $key => $_) {
            if (is_string($decoded[$key] ?? null) && !paper_section_html_is_empty($decoded[$key])) {
                $sections[$key] = $decoded[$key];
            }
        }
    }
    foreach (paper_custom_sections($imradContent) as $c) {
        $labels[$c['key']] = $c['label'];
        if (!paper_section_html_is_empty($c['html'])) { $sections[$c['key']] = $c['html']; }
    }

    $legacy = false;
    if (!$sections && trim((string)$abstract) !== '') {
        $sections['abstract'] = '<p>' . nl2br(e((string)$abstract)) . '</p>';
        $legacy = true;
    }
    return ['labels' => $labels, 'sections' => $sections, 'legacy' => $legacy];
}

/**
 * What a paper's stored sections say about the author's choice, in the shape
 * every page needs.
 *
 *   kind      full | abstract | partial | custom | legacy
 *   keys      the standard section keys provided
 *   provided  the labels of everything provided: the standard ones, then the author's own
 *   omitted   the labels of the standard sections left out
 *   custom    the labels of the author's own sections
 *   short     the words for a chip: "Abstract only", "3 of 6 sections", "Custom: 7 sections"
 *
 * A legacy paper is treated as complete and nothing is claimed for it, because
 * there is no way to know what its author intended.
 */
function paper_scope_info($imradContent): array {
    $labels = paper_section_labels();
    $scope  = paper_section_scope($imradContent);
    $custom = array_column(paper_custom_sections($imradContent), 'label');

    if ($scope === null) {
        return [
            'recorded' => false, 'kind' => 'legacy',
            'keys'     => array_keys($labels), 'provided' => array_merge(array_values($labels), $custom),
            'omitted'  => [], 'custom' => $custom, 'short' => '',
        ];
    }

    $provided = [];
    $omitted  = [];
    foreach ($labels as $key => $label) {
        if (in_array($key, $scope, true)) { $provided[] = $label; }
        else                              { $omitted[]  = $label; }
    }
    $provided = array_merge($provided, $custom);

    /* Standard sections left out in favour of the author's own is a different
       story from leaving them out: nothing is missing, the paper is organised
       its own way. Their own sections on top of all six is simply a full paper. */
    if (!$omitted)               { $kind = 'full'; }
    elseif ($custom)             { $kind = 'custom'; }
    elseif (count($scope) === 1) { $kind = 'abstract'; }
    else                         { $kind = 'partial'; }

    $short = $kind === 'full'      ? 'All sections'
           : ($kind === 'abstract' ? 'Abstract only'
           : ($kind === 'custom'   ? 'Custom: ' . count($provided) . ' sections'
                                   : count($scope) . ' of ' . count($labels) . ' sections'));

    return [
        'recorded' => true, 'kind' => $kind, 'keys' => $scope,
        'provided' => $provided, 'omitted' => $omitted, 'custom' => $custom, 'short' => $short,
    ];
}

/** Whether the author left sections out: the only case worth drawing attention to. */
function paper_scope_is_partial(array $info): bool {
    return in_array($info['kind'], ['abstract', 'partial', 'custom'], true);
}

/**
 * A chip for a paper whose author left sections out, and nothing for any other.
 * A complete paper is the rule and is not flagged.
 */
function paper_scope_chip(array $info): string {
    if (!paper_scope_is_partial($info)) { return ''; }
    $title = 'Provided: ' . paper_scope_list($info['provided'])
           . '. Left out by the author: ' . paper_scope_list($info['omitted']) . '.';
    return '<span class="scope-chip" title="' . e($title) . '">'
         . '<span class="material-symbols-outlined" aria-hidden="true">segment</span>'
         . e($info['short']) . '</span>';
}

/**
 * One sentence about the choice, worded for whoever is reading it. Empty for a
 * complete or legacy paper, which has nothing to explain.
 *
 *   reviewer  on the review page, where an absent section could look like a fault
 *   author    on the author's own page
 *   reader    on the public page
 *   brief     in a notification, where space is short
 */
function paper_scope_notice(array $info, string $audience): string {
    if (!paper_scope_is_partial($info)) { return ''; }

    $only  = $info['kind'] === 'abstract';
    $given = paper_scope_list($info['provided']);
    $left  = paper_scope_list($info['omitted']);
    // "3 of 6" means nothing once the author has sections the six do not include.
    $count = $info['kind'] === 'custom'
        ? (string)count($info['provided'])
        : count($info['keys']) . ' of ' . (count($info['keys']) + count($info['omitted']));

    switch ($audience) {
        case 'reviewer':
            return ($only
                    ? 'The author chose to submit only the Abstract as written text.'
                    : 'The author chose to submit ' . $count . ' written sections: ' . $given . '.')
                 . ' The others (' . $left . ') were left out on purpose, so their absence is not an omission,'
                 . " and the paper's page will show only what was provided.";
        case 'author':
            return ($only
                    ? 'You chose to submit the Abstract only.'
                    : 'You chose to submit these sections: ' . $given . '.')
                 . ' The others (' . $left . ") are not shown on this paper's page.";
        case 'reader':
            return $only
                ? 'The author chose to publish only the Abstract of this paper.'
                : 'The author chose to publish only these sections of this paper: ' . $given . '.';
        default: // brief
            return $only
                ? 'The author chose to submit the Abstract only.'
                : 'The author chose to submit ' . $count . ' sections: ' . $given . '.';
    }
}

/** paper_scope_info() for a paper by id, for the places that hold only the id. */
function paper_scope_info_for(int $paperId): array {
    $q = db()->prepare("SELECT imrad_content FROM research_papers WHERE paper_id = ?");
    $q->bind_param('i', $paperId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return paper_scope_info($row['imrad_content'] ?? null);
}

/**
 * An APA 7th-edition reference for a paper, built from what was filed.
 *
 * The archive is the paper's only publisher, so this always cites it as an
 * institutional record rather than guessing at a journal entry, even a paper
 * whose own "Paper Status" field says Published was never issued a DOI or a
 * volume/issue here, so there is nothing to cite it as except the archive.
 *
 * Names are stored "Given Middle. Surname" (Filipino order), one string per
 * author separated by a comma, semicolon or "and"/"&": the same split
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
 * paper (a reminder, say) so a click is never a dead end.
 */
/**
 * Split a notification into the line the list shows and the detail behind it.
 *
 * An account notice carries its detail from the second line on (which can hold
 * a freshly issued password) so the list shows the first line only and the
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
       the reader's own submission: the student case below sends to
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
 * Used wherever one page links to "your dashboard": the public repository's
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
 * confirm "Chapter 4" is asking about something that does not exist, and the
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

/* =========================================================================
   The review checklist

   What the Research Adviser confirms while reading a paper. It used to be eleven
   fixed yes/no columns, one run for IMRaD and one for chapters, picked by the
   file format alone. A feasibility study has neither, and the reviewer could not
   change a line, so the list is now chosen from a few ready-made ones and may be
   edited for the one paper it is recorded on.

   What is saved is the list as the adviser left it, labels included, in
   paper_checklist.items_json. A later change to a ready-made list below must not
   rewrite what a student was shown, so nothing is looked up by key afterwards.
   A row without items_json was written by the old form and is read from the old
   columns (paper_checklist_load).
   ========================================================================= */

const PAPER_CHECKLIST_MAX_ITEMS = 20;
const PAPER_CHECKLIST_MAX_LABEL = 80;

/**
 * The ready-made lists, in the order the dropdown offers them.
 *
 * "Full Manuscript" is not a list any more: its chapters are the Undergraduate
 * Thesis and Capstone Project lists, which are the same six lines under the name
 * of what is being reviewed. The Feasibility Study list is the outline for that
 * paper type. 'blank' starts empty, for a paper nothing else fits.
 */
function paper_checklist_templates(): array
{
    $chapters = ['Chapter 1', 'Chapter 2', 'Chapter 3', 'Chapter 4', 'Chapter 5', 'References'];
    return [
        'imrad' => [
            'label' => 'IMRaD Sections',
            'items' => ['Introduction', 'Methodology', 'Results', 'Discussion', 'References'],
        ],
        'undergrad_thesis' => ['label' => 'Undergraduate Thesis Sections', 'items' => $chapters],
        'capstone'         => ['label' => 'Capstone Project Sections',     'items' => $chapters],
        /* The chapters of the BSIE feasibility studies on file: an executive summary, the five
           aspects, then the references and appendices. Taken from the contents pages of the
           manuscripts (Shampease and VegiMix list exactly these); a study that adds a chapter,
           a conclusion for one, is covered by adding it for that paper. */
        'feasibility' => [
            'label' => 'Feasibility Study Sections',
            'items' => ['Executive Summary', 'Marketing Aspect', 'Technical Aspect', 'Management Aspect',
                        'Financial Aspect', 'Socio-Economic Aspect', 'References', 'Appendices'],
        ],
        'blank' => ['label' => 'Blank list', 'items' => []],
    ];
}

/**
 * The eleven columns the old form wrote, by the family of list they belonged to.
 * 'chapters' is what the Undergraduate Thesis and Capstone Project lists share.
 */
function paper_checklist_legacy_columns(): array
{
    return [
        'imrad' => [
            'imrad_intro' => 'Introduction', 'imrad_method' => 'Methodology', 'imrad_result' => 'Results',
            'imrad_discussion' => 'Discussion', 'imrad_references' => 'References',
        ],
        'chapters' => [
            'full_ch1' => 'Chapter 1', 'full_ch2' => 'Chapter 2', 'full_ch3' => 'Chapter 3',
            'full_ch4' => 'Chapter 4', 'full_ch5' => 'Chapter 5', 'full_references' => 'References',
        ],
    ];
}

/** Which family of old columns a list maps onto, or null for a list they cannot hold. */
function paper_checklist_family(?string $templateKey): ?string
{
    if ($templateKey === 'imrad') return 'imrad';
    if ($templateKey === 'undergrad_thesis' || $templateKey === 'capstone') return 'chapters';
    return null;
}

/**
 * Whether the database has the column the editable checklist is stored in.
 *
 * It was added on 23 October. A copy of the site built from the export staging
 * started from has no such column and no shell to add it with, so every caller
 * asks first (see schema_gaps(), and scripts/migrations/sql/ for the SQL).
 */
function paper_checklist_json_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $r = db()->query("SHOW COLUMNS FROM paper_checklist LIKE 'items_json'");
        $ready = $r && $r->num_rows > 0;
    }
    return $ready;
}

/**
 * The lists this server can actually keep. Without the column only the three
 * the old columns can hold are on offer, and they cannot be edited.
 */
function paper_checklist_templates_offered(): array
{
    $all = paper_checklist_templates();
    if (paper_checklist_json_ready()) return $all;
    return array_intersect_key($all, array_flip(['imrad', 'undergrad_thesis', 'capstone']));
}

/**
 * Which list a reviewer is given to start with. Only a starting point: the
 * dropdown changes it.
 *
 * The file format comes first, because real papers disagree with their type (two
 * of the three undergraduate theses on record were handed in as IMRaD, and an
 * IMRaD paper has no chapters to tick). A Feasibility Study has an outline of its
 * own whatever format it was filed in. A paper with no format recorded, or filed
 * as a full manuscript or both ways, gets the chapters list named for its type.
 */
function paper_checklist_default(?string $manuscriptType, ?string $paperType): string
{
    $type = strtolower(trim((string)$paperType));
    if ($type === 'project') return 'feasibility';
    $formats = paper_declared_formats($manuscriptType);
    if ($formats === ['imrad']) return 'imrad';
    /* Handed in as something the lists do not name (a Policy Brief): no ready-made
       list fits, and the adviser builds one for it from a blank list. */
    if ($formats === ['other']) return 'blank';
    if ($type === 'journal' || $type === 'conference') return 'imrad';
    return $type === 'capstone' ? 'capstone' : 'undergrad_thesis';
}

/**
 * What a chapters group is called on a row the old form wrote. It used to read
 * "Full Manuscript", which is no longer a list.
 */
function paper_checklist_chapters_label(?string $paperType): string
{
    $type = strtolower(trim((string)$paperType));
    if ($type === 'capstone') return 'Capstone Project Sections';
    if (in_array($type, ['undergrad_thesis', 'thesis', 'research', 'article'], true)) return 'Undergraduate Thesis Sections';
    return 'Chapters';
}

/**
 * One section name, made safe to keep and to show: tags and control characters
 * gone, runs of white space made one space, cut to the length a line can hold.
 * Empty when nothing usable is left.
 */
function paper_checklist_label_clean($raw): string
{
    if (!is_scalar($raw)) return '';
    $text = strip_tags((string)$raw);
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim(mb_substr(trim($text), 0, PAPER_CHECKLIST_MAX_LABEL));
}

/**
 * What a reviewer posted, made into what is kept. The page's own checks are a
 * convenience; this is the authority.
 *
 * Returns ['template', 'edited', 'items' => [['label', 'ok'], ...]], or
 * ['legacy' => [column => 0|1, ...]] when the post carries the eleven fixed
 * fields of the old form instead, which are still accepted so that nothing that
 * posts them (an old page, a test) breaks. Throws UserFacingException with a
 * sentence the reviewer can act on.
 */
function paper_checklist_clean(array $post): array
{
    if (!isset($post['ck_label']) || !is_array($post['ck_label'])) {
        $flags = [];
        foreach (paper_checklist_legacy_columns() as $columns) {
            foreach (array_keys($columns) as $col) $flags[$col] = isset($post[$col]) ? 1 : 0;
        }
        return ['legacy' => $flags];
    }

    $templates = paper_checklist_templates();
    $template  = (string)($post['ck_template'] ?? 'blank');
    if (!isset($templates[$template])) $template = 'blank';

    $tooLong = 'That checklist is too long. It can hold up to ' . PAPER_CHECKLIST_MAX_ITEMS . ' sections.';
    // Refused before it is read, so a hand-made post of thousands of fields costs nothing.
    if (count($post['ck_label']) > PAPER_CHECKLIST_MAX_ITEMS * 5) throw new UserFacingException($tooLong);

    $ticked = (isset($post['ck_ok']) && is_array($post['ck_ok'])) ? $post['ck_ok'] : [];
    $items  = [];
    $seen   = [];
    foreach ($post['ck_label'] as $n => $raw) {
        $label = paper_checklist_label_clean($raw);
        if ($label === '') continue;
        $key = mb_strtolower($label);
        if (isset($seen[$key])) continue;            // the same section twice is one section
        $seen[$key] = true;
        $items[] = ['label' => $label, 'ok' => !empty($ticked[$n])];
    }
    if (count($items) > PAPER_CHECKLIST_MAX_ITEMS) throw new UserFacingException($tooLong);
    if (!$items) throw new UserFacingException('Keep at least one section on the checklist, or choose a list.');

    $edited = $template === 'blank' || array_column($items, 'label') !== $templates[$template]['items'];
    return ['template' => $template, 'edited' => $edited, 'items' => $items];
}

/**
 * The old columns a list can be written to, for a server that has no items_json.
 * Only a ready-made list, unedited, fits them; anything else is refused in words
 * rather than saved wrongly.
 */
function paper_checklist_flags_from_list(array $clean): array
{
    $family  = paper_checklist_family((string)($clean['template'] ?? ''));
    $columns = $family !== null ? paper_checklist_legacy_columns()[$family] : null;
    $items   = $clean['items'] ?? [];
    if ($columns === null || !empty($clean['edited']) || count($items) !== count($columns)) {
        throw new UserFacingException(
            'Custom checklists need a database update that has not been installed on this server yet. '
          . 'Choose the IMRaD, Undergraduate Thesis or Capstone Project list as it stands.');
    }
    $flags = [];
    foreach (paper_checklist_legacy_columns() as $cols) {
        foreach (array_keys($cols) as $col) $flags[$col] = 0;
    }
    $i = 0;
    foreach (array_keys($columns) as $col) {
        $flags[$col] = !empty($items[$i++]['ok']) ? 1 : 0;
    }
    return $flags;
}

/**
 * Records a paper's checklist, replacing the one it had.
 *
 * An upsert: a paper the Coordinator returned, the student resubmitted and the
 * adviser approves again already has a row, and the plain INSERT this replaced
 * hit the unique key. Silently here (the old ticks stayed), and as an exception
 * on PHP 8.1 and later, which ended in the error page with the paper unforwarded.
 */
function paper_checklist_save(int $paperId, array $clean): void
{
    $conn = db();
    $legacyCols = [];
    foreach (paper_checklist_legacy_columns() as $cols) {
        foreach (array_keys($cols) as $col) $legacyCols[] = $col;
    }

    if (isset($clean['items']) && paper_checklist_json_ready()) {
        $json = json_encode([
            'v'        => 1,
            'template' => $clean['template'],
            'edited'   => (bool)$clean['edited'],
            'items'    => array_map(function ($it) { return ['label' => $it['label'], 'ok' => (bool)$it['ok']]; }, $clean['items']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // The old columns are cleared, so a stale tick from an earlier round cannot sit beside the new list.
        $resets = implode(', ', array_map(function ($c) { return "$c = 0"; }, $legacyCols));
        $st = $conn->prepare(
            "INSERT INTO paper_checklist (paper_id, items_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE items_json = VALUES(items_json), $resets, created_at = CURRENT_TIMESTAMP");
        $st->bind_param('is', $paperId, $json);
        $st->execute();
        $st->close();
        return;
    }

    $flags = isset($clean['legacy']) ? $clean['legacy'] : paper_checklist_flags_from_list($clean);
    $names = array_keys($flags);
    $update = implode(', ', array_map(function ($c) { return "$c = VALUES($c)"; }, $names));
    $clearJson = paper_checklist_json_ready() ? ', items_json = NULL' : '';
    $st = $conn->prepare(
        "INSERT INTO paper_checklist (paper_id, " . implode(', ', $names) . ")
         VALUES (?, " . implode(', ', array_fill(0, count($names), '?')) . ")
         ON DUPLICATE KEY UPDATE $update$clearJson, created_at = CURRENT_TIMESTAMP");
    $values = array_values($flags);
    $st->bind_param(str_repeat('i', count($values) + 1), $paperId, ...$values);
    $st->execute();
    $st->close();
}

/**
 * A paper's recorded checklist in one shape, whichever way it was written, or null
 * when none was recorded.
 *
 *   groups       [['key', 'label', 'items' => [['label', 'ok'], ...]], ...]
 *   edited       the adviser changed the ready-made list for this paper
 *   legacy       true for a row the old form wrote
 *   template     the list's key (null for a legacy row)
 *   recorded_at  when the paper was approved
 *
 * A legacy row shows the groups the paper's format asked about, as it always did
 * (paper_checklist_groups), the chapters group under the name of the paper's type.
 */
function paper_checklist_load(int $paperId, ?string $manuscriptType, ?string $paperType): ?array
{
    $st = db()->prepare("SELECT * FROM paper_checklist WHERE paper_id = ? ORDER BY checklist_id DESC LIMIT 1");
    $st->bind_param('i', $paperId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return null;

    $templates = paper_checklist_templates();
    $when = $row['created_at'] ?? null;

    if (!empty($row['items_json'])) {
        $d = json_decode((string)$row['items_json'], true);
        if (is_array($d) && !empty($d['items']) && is_array($d['items'])) {
            $items = [];
            foreach (array_slice($d['items'], 0, PAPER_CHECKLIST_MAX_ITEMS) as $it) {
                $label = is_array($it) ? paper_checklist_label_clean($it['label'] ?? '') : '';
                if ($label !== '') $items[] = ['label' => $label, 'ok' => !empty($it['ok'])];
            }
            if ($items) {
                $key = (string)($d['template'] ?? 'blank');
                if (!isset($templates[$key])) $key = 'blank';
                return [
                    'groups'      => [['key' => $key, 'label' => $key === 'blank' ? 'Checklist' : $templates[$key]['label'], 'items' => $items]],
                    'edited'      => !empty($d['edited']),
                    'legacy'      => false,
                    'template'    => $key,
                    'recorded_at' => $when,
                ];
            }
        }
    }

    $shown   = paper_checklist_groups($manuscriptType);
    $columns = paper_checklist_legacy_columns();
    $groups  = [];
    if ($shown['full']) {
        $groups[] = ['key' => 'chapters', 'label' => paper_checklist_chapters_label($paperType),
                     'items' => paper_checklist_legacy_items($row, $columns['chapters'])];
    }
    if ($shown['imrad']) {
        $groups[] = ['key' => 'imrad', 'label' => $templates['imrad']['label'],
                     'items' => paper_checklist_legacy_items($row, $columns['imrad'])];
    }
    return ['groups' => $groups, 'edited' => false, 'legacy' => true, 'template' => null, 'recorded_at' => $when];
}

/** A row of the old columns as a list of items, in the order of the map. */
function paper_checklist_legacy_items(array $row, array $columnMap): array
{
    $items = [];
    foreach ($columnMap as $col => $label) $items[] = ['label' => $label, 'ok' => !empty($row[$col])];
    return $items;
}

/**
 * Where the editor starts for a paper: the list as it was last recorded when there
 * is one, otherwise the default list with nothing ticked. An old row cannot hold a
 * list of its own, so its ticks are carried onto the default list by name.
 */
function paper_checklist_editor_state(?array $loaded, string $default): array
{
    if ($loaded && !$loaded['legacy'] && !empty($loaded['groups'][0]['items'])) {
        return ['template' => $loaded['template'], 'items' => $loaded['groups'][0]['items']];
    }
    $templates = paper_checklist_templates();
    if (!isset($templates[$default])) $default = 'imrad';

    $before = [];
    if ($loaded) {
        $family = paper_checklist_family($default);
        foreach ($loaded['groups'] as $g) {
            if ($g['key'] !== $family) continue;
            foreach ($g['items'] as $it) $before[$it['label']] = $it['ok'];
        }
    }
    $items = [];
    foreach ($templates[$default]['items'] as $label) {
        $items[] = ['label' => $label, 'ok' => !empty($before[$label])];
    }
    return ['template' => $default, 'items' => $items];
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
 * Uploads used to be recorded as a fully qualified URL (BASE_URL with the
 * relative path glued on) so a row carried the address of the machine it was
 * uploaded from. Two things went wrong with that. Anything that needed the file
 * rather than a link built a nonsense path
 * ("…/archive/../http://localhost/capstone/…") and simply failed: the download
 * button answered 404 and the AI text extraction silently found nothing. And
 * moving the project to another computer, another port, or serving it over the
 * LAN left every one of those rows pointing at somewhere that no longer exists.
 *
 * New rows store the relative path. This accepts either form, because the old
 * rows are still out there, and returns null rather than a path that escapes
 * the upload folder: a stored path is data, and data used to build a
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
        // An absolute URL from somewhere else entirely, not ours to open.
        return null;
    } else {
        $relative = $stored;
    }
    $relative = ltrim($relative, '/');
    if ($relative === '') return null;

    $base = realpath(__DIR__ . '/../app/student/uploads');
    $full = realpath(__DIR__ . '/../app/student/' . $relative);
    if ($base === false || $full === false) return null;

    // Must resolve inside uploads/: this is what stops "../" walking out.
    if (strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) return null;

    return is_file($full) ? $full : null;
}

/**
 * What a supporting document is called on screen.
 *
 * 'other' is the optional document a student names themselves, and the name
 * they typed is kept in document_name, so that is what it is called. Without a
 * name (a row repaired by run_supporting_doc_type_repair.php could not recover
 * one) it is simply "Other Document".
 *
 * An empty type is only ever met on a server the repair migration has not
 * reached. Before document_type could hold more than four values, the
 * copyright document was stored as an empty string (an ENUM turns a value it
 * does not list into one), so an empty type is read as the copyright document
 * for as long as such a row can still be there.
 */
function supporting_doc_label(?string $type, ?string $name = null): string {
    $key = strtolower(trim((string)$type));
    if ($key === 'other') {
        $name = trim((string)$name);
        return $name !== '' ? $name : 'Other Document';
    }
    $labels = [
        'ethics_clearance' => 'Ethical Clearance',
        'consent_form'     => 'Consent Form',
        'data_collection'  => 'Data Collection Tool',
        'copyright_doc'    => 'Copyright / IP Document',
        ''                 => 'Copyright / IP Document',
    ];
    return $labels[$key] ?? ucwords(str_replace('_', ' ', (string)$type));
}

/**
 * Whether supporting_documents has its document_name column.
 *
 * It was added by run_supporting_doc_type_repair.php on 21 October, and a copy of
 * the site only has it if someone ran that. Staging was set up a week earlier, has
 * no shell to run the script from and does not receive scripts/ either, so for it
 * every page that names the column in a query failed with "Unknown column", and the
 * adviser's desk, which does so as soon as a paper is waiting on it, ended in a 500.
 * The queries ask this first, so a server that is behind shows documents under
 * their type's name rather than not at all. scripts/migrations/sql/ holds the same
 * change as SQL, for phpMyAdmin.
 */
function supporting_documents_have_name(): bool
{
    static $has = null;
    if ($has === null) {
        $r = db()->query("SHOW COLUMNS FROM supporting_documents LIKE 'document_name'");
        $has = $r && $r->num_rows > 0;
    }
    return $has;
}

/** The document_name entry of a select list: the column, or NULL standing in for it where there is none. */
function supporting_doc_name_sql(): string
{
    return supporting_documents_have_name() ? 'document_name' : 'NULL AS document_name';
}

/**
 * The type to write for a supporting document.
 *
 * On a server the repair migration has not reached, document_type is still an ENUM
 * of the original four, and a value outside it is stored as an empty string on a
 * lenient server and refused on a strict one (the diagnostics page says which this
 * is): either way the copyright document was lost, or the submission failed at its
 * very last step. Such a document is filed as "other" instead, which keeps the file
 * and the submission, and the catch-up (the migration script or the SQL file) reads
 * the real type back out of the file's name when it is eventually run.
 */
function supporting_doc_type_for_storage(string $type): string
{
    static $allowed = false;      // false: not read yet. null: not an ENUM, so anything fits.
    if ($allowed === false) {
        $allowed = null;
        $r   = db()->query("SHOW COLUMNS FROM supporting_documents LIKE 'document_type'");
        $col = $r ? $r->fetch_assoc() : null;
        if ($col && stripos((string)$col['Type'], 'enum(') === 0
                 && preg_match_all("/'([^']*)'/", (string)$col['Type'], $m)) {
            $allowed = $m[1];
        }
    }
    if ($allowed === null || in_array($type, $allowed, true)) return $type;

    error_log("supporting_documents.document_type cannot hold '$type' on this server (run_supporting_doc_type_repair.php has not been applied); filed as 'other'.");
    return in_array('other', $allowed, true) ? 'other' : (string)$allowed[0];
}

/**
 * What this copy of the database is missing that the code expects, with the SQL
 * that supplies it.
 *
 * Schema changes reach other copies of the site as a script in scripts/migrations/
 * or a .sql export, and a copy that has no shell cannot run the first (scripts/ is
 * deliberately not deployed: several of the older ones have no guard and would be
 * reachable by anyone). So the check is in the application, where a person with no
 * shell can read it, and the statements are given here ready to paste into
 * phpMyAdmin. The same statements are in scripts/migrations/sql/.
 *
 * Each entry: key, what (the gap), effect (what a server in this state does), sql.
 * Empty when nothing is missing. Only the schema changes since 14 October are
 * listed: anything earlier is in the export staging was built from.
 */
function schema_gaps(): array
{
    $gaps = [];

    $type = db()->query("SHOW COLUMNS FROM supporting_documents LIKE 'document_type'");
    $col  = $type ? $type->fetch_assoc() : null;
    if ($col && stripos((string)$col['Type'], 'enum(') === 0) {
        $gaps[] = [
            'key'    => 'document_type',
            'what'   => 'supporting_documents.document_type is still an ENUM of four values (' . $col['Type'] . ')',
            'effect' => 'The copyright document cannot be stored under its own name, and a paper that carries one '
                      . 'is filed with it as "Other Document".',
            'sql'    => "ALTER TABLE supporting_documents\n  MODIFY document_type VARCHAR(50) NOT NULL DEFAULT 'other';",
        ];
    }

    if (!supporting_documents_have_name()) {
        $gaps[] = [
            'key'    => 'document_name',
            'what'   => 'supporting_documents.document_name does not exist',
            'effect' => 'A document a student names themselves loses its name and shows as "Other Document".',
            'sql'    => "ALTER TABLE supporting_documents\n  ADD COLUMN document_name VARCHAR(255) NULL AFTER document_type;",
        ];
    }

    if (!paper_manuscripts_ready()) {
        $gaps[] = [
            'key'    => 'paper_manuscripts',
            'what'   => 'The table paper_manuscripts does not exist',
            'effect' => 'A paper cannot be handed in as both IMRAD and Full Manuscript; each keeps its one file in its own columns.',
            'sql'    => "CREATE TABLE IF NOT EXISTS paper_manuscripts (\n"
                      . "  manuscript_id  INT(11)      NOT NULL AUTO_INCREMENT,\n"
                      . "  paper_id       INT(11)      NOT NULL,\n"
                      . "  format         VARCHAR(20)  NOT NULL,\n"
                      . "  file_path      VARCHAR(500) NOT NULL DEFAULT '',\n"
                      . "  gdrive_file_id VARCHAR(255) DEFAULT NULL,\n"
                      . "  file_size      BIGINT(20)   DEFAULT NULL,\n"
                      . "  uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
                      . "  PRIMARY KEY (manuscript_id),\n"
                      . "  UNIQUE KEY uq_paper_format (paper_id, format)\n"
                      . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
        ];
    }

    if (!manuscript_requests_ready()) {
        $gaps[] = [
            'key'    => 'manuscript_requests',
            'what'   => 'The table manuscript_requests does not exist',
            'effect' => 'Students cannot ask a Librarian for a manuscript, and the Librarian\'s Manuscript Requests page is empty. '
                      . 'Before this was tolerated, every student who opened a paper in the public repository got the error page.',
            'sql'    => "CREATE TABLE IF NOT EXISTS manuscript_requests (\n"
                      . "  request_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                      . "  paper_id        INT(11)      NOT NULL,\n"
                      . "  student_user_id INT(11)      NOT NULL,\n"
                      . "  status          ENUM('pending','granted','denied') NOT NULL DEFAULT 'pending',\n"
                      . "  duration_hours  TINYINT UNSIGNED NULL,\n"
                      . "  granted_by      INT(11)      NULL,\n"
                      . "  granted_at      DATETIME     NULL,\n"
                      . "  expires_at      DATETIME     NULL,\n"
                      . "  denied_by       INT(11)      NULL,\n"
                      . "  denied_at       DATETIME     NULL,\n"
                      . "  created_at      DATETIME     NOT NULL,\n"
                      . "  PRIMARY KEY (request_id),\n"
                      . "  KEY idx_student (student_user_id),\n"
                      . "  KEY idx_paper (paper_id),\n"
                      . "  KEY idx_status_expires (status, expires_at),\n"
                      . "  KEY idx_created (created_at)\n"
                      . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        ];
    }

    $nt  = db()->query("SHOW COLUMNS FROM notifications LIKE 'notification_type'");
    $ntc = $nt ? $nt->fetch_assoc() : null;
    if ($ntc && strpos((string)$ntc['Type'], "'manuscript'") === false) {
        $gaps[] = [
            'key'    => 'notification_manuscript',
            'what'   => "notifications.notification_type does not list 'manuscript' (" . $ntc['Type'] . ')',
            'effect' => 'A notice about a manuscript request is stored with no kind (an ENUM throws an unknown value away), '
                      . 'or refused outright where the database runs in strict mode.',
            'sql'    => "ALTER TABLE notifications MODIFY notification_type\n"
                      . "  ENUM('submission','approval','decline','reminder','comment','security','support','account','manuscript') NOT NULL;",
        ];
    }

    if (!paper_checklist_json_ready()) {
        $gaps[] = [
            'key'    => 'checklist_items',
            'what'   => 'paper_checklist.items_json does not exist',
            'effect' => "The adviser's checklist is limited to the IMRaD, Undergraduate Thesis and Capstone Project lists as they stand: "
                      . 'no Feasibility Study list, and no section can be added, removed or renamed.',
            'sql'    => "ALTER TABLE paper_checklist\n  ADD COLUMN items_json TEXT NULL AFTER full_references;",
        ];
    }

    return $gaps;
}

/**
 * Answers a POST whose body PHP discarded for exceeding post_max_size.
 *
 * Only fires when the body really was dropped: a form content type with
 * nothing parsed out of it and a Content-Length that says there was
 * something to parse. A JSON body (the assistant posts one) leaves $_POST
 * empty too and is deliberately not matched.
 */
function guard_discarded_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    if (!empty($_POST) || !empty($_FILES)) { return; }

    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) { return; }

    $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $isForm = strpos($ctype, 'multipart/form-data') !== false
           || strpos($ctype, 'application/x-www-form-urlencoded') !== false;
    if (!$isForm) { return; }

    /* PHP's own warning is sitting in the output buffer at this point and
       would prefix whatever we send, which is the very thing that stops the
       browser reading the answer. Headers have not gone out yet (Apache
       buffers), so the response can still be made clean. */
    while (ob_get_level() > 0) { ob_end_clean(); }

    $limit = function_exists('max_upload_label') ? max_upload_label() : ini_get('upload_max_filesize');
    $message = 'That file is larger than this server accepts (limit ' . $limit . '). '
             . 'Save the PDF at a smaller size, or ask for the server limit to be raised, and try again.';

    /* Sec-Fetch-Dest is the browser's own word for what asked, and unlike
       $_POST it survives a discarded body. require_role() reads the matching
       Sec-Fetch-Mode for the same reason. */
    $background = ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty'
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

    http_response_code(413);
    if ($background) {
        header('Content-Type: application/json');
        exit(json_encode(['success' => false, 'message' => $message, 'error' => 'post_too_large']));
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}
