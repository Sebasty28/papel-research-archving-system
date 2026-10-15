<?php
/**
 * Why is PAPEL answering 500 on this server?
 *
 * The error page carries only a reference code, by design: the detail goes to
 * the host's PHP log, which on free hosting is slow to reach. This walks the
 * same path the app takes when it starts, and says which step fails.
 *
 * It never prints a password, only whether one is set and how long it is.
 *
 *   1. Set KEY below to the same value you used for hostcheck.php.
 *   2. Upload to htdocs, beside index.php.
 *   3. Open https://your-site/bootcheck.php?key=YOURKEY
 *   4. Delete it afterwards.
 */

const KEY = 'papel-39f5838cdf8069ab89';

if (!isset($_GET['key']) || !hash_equals(KEY, (string)$_GET['key'])) {
    http_response_code(404);
    exit("Not found\n");
}

header('Content-Type: text/plain; charset=utf-8');
echo "PAPEL boot check\n", date('Y-m-d H:i:s'), "  ", $_SERVER['HTTP_HOST'] ?? '?', "\n";
echo str_repeat('=', 64), "\n";

/* ---- 1. Is the .env where the app looks for it? ---------------------- */
$root = __DIR__;                       // this file sits in the web root
$env  = $root . '/.env';
echo "\n1. THE .env FILE\n";
printf("   looking in      %s\n", $root);
printf("   .env exists     %s\n", is_file($env) ? 'yes' : 'NO');
if (!is_file($env)) {
    // The usual mistakes: a file manager adding .txt, or the dot being lost.
    foreach (['env', '.env.txt', 'env.txt', '.env.example'] as $wrong) {
        if (is_file($root . '/' . $wrong)) echo "   FOUND INSTEAD   $wrong   <-- rename it to .env\n";
    }
    echo "\n   Nothing else can work until this file is in place.\n";
    exit;
}
printf("   readable        %s\n", is_readable($env) ? 'yes' : 'NO');
printf("   size            %d bytes\n", filesize($env));
$raw = file_get_contents($env);
printf("   starts with BOM %s\n", substr($raw, 0, 3) === "\xEF\xBB\xBF" ? 'YES, that breaks the first line' : 'no');
printf("   line endings    %s\n", strpos($raw, "\r\n") !== false ? 'CRLF (fine)' : 'LF (fine)');
printf("   settings read   %d\n", preg_match_all('/^\s*[A-Z_]+\s*=/m', $raw));
if (preg_match('/FILL-?ME/i', $raw)) {
    echo "   *** still contains FILL-ME: a placeholder was not replaced ***\n";
}

/* ---- 2. Does the configuration load? --------------------------------- */
echo "\n2. CONFIGURATION\n";
try {
    require_once $root . '/config/config.php';
    echo "   config.php      loaded\n";
} catch (Throwable $e) {
    echo "   config.php      FAILED: ", $e->getMessage(), "\n";
    exit;
}
foreach (['APP_NAME', 'BASE_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'UPLOAD_MAX_MB', 'COOKIE_SECURE'] as $c) {
    printf("   %-15s %s\n", $c, defined($c) ? var_export(constant($c), true) : 'NOT DEFINED');
}
printf("   %-15s %s\n", 'DB_PASS', defined('DB_PASS') && DB_PASS !== '' ? 'set, ' . strlen(DB_PASS) . ' characters' : 'EMPTY');
printf("   %-15s %s\n", 'CSRF_KEY',
    defined('CSRF_KEY') && CSRF_KEY !== 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET' ? 'set' : 'STILL THE PLACEHOLDER');

/* ---- 3. The database ------------------------------------------------- */
echo "\n3. DATABASE\n";
if (!extension_loaded('mysqli')) {
    echo "   mysqli          MISSING\n";
} else {
    mysqli_report(MYSQLI_REPORT_OFF);          // we want the message, not an exception
    $t0 = microtime(true);
    $c = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($c->connect_errno) {
        printf("   connect         FAILED (%d) %s\n", $c->connect_errno, $c->connect_error);
        echo "\n   What that usually means here:\n";
        echo "     1045  the password is wrong. It is the vPanel password.\n";
        echo "     2002  DB_HOST is wrong. It is the sql###.infinityfree.com name, never localhost.\n";
        echo "     1044  DB_NAME is wrong, or missing the if0_ prefix.\n";
    } else {
        printf("   connect         ok in %d ms, server %s\n", round((microtime(true) - $t0) * 1000), $c->server_info);
        $r = $c->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema = DATABASE()");
        $n = $r ? (int)$r->fetch_assoc()['n'] : -1;
        printf("   tables          %d %s\n", $n, $n === 20 ? '(all there)' : '(expected 20)');
        $r = $c->query("SELECT COUNT(*) n FROM users");
        if ($r) printf("   users           %d\n", (int)$r->fetch_assoc()['n']);
        $c->close();
    }
}

/* ---- 4. The rest of the boot ----------------------------------------- */
echo "\n4. THE REST\n";
printf("   vendor/autoload %s\n", is_file($root . '/vendor/autoload.php') ? 'present' : 'MISSING, the deploy did not send it');
printf("   writable root   %s\n", is_writable($root) ? 'yes' : 'no');
foreach (['app/student/uploads/drafts', 'uploads/section_images', 'config'] as $d) {
    $p = $root . '/' . $d;
    printf("   %-24s %s\n", $d, is_dir($p) ? (is_writable($p) ? 'exists, writable' : 'exists, NOT writable') : 'MISSING');
}

/* core.php is where the app really starts: sessions, CSP headers, the lot.
   If the 500 is not the database, it shows up here. */
echo "\n5. core.php\n";
try {
    require_once $root . '/config/core.php';
    echo "   core.php        loaded, no fatal error\n";
    if (function_exists('max_upload_label')) {
        echo "   upload limit    ", max_upload_label(), "\n";
    }
} catch (Throwable $e) {
    echo "   core.php        FAILED: ", get_class($e), ': ', $e->getMessage(), "\n";
    echo "                   at ", str_replace($root, '', $e->getFile()), ':', $e->getLine(), "\n";
}

echo "\nDone. Delete this file when the site works.\n";
