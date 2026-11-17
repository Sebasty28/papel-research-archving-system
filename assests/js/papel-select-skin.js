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
                set: function (v) { proto.set.call(this, v); state.paint(state); }
            });
        });
        // `disabled` is toggled by a few pages too.
        new MutationObserver(function () { state.paint(state); })
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

    /* ======================= a select that can be typed in ==================
       The upload form's paper type and programme are lists long enough, or
       known well enough, that a student would rather type than scroll. Opt in
       with `data-type-in` on the select (see the note at the top of the file).

       The select stays in place as the real control, exactly as skin() leaves
       it: name, value, `required`, and the page's script reading `.value`. What
       is typed only ever decides which of its options is chosen. */
    function typein(select) {
        if (select.dataset.noSkin !== undefined) return;
        if (select.multiple || select.size > 1) return;
        if (select.classList.contains('is-skinned')) return;

        var id = 'sel' + (++seq);
        var wrap = document.createElement('div');
        wrap.className = 'sel sel-combo sel-typein';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'sel-btn sel-type';
        input.id = id + 'b';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', id + 'm');
        input.setAttribute('autocomplete', 'off');
        input.spellcheck = false;
        var first = select.options[0];
        input.placeholder = (first && first.value === '' ? first.textContent.trim() : '') || 'Type or choose';

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

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(input);
        wrap.appendChild(arrow);
        document.body.appendChild(menu);
        select.classList.add('is-skinned', 'is-typein');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        var label = select.id && document.querySelector('label[for="' + select.id + '"]');
        if (label) input.setAttribute('aria-labelledby', (label.id || (label.id = id + 'l')));
        else {
            var near = wrap.parentNode && wrap.parentNode.querySelector('.form-label');
            if (near) input.setAttribute('aria-label', near.textContent.replace('*', '').trim());
        }

        var field = select.dataset.typeInField ? document.getElementById(select.dataset.typeInField) : null;
        var state = { select: select, btn: input, menu: menu, active: -1, filter: false,
                      other: field ? (select.dataset.typeInOther || '') : '', field: field,
                      build: typeBuild, paint: typePaint };
        input._sel = state;
        menu._sel = state;

        typeBuild(state);
        typePaint(state);
        hookValue(state);
        // The name beside Other is also set from script (a draft coming back), with no event.
        if (field) hookField(state, field);

        arrow.addEventListener('mousedown', function (e) { e.preventDefault(); });
        arrow.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!state.menu.hidden) { close(state); return; }
            state.filter = false;
            open(state);
            input.focus();
        });
        input.addEventListener('click', function (e) {
            e.stopPropagation();
            if (state.menu.hidden) { state.filter = false; open(state); }
        });
        // Clicking into it to type over the answer: the answer is selected, so typing replaces it.
        input.addEventListener('focus', function () { input.select(); });
        input.addEventListener('input', function () {
            state.filter = true;
            state.dirty = true;
            open(state);
        });
        input.addEventListener('keydown', function (e) { onTypeKey(state, e); });
        // Leaving the box settles what was typed into one of the options, or puts the answer back.
        input.addEventListener('blur', function () {
            if (state.dirty) commitTyped(state);
            close(state);
        });

        new MutationObserver(function () { typeBuild(state); typePaint(state); })
            .observe(select, { childList: true, subtree: true });
        select.addEventListener('change', function () { typePaint(state); });
    }

    function typeOptions(state) {
        return Array.prototype.filter.call(state.select.options, function (o) {
            return o.value !== '' && !o.disabled;
        });
    }

    /** The options, narrowed to what has been typed, and the typed name itself when it can be kept. */
    function typeBuild(state) {
        var menu = state.menu;
        var typed = state.btn.value.replace(/\s+/g, ' ').trim();
        var want = state.filter ? typed.toLowerCase() : '';
        menu.innerHTML = '';
        var shown = 0;
        typeOptions(state).forEach(function (o) {
            var text = o.textContent.trim();
            if (want && (text + ' ' + o.value).toLowerCase().indexOf(want) < 0) return;
            menu.appendChild(typeRow(state, o.value, text, null));
            shown++;
        });
        /* Offered only when nothing on the list matches: "feas" is somebody on their way
           to Feasibility Study, not a type of their own. Leaving the box with a name of
           their own still keeps it (commitTyped()). */
        if (state.other && state.filter && typed && !shown) {
            menu.appendChild(typeRow(state, state.other, 'Use \u201c' + typed + '\u201d', typed));
        }
        if (!menu.children.length) {
            var none = document.createElement('div');
            none.className = 'sel-none';
            none.textContent = 'Nothing on the list matches that.';
            menu.appendChild(none);
        }
    }

    function typeRow(state, value, text, typed) {
        var el = document.createElement('div');
        el.className = 'sel-opt' + (typed !== null ? ' is-typed' : '');
        el.setAttribute('role', 'option');
        el.innerHTML = TICK + '<span></span>';
        el.lastChild.textContent = text;
        el.dataset.value = value;
        if (typed !== null) el.dataset.typed = typed;
        el.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the caret
        el.addEventListener('click', function (e) {
            e.stopPropagation();
            typeChoose(state, value, typed);
        });
        el.addEventListener('mousemove', function () {
            setActive(state, options(state).indexOf(el));
        });
        return el;
    }

    /** What the box says when nobody is typing: the answer, or for Other the name given. */
    function typeText(state) {
        var sel = state.select;
        if (state.other && sel.value === state.other && state.field) return state.field.value.trim();
        var chosen = sel.options[sel.selectedIndex];
        return chosen && chosen.value !== '' ? chosen.textContent.trim() : '';
    }

    function typePaint(state) {
        if (!(state.dirty && document.activeElement === state.btn)) {
            state.btn.value = typeText(state);
            state.dirty = false;
        }
        state.btn.disabled = state.select.disabled;
        var sel = state.select;
        options(state).forEach(function (el) {
            var on = el.dataset.value === sel.value
                && (el.dataset.typed === undefined || !state.field || el.dataset.typed === state.field.value.trim());
            el.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function typeChoose(state, value, typed) {
        var changed = state.select.value !== value;
        var sel = state.select;
        if (state.field && state.other) {
            var name = value === state.other ? (typed !== null ? typed : '') : state.field.value;
            if (state.field.value !== name) {
                state.field.value = name;
                state.field.dispatchEvent(new Event('input', { bubbles: true }));
                changed = true;
            }
        }
        sel.value = value;
        state.dirty = false;
        state.filter = false;
        typePaint(state);
        close(state);
        if (changed) {
            sel.dispatchEvent(new Event('input', { bubbles: true }));
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        /* Other with no name yet: the box is where the name goes. */
        if (value === state.other && !typeText(state)) state.btn.focus();
    }

    /* Typed and left: an option whose name it is, the typed name kept as Other when
       this select takes one, or else the answer as it was. */
    function commitTyped(state) {
        var typed = state.btn.value.replace(/\s+/g, ' ').trim();
        state.dirty = false;
        if (typed === typeText(state)) return;
        if (typed === '') { typeChoose(state, '', null); return; }
        var hit = typeOptions(state).filter(function (o) {
            return o.textContent.trim().toLowerCase() === typed.toLowerCase();
        })[0];
        if (hit) { typeChoose(state, hit.value, null); return; }
        if (state.other) { typeChoose(state, state.other, typed); return; }
        typePaint(state);
    }

    function onTypeKey(state, e) {
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
            case 'Enter':
                if (!openNow) return;               // the form's own Enter, as ever
                e.preventDefault();
                var el = rows[state.active];
                if (el) typeChoose(state, el.dataset.value, el.dataset.typed !== undefined ? el.dataset.typed : null);
                else commitTyped(state);
                return;
            case 'Escape':
                if (openNow || state.dirty) {
                    e.preventDefault();
                    e.stopPropagation();
                    state.dirty = false;
                    typePaint(state);               // back to the answer, untouched
                    close(state);
                }
                return;
            case 'Tab':
                if (openNow) close(state);
                return;
        }
    }

    function hookField(state, field) {
        var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        if (!proto || !proto.set) return;
        Object.defineProperty(field, 'value', {
            configurable: true,
            get: function () { return proto.get.call(this); },
            set: function (v) { proto.set.call(this, v); typePaint(state); }
        });
    }

    function skinAll(root) {
        (root || document).querySelectorAll('select[data-type-in]:not(.is-skinned)').forEach(typein);
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
                    if (n.tagName === 'SELECT') (n.dataset.typeIn !== undefined ? typein : skin)(n);
                    else if (n.tagName === 'INPUT') {
                        if (n.hasAttribute('list') && n.dataset.combo !== undefined) combo(n);
                    } else skinAll(n);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
