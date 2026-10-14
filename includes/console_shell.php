<?php
/**
 * Shared console shell - the dashboard look the student page established.
 *
 * Search row, tab strip, toolbar, the card list and its progress tracker. The
 * browse chrome underneath it (sidebar cards, filters, suggestions, quick
 * settings) comes from includes/browse_console.php, which this expects to have
 * been included already.
 *
 * Every role dashboard uses this, so a change here reaches all of them at once
 * and none of them can quietly drift from the others.
 *
 * Include inside <head>, after site_head.php and browse_console.php.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
body { background: var(--white); display: flex; flex-direction: column; min-height: 100vh; }
/* The breadcrumb strip lives in site_head.php now: every page needs it, not
   just the ones with a console layout. */
/* ===== Two-column layout, mirroring the public repository ===== */
.layout {
    display: grid;
    grid-template-columns: 1fr var(--sidebar-w, 226px);
    gap: var(--col-gap, 36px);
    align-items: start;
    padding-top: 1.5rem;
    padding-bottom: 3rem;
}
.main-col { position: relative; min-width: 0; }
/* The console sits on the page. It used to sit on a soft-cream panel with the
   white cards on top of it, which is one tint too many: the page already has
   a colour, and a block of a second one behind every list was doing nothing
   the cards and the rules below them do not do themselves. The padding stays,
   so everything inside keeps the inset it was laid out with. */
/* Named as a container so the toolbar inside it can be sized against the
   console rather than against the window: the sidebar takes a third of the
   page, which no viewport media query can see. */
.dash-shell {
    container-type: inline-size; container-name: desk;
    background: none; border-radius: var(--r-card, 8px); padding: .875rem;
}
/* ===== Search + upload row ===== */
.dash-top { display: flex; align-items: center; gap: .75rem; margin-bottom: .75rem; }
.dash-top .search-shell { flex: 1; min-width: 0; position: relative; }
.btn-upload {
    display: inline-flex; align-items: center; gap: .5rem;
    background: var(--maroon-surface); color: #fff; text-decoration: none;
    border: none; border-radius: var(--r-control, 4px);
    padding: .75rem 1.5rem; font-family: inherit; font-size: .875rem;
    white-space: nowrap; flex-shrink: 0;
    transition: background .2s;
}
.btn-upload:hover { background: var(--maroon-surface-hover); color: #fff; }
.btn-upload .material-symbols-outlined { font-size: 20px; }
/* ===== Tabs + toolbar row ===== */
/* One row, always. With five tabs and three icons on one side and the count,
   the page size and the pager on the other, the right-hand group used to drop
   to a line of its own, which read as a second toolbar nobody asked for.

   So the bar does not wrap. The tabs take whatever is left and scroll inside
   themselves if even that is not enough, rather than pushing anything down;
   the count is dropped first when the console is narrow (see the container
   query in review_console.php), because the list underneath already shows
   what is on screen and the pager says where in it you are. */
.dash-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: .75rem; flex-wrap: nowrap; margin-bottom: .75rem;
    /* Matches .browse-toolbar on the public repository */
    font-size: .6875rem; color: var(--ink);
}
.dash-tabs {
    display: flex; align-items: center; gap: .125rem; flex-wrap: nowrap;
    min-width: 0; overflow-x: auto;
    /* The scrollbar would sit under the tabs and take a line of its own. */
    scrollbar-width: none;
}
.dash-tabs::-webkit-scrollbar { display: none; }
.dash-tab {
    flex: 0 0 auto;
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .4rem .6rem; border-radius: var(--r-control, 4px);
    font-size: .8125rem; color: var(--grey); text-decoration: none;
    border: none; background: none; font-family: inherit; cursor: pointer;
    transition: background .2s, color .2s;
}
/* Cream, the same highlight the rest of the site uses. These were white for
   contrast against the cream panel that used to be under them; with that gone
   a white pill on a white page marks nothing at all. */
.dash-tab:hover { background: var(--cream); color: var(--maroon); }
.dash-tab.active { background: var(--cream); color: var(--maroon); }
.dash-shell .toolbar-btn:hover { background: var(--cream); color: var(--maroon); }
/* Quick Settings now sits on the left of the bar, so the panel opens to the
   right instead of hanging off the left edge. */
.dash-tabs .quick-settings-dropdown { left: 0; right: auto; }
.dash-shell .card-tool:hover { background: var(--cream); }
.dash-tab .count { font-size: .6875rem; color: var(--grey); }
/* ===== Paper cards =====
   Drawn the way the public repository draws its list: no box of its own, no
   fill, no gap between one and the next, and a hairline where they meet.
   What makes these a card rather than a row is what is inside them (the
   tracker, who is on it, the files) and not a frame around them.

   The hairline is drawn inside the padding, so it starts where the text
   starts, and the last one goes: a rule under the final item is a line under
   nothing. Same reasoning, same numbers, as .paper-item in
   browse_console.php. */
.paper-card {
    position: relative;
    background: none;
    border: 0;
    border-radius: var(--r-card, 8px);
    padding: 1rem 1.25rem;
    margin: 0;
    transition: background .2s ease;
}
.paper-card:hover { background: var(--cream); }
.paper-card::after {
    content: '';
    position: absolute;
    left: 1.25rem; right: 1.25rem; bottom: 0;
    border-bottom: 1px solid var(--border-soft);
}
.paper-card:last-child::after { display: none; }
/* The colour lives on the heading, not only on the link inside it: a draft's
   title is plain text, since the archive has nothing to link it to yet. */
.paper-card .paper-title { font-size: 1rem; font-weight: 500; line-height: 1.4; margin-bottom: .35rem; color: var(--ink); }
.paper-card .paper-title a { color: inherit; text-decoration: none; }
.paper-card .paper-title a:hover { color: var(--maroon); text-decoration: underline; }
.card-head { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 1rem; align-items: start; }
/* The toggle lives in the padding-right gutter reserved here, positioned on
   its own rather than sitting inline after the status text, so its width
   never enters into how far right "Status: ..." or "View Details" sit, and
   .card-people below (same gutter, no toggle of its own) lines up with them
   both at the same edge instead of the toggle shifting only the first line. */
.card-status {
    text-align: right; font-size: .6875rem; line-height: 1.7; white-space: nowrap;
    position: relative; padding-right: 1.625rem;
}
.card-status .status-value { color: var(--maroon); }
.card-status .paper-action { display: block; }

/* Folds everything under .card-head away (the tracker, who is on it, any
   files and the approve/return buttons) leaving the plain title/authors/
   status row the public repository's own list shows. Built by
   browse_console_js.php, which wraps that "everything else" into
   .paper-card-body the same way includes/card_collapse.php does. */
.paper-card-toggle {
    position: absolute; top: 0; right: 0;
    display: inline-flex; align-items: center; justify-content: center;
    padding: 0; border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon); cursor: pointer;
    transition: background .15s;
}
.paper-card-toggle:hover { background: var(--cream); }
.paper-card-toggle:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.paper-card-toggle .material-symbols-outlined { font-size: 18px; transition: transform .18s ease; }
/* Collapsed reads "›", the same convention card_collapse.php uses. */
.paper-card[data-collapsed="1"] .paper-card-toggle .material-symbols-outlined { transform: rotate(-90deg); }
.paper-card[data-collapsed="1"] .paper-card-body { display: none; }

/* ===== Rows =====
   The card is built for reading one paper; a list holding a hundred is
   scanned instead. This lays the same markup out as a line: title, author
   and meta on the left, status and whatever action the page offers on the
   right, and the parts that repeat on every card (the tracker, the people,
   any feedback) put away. Nothing is removed from the page, so the toggle
   is instant and the AJAX swaps need to know nothing about it.

   The tracker is the one real loss, so the status text stays: it is the
   same fact in four words instead of three dots and a rail.

   The head is display:contents, which promotes its own two children to the
   grid. The first is the title block: .paper-info on a review desk and a
   plain <div> on the student dashboard, so it is matched by position
   rather than by name. */
html[data-view="rows"] .paper-list .paper-card {
    display: grid;
    /* A column each, the same on every line, so the authors start where the
       authors above them start. Laid out as one flexible row instead, each
       line put its author wherever its own title happened to end and the
       list read as ragged text rather than as a table. */
    grid-template-columns: minmax(0, 1fr) minmax(0, 9rem) minmax(0, 10.5rem) auto auto;
    align-items: center;
    column-gap: .75rem;
    padding: .5rem .875rem;
}
/* The rule follows the row's own padding, which is tighter than a card's. */
html[data-view="rows"] .paper-list .paper-card::after { left: .875rem; right: .875rem; }
html[data-view="rows"] .paper-list .card-head { display: contents; }
/* A row sets its own padding, which would otherwise outrank the density
   rules and leave that setting doing nothing at all here. */
html[data-view="rows"][data-density="compact"] .paper-list .paper-card {
    padding: .3rem .875rem;
}
html[data-view="rows"][data-density="comfortable"] .paper-list .paper-card {
    padding: .75rem .875rem;
}
/* Both wrappers dissolve, so the title, the authors and the meta are the
   row's own cells rather than three things inside one of them. Each is then
   placed by hand: a paper filed without authors leaves that element out
   altogether, and in source order alone its meta would slide left into the
   empty column and sit under the authors of every other line. */
html[data-view="rows"] .paper-list .card-head > :not(.card-status) { display: contents; }
html[data-view="rows"] .paper-list .paper-card .paper-title   { grid-column: 1; }
html[data-view="rows"] .paper-list .paper-card .paper-authors { grid-column: 2; }
html[data-view="rows"] .paper-list .paper-card .paper-meta    { grid-column: 3; }
html[data-view="rows"] .paper-list .card-status               { grid-column: 4; }
html[data-view="rows"] .paper-list .paper-card-body           { grid-column: 5; }
/* The title takes the column that stretches: it is what the row is read by,
   and it is the one part that reads perfectly well cut short. */
html[data-view="rows"] .paper-list .paper-card .paper-title {
    font-size: .875rem; margin: 0; min-width: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
html[data-view="rows"] .paper-list .paper-card .paper-authors {
    margin: 0; min-width: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* The meta is a flex row of its own, and it wraps by default: left to do
   that inside a narrow column it stacked four deep and the row stopped
   being a row. */
html[data-view="rows"] .paper-list .paper-card .paper-meta {
    min-width: 0; flex-wrap: nowrap;
    white-space: nowrap; overflow: hidden;
    /* The meta is a row of separate spans, so text-overflow has nothing to
       put an ellipsis on: it would cut a word in half at the edge and read as
       a rendering fault. Faded out instead. */
    -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 1.75rem), transparent);
            mask-image: linear-gradient(to right, #000 calc(100% - 1.75rem), transparent);
}
html[data-view="rows"] .paper-list .paper-card .paper-meta .sep { flex: 0 0 auto; }
html[data-view="rows"] .paper-list .card-status {
    display: flex; align-items: center; gap: .75rem;
    white-space: nowrap; text-align: left; padding-right: 0;
}
/* Everything under the head sits in one wrapper, so the row keeps the part
   that is a decision and puts the rest away.

   Named twice on purpose. That wrapper is built by script, and a page of
   results fetched by AJAX is laid out before the script gets to it: for those
   few frames the tracker is a direct child of the card, and a row would open
   as a card and then collapse. */
html[data-view="rows"] .paper-list .paper-card-body { min-width: 0; }
html[data-view="rows"] .paper-list .paper-card-body > :not(.rc-actions),
html[data-view="rows"] .paper-list .paper-card > .card-track,
html[data-view="rows"] .paper-list .paper-card > .card-feedback,
html[data-view="rows"] .paper-list .paper-card > .card-actions,
/* The fold chevron folds what a row has already folded. */
html[data-view="rows"] .paper-list .paper-card-toggle { display: none; }
/* Below this the three columns have nowhere to go, so a row is a card
   again rather than a line squeezed to nothing. */
@media (max-width: 820px) {
    html[data-view="rows"] .paper-list .paper-card { display: block; padding: .875rem 1rem; }
    html[data-view="rows"] .paper-list .card-head { display: grid; }
    html[data-view="rows"] .paper-list .card-head > :not(.card-status) { display: block; }
    html[data-view="rows"] .paper-list .paper-card .paper-title { white-space: normal; }
    html[data-view="rows"] .paper-list .paper-card .paper-meta { max-width: none; }
}
/* ===== Progress tracker ===== */
.card-track { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 1.5rem; align-items: center; margin-top: 1rem; }
.track { display: flex; align-items: flex-start; position: relative; --steps: 3; --dot: 26px; }
.track-step { display: flex; flex-direction: column; align-items: center; gap: .35rem; flex: 1; z-index: 2; min-width: 0; }
.track-dot {
    width: 26px; height: 26px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--white); border: 2px solid var(--border);
    color: var(--border); flex-shrink: 0;
}
.track-dot .material-symbols-outlined { font-size: 18px; --mi-fill: 1; }
.track-step.done .track-dot { background: var(--maroon-surface); border-color: var(--maroon-surface); color: #fff; }
.track-step.current .track-dot { border-color: var(--maroon); color: var(--maroon); }
.track-label { font-size: .625rem; color: var(--grey); text-align: center; line-height: 1.25; }
.track-step.done .track-label, .track-step.current .track-label { color: var(--maroon); }
/* Connector runs edge-to-edge between the first and last dots: never past
   them. Each step is flex:1, so the outer dot centres sit at 50%/steps; add
   the dot's radius to land exactly on their inner edges. */
.track-line {
    position: absolute;
    top: calc(var(--dot) / 2 - 1px);
    left: calc(50% / var(--steps) + var(--dot) / 2);
    right: calc(50% / var(--steps) + var(--dot) / 2);
    height: 2px;
    background: var(--border);
    z-index: 1;
}
.track-line-fill { height: 100%; background: var(--maroon); transition: width .4s ease; }
/* ---- Card actions, small buttons and the confirmation dialog ----
   Shared vocabulary: every console uses the same two button sizes and the
   same dialog, so a destructive action looks the same wherever it is. */
/* Same reserved gutter width as .card-status, even though there's no toggle
   here to put in it: it's what keeps this text's right edge lined up with
   "Status: ..." above instead of running past it into the toggle's own
   column. */
.card-people { font-size: .6875rem; color: var(--ink); line-height: 1.8; text-align: right; white-space: nowrap; padding-right: 1.625rem; }
.card-people .who { color: var(--maroon); }
.card-feedback {
    margin-top: .875rem; padding: .625rem .875rem;
    background: #fdeaea; border-radius: var(--r-card, 8px);
    font-size: .75rem; color: var(--dark-maroon); line-height: 1.6;
}
.card-actions { margin-top: .875rem; display: flex; gap: .5rem; flex-wrap: wrap; }
/* .btn-sm-maroon and .btn-sm-outline now live in site_head.php. */
/* Confirmation dialog, matching the one used on the upload page. */
.papel-dialog-backdrop {
    position: fixed; inset: 0; z-index: 20000;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem; background: rgba(51, 0, 0, .45);
    opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.papel-dialog-backdrop.open { opacity: 1; pointer-events: auto; }
.papel-dialog {
    width: 100%; max-width: 26rem; background: var(--white);
    border-radius: var(--r-card, 8px); box-shadow: 0 18px 48px rgba(51, 0, 0, .28); overflow: hidden;
}
.papel-dialog-head {
    display: flex; align-items: center; gap: .5rem;
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--maroon);
}
.papel-dialog-head .material-symbols-outlined {
    color: var(--maroon); font-size: 20px; position: relative; top: -2px;
}
.papel-dialog-head h2 {
    font-family: var(--font-head); font-size: 1rem; font-weight: 500;
    color: var(--maroon); position: relative; top: 1px; margin: 0;
}
.papel-dialog-body { padding: 1.25rem; font-size: .875rem; color: var(--ink); line-height: 1.6; }
.papel-dialog-body strong { font-weight: 500; color: var(--maroon); }
.papel-dialog-foot { display: flex; justify-content: flex-end; gap: .5rem; padding: 0 1.25rem 1.25rem; }
.papel-dialog-foot .btn-sm-outline,
.papel-dialog-foot .btn-sm-maroon { padding: .5rem 1.1rem; font-size: .8125rem; }
@media (max-width: 900px) {
    .card-people { white-space: normal; }
}

@media (max-width: 900px) {
    .layout { grid-template-columns: 1fr; gap: 2rem; }
    .card-track { grid-template-columns: 1fr; gap: .75rem; }
}
@media (max-width: 600px) {
    .dash-top { flex-direction: column; align-items: stretch; }
    .btn-upload { justify-content: center; }
    .card-head { grid-template-columns: 1fr; }
    .card-status { text-align: left; }
}
</style>
