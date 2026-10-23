<?php
/**
 * The review checklist, as the Research Adviser fills it in and as everyone else
 * reads it.
 *
 * One editor serves both places the adviser decides: the full review page
 * (app/review_paper.php) and the approve window on the desk
 * (includes/review_console.php). Two hand-written copies of the same boxes were
 * how the eleven fixed ones drifted apart before.
 *
 * What it does, in the browser: a dropdown of ready-made lists (the heading used to
 * be fixed text), and an "Edit sections" mode in which the adviser can rename or
 * remove any section and add their own, for this paper only. It posts
 * ck_template, ck_label[n] and ck_ok[n]; paper_checklist_clean() in config/core.php
 * is what decides what is kept, so nothing here is trusted.
 *
 * What it does not do: save anything. The Approve button on each page does that.
 *
 * Usage (the include is before the page's own script, which needs papelChecklist):
 *
 *     echo review_checklist_editor('rvCk', review_checklist_config($loaded, $type, $ptype));
 *     review_checklist_assets();
 *
 * Everyone who may only read a checklist gets review_checklist_groups_html().
 */

/** The ready-made lists this server can keep, and what the editor needs to know about it. */
function review_checklist_lists(): array
{
    $lists = [];
    foreach (paper_checklist_templates_offered() as $key => $t) {
        $lists[] = ['key' => $key, 'label' => $t['label'], 'items' => $t['items']];
    }
    $ready = paper_checklist_json_ready();
    return [
        'lists'    => $lists,
        'canEdit'  => $ready,
        'notice'   => $ready ? '' : 'Custom sections need a database update that has not been installed on this '
                                  . 'server yet, so only these lists are on offer and they cannot be changed.',
        'max'      => PAPER_CHECKLIST_MAX_ITEMS,
        'maxLabel' => PAPER_CHECKLIST_MAX_LABEL,
    ];
}

/** The default list for a paper, kept to one this server offers. */
function review_checklist_default_key(?string $manuscriptType, ?string $paperType): string
{
    $offered = paper_checklist_templates_offered();
    $key = paper_checklist_default($manuscriptType, $paperType);
    return isset($offered[$key]) ? $key : 'imrad';
}

/** Everything the editor needs for one paper: the lists, and where it starts. */
function review_checklist_config(?array $loaded, ?string $manuscriptType, ?string $paperType): array
{
    $state = paper_checklist_editor_state($loaded, review_checklist_default_key($manuscriptType, $paperType));
    return review_checklist_lists() + ['template' => $state['template'], 'items' => $state['items']];
}

/** The place the editor is drawn into. The script finds it by class and builds the rest. */
function review_checklist_editor(string $id, array $config): string
{
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<div class="ck-mount" id="' . e($id) . '" data-ck="' . e((string)$json) . '"></div>';
}

/**
 * A recorded checklist as the groups of rows the paper pages draw, for whoever may
 * only read it. Meant to sit inside a .pd-check-grid. $returned makes an unticked
 * row read as something missing, as the author's page does for a returned paper.
 */
function review_checklist_groups_html(array $loaded, bool $returned = false): string
{
    ob_start();
    foreach ($loaded['groups'] as $g): ?>
        <div>
            <div class="pd-check-head"><?= e($g['label']) ?><?php if (!empty($loaded['edited'])): ?>
                <span class="ck-tag">adjusted for this paper</span><?php endif; ?></div>
            <?php foreach ($g['items'] as $it): $yes = !empty($it['ok']); ?>
                <div class="pd-check-row <?= $yes ? 'is-yes' : ($returned ? 'is-gap' : 'is-no') ?>">
                    <span class="material-symbols-outlined"><?= $yes ? 'check_circle' : ($returned ? 'error' : 'radio_button_unchecked') ?></span>
                    <span class="pd-check-name"><?= e($it['label']) ?></span>
                    <span class="pd-check-state"><?= $yes ? 'Indicated' : ($returned ? 'Missing' : 'Not indicated') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach;
    return (string)ob_get_clean();
}

/** The editor's styles and script, once per page. */
function review_checklist_assets(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $nonce = function_exists('csp_nonce') ? csp_nonce() : '';
    ?>
<style nonce="<?= $nonce ?>">
.ck-head {
    display: flex; align-items: center; justify-content: space-between; gap: .5rem .875rem; flex-wrap: wrap;
    padding-bottom: .5rem; margin-bottom: .5rem; border-bottom: 1px solid var(--border);
}
.ck-select {
    font-family: inherit; font-size: .8125rem; font-weight: 600; color: var(--maroon);
    background: var(--white); border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .3rem .5rem; max-width: 100%; cursor: pointer;
}
.ck-select:focus { outline: none; border-color: var(--maroon); }
/* The site draws every select as a button inside a full-width .sel wrapper (select_skin.php), so the
   rules above only matter where that script is absent. Left alone the wrapper took the whole row and
   "Edit sections" and "Tick all" wrapped underneath it; it is sized here, and the button is given the
   weight and colour the heading had. */
.ck-head .sel { width: auto; flex: 0 1 17rem; min-width: 12rem; }
.ck-head .sel-btn { font-weight: 600; color: var(--maroon); }
.ck-tools { display: flex; align-items: center; gap: .875rem; }
.ck-link {
    background: none; border: none; padding: 0; cursor: pointer;
    font-family: inherit; font-size: .6875rem; color: var(--grey); text-decoration: underline;
}
.ck-link:hover { color: var(--maroon); }
.ck-link[aria-pressed="true"] { color: var(--maroon); font-weight: 600; }

.ck-row { display: flex; align-items: center; gap: .5rem; padding: .25rem 0; font-size: .8125rem; color: var(--ink); }
.ck-tick { display: flex; align-items: center; gap: .5rem; flex: 1 1 auto; min-width: 0; cursor: pointer; }
.ck-row input[type="checkbox"] { accent-color: var(--maroon); flex: 0 0 auto; }
.ck-name { flex: 1 1 auto; overflow-wrap: anywhere; }
.ck-text, .ck-add-input {
    flex: 1 1 auto; min-width: 0; font-family: inherit; font-size: .8125rem; color: var(--ink);
    background: var(--white); border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .3rem .5rem;
}
.ck-text:focus, .ck-add-input:focus { outline: none; border-color: var(--maroon); }
.ck-text.is-bad { border-color: var(--maroon); background: var(--cream); }
.ck-del {
    background: none; border: none; cursor: pointer; color: var(--grey);
    padding: .125rem; line-height: 0; border-radius: var(--r-control, 4px);
}
.ck-del:hover, .ck-del:focus-visible { color: var(--maroon); }
.ck-del .material-symbols-outlined { font-size: 18px; }
.ck-empty { margin: 0; padding: .5rem 0; font-size: .75rem; color: var(--grey); }

.ck-add { display: flex; gap: .5rem; align-items: center; margin-top: .5rem; }
.ck-foot { display: flex; justify-content: space-between; align-items: center; gap: .5rem; flex-wrap: wrap; margin-top: .5rem; font-size: .6875rem; color: var(--grey); }
.ck-switch {
    display: flex; align-items: center; gap: .5rem .75rem; flex-wrap: wrap; margin-bottom: .5rem;
    padding: .5rem .75rem; background: var(--cream); border-radius: var(--r-card, 8px);
    font-size: .75rem; color: var(--ink);
}
.ck-switch span { flex: 1 1 14rem; }
.ck-notice { margin: 0 0 .5rem; font-size: .6875rem; color: var(--grey); }
.ck-msg { min-height: 1.1em; margin: .35rem 0 0; font-size: .6875rem; color: var(--dark-maroon); }
@media (max-width: 480px) {
    .ck-head { align-items: stretch; }
    .ck-select, .ck-head .sel { flex: 1 1 100%; }
}
</style>
<script nonce="<?= $nonce ?>">
(function (global) {
    'use strict';

    function make(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function tidy(s) { return String(s).replace(/\s+/g, ' ').replace(/^ | $/g, ''); }
    function key(s) { return tidy(s).toLowerCase(); }

    /* The editor for one checklist. cfg: lists [{key,label,items}], template, items
       [{label,ok}], canEdit, notice, max, maxLabel. Returns the handle the page uses
       to read it back (counts, gaps, valid) without knowing how it is drawn. */
    function mount(root, cfg) {
        while (root.firstChild) root.removeChild(root.firstChild);

        var lists = {};
        cfg.lists.forEach(function (l) { lists[l.key] = l; });
        var state = {
            template: lists[cfg.template] ? cfg.template : cfg.lists[0].key,
            rows: [],
            editing: false
        };
        var nextId = 1;
        var listeners = [];
        var pending = null;                       // a list picked while the sections were edited

        (cfg.items || []).forEach(function (it) { state.rows.push({ id: nextId++, label: it.label, ok: !!it.ok }); });
        var max = cfg.max || 20, maxLabel = cfg.maxLabel || 80;

        // ---- the frame ----
        var head = make('div', 'ck-head');
        var select = make('select', 'ck-select');
        select.setAttribute('aria-label', 'Checklist list');
        cfg.lists.forEach(function (l) {
            var o = make('option', '', l.label);
            o.value = l.key;
            select.appendChild(o);
        });
        var tools = make('span', 'ck-tools');
        var editBtn = make('button', 'ck-link', 'Edit sections');
        editBtn.type = 'button';
        editBtn.setAttribute('aria-pressed', 'false');
        var allBtn = make('button', 'ck-link', 'Tick all');
        allBtn.type = 'button';
        if (cfg.canEdit) tools.appendChild(editBtn);
        tools.appendChild(allBtn);
        head.appendChild(select);
        head.appendChild(tools);

        var notice = make('p', 'ck-notice', cfg.notice || '');
        if (!cfg.notice) notice.hidden = true;

        var bar = make('div', 'ck-switch');
        bar.hidden = true;
        var barText = make('span');
        var barYes = make('button', 'btn-sm-maroon', 'Replace');
        var barNo = make('button', 'btn-sm-outline', 'Keep mine');
        barYes.type = 'button';
        barNo.type = 'button';
        bar.appendChild(barText);
        bar.appendChild(barYes);
        bar.appendChild(barNo);

        var listEl = make('div', 'ck-list');
        listEl.setAttribute('role', 'list');

        var addBox = make('div', 'ck-add');
        var addInput = make('input', 'ck-add-input');
        addInput.type = 'text';
        addInput.maxLength = maxLabel;
        addInput.placeholder = 'Add a section';
        addInput.setAttribute('aria-label', 'Name of a section to add');
        var addBtn = make('button', 'btn-sm-outline', 'Add');
        addBtn.type = 'button';
        addBox.appendChild(addInput);
        addBox.appendChild(addBtn);

        var foot = make('div', 'ck-foot');
        var footNote = make('span', '', 'Changes here apply to this paper only.');
        var resetBtn = make('button', 'ck-link', 'Reset to the list');
        resetBtn.type = 'button';
        foot.appendChild(footNote);
        foot.appendChild(resetBtn);

        var msg = make('p', 'ck-msg');
        msg.setAttribute('role', 'status');
        msg.setAttribute('aria-live', 'polite');

        var tplField = make('input');
        tplField.type = 'hidden';
        tplField.name = 'ck_template';

        [head, notice, bar, listEl, addBox, foot, msg, tplField].forEach(function (n) { root.appendChild(n); });

        // ---- state helpers ----
        function say(text) { msg.textContent = text || ''; }
        function changed() { listeners.forEach(function (fn) { fn(); }); }

        function isEdited() {
            var own = lists[state.template].items;
            if (state.rows.length !== own.length) return true;
            for (var i = 0; i < own.length; i++) {
                if (key(state.rows[i].label) !== key(own[i])) return true;
            }
            return false;
        }

        function usable() { return state.rows.filter(function (r) { return tidy(r.label) !== ''; }); }

        function dupe() {
            var seen = {};
            for (var i = 0; i < state.rows.length; i++) {
                var k = key(state.rows[i].label);
                if (k === '') continue;
                if (seen[k]) return state.rows[i].label;
                seen[k] = true;
            }
            return null;
        }

        // ---- drawing ----
        function drawRows(focusId) {
            while (listEl.firstChild) listEl.removeChild(listEl.firstChild);

            if (!state.rows.length) {
                listEl.appendChild(make('p', 'ck-empty',
                    cfg.canEdit ? 'No sections yet. Choose Edit sections to add some.' : 'This list has no sections.'));
            }
            state.rows.forEach(function (r) {
                var row = make('div', 'ck-row');
                row.setAttribute('role', 'listitem');

                var box = make('input');
                box.type = 'checkbox';
                box.name = 'ck_ok[' + r.id + ']';
                box.value = '1';
                box.checked = r.ok;
                box.addEventListener('change', function () { r.ok = box.checked; syncTickAll(); changed(); });

                if (state.editing) {
                    box.setAttribute('aria-label', 'Ticked');
                    var text = make('input', 'ck-text');
                    text.type = 'text';
                    text.name = 'ck_label[' + r.id + ']';
                    text.value = r.label;
                    text.maxLength = maxLabel;
                    text.setAttribute('aria-label', 'Section name');
                    text.dataset.row = r.id;
                    text.addEventListener('input', function () { r.label = text.value; text.classList.remove('is-bad'); say(''); changed(); });
                    text.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addInput.focus(); } });
                    var del = make('button', 'ck-del');
                    del.type = 'button';
                    del.setAttribute('aria-label', 'Remove ' + (tidy(r.label) || 'this section'));
                    del.appendChild(make('span', 'material-symbols-outlined', 'close'));
                    del.addEventListener('click', function () {
                        state.rows = state.rows.filter(function (x) { return x !== r; });
                        say('Removed ' + (tidy(r.label) || 'a section') + '.');
                        redraw(); changed();
                    });
                    row.appendChild(box);
                    row.appendChild(text);
                    row.appendChild(del);
                } else {
                    var tick = make('label', 'ck-tick');
                    tick.appendChild(box);
                    tick.appendChild(make('span', 'ck-name', r.label));
                    var hidden = make('input');
                    hidden.type = 'hidden';
                    hidden.name = 'ck_label[' + r.id + ']';
                    hidden.value = r.label;
                    row.appendChild(tick);
                    row.appendChild(hidden);
                }
                listEl.appendChild(row);
            });

            if (focusId) {
                var f = listEl.querySelector('.ck-text[data-row="' + focusId + '"]');
                if (f) f.focus();
            }
        }

        function syncTickAll() {
            var all = state.rows.length > 0 && state.rows.every(function (r) { return r.ok; });
            allBtn.textContent = all ? 'Clear all' : 'Tick all';
            allBtn.disabled = state.rows.length === 0;
        }

        function redraw(focusId) {
            select.value = state.template;
            tplField.value = state.template;
            editBtn.textContent = state.editing ? 'Done editing' : 'Edit sections';
            editBtn.setAttribute('aria-pressed', state.editing ? 'true' : 'false');
            addBox.hidden = !state.editing;
            foot.hidden = !state.editing;
            drawRows(focusId);
            syncTickAll();
            addBtn.disabled = state.rows.length >= max;
        }

        // ---- changing the list ----
        // A list's sections carry their ticks across by name, so a section both lists
        // share (References) is not ticked twice.
        function applyList(listKey) {
            var before = {};
            state.rows.forEach(function (r) { before[key(r.label)] = r.ok; });
            state.template = listKey;
            state.rows = lists[listKey].items.map(function (label) {
                return { id: nextId++, label: label, ok: !!before[key(label)] };
            });
            if (listKey === 'blank' && cfg.canEdit) state.editing = true;
            say('');
            redraw();
            changed();
        }

        select.addEventListener('change', function () {
            var picked = select.value;
            if (picked === state.template) return;
            if (isEdited() && state.rows.length) {
                pending = picked;
                barText.textContent = 'Switching to ' + lists[picked].label + ' replaces the sections you changed for this paper.';
                bar.hidden = false;
                select.value = state.template;
                return;
            }
            applyList(picked);
        });
        barYes.addEventListener('click', function () {
            bar.hidden = true;
            if (pending) applyList(pending);
            pending = null;
        });
        barNo.addEventListener('click', function () { bar.hidden = true; pending = null; select.value = state.template; });

        allBtn.addEventListener('click', function () {
            var turnOn = state.rows.some(function (r) { return !r.ok; });
            state.rows.forEach(function (r) { r.ok = turnOn; });
            redraw();
            changed();
        });

        editBtn.addEventListener('click', function () {
            state.editing = !state.editing;
            bar.hidden = true; pending = null;
            say('');
            redraw();
            if (state.editing) addInput.focus();
        });

        resetBtn.addEventListener('click', function () {
            bar.hidden = true; pending = null;
            applyList(state.template);
            say('Back to the ' + lists[state.template].label + ' list.');
        });

        function addSection() {
            var label = tidy(addInput.value);
            if (label === '') { addInput.focus(); return; }
            if (state.rows.length >= max) { say('A checklist can hold up to ' + max + ' sections.'); return; }
            var exists = state.rows.some(function (r) { return key(r.label) === key(label); });
            if (exists) { say('"' + label + '" is already on the list.'); addInput.select(); return; }
            var row = { id: nextId++, label: label.slice(0, maxLabel), ok: false };
            state.rows.push(row);
            addInput.value = '';
            say('Added ' + row.label + '.');
            redraw();
            changed();
            addInput.focus();
        }
        addBtn.addEventListener('click', addSection);
        addInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addSection(); }
            else if (e.key === 'Escape') { addInput.value = ''; }
        });

        redraw();

        // ---- what the page reads back ----
        return {
            onChange: function (fn) { listeners.push(fn); },
            counts: function () {
                var rows = usable();
                return { ticked: rows.filter(function (r) { return r.ok; }).length, total: rows.length };
            },
            gaps: function () {
                return usable().filter(function (r) { return !r.ok; }).map(function (r) { return tidy(r.label); });
            },
            /* Fit to approve? If not, says why on the editor and returns false. */
            valid: function () {
                if (!usable().length) {
                    say('Keep at least one section on the checklist, or choose a list.');
                    if (cfg.canEdit) { state.editing = true; redraw(); addInput.focus(); }
                    return false;
                }
                var again = dupe();
                if (again !== null) {
                    say('Two sections are called "' + tidy(again) + '". Rename or remove one.');
                    if (!state.editing) { state.editing = true; redraw(); }
                    return false;
                }
                say('');
                return true;
            },
            destroy: function () { while (root.firstChild) root.removeChild(root.firstChild); }
        };
    }

    var registry = {};
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.ck-mount[data-ck]'), function (node) {
            try { registry[node.id] = mount(node, JSON.parse(node.getAttribute('data-ck'))); }
            catch (err) { node.textContent = 'The checklist could not be shown. Reload the page to try again.'; }
        });
    });

    global.papelChecklist = {
        mount: mount,
        get: function (id) { return registry[id] || null; }
    };
})(window);
</script>
    <?php
}
