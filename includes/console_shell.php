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
/* Three children now (tabs, tools, count-and-pager), so this cannot be
   space-between: with an odd one in the middle that would centre the three
   buttons in the gap instead of keeping them against the tabs. The last child
   is pushed to the far end by its own auto margin, which is what
   space-between did for it when there were two. The bar's own gap is 0 for
   the same reason: the spacing is stated between the pieces that want it, so
   the buttons keep the .125rem they had while they were inside .dash-tabs. */
.dash-bar {
    display: flex; align-items: center; justify-content: flex-start;
    gap: 0; flex-wrap: nowrap; margin-bottom: .75rem;
    /* Matches .browse-toolbar on the public repository */
    font-size: .6875rem; color: var(--ink);
}
.dash-tools {
    display: flex; align-items: center; gap: .125rem;
    flex: 0 0 auto; margin-left: .125rem;
}
/* Scoped to this bar: .toolbar-right is shared with the public repository's
   own toolbar (includes/browse_console.php), which lays itself out. */
.dash-bar > .toolbar-right { margin-left: auto; padding-left: .75rem; }
/* ---- The row of tabs, and the chevrons that say it slides ----
   The tabs are one line that is reached by sliding it sideways when they do
   not all fit, and a line that slides with nothing to say so reads as a line
   that ends where the screen does: on the student dashboard at 390px, Needs
   Revision was cut off mid-word and Archived was not there at all.

   A chevron at each end, each one shown only while there is something that
   way: none at all where the tabs fit, one at the end being slid away from,
   and both in the middle. .can-left and .can-right are written by
   includes/browse_console_js.php, which measures the scroller.

   They are drawn on the wrapper and not on .dash-tabs itself, because
   .dash-tabs is the scrolling box: a background or a pseudo-element on a
   scrolling box is painted on its contents and slides away with them, so the
   chevron would have sat over a tab somewhere in the middle of the line
   instead of at the edge of the screen. The wrapper does not scroll, so its
   edges are the ones the reader sees. */
.dash-tabs-glide { position: relative; display: flex; min-width: 0; }
.dash-tabs {
    display: flex; align-items: center; gap: .125rem; flex-wrap: nowrap;
    min-width: 0; width: 100%; overflow-x: auto;
    /* The scrollbar would sit under the tabs and take a line of its own. */
    scrollbar-width: none;
}
.dash-tabs::-webkit-scrollbar { display: none; }
.dash-tabs-glide::before,
.dash-tabs-glide::after {
    /* Material Symbols by codepoint: chevron_left and chevron_right. The
       ligature names the markup uses need text content to be read, which a
       pseudo-element's content is not reliably given. */
    font-family: 'Material Symbols Outlined';
    font-size: 20px;
    line-height: 1;
    /* Quiet, but not so quiet it is missed: it is the only thing saying the
       line goes on. Nothing happens when it is pressed (pointer-events below),
       so it must not sit up like a button either; the tab under it stays
       pressable. */
    color: color-mix(in srgb, var(--maroon) 75%, transparent);
    position: absolute;
    top: 0; bottom: 0;
    display: flex;
    align-items: center;
    /* 4rem, by way of 2, 3 and 3.5. The band has to be a good deal longer
       than the chevron is wide, or a tab's last letters stand clear of it and
       poke out beside the arrow still legible, which is the one thing it is
       there to stop. */
    width: 4rem;
    /* No blur on this one: it carries the arrow and the tint, and the
       blurring is done by the layers under it (.glide-fade, below). */
    pointer-events: none;
    opacity: 0;
    transition: opacity .18s ease;
    /* Above the blur layers, which are positioned with z-index auto. */
    z-index: 1;
}
/* The tint, over the blur, as a soft oval rather than a band: it fades out
   before it reaches the top, the bottom and the outer edge as well as the
   inner one, so there is no side of it drawn as a line. As a band it stopped
   square at three of its four sides, and even at 5% the pale rectangle was
   the one hard shape left at the end of the row. Centred at 65% of the band,
   so the chevron, which sits at the outer end, is on the thick of it.

   Maroon at 5% *of itself*, with nothing behind it. A tint mixed into the
   page's colour is opaque and hides the blurred row under it altogether,
   which is a frosted strip rather than glass; and at 5% the tint is barely a
   colour at all, so what reads as the band is the blur. It also needs no
   --white to mix with, so it holds in the dark themes without being told
   anything about them. */
.dash-tabs-glide::before {
    content: '\e5cb';
    left: 0;
    justify-content: flex-start;
    background: radial-gradient(ellipse 60% 75% at 35% 50%,
        color-mix(in srgb, var(--maroon) 5%, transparent) 35%, transparent);
}
.dash-tabs-glide::after {
    content: '\e5cc';
    right: 0;
    justify-content: flex-end;
    background: radial-gradient(ellipse 60% 75% at 65% 50%,
        color-mix(in srgb, var(--maroon) 5%, transparent) 35%, transparent);
}
.dash-tabs-glide.can-left::before,
.dash-tabs-glide.can-right::after { opacity: 1; }

/* ---- The blur, in layers ----
   Three layers to a side, each reaching less far into the row than the one
   under it and each blurring further: 1.5px across the whole band, 4px over
   its outer 70%, 8px over its outer 40%. So the row goes from sharp to soft
   to gone in steps too small to see, and there is no one line where the blur
   starts.

   One blurred band was tried first and had to choose between two faults. Cut
   off square, its inner edge was a line down the middle of a word, half of it
   sharp and half of it not. Faded out with a mask, it was worse: a mask
   blends away the whole element, blurred backdrop and all, and what comes up
   through the thin end is the page as it was, in focus, so the letters there
   were only pale and still read. Here the thin end of each layer shows the
   layer beneath it, which is already blurred; only the first layer's thin
   end shows the page itself, and that layer blurs by 1.5px.

   **Siblings, never nested.** The layers were first the one element and its
   two pseudo-elements, and the two inner ones did nothing at all: measured,
   the row was exactly as sharp with them switched off. An element with a
   backdrop-filter (or a mask) is the backdrop root of everything inside it,
   and what a child then blurs is what that element itself has painted, which
   is nothing; the parent's own blurred backdrop is not part of it. A later
   sibling's backdrop does include the siblings painted before it, so as
   three spans side by side the steps add up.

   The spans are made by script (includes/browse_console_js.php), being
   nothing but decoration, and are shown and hidden with the chevrons. Each
   is at most 64px by a row's height, so six of them cost little even on a
   phone. Checked by measuring how sharp each part of the band is with each
   layer on and off, which is the only way to tell a blur that works from one
   that does not; by eye at 1x they look alike. */
.glide-fade {
    position: absolute;
    top: 0; bottom: 0;
    pointer-events: none;
    opacity: 0;
    transition: opacity .18s ease;
}
.glide-fade.is-1 {
    width: 4rem;
    -webkit-backdrop-filter: blur(1.5px);
            backdrop-filter: blur(1.5px);
}
.glide-fade.is-2 {
    width: 2.8rem;
    -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
}
.glide-fade.is-3 {
    width: 1.6rem;
    -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
}
/* Each layer fades inward, and also at its top and its bottom: the two
   masks are intersected, so a layer is at full strength only where both say
   so. Without the second, a blur of 8px spreads a tab's letters up and down
   into a smear that the layer's own top and bottom then cut off square, and
   the band showed as a box with hard lids. Above and below the letters there
   is only the page, so letting the layer thin out there shows nothing sharp
   through it. */
.glide-fade-l {
    left: 0;
    -webkit-mask-image: linear-gradient(to right, #000 50%, transparent),
                        linear-gradient(to bottom, transparent, #000 30%, #000 70%, transparent);
    -webkit-mask-composite: source-in;
            mask-image: linear-gradient(to right, #000 50%, transparent),
                        linear-gradient(to bottom, transparent, #000 30%, #000 70%, transparent);
            mask-composite: intersect;
}
.glide-fade-r {
    right: 0;
    -webkit-mask-image: linear-gradient(to left, #000 50%, transparent),
                        linear-gradient(to bottom, transparent, #000 30%, #000 70%, transparent);
    -webkit-mask-composite: source-in;
            mask-image: linear-gradient(to left, #000 50%, transparent),
                        linear-gradient(to bottom, transparent, #000 30%, #000 70%, transparent);
            mask-composite: intersect;
}

/* ---- The tabs themselves fade out at an end that goes on ----
   The last thing with an edge was the row: the tabs are clipped square where
   the row ends, so blurred letters stopped in a straight line at the outer
   side of the band, under the chevron. A mask on the scroller fades them out
   into that end instead, so they blur and thin away together and there is no
   edge left to see.

   A mask on a scrolling box belongs to the box and not to what is scrolled in
   it, so it stays at the ends while the tabs slide under it; it is the one
   thing drawn on .dash-tabs itself that does. 3rem of the band's 4, so the
   tabs are faint but still there under the strongest blur, which is what
   makes the end read as glass rather than as an empty patch. Only at an end
   that can be slid to: a row that fits is never faded. */
.dash-tabs-glide.can-left .dash-tabs {
    -webkit-mask-image: linear-gradient(to right, transparent, #000 3rem);
            mask-image: linear-gradient(to right, transparent, #000 3rem);
}
.dash-tabs-glide.can-right .dash-tabs {
    -webkit-mask-image: linear-gradient(to left, transparent, #000 3rem);
            mask-image: linear-gradient(to left, transparent, #000 3rem);
}
.dash-tabs-glide.can-left.can-right .dash-tabs {
    -webkit-mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
            mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
}
.dash-tabs-glide.can-left .glide-fade-l,
.dash-tabs-glide.can-right .glide-fade-r { opacity: 1; }
@media (prefers-reduced-motion: reduce) {
    .dash-tabs-glide::before,
    .dash-tabs-glide::after,
    .glide-fade { transition: none; }
}
/* Quick Settings now hangs out of .dash-tools, which scrolls in no direction and
   so clips in none either. It used to be the last child of .dash-tabs, and
   overflow-x on its own clips the other axis too (it computes to auto, never
   visible), so the panel was cut to the row's own height: it opened, and
   nothing of it could be seen or pressed, on every dashboard that has this bar.
   Taking the buttons out of the scrolling box is what retired that rule; do not
   put them back inside it. */
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
.dash-tools .quick-settings-dropdown { left: 0; right: auto; }
/* Except where the bar has wrapped and the tools have gone to the right-hand
   end of their own row (below), which puts the gear against the edge of the
   screen and a panel opening rightwards straight off it. The script measures
   and places it anyway; this is so the first paint is already on the right
   side, and so a browser running no script is too. The width is the same one
   the rule above is written for. */
@media (max-width: 600px) {
    .dash-tools .quick-settings-dropdown { left: auto; right: 0; }
}
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
/* Which sections the author chose to submit, when they left some out. A small
   outlined chip beside the type and date, so a reviewer sees it before they open
   the paper and does not mistake a deliberate gap for a missing piece. In the
   rows view the meta fades out at its right edge, so the chip goes first, where
   it cannot be cut off. It is defined here because this file is the one every
   desk and the student dashboard already share. */
.paper-meta .scope-chip {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .0625rem .5rem;
    border: 1px solid var(--soft-maroon);
    border-radius: 999px;
    background: var(--cream);
    color: var(--maroon);
    font-size: .6875rem; font-weight: 500; line-height: 1.5;
    white-space: nowrap;
    cursor: help;
}
.paper-meta .scope-chip .material-symbols-outlined { font-size: 13px; }
html[data-view="rows"] .paper-list .paper-card .paper-meta .scope-chip { order: -1; flex: 0 0 auto; }
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
   again rather than a line squeezed to nothing.

   **Everything the row did to stay on one line has to be undone with it**,
   and this block undid three things out of eight. What was left behind, on a
   390px phone: the status still had its nowrap and, worse, still had the
   `grid-column: 4` it was given for the row's five-column grid, which in the
   head's own two-column grid makes a fourth column out of nothing and pushed
   it 164px past the right-hand edge of the list, where it showed as "St" and
   then nothing; the authors still had their ellipsis; and the meta still had
   the mask that fades out its last 1.75rem, so the date line ended at
   "Unpublished Pape". The public repository's rows (includes/browse_console.php)
   met this exactly and are the list this was written against.

   There is no line to protect at this width, so the text wraps like any
   other text, and the cells go back to being the card's own two. */
@media (max-width: 820px) {
    html[data-view="rows"] .paper-list .paper-card { display: block; padding: .875rem 1rem; }
    html[data-view="rows"] .paper-list .card-head { display: grid; }
    html[data-view="rows"] .paper-list .card-head > :not(.card-status) { display: block; }

    /* A column written for a grid this is no longer in. The head is the
       card's own `minmax(0,1fr) auto` again, so anything still asking for
       column 3, 4 or 5 invents the columns to sit in. */
    html[data-view="rows"] .paper-list .paper-card .paper-title,
    html[data-view="rows"] .paper-list .paper-card .paper-authors,
    html[data-view="rows"] .paper-list .paper-card .paper-meta,
    html[data-view="rows"] .paper-list .card-status,
    html[data-view="rows"] .paper-list .paper-card-body { grid-column: auto; }

    html[data-view="rows"] .paper-list .paper-card .paper-title,
    html[data-view="rows"] .paper-list .paper-card .paper-authors {
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
    }
    html[data-view="rows"] .paper-list .paper-card .paper-meta {
        max-width: none;
        flex-wrap: wrap;
        white-space: normal;
        overflow: visible;
        -webkit-mask-image: none;
                mask-image: none;
    }
    /* Back in the head's second column, where the card puts it: right-aligned,
       wrapping, and keeping the gutter the fold chevron sits in. */
    html[data-view="rows"] .paper-list .card-status {
        white-space: normal;
        text-align: right;
        padding-right: 1.625rem;
    }
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
    /* column-reverse, so Upload Paper stands above the search box. On a wide
       screen the two share a row and the eye takes the button at the end of it;
       stacked, the search box was first and the one thing a student comes here
       to do was below the fold of the card. The markup keeps the search first,
       so the keyboard still reaches it before the button: with two controls,
       both plainly labelled, that is a smaller price than burying the action. */
    .dash-top { flex-direction: column-reverse; align-items: stretch; }
    .btn-upload { justify-content: center; }
    .card-head { grid-template-columns: 1fr; }
    .card-status { text-align: left; }

    /* ---- The tab bar: tabs on one line, controls under them ----
       The tabs keep a single line and are reached by sliding that line
       sideways; under it the three buttons sit at the left and the count and
       pager at the right.

       What this replaced: the buttons used to be the last children of
       .dash-tabs, so they rode the same scrolling line as the tabs. Measured
       on the student dashboard at 390px the tabs alone overran the row, which
       left Refresh, Help and Quick Settings parked past its right-hand end
       with nothing on screen to say they existed. They are their own box in
       the markup now (.dash-tools, in student_dashboard.php and
       review_console.php), which is what lets the line scroll without taking
       them with it. */
    .dash-bar { flex-wrap: wrap; row-gap: .375rem; }
    /* The whole first row. The wrapper is the flex item now, the scrolling
       box being inside it (see the chevrons above); the row it takes is the
       only thing here that scrolls. */
    .dash-tabs-glide { flex: 1 1 100%; }
    .dash-tabs { flex-wrap: nowrap; overflow-x: auto; }
    /* Second row, in the Public Repository's order: the count and the pager at
       the left, the tools at the right. The DOM has the tools first, because on
       a wide screen they belong against the tabs, so the two swap by `order`
       here rather than by being moved. Both are reached by the keyboard in DOM
       order either way, and on this row that is the three buttons before the
       pager, which is the order the wide layout reads in too. */
    .dash-bar > .toolbar-right { order: 1; margin-left: 0; padding-left: 0; }
    .dash-tools { order: 2; margin-left: auto; }
}
</style>
