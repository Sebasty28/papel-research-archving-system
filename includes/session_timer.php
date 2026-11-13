<?php
/**
 * The warning five minutes before a session goes idle, and the pop-up that
 * says it has gone.
 *
 * A session here dies after SESSION_IDLE_SECONDS (30 minutes) with nothing
 * asked of the server, enforced on the request itself in start_session_once().
 * Until now the only word on it was after the fact: the next page opened
 * bounced to the sign-in panel carrying a flash that explained the sign-out.
 * Somebody who had spent twenty minutes filling in the upload form found that
 * out by pressing Submit, which is the worst moment to be told.
 *
 * So: a warning with five minutes left, counting down by the second, with a
 * button that keeps the session; and, when the window runs out, a pop-up that
 * cannot be dismissed, because there is no page behind it that still works. It
 * has one way out and that is the sign-in panel, which is what the ask was.
 *
 * Pulled in from includes/site_footer.php, after loading_bar.php, whose
 * papelQuiet flag the polls carry. It prints nothing at all for a visitor who
 * is not signed in: there is no session to lose, and a sign-in panel offered to
 * somebody already looking at one is noise.
 *
 * Four things here are written down the hard way elsewhere in CLAUDE.md:
 *   - a <dialog> needs `margin: auto` stated, or the site's reset takes away
 *     the margin a modal one centres itself with and it opens in the corner;
 *   - no style="..." attribute anywhere (style-src has no 'unsafe-inline', so
 *     they are dropped silently and read as a layout bug);
 *   - nothing that changes every second is written on :root, which would
 *     restyle the whole page once a second;
 *   - the clock is the server's, never the device's. A laptop ten minutes fast
 *     would otherwise announce an expiry that has not happened.
 */

if (!function_exists('session_idle_state')) { return; }

$stUser  = current_user();
$stGuest = !empty($_SESSION['guest_login']);
if (!$stUser && !$stGuest) { return; }

/* The page is drawn with the server's own answer, so the first second on
   screen is already right and nothing has to be fetched to start counting. */
$stState = session_idle_state();

/* How long before the end the warning goes up. Five minutes of a thirty-minute
   window; a figure rather than a fraction, because what matters is whether
   there is time to act, not what share of the window is left. */
if (!defined('SESSION_WARN_SECONDS')) { define('SESSION_WARN_SECONDS', 300); }
?>
<style nonce="<?= csp_nonce() ?>">
/* ===== The pop-up ===================================================
   One dialog in two states: `is-warn` while there is still time, `is-over`
   once there is not. One element rather than two, because the second is what
   the first becomes, and a warning still on screen under an expiry notice
   would be the page saying two different things at once. */
.sx-dlg {
    /* Stated: the site's reset removes the margin a modal dialog centres
       itself with, and it opened in the top left-hand corner. */
    margin: auto;
    width: min(23rem, calc(100vw - 2rem));
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
    color: var(--ink);
    box-shadow: 0 18px 50px rgba(51, 0, 0, .28);
    font-family: var(--font-body), 'Inter', sans-serif;
}
/* Darker than the manuscript pop-up's veil on purpose: behind that one the
   page still works, and behind this one it does not. */
.sx-dlg::backdrop { background: rgba(51, 0, 0, .55); }
.sx-dlg[open] { animation: sx-rise .18s ease-out; }
@keyframes sx-rise {
    from { opacity: 0; transform: translateY(8px) scale(.97); }
    to   { opacity: 1; transform: none; }
}
.sx-card { padding: 1.1rem 1.1rem 1rem; text-align: center; }

.sx-ring {
    position: relative;
    width: 3.25rem;
    height: 3.25rem;
    margin: .25rem auto .7rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    /* The accent, mixed with the card's own ground rather than --soft-maroon,
       so the ring is a tint of the accent in either palette. */
    background: color-mix(in srgb, var(--maroon) 12%, var(--white));
    color: var(--maroon);
}
/* Expired is the one that wears the refusal colours: the warning is still a
   question, and a red circle over a question nobody has answered yet reads as
   something having already gone wrong. */
.sx-dlg.is-over .sx-ring { background: var(--bad-bg); color: var(--bad-text); }
.sx-ico { font-size: 30px; line-height: 1; }
/* Beats while the warning is up and stops after a few rings: an alarm that
   sounds for ever reads as a fault with the page rather than as a notice. */
.sx-dlg.is-warn .sx-ico { animation: sx-tick 1s ease-in-out 8; transform-origin: 50% 50%; }
@keyframes sx-tick {
    0%, 100% { transform: rotate(0); }
    25%      { transform: rotate(9deg); }
    75%      { transform: rotate(-9deg); }
}
.sx-title {
    margin: 0 0 .35rem;
    font-family: var(--font-head), serif;
    font-size: 1.0625rem;
    color: var(--maroon);
}
.sx-dlg.is-over .sx-title { color: var(--bad-text); }
.sx-line { margin: 0 0 .5rem; font-size: .8125rem; color: var(--grey); line-height: 1.5; }

/* The figure. Tabular numerals, or the whole line shuffles sideways every
   second as the digits change width. */
.sx-clock {
    margin: 0 0 .9rem;
    font-family: var(--font-head), serif;
    font-size: 1.75rem;
    line-height: 1;
    color: var(--maroon);
    font-variant-numeric: tabular-nums;
    font-feature-settings: 'tnum' 1;
}
.sx-dlg.is-over .sx-clock { display: none; }

.sx-acts { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; }
.sx-acts .btn-sm-maroon, .sx-acts .btn-sm-outline { min-width: 8rem; justify-content: center; }
.sx-dlg.is-over .sx-stay { display: none; }
/* Expired, the way out is the only button, so it is not one of two. */
.sx-dlg.is-over .sx-acts .btn-sm-maroon { min-width: 11rem; }

@media (max-width: 420px) {
    .sx-card { padding: .9rem .9rem .85rem; }
    .sx-clock { font-size: 1.5rem; }
    .sx-acts { flex-direction: column; }
    .sx-acts .btn-sm-maroon, .sx-acts .btn-sm-outline { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .sx-dlg[open] { animation: none; }
    .sx-dlg.is-warn .sx-ico { animation: none; }
}
</style>

<dialog class="sx-dlg" id="sxDialog" aria-labelledby="sxTitle">
    <div class="sx-card">
        <div class="sx-ring" aria-hidden="true">
            <span class="material-symbols-outlined sx-ico" id="sxIcon">schedule</span>
        </div>
        <h2 class="sx-title" id="sxTitle"></h2>
        <p class="sx-line" id="sxLine"></p>
        <p class="sx-clock" id="sxClock" role="timer" aria-live="off"></p>
        <div class="sx-acts">
            <button type="button" class="btn-sm-maroon sx-stay" id="sxStay">Stay signed in</button>
            <a class="btn-sm-maroon" id="sxLogin" href="<?= e($stState['login']) ?>" hidden>Go to sign in</a>
            <a class="btn-sm-outline sx-stay" id="sxOut" href="<?= e(BASE_URL) ?>/app/auth/logout.php">Sign out now</a>
        </div>
    </div>
</dialog>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var START = <?= json_encode($stState, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var WARN  = <?= (int)SESSION_WARN_SECONDS ?>;
    var FEED  = <?= json_encode(BASE_URL . '/app/session_status.php') ?>;
    var TOKEN = <?= json_encode(csrf_token()) ?>;
    /* Shared with every other tab of this site, so one countdown is kept
       between them. A request in any tab pushes the deadline back for all of
       them, and a tab that has not noticed would otherwise warn about an
       expiry that is no longer coming. */
    var KEY   = 'papel_session_deadline';

    var dlg   = document.getElementById('sxDialog');
    var title = document.getElementById('sxTitle');
    var line  = document.getElementById('sxLine');
    var clock = document.getElementById('sxClock');
    var icon  = document.getElementById('sxIcon');
    var stay  = document.getElementById('sxStay');
    var login = document.getElementById('sxLogin');
    if (!dlg || !dlg.showModal) { return; }

    /* The server's clock less the device's. Every figure on screen is worked
       out through this, so a wrong system clock cannot end a session early. */
    var skew     = (START.now * 1000) - Date.now();
    var deadline = START.expires * 1000;      // in the server's own milliseconds
    var state    = 'quiet';                   // quiet | warn | over
    var asking   = false;                     // a poll is out
    var ticker   = null;

    function nowMs() { return Date.now() + skew; }
    function left()  { return Math.max(0, Math.round((deadline - nowMs()) / 1000)); }

    function share(at) {
        try { localStorage.setItem(KEY, String(at)); } catch (e) {}
    }
    function setDeadline(at) {
        if (at <= deadline) { return; }        // never bring the end forward on gossip
        deadline = at;
        if (state === 'warn') { quiet(); }     // the warning no longer applies
    }

    /* mm:ss, and "0:04" rather than "0:4". */
    function face(s) {
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    function quiet() {
        state = 'quiet';
        dlg.classList.remove('is-warn', 'is-over');
        if (dlg.open) { dlg.close(); }
    }

    function warn() {
        if (state === 'warn') { return; }
        state = 'warn';
        dlg.classList.add('is-warn');
        dlg.classList.remove('is-over');
        icon.textContent = 'schedule';
        title.textContent = 'Still there?';
        line.textContent  = 'You have not done anything for a while, so PAPEL is about to '
                          + 'sign you out. Anything you have typed and not saved will be lost.';
        clock.hidden = false;
        login.hidden = true;
        paint();
        if (!dlg.open) { dlg.showModal(); }
        /* The button that keeps the session, not the one that ends it: Enter
           on a dialog that has just appeared under somebody's hands must not
           be the destructive answer. */
        try { stay.focus({ preventScroll: true }); } catch (e) { stay.focus(); }
    }

    function over() {
        if (state === 'over') { return; }
        state = 'over';
        dlg.classList.remove('is-warn');
        dlg.classList.add('is-over');
        icon.textContent = 'lock';
        title.textContent = 'Your session has ended';
        line.textContent  = 'You were signed out after '
                          + Math.round(START.window / 60)
                          + ' minutes with nothing happening. Sign in again to carry on.';
        clock.hidden = true;
        login.hidden = false;
        if (!dlg.open) { dlg.showModal(); }
        try { login.focus({ preventScroll: true }); } catch (e) { login.focus(); }
    }

    function paint() {
        if (state !== 'warn') { return; }
        clock.textContent = face(left());
    }

    /* Asks the server, which is the only thing that knows: this page cannot
       see a request made in another tab, and every request there pushes the
       deadline back for this one too. */
    function ask(then) {
        if (asking) { return; }
        asking = true;
        /* papelQuiet: the loading bar wraps fetch and reports every request on
           the site, and a bar flashing across the window of its own accord
           reads as the page doing something. */
        fetch(FEED, { credentials: 'same-origin', papelQuiet: true,
                      headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                asking = false;
                if (!d || !d.ok) { return; }
                skew = (d.now * 1000) - Date.now();
                if (!d.signed_in) { over(); return; }
                deadline = d.expires * 1000;
                share(deadline);
                if (state === 'warn' && left() > WARN) { quiet(); }
                if (then) { then(d); }
            })
            .catch(function () {
                asking = false;
                /* Offline, or the server unreachable. Not an expiry: saying so
                   would sign somebody out over a dropped connection, and the
                   clock carries on either way. */
            });
    }

    /* The one request that is meant to count as activity. A POST, with the
       token, because it changes something on the server. */
    stay.addEventListener('click', function () {
        stay.disabled = true;
        var body = new FormData();
        body.append('_token', TOKEN);
        fetch(FEED, { method: 'POST', body: body, credentials: 'same-origin',
                      headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (d) {
                stay.disabled = false;
                if (!d || !d.ok || !d.signed_in) { over(); return; }
                skew = (d.now * 1000) - Date.now();
                deadline = d.expires * 1000;
                share(deadline);
                quiet();
            })
            .catch(function () {
                stay.disabled = false;
                /* The request failed, so nothing was extended and the warning
                   is still true. Left up rather than closed on a promise that
                   was not kept. */
            });
    });

    /* **Escape and the backdrop close the warning, and neither closes the
       expiry.** A warning is a question, and a question can be waved away; an
       expired session has no page left behind it to go back to, so a dialog
       that closed would leave somebody pressing buttons on a page that answers
       every one of them with the sign-in panel. */
    dlg.addEventListener('cancel', function (e) {
        if (state === 'over') { e.preventDefault(); }
    });
    dlg.addEventListener('click', function (e) {
        if (e.target === dlg && state === 'warn') { dlg.close(); }
    });
    /* Closed by hand, the warning stays dismissed but the clock does not stop:
       the expiry still arrives, and arrives as the pop-up above. */
    dlg.addEventListener('close', function () {
        if (state === 'warn') { state = 'dismissed'; }
    });

    /* Another tab heard from the server, or pushed the deadline back by doing
       something. localStorage is the only channel between them that needs no
       server and no permission. */
    window.addEventListener('storage', function (e) {
        if (e.key !== KEY || !e.newValue) { return; }
        var at = parseInt(e.newValue, 10);
        if (at > 0) { setDeadline(at); }
    });

    function tick() {
        var s = left();

        if (state === 'over') { return; }

        if (s <= 0) {
            /* **The page may reach zero a moment before the server does**, and
               the server is what decides: another tab may have been working all
               along, and this page's own clock is only ever agreed with the
               server's to within a second. So the end is confirmed before it is
               announced, the same rule the manuscript countdown keeps about a
               grant it has counted out. */
            ask(function (d) { if (!d.signed_in) { over(); } });
            return;
        }

        if (s <= WARN) {
            /* Confirm before warning, for the same reason: the deadline this
               page holds may be older than the one the server holds. One ask,
               then the warning if the server agrees there is little left. */
            if (state === 'quiet') {
                ask(function () { if (left() <= WARN) { warn(); } });
            } else {
                paint();
            }
            return;
        }

        /* Far from the end: ask now and then, so a deadline pushed back in
           another tab, or pulled forward by a sign-out there, is noticed
           before the warning would have gone up on a stale figure. A peek
           does not count as activity (PAPEL_SESSION_PEEK), so this cannot be
           the thing that keeps the session alive. */
        if (s % 120 === 0) { ask(); }
    }

    /* A tab left alone has had its timers throttled to a minute at best, so
       the figure is stale the moment it is looked at again: catch up, and ask
       the server, which also answers whether the session really has gone. */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        ask(function (d) { if (!d.signed_in) { over(); } else { tick(); } });
    });

    share(deadline);
    ticker = setInterval(tick, 1000);
    tick();
})();
</script>
