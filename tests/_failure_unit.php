<?php
/**
 * Unit tests for safe_error_message() in config/error_handler.php.
 *
 *     php tests/_failure_unit.php
 *
 * "PASS name" or "FAIL name: detail" per case, then "SUMMARY passed=N failed=N".
 * Run by tests/m11_submit_failures.py.
 *
 * The point of the function is a boundary: on production a failure is a sentence
 * and a reference, and on any copy of the site it also carries the cause. APP_ENV
 * is a constant and is fixed when config.php loads, so each environment is
 * checked in a process of its own (this script re-runs itself with a mode).
 */
$mode = $argv[1] ?? '';

if ($mode === 'production' || $mode === 'staging') {
    define('APP_ENV', $mode);                 // before config.php, which would otherwise read .env
    require_once __DIR__ . '/../config/core.php';

    $plain   = new RuntimeException('Duplicate entry for key PRIMARY');
    $wrapped = new Exception('Failed to upload paper to Google Drive. Please try again.', 0,
                             new Exception('invalid_grant: Token has been expired or revoked.'));
    $written = new UserFacingException('Please write the Introduction section.');

    echo json_encode([
        'env'     => APP_ENV,
        'plain'   => safe_error_message($plain, 'Upload failed. Please try again.'),
        'wrapped' => safe_error_message($wrapped, 'Upload failed. Please try again.'),
        'written' => safe_error_message($written, $written->getMessage()),
    ]), "\n";
    exit(0);
}

$passed = 0;
$failed = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $name\n"; }
    else     { $failed++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function run(string $env): array {
    $out = shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__FILE__) . ' ' . $env . ' 2>NUL');
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$out))));
    return json_decode(end($lines) ?: '{}', true) ?: [];
}

$prod = run('production');
$stg  = run('staging');

t('production: a plain failure is a sentence and a reference', ($prod['plain']['message'] ?? '') === 'Upload failed. Please try again.'
    && preg_match('/^ERR-[0-9A-F]{8}$/', $prod['plain']['reference'] ?? '') === 1, json_encode($prod['plain'] ?? null));
t('production: and nothing about the cause', !array_key_exists('detail', $prod['plain'] ?? []), json_encode($prod['plain'] ?? null));
t('production: not even for a wrapped error', !array_key_exists('detail', $prod['wrapped'] ?? []));

t('staging: the same failure also carries its cause', strpos($stg['plain']['detail'] ?? '', 'RuntimeException: Duplicate entry for key PRIMARY') === 0,
    json_encode($stg['plain'] ?? null));
t('staging: with where it was thrown', preg_match('/\[_failure_unit\.php:\d+\]$/', $stg['plain']['detail'] ?? '') === 1, $stg['plain']['detail'] ?? '');
t('staging: a wrapped error shows the error inside it, which is the real reason',
    strpos($stg['wrapped']['detail'] ?? '', '<- Exception: invalid_grant: Token has been expired or revoked.') !== false,
    $stg['wrapped']['detail'] ?? '');
t('staging: a message written for the reader gets no detail, it already says it all',
    !array_key_exists('detail', $stg['written'] ?? []) && ($stg['written']['message'] ?? '') === 'Please write the Introduction section.',
    json_encode($stg['written'] ?? null));
t('staging: the reference is the same shape', preg_match('/^ERR-[0-9A-F]{8}$/', $stg['plain']['reference'] ?? '') === 1);
t('detail is bounded', strlen($stg['wrapped']['detail'] ?? '') <= 500);

echo "SUMMARY passed=$passed failed=$failed\n";
exit($failed ? 1 : 0);
