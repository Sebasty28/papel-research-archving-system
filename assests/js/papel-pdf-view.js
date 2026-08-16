/**
 * PAPEL PDF viewer.
 *
 * Renders a PDF onto canvases we own, rather than handing it to the browser's
 * built-in viewer. That viewer lays out once against whatever size its frame
 * happens to have and never re-measures, which left it painting into a strip of
 * the preview panel no matter how the frame was sized. Drawing the pages here
 * keeps the geometry — and therefore zoom, panning and text selection — under
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
 *       workerSrc: '/path/to/pdf.worker.min.js'
 *   });
 *   view.load(fileOrArrayBuffer);
 */
(function (global) {
    'use strict';

    var MIN_ZOOM = 0.25;
    var MAX_ZOOM = 5;
    var STEPS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 2.5, 3, 4, 5];

    function create(options) {
        var scroller = options.scroller;
        var statusEl = options.status || null;
        var onZoom = options.onZoom || function () {};
        var onMode = options.onMode || function () {};

        var pdfDoc = null;
        var zoom = 1;              // 1 = fit the scroller's width
        var renderToken = 0;       // bumped to abandon a superseded render
        var workerReady = false;
        var resizeTimer = null;
        var panMode = false;
        var introShown = false;

        function say(msg) {
            if (statusEl) statusEl.textContent = msg || '';
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

        function render() {
            var token = ++renderToken;
            if (!pdfDoc) return Promise.resolve();

            var base = availableWidth();
            if (base <= 0) return Promise.resolve();

            var ratio = global.devicePixelRatio || 1;
            scroller.textContent = '';

            var page = 1;

            function next() {
                if (token !== renderToken || page > pdfDoc.numPages) return Promise.resolve();

                return pdfDoc.getPage(page).then(function (p) {
                    if (token !== renderToken) return;

                    var natural = p.getViewport({ scale: 1 });
                    // Fit the width first, then apply the zoom multiplier on
                    // top, so 100% always means "as wide as the panel".
                    var scale = (base / natural.width) * zoom;
                    var viewport = p.getViewport({ scale: scale });

                    // Wrapper so the text layer can sit exactly over the canvas.
                    var wrap = document.createElement('div');
                    wrap.className = 'papel-pdf-page';
                    wrap.style.width = Math.floor(viewport.width) + 'px';
                    wrap.style.height = Math.floor(viewport.height) + 'px';

                    var canvas = document.createElement('canvas');
                    canvas.className = 'papel-pdf-canvas';
                    canvas.setAttribute('role', 'img');
                    canvas.setAttribute('aria-label', 'Page ' + page + ' of ' + pdfDoc.numPages);
                    // Backing store in device pixels keeps text crisp; the CSS
                    // size stays in layout pixels.
                    canvas.width = Math.floor(viewport.width * ratio);
                    canvas.height = Math.floor(viewport.height * ratio);

                    var layer = document.createElement('div');
                    layer.className = 'papel-pdf-textlayer';
                    // PDF.js 3.x sizes the text spans from this custom property.
                    layer.style.setProperty('--scale-factor', scale);

                    wrap.appendChild(canvas);
                    wrap.appendChild(layer);
                    scroller.appendChild(wrap);

                    var pageNo = page;
                    return p.render({
                        canvasContext: canvas.getContext('2d'),
                        viewport: viewport,
                        transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null
                    }).promise.then(function () {
                        if (token !== renderToken) return;
                        return p.getTextContent();
                    }).then(function (textContent) {
                        if (token !== renderToken || !textContent) return;
                        // Selectable, copyable text sitting invisibly on top of
                        // the rendered picture.
                        const divs = [];
                        return pdfjsLib.renderTextLayer({
                            textContentSource: textContent,
                            container: layer,
                            viewport: viewport,
                            textDivs: divs
                        }).promise.then(function () {
                            markBold(p, textContent, divs);
                        });
                    }).then(function () {
                        if (token !== renderToken) return;
                        if (pageNo === 1) say('');
                        page++;
                        return next();
                    });
                });
            }

            return next().catch(function () {
                if (token === renderToken) say('This PDF could not be displayed.');
            });
        }

        function setZoom(value) {
            var clamped = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));
            if (Math.abs(clamped - zoom) < 0.001) return;

            // Keep whatever is in the middle of the view roughly in place, so
            // zooming does not throw the reader back to the top of the document.
            var anchor = scroller.scrollHeight > 0
                ? (scroller.scrollTop + scroller.clientHeight / 2) / scroller.scrollHeight
                : 0;

            zoom = clamped;
            onZoom(zoom);
            render().then(function () {
                scroller.scrollTop = Math.max(
                    0, anchor * scroller.scrollHeight - scroller.clientHeight / 2
                );
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

           A PDF carries no paragraphs, no bold tags and no tables — only glyphs
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
                // child of the page — no closest() walk needed per span.
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

            // Numeric compare — no DOM work inside the comparator.
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
           left-aligned — centred cells, which is how most academic tables set
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

            // A lane may be written into by a few rows — a merged heading, a
            // footnote marker — and still be a column boundary.
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
           by ending the previous line short of the margin — never with a blank
           line — which is why joining on line breaks alone produced one block. */
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
            // never a prerequisite — if anything here is unhappy, the copy must
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

        function hint(message) {
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
            }, 2600);
        }

        function setMode(mode, announce) {
            var next = (mode === 'pan');
            var changed = (next !== panMode);
            panMode = next;
            scroller.classList.toggle('is-pan-mode', panMode);
            onMode(panMode ? 'pan' : 'select');
            if (announce && changed) {
                hint(panMode
                    ? 'Drag mode — click and drag to move the page'
                    : 'Select mode — drag across the text to copy it');
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
            else if (k === 't') { e.preventDefault(); setMode('select', true); }
        });

        /* ----- Dragging the pages around -----
           Left button pans in pan mode; the middle button pans in either mode,
           so the document can always be dragged without leaving text selection.
           Pointer capture keeps the drag alive if the cursor leaves the panel. */
        var dragging = false, dragId = null, startX = 0, startY = 0, startLeft = 0, startTop = 0;

        scroller.addEventListener('pointerdown', function (e) {
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

        // Ctrl/Cmd + wheel zooms, matching every other document viewer. A plain
        // wheel is left alone so scrolling through the pages still works.
        scroller.addEventListener('wheel', function (e) {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            step(e.deltaY < 0 ? 1 : -1);
        }, { passive: false });

        global.addEventListener('resize', function () {
            if (!pdfDoc) return;
            clearTimeout(resizeTimer);
            // Re-rasterise at the new width — these are our canvases, so this is
            // a genuine re-layout rather than a stretched stale image.
            resizeTimer = setTimeout(render, 200);
        });

        return {
            load: function (source) {
                if (!ready()) { say('The preview component could not be loaded.'); return Promise.resolve(); }
                say('Loading preview...');

                var asBuffer = (source instanceof ArrayBuffer)
                    ? Promise.resolve(source)
                    : source.arrayBuffer();

                return asBuffer.then(function (data) {
                    if (pdfDoc) { try { pdfDoc.destroy(); } catch (err) {} pdfDoc = null; }
                    return pdfjsLib.getDocument({ data: data }).promise;
                }).then(function (doc) {
                    pdfDoc = doc;
                    zoom = 1;
                    onZoom(zoom);
                    return render();
                }).then(function () {
                    // Announced once per page load, not on every document, so it
                    // informs the first time and never nags afterwards.
                    if (!introShown) {
                        introShown = true;
                        hint('Press H to drag the page, T or Esc to select and copy text');
                    }
                }).catch(function () {
                    say('This PDF could not be previewed. You can still upload it.');
                });
            },
            zoomIn:    function () { step(1); },
            zoomOut:   function () { step(-1); },
            resetZoom: function () { setZoom(1); },
            getZoom:   function () { return zoom; },
            setMode:   function (mode) { setMode(mode, false); },
            getMode:   function () { return panMode ? 'pan' : 'select'; },
            toggleMode: function () { setMode(panMode ? 'select' : 'pan', true); },
            hint:      hint,
            redraw:    render,
            clear: function () {
                renderToken++;
                scroller.textContent = '';
                say('');
            },
            destroy: function () {
                renderToken++;
                if (pdfDoc) { try { pdfDoc.destroy(); } catch (err) {} pdfDoc = null; }
                scroller.textContent = '';
                say('');
            }
        };
    }

    global.papelPdfView = { create: create };
})(window);
