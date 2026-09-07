<?php
/**
 * Arrow keys move between links, buttons and boxes, anywhere on the site.
 *
 * Tab already does this — it is the browser's own way through a page and it
 * works everywhere. This adds the arrow keys alongside it, because that is the
 * key people reach for first, and Tab is not obvious to anyone who has not been
 * told about it.
 *
 * Down and Right go forward, Up and Left go back, in the order the page is
 * written. Enter still activates whatever is focused, as it always did.
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

    function stops() {
        return Array.prototype.filter.call(
            document.querySelectorAll(CANDIDATES), reachable);
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

        var next = list[i + (back ? -1 : 1)];
        if (!next) { return; }                       // at either end: stay put

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
