<?php
/**
 * "How would you like to browse?" - the one-time chooser a new account meets.
 *
 * Shown once, on the first page after the password gate (see
 * pages/change_password.php, which sets the session flag this reads), because
 * that is the first moment there is an account to have a preference. It offers
 * the four ways the repository and the dashboards draw a list of papers -
 * Cards, Rows, Hardbounds and Bookshelf - and Zen Mode beside them, each with
 * a picture of the real thing rather than a description of it: "Bookshelf" is
 * not a word anybody can picture, and a shelf of spines is.
 *
 * It is an offer, not a gate. Everything already works (Cards is the default),
 * Done closes it, Escape closes it, and it never asks again. The same dialog
 * opens from Settings > Appearance for anybody who wants another look, which
 * is also where somebody who closed it without choosing goes.
 *
 * Three things worth knowing:
 *
 * - **The pictures are real screenshots**, captured from the running
 *   repository, in both palettes, and swapped by CSS on data-mode. They live
 *   in assests/images/views/ and are 560px wide thumbnails, quantised to 256
 *   colours: about 35KB each, where the full-colour PNGs were 90. They are the
 *   app as it was on 14 November 2026; recapture them when a view is
 *   redesigned, with the script in the notes rather than by hand.
 * - **The choice is written the way Quick Settings writes it** - the
 *   `papel_view` key, `data-view` on <html>, and the other copies of the
 *   switch re-ticked - but *not* through the qs_view handler in
 *   browse_console_js.php, because that one clamps a shelf to Cards on a page
 *   that cannot draw one. The gate lands on whatever role_home() says, and a
 *   review desk draws no shelf: handing the clamp a choice made for the
 *   repository would quietly store "cards". So the key is written here and
 *   the attribute only where the page offers it.
 * - **Zen Mode is the switch itself** (zen_switch(), includes/zen_mode.php),
 *   so there is one control for it on the site and this dialog cannot drift
 *   from the profile menu's copy.
 */

/* Read once, and cleared, so the dialog opens on the first page after the gate
   and not on every page from then on. A page that never reaches the footer
   (the gate itself) therefore cannot consume it either. */
$PAPEL_VIEW_TOUR_OPEN = !empty($_SESSION['papel_view_tour']);
if ($PAPEL_VIEW_TOUR_OPEN) { unset($_SESSION['papel_view_tour']); }

$PAPEL_VIEW_TOUR_SHOTS = BASE_URL . '/assests/images/views/';
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The window. A <dialog>, for the same reasons the book viewer is one: the
   top layer with no z-index to pick, the focus held inside it, and Escape for
   free. margin: auto is stated because the site's reset takes away the margin
   a modal dialog centres itself with. */
.vw[open] {
    margin: auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
    color: var(--ink);
    width: min(60rem, calc(100vw - 2.5rem));
    max-height: calc(100vh - 2.5rem);
    overflow: auto;
    box-shadow: 0 24px 64px rgba(0, 0, 0, .32);
}
.vw::backdrop { background: rgba(20, 10, 10, .55); }
.vw-card { padding: 1.5rem 1.5rem 1.25rem; }
.vw-head h2 {
    font-family: var(--font-head);
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--maroon);
    margin: 0 0 .25rem;
}
.vw-head p { font-size: .8125rem; color: var(--grey); line-height: 1.6; margin: 0 0 1.25rem; }

.vw-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .875rem;
    margin-bottom: 1rem;
}
/* One option: the picture, then its name and the one line that says what it
   is for. The whole tile is the control. */
.vw-opt {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    padding: .5rem .5rem .75rem;
    border: 1.5px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
    font-family: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.vw-opt:hover { border-color: var(--soft-maroon); background: var(--cream); }
/* Chosen: the accent, filled from --maroon-surface and lettered in --maroon,
   the rule written down under Styling - --maroon is plain white under Old
   Night, so a tile filled from it would letter white on white. */
.vw-opt[aria-pressed="true"] {
    border-color: var(--maroon-surface);
    background: color-mix(in srgb, var(--maroon-surface) 10%, var(--white));
}
.vw-opt[aria-pressed="true"] .vw-name { color: var(--maroon); }
.vw-shot {
    display: block;
    width: 100%;
    height: auto;
    border-radius: calc(var(--r-card, 8px) - 2px);
    border: 1px solid var(--border);
}
/* One screenshot per palette, so the picture is of the site the reader is
   looking at rather than of the other one. */
html[data-mode="dark"] .vw-shot-light,
html:not([data-mode="dark"]) .vw-shot-dark { display: none; }
.vw-name {
    font-family: var(--font-head);
    font-size: .875rem;
    font-weight: 600;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: .375rem;
}
.vw-name .material-symbols-outlined { font-size: 18px; color: var(--maroon); }
.vw-note { font-size: .75rem; color: var(--grey); line-height: 1.5; }

/* Zen sits apart from the four: it is not a fifth way of drawing the list, it
   is the furniture going away, and it can be on with any of them. */
.vw-zen {
    display: grid;
    grid-template-columns: minmax(0, 18rem) minmax(0, 1fr);
    gap: 1rem;
    align-items: center;
    padding: .875rem;
    border: 1.5px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--cream);
}
.vw-zen-text { display: flex; flex-direction: column; gap: .25rem; }
.vw-zen-row { display: flex; align-items: center; gap: .75rem; margin-top: .25rem; }
.vw-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    margin-top: 1.25rem;
}
.vw-foot .vw-where { font-size: .75rem; color: var(--grey); }

@media (max-width: 760px) {
    .vw-grid { grid-template-columns: minmax(0, 1fr); }
    .vw-zen { grid-template-columns: minmax(0, 1fr); }
    .vw-card { padding: 1.25rem 1rem 1rem; }
    .vw-foot { flex-direction: column; align-items: stretch; }
    .vw-foot .vw-where { order: 2; text-align: center; }
}
</style>

<dialog class="vw" id="viewWelcome" aria-labelledby="vwTitle"
        <?= $PAPEL_VIEW_TOUR_OPEN ? 'data-open="1"' : '' ?>>
    <form method="dialog" class="vw-card">
        <div class="vw-head">
            <h2 id="vwTitle">How would you like to browse?</h2>
            <p>Four ways to show a list of papers. Pick the one you like - the page
               behind this changes as you choose, so you can see it before you decide.
               You can change your mind any time in Settings&nbsp;&rsaquo;&nbsp;Appearance.</p>
        </div>

        <div class="vw-grid">
            <?php
            /* The four, in the order Quick Settings lists them. The stored
               value for Hardbounds is still 'shelf': it was called Shelf until
               Bookshelf arrived beside it, and renaming the value would put
               every device that had chosen it back on Cards. */
            $views = [
                ['cards', 'Cards', 'view_agenda', 'The whole record at a glance: title, authors, and what has happened to it.'],
                ['rows', 'Rows', 'table_rows', 'One line per paper, so more of them fit on the screen at once.'],
                ['shelf', 'Hardbounds', 'auto_stories', 'Each paper as the bound copy it is, cover out, coloured by programme.'],
                ['bookshelf', 'Bookshelf', 'book_5', 'The same books stood spine out, the way they sit on a library shelf.'],
            ];
            foreach ($views as [$value, $label, $icon, $note]):
                $slug = $value === 'shelf' ? 'hardbounds' : $value; ?>
                <button type="button" class="vw-opt" data-vw-view="<?= e($value) ?>" aria-pressed="false">
                    <img class="vw-shot vw-shot-light" alt=""
                         src="<?= e($PAPEL_VIEW_TOUR_SHOTS . 'view-' . $slug . '-light.png') ?>"
                         width="560" height="214" loading="lazy" decoding="async">
                    <img class="vw-shot vw-shot-dark" alt=""
                         src="<?= e($PAPEL_VIEW_TOUR_SHOTS . 'view-' . $slug . '-dark.png') ?>"
                         width="560" height="214" loading="lazy" decoding="async">
                    <span class="vw-name">
                        <span class="material-symbols-outlined" aria-hidden="true"><?= e($icon) ?></span>
                        <?= e($label) ?>
                    </span>
                    <span class="vw-note"><?= e($note) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="vw-zen">
            <img class="vw-shot vw-shot-light" alt=""
                 src="<?= e($PAPEL_VIEW_TOUR_SHOTS . 'view-zen-light.png') ?>"
                 width="560" height="223" loading="lazy" decoding="async">
            <img class="vw-shot vw-shot-dark" alt=""
                 src="<?= e($PAPEL_VIEW_TOUR_SHOTS . 'view-zen-dark.png') ?>"
                 width="560" height="223" loading="lazy" decoding="async">
            <div class="vw-zen-text">
                <span class="vw-name">
                    <span class="material-symbols-outlined" aria-hidden="true">self_improvement</span>
                    Zen Mode
                </span>
                <span class="vw-note">Puts the page's furniture away - the breadcrumb strip, the
                    footer, the banner and the side panel - and leaves the paper. It works with
                    any of the four above, and the switch is in your profile menu as well.</span>
                <?php /* The site's one Zen switch, printed by includes/zen_mode.php:
                         a second one here would be a second opinion about what the
                         control is and what it does. */ ?>
                <div class="vw-zen-row"><?php zen_switch('row'); ?><span class="vw-note">Try it</span></div>
            </div>
        </div>

        <div class="vw-foot">
            <span class="vw-where">You can reopen this from Settings &rsaquo; Appearance.</span>
            <button type="submit" value="done" class="btn-sm-maroon" data-vw-done>Done</button>
        </div>
    </form>
</dialog>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var dlg = document.getElementById('viewWelcome');
    if (!dlg) { return; }

    var VIEW_KEY = 'papel_view';
    var SEEN_KEY = 'papel_view_tour_seen';

    function stored() {
        try { return localStorage.getItem(VIEW_KEY) || 'cards'; } catch (e) { return 'cards'; }
    }
    /* Only the repository and the student dashboard can draw a shelf, and they
       say so on their results column. The same question browse_console_js.php
       asks, asked the same way. */
    function shelfOffered() {
        var col = document.querySelector('#mainCol[data-view-console]');
        return !!col && col.getAttribute('data-view-console') === 'shelf';
    }

    function mark(view) {
        dlg.querySelectorAll('[data-vw-view]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.vwView === view ? 'true' : 'false');
        });
    }

    function choose(view) {
        try { localStorage.setItem(VIEW_KEY, view); } catch (e) {}
        /* The attribute only where the page can honour it: a desk that draws
           no shelf would show nothing for Hardbounds, and the reader would be
           choosing against a page that did not change. The stored answer still
           stands, and the repository opens in it. */
        if (view === 'cards' || view === 'rows' || shelfOffered()) {
            document.documentElement.setAttribute('data-view', view);
        }
        document.querySelectorAll('input[name="qs_view"]').forEach(function (i) {
            i.checked = (i.value === view);
        });
        if (window.papelSyncQuickSettings) { window.papelSyncQuickSettings(); }
        mark(view);
    }

    dlg.addEventListener('click', function (e) {
        var opt = e.target.closest ? e.target.closest('[data-vw-view]') : null;
        if (!opt) { return; }
        choose(opt.dataset.vwView);
    });

    function open() {
        mark(stored());
        if (!dlg.open) { dlg.showModal(); }
    }

    /* Settings, and anything else that wants to offer another look. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-view-tour]') : null;
        if (!btn) { return; }
        e.preventDefault();
        open();
    });

    dlg.addEventListener('close', function () {
        try { localStorage.setItem(SEEN_KEY, '1'); } catch (e) {}
    });

    /* The first page after the password gate. The server said so; this only
       waits for the page to be there to change behind it. */
    if (dlg.dataset.open === '1') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', open);
        } else {
            open();
        }
    }

    window.papelViewTour = { open: open };
})();
</script>
