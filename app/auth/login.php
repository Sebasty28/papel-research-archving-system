<?php
require_once '../../config/core.php';
start_session_once();
$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  /* Send a stale sign-in back to the login modal rather than a bare 419.
     Signing out clears the token, so a login page left open beforehand — or
     reached with the Back button afterwards — carries one that no longer
     matches. That is a expired form, not an attack, and the person simply
     needs to sign in again on a page that has a current token. */
  $role_for_redirect = in_array($_POST['selected_role'] ?? '', ['student', 'faculty', 'guest'], true)
      ? $_POST['selected_role'] : 'student';
  csrf_verify(BASE_URL . '/archive/index.php?login_modal=1&role=' . $role_for_redirect);

  $action = $_POST['action'] ?? 'login';

  if ($action === 'login') {
      $id = trim($_POST['identifier'] ?? '');
      $pw = $_POST['password'] ?? '';
      $role_hint = in_array($_POST['selected_role'] ?? '', ['student','faculty','guest']) ? $_POST['selected_role'] : 'student';

      $error_redirect = BASE_URL.'/archive/index.php?login_modal=1&role='.$role_hint;
      /* An ID and a password. The birthdate used to be a third factor here,
         which meant an account with none on file could not sign in at all — and
         a date of birth is not a secret, so it was never adding much. */
      if ($id === '' || $pw === '') { flash('error','Enter your ID and password.'); header('Location: '.$error_redirect); exit; }

      /* Whatever ID this person was given, it signs them in.
         Students carry theirs in student_id and staff in faculty_id — which
         column depends on who created the account — so both are matched here.
         Without faculty_id, a newly created adviser or coordinator could only
         get in with the username derived from their Employee ID, which nobody
         ever tells them. Email and username still work as before. */
      $sql = "SELECT user_id, username, email, password, full_name, user_role, is_active, student_id, birthdate, expires_on, admin_level
              FROM users
              WHERE (username=? OR email=?
                     OR (student_id IS NOT NULL AND student_id<>'' AND student_id=?)
                     OR (faculty_id IS NOT NULL AND faculty_id<>'' AND faculty_id=?))
                AND is_active=1 LIMIT 1";
      
      try {
        $stmt = $conn->prepare($sql);
      } catch (mysqli_sql_exception $e) {
        if (strpos($e->getMessage(), "Unknown column 'student_id'") !== false) {
          error_log('Database migration required: student_id column is missing from users table. Run scripts/migrations/ to fix.');
          flash('error', 'A system update is required. Please contact your administrator.');
          header('Location: '.BASE_URL.'/archive/index.php?login_modal=1'); exit;
        }
        throw $e;
      }
      $stmt->bind_param('ssss', $id, $id, $id, $id);
      $stmt->execute();
      $stmt->store_result();
      if ($stmt->num_rows !== 1) { flash('error','That ID or password is not right.'); header('Location: '.$error_redirect); exit; }
      $stmt->bind_result($user_id, $username, $email, $password_hash, $full_name, $user_role, $is_active, $student_id, $stored_birthdate, $expires_on, $admin_level);
      $stmt->fetch();
      if (!$is_active || !password_verify($pw, $password_hash)) { flash('error','That ID or password is not right.'); header('Location: '.$error_redirect); exit; }

      // Enforce that the user's actual role matches the tab they logged in from
      $role_groups = [
          'student' => ['student'],
          'faculty' => ['faculty', 'admin', 'head_academic', 'super_admin', 'librarian'],
          'guest'   => ['guest'],
      ];
      if (!in_array($user_role, $role_groups[$role_hint] ?? [])) {
          flash('error', 'This account does not belong to the selected login section. Please choose the correct tab.');
          header('Location: '.$error_redirect);
          exit;
      }

      /* A student account is given a life when it is created — five academic
         years for a first year, down to two for a fourth year or a ladderized
         intake. Past that date it stops working, and the way back is for their
         adviser to move them on, which recalculates the date. The password was
         correct, so say plainly what is wrong rather than "not right". */
      if ($user_role === 'student' && !empty($expires_on) && strtotime($expires_on) < strtotime('today')) {
          flash('error', 'This student account expired on ' . date('F j, Y', strtotime($expires_on)) .
                         '. Ask your research adviser to renew it.');
          header('Location: '.$error_redirect); exit;
      }

      // Additional validation for students: must have student_id or username
      if ($user_role === 'student') {
        if (empty($student_id) && empty($username)) {
          flash('error','Student account is missing required login credentials. Please contact administrator.');
          header('Location: '.$error_redirect); exit;
        }
      }

      /*
       * OTP TEMPORARILY DISABLED — uncomment the block below to re-enable email OTP verification.
       *
       * $otp = sprintf("%06d", mt_rand(1, 999999));
       * $_SESSION['pending_login'] = [
       *     'user_id'     => $user_id,
       *     'username'    => $username,
       *     'email'       => $email,
       *     'full_name'   => $full_name,
       *     'user_role'   => $user_role,
       *     'otp'         => $otp,
       *     'otp_expires' => time() + 300,
       *     'redirect'    => $_GET['redirect'] ?? null
       * ];
       * $emailBody = "Hello {$full_name},\n\nYour One-Time Password (OTP) for login is: {$otp}\n\nThis OTP will expire in 5 minutes. Do not share this with anyone.";
       * try {
       *     send_email($email, "Your Login OTP", $emailBody);
       *     $masked_email = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $email);
       *     flash('success', "An OTP has been sent to your email: {$masked_email}.");
       * } catch (Exception $e) {
       *     flash('error', "Failed to send OTP email. Please try again or contact support.");
       *     unset($_SESSION['pending_login']);
       *     header('Location: '.$error_redirect);
       *     exit;
       * }
       * header('Location: login.php?step=otp');
       * exit;
       */

      // Direct login (OTP bypassed)
      $stmt2 = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
      $stmt2->bind_param('i', $user_id);
      $stmt2->execute();

      /* admin_level rides along with the rest.
         Without it every "$u['admin_level'] ?? 1" on the site read 1, so a Head
         of Academic Programs got the Research Coordinator's navbar and title —
         the dashboards only avoided it by asking the database again themselves. */
      login_user([
          'user_id'     => $user_id,
          'username'    => $username,
          'email'       => $email,
          'full_name'   => $full_name,
          'user_role'   => $user_role,
          'admin_level' => (int)$admin_level,
      ]);

      $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? null;
      if ($redirect === 'archive') {
          header('Location: '.BASE_URL.'/archive/index.php');
      } else {
          header('Location: '.role_home($user_role));
      }
      exit;

  } elseif ($action === 'verify_otp') {
      /* OTP step is currently disabled — this block is kept for when OTP is re-enabled */
      $entered_otp = trim($_POST['otp'] ?? '');

      if (!isset($_SESSION['pending_login'])) {
          flash('error', 'Session expired. Please login again.');
          header('Location: '.BASE_URL.'/archive/index.php?login_modal=1');
          exit;
      }

      $pending = $_SESSION['pending_login'];

      if (time() > $pending['otp_expires']) {
          unset($_SESSION['pending_login']);
          flash('error', 'OTP has expired. Please login again.');
          header('Location: '.BASE_URL.'/archive/index.php?login_modal=1');
          exit;
      }

      if ($entered_otp !== $pending['otp']) {
          flash('error', 'Invalid OTP. Please try again.');
          header('Location: '.BASE_URL.'/archive/index.php?login_modal=1');
          exit;
      }
      
      // OTP matches! Complete login
      $user_id = $pending['user_id'];
      $stmt2 = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
      $stmt2->bind_param('i', $user_id);
      $stmt2->execute();

      login_user([
          'user_id' => $pending['user_id'],
          'username' => $pending['username'],
          'email' => $pending['email'],
          'full_name' => $pending['full_name'],
          'user_role' => $pending['user_role']
      ]);
      
      $redirect = $pending['redirect'];
      unset($_SESSION['pending_login']);
      
      if($redirect === 'archive') {
        header('Location: '.BASE_URL.'/archive/index.php');
      } else {
        header('Location: '.role_home($pending['user_role']));
      }
      exit;
  }
}

// GET requests — the login UI is now the modal on the public archive page
header('Location: ' . BASE_URL . '/archive/index.php');
exit;

/* -----------------------------------------------------------------------
 * DEAD CODE BELOW — kept for reference only; never reached on GET requests.
 * The standalone login page was replaced by a slide-in modal on archive/index.php.
 * To restore it: remove the redirect above and uncomment this block.
 * ----------------------------------------------------------------------- */
if (false):
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · PAPEL - PUP Biñan Digital Research Repository</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
  :root {
    --pup-maroon-900: #810403;
    --pup-gold-600: #dca92c;
    --pup-gold-400: #fede0e;
    --pup-ivory-50: #fcf8f7;
    --pup-rose-300: #be7d7c;

    --glass-bg: rgba(255, 255, 255, 0.95);
    --glass-border: rgba(190, 125, 124, 0.3);
    --shadow-sm: 0 2px 8px rgba(129, 4, 3, 0.08);
    --shadow-md: 0 4px 16px rgba(129, 4, 3, 0.12);
    --shadow-lg: 0 8px 32px rgba(129, 4, 3, 0.16);
    --shadow-xl: 0 12px 48px rgba(129, 4, 3, 0.2);
  }

  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    height: 100vh;
    overflow: hidden;
    background: var(--pup-ivory-50);
    position: relative;
  }

  /* Animated Background with mesh gradient effect */
  body::before {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    background: url('../../assests/images/loginbackground.png') no-repeat center center/cover;
    opacity: 0.05;
    z-index: 0;
  }

  body::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    background: 
      radial-gradient(circle at 10% 20%, rgba(129, 4, 3, 0.1) 0%, transparent 35%),
      radial-gradient(circle at 90% 20%, rgba(220, 169, 44, 0.15) 0%, transparent 40%),
      radial-gradient(circle at 50% 80%, rgba(190, 125, 124, 0.1) 0%, transparent 45%),
      radial-gradient(circle at 20% 70%, rgba(252, 248, 247, 0.5) 0%, transparent 35%);
    z-index: 0;
    animation: gradientShift 15s ease infinite;
  }

  @keyframes gradientShift {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
  }

  .login-container {
    display: grid;
    grid-template-columns: 70fr 30fr;
    height: 100vh;
    width: 100%;
    position: relative;
    z-index: 1;
    gap: 0;
  }

  /* Left Side - Branding */
  .login-left {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 3rem;
    background: linear-gradient(135deg, var(--pup-maroon-900) 0%, #600302 100%);
    backdrop-filter: blur(40px) saturate(180%);
    position: relative;
    border-right: 1px solid var(--pup-rose-300);
    box-shadow: inset 0 0 100px rgba(15, 23, 42, 0.3);
  }

  .login-left::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url('../../assests/images/loginbackground.png') no-repeat center center/cover;
    opacity: 0.03;
    z-index: 0;
  }

  .branding-content {
    position: relative;
    z-index: 1;
    max-width: 500px;
    text-align: center;
  }

  .branding-icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, var(--pup-gold-400), var(--pup-gold-600));
    border-radius: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 4rem;
    color: var(--pup-maroon-900);
    box-shadow: 0 20px 60px rgba(220, 169, 44, 0.4);
    margin: 0 auto 2rem;
    animation: float 3s ease-in-out infinite;
  }

  @keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
  }

  .branding-title {
    font-size: 3.5rem;
    font-weight: 900;
    color: #ffffff;
    margin-bottom: 1rem;
    letter-spacing: -2px;
    text-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
    line-height: 1.1;
  }

  .branding-subtitle {
    font-size: 1.25rem;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 500;
    line-height: 1.8;
    margin-bottom: 2rem;
  }

  .branding-features {
    display: grid;
    gap: 1.5rem;
    margin-top: 3rem;
    text-align: left;
  }

  .feature-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    background: rgba(255, 255, 255, 0.15);
    padding: 1.25rem;
    border-radius: 16px;
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  }

  .feature-item:hover {
    background: rgba(255, 255, 255, 0.22);
    transform: translateX(8px);
    box-shadow: var(--shadow-lg);
  }

  .feature-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--pup-gold-400), var(--pup-gold-600));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .feature-text h3 {
    color: white;
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 0.25rem 0;
  }

  .feature-text p {
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.5;
  }

  /* Right Side - Form */
  .login-right {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 3rem;
    background: transparent;
    backdrop-filter: blur(30px) saturate(150%);
  }

  .login-wrapper {
    width: 100%;
    max-width: 480px;
    position: relative;
    animation: fadeInUp 0.6s ease-out;
  }

  @keyframes fadeInUp {
    from {
      opacity: 0;
      transform: translateY(30px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  /* Form Card */
  .form-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(25px);
    border-radius: 24px;
    box-shadow: 
      0 20px 60px rgba(0, 0, 0, 0.12),
      0 10px 30px rgba(129, 4, 3, 0.08),
      inset 0 1px 0 rgba(255, 255, 255, 0.9);
    padding: 2.5rem;
    border: 1px solid var(--pup-rose-300);
    position: relative;
    overflow: hidden;
  }

  .form-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--pup-maroon-900) 0%, var(--pup-gold-600) 100%);
  }

  .form-header {
    margin-bottom: 2rem;
  }

  .form-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--pup-maroon-900);
    margin-bottom: 0.5rem;
  }

  .form-header p {
    color: #64748b;
    font-size: 0.95rem;
  }

  /* Form Elements */
  .form-group {
    margin-bottom: 1.5rem;
  }

  .form-label {
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
    display: block;
  }

  .input-wrapper {
    position: relative;
  }

  .input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
    z-index: 1;
  }

  .form-control {
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.875rem 1rem;
    padding-left: 3rem;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: #ffffff;
    width: 100%;
  }

  .form-control:focus {
    border-color: var(--pup-maroon-900);
    box-shadow: 0 0 0 4px rgba(129, 4, 3, 0.1);
    outline: none;
    background: #ffffff;
  }

  .form-control:hover:not(:focus) {
    border-color: #cbd5e1;
  }

  .form-control::placeholder {
    color: #94a3b8;
  }

  .password-group {
    position: relative;
  }

  .password-group .form-control {
    padding-right: 3.5rem;
  }

  .password-toggle {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 8px;
    transition: all 0.2s;
    z-index: 2;
  }

  .password-toggle:hover {
    background: #f1f5f9;
    color: var(--pup-maroon-900);
  }

  .password-toggle:active {
    transform: translateY(-50%) scale(0.95);
  }

  /* Buttons */
  .btn-sign-in {
    background: var(--pup-maroon-900);
    color: white;
    border: none;
    border-radius: 12px;
    padding: 1rem 2rem;
    font-weight: 700;
    font-size: 1rem;
    width: 100%;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    margin-top: 0.5rem;
    box-shadow: 0 4px 12px rgba(129, 4, 3, 0.3);
    position: relative;
    overflow: hidden;
  }

  .btn-sign-in::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s;
  }

  .btn-sign-in:hover::before {
    left: 100%;
  }

  .btn-sign-in:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(129, 4, 3, 0.4);
  }

  .btn-sign-in:active {
    transform: translateY(0);
  }

  .btn-public-archive {
    background: var(--pup-gold-600);
    color: var(--pup-maroon-900);
    border: none;
    border-radius: 12px;
    padding: 1rem 2rem;
    font-weight: 700;
    font-size: 1rem;
    width: 100%;
    margin-top: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(220, 169, 44, 0.3);
    position: relative;
    overflow: hidden;
  }

  .btn-public-archive::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.5s;
  }

  .btn-public-archive:hover::before {
    left: 100%;
  }

  .btn-public-archive:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(220, 169, 44, 0.4);
  }

  .btn-public-archive:active {
    transform: translateY(0);
  }

  /* Divider */
  .divider {
    display: flex;
    align-items: center;
    margin: 1.5rem 0 1rem;
    gap: 1rem;
  }

  .divider::before,
  .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
  }

  .divider span {
    color: #94a3b8;
    font-size: 0.875rem;
    font-weight: 600;
    padding: 0 0.5rem;
  }

  /* Alert */
  .alert {
    border-radius: 12px;
    border: none;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    animation: slideDown 0.3s ease-out;
  }

  @keyframes slideDown {
    from {
      opacity: 0;
      transform: translateY(-10px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .alert-danger {
    background: #fee2e2;
    color: #991b1b;
  }

  /* Invalid Feedback */
  .invalid-feedback {
    display: none;
    color: #dc2626;
    font-size: 0.875rem;
    margin-top: 0.5rem;
    font-weight: 500;
  }

  .is-invalid {
    border-color: #dc2626 !important;
    animation: shake 0.3s;
  }

  .is-invalid ~ .invalid-feedback {
    display: block;
  }

  @keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-4px); }
    75% { transform: translateX(4px); }
  }

  /* Footer */
  .form-footer {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e2e8f0;
    text-align: center;
  }

  .form-footer p {
    color: #64748b;
    font-size: 0.8rem;
    line-height: 1.6;
    margin: 0;
  }

  .form-footer a {
    color: var(--pup-maroon-900);
    text-decoration: none;
    font-weight: 600;
    transition: color 0.2s;
  }

  .form-footer a:hover {
    color: var(--pup-gold-600);
    text-decoration: underline;
  }

  /* Loading State */
  .btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
  }

  .btn-loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid transparent;
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  /* Responsive Design */
  @media (max-width: 1024px) {
    .login-container {
      grid-template-columns: 1fr;
    }

    .login-left {
      display: none;
    }

    .login-right {
      padding: 2rem;
    }
  }

  @media (max-width: 576px) {
    .login-right {
      padding: 1.5rem;
    }

    .login-wrapper {
      max-width: 100%;
    }

    .form-card {
      padding: 2rem 1.5rem;
    }
  }

  @media (max-width: 400px) {
    .form-card {
      padding: 1.75rem 1.25rem;
    }
  }

  /* Show simplified logo on mobile */
  @media (max-width: 1024px) {
    .login-right::before {
      content: '';
      position: absolute;
      top: 2rem;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 60px;
      background: linear-gradient(135deg, var(--pup-gold-400), var(--pup-gold-600));
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(220, 169, 44, 0.3);
    }

    .login-right::after {
      content: 'PAPEL';
      position: absolute;
      top: 2rem;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      font-size: 1.5rem;
      color: var(--pup-maroon-900);
      z-index: 1;
    }

    .login-wrapper {
      margin-top: 80px;
    }
  }

  /* Accessibility Focus States */
  .form-control:focus-visible,
  .btn-sign-in:focus-visible,
  .btn-public-archive:focus-visible,
  .password-toggle:focus-visible {
    outline: 2px solid var(--pup-maroon-900);
    outline-offset: 2px;
  }

  /* High Contrast Mode Support */
  @media (prefers-contrast: high) {
    .form-control {
      border-width: 3px;
    }
    
    .btn-sign-in,
    .btn-public-archive {
      border: 2px solid transparent;
    }
  }

  /* Reduced Motion Support */
  @media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
      animation-duration: 0.01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0.01ms !important;
    }
  }
</style>
</head>
<body>

<div class="login-container">
  <!-- Left Side - Branding & Features -->
  <div class="login-left">
    <div class="branding-content">
      <div class="branding-icon">P</div>
      <h1 class="branding-title">PAPEL</h1>
      <p class="branding-subtitle">
        PUP Biñan Digital Research Repository<br>
        Your Gateway to Academic Excellence
      </p>

      <div class="branding-features">
        <div class="feature-item">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 16 16">
              <path d="M1 2.828c.885-.37 2.154-.769 3.388-.893 1.33-.134 2.458.063 3.112.752v9.746c-.935-.53-2.12-.603-3.213-.493-1.18.12-2.37.461-3.287.811V2.828zm7.5-.141c.654-.689 1.782-.886 3.112-.752 1.234.124 2.503.523 3.388.893v9.923c-.918-.35-2.107-.692-3.287-.81-1.094-.111-2.278-.039-3.213.492V2.687zM8 1.783C7.015.936 5.587.81 4.287.94c-1.514.153-3.042.672-3.994 1.105A.5.5 0 0 0 0 2.5v11a.5.5 0 0 0 .707.455c.882-.4 2.303-.881 3.68-1.02 1.409-.142 2.59.087 3.223.877a.5.5 0 0 0 .78 0c.633-.79 1.814-1.019 3.222-.877 1.378.139 2.8.62 3.681 1.02A.5.5 0 0 0 16 13.5v-11a.5.5 0 0 0-.293-.455c-.952-.433-2.48-.952-3.994-1.105C10.413.809 8.985.936 8 1.783z"/>
            </svg>
          </div>
          <div class="feature-text">
            <h3>Access Research</h3>
            <p>Browse thousands of academic papers and thesis documents</p>
          </div>
        </div>

        <div class="feature-item">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 16 16">
              <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/>
            </svg>
          </div>
          <div class="feature-text">
            <h3>Manage Projects</h3>
            <p>Upload and organize your research work efficiently</p>
          </div>
        </div>

        <div class="feature-item">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 16 16">
              <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
              <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
            </svg>
          </div>
          <div class="feature-text">
            <h3>Secure & Reliable</h3>
            <p>Your data is protected with enterprise-grade security</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Side - Login Form -->
  <div class="login-right">
    <div class="login-wrapper">
      <!-- Form Card -->
      <div class="form-card">
      <div class="form-header">
        <?php if ($step === 'otp'): ?>
            <h2>OTP Verification</h2>
            <p>Enter the 6-digit code sent to your email.</p>
        <?php else: ?>
            <h2>Welcome Back</h2>
            <p>Sign in to continue to your account</p>
        <?php endif; ?>
      </div>

      <!-- Alert placeholder for PHP flash messages -->
      <?php if($m=flash('error')): ?>
        <div class="alert alert-danger" role="alert">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" style="vertical-align: text-bottom; margin-right: 0.5rem;" viewBox="0 0 16 16">
            <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
          </svg>
          <?= e($m) ?>
        </div>
      <?php endif; ?>
      <?php if($m=flash('success')): ?>
        <div class="alert alert-success" role="alert" style="background: #d1fae5; color: #065f46; border-radius: 12px; border-top: none; border-right: none; border-bottom: none; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" style="vertical-align: text-bottom; margin-right: 0.5rem;" viewBox="0 0 16 16">
            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
          </svg>
          <?= e($m) ?>
        </div>
      <?php endif; ?>
      
      <form method="post" id="<?= $step === 'otp' ? 'otpForm' : 'loginForm' ?>">
        <!-- CSRF token placeholder -->
        <?= csrf_field(); ?>
        
        <?php if ($step === 'otp'): ?>
            <input type="hidden" name="action" value="verify_otp">
            
            <div class="form-group">
              <label class="form-label" for="otp">One-Time Password</label>
              <div class="input-wrapper">
                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                  <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                  <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3.5a.5.5 0 0 1-.5-.5v-3.5A.5.5 0 0 1 8 4z"/>
                </svg>
                <input type="text" class="form-control" name="otp" id="otp" placeholder="Enter 6-digit OTP" required autocomplete="one-time-code" maxlength="6" pattern="\d{6}" style="letter-spacing: 0.5em; text-align: center; padding-left: 1rem; font-weight: 700; font-size: 1.2rem;">
              </div>
              <div id="otpFeedback" class="invalid-feedback">Please enter a valid 6-digit OTP.</div>
            </div>
            
            <button type="submit" class="btn btn-sign-in" id="verifyBtn">
              <span>VERIFY OTP</span>
            </button>
            <div class="form-footer mt-3" style="border-top: none;">
                <p><a href="login.php?redirect=<?= e($_GET['redirect'] ?? '') ?>" style="color: var(--pup-maroon-900);">← Back to Login</a></p>
            </div>
        <?php else: ?>
            <input type="hidden" name="action" value="login">
            
            <div class="form-group">
              <label class="form-label" for="identifier">University ID</label>
              <div class="input-wrapper">
                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                  <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3Zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>
                </svg>
                <input type="text" class="form-control" name="identifier" id="identifier" placeholder="Enter your University ID" required autocomplete="username">
              </div>
              <div id="idFeedback" class="invalid-feedback">Please enter a valid University ID (alphanumeric, 5-50 characters).</div>
            </div>
    
            <div class="form-group">
              <label class="form-label" for="password">Password</label>
              <div class="password-group">
                <div class="input-wrapper">
                  <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                  </svg>
                  <input type="password" class="form-control" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password">
                  <button class="password-toggle" type="button" id="togglePassword" aria-label="Toggle password visibility">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                      <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                    </svg>
                  </button>
                </div>
              </div>
              <div id="passwordFeedback" class="invalid-feedback">Password is required.</div>
            </div>

            <button type="submit" class="btn btn-sign-in" id="signInBtn">
              <span>SIGN IN</span>
            </button>
    
            <button type="button" class="btn btn-public-archive" id="publicArchiveBtn">
              <span>PUBLIC ARCHIVE</span>
            </button>
        <?php endif; ?>
      </form>

      <div class="form-footer">
        <p>
          By using this service, you understood and agree to the PAPEL<br>
          <a href="../../pages/terms_and_conditions.php">Terms of Use</a> and <a href="../../pages/privacy.php">Privacy Statement</a>.
        </p>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>"  src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
  // Password Toggle
  if (document.getElementById('togglePassword')) {
  const togglePassword = document.getElementById('togglePassword');
  const passwordInput = document.getElementById('password');
  
  togglePassword.addEventListener('click', function() {
    const icon = this.querySelector('svg');
    
    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      icon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
      this.setAttribute('aria-label', 'Hide password');
    } else {
      passwordInput.type = 'password';
      icon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
      this.setAttribute('aria-label', 'Show password');
    }
  });
  }

  // Form Validation
  const loginForm = document.getElementById('loginForm');
  if (loginForm) {
      const idInput = document.getElementById('identifier');
      const passInput = document.getElementById('password');
      const signInBtn = document.getElementById('signInBtn');
    
      loginForm.addEventListener('submit', function(e) {
        let isValid = true;
    
        // Validate University ID (Alphanumeric, dots, underscores, hyphens, 5-50 chars)
        const idPattern = /^[A-Za-z0-9._-]{5,50}$/;
        if (!idPattern.test(idInput.value.trim())) {
          idInput.classList.add('is-invalid');
          isValid = false;
        } else {
          idInput.classList.remove('is-invalid');
        }
    
        // Validate Password (Not empty)
        if (passInput.value.trim() === '') {
          passInput.classList.add('is-invalid');
          isValid = false;
        } else {
          passInput.classList.remove('is-invalid');
        }
    
        if (!isValid) {
          e.preventDefault();
        } else {
          // Add loading state
          signInBtn.classList.add('btn-loading');
          signInBtn.querySelector('span').textContent = 'VERIFYING...';
        }
      });
    
      // Remove invalid class on input
      idInput.addEventListener('input', function() {
        if (this.classList.contains('is-invalid')) {
          this.classList.remove('is-invalid');
        }
      });
    
      passInput.addEventListener('input', function() {
        if (this.classList.contains('is-invalid')) {
          this.classList.remove('is-invalid');
        }
      });
    
      [bMonth, bDay, bYear].forEach(el => {
        el.addEventListener('change', function() {
          this.classList.remove('is-invalid');
          if(bMonth.value && bDay.value && bYear.value) bdFeedback.style.display = 'none';
        });
      });
  }
  
  const otpForm = document.getElementById('otpForm');
  if (otpForm) {
    const otpInput = document.getElementById('otp');
    const verifyBtn = document.getElementById('verifyBtn');
    
    otpForm.addEventListener('submit', function(e) {
        if (otpInput.value.trim().length !== 6 || !/^\d{6}$/.test(otpInput.value.trim())) {
            e.preventDefault();
            otpInput.classList.add('is-invalid');
            document.getElementById('otpFeedback').style.display = 'block';
        } else {
            verifyBtn.classList.add('btn-loading');
            verifyBtn.querySelector('span').textContent = 'VERIFYING...';
        }
    });
    
    otpInput.addEventListener('input', function() {
        // Only allow numbers
        this.value = this.value.replace(/[^\d]/g, '');
        if (this.classList.contains('is-invalid')) {
            this.classList.remove('is-invalid');
            document.getElementById('otpFeedback').style.display = 'none';
        }
    });
  }

  // Auto-focus on first input
  window.addEventListener('load', function() {
    if (document.getElementById('identifier')) {
        document.getElementById('identifier').focus();
    } else if (document.getElementById('otp')) {
        document.getElementById('otp').focus();
    }
  });

  var publicArchiveBtn = document.getElementById('publicArchiveBtn');
  if (publicArchiveBtn) {
    publicArchiveBtn.addEventListener('click', function() {
      window.location.href = '../../archive/index.php';
    });
  }
</script>
<?php include '../../includes/accessibility.php'; ?>
</body>
</html>
<?php endif; ?>