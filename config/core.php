<?php
// core.php (FINAL)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/../app/helpers/UploadHelper.php';

function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
        $csp = "default-src 'self'; " .
               "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://unpkg.com; " .
               "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
               "img-src 'self' data: blob: https://lh3.googleusercontent.com; " .
               "connect-src 'self' https://api.groq.com https://www.googleapis.com; " .
               "frame-src 'self' https://drive.google.com; " .
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
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_errno) {
      $ref = generate_error_ref();
      log_error_detail($ref, 'Database Connection Error', 'Connection failed: ' . $conn->connect_error, __FILE__, __LINE__);
      show_safe_error($ref, 500);

    }
    $conn->set_charset(DB_CHARSET);
  }
  return $conn;
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
  if (isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['_token'])) {
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

function login_user(array $u): void { start_session_once(); session_regenerate_id(true); $_SESSION['user'] = $u; }
function current_user(): ?array { start_session_once(); return $_SESSION['user'] ?? null; }
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
  if($role === 'librarian') return BASE_URL.'/app/guest/admin_manage_guests.php';
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
function email_layout(string $bodyHtml, string $heading = ''): string {
  $appName = defined('APP_NAME') ? APP_NAME : 'PAPEL';
  $year    = date('Y');
  $maroon  = '#7a0d0c';
  $maroonDk= '#560201';
  $gold    = '#dca92c';
  $ink     = '#2f2b2b';   // primary body text
  $muted   = '#6b6361';   // secondary text
  $font    = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

  $headingHtml = $heading !== ''
    ? '<tr><td style="padding:38px 40px 0;font-family:' . $font . ';">
         <h1 style="margin:0;color:' . $maroon . ';font-size:22px;font-weight:700;line-height:1.35;">' . htmlspecialchars($heading) . '</h1>
         <div style="width:46px;height:3px;background-color:' . $gold . ';border-radius:2px;margin-top:12px;font-size:0;line-height:0;">&nbsp;</div>
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
<body style="margin:0;padding:0;background-color:#efe9e7;-webkit-text-size-adjust:100%;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#efe9e7;">
    <tr><td align="center" style="padding:34px 16px;">

      <!-- Card -->
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(86,2,1,.14);">

        <!-- Header (centered, stacked — no overlap) -->
        <tr><td align="center" style="background-color:' . $maroon . ';background-image:linear-gradient(160deg,' . $maroon . ' 0%,' . $maroonDk . ' 100%);padding:34px 30px 30px;" bgcolor="' . $maroon . '">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">
            <div style="font-family:' . $font . ';font-size:30px;font-weight:800;letter-spacing:3px;color:#ffffff;line-height:1;">' . htmlspecialchars($appName) . '</div>
            <div style="margin-top:12px;font-family:' . $font . ';font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:' . $gold . ';line-height:1;">PUP Bi&ntilde;an Research Repository</div>
          </td></tr></table>
        </td></tr>

        ' . $headingHtml . '

        <!-- Content -->
        <tr><td style="padding:' . ($heading !== '' ? '22px' : '38px') . ' 40px 36px;font-family:' . $font . ';font-size:15px;line-height:1.75;color:' . $ink . ';">
          <div style="color:' . $ink . ';">' . $bodyHtml . '</div>
        </td></tr>

        <!-- Divider -->
        <tr><td style="padding:0 40px;"><div style="border-top:1px solid #efe3e2;font-size:0;line-height:0;">&nbsp;</div></td></tr>

        <!-- Footer -->
        <tr><td align="center" style="padding:22px 40px 30px;font-family:' . $font . ';font-size:12px;line-height:1.7;color:' . $muted . ';">
          <div style="font-weight:700;color:' . $maroon . ';font-size:13px;letter-spacing:.5px;margin-bottom:4px;">' . htmlspecialchars($appName) . '</div>
          This is an automated message &mdash; please do not reply to this email.<br>
          &copy; ' . $year . ' Polytechnic University of the Philippines &ndash; Bi&ntilde;an Campus.
        </td></tr>

      </table>
      <!-- /Card -->

    </td></tr>
  </table>
</body>
</html>';
}

function send_email(string $to, string $subject, string $body): void {
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

      $mail->setFrom('pupbinanRepository@gmail.com', APP_NAME);
      $mail->addAddress($to);
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = email_layout(nl2br($body), $subject);
      $mail->AltBody = strip_tags($body);

      $mail->send();
      return; // Sent successfully
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
    $safeHref = function ($href) {
        $h = trim($href);
        if ($h === '') return null;
        // Strip characters browsers ignore when resolving a scheme, so
        // "java\tscript:" cannot smuggle itself past the check.
        $probe = strtolower(preg_replace('/[\s\x00-\x1F]/', '', $h));
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/', $probe, $m)) {
            $scheme = rtrim($m[0], ':');
            if (!in_array($scheme, ['http', 'https', 'mailto'], true)) return null;
        }
        return $h;
    };

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $dropTags, $cellSpanAttrs, $safeHref, $allowedAlign) {
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

            $isCell = ($tag === 'td' || $tag === 'th');
            $isLink = ($tag === 'a');
            $href   = null;

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

                /* Two declarations survive, on the elements that can mean them:
                   how a cell's text is aligned, and how wide a column is, which
                   comes from dragging a table's border. Everything else in a
                   style attribute is dropped — fonts and colours would carry
                   Word's shading into the repository, and url() is an attack
                   surface. */
                if (($isCell || $tag === 'table') && $name === 'style') {
                    $keep = [];
                    foreach (explode(';', (string)$attr->nodeValue) as $decl) {
                        if (strpos($decl, ':') === false) continue;
                        list($prop, $value) = explode(':', $decl, 2);
                        $prop  = trim(strtolower($prop));
                        $value = trim(strtolower($value));

                        // Alignment is a cell's business only — body text is
                        // justified for every paper, by the stylesheet.
                        if ($isCell && $prop === 'text-align' && in_array($value, $allowedAlign, true)) {
                            $keep['text-align'] = 'text-align:' . $value;
                        }
                        // Column widths only, and only where a width means a column.
                        if ($prop === 'width' && ($isCell || $tag === 'table')
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
 * Paper types that must be submitted with ethics clearance, a consent form and
 * a data-collection tool.
 *
 * These are the types that involve human participants and original data
 * gathering. A journal article, conference paper or write-up of existing work
 * may still attach the same documents, but is not blocked without them.
 */
function paper_type_needs_documents(?string $paperType): bool {
    return in_array(strtolower(trim((string)$paperType)), ['research', 'capstone'], true);
}

/** Human-readable name for a paper type, for use in messages. */
function paper_type_label(?string $paperType): string {
    $labels = [
        'research'   => 'Research Paper',
        'capstone'   => 'Capstone',
        'thesis'     => 'Thesis',
        'conference' => 'Conference Paper',
        'journal'    => 'Journal Article',
        'article'    => 'Article',
        'project'    => 'Project',
    ];
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
function notification_link(?int $paperId, string $role): string {
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
