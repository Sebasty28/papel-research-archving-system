<?php
/**
 * The red and green banners that report what just happened (a paper
 * forwarded, a password refused, a sign-in that did not work) get a close
 * button in their top right corner and take themselves away after five
 * seconds.
 *
 * Two kinds, shown two ways:
 *
 *   - A message about the page (Mark all as read, a password refused, a
 *     support request sent) becomes a snackbar: lifted out of the page into a
 *     small white card at the top centre of the window, just under the
 *     navbar. The page no longer shifts down to make room for it and back up
 *     when it goes, and it is in the same place on every page.
 *   - A message about a form in front of the reader (the sign-in panel, the
 *     two stand-alone sign-in pages) stays where it is, above the form it is
 *     about. A snackbar across the top of the window would be over the blurred
 *     page behind the panel, away from the fields it refers to.
 *
 * Only messages about something that has just happened. The notes that explain
 * a page (Bootstrap's .alert-info and .alert-warning on the upload pages, for
 * instance, which say what a submission needs) are not status messages and
 * are left alone: they are meant to be read at leisure and are still wanted a
 * minute later. That is why this works from a list of known banners rather
 * than from `.alert` on its own. A new banner elsewhere can join in by
 * carrying `js-flash-snack` (as a snackbar) or `js-flash-auto` (in place).
 *
 * The page still prints each banner where it always did, so without
 * JavaScript it simply stays there. Only its words move into the snackbar.
 * With JavaScript, the one that is going to become a snackbar is held back
 * from the start (html.papel-snacks-on below): printed at the top of the page
 * and lifted out only once the script runs, it was drawn in place first, so
 * the page opened with a green bar that blinked out a moment later. The mark
 * is set as this file is parsed, which is why it is included from
 * site_head.php rather than from the foot of the page.
 *
 * The countdown starts when the message is first actually on screen, not when
 * the page loads: the sign-in panel's message is in the page from the start
 * but is not seen until the panel opens, and a message nobody has seen yet has
 * no business expiring. It also stops while the pointer is over the message or
 * the focus is inside it, and starts again on the way out, so it cannot vanish
 * from under someone who is reading it or reaching for the close button.
 *
 * Included from site_footer.php, and directly from the two stand-alone sign-in
 * pages, which carry no footer.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Before anything is drawn: with JavaScript, a banner bound for a snackbar is
   not shown where the page printed it. Without JavaScript none of this runs,
   the mark is never set, and every banner stays exactly where it is. */
(function () {
    var root = document.documentElement;
    root.classList.add('papel-snacks-on');
    /* And if the rest of this file never runs, nothing would carry the
       message at all, so the page's own banners are given back. */
    setTimeout(function () {
        if (!window.papelFlashReady) { root.classList.remove('papel-snacks-on'); }
    }, 2000);
})();
</script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Held back until the script below lifts its words into a snackbar. The list
   is the one the script calls SNACK; the two are read together. */
html.papel-snacks-on .mgmt-flash,
html.papel-snacks-on .alert.error,
html.papel-snacks-on .alert.success,
html.papel-snacks-on .alert-box,
html.papel-snacks-on .set-flash,
html.papel-snacks-on .js-flash-snack { display: none; }

/* ---- In place (the sign-in messages) ---- */
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

/* ---- Snackbars (everything else) ----
   The stack sits under the navbar (--nav-h is measured by site_header.php),
   centred, and lets clicks through everywhere but on the cards themselves. */
.papel-snacks {
    position: fixed;
    top: calc(var(--nav-h, 56px) + .75rem);
    left: 50%;
    transform: translateX(-50%);
    z-index: 1250;             /* over the page, the navbar and the sign-in panel */
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .5rem;
    width: min(32rem, calc(100vw - 1.5rem));
    pointer-events: none;
}
.papel-snack {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: .625rem;
    width: 100%;
    padding: .75rem 2.75rem .75rem 1rem;   /* the right side is the close button's */
    border-radius: var(--r-card, 8px);
    /* White, in tokens: the card goes with the theme, so in Old Night it is
       that palette's dark card rather than a white slab on a dark page. */
    background: var(--white);
    color: var(--ink);
    /* An edge of its own, for where it crosses the maroon breadcrumb bar
       at the top of a page, and a shadow to lift it off the page below. */
    border: 1px solid var(--border);
    font-family: var(--font-body);
    font-size: .875rem;
    line-height: 1.5;
    box-shadow: 0 10px 28px rgba(51, 0, 0, .16), 0 2px 6px rgba(51, 0, 0, .10);
    pointer-events: auto;
    animation: papel-snack-in .25s ease;
    transition: opacity .3s ease, transform .3s ease;
}
.papel-snack-icon { flex: 0 0 auto; font-size: 20px; margin-top: .05rem; }
/* Told apart by the icon's shape as well as its colour, and the colours are
   the palette's own for success and failure, so they hold up on white and on
   Old Night's dark card alike. */
.papel-snack.is-good .papel-snack-icon { color: var(--ok-text); }
.papel-snack.is-bad  .papel-snack-icon { color: var(--bad-text); }
.papel-snack-text { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
.papel-snack-text a { color: var(--maroon); text-decoration: underline; }
.papel-snack .papel-dismiss { top: .5rem; right: .5rem; color: var(--ink); opacity: .55; }
.papel-snack .papel-dismiss:hover { opacity: 1; background: var(--cream); }
.papel-snack.is-going { opacity: 0; transform: translateY(-6px); pointer-events: none; }
@keyframes papel-snack-in {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: none; }
}

@media (prefers-reduced-motion: reduce) {
    .papel-dismissible, .papel-snack { transition: none; animation: none; }
}
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    /* Says the converter is here, so the mark set above may hold the banners
       back. Set as this is parsed rather than when the page has loaded: a page
       that takes a while would otherwise have them handed back mid-load and
       show the very flash this avoids. */
    window.papelFlashReady = true;

    var HIDE_AFTER = 5000;

    /* Messages about the page: these become snackbars. */
    var SNACK = [
        '.mgmt-flash',                       // includes/flash_banner.php: consoles, desks, notifications
        '.alert.error', '.alert.success',    // includes/page_theme.php: Settings
        '.alert-box',                        // Contact Support
        '.set-flash',                        // the storage folder settings
        '.js-flash-snack'                    // anything else that asks for this
    ].join(',');

    /* Messages about a form in front of the reader: these stay put. */
    var IN_PLACE = [
        '.panel-alert',                      // the sign-in panel
        '.alert.alert-danger[role="alert"]', // the two stand-alone sign-in pages
        '.alert.alert-success[role="alert"]',
        '.alert.alert-warning[role="alert"]',
        '.js-flash-auto'
    ].join(',');

    var ALL = SNACK + ',' + IN_PLACE;

    function remove(el) {
        el.classList.add('is-going');
        var done = function () { if (el.parentNode) { el.parentNode.removeChild(el); } };
        el.addEventListener('transitionend', done, { once: true });
        setTimeout(done, 400);               // if the transition never runs
    }

    /* ---- Snackbars ---- */
    var stack = null;
    function snackStack() {
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'papel-snacks';
            document.body.appendChild(stack);
        }
        return stack;
    }

    function isBad(el) {
        return el.matches('.is-bad, .error, .bad, .alert-danger');
    }

    /* The words of the message, without the banner's own icon or close
       button: the snackbar brings its own of each. */
    function wordsOf(el) {
        var from = el.querySelector('.mgmt-flash-text') || el;
        var words = document.createDocumentFragment();
        Array.prototype.forEach.call(from.childNodes, function (n) {
            if (n.nodeType === 1 && n.matches('i, button, .material-symbols-outlined, .bi')) { return; }
            words.appendChild(n.cloneNode(true));
        });
        return words;
    }

    function toSnack(el) {
        var bad = isBad(el);
        var snack = document.createElement('div');
        snack.className = 'papel-snack ' + (bad ? 'is-bad' : 'is-good');
        // An error interrupts; a confirmation waits its turn.
        snack.setAttribute('role', bad ? 'alert' : 'status');
        snack.dataset.papelFlash = '1';

        var icon = document.createElement('span');
        icon.className = 'material-symbols-outlined papel-snack-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = bad ? 'error' : 'check_circle';

        var text = document.createElement('span');
        text.className = 'papel-snack-text';
        text.appendChild(wordsOf(el));

        snack.appendChild(icon);
        snack.appendChild(text);
        snackStack().appendChild(snack);
        // Out of the page, so nothing is left holding its place.
        if (el.parentNode) { el.parentNode.removeChild(el); }
        return snack;
    }

    /* ---- Both kinds: close button, countdown, pause ---- */
    function arm(el) {
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

    function setup(el) {
        if (el.dataset.papelFlash) { return; }
        el.dataset.papelFlash = '1';
        if (el.matches(SNACK)) {
            arm(toSnack(el));
        } else {
            el.classList.add('papel-dismissible');
            arm(el);
        }
    }

    function scan(root) {
        var within = (root && root.querySelectorAll) ? root : document;
        Array.prototype.forEach.call(within.querySelectorAll(ALL), setup);
        if (root && root.matches && root.matches(ALL)) { setup(root); }
    }

    function ready() {
        scan(document);
        /* Messages the page puts up later (a saved draft, an AJAX list that
           came back with one) are taken care of the same way. */
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
