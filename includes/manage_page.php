<?php
/**
 * The look of the management consoles.
 *
 * Manage Students was rebuilt on the site's own tokens — panels, chips, tabs,
 * borderless row actions, the two-column shell — and Manage Faculty needs the
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
/* This page used to carry its own design system — Crimson Pro over IBM Plex
   Sans, slate greys, a gold gradient on every card — which is why it read as a
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
    border-radius: 10px;
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
    border: 1px solid var(--border); border-radius: 8px;
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
.mgmt-hint { display: block; font-size: .6875rem; color: var(--grey); margin-top: .25rem; }
/* Academic year and section sit together — they are read as one answer. */
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
    background: var(--cream); border-radius: 8px;
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
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .5rem .9rem;
    border: none; background: none; cursor: pointer;
    font-family: var(--font-body); font-size: .8125rem;
    color: var(--grey); text-decoration: none;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.mgmt-tab:hover { color: var(--maroon); border-bottom-color: var(--soft-maroon); }
.mgmt-tab.is-on { color: var(--maroon); border-bottom-color: var(--maroon); font-weight: 500; }
.mgmt-tab .count { color: var(--soft-maroon); font-size: .6875rem; }

/* ---- Filter chips ---- */
.mgmt-chips { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-bottom: .875rem; }
/* The section row is the second step, so it is set back from the first. */
.mgmt-chips-sub {
    margin-top: -.375rem; padding: .5rem .625rem;
    background: var(--cream); border-radius: 8px;
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
    .mgmt-sort { margin-left: 0; width: 100%; padding-bottom: .5rem; }
}

/* Why a row is in the second tab, said on the row itself. */
.mgmt-tag {
    display: inline-block; margin-left: .35rem; vertical-align: 1px;
    padding: .05rem .4rem; border-radius: 999px;
    background: var(--cream); color: var(--grey);
    font-size: .625rem; font-weight: 500; text-transform: uppercase; letter-spacing: .03em;
}
.mgmt-tag.is-lapsed { background: #fdeaea; color: var(--dark-maroon); }
.mgmt-chip {
    border: 1px solid var(--border); border-radius: 999px;
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
/* Actions hug the right edge; everything else takes the room it needs.
   The alignment lives on the header and on the button row, not on the cell —
   the reset dialog is rendered inside that cell, and a cell-wide text-align
   was reaching into it and right-aligning its labels. */
.mgmt-table th:last-child, .mgmt-table td:last-child { width: 1%; }
.mgmt-table th:last-child { text-align: right; }
.mgmt-table tbody tr:last-child td { border-bottom: none; }
.mgmt-table tbody tr:hover { background: var(--cream); }
/* The email belongs to the name, not to a column of its own — as its own
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
.mgmt-sub.is-lapsed  { color: var(--dark-maroon); font-weight: 500; }
.mgmt-table tbody tr.is-lapsed > td { background: #fdeaea; }
.mgmt-pass {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .75rem; color: var(--maroon);
}
.mgmt-date { white-space: nowrap; color: var(--grey); font-size: .75rem; }
.mgmt-actions { display: flex; gap: .25rem; white-space: nowrap; justify-content: flex-end; }
/* Each action posts its own form. Left as blocks they stack one per line, so
   the forms step out of the way and let the buttons be the flex items. */
.mgmt-actions form { display: contents; }
/* Borderless: a row of outlined boxes competed with the table's own rules for
   attention. The words carry the action; a tint on hover confirms the target. */
.mgmt-act {
    border: none; border-radius: 6px;
    background: none; color: var(--maroon);
    font-family: var(--font-body); font-size: .6875rem;
    padding: .25rem .5rem; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: .2rem;
}
.mgmt-actions .material-symbols-outlined { font-size: 16px; }
.mgmt-act:hover { background: var(--cream); color: var(--maroon); }
.mgmt-act:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.mgmt-act.is-danger { color: var(--dark-maroon); }
.mgmt-act.is-danger:hover { background: var(--dark-maroon); color: #fff; }
/* The Generate button beside the password box is not in a table row, so it
   keeps an edge — there it reads as part of the field. */
.mgmt-with-btn .mgmt-act { border: 1px solid var(--border); background: var(--white); }
.mgmt-with-btn .mgmt-act:hover { border-color: var(--soft-maroon); }
.mgmt-row-off td { color: var(--grey); }
.mgmt-row-off .mgmt-prog, .mgmt-row-off .mgmt-pass { color: var(--grey); }

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
    border-radius: 12px; box-shadow: 0 18px 48px rgba(51, 0, 0, .28);
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
    color: var(--grey); border-radius: 6px; display: inline-flex;
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
    background: var(--cream); border-radius: 8px; padding: .625rem .75rem;
}
.mgmt-help-foot { display: flex; justify-content: flex-end; padding: 0 1.25rem 1.25rem; }

/* ---- Flash ---- */
.mgmt-flash {
    display: flex; align-items: flex-start; gap: .5rem;
    border-radius: 10px; border: 1px solid var(--border);
    font-size: .8125rem; padding: .75rem 1rem; margin-bottom: 1rem;
}
.mgmt-flash .material-symbols-outlined { font-size: 18px; flex: 0 0 auto; }
.mgmt-flash.is-good { background: #e7f6ed; border-color: #bfe3cd; color: #1b5e35; }
.mgmt-flash.is-bad  { background: #fdeaea; border-color: var(--soft-maroon); color: var(--dark-maroon); }

@media (max-width: 1100px) {
    .mgmt-grid { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .mgmt-pair { grid-template-columns: 1fr; }
}
</style>
