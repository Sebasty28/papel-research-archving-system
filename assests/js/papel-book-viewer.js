(function () {
    if (window.papelBookViewer) { return; }
    var dlg = document.getElementById('bookViewer');
    /* No <dialog> (a very old browser): the pages keep their plain links. */
    if (!dlg || typeof dlg.showModal !== 'function') { return; }

    var mover = dlg.querySelector('.bv-mover');
    var book  = dlg.querySelector('.bv-book');
    var front = dlg.querySelector('.bv-front');
    var spine = dlg.querySelector('.bv-spine-text');
    var card  = dlg.querySelector('.bv-card');
    var slot  = dlg.querySelector('.bv-slot-book');
    var hintW = dlg.querySelector('.bv-hint-words');
    var prevB = dlg.querySelector('.bv-prev');
    var nextB = dlg.querySelector('.bv-next');
    var saveB = dlg.querySelector('.bv-save');
    /* The shelf the book came off: the page's own books in their order, which
       one this is, and how to say what each one is. */
    var shelf = null;
    /* The book that is out of its place on that shelf while it is held here. */
    var taken = null;

    var still = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var touch = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);
    var HINT = touch ? 'Drag to turn the book · tap it to see the details'
                     : 'Drag to turn the book · click it to see the details';

    /* Where the book is and how it is turned. Every frame writes these two
       transforms and nothing else: one on the mover (where, how large) and one
       on the book (its own perspective, and the turn). */
    var at   = { tx: 0, ty: 0, s: 1, rx: 8, ry: -28 };
    var W    = 300;
    var mode = 'closed';      // 'inspect' | 'details' | 'moving' | 'leaving' | 'closed'
    /* The angle the book stood at on the shelf it came off, so it can be put
       back the way round it was: Hardbounds holds its books at -28deg and
       Bookshelf stands them spine out at 90. */
    var stood = -28;
    var info = null;
    var tweenId = 0, spinId = 0;
    var swallowClick = false;

    var HELD   = { rx: 8, ry: -28 };      // the angle it is held up at
    var LIFT   = -18;                      // a little above the middle, clear of the hint
    var SET_RY = -24;                      // the angle it lies at in the card, as My Library's did

    function apply() {
        mover.style.transform = 'translate(' + at.tx.toFixed(2) + 'px,' + at.ty.toFixed(2) + 'px) scale(' + at.s.toFixed(4) + ')';
        book.style.transform  = 'perspective(' + Math.round(W * 7) + 'px) rotateX(' + at.rx.toFixed(2) + 'deg) rotateY(' + at.ry.toFixed(2) + 'deg)';
    }

    /* As large as the window allows, up to a size a bound paper might be held
       at: never wider than most of a phone, never taller than the window less
       room for the hint and the close. Width to height is the shelf's 108:156,
       and the thickness a thick thesis's. */
    function size() {
        W = Math.round(Math.max(140, Math.min(300, window.innerWidth * 0.62, (window.innerHeight - 150) / 1.444)));
        book.style.setProperty('--w', W + 'px');
        book.style.setProperty('--h', Math.round(W * 1.444) + 'px');
        book.style.setProperty('--d', Math.round(W * 0.17) + 'px');
        book.style.setProperty('--bk-scale', (W / 108).toFixed(4));
        mover.style.setProperty('--w', W + 'px');
        mover.style.setProperty('--h', Math.round(W * 1.444) + 'px');
    }

    /* The centre of an element, measured from the middle of the window, which
       is where the mover's origin is. */
    function placeOf(el, width) {
        var r = el.getBoundingClientRect();
        var d = dlg.open ? dlg.getBoundingClientRect() : null;
        var cx = d && d.width ? d.left + d.width / 2 : window.innerWidth / 2;
        var cy = d && d.height ? d.top + d.height / 2 : window.innerHeight / 2;
        return {
            tx: r.left + r.width / 2 - cx,
            ty: r.top + r.height / 2 - cy,
            s:  (width || r.width) / W
        };
    }

    function ease(k) { return k < .5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2; }

    /* Out of its place on the shelf, and the one before it back in its own.
       One at a time, because the arrows walk along the shelf: a book stepped
       past has been put back by then. */
    function takeDown(el) {
        if (taken === el) { return; }
        if (taken) { taken.classList.remove('bv-taken'); }
        taken = el || null;
        if (taken) { taken.classList.add('bv-taken'); }
    }

    function stopSpin() { if (spinId) { cancelAnimationFrame(spinId); spinId = 0; } }

    /* From where it is to `to`, over `ms`; the turn goes the short way round,
       so a book spun three times does not unwind all three on its way down. */
    function tween(to, ms, done) {
        stopSpin();
        if (tweenId) { cancelAnimationFrame(tweenId); tweenId = 0; }
        var from = { tx: at.tx, ty: at.ty, s: at.s, rx: at.rx, ry: at.ry };
        if (to.ry !== undefined) {
            var d = ((to.ry - from.ry) % 360 + 540) % 360 - 180;
            to.ry = from.ry + d;
        }
        if (still) {
            for (var k in to) { at[k] = to[k]; }
            apply();
            if (done) { done(); }
            return;
        }
        var t0 = performance.now();
        function frame(now) {
            var p = Math.min(1, (now - t0) / ms), e = ease(p);
            for (var key in to) { at[key] = from[key] + (to[key] - from[key]) * e; }
            apply();
            if (p < 1) { tweenId = requestAnimationFrame(frame); }
            else { tweenId = 0; if (done) { done(); } }
        }
        tweenId = requestAnimationFrame(frame);
    }

    /* Once round, slowly, when it first arrives: the book showing itself, back
       and spine and all. Anything the reader does stops it. */
    function showRound() {
        if (still) { return; }
        var from = at.ry, t0 = performance.now(), ms = 2600;
        function frame(now) {
            var p = Math.min(1, (now - t0) / ms);
            at.ry = from + 360 * ease(p);
            apply();
            spinId = p < 1 ? requestAnimationFrame(frame) : 0;
        }
        spinId = requestAnimationFrame(frame);
    }

    /* Let go while moving: it carries on, slowing, the way a thing spun by hand
       does. */
    function glide(vx, vy) {
        if (still) { return; }
        stopSpin();
        function frame() {
            vx *= 0.94; vy *= 0.9;
            at.ry += vx;
            at.rx = Math.max(-50, Math.min(50, at.rx + vy));
            apply();
            spinId = (Math.abs(vx) > 0.04 || Math.abs(vy) > 0.04) ? requestAnimationFrame(frame) : 0;
        }
        spinId = requestAnimationFrame(frame);
    }

    /* ---- What the card says ---- */
    function text(sel, value) { dlg.querySelector(sel).textContent = value || ''; }

    function fillCard() {
        text('.bv-kicker',   info.kicker);
        text('.bv-title',    info.title);
        text('.bv-authors',  info.authors);
        text('.bv-abstract', info.abstract);
        text('.bv-note',     info.note);

        var acts = dlg.querySelector('.bv-actions');
        acts.textContent = '';
        if (info.href) {
            var read = document.createElement('a');
            read.className = 'btn-sm-maroon';
            read.href = info.href;
            read.appendChild(icon('menu_book', 'mi-18'));
            read.appendChild(document.createTextNode(' Read paper'));
            acts.appendChild(read);
        } else if (info.signIn) {
            /* The site's own sign-in panel, opened by the delegated handler in
               site_footer.php; this window closes first (see the click handler
               below), or the panel would open underneath it. */
            var sign = document.createElement('button');
            sign.type = 'button';
            sign.className = 'btn-sm-maroon js-open-modal';
            sign.setAttribute('data-role', 'student');
            /* "now": no flight home, since the panel this opens would come up
               under the dialog while the book was still travelling. */
            sign.setAttribute('data-bv-close', 'now');
            sign.appendChild(icon('login', 'mi-18'));
            sign.appendChild(document.createTextNode(' Sign in to read'));
            acts.appendChild(sign);
        }
        if (info.saved === true || info.saved === false) {
            /* The shared Save button; includes/save_paper.php answers for it
               like any other, and tells every copy of it on the page. */
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'save-paper is-labelled' + (info.saved ? ' is-saved' : '');
            btn.setAttribute('data-save-paper', info.paperId);
            btn.setAttribute('aria-pressed', info.saved ? 'true' : 'false');
            btn.title = info.saved ? 'Remove from My Library' : 'Save to My Library';
            btn.appendChild(icon('bookmark'));
            var word = document.createElement('span');
            word.className = 'save-paper-label';
            word.textContent = info.saved ? 'Saved' : 'Save';
            btn.appendChild(word);
            acts.appendChild(btn);
        }
    }

    /* The bookmark on the held-up book, pointed at the paper in the window.
       It is painted here only as it is opened: from then on the shared script
       owns it like any other copy of itself, since it carries the same
       data-save-paper (includes/save_paper.php). A reader with no library
       (info.saved is neither true nor false) has none, and it is unhooked
       rather than left pointing at the last paper. */
    function fillSave() {
        var has = (info.saved === true || info.saved === false);
        saveB.hidden = !has;
        if (!has) { saveB.removeAttribute('data-save-paper'); return; }
        var say = info.saved ? 'Remove from My Library' : 'Save to My Library';
        saveB.setAttribute('data-save-paper', info.paperId);
        saveB.classList.toggle('is-saved', !!info.saved);
        saveB.classList.remove('is-failed');
        saveB.removeAttribute('aria-busy');
        saveB.setAttribute('aria-pressed', info.saved ? 'true' : 'false');
        saveB.setAttribute('aria-label', say);
        saveB.title = say;
    }

    function icon(name, extra) {
        var s = document.createElement('span');
        s.className = 'material-symbols-outlined' + (extra ? ' ' + extra : '');
        s.setAttribute('aria-hidden', 'true');
        s.textContent = name;
        return s;
    }

    /* The spine: programme at the head, the title, the year at the foot, each
       between a pair of bars, read from "BSIT · 2025" on the cover's foot. */
    function fillSpine() {
        spine.textContent = '';
        var parts = String(info.mark || '').split('·').map(function (p) { return p.trim(); })
                                         .filter(function (p) { return p !== ''; });
        function piece(cls, words) {
            var s = document.createElement('span');
            s.className = cls;
            s.textContent = words;
            spine.appendChild(s);
        }
        function bars() { var b = document.createElement('span'); b.className = 'bv-bars'; spine.appendChild(b); }
        if (parts[0]) { piece('bv-spine-end', parts[0]); bars(); }
        piece('bv-spine-title', info.title);
        if (parts[1]) { bars(); piece('bv-spine-end', parts[1]); }
    }

    /* The front, stamped in full. The shelf's book is 108px wide and says
       what fits ("Baliza et al.", "BSIE · 2026", a title cut after six
       lines); held up, the book has the room to say all of it, so it is
       stamped again from the details rather than copied: the whole title,
       every author, and the programme by its full name. Anything across the
       head of the shelf's cover (a request, a status) is kept. Where a page
       has not given the details, the copied face stands. */
    function fillFront() {
        if (!info.title || (info.authors === undefined && info.program === undefined)) { return; }
        var band = front.querySelector('.bk-band');
        var bandText = band ? band.textContent : '';
        front.textContent = '';
        function stamp(parent, cls, words) {
            if (!words) { return null; }
            var s = document.createElement('span');
            s.className = cls;
            s.textContent = words;
            parent.appendChild(s);
            return s;
        }
        stamp(front, 'bk-band', bandText);
        stamp(front, 'bk-title', info.title);
        stamp(front, 'bk-kind', info.kind);
        var foot = document.createElement('span');
        foot.className = 'bk-foot';
        if (info.authors) {
            stamp(foot, 'bk-by-label', 'Presented by');
            stamp(foot, 'bk-by', info.authors);
        }
        stamp(foot, 'bk-mark bk-program', info.program);
        stamp(foot, 'bk-mark bk-year', info.year);
        front.appendChild(foot);
    }

    /* All of it has to fit the board, and a paper can have a title of thirty
       words and eight authors. So the stamping is set at its own size and
       made smaller, a step at a time, until nothing runs off the bottom:
       small type is the price of nothing being cut. Measured on the book
       held up; set down into the card it is the same book made smaller, so
       it still fits. */
    function fit(el, prop, floor) {
        var f = 1;
        el.style.setProperty(prop, '1');
        while (f > floor && (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1)) {
            f = Math.round((f - .04) * 100) / 100;
            el.style.setProperty(prop, String(f));
        }
    }
    function fitAll() {
        fit(front, '--fit', .3);
        var title = spine.querySelector('.bv-spine-title');
        if (title) { fit(title, '--fit', .35); }
    }

    /* ---- Where on the shelf it is ---- */
    function showPlace() {
        var many = shelf && shelf.items.length > 1;
        prevB.hidden = !many || shelf.index <= 0;
        nextB.hidden = !many || shelf.index >= shelf.items.length - 1;
        hintW.textContent = (many ? (shelf.index + 1) + ' of ' + shelf.items.length + ' · ' : '') + HINT;
    }

    /* Everything that is about one paper rather than about the window: its
       cover, its spine and its card. */
    function dress(data) {
        info = data || {};
        info.paperId = String(info.paperId || '');
        /* The same cover as the book pressed, copied face and all; held up, it
           is stamped again in full (fillFront, once the dialog has a size). */
        book.setAttribute('data-cover', info.cover || 'other');
        front.innerHTML = info.face || '';
        fillSpine();
        fillCard();
        fillSave();
    }

    /* ---- Previous and next ----
       The book on view slides out to one side, turning as it goes, and the
       next comes in from the other, held up the way the first one was. Only
       while a book is held up: the card is about one paper. */
    function step(dir) {
        if (mode !== 'inspect' || !shelf) { return; }
        var to = shelf.index + dir;
        if (to < 0 || to >= shelf.items.length) { return; }
        var data = shelf.infoFor(shelf.items[to]);
        if (!data) { return; }
        mode = 'moving';
        stopSpin();
        var away = Math.round(W * 1.15);
        mover.classList.add('is-away');
        tween({ tx: -dir * away, ry: at.ry - dir * 40 }, 220, function () {
            shelf.index = to;
            takeDown(shelf.items[to]);
            dress(data);
            fillFront();
            fitAll();
            showPlace();
            at = { tx: dir * away, ty: LIFT, s: 1, rx: HELD.rx, ry: HELD.ry + dir * 40 };
            apply();
            mover.classList.remove('is-away');
            tween({ tx: 0, ry: HELD.ry }, 340, function () {
                mode = 'inspect';
                book.focus({ preventScroll: true });
            });
        });
    }

    /* ---- Taking it off the shelf ----
       nav, where a page gives it: { items: its books in order, index: which
       one this is, infoFor: what to say about any of them, turn: the angle
       the book stood at }. Without items there are no arrows; without a turn
       it starts as Hardbounds holds its books, -28deg. The Bookshelf view
       says 90: the book stood spine out, and comes off the shelf edge on,
       turning to its cover as it is pulled out, which is what that view is. */
    function open(source, data, nav) {
        dlg.classList.remove('is-settled');
        slot.textContent = '';
        slot.removeAttribute('role');
        slot.removeAttribute('tabindex');
        shelf = (nav && nav.items && nav.items.length && typeof nav.infoFor === 'function')
              ? { items: nav.items, index: Math.max(0, nav.index | 0), infoFor: nav.infoFor }
              : null;
        dress(data);
        showPlace();
        mover.classList.remove('is-away');
        size();

        /* Where it was, measured before the page is held: taking the
           scrollbar away moves everything a little to the right. */
        /* By its height where the book is taller than this one's proportion
           (Bookshelf stands taller books), or the flight would start with a
           book shorter than the spine it is pulled from. */
        var from = source
            ? placeOf(source, Math.max(source.offsetWidth || 0, (source.offsetHeight || 0) / 1.444) || null)
            : { tx: 0, ty: 40, s: .4 };
        /* After it has been measured, and before the first frame: the book in
           the window is this book, so the shelf shows the gap it left. */
        takeDown(source);

        dlg.classList.remove('is-details');
        dlg.classList.add('is-inspect');
        document.documentElement.classList.add('bv-locked');
        dlg.showModal();
        /* Once it is open, since a closed dialog has no size to measure, and
           before the first frame is drawn, so nothing is seen at the wrong size. */
        fillFront();
        fitAll();

        stood = (nav && typeof nav.turn === 'number') ? nav.turn : -28;
        at = { tx: from.tx, ty: from.ty, s: from.s, rx: 0, ry: stood };
        apply();
        mode = 'moving';
        book.focus({ preventScroll: true });
        tween({ tx: 0, ty: LIFT, s: 1, rx: HELD.rx, ry: HELD.ry }, 620, function () {
            mode = 'inspect';
            showRound();
        });
    }

    /* ---- Setting it down into the card, and picking it up again ---- */
    function syncCardCover() {
        if (mode === 'details' && window.innerWidth <= 700) {
            if (!slot.firstChild) {
                var cover = front.cloneNode(true);
                cover.setAttribute('data-cover', book.getAttribute('data-cover') || 'other');
                slot.replaceChildren(cover);
            }
            slot.setAttribute('role', 'button');
            slot.setAttribute('tabindex', '0');
            slot.setAttribute('aria-label', 'Pick up the book');
            dlg.classList.add('is-settled');
        } else {
            dlg.classList.remove('is-settled');
            slot.textContent = '';
            slot.removeAttribute('role');
            slot.removeAttribute('tabindex');
            slot.removeAttribute('aria-label');
        }
    }
    function setDown() {
        mode = 'moving';
        dlg.classList.remove('is-inspect');
        dlg.classList.add('is-details');
        var to = placeOf(slot);
        tween({ tx: to.tx, ty: to.ty, s: to.s, rx: 0, ry: SET_RY }, 640, function () {
            mode = 'details';
            syncCardCover();
            var first = dlg.querySelector('.bv-actions a, .bv-actions button') || dlg.querySelector('.bv-close');
            if (first) { first.focus({ preventScroll: true }); }
        });
    }
    function pickUp() {
        mode = 'moving';
        syncCardCover();
        dlg.classList.remove('is-details');
        dlg.classList.add('is-inspect');
        tween({ tx: 0, ty: LIFT, s: 1, rx: HELD.rx, ry: HELD.ry }, 560, function () {
            mode = 'inspect';
            book.focus({ preventScroll: true });
        });
    }
    /* In the card the book lies over its slot, so it follows the slot when the
       window changes size or the card scrolls (a phone's card is taller than
       the window). */
    function settle() {
        if (mode !== 'details') { return; }
        var to = placeOf(slot);
        at.tx = to.tx; at.ty = to.ty; at.s = to.s;
        apply();
    }
    card.addEventListener('scroll', settle, { passive: true });
    slot.addEventListener('click', function () { if (mode === 'details' && window.innerWidth <= 700) pickUp(); });
    slot.addEventListener('keydown', function (e) {
        if (mode !== 'details' || window.innerWidth > 700 || (e.key !== 'Enter' && e.key !== ' ')) return;
        e.preventDefault();
        pickUp();
    });
    window.addEventListener('resize', function () {
        if (mode === 'closed') { return; }
        size();
        fitAll();
        if (mode === 'details') { settle(); syncCardCover(); }
        else if (mode === 'inspect') { at.tx = 0; at.ty = LIFT; at.s = 1; apply(); }
    });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            if (mode === 'details') settle();
        });
    }

    /* ---- Turning it by hand ---- */
    var drag = null;
    book.addEventListener('pointerdown', function (e) {
        /* Only while it is being looked at or is lying in the card: not while
           it is flying in, stepping along the shelf or on its way home. */
        if (e.button !== 0 || (mode !== 'inspect' && mode !== 'details')) { return; }
        stopSpin();
        drag = { x: e.clientX, y: e.clientY, x0: e.clientX, y0: e.clientY, t: performance.now(), vx: 0, vy: 0, moved: false };
        book.classList.add('is-held');
        e.preventDefault();
    });
    /* On the document, never captured: see the head of this file. */
    document.addEventListener('pointermove', function (e) {
        if (!drag) { return; }
        var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
        if (Math.abs(e.clientX - drag.x0) + Math.abs(e.clientY - drag.y0) > 6) { drag.moved = true; }
        if (mode !== 'inspect' || !drag.moved) { drag.x = e.clientX; drag.y = e.clientY; return; }
        var now = performance.now(), dt = Math.max(8, now - drag.t);
        at.ry += dx * 0.5;
        at.rx = Math.max(-50, Math.min(50, at.rx - dy * 0.3));
        /* Degrees a frame, for the glide when it is let go. */
        drag.vx = dx * 0.5 / dt * 16;
        drag.vy = -dy * 0.3 / dt * 16;
        drag.x = e.clientX; drag.y = e.clientY; drag.t = now;
        apply();
    });
    document.addEventListener('pointerup', function () {
        if (!drag) { return; }
        var d = drag;
        drag = null;
        book.classList.remove('is-held');
        if (!d.moved) {
            if (mode === 'inspect') { setDown(); } else if (mode === 'details') { pickUp(); }
            return;
        }
        /* The click this drag is about to raise lands wherever the pointer was
           let go, the backdrop included, which would close the window. */
        swallowClick = true;
        setTimeout(function () { swallowClick = false; }, 0);
        /* A hand that stopped before letting go has nothing to carry on. */
        if (performance.now() - d.t > 80) { return; }
        if (mode === 'inspect') { glide(d.vx, d.vy); }
    });
    document.addEventListener('pointercancel', function () {
        drag = null;
        book.classList.remove('is-held');
    });

    book.addEventListener('keydown', function (e) {
        if (mode === 'moving' || mode === 'leaving') { return; }
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (mode === 'inspect') { setDown(); } else if (mode === 'details') { pickUp(); }
            return;
        }
        if (mode !== 'inspect') { return; }
        var turn = { ArrowLeft: [0, -20], ArrowRight: [0, 20], ArrowUp: [10, 0], ArrowDown: [-10, 0] }[e.key];
        if (!turn) { return; }
        e.preventDefault();
        stopSpin();
        tween({ rx: Math.max(-50, Math.min(50, at.rx + turn[0])), ry: at.ry + turn[1] }, 220);
    });

    /* Page Up and Page Down step along the shelf. Not the arrows, which turn
       the book when it has the focus, as they always have. */
    dlg.addEventListener('keydown', function (e) {
        if (mode === 'leaving') { return; }
        if (e.key === 'PageUp')   { e.preventDefault(); step(-1); }
        if (e.key === 'PageDown') { e.preventDefault(); step(1); }
    });

    /* ---- Putting it away ----
       The flight run backwards: the book goes home to the gap it left on the
       shelf, turning back to the way round it stood, and the window closes
       when it lands - so the paper is seen being put back where it was taken
       from, as it was seen being taken out. The shelf book is uncovered by
       the close handler below, at the moment the flying one arrives over it.

       Where there is nothing to fly to (no shelf book, or one the page has
       since taken away, which My Library does) the window simply closes. */
    function goHome() {
        if (mode === 'leaving' || mode === 'closed') { return; }
        if (mode === 'details') { mode = 'leaving'; syncCardCover(); }
        var home = (taken && taken.isConnected && taken.getClientRects().length)
            ? placeOf(taken, Math.max(taken.offsetWidth || 0, (taken.offsetHeight || 0) / 1.444) || null)
            : null;
        if (!home) { dlg.close(); return; }
        mode = 'leaving';
        drag = null;
        book.classList.remove('is-held');
        dlg.classList.add('is-leaving');
        tween({ tx: home.tx, ty: home.ty, s: home.s, rx: 0, ry: stood }, 460, function () {
            dlg.close();
        });
    }

    dlg.addEventListener('click', function (e) {
        if (swallowClick) { e.preventDefault(); e.stopPropagation(); swallowClick = false; return; }
        if (mode === 'leaving') { return; }
        var stepper = e.target.closest ? e.target.closest('[data-bv-step]') : null;
        if (stepper) { step(parseInt(stepper.getAttribute('data-bv-step'), 10)); return; }
        var closer = e.target.closest ? e.target.closest('[data-bv-close]') : null;
        /* The dialog has no padding and the layer takes no pointer, so a click
           that lands on the dialog itself is a click on the space round the
           book or the card. */
        if (!closer && e.target !== dlg) { return; }
        /* data-bv-close="now" is for a control that does something else as
           soon as this window is gone: the "Sign in to read" button opens the
           site's own panel, and a panel opened half a second before a
           top-layer dialog closes opens underneath it. */
        if (closer && closer.getAttribute('data-bv-close') === 'now') { dlg.close(); }
        else { goHome(); }
    });
    /* Escape. The dialog's own answer is to close at once; it is the same act
       as pressing the X, so it takes the same flight. Pressed again while the
       book is on its way, it is let through and the window goes. */
    dlg.addEventListener('cancel', function (e) {
        if (mode === 'leaving') { return; }
        e.preventDefault();
        goHome();
    });
    dlg.addEventListener('close', function () {
        stopSpin();
        if (tweenId) { cancelAnimationFrame(tweenId); tweenId = 0; }
        drag = null;
        mode = 'closed';
        /* Back on the shelf, in the place it was taken from: at this moment
           the flying book is over that gap, so the two change places without
           anything being seen to move. */
        takeDown(null);
        dlg.classList.remove('is-leaving');
        document.documentElement.classList.remove('bv-locked');
        var id = info ? info.paperId : '';
        info = null;
        shelf = null;
        mover.classList.remove('is-away');
        /* Unhooked, or a paper saved elsewhere on the page would paint a
           button nobody can see, and its is-saved would stand the ribbon up
           on whatever book is opened next. */
        saveB.hidden = true;
        saveB.removeAttribute('data-save-paper');
        saveB.classList.remove('is-saved', 'is-failed');
        /* For a page that has something to tidy once the window is gone: My
           Library takes a book off its shelf that was unsaved while open. */
        document.dispatchEvent(new CustomEvent('papel:book-viewer-closed', { detail: { paperId: id } }));
    });

    window.papelBookViewer = {
        open: open,
        /* Whether this paper's book is the one in the window just now. */
        isOpenFor: function (id) { return dlg.open && info !== null && info.paperId === String(id); }
    };
})();
