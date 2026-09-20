<?php
/**
 * The red and green banners that report what just happened — a paper
 * forwarded, a password refused, a sign-in that did not work — get a close
 * button in their top right corner and take themselves away after five
 * seconds.
 *
 * Only messages about something that has just happened. The notes that explain
 * a page — Bootstrap's .alert-info and .alert-warning on the upload pages, for
 * instance, which say what a submission needs — are not status messages and
 * are left alone: they are meant to be read at leisure and are still wanted a
 * minute later. That is why this works from a list of known banners rather
 * than from `.alert` on its own. A new banner elsewhere can join them by
 * carrying `js-flash-auto`.
 *
 * The countdown starts when the banner is first actually on screen, not when
 * the page loads: the sign-in panel's message is in the page from the start
 * but is not seen until the panel opens, and a message nobody has seen yet has
 * no business expiring.
 *
 * It also stops while the pointer is over the banner or the focus is inside
 * it, and starts again on the way out, so it cannot vanish from under someone
 * who is reading it or reaching for the close button.
 *
 * Included from site_footer.php, and directly from the two stand-alone sign-in
 * pages, which carry no footer.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.papel-dismissible {
    position: relative;
    /* Room for the button, so a long message does not run under it. */
    padding-right: 2.25rem;
    transition: opacity .3s ease, transform .3s ease;
}
.papel-dismiss {
    position: absolute;
    top: .25rem;
    right: .25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    padding: 0;
    border: 0;
    border-radius: var(--r-control, 4px);
    background: transparent;
    /* The banner's own colour, whichever kind it is. */
    color: inherit;
    cursor: pointer;
    opacity: .55;
    transition: opacity .15s ease, background .15s ease;
}
.papel-dismiss:hover { opacity: 1; background: rgba(0, 0, 0, .06); }
.papel-dismiss:focus-visible { opacity: 1; outline: 2px solid currentColor; outline-offset: -2px; }
.papel-dismiss .material-symbols-outlined { font-size: 16px; }
.papel-dismissible.is-going { opacity: 0; transform: translateY(-4px); pointer-events: none; }
@media (prefers-reduced-motion: reduce) {
    .papel-dismissible { transition: none; }
}
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var HIDE_AFTER = 5000;

    /* The banners that report an outcome. .mgmt-flash brings its own close
       button (includes/flash_banner.php) and only needs the countdown. */
    var SELECTOR = [
        '.mgmt-flash',                       // the management consoles and review desks
        '.alert.error', '.alert.success',    // includes/page_theme.php
        '.alert-box',                        // Contact Support
        '.panel-alert',                      // the sign-in panel
        '.alert.alert-danger[role="alert"]', // the two stand-alone sign-in pages
        '.alert.alert-success[role="alert"]',
        '.alert.alert-warning[role="alert"]',
        '.js-flash-auto'                     // anything else that asks for this
    ].join(',');

    function remove(el) {
        el.classList.add('is-going');
        var done = function () { if (el.parentNode) { el.parentNode.removeChild(el); } };
        el.addEventListener('transitionend', done, { once: true });
        setTimeout(done, 400);               // if the transition never runs
    }

    function setup(el) {
        if (el.dataset.papelFlash) { return; }
        el.dataset.papelFlash = '1';
        el.classList.add('papel-dismissible');

        // One close button: never a second one beside the one it already has.
        if (!el.querySelector('.papel-dismiss, .js-flash-x')) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'papel-dismiss';
            btn.setAttribute('aria-label', 'Dismiss this message');
            btn.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">close</span>';
            btn.addEventListener('click', function () { remove(el); });
            el.appendChild(btn);
        }

        var timer = null;
        function start() { clearTimeout(timer); timer = setTimeout(function () { remove(el); }, HIDE_AFTER); }
        function stop()  { clearTimeout(timer); }

        el.addEventListener('mouseenter', stop);
        el.addEventListener('mouseleave', start);
        el.addEventListener('focusin', stop);
        el.addEventListener('focusout', start);

        /* Start counting the first time it is actually shown. */
        if (window.IntersectionObserver) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) { io.disconnect(); start(); }
                });
            });
            io.observe(el);
        } else {
            start();
        }
    }

    function scan(root) {
        var within = (root && root.querySelectorAll) ? root : document;
        Array.prototype.forEach.call(within.querySelectorAll(SELECTOR), setup);
        if (root && root.matches && root.matches(SELECTOR)) { setup(root); }
    }

    function ready() {
        scan(document);
        /* Messages the page puts up later — a saved draft, an AJAX list that
           came back with one — are taken care of the same way. */
        if (window.MutationObserver) {
            new MutationObserver(function (records) {
                records.forEach(function (r) {
                    Array.prototype.forEach.call(r.addedNodes, function (n) {
                        if (n.nodeType === 1) { scan(n); }
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
</script>
