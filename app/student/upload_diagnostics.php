<?php
/**
 * Upload diagnostics: what this host refuses, and why uploading fails on it.
 *
 * Temporary, and meant to be deleted once the answer is in hand. Staging
 * answers some POSTs with an nginx "403 Forbidden" page, which never reaches
 * PHP, so nothing in this application can see or report it. The second half of
 * this page sends a series of POSTs that differ in one way each and prints what
 * came back, which is the only way to learn what the edge objects to.
 *
 * Signed-in only, and it prints no secret: a key is reported as present or
 * absent and by length, never by value.
 */
require_once '../../config/core.php';
require_role(['student', 'faculty', 'admin', 'super_admin', 'head_academic', 'librarian']);
require_once '../../config/groq_config.php';

/* The target for the probes below, answered before any output so a probe sees
   a small JSON reply and nothing else. Kept on this same file so the address
   being POSTed to is a PHP script in the same folder as the real one: a rule
   that fires on one should fire on the other. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    exit(json_encode([
        'ok'       => true,
        'shape'    => (string)($_POST['shape'] ?? '(none)'),
        'fields'   => count($_POST),
        'files'    => count($_FILES),
        'bytes_in' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
    ]));
}

header('X-Robots-Tag: noindex');
$nonce = csp_nonce();
$yn = fn($b) => $b ? 'yes' : 'NO';
$rows = [];
$line = function (string $label, $value) use (&$rows) { $rows[] = sprintf('%-28s %s', $label . ':', $value); };

$line('php version', PHP_VERSION);
$line('host', $_SERVER['HTTP_HOST'] ?? '?');
$line('app env', defined('APP_ENV') ? APP_ENV : '(undefined)');
$line('server software', $_SERVER['SERVER_SOFTWARE'] ?? '?');
$line('memory_limit', ini_get('memory_limit'));
$line('max_execution_time', ini_get('max_execution_time') . 's');
$line('post_max_size', ini_get('post_max_size'));
$line('upload_max_filesize', ini_get('upload_max_filesize'));
$line('curl extension', $yn(function_exists('curl_init')));
$line('Smalot PdfParser', $yn(class_exists('\Smalot\PdfParser\Parser')));
$line('vendor/autoload', $yn(is_file(__DIR__ . '/../../vendor/autoload.php')));
foreach (['GROQ_API_KEY', 'GROQ_API_KEY_UPLOAD', 'GROQ_API_KEY_UPLOAD_2'] as $k) {
    $line($k, defined($k) && constant($k) !== ''
        ? 'present, ' . strlen((string)constant($k)) . ' chars' : 'ABSENT');
}
$line('AI_PROXY_URL', defined('AI_PROXY_URL') && AI_PROXY_URL !== '' ? AI_PROXY_URL : '(not set)');

$apiHost = parse_url(defined('GROQ_API_URL') ? GROQ_API_URL : 'https://api.groq.com', PHP_URL_HOST);
$ip = gethostbyname($apiHost);
$line('dns ' . $apiHost, $ip === $apiHost ? 'FAILED to resolve' : $ip);
if (function_exists('curl_init')) {
    $t = microtime(true);
    $ch = curl_init('https://' . $apiHost . '/');
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $line('outbound to ' . $apiHost, ($code ? 'HTTP ' . $code : 'no response')
        . ($err !== '' ? ' | ' . $err : '') . sprintf(' | %.2fs', microtime(true) - $t));
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Upload diagnostics</title>
<style nonce="<?= $nonce ?>">
  body { font-family: ui-monospace, Consolas, monospace; margin: 2rem; max-width: 62rem; }
  pre { background: #f6f6f6; padding: 1rem; overflow-x: auto; white-space: pre-wrap; }
  h2 { font-size: 1rem; margin-top: 2rem; }
</style>
<h1>Upload diagnostics</h1>
<h2>Server</h2>
<pre><?= htmlspecialchars(implode(PHP_EOL, $rows), ENT_QUOTES, 'UTF-8') ?></pre>

<h2>What does this host refuse?</h2>
<p>Each row is one POST to this page, differing in a single way. A row marked
REFUSED with a 403 and an nginx page is the host, before PHP ever sees it.</p>
<pre id="probeOut">running...</pre>
<p>Delete <code>app/student/upload_diagnostics.php</code> once this is answered.</p>

<script nonce="<?= $nonce ?>">
(async function () {
  const out = document.getElementById('probeOut');
  const lines = [];
  const show = function () { out.textContent = lines.join(String.fromCharCode(10)); };
  const token = <?= json_encode(csrf_token()) ?>;

  const letters = 'the quick brown fox jumps over the lazy dog ';
  const plain20k = letters.repeat(500).slice(0, 20000);
  const html20k = '<p>' + letters.repeat(400).slice(0, 19000) + '</p><table><tr><td>x</td></tr></table>';
  const bytes = new Uint8Array(300 * 1024);
  bytes.set([0x25, 0x50, 0x44, 0x46, 0x2d, 0x31, 0x2e, 0x34]);
  for (let i = 8; i < bytes.length; i++) { bytes[i] = 32 + (i % 90); }
  const bigPdf = new File([bytes], 'probe.pdf', { type: 'application/pdf' });
  const tinyPdf = new File([bytes.slice(0, 1024)], 'probe.pdf', { type: 'application/pdf' });

  function form(pairs, file) {
    const fd = new FormData();
    fd.set('_token', token);
    for (const k in pairs) { fd.set(k, pairs[k]); }
    if (file) { fd.set('research_pdf', file, 'probe.pdf'); }
    return fd;
  }
  function urlenc(pairs) {
    const p = new URLSearchParams();
    p.set('_token', token);
    for (const k in pairs) { p.set(k, pairs[k]); }
    return p;
  }

  const cases = [
    ['1 tiny, url-encoded',          function () { return urlenc({ shape: 'tiny' }); }],
    ['2 tiny, multipart',            function () { return form({ shape: 'tinymp' }); }],
    ['3 20k of plain text',          function () { return form({ shape: 'plain20k', text: plain20k }); }],
    ['4 a field containing HTML',    function () { return form({ shape: 'html', text: '<p>hello <b>world</b></p>' }); }],
    ['5 20k of HTML',                function () { return form({ shape: 'html20k', text: html20k }); }],
    ['6 a field that looks like SQL', function () { return form({ shape: 'sqlish', text: 'SELECT * FROM users WHERE 1=1' }); }],
    ['7 small PDF attached',         function () { return form({ shape: 'pdfsmall' }, tinyPdf); }],
    ['8 300KB PDF attached',         function () { return form({ shape: 'pdf300k' }, bigPdf); }],
    ['9 PDF plus 20k of HTML',       function () { return form({ shape: 'both', text: html20k }, bigPdf); }]
  ];

  for (const row of cases) {
    const label = row[0];
    let verdict;
    try {
      const r = await fetch(location.pathname, {
        method: 'POST', body: row[1](),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const text = await r.text();
      let parsed = null;
      try { parsed = JSON.parse(text); } catch (e) {}
      if (parsed && parsed.ok) {
        verdict = 'HTTP ' + r.status + '  OK  (' + parsed.fields + ' fields, '
                + parsed.files + ' file, ' + parsed.bytes_in + ' bytes)';
      } else {
        const snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80);
        verdict = 'HTTP ' + r.status + '  REFUSED  ' + (snippet || '(empty body)');
      }
    } catch (err) {
      verdict = 'request never completed: ' + err.message;
    }
    lines.push((label + '                                ').slice(0, 32) + verdict);
    show();
  }
  show();
  document.title = 'PROBE ' + lines.join(' || ');
})();
</script>
