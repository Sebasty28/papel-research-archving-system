<?php
/**
 * The loading skeleton: pale blocks in the shape of the page, shown over the
 * content area while a page is settling, in the way a chat app shows the shape
 * of a conversation before the messages arrive.
 *
 * It exists because the splash (includes/splash.php) only plays on the first
 * page of a visit and on a refresh: it returns early otherwise, so ordinary
 * navigation from page to page has nothing over it. On those navigations the
 * browser paints the server's markup and the page then settles into its real
 * shape, which on the upload page is a visible jump: the form paints as one
 * column and papelSide rebuilds it into two panes with tab strips at
 * DOMContentLoaded. The skeleton covers that.
 *
 * Required from the top of site_head.php so the decision is made before the
 * first paint. Deciding any later would let the page flash up underneath,
 * which is the thing being fixed.
 *
 * Two parts, because the decision belongs in <head> and the markup in <body>:
 * this file prints the CSS and the deciding script, and defines
 * papel_skeleton_layer(), which site_header.php calls at its end to print the
 * blocks themselves.
 *
 * It covers rather than hides. The real content stays laid out and measurable
 * underneath, so nothing that reads an element's size (the navbar's edge for
 * --pdf-dock-top, the pane widths papelSide measures) sees a hidden box. The
 * overlay starts below the navbar and the breadcrumb strip, which are
 * server-rendered and never reshaped, so those two stay real and only the part
 * of the window that actually changes is covered.
 *
 * A page chooses its shape by setting $PAGE_SKELETON before including
 * site_head.php. 'panes' is the upload page's two-pane editor layout;
 * anything else, including nothing at all, gets the generic page shape.
 *
 * It lifts at the latest of: the page's ready signal, and 300ms after it
 * appeared. The minimum is deliberate: a cached page is ready in well under a
 * tenth of a second, and a skeleton that appears and vanishes inside one is a
 * blink of its own. A page that restructures itself in script calls
 * papelSkeleton.hold() while it parses and papelSkeleton.done() when its real
 * layout is up, so the skeleton lifts onto the finished page and never onto a
 * half-built one. Two failsafes in case that call never comes: the script
 * gives up 1.5s after load, and the CSS fades the layer out on its own at 6s
 * in case the script is the thing that failed.
 *
 * Skipped for anyone who has asked for less motion, the same two settings the
 * splash reads: the system's prefers-reduced-motion, and the accessibility
 * widget's own "Stop animations". The blocks still show, since they are what
 * stops the jump; it is only the sweep across them that is dropped.
 */

/* Read once, here, while the including page's scope is still in reach:
   papel_skeleton_layer() is a function and would not see the page's own
   variable. */
$GLOBALS['PAPEL_SKEL_SHAPE'] = isset($PAGE_SKELETON) ? (string) $PAGE_SKELETON : 'page';
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Hidden until the script decides, so a browser with no script never shows it
   and there is nothing to take away. */
.papel-skel-layer { display: none; }

html.papel-skel .papel-skel-layer {
    display: block;
    position: fixed;
    /* The bars publish their measured heights (site_header.php); the script
       below overwrites this with their live bottom edge once they exist. The
       fallbacks are the resting heights, used only for the first frames. */
    top: var(--papel-skel-top, calc(var(--nav-h, 56px) + var(--crumb-h, 36px)));
    left: 0;
    right: 0;
    bottom: 0;
    /* Under the splash (2147483000) and the loading bar (2147483100), over
       every one of the page's own fixed panels, which top out at 903. */
    z-index: 2147482000;
    background: var(--cream);
    overflow: hidden;
    /* Nothing underneath is reachable while it is up, and it answers nothing
       itself: it is scenery. */
    cursor: progress;
}
html.papel-skel-out .papel-skel-layer {
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease;
}

/* Both tones come from --ink, never from a fixed grey, so the blocks track the
   theme: dark ink on cream in the light theme, light ink on the dark ground in
   the dark one, with no knowledge here of which is which. */
.papel-skel-layer {
    --skel-a: color-mix(in srgb, var(--ink) 8%, transparent);
    --skel-b: color-mix(in srgb, var(--ink) 16%, transparent);
}
.skel-b {
    border-radius: 6px;
    background: linear-gradient(90deg, var(--skel-a) 0%, var(--skel-b) 50%, var(--skel-a) 100%);
    background-size: 220% 100%;
    animation: papel-skel-sweep 1.4s linear infinite;
}
@keyframes papel-skel-sweep {
    from { background-position: 120% 0; }
    to   { background-position: -120% 0; }
}
/* Less motion: the blocks stay, the sweep goes. The blocks are what stops the
   page jumping, so taking them away would reintroduce the thing being fixed. */
@media (prefers-reduced-motion: reduce) {
    .skel-b { animation: none; background: var(--skel-a); }
}
html.papel-skel-still .skel-b { animation: none; background: var(--skel-a); }

/* The last resort, in case the script that lifts this never runs at all. */
html.papel-skel .papel-skel-layer {
    animation: papel-skel-failsafe .3s ease 6s forwards;
}
@keyframes papel-skel-failsafe {
    to { opacity: 0; visibility: hidden; pointer-events: none; }
}

/* ----- The pieces every shape is built from ----- */
.skel-card {
    background: var(--white);
    border-radius: 14px;
    padding: 1.15rem 1.35rem;
    display: flex;
    flex-direction: column;
    gap: .7rem;
}
.skel-row  { height: 12px; }
.skel-h1   { height: 26px; width: 240px; border-radius: 8px; }
.skel-w30  { width: 30%; }
.skel-w45  { width: 45%; }
.skel-w60  { width: 60%; }
.skel-w75  { width: 75%; }
.skel-w90  { width: 90%; }
.skel-grow { flex: 1 1 auto; }

/* ----- The generic page: a title, then cards of lines ----- */
.skel-page {
    max-width: 1320px;
    margin: 0 auto;
    padding: 1.6rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

/* ----- The shared console shape: main column and a 226px rail -----
   Every desk, the repository and the reader hang off the same .wrap, so the
   measurements here are one set, taken from the pages themselves: .wrap is
   1168px at most with 24px of padding, and inside it the main column, a 6px
   gap and the rail come to the 1120px that leaves. A shape that merely looks
   about right still shifts the page sideways when it lifts, which is the jump
   being covered, so these are measured rather than judged by eye. */
.skel-wrap {
    max-width: 1168px;
    margin: 0 auto;
    padding: 24px;
}
.skel-desk {
    display: grid;
    grid-template-columns: 1fr var(--skel-rail-w, 226px);
    /* The gutter both consoles leave: --col-gap's 6px fallback in
       console_shell.php, and the same figure set by archive/index.php. A
       shape that merely looks about right still shifts the page sideways as
       it lifts, which is the jump being covered. */
    gap: 6px;
    align-items: start;
}
.skel-col { min-width: 0; display: flex; flex-direction: column; gap: 12px; }
/* The rail's cards are inset 10px inside the column, as the real ones are. */
.skel-rail { display: flex; flex-direction: column; gap: 8px; padding: 0 10px; }
.skel-rail .skel-card { padding: .55rem .7rem; gap: .5rem; border-radius: 10px; }

/* The console's own rows: the search bar, the strip of tabs, then the papers.
   .dash-shell pads its contents by 14px, so the rows sit in from the column. */
.skel-shell { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
.skel-searchrow { display: flex; align-items: center; gap: .75rem; height: 50px; }
.skel-searchrow .skel-search { flex: 1 1 auto; height: 50px; border-radius: 10px; }
.skel-searchrow .skel-btn { flex: 0 0 166px; height: 46px; border-radius: 10px; }
.skel-barrow { display: flex; align-items: center; justify-content: space-between; height: 33px; }
.skel-barrow .skel-tabs { width: 420px; height: 33px; border-radius: 8px; }
.skel-barrow .skel-tools { width: 174px; height: 26px; border-radius: 8px; }
/* One paper in a list: a title and two lines of detail, with the rule under it
   that .paper-card draws with ::after. */
.skel-paper {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    padding: .875rem 0;
    border-bottom: 1px solid var(--border);
}

/* ----- The repository: the photo band and the search band above the desk ----- */
/* The band it stands in for is a photograph, so it is the stronger of the
   two tones: the paler one read as a gap above the page. */
.skel-hero { height: 240px; background: var(--skel-b); }
.skel-band { height: 50px; display: flex; align-items: center; }
.skel-band .skel-wrap { width: 100%; padding: 0 24px; }
/* Full width: below the breakpoint the repository is one column and the real
   field runs the whole of it. Narrowed to the results column above, in the
   query with the swap. A flat 73% was 40px short of the real field at 1280px
   and 230px short at 900, which shows at whichever edge the column does not
   start from. */
.skel-band .skel-search { width: 100%; height: 50px; border-radius: 10px; }

/* ----- The reader: the paper's heading, then its cards beside a narrow rail ----- */
.skel-read-top { display: flex; flex-direction: column; gap: .45rem; padding-bottom: 28px; }
.skel-read-top .skel-h1 { width: 70%; height: 30px; }
.skel-read {
    display: grid;
    grid-template-columns: 1fr 256px;
    gap: 28px;
    align-items: start;
}
.skel-read .skel-col { gap: 18px; }
/* The reader's rail sits flush against the column, unlike a desk's, whose
   cards are inset 10px inside a wider aside. */
.skel-read .skel-rail { padding: 0; }

/* ----- The management consoles: a heading, the form panel, the table ----- */
.skel-mgmt { max-width: 1168px; margin: 0 auto; padding: 24px 0 48px; }
.skel-mgmt-head { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 20px; }
.skel-mgmt-grid { display: grid; grid-template-columns: 304px 1fr; gap: 20px; align-items: start; }
.skel-trow { display: flex; gap: 1.25rem; align-items: center; padding: .7rem .625rem; border-bottom: 1px solid var(--border); }
.skel-trow .skel-b { height: 12px; flex: 1 1 0; }
.skel-trow .skel-b:first-child { flex: 0 0 22%; }

/* ----- The upload page: two panes with tab strips (papelSide) ----- */
.skel-panes {
    padding: .75rem;
}
.skel-panes-row {
    display: flex;
    gap: .75rem;
    height: 100%;
}
.skel-side {
    /* The real pane's width, which the student can drag and which is kept on the
       device (papel.pdfDockWidth, read by the script below); the fallback is the
       same expression the pane itself opens at ($PDF_DOCK_WIDTH, set where the
       upload page requires pdf_dock.php, and this shape is that page's alone).
       **Change one and change the other**: a guessed width puts the join in the
       wrong place and the swap still shifts sideways, which is the jump being
       covered. */
    flex: 0 0 var(--skel-side-w, clamp(280px, 23vw, 23rem));
    display: flex;
    flex-direction: column;
    gap: 0;
    min-width: 0;
}
/* The pane can be moved to the other side, and folded into a rail of buttons. */
html.papel-skel-right .skel-panes-row { flex-direction: row-reverse; }
html.papel-skel-rail .skel-side { flex-basis: 46px; }
html.papel-skel-rail .skel-side .skel-card { display: none; }
html.papel-skel-rail .skel-strip { flex-direction: column; align-items: stretch; height: auto; gap: .4rem; }
html.papel-skel-rail .skel-tab { width: auto; height: 30px; border-radius: 8px; }
.skel-main {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 0;
    min-width: 0;
}
/* The strip of tabs above each pane, the height the real one is fixed at. */
.skel-strip {
    display: flex;
    align-items: flex-end;
    gap: .35rem;
    height: 38px;
    padding-bottom: 4px;
}
.skel-tab {
    height: 26px;
    width: 120px;
    border-radius: 8px 8px 0 0;
}
.skel-tab.is-short { width: 84px; }
/* The two small buttons at the head of the side strip, and Corpus's round one
   at its end. */
.skel-tab.is-icon { width: 26px; border-radius: 6px; }
.skel-strip .skel-sp { flex: 1 1 auto; }
.skel-orb { height: 30px; width: 30px; border-radius: 50%; flex: 0 0 auto; }
/* The stepper card above the form, four rings joined by rules. */
.skel-steps {
    background: var(--cream);
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 0;
    padding: 1.4rem 1.25rem;
}
.skel-dot  { height: 42px; width: 42px; border-radius: 50%; flex: 0 0 auto; }
.skel-join { height: 2px; flex: 0 1 110px; margin: 0 .9rem; }
/* Step 1's fields, two to a row as the real form lays them out. */
.skel-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .9rem 1.6rem;
}
.skel-field { display: flex; flex-direction: column; gap: .4rem; }
.skel-field .skel-row:last-child { height: 30px; border-radius: 8px; }
.skel-cards { display: flex; flex-direction: column; gap: .75rem; }

/* A phone has no panes, so neither does its skeleton: the side pane goes and
   the form runs the width of the window, which is what the page itself does
   below 900px. */
@media (max-width: 900px) {
    .skel-desk, .skel-read, .skel-mgmt-grid { grid-template-columns: 1fr; }
    .skel-rail, .skel-read .skel-rail { display: none; }
    .skel-side { display: none; }
    .skel-grid { grid-template-columns: 1fr; }
    .skel-steps { display: none; }
}

/* ----- The rail on the left, where the page is about to draw it -----
   The side is a device setting (papel_sidebar_side, read on <html> before
   paint by site_head.php) and the left is the default, so a skeleton with the
   rail always on the right would shift the whole page sideways as it lifts,
   which is the one jump it exists to cover. Each shape uses the same query as
   the layout it stands in for, so the two cannot disagree at a width: the
   desk and the repository swap at 901px (includes/browse_console.php), the
   reader at 1051px (includes/paper_record_css.php). */
@media (min-width: 901px) {
    html.sidebar-left .skel-desk { grid-template-columns: var(--skel-rail-w, 226px) 1fr; }
    html.sidebar-left .skel-desk > .skel-rail { order: 1; }
    html.sidebar-left .skel-desk > .skel-col  { order: 2; }
    /* Beside the rail, the field spans the results column it searches: the
       rail and the 6px gutter off what .wrap leaves. */
    .skel-band .skel-search { width: calc(100% - var(--skel-rail-w, 226px) - 6px); }

    /* ----- The panel folded to its rail (papel_sidebar_folded) -----
       The one width every shape above reads, so the results column and the
       repository's search field follow it without a rule each. The cards go
       with it: a filter card drawn 44px wide is a stripe, and what the page is
       about to paint there is a column of icons. */
    html.sidebar-folded { --skel-rail-w: 56px; }   /* browse_console.php's --sidebar-w */
    html.sidebar-folded .skel-desk > .skel-rail { padding: 0; }
    html.sidebar-folded .skel-desk > .skel-rail .skel-card { display: none; }
    html.sidebar-folded .skel-desk > .skel-rail::before {
        content: '';
        display: block;
        height: 148px;
        border-radius: var(--r-card, 8px);
        background: var(--skel-b);
    }
    /* And it crosses the page with those results, as archive/index.php moves
       the real one into the second column. */
    html.sidebar-left .skel-band .skel-search { margin-left: auto; }
}
@media (min-width: 1051px) {
    html.sidebar-left .skel-read { grid-template-columns: 256px 1fr; }
    html.sidebar-left .skel-read > .skel-rail { order: 1; }
    html.sidebar-left .skel-read > .skel-col  { order: 2; }
}
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var root = document.documentElement;

    /* The splash already covers the whole window on a first open or a refresh,
       and lifts at the later of 2.5s and the load event, which is after the
       page has finished settling. Two overlays for one wait would be one too
       many, so the skeleton takes only the case the splash leaves uncovered. */
    if (root.classList.contains('papel-splash')) { return; }

    var MIN_VISIBLE = 300;     // so a cached page does not blink
    var FADE = 200;
    var shownAt = (window.performance && performance.now) ? performance.now() : Date.now();
    var held = false;
    var finished = false;
    var gone = false;

    function now() {
        return (window.performance && performance.now) ? performance.now() : Date.now();
    }

    /* The same two settings the splash asks about. The blocks are kept either
       way; only the sweep across them is dropped. */
    try {
        var still = (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
        if (!still) {
            var a11y = JSON.parse(localStorage.getItem('papel_a11y') || 'null');
            still = !!(a11y && Array.isArray(a11y.classes) &&
                       a11y.classes.indexOf('a11y-stop-animations') !== -1);
        }
        if (still) { root.classList.add('papel-skel-still'); }
    } catch (err) { /* storage blocked: leave the sweep on */ }

    /* The pane's width, side and folded state are kept on the device by
       papelSide, so they are known here, before anything is drawn, and the
       skeleton can be laid out where the real panes are about to be. Written
       once, in <head>, with no content on the page yet: the warning against
       custom properties on :root is about writing them on every scroll step,
       which restyles the whole page each time. */
    try {
        if (window.innerWidth > 900) {
            var w = parseFloat(localStorage.getItem('papel.pdfDockWidth'));
            if (w > 0) { root.style.setProperty('--skel-side-w', w + 'px'); }
            if (localStorage.getItem('papel.sideSide') === 'right') { root.classList.add('papel-skel-right'); }
            if (localStorage.getItem('papel.sideCollapsed') === '1') { root.classList.add('papel-skel-rail'); }
        }
    } catch (err) { /* storage blocked: the CSS fallbacks are the resting layout */ }

    root.classList.add('papel-skel');

    function lift() {
        if (gone) { return; }
        gone = true;
        root.classList.add('papel-skel-out');
        setTimeout(function () {
            root.classList.remove('papel-skel', 'papel-skel-out', 'papel-skel-still',
                                  'papel-skel-right', 'papel-skel-rail');
            root.style.removeProperty('--skel-side-w');
            var el = document.getElementById('papelSkel');
            if (el && el.parentNode) { el.parentNode.removeChild(el); }
        }, FADE);
    }

    function finish() {
        if (finished) { return; }
        finished = true;
        setTimeout(lift, Math.max(0, shownAt + MIN_VISIBLE - now()));
    }

    /* The overlay starts at the bars' live bottom edge rather than at their
       resting heights: the navbar grows when it wraps on a narrow window or as
       the web fonts arrive, and a stale number would leave a strip of the
       settling page showing above the blocks. Written on the element, never as
       a custom property on :root, which would restyle the whole page. */
    function place() {
        var el = document.getElementById('papelSkel');
        if (!el) { return; }
        var low = 0;
        var bars = [document.querySelector('.main-nav'), document.querySelector('.crumb-bar')];
        for (var i = 0; i < bars.length; i++) {
            if (!bars[i]) { continue; }
            var b = bars[i].getBoundingClientRect().bottom;
            if (b > low) { low = b; }
        }
        if (low > 0) { el.style.top = low + 'px'; }
    }

    /* A page that rebuilds its own layout in script holds the skeleton while it
       parses and releases it once the real layout is up, so the skeleton never
       lifts onto a half-built page. */
    window.papelSkeleton = {
        hold: function () { if (!finished) { held = true; } },
        done: function () { held = false; finish(); },
        showing: function () { return !gone; }
    };
    document.addEventListener('papel-ready', function () { window.papelSkeleton.done(); });

    document.addEventListener('DOMContentLoaded', function () {
        place();
        /* Two frames, not one: the first lets the page's own DOMContentLoaded
           work run, the second lets the browser lay out what it changed. */
        requestAnimationFrame(function () {
            place();
            requestAnimationFrame(function () { if (!held) { finish(); } });
        });
    });
    window.addEventListener('resize', place, { passive: true });

    /* Failsafe: a hold that is never released still lets go shortly after the
       page has finished loading. The CSS fades the layer out at 6s in case
       this script is itself the thing that failed. */
    window.addEventListener('load', function () {
        place();
        setTimeout(finish, 1500);
    });
})();
</script>
<?php
/**
 * The blocks themselves, printed at the end of site_header.php so they sit in
 * <body> with the bars already above them. Fixed-position, so where they fall
 * in the markup does not matter; what matters is that they are printed before
 * the page's own content, so they exist on the first paint.
 */
function papel_skeleton_layer(): void
{
    $shape = isset($GLOBALS['PAPEL_SKEL_SHAPE']) ? $GLOBALS['PAPEL_SKEL_SHAPE'] : 'page';
    $cls   = 'papel-skel-layer' . ($shape === 'panes' ? ' skel-panes' : '');

    echo '<div class="' . $cls . '" id="papelSkel" aria-hidden="true">';
    switch ($shape) {
        case 'panes':      papel_skel_panes();   break;
        case 'console':    papel_skel_console(); break;
        case 'repository': papel_skel_repo();    break;
        case 'reader':     papel_skel_reader();  break;
        case 'table':      papel_skel_table();   break;
        default:           papel_skel_page();
    }
    echo '</div>';
}

/* A run of lines, the widths varied so a card does not read as a barcode. */
function papel_skel_lines(array $widths): void
{
    foreach ($widths as $w) {
        echo '<div class="skel-b skel-row skel-w' . (int) $w . '"></div>';
    }
}

/* The three cards down the right of every desk: two short ones of links, and
   the filters below them. */
function papel_skel_rail(): void
{
    echo '<div class="skel-rail">';
    echo   '<div class="skel-card">'; papel_skel_lines([60, 90, 90]); echo '</div>';
    echo   '<div class="skel-card">'; papel_skel_lines([75, 90, 90]); echo '</div>';
    echo   '<div class="skel-card">'; papel_skel_lines([60, 90, 75, 90, 45, 90, 75, 60, 90, 45]); echo '</div>';
    echo '</div>';
}

/* One paper in a list: the title, then two lines of detail. */
function papel_skel_papers(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        echo '<div class="skel-paper">';
        echo   '<div class="skel-b skel-row skel-w75"></div>';
        echo   '<div class="skel-b skel-row skel-w45"></div>';
        echo   '<div class="skel-b skel-row skel-w60"></div>';
        echo '</div>';
    }
}

/* The dashboards and the four review desks (console_shell.php). */
function papel_skel_console(): void
{
    echo '<div class="skel-wrap"><div class="skel-desk">';
    echo   '<div class="skel-col"><div class="skel-shell">';
    echo     '<div class="skel-searchrow"><div class="skel-b skel-search"></div><div class="skel-b skel-btn"></div></div>';
    echo     '<div class="skel-barrow"><div class="skel-b skel-tabs"></div><div class="skel-b skel-tools"></div></div>';
    papel_skel_papers(4);
    echo   '</div></div>';
    papel_skel_rail();
    echo '</div></div>';
}

/* The Public Repository: the photo band and the search band above the same
   desk. */
function papel_skel_repo(): void
{
    echo '<div class="skel-hero"></div>';
    echo '<div class="skel-band"><div class="skel-wrap"><div class="skel-b skel-search"></div></div></div>';
    echo '<div class="skel-wrap"><div class="skel-desk">';
    echo   '<div class="skel-col">';
    echo     '<div class="skel-b skel-h1"></div>';
    echo     '<div class="skel-barrow"><div class="skel-b skel-tabs"></div><div class="skel-b skel-tools"></div></div>';
    papel_skel_papers(3);
    echo   '</div>';
    papel_skel_rail();
    echo '</div></div>';
}

/* A paper's page: review_paper.php, paper_details.php and the public
   view_paper.php, which share .pd-wrap. */
function papel_skel_reader(): void
{
    echo '<div class="skel-wrap">';
    echo   '<div class="skel-read-top">';
    echo     '<div class="skel-b skel-h1"></div>';
    echo     '<div class="skel-b skel-row skel-w45"></div>';
    echo     '<div class="skel-b skel-row skel-w30"></div>';
    echo   '</div>';
    echo   '<div class="skel-read">';
    echo     '<div class="skel-col">';
    echo       '<div class="skel-card">'; papel_skel_lines([30, 90, 75, 60]); echo '</div>';
    echo       '<div class="skel-card">'; papel_skel_lines([45, 90, 60]); echo '</div>';
    echo       '<div class="skel-card">'; papel_skel_lines([30, 90, 90, 75, 90, 60, 90, 75]); echo '</div>';
    echo     '</div>';
    echo     '<div class="skel-rail">';
    echo       '<div class="skel-card">'; papel_skel_lines([60, 90, 75, 90, 45, 75]); echo '</div>';
    echo       '<div class="skel-card">'; papel_skel_lines([75]); echo '</div>';
    echo     '</div>';
    echo   '</div>';
    echo '</div>';
}

/* The three management consoles and the support desk (manage_page.php): a
   heading, the form panel down the left, the table beside it. */
function papel_skel_table(): void
{
    echo '<div class="skel-mgmt">';
    echo   '<div class="skel-mgmt-head"><div class="skel-b skel-h1"></div><div class="skel-b skel-row skel-w45"></div></div>';
    echo   '<div class="skel-mgmt-grid">';
    echo     '<div class="skel-card">'; papel_skel_lines([60, 90, 90, 45, 90, 90, 75, 90, 60, 90]); echo '</div>';
    echo     '<div class="skel-col">';
    echo       '<div class="skel-barrow"><div class="skel-b skel-tabs"></div><div class="skel-b skel-tools"></div></div>';
    for ($i = 0; $i < 7; $i++) {
        echo   '<div class="skel-trow"><div class="skel-b"></div><div class="skel-b"></div><div class="skel-b"></div><div class="skel-b"></div></div>';
    }
    echo     '</div>';
    echo   '</div>';
    echo '</div>';
}

/* Anything that has not said what it is: a title and two cards of lines. */
function papel_skel_page(): void
{
    echo '<div class="skel-page">';
    echo   '<div class="skel-b skel-h1"></div>';
    echo   '<div class="skel-card">'; papel_skel_lines([30, 90, 75, 60]); echo '</div>';
    echo   '<div class="skel-card">'; papel_skel_lines([45, 90, 75, 30]); echo '</div>';
    echo '</div>';
}

/* The upload page's two panes with their tab strips (papelSide). */
function papel_skel_panes(): void
{
    ?>
    <div class="skel-panes-row">
      <div class="skel-side">
        <div class="skel-strip">
          <div class="skel-b skel-tab is-icon"></div>
          <div class="skel-b skel-tab is-icon"></div>
          <div class="skel-b skel-tab"></div>
          <div class="skel-sp"></div>
          <div class="skel-b skel-orb"></div>
        </div>
        <div class="skel-card skel-grow">
          <?php papel_skel_lines([45, 75, 60, 75, 45, 60, 75, 30]); ?>
        </div>
      </div>
      <div class="skel-main">
        <div class="skel-strip">
          <div class="skel-b skel-tab"></div>
          <div class="skel-b skel-tab is-short"></div>
        </div>
        <div class="skel-cards skel-grow">
          <div class="skel-card skel-steps">
            <div class="skel-b skel-dot"></div>
            <div class="skel-b skel-join"></div>
            <div class="skel-b skel-dot"></div>
            <div class="skel-b skel-join"></div>
            <div class="skel-b skel-dot"></div>
            <div class="skel-b skel-join"></div>
            <div class="skel-b skel-dot"></div>
          </div>
          <div class="skel-card skel-grow">
            <div class="skel-b skel-h1"></div>
            <div class="skel-grid">
              <div class="skel-field"><div class="skel-b skel-row skel-w45"></div><div class="skel-b skel-row"></div></div>
              <div class="skel-field"><div class="skel-b skel-row skel-w45"></div><div class="skel-b skel-row"></div></div>
              <div class="skel-field"><div class="skel-b skel-row skel-w45"></div><div class="skel-b skel-row"></div></div>
              <div class="skel-field"><div class="skel-b skel-row skel-w45"></div><div class="skel-b skel-row"></div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
}
