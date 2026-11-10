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
   public paper view builds this markup: everything else keeps the plain
   single column above. */
.pd-layout { display: grid; grid-template-columns: minmax(0, 1fr) 16rem; gap: 1.75rem; align-items: start; }
.pd-main { min-width: 0; }
/* The column ends where its last card ends. The gap each card keeps beneath it
   is for the next one; left on the final card it is space below the last
   border, which the rail beside it then matches and overhangs by. */
.pd-main > :last-child { margin-bottom: 0; }
.pd-side {
    position: sticky; top: 76px; display: flex; flex-direction: column; gap: 1.125rem;
    /* Two ceilings: the window, and the record beside it. Without the second,
       a rail holding a long contents list and the citation card outgrew a
       record whose cards were folded, and hung below the last one, so the page
       appeared to end twice, once for each column. It is capped instead and
       scrolls inside itself, which it was already set up to do.

       The record's height arrives as --pd-main-h from the script at the foot of
       this file: no selector can ask a sibling how tall it is. The fallback is
       the window on its own, which is what this was before. */
    max-height: min(calc(100vh - 96px), var(--pd-main-h, calc(100vh - 96px)));
    overflow-y: auto; overflow-x: hidden;
}
/* Both cards keep the height they ask for. The rail is what scrolls when they
   do not fit, not the contents list inside it: squeezing that list into a box
   of its own put a second scrollbar inside the first and left the contents
   reading as a cramped window onto a page that has plenty of room. */
.pd-side > .pd-card { flex: 0 0 auto; }
/* Collapsed by the toggle beneath the status badge in .pd-top: the rail
   leaves the grid entirely rather than shrinking to nothing, so .pd-main
   retakes the width instead of leaving an empty column behind. */
.pd-layout.is-rail-collapsed { grid-template-columns: 1fr; }
.pd-layout.is-rail-collapsed .pd-side { display: none; }

/* Under "Status", right-aligned like the badge above it. Icon-only, the same
   chrome-free treatment as the card-collapse chevron it sits beside in spirit:
   a border here just duplicated the badge's own box outline above it. */
.pd-rail-toggle {
    display: inline-flex; align-items: center; justify-content: flex-end;
    margin-top: .35rem; padding: .2rem .35rem;
    /* 26px, so a control made of 12px text still answers to a thumb: 24x24
       CSS px is the floor a target may be drawn at (WCAG 2.5.8). */
    min-height: 1.625rem;
    border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon); cursor: pointer;
    /* A button inherits neither from the page, and this one is a line of
       prose in a corner of small print, not a piece of chrome. */
    font-family: inherit; font-size: .75rem; line-height: 1.3; text-align: right;
    transition: background .15s;
}
.pd-rail-toggle:hover { background: var(--cream); text-decoration: underline; }
/* Words and no glyph. An icon in this corner was a rectangle with a bar in
   it, which says "a panel" to somebody who already knows there is one and
   nothing at all to anybody else; and the rail it opens is the one thing on
   the page a reader cannot see while it is away, so there was nothing on
   screen to read the icon against. Beside the words it was redundant, which
   is why it is gone too: owner's call, and the line is clearer for it. */
.pd-rail-toggle-text { white-space: nowrap; }
/* Drawn only while the rail is away, this being the way back to it; the
   control that puts it away is inside the rail itself, on the contents'
   heading. The attribute is written by the script at the foot of this file,
   and the rule is here because .pd-rail-toggle sets a display of its own and
   so would otherwise outrank the one [hidden] carries. The markup ships with
   it set: a record always opens with its rail showing, so the button drawn
   first and taken away a moment later would be a flicker and nothing else. */
.pd-rail-toggle[hidden] { display: none; }

/* Putting the rail away, from inside it: on the contents' own heading, with
   the chevron that folds the card and the arrows that send the rail across.
   Drawn as those two are, so the three read as one set of controls rather
   than one borrowed from somewhere else. */
.pd-rail-hide {
    display: inline-flex; align-items: center; justify-content: center;
    padding: .15rem; border: none; border-radius: var(--r-control, 4px);
    background: none; color: var(--maroon); cursor: pointer; transition: background .15s;
}
.pd-rail-hide:hover { background: var(--cream); }
.pd-rail-hide .material-symbols-outlined { font-size: 18px; }

.pd-side .pd-card { padding: 1.125rem 1.25rem; margin-bottom: 0; }
.pd-side .pd-card > h2 { font-size: .8125rem; margin-bottom: .75rem; }
.pd-side .pd-card > h2 .material-symbols-outlined { font-size: 16px; }
/* Three controls on one heading, in a column 256px wide: put the rail away,
   send it to the other side, fold the card. At the sizes the rest of the
   record uses they came to 80px of the 216 the card has inside its padding,
   which left "Table of Contents" 114px for a word that wants 112, and the
   title dropped onto a second line as soon as the third was added. Taken in
   by a couple of pixels each, which buys it 15 and settles the question.
   Measured at 1280px against the heading before the button was added, not
   judged by eye. */
.pd-side .pd-card > h2 { gap: .25rem; }
.pd-side .pd-card > h2 > button { padding: .1rem; }
.pd-side .pd-card > h2 > button .material-symbols-outlined { font-size: 17px; }
/* The title grows to fill the row so the swap icon and the collapse chevron
   card_collapse.php appends land together at the edge, instead of each
   claiming its own share of an auto margin and drifting apart. */
.pd-card-title { flex: 1 1 auto; min-width: 0; }

/* Sends the whole rail to the other side of the record: the same control,
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

/* Only above the breakpoint where the rail sits beside the record at all:
   stacked on a narrow screen, there is no "side" left to choose between. */
@media (min-width: 1051px) {
    /* The record on the left, the rail on the right, which is the opposite of
       the order they are written in: the rail comes first in the markup so
       that a stacked layout puts it above the paper rather than below it (see
       archive/view_paper.php). Grid places items in order-modified document
       order, so saying which is which here is all it takes to put them back.
       The rules below, for the rail sent to the other side, are the same two
       the other way about and outrank these by a class. */
    .pd-main { order: 1; }
    .pd-side { order: 2; }
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
    /* The retrieval URL is one unbroken token, without this it overflows the
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
.pd-cite-copy:hover { background: var(--maroon-surface); color: #fff; }
.pd-cite-copy .material-symbols-outlined { font-size: 16px; }
.pd-cite-copy.is-copied { background: var(--maroon-surface); color: #fff; border-color: var(--maroon-surface); }

@media (max-width: 1050px) {
    .pd-layout { grid-template-columns: 1fr; gap: 1.125rem; }

    /* The sticky contents cards meet the navbar exactly. The navbar's frosted
       fill otherwise lets a thin line of manuscript text show through above
       them while scrolling, which reads as a gap between the two bars. */
    body:has(.pd-wrap.has-rail) .site-header.scrolled {
        background: var(--white);
        -webkit-backdrop-filter: none;
        backdrop-filter: none;
    }

    /* The contents and citation cards lead the paper in the narrow layout
       and remain within reach below the navbar as the record scrolls. */
    .pd-side {
        position: sticky;
        top: var(--pdf-dock-top, 57px);
        z-index: 3;
        gap: .5rem;
        /* The cards share one ground across the width of the column. */
        margin: 0 -.75rem;
        padding: .5rem .75rem;
        background: var(--white);
        box-shadow: 0 .5rem .75rem -.6rem rgba(0, 0, 0, .28);
        /* The column's height cap and overflow belong only to the wide rail.
           Here the opened contents list can extend beyond its card. */
        max-height: none;
        overflow: visible;
    }

    /* A card in the contents group is the same height open or shut, and what
       it holds is drawn over the paper rather than in it.

       Folded in the flow, the contents of a long paper take 454px down to
       142, and everything below them moves up by the 312 between: one notch
       of a scroll wheel took the page off the top, the card folded itself,
       and the first card of the record jumped 353px for a 40px scroll, which
       reads as the page having reloaded under the reader's thumb. Scroll
       anchoring cannot undo it either, since near the top there is no room
       above to give back.

       So the list hangs off the bar as a panel: the bar's own height never
       changes, there is nothing for a fold to move, and the panel behaves
       the way a panel should, covering the page for as long as it is open.
       The heading's bottom margin goes for the same reason, it being the
       other thing that is there only while the card is open. */
    .pd-side .pd-card { position: relative; }
    .pd-side .pd-card > h2 { margin-bottom: 0; }
    .pd-side .card-collapse-body {
        position: absolute;
        /* The card's border is outside its padding box, which is what 100%
           measures to, so the panel starts a pixel lower and its own sides
           are pulled out to stand in the same line as the card's. */
        top: calc(100% + 1px);
        left: -1px; right: -1px;
        z-index: 1;
        padding: .875rem 1.25rem 1.125rem;
        background: var(--white);
        border: 1px solid var(--border);
        border-top: 0;
        border-radius: 0 0 var(--r-card, 8px) var(--r-card, 8px);
        box-shadow: 0 .6rem 1rem -.5rem rgba(0, 0, 0, .3);
        /* A long contents list scrolls inside its panel, leaving some of the
           page visible below it on a phone. */
        max-height: calc(100vh - var(--pdf-dock-top, 57px) - 10rem);
        overflow-y: auto; overflow-x: hidden;
    }
    /* A panel is open over the paper, so the bar below it would be half
       covered and read as a card that had lost its bottom. It keeps its room
       (visibility, not display) because the bar's height is the one thing
       here that must not change. */
    .pd-side .pd-card[data-collapsed="0"] ~ .pd-card { visibility: hidden; }

    /* The inline PDF's loading ring and navigation hint have their own high
       z-indices. Contain them inside the viewer's layer so the sticky rail
       stays in front of the entire viewer, not just its page and toolbar. */
    .pd-layout .pdf-dock.is-inline { z-index: 0; }

    /* Stacked full-width, there is no "side" left to send it to. */
    .pd-side-swap { display: none; }
}

/* A direct link (or a browser back/forward jump) lands on the fragment
   without running the table of contents' own script, so this keeps the
   target clear of the fixed header even then. Matched by id prefix rather
   than by these two classes alone: a table of contents can also point at a
   plain row that is neither, such as the reviewer names under "Where It
   Stands". */
.pd-card[id], .pd-section[id], [id^="pd-sec-"], [id^="pd-sub-"] { scroll-margin-top: 76px; }
@media (max-width: 1050px) {
    /* A section link clears both the navbar and the pinned contents rail. */
    .pd-card[id], .pd-section[id], [id^="pd-sec-"], [id^="pd-sub-"] {
        scroll-margin-top: calc(var(--pdf-dock-top, 57px) + var(--pd-rail-h, 9rem) + .75rem);
    }
}

/* Header: back out the way you came in, title, status.

   The title starts on the same line as the cards below it. The way back is
   taken out of the row to allow that, in the row, even pulled part-way into
   the margin, it pushed the title about 20px in from the cards' edge. Where
   there is margin enough it sits out there, level with the title's first
   line; where there is not, it takes a line of its own above the title, and
   the title still starts on the cards' edge. */
.pd-top {
    position: relative;
    display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem;
    padding-top: 2.25rem;           /* the line the way back sits on */
}
/* A way back, not one of the page's own controls, so it sits outside the
   record rather than boxed in beside the title. */
.pd-back {
    position: absolute;
    top: 0;
    /* The arrow's ink lines up with the title and the cards: the link's own
       left padding, plus the space the arrow glyph carries at its side. */
    left: calc(-.3rem - 4px);
    display: inline-flex; align-items: center; gap: .3rem;
    color: var(--maroon); text-decoration: none; font-size: .8125rem;
    padding: .35rem .5rem .35rem .3rem; border-radius: var(--r-card, 8px);
    border: none; background: none;
}
.pd-back:hover { background: var(--cream); }
/* Out in the margin, once there is margin to hold it. The record is capped at
   the site's 1168px wrap, so the space to its left grows only past that;
   at 1320px it is wide enough for the link and its gap with room to spare
   from the window's edge. Below that it stays on its own line above. */
@media (min-width: 1320px) {
    .pd-top { padding-top: 0; }
    .pd-back { left: auto; right: calc(100% + .75rem); }
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

/* Step 2: the written sections, shown as written */
.pd-section + .pd-section { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--border); }
.pd-section h3 {
    font-family: var(--font-head); font-size: .875rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .5rem;
}
/* Justified, exactly as the section boxes set it while it was being written:
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

/* Step 3: the files that went with it */
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

/* The checklist: the reason this page exists */
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
/* Said once on a list the adviser changed for this paper. */
.ck-tag { margin-left: .5rem; font-size: .6875rem; font-weight: 400; color: var(--grey); }

/* A banner introduces what follows, so it always carries the gap below it
   itself: the inline margins dotted through the markup were what let it sit
   flush against the file cards and the checklist. */
.pd-note {
    display: flex; gap: .5rem; padding: .75rem .875rem; border-radius: var(--r-card, 8px);
    background: var(--cream); font-size: .75rem; color: var(--ink); line-height: 1.6;
    margin: 0 0 1.25rem;
}
.pd-note:last-child { margin-bottom: 0; }
.pd-note--after { margin: 1.25rem 0 0; }        /* a footnote, not a banner */
.pd-note .material-symbols-outlined { font-size: 18px; color: var(--maroon); flex: 0 0 auto; }
/* The site's own link colour. A browser's default blue belongs to nothing
   else on these pages. */
.pd-note a { color: var(--maroon); }
.pd-note a:hover { color: var(--dark-maroon); }

/* The way to the public copy of a record, in the corner that holds what can
   be done with it rather than what is written in it: under the panel toggle,
   beneath the status badge. Both the student's copy of a record and the
   reviewer's show it, so it lives here rather than in either page.

   A row of its own under the toggle, and flex rather than inline-flex so it
   takes that row instead of trailing along beside the button. The column is
   right-aligned text, so its contents are pushed right to line up with the
   status and the toggle above them, and it is held to one line: wrapped,
   "View in public repository" ladders down the corner a word at a time. */
.pd-repo-link {
    display: flex; justify-content: flex-end; align-items: center; gap: .25rem;
    margin-top: .35rem; font-size: .75rem;
    white-space: nowrap;
    color: var(--maroon); text-decoration: none;
}
.pd-repo-link:hover { color: var(--dark-maroon); text-decoration: underline; }

/* Room between the heading of a group and the rows under it. */
.pd-check-grid { margin-top: .25rem; }
.pd-files { margin-top: .25rem; }

.pd-people { font-size: .75rem; color: var(--ink); line-height: 1.9; }
.pd-people .who { color: var(--maroon); }

@media (max-width: 700px) {
    /* The record's heading, at a size a phone can hold.

       Every one of these figures is set for a wide screen, where the heading
       is one band across the top of a page that has room to spare. On a phone
       the same band ran to 280px before the first card, a third of the screen
       spent on a title, its author and a status: the title alone took three
       lines at 22px, and .pd-status, having been sent full-width by the rule
       below, spread "Status", the panel toggle and the way to the published
       copy down three rows of their own, the toggle against the left edge and
       the link against the right, which read as three unrelated things rather
       than one corner.

       So the type steps down a size, and the corner becomes a single row that
       wraps only if it has to. Nothing is hidden: a phone reader still gets
       the whole heading, and the record starts about 110px further up. */
    .pd-top {
        flex-wrap: wrap; gap: .5rem;
        padding-top: 1.75rem;       /* the line the way back sits on */
        margin-bottom: .875rem;
    }
    .pd-back { font-size: .75rem; padding: .25rem .5rem .25rem .3rem; }
    .pd-back .material-symbols-outlined { font-size: 16px; }
    .pd-heading h1 { font-size: 1.0625rem; line-height: 1.3; margin-bottom: .2rem; }
    .pd-authors { font-size: .75rem; margin-bottom: .15rem; }
    .pd-meta { font-size: .6875rem; }
    .pd-meta .sep { margin: 0 .3rem; }

    /* One row: the word, the badge, the panel toggle, and whatever the page
       puts after them (the way to the public copy on a student's and a
       reviewer's record, Archive on the Director's). The <br> in the markup
       is what used to break the line, and it is the markup of three pages, so
       it is switched off here rather than taken out of each of them.

       Left-aligned, with the status: on a wide screen this column is pushed
       to the right-hand edge of the record and reads as a corner, and at this
       width there is no corner to read, only a row under the title. */
    .pd-status {
        display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .5rem;
        width: 100%; text-align: left;
    }
    .pd-status br { display: none; }
    .pd-status .pd-badge { margin-top: 0; padding: .15rem .45rem; font-size: .6875rem; }
    /* 26px and not smaller: 24x24 CSS px is the floor a target may be drawn
       at (WCAG 2.5.8), and this one is pressed with a thumb.

       Last in the row and hard against its right-hand end. In the markup it
       comes before the way to the published copy, which is right for the
       column this is on a wide screen, where everything stacks down the
       corner in the order it is written; laid out as a row it put a bare icon
       at the left margin, ahead of a link it is no part of, and read as a
       stray mark rather than a control. It is the odd one out in this row by
       nature: the rest of it says what the paper is, and this says what to do
       with the page. The right-hand end is also where the same control sits
       in the corner on a wide screen, so it has not moved so much as stayed
       put.

       The order and the margin are two halves of one thing: `order` alone
       leaves it trailing the link immediately, the auto margin alone leaves
       it in front of the link and pushes the pair right, which is the same
       arrangement moved along. Together the row is the status at one end and
       this at the other. */
    .pd-status .pd-rail-toggle {
        margin-top: 0; padding: .1rem .25rem;
        font-size: .6875rem;
        order: 1; margin-left: auto;
    }
    /* Both of these keep a margin of their own for the stacked layout they
       used to have; in the row they are items like any other and the gap is
       what spaces them. The archive form is styled in archive/view_paper.php
       and reached here by the extra class, which outranks it. */
    .pd-repo-link { margin-top: 0; font-size: .6875rem; }
    .pd-status .pd-archive-form { margin-top: 0; }

    /* The pinned Contents/Citation group should leave room for the paper on a
       phone. Only its card headers shrink; the opened panels keep their space. */
    .pd-side { gap: .25rem; margin: 0 -.5rem; padding: 0 .5rem .125rem; }
    .pd-side .pd-card { padding: .125rem .625rem; }
    .pd-side .pd-card > h2 { min-height: 1.5rem; }
    .pd-side .pd-card > h2 > button { min-width: 1.5rem; min-height: 1.5rem; }

    .pd-card { padding: 1.125rem; }
}
</style>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The wide rail is capped to the height of the record beside it. On a narrow
   screen, section links must clear the pinned rail's current height. CSS
   cannot ask a sibling for either size, so both are measured as they change.
   Keep the values on .pd-layout rather than :root, where a custom property
   change would restyle the whole page. */
(function () {
    /* What the three record pages call for the rail, answered as if there were
       no rail. railControls() below replaces it where there is one. A record
       drawn without a rail still has a table of contents' worth of links to
       scroll by, and reaching for a member of something undefined would stop
       the handler that does it. */
    window.papelRecord = {
        foldContents: function () {},
        headerOffset: function (wide) { return wide; }
    };

    /* This file is included in the head, so the record does not exist yet when
       it runs: the measuring waits for the document the same way the rest of
       the site's head scripts do. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    function start() {
        var layout = document.querySelector('.pd-layout');
        var main   = layout && layout.querySelector('.pd-main');
        var rail   = layout && layout.querySelector('.pd-side');
        if (!layout || !main) return;

        function sync() {
            layout.style.setProperty('--pd-main-h', Math.round(main.offsetHeight) + 'px');
            if (rail) {
                layout.style.setProperty('--pd-rail-h', Math.round(rail.offsetHeight) + 'px');
            }
        }
        sync();

        /* Everything below is about the rail, so a record drawn without one
           stops here. */
        if (rail) { setTimeout(function () { railControls(layout, rail); }, 0); }

        if (window.ResizeObserver) {
            var watch = new ResizeObserver(sync);
            watch.observe(main);
            if (rail) { watch.observe(rail); }
        } else {
            /* Older browsers get the two moments that matter: the cards being
               folded, and the window changing shape. */
            document.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('.card-collapse-btn')) setTimeout(sync, 0);
            });
            window.addEventListener('resize', sync);
        }
    }

    /* ===== The rail's own controls, and the contents bar on a narrow screen =====

       Shared by the three pages that draw a record (the public paper page, the
       author's copy and the reviewer's), which between them had three copies of
       everything round this rail already.

       Run from a timeout rather than straight away, because it works on the
       chevron card_collapse.php adds, and that script's DOMContentLoaded
       listener is registered after this file's, this file being in the head: a
       timeout of zero is after both of them have run. */
    function railControls(layout, rail) {
        var toc  = rail.querySelector('.pd-toc');
        var head = toc && toc.querySelector(':scope > h2');
        var fold = head && head.querySelector('.card-collapse-btn');
        var away = document.getElementById('pdRailToggle');
        var narrowQ = window.matchMedia('(max-width: 1050px)');
        if (!toc) { return; }

        /* ----- Putting the rail away, from inside it -----

           The control used to sit up in the record's own heading beside the
           status, a lone icon with nothing round it to say what it was for. It
           belongs on the thing it acts on, so here it is in the contents' own
           heading, with the chevron that folds the card and the arrows that
           send the rail to the other side.

           The one thing it cannot be is the way back: hidden, the rail takes
           this button with it. So the button in the record's heading is kept
           and drawn only while the rail is away, and that is the whole of the
           pair: shut it from inside, open it from outside, one of the two
           showing at any moment.

           An X rather than a second panel glyph, which beside the swap arrows
           would have been two near-identical rectangles meaning different
           things. */
        if (head && away) {
            var hide = document.createElement('button');
            hide.type = 'button';
            hide.className = 'pd-rail-hide';
            hide.title = 'Hide the contents panel';
            hide.setAttribute('aria-label', hide.title);
            hide.setAttribute('aria-controls', layout.id || 'pdLayout');
            var x = document.createElement('span');
            x.className = 'material-symbols-outlined';
            x.textContent = 'close';
            hide.appendChild(x);
            /* The page's own handler owns what hiding means and is listening
               on that button, so pressing it from here keeps one copy of that
               logic. A hidden button still takes a click. */
            hide.addEventListener('click', function () { away.click(); });
            head.insertBefore(hide, fold || null);

            /* The record's heading and the rail are siblings, so no selector
               can tell the first about the second's state. Watched instead,
               which also covers the rail being put away by something other
               than a press: the public paper page folds it while a manuscript
               is docked over the record. */
            var syncAway = function () {
                away.hidden = !layout.classList.contains('is-rail-collapsed');
            };
            syncAway();
            if (window.MutationObserver) {
                new MutationObserver(syncAway).observe(layout,
                    { attributes: true, attributeFilter: ['class'] });
            } else {
                document.addEventListener('click', function () { setTimeout(syncAway, 0); });
            }
        }

        /* ----- The contents pinned above a narrow record -----

           Below 1051px the rail is not a column beside the record but a bar
           above it, pinned beneath the navbar. Open, the contents of a long
           paper can fill most of a phone, so a scroll folds the list.

           Open, it is a panel hanging off the bar and covering the record, so
           a scroll shuts it, as a scroll shuts any menu: the reader has gone
           back to reading. Pressing the chevron in between does whatever they
           ask of it, and the next scroll has the last word.

           The two figures are a threshold with room either side of it, as the
           upload page's step bar has: a page resting a pixel off the top must
           not fold and unfold the card on every notch of a scroll.

           One listener on the window, which hears the page scrolling and
           nothing else: a scroll event does not bubble, so running down the
           list inside the open panel never reaches this and never shuts the
           panel under the finger doing it. */
        var OPEN_AT = 8, FOLD_AT = 24;

        function isOpen() { return toc.getAttribute('data-collapsed') === '0'; }

        /* Driven through the chevron rather than by writing data-collapsed
           here, so its own aria-expanded, title and label keep up with it. */
        function setOpen(open) { if (isOpen() !== open) { fold.click(); } }

        function follow() {
            if (!fold || !narrowQ.matches) { return; }
            var y = window.pageYOffset;
            if (y <= OPEN_AT) { setOpen(true); }
            else if (y > FOLD_AT) { setOpen(false); }
        }

        window.papelRecord = {
            /* Shut before a section is scrolled to: open, the panel is most of
               the screen the section is about to land on. */
            foldContents: function () { if (fold && narrowQ.matches) { setOpen(false); } },

            /* A narrow target heading clears the navbar, contents rail and
               a small gap, using the rail's measured current height. */
            headerOffset: function (wide) {
                if (!narrowQ.matches) { return wide; }
                var bar = parseFloat(getComputedStyle(document.documentElement)
                            .getPropertyValue('--pdf-dock-top')) || 57;
                var seen = rail.getClientRects().length
                    ? rail.getBoundingClientRect().height : 0;
                return bar + seen + 12;
            }
        };

        if (!fold) { return; }
        window.addEventListener('scroll', follow, { passive: true });
        /* Crossing the breakpoint changes the answer with nothing pressed, and
           a card left folded by the phone layout would otherwise stay folded in
           the column beside the record, where there is room for it and nothing
           it can be in the way of. */
        var onWidth = function () {
            if (narrowQ.matches) { follow(); } else { setOpen(true); }
        };
        if (narrowQ.addEventListener) { narrowQ.addEventListener('change', onWidth); }
        else if (narrowQ.addListener) { narrowQ.addListener(onWidth); }
        /* Landing on a fragment is landing scrolled, so the first state is read
           from where the page actually is, not assumed to be the top. */
        follow();
    }
})();
</script>
