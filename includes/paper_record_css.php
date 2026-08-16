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

/* Header: back out the way you came in, title, status. */
.pd-top { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
/* Sits out in the margin beside the record rather than boxed in next to the
   title — it is a way back, not one of the page's own controls. */
.pd-back {
    display: inline-flex; align-items: center; gap: .3rem; flex: 0 0 auto;
    color: var(--maroon); text-decoration: none; font-size: .8125rem;
    padding: .35rem .5rem .35rem .3rem; border-radius: 8px;
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
.pd-meta .sep { margin: 0 .4rem; color: var(--border); }
.pd-status {
    flex: 0 0 auto; text-align: right; font-size: .75rem; color: var(--grey); white-space: nowrap;
}
.pd-status .pd-badge {
    display: inline-block; margin-top: .2rem; padding: .25rem .6rem; border-radius: 999px;
    background: var(--cream); color: var(--maroon); font-size: .75rem; font-weight: 500;
}
.pd-status .pd-badge.is-warn { background: #fdeaea; color: var(--dark-maroon); }

/* What the reviewer said. A returned paper is opened to read this, so it leads
   the page instead of sitting at the foot of it. */
.pd-callout {
    display: flex; gap: .75rem; padding: 1rem 1.25rem; border-radius: 12px;
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
    background: var(--white); border: 1px solid var(--border); border-radius: 12px;
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
    padding: .25rem .65rem; border: 1px solid var(--border); border-radius: 999px;
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
.pd-prose-scroll { overflow-x: auto; }

/* Step 3 — the files that went with it */
.pd-files { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: .75rem; }
.pd-file {
    display: flex; flex-direction: column; gap: .5rem; min-height: 7rem;
    padding: .875rem; border: 1px solid var(--border); border-radius: 10px;
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
    display: flex; gap: .5rem; padding: .75rem .875rem; border-radius: 8px;
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
