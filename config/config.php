<?php
// config.php - Load environment variables
function load_env($file = __DIR__ . '/.env') {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
load_env();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'PAPEL');
define('BASE_URL', $_ENV['BASE_URL'] ?? 'http://localhost/capstone');
define('ROOT_PATH', dirname(__DIR__));

// MySQL
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'capstone_db');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// Security
define('CSRF_KEY', $_ENV['CSRF_KEY'] ?? 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');
define('COOKIE_SECURE', filter_var($_ENV['COOKIE_SECURE'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

// Email Settings
// Set to 'gmail' or 'outlook' to switch modes
define('MAIL_PROVIDER', 'gmail');

/* Where messages from the contact form land, and the address shown to anyone
   looking for help. Defined once so the two cannot drift apart — it used to be
   written out separately in each place, which meant the page could invite
   people to write to one address while the form delivered to another. */
define('SUPPORT_EMAIL', $_ENV['SUPPORT_EMAIL'] ?? 'clarionlessonvewsystem@gmail.com');

if (MAIL_PROVIDER === 'gmail') {
    // Gmail Settings
    /* The account PAPEL sends from. Both of these live in .env, which git is
       told to ignore — a password written here would be committed and would
       stay in the repository's history for good. */
    define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
    define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
    define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');   // Gmail app password — set in .env
    define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? 587);
    define('SMTP_SECURE', 'tls');
} else {
    // Outlook / Office 365 Settings
    define('SMTP_HOST', 'smtp.office365.com');
    define('SMTP_USER', 'your_email@outlook.com'); 
    define('SMTP_PASS', 'your_app_password');      
    define('SMTP_PORT', 587);
    define('SMTP_SECURE', 'tls');
}
