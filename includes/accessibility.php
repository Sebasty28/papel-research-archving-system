<!-- Accessibility Widget -->
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Prevent text selection / inspection */
body {
    -webkit-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}

/* ===== Widget shell =====
   JS moves this to <html> so body { filter:... } never breaks
   the position:fixed containing block.
   ============================= */
#a11y-widget {
    position: fixed;
    top: 50%;
    right: 0;
    transform: translateY(-50%);
    z-index: 2147483647;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 14px;
}

/* ===== Toggle button ===== */
/* The same tab as the PDF preview's restore control — same padding, radius,
   shadow and type, and the site's own accent rather than a green that matches
   nothing else on the page. A floating circle sat on top of whatever was
   underneath it; a tab sits beside it. */
#a11y-toggle {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .25rem;
    padding: .75rem .5rem;
    border: none;
    border-radius: 10px 0 0 10px;
    background: var(--maroon, #820707);
    color: #fff;
    font-family: var(--font-body), 'Inter', sans-serif;
    font-size: .625rem;
    letter-spacing: .04em;
    cursor: pointer;
    box-shadow: -2px 0 10px rgba(51, 0, 0, .22);
    transition: background .15s, padding .15s;
    position: relative;
    z-index: 1;
}
#a11y-toggle:hover { background: var(--dark-maroon, #630000); padding-right: .75rem; }
#a11y-toggle:focus-visible { outline: 2px solid #fff; outline-offset: -4px; }
#a11y-toggle .a11y-tab-label { writing-mode: vertical-rl; text-orientation: mixed; }
/* Whichever edge it is parked on, the curve faces into the page and the
   growth on hover pushes away from the edge. */
#a11y-widget.edge-left #a11y-toggle { border-radius: 0 10px 10px 0; }
#a11y-widget.edge-left #a11y-toggle:hover { padding-right: .5rem; padding-left: .75rem; }
#a11y-widget.edge-right #a11y-toggle { border-radius: 10px 0 0 10px; }

/* Along the top or bottom it lies flat: a word set vertically down there reads
   badly and needs far more height than the tab should take. */
#a11y-widget.edge-top,
#a11y-widget.edge-bottom { transform: none; }
#a11y-widget.edge-top #a11y-toggle,
#a11y-widget.edge-bottom #a11y-toggle {
    flex-direction: row;
    gap: .4rem;
    padding: .45rem .85rem;
}
#a11y-widget.edge-top #a11y-toggle    { border-radius: 0 0 10px 10px; }
#a11y-widget.edge-bottom #a11y-toggle { border-radius: 10px 10px 0 0; }
#a11y-widget.edge-top #a11y-toggle:hover    { padding-bottom: .7rem; padding-right: .85rem; }
#a11y-widget.edge-bottom #a11y-toggle:hover { padding-top: .7rem;    padding-right: .85rem; }
#a11y-widget.edge-top .a11y-tab-label,
#a11y-widget.edge-bottom .a11y-tab-label { writing-mode: horizontal-tb; }
#a11y-widget.edge-top #a11y-toggle    { box-shadow: 0 2px 10px rgba(51, 0, 0, .22); }
#a11y-widget.edge-bottom #a11y-toggle { box-shadow: 0 -2px 10px rgba(51, 0, 0, .22); }
/* Draggable: the button can be picked up and moved anywhere on screen. */
#a11y-toggle { touch-action: none; }
#a11y-widget.is-dragging #a11y-toggle {
    cursor: grabbing;
    transform: none;
    transition: none;
}
#a11y-widget.is-dragging { transition: none; }
/* Once moved, the widget is positioned from the top-left, so the panel has
   to flip to whichever side has room. */
#a11y-widget.anchor-left #a11y-menu  { left: 0; right: auto; }
#a11y-widget.anchor-top  #a11y-menu  { top: calc(100% + 12px); bottom: auto; }
/* Open state darkens the same accent rather than switching to another colour. */
#a11y-toggle.panel-open { background: var(--dark-maroon, #630000); }

/* ===== Panel ===== */
#a11y-menu {
    position: absolute;
    bottom: calc(100% + 12px);
    right: 0;
    width: 300px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 32px rgba(0,0,0,.18), 0 0 0 1px rgba(0,0,0,.06);
    overflow: hidden;
    display: none;
    max-height: calc(100vh - 100px);
    overflow-y: auto;
}
#a11y-menu.visible {
    display: block;
    animation: a11ySlideIn .18s ease;
}
@keyframes a11ySlideIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Panel header */
.a11y-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 12px;
    border-bottom: 1px solid #f0f0f0;
    background: #f9fafb;
    position: sticky;
    top: 0;
    z-index: 1;
}
.a11y-header h3 { margin: 0; font-size: 13px; font-weight: 700; color: #111827; letter-spacing: -.1px; }
.a11y-header-actions { display: flex; align-items: center; gap: 8px; }
#a11y-reset {
    background: none; border: none; cursor: pointer;
    font-size: 11px; color: #6b7280; font-family: inherit;
    padding: 3px 7px; border-radius: 5px; transition: background .15s, color .15s;
}
#a11y-reset:hover { background: #fee2e2; color: #991b1b; }
#a11y-close {
    background: none; border: none; cursor: pointer;
    width: 24px; height: 24px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; color: #9ca3af; transition: background .15s, color .15s;
    padding: 0; line-height: 1;
}
#a11y-close:hover { background: #f3f4f6; color: #374151; }

/* Panel sections */
.a11y-section { padding: 12px 14px; border-bottom: 1px solid #f3f4f6; }
.a11y-section:last-child { border-bottom: none; }
.a11y-section-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .7px; color: #9ca3af; margin-bottom: 10px;
}

/* Font size control */
.a11y-font-control {
    display: flex; align-items: center; justify-content: space-between;
    background: #f3f4f6; border-radius: 8px; padding: 4px;
    margin-bottom: 10px;
}
.a11y-font-btn {
    width: 36px; height: 36px; border: none; background: #fff;
    border-radius: 6px; cursor: pointer; font-size: 1.125rem;
    font-weight: 700; color: #374151;
    box-shadow: 0 1px 3px rgba(0,0,0,.1);
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.a11y-font-btn:hover { background: #f9fafb; }
#a11y-font-val { font-size: 13px; font-weight: 600; color: #374151; min-width: 44px; text-align: center; }

/* Option grid */
.a11y-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.a11y-btn {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 5px; padding: 10px 6px;
    border: 1.5px solid #e5e7eb; background: #fff;
    border-radius: 9px; cursor: pointer; font-family: inherit;
    font-size: 11px; font-weight: 500; color: #4b5563;
    line-height: 1.3; text-align: center;
    transition: border-color .15s, background .15s, color .15s;
}
.a11y-btn:hover { border-color: #d1d5db; background: #f9fafb; }
.a11y-btn.active { border-color: #10b981; background: #ecfdf5; color: #065f46; }
.a11y-btn .a11y-icon { font-size: 1.125rem; line-height: 1; }

/* Reading guide */
#a11y-reading-guide {
    position: fixed;
    left: 0;
    width: 100%;
    height: 4px;
    background: rgba(255,200,0,.7);
    z-index: 2147483646;
    pointer-events: none;
    display: none;
    box-shadow: 0 0 0 9999px rgba(0,0,0,.25);
}

/* ===== Body modifier classes ===== */
body.a11y-highlight-titles h1,
body.a11y-highlight-titles h2,
body.a11y-highlight-titles h3,
body.a11y-highlight-titles h4,
body.a11y-highlight-titles h5,
body.a11y-highlight-titles h6 { outline: 2px solid #3b82f6 !important; outline-offset: 2px !important; background: rgba(59,130,246,.07) !important; }

body.a11y-highlight-links a { text-decoration: underline !important; background: #fef08a !important; color: #000 !important; font-weight: 700 !important; }

body.a11y-dyslexia-font,
body.a11y-dyslexia-font * { font-family: 'Comic Sans MS', 'Chalkboard SE', cursive !important; }

body.a11y-letter-spacing * { letter-spacing: .1em !important; word-spacing: .2em !important; }

body.a11y-line-height * { line-height: 2 !important; }

body.a11y-font-weight * { font-weight: 700 !important; }

/* Filters on body — widget is moved outside body in JS so these
   won't break its position:fixed containing block */
body.a11y-dark-contrast { filter: invert(1) hue-rotate(180deg); background: #000; }
body.a11y-dark-contrast img, body.a11y-dark-contrast video { filter: invert(1) hue-rotate(180deg); }

body.a11y-light-contrast { background: #fff !important; color: #333 !important; }
body.a11y-light-contrast * { background: #fff !important; color: #333 !important; border-color: #ccc !important; }

body.a11y-high-contrast { filter: contrast(160%); }
body.a11y-high-saturation { filter: saturate(300%); }
body.a11y-low-saturation  { filter: saturate(40%); }
body.a11y-monochrome      { filter: grayscale(100%); }

body.a11y-stop-animations * { animation: none !important; transition: none !important; }

body.a11y-big-cursor,
body.a11y-big-cursor * { cursor: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='32' height='32' viewBox='0 0 24 24' fill='black' stroke='white' stroke-width='2'><path d='M5.5 3.21l10.8 15.66-5.2 1.1-2.8 6.03-3.6-1.6 2.8-6.03-5.5-2.4V3.21z'/></svg>"), auto !important; }
</style>

<div id="a11y-widget">
    <div id="a11y-menu" role="dialog" aria-label="Accessibility options">
        <div class="a11y-header">
            <h3>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px;" aria-hidden="true">
                    <circle cx="12" cy="4.4" r="1.9" fill="currentColor" stroke="none"/>
                    <path d="M4.8 8h14.4"/>
                    <path d="M12 8v6"/>
                    <path d="M12 14l-3.3 6"/>
                    <path d="M12 14l3.3 6"/>
                </svg>Accessibility
            </h3>
            <div class="a11y-header-actions">
                <button id="a11y-reset" title="Reset all settings">Reset</button>
                <button id="a11y-close" title="Close" aria-label="Close">&#215;</button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Text Size</div>
            <div class="a11y-font-control">
                <button class="a11y-font-btn" id="a11y-font-dec" aria-label="Decrease font size">&#8722;</button>
                <span id="a11y-font-val">100%</span>
                <button class="a11y-font-btn" id="a11y-font-inc" aria-label="Increase font size">&#43;</button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Content</div>
            <div class="a11y-grid">
                <button class="a11y-btn" data-cls="a11y-highlight-titles">
                    <span class="a11y-icon">T&#772;</span>Highlight Headings
                </button>
                <button class="a11y-btn" data-cls="a11y-highlight-links">
                    <span class="a11y-icon">&#128279;</span>Highlight Links
                </button>
                <button class="a11y-btn" data-cls="a11y-dyslexia-font">
                    <span class="a11y-icon" style="font-family:'Comic Sans MS',cursive">A</span>Dyslexia Font
                </button>
                <button class="a11y-btn" data-cls="a11y-letter-spacing">
                    <span class="a11y-icon">A&#8239;B</span>Letter Spacing
                </button>
                <button class="a11y-btn" data-cls="a11y-line-height">
                    <span class="a11y-icon">&#8597;</span>Line Height
                </button>
                <button class="a11y-btn" data-cls="a11y-font-weight">
                    <span class="a11y-icon"><b>B</b></span>Bold Text
                </button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Color &amp; Contrast</div>
            <div class="a11y-grid">
                <button class="a11y-btn" data-cls="a11y-dark-contrast">
                    <span class="a11y-icon">&#9899;</span>Dark Contrast
                </button>
                <button class="a11y-btn" data-cls="a11y-light-contrast">
                    <span class="a11y-icon">&#9898;</span>Light Contrast
                </button>
                <button class="a11y-btn" data-cls="a11y-high-contrast">
                    <span class="a11y-icon">&#9680;</span>High Contrast
                </button>
                <button class="a11y-btn" data-cls="a11y-high-saturation">
                    <span class="a11y-icon">&#127752;</span>Vivid Colors
                </button>
                <button class="a11y-btn" data-cls="a11y-low-saturation">
                    <span class="a11y-icon">&#127787;</span>Muted Colors
                </button>
                <button class="a11y-btn" data-cls="a11y-monochrome">
                    <span class="a11y-icon">&#9636;</span>Monochrome
                </button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Tools</div>
            <div class="a11y-grid">
                <button class="a11y-btn" id="a11y-reading-guide-btn">
                    <span class="a11y-icon">&#8213;</span>Reading Guide
                </button>
                <button class="a11y-btn" data-cls="a11y-stop-animations">
                    <span class="a11y-icon">&#9208;</span>Stop Animations
                </button>
                <button class="a11y-btn" data-cls="a11y-big-cursor">
                    <span class="a11y-icon">&#8598;</span>Large Cursor
                </button>
            </div>
        </div>
    </div>

    <button id="a11y-toggle" aria-label="Open accessibility menu" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="4.4" r="1.9" fill="currentColor" stroke="none"/>
            <path d="M4.8 8h14.4"/>
            <path d="M12 8v6"/>
            <path d="M12 14l-3.3 6"/>
            <path d="M12 14l3.3 6"/>
        </svg>
        <span class="a11y-tab-label">Accessibility</span>
    </button>
</div>
<div id="a11y-reading-guide"></div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var STORAGE_KEY = 'papel_a11y';

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

                // Active body classes
                if (Array.isArray(prefs.classes)) {
                    prefs.classes.forEach(function (cls) {
                        document.body.classList.add(cls);
                        var btn = document.querySelector('.a11y-btn[data-cls="' + cls + '"]');
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
                    fontSize: fontSize,
                    classes:  classes,
                    guide:    guideOn
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
           so with the tab near an edge it ran off the screen — and its own
           scrollbar could not help, because the part that was cut off was
           outside the window rather than inside the panel. It now opens into
           whichever side has more room and is never taller than that room. */
        function positionPanel() {
            var r = widget.getBoundingClientRect();
            var gap = 12, margin = 12;
            var above = r.top - gap - margin;
            var below = window.innerHeight - r.bottom - gap - margin;
            var openDown = below >= above;

            widget.classList.toggle('anchor-top', openDown);
            menu.style.maxHeight = Math.max(140, openDown ? below : above) + 'px';
        }

        function openPanel() {
            positionPanel();
            menu.classList.add('visible');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.classList.add('panel-open');
        }
        function closePanel() {
            menu.classList.remove('visible');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.classList.remove('panel-open');
        }

        if (toggle) toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.contains('visible') ? closePanel() : openPanel();
        });
        if (closeBtn) closeBtn.addEventListener('click', closePanel);

        /* Opening from somewhere else on the page — the Settings page has an
           "Open" button for it. Going through a synthetic click on the tab does
           not work: the original click carries on bubbling to <html>, where the
           close-on-outside-click listener below sees a click that did not come
           from inside the widget and shuts the panel again. Calling this
           instead skips that entirely. */
        window.papelAccessibility = {
            open:   openPanel,
            close:  closePanel,
            toggle: function () {
                menu.classList.contains('visible') ? closePanel() : openPanel();
            }
        };

        /* Close on outside click — listener on <html> since widget is there */
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

        /* Park against one edge. `offset` runs along that edge — a distance
           down the page for the sides, across it for the top and bottom — and
           is clamped so the whole tab stays on screen. */
        function placeWidget(edge, offset, save) {
            widget.classList.remove('edge-left', 'edge-right', 'edge-top', 'edge-bottom');
            widget.classList.add('edge-' + edge);

            // The class changes the tab's shape, so measure after applying it.
            var s = widgetSize();
            var style = widget.style;
            style.left = style.right = style.top = style.bottom = 'auto';
            style.transform = 'none';

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

        function restorePosition() {
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
            updateAnchors();     // never moved: the stylesheet's centred right edge
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

            /* Swallow the click that follows a drag so the panel stays shut. */
            toggle.addEventListener('click', function (e) {
                if (didDrag) {
                    didDrag = false;
                    e.stopImmediatePropagation();
                    e.preventDefault();
                }
            }, true);

            /* Keep it on the edge, and on screen, when the window is resized. */
            window.addEventListener('resize', function () {
                if (menu.classList.contains('visible')) positionPanel();
                var edge = ['left', 'right', 'top', 'bottom'].filter(function (e) {
                    return widget.classList.contains('edge-' + e);
                })[0];
                if (!edge) { updateAnchors(); return; }
                var along = (edge === 'left' || edge === 'right')
                    ? parseFloat(widget.style.top) : parseFloat(widget.style.left);
                placeWidget(edge, along || 0, false);
            });

            toggle.title = 'Accessibility options — drag it to any edge';
            restorePosition();
        }

        /* ---- Toggle body classes ---- */
        document.querySelectorAll('.a11y-btn[data-cls]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cls = this.dataset.cls;
                document.body.classList.toggle(cls);
                this.classList.toggle('active');
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
                    if (b.dataset.cls) document.body.classList.remove(b.dataset.cls);
                    b.classList.remove('active');
                });
                guideOn = false;
                if (guideEl) guideEl.style.display = 'none';
                try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
            });
        }

        /* ---- Disable right-click ---- */
        document.addEventListener('contextmenu', function (e) { e.preventDefault(); });

        /* ---- Restore saved preferences ---- */
        loadPrefs();
    });
}());
</script>

<!-- ===== Global submit-button loading state ===== -->
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.is-submitting { position: relative; pointer-events: none; opacity: .85; cursor: progress !important; }
.btn-load-spinner {
    display: inline-block;
    width: 1em; height: 1em;
    margin-right: .5em;
    vertical-align: -.15em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: btnLoadSpin .6s linear infinite;
}
@keyframes btnLoadSpin { to { transform: rotate(360deg); } }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var LOADING_LABEL = 'Processing…';

    function startLoading(btn) {
        if (!btn || btn.dataset.loading === '1') return;
        btn.dataset.loading = '1';
        btn.classList.add('is-submitting');

        if (btn.tagName === 'BUTTON') {
            btn.dataset.originalHtml = btn.innerHTML;
            var label = btn.getAttribute('data-loading-text') || LOADING_LABEL;
            btn.innerHTML = '<span class="btn-load-spinner"></span>' + label;
        } else { // input[type=submit|button|image]
            btn.dataset.originalValue = btn.value;
            btn.value = btn.getAttribute('data-loading-text') || LOADING_LABEL;
        }
        // Keep it visually disabled but still let the browser submit the form.
        btn.setAttribute('aria-busy', 'true');
    }

    function resetLoading(btn) {
        if (!btn || btn.dataset.loading !== '1') return;
        if (btn.tagName === 'BUTTON' && btn.dataset.originalHtml !== undefined) {
            btn.innerHTML = btn.dataset.originalHtml;
        } else if (btn.dataset.originalValue !== undefined) {
            btn.value = btn.dataset.originalValue;
        }
        btn.classList.remove('is-submitting');
        btn.removeAttribute('aria-busy');
        delete btn.dataset.loading;
    }

    // Fire on real form submissions (after the browser passes HTML5 validation).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.hasAttribute('data-no-loading')) return;

        var btn = e.submitter ||
                  form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
        if (!btn || btn.hasAttribute('data-no-loading')) return;

        // Defer one tick so we can detect if a validator / AJAX handler cancelled the submit.
        setTimeout(function () {
            if (e.defaultPrevented) return;   // navigation was stopped — don't get stuck spinning
            startLoading(btn);
        }, 0);
    }, false);

    // Restore buttons when returning via browser back/forward (bfcache).
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            document.querySelectorAll('[data-loading="1"]').forEach(resetLoading);
        }
    });
}());
</script>
