<?php
/**
 * The start-up splash: the PAPEL logo animation over the whole window, shown
 * when the site is first opened in a tab and whenever a page is refreshed.
 * Ordinary navigation from page to page does not show it — the loading bar
 * (includes/loading_bar.php) covers that, with its own small copy of the same
 * animation.
 *
 * Required from the top of site_head.php so it decides before the first paint.
 * Deciding any later would let the page flash up, vanish under the splash, and
 * reappear, which is worse than no splash at all.
 *
 * The overlay is html::before, not an element, so there is nothing to place in
 * <body> and nothing that depends on the page's markup: a class on <html> turns
 * it on, and removing the class takes it away.
 *
 * It stays up for at least 2.5s, however quickly the page loads — a set
 * minimum, not just however long loading happens to take. The GIF itself runs
 * 5.33s; the whole of it was tried and was too long a wait on every refresh,
 * so the splash leaves once the wordmark is drawn rather than at the end.
 * The fade begins at the latest of three moments:
 *   - 2.5s after the splash first appeared, the required minimum;
 *   - 2.45s after the GIF is ready: the animation builds the wordmark up — P,
 *     the rule, then P|PAPEL — and holds it from about 2.43s to 2.87s, so this
 *     keeps the 0.35s fade on the finished wordmark even when the GIF arrived
 *     late;
 *   - the page finishing loading, so the splash never lifts off a half-built
 *     page.
 * A page that never finishes is given up on at 8s regardless, and the CSS
 * fades it out on its own at 8s too, in case this script is the thing that
 * failed.
 *
 * Signing in shows it too, in place of the small pill the site's other
 * workflows use: window.papelSplash.show() puts it up on the sign-in page and
 * leaves a note in sessionStorage, which the page being signed in to reads
 * here to play it through properly.
 *
 * Skipped for anyone who has asked for less motion — the operating system's
 * setting, or the site's own "Stop animations" in the accessibility widget,
 * read here from the same localStorage entry that widget writes.
 */
$splash_gif = (defined('BASE_URL') ? BASE_URL : '/capstone')
            . '/assests/images/' . rawurlencode('Logo Loading PAPEL.gif');
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The colour is the average of the GIF's own background, so the canvas has no
   visible edge however much of the window lies outside it. That background is
   a speckle of near-whites rather than one flat value; a colour sampled from a
   single corner came out a shade too dark and outlined the canvas. The width is sized to the
   wordmark, not the canvas: the logo takes up about a third of the GIF's width,
   so this gives roughly 420px of logo on a desktop and about two-thirds of a
   phone's width, the canvas simply running off the sides there. */
html.papel-splash::before {
    content: "";
    position: fixed;
    inset: 0;
    z-index: 2147483000;
    background: #FEFEFD url("<?= htmlspecialchars($splash_gif, ENT_QUOTES, 'UTF-8') ?>") center / min(1180px, 183vw) auto no-repeat;
    animation: papel-splash-failsafe .35s ease 8s forwards;
}
html.papel-splash.papel-splash-out::before {
    opacity: 0;
    pointer-events: none;
    transition: opacity .35s ease;
}
@keyframes papel-splash-failsafe {
    to { opacity: 0; visibility: hidden; pointer-events: none; }
}
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var root = document.documentElement;
    var ASKED_KEY = 'papel_splash_next';   // a workflow asked for it on the page before

    /* Whether an animation may be played at all. Asked before anything else,
       and again by show(), which the sign-in forms call from another page. */
    function allowed() {
        try {
            if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) { return false; }
            var a11y = JSON.parse(localStorage.getItem('papel_a11y') || 'null');
            return !(a11y && Array.isArray(a11y.classes) &&
                     a11y.classes.indexOf('a11y-stop-animations') !== -1);
        } catch (err) {
            return false;
        }
    }

    /* Signing in shows this instead of the small pill, and the sign-in page is
       gone the moment the form goes out — so it is put up here and asked for
       again on the page being signed in to, which plays it properly timed.
       show() reports back, so a caller can fall back to the pill when a
       reduced-motion setting means nothing will be shown. */
    window.papelSplash = {
        show: function () {
            if (!allowed()) { return false; }
            try { sessionStorage.setItem(ASKED_KEY, '1'); } catch (err) {}
            root.classList.add('papel-splash');
            return true;
        }
    };

    var asked = false;
    try {
        if (!allowed()) { return; }

        asked = sessionStorage.getItem(ASKED_KEY) === '1';
        sessionStorage.removeItem(ASKED_KEY);

        var nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        var reloaded = nav ? nav.type === 'reload'
                           : !!(performance.navigation && performance.navigation.type === 1);
        /* sessionStorage lives as long as the tab: empty on the first page of a
           visit, set on every page after it. */
        var started = sessionStorage.getItem('papel_started') === '1';
        sessionStorage.setItem('papel_started', '1');
        if (started && !reloaded && !asked) { return; }
    } catch (err) {
        return;                  // storage blocked: no way to tell a start from a click
    }

    root.classList.add('papel-splash');

    var MIN_VISIBLE = 2500;          // required, even when loading is already done
    var FULL_WORDMARK_AT = 2450;
    var FADE = 350;
    var shownAt = performance.now();
    var gifReadyAt = null;
    var pageLoaded = false;
    var hidden = false;

    function hide() {
        if (hidden) { return; }
        hidden = true;
        root.classList.add('papel-splash-out');
        setTimeout(function () {
            root.classList.remove('papel-splash', 'papel-splash-out');
        }, FADE);
    }
    function maybeHide() {
        if (!pageLoaded || gifReadyAt === null) { return; }
        var fadeAt = Math.max(shownAt + MIN_VISIBLE, gifReadyAt + FULL_WORDMARK_AT);
        setTimeout(hide, Math.max(0, fadeAt - performance.now()));
    }

    // Loaded again here only to learn when it is ready; the browser shares the
    // one download with the overlay's background.
    var gif = new Image();
    gif.onload  = function () { gifReadyAt = performance.now(); maybeHide(); };
    gif.onerror = function () { gifReadyAt = -Infinity; maybeHide(); };   // nothing to wait for
    gif.src = <?= json_encode($splash_gif) ?>;

    window.addEventListener('load', function () { pageLoaded = true; maybeHide(); });
    setTimeout(hide, 8000);
})();
</script>
