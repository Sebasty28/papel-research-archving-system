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

.mgmt-wrap { padding: 1.5rem 0 3rem; }

/* ---- Page heading ---- */
.mgmt-head { margin-bottom: 1.25rem; }
.mgmt-head h1 {
    font-family: var(--font-head);
    font-size: 1.375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .2rem;
}
.mgmt-head p { font-size: .8125rem; color: var(--grey); margin: 0; }

/* ---- Two columns: the form keeps its width, the roll takes the rest ---- */
.mgmt-grid {
    display: grid;
    grid-template-columns: minmax(0, 19rem) minmax(0, 1fr);
    gap: 1.125rem;
    align-items: start;
}

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

/* ---- The "?" beside a label ---- */
.mgmt-field label { display: flex; align-items: center; gap: .3rem; }
.mgmt-help {
    width: 14px; height: 14px; flex: 0 0 auto;
    border: 1px solid var(--border); border-radius: 50%;
    background: var(--white); color: var(--grey);
    font-family: var(--font-body); font-size: .5625rem; line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; padding: 0;
}
.mgmt-help:hover { border-color: var(--maroon); color: var(--maroon); background: var(--cream); }
.mgmt-help:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
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

/* ---- Tabs, matching the dashboard's ---- */
.mgmt-tabs {
    display: flex; align-items: center; gap: .25rem;
    border-bottom: 1px solid var(--border);
    margin-bottom: .875rem;
}
.mgmt-tab {
    display: inline-flex; align-items: center; gap: .3rem;
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
    .mgmt-tabs { flex-wrap: wrap; }
    /* Wrapping between tabs is fine on a narrow screen; wrapping inside one is
       what looked broken, so that stays prevented. */
    .mgmt-tab { padding-left: .55rem; padding-right: .55rem; }
    .mgmt-sort { margin-left: 0; width: 100%; padding-bottom: .5rem; }
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
.mgmt-scroll { overflow-x: auto; }
.mgmt-table { width: 100%; border-collapse: collapse; font-size: .8125rem; color: var(--ink); }
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
.mgmt-dock-fold { margin-right: auto; }
.mgmt-dock-fold .material-symbols-outlined { transition: transform .18s ease; }

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

/* Folded, the chevron is the only thing left on the page saying what it does,
   so it says it: Show form slides out from behind a thin rule beside it, the
   way the mark in the navbar draws out the name (.brand-word in site_head.php,
   where the mechanics are explained at length). Absolutely placed, so the
   button keeps its 26px either way and the roll below does not shift as the
   label opens. Out is quicker than in; leaving should not make anyone wait. */
.mgmt-dock-word { display: none; }
html.mgmt-form-folded .mgmt-dock-fold { position: relative; }
html.mgmt-form-folded .mgmt-dock-fold::after {
    content: "";
    position: absolute;
    left: 100%;
    top: 50%;
    width: 1rem;
    height: 1.125rem;
    transform: translateY(-50%);
    background: linear-gradient(var(--soft-maroon), var(--soft-maroon)) center / 1px 100% no-repeat;
    opacity: 0;
    pointer-events: none;
    transition: opacity .22s ease-in;
}
html.mgmt-form-folded .mgmt-dock-fold:hover::after,
html.mgmt-form-folded .mgmt-dock-fold:focus-visible::after {
    opacity: 1;
    transition: opacity .3s ease-out;
}
html.mgmt-form-folded .mgmt-dock-word {
    display: block;
    position: absolute;
    left: calc(100% + .5rem);
    top: 50%;
    padding-left: .5rem;
    font-family: var(--font-head);
    font-size: .8125rem;
    font-weight: 500;
    line-height: 1;
    white-space: nowrap;
    color: var(--maroon);
    clip-path: inset(-.3rem 100% -.3rem .6rem);
    opacity: 0;
    transform: translate(-.6rem, -50%);
    pointer-events: none;
    transition: clip-path .22s ease-in, opacity .22s ease-in, transform .22s ease-in;
}
html.mgmt-form-folded .mgmt-dock-fold:hover .mgmt-dock-word,
html.mgmt-form-folded .mgmt-dock-fold:focus-visible .mgmt-dock-word {
    clip-path: inset(-.3rem -.25rem -.3rem 0);
    opacity: 1;
    transform: translate(0, -50%);
    transition: clip-path .38s cubic-bezier(.2, .8, .2, 1), opacity .3s ease-out,
                transform .38s cubic-bezier(.2, .8, .2, 1);
}

@media (max-width: 1100px) {
    .mgmt-grid { grid-template-columns: 1fr; }
    /* One column either way down here, so there is nothing to choose. */
    .mgmt-dock { display: none; }
}
@media (max-width: 600px) {
    .mgmt-pair { grid-template-columns: 1fr; }
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
        /* The word is for the eye only: the button already says what it does
           through aria-label, and reading both would say it twice. */
        chev.innerHTML = '<span class="material-symbols-outlined">expand_more</span>'
                       + '<span class="mgmt-dock-word" aria-hidden="true">Show form</span>';
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
