<?php
/**
 * A word before an edit is committed.
 *
 * Creating an account is obviously a change — you filled in an empty form. An
 * edit is not: the boxes were already full, and it is easy to press Save having
 * touched nothing, or having touched more than you meant to. So on the way out
 * this asks, and names what is about to change.
 *
 * If nothing is different it says so and stops, rather than posting a no-op and
 * answering with "details were saved" — which would read as though something
 * had happened.
 *
 * The page tells this script when a row has been loaded, by calling
 * window.papelEditSnapshot(form) at the end of its own edit-mode setup.
 *
 * Borrows the dialog styling from action_dialogs.php, which every management
 * page already includes; only the id differs, so the two do not collide.
 *
 * Include once before the footer, after action_dialogs.php.
 */
?>
<div class="ad-backdrop" id="saveDialog" role="dialog" aria-modal="true" aria-labelledby="saveTitle">
    <div class="ad-dialog">
        <div class="ad-head">
            <span class="material-symbols-outlined" id="saveIcon">save</span>
            <h2 id="saveTitle">Save these changes?</h2>
        </div>
        <div class="ad-body" id="saveBody"></div>
        <div class="ad-foot">
            <button type="button" class="ad-btn ad-btn-keep" id="saveNo">Keep editing</button>
            <button type="button" class="ad-btn ad-btn-go" id="saveYes">Save changes</button>
        </div>
    </div>
</div>

<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* What is about to change, field by field. */
.sd-list { list-style: none; margin: .6rem 0 0; padding: 0; }
.sd-list li { padding: .3rem 0; border-top: 1px solid var(--border); font-size: .8125rem; }
.sd-list li:first-child { border-top: none; }
.sd-field { color: var(--grey); display: block; font-size: .6875rem;
            text-transform: uppercase; letter-spacing: .04em; }
.sd-was { color: var(--grey); text-decoration: line-through; }
.sd-now { color: var(--maroon); font-weight: 500; }
</style>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var dlg   = document.getElementById('saveDialog');
    var body  = document.getElementById('saveBody');
    var head  = document.getElementById('saveTitle');
    var icon  = document.getElementById('saveIcon');
    var yes   = document.getElementById('saveYes');
    var no    = document.getElementById('saveNo');
    var snapshot = null;
    var pending  = null;

    /* Only the fields a person actually edits — the hidden ones carrying the
       action and the row id are bookkeeping, not changes. */
    function fields(form) {
        return [].filter.call(form.elements, function (el) {
            return el.name && el.type !== 'hidden' && !el.disabled &&
                   ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(el.tagName) !== -1;
        });
    }

    function labelFor(el) {
        var lab = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
        var text = lab ? lab.textContent : el.name.replace(/_/g, ' ');
        return text.replace(/\*/g, '').replace(/\s+/g, ' ').trim();
    }

    // Called by the page once it has filled the form with a row.
    window.papelEditSnapshot = function (form) {
        snapshot = {};
        fields(form).forEach(function (el) { snapshot[el.name] = el.value; });
    };

    function changes(form) {
        if (!snapshot) { return null; }          // nothing to compare against
        var out = [];
        fields(form).forEach(function (el) {
            var was = snapshot[el.name];
            if (was === undefined) { return; }
            /* A blank password means "leave it alone", so it is only a change
               when something was typed. */
            if (el.type === 'password' || el.name === 'password') {
                if (el.value !== '') { out.push({ label: labelFor(el), was: null, now: 'a new password' }); }
                return;
            }
            if (el.value !== was) {
                var shown = el;
                if (el.tagName === 'SELECT') {
                    var pick = function (v) {
                        var o = [].filter.call(el.options, function (x) { return x.value === v; })[0];
                        return o ? o.textContent.trim() : v;
                    };
                    out.push({ label: labelFor(el), was: pick(was), now: pick(el.value) });
                } else {
                    out.push({ label: labelFor(shown), was: was, now: el.value });
                }
            }
        });
        return out;
    }

    function esc(s) {
        return String(s === null || s === undefined || s === '' ? '—' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function open() { dlg.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function close() { dlg.classList.remove('open'); document.body.style.overflow = ''; pending = null; }

    document.querySelectorAll('form.js-manage-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var action = form.querySelector('#formAction');
            if (!action || action.value !== 'update_user') { return; }   // creating: nothing to ask

            // The browser has already checked the fields by the time submit fires.
            var diff = changes(form);
            if (diff === null) { return; }

            e.preventDefault();
            pending = form;

            if (diff.length === 0) {
                head.textContent = 'Nothing has changed';
                icon.textContent = 'info';
                body.textContent = 'These details are the same as they were. There is nothing to save.';
                no.textContent = 'Back to editing';
                yes.hidden = true;
            } else {
                head.textContent = 'Save these changes?';
                icon.textContent = 'save';
                var who = document.getElementById('editingWho');
                var rows = diff.map(function (c) {
                    return '<li><span class="sd-field">' + esc(c.label) + '</span>' +
                           (c.was === null ? '<span class="sd-now">' + esc(c.now) + '</span>'
                                           : '<span class="sd-was">' + esc(c.was) + '</span> ' +
                                             '<span class="sd-now">' + esc(c.now) + '</span>') +
                           '</li>';
                }).join('');
                body.innerHTML = (who && who.textContent
                        ? esc(who.textContent) + ' will be updated:'
                        : 'This account will be updated:') +
                    '<ul class="sd-list">' + rows + '</ul>';
                no.textContent = 'Keep editing';
                yes.hidden = false;
            }
            open();
        });
    });

    yes.addEventListener('click', function () {
        var form = pending;
        close();
        if (form) { snapshot = null; form.submit(); }
    });
    no.addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { close(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dlg.classList.contains('open')) { close(); }
    });
})();
</script>
