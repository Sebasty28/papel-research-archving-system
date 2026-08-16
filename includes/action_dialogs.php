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
    border-radius: 12px; box-shadow: 0 18px 48px rgba(51, 0, 0, .28); overflow: hidden;
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
.ad-foot { display: flex; justify-content: flex-end; gap: .5rem; padding: 0 1.25rem 1.25rem; }
.ad-btn {
    padding: .5rem 1.1rem; border-radius: 6px; font-family: var(--font-body);
    font-size: .8125rem; cursor: pointer; border: 1px solid transparent;
}
.ad-btn-keep { background: none; color: var(--maroon); border-color: var(--soft-maroon); }
.ad-btn-keep:hover { background: var(--cream); }
.ad-btn-go { background: var(--maroon); color: #fff; }
.ad-btn-go:hover { background: var(--dark-maroon); }

/* A note about what just happened, shown where the eye already is. */
.ad-toast {
    position: fixed; left: 50%; bottom: 1.5rem; transform: translateX(-50%) translateY(8px);
    z-index: 19000; max-width: min(32rem, calc(100vw - 2rem));
    display: flex; align-items: flex-start; gap: .5rem;
    padding: .75rem 1rem; border-radius: 10px;
    background: var(--white); border: 1px solid var(--border);
    box-shadow: 0 10px 30px rgba(51, 0, 0, .18);
    font-family: var(--font-body); font-size: .8125rem; color: var(--ink);
    opacity: 0; visibility: hidden; transition: opacity .2s, transform .2s, visibility .2s;
}
.ad-toast.is-open { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
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
    var pending = null;

    function close() {
        dlg.classList.remove('open');
        document.body.style.overflow = '';
        pending = null;
    }

    /* Some wording reads as a warning, some as a plain question. The word
       "delete" is the one that should look different from the rest. */
    function ask(el, message) {
        pending = el;
        var destructive = /delete|remove|permanent/i.test(message);
        head.textContent = destructive ? 'This cannot be undone' : 'Please confirm';
        icon.textContent = destructive ? 'delete' : 'help';
        body.textContent = message;
        yes.textContent  = destructive ? 'Yes, do it' : 'Continue';
        dlg.classList.add('open');
        document.body.style.overflow = 'hidden';
        no.focus();                       // the safe option is under the cursor
    }

    /* Capture phase: this runs before the page's own click handler, so the
       browser's confirm() inside that handler is never reached. */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el || el.dataset.adDone === '1') return;

        e.preventDefault();
        e.stopImmediatePropagation();
        ask(el, el.getAttribute('data-confirm'));
    }, true);

    yes.addEventListener('click', function () {
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
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dlg.classList.contains('open')) close();
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

    // The page's own flash banner is the message; echo it once as a note too.
    var flash = document.querySelector('.alert-success, .alert-danger');
    if (flash) {
        var text = flash.textContent.replace(/\s+/g, ' ').trim();
        if (text) window.papelNote(text, flash.classList.contains('alert-success'));
    }
})();
</script>
