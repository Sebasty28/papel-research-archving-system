<?php
/**
 * The paper record, as a page.
 *
 * One paper laid out in full: what was filed, the sections as written, the
 * files that came with it and the review checklist. The student reads their own
 * copy of this; a reviewer reads the same record with the checklist live and
 * the decision at the foot of it. Both include this so the two views cannot
 * drift into looking like different systems.
 *
 * Include inside <head>, after site_head.php and console_shell.php.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.pd-wrap { max-width: 60rem; margin: 0 auto; padding: 1.75rem 0 3rem; }
/* A page with a right-hand rail (the table of contents and citation card on
   the public paper view) needs more room than the plain record does, so it
   opts into this wider cap instead of changing .pd-wrap for every page that
   includes this stylesheet. */
.pd-wrap.has-rail { max-width: 78rem; }

/* Table of contents + citation, run down the right of the record. Only the
   public paper view builds this markup — everything else keeps the plain
   single column above. */
.pd-layout { display: grid; grid-template-columns: minmax(0, 1fr) 16rem; gap: 1.75rem; align-items: start; }
.pd-main { min-width: 0; }
.pd-side {
    position: sticky; top: 76px; display: flex; flex-direction: column; gap: 1.125rem;
    max-height: calc(100vh - 96px); overflow-y: auto; overflow-x: hidden;
}
/* Collapsed by the toggle beneath the status badge in .pd-top — the rail
   leaves the grid entirely rather than shrinking to nothing, so .pd-main
   retakes the width instead of leaving an empty column behind. */
.pd-layout.is-rail-collapsed { grid-template-columns: 1fr; }
.pd-layout.is-rail-collapsed .pd-side { display: none; }

/* Under "Status", right-aligned like the badge above it. Icon-only, the same
   chrome-free treatment as the card-collapse chevron it sits beside in spirit
   — a border here just duplicated the badge's own box outline above it. */
.pd-rail-toggle {
    display: inline-flex; align-items: center; justify-content: center;
    margin-top: .5rem; width: 1.875rem; height: 1.875rem;
    border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon); cursor: pointer;
    transition: background .15s;
}
.pd-rail-toggle:hover { background: var(--cream); }
.pd-rail-toggle .material-symbols-outlined { font-size: 18px; }

.pd-side .pd-card { padding: 1.125rem 1.25rem; margin-bottom: 0; }
.pd-side .pd-card > h2 { font-size: .8125rem; margin-bottom: .75rem; }
.pd-side .pd-card > h2 .material-symbols-outlined { font-size: 16px; }
/* The title grows to fill the row so the swap icon and the collapse chevron
   card_collapse.php appends land together at the edge, instead of each
   claiming its own share of an auto margin and drifting apart. */
.pd-card-title { flex: 1 1 auto; min-width: 0; }

/* Sends the whole rail to the other side of the record — the same control,
   the same icon pair and the same stored preference (papel_sidebar_side) as
   the Public Repository's own panel-side tool, so the choice made there
   carries over here. */
.pd-side-swap {
    display: inline-flex; align-items: center; justify-content: center;
    padding: .15rem; border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon); cursor: pointer; transition: background .15s;
}
.pd-side-swap:hover { background: var(--cream); }
.pd-side-swap .material-symbols-outlined { font-size: 18px; }
.pd-side-swap .side-icon-right { display: none; }
html.sidebar-left .pd-side-swap .side-icon-left { display: none; }
html.sidebar-left .pd-side-swap .side-icon-right { display: inline-flex; }

/* Only above the breakpoint where the rail sits beside the record at all —
   stacked on a narrow screen, there is no "side" left to choose between. */
@media (min-width: 1051px) {
    html.sidebar-left .pd-layout { grid-template-columns: 16rem minmax(0, 1fr); }
    html.sidebar-left .pd-layout.is-rail-collapsed { grid-template-columns: 1fr; }
    html.sidebar-left .pd-main { order: 2; }
    html.sidebar-left .pd-side { order: 1; }
}

.pd-toc-list, .pd-toc-sublist { list-style: none; margin: 0; padding: 0; }
.pd-toc-list > li + li { margin-top: .25rem; }
.pd-toc-sublist { padding-left: .85rem; margin-top: .15rem; }
.pd-toc-link {
    display: block; padding: .3rem .5rem; border-radius: var(--r-control, 4px);
    font-size: .75rem; color: var(--ink); text-decoration: none; line-height: 1.4;
    border-left: 2px solid transparent;
}
.pd-toc-link:hover { background: var(--cream); color: var(--maroon); }
.pd-toc-link.is-active { color: var(--maroon); font-weight: 600; background: var(--cream); }
.pd-toc-sublink { font-size: .6875rem; color: var(--grey); }
.pd-toc-sublink.is-active { color: var(--maroon); }

.pd-cite-label {
    font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--grey); margin: 0 0 .5rem;
}
.pd-cite-text {
    margin: 0 0 .875rem; padding: .75rem .875rem; border-left: 2px solid var(--soft-maroon);
    background: var(--cream); font-size: .75rem; line-height: 1.65; color: var(--ink);
    font-style: normal;
    /* The retrieval URL is one unbroken token — without this it overflows the
       card instead of wrapping, and .pd-side's own overflow-y:auto then picks
       up an implicit overflow-x:auto (per spec, when one axis is scrollable
       and the other is "visible", the visible one becomes "auto" too), which
       is where that unwanted horizontal scrollbar came from. */
    overflow-wrap: anywhere;
    word-break: break-word;
}
.pd-cite-copy {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem; width: 100%;
    padding: .5rem .75rem; border: 1px solid var(--soft-maroon); border-radius: var(--r-control, 4px);
    background: var(--white); color: var(--maroon); font-size: .75rem; font-weight: 500; cursor: pointer;
    transition: background .15s, color .15s;
}
.pd-cite-copy:hover { background: var(--maroon); color: #fff; }
.pd-cite-copy .material-symbols-outlined { font-size: 16px; }
.pd-cite-copy.is-copied { background: var(--maroon); color: #fff; border-color: var(--maroon); }

@media (max-width: 1050px) {
    .pd-layout { grid-template-columns: 1fr; gap: 1.125rem; }
    .pd-side { position: static; max-height: none; overflow: visible; }
    /* Stacked full-width, there is no "side" left to send it to. */
    .pd-side-swap { display: none; }
}

/* A direct link (or a browser back/forward jump) lands on the fragment
   without running the table of contents' own script, so this keeps the
   target clear of the fixed header even then. Matched by id prefix rather
   than by these two classes alone — a table of contents can also point at a
   plain row that is neither, such as the reviewer names under "Where It
   Stands". */
.pd-card[id], .pd-section[id], [id^="pd-sec-"], [id^="pd-sub-"] { scroll-margin-top: 76px; }

/* Header: back out the way you came in, title, status. */
.pd-top { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
/* Sits out in the margin beside the record rather than boxed in next to the
   title — it is a way back, not one of the page's own controls. */
.pd-back {
    display: inline-flex; align-items: center; gap: .3rem; flex: 0 0 auto;
    color: var(--maroon); text-decoration: none; font-size: .8125rem;
    padding: .35rem .5rem .35rem .3rem; border-radius: var(--r-card, 8px);
    border: none; background: none;
}
.pd-back:hover { background: var(--cream); }
/* Only stepped out into the margin where there is margin to step into —
   below this the page needs its full width and the button stays in line. */
@media (min-width: 1100px) {
    .pd-back { margin-left: -3.5rem; }
}
.pd-heading { flex: 1 1 auto; min-width: 0; }
.pd-heading h1 {
    font-family: var(--font-head); font-size: 1.375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .25rem; line-height: 1.35;
}
.pd-authors { font-size: .8125rem; color: var(--ink); margin: 0 0 .25rem; }
.pd-meta { font-size: .75rem; color: var(--grey); }
.pd-meta .sep { margin: 0 .4rem; color: var(--grey); }
.pd-status {
    flex: 0 0 auto; text-align: right; font-size: .75rem; color: var(--grey); white-space: nowrap;
}
.pd-status .pd-badge {
    display: inline-block; margin-top: .2rem; padding: .25rem .6rem; border-radius: var(--r-badge, 2px);
    background: var(--cream); color: var(--maroon); font-size: .75rem; font-weight: 500;
}
.pd-status .pd-badge.is-warn { background: #fdeaea; color: var(--dark-maroon); }

/* What the reviewer said. A returned paper is opened to read this, so it leads
   the page instead of sitting at the foot of it. */
.pd-callout {
    display: flex; gap: .75rem; padding: 1rem 1.25rem; border-radius: var(--r-card, 8px);
    margin-bottom: 1.125rem; background: #fdeaea; border: 1px solid var(--soft-maroon);
}
.pd-callout.is-info { background: var(--cream); border-color: var(--border); }
.pd-callout > .material-symbols-outlined { color: var(--maroon); flex: 0 0 auto; }
.pd-callout h2 {
    font-family: var(--font-head); font-size: .9375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .35rem;
}
.pd-callout p { font-size: .8125rem; color: var(--ink); line-height: 1.7; margin: 0; }
.pd-callout .pd-said {
    margin-top: .5rem; padding-left: .75rem; border-left: 2px solid var(--soft-maroon);
    white-space: pre-wrap;
}
.pd-callout .pd-who { display: block; margin-top: .5rem; font-size: .6875rem; color: var(--grey); }
.pd-callout .btn-sm-maroon { margin-top: .75rem; }

.pd-card {
    background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px);
    padding: 1.375rem 1.5rem; margin-bottom: 1.125rem;
}
.pd-card > h2 {
    font-family: var(--font-head); font-size: .9375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 1rem;
    display: flex; align-items: center; gap: .4rem;
}
.pd-card > h2 .material-symbols-outlined { font-size: 18px; }

/* Step 1, as filed */
.pd-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1rem 1.25rem; }
.pd-fact-label {
    display: block; font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--grey); margin-bottom: .2rem;
}
.pd-fact-value { font-size: .8125rem; color: var(--ink); line-height: 1.5; }
.pd-fact-value.is-empty { color: var(--grey); font-style: italic; }

.pd-chips { display: flex; flex-wrap: wrap; gap: .375rem; }
.pd-chip {
    padding: .25rem .65rem; border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    font-size: .75rem; color: var(--ink); background: var(--white);
}

/* Step 2 — the written sections, shown as written */
.pd-section + .pd-section { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--border); }
.pd-section h3 {
    font-family: var(--font-head); font-size: .875rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .5rem;
}
/* Justified, exactly as the section boxes set it while it was being written —
   the record should read the way the author saw it. */
.pd-prose { font-size: .8125rem; color: var(--ink); line-height: 1.8; text-align: justify; }
.pd-prose td, .pd-prose th { text-align: left; }   /* cells keep their own alignment */
.pd-prose p { margin: 0 0 .75rem; }
.pd-prose p:last-child { margin-bottom: 0; }
.pd-prose ul, .pd-prose ol { margin: 0 0 .75rem; padding-left: 1.4rem; }
/* `table-layout: fixed` matches the editor, so the column widths the student
   dragged are reproduced here exactly rather than being re-guessed. */
.pd-prose table { border-collapse: collapse; margin: .5rem 0; width: 100%; table-layout: fixed; }
.pd-prose td, .pd-prose th { border: 1px solid currentColor; padding: .35rem .6rem; background: #fff; }
/* A picture pasted into a section. Centred on its own line, as it was while
   it was being written, and never wider than the column it sits in. */
.pd-prose img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: .75rem auto;
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
}
.pd-prose-scroll { overflow-x: auto; }

/* Step 3 — the files that went with it */
.pd-files { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: .75rem; }
.pd-file {
    display: flex; flex-direction: column; gap: .5rem; min-height: 7rem;
    padding: .875rem; border: 1px solid var(--border); border-radius: var(--r-card, 8px);
    text-decoration: none; background: var(--white); transition: border-color .15s, box-shadow .15s;
}
.pd-file:hover { border-color: var(--soft-maroon); box-shadow: 0 2px 10px rgba(51,0,0,.06); }
.pd-file-name { font-size: .75rem; color: var(--ink); line-height: 1.4; }
.pd-file:hover .pd-file-name { color: var(--maroon); }
.pd-file-ico { margin-top: auto; color: var(--maroon); }
.pd-file-ico .material-symbols-outlined { font-size: 30px; }

/* A file that never arrived is still shown, because the gap is the point. */
.pd-file.is-missing { border-style: dashed; background: #fdf7f7; cursor: default; }
.pd-file.is-missing:hover { border-color: var(--border); box-shadow: none; }
.pd-file.is-missing .pd-file-ico { color: var(--maroon); opacity: .55; }
.pd-file.is-optional { background: var(--white); }
.pd-file.is-optional .pd-file-ico { color: var(--grey); opacity: .5; }
.pd-file-state {
    font-size: .6875rem; font-weight: 500; color: var(--maroon);
    display: flex; align-items: center; gap: .25rem;
}
.pd-file.is-optional .pd-file-state { color: var(--grey); font-weight: 400; }
.pd-file-state .material-symbols-outlined { font-size: 14px; }

/* The checklist — the reason this page exists */
.pd-check-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1.25rem 2rem; }
.pd-check-head {
    font-size: .75rem; font-weight: 600; color: var(--maroon);
    padding-bottom: .5rem; margin-bottom: .5rem; border-bottom: 1px solid var(--border);
}
.pd-check-row {
    display: flex; align-items: center; gap: .5rem;
    padding: .3rem 0; font-size: .8125rem; color: var(--ink);
}
.pd-check-row .material-symbols-outlined { font-size: 18px; flex: 0 0 auto; }
.pd-check-row.is-yes .material-symbols-outlined { color: var(--maroon); }
.pd-check-row.is-no  { color: var(--grey); }
.pd-check-row.is-no .material-symbols-outlined { color: var(--border); }
/* Something the reviewer marked absent reads as a problem, not as a blank. */
.pd-check-row.is-gap { color: var(--dark-maroon); }
.pd-check-row.is-gap .material-symbols-outlined { color: var(--maroon); }
.pd-check-name { flex: 1 1 auto; }
.pd-check-state { font-size: .6875rem; color: var(--grey); }
.pd-check-row.is-yes .pd-check-state { color: var(--maroon); }
.pd-check-row.is-gap .pd-check-state { color: var(--maroon); font-weight: 500; }

/* A banner introduces what follows, so it always carries the gap below it
   itself — the inline margins dotted through the markup were what let it sit
   flush against the file cards and the checklist. */
.pd-note {
    display: flex; gap: .5rem; padding: .75rem .875rem; border-radius: var(--r-card, 8px);
    background: var(--cream); font-size: .75rem; color: var(--ink); line-height: 1.6;
    margin: 0 0 1.25rem;
}
.pd-note:last-child { margin-bottom: 0; }
.pd-note--after { margin: 1.25rem 0 0; }        /* a footnote, not a banner */
.pd-note .material-symbols-outlined { font-size: 18px; color: var(--maroon); flex: 0 0 auto; }

/* Room between the heading of a group and the rows under it. */
.pd-check-grid { margin-top: .25rem; }
.pd-files { margin-top: .25rem; }

.pd-people { font-size: .75rem; color: var(--ink); line-height: 1.9; }
.pd-people .who { color: var(--maroon); }

@media (max-width: 700px) {
    .pd-top { flex-wrap: wrap; }
    .pd-status { text-align: left; width: 100%; }
    .pd-card { padding: 1.125rem; }
}
</style>
