<?php
/**
 * The look of the management consoles.
 *
 * Manage Students was rebuilt on the site's own tokens (panels, chips, tabs,
 * borderless row actions, the two-column shell) and Manage Faculty needs the
 * same. Rather than keep two copies in step by hand, the whole stylesheet lives
 * here and both pages include it.
 *
 * Nothing in here is about students specifically; the class names are all
 * mgmt-*, and a page supplies its own markup. Anything genuinely particular to
 * one page (the cohort help dialog, for instance) stays on that page.
 *
 * Include inside <head>, after site_head.php and manage_console.php.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* This page used to carry its own design system (Crimson Pro over IBM Plex
   Sans, slate greys, a gold gradient on every card) which is why it read as a
   different product from the dashboard beside it. Everything here now comes
   from the site's own tokens, so the page inherits whatever palette and theme
   the reader has chosen. */

.mgmt-wrap { padding-block: 1.5rem 3rem; }

/* ---- Page heading ---- */
.mgmt-head { margin-bottom: 1.25rem; }
/* The Librarian's two desks, as one line: the page you are on as the title, the
   other as a link after a divider. That desk has no sidebar of links, and the two
   belong together, so going between them should not mean a trip to the navbar.
   Here rather than in either page, because both wear it.
   Baseline, not centre, so the link sits on the title's own line of text whatever
   the two font sizes are. */
.mgmt-head-title {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: .55rem;
}
.mgmt-head-sep {
    color: var(--border);
    font-weight: 300;
    /* The divider follows the title's size, so it stays the height of the text
       beside it rather than the link's. */
    font-size: 1.5rem;
    line-height: 1;
}
/* Underlined only on hover: at rest it is a sibling of the title, not a link in
   a paragraph. */
.mgmt-head-switch {
    color: var(--maroon);
    font-family: var(--font-head);
    font-size: 1rem;
    font-weight: 500;
    text-decoration: none;
}
.mgmt-head-switch:hover { text-decoration: underline; }
.mgmt-head-switch:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.mgmt-head h1 {
    font-family: var(--font-head);
    font-size: 1.375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .2rem;
}
.mgmt-head p { font-size: .8125rem; color: var(--grey); margin: 0; }

/* The title and the page's one action, on a row that wraps rather than squeezing
   the description on a narrow screen. Both Librarian desks wear it: neither has a
   sidebar of links, so the one other thing a Librarian can do lives here beside
   the title. */
.mgmt-head-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.mgmt-head-action {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    flex: 0 0 auto;
    text-decoration: none;
    /* .btn-sm-outline is built for a row of buttons: beside a plain page title its
       .75rem reads as fine print next to the h1, and its border as a second frame
       round something that has nothing to be told apart from. */
    font-size: .875rem;
    border: none;
}

/* ---- Two columns: the form keeps its width, the roll takes the rest ---- */
.mgmt-grid {
    display: grid;
    grid-template-columns: minmax(0, 19rem) minmax(0, 1fr);
    gap: 1.125rem;
    align-items: start;
}
.mgmt-grid > * { min-width: 0; }

/* ---- Panels ---- */
.mgmt-panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    overflow: hidden;
}
.mgmt-panel-head {
    display: flex; align-items: center; gap: .5rem;
    padding: .875rem 1.125rem;
    border-bottom: 1px solid var(--border);
    font-family: var(--font-head); font-size: .875rem;
    font-weight: 600; color: var(--maroon);
}
.mgmt-panel-head .material-symbols-outlined { font-size: 18px; }
.mgmt-panel-body { padding: 1.125rem; }

/* ---- Form ---- */
.mgmt-field { margin-bottom: .875rem; }
.mgmt-field:last-of-type { margin-bottom: 1.125rem; }
.mgmt-field label {
    display: block; font-size: .75rem; font-weight: 500;
    color: var(--ink); margin-bottom: .3rem;
}
.mgmt-field .req { color: var(--maroon); }
.mgmt-field input, .mgmt-field select {
    width: 100%;
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .5rem .7rem;
    font-family: var(--font-body); font-size: .8125rem;
    color: var(--ink); background: var(--white);
}
.mgmt-field input:focus, .mgmt-field select:focus {
    border-color: var(--maroon); outline: none; box-shadow: none;
}
/* The browser's own arrow sits hard against the edge. This is the same chevron
   the sign-in panel uses, set in from the border so it is not touching it. */
.mgmt-field select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    cursor: pointer;
    padding-right: 2.2rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 1rem center;
}

/* ---- The info mark beside a label ----
   Material Symbols' own `info`, which draws its circle as part of the glyph:
   the button had been a "?" drawn inside a 14px bordered circle of its own,
   and a bordered circle around a glyph that is already a circle reads as two.
   One mark for the whole of these consoles, so nothing has to be learnt
   twice: the cohort pair's, which opens a dialog, is the same mark. */
.mgmt-field label { display: flex; align-items: center; gap: .3rem; }
.mgmt-help {
    flex: 0 0 auto;
    border: 0; border-radius: 50%;
    background: none; color: var(--grey);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; padding: 0;
}
.mgmt-help .material-symbols-outlined { font-size: 15px; display: block; }
.mgmt-help:hover { color: var(--maroon); }
.mgmt-help:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.mgmt-field input::placeholder { color: var(--grey); }
/* A hint that is a complaint: said under the field it is about, while the
   value is still being typed. The border is left alone on purpose, since
   every control's border comes from one rule in focus_ring.php whose chain
   of :not()s outweighs a plain class here. */
.mgmt-hint.is-bad { color: var(--bad-text); }
.mgmt-hint { display: block; font-size: .6875rem; color: var(--grey); margin-top: .25rem; }
/* The hint doubles as the Generate button's only way to answer back (there is
   no alert() on this site) so it needs a state that reads as a correction. */
.mgmt-hint.is-warn { color: var(--maroon); font-weight: 500; }

/* ---- A field's hint, behind the "?" beside its label -------------------
   A hint used to sit under its box as a second line of grey small print.
   Six of them down one form is a column nobody reads twice, and together
   they pushed the Create button off the bottom of the screen. The words are
   the same words, moved behind the "?" the two cohort fields already had.

   What is shown is the hint element itself and never a copy of its words:
   #passHint is rewritten by script (the Generate button answers through it,
   and editing changes it to "leave blank to keep their current password"),
   and a copy taken at load would go on saying whatever it said then.

   Hidden to the eye, not to a screen reader: the control carries
   aria-describedby to it, so it is still read out with the field as it was
   when it was printed underneath. display:none would take it out of the
   accessibility tree altogether and lose that. */
.mgmt-hint.is-tip {
    position: absolute;
    width: 1px; height: 1px;
    margin: -1px; padding: 0; border: 0;
    overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%);
}
.mgmt-hint.is-tip.is-open {
    /* Fixed, because a hint must not be clipped by the panel, the modal or
       the import drawer it was opened inside; placed by script against the
       button, and closed on any scroll rather than followed. */
    position: fixed;
    z-index: 19500;      /* over the import drawer (19000), under its dialogs (20000) */
    width: auto; height: auto; max-width: 17rem;
    margin: 0; padding: .5rem .625rem;
    overflow: visible; clip: auto; clip-path: none;
    background: var(--white); color: var(--ink);
    border: 1px solid var(--border); border-radius: var(--r-card, 8px);
    box-shadow: 0 6px 20px rgb(0 0 0 / .14);
    font-size: .6875rem; line-height: 1.45;
}
/* A complaint is not a tooltip. It is said under the field, as it always was,
   whether or not the "?" happens to be open. After .is-open on purpose: the
   two have the same specificity and this one has to win. */
.mgmt-hint.is-tip.is-warn,
.mgmt-hint.is-tip.is-bad {
    position: static;
    width: auto; height: auto; max-width: none;
    margin: .25rem 0 0; padding: 0; border: 0;
    overflow: visible; clip: auto; clip-path: none;
    background: none; box-shadow: none; z-index: auto;
    font-size: .6875rem;
}
/* and the "?" goes while it is, or it offers to show what is already there. */
.mgmt-field:has(> .mgmt-hint.is-tip.is-warn) .mgmt-help.js-hint,
.mgmt-field:has(> .mgmt-hint.is-tip.is-bad) .mgmt-help.js-hint { display: none; }
/* Academic year and section sit together: they are read as one answer. */
.mgmt-pair { display: grid; grid-template-columns: 1fr 1fr; gap: .625rem; }
.mgmt-with-btn { display: flex; gap: .375rem; }
.mgmt-with-btn input { flex: 1 1 auto; min-width: 0; }
.mgmt-submit {
    width: 100%; justify-content: center;
    padding: .625rem 1rem; font-size: .8125rem;
}
.mgmt-form-actions { display: flex; flex-direction: column; gap: .5rem; }
/* Editing is a different job from creating, and the panel says so plainly
   rather than relying on the reader noticing the filled-in boxes. */
#formPanel.is-editing .mgmt-panel-head { background: var(--cream); }
.mgmt-editing-note {
    display: none; align-items: flex-start; gap: .4rem;
    margin-bottom: .875rem; padding: .5rem .625rem;
    background: var(--cream); border-radius: var(--r-card, 8px);
    font-size: .6875rem; color: var(--ink); line-height: 1.5;
}
#formPanel.is-editing .mgmt-editing-note { display: flex; }
.mgmt-editing-note .material-symbols-outlined { font-size: 16px; color: var(--maroon); flex: 0 0 auto; }
/* The row being edited is marked, so it is obvious which one the panel holds. */
.mgmt-table tbody tr.is-editing > td { background: var(--cream); }

/* ---- Sliding tabs, with the dashboard's edge chevrons ---- */
.mgmt-tabs-row {
    display: flex; align-items: center; min-width: 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: .875rem;
}
.mgmt-tabs-glide { position: relative; flex: 1 1 auto; min-width: 0; }
.mgmt-tabs {
    display: flex; align-items: center; gap: .25rem;
    min-width: 0; overflow-x: auto; scrollbar-width: none;
}
.mgmt-tabs::-webkit-scrollbar { display: none; }
.mgmt-tabs-glide::before,
.mgmt-tabs-glide::after {
    font-family: 'Material Symbols Outlined';
    font-size: 20px; line-height: 1;
    color: color-mix(in srgb, var(--maroon) 75%, transparent);
    position: absolute; top: 0; bottom: 0;
    display: flex; align-items: center; width: 4rem;
    pointer-events: none; opacity: 0; z-index: 1;
    transition: opacity .18s ease;
}
.mgmt-tabs-glide::before { content: '\e5cb'; left: 0; justify-content: flex-start; }
.mgmt-tabs-glide::after { content: '\e5cc'; right: 0; justify-content: flex-end; }
.mgmt-tabs-glide.can-left::before,
.mgmt-tabs-glide.can-right::after { opacity: 1; }
.mgmt-tabs-glide.can-left .mgmt-tabs {
    -webkit-mask-image: linear-gradient(to right, transparent, #000 3rem);
            mask-image: linear-gradient(to right, transparent, #000 3rem);
}
.mgmt-tabs-glide.can-right .mgmt-tabs {
    -webkit-mask-image: linear-gradient(to left, transparent, #000 3rem);
            mask-image: linear-gradient(to left, transparent, #000 3rem);
}
.mgmt-tabs-glide.can-left.can-right .mgmt-tabs {
    -webkit-mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
            mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
}
@media (prefers-reduced-motion: reduce) {
    .mgmt-tabs-glide::before, .mgmt-tabs-glide::after { transition: none; }
}
.mgmt-tab {
    display: inline-flex; align-items: center; gap: .3rem; flex: 0 0 auto;
    padding: .5rem .7rem;
    border: none; background: none; cursor: pointer;
    font-family: var(--font-body); font-size: .75rem;
    color: var(--grey); text-decoration: none;
    /* The Director's console carries four tabs and the longest of them is
       "Head of Academic Programs", which wrapped to two lines and left the row
       uneven. It stays on one line now and the row keeps its height whatever
       is on it. */
    white-space: nowrap;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.mgmt-tab:hover { color: var(--maroon); border-bottom-color: var(--soft-maroon); }
.mgmt-tab.is-on { color: var(--maroon); border-bottom-color: var(--maroon); font-weight: 500; }
.mgmt-tab .count { color: var(--grey); font-size: .625rem; }

/* While a search is on, each tab's count is what that tab holds of the matches,
   so the figure says which tab to open. Marked in the accent, or a tab reading 0
   looks like a console that has lost its rows. */
.mgmt-tab .count.is-filtered { color: var(--maroon); font-weight: 600; }

/* ---- The search field on the tab row (includes/manage_search.php) ----
   The same idiom as .search-form (includes/browse_console.php): the box shows
   the focus, the magnifier takes the accent with it, and the field's own ring is
   off, there being no sense in a second rectangle a few pixels inside the first.
   Smaller, because that one is a dashboard's hero search and this rides in a tab
   row. Written here rather than borrowed, because that stylesheet comes with the
   whole browse console and no management page carries it. */
.mgmt-search {
    display: flex; align-items: center; gap: .4rem;
    padding: .15rem .5rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    transition: border-color .2s, box-shadow .2s;
}
/* ---- Where the field goes on a wide screen ----
   On the heading's row, at the far end, standing on the line of the description
   under the title. Not on the tab row, where the Manuscript Requests desk has
   it: that desk's roll is the whole page and carries three short tabs, while
   these three consoles share their width with the form panel beside them and
   their row already holds up to four tabs and the Sort chips. Measured on Manage
   Admins at 1440px: the roll's column is 846px and the tabs and chips come to
   782 of it, so a 13rem field on that row overflowed the column and squeezed
   Sort into two lines, which left the tab on view underlining nothing, eight
   pixels clear of its own rule. The heading's row is the full width of the page
   and holds the title and one line of prose, so there it fits with room to
   spare. On a phone the shared script moves the input below the form. */
.mgmt-head-row .mgmt-search { align-self: flex-end; }
.mgmt-search:hover { border-color: var(--soft-maroon); }
.mgmt-search:focus-within {
    border-color: var(--maroon);
    box-shadow: 0 0 0 3px rgba(177,125,125,.20);
}
.mgmt-search .material-symbols-outlined { color: var(--grey); transition: color .2s; }
.mgmt-search:hover .material-symbols-outlined,
.mgmt-search:focus-within .material-symbols-outlined { color: var(--maroon); }
.mgmt-search input {
    width: 13rem; padding: .2rem 0;
    border: 0; background: transparent;
    font-family: inherit; font-size: .75rem; color: var(--ink);
}
.mgmt-search input::placeholder { color: var(--grey); }
/* The shared focus ring is !important, so switching it off for one control has
   to be too. */
.mgmt-search input:focus,
.mgmt-search input:focus-visible { outline: none !important; }
/* The browser's own clear button arrives bright blue and cannot be recoloured as
   a control, so the glyph is redrawn as a mask and the colour comes from
   background-color, which we do control. As .search-input's. */
.mgmt-search input::-webkit-search-cancel-button {
    -webkit-appearance: none;
            appearance: none;
    width: .7rem; height: .7rem;
    cursor: pointer;
    background-color: var(--search-clear, #454545);
    opacity: .7;
    transition: opacity .15s ease;
    -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M1.5 1.5l9 9M10.5 1.5l-9 9' stroke='%23000' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") center / contain no-repeat;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M1.5 1.5l9 9M10.5 1.5l-9 9' stroke='%23000' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") center / contain no-repeat;
}
.mgmt-search input::-webkit-search-cancel-button:hover { opacity: 1; }
/* Light on a dark field, for the same reason it is dark on a light one. */
html[data-mode="dark"] { --search-clear: #CFC3AE; }

/* ---- Filter chips ---- */
.mgmt-chips { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-bottom: .875rem; }
/* The section row is the second step, so it is set back from the first. */
.mgmt-chips-sub {
    margin-top: -.375rem; padding: .5rem .625rem;
    background: var(--cream); border-radius: var(--r-control, 4px);
}
.mgmt-chips-label {
    font-size: .6875rem; color: var(--grey);
    text-transform: uppercase; letter-spacing: .04em; margin-right: .25rem;
}
/* Sort is a different question from which students to show, so it rides on the
   tab row, clear of the filter chips, and lines up with the tabs themselves. */
.mgmt-sort {
    margin-left: auto; display: inline-flex; align-items: center; gap: .25rem;
    padding-bottom: .35rem;
}
.mgmt-sort .mgmt-chip { padding: .25rem .7rem; }
@media (max-width: 780px) {
    .mgmt-tabs-row { flex-wrap: wrap; row-gap: .5rem; }
    .mgmt-tabs-glide { flex: 1 1 100%; }
    .mgmt-tab { padding-left: .55rem; padding-right: .55rem; }
    .mgmt-sort { margin-left: 0; width: 100%; padding-bottom: .5rem; }
    /* The search field lives above the tabs after the form on narrow screens.
       Hide its heading position until the shared script moves the same input. */
    .mgmt-head-row > .mgmt-search { display: none; }
    .mgmt-search-mobile { margin-bottom: .75rem; }
    .mgmt-search-mobile .mgmt-search { width: 100%; }
    .mgmt-head-row .mgmt-search { width: 100%; }
    .mgmt-search input { width: 100%; }
}

/* Why a row is in the second tab, said on the row itself. */
.mgmt-tag {
    display: inline-block; margin-left: .35rem; vertical-align: 1px;
    padding: .05rem .4rem; border-radius: var(--r-badge, 2px);
    background: var(--cream); color: var(--grey);
    font-size: .625rem; font-weight: 500; text-transform: uppercase; letter-spacing: .03em;
}
.mgmt-tag.is-lapsed { background: #fdeaea; color: var(--dark-maroon); }
.mgmt-chip {
    border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    background: var(--white); color: var(--ink);
    font-family: var(--font-body); font-size: .75rem;
    padding: .3rem .8rem; cursor: pointer;
}
.mgmt-chip:hover { border-color: var(--soft-maroon); color: var(--maroon); }
.mgmt-chip.is-on {
    background: var(--cream); border-color: var(--maroon);
    color: var(--maroon); font-weight: 500;
}

/* ---- Roll ---- */
.mgmt-scroll { max-width: 100%; overflow-x: auto; }
.mgmt-table { width: 100%; border-collapse: collapse; font-size: .8125rem; color: var(--ink); }
.mgmt-table tbody tr.is-search-off { display: none; }
.mgmt-table th {
    text-align: left; white-space: nowrap;
    padding: .6rem .625rem;
    background: var(--cream);
    font-size: .6875rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .04em;
    color: var(--grey);
    border-bottom: 1px solid var(--border);
}
.mgmt-table td { padding: .7rem .625rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
/* A plain <a> inside a cell (a paper title linking out, say) otherwise renders
   in the browser's default link blue, which reads as broken next to a page
   built entirely from the theme's own palette. */
.mgmt-table td a { color: var(--maroon); }
.mgmt-table td a:hover { color: var(--dark-maroon); text-decoration-thickness: 2px; }
/* Actions hug the right edge; everything else takes the room it needs.
   The alignment lives on the header and on the button row, not on the cell:
   the reset dialog is rendered inside that cell, and a cell-wide text-align
   was reaching into it and right-aligning its labels. */
.mgmt-table th:last-child, .mgmt-table td:last-child { width: 1%; }
.mgmt-table th:last-child { text-align: right; }
.mgmt-table tbody tr:last-child td { border-bottom: none; }
.mgmt-table tbody tr:hover { background: var(--cream); }
/* The email belongs to the name, not to a column of its own, as its own
   column it was the widest thing in the table and pushed the actions off. */
.mgmt-name { font-weight: 500; white-space: nowrap; }
/* A long address would otherwise set the width of the whole column and push
   the actions off the far edge, so it is cut with the full text on hover. */
.mgmt-sub {
    display: block; font-weight: 400; font-size: .6875rem;
    color: var(--grey); margin-top: .1rem;
    max-width: 13rem; overflow: hidden; text-overflow: ellipsis;
}
.mgmt-id { font-variant-numeric: tabular-nums; white-space: nowrap; }
/* The full programme name is far too long for a column; the code carries it and
   the title attribute keeps the whole thing a hover away. */
.mgmt-prog { white-space: nowrap; color: var(--maroon); cursor: help; }
.mgmt-cohort { white-space: nowrap; font-variant-numeric: tabular-nums; }
/* An account close to its end, or past it, says so where the date is. */
.mgmt-sub.is-closing { color: var(--dark-maroon); }
.mgmt-sub.is-lapsed  { color: var(--bad-text); font-weight: 500; }
.mgmt-table tbody tr.is-lapsed > td { background: var(--bad-bg); }
.mgmt-date { white-space: nowrap; color: var(--grey); font-size: .75rem; }
.mgmt-actions { display: flex; gap: .25rem; white-space: nowrap; justify-content: flex-end; }
/* Each action posts its own form. Left as blocks they stack one per line, so
   the forms step out of the way and let the buttons be the flex items. */
.mgmt-actions form { display: contents; }
/* Borderless: a row of outlined boxes competed with the table's own rules for
   attention. The words carry the action; a tint on hover confirms the target. */
.mgmt-act {
    border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon);
    font-family: var(--font-body); font-size: .6875rem;
    padding: .25rem .5rem; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: .2rem;
}
.mgmt-actions .material-symbols-outlined { font-size: 16px; }
.mgmt-act:hover { background: var(--cream); color: var(--maroon); }
.mgmt-act:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.mgmt-act.is-danger { color: var(--dark-maroon); }
.mgmt-act.is-danger:hover { background: var(--maroon-surface-hover); color: #fff; }
/* The Generate button beside the password box is not in a table row, so it
   keeps an edge: there it reads as part of the field. */
.mgmt-with-btn .mgmt-act { border: 1px solid var(--border); background: var(--white); }
.mgmt-with-btn .mgmt-act:hover { border-color: var(--soft-maroon); }
.mgmt-row-off td { color: var(--grey); }
.mgmt-row-off .mgmt-prog { color: var(--grey); }

/* ---- Nothing to show ---- */
.mgmt-empty { padding: 3rem 1rem; text-align: center; color: var(--grey); font-size: .8125rem; }
.mgmt-empty .material-symbols-outlined { font-size: 34px; display: block; margin: 0 auto .5rem; opacity: .5; }

/* ---- The help dialog ----
   Its own styles rather than Bootstrap's: this page's dialogs have been caught
   under a backdrop once already, and there is nothing here to inherit from. */
.mgmt-help-backdrop {
    position: fixed; inset: 0; z-index: 20000;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem; background: rgba(51, 0, 0, .45);
    opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.mgmt-help-backdrop.is-open { opacity: 1; pointer-events: auto; }
.mgmt-help-panel {
    width: 100%; max-width: 30rem; background: var(--white);
    border-radius: var(--r-card, 8px); box-shadow: 0 18px 48px rgba(51, 0, 0, .28);
    overflow: hidden; max-height: calc(100vh - 3rem); display: flex; flex-direction: column;
}
.mgmt-help-head {
    display: flex; align-items: center; gap: .5rem;
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--maroon);
}
.mgmt-help-head .material-symbols-outlined { color: var(--maroon); font-size: 20px; }
.mgmt-help-head h2 {
    font-family: var(--font-head); font-size: 1rem; font-weight: 500;
    color: var(--maroon); margin: 0; flex: 1 1 auto;
}
.mgmt-help-close {
    border: none; background: none; cursor: pointer; padding: .15rem;
    color: var(--grey); border-radius: var(--r-control, 4px); display: inline-flex;
}
.mgmt-help-close:hover { background: var(--cream); color: var(--maroon); }
.mgmt-help-panel:focus { outline: none; }
.mgmt-help-body {
    padding: 1.25rem; overflow-y: auto;
    font-size: .8125rem; color: var(--ink); line-height: 1.65;
}
.mgmt-help-body p { margin: 0 0 .75rem; }
.mgmt-help-body p:last-child { margin-bottom: 0; }
.mgmt-help-body strong { color: var(--maroon); font-weight: 600; }
.mgmt-help-body em { font-style: normal; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.mgmt-help-note {
    background: var(--cream); border-radius: var(--r-card, 8px); padding: .625rem .75rem;
}
.mgmt-help-foot { display: flex; justify-content: flex-end; padding: 0 1.25rem 1.25rem; }

/* ---- Flash ---- */
.mgmt-flash {
    display: flex; align-items: flex-start; gap: .5rem;
    border-radius: var(--r-card, 8px); border: 1px solid var(--border);
    font-size: .8125rem; padding: .75rem 1rem; margin-bottom: 1rem;
}
.mgmt-flash .material-symbols-outlined { font-size: 18px; flex: 0 0 auto; }
.mgmt-flash.is-good { background: var(--ok-bg); border-color: var(--ok-border); color: var(--ok-text); }
.mgmt-flash.is-bad  { background: var(--bad-bg); border-color: var(--bad-border); color: var(--bad-text); }

/* ---- Where the form sits ----
   The roll is the page; the form beside it is a tool, and which hand it is
   held in is a preference. Left is how it has always been. Right suits a
   reader working down the roll with the form out of the way of the first
   column. Centre drops the two-column shell altogether: the roll takes the
   full width and the form sits above it, which is the only arrangement that
   gives a long table room on a laptop.

   Set on <html> rather than on the grid, so the choice is applied before the
   page paints (see the script at the foot of this file) and holds across all
   four consoles. */
html.mgmt-dock-right .mgmt-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 19rem); }
html.mgmt-dock-right .mgmt-grid > #formPanel { order: 2; }
html.mgmt-dock-right .mgmt-grid > :not(#formPanel) { order: 1; }

html.mgmt-dock-center .mgmt-grid { grid-template-columns: minmax(0, 1fr); }
/* The full width of the shell, the same as the roll below it: held in to a
   column of its own it left a wide empty margin down either side. */
html.mgmt-dock-center .mgmt-grid > #formPanel { width: 100%; }

/* The control itself: a row of three in the panel's top right corner. It is
   the first thing in the body rather than a header strip of its own, because
   this panel deliberately has no heading. The negative margins pull it into
   the corner so it costs the form almost no height. */
.mgmt-dock {
    display: flex; justify-content: flex-end; gap: .125rem;
    margin: -.375rem -.375rem .25rem 0;
}
.mgmt-dock-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px;
    border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--grey);
    cursor: pointer; transition: background .15s, color .15s;
}
.mgmt-dock-btn .material-symbols-outlined { font-size: 18px; }
.mgmt-dock-btn:hover { background: var(--cream); color: var(--maroon); }
/* The one in force is the state, not an action, so it is marked rather than
   left looking like the two that would move something. */
.mgmt-dock-btn[aria-pressed="true"] { background: var(--cream); color: var(--maroon); }
/* The chevron is at the far left of the strip, the three positions at the
   right. Apart from them because it does a different job, and read as a
   fourth position when it was flush against them; on the left because that
   is where it stays once the panel is folded, so it does not jump across the
   page as it is pressed. */
.mgmt-dock-fold { width: auto; min-width: 26px; padding: 0 .25rem; gap: .375rem; margin-right: auto; }
.mgmt-dock-fold .material-symbols-outlined { transition: transform .18s ease; }
.mgmt-dock-label {
    color: var(--maroon); font-family: var(--font-body);
    font-size: .75rem; font-weight: 500; white-space: nowrap;
}

/* Folded: the card goes altogether, not just its contents. What is left is the
   chevron that brings it back, at the left where the panel began, and the roll
   with the whole width to itself. The three position buttons go with the card:
   there is nothing to position while it is away.

   Keyed off <html> rather than the panel so the state is settled before the
   page paints, the same way the position is. Collapsed shows ">", the
   convention card_collapse.php set. */
html.mgmt-form-folded .mgmt-grid { grid-template-columns: minmax(0, 1fr); }
/* Right-docked, #formPanel is ordered after the roll. Folded there is one
   column, and that order would drop the chevron below the whole table. Named
   by its id, because the rule it has to beat is.
   Both selectors below, so neither the panel nor the roll keeps an order. */
html.mgmt-form-folded .mgmt-grid > #formPanel,
html.mgmt-form-folded .mgmt-grid > :not(#formPanel) { order: 0; }
html.mgmt-form-folded #formPanel {
    background: none; border: 0; border-radius: 0; overflow: visible;
}
html.mgmt-form-folded #formPanel .mgmt-panel-body { padding: 0; }
html.mgmt-form-folded #formPanel .mgmt-panel-body > :not(.mgmt-dock) { display: none; }
html.mgmt-form-folded #formPanel .mgmt-dock { justify-content: flex-start; margin: 0 0 .25rem; }
html.mgmt-form-folded .mgmt-dock-btn:not(.mgmt-dock-fold) { display: none; }
html.mgmt-form-folded .mgmt-dock-fold { margin-left: 0; }
html.mgmt-form-folded .mgmt-dock-fold .material-symbols-outlined { transform: rotate(-90deg); }

@media (max-width: 1100px) {
    .mgmt-grid,
    html.mgmt-dock-right .mgmt-grid,
    html.mgmt-dock-center .mgmt-grid,
    html.mgmt-form-folded .mgmt-grid { grid-template-columns: minmax(0, 1fr); }
    /* A saved right dock belongs to the desktop layout. Keep the form above
       the roll when the two columns stack. */
    html.mgmt-dock-right .mgmt-grid > #formPanel,
    html.mgmt-dock-right .mgmt-grid > :not(#formPanel) { order: 0; }
    /* Position choices need two columns; the fold control still needs to be
       reachable when a form was folded on a wider screen. */
    .mgmt-dock-btn:not(.mgmt-dock-fold) { display: none; }
}
@media (max-width: 600px) {
    .mgmt-pair { grid-template-columns: 1fr; }
    .mgmt-grid { gap: .75rem; }
    .mgmt-panel-body { padding: .875rem; }
}
@media (max-width: 380px) {
    body:has(.mgmt-wrap) .crumb-inner { flex-wrap: wrap; }
}

/* ---- The row a link arrived for ----
   A support request, or a notification, sends the reader here about one named
   account. These rolls run to dozens of rows, so the one that was meant is
   marked and scrolled to rather than left to be found by eye. The mark stays:
   it is the answer to "which one", and it should still be there after the
   reader has looked away to read the request. */
.mgmt-table tbody tr.is-found > td {
    background: var(--cream);
}
.mgmt-table tbody tr.is-found > td:first-child {
    box-shadow: inset 3px 0 0 var(--maroon);
}


/* ---- Import from a file (includes/manage_import.php) ----
   A button beside the search, opening a drawer down the right-hand side, so
   the form and the roll keep the whole page. Placed in the grid, as it first
   was, the upload card took the roll's column and pushed the table down: the
   grid is two columns and a third child goes beside the form, not under it. */
/* stretch, not flex-end, so the button is exactly as tall as the search field
   beside it without either being given a height. The search has none to copy:
   it is padding round an input and an icon, so any number written here would be
   a guess that drifts the moment that padding or font size changes. Wrapped
   onto two lines each is its own height again, which is what is wanted. */
.mgmt-head-tools {
    display: flex; align-items: stretch; gap: .6rem;
    flex-wrap: wrap; justify-content: flex-end;
}
/* The shared rule puts the search on the heading's baseline; inside the tools
   row it has to stretch with everything else, so that one is turned off. */
.mgmt-head-row .mgmt-head-tools .mgmt-search { align-self: stretch; }

/* On a phone the Import file button stays **beside** the search rather than
   dropping to a line of its own. The two are one toolbar: a button alone on a
   row reads as a second, unrelated thing to press. What pushed it off was
   `.mgmt-head-row .mgmt-search { width: 100% }` in the narrow block above,
   which leaves no room next to it. The button keeps its own width (it already
   refuses to wrap its words) and the field takes whatever is left, down to
   nothing, which is what `min-width: 0` is for on a flex item.

   **This block has to live here, after `.mgmt-head-tools` above, not up in the
   `max-width: 780px` section.** That section comes earlier in this stylesheet,
   and `flex-wrap: wrap` on the base rule is the same specificity, so written
   there it lost on source order: the tools went on wrapping, and the two sat
   side by side only at the widths where they happened to fit anyway. */
@media (max-width: 780px) {
    .mgmt-head-row .mgmt-head-tools {
        flex-wrap: nowrap; width: 100%; justify-content: flex-start;
    }
    .mgmt-head-row .mgmt-head-tools .mgmt-search {
        flex: 1 1 auto; width: auto; min-width: 0;
    }
    .mgmt-head-row .mgmt-head-tools .import-open { flex: 0 0 auto; }
}
/* `button.`, not a bare class. `import-open` is two things: this button, and the
   class put on <html> to hold the page still while the drawer is up. A bare
   `.import-open` matched the <html> element as well, so `white-space: nowrap`
   was inherited by the whole document for exactly as long as the drawer was
   open: the heading and the sentence under it then ran off the end of the
   drawer in one line instead of wrapping. Two things sharing a class name is
   the mistake `.modal-backdrop` cost a build to, in the other direction. */
button.import-open { white-space: nowrap; justify-content: center; }
.import-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 1.25rem; height: 1.25rem; padding: 0 .3rem; margin-left: .1rem;
    border-radius: 999px; background: var(--white); color: var(--maroon);
    font-size: .7rem; font-weight: 700; font-variant-numeric: tabular-nums;
}

/* 19000, under the 20000 of the help dialog and of action_dialogs.php, so the
   "Create N accounts?" confirmation opens over the drawer and not beneath it.
   Never .modal-backdrop: Bootstrap generates that class and these pages load
   Bootstrap, which is how the login slide-in once buried every modal. */
.import-veil {
    position: fixed; inset: 0; z-index: 19000;
    background: rgba(51, 0, 0, .45);
    opacity: 0; transition: opacity .2s ease;
}
.import-veil.is-open { opacity: 1; }

.import-drawer {
    position: fixed; top: 0; right: 0; bottom: 0; z-index: 19001;
    width: min(46rem, 100vw);
    display: flex; flex-direction: column;
    background: var(--white);
    border-left: 1px solid var(--border);
    box-shadow: -18px 0 48px rgba(51, 0, 0, .22);
    transform: translateX(100%);
    transition: transform .22s ease;
}
.import-drawer.is-open { transform: translateX(0); }
@media (prefers-reduced-motion: reduce) {
    .import-veil, .import-drawer { transition: none; }
}

.import-drawer-head {
    display: flex; align-items: center; gap: .5rem; flex: none;
    padding: .9rem 1.1rem; border-bottom: 1px solid var(--maroon);
}
.import-drawer-head .material-symbols-outlined { color: var(--maroon); font-size: 20px; }
.import-drawer-head h2 {
    font-family: var(--font-head); font-size: 1rem; font-weight: 500;
    color: var(--maroon); margin: 0; flex: 1 1 auto;
}
.import-close {
    border: none; background: none; cursor: pointer; padding: .15rem;
    display: inline-flex; color: var(--grey); border-radius: 6px;
}
.import-close:hover { color: var(--maroon); background: var(--cream); }

/* The drawer scrolls, not the page behind it: html.import-open holds that
   still, and on <html> rather than <body> for the reason written on the
   expanded section of the upload page. */
.import-drawer-body { flex: 1 1 auto; overflow-y: auto; padding: 1rem 1.1rem 1.25rem; }
.import-drawer-body > .mgmt-panel { border: 0; }
/* `~` and not `+`: the preview prints its own <script> after the table, so the
   upload card is not the panel's *adjacent* sibling and `+` matched nothing at
   all. The two panels then met at exactly 0px, with no rule and no margin
   between the "Email each person their password" line and "Import from a file",
   which reads as one run-on panel. An element that draws nothing still counts
   as a sibling; the upload page's step bar cost a build to the same trap. */
.import-drawer-body > .mgmt-panel ~ .mgmt-panel { margin-top: 1.25rem; border-top: 1px solid var(--border); }
.import-drawer-body .mgmt-panel-body { padding: 0; }
.import-drawer-body > .mgmt-panel ~ .mgmt-panel .mgmt-panel-body { padding-top: 1.25rem; }
html.import-open { overflow: hidden; }

/* In a drawer the table has the full height to use, and the page is not
   scrolling behind it, so it is the body that scrolls rather than the box. */
.import-drawer-body .import-scroll { max-height: none; }

/* Wrapping, because the file's name is in here and it is somebody else's: the
   icon and the count stay on the first line and a long name takes the next
   rather than widening the panel. */
.import-h {
    display: flex; align-items: center; flex-wrap: wrap; gap: .4rem;
    margin: 0 0 .35rem; font-size: .95rem; font-weight: 700; color: var(--maroon);
}
.import-h .material-symbols-outlined { font-size: 1.1rem; }
.import-lead,
.import-note { margin: 0 0 .7rem; font-size: .8rem; color: var(--grey); line-height: 1.5; }
.import-form .mgmt-field { margin-bottom: .6rem; }
.import-go { margin-top: .2rem; }

.import-sum {
    display: flex; flex-wrap: wrap; gap: .75rem;
    align-items: flex-start; justify-content: space-between;
}
/* A flex item will not shrink below the width of its own content unless it is
   told it may, so the heading and the sentence under it kept their one-line
   width and ran off the end of the drawer instead of wrapping. `min-width: 0`
   is what lets them wrap; the file name is somebody's, so it may be one long
   word, and `overflow-wrap` breaks that rather than pushing the panel wide. */
/* The whole line, so Undo, Redo and Discard stay on a row of their own under
   the sentence rather than being pulled up beside the heading as soon as the
   text is allowed to wrap. */
.import-sum > div { min-width: 0; flex: 1 1 100%; }
.import-sum .import-h,
.import-sum .import-lead { overflow-wrap: anywhere; }
/* Undo, Redo and Discard on one line: three ways back from a correction, in
   order of how much they throw away. The gap before Discard is wider than the
   one between the pair, so the destructive one is not pressed for the mild one. */
.import-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-bottom: .85rem; }
.import-tools .import-discard { margin: 0 0 0 .5rem; }
.import-discard { margin: 0; }
.import-history { display: inline-flex; gap: .4rem; }
/* No border on these three. They sit over the table as a toolbar does, and
   three outlined boxes in a row read as three decisions to make rather than as
   the small corrections they are. The hover fill is what answers the pointer,
   and :focus-visible keeps its ring: a control with neither border nor focus
   ring cannot be found from the keyboard at all. */
.import-tools .btn-sm-outline {
    border-color: transparent; background: none; padding: .4rem .7rem;
}
.import-tools .btn-sm-outline:hover:not([disabled]) { background: var(--cream); }
.import-history button { display: inline-flex; align-items: center; gap: .25rem; }
.import-history button .material-symbols-outlined { font-size: 1rem; }
.import-history button[disabled] { opacity: .45; cursor: not-allowed; }

.import-warn {
    display: flex; align-items: flex-start; gap: .4rem;
    margin: 0 0 .7rem; padding: .5rem .6rem;
    font-size: .78rem; line-height: 1.5;
    color: var(--ink); background: var(--cream);
    border: 1px solid var(--border); border-radius: 6px;
}
.import-warn .material-symbols-outlined { font-size: 1rem; color: var(--maroon); flex: none; }

/* A wide table in a column that must not widen the page: the scroll is on this
   box, never on the body. */
.import-scroll { overflow-x: auto; max-height: 26rem; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; }
.import-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
.import-table th,
.import-table td { padding: .4rem .5rem; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; }
.import-table thead th {
    position: sticky; top: 0; z-index: 1;
    background: var(--cream); color: var(--ink);
    font-weight: 700; white-space: nowrap;
}
.import-table tbody tr:last-child td { border-bottom: 0; }
.import-table tr.is-bad { background: color-mix(in srgb, var(--maroon) 6%, transparent); }
.import-tick { width: 2rem; }
/* The browser's own control, in the site's colour. accent-color recolours the
   tick and its box without replacing the control, so the keyboard, the focus
   ring and the indeterminate state all stay as the platform draws them. */
.import-tick input,
.import-notify input { accent-color: var(--maroon); width: 1rem; height: 1rem; }

/* Already on an account: greyed, and its tick locked, so it cannot be created.
   Editing the ID or the email unlocks it, since that may be what mends it. */
.import-table tr.is-taken { background: color-mix(in srgb, var(--ink) 5%, transparent); }
.import-table tr.is-taken .import-cell { color: var(--grey); }
.import-table tr.is-taken .import-tick input { cursor: not-allowed; }
/* The spreadsheet's line number was the second column and meant nothing to
   anybody reading the preview: the file is on screen, and a row can now be
   taken out or added here, so the number it happened to have is not even
   stable. Its place is the remove button. The line itself lives on in
   data-line, which is what the posted field names are keyed by. */
.import-act { width: 2.2rem; }
.import-row-del {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.6rem; height: 1.6rem; padding: 0;
    border: 1px solid transparent; border-radius: var(--r-control, 4px);
    background: none; color: var(--grey); cursor: pointer;
}
.import-row-del .material-symbols-outlined { font-size: 1rem; }
.import-row-del:hover { color: var(--maroon); background: var(--cream); border-color: var(--border); }
/* A row added here has nothing in it yet, so it says so rather than showing a
   password worked out from two empty boxes. */
.import-row.is-new .import-pw { color: var(--grey); font-style: italic; }
.import-add {
    display: inline-flex; align-items: center; gap: .3rem; margin-top: .6rem;
    padding: .35rem .7rem; font-size: .75rem;
    color: var(--maroon); background: none; border: 1px dashed var(--soft-maroon);
    border-radius: var(--r-control, 4px); cursor: pointer; font-family: var(--font-body);
}
.import-add:hover { background: var(--cream); }
.import-add .material-symbols-outlined { font-size: 1rem; }
.import-pw { font-family: ui-monospace, Consolas, monospace; white-space: nowrap; }
.import-pw-pending { font-family: inherit; color: var(--grey); font-style: italic; }

/* Every cell of the preview is editable, so a row can be mended here rather
   than in the spreadsheet. They are written as plain text until focused: a
   table of forty boxed inputs reads as a form to fill in rather than a list to
   check, which is the opposite of what this screen is for. */
.import-cell {
    width: 100%; min-width: 6rem;
    padding: .2rem .3rem; margin: -.2rem -.3rem;
    font-family: inherit; font-size: inherit; color: inherit;
    background: transparent; border: 1px solid transparent;
    border-radius: var(--r-control, 4px);
}
.import-cell:hover { border-color: var(--border); background: var(--white); }
.import-cell:focus {
    outline: none; background: var(--white);
    border-color: var(--maroon); box-shadow: 0 0 0 2px color-mix(in srgb, var(--maroon) 18%, transparent);
}
select.import-cell { min-width: 8rem; cursor: pointer; }
.import-table.is-editable td { vertical-align: middle; }
.import-table.is-editable .import-status { vertical-align: top; }
.import-ok { color: var(--maroon); font-weight: 600; }
.import-skip { color: var(--grey); }
.import-why { margin: 0; padding-left: 1rem; }
.import-why li { margin: 0; }
/* Room for a sentence. The whole document used to inherit `white-space: nowrap`
   from the class the drawer puts on <html> (see button.import-open above), so
   every reason sat on one line and this column was as wide as the longest of
   them. With the text wrapping properly it was squeezed to 79px instead, and a
   row with one thing wrong stood three times the height of its neighbours. */
.import-status { min-width: 11rem; }

.import-foot {
    display: flex; flex-wrap: wrap; gap: 1rem;
    align-items: center; justify-content: space-between;
    margin-top: 1rem; padding-bottom: .25rem;
}
.import-notify { display: flex; align-items: flex-start; gap: .45rem; font-size: .8rem; cursor: pointer; }
.import-notify input { margin-top: .2rem; }
.import-notify small { display: block; margin-top: .15rem; color: var(--grey); font-size: .72rem; line-height: 1.45; }

@media (max-width: 720px) {
    .import-foot { align-items: stretch; }
    .import-scroll { max-height: 20rem; }
}
</style>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Arrived at with ?q=<the ID on the account>. Opens the tab the row is on,
   marks it and brings it into view. Shared by all four consoles: they differ in
   which column holds the identifier, so all of them are checked. */
(function () {
    var wanted = new URLSearchParams(location.search).get('q');
    if (!wanted) return;
    wanted = wanted.trim().toLowerCase();

    document.addEventListener('DOMContentLoaded', function () {
        /* One tick later, for the same reason the password tab waits: each
           console wires its own tabs in a DOMContentLoaded listener registered
           after this one, and a tab clicked before that is a button nothing is
           listening to. */
        setTimeout(function () {
            var rows = document.querySelectorAll('.mgmt-table tbody tr[data-student-id],'
                     + '.mgmt-table tbody tr[data-faculty-id],'
                     + '.mgmt-table tbody tr[data-username]');
            var hit = null;
            Array.prototype.forEach.call(rows, function (row) {
                if (hit) return;
                var d = row.dataset;
                [d.studentId, d.facultyId, d.username].forEach(function (v) {
                    if (!hit && v && v.trim().toLowerCase() === wanted) hit = row;
                });
            });
            if (!hit) return;

            /* The row may be on a tab that is not the one showing: an archived
               student, say. Press that tab rather than revealing the pane here,
               so the console stays the only thing deciding what "selected"
               looks like. Which pane it sits in is read from the tabs, because
               the four consoles name their panes differently: activePane on
               one, pane0 on another. */
            Array.prototype.forEach.call(
                document.querySelectorAll('.mgmt-tab[data-pane]'), function (tab) {
                    var pane = document.getElementById(tab.dataset.pane);
                    if (pane && pane.contains(hit) && !tab.classList.contains('is-on')) tab.click();
                });

            hit.classList.add('is-found');

            /* Open the account for editing, by pressing the row's own Edit
               rather than filling the panel from here: the console knows what
               editing means on its own roll (which fields, what the panel
               title says, that a blank password keeps the old one) and there is
               no second copy of that to fall out of step.
               Its own scrolling then stands, which is why the row is only
               scrolled to when there is no Edit to press. */
            var edit = hit.querySelector('.js-edit');
            if (edit) edit.click();
            else hit.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }, 0);
    });
})();

/* ---- The tab the reader is on, kept for the length of the visit ----

   Every action on these consoles - create, edit, archive, restore, delete,
   and the password changes - posts and is answered with a redirect back to
   the page. A redirect is a fresh load, and a fresh load starts on the first
   tab, so archiving a student while reading the Archived tab, or deleting a
   librarian from the Librarian tab, threw the reader back to Active and left
   them to find their way again after every single action. On Manage Admins,
   where the tabs are whole roles, it meant the Librarian list could not be
   worked through at all without re-selecting it each time.

   sessionStorage, not localStorage: where someone is in a console is a
   position, not a preference like the form's side below, and it should not
   outlive the visit or reach into another window. Keyed on the path, so the
   four consoles remember separately.

   Pressed rather than revealed, and a tick after DOMContentLoaded, for the
   reason the ?q= opener above gives: each console wires its own tabs in a
   listener registered after this one, so the console stays the only thing
   deciding what a selected tab looks like. The skeleton (shape 'table') is
   still over the page at that point, so nothing is seen to change. */
(function () {
    var KEY = 'papel_mgmt_tab:' + location.pathname;

    /* Delegated, so it holds whatever a console does with its own tabs and
       whichever order the two listeners were registered in. It also records
       the tab the ?q= opener presses, which is correct: that is where the
       reader has been put. */
    document.addEventListener('click', function (e) {
        var tab = e.target && e.target.closest ? e.target.closest('.mgmt-tab[data-pane]') : null;
        if (!tab) return;
        try { sessionStorage.setItem(KEY, tab.dataset.pane); } catch (err) {}
    });

    /* A deep link names the tab itself, so it wins over the remembered one. */
    if (new URLSearchParams(location.search).get('q')) return;

    document.addEventListener('DOMContentLoaded', function () {
        var want = null;
        try { want = sessionStorage.getItem(KEY); } catch (err) {}
        if (!want) return;
        setTimeout(function () {
            var tabs = document.querySelectorAll('.mgmt-tab[data-pane]');
            Array.prototype.forEach.call(tabs, function (tab) {
                /* Gone since it was remembered - the last account on a tab
                   deleted, say - and the console keeps its own default. */
                if (tab.dataset.pane === want && !tab.classList.contains('is-on')) tab.click();
            });
        }, 0);
    });
})();

/* ---- Where the form sits: left, centre or right ----
   Three buttons in the form panel's top right corner. The choice is kept on
   this device and applied to all four consoles, so a reader sets it once.

   Read and applied as this file is parsed, which is inside <head>: waiting
   for DOMContentLoaded would paint the form on the left first and move it
   afterwards, which reads as the page correcting itself. The buttons are put
   in once there is a panel to put them in. */
(function () {
    var KEY   = 'papel_manage_dock';
    var FOLD  = 'papel_manage_folded';
    var SIDES = ['left', 'center', 'right'];
    var ICONS = {
        left:   'dock_to_left',
        /* A plain window beside the two docked ones: the same drawing, put in
           the middle rather than against an edge. */
        center: 'web_asset',
        right:  'dock_to_right'
    };
    var NAMES = { left: 'the left', center: 'the centre', right: 'the right' };
    var root  = document.documentElement;

    function current() {
        var v;
        try { v = localStorage.getItem(KEY); } catch (e) {}
        return SIDES.indexOf(v) === -1 ? 'left' : v;
    }

    function apply(side, remember) {
        root.classList.toggle('mgmt-dock-right',  side === 'right');
        root.classList.toggle('mgmt-dock-center', side === 'center');
        if (remember) { try { localStorage.setItem(KEY, side); } catch (e) {} }
        document.querySelectorAll('.mgmt-dock-btn').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.side === side ? 'true' : 'false');
        });
    }

    function folded() {
        try { return localStorage.getItem(FOLD) === '1'; } catch (e) { return false; }
    }

    function fold(on, remember) {
        root.classList.toggle('mgmt-form-folded', on);
        if (remember) { try { localStorage.setItem(FOLD, on ? '1' : '0'); } catch (e) {} }
        document.querySelectorAll('.mgmt-dock-fold').forEach(function (b) {
            b.setAttribute('aria-expanded', on ? 'false' : 'true');
            var say = on ? 'Show the form' : 'Hide the form';
            b.title = say;
            b.setAttribute('aria-label', say);
            b.querySelector('.mgmt-dock-label').textContent = on ? '| Show Form' : '| Hide Form';
        });
    }

    apply(current(), false);          // before the first paint
    fold(folded(), false);

    function addControl() {
        var body = document.querySelector('#formPanel .mgmt-panel-body');
        if (!body || body.querySelector('.mgmt-dock')) return;


        var bar = document.createElement('div');
        bar.className = 'mgmt-dock';
        bar.setAttribute('role', 'group');
        bar.setAttribute('aria-label', 'Where this form sits');

        SIDES.forEach(function (side) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mgmt-dock-btn';
            b.dataset.side = side;
            b.title = 'Move this form to ' + NAMES[side];
            b.setAttribute('aria-label', 'Move this form to ' + NAMES[side]);
            b.innerHTML = '<span class="material-symbols-outlined">' + ICONS[side] + '</span>';
            b.addEventListener('click', function () { apply(side, true); });
            bar.appendChild(b);
        });

        var chev = document.createElement('button');
        chev.type = 'button';
        chev.className = 'mgmt-dock-btn mgmt-dock-fold';
        chev.setAttribute('aria-controls', 'formPanel');
        /* The visible label follows the fold state; aria-label gives the same
           action to screen readers without reading it twice. */
        chev.innerHTML = '<span class="material-symbols-outlined">expand_more</span>'
                       + '<span class="mgmt-dock-label" aria-hidden="true">| Hide Form</span>';
        chev.addEventListener('click', function () {
            fold(!root.classList.contains('mgmt-form-folded'), true);
        });
        bar.insertBefore(chev, bar.firstChild);   // the left end of the strip

        body.insertBefore(bar, body.firstChild);
        apply(current(), false);      // marks the one in force
        fold(folded(), false);

        /* Pressing Edit on a row fills this form in. Folded, that happens out of
           sight and the row looks as though nothing happened, so the panel opens
           itself. The three account consoles mark the panel is-editing; the
           guest console has no edit mode and never trips this. */
        var panel = document.getElementById('formPanel');
        new MutationObserver(function () {
            if (panel.classList.contains('is-editing')) fold(false, true);
        }).observe(panel, { attributes: true, attributeFilter: ['class'] });
    }

    document.addEventListener('DOMContentLoaded', addControl);
})();
</script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The three account consoles share one search input across their tabs. On a
   phone it belongs after the create form, immediately above the account tabs.
   Moving the actual node keeps its value and the search listener on resize. */
document.addEventListener('DOMContentLoaded', function () {
    var search = document.querySelector('.mgmt-head-row > .mgmt-search');
    var tabs = document.querySelector('.mgmt-grid .mgmt-tabs-row');
    if (!search || !tabs || !window.matchMedia) return;

    var home = search.parentNode;
    var next = search.nextSibling;
    var slot = document.createElement('div');
    slot.className = 'mgmt-search-mobile';
    tabs.parentNode.insertBefore(slot, tabs);
    var narrow = window.matchMedia('(max-width: 780px)');

    function place() {
        if (narrow.matches) slot.appendChild(search);
        else home.insertBefore(search, next);
    }
    place();
    if (narrow.addEventListener) narrow.addEventListener('change', place);
    else narrow.addListener(place);
});
</script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Keep the edge cue in step with the part of each management tab row that is
   still out of view. The tab strip itself remains touch and keyboard scrollable. */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mgmt-tabs-glide').forEach(function (glide) {
        var strip = glide.querySelector('.mgmt-tabs');
        if (!strip) return;

        function mark() {
            var max = strip.scrollWidth - strip.clientWidth;
            glide.classList.toggle('can-left', strip.scrollLeft > 2);
            glide.classList.toggle('can-right', strip.scrollLeft < max - 2);
        }
        function reveal(tab) {
            var stripBox = strip.getBoundingClientRect();
            var tabBox = tab.getBoundingClientRect();
            var pad = 12;
            if (tabBox.right > stripBox.right - pad)
                strip.scrollLeft += tabBox.right - stripBox.right + pad;
            else if (tabBox.left < stripBox.left + pad)
                strip.scrollLeft -= stripBox.left - tabBox.left + pad;
            mark();
        }

        strip.addEventListener('scroll', mark, { passive: true });
        strip.addEventListener('click', function (event) {
            var tab = event.target.closest('.mgmt-tab');
            if (tab && strip.contains(tab)) requestAnimationFrame(function () { reveal(tab); });
        });
        if (window.ResizeObserver) {
            var observer = new ResizeObserver(mark);
            observer.observe(strip);
            strip.querySelectorAll('.mgmt-tab').forEach(function (tab) { observer.observe(tab); });
        }
        window.addEventListener('resize', mark);
        if (document.fonts) {
            if (document.fonts.ready) document.fonts.ready.then(mark);
            if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', mark);
        }
        mark();
    });
});
</script>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ---- A field's hint, behind the "?" beside its label --------------------
   Every .mgmt-field with a label of its own and a hint under it gets a "?"
   on the end of the label, and the hint is shown from it instead: on hover,
   on focus, and on a press, the last being the only one a finger has.

   Three fields are left alone, each for its own reason:

     - Academic year and Section, which are one .mgmt-field holding two
       labels and already carry a "?" of their own. Theirs opens the cohort
       dialog, which says more than a line can, so a second "?" beside it
       would be two glyphs offering two different things.
     - A hint that is not a child of a .mgmt-field: the import drawer's note
       about the section is about the whole card, not about one box.
     - .js-field-note, the complaint includes/manage_validate.php writes
       while a box is being typed into. That is the one kind of hint that
       has to be in front of somebody without being asked for.

   The bubble is positioned here rather than in CSS because it is fixed: it
   has to be, or the import drawer and the reset modal would both clip it. */
document.addEventListener('DOMContentLoaded', function () {

    var GUTTER = 8, open = null, shut = null, n = 0;

    function place(btn, tip) {
        /* Measured with the bubble already shown, so its own size is known.
           Left-aligned under the "?" and pulled back inside the window; above
           it instead when there is no room below. */
        var b = btn.getBoundingClientRect(), t = tip.getBoundingClientRect();
        var left = Math.min(Math.max(GUTTER, b.left), window.innerWidth - t.width - GUTTER);
        var top  = b.bottom + 6;
        if (top + t.height > window.innerHeight - GUTTER) {
            top = Math.max(GUTTER, b.top - t.height - 6);
        }
        tip.style.left = Math.round(left) + 'px';
        tip.style.top  = Math.round(top) + 'px';
    }

    function hide() {
        if (!open) { return; }
        open.tip.classList.remove('is-open');
        open.btn.setAttribute('aria-expanded', 'false');
        open = null;
    }
    function hideSoon() { clearTimeout(shut); shut = setTimeout(hide, 120); }
    function keep()     { clearTimeout(shut); }

    function show(btn, tip) {
        clearTimeout(shut);
        if (open && open.btn === btn) { return; }
        hide();
        tip.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        open = { btn: btn, tip: tip };
        place(btn, tip);
    }

    document.querySelectorAll('.mgmt-field').forEach(function (field) {
        var label = field.querySelector(':scope > label');
        var tip   = field.querySelector(':scope > .mgmt-hint:not(.js-field-note)');
        if (!label || !tip || !tip.textContent.trim()) { return; }
        if (label.querySelector('.mgmt-help')) { return; }   // it already has one of its own

        if (!tip.id) { tip.id = 'mgmtTip' + (++n); }
        tip.classList.add('is-tip');
        tip.setAttribute('role', 'tooltip');

        /* Still read out with the field, as it was when it was printed under
           it. Appended rather than assigned: a control may already point at
           something else. */
        var control = field.querySelector('input, select, textarea');
        if (control) {
            var had = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            if (had.indexOf(tip.id) === -1) {
                had.push(tip.id);
                control.setAttribute('aria-describedby', had.join(' '));
            }
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mgmt-help js-hint';
        var mark = document.createElement('span');
        mark.className = 'material-symbols-outlined';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = 'info';
        btn.appendChild(mark);
        btn.setAttribute('aria-expanded', 'false');
        /* The label's own words, without the "*" that marks it required. */
        var what = label.textContent.replace(/\*/g, '').replace(/\s+/g, ' ').trim();   // read before the mark is added
        btn.setAttribute('aria-label', what ? 'More about ' + what : 'More about this field');
        label.appendChild(btn);

        btn.addEventListener('mouseenter', function () { show(btn, tip); });
        btn.addEventListener('mouseleave', hideSoon);
        btn.addEventListener('focus',      function () { show(btn, tip); });
        btn.addEventListener('blur',       hide);
        /* A finger has no hover, so the press is the whole of it there; on a
           mouse the bubble is already up and this closes it again. */
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (open && open.btn === btn) { hide(); } else { show(btn, tip); }
        });
        tip.addEventListener('mouseenter', keep);
        tip.addEventListener('mouseleave', hideSoon);
    });

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
    document.addEventListener('pointerdown', function (e) {
        if (open && !e.target.closest('.js-hint') && !e.target.closest('.mgmt-hint.is-open')) { hide(); }
    });
    /* Fixed to the window, so anything that moves the mark under it leaves the
       bubble behind: it is put back over the mark instead.

       It used to close on a scroll, which looked right and was not. A scroll
       event does not bubble, so this listener is in the capture phase to hear
       one at all, and that means it hears a scroll of ANY box on the page -
       the tab strip gliding, a panel's own overflow, the scrollIntoView the
       form does when a row is opened for editing. The bubble shut itself
       before it had been read, and on the first load it shut before it had
       been seen at all, while the mark it belongs to had not moved a pixel.

       Writing left/top on the bubble itself is the cheap kind of per-scroll
       work; a custom property on :root is the kind this project has already
       paid for once (see the PDF panel in CLAUDE.md). */
    function follow() {
        if (!open) { return; }
        var b = open.btn.getBoundingClientRect();
        if (b.bottom < 0 || b.top > window.innerHeight ||
            b.right < 0 || b.left > window.innerWidth) { hide(); return; }   // scrolled out of sight
        place(open.btn, open.tip);
    }
    document.addEventListener('scroll', follow, true);
    window.addEventListener('resize', follow);
});
</script>
