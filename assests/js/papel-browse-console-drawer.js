(function () {
    /* Slack at each end, and nothing more than that: any row with something
       out of sight says so. A row scrolled fully to one end still reports a
       fraction of a pixel to go under a fractional zoom, which this site meets
       from 110% up, so a chevron measured exactly would never come off; 2px
       covers that and no real overrun.

       It was 8px for a day, to spare a row that overruns by four the chevron
       and the fade that cover more than four pixels of it. What that missed is
       that the tabs are not the same from tab to tab: the student dashboard
       shows Drafts and Archived only while they hold something or are the tab
       being read, so the same row overruns by 4px on Approved and by 74 on
       Drafts. The sign came and went as the student moved along the row,
       which reads as a fault in the page. Owner's call, and the right one: a
       row that slides at all says so. */
    var EDGE = 2;

    /* How long after a row is drawn it may still put itself right. Long
       enough for a face fetched over the network, short enough that it is
       over before anyone has read the page and started using it. */
    var SETTLE = 4000;

    /* Every row set up so far, with the function that measures it. Rows are
       thrown away and replaced (see the MutationObserver at the end), so the
       window's listeners walk this list rather than each row adding its own:
       a row replaced on every tab click would otherwise leave a resize
       listener behind each time, measuring a row that is no longer there. */
    var rows = [];

    /* Where each row was left, by its place in the page.

       A tab, a filter, a search and a page of results all swap the whole of
       #mainCol by innerHTML, and the tab row is inside it, so the row on
       screen after a click is not the row that was clicked: it is fresh
       markup, and a fresh scrolling box starts at its left-hand end. On a
       phone, where the line does not fit, that put the line back at All and
       Approved every time a student pressed a tab further along it: the tab
       opened correctly, but the row under their finger jumped, which reads
       as the page having reloaded.

       Kept here, by index, because the element it belongs to is gone by the
       time its replacement arrives; an array keyed on the row's position
       survives that, and a page drawing more than one row keeps them apart.
       The order of .dash-tabs-glide in the document is what that position
       is, and these pages redraw a row in the same place every time. */
    var left = [];

    function placeOf(wrap) {
        return Array.prototype.indexOf.call(
            document.querySelectorAll('.dash-tabs-glide'), wrap);
    }

    function setUp(wrap) {
        var box = wrap.querySelector('.dash-tabs');
        if (!box) { return; }
        var slot = placeOf(wrap);
        /* Nothing kept for this row's place means this is a full page load
           and not a column swapped in: there is no position to go back to,
           and the row has to find the tab being read for itself. */
        var fresh = left[slot] == null;
        /* Marked as done on the element itself, so the observer below never
           sets a row up twice: appending the layers is itself a change it
           hears. */
        wrap.setAttribute('data-glide', '1');
        var wrote = null, touched = false;
        var born = Date.now(), sized = -1;

        /* The blur layers at each end (.glide-fade, includes/console_shell.php),
           three to a side, weakest first. Made here rather than written into
           the two pages that draw the row: they are decoration and nothing
           else, and they only mean anything once this script is deciding when
           they show. Appended after the row and as siblings of one another, in
           that order, because the order is what makes them work: each one
           blurs whatever was painted before it, so the row has to come first
           and each layer has to come after the weaker one it builds on. */
        ['l', 'r'].forEach(function (side) {
            [1, 2, 3].forEach(function (step) {
                var layer = document.createElement('span');
                layer.className = 'glide-fade glide-fade-' + side + ' is-' + step;
                layer.setAttribute('aria-hidden', 'true');
                wrap.appendChild(layer);
            });
        });

        function mark() {
            var max = box.scrollWidth - box.clientWidth;
            wrap.classList.toggle('can-left', box.scrollLeft > EDGE);
            wrap.classList.toggle('can-right', box.scrollLeft < max - EDGE);
        }

        /* Put the line back where its predecessor was left; failing that,
           find the tab being read.

           The second is for a full load of ?tab=archived, where the row
           would otherwise open at its left-hand end with the tab the student
           asked for out of sight past the right. It is deliberately **not**
           applied on top of a restored position, which was the first build:
           a search, a filter and a page of results swap the column without
           changing the tab, so hunting the active tab there dragged the row
           back to the left-hand end, which is the very jump this is here to
           stop, moved to another button. Where a position is restored the tab that
           was pressed is on screen already, it being the one the student's
           finger was on; and Drafts and Archived, the two tabs that come and
           go, are at the end of the row, so losing one shortens the line
           without moving anything before it.

           The tab's place is worked out from the rectangles and the scroll
           as they stand, not from offsetLeft, which is measured against
           .dash-tabs-glide rather than the scrolling box.

           Written straight to scrollLeft, never scrollIntoView and never
           `behavior: 'smooth'`: the observer below calls this as a microtask
           after the swap and before the next paint, so the row is drawn once,
           already in place. A row that slides into position afterwards is the
           movement being got rid of; and scrollIntoView scrolls every box
           round its target, the window included. */
        function place() {
            var max = box.scrollWidth - box.clientWidth;
            var want = left[slot] == null ? box.scrollLeft : left[slot];
            if (want > max) { want = max; }
            if (want < 0) { want = 0; }
            var on = fresh ? box.querySelector('.dash-tab.active') : null;
            if (on) {
                /* Clear of the ends by a little, so the tab being read is not
                   left sitting under the blur the chevrons are drawn in. */
                var pad = 12;
                var edge = box.getBoundingClientRect().left;
                var here = on.getBoundingClientRect();
                var from = box.scrollLeft + here.left - edge - pad;
                var to = box.scrollLeft + here.right - edge + pad;
                /* The far edge first and the near one second, so a tab wider
                   than the row shows its name rather than its count. */
                if (want < to - box.clientWidth) { want = to - box.clientWidth; }
                if (want > from) { want = from; }
                if (want > max) { want = max; }
                if (want < 0) { want = 0; }
            }
            if (want !== box.scrollLeft) { wrote = want; box.scrollLeft = want; }
            left[slot] = box.scrollLeft;
        }

        /* Place it again, the measurements having changed under it. The tabs
           are first laid out in the fallback face, which is narrower, so a
           row placed at parse time is placed against widths that are about
           to change: a first load of the last tab put the line 17px short of
           where that tab needed it and left its count cut off at the edge.

           Not while the reader has hold of the row, and not after the first
           few seconds: by then nothing is still arriving, and anything that
           calls this is a window being resized rather than the page coming
           up. */
        function settle() {
            if (!touched && Date.now() - born < SETTLE) { place(); }
            mark();
        }

        /* A scroll the reader made, as against one place() wrote: only the
           first means hands off from here on. Told apart by the figure and
           not by a flag, because two writes in one frame raise a single
           scroll event between them and a flag would still be standing
           afterwards, ready to swallow the reader's first real scroll. */
        box.addEventListener('scroll', function () {
            if (wrote !== null && box.scrollLeft === wrote) { wrote = null; }
            else { touched = true; }
            left[slot] = box.scrollLeft;
            mark();
        }, { passive: true });

        /* Every tab is watched, and not only the row. The row's box keeps its
           size when what is in it grows, and a ResizeObserver on the box hears
           nothing then; what changes is the tabs. The one that matters is the
           font: the tabs are first laid out in the fallback face, which is
           narrower, and the real one arrives later. Measured at 390px on
           Approved, the row fits by 0px in the fallback and overruns by 4px
           in the real face. Lengthening one tab's count by script shows it:
           26px over and no chevron, with only the box watched. */
        if (window.ResizeObserver) {
            var watch = new ResizeObserver(function () {
                /* The tabs changing width, and not the window changing size.
                   scrollWidth is how much there is to slide past and does not
                   move when the viewport does, so this tells the font from an
                   address bar sliding away, and that matters both ways round:
                   the font is the one thing that can make a placement made at
                   parse time wrong, and a row that jumped back to the tab
                   being read every time the student scrolled the page would
                   be a worse fault than the one this fixes.

                   Measured against what the row was when it was placed, and
                   not by counting the first callback out: a callback is
                   delivered at the next frame, which on a cold load can be
                   after the face has already landed, and a row that spent its
                   only chance that way sat at its left-hand end until
                   document.fonts caught up two frames later. */
                var now = box.scrollWidth;
                if (now !== sized) { sized = now; settle(); return; }
                mark();
            });
            watch.observe(box);
            Array.prototype.forEach.call(box.children, function (tab) { watch.observe(tab); });
        }

        rows.push({ wrap: wrap, mark: mark, settle: settle });
        /* Placed before it is measured: the chevrons say which way the line
           can still go, and that depends on where it is standing. */
        place();
        mark();
        sized = box.scrollWidth;
    }

    function scan() {
        Array.prototype.forEach.call(
            document.querySelectorAll('.dash-tabs-glide:not([data-glide])'), setUp);
    }

    function markAll() {
        /* Rows the page has thrown away are dropped here, which is what lets
           them, their observers and their layers be collected. */
        rows = rows.filter(function (r) { return r.wrap.isConnected; });
        rows.forEach(function (r) { r.mark(); });
    }

    /* markAll's companion for the font: it measures the row again and, where
       the reader has not touched it, puts it where the new measurements say
       it belongs. */
    function settleAll() {
        rows = rows.filter(function (r) { return r.wrap.isConnected; });
        rows.forEach(function (r) { r.settle(); });
    }

    scan();

    window.addEventListener('resize', markAll);
    /* document.fonts.ready alone does not cover a late face. It resolves at
       once if no font has started loading when this runs, and a face is only
       fetched once some text is laid out in it, so ready can be long gone by
       the time the real face lands. loadingdone hears every one that does. */
    if (document.fonts) {
        if (document.fonts.ready) { document.fonts.ready.then(settleAll); }
        if (document.fonts.addEventListener) { document.fonts.addEventListener('loadingdone', settleAll); }
    }
    /* And once everything is in, and again when a page comes back from the
       back-forward cache, which runs no script but may come back at a
       different width. */
    window.addEventListener('load', settleAll);
    window.addEventListener('pageshow', markAll);

    /* **A tab click is not a page load.** On the student dashboard and every
       review desk, a tab, a page of results, a search and a filter all fetch
       the new list and swap the whole of #mainCol by innerHTML, and the tab
       row is inside #mainCol. So after the first click the row on screen was
       new markup that nothing had set up: no layers, no listeners and no
       classes, which is no chevron at all, on every tab but the one the page
       was opened on. Clicking from tab to tab with real mouse events showed
       it at every width the row overruns at; opening each tab's address
       directly, which is how it had been checked, never could.

       Watched for here rather than called from the two loaders, so a third
       page that swaps a row in, or a loader written later, needs nothing
       done. The callback runs as a microtask straight after the swap and
       before the next paint, so the new row is never drawn bare first; and
       what it does is one selector that finds nothing on almost every call.
       Only where there was a row to begin with: the Public Repository
       requires this file too and has none, and swaps its own results often. */
    if (rows.length && window.MutationObserver && document.body) {
        new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
    }
})();
