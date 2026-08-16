<?php
/**
 * Custom Error Page
 * Shows a user-friendly error with reference ID only.
 * Variables expected: $error_reference, $error_http_code
 */
$error_reference = $error_reference ?? 'UNKNOWN';
$error_http_code = $error_http_code ?? 500;
$app_name = defined('APP_NAME') ? APP_NAME : 'PAPEL';
$base_url = defined('BASE_URL') ? BASE_URL : '/capstone';

$titles = [
    400 => 'Bad Request',
    403 => 'Access Denied',
    404 => 'Page Not Found',
    419 => 'Session Expired',
    429 => 'Too Many Requests',
    500 => 'Something Went Wrong',
];
$descriptions = [
    400 => 'The request could not be processed. Please check your input and try again.',
    403 => 'You do not have permission to access this resource.',
    404 => 'The page or resource you are looking for could not be found.',
    419 => 'Your session has expired. Please log in again.',
    429 => 'You have made too many requests. Please wait a moment and try again.',
    500 => 'An unexpected error occurred. Our team has been notified.',
];

$title = $titles[$error_http_code] ?? 'Something Went Wrong';
$description = $descriptions[$error_http_code] ?? 'An unexpected error occurred. Please try again later.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($app_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
  :root {
    --pup-maroon: #810403;
    --pup-gold: #dca92c;
    --pup-ivory: #fcf8f7;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--pup-ivory);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }
  body::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(129, 4, 3, 0.06) 0%, transparent 70%);
    pointer-events: none;
  }
  body::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(220, 169, 44, 0.06) 0%, transparent 70%);
    pointer-events: none;
  }
  .error-container {
    text-align: center;
    max-width: 520px;
    padding: 3rem 2rem;
    position: relative;
    z-index: 1;
    animation: fadeInUp 0.6s ease-out;
  }
  @keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .error-code {
    font-size: 7rem;
    font-weight: 900;
    background: linear-gradient(135deg, var(--pup-maroon), var(--pup-gold));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1;
    margin-bottom: 1rem;
  }
  .error-title {
    font-size: 1.75rem;
    font-weight: 800;
    color: #1a1a1a;
    margin-bottom: 1rem;
  }
  .error-description {
    font-size: 1.1rem;
    color: #64748b;
    line-height: 1.7;
    margin-bottom: 2rem;
  }
  .error-ref {
    display: inline-block;
    background: #f1f5f9;
    color: #475569;
    padding: 0.5rem 1.25rem;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    font-family: 'Courier New', monospace;
    margin-bottom: 2rem;
    border: 1px solid #e2e8f0;
  }
  .error-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
  }
  .btn-home {
    background: linear-gradient(135deg, var(--pup-maroon), #a52a2a);
    color: white;
    border: none;
    padding: 0.875rem 2rem;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(129, 4, 3, 0.3);
  }
  .btn-home:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(129, 4, 3, 0.4);
    color: white;
  }
  .btn-back {
    background: white;
    color: var(--pup-maroon);
    border: 2px solid var(--pup-maroon);
    padding: 0.875rem 2rem;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    transition: all 0.3s ease;
  }
  .btn-back:hover {
    background: var(--pup-maroon);
    color: white;
    transform: translateY(-2px);
  }
  .footer-text {
    margin-top: 3rem;
    color: #94a3b8;
    font-size: 0.8rem;
  }
</style>
</head>
<body>
<div class="error-container">
    <div class="error-code"><?= (int)$error_http_code ?></div>
    <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>
    <p class="error-description"><?= htmlspecialchars($description) ?></p>
    <div class="error-ref">Reference: <?= htmlspecialchars($error_reference) ?></div>
    <div class="error-actions">
        <a href="javascript:history.back()" class="btn-back">← Go Back</a>
        <a href="<?= htmlspecialchars($base_url) ?>" class="btn-home">Home</a>
    </div>
    <p class="footer-text">If this problem persists, please contact support with the reference code above.</p>
</div>
</body>
</html>
