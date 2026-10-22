<?php
/**
 * Upload diagnostics: why "Extract Metadata with AI" fails on a given server.
 *
 * Temporary, and meant to be deleted once the answer is in hand. It exists
 * because the failure it diagnoses only happens on the host, where none of the
 * usual verification applies: no CLI, no logs to read, no browser tooling on
 * the machine itself. One page view reports the facts instead.
 *
 * Signed-in only, like every other page here, and it prints no secret: a key is
 * reported as present or absent and by length, never by value.
 */
require_once '../../config/core.php';
require_role(['student', 'faculty', 'admin', 'super_admin', 'head_academic', 'librarian']);
require_once '../../config/groq_config.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$line = function (string $label, $value) { printf("%-28s %s\n", $label . ':', $value); };
$yn   = fn($b) => $b ? 'yes' : 'NO';

echo "PAPEL upload diagnostics\n";
echo str_repeat('=', 60), "\n\n";

echo "-- Server ------------------------------------------------\n";
$line('php version', PHP_VERSION);
$line('host', $_SERVER['HTTP_HOST'] ?? '?');
$line('app env', defined('APP_ENV') ? APP_ENV : '(undefined)');
$line('memory_limit', ini_get('memory_limit'));
$line('max_execution_time', ini_get('max_execution_time') . 's');
$line('post_max_size', ini_get('post_max_size'));
$line('upload_max_filesize', ini_get('upload_max_filesize'));
$line('max_post_bytes()', function_exists('max_post_bytes') ? max_post_bytes() : '?');
$line('curl extension', $yn(function_exists('curl_init')));
$line('allow_url_fopen', $yn((bool)ini_get('allow_url_fopen')));
$line('openssl', $yn(extension_loaded('openssl')));

echo "\n-- PDF reading -------------------------------------------\n";
$line('Smalot PdfParser', $yn(class_exists('\Smalot\PdfParser\Parser')));
$line('vendor/autoload', $yn(is_file(__DIR__ . '/../../vendor/autoload.php')));
$line('finfo', $yn(class_exists('finfo')));

echo "\n-- Groq keys (never printed) -----------------------------\n";
foreach (['GROQ_API_KEY', 'GROQ_API_KEY_UPLOAD', 'GROQ_API_KEY_UPLOAD_2'] as $k) {
    $line($k, defined($k) && constant($k) !== ''
        ? 'present, ' . strlen((string)constant($k)) . ' chars'
        : 'ABSENT');
}
$line('GROQ_API_URL', defined('GROQ_API_URL') ? GROQ_API_URL : '(undefined)');

echo "\n-- Can this server reach the API? ------------------------\n";
$hostName = parse_url(defined('GROQ_API_URL') ? GROQ_API_URL : 'https://api.groq.com', PHP_URL_HOST);
$line('resolving', $hostName);
$ip = gethostbyname($hostName);
$line('dns result', $ip === $hostName ? 'FAILED to resolve' : $ip);

if (function_exists('curl_init')) {
    /* A HEAD against the API root. Nothing is sent and nothing is charged: the
       question is only whether a TCP and TLS connection can be made at all,
       which is the thing free hosting most often forbids. */
    $t = microtime(true);
    $ch = curl_init('https://' . $hostName . '/');
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    $line('connect test', $code ? "HTTP $code" : 'no response');
    $line('curl errno', $errno ?: '0 (none)');
    $line('curl error', $err !== '' ? $err : '(none)');
    printf("%-28s %.2fs\n", 'took:', microtime(true) - $t);

    echo "\n-- A real (tiny) model call ------------------------------\n";
    $t = microtime(true);
    $res = call_groq_api('Reply with the single word ok.', 'ok', 5,
        defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null,
        defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null, false);
    printf("%-28s %.2fs\n", 'took:', microtime(true) - $t);
    $line('success', $yn(!empty($res['success'])));
    $line('http_code', $res['http_code'] ?? '(none)');
    $line('error', isset($res['error']) ? substr((string)$res['error'], 0, 200) : '(none)');
}

echo "\n", str_repeat('=', 60), "\n";
echo "Delete app/student/upload_diagnostics.php once this is answered.\n";
