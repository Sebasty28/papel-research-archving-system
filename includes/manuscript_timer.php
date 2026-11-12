<?php
/**
 * The live countdown on a student's unlocked manuscripts, and the two pop-ups
 * that announce one starting and one ending.
 *
 * A grant from a librarian lasts a few hours and then stops working on its own
 * (config/core.php, student_manuscript_access(): lazy expiry, nothing flips the
 * row). Before this, the only way to know how much was left was the line in My
 * Library or on the paper's page, written once when the page was drawn: a
 * reader sitting with a paper open for two hours was still told "3h 0m left",
 * and the file simply stopped opening with no word of why. Worse, a grant
 * signed while the student had the paper open showed nothing at all until they
 * happened to reload.
 *
 * So: a pill in the top right-hand corner counting down by the second, polled
 * every half minute (app/manuscript_grants.php) so a new grant arrives on its
 * own, and a pop-up in the middle of the window when one is unlocked and when
 * one runs out.
 *
 * Pulled in from includes/site_footer.php. It prints nothing at all unless the
 * reader is a student with something to watch: a grant open now, or a request
 * waiting for an answer that could become one while the page is open. With
 * neither, nothing can change without an act that reloads the page (a request
 * is filed by POST and comes back as a new page), so there is nothing to poll
 * for and no markup to carry.
 *
 * Three things here are written down the hard way elsewhere in CLAUDE.md:
 *   - the pill is placed below the navbar *and* the breadcrumb strip, from the
 *     measured --nav-h and --crumb-h that includes/site_header.php keeps, so it
 *     never fights a bar for the same pixels and nothing has to move on scroll;
 *   - no style="..." attribute anywhere (style-src has no 'unsafe-inline', so
 *     they are dropped), and nothing that changes every second is written on
 *     :root, which would restyle the whole page;
 *   - the pop-up is a <dialog>, which needs `margin: auto` stated, because the
 *     site's reset takes away the margin a modal dialog centres itself with.
 */

if (!function_exists('current_user')) { return; }

$msTimerUser = current_user();
if (!$msTimerUser || ($msTimerUser['user_role'] ?? '') !== 'student') { return; }
if (!manuscript_requests_ready()) { return; }

$msTimerUid     = (int)$msTimerUser['user_id'];
$msTimerGrants  = student_manuscript_grants($msTimerUid);
$msTimerPending = student_manuscript_pending_count($msTimerUid);
if (!$msTimerGrants && $msTimerPending < 1) { return; }

/* What the script starts from, so the first second on screen is already right
   and no fetch is needed to draw the pill. `now` is the server's clock: a
   device whose own is ten minutes fast would otherwise count a grant out ten
   minutes early and announce an expiry that has not happened. */
$msTimerState = ['now' => time(), 'pending' => $msTimerPending, 'grants' => []];
foreach ($msTimerGrants as $g) {
    $msTimerState['grants'][] = [
        'id'      => (int)$g['request_id'],
        'paper'   => (int)$g['paper_id'],
        'title'   => (string)($g['paper_title'] ?? 'A paper no longer in the repository'),
        'expires' => (int)strtotime((string)$g['expires_at']),
        'hours'   => (int)$g['duration_hours'],
        'url'     => BASE_URL . '/archive/view_paper.php?id=' . (int)$g['paper_id'] . '#pd-sec-manuscript',
    ];
}
?>
<style nonce="<?= csp_nonce() ?>">
/* ===== The pill ===================================================== */
.ms-timer {
    position: fixed;
    /* Under both bars rather than beside one of them. The two figures are
       measured by includes/site_header.php on load and on any resize; the
       fallbacks are a desktop navbar and no strip, for a page that carries the
       footer without that script. */
    top: calc(var(--nav-h, 56px) + var(--crumb-h, 0px) + .5rem);
    right: .75rem;
    /* Above the page's own content and below every bar and panel on the ladder
       in CLAUDE.md (the navbar at 900, the footer at 902, the PDF panel at
       900), which is why it is placed clear of all of them rather than over
       one. */
    z-index: 890;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .35rem;
    font-family: var(--font-body), 'Inter', sans-serif;
}
.ms-timer[hidden] { display: none; }

/* A docked PDF panel, and the upload page's side pane, both take the right-hand
   side of the window. Read their widths rather than lying over them. */
body.pdf-docked .ms-timer { right: calc(var(--pdf-dock-w, 0px) + .75rem); }
body.panes      .ms-timer { right: calc(var(--room-r, 0px) + .75rem); }

/* Named, read out, and not drawn: the pill's own accessible name, which changes
   by the minute rather than by the second. Clipped rather than display: none,
   which would take it out of the accessibility tree, as the management
   consoles' field hints are. */
.ms-offscreen {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
    border: 0;
}

.ms-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .25rem .5rem .25rem .4rem;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--white);
    color: var(--ink);
    box-shadow: var(--shadow-md);
    cursor: pointer;
    font: inherit;
    font-size: .75rem;
    line-height: 1.2;
    transition: background .15s, border-color .15s, color .15s;
}
.ms-pill:hover { background: color-mix(in srgb, var(--maroon) 7%, var(--white)); }
.ms-pill:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.ms-pill-ico { font-size: 16px; line-height: 1; color: var(--maroon); }
/* Tabular figures, or the whole pill twitches as the digits change width. */
.ms-pill-time {
    font-variant-numeric: tabular-nums;
    font-feature-settings: 'tnum' 1;
    font-weight: 600;
    letter-spacing: .01em;
}
.ms-pill-more {
    font-size: .625rem;
    padding: 0 .3rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--maroon) 12%, transparent);
    color: var(--maroon);
}
.ms-pill-more[hidden] { display: none; }
.ms-pill-chev { font-size: 14px; line-height: 1; opacity: .6; transition: transform .15s; }
.ms-timer.is-open .ms-pill-chev { transform: rotate(180deg); }

/* The last quarter of an hour: the colours a bad flash banner uses, and a
   heartbeat on the icon, because that is the point at which a reader has to
   decide whether to ask again. */
.ms-timer.is-soon .ms-pill {
    background: var(--bad-bg);
    border-color: var(--bad-border);
    color: var(--bad-text);
}
.ms-timer.is-soon .ms-pill-ico { color: var(--bad-text); animation: ms-beat 1.6s ease-in-out infinite; }
.ms-timer.is-soon .ms-pill-more { background: color-mix(in srgb, var(--bad-text) 14%, transparent); color: var(--bad-text); }
@keyframes ms-beat {
    0%, 60%, 100% { transform: scale(1); }
    15%           { transform: scale(1.22); }
    30%           { transform: scale(1); }
    45%           { transform: scale(1.14); }
}

/* ===== The panel under it =========================================== */
.ms-panel {
    width: 16rem;
    max-width: calc(100vw - 1.5rem);
    padding: .6rem .7rem;
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
    box-shadow: var(--shadow-md);
    color: var(--ink);
    font-size: .75rem;
    text-align: left;
}
.ms-timer:not(.is-open) .ms-panel { display: none; }
.ms-panel-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: .5rem;
    margin-bottom: .3rem;
    font-family: var(--font-head), serif;
    font-size: .8125rem;
    color: var(--maroon);
}
.ms-panel-count { font-size: .625rem; color: var(--grey); font-family: var(--font-body), sans-serif; }
.ms-panel-list {
    list-style: none;
    margin: 0 0 .5rem;
    padding: 0;
    /* Three grants is the cap, and three rows fit; the scroll is for a site
       whose cap has been raised. The wheel stops here rather than carrying on
       into the page behind the panel. */
    max-height: 13rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.ms-row {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .35rem 0;
    border-bottom: 1px solid var(--border);
}
.ms-row:last-child { border-bottom: 0; }
.ms-row-main { flex: 1 1 auto; min-width: 0; }
a.ms-panel-title { color: var(--ink); text-decoration: none; }
a.ms-panel-title:hover { color: var(--maroon); text-decoration: underline; }
a.ms-panel-title:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.ms-panel-title {
    margin: 0 0 .1rem;
    font-weight: 600;
    /* Two lines at most: a thesis title is longer than any pill-sized card. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ms-panel-left { margin: 0; color: var(--grey); font-size: .6875rem; }
/* The one running out first, which is the figure on the pill itself. */
.ms-row.is-next .ms-panel-left { color: var(--maroon); font-weight: 600; }
.ms-row-open {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.6rem;
    height: 1.6rem;
    border-radius: 50%;
    background: var(--maroon-surface);
    color: #fff;
    text-decoration: none;
}
.ms-row-open:hover { background: var(--maroon-surface-hover); color: #fff; }
.ms-row-open .material-symbols-outlined { font-size: 15px; }
.ms-panel-acts { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
.ms-panel-acts .btn-sm-maroon,
.ms-panel-acts .btn-sm-outline { font-size: .6875rem; padding: .25rem .6rem; }

/* ===== Smaller on a phone =========================================== */
/* The same pair of queries the accessibility tab uses, which covers every width
   between them, rather than min-width: 641px: a window 640.5px wide (which
   fractional zoom gives) matches neither integer and would fall through both. */
@media (max-width: 640px) {
    .ms-timer { right: .5rem; gap: .25rem; }
    .ms-pill {
        padding: .15rem .4rem .15rem .3rem;
        gap: .25rem;
        font-size: .625rem;
        box-shadow: var(--shadow-sm);
    }
    .ms-pill-ico   { font-size: 13px; }
    .ms-pill-chev  { font-size: 12px; }
    .ms-pill-more  { font-size: .5625rem; }
    .ms-panel      { width: 13.5rem; font-size: .6875rem; padding: .5rem .55rem; }
    .ms-panel-head { font-size: .75rem; }
}

/* ===== The pop-up =================================================== */
.ms-alarm {
    /* Stated, because the site's reset takes away the margin a modal dialog
       centres itself with and it opened in the top left-hand corner. */
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
.ms-alarm::backdrop { background: rgba(51, 0, 0, .42); }
.ms-alarm[open] { animation: ms-rise .18s ease-out; }
@keyframes ms-rise {
    from { opacity: 0; transform: translateY(8px) scale(.97); }
    to   { opacity: 1; transform: none; }
}
.ms-alarm-card { padding: 1.1rem 1.1rem 1rem; text-align: center; }

/* The bell, in a ring that beats out of it the way an alarm's sound does. */
.ms-alarm-ring {
    position: relative;
    width: 3.25rem;
    height: 3.25rem;
    margin: .25rem auto .7rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--bad-bg);
    color: var(--bad-text);
}
.ms-alarm-ring::before,
.ms-alarm-ring::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    border: 2px solid currentColor;
    opacity: 0;
    animation: ms-wave 2s ease-out infinite;
}
.ms-alarm-ring::after { animation-delay: 1s; }
@keyframes ms-wave {
    0%   { opacity: .45; transform: scale(1); }
    100% { opacity: 0;   transform: scale(1.75); }
}
.ms-alarm-ico { font-size: 30px; line-height: 1; }
/* The shake stops after a few seconds: an alarm that rings for ever reads as a
   fault with the page rather than as a notice to act on. */
.ms-alarm.is-ended .ms-alarm-ico { animation: ms-shake .55s ease-in-out 5; transform-origin: 50% 15%; }
@keyframes ms-shake {
    0%, 100% { transform: rotate(0); }
    15%      { transform: rotate(-14deg); }
    30%      { transform: rotate(12deg); }
    45%      { transform: rotate(-9deg); }
    60%      { transform: rotate(7deg); }
    75%      { transform: rotate(-4deg); }
}
/* The site's own maroon, not a green: good news here is still PAPEL telling a
   student something, and the one green circle on the page read as a different
   product. Mixed with the card's own white rather than --maroon-soft, so the
   ring stays a tint of the accent in either palette. */
.ms-alarm.is-granted .ms-alarm-ring {
    background: color-mix(in srgb, var(--maroon) 12%, var(--white));
    color: var(--maroon);
}
.ms-alarm.is-granted .ms-alarm-ico  { animation: ms-pop .5s cubic-bezier(.2, 1.4, .4, 1); }
@keyframes ms-pop {
    from { transform: scale(.4); opacity: 0; }
    to   { transform: scale(1); opacity: 1; }
}
.ms-alarm-title {
    margin: 0 0 .35rem;
    font-family: var(--font-head), serif;
    font-size: 1.0625rem;
    color: var(--maroon);
}
.ms-alarm.is-ended .ms-alarm-title { color: var(--bad-text); }
.ms-alarm-paper {
    margin: 0 0 .35rem;
    font-weight: 600;
    font-size: .8125rem;
    /* Three lines at most. A thesis title set in capitals ran to five and
       pushed the sentence that says what has happened below the fold of a
       phone, which is the one line that has to be read. */
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ms-alarm-line  { margin: 0 0 .9rem; font-size: .75rem; color: var(--grey); line-height: 1.45; }
.ms-alarm-clock {
    display: inline-block;
    margin: 0 0 .9rem;
    padding: .2rem .55rem;
    border-radius: var(--r-control, 4px);
    background: color-mix(in srgb, var(--maroon) 10%, transparent);
    color: var(--maroon);
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    font-size: .8125rem;
}
.ms-alarm-clock[hidden] { display: none; }
.ms-alarm-acts { display: flex; justify-content: center; flex-wrap: wrap; gap: .4rem; }

@media (max-width: 640px) {
    .ms-alarm-card  { padding: .9rem .85rem .85rem; }
    .ms-alarm-ring  { width: 2.75rem; height: 2.75rem; }
    .ms-alarm-ico   { font-size: 26px; }
    .ms-alarm-title { font-size: .9375rem; }
    .ms-alarm-line  { font-size: .6875rem; }
}

/* Reduced motion keeps the colours and the words and drops every loop: the
   message is what matters, not the ringing. */
@media (prefers-reduced-motion: reduce) {
    .ms-timer.is-soon .ms-pill-ico,
    .ms-alarm-ring::before,
    .ms-alarm-ring::after,
    .ms-alarm.is-ended .ms-alarm-ico,
    .ms-alarm.is-granted .ms-alarm-ico,
    .ms-alarm[open] { animation: none; }
}
</style>

<div class="ms-timer" id="msTimer" hidden>
    <button type="button" class="ms-pill" id="msPill" aria-expanded="false" aria-controls="msPanel">
        <span class="material-symbols-outlined ms-pill-ico" id="msPillIco" aria-hidden="true">lock_open</span>
        <?php /* aria-hidden, with the button named by the clipped label below:
                 a live region ticking once a second would be read out for
                 ever. */ ?>
        <span class="ms-pill-time" id="msPillTime" aria-hidden="true">--:--</span>
        <span class="ms-pill-more" id="msPillMore" hidden aria-hidden="true"></span>
        <span class="material-symbols-outlined ms-pill-chev" aria-hidden="true">expand_more</span>
        <span class="ms-offscreen" id="msPillLabel">Manuscript access</span>
    </button>
    <div class="ms-panel" id="msPanel" role="group" aria-labelledby="msPanelHead">
        <div class="ms-panel-head">
            <span id="msPanelHead">Manuscript unlocked</span>
            <span class="ms-panel-count" id="msPanelCount"></span>
        </div>
        <?php /* One row per grant, not just the one running out first: a reader
                 holding three of them was told the time left on one and the count
                 of the others, which is the panel knowing something it will not
                 say. Up to three is the cap a librarian can grant at once, so the
                 list is short; it scrolls anyway, since the cap is a setting. */ ?>
        <ul class="ms-panel-list" id="msPanelList"></ul>
        <div class="ms-panel-acts">
            <a class="btn-sm-outline" href="<?= e(BASE_URL) ?>/app/my_library.php?tab=requests">My requests</a>
        </div>
    </div>
</div>

<dialog class="ms-alarm" id="msAlarm" aria-labelledby="msAlarmTitle">
    <div class="ms-alarm-card">
        <div class="ms-alarm-ring" aria-hidden="true">
            <span class="material-symbols-outlined ms-alarm-ico" id="msAlarmIco">alarm</span>
        </div>
        <h2 class="ms-alarm-title" id="msAlarmTitle"></h2>
        <p class="ms-alarm-paper" id="msAlarmPaper"></p>
        <p class="ms-alarm-line" id="msAlarmLine"></p>
        <p class="ms-alarm-clock" id="msAlarmClock" hidden></p>
        <div class="ms-alarm-acts">
            <a class="btn-sm-maroon" id="msAlarmGo" href="#"></a>
            <button type="button" class="btn-sm-outline" id="msAlarmClose">Close</button>
        </div>
    </div>
</dialog>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var START = <?= json_encode($msTimerState, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var FEED  = <?= json_encode(BASE_URL . '/app/manuscript_grants.php') ?>;
    var LIB   = <?= json_encode(BASE_URL . '/app/my_library.php?tab=requests') ?>;

    var box  = document.getElementById('msTimer');
    var pill = document.getElementById('msPill');
    var dlg  = document.getElementById('msAlarm');
    if (!box || !pill) { return; }

    /* The server's clock less the device's. Every figure on screen is worked
       out through this, so a wrong system clock cannot end a grant early. */
    var skew    = (START.now * 1000) - Date.now();
    var grants  = START.grants.slice();
    var pending = START.pending | 0;
    /* Seeded with what the page was drawn holding, so arriving on a page where
       a grant is already open is not announced as one just granted. */
    var known = {};
    grants.forEach(function (g) { known[g.id] = true; });

    /* Grants this page has already counted out. The two clocks agree to within
       a second at best (the server's time() has no fraction, and the device's
       own may drift between polls), so a grant can run out here a moment before
       the server's `expires_at > NOW()` stops returning it: the next poll then
       handed the row back, the pill showed it again, and a second later it
       "ended" a second time, with a second pop-up behind the one just closed.
       An id ended here is finished for good; asking again is a new row with a
       new id. */
    var finished = {};

    var queue   = [];       // pop-ups waiting their turn
    var showing = null;     // the one on screen
    var stale   = false;    // this page's own words are now out of date
    var polling = null;
    var SOON    = 15 * 60 * 1000;
    var FOLD    = 'papel.msTimerOpen';

    function nowMs() { return Date.now() + skew; }
    function pad(n)  { return n < 10 ? '0' + n : '' + n; }

    /** 2:59:41, or 59:41 inside the last hour: a clock, for the pill. */
    function clock(ms) {
        var s = Math.max(0, Math.round(ms / 1000));
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
        return h > 0 ? h + ':' + pad(m) + ':' + pad(x) : pad(m) + ':' + pad(x);
    }

    /** The same figure in words, for a sentence: "2h 59m left". */
    function words(ms) {
        var s = Math.max(0, Math.round(ms / 1000));
        if (s <= 0) { return 'ended'; }
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
        if (h > 0) { return h + 'h ' + m + 'm left'; }
        if (m > 0) { return m + 'm ' + pad(x) + 's left'; }
        return x + 's left';
    }

    function hours(n) { return n + ' hour' + (n === 1 ? '' : 's'); }

    /* ---- the pill and its panel ---- */

    var ico    = document.getElementById('msPillIco');
    var face   = document.getElementById('msPillTime');
    var more   = document.getElementById('msPillMore');
    var label  = document.getElementById('msPillLabel');
    var pList  = document.getElementById('msPanelList');
    var pHead  = document.getElementById('msPanelHead');
    var pCount = document.getElementById('msPanelCount');
    /* The rows now on the panel, by grant id, so a tick writes the time into
       the row it belongs to rather than building the list again every second. */
    var rows   = {};
    var drawn  = '';      // the ids and expiries the list was built from

    function paint() {
        rowsUnlocked();
        if (!grants.length) {
            box.hidden = true;
            box.classList.remove('is-open', 'is-soon');
            return;
        }
        var g    = grants[0];                       // soonest to run out
        var left = (g.expires * 1000) - nowMs();
        var soon = left <= SOON;

        box.hidden = false;
        box.classList.toggle('is-soon', soon);
        ico.textContent  = soon ? 'alarm' : 'lock_open';
        face.textContent = clock(left);
        /* Rounded up to the minute: the accessible name then changes once a
           minute rather than once a second, which is the difference between a
           label and an interruption. */
        label.textContent = 'Manuscript access: ' + words(Math.ceil(left / 60000) * 60000)
                          + (grants.length > 1 ? ', ' + grants.length + ' unlocked' : '');
        pill.title  = g.title + ' (' + words(left) + ')';
        more.hidden = grants.length < 2;
        if (grants.length > 1) { more.textContent = '+' + (grants.length - 1); }

        pHead.textContent  = grants.length > 1 ? 'Manuscripts unlocked' : 'Manuscript unlocked';
        pCount.textContent = grants.length > 1 ? grants.length + ' unlocked' : '';
        paintList();
    }

    /**
     * The panel's list: one row per grant, soonest first.
     *
     * Built only when the grants themselves change (their ids and their
     * expiries, since a librarian can shorten one), and from then on each tick
     * writes the time into the row it belongs to. Rebuilding three rows a
     * second would also throw away the row under the pointer every time.
     */
    function paintList() {
        var key = grants.map(function (g) { return g.id + ':' + g.expires; }).join('|');
        if (key !== drawn) {
            drawn = key;
            rows  = {};
            pList.textContent = '';
            grants.forEach(function (g) {
                var li = document.createElement('li');
                li.className = 'ms-row';

                var main = document.createElement('div');
                main.className = 'ms-row-main';
                /* The name is the way to the paper, as a title is everywhere
                   else on the site: the round button beside it opens the same
                   page, and a line of text that is plainly a paper and plainly
                   not pressable is a dead end next to a button that works. */
                var title = document.createElement('a');
                title.className = 'ms-panel-title';
                title.href = g.url;
                title.textContent = g.title;           // somebody's typing, as text
                var left = document.createElement('p');
                left.className = 'ms-panel-left';
                main.appendChild(title);
                main.appendChild(left);

                var open = document.createElement('a');
                open.className = 'ms-row-open';
                open.href = g.url;
                open.title = 'Open this manuscript';
                open.setAttribute('aria-label', 'Open ' + g.title);
                var mi = document.createElement('span');
                mi.className = 'material-symbols-outlined';
                mi.setAttribute('aria-hidden', 'true');
                mi.textContent = 'menu_book';
                open.appendChild(mi);

                li.appendChild(main);
                li.appendChild(open);
                pList.appendChild(li);
                rows[g.id] = { li: li, left: left };
            });
        }
        grants.forEach(function (g, i) {
            var row = rows[g.id];
            if (!row) { return; }
            var left = (g.expires * 1000) - nowMs();
            row.left.textContent = g.hours
                ? words(left) + ' of ' + hours(g.hours) + ' of access'
                : words(left);
            row.li.classList.toggle('is-next', i === 0 && grants.length > 1);
        });
    }

    function setOpen(on) {
        box.classList.toggle('is-open', on);
        pill.setAttribute('aria-expanded', on ? 'true' : 'false');
        try { localStorage.setItem(FOLD, on ? '1' : '0'); } catch (e) {}
    }

    pill.addEventListener('click', function () {
        setOpen(!box.classList.contains('is-open'));
    });
    /* Pressed anywhere else: the panel is a detail, not a dialog. */
    document.addEventListener('click', function (e) {
        if (!box.classList.contains('is-open')) { return; }
        if (box.contains(e.target)) { return; }
        setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && box.classList.contains('is-open')) { setOpen(false); }
    });
    try { if (localStorage.getItem(FOLD) === '1') { setOpen(true); } } catch (e) {}

    /* ---- the pop-up ---- */

    var aTitle = document.getElementById('msAlarmTitle');
    var aPaper = document.getElementById('msAlarmPaper');
    var aLine  = document.getElementById('msAlarmLine');
    var aClock = document.getElementById('msAlarmClock');
    var aIco   = document.getElementById('msAlarmIco');
    var aGo    = document.getElementById('msAlarmGo');
    var aClose = document.getElementById('msAlarmClose');

    function announce(kind, g) {
        queue.push({ kind: kind, g: g });
        next();
    }

    /**
     * Whether the page under the pop-up is one this news has just made wrong.
     *
     * The paper's own page is the one that cannot be put right in place: what
     * changes there is whether the file itself is offered, which is the card
     * the server builds, the viewer it hands the file to and the access check
     * behind both. So that page, and only for the paper the news is about, is
     * drawn again once the pop-up has been read.
     *
     * My Library is *not* reloaded although it says as much about a request:
     * its rows are brought up to date where they stand (rowsUnlocked(),
     * rowEnded()), and a page that can correct itself should not blink.
     */
    function pageAbout(g) {
        var path = location.pathname;
        if (/\/archive\/view_paper\.php$/.test(path)) {
            var m = location.search.match(/[?&]id=(\d+)/);
            return !!m && parseInt(m[1], 10) === g.paper;
        }
        return false;
    }

    /* One at a time, the rest when this one is closed: three grants ending in
       the same minute is three notices, not three dialogs fighting for the top
       layer. */
    function next() {
        if (showing || !queue.length || !dlg || !dlg.showModal) { return; }
        var item = queue.shift();
        var g    = item.g;
        showing  = item;

        /* Three kinds, two faces: unlocked, and access that has stopped, which
           is either the clock running out or a librarian ending it. The last
           two look the same (an alarm, the bad-flash colours) because to the
           reader the file has locked either way, and read differently because
           "your time is up" over access that was in fact taken back is the
           site telling a student something untrue. */
        var ended   = item.kind !== 'granted';
        var revoked = item.kind === 'revoked';
        dlg.classList.toggle('is-ended', ended);
        dlg.classList.toggle('is-granted', !ended);
        aIco.textContent   = revoked ? 'lock' : (ended ? 'alarm' : 'lock_open');
        aTitle.textContent = revoked ? 'Manuscript access revoked'
                           : (ended ? 'Manuscript request ended' : 'Manuscript unlocked');
        aPaper.textContent = g.title || '';
        aLine.textContent  = revoked
            ? 'A librarian has ended your access to this manuscript before its time was up, and the file is locked again. Everything the authors wrote is still on the paper’s page, and you can ask again.'
            : (ended
                ? 'Your access to this manuscript has ended, and the file is locked again. Everything the authors wrote is still on the paper’s page, and you can ask a librarian again.'
                : 'A librarian has unlocked this manuscript for you'
                  + (g.hours ? ' for ' + hours(g.hours) : '') + '.');
        aClock.hidden   = ended;
        aGo.textContent = ended ? 'Ask again' : 'Open manuscript';
        aGo.href        = g.url || LIB;
        if (pageAbout(g)) { stale = true; }
        tickAlarm();
        dlg.showModal();
        /* The pop-up is the one thing worth reading just then, so the panel
           behind it is folded away. */
        setOpen(false);
    }

    function tickAlarm() {
        if (!showing || showing.kind === 'ended') { return; }
        aClock.textContent = words((showing.g.expires * 1000) - nowMs());
    }

    if (dlg) {
        dlg.addEventListener('close', function () {
            showing = null;
            if (queue.length) { next(); return; }   // the rest first, then the page
            if (stale) { location.reload(); }
        });
        aClose.addEventListener('click', function () { dlg.close(); });
    }

    /* ---- the request rows in My Library, rewritten where they stand ---- */
    /* My Library lists every request in words the server wrote. A grant signed
       while that page is open left it saying "Waiting for a librarian" over a
       manuscript that was by then unlocked, and the reader had to reload to
       find out. These rows carry the hooks to be brought up to date in place
       (data-ms-row, -ico, -state, -act), so nothing is reloaded and nothing is
       stale. */

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                  'August', 'September', 'October', 'November', 'December'];
    var ENDED_LINE   = 'Access to this manuscript has ended. You can ask a librarian again.';
    var REVOKED_LINE = 'A librarian ended this access before it was due to run out. You can ask again.';

    /** "November 13, 2026 3:28 AM": PHP's 'F j, Y g:i A', which wrote the rows. */
    function stamp(ms) {
        var d = new Date(ms);
        var h = d.getHours();
        var ampm = h < 12 ? 'AM' : 'PM';
        h = h % 12; if (h === 0) { h = 12; }
        return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear()
             + ' ' + h + ':' + pad(d.getMinutes()) + ' ' + ampm;
    }

    function rowFor(paperId) {
        return document.querySelector('[data-ms-row="' + paperId + '"]');
    }

    /** Every row whose paper is unlocked now, said as an unlocked one. */
    function rowsUnlocked() {
        grants.forEach(function (g) {
            var li = rowFor(g.paper);
            /* data-ms-live is the grant the row has already been told about, so
               this is the work of one attribute read per second, not a rebuild.
               The expiry is part of it: a librarian can change how long a grant
               runs, and a row written from the old figure would go on counting
               down to a time that is no longer the one on file. */
            var tag = g.id + ':' + g.expires;
            if (!li || li.getAttribute('data-ms-live') === tag) { return; }
            li.setAttribute('data-ms-live', tag);

            var ico = li.querySelector('[data-ms-ico]');
            if (ico) { ico.textContent = 'lock_open'; }

            var state = li.querySelector('[data-ms-state]');
            if (state) {
                state.setAttribute('data-ms-ended', ENDED_LINE);
                state.textContent = '';
                var strong = document.createElement('strong');
                strong.textContent = 'Unlocked';
                var count = document.createElement('span');
                count.setAttribute('data-ms-until', g.expires);
                count.textContent = words((g.expires * 1000) - nowMs());
                state.appendChild(strong);
                state.appendChild(document.createTextNode(' until ' + stamp(g.expires * 1000) + ' ('));
                state.appendChild(count);
                state.appendChild(document.createTextNode(').'));
            }

            var act = li.querySelector('[data-ms-act]');
            if (act && !act.querySelector('a')) {
                /* Built from elements, never from a string of markup: the row's
                   address is the page's own and the words are ours, but this is
                   the same rule the file indicator on the upload form follows. */
                var a = document.createElement('a');
                a.className = 'btn-sm-maroon';
                a.href = li.getAttribute('data-ms-href') || g.url;
                var mi = document.createElement('span');
                mi.className = 'material-symbols-outlined mi-18';
                mi.setAttribute('aria-hidden', 'true');
                mi.textContent = 'menu_book';
                a.appendChild(mi);
                a.appendChild(document.createTextNode(' Open manuscript'));
                act.appendChild(a);
            }
        });
    }

    /**
     * One that has just ended: the icon, the button and the sentence.
     *
     * The sentence is swapped here rather than left to inPage(), which only
     * acts when a countdown reaches zero: a grant the librarian deleted ends
     * with its own expiry still in the future, and the row would otherwise go
     * on saying "Unlocked until midnight" over a manuscript that has locked.
     */
    function rowEnded(g, revoked) {
        var li = rowFor(g.paper);
        if (!li) { return; }
        li.removeAttribute('data-ms-live');
        var ico = li.querySelector('[data-ms-ico]');
        if (ico) { ico.textContent = revoked ? 'lock' : 'lock_clock'; }
        var act = li.querySelector('[data-ms-act]');
        if (act) { act.textContent = ''; }
        var state = li.querySelector('[data-ms-state]');
        if (state) {
            state.textContent = revoked
                ? REVOKED_LINE
                : (state.getAttribute('data-ms-ended') || ENDED_LINE);
        }
    }

    /* ---- countdowns written into the page itself ---- */
    /* Any element carrying data-ms-until="<epoch>" is kept in words by this
       same clock: the request rows in My Library, the note on a paper's page.
       One timer for the lot, rather than each page starting its own. */
    function inPage() {
        var list = document.querySelectorAll('[data-ms-until]');
        for (var i = 0; i < list.length; i++) {
            var el   = list[i];
            var at   = parseInt(el.getAttribute('data-ms-until'), 10) || 0;
            var left = (at * 1000) - nowMs();
            if (left > 0) { el.textContent = words(left); continue; }
            el.textContent = 'ended';
            /* The sentence around it was written in the present tense, and
               "Unlocked until 10:30 PM (ended)" is not a sentence. Where the
               page has said what it should read instead, say that, once. */
            var line = el.closest ? el.closest('[data-ms-ended]') : null;
            if (line) {
                line.textContent = line.getAttribute('data-ms-ended');
            }
        }
    }

    /* ---- the clock ---- */

    function tick() {
        var live = [];
        var gone = [];
        grants.forEach(function (g) {
            if ((g.expires * 1000) - nowMs() > 0) { live.push(g); } else { gone.push(g); }
        });
        if (gone.length) {
            grants = live;
            gone.forEach(function (g) {
                finished[g.id] = true;
                rowEnded(g);
                announce('ended', g);
            });
            /* The row still says 'granted' on the server until it is asked
               again; ask, so a second grant signed meanwhile is picked up in
               the same breath. */
            poll();
        }
        paint();
        tickAlarm();
        inPage();
        rest();
    }

    /* Nothing more can arrive once there is no grant and nothing waiting: a
       request is filed by a POST that comes back as a new page, which draws
       all of this again. So the polling stops rather than running all day. */
    function rest() {
        if (grants.length || pending > 0) { return; }
        if (polling) { clearInterval(polling); polling = null; }
    }

    function poll() {
        /* papelQuiet: the loading bar wraps fetch and reports every request on
           the site, and a bar flashing across the window every half minute of
           its own accord reads as the page doing something. */
        fetch(FEED, {
            credentials: 'same-origin',
            papelQuiet: true,
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                skew    = (d.now * 1000) - Date.now();
                pending = d.pending | 0;
                var fresh = (d.grants || []).filter(function (g) { return !finished[g.id]; });
                fresh.forEach(function (g) {
                    if (!known[g.id]) {
                        known[g.id] = true;
                        announce('granted', g);
                    }
                });
                /* A grant the server no longer lists has ended, and not always
                   by the clock: the librarian's desk can delete a grant, which
                   ends the access there and then. Without this the pill simply
                   stopped showing it, the row went on counting down to a time
                   that no longer meant anything, and nothing said why the file
                   had stopped opening. Said in the same words as a grant that
                   ran its course, because to the reader it is the same event. */
                var live = {};
                fresh.forEach(function (g) { live[g.id] = true; });
                grants.forEach(function (g) {
                    if (live[g.id] || finished[g.id]) { return; }
                    finished[g.id] = true;
                    /* Gone with time still on it is a librarian taking it back,
                       not a grant running its course, and the two are not the
                       same news: "your hour is up" over access that was in fact
                       revoked is the site telling a student something untrue.
                       A second of slack, because the two clocks are only ever
                       agreed to within the server's whole second. */
                    var early = (g.expires * 1000) - nowMs() > 1000;
                    rowEnded(g, early);
                    announce(early ? 'revoked' : 'ended', g);
                });
                grants = fresh;
                paint();
                rest();
            })
            .catch(function () { /* offline, or the session has ended: the clock carries on */ });
    }

    /* A tab left for an hour has had its timers throttled to a minute at best,
       so the figure on screen is stale the moment it is looked at again: catch
       up, and ask the server, which also answers whether a grant that appears
       to have ended really did. */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        tick();
        poll();
    });

    paint();
    inPage();
    setInterval(tick, 1000);
    polling = setInterval(poll, 30000);
    rest();
})();
</script>
