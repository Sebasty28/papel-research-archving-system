<?php
/**
 * Shared browse-console styles: the search field, results toolbar, Quick
 * Settings dropdown, paper list, loading state, and the right-hand
 * Browse/Filter sidebar cards.
 *
 * Used by archive/index.php (public repository) and the role dashboards so
 * the browse experience stays identical everywhere. Include inside <head>
 * AFTER includes/site_head.php (which supplies the design tokens).
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Cards or rows, applied before the first paint: read it afterwards and a
   list would draw as cards and then collapse, which on a long page is a
   visible lurch. Kept per device, beside the density and the palette the
   rest of Quick Settings remembers.

   papel_desk_view is the key this started life under, when only the review
   desks offered the switch; it is read as a fallback so nobody who set rows
   there is handed cards back. */
(function () {
    var v;
    try { v = localStorage.getItem('papel_view') || localStorage.getItem('papel_desk_view'); }
    catch (e) {}
    document.documentElement.setAttribute('data-view', v === 'rows' ? 'rows' : 'cards');
})();
</script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ===== Search field ===== */
.search-form {
    display: flex;
    align-items: center;
    gap: .875rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    padding: .75rem 1rem;
    box-shadow: var(--shadow-md);
    transition: border-color .2s, box-shadow .2s;
}
.search-form:hover { border-color: var(--soft-maroon); }
.search-form:focus-within {
    /* The full accent rather than the soft tint, because this is now the only
       thing marking focus on the field: the ring on the input itself is turned
       off below. */
    border-color: var(--maroon);
    box-shadow: 0 0 0 3px rgba(177,125,125,.20);
}
/* Magnifier picks up maroon whenever the field is engaged */
.search-form:hover .btn-search-icon,
.search-form:focus-within .btn-search-icon { color: var(--maroon); }
/* The magnifier is the submit control: Figma has no separate button */
.btn-search-icon {
    display: flex;
    align-items: center;
    background: none;
    border: none;
    padding: 0 .875rem 0 .125rem;
    border-right: 1px solid var(--border);
    color: var(--grey);
    cursor: pointer;
    flex-shrink: 0;
    transition: color .2s;
}
.btn-search-icon:hover { color: var(--maroon); }
.search-input {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-family: inherit;
    font-size: .875rem;
    color: var(--ink);
}
.search-input::placeholder { color: var(--grey); }
/* The shared focus ring is deliberately !important, so turning it off for one
   control has to be as well. The container above shows focus for both of them,
   and the ring drawn inside the box was a second rectangle a few pixels in from
   the first. */
.search-input:focus,
.search-input:focus-visible { outline: none !important; }

/* The clear button, which is the browser's own and arrives bright blue. It
   cannot be recoloured as a control, so the glyph is redrawn as a mask and the
   colour comes from background-color, which we do control. */
.search-input::-webkit-search-cancel-button {
    -webkit-appearance: none;
            appearance: none;
    width: .75rem; height: .75rem;
    cursor: pointer;
    background-color: var(--search-clear, #454545);
    opacity: .7;
    transition: opacity .15s ease;
    -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M1.5 1.5l9 9M10.5 1.5l-9 9' stroke='%23000' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") center / contain no-repeat;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M1.5 1.5l9 9M10.5 1.5l-9 9' stroke='%23000' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") center / contain no-repeat;
}
.search-input::-webkit-search-cancel-button:hover { opacity: 1; }
/* Light on a dark field, for the same reason it is dark on a light one: a
   warm parchment grey, to match Old Night's surfaces (10.10:1 on #1E1813). */
html[data-mode="dark"] { --search-clear: #CFC3AE; }

/* ===== Paper list ===== */
/* Row hover bleeds slightly past the column, so the cream block reads as a
   card while the text stays aligned with the heading rule above it. */
.paper-item {
    position: relative;
    padding: .875rem 1.25rem;
    margin: 0 -.75rem;
    border-radius: var(--r-card, 8px);
    transition: background .2s ease;
}
.paper-item:hover { background: var(--cream); }
/* Separator drawn inside the padding so it lines up with the column edges */
.paper-item::after {
    content: '';
    position: absolute;
    left: 1.25rem;
    right: 1.25rem;
    bottom: 0;
    border-bottom: 1px solid var(--border-soft);
}
.paper-item:last-child::after { display: none; }
.paper-title {
    font-family: var(--font-head);
    font-size: .9375rem;
    font-weight: 600;
    line-height: 1.45;
    margin-bottom: .3rem;
}
.paper-title a, .paper-title span { color: var(--ink); text-decoration: none; }
.paper-title a:hover { color: var(--maroon); text-decoration: underline; }

/* Two columns under the title: authors/meta at left, actions at right.
   The right column starts ~62% across so "Login to view details" sits
   level with the authors line and the program level with the meta line. */
.paper-foot {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 38%;
    gap: 1rem;
    align-items: start;
}
.paper-info { min-width: 0; }
.paper-authors {
    font-size: .6875rem;
    color: var(--ink);
    margin-bottom: .15rem;
}
.paper-meta {
    font-size: .6875rem;
    color: var(--ink);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .4rem;
}
/* The dot between date, type and status. --soft-maroon put it at 3.44:1,
   the one thing on the site still under AAA. It is punctuation, so it takes
   the muted text colour rather than an accent. */
.paper-meta .sep { color: var(--grey); }
.paper-side {
    text-align: left;
    font-size: .6875rem;
    line-height: 1.6;
    min-width: 0;
}
.paper-action {
    color: var(--maroon);
    font-weight: 500;
    text-decoration: none;
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
    font-size: inherit;
    cursor: pointer;
    transition: color .2s;
}
.paper-action:hover { color: var(--dark-maroon); text-decoration: underline; }
.paper-program { display: block; color: var(--maroon); }

/* ===== Rows =====
   The block above is built for reading one paper at a time: a title, then
   who wrote it and what it is underneath. A page holding fifty is scanned
   instead, and scanning wants one line each. Nothing is removed from the
   markup, so the switch is instant and an AJAX swap need know nothing
   about it: .paper-foot stops being a box of its own and its two columns
   join the title on one line.

   The switch itself is drawn by browse_console_js.php into whatever Quick
   Settings panel the page has, and the attribute is set above before the
   first paint. */
html[data-view="rows"] .paper-list .paper-item {
    display: grid;
    /* Shares of the line rather than content widths. Letting the middle
       column take what it wanted left the title at 144px of its 716; giving
       the title everything clipped the date mid-word. The title is what the
       row is read by, so it gets the larger share and the rest is capped. */
    grid-template-columns: minmax(10rem, 1.3fr) minmax(0, 1fr) auto;
    align-items: center;
    column-gap: 1rem;
    padding: .45rem 1.25rem;
}
html[data-view="rows"] .paper-list .paper-foot { display: contents; }
/* A row sets its own padding, which would otherwise outrank the density
   rules above and leave that setting doing nothing at all here. */
html[data-view="rows"][data-density="compact"] .paper-list .paper-item { padding: .3rem 1.25rem; }
html[data-view="rows"][data-density="comfortable"] .paper-list .paper-item { padding: .7rem 1.25rem; }
html[data-view="rows"] .paper-list .paper-item .paper-title {
    margin: 0; font-size: .875rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
html[data-view="rows"] .paper-list .paper-item .paper-info {
    display: flex; align-items: baseline; gap: .5rem;
    min-width: 0; overflow: hidden;
}
html[data-view="rows"] .paper-list .paper-item .paper-authors {
    margin: 0; flex: 0 0 auto; min-width: 0; max-width: 10rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* The meta is a flex row that wraps by default. Left to do that inside a
   narrow column it stacks three deep and the row stops being a row. */
html[data-view="rows"] .paper-list .paper-item .paper-meta {
    flex: 0 1 auto; min-width: 0; flex-wrap: nowrap;
    white-space: nowrap; overflow: hidden;
    /* A row of separate spans, so text-overflow has nothing to put an
       ellipsis on: it would cut a word in half at the edge and read as a
       rendering fault. Faded out instead. */
    -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 1.75rem), transparent);
            mask-image: linear-gradient(to right, #000 calc(100% - 1.75rem), transparent);
}
html[data-view="rows"] .paper-list .paper-item .paper-side {
    display: flex; align-items: center; gap: .75rem;
    white-space: nowrap; line-height: 1.4;
}
html[data-view="rows"] .paper-list .paper-item .paper-program { display: inline; }
/* Below this the three columns have nowhere to go, so a row is a block
   again rather than a line squeezed to nothing. */
@media (max-width: 820px) {
    html[data-view="rows"] .paper-list .paper-item { display: block; padding: .875rem 1.25rem; }
    html[data-view="rows"] .paper-list .paper-foot { display: grid; }
    html[data-view="rows"] .paper-list .paper-item .paper-info { display: block; }
    html[data-view="rows"] .paper-list .paper-item .paper-title { white-space: normal; }
}

/* ===== The card behind a row =====
   Rows trade the detail for the overview, so resting on one brings its card
   back for as long as the pointer stays there. It is the card itself,
   cloned: a second rendering of the same facts would be one more thing to
   keep in step with the first, and it would drift.

   Never interactive. Pointer events are off, so the row underneath keeps the
   hover (no flicker as the pointer crosses into it) and nothing in here can
   be clicked by mistake. The buttons that decide something are taken out for
   the same reason: a row has its own, and these would be dead copies. */
.row-peek {
    position: fixed; z-index: 1300;
    pointer-events: none;
    max-width: calc(100vw - 1rem);
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md, 0 6px 18px rgba(51,0,0,.14));
    opacity: 0; transform: translateY(-.25rem);
    transition: opacity .12s ease, transform .12s ease;
}
.row-peek[hidden] { display: none; }
.row-peek.is-open { opacity: 1; transform: none; }
/* The clone keeps its own class, so it is drawn as the card it is; what it
   must not keep is the frame, which this panel is already drawing. */
.row-peek .paper-card,
.row-peek .paper-item {
    margin: 0; border: 0; background: none; box-shadow: none;
    padding: .875rem 1.125rem;
}
.row-peek .paper-item::after { display: none; }
/* Room to read: this is the view that has it. */
.row-peek .paper-title { white-space: normal; overflow: visible; }
/* The link beside the status is named through its own box as well: console_shell
   sets .card-status .paper-action to block, and that sheet loads after this one,
   so an unqualified rule here lost on load order alone. */
.row-peek .rc-actions,
.row-peek .card-actions,
.row-peek .paper-action,
.row-peek .card-status .paper-action,
.row-peek .paper-side .paper-action { display: none; }
@media (prefers-reduced-motion: reduce) {
    .row-peek { transition: none; }
}

/* ===== Member browse console (signed-in only) =====
   Toolbar directly under the search bar + scrollable result well. */
.browse-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    padding: .5rem 0 .625rem;
    font-size: .6875rem;
    color: var(--ink);
}
.toolbar-left, .toolbar-right { display: flex; align-items: center; gap: .25rem; }
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border: none;
    border-radius: var(--r-control, 4px);
    background: none;
    color: var(--maroon);
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
    transition: background .2s, color .2s;
}
.toolbar-btn:hover { background: var(--cream); color: var(--dark-maroon); }
/* --border is all but invisible against a dark surface (1.25:1 measured);
   a disabled control should read as muted, not absent. */
.toolbar-btn.disabled { color: var(--grey); opacity: .65; pointer-events: none; }
.toolbar-btn .material-symbols-outlined { font-size: 18px; }

/* Quick Settings dropdown (Density / Theme) */
.quick-settings { position: relative; }
.quick-settings-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    box-shadow: var(--shadow-md);
    width: 220px;
    z-index: 1000;
    display: none;
    overflow: hidden;
}
.quick-settings-dropdown.open { display: block; }
.qs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .625rem .875rem;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: .8125rem;
    color: var(--ink);
}
.qs-close {
    background: none;
    border: none;
    color: var(--grey);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: var(--r-control, 4px);
    padding: 0;
}
.qs-close:hover { background: var(--cream); color: var(--maroon); }
.qs-section { padding: .625rem .875rem; border-bottom: 1px solid var(--border); }
.qs-section:last-child { border-bottom: none; }
.qs-link {
    display: block;
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
    font-size: .8125rem;
    color: var(--maroon);
    cursor: pointer;
    text-align: left;
    text-decoration: none;
    width: 100%;
}
.qs-link:hover { color: var(--dark-maroon); text-decoration: underline; }
.qs-section-label {
    display: block;
    font-size: .6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--maroon);
    margin-bottom: .4rem;
}
.qs-radio {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .8125rem;
    color: var(--ink);
    padding: .3rem 0;
    cursor: pointer;
}
.qs-radio input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #E2DCDC;
    margin: 0;
    flex-shrink: 0;
    cursor: pointer;
}
.qs-radio input[type="radio"]:checked { background: var(--pup-maroon); }

/* ===== The same choices, on the list itself =====
   Right-clicking a paper opens them where the pointer already is, instead of
   sending the reader across the page to the gear. It is the panel's own
   sections, with the panel's own controls, so there is one setting and two
   places to reach it. */
/* Dressed as the notification menu is (includes/notif_actions.php), since that
   is the site's other right-click menu and two of them should not look like
   two different ideas. */
.qs-context {
    position: fixed;
    z-index: 1400;                 /* over the hover card, which is 1300 */
    width: 200px;
    padding: .25rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: 0 8px 24px rgba(51, 0, 0, .18);
    font-family: var(--font-body);
    overflow: hidden;
}
.qs-context[hidden] { display: none; }
/* Tighter than the panel's, because this one opens under the pointer and a
   tall menu would reach off the bottom of the screen. */
.qs-context .qs-section { padding: .4rem .5rem; }
.qs-context .qs-section:last-child { border-bottom: none; }
.qs-context .qs-section-label { margin-bottom: .25rem; }
.qs-context .qs-radio {
    padding: .3rem .375rem;
    border-radius: var(--r-control, 4px);
}
.qs-context .qs-radio:hover { background: var(--cream); color: var(--maroon); }

/* ===== The record, opened small =====
   One click on a row opens the paper's own page in the middle of the screen,
   at about the size it is read at, and the list stays where it was underneath.
   Two clicks go to the page properly. It is the record page itself in a frame,
   not a second rendering of it: whatever that page shows, this shows, and the
   site frames its own pages by policy (frame-src 'self' in config/core.php).

   The window is capped to the screen it is on, so it never opens larger than
   the room it has. */
.row-view-back {
    position: fixed; inset: 0; z-index: 1500;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
    background: rgba(51, 0, 0, .38);
    opacity: 0; transition: opacity .14s ease;
}
.row-view-back[hidden] { display: none; }
.row-view-back.is-open { opacity: 1; }
.row-view {
    width: min(1690px, 96vw);
    height: min(766px, 88vh);
    display: flex; flex-direction: column; overflow: hidden;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: 0 20px 56px rgba(51, 0, 0, .3);
    transform: translateY(.4rem);
    transition: transform .14s ease;
}
.row-view-back.is-open .row-view { transform: none; }
.row-view-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .5rem .75rem;
    border-bottom: 1px solid var(--border);
    background: var(--cream);
}
.row-view-name {
    flex: 1 1 auto; min-width: 0;
    font-family: var(--font-head); font-size: .8125rem; font-weight: 600; color: var(--ink);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* No box of its own: these sit on a tinted strip of their own already, and an
   outline around each made the head read as three panels rather than one. */
.row-view-btn {
    flex: 0 0 auto;
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .25rem .5rem;
    border: 0; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon);
    font-family: inherit; font-size: .75rem; text-decoration: none; cursor: pointer;
}
.row-view-btn:hover { background: var(--white); }
/* The frame carries the page's own scrolling, so this one has none of its own. */
.row-view-frame { flex: 1 1 auto; width: 100%; border: 0; display: block; }
@media (prefers-reduced-motion: reduce) {
    .row-view-back, .row-view { transition: none; }
}

/* Density: applied to a stable ancestor so it survives AJAX result swaps */
/* Applies to both result shapes: .paper-item (public repository rows) and
   .paper-card (dashboard cards). Default deliberately sets explicit values
   too, so switching back from another density actually restores it. */
[data-density="default"] .paper-item { padding: .875rem 1.25rem; }
[data-density="default"] .paper-card { padding: 1rem 1.25rem; }
[data-density="default"] .paper-item .paper-title { font-size: .9375rem; }
[data-density="default"] .paper-card .paper-title { font-size: 1rem; }

[data-density="compact"] .paper-item { padding: .5rem 1.25rem; }
[data-density="compact"] .paper-card { padding: .625rem .875rem; }
[data-density="compact"] .paper-item .paper-title { font-size: .8125rem; }
[data-density="compact"] .paper-card .paper-title { font-size: .875rem; margin-bottom: .2rem; }
[data-density="compact"] .paper-authors,
[data-density="compact"] .paper-meta,
[data-density="compact"] .paper-side { font-size: .625rem; }
[data-density="compact"] .card-track { margin-top: .625rem; }

[data-density="comfortable"] .paper-item { padding: 1.25rem 1.25rem; }
[data-density="comfortable"] .paper-card { padding: 1.5rem 1.5rem; }
[data-density="comfortable"] .paper-item .paper-title { font-size: 1rem; margin-bottom: .45rem; }
[data-density="comfortable"] .paper-card .paper-title { font-size: 1.0625rem; margin-bottom: .5rem; }
[data-density="comfortable"] .paper-authors,
[data-density="comfortable"] .paper-meta { font-size: .75rem; }
[data-density="comfortable"] .card-track { margin-top: 1.5rem; }

/* Result well: fixed height with its own scrollbar in both directions */
.paper-list.is-scrollable {
    height: 620px;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: .5rem;
    scrollbar-width: thin;
    scrollbar-color: var(--dark-maroon) var(--cream);
}
.paper-list.is-scrollable::-webkit-scrollbar { width: 8px; }
.paper-list.is-scrollable::-webkit-scrollbar-track { background: var(--cream); border-radius: var(--r-card, 8px); }
.paper-list.is-scrollable::-webkit-scrollbar-thumb { background: var(--dark-maroon); border-radius: var(--r-card, 8px); }

/* Loading state while search/filter/pagination fetch results via AJAX */
.main-col { position: relative; }
.main-col.is-loading::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    z-index: 20;
    background: linear-gradient(90deg, transparent, var(--soft-maroon), transparent);
    background-size: 50% 100%;
    animation: papel-loading-bar 1.1s ease-in-out infinite;
}
.main-col.is-loading .browse-toolbar,
.main-col.is-loading .paper-list {
    opacity: .5;
    transition: opacity .2s ease .1s;
}
.main-col .browse-toolbar,
.main-col .paper-list { transition: opacity .15s ease; }
@keyframes papel-loading-bar {
    0%   { background-position: -50% 0; }
    100% { background-position: 150% 0; }
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--grey);
}
.empty-state .material-symbols-outlined { font-size: 44px; display: block; margin: 0 auto .75rem; }
.empty-state a { color: var(--maroon); }

/* ===== 7. Sidebar =====
   A soft-cream panel carries the column; the cards sit on top of it in white. */
.sidebar-right {
    background: var(--cream);
    border-radius: var(--r-card, 8px);
    padding: .625rem;
}
/* No border. A white card on the tinted panel is already separated from it,
   so the outline was a second frame around the same edge - which is most of
   what made this column read as bulky. A hairline shadow does the lifting. */
.sidebar-card {
    background: var(--white);
    border-radius: var(--r-card, 8px);
    margin-bottom: .5rem;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(51, 0, 0, .06);
}
.sidebar-card:last-child { margin-bottom: 0; }
.sidebar-card-header {
    background: var(--white);
    color: var(--maroon);
    padding: .375rem .625rem;
    font-family: var(--font-head);
    font-size: .75rem;
    font-weight: 600;
    /* A hairline in the accent, not a full-weight rule: three of these stacked
       down a narrow column was the loudest thing in it. */
    border-bottom: 1px solid var(--soft-maroon);
}
.sidebar-card-body { padding: .125rem 0; }
/* Collapsible card headers (member view) */
.sidebar-card-header.is-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    padding: 0 .625rem 0 0;
}
.card-title-btn {
    flex: 1;
    background: none;
    border: none;
    padding: .375rem .625rem;
    text-align: left;
    font-family: var(--font-head);
    font-size: .75rem;
    font-weight: 600;
    color: var(--maroon);
    cursor: pointer;
}
.card-header-tools { display: flex; align-items: center; gap: .125rem; }
.card-tool {
    display: inline-flex;
    align-items: center;
    background: none;
    border: none;
    padding: 0;
    color: var(--maroon);
    text-decoration: none;
    border-radius: var(--r-card, 8px);
    cursor: pointer;
    transition: color .2s;
}
.card-tool:hover { color: var(--dark-maroon); }
.card-tool .material-symbols-outlined { font-size: 16px; }
/* ===== The sidebar on either side =====
   A control in the top sidebar card sends the whole column across the page.
   Left is a mirror of right rather than a move: the two grid tracks trade
   places and the columns trade order.

   Every console that has this sidebar gets it: the public repository, the
   student dashboard, and the four review desks that share review_console.php.
   The order properties do the work rather than anything moving in the DOM,
   because applying a filter replaces the inside of both columns from script
   and the elements have to stay where that code expects them.

   The sidebar width is read with the same fallback console_shell.php uses, so
   this one rule fits both layouts that define .layout. */
.js-side-swap .side-icon-right { display: none; }
html.sidebar-left .js-side-swap .side-icon-left { display: none; }
html.sidebar-left .js-side-swap .side-icon-right { display: inline-flex; }

@media (min-width: 901px) {
    html.sidebar-left .layout {
        grid-template-columns: var(--sidebar-w, 226px) 1fr;
    }
    html.sidebar-left #sidebarCol { order: 1; }
    html.sidebar-left #mainCol    { order: 2; }
}

/* Below the breakpoint the sidebar is a drawer, or stacked under the results,
   and there are no sides to choose between. */
@media (max-width: 900px) {
    .js-side-swap { display: none; }
}

.card-chevron { transition: transform .25s ease; }
.sidebar-card.collapsed .card-chevron { transform: rotate(-90deg); }
.sidebar-card.collapsed .sidebar-card-body,
.sidebar-card.collapsed form { display: none; }
.sidebar-link {
    display: block;
    width: 100%;
    text-align: left;
    padding: .375rem .625rem;
    font-size: .75rem;
    color: var(--ink);
    text-decoration: none;
    background: none;
    border: none;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.sidebar-link:hover { background: var(--cream); color: var(--maroon); }
.sidebar-link.active { color: var(--maroon); font-weight: 700; }

/* Filter groups flow continuously: no rules between them */
.filter-section { padding: .5rem .625rem .125rem; }
.filter-section:last-child { padding-bottom: .625rem; }
.filter-section-label {
    display: block;
    font-size: .75rem;
    font-weight: 600;
    color: var(--maroon);
    margin-bottom: .3rem;
}
.filter-radio {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .2rem;
    padding-left: .5rem;
    font-size: .75rem;
    color: var(--ink);
    cursor: pointer;
}
/* Grey dot when idle, solid PUP maroon when chosen */
.filter-radio input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    border: none;
    border-radius: 50%;
    background: #E2DCDC;
    flex-shrink: 0;
    cursor: pointer;
    margin: 0;
    transition: background .15s;
}
.filter-radio input[type="radio"]:checked { background: var(--pup-maroon); }
.filter-radio input[type="radio"]:focus-visible {
    outline: 2px solid var(--maroon);
    outline-offset: 2px;
}
.filter-select {
    width: auto;
    min-width: 118px;
    max-width: calc(100% - 1rem);
    margin-left: .5rem;
    margin-right: .5rem;
    padding: .3rem 1.75rem .3rem .45rem;
    border: 1px solid var(--soft-maroon);
    border-radius: var(--r-control, 4px);
    font-family: inherit;
    font-size: .75rem;
    color: var(--ink);
    background: var(--white);
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
}
.filter-select:focus { outline: none; border-color: var(--maroon); }

/* The two dropdowns are the tallest thing in this column, and not because of
   the rule above: select_skin.php hides the real <select> and draws its own
   control, so .filter-select's padding never applies. At 38px each they set
   the height of the whole Filter card. Scoped to this sidebar, so the same
   control keeps its comfortable size on the analytics and management pages
   where there is room for it. */
.sidebar-right .sel-btn {
    padding: .3rem .55rem;
    font-size: .75rem;
}
.sidebar-right .sel-opt {
    padding: .3rem .55rem;
    font-size: .75rem;
}
/* Date group: year / month / day stacked, indented past the radio column */
.date-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .4rem;
    padding-left: .75rem;
}
.date-stack .filter-select { min-width: 112px; text-align: center; }

.note-card {
    border: 1px solid rgba(177,125,125,.22);
    border-radius: var(--r-card, 8px);
    padding: .5rem .75rem;
    font-size: .75rem;
    color: var(--maroon);
    background: var(--white);
    line-height: 1.6;
}
.note-card strong { color: var(--pup-maroon); display: block; font-weight: 600; }
/* Search suggestions */
.suggestions-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0; right: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    box-shadow: var(--shadow-md);
    z-index: 800;
    max-height: 280px;
    overflow-y: auto;
    display: none;
}
.suggestion-item {
    padding: .625rem 1rem;
    cursor: pointer;
    font-size: .875rem;
    color: var(--ink);
    border-bottom: 1px solid var(--border-soft);
}
.suggestion-item:last-child { border-bottom: none; }
.suggestion-item:hover, .suggestion-item.active { background: var(--cream); color: var(--maroon); }

/* ===== Phones and small tablets =====
   Everything above this point was sized for a pointer on a large screen: 11px
   metadata, 26px buttons, and two columns of detail under each title. On a
   phone that reads as a desktop table that has been squeezed rather than a
   page built for the device. Nothing here applies above 900px, so the wide
   layout is exactly as it was.

   The density rules further up carry an attribute selector, so these repeat it:
   otherwise they lose the cascade and silently do nothing. */
@media (max-width: 900px) {
    /* A result becomes a card you can hit with a thumb, rather than a row
       separated from the next one by a hairline. */
    [data-density] .paper-item,
    .paper-item {
        margin: 0 0 .625rem;
        padding: .875rem 1rem;
        border: 1px solid var(--border-soft);
        border-radius: var(--r-card, 8px);
        background: var(--white);
    }
    .paper-item::after { display: none; }   /* the card edge separates them now */

    [data-density] .paper-item .paper-title,
    .paper-item .paper-title {
        font-size: 1rem;
        line-height: 1.4;
        margin-bottom: .45rem;
    }

    /* One column. Two columns of 11px text on a 360px screen left roughly
       fourteen characters per line in each of them. */
    .paper-foot {
        grid-template-columns: 1fr;
        gap: .5rem;
    }

    /* 13px is the floor for body text on a phone; 11px was set for a screen
       held at arm's length on a desk. */
    [data-density] .paper-authors,
    [data-density] .paper-meta,
    [data-density] .paper-side,
    .paper-authors, .paper-meta, .paper-side { font-size: .8125rem; }
    .paper-authors { margin-bottom: .25rem; }
    .paper-meta { gap: .3rem .5rem; }

    /* The action was an 11px link in a line of other text: a target a finger
       cannot reliably find. It becomes the card's own button. */
    .paper-side {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .25rem .75rem;
        margin-top: .5rem;
        padding-top: .625rem;
        border-top: 1px solid var(--border-soft);
    }
    .paper-action {
        display: inline-flex;
        align-items: center;
        min-height: 40px;
        padding: 0 .875rem;
        border: 1px solid var(--border);
        border-radius: var(--r-control, 4px);
        font-size: .8125rem;
    }
    .paper-action:hover { background: var(--cream); text-decoration: none; }
    .paper-program { display: inline; }

    /* Apple and Google both publish 44px as the smallest reliable target;
       these were 26. */
    .browse-toolbar {
        font-size: .8125rem;
        gap: .5rem;
        padding: .25rem 0 .5rem;
    }
    .toolbar-left, .toolbar-right { gap: .125rem; }
    .toolbar-btn { width: 40px; height: 40px; }
    .toolbar-btn .material-symbols-outlined { font-size: 20px; }

    .suggestion-item { padding: .8rem 1rem; font-size: .9375rem; }

    /* The magnifier is the form's submit button, so it has to be reachable in
       its own right and not only by the field beside it. */
    .btn-search-icon { padding: 0 .875rem; min-height: 44px; }
}
</style>
