<?php
require_once __DIR__ . '/../config/core.php';
start_session_once();
$conn = db();

// Check if already logged in
$u = current_user();
if ($u) {
  header('Location: index.php');
  exit;
}

// Check if already logged in as guest
if (isset($_SESSION['guest_login']) && $_SESSION['guest_expire'] > time()) {
  header('Location: index.php');
  exit;
}

// Show expired message
$expired = isset($_GET['expired']);

$error = null;

// Handle login (Guest or User)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username && $password) {
    $stmt = $conn->prepare("SELECT guest_id, username, password, expires_at FROM guest_sessions WHERE username = ? AND expires_at> NOW()");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
      if (password_verify($password, $row['password'])) {
        $u = ['user_id' => 0, 'username' => $row['username'], 'email' => '', 'full_name' => 'Guest User', 'user_role' => 'guest'];
        login_user($u);
        $_SESSION['guest_expire'] = strtotime($row['expires_at']);
        $_SESSION['guest_login'] = true;
        header('Location: index.php');
        exit;
      } else {
        $error = "Invalid password.";
      }
    } else {
      /* Not a guest pass, so try it as a staff or student sign-in.
         This used to demand a birthdate as well, and the variable holding it
         went away when the birthdate was dropped from sign-in — leaving a test
         that nothing could pass, so nobody but a guest could get in here.
         An ID and a password now, the same as the main login. */
      $stmt = $conn->prepare(
        "SELECT user_id, username, email, password, full_name, user_role, is_active, expires_on, admin_level
         FROM users
         WHERE (username=? OR email=?
                OR (student_id IS NOT NULL AND student_id<>'' AND student_id=?)
                OR (faculty_id IS NOT NULL AND faculty_id<>'' AND faculty_id=?))
           AND is_active=1 LIMIT 1");
      $stmt->bind_param('ssss', $username, $username, $username, $username);
      $stmt->execute();
      $res = $stmt->get_result();

      $row = $res->fetch_assoc();
      // A student account only lasts as long as their course does.
      if ($row && $row['user_role'] === 'student' && !empty($row['expires_on'])
          && strtotime($row['expires_on']) < strtotime('today')
          && password_verify($password, $row['password'])) {
        $error = 'This student account expired on ' . date('F j, Y', strtotime($row['expires_on'])) .
                 '. Ask your research adviser to renew it.';
        $row = null;
      }

      if ($row && password_verify($password, $row['password'])) {
        login_user([
          'user_id'     => $row['user_id'],
          'username'    => $row['username'],
          'email'       => $row['email'],
          'full_name'   => $row['full_name'],
          'user_role'   => $row['user_role'],
          'admin_level' => (int)($row['admin_level'] ?? 1),
        ]);
        header('Location: index.php');
        exit;
      }
      // Do not paper over a more specific reason set just above.
      if ($error === null) { $error = "That ID or password is not right."; }
    }
  } else {
    $error = "Please enter username and password.";
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Archive Login · <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
    rel="stylesheet">
  <style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    :root {
      --pup-blue: #810403;
      --pup-blue-dark: #600302;
      --pup-blue-darker: #810403;
      --pup-gold: #dca92c;
      --pup-gold-dark: #c59625;
      --glass-bg: rgba(255, 255, 255, 0.95);
      --glass-border: rgba(255, 255, 255, 0.2);
      --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
      --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
      --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.16);
      --shadow-xl: 0 12px 48px rgba(0, 0, 0, 0.2);
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
      background: #fcf8f7;
      position: relative;
    }

    /* Animated Background with mesh gradient effect */
    body::before {
      content: '';
      position: absolute;
      width: 100%;
      height: 100%;
      background: url('../assests/images/loginbackground.png') no-repeat center center/cover;
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

      0%,
      100% {
        opacity: 1;
      }

      50% {
        opacity: 0.8;
      }
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
      background: linear-gradient(135deg, rgba(129, 4, 3, 0.9) 0%, rgba(96, 3, 2, 0.9) 100%);
      backdrop-filter: blur(40px) saturate(180%);
      position: relative;
      border-right: 1px solid #be7d7c;
      box-shadow: inset 0 0 100px rgba(15, 23, 42, 0.3);
    }

    .login-left::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('../assests/images/loginbackground.png') no-repeat center center/cover;
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
      background: linear-gradient(135deg, #fede0e, var(--pup-gold));
      border-radius: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      font-size: 4rem;
      color: var(--pup-blue);
      box-shadow: 0 20px 60px rgba(220, 169, 44, 0.4);
      margin: 0 auto 2rem;
      animation: float 3s ease-in-out infinite;
    }

    @keyframes float {

      0%,
      100% {
        transform: translateY(0px);
      }

      50% {
        transform: translateY(-10px);
      }
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
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    }

    .feature-icon {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #fede0e, var(--pup-gold));
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
      border: 1px solid #be7d7c;
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
      background: linear-gradient(90deg, var(--pup-blue) 0%, var(--pup-gold) 100%);
    }

    .form-header {
      margin-bottom: 2rem;
    }

    .form-header h2 {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--pup-blue-darker);
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
      border-color: var(--pup-blue);
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
      color: var(--pup-blue);
    }

    .password-toggle:active {
      transform: translateY(-50%) scale(0.95);
    }

    /* Buttons */
    .btn-sign-in {
      background: var(--pup-blue);
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
      background: var(--pup-gold);
      color: var(--pup-blue);
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

    .alert-warning {
      background: #fef3c7;
      color: #92400e;
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

    .is-invalid~.invalid-feedback {
      display: block;
    }

    @keyframes shake {

      0%,
      100% {
        transform: translateX(0);
      }

      25% {
        transform: translateX(-4px);
      }

      75% {
        transform: translateX(4px);
      }
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
      color: var(--pup-blue);
      text-decoration: none;
      font-weight: 600;
      transition: color 0.2s;
    }

    .form-footer a:hover {
      color: var(--pup-blue-dark);
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
      to {
        transform: rotate(360deg);
      }
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
        background: linear-gradient(135deg, #fede0e, var(--pup-gold));
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(220, 169, 44, 0.3);
      }

      .login-right::after {
        content: 'ARCHIVE';
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
        font-size: 0.8rem;
        color: var(--pup-blue);
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
      outline: 2px solid var(--pup-blue);
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
        <div class="branding-icon">A</div>
        <h1 class="branding-title">ARCHIVE</h1>
        <p class="branding-subtitle">
          Public Research Repository<br>
          Guest Access Portal
        </p>

        <div class="branding-features">
          <div class="feature-item">
            <div class="feature-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 16 16">
                <path
                  d="M1 2.828c.885-.37 2.154-.769 3.388-.893 1.33-.134 2.458.063 3.112.752v9.746c-.935-.53-2.12-.603-3.213-.493-1.18.12-2.37.461-3.287.811V2.828zm7.5-.141c.654-.689 1.782-.886 3.112-.752 1.234.124 2.503.523 3.388.893v9.923c-.918-.35-2.107-.692-3.287-.81-1.094-.111-2.278-.039-3.213.492V2.687zM8 1.783C7.015.936 5.587.81 4.287.94c-1.514.153-3.042.672-3.994 1.105A.5.5 0 0 0 0 2.5v11a.5.5 0 0 0 .707.455c.882-.4 2.303-.881 3.68-1.02 1.409-.142 2.59.087 3.223.877a.5.5 0 0 0 .78 0c.633-.79 1.814-1.019 3.222-.877 1.378.139 2.8.62 3.681 1.02A.5.5 0 0 0 16 13.5v-11a.5.5 0 0 0-.293-.455c-.952-.433-2.48-.952-3.994-1.105C10.413.809 8.985.936 8 1.783z" />
              </svg>
            </div>
            <div class="feature-text">
              <h3>Browse Papers</h3>
              <p>Access approved research documents and abstracts</p>
            </div>
          </div>

          <div class="feature-item">
            <div class="feature-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 16 16">
                <path
                  d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1h2Zm-1 0c0 .667-.455 1-1 1H4c-.545 0-1-.333-1-1 0-3.18 4.5-5 5-5 1.22 0 2.531.55 3.355 1.564.563.671.645 1.622.645 2.436Z" />
              </svg>
            </div>
            <div class="feature-text">
              <h3>Guest Access</h3>
              <p>Temporary secure access for external visitors</p>
            </div>
          </div>

          <div class="feature-item">
            <div class="feature-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 16 16">
                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z" />
                <path
                  d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z" />
              </svg>
            </div>
            <div class="feature-text">
              <h3>Verified Content</h3>
              <p>All documents are reviewed and approved by faculty</p>
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
            <h2>Archive Access</h2>
            <p>Enter your credentials to continue</p>
          </div>

          <!-- Alerts -->
          <?php if ($expired): ?>
            <div class="alert alert-warning" role="alert">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                style="vertical-align: text-bottom; margin-right: 0.5rem;" viewBox="0 0 16 16">
                <path
                  d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z" />
              </svg>
              Your guest session has expired. Please login again.
            </div>
          <?php endif; ?>
          <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                style="vertical-align: text-bottom; margin-right: 0.5rem;" viewBox="0 0 16 16">
                <path
                  d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z" />
              </svg>
              <?= e($error) ?>
            </div>
          <?php endif; ?>

          <form method="post" id="loginForm">
            <div class="form-group">
              <label class="form-label" for="username">Username / ID</label>
              <div class="input-wrapper">
                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                  viewBox="0 0 16 16">
                  <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3Zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                </svg>
                <input type="text" name="username" id="username" class="form-control" placeholder="Enter username or ID"
                  required autocomplete="username">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="password">Password</label>
              <div class="password-group">
                <div class="input-wrapper">
                  <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                    viewBox="0 0 16 16">
                    <path
                      d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z" />
                  </svg>
                  <input type="password" name="password" id="password" class="form-control" placeholder="Enter password"
                    required autocomplete="current-password">
                  <button class="password-toggle" type="button" id="togglePassword"
                    aria-label="Toggle password visibility">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                      viewBox="0 0 16 16">
                      <path
                        d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z" />
                      <path
                        d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>

            <button type="submit" class="btn btn-sign-in" id="signInBtn">
              <span>ENTER ARCHIVE</span>
            </button>
          </form>

          <div class="divider">
            <span>OR</span>
          </div>

          <!-- Staff and students sign in through the modal on the repository page. -->
          <a href="index.php?login_modal=1" class="btn btn-public-archive">
            <span>USER LOGIN</span>
          </a>

          <div class="form-footer">
            <p>
              <a href="index.php">← Back to the repository</a>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>"
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
    crossorigin="anonymous"></script>
  <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    // Password Toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function () {
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

    // Form Validation
    const loginForm = document.getElementById('loginForm');
    const usernameInput = document.getElementById('username');
    const passInput = document.getElementById('password');
    const signInBtn = document.getElementById('signInBtn');

    loginForm.addEventListener('submit', function (e) {
      let isValid = true;

      if (usernameInput.value.trim() === '') {
        usernameInput.classList.add('is-invalid');
        isValid = false;
      } else {
        usernameInput.classList.remove('is-invalid');
      }

      if (passInput.value.trim() === '') {
        passInput.classList.add('is-invalid');
        isValid = false;
      } else {
        passInput.classList.remove('is-invalid');
      }

      if (!isValid) {
        e.preventDefault();
      } else {
        signInBtn.classList.add('btn-loading');
        signInBtn.querySelector('span').textContent = 'ENTERING...';
      }
    });

    // Remove invalid class on input
    usernameInput.addEventListener('input', function () {
      if (this.classList.contains('is-invalid')) {
        this.classList.remove('is-invalid');
      }
    });

    passInput.addEventListener('input', function () {
      if (this.classList.contains('is-invalid')) {
        this.classList.remove('is-invalid');
      }
    });

    [bMonth, bDay, bYear].forEach(el => {
      el.addEventListener('change', function () {
        this.classList.remove('is-invalid');
        if (bMonth.value && bDay.value && bYear.value) bdFeedback.style.display = 'none';
      });
    });

    // Auto-focus on first input
    window.addEventListener('load', function () {
      usernameInput.focus();
    });
  </script>
  <?php include __DIR__ . '/../includes/accessibility.php'; ?>
</body>

</html>
