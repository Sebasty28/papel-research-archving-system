<?php
/**
 * The Save button: one paper into the reader's library (app/my_library.php),
 * or back out of it.
 *
 * One include for every place a paper can be saved from, so the bookmark looks
 * and behaves the same on the paper's page, on a repository row and on the
 * shelf itself, and there is one script talking to app/save_paper.php.
 *
 * Include inside <head>, after site_head.php, then print a button wherever one
 * belongs with save_paper_button(). The button is only ever printed for a
 * reader who has a library (user_has_library()); the caller checks, because it
 * is the caller that knows whether a list of fifty rows needs the one query
 * saved_paper_ids() makes.
 */

if (!function_exists('save_paper_button')) {
    /**
     * @param string $shape 'icon' for a bookmark on its own (a row, a book),
     *                      'labelled' for the bookmark and the word beside it.
     */
    function save_paper_button(int $paperId, bool $saved, string $shape = 'icon'): string
    {
        $say   = $saved ? 'Remove from My Library' : 'Save to My Library';
        $class = 'save-paper' . ($shape === 'labelled' ? ' is-labelled' : '') . ($saved ? ' is-saved' : '');
        $html  = '<button type="button" class="' . $class . '" data-save-paper="' . $paperId . '"'
               . ' aria-pressed="' . ($saved ? 'true' : 'false') . '" title="' . e($say) . '"'
               . ($shape === 'labelled' ? '' : ' aria-label="' . e($say) . '"') . '>'
               . '<span class="material-symbols-outlined" aria-hidden="true">bookmark</span>';
        if ($shape === 'labelled') {
            $html .= '<span class="save-paper-label">' . ($saved ? 'Saved' : 'Save') . '</span>';
        }
        return $html . '</button>';
    }
}

/* The stylesheet and the script go out once a page, however many times a
   page's own includes ask for this file. */
if (defined('PAPEL_SAVE_PAPER_PRINTED')) { return; }
define('PAPEL_SAVE_PAPER_PRINTED', true);
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* A bookmark, outlined until the paper is saved and filled once it is. The
   same glyph both ways (the font's FILL axis, through --mi-fill), so pressing
   it changes the mark and moves nothing. Tokens only: --maroon is the form
   that lifts on a dark page, which is what a mark needs. */
.save-paper {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: 0; border: 0; background: none;
    font: inherit; color: var(--grey); cursor: pointer;
    border-radius: var(--r-control, 4px);
    transition: color .15s, border-color .15s, background .15s;
}
.save-paper .material-symbols-outlined { font-size: 20px; }
.save-paper:hover { color: var(--maroon); }
.save-paper.is-saved { color: var(--maroon); }
.save-paper.is-saved .material-symbols-outlined { --mi-fill: 1; }
/* While the answer is on its way. Not disabled: a disabled button gives up
   focus, and the reader's place in the page goes with it. */
.save-paper[aria-busy="true"] { opacity: .6; pointer-events: none; }
.save-paper.is-failed { color: var(--bad-text); }

/* With its word, on the paper's page: a control in its own right there. */
.save-paper.is-labelled {
    margin-top: .625rem;
    padding: .3rem .65rem .3rem .45rem;
    border: 1px solid var(--border);
    background: var(--white); color: var(--ink);
    font-size: .8125rem;
}
.save-paper.is-labelled:hover { border-color: var(--soft-maroon); color: var(--maroon); }
.save-paper.is-labelled.is-saved { border-color: var(--soft-maroon); background: var(--cream); color: var(--maroon); }
.save-paper.is-labelled.is-failed { border-color: var(--bad-border); color: var(--bad-text); }

/* ===== On a repository result =====
   The right-hand end, where the rest of the result's controls are. It is a
   child of .paper-item itself rather than of the title or of .paper-side,
   because the two views put it in two different places.

   Cards: the top right corner. Absolute, so it takes no room in the flow and
   the title keeps the whole width it had; the title is given a gutter the
   width of the mark so a long one wraps before reaching it, rather than
   running underneath. */
.paper-item { position: relative; }
.paper-item > .save-paper {
    position: absolute;
    top: .875rem; right: 1.25rem;
    z-index: 1;                       /* over the title's own line box */
}
.paper-item > .save-paper .material-symbols-outlined { font-size: 18px; }
.paper-item:has(> .save-paper) .paper-title { padding-right: 1.75rem; }
/* The density settings move the result's own padding, and the mark sits on
   the same line as the title whatever that padding is. */
[data-density="compact"] .paper-item > .save-paper { top: .55rem; }
[data-density="comfortable"] .paper-item > .save-paper { top: 1.1rem; }

/* Rows: a column of its own at the end of the grid, so the mark lines up
   down the page the way every other field in a row does. Static, not
   absolute: a row is one line and the grid already has a right-hand end. */
html[data-view="rows"] .paper-list .paper-item {
    grid-template-columns: minmax(0, 1fr) minmax(0, 9rem) minmax(0, 10.5rem) minmax(0, 8.5rem) 1.25rem;
}
html[data-view="rows"] .paper-list .paper-item > .save-paper {
    position: static;
    /* The row as well as the column, and the row is why. The mark sits
       between the title and .paper-foot in the markup, where it is read as
       belonging to the paper rather than to the actions at the end. An item
       given only a column is still placed in document order, and placing one
       in column 5 carries the grid's cursor past columns 2 to 4, so everything
       after it (the authors, the date, the actions) was pushed onto a second
       line and the row grew from 45px to 65px. A definite row and column takes
       it out of that sequence altogether, and the rest fill line one as before. */
    grid-row: 1; grid-column: 5;
    justify-self: end; align-self: center;
}
html[data-view="rows"] .paper-list .paper-item .paper-title { padding-right: 0; }
@media (max-width: 900px) {
    /* Under 900px a row drops to two columns (includes/browse_console.php),
       so the fifth is gone and the mark goes back to the corner. */
    html[data-view="rows"] .paper-list .paper-item > .save-paper {
        position: absolute; top: .45rem; right: 1.25rem;
    }
}
/* The hover peek is a copy of the row for reading; a second bookmark in it
   would be a control floating over the one it was copied from. */
.row-peek .save-paper { display: none; }
@media (prefers-reduced-motion: reduce) { .save-paper { transition: none; } }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    if (window.papelSavePaper) { return; }

    var TOKEN = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '') ?>;
    var URL   = <?= json_encode(rtrim(BASE_URL, '/') . '/app/save_paper.php') ?>;

    function buttons(id) {
        return document.querySelectorAll('[data-save-paper="' + String(id).replace(/[^0-9]/g, '') + '"]');
    }

    function paint(btn, saved) {
        var label = btn.querySelector('.save-paper-label');
        var say   = saved ? 'Remove from My Library' : 'Save to My Library';
        btn.classList.toggle('is-saved', saved);
        btn.classList.remove('is-failed');
        btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
        btn.title = say;
        if (label) { label.textContent = saved ? 'Saved' : 'Save'; }
        else { btn.setAttribute('aria-label', say); }
    }
    function paintAll(id, saved) {
        Array.prototype.forEach.call(buttons(id), function (b) { paint(b, saved); });
    }
    function busy(id, on) {
        Array.prototype.forEach.call(buttons(id), function (b) {
            if (on) { b.setAttribute('aria-busy', 'true'); } else { b.removeAttribute('aria-busy'); }
        });
    }

    /* Said on the button, since that is where the reader is looking: the mark
       goes back to what is true and the reason is its tooltip and, where it
       has a word, the word, for long enough to be read. */
    function failed(id, was, message) {
        paintAll(id, was);
        Array.prototype.forEach.call(buttons(id), function (b) {
            var label = b.querySelector('.save-paper-label');
            b.classList.add('is-failed');
            b.title = message;
            if (label) { label.textContent = 'Not saved'; }
            setTimeout(function () { paint(b, was); }, 2600);
        });
    }

    function announce(id, saved) {
        var ev = new CustomEvent('papel:paper-saved', { detail: { paperId: +id, saved: saved } });
        document.dispatchEvent(ev);
        /* A paper's page is also what the repository opens in a window over
           its list (the row preview). Saved in there, the row underneath has
           to hear of it, or its bookmark says the opposite until a reload. */
        if (window.parent && window.parent !== window) {
            try {
                window.parent.document.dispatchEvent(
                    new window.parent.CustomEvent('papel:paper-saved', { detail: { paperId: +id, saved: saved } }));
            } catch (err) {}
        }
    }

    function set(id, want) {
        var was = !want;
        /* Painted before the answer: a bookmark that waits on the network to
           change feels broken, and the answer is nearly always yes. */
        paintAll(id, want);
        busy(id, true);
        if (window.papelLoading) { window.papelLoading.start(); }

        var body = new FormData();
        body.append('_token', TOKEN);
        body.append('paper_id', id);
        body.append('save', want ? '1' : '0');

        return fetch(URL, { method: 'POST', body: body, credentials: 'same-origin',
                            headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    if (!r.ok || !data.success) {
                        throw new Error(data.message || 'The paper could not be saved. Please try again.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                paintAll(id, !!data.saved);
                announce(id, !!data.saved);
                return !!data.saved;
            })
            .catch(function (err) {
                failed(id, was, err && err.message ? err.message : 'The paper could not be saved. Please try again.');
                return was;
            })
            .then(function (now) {
                busy(id, false);
                if (window.papelLoading) { window.papelLoading.done(); }
                return now;
            });
    }

    /* Delegated: repository rows are swapped in by search and paging, and a
       book is made by the shelf's own script, so there is never a moment when
       every button exists to be wired. */
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-save-paper]') : null;
        if (!btn) { return; }
        e.preventDefault();
        if (btn.getAttribute('aria-busy') === 'true') { return; }
        set(btn.getAttribute('data-save-paper'), !btn.classList.contains('is-saved'));
    });

    /* Heard from this page's own buttons and from a preview window inside it. */
    document.addEventListener('papel:paper-saved', function (e) {
        if (e.detail) { paintAll(e.detail.paperId, !!e.detail.saved); }
    });

    window.papelSavePaper = { set: set };
})();
</script>
