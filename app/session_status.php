<?php
/**
 * How much of the idle window is left, and the one way to push it back.
 *
 * includes/session_timer.php draws a warning five minutes before a session
 * goes idle, and a pop-up when it has gone. Both need the server's own answer:
 * the page cannot work it out for itself, because a request made in any other
 * tab, or by any poll on this one, resets the clock for all of them.
 *
 * GET   a peek. Reports the time left and **does not count as activity**.
 *       PAPEL_SESSION_PEEK is defined before core.php for that reason: without
 *       it, asking how long was left would be the thing keeping the session
 *       alive, and a browser left open would never idle out at all.
 * POST  _token      "Stay signed in". An ordinary request, so core.php's own
 *       `seen` stamp pushes the deadline back; this file does nothing else.
 *
 * 200   {"ok":true,"signed_in":true,"now":<epoch>,"seen":<epoch>,
 *        "expires":<epoch>,"window":1800,"login":"<url>"}
 * 200   {"ok":true,"signed_in":false,...} when the session has gone. A refusal
 *       code would be right about the session and wrong about the request: the
 *       question was answered, and the answer is "nobody is signed in". The
 *       page needs that answer to put its pop-up up, so it must be able to
 *       read it.
 * 403   {"ok":false,...} on a POST whose token is stale, which is the one case
 *       the page must not read as "you are still signed in".
 */

/* Before core.php, and only for the peek: the flag is read inside
   start_session_once(), which runs on the first call that touches the session.
   The idle wipe still happens, so a peek still reports a session that has
   ended; it simply does not stamp it as alive. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    define('PAPEL_SESSION_PEEK', true);
}

require_once '../config/core.php';

header('Content-Type: application/json');
/* A countdown read from a cached answer is a countdown that is wrong. */
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* csrf_valid(), the shared comparison, rather than csrf_verify(), which
       answers a failure with a redirect: a fetch follows that and reads a page
       of HTML where it expected JSON. The same reason archive/login.php and
       the sign-in panel use it. */
    if (!csrf_valid()) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'message' => 'This page has been open too long. Please reload it.']));
    }
}

exit(json_encode(session_idle_state(), JSON_INVALID_UTF8_SUBSTITUTE));
