<?php
/**
 * Result view and density, in the profile menu.
 *
 * The two controls Settings > Appearance already carries, put where somebody
 * reading a list of papers actually wants them: Result view and Result density. Printed by
 * `view_switcher()` into both profile menus, the avatar dropdown and the phone
 * sheet, the way `zen_switch()` prints one control into both.
 *
 * **It is not a second source of truth.** The keys, the attributes and the
 * clamp are the ones Quick Settings uses:
 *
 *   papel_view     -> html[data-view]     cards | rows | shelf | bookshelf
 *   papel_density  -> html[data-density]  default | comfortable | compact
 *
 * and the radios carry the names Quick Settings gives them (`qs_view`,
 * `qs_density`), so `syncQuickSettings()` in includes/browse_console_js.php
 * re-ticks these copies with no change at all, and a choice made in either
 * place is one act.
 *
 * Four things here are not guessable from the code, each of them a trap this
 * file is written around:
 *
 * - **The avatar dropdown stops every click inside itself from bubbling**
 *   (includes/site_footer.php), which is how pressing the menu does not close
 *   the menu. So a document listener in the ordinary phase never hears a
 *   button in here at all. Zen Mode's switch is delegated in the **capture**
 *   phase for this reason, and the `[data-view-tour]` handler in
 *   includes/view_welcome.php was moved to the capture phase when this was
 *   added. A `change` event is a different event and is not stopped, which is
 *   why the radios below need no such care.
 * - **The radios live in a `<form>` of their own.** Radios with the same name
 *   belong to one group per form owner, so outside a form this menu's
 *   "Compact" and the Quick Settings panel's "Compact" would be the same
 *   radio, and choosing here would leave that panel showing nothing selected.
 *   The context menu in browse_console_js.php is a form for the same reason.
 * - **Hardbounds and Bookshelf are offered only where a shelf can be drawn**,
 *   which a console marks on #mainCol, or My Library marks on its view root.
 *   That rule is `shelfOffered()` in includes/browse_console_js.php and the
 *   pre-paint script in includes/browse_console.php; this is its third copy,
 *   and all three have to keep agreeing. A page with no shelf offers Cards
 *   and Rows, and a stored shelf reads as Cards there, exactly as it does in
 *   the other two.
 * - **The options are built in script, not printed here.** Whether a shelf is
 *   on offer is a fact about the page's own column, and this file is required
 *   from site_head.php, which runs before that column exists.
 *
 * Added 15 November 2026 at the owner's request.
 */

if (!function_exists('view_switcher')) {

/**
 * The block, for the two profile menus. The phone sheet uses a little more
 * padding than the avatar dropdown.
 */
function view_switcher(string $where = 'dropdown'): void
{
    $sheet = ($where === 'sheet');
    /* A form, and the reason is the radio grouping noted at the top of this
       file. Nothing is ever sent, so Enter inside it is stopped in the script
       below rather than reloading the page. */
    echo '<form class="vs-menu' . ($sheet ? ' vs-menu-sheet' : '') . '" data-vs-form>'
       .     '<div class="vs-group">'
       .         '<span class="vs-label">Result view</span>'
       .         '<div class="vs-seg" data-vs-seg="view"></div>'
       .     '</div>'
       .     '<div class="vs-group">'
       .         '<span class="vs-label">Result density</span>'
       .         '<div class="vs-seg" data-vs-seg="density"></div>'
       .     '</div>'
       . '</form>';
}

}
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.vs-menu {
    padding: .5rem 1rem .625rem;
    border-top: 1px solid var(--border);
    margin-top: .375rem;
}
.vs-menu-sheet { padding: .625rem 1.1rem .75rem; }

.vs-group + .vs-group { margin-top: .5rem; }

.vs-label {
    display: block;
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--grey);
    margin-bottom: .3rem;
}

/* A segmented control, because three words in a row read faster in a menu
   than three stacked radios with their own discs, and the menu is narrow. */
.vs-seg {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    background: var(--cream);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    padding: 2px;
}
.vs-seg input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
}
.vs-seg label {
    flex: 1 1 auto;
    min-width: 0;
    text-align: center;
    padding: .25rem .4rem;
    font-size: .75rem;
    line-height: 1.25;
    color: var(--grey);
    border-radius: 3px;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s, color .15s;
}
.vs-seg label:hover { background: var(--white); color: var(--ink); }

/* The fill is --maroon-surface and never --maroon: under Old Night --maroon is
   --accent-light, which is plain white, so a white word on a --maroon fill is
   white on white. The same note is in CLAUDE.md under Styling.

   The letters are a literal #fff and not --white, which is the one place this
   file does not read a token, for the reason .btn-sm-maroon and the
   accessibility tab do the same: --maroon-surface is a real maroon in both
   palettes (#6B0F0F in Old Classic, #6E2E2E in Old Night) and does not lift,
   while --white under Old Night is the dark surface colour #2D2F32. Lettered
   in --white the chosen segment measured 1.5:1 on its own fill in the dark
   palette, which is a word nobody can read. */
.vs-seg input[type="radio"]:checked + label {
    background: var(--maroon-surface);
    color: #fff;
    font-weight: 600;
}
/* Keyboard focus has to be visible on a control whose input is clipped away. */
.vs-seg input[type="radio"]:focus-visible + label {
    outline: 2px solid var(--maroon-surface);
    outline-offset: 1px;
}

/* Four options do not fit one line in a 250px menu, so the shelf views take a
   second row rather than being squeezed to three letters each. */
.vs-seg[data-vs-count="4"] label { flex-basis: calc(50% - 2px); }

/* The dropdown is 250px at its narrowest, which is one or two characters short
   of "Comfortable" beside two others. */
.user-dropdown:has(.vs-menu) { min-width: 286px; }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* One handler for every copy of these controls, delegated on the document, so
   a menu rebuilt in script does not lose its wiring. */
(function () {
    var VIEW_KEY    = 'papel_view';
    var DENSITY_KEY = 'papel_density';
    var seq = 0;

    var DENSITY_OPTIONS = [['default', 'Default'],
                           ['comfortable', 'Comfortable'],
                           ['compact', 'Compact']];

    function getStored(key, fallback) {
        try { return localStorage.getItem(key) || fallback; } catch (err) { return fallback; }
    }
    function store(key, value) {
        try { localStorage.setItem(key, value); } catch (err) {}
    }

    /* The twin of shelfOffered() in includes/browse_console_js.php and of the
       pre-paint script in includes/browse_console.php. Change all three or
       none: a page that offers a shelf here and clamps it to cards there
       would show a reader a view they cannot have. */
    function shelfOffered() {
        var col = document.querySelector('#mainCol[data-view-console]');
        return (!!col && col.getAttribute('data-view-console') === 'shelf')
            || !!document.querySelector('#libSaved[data-view-console="shelf"], #libRequestView[data-view-console="shelf"]');
    }
    function libraryView() {
        return !!document.querySelector('#libSaved, #libRequestView');
    }
    function viewOptions() {
        var o = libraryView() ? [['rows', 'Rows']] : [['cards', 'Cards'], ['rows', 'Rows']];
        // "shelf" is the value Hardbounds has always been stored under.
        if (shelfOffered()) { o.push(['shelf', 'Hardbounds'], ['bookshelf', 'Bookshelf']); }
        return o;
    }
    function asView(v) {
        if (v === 'rows') { return 'rows'; }
        if ((v === 'shelf' || v === 'bookshelf') && shelfOffered()) { return v; }
        return libraryView() ? 'rows' : 'cards';
    }
    function currentView() {
        return asView(getStored(VIEW_KEY, '') || getStored('papel_desk_view', ''));
    }

    function fill(seg, name, options, current) {
        if (seg.childElementCount) { return; }          // already built
        seg.setAttribute('data-vs-count', String(options.length));
        options.forEach(function (opt) {
            var id = 'vs' + (++seq);
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = name;
            input.value = opt[0];
            input.id = id;
            input.checked = (opt[0] === current);
            var label = document.createElement('label');
            label.setAttribute('for', id);
            label.textContent = opt[1];
            seg.appendChild(input);
            seg.appendChild(label);
        });
    }

    function build() {
        var view    = currentView();
        var density = getStored(DENSITY_KEY, 'default');
        document.querySelectorAll('[data-vs-form]').forEach(function (form) {
            var v = form.querySelector('[data-vs-seg="view"]');
            var d = form.querySelector('[data-vs-seg="density"]');
            if (v) { fill(v, 'qs_view', viewOptions(), view); }
            if (d) { fill(d, 'qs_density', DENSITY_OPTIONS, density); }
        });
    }

    /* Both attributes on <html>, for a page that has no Quick Settings panel
       of its own to have set them: My Library, a paper's page, the upload
       form. On a page that does have one this writes what is already there. */
    function reflect() {
        var el = document.documentElement;
        el.setAttribute('data-view', currentView());
        el.setAttribute('data-density', getStored(DENSITY_KEY, 'default'));
    }

    function tick(name, value) {
        document.querySelectorAll('input[name="' + name + '"]').forEach(function (i) {
            i.checked = (i.value === value);
        });
    }

    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input || !input.name || !input.closest) { return; }
        // Only this menu's own controls: every other copy has its own handler.
        if (!input.closest('[data-vs-form]')) { return; }

        if (input.name === 'qs_view') {
            var view = asView(input.value);
            document.documentElement.setAttribute('data-view', view);
            store(VIEW_KEY, view);
            tick('qs_view', view);
        } else if (input.name === 'qs_density') {
            document.documentElement.setAttribute('data-density', input.value);
            store(DENSITY_KEY, input.value);
            tick('qs_density', input.value);
        } else {
            return;
        }

        /* So the Quick Settings panel, the context menu and the Appearance tab
           all show what was just chosen. Absent on a page with no console,
           which is most of them. */
        if (window.papelSyncQuickSettings) { window.papelSyncQuickSettings(); }
    });

    // Nothing to send: Enter on a radio would otherwise reload the page.
    document.addEventListener('submit', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-vs-form')) {
            e.preventDefault();
        }
    });

    function start() { build(); reflect(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
</script>
