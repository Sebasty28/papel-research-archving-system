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
    /* Shelf is offered by the repository and the student dashboard, not by
       the review desks: a desk is a queue worked through by status, not a
       shelf browsed. The setting is one per device and shared by every
       console, so a page that cannot draw a shelf falls back to cards rather
       than showing a list with no layout at all. The page says which it is by
       setting $VIEW_SHELF before this file is included. */
    var shelfOk = <?= !empty($VIEW_SHELF) ? 'true' : 'false' ?>;
    /* Two shelf views now, by the same licence: Hardbounds (stored as "shelf",
       its name before there were two, so nobody's saved choice is lost) and
       Bookshelf, the same books stood spine out. */
    var shelfy = v === 'shelf' || v === 'bookshelf';
    if (v !== 'rows' && !(shelfy && shelfOk)) { v = 'cards'; }
    document.documentElement.setAttribute('data-view', v);
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
/* A light neutral clear icon remains visible on Old Night's charcoal field. */
html[data-mode="dark"] { --search-clear: #C8C9CB; }

/* ===== Paper list ===== */
/* The sidebar can leave the results column narrower at 1024px than it is at
   900px, when the sidebar has become a drawer. Rows therefore respond to the
   list's own width, not just the window's width. */
.paper-list { container: paper-results / inline-size; }
/* The hover fill sits inside the row without moving its text or actions. */
.paper-item {
    position: relative;
    isolation: isolate;
    padding: .875rem 1.25rem;
    margin: 0 -.75rem;
    border-radius: var(--r-card, 8px);
}
.paper-item::before {
    content: '';
    position: absolute;
    /* .75rem, which is the row's own negative margin, so the fill spans the
       results column exactly: the row is pulled 12px wider than the list at
       each side precisely so this can reach the column's edge. At 1.375rem it
       stopped 2px short of the title on both sides - inside the text rather
       than around it - and a highlight narrower than the line it is
       highlighting reads as clipped. Now there is 8px of ground either side
       of the text, and the fill lines up with the column rather than with
       nothing. */
    inset: 7px .75rem;
    z-index: -1;
    border-radius: var(--r-card, 8px);
    background: var(--cream);
    opacity: 0;
    transition: opacity .2s ease;
    pointer-events: none;
}
@media (hover: hover) {
    .paper-item:hover::before { opacity: 1; }
}
html[data-view="rows"] .paper-list .paper-item::before { inset-block: 3px; }
.paper-item:has(.paper-action.js-open-modal) { cursor: pointer; }
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
    /* Match the dashboard's fixed author and metadata columns, leaving the
       rest for the title. Each field starts at the same place on every row. */
    grid-template-columns: minmax(0, 1fr) minmax(0, 9rem) minmax(0, 10.5rem) minmax(0, 8.5rem);
    align-items: center;
    column-gap: .75rem;
    padding: .45rem 1.25rem;
}
html[data-view="rows"] .paper-list .paper-foot,
html[data-view="rows"] .paper-list .paper-info { display: contents; }
/* A row sets its own padding, which would otherwise outrank the density
   rules above and leave that setting doing nothing at all here. */
html[data-view="rows"][data-density="compact"] .paper-list .paper-item { padding: .3rem 1.25rem; }
html[data-view="rows"][data-density="comfortable"] .paper-list .paper-item { padding: .7rem 1.25rem; }
html[data-view="rows"] .paper-list .paper-item .paper-title {
    grid-column: 1; min-width: 0;
    margin: 0; font-size: .875rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
html[data-view="rows"] .paper-list .paper-item .paper-authors {
    grid-column: 2; margin: 0; min-width: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* The meta is a flex row that wraps by default. Left to do that inside a
   narrow column it stacks three deep and the row stops being a row. */
html[data-view="rows"] .paper-list .paper-item .paper-meta {
    grid-column: 3; min-width: 0; flex-wrap: nowrap;
    white-space: nowrap; overflow: hidden;
    /* A row of separate spans, so text-overflow has nothing to put an
       ellipsis on: it would cut a word in half at the edge and read as a
       rendering fault. Faded out instead. */
    -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 1.75rem), transparent);
            mask-image: linear-gradient(to right, #000 calc(100% - 1.75rem), transparent);
}
html[data-view="rows"] .paper-list .paper-item .paper-side {
    grid-column: 4; display: block; overflow: hidden;
    white-space: nowrap; line-height: 1.4;
}
html[data-view="rows"] .paper-list .paper-item .paper-action,
html[data-view="rows"] .paper-list .paper-item .paper-program {
    display: block; overflow: hidden; text-overflow: ellipsis;
}
/* A sidebar can make the results column narrow just above 900px. Stack rows
   there so their title stays readable; phones use the sideways grid below. */
@container paper-results (max-width: 760px) {
    /* The same padding a card gets at this width, written out because the
       rows selector outranks it: switching views should change how a result
       reads, not where the page's text begins. */
    html[data-view="rows"] .paper-list .paper-item { display: block; padding: .875rem 1.25rem; }
    html[data-view="rows"] .paper-list .paper-foot { display: grid; grid-template-columns: minmax(0, 1fr) 38%; }
    html[data-view="rows"] .paper-list .paper-item .paper-info { display: block; min-width: 0; }
    html[data-view="rows"] .paper-list .paper-item .paper-title {
        grid-column: auto; white-space: normal; overflow: visible;
        text-overflow: clip; overflow-wrap: anywhere;
    }

    /* The stacked fallback needs the full author and date again. Retaining
       the grid's ellipsis and mask clips words even though there is room to
       wrap them. Below 900px neither is shown at all: see the phone rows at
       the foot of this file. */
    html[data-view="rows"] .paper-list .paper-item .paper-authors {
        grid-column: auto;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
    }
    html[data-view="rows"] .paper-list .paper-item .paper-meta {
        grid-column: auto;
        flex-wrap: wrap;
        white-space: normal;
        overflow: visible;
        -webkit-mask-image: none;
                mask-image: none;
    }
    html[data-view="rows"] .paper-list .paper-item .paper-side {
        grid-column: auto; overflow: visible; white-space: normal;
    }
    html[data-view="rows"] .paper-list .paper-item .paper-action,
    html[data-view="rows"] .paper-list .paper-item .paper-program {
        overflow: visible; text-overflow: clip;
    }
    html[data-view="rows"] .paper-list .paper-item .paper-program { display: block; }
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
/* A touch screen has no pointer to take away: tapping one of these left :hover
   on until something else was tapped, so the fill sat there on a button nobody
   was pointing at, which reads as a control stuck open. (The same guard the
   navbar's bell and menu button carry, in site_head.php.) A button marked by a
   class while its panel is open - the filters toggle - keeps that mark: this is
   only about the pointer. */
@media (hover: none) {
    .toolbar-btn:hover { background-color: transparent; color: var(--maroon); }
    /* And the result itself. Now that an entry has no ground of its own, a
       cream block left behind across a whole one is the most visible stuck
       hover on the page. */
    .paper-item:hover::before { opacity: 0; }
}
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
    /* Never wider than the screen it is drawn on, whatever happens to the
       script that places it: 220px is the width this panel wants, and on a
       320px phone with the gear near one edge that is most of the window. The
       script in includes/browse_console_js.php is what slides it along until
       both sides are inside and caps its height; this is the floor under that,
       so a browser that runs none of it still cannot draw a panel wider than
       the window. */
    max-width: calc(100vw - 1rem);
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
/* Filled controls use the surface accent; the text accent turns white in Old Night. */
.qs-radio input[type="radio"]:checked { background: var(--maroon-surface); }

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
    max-height: calc(100vh - 2rem);
    display: flex; flex-direction: column; overflow: hidden;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: 0 20px 56px rgba(51, 0, 0, .3);
    transform: translateY(.4rem);
    transition: transform .14s ease;
}
@supports (height: 100dvh) {
    .row-view { height: min(766px, 88dvh); max-height: calc(100dvh - 2rem); }
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
.row-view-frame { flex: 1 1 auto; min-height: 0; width: 100%; border: 0; display: block; }
/* On short laptop and landscape tablet screens, give the paper the available
   height while retaining a small edge gap and a reachable title/close bar. */
@media (max-height: 700px) {
    .row-view-back { padding: .5rem; }
    .row-view { height: calc(100vh - 1rem); max-height: calc(100vh - 1rem); }
    .row-view-head { padding-block: .35rem; }
    @supports (height: 100dvh) {
        .row-view { height: calc(100dvh - 1rem); max-height: calc(100dvh - 1rem); }
    }
}
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

/* Loading state while search/filter/pagination fetch results via AJAX.
   The column dims; the progress bar is the one along the top of the window
   (includes/loading_bar.php), which is where every other fetch on the site
   reports itself. The column used to draw a second bar of its own across its
   own top edge, and on the student dashboard that edge is above the search
   box, so the bar surfaced as a rule floating in the gap over the search
   field with nothing to attach it to, and read as a stray border rather than
   as progress. */
.main-col { position: relative; }
.main-col.is-loading .browse-toolbar,
.main-col.is-loading .paper-list {
    opacity: .5;
    transition: opacity .2s ease .1s;
}
.main-col .browse-toolbar,
.main-col .paper-list { transition: opacity .15s ease; }

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
    padding: .375rem;
}

/* ---- The drawer that column becomes on a narrow screen ----
   In one column the sidebar's cards land underneath every result: a long
   scroll from the search box they belong to, which is where the eye is. On a
   narrow screen they become a drawer instead, opened by the tune button in the
   toolbar and closed by its own X, the backdrop or Escape.

   The <aside> is not moved in the DOM to do this. An AJAX filter replaces its
   innerHTML in place, so the element has to stay exactly where the script goes
   looking for it; only its painting changes.

   Here rather than on one page, because the repository, the student dashboard
   and the four review desks all carry <aside id="sidebarCol"> and all wanted
   the same drawer. The behaviour is shared too, in browse_console_js.php.
   archive/index.php still states these rules in its own stylesheet, where they
   sit among the banner rules; they are the same declarations and that page is
   later in the document, so it keeps deciding for itself and nothing about the
   repository changes. */
.btn-tools-toggle,
.tools-backdrop,
.btn-tools-close { display: none; }

@media (max-width: 900px) {
    /* Everything else about it comes from .toolbar-btn, which it is; all this
       width decides is whether there is a drawer to open at all. */
    .btn-tools-toggle { display: inline-flex; }
    /* Marked while its drawer is open, the way a pressed tool button would be.
       The drawer covers this button at a phone's width, so this is seen on the
       way out rather than the way in, and on a tablet both are on screen. */
    html.tools-open .btn-tools-toggle { background: var(--cream); color: var(--dark-maroon); }

    .sidebar-right {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: min(88vw, 360px);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0;
        padding: 3rem 1rem 2rem;
        background: var(--cream);
        box-shadow: -4px 0 24px rgba(51, 0, 0, .18);
        transform: translateX(100%);
        transition: transform .3s cubic-bezier(.4, 0, .2, 1);
        /* Under the login slide-in (1200) on purpose: signing in should still
           come out on top of a drawer left open behind it. */
        z-index: 1090;
    }
    html.tools-open .sidebar-right { transform: translateX(0); }

    .tools-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        background: rgba(51, 0, 0, .40);
        opacity: 0;
        pointer-events: none;
        transition: opacity .3s;
        z-index: 1085;
    }
    html.tools-open .tools-backdrop { opacity: 1; pointer-events: auto; }

    /* Outside the <aside>, because everything inside it is replaced whenever a
       filter is applied and a close button in there would be swept away. */
    html.tools-open .btn-tools-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        top: .5rem;
        right: .5rem;
        width: 38px;
        height: 38px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: var(--maroon);
        cursor: pointer;
        z-index: 1095;
    }
    html.tools-open { overflow: hidden; }
}
@media (prefers-reduced-motion: reduce) {
    .sidebar-right, .tools-backdrop { transition: none; }
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

/* ===== The sidebar folded away =====
   The same move the upload page's side pane makes (papelSide, .side-rail in
   student_upload_ai.php), in the same clothes: the cards go and the cream
   panel they sat on narrows to a rail of icons, one to bring the panel back
   and one per card. The reader who wants the whole window for the results
   keeps the filters a click away rather than scrolling past a column they are
   not using.

   The width is the one custom property both layouts already read, so the
   results column and (on the repository) the search field above it follow
   without a rule of their own: html.sidebar-folded beats :root on
   specificity, whichever stylesheet set it. Written here as a class and never
   from script on every frame: see the warning about :root in CLAUDE.md. */
.sidebar-rail { display: none; flex-direction: column; align-items: center; gap: .25rem; }
.sidebar-rail-btn {
    display: inline-flex; align-items: center; justify-content: center;
    /* 44px, which is both the comfortable size for a finger and what the
       rail's width is set from: the panel is this plus the .375rem of padding
       it keeps on either side. */
    width: 44px; height: 44px;
    border: 0; border-radius: var(--r-control, 4px);
    background: none;
    /* Literal #fff on --maroon-surface, which is the pair the breadcrumb
       strip uses. Not var(--white): that token is the site's *surface*
       colour, and in Old Night it is #2D2F32, which put charcoal icons on the
       maroon bar. Nothing filled from the accent may take its text from it. */
    color: #fff;
    cursor: pointer;
    transition: background .15s, color .15s;
}
/* The one that brings the panel back stands a little apart from the cards'
   own buttons, as it does on the upload page's rail. */
.sidebar-rail-btn.is-show { margin-bottom: .35rem; }
.sidebar-rail-btn .material-symbols-outlined { font-size: 20px; }
.sidebar-rail-btn:hover { background: var(--maroon-surface-hover); }
/* The ring goes inside the button, in white, for the same reason the crumb
   bar's does: an outline in the accent is invisible on the accent. */
.sidebar-rail-btn:focus-visible { outline: 2px solid #fff; outline-offset: -2px; }

.js-side-hide .side-icon-right { display: none; }
html.sidebar-left .js-side-hide .side-icon-left { display: none; }
html.sidebar-left .js-side-hide .side-icon-right { display: inline-flex; }

@media (min-width: 901px) {
    /* The button plus the panel's own padding. Change it here and in
       includes/skeleton.php's --skel-rail-w together, or the page steps
       sideways as the skeleton lifts off it. */
    html.sidebar-folded { --sidebar-w: 56px; }
    /* Everything the panel holds goes, not the cards alone: the repository
       signed out ends its column with a plain .note-card, which at 44px wrapped
       to one word a line down the page beside the rail. */
    html.sidebar-folded #sidebarCol > *:not(.sidebar-rail) { display: none; }
    /* The rail is the panel while it is folded: it takes the column's whole
       width and carries the colour itself, rather than sitting as a maroon
       pill on the cream the cards used to stand on. The panel keeps its
       rounded corners and gives up its padding, so nothing of the old ground
       shows at an edge. On the repository, where the panel's background is
       painted by ::after behind it (the shadow that lifts it off the banner
       photo), the rail covers that too: both are the same shape and radius. */
    html.sidebar-folded #sidebarCol { padding: 0; background: none; }
    /* Tinted glass, in the same mix and the same blur as the breadcrumb
       strip: on the repository this bar stands on the banner photo, and a
       solid block of maroon there reads as a hole cut in the picture. */
    html.sidebar-folded #sidebarCol .sidebar-rail {
        /* The solid colour first: a browser that does not know color-mix
           drops the line below and would otherwise leave white icons on a
           bar with no fill at all. */
        background: var(--maroon-surface);
        background: color-mix(in srgb, var(--maroon-surface) 82%, transparent);
        -webkit-backdrop-filter: blur(10px) saturate(160%);
        backdrop-filter: blur(10px) saturate(160%);
        border-radius: var(--r-card, 8px);
        padding: .375rem 0;
    }
    /* Nothing to blur, so the bar keeps its own colour, as the strip does. */
    @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
        html.sidebar-folded #sidebarCol .sidebar-rail { background: var(--maroon-surface); }
    }
    /* The panel's ground and the rim that lifts it off the photo are two
       pseudo-elements on the repository (archive/index.php), painted behind
       it. Behind glass they are no longer behind anything: the cream came
       through as a pink cast where the picture should be, and the rim as a
       dark band across the top third of the bar. The rail is its own surface
       now, so both stand down while it is folded. An id outranks the four
       classes that stylesheet names them with, whichever loads last. */
    html.sidebar-folded #sidebarCol::before,
    html.sidebar-folded #sidebarCol::after { content: none; }
    html.sidebar-folded #sidebarCol .sidebar-rail { display: flex; }
    /* The panel is no wider than one button, so it is as tall as the rail and
       no taller: a dashboard that lets the sidebar set the column height
       (console_shell.php) would otherwise stretch the cream the length of the
       results. */
    html.sidebar-folded #sidebarCol { align-self: start; }
}

/* Below the breakpoint the sidebar is a drawer, or stacked under the results:
   there are no sides to choose between, and nothing to fold away from. The
   rail is never drawn there, so the cards stay reachable however the setting
   was left on a wider screen. */
@media (max-width: 900px) {
    .js-side-swap, .js-side-hide { display: none; }
    .sidebar-rail { display: none !important; }
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
.filter-radio input[type="radio"]:checked { background: var(--maroon-surface); }
.filter-radio input[type="radio"]:focus-visible {
    outline: 2px solid var(--maroon);
    outline-offset: 2px;
}
/* How many a choice would leave, beside its name: the same muted figure the
   dashboard tabs carry (.dash-tab .count in includes/console_shell.php). */
.filter-radio .filter-count { font-size: .6875rem; color: var(--grey); }
.filter-radio input[type="radio"]:checked ~ .filter-count { color: var(--maroon); }
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
    /* The same list a desktop reads, smaller. It was a boxed card per result
       for a while - a border, a radius, a white ground, a rule above its own
       button - on the reasoning that a thumb wants something to hit. Four
       pieces of chrome round every result is what the owner saw on a phone,
       and next to the desktop's plain hairline list it read as a different
       site. So the box goes and the list is the desktop's, with the padding
       and the gaps taken in rather than the shapes changed.

       The type is the desktop's too: 15px titles and 11px below them, which is
       small but is what the same page shows on a laptop, and the point here is
       that the two match. */
    [data-density] .paper-item,
    .paper-item {
        margin: 0;
        padding: .75rem .5rem;
        border: 0;
        border-radius: 0;
        background: none;
    }
    /* The hairline between results, inset to the item's own padding. */
    .paper-item::after { left: .5rem; right: .5rem; }

    [data-density] .paper-item .paper-title,
    .paper-item .paper-title { line-height: 1.4; margin-bottom: .25rem; }

    /* Two columns, as on a desktop: what the paper is at the left, what you
       can do about it at the right. The title is above both and keeps the
       whole width. 38% is the desktop's share, so "BS Information Technology"
       sits on one line at 390px and a longer programme wraps inside its own
       column instead of pushing the row about. */
    .paper-foot {
        grid-template-columns: minmax(0, 1fr) 38%;
        gap: .625rem;
        align-items: start;
    }

    .paper-authors { margin-bottom: .15rem; }
    .paper-meta { gap: .15rem .3rem; }

    /* A plain link with the programme under it, which is the desktop's right
       column. It was a bordered 40px button under a rule, which is the single
       thing that made a result look like a form rather than an entry in a
       list. */
    .paper-side {
        display: block;
        text-align: left;
        line-height: 1.6;
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
    }
    .paper-action {
        display: inline;
        min-height: 0;
        padding: 0;
        border: 0;
        font-size: inherit;
    }
    .paper-program { display: block; }

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

/* ===== A phone, compact =====
   The list is the desktop's at every width below 900 (the block above); this
   only takes the room in further, where there is least of it. Measured at 390px
   before any of this: a result stood 237px tall, so three filled the screen,
   titles ran to three lines and the date / type / status line broke after the
   type and put "Unpublished Paper" on its own.

   The sizes are measured rather than judged: a line fits when the text it
   holds is narrower than the column it sits in, and that was checked at 320,
   360 and 390px. */
@media (max-width: 480px) {
    [data-density] .paper-item,
    .paper-item,
    html[data-view="rows"] .paper-list .paper-item { padding: .625rem .25rem; }
    .paper-item::after { left: .25rem; right: .25rem; }

    [data-density] .paper-item .paper-title,
    .paper-item .paper-title { line-height: 1.35; margin-bottom: .2rem; }

    .paper-authors { margin-bottom: .1rem; line-height: 1.35; }
    .paper-meta { gap: .1rem .2rem; line-height: 1.4; }

    /* The two columns are 350px between them here, so the gap is what the
       right-hand one can least afford to give up. */
    .paper-foot { gap: .5rem; }
    .paper-side { line-height: 1.5; }

    .browse-toolbar { font-size: .75rem; gap: .375rem; padding: .25rem 0 .375rem; }
    .toolbar-left, .toolbar-right { gap: .0625rem; }
    .toolbar-btn { width: 34px; height: 34px; }
    .toolbar-btn .material-symbols-outlined { font-size: 18px; }

    /* The field's own padding was 12px over and 16px either side, which on a
       phone is a tenth of the screen given to air around the placeholder. */
    .search-form { padding: .4375rem .6875rem; gap: .5rem; }
    .search-input { font-size: .8125rem; }
    .btn-search-icon { padding: 0 .5rem 0 .0625rem; min-height: 38px; }
    .btn-search-icon .material-symbols-outlined { font-size: 20px; }
    .suggestion-item { padding: .55rem .75rem; font-size: .8125rem; }
}

/* The narrowest phones still in use. "1-3 of 3" is the figure the row is for,
   and the words in front of it are what cost it the line: with them, the count
   and its pager came to 310px of a 296px row at 320px and the tool buttons fell
   below. Both consoles that print a count mark the words with this class. */
@media (max-width: 400px) {
    .count-words { display: none; }
}

/* Rows stay rows on a phone, and a phone row is two columns: the title, which
   is what a result is read by and reads perfectly well cut short, and the one
   thing there is to do with it. The authors, the date line and the programme
   go: four columns at a legible type size need about 35rem, and the only ways
   to find that on a 390px screen are type too small to read or a well that
   scrolls sideways, which hides half of every row behind a gesture nothing on
   the page suggests. Cards is the view that shows the rest, one result at a
   time, and it is a tap away on the same toolbar. */
@media (max-width: 900px) {
    html[data-view="rows"] .paper-list .paper-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: baseline;
        column-gap: .75rem;
        padding: .45rem 1.25rem;
    }
    html[data-view="rows"][data-density="compact"] .paper-list .paper-item { padding: .3rem 1.25rem; }
    html[data-view="rows"][data-density="comfortable"] .paper-list .paper-item { padding: .7rem 1.25rem; }
    html[data-view="rows"] .paper-list .paper-foot,
    html[data-view="rows"] .paper-list .paper-item .paper-info,
    html[data-view="rows"] .paper-list .paper-item .paper-side { display: contents; }
    html[data-view="rows"] .paper-list .paper-item .paper-title {
        grid-column: 1;
        margin: 0;
        min-width: 0;
        font-size: .8125rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        /* The stacked block above lets a title break mid-word. One line has
           nowhere to break, and the ellipsis is the cut. */
        overflow-wrap: normal;
    }
    /* Held off the grid rather than hidden with `display: none` on the parts
       themselves: .paper-info and .paper-side are `display: contents` above,
       so these are the grid's own children now and nothing else is left to
       hide them by. */
    html[data-view="rows"] .paper-list .paper-item .paper-authors,
    html[data-view="rows"] .paper-list .paper-item .paper-meta,
    html[data-view="rows"] .paper-list .paper-item .paper-program { display: none; }
    html[data-view="rows"] .paper-list .paper-item .paper-action {
        grid-column: 2;
        display: block;
        white-space: nowrap;
        font-size: .75rem;
    }
}
</style>
