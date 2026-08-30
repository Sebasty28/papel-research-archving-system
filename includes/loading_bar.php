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
 * Included once from site_footer.php, which every page carries.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.papel-loader {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    z-index: 20100;              /* over the shared dialogs, under the a11y widget */
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

/* Someone who has asked for less movement still needs to know it is working,
   so the bar stays and breathes instead of travelling. */
@media (prefers-reduced-motion: reduce) {
    .papel-loader.is-busy .papel-loader__seg {
        animation: papel-loader-breathe 1.6s ease-in-out infinite;
        transform: none;
    }
    @keyframes papel-loader-breathe {
        0%, 100% { opacity: .35; }
        50%      { opacity: 1; }
    }
}
</style>

<div class="papel-loader" id="papelLoader" role="status" aria-live="polite"
     aria-label="Loading">
    <div class="papel-loader__seg"></div>
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
       the next one takes to answer, which is exactly the wait being reported. */
    window.addEventListener('beforeunload', start);

    /* Coming back to a page the browser had parked keeps the old counter, and
       a bar left running from the navigation that took us away would never
       stop. Anything restored from the back/forward cache starts clean. */
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { reset(); }
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
