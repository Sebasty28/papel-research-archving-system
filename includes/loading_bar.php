<?php
/**
 * A thin bar across the foot of the window that runs while the site is busy.
 *
 * The point is to answer "did my click do anything?" during the gap where the
 * page still looks finished but the server has not answered yet — leaving a
 * page, submitting a form, waiting on an extraction. It is indeterminate on
 * purpose: nothing here can honestly say how far along a request is, so the
 * segment simply travels rather than pretending to measure.
 *
 * It counts work rather than toggling a flag, so two things running at once
 * cannot have the first one to finish switch the bar off while the second is
 * still going.
 *
 * Two timings keep it from being a distraction:
 *   - it waits a moment before appearing, so anything that finishes quickly
 *     never flashes a bar at all;
 *   - once shown it stays for a short minimum, so a request that lands just
 *     after that moment does not blink.
 *
 * fetch and XMLHttpRequest are wrapped here rather than at each call site, so
 * every existing AJAX request on the site — the archive search, the notification
 * bell, drafts, the AI extraction — reports without being edited.
 *
 * Page code can also drive it directly for work that is neither:
 *     window.papelLoading.start();  ...  window.papelLoading.done();
 *
 * ---- The logo pill ----------------------------------------------------------
 * A small pill playing the PAPEL logo animation — a cropped, 300px copy of the
 * full-screen splash's GIF (includes/splash.php), which is a 1920x1080 canvas
 * with the logo in a third of it. It is not tied to the bar. It marks the
 * site's workflows — signing in, uploading or submitting a paper, requesting a
 * manuscript or support, creating, editing, enabling, resetting or deleting an
 * account or record, approving or returning a paper, granting or denying a
 * request, archiving — and nothing else: not moving between pages, not the
 * background requests the bar also reports.
 *
 * Every one of those workflows is a POST form, and nothing else on the site
 * is, bar three that are marked data-no-pill: the two exports, which are
 * downloads, and Mark all as read. So a POST form going out starts the pill,
 * however it is sent — the submit event for an ordinary button, and a wrapped
 * HTMLFormElement.prototype.submit for the forms action_dialogs.php sends
 * after its confirmation, which never fire that event. Workflows that run
 * as background requests instead — the paper upload, the AI extraction,
 * saving a draft, deleting a notification — drive it by hand:
 *     window.papelLoading.work.start();  ...  window.papelLoading.work.done();
 * with work.leave() in place of done() when the page moves on afterwards.
 *
 * Signing in is the exception: those forms carry data-splash and put up the
 * full-screen animation (includes/splash.php) instead, since that is the site
 * opening rather than a change being saved.
 *
 * Once it appears it stays at least 1.5s, even when the work is already done.
 * A form usually navigates sooner than that, which would take the pill with
 * the page, so the time it owes is left in sessionStorage and the next page
 * shows it for the remainder.
 *
 * Included once from site_footer.php, which every page carries, and directly
 * from the two stand-alone sign-in pages, which carry no footer.
 */
$loader_badge_gif = (defined('BASE_URL') ? BASE_URL : '/capstone')
                  . '/assests/images/Logo-Loading-PAPEL-badge.gif';
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.papel-loader {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    /* Over everything, the start-up splash included: the first page load is
       exactly when there is something to report, and a bar hidden behind the
       splash reports it to nobody. */
    z-index: 2147483100;    /* the splash sits at 2147483000 */
    pointer-events: none;        /* it is an indicator, never a target */
    overflow: hidden;
    background: var(--border);
    opacity: 0;
    visibility: hidden;
    transition: opacity .2s ease, visibility .2s;
}
.papel-loader.is-busy {
    opacity: 1;
    visibility: visible;
}
.papel-loader__seg {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 100%;
    transform-origin: 0 50%;
    border-radius: var(--r-badge, 2px);
    /* Solid rather than fading in from a pale end: at three pixels tall a
       gradient that starts light reads as a smudge instead of a bar. The
       lighter middle is only a sheen, so the travel is easy to follow. */
    background: linear-gradient(90deg,
                var(--dark-maroon) 0%,
                var(--maroon) 50%,
                var(--dark-maroon) 100%);
}
/* Only animate while it is on screen: an animation left running on a hidden
   element keeps waking the compositor for nothing. */
.papel-loader.is-busy .papel-loader__seg {
    animation: papel-loader-run 1.1s cubic-bezier(.65, .02, .35, 1) infinite;
}
@keyframes papel-loader-run {
    0%   { transform: translateX(-45%) scaleX(.45); }
    100% { transform: translateX(145%) scaleX(.45); }
}

/* The logo animation, in the middle of the window, where the eye already is
   after pressing a button — down by the bar it was easy to miss. Its
   background is the GIF's own off-white, so the frame has no visible edge
   inside the pill. It never takes pointer events, so sitting over the page
   cannot block a click. */
.papel-loader-badge {
    position: fixed;
    left: 50%;
    /* A page that puts a loader of its own in the middle of the window — the
       upload overlay is the one that does — moves the pill out of its way by
       setting --papel-pill-top to where the pill's centre should sit. */
    top: var(--papel-pill-top, 50%);
    z-index: 20100;
    padding: 6px 16px;
    border-radius: 999px;
    background: #FDFBFB;
    box-shadow: 0 6px 20px rgba(51, 0, 0, .16);
    pointer-events: none;
    opacity: 0;
    visibility: hidden;
    transform: translate(-50%, calc(-50% + 8px));
    transition: opacity .2s ease, transform .2s ease, visibility .2s;
}
.papel-loader-badge img { display: block; width: 130px; height: auto; }
.papel-loader-badge.is-working {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, -50%);
}

/* Someone who has asked for less movement still needs to know it is working,
   so the bar stays and breathes instead of travelling. A GIF cannot be paused
   from CSS, so the logo is left out altogether, and for the site's own "Stop
   animations" setting too — its animation:none does not reach an image. */
@media (prefers-reduced-motion: reduce) {
    .papel-loader.is-busy .papel-loader__seg {
        animation: papel-loader-breathe 1.6s ease-in-out infinite;
        transform: none;
    }
    @keyframes papel-loader-breathe {
        0%, 100% { opacity: .35; }
        50%      { opacity: 1; }
    }
    .papel-loader-badge { display: none; }
}
body.a11y-stop-animations .papel-loader-badge { display: none; }
</style>

<div class="papel-loader" id="papelLoader" role="status" aria-live="polite"
     aria-label="Loading">
    <div class="papel-loader__seg"></div>
</div>
<div class="papel-loader-badge" id="papelWorkBadge" aria-hidden="true">
    <img src="<?= htmlspecialchars($loader_badge_gif, ENT_QUOTES, 'UTF-8') ?>" alt="" width="300" height="99">
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var el = document.getElementById('papelLoader');
    if (!el) { return; }

    var jobs = 0;               // how many things are in flight
    var showTimer = null;       // waiting to appear
    var hideTimer = null;       // waiting out the minimum
    var shownAt = 0;
    var APPEAR_AFTER = 120;     // ms of waiting before it is worth showing
    var STAY_AT_LEAST = 320;    // ms on screen once shown, so it cannot blink

    function paint(on) {
        el.classList.toggle('is-busy', on);
    }

    function start() {
        jobs++;
        if (jobs > 1 || showTimer || el.classList.contains('is-busy')) { return; }
        clearTimeout(hideTimer);
        showTimer = setTimeout(function () {
            showTimer = null;
            shownAt = Date.now();
            paint(true);
        }, APPEAR_AFTER);
    }

    function done() {
        jobs = jobs > 0 ? jobs - 1 : 0;
        if (jobs > 0) { return; }
        if (showTimer) {          // finished before it ever appeared
            clearTimeout(showTimer);
            showTimer = null;
            return;
        }
        var owed = Math.max(0, STAY_AT_LEAST - (Date.now() - shownAt));
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            if (jobs === 0) { paint(false); }
        }, owed);
    }

    function reset() {
        jobs = 0;
        clearTimeout(showTimer); showTimer = null;
        clearTimeout(hideTimer); hideTimer = null;
        paint(false);
    }

    window.papelLoading = { start: start, done: done, reset: reset };

    // ---- the logo pill: workflows only (see the note at the top) -----------
    var badge = document.getElementById('papelWorkBadge');
    var WORK_MIN = 1500;                 // ms required on screen once shown
    var OWED_KEY = 'papel_work_until';   // when a page that navigated away owed it until
    var workJobs = 0;
    var workShownAt = 0;
    var workHideTimer = null;
    var leaving = false;

    function workShow() {
        if (!badge) { return; }
        clearTimeout(workHideTimer);
        workHideTimer = null;
        if (!badge.classList.contains('is-working')) {
            workShownAt = Date.now();
            badge.classList.add('is-working');
        }
    }
    function workHideAt(when) {
        clearTimeout(workHideTimer);
        workHideTimer = setTimeout(function () {
            if (workJobs === 0 && badge) { badge.classList.remove('is-working'); }
        }, Math.max(0, when - Date.now()));
    }
    function workStart() {
        workJobs++;
        workShow();
    }
    function workDone() {
        workJobs = workJobs > 0 ? workJobs - 1 : 0;
        if (workJobs === 0) { workHideAt(workShownAt + WORK_MIN); }
    }
    /* A workflow form sent: shown now, and — since the page it belongs to is
       about to go — never finished here. What is left of its minimum goes to
       the next page instead. */
    function workLeaving() {
        if (leaving) { return; }
        leaving = true;
        workStart();
        try { sessionStorage.setItem(OWED_KEY, String(workShownAt + WORK_MIN)); } catch (err) {}
    }

    /* leave() is for a workflow sent in the background that then moves to
       another page itself, like the paper upload: done() there would start the
       minimum on a page that is about to vanish. */
    window.papelLoading.work = { start: workStart, done: workDone, leave: workLeaving };

    // The remainder a workflow on the previous page still owes.
    try {
        var owedUntil = +sessionStorage.getItem(OWED_KEY);
        sessionStorage.removeItem(OWED_KEY);
        var owed = owedUntil - Date.now();
        if (owed > 0 && owed <= WORK_MIN) {
            workShow();
            workShownAt = owedUntil - WORK_MIN;
            workHideAt(owedUntil);
        }
    } catch (err) {}

    /* Signing in is the one workflow that gets the full-screen animation
       (includes/splash.php) rather than the pill — it is the site opening, not
       a change being saved. The splash declines when a reduced-motion setting
       is on, and the pill stands in. */
    function leavingFor(form) {
        if (form.hasAttribute('data-splash') && window.papelSplash && window.papelSplash.show()) {
            leaving = true;      // the pill must not follow it onto the next page
            return;
        }
        workLeaving();
    }

    function isWorkflowForm(form, submitter) {
        if (!form || form.hasAttribute('data-no-pill')) { return false; }
        var method = (submitter && submitter.getAttribute('formmethod'))
                  || form.getAttribute('method') || 'get';
        if (method.toLowerCase() !== 'post') { return false; }
        // Sent into another window, this page is not the one waiting on it.
        var target = (submitter && submitter.getAttribute('formtarget'))
                  || form.getAttribute('target') || '';
        return target === '' || target === '_self';
    }

    document.addEventListener('submit', function (e) {
        if (!isWorkflowForm(e.target, e.submitter)) { return; }
        /* Decided once every other listener has had its turn: a form a page
           stops in order to send it by fetch is not leaving, and a check made
           now could run before the listener that stops it. */
        setTimeout(function () {
            if (!e.defaultPrevented) { leavingFor(e.target); }
        }, 0);
    });

    /* form.submit() fires no submit event, and it is how action_dialogs.php
       sends a form once its confirmation is answered — every delete, archive
       and account change on the site goes that way. */
    var nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (isWorkflowForm(this, null)) { leavingFor(this); }
        return nativeSubmit.apply(this, arguments);
    };

    /* The rest of this page load. The footer runs before stylesheets, images
       and the fonts have necessarily finished, so there is usually still
       something to wait for. */
    if (document.readyState !== 'complete') {
        start();
        window.addEventListener('load', done, { once: true });
    }

    /* Leaving the page. This covers every kind of navigation — a link, a form
       post, the Back button, a typed address — so no click handler has to be
       attached to anything. The bar then runs on the old page for as long as
       the next one takes to answer, which is exactly the wait being reported.

       A form whose response turns out to be a file download (Export CSV, on
       the review desks) fires this exactly the same as a real navigation —
       the browser commits to leaving before it has seen the response's
       Content-Disposition header — and then cancels the navigation once it
       finds out, downloading the file and leaving this same page, and this
       same script, running. Nothing tells this page that happened, so the
       start() this fired never gets the done() it is owed and the bar runs
       forever — which is the bug this was reported as. The timeout below is
       the fallback for exactly that case: a real navigation destroys this
       whole page, and the timer with it, well before six seconds are up, so
       it never fires there — only a cancelled one runs long enough to reach
       it, and the bar was never entitled to keep running past that point
       anyway. */
    window.addEventListener('beforeunload', function () {
        start();
        setTimeout(done, 6000);
    });

    /* Coming back to a page the browser had parked keeps the old counter, and
       a bar left running from the navigation that took us away would never
       stop. Anything restored from the back/forward cache starts clean. */
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        reset();
        workJobs = 0;
        leaving = false;
        clearTimeout(workHideTimer);
        if (badge) { badge.classList.remove('is-working'); }
    });

    // ---- report the requests the site already makes -----------------------
    if (window.fetch) {
        var nativeFetch = window.fetch;
        window.fetch = function () {
            start();
            /* then/catch rather than finally: this has to behave the same on
               anything that reaches the site, and finally is the newer of the
               two. The rejection is re-thrown so callers see it unchanged. */
            return nativeFetch.apply(this, arguments).then(function (r) {
                done();
                return r;
            }, function (err) {
                done();
                throw err;
            });
        };
    }

    if (window.XMLHttpRequest && XMLHttpRequest.prototype.send) {
        var nativeSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function () {
            var settled = false;
            var finish = function () {
                if (!settled) { settled = true; done(); }
            };
            start();
            // loadend covers success, failure, abort and timeout alike.
            this.addEventListener('loadend', finish);
            try {
                return nativeSend.apply(this, arguments);
            } catch (err) {
                finish();       // it never got as far as firing an event
                throw err;
            }
        };
    }
})();
</script>
