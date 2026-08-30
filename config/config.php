<?php
/**
 * Load environment variables from .env.
 *
 * This defaulted to __DIR__ . '/.env' — that is config/.env, and the file has
 * always lived at the project root. file_exists() therefore failed, load_env()
 * returned immediately, and *nothing* in .env was ever read: every setting
 * below silently fell back to its hardcoded default, on every machine, always.
 *
 * It went unnoticed because the database defaults (localhost / capstone_db /
 * root / no password) happen to match XAMPP, so the app ran. What did not run
 * was everything whose default is a placeholder or empty — SMTP_USER and
 * SMTP_PASS were blank, which is why no email has ever been delivered, and
 * CSRF_KEY stayed on the literal string CHANGE_THIS_TO_A_LONG_RANDOM_SECRET.
 *
 * The root is checked first, config/ second, so an existing config/.env on
 * somebody's machine still works.
 */
function load_env(?string $file = null): void {
    $candidates = $file !== null
        ? [$file]
        : [dirname(__DIR__) . '/.env', __DIR__ . '/.env'];

    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path)) continue;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            // A line with no '=' is a comment somebody forgot to mark.
            if (strpos($line, '=') === false) continue;

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') continue;

            $value = trim($value);
            // Allow "quoted values", which is how a value with spaces is written.
            $len = strlen($value);
            if ($len > 1 && (($value[0] === '"' && $value[$len - 1] === '"')
                          || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            $_ENV[$key] = $value;
        }
        return;   // first file found wins
    }
}
load_env();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'PAPEL');
define('BASE_URL', $_ENV['BASE_URL'] ?? 'http://localhost/capstone');

/* The sign-in robot check. Blank until a site is registered at
   google.com/recaptcha/admin; see includes/recaptcha.php for why blank has to
   mean "switched off" rather than "blocked". */
define('RECAPTCHA_SITE_KEY',   $_ENV['RECAPTCHA_SITE_KEY']   ?? '');
define('RECAPTCHA_SECRET_KEY', $_ENV['RECAPTCHA_SECRET_KEY'] ?? '');
define('ROOT_PATH', dirname(__DIR__));

// MySQL
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'capstone_db');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

/* A hosted database is not on 3306 and will not talk in the clear. Both of
   these are empty for XAMPP, where the defaults are right and there is no
   certificate to present, so nothing changes locally until .env says otherwise.

   DB_SSL_CA is the path to the provider's CA certificate — Aiven hands you one
   as ca.pem. Setting it is what turns the connection encrypted *and verified*;
   without verification an encrypted connection still trusts whoever answers. */
define('DB_PORT',   (int)($_ENV['DB_PORT'] ?? 3306));
define('DB_SSL_CA', $_ENV['DB_SSL_CA'] ?? '');

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
/* Falls back to the account PAPEL sends from, because those two being different
   is silent: the send succeeds, the message is accepted, and it lands in a
   mailbox nobody reads. The literal below was one letter off from the real
   account — "vew" for "view" — so every contact form message and every support
   request went to an address the office does not own. */
define('SUPPORT_EMAIL',
    $_ENV['SUPPORT_EMAIL'] ?? $_ENV['SMTP_USER'] ?? 'clarionlessonviewsystem@gmail.com');

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
