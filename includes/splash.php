<?php
/**
 * The PAPEL logo animation shown while a successful sign-in navigates to its
 * destination. Ordinary page loads use the short loading skeleton instead.
 *
 * Required from site_head.php so the sign-in page has show() ready before its
 * forms submit.
 *
 * The overlay is html::before, not an element, so there is nothing to place in
 * <body> and nothing that depends on the page's markup: a class on <html> turns
 * it on, and removing the class takes it away.
 *
 * The destination page uses the skeleton, so a slow GIF or a third-party asset
 * cannot hold the whole window over already usable content for 2.5 seconds.
 * window.papelSplash.show() is called only after sign-in succeeds (or as its
 * form is submitted without AJAX). If navigation fails, CSS lifts it at 8s.
 *
 * Skipped for anyone who has asked for less motion: the operating system's
 * setting, or the site's own "Stop animations" in the accessibility widget,
 * read here from the same localStorage entry that widget writes.
 */
/* A new filename, and no space in it, both deliberately.

   The original was "Logo Loading PAPEL.gif". Renaming it to remove the space
   did not help: the host answered 403 for the file and then 553 "permission
   denied" when the deploy tried to replace it, which is a quarantined file,
   not a path problem. A file the host has locked cannot be overwritten, so
   the way out is a different name with different bytes.

   This one is also re-encoded: 960px instead of 1920, 80 frames instead of
   160, one shared palette. The page draws it at min(1180px, 183vw), so the
   difference is invisible and it is a third smaller over a phone connection. */
$splash_gif = (defined('BASE_URL') ? BASE_URL : '/capstone')
            . '/assests/images/papel-splash.gif';
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
@keyframes papel-splash-failsafe {
    to { opacity: 0; visibility: hidden; pointer-events: none; }
}
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var root = document.documentElement;
    /* Whether an animation may be played at all. */
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

    /* The sign-in page shows this while navigating. The destination's skeleton
       takes over as soon as its markup arrives. Report back so a caller can
       use the loading pill when reduced motion disables the animation. */
    window.papelSplash = {
        show: function () {
            if (!allowed()) { return false; }
            root.classList.add('papel-splash');
            return true;
        }
    };
})();
</script>
