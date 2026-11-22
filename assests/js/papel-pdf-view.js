/**
 * PAPEL PDF viewer.
 *
 * Renders a PDF onto canvases we own, rather than handing it to the browser's
 * built-in viewer. That viewer lays out once against whatever size its frame
 * happens to have and never re-measures, which left it painting into a strip of
 * the preview panel no matter how the frame was sized. Drawing the pages here
 * keeps the geometry (and therefore zoom, panning and text selection) under
 * our control.
 *
 * Each page gets a canvas for the picture and, on top of it, a transparent text
 * layer positioned by PDF.js. That layer is what makes the text selectable and
 * copyable: the canvas alone is just pixels.
 *
 * Shared by the upload page's side panel and the full-window viewer so both
 * show a document exactly the same way.
 *
 * Usage:
 *   const view = papelPdfView.create({
 *       scroller: <element the pages go in>,
 *       status:   <element for messages>,
 *       workerSrc: '/path/to/pdf.worker.min.js',
 *       failMessage: 'said when a PDF cannot be drawn'    (optional)
 *       intro:       'hint shown after the first document' (optional; '' for none)
 *       loadingText: 'said while loading'                 (optional; '' for none)
 *       onBusy:      function (loading) {…}               (optional; for a spinner)
 *       onFail:      function (message) {…}               (optional)
 *       watermark:   ['line one', 'line two']              (optional; burnt into
 *                                                           every page as it is drawn)
 *       textLayer:   false                                 (optional; no selectable
 *                                                           text over the canvas)
 *       protect:     true                                  (optional; the pages are
 *                                                           covered while this window
 *                                                           is not the one in front)
 *       protectMessage: 'said on the cover'                (optional)
 *   });
 *
 * onFail is given the failure instead of the status element, for a host that
 * wants to put something to press beside the words (the two viewers offer a
 * retry). Without it the message is written to the status element as before,
 * so a host that has not been taught about it is unaffected.
 *   view.load(fileOrArrayBuffer);
 */
(function (global) {
    'use strict';

    var MIN_ZOOM = 0.25;
    var MAX_ZOOM = 5;
    /* The levels a click on a zoom button, or a notch of the wheel, moves between.
       They are the ones the browser's own PDF viewer uses, so the pace is one
       readers already know: 10 to 25% a step. The ladder here used to run
       100, 75, 50, 25 and 100, 125, 150, 200, so one click took a quarter or half
       of the page's size away and the document jumped instead of zooming. */
    var STEPS = [0.25, 0.33, 0.5, 0.67, 0.75, 0.8, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4, 5];

    function create(options) {
        var scroller = options.scroller;
        var statusEl = options.status || null;
        var onZoom = options.onZoom || function () {};
        var onMode = options.onMode || function () {};

        var pdfDoc = null;
        var zoom = 1;              // 1 = fit the scroller's width
        var renderToken = 0;       // bumped to abandon a superseded render
        var wraps = [], drawn = {}, failedPages = {};
        var activePage = 0, activeTask = null, visibleFrame = 0;
        var loadToken = 0;         // the same, for a document still being opened
        var lastWidth = 0;         // the width the pages were last drawn at
        var workerReady = false;
        var resizeTimer = null;
        var panMode = false;
        var introShown = false;

        function say(msg) {
            if (statusEl) statusEl.textContent = msg || '';
        }

        /* ----- The protected view -----

           Two halves of one answer to "stop people screenshotting the paper",
           which no web page can do: see pdf_view_protection() in
           config/core.php for why Netflix's black frame is not available here.

           The watermark is drawn into the canvas itself, not laid over it as
           an element: an element can be deleted from the inspector in two
           keystrokes and never appears in the pixels an OS screenshot takes
           from the compositor. Burnt in, it is in every capture there is,
           including a photograph of the monitor.

           The cover is the visible half. It goes over the pages the moment
           this window is no longer the one in front, so a capture taken from
           another window, or a recording of a tab left in the background,
           gets the notice rather than the paper. It is honest theatre: a
           recorder running while the reader reads sees everything. */
        var mark = Array.isArray(options.watermark) ? options.watermark.filter(Boolean) : [];
        var textOn = options.textLayer !== false;
        var guardOn = !!options.protect;
        var guardEl = null, guardHeld = false, guardTimer = null;
        if (!textOn) { panMode = true; }

        /* Stamped after the page is drawn and before it is shown, so there is
           no frame in which the paper stands there unmarked. Every zoom
           re-renders, and this runs again with it.

           Everything here is in the canvas's own pixels, which are the CSS
           size times the device ratio the renderer chose, so `ratio` is what
           turns a size meant for the eye into one for the backing store.
           Sized from the canvas instead (min(w, h) / 38, as this was first
           written) the lettering came out about 35px on screen and read as a
           poster laid over the paper rather than a mark on it. */
        function stamp(canvas, ratio) {
            if (!mark.length) return;
            var ctx;
            try { ctx = canvas.getContext('2d'); } catch (err) { return; }
            if (!ctx) return;

            var scaled = ratio > 0 ? ratio : 1;
            var w = canvas.width, h = canvas.height;
            /* 13px to the reader, whatever the zoom: the mark belongs to the
               view and not to the page, so it does not grow with it. */
            var size = Math.max(11, 13 * scaled);
            var line = mark.join('   ·   ');

            ctx.save();
            /* 600 rather than bold: at this opacity a heavy face reads as a
               smudge over the paper's own text, and a light one disappears
               in a JPEG screenshot. */
            ctx.font = '600 ' + size + 'px Inter, "Segoe UI", system-ui, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            /* Ink, not maroon: the mark has to survive a greyscale print and
               a screenshot put through a filter, and it must not read as part
               of the paper. 11% is the figure measured against the papers on
               file: legible in a capture, and not in the way of reading. */
            ctx.fillStyle = 'rgba(20, 20, 20, 0.13)';

            var step = ctx.measureText(line).width + size * 9;
            var rows = size * 12;
            var reach = Math.sqrt(w * w + h * h) / 2;
            ctx.translate(w / 2, h / 2);
            ctx.rotate(-Math.PI / 7);
            for (var y = -reach; y <= reach; y += rows) {
                /* Every other row is offset by half a tile, so the stamp does
                   not form clean columns a crop can be taken between. */
                var shift = (Math.round((y + reach) / rows) % 2) ? step / 2 : 0;
                for (var x = -reach - shift; x <= reach; x += step) {
                    ctx.fillText(line, x + shift, y);
                }
            }
            ctx.restore();
        }

        /* The cover, built the first time it is needed. It lives in the
           scroller's parent, the positioned stage in both layouts, which is
           where the hint toast already goes: inside the scroller it would
           scroll away from the pages it is covering. */
        function guard() {
            if (guardEl || !guardOn) return guardEl;
            guardEl = document.createElement('div');
            guardEl.className = 'papel-pdf-guard';
            guardEl.setAttribute('role', 'status');
            var word = document.createElement('div');
            word.className = 'papel-pdf-guard-word';
            word.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">visibility_off</span>';
            var say1 = document.createElement('strong');
            say1.textContent = 'The document is hidden';
            var say2 = document.createElement('span');
            say2.textContent = options.protectMessage ||
                'PAPEL covers a paper while its window is not in front. Come back to this window to read on.';
            word.appendChild(say1);
            word.appendChild(say2);
            guardEl.appendChild(word);
            (scroller.parentNode || scroller).appendChild(guardEl);
            return guardEl;
        }

        function cover(why) {
            if (!guardOn) return;
            var el = guard();
            if (!el) return;
            clearTimeout(guardTimer);
            el.classList.add('is-on');
            scroller.setAttribute('aria-hidden', 'true');
            /* A cover put up by a key press has nothing to wait for: the
                window never lost focus, so nothing will give it back. */
            if (why === 'key') {
                guardTimer = setTimeout(uncover, 1200);
            }
        }

        function uncover() {
            if (!guardEl) return;
            clearTimeout(guardTimer);
            guardEl.classList.remove('is-on');
            scroller.removeAttribute('aria-hidden');
        }

        /* PrintScreen is past tense: the picture was taken before this runs,
           and no page can stop it. What can be done is to take the copy back
           out of the clipboard, which Chrome allows a focused page to do, and
           to say that the capture is marked. Both are best effort. */
        function printScreenTaken() {
            cover('key');
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText('').catch(function () {});
                }
            } catch (err) { /* no clipboard permission: the stamp still names them */ }
            hint('This document is watermarked with your name and the time.', 4000);
        }

        var guardHandlers = [];
        function watch(target, type, fn) {
            target.addEventListener(type, fn, false);
            guardHandlers.push([target, type, fn]);
        }

        if (guardOn) {
            /* The stylesheet's print rule finds the stage by this, so a
               protected viewer prints its notice rather than its pages even
               if the sheet is rendered before beforeprint has run. */
            (scroller.parentNode || scroller).classList.add('is-protected');
            /* blur rather than focusout: the window losing the focus is the
               event, not an element inside it losing it, and focusout fires
               every time the reader clicks from the scroller to a toolbar
               button, which would blank the paper on every zoom. */
            watch(global, 'blur', function () { cover('blur'); });
            watch(global, 'focus', function () { if (!guardHeld) uncover(); });
            watch(document, 'visibilitychange', function () {
                if (document.visibilityState === 'hidden') cover('hidden');
                else if (document.hasFocus && document.hasFocus()) uncover();
            });
            /* Windows fires keyup alone for this key and no keydown, so there
               is nothing to prevent and nothing to catch it before the fact. */
            watch(document, 'keyup', function (e) {
                if (e.key === 'PrintScreen') printScreenTaken();
            });
            /* Print is a capture with a file at the end of it. The stylesheet
               hides the pages as well (papel-pdf-view.css), for a browser that
               renders the sheet before this runs. */
            watch(global, 'beforeprint', function () { guardHeld = true; cover('print'); });
            watch(global, 'afterprint', function () {
                guardHeld = false;
                if (document.hasFocus && document.hasFocus()) uncover();
            });
            if (global.matchMedia) {
                var printQuery = global.matchMedia('print');
                var onPrintQuery = function (e) {
                    guardHeld = !!e.matches;
                    if (e.matches) cover('print');
                    else if (document.hasFocus && document.hasFocus()) uncover();
                };
                if (printQuery.addEventListener) {
                    printQuery.addEventListener('change', onPrintQuery);
                    guardHandlers.push([printQuery, 'change', onPrintQuery]);
                }
            }
            /* The window may already be in the background when the viewer is
               built: a file opened in a tab the reader has since left. */
            if (document.visibilityState === 'hidden') cover('hidden');
        }

        function releaseGuard() {
            clearTimeout(guardTimer);
            guardHandlers.forEach(function (h) {
                try { h[0].removeEventListener(h[1], h[2], false); } catch (err) {}
            });
            guardHandlers = [];
            if (guardEl && guardEl.parentNode) { guardEl.parentNode.removeChild(guardEl); }
            guardEl = null;
        }


        /* A failure, as opposed to anything else say() carries. The host is
           offered it first: say() writes textContent, which would wipe out a
           retry button the host had just put in the same box. */
        function fail(msg) {
            if (typeof options.onFail === 'function') options.onFail(msg);
            else say(msg);
        }

        /* Loading, told to the page rather than written: onBusy(true) when a
           document starts to load, onBusy(false) once its first page is up or
           it has failed. A page that shows a spinner for this passes
           loadingText: '' so the words do not appear under it as well. */
        var loadingText = options.loadingText !== undefined ? options.loadingText : 'Loading preview...';
        function busy(on) {
            if (typeof options.onBusy === 'function') options.onBusy(!!on);
        }

        function ready() {
            if (typeof pdfjsLib === 'undefined') return false;
            if (!workerReady && options.workerSrc) {
                pdfjsLib.GlobalWorkerOptions.workerSrc = options.workerSrc;
                workerReady = true;
            }
            return true;
        }

        // Width available for a page, inside the scroller's padding.
        function availableWidth() {
            var style = global.getComputedStyle(scroller);
            var pad = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
            return scroller.clientWidth - pad;
        }

        /* Flags the spans drawn in a bold face.

           A PDF has no <strong>; weight lives in the font each run is set in.
           PDF.js exposes that font per text item, and the loaded font's name
           carries the weight ("...-Bold", "...Black"). Marking the span lets
           the copy handler below re-emit it as <strong>, so bold survives a
           copy out of the preview and a paste into a section. */
        function markBold(page, textContent, divs) {
            var items = textContent.items || [];
            var cache = {};

            function isBoldFont(fontName) {
                if (!fontName) return false;
                if (cache.hasOwnProperty(fontName)) return cache[fontName];
                var bold = false;
                try {
                    var font = page.commonObjs.get(fontName);
                    if (font) {
                        // `black` and `bold` are set by PDF.js when it can tell;
                        // otherwise the face name is the only clue.
                        bold = !!(font.black || font.bold) ||
                               /bold|black|heavy|semib|demib/i.test(font.name || '');
                    }
                } catch (err) { bold = false; }
                cache[fontName] = bold;
                return bold;
            }

            for (var i = 0; i < divs.length && i < items.length; i++) {
                if (divs[i] && isBoldFont(items[i].fontName)) {
                    divs[i].dataset.bold = '1';
                }
            }
        }

        /* Where the reader is: the page across the middle of the view, and how
           far down it. Kept as a page and a fraction rather than a pixel
           offset, because a redraw at another width or zoom changes every
           pixel measurement but not which line is being read. */
        function readingPlace() {
            var pages = scroller.querySelectorAll('.papel-pdf-page');
            if (!pages.length) return null;
            var mid = scroller.getBoundingClientRect().top + scroller.clientHeight / 2;
            for (var i = 0; i < pages.length; i++) {
                var r = pages[i].getBoundingClientRect();
                if (r.bottom >= mid) {
                    return { page: i + 1, ratio: r.height ? Math.min(1, Math.max(0, (mid - r.top) / r.height)) : 0 };
                }
            }
            return { page: pages.length, ratio: 1 };
        }

        function putBack(place, wraps) {
            var wrap = wraps[Math.min(place.page, wraps.length) - 1];
            if (!wrap) return;
            var top = wrap.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
            scroller.scrollTop = Math.max(0, top + place.ratio * wrap.offsetHeight - scroller.clientHeight / 2);
        }

        /* A long manuscript must never rasterise all its pages on load or on
           every zoom. Only the pages near the viewport need canvases and text
           layers; the sized wrappers keep scrolling and page numbers stable. */
        function stopRendering() {
            ++renderToken;
            if (visibleFrame) { cancelAnimationFrame(visibleFrame); visibleFrame = 0; }
            if (activeTask) { try { activeTask.cancel(); } catch (err) {} }
            activeTask = null;
            activePage = 0;
            wraps = []; drawn = {}; failedPages = {};
        }

        function nearby() {
            var box = scroller.getBoundingClientRect();
            var mid = box.top + box.height / 2;
            var near = [];
            for (var i = 0; i < wraps.length; i++) {
                var rect = wraps[i].getBoundingClientRect();
                var distance = Math.abs((rect.top + rect.bottom) / 2 - mid);
                if (rect.bottom >= box.top - box.height && rect.top <= box.bottom + box.height) {
                    near.push({ page: i + 1, distance: distance });
                } else if (drawn[i + 1] && (rect.bottom < box.top - box.height * 2 || rect.top > box.bottom + box.height * 2)) {
                    // A distant canvas can cost tens of MB at high zoom.
                    wraps[i].textContent = '';
                    delete drawn[i + 1];
                }
            }
            near.sort(function (a, b) { return a.distance - b.distance; });
            return near.map(function (item) { return item.page; });
        }

        function visiblePages() {
            if (!pdfDoc || !wraps.length || activePage) return;
            var pages = nearby();
            var page = pages.find(function (n) { return !drawn[n] && !failedPages[n]; });
            if (!page) return;
            var token = renderToken;
            activePage = page;
            pdfDoc.getPage(page).then(function (p) {
                if (token !== renderToken) return;
                var base = availableWidth();
                var natural = p.getViewport({ scale: 1 });
                var scale = (base / natural.width) * zoom;
                var viewport = p.getViewport({ scale: scale });
                var wrap = wraps[page - 1];
                var before = wrap.offsetHeight;
                wrap.style.width = Math.floor(viewport.width) + 'px';
                wrap.style.height = Math.floor(viewport.height) + 'px';
                if (wrap.getBoundingClientRect().bottom < scroller.getBoundingClientRect().top) {
                    scroller.scrollTop += wrap.offsetHeight - before;
                }
                var canvas = document.createElement('canvas');
                canvas.className = 'papel-pdf-canvas';
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', 'Page ' + page + ' of ' + pdfDoc.numPages);
                // Bound backing-store memory, especially at 5x zoom on phones.
                var pixels = Math.min(global.devicePixelRatio || 1, 2,
                    Math.sqrt(8000000 / Math.max(1, viewport.width * viewport.height)));
                canvas.width = Math.max(1, Math.floor(viewport.width * pixels));
                canvas.height = Math.max(1, Math.floor(viewport.height * pixels));
                /* No text layer in a protected view: the canvas alone is
                   pixels, so a published paper cannot be lifted whole with
                   one Ctrl+A. */
                var layer = null;
                if (textOn) {
                    layer = document.createElement('div');
                    layer.className = 'papel-pdf-textlayer';
                    layer.style.setProperty('--scale-factor', scale);
                }
                wrap.textContent = '';
                wrap.appendChild(canvas);
                if (layer) wrap.appendChild(layer);
                activeTask = p.render({
                    canvasContext: canvas.getContext('2d'), viewport: viewport,
                    transform: pixels !== 1 ? [pixels, 0, 0, pixels, 0, 0] : null
                });
                return activeTask.promise.then(function () {
                    if (token !== renderToken) return;
                    // Before the page is counted as drawn, so no frame of it
                    // is ever on screen without the reader's name on it.
                    stamp(canvas, pixels);
                    drawn[page] = true;
                    busy(false);
                    while (readyWaiters.length) readyWaiters.shift()();
                    failWaiters = [];
                    if (!layer) return;
                    // A usable page appears before the text layer is built.
                    return p.getTextContent().then(function (textContent) {
                        if (token !== renderToken || !wrap.isConnected) return;
                        var divs = [];
                        return pdfjsLib.renderTextLayer({
                            textContentSource: textContent, container: layer,
                            viewport: viewport, textDivs: divs
                        }).promise.then(function () { markBold(p, textContent, divs); });
                    });
                });
            }).catch(function (err) {
                if (token !== renderToken) return;
                if (err && err.name === 'RenderingCancelledException') return;
                failedPages[page] = true;
                if (readyWaiters.length) {
                    readyWaiters = [];
                    while (failWaiters.length) failWaiters.shift()(err);
                }
            }).then(function () {
                if (token !== renderToken) return;
                activePage = 0;
                activeTask = null;
                scheduleVisible();
            });
        }

        function scheduleVisible() {
            if (visibleFrame) return;
            visibleFrame = requestAnimationFrame(function () {
                visibleFrame = 0;
                if (activePage && wraps[activePage - 1]) {
                    var rect = wraps[activePage - 1].getBoundingClientRect();
                    var box = scroller.getBoundingClientRect();
                    if ((rect.bottom < box.top - box.height || rect.top > box.bottom + box.height) && activeTask) {
                        activeTask.cancel();
                    }
                }
                visiblePages();
            });
        }
        scroller.addEventListener('scroll', scheduleVisible, { passive: true });
        var readyWaiters = [], failWaiters = [];
        function settleWaiters() {
            while (readyWaiters.length) readyWaiters.shift()();
            failWaiters = [];
        }

        /* Lay out the document at the current width, preserving the reading
           position; the viewport renderer then draws only nearby pages. */
        function render(keep) {
            stopRendering();
            var token = renderToken;
            if (!pdfDoc) return Promise.resolve();

            var base = availableWidth();
            /* Nothing to draw into: the panel is hidden (minimised while this
               document was still opening, or a folded section). The width is
               forgotten, so that when the panel is shown again the resize
               watcher sees a change and draws; keeping the last width it was
               drawn at left a blank stage and a ring that never stopped. */
            if (base <= 0) { lastWidth = 0; return Promise.resolve(); }
            lastWidth = base;

            var total = pdfDoc.numPages;

            return pdfDoc.getPage(1).then(function (first) {
                if (token !== renderToken) return;

                /* Every page is laid out before any is drawn, at the first
                   page's size, so the document has its whole height at once
                   and the reading place can be put back immediately, rather
                   than starting again at page 1 and jumping once every page
                   above had been drawn. A page of another size is corrected
                   when its turn comes. */
                var firstView = first.getViewport({ scale: (base / first.getViewport({ scale: 1 }).width) * zoom });
                scroller.textContent = '';
                for (var i = 0; i < total; i++) {
                    var w = document.createElement('div');
                    w.className = 'papel-pdf-page';
                    w.style.width = Math.floor(firstView.width) + 'px';
                    w.style.height = Math.floor(firstView.height) + 'px';
                    scroller.appendChild(w);
                    wraps.push(w);
                }
                if (keep) putBack(keep, wraps);

                return new Promise(function (resolve, reject) {
                    readyWaiters.push(resolve);
                    failWaiters.push(reject);
                    scheduleVisible();
                });
            }).catch(function (err) {
                if (token === renderToken) { busy(false); throw err; }
            });
        }

        function setZoom(value) {
            var clamped = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));
            if (Math.abs(clamped - zoom) < 0.001) return;

            // Keep whatever is in the middle of the view in place, so zooming
            // does not throw the reader back to the top of the document.
            var place = readingPlace();
            zoom = clamped;
            onZoom(zoom);
            render(place).catch(function () {
                fail(options.failMessage || 'This PDF could not be displayed.');
            });
        }

        function step(direction) {
            var i;
            if (direction > 0) {
                for (i = 0; i < STEPS.length; i++) {
                    if (STEPS[i] > zoom + 0.001) { setZoom(STEPS[i]); return; }
                }
                setZoom(MAX_ZOOM);
            } else {
                for (i = STEPS.length - 1; i >= 0; i--) {
                    if (STEPS[i] < zoom - 0.001) { setZoom(STEPS[i]); return; }
                }
                setZoom(MIN_ZOOM);
            }
        }

        /* =====================================================================
           Copying out of the preview

           A PDF carries no paragraphs, no bold tags and no tables, only glyphs
           at coordinates. Left to itself the browser copies the text layer as
           one span per visual line, which is why a paste used to arrive as a
           single undifferentiated block.

           Because the layer is ours, the geometry is available: where each line
           starts, where it ends, and where the gaps between runs fall. That is
           enough to recover the structure the page was showing.
           ===================================================================== */

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // Every text span the selection touches, in reading order.
        // Anything above this and the reconstruction is abandoned in favour of
        // the browser's own copy. A page that pastes plainly beats one that
        // stops responding.
        var MAX_SPANS = 6000;

        /* The spans the selection touches.

           Scoped to the selection's common ancestor rather than the whole
           scroller: selecting inside one page then walks that page alone
           instead of every page rendered. intersectsNode() is also markedly
           cheaper than Selection.containsNode(), which was being called tens of
           thousands of times per copy and locking the tab. */
        function selectedSpans(range) {
            var scope = range.commonAncestorContainer;
            if (scope.nodeType !== 1) scope = scope.parentNode;
            if (!scope || !scroller.contains(scope)) scope = scroller;

            var candidates = scope.querySelectorAll('.papel-pdf-textlayer span');
            if (candidates.length > MAX_SPANS) return null;

            var out = [];
            for (var i = 0; i < candidates.length; i++) {
                var span = candidates[i];
                if (!span.firstChild || span.classList.contains('endOfContent')) continue;
                if (range.intersectsNode(span)) out.push(span);
            }
            return out;
        }

        // Spans sharing a baseline form a line. Offsets are measured inside the
        // page wrapper, so pages are numbered first and never interleaved.
        function groupIntoLines(spans) {
            var pageOrder = new Map();
            scroller.querySelectorAll('.papel-pdf-page').forEach(function (page, i) {
                pageOrder.set(page, i);
            });

            var rows = [];

            for (var i = 0; i < spans.length; i++) {
                var span = spans[i];
                // Spans are direct children of the text layer, which is a direct
                // child of the page: no closest() walk needed per span.
                var layer = span.parentNode;
                var page = layer && layer.parentNode;
                if (!pageOrder.has(page)) {
                    page = span.closest ? span.closest('.papel-pdf-page') : page;
                }
                var index = pageOrder.has(page) ? pageOrder.get(page) : 0;
                var top = span.offsetTop;
                var tolerance = Math.max(4, span.offsetHeight * 0.5);

                var row = null;
                // Only the tail of the list can match: spans arrive in document
                // order, so anything earlier belongs to a line already closed.
                for (var j = rows.length - 1; j >= 0; j--) {
                    if (rows[j].index !== index) break;
                    if (Math.abs(rows[j].top - top) <= tolerance) { row = rows[j]; break; }
                    if (rows.length - j > 4) break;
                }
                if (!row) {
                    row = { index: index, top: top, spans: [] };
                    rows.push(row);
                }
                row.spans.push(span);
            }

            // Numeric compare: no DOM work inside the comparator.
            rows.sort(function (a, b) {
                return (a.index - b.index) || (a.top - b.top);
            });
            rows.forEach(function (row) {
                row.spans.sort(function (a, b) { return a.offsetLeft - b.offsetLeft; });
                var last = row.spans[row.spans.length - 1];
                row.left = row.spans[0].offsetLeft;
                row.right = last.offsetLeft + last.offsetWidth;
                row.height = row.spans[0].offsetHeight || 12;
                row.page = row.index;
            });
            return rows;
        }

        function spanHtml(span) {
            var text = esc(span.textContent);
            return span.dataset.bold === '1' ? '<strong>' + text + '</strong>' : text;
        }

        function cellsToHtml(cells) {
            return cells.map(function (group) {
                return group.map(spanHtml).join('').replace(/\s+/g, ' ').trim();
            });
        }

        /* A table is found by the empty vertical lanes running down it.

           Two earlier attempts failed on real documents. Splitting each row on
           a fixed pixel gap meant nothing at a different zoom level. Clustering
           the left edges of the runs worked only while the columns were
           left-aligned: centred cells, which is how most academic tables set
           their text, start at a different x on every row and never clustered
           at all.

           What survives any alignment is the whitespace between the columns: a
           table always leaves a clear vertical lane there, and prose never
           does. So the runs are painted onto a horizontal strip, the lanes no
           row writes into become the column boundaries, and each run is filed
           into the band its centre falls in. Centred, right-aligned and ragged
           columns all land correctly, and one wide heading spanning two columns
           cannot erase a lane, because a lane only has to be clear in most
           rows rather than all of them. */
        function columnBands(rows) {
            var minX = Infinity, maxX = -Infinity, heights = [];
            rows.forEach(function (row) {
                heights.push(row.height || 12);
                row.spans.forEach(function (s) {
                    minX = Math.min(minX, s.offsetLeft);
                    maxX = Math.max(maxX, s.offsetLeft + s.offsetWidth);
                });
            });
            if (!isFinite(minX) || maxX - minX < 40) return null;

            heights.sort(function (a, b) { return a - b; });
            var line = heights[Math.floor(heights.length / 2)] || 12;

            // Everything below scales with the text, so it holds at any zoom.
            var bucket  = Math.max(1, line * 0.2);
            var wordGap = line * 0.6;    // a space between words, not a column
            var minLane = line * 0.7;    // narrower than this is not a column gap
            var count   = Math.ceil((maxX - minX) / bucket) + 1;

            var hits = new Array(count);
            for (var i = 0; i < count; i++) hits[i] = 0;

            rows.forEach(function (row) {
                var covered = new Array(count);
                var prevRight = null;
                row.spans.forEach(function (s) {
                    var left = s.offsetLeft, right = left + s.offsetWidth;
                    // Bridge the ordinary spaces inside one cell's text.
                    if (prevRight !== null && left - prevRight <= wordGap) left = prevRight;
                    prevRight = Math.max(prevRight === null ? right : prevRight, right);

                    var a = Math.floor((left - minX) / bucket);
                    var b = Math.ceil((right - minX) / bucket);
                    for (var i = Math.max(0, a); i < b && i < count; i++) covered[i] = 1;
                });
                for (var i = 0; i < count; i++) if (covered[i]) hits[i]++;
            });

            // A lane may be written into by a few rows: a merged heading, a
            // footnote marker, and still be a column boundary.
            var tolerated = Math.floor(rows.length * 0.15);
            var bands = [], open = null;
            for (var i = 0; i < count; i++) {
                var isText = hits[i] > tolerated;
                if (isText && open === null) open = i;
                if (!isText && open !== null) {
                    // Close the band only once the lane is wide enough to count.
                    var j = i;
                    while (j < count && hits[j] <= tolerated) j++;
                    if ((j - i) * bucket >= minLane || j >= count) {
                        bands.push({ left: minX + open * bucket, right: minX + i * bucket });
                        open = null;
                        i = j - 1;
                    } else {
                        i = j - 1;   // too narrow: part of the same column
                    }
                }
            }
            if (open !== null) bands.push({ left: minX + open * bucket, right: maxX });

            return bands.length >= 2 ? bands : null;
        }

        function detectTable(rows) {
            if (rows.length < 2) return null;

            var bands = columnBands(rows);
            if (!bands) return null;

            var grid = rows.map(function (row) {
                var cells = bands.map(function () { return []; });
                row.spans.forEach(function (span) {
                    var mid = span.offsetLeft + span.offsetWidth / 2;
                    var col = 0;
                    for (var i = 0; i < bands.length; i++) {
                        if (mid >= bands[i].left) col = i;
                        else break;
                    }
                    cells[col].push(span);
                });
                return cells;
            });

            // Prose would land entirely in one band, so a table has to show
            // rows that genuinely straddle the columns.
            var straddling = grid.filter(function (cells) {
                var used = 0;
                cells.forEach(function (c) { if (c.length) used++; });
                return used >= 2;
            }).length;
            if (straddling < 2 || straddling < Math.ceil(rows.length * 0.5)) return null;

            /* A cell whose text wrapped onto the next line arrives as a row of
               its own with only that column filled. It belongs to the row above
               it, not to a row of its own. */
            var merged = [];
            grid.forEach(function (cells) {
                var used = [];
                cells.forEach(function (c, i) { if (c.length) used.push(i); });
                if (merged.length && used.length === 1) {
                    var into = merged[merged.length - 1];
                    into[used[0]] = into[used[0]].concat(cells[used[0]]);
                } else {
                    merged.push(cells);
                }
            });
            if (merged.length < 2) return null;

            var html = '<table>';
            merged.forEach(function (cells, i) {
                var values = cellsToHtml(cells);
                var tag = (i === 0) ? 'th' : 'td';
                html += '<tr>' + values.map(function (v) {
                    return '<' + tag + ' style="text-align:center">' + (v || '<br>') + '</' + tag + '>';
                }).join('') + '</tr>';
            });
            return html + '</table>';
        }

        /* Prose: rejoin the wrapped lines and start a new paragraph where the
           page shows one. A PDF marks a paragraph with a first-line indent or
           by ending the previous line short of the margin (never with a blank
           line) which is why joining on line breaks alone produced one block. */
        function paragraphsToHtml(rows) {
            var lefts = rows.map(function (r) { return r.left; });
            var rights = rows.map(function (r) { return r.right; });
            var bodyLeft = Math.min.apply(null, lefts);
            var bodyRight = Math.max.apply(null, rights);
            var indent = Math.max(10, (rows[0].height || 12) * 0.8);
            var shortfall = Math.max(24, (rows[0].height || 12) * 2);

            var paragraphs = [], current = [];

            rows.forEach(function (row, i) {
                var startsIndented = (row.left - bodyLeft) > indent;
                var prev = rows[i - 1];
                var prevEndedShort = prev && (bodyRight - prev.right) > shortfall;
                var newPage = prev && prev.page !== row.page;

                if (i > 0 && (startsIndented || prevEndedShort) && !newPage) {
                    if (current.length) paragraphs.push(current);
                    current = [];
                }
                current.push(row);
            });
            if (current.length) paragraphs.push(current);

            return paragraphs.map(function (lines) {
                var text = lines.map(function (row) {
                    return row.spans.map(spanHtml).join('');
                }).join(' ');
                text = text.replace(/\s+/g, ' ').trim();
                return text ? '<p>' + text + '</p>' : '';
            }).join('');
        }

        scroller.addEventListener('copy', function (e) {
            var sel = window.getSelection();
            if (!sel || sel.isCollapsed || sel.rangeCount === 0) return;

            // Every early return below leaves the event alone, so the browser
            // performs its own copy. Reconstruction is an improvement on that,
            // never a prerequisite, if anything here is unhappy, the copy must
            // still work.
            try {
                var spans = selectedSpans(sel.getRangeAt(0));
                if (!spans || !spans.length) return;

                var rows = groupIntoLines(spans);
                if (!rows.length) return;

                var html = detectTable(rows) || paragraphsToHtml(rows);
                if (!html) return;

                var plain = rows.map(function (row) {
                    return row.spans.map(function (s) { return s.textContent; }).join('').trim();
                }).join('\n');

                e.preventDefault();
                e.clipboardData.setData('text/html', html);
                e.clipboardData.setData('text/plain', plain);
            } catch (err) {
                /* leave the clipboard to the browser */
            }
        });

        /* ----- Transient hint ----- */
        var toast = null, toastTimer = null;

        /* Shown for `ms`, or 2.6 seconds: long enough to read a mode change,
           which is all most hints are. */
        function hint(message, ms) {
            if (!message) return;
            if (!toast) {
                toast = document.createElement('div');
                toast.className = 'papel-pdf-toast';
                toast.setAttribute('role', 'status');
                toast.setAttribute('aria-live', 'polite');
                // The scroller's parent is the positioned stage in both layouts,
                // so the hint stays put while the pages scroll underneath it.
                (scroller.parentNode || scroller).appendChild(toast);
            }
            toast.textContent = message;
            toast.classList.add('is-visible');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () {
                if (toast) toast.classList.remove('is-visible');
            }, ms || 2600);
        }

        function setMode(mode, announce) {
            /* With no text layer there is nothing to select, so the view is
               always the hand: offering a mode that does nothing would be a
               control that looks broken. */
            var next = textOn ? (mode === 'pan') : true;
            var changed = (next !== panMode);
            panMode = next;
            scroller.classList.toggle('is-pan-mode', panMode);
            onMode(panMode ? 'pan' : 'select');
            if (announce && changed) {
                hint(panMode
                    ? 'Drag mode: click and drag to move the page'
                    : 'Select mode: drag across the text to copy it');
            }
        }

        /* ----- Keyboard shortcuts -----
           H for the hand, T for text. Deliberately narrow: they are ignored
           while a field or a rich-text editor has focus, so typing an "h" into
           the abstract can never flip the preview's mode, and they only apply
           while the reader is actually working in the preview. */
        var pointerOver = false;
        scroller.addEventListener('pointerenter', function () { pointerOver = true; });
        scroller.addEventListener('pointerleave', function () { pointerOver = false; });

        function isTypingTarget(el) {
            if (!el) return false;
            if (el.isContentEditable) return true;
            var tag = el.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
        }

        function engaged() {
            if (!scroller.offsetParent && scroller.offsetHeight === 0) return false;  // hidden
            var active = document.activeElement;
            return pointerOver || (active && scroller.contains(active)) || active === scroller;
        }

        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (isTypingTarget(e.target) || isTypingTarget(document.activeElement)) return;
            if (!engaged()) return;

            var k = e.key.toLowerCase();
            if (k === 'h') { e.preventDefault(); setMode('pan', true); }
            else if (k === 't' && textOn) { e.preventDefault(); setMode('select', true); }
        });

        /* ----- Dragging the pages around -----
           Left button pans in pan mode; the middle button pans in either mode,
           so the document can always be dragged without leaving text selection.
           Pointer capture keeps the drag alive if the cursor leaves the panel. */
        var dragging = false, dragId = null, startX = 0, startY = 0, startLeft = 0, startTop = 0;

        scroller.addEventListener('pointerdown', function (e) {
            // A finger always uses the scroller's native one-finger movement,
            // regardless of the mouse's select/drag mode.
            if (e.pointerType === 'touch') return;
            var wantsPan = (e.button === 1) || (panMode && e.button === 0);
            if (!wantsPan) return;
            e.preventDefault();                 // also suppresses middle-click autoscroll
            dragging = true;
            dragId = e.pointerId;
            startX = e.clientX;
            startY = e.clientY;
            startLeft = scroller.scrollLeft;
            startTop = scroller.scrollTop;
            try { scroller.setPointerCapture(e.pointerId); } catch (err) {}
            scroller.classList.add('is-panning');
        });

        scroller.addEventListener('pointermove', function (e) {
            if (!dragging || e.pointerId !== dragId) return;
            e.preventDefault();
            scroller.scrollLeft = startLeft - (e.clientX - startX);
            scroller.scrollTop = startTop - (e.clientY - startY);
        });

        function endDrag(e) {
            if (!dragging || (e && e.pointerId !== dragId)) return;
            dragging = false;
            try { scroller.releasePointerCapture(dragId); } catch (err) {}
            dragId = null;
            scroller.classList.remove('is-panning');
        }
        scroller.addEventListener('pointerup', endDrag);
        scroller.addEventListener('pointercancel', endDrag);
        // Middle click would otherwise paste or open a scroll widget on release.
        scroller.addEventListener('auxclick', function (e) {
            if (e.button === 1) e.preventDefault();
        });

        /* Let one finger scroll normally. Two fingers change the PDF's own
           zoom when released, rather than enlarging the entire website. A
           single redraw at the end avoids rerendering every page on each
           touchmove from a long thesis. */
        var pinchDistance = 0, pinchScale = 1, pinchZoom = 1;
        function distance(touches) {
            var dx = touches[0].clientX - touches[1].clientX;
            var dy = touches[0].clientY - touches[1].clientY;
            return Math.hypot(dx, dy);
        }
        scroller.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 2 || !pdfDoc) return;
            pinchDistance = distance(e.touches);
            pinchScale = 1;
            pinchZoom = zoom;
        }, { passive: true });
        scroller.addEventListener('touchmove', function (e) {
            if (!pinchDistance || e.touches.length !== 2) return;
            e.preventDefault();
            pinchScale = distance(e.touches) / pinchDistance;
        }, { passive: false });
        function finishPinch(e) {
            if (!pinchDistance || e.touches.length >= 2) return;
            pinchDistance = 0;
            setZoom(pinchZoom * pinchScale);
        }
        scroller.addEventListener('touchend', finishPinch);
        scroller.addEventListener('touchcancel', function () { pinchDistance = 0; });

        /* Ctrl/Cmd + wheel zooms, matching every other document viewer. A plain
           wheel is left alone so scrolling through the pages still works.

           A step used to be taken for every wheel EVENT. A mouse notch is one
           event, but a touchpad (two fingers with Ctrl held, or a pinch) and a
           free-spinning wheel send dozens a second, and each one took a whole
           step: a short flick crossed the range from 100% to 25% in a fifth of a
           second. So the movement is gathered, a notch's worth makes a step, and
           two steps are never closer than WHEEL_GAP. That puts a touchpad and a
           mouse at one readable pace, the same as the buttons. */
        var WHEEL_STEP = 40;     // gathered movement that makes a step (a mouse notch is 100)
        var WHEEL_GAP  = 140;    // fewest milliseconds between two wheel-driven steps
        var WHEEL_REST = 250;    // a pause this long ends a gesture, and what was gathered is let go
        var wheelGathered = 0, wheelLast = 0, wheelStepped = 0;

        scroller.addEventListener('wheel', function (e) {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();

            var now = performance.now();
            if (now - wheelLast > WHEEL_REST) wheelGathered = 0;
            wheelLast = now;

            /* Firefox reports a notch as three lines, and some mice report pages,
               not pixels. Turned into pixels so a notch is a notch everywhere. */
            var delta = e.deltaY * (e.deltaMode === 1 ? 33 : e.deltaMode === 2 ? 100 : 1);
            if (wheelGathered * delta < 0) wheelGathered = 0;     // turned the other way: start again
            wheelGathered += delta;

            if (Math.abs(wheelGathered) < WHEEL_STEP) return;
            if (now - wheelStepped < WHEEL_GAP) return;           // too soon: keep gathering
            wheelStepped = now;
            step(wheelGathered < 0 ? 1 : -1);
            wheelGathered = 0;
        }, { passive: false });

        /* Re-rasterise when the pages' own width changes: these are our
           canvases, so this is a genuine re-layout rather than a stretched
           stale image. Watched on the scroller itself, not the window: a panel
           docked, put back or dragged wider changes it while the window stands
           still. The reading place goes with it. */
        function relayout() {
            if (!pdfDoc) return;
            var w = availableWidth();
            if (w <= 0 || Math.abs(w - lastWidth) < 2) return;   // hidden, or not really moved
            render(readingPlace()).catch(function () {
                fail(options.failMessage || 'This PDF could not be displayed.');
            });
        }
        function scheduleRelayout() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(relayout, 180);
        }
        if (global.ResizeObserver) { new ResizeObserver(scheduleRelayout).observe(scroller); }
        else { global.addEventListener('resize', scheduleRelayout); }

        return {
            load: function (source) {
                if (!ready()) { say('The preview component could not be loaded.'); return Promise.resolve(); }
                say(loadingText);
                busy(true);

                /* Two loads can be in flight at once (a student picking a second
                   file before the first has opened), and whichever document
                   finished opening last used to win, so the older file could be
                   the one drawn. Only the latest load may take the view now; an
                   overtaken one lets its document go and says nothing. */
                var mine = ++loadToken;
                function overtaken() { return mine !== loadToken; }

                var asBuffer = (source instanceof ArrayBuffer)
                    ? Promise.resolve(source)
                    : source.arrayBuffer();

                return asBuffer.then(function (data) {
                    if (overtaken()) return null;
                    if (pdfDoc) { try { pdfDoc.destroy(); } catch (err) {} pdfDoc = null; }
                    /* isEvalSupported off: this PDF.js (3.11) can be made to run
                       a script hidden in a crafted font when it compiles glyphs
                       with eval (CVE-2024-4367). The CSP already forbids eval;
                       this stops it being attempted. The papers are uploaded by
                       students, so the files are not ours to trust. */
                    return pdfjsLib.getDocument({ data: data, isEvalSupported: false }).promise;
                }).then(function (doc) {
                    if (!doc) return false;
                    if (overtaken()) { try { doc.destroy(); } catch (err) {} return false; }
                    pdfDoc = doc;
                    zoom = 1;
                    onZoom(zoom);
                    return render().then(function () { return !overtaken(); });
                }).then(function (drawn) {
                    if (!drawn) return;
                    // Announced once per page load, not on every document, so it
                    // informs the first time and never nags afterwards.
                    var touchScreen = global.matchMedia &&
                        global.matchMedia('(max-width: 600px), (pointer: coarse)').matches;
                    var intro = touchScreen
                        ? (options.introTouch !== undefined ? options.introTouch
                            : 'Drag to move. Pinch to zoom.')
                        : (options.intro !== undefined ? options.intro
                            : (textOn
                                ? 'Press H to drag the page, T or Esc to select and copy text'
                                : 'Drag the page to move it. Ctrl + scroll zooms.'));
                    if (!introShown && intro) {
                        introShown = true;
                        // Ten seconds: it is the one hint that teaches the keys,
                        // and it arrives while the reader is still taking in
                        // the page, not looking for instructions.
                        hint(intro, options.introMs || 10000);
                    }
                }).catch(function () {
                    if (overtaken()) return;      // the newer load owns the status now
                    busy(false);
                    fail(options.failMessage || 'This PDF could not be previewed. You can still upload it.');
                });
            },
            pageCount: function () { return pdfDoc ? pdfDoc.numPages : 0; },
            // The page across the middle of the view, for a "page 3 of 12" label.
            currentPage: function () { var p = readingPlace(); return p ? p.page : 0; },
            zoomIn:    function () { step(1); },
            zoomOut:   function () { step(-1); },
            resetZoom: function () { setZoom(1); },
            getZoom:   function () { return zoom; },
            setMode:   function (mode) { setMode(mode, false); },
            getMode:   function () { return panMode ? 'pan' : 'select'; },
            toggleMode: function () { setMode(panMode ? 'select' : 'pan', true); },
            hint:      hint,
            hasText:   function () { return textOn; },
            protected: function () { return guardOn; },
            redraw:    render,
            clear: function () {
                settleWaiters();
                stopRendering();
                loadToken++;
                scroller.textContent = '';
                say('');
                busy(false);
            },
            destroy: function () {
                settleWaiters();
                stopRendering();
                releaseGuard();
                loadToken++;
                if (pdfDoc) { try { pdfDoc.destroy(); } catch (err) {} pdfDoc = null; }
                scroller.textContent = '';
                say('');
                busy(false);
            }
        };
    }

    global.papelPdfView = { create: create };
})(window);
