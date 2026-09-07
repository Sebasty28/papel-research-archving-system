<?php
/**
 * Site-styled confirmations for the management pages.
 *
 * Archiving, deleting and resetting an account were all confirmed by the
 * browser's own dialog — a grey box with the site's name at the top, no way to
 * word the consequence properly, and nothing to do with PAPEL to look at. This
 * replaces them with the same dialog the rest of the site uses.
 *
 * The pages' markup already says what each action is, on
 * `.btn-confirm[data-confirm]` and `.form-confirm[data-confirm]`, so nothing
 * there needs changing. This listens in the capture phase, which runs before
 * the page's own handler, and stops that handler from ever reaching its
 * confirm(). When the reader agrees, the form is submitted directly — going
 * back through the click would only summon the old dialog again.
 *
 * Include once before the footer.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.ad-backdrop {
    position: fixed; inset: 0; z-index: 20000;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem; background: rgba(51, 0, 0, .45);
    opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.ad-backdrop.open { opacity: 1; pointer-events: auto; }
.ad-dialog {
    width: 100%; max-width: 26rem; background: var(--white);
    border-radius: var(--r-card, 8px); box-shadow: 0 18px 48px rgba(51, 0, 0, .28); overflow: hidden;
}
.ad-head {
    display: flex; align-items: center; gap: .5rem;
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--maroon);
}
.ad-head .material-symbols-outlined { color: var(--maroon); font-size: 20px; }
.ad-head h2 {
    font-family: var(--font-head); font-size: 1rem; font-weight: 500;
    color: var(--maroon); margin: 0;
}
.ad-body {
    padding: 1.25rem; font-size: .875rem; color: var(--ink); line-height: 1.6;
    font-family: var(--font-body);
}
/* papelShow(): a line of prose, and label/value pairs for the things that were
   changed. The value takes the monospaced face for the same reason the emails
   do — a password has to be read one character at a time. */
.ad-line { margin: 0 0 .75rem; }
.ad-line:last-child { margin-bottom: 0; }
.ad-kv {
    display: flex; gap: .75rem; align-items: baseline;
    padding: .375rem 0; border-top: 1px solid var(--border);
}
.ad-kv-key {
    flex: 0 0 8.5rem; font-size: .75rem; color: var(--grey);
}
.ad-kv-val {
    flex: 1 1 auto; color: var(--ink); font-weight: 500;
    font-family: Consolas, 'Courier New', monospace; letter-spacing: .3px;
    word-break: break-word;
}
.ad-foot { display: flex; justify-content: flex-end; gap: .5rem; padding: 0 1.25rem 1.25rem; }
.ad-btn {
    padding: .5rem 1.1rem; border-radius: var(--r-control, 4px); font-family: var(--font-body);
    font-size: .8125rem; cursor: pointer; border: 1px solid transparent;
}
/* No outline. The border stays in the box at `transparent` from .ad-btn above,
   so the button keeps exactly the size it had and nothing beside it shifts. */
.ad-btn-keep { background: none; color: var(--maroon); }
.ad-btn-keep:hover { background: var(--cream); }
.ad-btn-go { background: var(--maroon-surface); color: #fff; }
.ad-btn-go:hover { background: var(--maroon-surface-hover); }

/* A note about what just happened, shown where the eye already is. */
.ad-toast {
    position: fixed; left: 50%; bottom: 1.5rem; transform: translateX(-50%) translateY(8px);
    z-index: 19000; max-width: min(32rem, calc(100vw - 2rem));
    display: flex; align-items: flex-start; gap: .5rem;
    padding: .75rem 1rem; border-radius: var(--r-card, 8px);
    background: var(--white); border: 1px solid var(--border);
    box-shadow: 0 10px 30px rgba(51, 0, 0, .18);
    font-family: var(--font-body); font-size: .8125rem; color: var(--ink);
    opacity: 0; visibility: hidden; transition: opacity .2s, transform .2s, visibility .2s;
}
.ad-toast.is-open { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
.ad-match { padding-top: 0; }
.ad-match-label {
    display: block; margin-bottom: .375rem;
    font-size: .75rem; font-weight: 500; color: var(--ink);
}
.ad-match input {
    width: 100%; padding: .5rem .625rem;
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    background: var(--white); color: var(--ink);
    font-family: var(--font-body); font-size: .8125rem;
}
.ad-match input:focus { outline: 2px solid var(--maroon); outline-offset: 1px; }
/* Nothing has gone wrong until something has been typed, so the field only
   turns red once it holds text that does not match. */
.ad-match input.is-wrong { border-color: var(--maroon); }
.ad-btn-go[disabled] { opacity: .45; cursor: not-allowed; }

.ad-toast .material-symbols-outlined { font-size: 18px; color: var(--maroon); flex: 0 0 auto; }
.ad-toast.is-good .material-symbols-outlined { color: #1b5e35; }
</style>

<div class="ad-backdrop" id="adDialog" role="dialog" aria-modal="true" aria-labelledby="adTitle">
    <div class="ad-dialog">
        <div class="ad-head">
            <span class="material-symbols-outlined" id="adIcon">help</span>
            <h2 id="adTitle">Are you sure?</h2>
        </div>
        <div class="ad-body" id="adBody"></div>
        <?php /* Only shown when a caller asks for a typed confirmation, so every
                 other dialog keeps its two buttons and nothing else. */ ?>
        <div class="ad-body ad-match" id="adMatchWrap" hidden>
            <label class="ad-match-label" for="adMatch" id="adMatchLabel"></label>
            <input type="text" id="adMatch" autocomplete="off" spellcheck="false">
        </div>
        <div class="ad-foot">
            <button type="button" class="ad-btn ad-btn-keep" id="adNo">Cancel</button>
            <button type="button" class="ad-btn ad-btn-go" id="adYes">Continue</button>
        </div>
    </div>
</div>

<div class="ad-toast" id="adToast" role="status" aria-live="polite">
    <span class="material-symbols-outlined" id="adToastIcon">info</span>
    <span id="adToastText"></span>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var dlg  = document.getElementById('adDialog');
    var body = document.getElementById('adBody');
    var head = document.getElementById('adTitle');
    var icon = document.getElementById('adIcon');
    var yes  = document.getElementById('adYes');
    var no   = document.getElementById('adNo');
    var matchWrap  = document.getElementById('adMatchWrap');
    var matchLabel = document.getElementById('adMatchLabel');
    var matchInput = document.getElementById('adMatch');
    var pending = null;
    var wanted  = null;          // the text that has to be typed, or null

    function close() {
        dlg.classList.remove('open');
        document.body.style.overflow = '';
        pending = null;
        wanted = null;
        matchWrap.hidden = true;
        matchInput.value = '';
        matchInput.classList.remove('is-wrong');
        yes.disabled = false;
    }

    /* Compared with the spacing and capitals taken off, because a name typed
       from memory rarely comes back with both exactly right, and this is a
       check that the reader knows whose account it is, not a spelling test. */
    function tidy(v) { return String(v || '').trim().replace(/\s+/g, ' ').toLowerCase(); }

    function checkMatch() {
        if (wanted === null) return;
        var typed = tidy(matchInput.value);
        var ok = typed === tidy(wanted);
        yes.disabled = !ok;
        matchInput.classList.toggle('is-wrong', typed !== '' && !ok);
    }
    matchInput.addEventListener('input', checkMatch);

    /* Some wording reads as a warning, some as a plain question. The word
       "delete" is the one that should look different from the rest. */
    function ask(el, message) {
        pending = el;
        var destructive = /delete|remove|permanent/i.test(message);
        head.textContent = destructive ? 'This cannot be undone' : 'Please confirm';
        icon.textContent = destructive ? 'delete' : 'help';
        body.textContent = message;
        yes.textContent  = destructive ? 'Yes, do it' : 'Continue';

        wanted = el.getAttribute('data-confirm-match');
        if (wanted) {
            matchLabel.textContent = el.getAttribute('data-confirm-input')
                || 'Type ' + wanted + ' to confirm';
            matchWrap.hidden = false;
            yes.disabled = true;
        }

        dlg.classList.add('open');
        document.body.style.overflow = 'hidden';
        // The field when there is one to fill; otherwise the safe option, which
        // is what should be under the cursor when a single click is all it takes.
        if (wanted) matchInput.focus(); else no.focus();
    }

    /* Capture phase: this runs before the page's own click handler, so the
       browser's confirm() inside that handler is never reached. */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el || el.dataset.adDone === '1') return;

        /* On a form, only a submit asks the question. Without this, every
           control inside a confirming form summons the dialog: the eye buttons
           on the password fields opened it instead of revealing the password.
           A click on the element carrying the attribute is always its own. */
        if (el.matches('form') && e.target !== el) {
            var hit = e.target.closest('button, input');
            var type = hit ? (hit.getAttribute('type') || (hit.tagName === 'BUTTON' ? 'submit' : 'text')) : '';
            if (!hit || type.toLowerCase() !== 'submit') return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        ask(el, el.getAttribute('data-confirm'));
    }, true);

    yes.addEventListener('click', function () {
        if (yes.disabled) return;
        var el = pending;
        close();
        if (!el) return;
        el.dataset.adDone = '1';

        /* Submit the form directly rather than re-clicking: a second click
           would reach the page's handler and its confirm() after all. A submit
           button's own name/value would be lost that way, so it is carried
           over as a hidden field. */
        var form = el.matches('form') ? el : el.closest('form');
        if (!form) { el.click(); return; }

        if (el.name && el.tagName === 'BUTTON') {
            var carry = document.createElement('input');
            carry.type = 'hidden';
            carry.name = el.name;
            carry.value = el.value || '';
            form.appendChild(carry);
        }
        form.submit();
    });

    no.addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    matchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); if (!yes.disabled) yes.click(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dlg.classList.contains('open')) close();
    });

    /* The same dialog with nothing to decide: a title, some lines, one way out.
       Used by the notification list, where the message is worth reading in full
       but there is nowhere to send the reader afterwards.

       Lines given as [label, value] pairs are laid out as a small table, so a
       password or an ID reads as a value rather than as prose. */
    window.papelShow = function (title, lines) {
        pending = null;
        wanted  = null;
        matchWrap.hidden = true;

        head.textContent = title || 'Details';
        icon.textContent = 'info';
        body.textContent = '';

        (lines || []).forEach(function (line) {
            if (Array.isArray(line)) {
                var row = document.createElement('div');
                row.className = 'ad-kv';
                var k = document.createElement('span');
                k.className = 'ad-kv-key';
                k.textContent = line[0];
                var v = document.createElement('span');
                v.className = 'ad-kv-val';
                v.textContent = line[1];
                row.appendChild(k);
                row.appendChild(v);
                body.appendChild(row);
            } else {
                var p = document.createElement('p');
                p.className = 'ad-line';
                p.textContent = line;
                body.appendChild(p);
            }
        });

        // Nothing to confirm, so the one button closes and says as much.
        yes.hidden = true;
        no.textContent = 'Close';
        dlg.classList.add('open');
        document.body.style.overflow = 'hidden';
        no.focus({ preventScroll: true });
    };

    /* Put the two buttons back for the next caller: ask() and papelShow() share
       one dialog, and a confirm opened after a note must not be missing its
       Continue. */
    var restore = function () {
        yes.hidden = false;
        no.textContent = 'Cancel';
    };
    no.addEventListener('click', restore);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) restore(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') restore();
    });

    /* A short note after the page comes back, so the result of what you just
       did is visible without hunting for a banner. */
    window.papelNote = function (message, good) {
        var toast = document.getElementById('adToast');
        document.getElementById('adToastText').textContent = message;
        document.getElementById('adToastIcon').textContent = good ? 'check_circle' : 'info';
        toast.classList.toggle('is-good', !!good);
        toast.classList.add('is-open');
        setTimeout(function () { toast.classList.remove('is-open'); }, 4000);
    };

    /* The page's own flash banner is the message; echo it once as a note too.
       Only one that is actually on screen, though: a hidden alert is not a
       message to anybody. The upload wizard keeps a "Ready to Submit" card
       inside its last step, and matching that one announced it on arrival at
       step 1, on every visit, before the reader had filled in anything. */
    var flash = null;
    Array.prototype.some.call(
        document.querySelectorAll('.alert-success, .alert-danger'),
        function (a) {
            var seen = a.checkVisibility
                ? a.checkVisibility({ opacityProperty: true,
                                      visibilityProperty: true })
                : (a.getBoundingClientRect().width > 0 &&
                   a.getBoundingClientRect().height > 0);
            if (seen) { flash = a; }
            return seen;
        });
    if (flash) {
        var text = flash.textContent.replace(/\s+/g, ' ').trim();
        if (text) window.papelNote(text, flash.classList.contains('alert-success'));
    }
})();
</script>
