<?php
require_once '../../config/core.php';
start_session_once();

// Check if user is a guest (either via session or role) before destroying session
$is_guest = isset($_SESSION['guest_login']) || (isset($_SESSION['user']) && isset($_SESSION['user']['user_role']) && $_SESSION['user']['user_role'] === 'guest');

// Check if coming from archive (via referrer or GET parameter)
$referrer = $_SERVER['HTTP_REFERER'] ?? '';
$from_archive = isset($_GET['from']) && $_GET['from'] === 'archive';
$from_archive = $from_archive || (strpos($referrer, '/archive/') !== false);

/* Which browser copy of a draft to clear once this is done, read while the
   session can still say who this is. The next page removes it: signing out is
   the moment somebody leaves a shared lab machine, and an unsubmitted paper
   should not be sitting in its browser afterwards. See
   upload_draft_storage_key() and includes/site_footer.php.

   The key names the account that is signing out and no other, so one student
   signing out never destroys the draft another left on the same browser. */
$draft_key = isset($_SESSION['user']) && is_array($_SESSION['user'])
    ? upload_draft_storage_key($_SESSION['user'])
    : '';

/* Destroy the session, and the cookie that points at it.
   Without clearing the cookie the browser keeps presenting the same id, so the
   next page reuses an emptied session: one with no CSRF token in it. Any login
   page left open from before then fails to submit, which reads as "Invalid CSRF
   token" rather than "you signed out". Dropping the cookie means the next
   request starts a clean session and is issued a fresh token. */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

/* A new session holding one value, so the instruction above has somewhere to
   live across the redirect. The same idiom as the idle-expiry path and the
   wrong-role path in core.php, both of which keep a clean session for a flash
   rather than leaving nothing behind.

   It does not undo the paragraph above. What that avoids is the browser
   presenting the *destroyed* id and a page then reusing an emptied session
   with no CSRF token in it; this is a different id with a fresh session behind
   it, so the next page is issued a token exactly as it would have been. The
   id is regenerated rather than reused for the same reason, and nothing here
   is a credential: a storage key is the account's number, which a signed-out
   browser can do nothing with.

   Only when somebody was actually signed in, so a sign-out with no account
   behind it leaves no session behind at all. For an account that has never
   opened the upload page there is no such key in the browser and the removal
   is a no-op, which is cheaper than asking the database whether a draft
   exists on the one request where nothing can be done about the answer. */
if ($draft_key !== '') {
    session_start();
    session_regenerate_id(true);
    $_SESSION['papel_clear_draft'] = $draft_key;
}

// Always land on the public archive after logout
header('Location: '.BASE_URL.'/archive/index.php');
exit;
