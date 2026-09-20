<?php
/**
 * Arrow keys move between links, buttons and boxes, anywhere on the site.
 *
 * Tab already does this — it is the browser's own way through a page and it
 * works everywhere. This adds the arrow keys alongside it, because that is the
 * key people reach for first, and Tab is not obvious to anyone who has not been
 * told about it.
 *
 * The arrows follow the layout rather than the order the page happens to be
 * written in, because what the reader is steering by is what they can see:
 *
 *   - Left and Right move along the row the focus is already on — the navbar,
 *     a row of buttons, a line of cards — and stop when the row ends. They no
 *     longer jump to whatever came next in the markup, which is what made them
 *     feel as though they had lost the thread.
 *   - Up and Down go to the nearest control above or below, preferring the one
 *     in line with where the focus is, so a column stays a column and a form
 *     goes field by field. Only when nothing is directly above or below do
 *     they fall back to the page's own order, which is what ends a column.
 *
 * Enter still activates whatever is focused, as it always did.
 *
 * The whole difficulty here is not moving focus — it is knowing when NOT to.
 * Arrow keys already mean something in half a dozen places, and taking them
 * over blindly would break typing, dropdowns and radio buttons all at once. So
 * this stands aside whenever:
 *
 *   - nothing is focused, so the arrows still scroll the page as usual;
 *   - the focus is in a textarea, or moving the caret sideways in a text box;
 *   - the focus is on a select, a radio, a slider or editable content, where
 *     the arrows change the value;
 *   - a component has already handled the key — the custom dropdown, the
 *     search suggestions and the PDF panel's resize grip all do;
 *   - any modifier is held, so browser and screen-reader shortcuts still work.
 *
 * A single-line text box is the one place it does take a side: Left and Right
 * move the caret as normal, but Up and Down have nothing to do there, so they
 * carry on to the next field. Escape lets go of the focus entirely, which hands
 * the arrows back to the page for scrolling.
 *
 * Included once from site_footer.php, which every page carries.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var FORWARD  = { ArrowDown: 1, ArrowRight: 1 };
    var BACKWARD = { ArrowUp: 1, ArrowLeft: 1 };
    var SIDEWAYS = { ArrowLeft: 1, ArrowRight: 1 };

    /* Text fields whose caret moves left and right. Everything else that is an
       <input> — a checkbox, a button, a file picker — has no caret, so the
       arrows are free. */
    var CARET = {
        text: 1, search: 1, email: 1, password: 1, tel: 1, url: 1,
        number: 1, date: 1, 'datetime-local': 1, month: 1, time: 1, week: 1
    };

    /* Places that run their own arrow-key behaviour. Focus inside one of these
       and the arrows belong to it, not to us. */
    var THEIRS = [
        '.sel-btn', '.sel-menu', '.sel-opt',          // the skinned dropdown
        '.pdf-dock-grip', '[data-role="dock-grip"]',  // the PDF panel resizer
        '[role="listbox"]', '[role="menu"]', '[role="menubar"]',
        '[role="tablist"]', '[role="radiogroup"]', '[role="slider"]',
        '[role="spinbutton"]', '[contenteditable="true"]', '[contenteditable=""]'
    ].join(',');

    var CANDIDATES = [
        'a[href]', 'button', 'input', 'select', 'textarea',
        '[tabindex]', '[contenteditable="true"]', '[contenteditable=""]'
    ].join(',');

    function visible(el) {
        var r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) { return false; }

        /* A closed panel that was slid off the side still has a size and a
           place in the page. The login slide-in is parked at
           translateX(100%), so without this the arrows march into an
           invisible form eight fields deep. Sideways only: something below
           the fold is merely further down, and scrolling to it is the point. */
        var w = window.innerWidth || document.documentElement.clientWidth;
        if (r.right <= 0 || r.left >= w) { return false; }

        /* Chrome's own answer where it exists — it accounts for display,
           visibility and opacity on every ancestor, which the offsetParent
           test below only half does. */
        if (el.checkVisibility) {
            return el.checkVisibility({
                opacityProperty: true,
                visibilityProperty: true,
                contentVisibilityAuto: true
            });
        }
        var cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.opacity === '0') { return false; }
        return el.offsetParent !== null || cs.position === 'fixed';
    }

    function reachable(el) {
        if (el.disabled || el.hidden) { return false; }
        if (el.getAttribute('tabindex') === '-1') { return false; }
        if (el.getAttribute('aria-hidden') === 'true') { return false; }
        if (el.closest('[aria-hidden="true"], [inert]')) { return false; }
        if (el.type === 'hidden') { return false; }
        return visible(el);
    }

    /* With the sign-in panel open, the arrows stay inside it. The page behind
       is marked inert while it is up (site_footer.php), which reachable()
       already respects; this is the same rule stated where the keys are
       handled, so it holds in a browser too old to know what inert means. */
    function scope() {
        return document.querySelector('.login-panel.open') || document;
    }

    function stops() {
        return Array.prototype.filter.call(
            scope().querySelectorAll(CANDIDATES), reachable);
    }

    /* Where something sits on screen. Viewport coordinates, so every candidate
       is measured in the same frame and they compare directly. */
    function box(el) {
        var r = el.getBoundingClientRect();
        return { l: r.left, r: r.right, t: r.top, b: r.bottom,
                 cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
    }

    /* The nearest stop in the direction pressed.
       Two numbers decide it: the gap straight ahead, and how far off to one
       side the thing sits. Something that lines up with the focus — the next
       item down a column, the next along a row — is what the eye expects, so
       being out of line counts against a candidate, and heavily when the two
       do not overlap at all. Anything level with the focus, or behind it, is
       not in that direction and does not count. */
    function nearest(current, key, list) {
        var a = box(current);
        var sideways = SIDEWAYS[key] === 1;
        var forward  = FORWARD[key] === 1;
        var best = null, bestScore = Infinity;

        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el === current) { continue; }
            var b = box(el), gap, overlap, offset;

            if (sideways) {
                gap     = forward ? b.l - a.r : a.l - b.r;
                overlap = Math.min(a.b, b.b) - Math.max(a.t, b.t);
                offset  = Math.abs(b.cy - a.cy);
                // Another row is not to the left or right of this one.
                if (overlap <= 0) { continue; }
            } else {
                gap     = forward ? b.t - a.b : a.t - b.b;
                overlap = Math.min(a.r, b.r) - Math.max(a.l, b.l);
                offset  = Math.abs(b.cx - a.cx);
            }

            if (gap < -2) { continue; }          // level with the focus, or behind it
            if (gap < 0)  { gap = 0; }           // touching counts as no gap at all

            var score = gap + offset * (overlap > 0 ? 0.3 : 1.5);
            if (score < bestScore) { bestScore = score; best = el; }
        }
        return best;
    }

    /* Does this element want the key for itself? */
    function keepsKey(el, key) {
        var tag = (el.tagName || '').toLowerCase();
        if (tag === 'textarea') { return true; }
        if (tag === 'select') { return true; }
        if (tag === 'input') {
            var type = (el.type || 'text').toLowerCase();
            if (type === 'radio' || type === 'range') { return true; }
            // A caret only travels sideways, so up and down are ours.
            if (CARET[type]) { return SIDEWAYS[key] === 1; }
        }
        return !!el.closest(THEIRS);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            /* Let go, so the arrows scroll the page again. Only when focus is
               on something of ours — a dialog's own Escape must still close it. */
            var on = document.activeElement;
            if (on && on !== document.body && !on.closest('[role="dialog"], .modal, .quick-settings-dropdown')) {
                on.blur();
            }
            return;
        }

        if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        if (e.defaultPrevented) { return; }          // a component got there first
        var back = BACKWARD[e.key] === 1;
        if (!back && FORWARD[e.key] !== 1) { return; }

        var current = document.activeElement;
        /* Nothing focused means nobody is navigating by keyboard yet, so the
           arrows do what they have always done and scroll. */
        if (!current || current === document.body || current === document.documentElement) {
            return;
        }
        if (keepsKey(current, e.key)) { return; }

        var list = stops();
        if (!list.length) { return; }
        var i = list.indexOf(current);
        if (i === -1) { return; }

        var next = nearest(current, e.key, list);
        /* Nothing above or below: the foot of a column, or a control the
           layout has set out of line with everything else. The page's own
           order carries on from there. Left and Right get no such fallback —
           running off the end of a row into another part of the page is the
           jump they are meant to stop. */
        if (!next && !SIDEWAYS[e.key]) { next = list[i + (back ? -1 : 1)]; }
        if (!next) { return; }                       // nowhere to go: stay put

        next.focus();
        /* Selecting the text makes the next box ready to type over, which is
           what moving through a form is usually for. */
        if (next.select && CARET[(next.type || '').toLowerCase()]) {
            try { next.select(); } catch (err) {}
        }
        e.preventDefault();
    });
})();
</script>
