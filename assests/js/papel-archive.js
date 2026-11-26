document.addEventListener('DOMContentLoaded', function () {

// ===== Hero banner show/hide (remembered per browser) =====
var bannerToggle = document.getElementById('bannerToggle');
var heroBanner   = document.getElementById('heroBanner');
if (bannerToggle && heroBanner) {
    function applyBannerState(collapsed) {
        heroBanner.classList.toggle('collapsed', collapsed);
        bannerToggle.classList.toggle('is-collapsed', collapsed);
        /* Words, icon and label move together. The eye names what pressing it
           will do, so a hidden banner offers an open eye. */
        var text = collapsed ? 'Show Banner' : 'Hide Banner';
        document.getElementById('bannerToggleLabel').textContent = text;
        var icon = document.getElementById('bannerToggleIcon');
        if (icon) { icon.textContent = collapsed ? 'visibility' : 'visibility_off'; }
        bannerToggle.setAttribute('aria-label', text);
        bannerToggle.setAttribute('title', text);
        bannerToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
    }
    var stored = null;
    try { stored = localStorage.getItem('papel_banner_hidden'); } catch (err) {}
    if (stored === '1') applyBannerState(true);
    /* In Zen the banner is folded whatever the stored answer is, and the eye
       that would unfold it is in the strip, which Zen has hidden. Coming out
       of Zen gives the reader their own answer back: Zen never wrote it, so
       this is the only thing that remembers they did not fold it. */
    if (document.documentElement.classList.contains('zen-on')) { applyBannerState(true); }
    document.addEventListener('papel:zen', function (e) {
        applyBannerState(e.detail && e.detail.on ? true : stored === '1');
    });

    /* Pressed by hand: that answer is kept, and the scroll below stops
       managing the banner for the rest of this visit. Showing it again and
       having it fold away on the next notch of the wheel is the page
       arguing with the person using it. */
    var bannerManual = false;
    bannerToggle.addEventListener('click', function() {
        var collapsed = !heroBanner.classList.contains('collapsed');
        bannerManual = true;
        applyBannerState(collapsed);
        try { localStorage.setItem('papel_banner_hidden', collapsed ? '1' : '0'); } catch (err) {}
    });

    /* ---- Scrolling down folds the banner away -------------------------
       The photo is 240px of the window (and the same again of a phone's), and
       the results sit in a well 620px high: a reader who has started reading
       the list is looking at the part of the page the banner is pushing down.
       So the first scroll down puts it away, and coming back to the very top
       brings it back.

       Two things this has to respect, both already written down in CLAUDE.md:

       - **A scroll event does not bubble.** On a desktop the window hardly
         scrolls at all here, because the well scrolls inside itself; on a
         phone the window is the scroller. One listener on the document in the
         **capture** phase hears both, and the position read is the two added
         together, so a notch in either one counts.
       - **Folding the banner moves the page under the reader.** The hero
         leaves the flow, the browser clamps the scroll, and that clamp
         arrives as another scroll event: without the lock below it reads as a
         scroll back to the top, the banner comes back, and the pair of them
         flap once a frame. Nothing is decided for half a second after each
         automatic change, which is longer than the clamp and far shorter than
         a second deliberate scroll.

       It is never written to localStorage: this is what the page does while
       it is being read, not an answer the reader has given. Their own answer
       is the toggle, and a banner they hid stays hidden (autoHidden is false,
       so nothing here ever brings it back). */
    var autoHidden = false;
    var lastPos    = 0;
    var lockUntil  = 0;

    function scrollPos() {
        var win  = window.pageYOffset || document.documentElement.scrollTop || 0;
        /* Looked up each time: an AJAX page swap replaces the whole results
           column, well and all, so a reference taken once would be to a node
           that is no longer on the page. */
        var well = document.querySelector('.paper-list.is-scrollable');
        return win + (well ? well.scrollTop : 0);
    }

    /* Below the breakpoint there is no banner to fold, so none of this runs:
       a phone is not asked to read two scroll positions and compare them on
       every notch for a photo that is display: none. matchMedia with the
       stylesheet's own query string, never innerWidth, which disagrees with
       it by a scrollbar at the boundary. */
    var noBanner = window.matchMedia('(max-width: 900px)');

    document.addEventListener('scroll', function () {
        if (bannerManual || noBanner.matches) { return; }
        var now  = Date.now();
        var at   = scrollPos();
        var down = at - lastPos;
        lastPos  = at;
        if (now < lockUntil) { return; }

        var collapsed = heroBanner.classList.contains('collapsed');
        if (!collapsed && down > 8 && at > 8) {
            applyBannerState(true);
            autoHidden = true;
            lockUntil  = now + 500;
            lastPos    = scrollPos();
        } else if (collapsed && autoHidden && at <= 2 && down < 0) {
            applyBannerState(false);
            autoHidden = false;
            lockUntil  = now + 500;
            lastPos    = scrollPos();
        }
    }, true);
}

// ===== Banner slideshow: the order was shuffled by the server; this walks
// it, one photo every five seconds. =====
(function () {
    if (window.matchMedia('(max-width: 900px)').matches) { return; }
    var hero   = document.getElementById('heroBanner');
    var slides = hero ? hero.querySelectorAll('.hero-slide') : [];
    if (!slides.length) return;

    var current = 0;
    function ready(img) { return img.complete && img.naturalWidth > 0; }
    function show(index) {
        slides[current].classList.remove('is-active');
        slides[index].classList.add('is-active');
        current = index;
        hero.classList.remove('image-unavailable');
    }
    function failed(img, index) {
        /* A failed WebP source does not make <picture> try its JPEG <img>.
           Retry that fallback once, then remove the empty banner if no photo
           can paint. An errored image still reports complete=true. */
        var source = img.previousElementSibling;
        if (source && source.tagName === 'SOURCE' && source.srcset && !img.dataset.jpegRetried) {
            img.dataset.jpegRetried = '1';
            source.remove();
            var jpeg = img.getAttribute('src');
            if (jpeg) { img.src = jpeg + (jpeg.indexOf('?') < 0 ? '?' : '&') + 'jpeg-fallback=1'; }
            return;
        }
        if (index !== current) return;
        for (var n = 0; n < slides.length; n++) {
            if (ready(slides[n])) { show(n); return; }
        }
        hero.classList.add('image-unavailable');
    }
    slides.forEach(function (img, index) {
        img.addEventListener('load', function () {
            if (hero.classList.contains('image-unavailable')) { show(index); }
        });
        img.addEventListener('error', function () { failed(img, index); });
        if (img.getAttribute('src') && img.complete && !img.naturalWidth) { failed(img, index); }
    });
    /* Someone who has asked their system for less motion keeps one photo. */
    if (slides.length < 2 || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;

    function loadSlide(index) {
        var img = slides[index];
        if (!img || !img.dataset.src) return;
        /* Fetch only the next photo. Loading all four at once adds a burst of
           requests to the repository's initial page load. */
        var webp = img.previousElementSibling;
        if (webp && webp.tagName === 'SOURCE' && webp.dataset.srcset) {
            webp.srcset = webp.dataset.srcset; delete webp.dataset.srcset;
        }
        img.src = img.dataset.src; delete img.dataset.src;
    }
    window.addEventListener('load', function () { loadSlide(1); });

    setInterval(function () {
        /* Nothing to animate with the banner hidden or the tab in the
           background, and advancing unseen would make the photo someone
           returns to a jump rather than a fade. */
        if (document.hidden || hero.classList.contains('collapsed')) return;
        var next = (current + 1) % slides.length;
        /* Not loaded yet (a slow connection): hold on the current photo
           rather than fade to nothing. */
        if (!slides[next].getAttribute('src') || !ready(slides[next])) return;
        show(next);
        loadSlide((next + 1) % slides.length);
    }, 5000);
})();

// ===== The banner photo's reach: up under the navbar, down to the bottom of
// the search field. Measured, because neither the header nor the field is a
// fixed size (see .hero). Both readings are the same with the banner open or
// hidden (the top of the box never moves, only its height) so the photo
// keeps one size in both states. =====
(function () {
    var hero  = document.getElementById('heroBanner');
    var field = document.querySelector('.search-form');
    var crumb = document.querySelector('.crumb-bar');
    if (!hero || !field) return;
    function fit() {
        var h = hero.getBoundingClientRect();
        hero.style.setProperty('--hero-lift', Math.max(0, Math.round(h.top + window.scrollY)) + 'px');
        hero.style.setProperty('--hero-fade', Math.round(field.getBoundingClientRect().height) + 'px');
        // Where the hidden banner is cut: the bottom of the maroon strip, so
        // the glass of the navbar and of the strip both keep something behind
        // them. Read off the strip because the navbar is sticky and its own
        // position depends on the scroll.
        if (crumb) {
            hero.style.setProperty('--hero-nav',
                Math.max(0, Math.round(crumb.getBoundingClientRect().bottom + window.scrollY)) + 'px');
        }
    }
    fit();
    window.addEventListener('resize', fit);
    /* The strip is one of the three things measured here, and Zen takes it
       away without a resize or a reload. */
    document.addEventListener('papel:zen', fit);
    /* The header grows when the web fonts land and when the nav wraps, and the
       field changes height at the narrow breakpoints: none of which is a
       window resize on its own. */
    if (window.ResizeObserver) {
        var watch = new ResizeObserver(fit);
        [field, document.querySelector('.site-header'), document.querySelector('.crumb-bar')]
            .forEach(function (el) { if (el) watch.observe(el); });
    }
})();

// ===== AJAX result loading: search, filters, and pagination update just
// the results list instead of reloading the whole page. =====
var mainCol = document.getElementById('mainCol');
var searchForm = document.getElementById('searchForm');
// filterForm is resolved per-event via delegation (the sidebar is swapped too)

// Only reveal the loading state if the fetch is still pending after a
// short delay: keeps fast responses from flashing the overlay on/off.
var resultsLoadingTimer = null;
function setResultsLoading(isLoading) {
    if (!mainCol) return;
    clearTimeout(resultsLoadingTimer);
    if (isLoading) {
        resultsLoadingTimer = setTimeout(function () { mainCol.classList.add('is-loading'); }, 200);
    } else {
        mainCol.classList.remove('is-loading');
    }
}

/* Published, because the keyword strip loads results too and is wired in its
   own block above this one. */
window.papelLoadResults = function (url, pushState) { loadResults(url, pushState); };

function loadResults(url, pushState) {
    if (!mainCol) { window.location.href = url; return; }
    setResultsLoading(true);
    var ajaxUrl = url + (url.indexOf('?') > -1 ? '&' : '?') + 'ajax=1';
    fetch(ajaxUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('Request failed'); return r.json(); })
        .then(function (data) {
            /* **Where the keyword strip had been scrolled to.** The strip is
               inside the column this swap replaces, so a fresh one starts at
               its left-hand end: somebody who had scrolled a long way along it
               to find a keyword was put back at the beginning the moment they
               pressed one, and had to scroll all the way out again for the
               next one. Read now rather than when the request went out,
               because the strip can be scrolled while the results are being
               fetched. */
            var railAt = 0;
            var railWas = document.getElementById('kwRail');
            if (railWas) { railAt = railWas.scrollLeft; }

            mainCol.innerHTML = data.html;
            /* Put back before the frame is painted, so it never shows the
               start of the strip and then jumps. The keywords are the same on
               every page of results (they are counted from the whole
               repository, not from the filter), so the figure still means the
               same place in the same list. */
            var railNow = document.getElementById('kwRail');
            if (railNow && railAt) { railNow.scrollLeft = railAt; }
            // Re-render the sidebar too so the filter controls reflect the
            // state the server just applied (selects back to "All", etc.).
            if (data.sidebar) {
                var side = document.getElementById('sidebarCol');
                if (side) side.innerHTML = data.sidebar;
            }
            setResultsLoading(false);
            /* **Everything the reader cannot see yet waits for the paint.**
               Measured on staging, a click here took 474ms, of which 390ms was
               this callback: two innerHTML writes, and then work that reads
               geometry straight afterwards, which forces the layout the writes
               had just invalidated. The results are on screen as soon as the
               two writes above are done, so the browser should be allowed to
               show them before any of the rest runs.

               rAF then a timeout, not rAF alone: a rAF callback still runs
               before the paint it is scheduled with, so the work would land in
               the same frame and change nothing. The pair yields after it.

               Nothing visible is deferred. Quick Settings is a closed panel, so
               its radios are not on screen; the keyword strip renders from its
               own HTML and only its drag handler and end-room class come from
               script, and neither is missed in the frame before they arrive. */
            requestAnimationFrame(function () {
                setTimeout(function () {
                    // The swap replaced the Quick Settings controls: re-tick them.
                    if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
                    // The swap replaced the keyword strip with a fresh node of its own.
                    if (window.papelKeywordRail) window.papelKeywordRail();
                }, 0);
            });
            /* The search box is outside the column that was swapped, so it
               keeps whatever it held: after Back, or a keyword pressed from a
               page whose box said something else, it would be showing a search
               that is no longer the one on screen. The address is the
               authority. */
            var box = document.getElementById('searchInput');
            if (box) {
                var asked = '';
                try {
                    asked = new URL(url, window.location.href).searchParams.get('q') || '';
                } catch (err) {}
                if (box.value !== asked) { box.value = asked; }
            }
            if (pushState !== false) {
                var pageUrl = new URL(url, location.href);
                pageUrl.pathname = pageUrl.pathname.replace(/\/index\.php$/, '/').replace(/\.php$/, '');
                history.pushState({ papelAjax: true }, '', pageUrl.pathname + pageUrl.search + pageUrl.hash);
            }
        })
        .catch(function () { window.location.href = url; });
}

/* ===== The keyword strip ============================================

   A strip of the papers' own keywords, scrolled by hand: a swipe on a phone, a
   sideways wheel, the keyboard walking the chips, or a mouse drag, which is
   the one gesture a browser does not give for free and is all this block
   builds. It used to roll along by itself; a strip that moves is a strip a
   reader has to catch, so it stands still now.

   Rebound after every AJAX swap, which replaces the whole results column and
   the rail with it: window.papelKeywordRail is called from loadResults() as
   well as on load. */
(function () {
    /* **Room at the end of the track for the overlay to stand on, and only
       where the strip has somewhere to scroll.** On a phone View All Keywords
       lies over the strip's right-hand end, so without that room the last
       keyword can never be brought out from under it, which is a control
       hiding the thing it is about. On a repository with few enough keywords
       to fit the line the same room is a lie: the strip would drag a hand's
       width into nothing and the faded end would promise more to come.

       Measured from the chips rather than from the rail's own scrollWidth,
       which already counts the room once it is on and would then go on
       answering yes for ever. The two rectangles both move with scrollLeft,
       so their difference is the content's width wherever the strip is
       standing. Read again on a resize, which is also how the desktop layout
       - where the button sits beside the strip and nothing is covered - never
       gains it, and once more when the fonts land, since the chips are as
       wide as their words. */
    function fitEnd() {
        var rail = document.getElementById('kwRail');
        var set  = rail && rail.querySelector('.kw-set');
        if (!set || !set.lastElementChild) { return; }
        var content = set.lastElementChild.getBoundingClientRect().right
                    - set.getBoundingClientRect().left;
        rail.classList.toggle('has-end-room', content > rail.clientWidth + 1);
    }
    window.addEventListener('resize', fitEnd);
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitEnd); }

    window.papelKeywordRail = function () {
        var rail = document.getElementById('kwRail');
        if (!rail) { return; }
        fitEnd();

        var dragging = false;
        var moved    = false;   // a drag, not a press: the click is then dropped
        var fromX = 0, fromLeft = 0;

        /* **A chip is a link, and a link is draggable.** Press one and move and
           the browser starts a drag of its own: it fired `dragstart` after the
           first move, then `pointercancel`, which ended this one a notch in, so
           the strip moved 10px and stuck. Nothing here has anywhere to be
           dropped. */
        rail.addEventListener('dragstart', function (e) { e.preventDefault(); });

        rail.addEventListener('pointerdown', function (e) {
            /* A finger is left to the browser: native touch scrolling has
               momentum and a feel this cannot reproduce. */
            if (e.pointerType !== 'mouse') { return; }
            dragging = true;
            moved    = false;
            fromX    = e.clientX;
            fromLeft = rail.scrollLeft;
            rail.classList.add('is-dragging');
        });

        /* **Followed on the document, and never with setPointerCapture.**
           Capturing the pointer on the rail retargets the compatibility mouse
           events to it, and the click that follows is then raised on the rail
           rather than on the chip under the pointer: `closest('.kw-chip')` came
           back null and pressing a keyword did nothing at all, while a script
           calling .click() on the chip (which is how it was tested) went
           straight past the bug. The same rule as the section rows on the
           upload page, which follow the pointer on the document for a
           different reason. */
        document.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            var by = e.clientX - fromX;
            if (Math.abs(by) > 4) { moved = true; }
            rail.scrollLeft = fromLeft - by;
            e.preventDefault();
        });

        document.addEventListener('pointerup', function () {
            if (!dragging) { return; }
            dragging = false;
            rail.classList.remove('is-dragging');
            /* Only the click this drag is about to raise is swallowed. Cleared
               in a timeout rather than in the click handler, which never runs
               at all when a drag ends off a chip: left set, the flag ate the
               next real press of a keyword instead. */
            setTimeout(function () { moved = false; }, 0);
        });

        /* A press of a chip. Capture, so it runs before the delegated handler
           that loads whatever a link in the results column points at: a keyword
           is not a page to go to, it is one of up to five terms in the search
           box, and pressing it again takes it out.

           The chip's href stays a plain search for that one keyword, which is
           what happens with no script at all. */
        rail.addEventListener('click', function (e) {
            if (moved) {                       // a drag, not a press
                moved = false;
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var chip = e.target.closest ? e.target.closest('.kw-chip') : null;
            if (!chip || !window.papelKeywordPick) { return; }
            e.preventDefault();
            e.stopPropagation();
            window.papelKeywordPick(chip.textContent.trim());
        }, true);
    };

    window.papelKeywordRail();

    /* ---- choosing keywords ------------------------------------------
       The search box is the one place the choice lives: the chips write into
       it, the server reads it (as a comma-separated list of terms, each one
       narrowing the results further), and it is what the reader sees they have
       asked for. Nothing is kept anywhere else, so typing in the box by hand,
       pressing chips, and the Back button cannot disagree about what is
       chosen. */
    var MAX = 5;

    function terms() {
        var box = document.getElementById('searchInput');
        if (!box) { return []; }
        return box.value.split(',').map(function (t) {
            return t.replace(/\s+/g, ' ').trim();
        }).filter(function (t) { return t !== ''; });
    }

    /* The sixth keyword, said in the dialog every console on this site says
       things in (includes/action_dialogs.php, which site_footer.php brings in
       on every page). A chip that simply refused to light up reads as a strip
       that has stopped working; the reader has to be told there is a limit and
       what to do about it, which is to press one of the five again. */
    function sayLimit(chosen) {
        if (typeof window.papelShow === 'function') {
            window.papelShow('Five keywords at most', [
                'You have already chosen five. Press one of them again to take it '
                + 'out, then choose the one you want instead.',
                /* A plain line, not a [label, value] pair: that pair is set in
                   monospace for IDs and passwords, and a list of keywords read
                   as a code somebody had to decipher. */
                'Chosen: ' + chosen.join(', ')
            ]);
            return;
        }
        /* No dialog on the page for some reason: say it where the chips are
           rather than not at all. */
        var note = document.getElementById('kwLimit');
        if (!note) { return; }
        note.hidden = false;
        clearTimeout(note.papelTimer);
        note.papelTimer = setTimeout(function () { note.hidden = true; }, 2600);
    }

    /* **The X in the search field takes every keyword out.** It is the
       browser's own clear button on an `input type="search"`, and it only
       empties the box: the results underneath went on showing the search that
       had just been rubbed out, and the chips went on looking chosen. The
       `search` event is what that button raises (and Enter, which is why a box
       with words still in it is left to the form).

       Bound once, on a field that lives outside the column AJAX swaps replace,
       so unlike the strip it is not rebound after a swap. */
    (function () {
        var box = document.getElementById('searchInput');
        if (!box) { return; }
        box.addEventListener('search', function () {
            if (box.value.trim() !== '') { return; }      // Enter, not the X
            /* Nothing was being searched for: emptying an empty box should not
               fetch the whole repository again. */
            if (!/[?&]q=/.test(window.location.search)) { return; }
            if (window.papelLoadResults) { window.papelLoadResults('index.php?browse=1'); }
            else { window.location.href = 'index.php?browse=1'; }
        });
    })();

    /* ---- every keyword, in the dialog --------------------------------
       The strip shows the commonest thirty; this is the rest of them, and the
       way to pick several without pressing one, waiting for the results, and
       pressing the next. Nothing is applied until Done: Cancel and Escape
       leave the page exactly as it was, which is what a dialog with two
       buttons promises.

       The button that opens it is inside the column AJAX swaps replace, so it
       is reached by delegation; the dialog itself is outside that column and
       is wired once. */
    (function () {
        var dlg = document.getElementById('kwDialog');
        if (!dlg) { return; }
        var card   = document.getElementById('kwDialogCard');
        var list   = document.getElementById('kwDialogList');
        var count  = document.getElementById('kwDialogCount');
        var clear  = document.getElementById('kwDialogClear');
        var note   = document.getElementById('kwDialogNote');
        var find   = document.getElementById('kwDialogFind');
        var wipe   = document.getElementById('kwDialogFindClear');
        var none   = document.getElementById('kwDialogNone');
        var done   = document.getElementById('kwDialogDone');
        var cancel = document.getElementById('kwDialogCancel');
        var picked = [];        // in the order they were pressed

        function draw() {
            var full = picked.length >= MAX;
            count.textContent = picked.length + ' of ' + MAX + ' chosen';
            clear.disabled = picked.length === 0;
            note.classList.toggle('is-full', full);
            note.textContent = full
                ? 'Five keywords at most. Press one of them again to take it out.'
                : 'Choose up to five. Press one again to take it out.';
            var chips = list.querySelectorAll('.kw-pick');
            for (var i = 0; i < chips.length; i++) {
                var on = picked.indexOf(chips[i].getAttribute('data-kw')) > -1;
                chips[i].classList.toggle('is-on', on);
                /* A chip that cannot be added says so to a screen reader as
                   well as by not lighting up, and stays pressable so it can be
                   taken out again. */
                chips[i].setAttribute('aria-pressed', on ? 'true' : 'false');
                chips[i].disabled = false;
            }
        }

        /* Narrowed as it is typed. A chip taken out of view is only hidden: it
           stays chosen and still counts, so finding one keyword, choosing it,
           and then searching for the next does not quietly undo the first. */
        function sift() {
            var want  = (find.value || '').trim().toLowerCase();
            if (wipe) { wipe.hidden = (find.value || '') === ''; }
            var chips = list.querySelectorAll('.kw-pick');
            var shown = 0;
            for (var i = 0; i < chips.length; i++) {
                var hit = !want || chips[i].getAttribute('data-kw').toLowerCase().indexOf(want) > -1;
                chips[i].hidden = !hit;
                if (hit) { shown++; }
            }
            none.hidden = shown > 0;
        }

        function open() {
            /* Seeded from the search box every time: it is the one place the
               choice lives, and this dialog is not redrawn by a swap. */
            picked = terms().slice(0, MAX);
            find.value = '';
            sift();
            draw();
            if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
            /* The page behind it is held still while it is up. */
            document.documentElement.classList.add('kw-locked');
            list.scrollTop = 0;
            if (card && card.focus) { card.focus({ preventScroll: true }); }
        }

        /* However it closes: Done, Cancel, Escape, or the browser's own doing. */
        dlg.addEventListener('close', function () {
            document.documentElement.classList.remove('kw-locked');
        });

        find.addEventListener('input', sift);
        if (wipe) {
            wipe.addEventListener('click', function () {
                find.value = '';
                sift();
                /* Back in the field, which is where somebody who has just
                   cleared it is about to type. */
                find.focus();
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('#kwMoreBtn') : null;
            if (!btn) { return; }
            e.preventDefault();
            open();
        });

        list.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('.kw-pick') : null;
            if (!chip) { return; }
            var label = chip.getAttribute('data-kw');
            var at = picked.indexOf(label);
            if (at > -1) { picked.splice(at, 1); }
            else if (picked.length >= MAX) { draw(); return; }   // the note says why
            else { picked.push(label); }
            draw();
        });

        clear.addEventListener('click', function () {
            picked = [];
            draw();
        });

        cancel.addEventListener('click', function () { dlg.close(); });

        done.addEventListener('click', function () {
            var box = document.getElementById('searchInput');
            dlg.close();
            if (!box) { return; }
            var wanted = picked.join(', ');
            if (box.value === wanted) { return; }   // nothing was changed
            box.value = wanted;
            var url = 'index.php?browse=1' + (wanted ? '&q=' + encodeURIComponent(wanted) : '');
            if (window.papelLoadResults) { window.papelLoadResults(url); }
            else { window.location.href = url; }
        });
    })();

    window.papelKeywordPick = function (label) {
        var box = document.getElementById('searchInput');
        if (!box) { return; }
        var now  = terms();
        var at   = -1;
        for (var i = 0; i < now.length; i++) {
            if (now[i].toLowerCase() === label.toLowerCase()) { at = i; break; }
        }
        if (at > -1) {
            now.splice(at, 1);                 // pressed again: take it out
        } else if (now.length >= MAX) {
            sayLimit(now);                     // five is the limit, and it says so
            return;
        } else {
            now.push(label);
        }
        /* Written into the box before the request goes out, so the words are
           on screen while the results are still being fetched. */
        box.value = now.join(', ');
        var url = 'index.php?browse=1' + (now.length ? '&q=' + encodeURIComponent(box.value) : '');
        if (window.papelLoadResults) { window.papelLoadResults(url); }
        else { window.location.href = url; }
    };
})();

/* ===== A book pressed on the shelf =====
   Taken off the shelf into the book viewer (includes/book_viewer.php) rather
   than followed to the paper's page: the viewer's card has the Read button
   that goes there. A press with a modifier or a middle button is left alone,
   so "open in a new tab" still opens the paper in a new tab. Delegated, and
   in the capture phase so it runs before the handler below that loads
   whatever a link in the results column points at: the books are replaced
   with the column on every AJAX swap. */
/* What the viewer is told about one book: asked for the one pressed, and
   again for each one the viewer's arrows step to. */
function shelfBookInfo(el) {
    var data;
    try { data = JSON.parse(el.getAttribute('data-bv') || '{}'); } catch (err) { return null; }
    var slot = el.closest('.paper-item');
    var save = slot ? slot.querySelector(':scope > .save-paper') : null;
    var face = el.querySelector('.bk-face');
    var mark = el.querySelector('.bk-mark');
    return {
        paperId:  data.id,
        title:    data.title,
        authors:  data.authors,
        kicker:   data.kicker,
        abstract: data.abstract,
        href:     data.href || '',
        signIn:   !!data.signIn,
        program:  data.program || '',
        year:     data.year || '',
        kind:     data.kind || '',
        /* The Save button in the card says what the one on the shelf says, and
           only where there is one: a reader with no library has none. */
        saved:    save ? save.classList.contains('is-saved') : null,
        cover:    el.getAttribute('data-cover'),
        face:     face ? face.innerHTML : '',
        mark:     mark ? mark.textContent : ''
    };
}
function openShelfBook(el) {
    if (!window.papelBookViewer) { return false; }
    var info = shelfBookInfo(el);
    if (!info) { return false; }
    /* The shelf as it stands, in its order, for the viewer's previous and next:
       the page of results on view, which is the shelf the reader is looking at. */
    var items = Array.prototype.slice.call(document.querySelectorAll('.paper-list .shelf-book[data-bv]'));
    /* On the Bookshelf the book stood spine out, a quarter turn from facing:
       it comes off the shelf from there, turning to its cover. */
    var turn = document.documentElement.getAttribute('data-view') === 'bookshelf' ? 90 : -28;
    window.papelBookViewer.open(el, info, { items: items, index: items.indexOf(el), infoFor: shelfBookInfo, turn: turn });
    return true;
}
document.addEventListener('click', function (e) {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
    var view = document.documentElement.getAttribute('data-view');
    if (view !== 'shelf' && view !== 'bookshelf') { return; }
    var el = e.target.closest ? e.target.closest('.paper-list .shelf-book[data-bv]') : null;
    if (el && openShelfBook(el)) { e.preventDefault(); e.stopPropagation(); }
}, true);
/* The book a reader who may not open the paper sees is a span made
   pressable; Enter and Space are what a button answers to. */
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    var el = e.target.closest ? e.target.closest('.paper-list span.shelf-book[data-bv]') : null;
    if (el && openShelfBook(el)) { e.preventDefault(); }
});

// Pagination links, refresh, and the sidebar's "Clear filters" (X) button
// all live inside content that AJAX swaps replace, so they're bound here
// via delegation rather than direct listeners.
document.addEventListener('click', function (e) {
    var navLink = e.target.closest('#mainCol a[href], #filterCard a[href]');
    if (navLink) {
        var href = navLink.getAttribute('href');
        if (href && href.indexOf('index.php') === 0) {
            e.preventDefault();
            loadResults(href);
        }
    }
});

if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        loadResults('index.php?' + new URLSearchParams(new FormData(searchForm)).toString());
    });
}

// The sidebar is re-rendered on every swap, so the filter form is bound by
// delegation: a direct handler would be orphaned on a detached node.
document.addEventListener('submit', function (e) {
    var form = e.target.closest('#filterForm');
    if (!form) return;
    e.preventDefault();
    loadResults('index.php?' + new URLSearchParams(new FormData(form)).toString());
});
document.addEventListener('change', function (e) {
    var form = e.target.closest('#filterForm');
    if (!form) return;
    if (e.target.matches('input[type="radio"], select')) {
        loadResults('index.php?' + new URLSearchParams(new FormData(form)).toString());
    }
});

window.addEventListener('popstate', function () {
    loadResults(window.location.href, false);
});

/* Right-click is handled once for the whole site in includes/accessibility.php
   (via site_footer.php), which leaves links alone. A second blanket copy here
   put the browser's menu back out of reach on this page only. */

}); // end DOMContentLoaded
