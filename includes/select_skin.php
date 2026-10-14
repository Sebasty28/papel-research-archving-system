<?php
/**
 * Dropdowns in the site's own clothes, list included.
 *
 * A `<select>` can be styled down to its border, and then the moment it opens
 * the operating system draws the list: Windows grey, Segoe UI, square corners,
 * its own highlight colour. On a page built out of maroon and cream that is the
 * one control that always looks borrowed.
 *
 * There is no CSS for the open list (no browser allows it) so the list has to
 * be drawn. The rule followed here is that the real control stays:
 *
 *   - The `<select>` remains in the DOM and keeps its name and value, so forms
 *     submit exactly as before and every page that reads `.value` still works.
 *   - Setting `.value` or `.selectedIndex` from script updates the skin, because
 *     assignment is intercepted per element. Several pages fill a select from
 *     another one, and none of them fire an event when they do.
 *   - Options added or removed later are picked up, which is what Contact
 *     Support does when it rebuilds the list of names for a chosen role.
 *   - Keyboard behaviour is rebuilt rather than lost: arrows, Home and End,
 *     Enter and Escape, and type-ahead.
 *
 * The menu is positioned fixed, not absolute, because several of these sit
 * inside panels with `overflow: hidden` and an absolutely positioned list would
 * be cut off at the panel's edge.
 *
 * The same clothes are available to a box that can be typed in as well as
 * picked from: put `data-combo` on an `<input list="...">` and it gains the
 * chevron, the site's own list and the same keyboard, while staying the input
 * the form submits. See the combo section near the bottom.
 *
 * Anything that should keep the native control can say so with `data-no-skin`.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The original stays in the layout so nothing reflows; it is simply not shown.
   It keeps its name and value, and it is taken out of the tab order because the
   button below stands in for it. */
/* Every declaration is forced, because page stylesheets set widths, padding and
   borders on their own selects and any one of them would leave an invisible
   144px box sitting in the layout. */
select.is-skinned {
    position: absolute !important;
    width: 1px !important; height: 1px !important;
    min-width: 0 !important; max-width: none !important;
    padding: 0 !important; margin: -1px !important; border: 0 !important;
    appearance: none !important; -webkit-appearance: none !important;
    background: none !important;
    clip: rect(0 0 0 0); clip-path: inset(50%);
    overflow: hidden; white-space: nowrap; pointer-events: none;
}

.sel { position: relative; display: block; width: 100%; }
.sel-btn {
    display: flex; align-items: center; gap: .5rem; width: 100%;
    padding: .5rem .7rem;
    background: var(--white, #fff);
    border: 1px solid var(--border-control, #A88A8A);
    border-radius: var(--r-control, 4px);
    font-family: var(--font-body), inherit;
    font-size: .8125rem; color: var(--ink, #330000);
    text-align: left; cursor: pointer;
    transition: border-color .15s ease;
}
.sel-btn:hover { border-color: var(--maroon, #820707); }
.sel-btn[aria-expanded="true"] { border-color: var(--maroon, #820707); }
.sel-label { flex: 1 1 auto; min-width: 0; overflow: hidden;
             text-overflow: ellipsis; white-space: nowrap; }
/* The placeholder option reads as a prompt, not as an answer. */
.sel-btn.is-empty .sel-label { color: var(--grey, #545054); }
.sel-chev {
    flex: 0 0 auto; width: 10px; height: 6px;
    transition: transform .15s ease;
}
.sel-btn[aria-expanded="true"] .sel-chev { transform: rotate(180deg); }
.sel-btn:disabled { opacity: .55; cursor: not-allowed; background: var(--cream, #FFF5F5); }

/* The list. Fixed, so a panel with overflow:hidden cannot clip it. */
.sel-menu {
    position: fixed; z-index: 1200;
    max-height: 15rem; overflow-y: auto;
    padding: .25rem;
    background: var(--white, #fff);
    border: 1px solid var(--border, #E6D4D4);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md, 0 6px 18px rgba(51,0,0,.14));
}
.sel-menu[hidden] { display: none; }
.sel-opt {
    display: flex; align-items: center; gap: .5rem;
    padding: .4rem .55rem;
    border-radius: var(--r-control, 4px);
    font-size: .8125rem; color: var(--ink, #330000);
    cursor: pointer; white-space: normal;
}
/* One highlight for both the mouse and the keyboard, so the list never shows
   two different "current" rows at once. */
.sel-opt.is-active { background: var(--cream, #FFF5F5); }
.sel-opt[aria-selected="true"] { color: var(--maroon, #820707); font-weight: 600; }
.sel-opt[aria-disabled="true"] { opacity: .5; cursor: not-allowed; }
.sel-tick {
    flex: 0 0 auto; width: .75rem; height: .75rem;
    opacity: 0;
}
.sel-opt[aria-selected="true"] .sel-tick { opacity: 1; }
.sel-group {
    padding: .4rem .55rem .2rem;
    font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase;
    color: var(--grey, #545054);
}

/* ---- A dropdown that can be typed in ----
   The input stays the real control, so it keeps the page's own field styling
   and only gains room for the handle on its right. */
.sel-combo { position: relative; display: block; width: 100%; }
.sel-combo > .sel-field { width: 100%; padding-right: 1.9rem; }
.sel-arrow {
    position: absolute; right: .4rem; top: 50%; transform: translateY(-50%);
    display: flex; align-items: center; justify-content: center;
    width: 1.15rem; height: 1.15rem; padding: 0;
    background: none; border: 0; border-radius: var(--r-control, 4px);
    color: var(--grey, #545054); cursor: pointer;
}
.sel-arrow:hover { color: var(--maroon, #820707); }
.sel-field[aria-expanded="true"] ~ .sel-arrow .sel-chev { transform: rotate(180deg); }
/* Said rather than shown as an empty box: a list that opens with nothing in it
   reads as broken, where this reads as an answer. */
.sel-none { padding: .4rem .55rem; font-size: .8125rem; color: var(--grey, #545054); }
</style>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var CHEV = '<svg class="sel-chev" viewBox="0 0 10 6" aria-hidden="true">'
             + '<path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" '
             + 'stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var TICK = '<svg class="sel-tick" viewBox="0 0 12 12" aria-hidden="true">'
             + '<path d="M1.5 6.5l3 3 6-6" fill="none" stroke="currentColor" stroke-width="2" '
             + 'stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var seq = 0;
    var openOne = null;

    function skin(select) {
        if (select.dataset.noSkin !== undefined) return;
        if (select.multiple || select.size > 1) return;      // not this kind of control
        if (select.classList.contains('is-skinned')) return;

        var id = 'sel' + (++seq);
        var wrap = document.createElement('div');
        wrap.className = 'sel';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sel-btn';
        btn.id = id + 'b';
        btn.setAttribute('role', 'combobox');
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', id + 'm');
        btn.innerHTML = '<span class="sel-label"></span>' + CHEV;

        var menu = document.createElement('div');
        menu.className = 'sel-menu';
        menu.id = id + 'm';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(btn);
        document.body.appendChild(menu);            // fixed: lives at the top level
        select.classList.add('is-skinned');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        // The label a select is described by should describe the button now.
        var label = select.id && document.querySelector('label[for="' + select.id + '"]');
        if (label) btn.setAttribute('aria-labelledby', (label.id || (label.id = id + 'l')));

        /* build and paint are carried on the state because open() serves two
           kinds of control now: this one, and the typeable combo below. */
        var state = { select: select, btn: btn, menu: menu, active: -1,
                      build: build, paint: paint };
        btn._sel = state;
        menu._sel = state;

        build(state);
        paint(state);
        hookValue(state);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            state.menu.hidden ? open(state) : close(state);
        });
        btn.addEventListener('keydown', function (e) { onKey(state, e); });
        menu.addEventListener('keydown', function (e) { onKey(state, e); });

        /* Options are rebuilt by several pages after a related choice changes. */
        new MutationObserver(function () { build(state); paint(state); })
            .observe(select, { childList: true, subtree: true });
        select.addEventListener('change', function () { paint(state); });
    }

    /** Redraw the list from the select's own options. */
    function build(state) {
        var menu = state.menu;
        menu.innerHTML = '';
        Array.prototype.forEach.call(state.select.children, function (node) {
            if (node.tagName === 'OPTGROUP') {
                var g = document.createElement('div');
                g.className = 'sel-group';
                g.textContent = node.label;
                menu.appendChild(g);
                Array.prototype.forEach.call(node.children, function (o) {
                    menu.appendChild(row(state, o));
                });
            } else if (node.tagName === 'OPTION') {
                menu.appendChild(row(state, node));
            }
        });
    }

    function row(state, option) {
        var el = document.createElement('div');
        el.className = 'sel-opt';
        el.setAttribute('role', 'option');
        el.innerHTML = TICK + '<span></span>';
        el.lastChild.textContent = option.textContent.trim();
        el.dataset.value = option.value;
        if (option.disabled) el.setAttribute('aria-disabled', 'true');
        el.addEventListener('click', function (e) {
            e.stopPropagation();
            if (option.disabled) return;
            choose(state, option.value);
        });
        el.addEventListener('mousemove', function () {
            var rows = options(state);
            setActive(state, rows.indexOf(el));
        });
        return el;
    }

    function options(state) {
        return Array.prototype.slice.call(state.menu.querySelectorAll('.sel-opt'));
    }

    /** Put the chosen option's text on the button. */
    function paint(state) {
        var sel = state.select;
        var chosen = sel.options[sel.selectedIndex];
        var text = chosen ? chosen.textContent.trim() : '';
        state.btn.querySelector('.sel-label').textContent = text;
        state.btn.classList.toggle('is-empty', !chosen || chosen.value === '');
        state.btn.disabled = sel.disabled;
        options(state).forEach(function (el) {
            el.setAttribute('aria-selected', el.dataset.value === sel.value ? 'true' : 'false');
        });
    }

    function choose(state, value) {
        if (state.select.value === value) { close(state); return; }
        state.select.value = value;
        paint(state);
        // The page is told the way it expects to be told.
        state.select.dispatchEvent(new Event('input', { bubbles: true }));
        state.select.dispatchEvent(new Event('change', { bubbles: true }));
        close(state);
    }

    function open(state) {
        if (state.btn.disabled) return;
        if (openOne && openOne !== state) close(openOne);
        state.build(state);
        state.paint(state);
        state.menu.hidden = false;
        state.btn.setAttribute('aria-expanded', 'true');
        place(state);
        openOne = state;
        var rows = options(state);
        var at = rows.findIndex(function (el) {
            return el.getAttribute('aria-selected') === 'true';
        });
        setActive(state, at < 0 ? 0 : at);
    }

    function close(state) {
        state.menu.hidden = true;
        state.btn.setAttribute('aria-expanded', 'false');
        state.active = -1;
        if (openOne === state) openOne = null;
    }

    /** Under the button, or above it when there is no room below. */
    function place(state) {
        var r = state.btn.getBoundingClientRect();
        var menu = state.menu;
        menu.style.minWidth = r.width + 'px';
        menu.style.left = r.left + 'px';
        var below = window.innerHeight - r.bottom;
        var h = menu.offsetHeight;
        if (below < h + 8 && r.top > below) {
            menu.style.top = Math.max(8, r.top - h - 4) + 'px';
        } else {
            menu.style.top = (r.bottom + 4) + 'px';
        }
    }

    function setActive(state, i) {
        var rows = options(state);
        if (!rows.length) return;
        i = Math.max(0, Math.min(rows.length - 1, i));
        rows.forEach(function (el, n) { el.classList.toggle('is-active', n === i); });
        state.active = i;
        var el = rows[i];
        var m = state.menu;
        if (el.offsetTop < m.scrollTop) m.scrollTop = el.offsetTop;
        else if (el.offsetTop + el.offsetHeight > m.scrollTop + m.clientHeight) {
            m.scrollTop = el.offsetTop + el.offsetHeight - m.clientHeight;
        }
    }

    var typed = '', typedAt = 0;

    function onKey(state, e) {
        var rows = options(state);
        var openNow = !state.menu.hidden;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                openNow ? setActive(state, state.active + 1) : open(state);
                return;
            case 'ArrowUp':
                e.preventDefault();
                openNow ? setActive(state, state.active - 1) : open(state);
                return;
            case 'Home':
                if (openNow) { e.preventDefault(); setActive(state, 0); }
                return;
            case 'End':
                if (openNow) { e.preventDefault(); setActive(state, rows.length - 1); }
                return;
            case 'Enter':
            case ' ':
                e.preventDefault();
                if (!openNow) { open(state); return; }
                var el = rows[state.active];
                if (el && el.getAttribute('aria-disabled') !== 'true') {
                    choose(state, el.dataset.value);
                    state.btn.focus();
                }
                return;
            case 'Escape':
                if (openNow) { e.preventDefault(); close(state); state.btn.focus(); }
                return;
            case 'Tab':
                if (openNow) close(state);
                return;
        }

        // Type-ahead, the way a native select behaves.
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            var now = Date.now();
            typed = (now - typedAt < 900) ? typed + e.key : e.key;
            typedAt = now;
            if (!openNow) open(state);
            var want = typed.toLowerCase();
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].textContent.trim().toLowerCase().indexOf(want) === 0) {
                    setActive(state, i);
                    break;
                }
            }
        }
    }

    /* Assignment from script. `select.value = x` fires nothing, and pages here
       rely on doing exactly that, so the property is intercepted on each
       skinned element and the original behaviour is kept underneath. */
    function hookValue(state) {
        var el = state.select;
        ['value', 'selectedIndex'].forEach(function (prop) {
            var proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
            if (!proto || !proto.set) return;
            Object.defineProperty(el, prop, {
                configurable: true,
                get: function () { return proto.get.call(this); },
                set: function (v) { proto.set.call(this, v); paint(state); }
            });
        });
        // `disabled` is toggled by a few pages too.
        new MutationObserver(function () { paint(state); })
            .observe(el, { attributes: true, attributeFilter: ['disabled'] });
    }

    /* ======================= a dropdown that can be typed in ================
       An `<input list="...">` is the right control for a short list somebody
       may also want to search by typing, but the browser draws the suggestions
       itself: no chevron, so it does not read as a dropdown at all, and the
       popup that eventually appears is the operating system's again, the very
       thing the skin above exists to avoid.

       This gives it the same clothes: the handle on the right, the site's own
       list, the same keyboard. The input is left in place as the real control,
       so it keeps its name, its value, its `required` flag and its validity,
       and the page's own script sees exactly what it saw before.

       Opt in with `data-combo`. The `<datalist>` stays in the markup and
       remains the source of the options, so a page that rebuilds the list is
       followed, and nothing is lost if this script never runs. */
    function combo(input) {
        if (input.dataset.noSkin !== undefined) return;
        if (input.classList.contains('is-combo')) return;
        var data = document.getElementById(input.getAttribute('list') || '');
        if (!data) return;

        var id = 'sel' + (++seq);
        var wrap = document.createElement('div');
        wrap.className = 'sel sel-combo';

        /* Out of the tab order, and hidden from a reader: the input beside it
           is the control, and this only opens what the keyboard opens with the
           down arrow anyway. */
        var arrow = document.createElement('button');
        arrow.type = 'button';
        arrow.className = 'sel-arrow';
        arrow.tabIndex = -1;
        arrow.setAttribute('aria-hidden', 'true');
        arrow.innerHTML = CHEV;

        var menu = document.createElement('div');
        menu.className = 'sel-menu';
        menu.id = id + 'm';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        wrap.appendChild(arrow);
        document.body.appendChild(menu);            // fixed: nothing can clip it
        input.classList.add('is-combo', 'sel-field');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', id + 'm');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('autocomplete', 'off');
        /* Taken off only now that the list is drawn here: with the attribute
           still on, Chrome shows its own popup on top of this one. Kept as
           data-list so the list can be found again. */
        input.dataset.list = data.id;
        input.removeAttribute('list');

        var state = { input: input, data: data, btn: input, menu: menu, active: -1,
                      build: comboBuild, paint: comboPaint, filter: false };
        input._sel = state;
        menu._sel = state;

        comboBuild(state);
        comboPaint(state);

        // The caret should not move because the handle was clicked.
        arrow.addEventListener('mousedown', function (e) { e.preventDefault(); });
        arrow.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!state.menu.hidden) { close(state); return; }
            /* The handle is the dropdown, so it shows the whole list whatever
               has been typed; typing is what narrows it. */
            state.filter = false;
            open(state);
            input.focus();
        });
        input.addEventListener('click', function (e) {
            e.stopPropagation();
            if (state.menu.hidden) { state.filter = false; open(state); }
        });
        input.addEventListener('input', function () {
            state.filter = true;
            open(state);
        });
        input.addEventListener('keydown', function (e) { onComboKey(state, e); });

        new MutationObserver(function () { comboBuild(state); comboPaint(state); })
            .observe(data, { childList: true, subtree: true });
    }

    /** The list, narrowed to what has been typed if anything has. */
    function comboBuild(state) {
        var menu = state.menu;
        var want = state.filter ? state.input.value.trim().toLowerCase() : '';
        menu.innerHTML = '';
        var shown = 0;
        Array.prototype.forEach.call(state.data.querySelectorAll('option'), function (o) {
            /* A datalist option carries its text in `value`; `label` or child
               text is optional, and is the label when it is there. */
            var value = (o.value || o.textContent || '').trim();
            var text  = (o.getAttribute('label') || o.textContent || '').trim() || value;
            if (value === '') return;
            if (want && (value + ' ' + text).toLowerCase().indexOf(want) < 0) return;
            menu.appendChild(comboRow(state, value, text));
            shown++;
        });
        if (!shown) {
            var none = document.createElement('div');
            none.className = 'sel-none';
            none.textContent = 'Nothing on the list matches that.';
            menu.appendChild(none);
        }
    }

    function comboRow(state, value, text) {
        var el = document.createElement('div');
        el.className = 'sel-opt';
        el.setAttribute('role', 'option');
        el.innerHTML = TICK + '<span></span>';
        el.lastChild.textContent = text;
        el.dataset.value = value;
        // Picked on the click, so the box must not lose the caret on the press.
        el.addEventListener('mousedown', function (e) { e.preventDefault(); });
        el.addEventListener('click', function (e) {
            e.stopPropagation();
            comboChoose(state, value);
        });
        el.addEventListener('mousemove', function () {
            setActive(state, options(state).indexOf(el));
        });
        return el;
    }

    /** Tick whichever row is what the box already says. */
    function comboPaint(state) {
        var now = state.input.value.trim().toLowerCase();
        options(state).forEach(function (el) {
            el.setAttribute('aria-selected', el.dataset.value.toLowerCase() === now);
        });
    }

    function comboChoose(state, value) {
        var el = state.input;
        el.value = value;
        /* Told the way a page expects to be told. Both, because a page that
           watches a text box watches `input`, and one that watches a dropdown
           watches `change`; neither fires on assignment. */
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        close(state);
        state.filter = false;
        el.focus();
    }

    function onComboKey(state, e) {
        var openNow = !state.menu.hidden;
        var rows = options(state);

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (openNow) setActive(state, state.active + 1);
                else { state.filter = false; open(state); }
                return;
            case 'ArrowUp':
                e.preventDefault();
                if (openNow) setActive(state, state.active - 1);
                else { state.filter = false; open(state); }
                return;
            case 'Home':
            case 'End':
                return;            // the caret's, in a box that can be typed in
            case 'Enter':
                /* Only while the list is open: with it closed this is the
                   submit the form is expecting, and taking that would be a
                   dropdown stopping somebody from sending the form. */
                if (!openNow) return;
                e.preventDefault();
                if (rows[state.active]) comboChoose(state, rows[state.active].dataset.value);
                else close(state);
                return;
            case 'Escape':
                if (openNow) { e.preventDefault(); e.stopPropagation(); close(state); }
                return;
            case 'Tab':
                if (openNow) close(state);
                return;
        }
    }

    function skinAll(root) {
        (root || document).querySelectorAll('select:not(.is-skinned)').forEach(skin);
        (root || document).querySelectorAll('input[list][data-combo]:not(.is-combo)')
            .forEach(combo);
    }

    document.addEventListener('click', function () { if (openOne) close(openOne); });
    window.addEventListener('resize', function () { if (openOne) place(openOne); });
    window.addEventListener('scroll', function () { if (openOne) place(openOne); }, true);

    document.addEventListener('DOMContentLoaded', function () {
        skinAll();
        // Selects added later: the upload form and the review desk both do this.
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                for (var j = 0; j < records[i].addedNodes.length; j++) {
                    var n = records[i].addedNodes[j];
                    if (n.nodeType !== 1) continue;
                    if (n.tagName === 'SELECT') skin(n);
                    else if (n.tagName === 'INPUT') {
                        if (n.hasAttribute('list') && n.dataset.combo !== undefined) combo(n);
                    } else skinAll(n);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
</script>
