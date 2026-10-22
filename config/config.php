<?php
/**
 * Load environment variables from .env.
 *
 * This defaulted to __DIR__ . '/.env', that is config/.env, and the file has
 * always lived at the project root. file_exists() therefore failed, load_env()
 * returned immediately, and *nothing* in .env was ever read: every setting
 * below silently fell back to its hardcoded default, on every machine, always.
 *
 * It went unnoticed because the database defaults (localhost / capstone_db /
 * root / no password) happen to match XAMPP, so the app ran. What did not run
 * was everything whose default is a placeholder or empty: SMTP_USER and
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

            /* A quoted value runs to its closing quote, and anything after it
               is a comment. An unquoted value ends at the first " #", so a
               line may explain itself:

                   DB_PASS=s3cret            # the vPanel password
                   APP_NAME="PAPEL (Staging)"

               Without this the whole remainder of the line was the value. A
               password with the comment still glued to it is invisible in a
               file manager and comes back as "Access denied", which cost an
               afternoon on the staging host. The hash must be preceded by
               whitespace, so a password containing one, s3cr#t, survives;
               quote the value if it has to end with something like " #1". */
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $close = strpos($value, $quote, 1);
                $value = $close !== false ? substr($value, 1, $close - 1) : substr($value, 1);
            } else {
                foreach ([' #', "\t#"] as $marker) {
                    $at = strpos($value, $marker);
                    if ($at !== false) {
                        $value = rtrim(substr($value, 0, $at));
                        break;
                    }
                }
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

define('APP_NAME', $_ENV['APP_NAME'] ?? 'PAPEL');

/* Which copy of the site this is. Anything other than "production" is a copy
   that must never be mistaken for the real one, so the header marks it with a
   chip beside the logo. The name itself stays "PAPEL" everywhere, because it
   is printed in page titles and in the subject line of every email, and a
   parenthesis in all of those reads worse than one small label in the one
   place a person is actually looking. */
define('APP_ENV', strtolower(trim((string)($_ENV['APP_ENV'] ?? 'production'))));

/** The chip's text, or '' on production, where nothing is shown. */
function app_env_badge(): string {
    return APP_ENV === 'production' || APP_ENV === '' ? '' : strtoupper(APP_ENV);
}
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

   DB_SSL_CA is the path to the provider's CA certificate: Aiven hands you one
   as ca.pem. Setting it is what turns the connection encrypted *and verified*;
   without verification an encrypted connection still trusts whoever answers. */
define('DB_PORT',   (int)($_ENV['DB_PORT'] ?? 3306));
define('DB_SSL_CA', $_ENV['DB_SSL_CA'] ?? '');

// Security
define('CSRF_KEY', $_ENV['CSRF_KEY'] ?? 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');
define('COOKIE_SECURE', filter_var($_ENV['COOKIE_SECURE'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

/* The largest paper the server will take, in megabytes.
 *
 * Three separate numbers can each refuse an upload: upload_max_filesize,
 * post_max_size, and a ceiling the host enforces underneath both, which on
 * free hosting is lower than either and cannot be raised from PHP. This is
 * that host ceiling. max_upload_bytes() in core.php takes whichever of the
 * three is smallest, and every page and validator quotes it, so the figure a
 * student is promised is the figure the server will actually accept. */
define('UPLOAD_MAX_MB', (int)($_ENV['UPLOAD_MAX_MB'] ?? 50));

/* Where the browser sends a paper's text to have its metadata read.
 *
 * Empty means "this server calls the model itself", which is what a machine
 * with outbound access does. Set it on hosting that forbids outbound calls
 * (InfinityFree does): the page then reads the PDF with the copy of PDF.js it
 * already loads, sends the text to that address, and the key stays there
 * rather than in a page anyone can view the source of. See workers/groq-proxy.
 */
define('AI_PROXY_URL', trim((string)($_ENV['AI_PROXY_URL'] ?? '')));

// Email Settings
// Set to 'gmail' or 'outlook' to switch modes
define('MAIL_PROVIDER', 'gmail');

/* Where messages from the contact form land, and the address shown to anyone
   looking for help. Defined once so the two cannot drift apart: it used to be
   written out separately in each place, which meant the page could invite
   people to write to one address while the form delivered to another. */
/* Falls back to the account PAPEL sends from, because those two being different
   is silent: the send succeeds, the message is accepted, and it lands in a
   mailbox nobody reads. The literal below was one letter off from the real
   account ("vew" for "view") so every contact form message and every support
   request went to an address the office does not own. */
define('SUPPORT_EMAIL',
    $_ENV['SUPPORT_EMAIL'] ?? $_ENV['SMTP_USER'] ?? 'clarionlessonviewsystem@gmail.com');

if (MAIL_PROVIDER === 'gmail') {
    // Gmail Settings
    /* The account PAPEL sends from. Both of these live in .env, which git is
       told to ignore: a password written here would be committed and would
       stay in the repository's history for good. */
    define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
    define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
    define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');   // Gmail app password: set in .env
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
