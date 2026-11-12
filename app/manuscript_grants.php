<?php
/**
 * What a student's manuscript grants look like right now, as JSON.
 *
 * The live countdown in the corner of every page
 * (includes/manuscript_timer.php) asks this every half minute, so a grant a
 * librarian has just signed, and one that has just run out, both show without
 * the reader touching anything. Nothing here writes: a lapsed grant is left as
 * it was written and simply stops being returned (student_manuscript_grants()).
 *
 * GET  (no parameters; the session says who is asking)
 * 200  {"ok":true,"now":<epoch>,"pending":<n>,"grants":[{...}]}
 * 4xx  {"ok":false,"message":"..."}
 *
 * `now` is the server's clock, which the page uses in place of the device's:
 * a laptop whose clock is ten minutes fast would otherwise count a grant out
 * ten minutes early and announce an expiry that has not happened.
 */
require_once '../config/core.php';

header('Content-Type: application/json');
/* A countdown read from a cached answer is a countdown that is wrong. */
header('Cache-Control: no-store');

$refuse = function (int $code, string $message): void {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'message' => $message]));
};

$u = current_user();
if (!$u) {
    $refuse(401, 'Your session has ended.');
}
/* Only a student holds grants: staff read the files anyway, and a Library Pass
   guest has no account for a request to belong to. */
if (($u['user_role'] ?? '') !== 'student') {
    $refuse(403, 'Only a student has manuscript grants.');
}

$uid    = (int)$u['user_id'];
$grants = [];
foreach (student_manuscript_grants($uid) as $g) {
    $grants[] = [
        'id'      => (int)$g['request_id'],
        'paper'   => (int)$g['paper_id'],
        'title'   => (string)($g['paper_title'] ?? 'A paper no longer in the repository'),
        'expires' => (int)strtotime((string)$g['expires_at']),
        'granted' => $g['granted_at'] ? (int)strtotime((string)$g['granted_at']) : 0,
        'hours'   => (int)$g['duration_hours'],
        'url'     => BASE_URL . '/archive/view_paper.php?id=' . (int)$g['paper_id'] . '#pd-sec-manuscript',
    ];
}

echo json_encode([
    'ok'      => true,
    'now'     => time(),
    'pending' => student_manuscript_pending_count($uid),
    'grants'  => $grants,
], JSON_INVALID_UTF8_SUBSTITUTE);
