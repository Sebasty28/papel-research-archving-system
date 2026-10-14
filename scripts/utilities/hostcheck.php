<?php
/**
 * Host check: can this server actually run PAPEL?
 *
 * Upload this one file to the web root of a new hosting account, open it with
 * the key below, and read the answer. It depends on nothing else in the
 * project, so it works before the code, the database or the .env exist.
 *
 * It exists because free and shared hosts differ in the two places PAPEL
 * cannot bend: outbound HTTPS (every paper goes to Google Drive through the
 * API, and the AI extraction calls Groq) and outbound SMTP (every guest pass,
 * password and notification is an email). A host can serve PHP perfectly and
 * still fail both. Finding that out from a probe takes ten minutes; finding it
 * out from a half-migrated system takes a weekend.
 *
 *   1. Change KEY below to something only you know.
 *   2. Upload as hostcheck.php to the account's web root (htdocs).
 *   3. Open https://your-site/hostcheck.php?key=YOURKEY
 *   4. Delete it when you are done. It reports configuration, so it is not
 *      something to leave lying about.
 *
 * Database and SMTP credentials are optional: fill them in to test those two,
 * leave them blank to skip.
 */

const KEY = 'change-this-first';

// Optional. Fill in to test the database and the mail account on this host.
const DB_HOST_T = '';
const DB_NAME_T = '';
const DB_USER_T = '';
const DB_PASS_T = '';
const SMTP_HOST_T = 'smtp.gmail.com';

if (!isset($_GET['key']) || !hash_equals(KEY, (string)$_GET['key'])) {
    http_response_code(404);
    exit("Not found\n");
}

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(120);

$fails = 0;
$warns = 0;

function head(string $title): void {
    echo "\n", str_repeat('=', 68), "\n", $title, "\n", str_repeat('=', 68), "\n";
}
function line(string $label, string $value, ?bool $ok = null, string $why = ''): void {
    global $fails, $warns;
    $mark = '     ';
    if ($ok === true)  { $mark = '[ OK]'; }
    if ($ok === false) { $mark = '[BAD]'; $GLOBALS['fails']++; }
    if ($ok === null && $why !== '') { $mark = '[HM ]'; $GLOBALS['warns']++; }
    printf("%s %-26s %s%s\n", $mark, $label, $value, $why !== '' ? "\n      -> $why" : '');
}
/* "40M" and "128M" are not numbers until you make them numbers. */
function to_bytes(string $v): float {
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $unit = strtolower($v[strlen($v) - 1]);
    $n = (float)$v;
    if ($unit === 'g') return $n * 1024 * 1024 * 1024;
    if ($unit === 'm') return $n * 1024 * 1024;
    if ($unit === 'k') return $n * 1024;
    return $n;
}
function mb(float $bytes): string {
    return $bytes < 0 ? 'unlimited' : round($bytes / 1048576, 1) . ' MB';
}

echo "PAPEL host check\n";
echo date('Y-m-d H:i:s T'), "   ", $_SERVER['HTTP_HOST'] ?? php_uname('n'), "\n";

/* ------------------------------------------------------------------ PHP */
head('1. PHP');
$v = PHP_VERSION;
line('Version', $v . ' (' . PHP_SAPI . ')', version_compare($v, '8.0', '>='),
     version_compare($v, '8.0', '>=') ? '' : 'PAPEL needs 8.0 or newer');
line('Timezone', date_default_timezone_get(), null,
     date_default_timezone_get() === 'Asia/Manila' ? '' : 'config.php sets Asia/Manila itself, so this is only the default');

/* Only what PAPEL actually calls. gd is deliberately not here: the single
   image check uses getimagesize(), which is core PHP, so a host without gd
   is fine and reporting it as missing would be a false alarm. */
$need = [
    'mysqli'  => 'every query',
    'curl'    => 'Google Drive and Groq',
    'openssl' => 'HTTPS to both, and SMTP',
    'mbstring'=> 'text handling throughout',
    'json'    => 'every API call and the chatbot',
    'dom'     => 'the HTML sanitiser in core.php',
    'zip'     => 'the Word export in includes/docx_writer.php',
    'zlib'    => 'the PDF parser',
    'session' => 'sign-in',
];
foreach ($need as $ext => $why) {
    $has = extension_loaded($ext);
    line('Extension ' . $ext, $has ? 'loaded' : 'MISSING', $has, $has ? '' : 'PAPEL needs it for ' . $why);
}

$disabled = trim((string)ini_get('disable_functions'));
line('disable_functions', $disabled === '' ? 'none' : $disabled, null,
     $disabled === '' ? '' : 'check that fsockopen, curl_exec and file_get_contents are not on this list');

/* -------------------------------------------------------------- Limits */
head('2. Limits that PAPEL bumps into');
$up   = to_bytes((string)ini_get('upload_max_filesize'));
$post = to_bytes((string)ini_get('post_max_size'));
line('upload_max_filesize', mb($up), $up < 0 || $up >= 10485760,
     $up > 0 && $up < 52428800 ? 'The upload page promises students 50 MB. A paper larger than ' . mb($up) . ' will be refused, so either the limit goes up or the promise comes down.' : '');
line('post_max_size', mb($post), $post < 0 || $post >= $up,
     $post > 0 && $post < $up ? 'Smaller than upload_max_filesize, which makes the upload limit meaningless' : '');
line('memory_limit', mb(to_bytes((string)ini_get('memory_limit'))), null,
     to_bytes((string)ini_get('memory_limit')) > 0 && to_bytes((string)ini_get('memory_limit')) < 134217728
        ? 'Under 128 MB is tight for parsing a large PDF' : '');
line('max_execution_time', (string)ini_get('max_execution_time') . ' s', null,
     (int)ini_get('max_execution_time') > 0 && (int)ini_get('max_execution_time') < 60
        ? 'A Drive upload plus an AI extraction can take longer than this' : '');
line('max_input_vars', (string)ini_get('max_input_vars'));
line('allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off');

/* ------------------------------------------------------- Outbound HTTPS */
head('3. Outbound HTTPS (this is the one that decides it)');
if (!function_exists('curl_init')) {
    line('cURL', 'not available', false, 'Without cURL there is no Google Drive and no Groq, so no uploads and no AI extraction');
} else {
    $targets = [
        'Google Drive API'  => 'https://www.googleapis.com/drive/v3/about',
        'Google OAuth'      => 'https://oauth2.googleapis.com/tokeninfo',
        'Groq API'          => 'https://api.groq.com/openai/v1/models',
        'Google reCAPTCHA'  => 'https://www.google.com/recaptcha/api/siteverify',
        'Plain internet'    => 'https://www.gstatic.com/generate_204',
    ];
    foreach ($targets as $label => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_NOBODY         => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'PAPEL-hostcheck',
        ]);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $ms = round((microtime(true) - $t0) * 1000);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        /* 401 and 403 are fine here: the request reached the service and was
           turned away for having no key, which is exactly what we are asking.
           Only a connection that never lands is a failure. */
        $reached = $code > 0;
        line($label, $reached ? "HTTP $code in {$ms} ms" : 'no connection',
             $reached, $reached ? '' : ($err !== '' ? $err : 'blocked or unreachable'));
    }
    line('DNS', implode(', ', array_map(static function ($h) {
        $ip = gethostbyname($h);
        return $h . '=' . ($ip === $h ? 'FAILED' : $ip);
    }, ['www.googleapis.com', 'api.groq.com', 'smtp.gmail.com'])));
}

/* -------------------------------------------------------- Outbound SMTP */
head('4. Outbound SMTP (every notification is an email)');
if (!function_exists('fsockopen')) {
    line('fsockopen', 'disabled', false, 'PHPMailer cannot open an SMTP connection without it');
} else {
    foreach ([587 => 'STARTTLS, what PAPEL uses now', 465 => 'implicit TLS, the usual fallback'] as $port => $note) {
        $t0 = microtime(true);
        $fp = @fsockopen(SMTP_HOST_T, $port, $errno, $errstr, 10);
        $ms = round((microtime(true) - $t0) * 1000);
        if ($fp) {
            $banner = trim((string)fgets($fp, 256));
            fclose($fp);
            line(SMTP_HOST_T . ':' . $port, "open in {$ms} ms  $banner", true, '');
        } else {
            line(SMTP_HOST_T . ':' . $port, "refused ($errno $errstr)", false,
                 "$note. If both ports are shut, email has to go out over HTTPS through a mail API instead.");
        }
    }
}

/* ---------------------------------------------------------- Filesystem */
head('5. Filesystem');
$here = __DIR__;
$probe = $here . '/.papel-write-test';
$canWrite = @file_put_contents($probe, 'x') !== false;
if ($canWrite) @unlink($probe);
line('Write in web root', $canWrite ? 'yes' : 'no', $canWrite,
     $canWrite ? '' : 'Drafts, pasted section images and the Drive token all need a writable folder');

$count = 0;
$big = [];
$bigCode = [];
try {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($here, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $count++;
        if ($count > 60000) break;
        if (!$f->isFile()) continue;
        $size = $f->getSize();
        if ($size > 10485760) $big[] = $f->getFilename() . ' (' . mb((float)$size) . ')';
        if ($size > 1048576 && preg_match('/\.(php|html?)$/i', $f->getFilename())) {
            $bigCode[] = $f->getFilename() . ' (' . mb((float)$size) . ')';
        }
    }
} catch (Throwable $e) {
    line('Counting files', 'stopped: ' . $e->getMessage());
}
line('Files and folders here', number_format($count), $count < 28000,
     $count >= 28000 ? 'Close to the 30,000 file ceiling free accounts have. Trim vendor: PAPEL only needs the Drive service.' : '');
line('Files over 10 MB', $big ? implode(', ', $big) : 'none', !$big,
     $big ? 'Free hosting refuses files over 10 MB, by FTP and by file manager alike' : '');
line('PHP or HTML over 1 MB', $bigCode ? implode(', ', $bigCode) : 'none', !$bigCode,
     $bigCode ? 'Some free hosts cap code files at 1 MB' : '');

session_start();
$_SESSION['papel_probe'] = 1;
line('Sessions', session_id() !== '' ? 'working, id ' . substr(session_id(), 0, 6) . '...' : 'no id',
     session_id() !== '');

/* ------------------------------------------------------------ Database */
head('6. Database');
if (DB_NAME_T === '') {
    line('Skipped', 'fill in DB_* at the top of this file to test');
} elseif (!extension_loaded('mysqli')) {
    line('mysqli', 'missing', false);
} else {
    $t0 = microtime(true);
    $c = @new mysqli(DB_HOST_T, DB_USER_T, DB_PASS_T, DB_NAME_T);
    $ms = round((microtime(true) - $t0) * 1000);
    if ($c->connect_errno) {
        line('Connection', 'failed: ' . $c->connect_error, false);
    } else {
        line('Connection', "ok in {$ms} ms", true);
        line('Server', (string)$c->server_info);
        $r = $c->query("SELECT @@sql_mode m, @@max_allowed_packet p");
        if ($r && ($row = $r->fetch_assoc())) {
            $strict = strpos((string)$row['m'], 'STRICT_TRANS_TABLES') !== false;
            line('sql_mode', $row['m'] === '' ? '(empty)' : $row['m'], null,
                 $strict ? 'Stricter than the local server: a value an ENUM does not list will now be an error rather than an empty string. That is better, but it can surface writes that were quietly failing.' : '');
            line('max_allowed_packet', mb((float)$row['p']));
        }
        $c->close();
    }
}

/* --------------------------------------------------------- The verdict */
head('Verdict');
echo $fails === 0
    ? "Nothing failed. PAPEL can run here.\n"
    : "$fails check(s) failed. Read each [BAD] above before committing to this host.\n";
if ($warns) echo "$warns thing(s) worth a look, marked [HM ].\n";
echo "\nDelete this file when you are done.\n";
