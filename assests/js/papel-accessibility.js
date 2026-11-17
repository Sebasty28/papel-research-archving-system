(function () {
    var STORAGE_KEY = 'papel_a11y';
    var TAB_KEY     = 'papel_a11y_tab_hidden';   // above a phone: set means hidden
    var PHONE_KEY   = 'papel_a11y_tab_phone';    // on a phone: set means shown
    /* Signed-in readers have Settings; public readers have the footer. */
    var script = document.currentScript;
    var CAN_RESTORE = !!script && script.dataset.canRestore === '1';
    var SETTINGS_URL = script ? script.dataset.settingsUrl : '';

    /* The same query the stylesheet switches on, asked of the browser rather
       than worked out from innerWidth. The two have to agree about which side
       of the line the window is on, or at the boundary the switch would write
       one width's answer while the stylesheet was reading the other's. */
    var PHONE_Q  = '(max-width: 900px)';
    var phoneMQ  = window.matchMedia ? window.matchMedia(PHONE_Q) : null;
    function onPhone() { return phoneMQ ? phoneMQ.matches : window.innerWidth <= 900; }

    // Hidden or shown in another tab: follow the stored desktop choice here too.
    window.addEventListener('storage', function (e) {
        if (e.key === TAB_KEY) {
            document.documentElement.classList.toggle('a11y-tab-hidden', e.newValue === '1');
        }
        if (e.key === PHONE_KEY) {
            document.documentElement.classList.toggle('a11y-tab-phone', e.newValue === '1');
        }
        if (e.key === TAB_KEY || e.key === PHONE_KEY) {
            document.dispatchEvent(new CustomEvent('papel:a11y-tab', { detail: { hidden: null } }));
        }
    });

    /* ---- Move widget + guide to <html> so body { filter:... } never
            breaks position:fixed (CSS spec: a filtered element becomes
            a containing block for its fixed descendants).              ---- */
    var widget = document.getElementById('a11y-widget');
    var guide  = document.getElementById('a11y-reading-guide');
    if (widget) document.documentElement.appendChild(widget);
    if (guide)  document.documentElement.appendChild(guide);

    document.addEventListener('DOMContentLoaded', function () {
        var toggle    = document.getElementById('a11y-toggle');
        var menu      = document.getElementById('a11y-menu');
        var closeBtn  = document.getElementById('a11y-close');
        var resetBtn  = document.getElementById('a11y-reset');
        var fontVal   = document.getElementById('a11y-font-val');
        var fontDec   = document.getElementById('a11y-font-dec');
        var fontInc   = document.getElementById('a11y-font-inc');
        var guideEl   = document.getElementById('a11y-reading-guide');
        var guideBtn  = document.getElementById('a11y-reading-guide-btn');

        var fontSize = 100;
        var guideOn  = false;
        /* The palette the reader had before Dark Contrast was switched on, so
           it can be given back. Kept with the other preferences rather than in
           a variable alone: the setting survives a reload and so must the way
           out of it. */
        var prevColor = null;

        /* ---- Dark Contrast is the site's own dark palette ----
           Not a filter over the page: the same two attributes the profile
           menu, Quick Settings and Settings all set, and the same stored key,
           so every one of those controls shows the truth while this is on, and
           the pre-paint script in theme.php draws the next page dark with no
           flash. Writing the key is the point: a dark page restored a frame
           late is the white flash that block exists to prevent. */
        var PALETTE_KEY  = 'papel_color';
        var DARK_PALETTE = 'old-night';

        function palette() {
            var now = document.documentElement.getAttribute('data-color');
            if (now) { return now; }
            try { return localStorage.getItem(PALETTE_KEY) || 'classic'; } catch (e) { return 'classic'; }
        }
        function isDark() { return document.documentElement.getAttribute('data-mode') === 'dark'; }

        function setPalette(name) {
            var el = document.documentElement;
            el.setAttribute('data-color', name);
            el.setAttribute('data-mode', name === DARK_PALETTE ? 'dark' : 'light');
            try { localStorage.setItem(PALETTE_KEY, name); } catch (e) {}
            /* The other places the palette is shown. Asked for rather than
               assumed: a page without Quick Settings has neither. */
            if (window.papelSyncQuickSettings) { window.papelSyncQuickSettings(); }
            document.querySelectorAll('input[name="qs_color"]').forEach(function (i) {
                i.checked = i.value === name;
            });
        }

        function applyDarkContrast(on) {
            if (on) {
                /* Already dark because the reader chose it: remember that, so
                   switching this off does not take their own palette away. */
                if (prevColor === null) { prevColor = palette(); }
                if (!isDark()) { setPalette(DARK_PALETTE); }
            } else if (prevColor !== null) {
                var back = prevColor;
                prevColor = null;
                if (palette() !== back) { setPalette(back); }
            }
        }

        /* One switch per class, so the group rule, the restore and Reset all
           turn a setting off the same way. */
        function setSetting(cls, on) {
            document.body.classList.toggle(cls, on);
            if (cls === 'a11y-dark-contrast') { applyDarkContrast(on); }
        }

        /* ---- Load persisted prefs ---- */
        function loadPrefs() {
            try {
                var raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return;
                var prefs = JSON.parse(raw);

                // Font size
                if (prefs.fontSize && typeof prefs.fontSize === 'number') {
                    fontSize = prefs.fontSize;
                    applyFont();
                }

                /* What the palette was before Dark Contrast, read before the
                   classes below so switching it off has somewhere to go back
                   to. A device that stored the class under the old build has
                   no such answer, and falls back to the light palette rather
                   than to a dark site with no way out. */
                prevColor = (typeof prefs.prevColor === 'string') ? prefs.prevColor : null;

                // Active body classes
                if (Array.isArray(prefs.classes)) {
                    /* One of a group, even where an older build stored three:
                       the first wins and the rest are dropped here rather than
                       left to pile their filters on one another. */
                    var seen = {};
                    prefs.classes.forEach(function (cls) {
                        var btn = document.querySelector('.a11y-btn[data-cls="' + cls + '"]');
                        var group = btn && btn.dataset.group;
                        if (group) {
                            if (seen[group]) { return; }
                            seen[group] = cls;
                        }
                        if (cls === 'a11y-dark-contrast' && prevColor === null) {
                            prevColor = isDark() ? 'classic' : palette();
                        }
                        setSetting(cls, true);
                        if (btn) btn.classList.add('active');
                    });
                }

                // Reading guide
                if (prefs.guide && guideEl && guideBtn) {
                    guideOn = true;
                    guideEl.style.display = 'block';
                    guideBtn.classList.add('active');
                }
            } catch (e) {}
        }

        function savePrefs() {
            try {
                var classes = [];
                document.querySelectorAll('.a11y-btn[data-cls].active').forEach(function (b) {
                    classes.push(b.dataset.cls);
                });
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    fontSize:  fontSize,
                    classes:   classes,
                    guide:     guideOn,
                    prevColor: prevColor
                }));
            } catch (e) {}
        }

        /* ---- Font size ---- */
        function applyFont() {
            document.documentElement.style.fontSize = fontSize + '%';
            if (fontVal) fontVal.textContent = fontSize + '%';
        }

        function updateFont(delta) {
            fontSize = Math.max(70, Math.min(200, fontSize + delta));
            applyFont();
            savePrefs();
        }

        if (fontDec) fontDec.addEventListener('click', function () { updateFont(-10); });
        if (fontInc) fontInc.addEventListener('click', function () { updateFont(10); });

        /* ---- Panel open / close ---- */
        /* The panel opened to a fixed height regardless of where the tab was,
           so with the tab near an edge it ran off the screen, and its own
           scrollbar could not help, because the part that was cut off was
           outside the window rather than inside the panel. It now opens into
           whichever side has more room and is never taller than that room. */
        /* Set while the panel is open against a button rather than the tab. */
        var anchorEl = null;

        /* Whether the tab is away *at the width being read*, which is the only
           question anything asks: Settings labels its button from it, and the
           panel decides from it whether to open at the edge or against the
           button that called for it. Each width reads its own class, matching
           the pair of media queries in the stylesheet above. */
        function tabHidden() {
            var html = document.documentElement;
            return onPhone() ? !html.classList.contains('a11y-tab-phone')
                             :  html.classList.contains('a11y-tab-hidden');
        }

        /* Below the button when the whole panel fits there, otherwise on
           whichever side has more room; right edge in line with the button's,
           pulled in if that would run off either side of the window. */
        function positionAgainst(el) {
            var r = el.getBoundingClientRect();
            var gap = 8, margin = 12;
            /* Opening upwards it stops under the site's navbar rather than
               covering the bell and the avatar menu; the panel scrolls inside
               itself for the rest. Pages without the navbar (the sign-in
               pages) just keep the window margin. */
            var topEdge = margin;
            var header = document.getElementById('siteHeader');
            if (header) {
                var hb = header.getBoundingClientRect().bottom;
                if (hb > 0) topEdge = Math.max(topEdge, hb + gap);
            }
            var below = window.innerHeight - r.bottom - gap - margin;
            var above = r.top - gap - topEdge;
            var s = menu.style;
            s.maxHeight = '';
            var natural = menu.scrollHeight;
            var openDown = below >= natural || below >= above;

            var w = menu.offsetWidth;
            var left = Math.min(Math.max(r.right - w, margin), Math.max(margin, window.innerWidth - w - margin));
            s.left = left + 'px';
            s.right = 'auto';
            if (openDown) {
                s.top = (r.bottom + gap) + 'px';
                s.bottom = 'auto';
            } else {
                s.top = 'auto';
                s.bottom = (window.innerHeight - r.top + gap) + 'px';
            }
            s.maxHeight = Math.max(140, openDown ? below : above) + 'px';
        }

        function positionPanel() {
            if (anchorEl) { positionAgainst(anchorEl); return; }
            var r = widget.getBoundingClientRect();
            var gap = 12, margin = 12;
            var above = r.top - gap - margin;
            var below = window.innerHeight - r.bottom - gap - margin;
            var openDown = below >= above;

            widget.classList.toggle('anchor-top', openDown);
            menu.style.maxHeight = Math.max(140, openDown ? below : above) + 'px';
        }

        /* `anchor` is the button that asked, when it was not the tab. It only
           counts while the tab is hidden: with the tab showing, the panel
           opens beside it as always, so what is open stays next to the thing
           that opens and closes it. */
        function openPanel(anchor) {
            anchorEl = (anchor && anchor.getBoundingClientRect && tabHidden()) ? anchor : null;
            widget.classList.toggle('is-anchored', !!anchorEl);
            // Shown first: the anchored placement measures the panel itself.
            menu.classList.add('visible');
            positionPanel();
            toggle.setAttribute('aria-expanded', 'true');
            toggle.classList.add('panel-open');
        }
        function closePanel() {
            menu.classList.remove('visible');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.classList.remove('panel-open');
            if (anchorEl) {
                anchorEl = null;
                widget.classList.remove('is-anchored');
                menu.style.left = menu.style.right = menu.style.top = menu.style.bottom = '';
            }
        }
        // An anchored panel keeps to its button as the page moves under it.
        window.addEventListener('scroll', function () {
            if (anchorEl && menu.classList.contains('visible')) positionAgainst(anchorEl);
        }, { passive: true });

        if (toggle) toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.contains('visible') ? closePanel() : openPanel(null);
        });
        if (closeBtn) closeBtn.addEventListener('click', closePanel);

        /* Opening from somewhere else on the page: the Settings page has an
           "Open" button for it. Going through a synthetic click on the tab does
           not work: the original click carries on bubbling to <html>, where the
           close-on-outside-click listener below sees a click that did not come
           from inside the widget and shuts the panel again. Calling this
           instead skips that entirely. */
        window.papelAccessibility = {
            open:   openPanel,
            close:  closePanel,
            toggle: function (anchor) {
                menu.classList.contains('visible') ? closePanel() : openPanel(anchor);
            },
            /* The tab on the edge of the page, hidden or shown: Settings has
               the switch. Saved per browser, like everything else here, and
               per width: the press answers for the screen it was made on, so
               a phone is given the tab without a desktop losing it. The two
               keys are stored the opposite way round, each holding the answer
               that is *not* its width's default, so a browser that has never
               been asked carries nothing at all. */
            tabHidden: tabHidden,
            setTabHidden: function (hide) {
                hide = !!hide;
                var html = document.documentElement, key, store;
                if (onPhone()) {
                    key = PHONE_KEY; store = !hide;
                    html.classList.toggle('a11y-tab-phone', store);
                } else {
                    key = TAB_KEY;   store = hide;
                    html.classList.toggle('a11y-tab-hidden', store);
                }
                try {
                    if (store) localStorage.setItem(key, '1');
                    else       localStorage.removeItem(key);
                } catch (e) {}
                /* Placed while hidden, it was measured at no size at all; now
                   that it has one again, keep it inside the window. */
                if (!hide) restorePosition();
                /* Said aloud, so a page showing the switch (Settings) can keep
                   its label right when the tab is hidden from the tab itself. */
                document.dispatchEvent(new CustomEvent('papel:a11y-tab', { detail: { hidden: !!hide } }));
            }
        };

        /* Said once as soon as there is something to ask. A page carrying the
           switch (Settings) has its own script in the body, and this file comes
           in with the footer below it, so that page paints its button before
           papelAccessibility exists and has to fall back to reading a class,
           which only ever answers for the wider screen. On a phone that came
           out as "Hide bar" against a bar that was not there. */
        document.dispatchEvent(new CustomEvent('papel:a11y-tab', { detail: { hidden: tabHidden() } }));

        /* Close on outside click: listener on <html> since widget is there */
        document.documentElement.addEventListener('click', function (e) {
            if (menu && menu.classList.contains('visible') &&
                !widget.contains(e.target)) {
                closePanel();
            }
        });

        /* ---- Slide the tab along the edge ----
           It docks to a side and moves up and down; drag it past the middle of
           the window and it changes sides. Where it ended up is remembered per
           browser. A drag must not also fire the panel's click handler, so
           movement past a small threshold marks the gesture as a drag and the
           click that follows is swallowed once. */
        var DRAG_KEY = 'papel_a11y_pos';
        var dragging = false, didDrag = false;
        var startX = 0, startY = 0, startTop = 0;

        function widgetSize() {
            var r = widget.getBoundingClientRect();
            return { w: r.width || 44, h: r.height || 96 };
        }

        /* Park against one edge. `offset` runs along that edge (a distance
           down the page for the sides, across it for the top and bottom) and
           is clamped so the whole tab stays on screen. */
        function placeWidget(edge, offset, save) {
            widget.classList.remove('edge-left', 'edge-right', 'edge-top', 'edge-bottom');
            widget.classList.add('edge-' + edge);

            /* Measure last, not first. The class changes the tab's shape (down
               one edge it is a narrow vertical strip, along the top or bottom a
               wider horizontal one) and the offsets left over from wherever it
               was before change it again. Measured before those are cleared,
               the width came back short and the clamp below let the tab hang a
               few pixels past the edge, which is how a saved position could
               reopen looking cut off. */
            var style = widget.style;
            style.left = style.right = style.top = style.bottom = 'auto';
            style.transform = 'none';
            var s = widgetSize();

            if (edge === 'left' || edge === 'right') {
                offset = Math.min(Math.max(offset, 0), Math.max(0, window.innerHeight - s.h));
                style.top = offset + 'px';
                style[edge] = '0px';
            } else {
                offset = Math.min(Math.max(offset, 0), Math.max(0, window.innerWidth - s.w));
                style.left = offset + 'px';
                style[edge] = '0px';
            }

            // The panel flips toward whichever side of the page has room.
            widget.classList.toggle('anchor-left',
                widget.getBoundingClientRect().left + s.w / 2 < window.innerWidth / 2);

            if (save) {
                try { localStorage.setItem(DRAG_KEY, JSON.stringify({ edge: edge, offset: offset })); }
                catch (err) {}
            }
        }

        /* Whichever edge the tab is nearest when let go. */
        function nearestEdge(x, y) {
            var d = { left: x, right: window.innerWidth - x, top: y, bottom: window.innerHeight - y };
            var best = 'right';
            Object.keys(d).forEach(function (k) { if (d[k] < d[best]) best = k; });
            return best;
        }

        /* Wherever it was last dragged to, on every page and on every visit.
           The position lives in localStorage rather than the session, so it
           survives closing the browser as well as moving between pages: the
           tab someone put out of their way stays out of their way. */
        /* Below this the page is full-bleed, so there is no margin for the
           tab to sit in and every position is over something. */
        /* onPhone(), not a second reading of innerWidth: where the tab rests
           and whether it is there at all are the same question about the same
           line, and two ways of asking it disagree by the width of a
           scrollbar. */
        var isNarrow = onPhone;

        /* A spot chosen on a desktop means nothing on a phone. A drag that
           parked the tab halfway down a wide window leaves it sitting on top
           of the reading once the text runs edge to edge, which is exactly
           what it looked like. Narrow screens get their own resting place,
           low on the right where the text has already ended, and the saved
           desktop position is deliberately not overwritten, so it comes back
           the next time the same person opens the site on a real screen. */
        function dockForPhone() {
            placeWidget('right', 0, false);          // measure in final shape
            var s = widgetSize();
            placeWidget('right',
                Math.max(0, window.innerHeight - s.h - 88), false);
            /* Flush against the edge, as on a desktop: it is a tab, and the
               curve is on the face that looks into the page. It used to be
               held 12px off, which a circle needs (one flush against the side
               reads as clipped) and a tab does not. */
        }

        function restorePosition() {
            if (isNarrow()) { dockForPhone(); return; }
            var saved = null;
            try { saved = JSON.parse(localStorage.getItem(DRAG_KEY) || 'null'); } catch (err) {}

            if (saved && typeof saved.edge === 'string' && typeof saved.offset === 'number') {
                placeWidget(saved.edge, saved.offset, false);
                return;
            }
            // Saved when only the two sides existed.
            if (saved && typeof saved.side === 'string' && typeof saved.top === 'number') {
                placeWidget(saved.side, saved.top, true);
                return;
            }
            /* Older still: a free-floating left/top from when it could sit
               anywhere. Read once and pulled to the nearest edge. */
            if (saved && typeof saved.left === 'number' && typeof saved.top === 'number') {
                var sz = widgetSize();
                var edge = nearestEdge(saved.left + sz.w / 2, saved.top + sz.h / 2);
                placeWidget(edge,
                    (edge === 'left' || edge === 'right') ? saved.top : saved.left, true);
                return;
            }
            /* Never moved: the left edge, vertically centred, which is what
               the stylesheet also rests it at. Placed here rather than left to
               the stylesheet so the tab carries its edge-left class on a first
               visit: the class is what gives it the curve facing into the page,
               the shadow leaning the right way and the hover that grows away
               from the edge. Cleared first, because a placement may already
               have written an edge (a long press that wobbled into a drag, or
               the phone position once the window widens) and it would otherwise
               hold the tab wherever that left it. */
            widget.classList.remove('edge-left', 'edge-right', 'edge-top', 'edge-bottom');
            widget.style.left = widget.style.right = widget.style.top = widget.style.bottom = '';
            widget.style.transform = '';
            placeWidget('left', Math.max(0, (window.innerHeight - widgetSize().h) / 2), false);
        }

        /* Flip the panel toward whichever side of the screen has space. */
        function updateAnchors() {
            var r = widget.getBoundingClientRect();
            widget.classList.toggle('anchor-left', r.left + r.width / 2 < window.innerWidth / 2);
            widget.classList.toggle('anchor-top',  r.top + r.height / 2 < window.innerHeight / 2);
        }

        if (toggle && widget) {
            toggle.addEventListener('pointerdown', function (e) {
                if (e.button !== undefined && e.button !== 0) return;
                var r = widget.getBoundingClientRect();
                dragging = true;
                didDrag  = false;
                startX = e.clientX; startY = e.clientY;
                startTop = r.top;
                widget.classList.add('is-dragging');
                try { toggle.setPointerCapture(e.pointerId); } catch (err) {}
            });

            toggle.addEventListener('pointermove', function (e) {
                if (!dragging) return;
                var dx = e.clientX - startX, dy = e.clientY - startY;
                if (!didDrag && Math.abs(dx) + Math.abs(dy) < 4) return;  // still a click
                didDrag = true;
                e.preventDefault();
                closePanel();                       // panel would trail behind
                // Side follows the pointer; height follows the drag.
                var edge = nearestEdge(e.clientX, e.clientY);
                placeWidget(edge,
                    (edge === 'left' || edge === 'right') ? e.clientY - 24 : e.clientX - 40,
                    false);
            });

            function endDrag(e) {
                if (!dragging) return;
                dragging = false;
                widget.classList.remove('is-dragging');
                try { toggle.releasePointerCapture(e.pointerId); } catch (err) {}
                if (didDrag) {
                    var r = widget.getBoundingClientRect();
                    var edge = nearestEdge(r.left + r.width / 2, r.top + r.height / 2);
                    placeWidget(edge,
                        (edge === 'left' || edge === 'right') ? r.top : r.left, true);
                }
            }
            toggle.addEventListener('pointerup', endDrag);
            toggle.addEventListener('pointercancel', endDrag);

            /* Swallow the click that follows a drag, or a right-click that
               became one (Ctrl+click on a Mac is a left press), so the panel
               stays shut. */
            var swallowUntil = 0;
            toggle.addEventListener('click', function (e) {
                if (didDrag || Date.now() < swallowUntil) {
                    didDrag = false;
                    swallowUntil = 0;
                    e.stopImmediatePropagation();
                    e.preventDefault();
                }
            }, true);

            /* ---- Right-click: hide the tab ----
               The choice persists at either width. The footer restores it
               without an account; the Menu key and Shift+F10 reach this too. */
            var ctx = null, ctxItem = null;

            function buildCtx() {
                ctx = document.createElement('div');
                ctx.className = 'a11y-ctx';
                ctx.setAttribute('role', 'menu');
                ctx.setAttribute('aria-label', 'Accessibility tab');
                ctx.hidden = true;

                ctxItem = document.createElement('button');
                ctxItem.type = 'button';
                ctxItem.className = 'a11y-ctx-item';
                ctxItem.setAttribute('role', 'menuitem');
                ctxItem.setAttribute('aria-describedby', 'a11yCtxNote');
                // An eye struck through. Drawn, since the sign-in pages carry no icon font.
                ctxItem.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
                    + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                    + '<path d="M2 12s3.6-7 10-7c2 0 3.7.6 5.1 1.5M22 12s-3.6 7-10 7c-2 0-3.7-.6-5.1-1.5"/>'
                    + '<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M3 3l18 18"/></svg>';
                var label = document.createElement('span');
                label.textContent = 'Hide this tab';
                ctxItem.appendChild(label);

                var note = document.createElement('div');
                note.className = 'a11y-ctx-note';
                note.id = 'a11yCtxNote';
                note.setAttribute('aria-hidden', 'true');   // read out as the item's description
                note.textContent = CAN_RESTORE
                    ? 'Bring it back any time in Settings > Appearance.'
                    : 'Bring it back from the footer on any page.';

                ctx.appendChild(ctxItem);
                ctx.appendChild(note);
                // Beside the widget on <html>, out of reach of the filters on <body>.
                document.documentElement.appendChild(ctx);

                ctxItem.addEventListener('click', hideFromCtx);
                ctx.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        /* Kept here: the site's key handling takes focus off
                           whatever has it on Escape, which would leave it on
                           nothing rather than back on the tab. */
                        e.stopPropagation();
                        e.preventDefault();
                        closeCtx(true);
                    } else if (e.key === 'Tab') {
                        /* The menu is the last thing on the page, so the
                           browser's own Tab would leave the page for its
                           toolbar. Back to the tab, as Escape does. */
                        e.preventDefault();
                        closeCtx(true);
                    }
                });
            }

            /* Against the tab rather than the pointer, which a key press does
               not have: beside it on a side edge, facing into the page, and
               below or above it on the top or bottom edge. Placed from script,
               which the CSP allows; a style attribute it would drop. */
            function placeCtx() {
                /* Measured from the corner, where it has the whole window:
                   left where it was last placed, it shrinks to fit the room
                   to its right and comes out narrower than it really is. */
                ctx.style.left = '0px';
                ctx.style.top  = '0px';
                var r = toggle.getBoundingClientRect();
                var w = ctx.offsetWidth, h = ctx.offsetHeight;
                var vw = document.documentElement.clientWidth, vh = window.innerHeight;
                var gap = 8, margin = 8, left, top;
                if (widget.classList.contains('edge-top')) {
                    left = r.left + r.width / 2 - w / 2; top = r.bottom + gap;
                } else if (widget.classList.contains('edge-bottom')) {
                    left = r.left + r.width / 2 - w / 2; top = r.top - h - gap;
                } else {
                    left = (r.left + r.width / 2 > vw / 2) ? r.left - w - gap : r.right + gap;
                    top  = r.top + r.height / 2 - h / 2;
                }
                // The window's margin wins over the far edge, so a menu as wide
                // as a phone starts at its left edge rather than off it.
                ctx.style.left = Math.round(Math.max(margin, Math.min(left, vw - w - margin))) + 'px';
                ctx.style.top  = Math.round(Math.max(margin, Math.min(top,  vh - h - margin))) + 'px';
            }

            function openCtx() {
                if (!ctx) buildCtx();
                ctx.hidden = false;                  // shown first: placing it measures it
                placeCtx();
                ctxItem.focus();
            }
            function closeCtx(refocus) {
                if (!ctx || ctx.hidden) return;
                ctx.hidden = true;
                if (refocus && !tabHidden()) toggle.focus();
            }

            function tellHidden() {
                /* Signed in, a snackbar that keeps the link (includes/
                   flash_dismiss.php). Not for a visitor: the pages they see
                   it on, the sign-in pages, carry no icon font for the
                   snackbar's tick. The menu itself already said how it
                   comes back, so a page with neither helper says nothing. */
                if (CAN_RESTORE && window.papelFlashReady) {
                    var snack = document.createElement('div');
                    snack.className = 'js-flash-snack';
                    snack.appendChild(document.createTextNode('Accessibility tab hidden. Bring it back any time in '));
                    var a = document.createElement('a');
                    a.href = SETTINGS_URL;
                    a.textContent = 'Settings > Appearance';
                    snack.appendChild(a);
                    snack.appendChild(document.createTextNode('.'));
                    document.body.appendChild(snack);
                } else if (typeof window.papelNote === 'function') {
                    window.papelNote(CAN_RESTORE
                        ? 'Accessibility tab hidden. Bring it back any time in Settings > Appearance.'
                        : 'Accessibility tab hidden. Bring it back from the footer on any page.', true);
                }
            }

            function hideFromCtx() {
                closeCtx(false);
                closePanel();
                window.papelAccessibility.setTabHidden(true);
                // Focus was on the menu, which has gone; not left on a hidden tab either.
                if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
                tellHidden();
            }

            toggle.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                /* A long press on a touch screen, or Ctrl+click on a Mac, began
                   as a drag. Put that down, so letting go neither slides the
                   tab nor opens the panel under the menu. */
                if (dragging) {
                    dragging = false;
                    widget.classList.remove('is-dragging');
                    try { toggle.releasePointerCapture(e.pointerId); } catch (err) {}
                    /* A finger that wavered moved the tab a little before the
                       long press landed; put it back where it was kept. */
                    if (didDrag) { didDrag = false; restorePosition(); }
                    /* Only a press that began as a left one can still be
                       followed by a click. The right button and the Menu key
                       never are, and arming this for them ate the next real
                       click or Enter on the tab. */
                    swallowUntil = Date.now() + 600;
                }
                closePanel();
                openCtx();
            });

            // Anything pressed elsewhere, a scroll or a resize puts it away.
            document.addEventListener('pointerdown', function (e) {
                if (ctx && !ctx.hidden && !ctx.contains(e.target)) closeCtx(false);
            }, true);
            window.addEventListener('scroll', function () { closeCtx(false); }, { capture: true, passive: true });
            window.addEventListener('resize', function () { closeCtx(false); });
            window.addEventListener('blur', function () { closeCtx(false); });

            /* Put it back on its edge at the size it actually is. Passed
               save:false, so a position squeezed to fit a small window is not
               written back over the one the person chose: open the same page
               on a big screen again and the tab returns to where they put it. */
            function reclamp() {
                var edge = ['left', 'right', 'top', 'bottom'].filter(function (e) {
                    return widget.classList.contains('edge-' + e);
                })[0];
                if (!edge) { updateAnchors(); return; }
                var along = (edge === 'left' || edge === 'right')
                    ? parseFloat(widget.style.top) : parseFloat(widget.style.left);
                placeWidget(edge, along || 0, false);
            }

            /* Keep it on the edge, and on screen, when the window is resized. */
            var wasNarrow = isNarrow();
            window.addEventListener('resize', function () {
                if (menu.classList.contains('visible')) positionPanel();
                /* Crossing the phone breakpoint changes which position is the
                   right one, so re-decide rather than just re-clamping the
                   old one. */
                if (isNarrow() !== wasNarrow) {
                    wasNarrow = isNarrow();
                    restorePosition();
                    /* The two widths keep separate answers about whether the
                       tab is there, so crossing the line changes what
                       tabHidden() says without anything having been switched.
                       Settings is showing that answer as a button label. */
                    document.dispatchEvent(new CustomEvent('papel:a11y-tab', { detail: { hidden: tabHidden() } }));
                    return;
                }
                reclamp();
            });

            /* And once the icon font has arrived. Until the glyph exists the
               tab is measured with fallback metrics and comes out narrower
               than it ends up, so a position clamped against that measurement
               let it hang past the edge, which is how a saved position could
               reopen looking cut off. */
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(reclamp).catch(function () {});
            }

            toggle.title = 'Accessibility options (drag it to any edge, right-click to hide it)';
            restorePosition();
        }

        /* ---- Toggle body classes ---- */
        document.querySelectorAll('.a11y-btn[data-cls]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cls = this.dataset.cls;
                var on  = !this.classList.contains('active');
                var group = this.dataset.group;
                /* A group is a radio set: whatever else in it was on goes off
                   first, and goes off properly, through setSetting, so Dark
                   Contrast hands the palette back on its way out. */
                if (group && on) {
                    document.querySelectorAll('.a11y-btn[data-group="' + group + '"].active')
                        .forEach(function (other) {
                            other.classList.remove('active');
                            if (other.dataset.cls) { setSetting(other.dataset.cls, false); }
                        });
                }
                setSetting(cls, on);
                this.classList.toggle('active', on);
                savePrefs();
            });
        });

        /* ---- Reading guide ---- */
        if (guideBtn) {
            guideBtn.addEventListener('click', function () {
                guideOn = !guideOn;
                guideBtn.classList.toggle('active', guideOn);
                if (guideEl) guideEl.style.display = guideOn ? 'block' : 'none';
                savePrefs();
            });
        }
        if (guideEl) {
            document.addEventListener('mousemove', function (e) {
                if (guideOn) guideEl.style.top = (e.clientY - 2) + 'px';
            });
        }

        /* ---- Reset ---- */
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                fontSize = 100;
                applyFont();
                document.querySelectorAll('.a11y-btn.active').forEach(function (b) {
                    /* Through setSetting, or Reset would leave the page dark
                       with nothing on the panel saying why. */
                    if (b.dataset.cls) { setSetting(b.dataset.cls, false); }
                    b.classList.remove('active');
                });
                guideOn = false;
                if (guideEl) guideEl.style.display = 'none';
                try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
            });
        }

        /* ---- Right-click ----
           Kept off the page at large, but never off a link: "Open link in new
           tab", "Open in new window" and "Copy link address" are how people
           read a repository (a paper in one tab, the list still in another)
           and there is nothing to protect on a link that its own href does not
           already say. The menu on a notification (includes/notif_actions.php)
           is that page's own and still takes precedence. */
        document.addEventListener('contextmenu', function (e) {
            if (e.target.closest && e.target.closest('a[href]')) { return; }
            e.preventDefault();
        });

        /* ---- The palette can also be changed from outside this panel ----
           The profile menu, Quick Settings and Settings all set it, and a
           reader who turns the site light again from one of those has turned
           Dark Contrast off whether or not they were looking at this panel.
           Left alone, the button went on claiming a dark page that was no
           longer there, and pressing it twice was the only way to clear it.
           Watched on <html>, which is where every one of those writes. */
        new MutationObserver(function () {
            var btn = document.querySelector('.a11y-btn[data-cls="a11y-dark-contrast"]');
            if (!btn || !btn.classList.contains('active') || isDark()) { return; }
            btn.classList.remove('active');
            document.body.classList.remove('a11y-dark-contrast');
            /* The reader's own choice is the one on the page now, so there is
               nothing left to put back. */
            prevColor = null;
            savePrefs();
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-mode', 'data-color'] });

        /* ---- Restore saved preferences ---- */
        loadPrefs();
    });
}());
