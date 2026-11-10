(function () {
    'use strict';

    // The editor owns only the export copy. The paper records behind Analytics are never written here.
    window.PapelAnalyticsWordEditor = function (container, options) {
        options = options || {};
        var root = container.querySelector('.an-doc-preview');
        if (!root) return null;
        var source = root.querySelector('.an-preview-document');
        var sequence = 0, timer = null, destroyed = false, reflowing = false;
        var selectedFigure = null, activeEdit = null, savedRange = null;
        var history = [], historyIndex = -1;
        var toolbar = document.createElement('div');
        toolbar.className = 'an-word-toolbar';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Word document editing');
        function tool(action, icon, label) {
            return '<button type="button" data-word-action="' + action + '" title="' + label +
                '" aria-label="' + label + '"><span class="material-symbols-outlined" aria-hidden="true">' + icon + '</span></button>';
        }
        toolbar.innerHTML = '<div class="an-word-tool-group">' + tool('undo', 'undo', 'Undo') + tool('redo', 'redo', 'Redo') + '</div>' +
            '<div class="an-word-tool-group">' + tool('bold', 'format_bold', 'Bold') + tool('italic', 'format_italic', 'Italic') +
            tool('underline', 'format_underlined', 'Underline') + '<select data-word-size aria-label="Font size">' +
            [8, 9, 10, 11, 12, 14, 16, 18, 24, 30, 36].map(function (size) { return '<option>' + size + '</option>'; }).join('') + '</select></div>' +
            '<div class="an-word-tool-group">' + tool('left', 'format_align_left', 'Align left') + tool('center', 'format_align_center', 'Centre') +
            tool('right', 'format_align_right', 'Align right') + tool('justify', 'format_align_justify', 'Justify text') + '</div>' +
            '<div class="an-word-tool-group">' + tool('paragraph', 'add', 'Insert paragraph') + tool('page-break', 'insert_page_break', 'Insert page break') +
            '<select data-word-orientation aria-label="Page orientation"><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select></div>' +
            '<div class="an-word-tool-group" data-word-figure-controls hidden>' + tool('earlier', 'arrow_upward', 'Move figure earlier') +
            tool('later', 'arrow_downward', 'Move figure later') + '<label>Figure width <input type="range" min="20" max="100" value="100" data-word-width></label></div>' +
            '<span class="an-word-status" aria-live="polite"></span>';
        toolbar.querySelector('[data-word-size]').value = '10';
        container.before(toolbar);
        root.classList.add('an-word-document');
        root.dataset.orientation = 'portrait';
        var initial = [];
        function flatten(parent) {
            Array.from(parent.children).forEach(function (element) {
                if (element.tagName === 'SECTION') flatten(element);
                else if (element.matches('h3, h4, p, .an-preview-table-wrap, figure')) initial.push(element);
            });
        }
        flatten(source);

        function setEditable(element, label) {
            element.contentEditable = 'true';
            element.spellcheck = true;
            element.setAttribute('aria-label', label);
        }
        function initialiseBlock(block) {
            block.classList.add('an-word-block');
            if (!block.dataset.wordGroup) block.dataset.wordGroup = 'word-' + (++sequence);
            if (block.matches('h3, h4, p')) setEditable(block, 'Edit document text');
            if (block.matches('.an-preview-table-wrap')) {
                block.querySelectorAll('tbody tr').forEach(function (row, index) { row.dataset.wordRow = block.dataset.wordGroup + '-row-' + index; });
                block.querySelectorAll('th, td').forEach(function (cell) { setEditable(cell, 'Edit table cell'); });
                block.querySelectorAll('.an-resize-handle').forEach(function (handle) {
                    handle.contentEditable = 'false'; handle.dataset.wordUi = 'resize';
                });
            }
            if (block.tagName === 'FIGURE') {
                block.contentEditable = 'false';
                block.dataset.width = block.dataset.width || '100';
                block.dataset.align = block.dataset.align || 'center';
                var image = block.querySelector('img');
                image.draggable = false;
                var box = document.createElement('div');
                box.className = 'an-word-image-box';
                image.before(box); box.appendChild(image);
                var controls = document.createElement('div');
                controls.className = 'an-word-figure-tools'; controls.dataset.wordUi = 'figure';
                controls.innerHTML = tool('drag-figure', 'drag_indicator', 'Drag figure to move it');
                box.appendChild(controls);
                var resize = document.createElement('button');
                resize.type = 'button'; resize.className = 'an-word-image-resize'; resize.dataset.wordUi = 'resize';
                resize.setAttribute('aria-label', 'Resize figure'); resize.title = 'Drag to resize figure';
                box.appendChild(resize);
                setEditable(block.querySelector('figcaption'), 'Edit figure caption');
            }
            return block;
        }
        initial.forEach(initialiseBlock);
        root.replaceChildren();

        function contentBlocks() { return Array.from(root.querySelectorAll('.an-word-content > .an-word-block')); }
        function textNodes(element) {
            var walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, {
                acceptNode: function (node) {
                    return node.parentElement.closest('[data-word-ui]') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
                }
            });
            var nodes = [], node;
            while ((node = walker.nextNode())) nodes.push(node);
            return nodes;
        }
        function pointAt(element, offset) {
            var nodes = textNodes(element);
            for (var i = 0; i < nodes.length; i++) {
                if (offset <= nodes[i].length) return [nodes[i], offset];
                offset -= nodes[i].length;
            }
            return nodes.length ? [nodes[nodes.length - 1], nodes[nodes.length - 1].length] : [element, 0];
        }
        function selectionOffsets() {
            var selection = window.getSelection();
            if (!selection.rangeCount || !root.contains(selection.anchorNode) || !root.contains(selection.focusNode)) return null;
            function offset(node, position) {
                var range = document.createRange(); range.selectNodeContents(root); range.setEnd(node, position);
                var fragment = range.cloneContents();
                fragment.querySelectorAll('[data-word-ui], .an-word-page-number').forEach(function (ui) { ui.remove(); });
                return fragment.textContent.length;
            }
            return {offsets: [offset(selection.anchorNode, selection.anchorOffset), offset(selection.focusNode, selection.focusOffset)],
                anchor: selection.anchorNode, anchorOffset: selection.anchorOffset,
                focus: selection.focusNode, focusOffset: selection.focusOffset};
        }
        function restoreOffsets(saved) {
            if (!saved) return;
            function valid(node, offset) { return node.isConnected && offset <= (node.nodeType === Node.TEXT_NODE ? node.length : node.childNodes.length); }
            if (valid(saved.anchor, saved.anchorOffset) && valid(saved.focus, saved.focusOffset)) {
                window.getSelection().setBaseAndExtent(saved.anchor, saved.anchorOffset, saved.focus, saved.focusOffset);
                return;
            }
            var offsets = saved.offsets;
            // Page numbers are outside the editable flow and must not count towards a caret's position.
            var nodes = [];
            root.querySelectorAll('.an-word-content').forEach(function (content) { nodes.push.apply(nodes, textNodes(content)); });
            function at(offset) {
                for (var i = 0; i < nodes.length; i++) {
                    if (offset <= nodes[i].length) return [nodes[i], offset];
                    offset -= nodes[i].length;
                }
                return nodes.length ? [nodes[nodes.length - 1], nodes[nodes.length - 1].length] : null;
            }
            var start = at(offsets[0]), end = at(offsets[1]);
            if (start && end) window.getSelection().setBaseAndExtent(start[0], start[1], end[0], end[1]);
        }
        function newPage() {
            var page = document.createElement('article');
            page.className = 'an-preview-document an-word-page';
            var content = document.createElement('div'); content.className = 'an-word-content';
            var number = document.createElement('div'); number.className = 'an-word-page-number';
            page.append(content, number); root.appendChild(page);
            return content;
        }
        function overflows(content) { return content.scrollHeight > content.clientHeight + 1; }
        function normaliseTable(block, width) {
            var table = block.querySelector('table');
            if (!table) return;
            var cols = Array.from(table.querySelectorAll(':scope > colgroup > col'));
            var total = cols.reduce(function (sum, col) { return sum + parseFloat(col.style.width || '100'); }, 0);
            cols.forEach(function (col) { col.style.width = (parseFloat(col.style.width || '100') * width / total) + 'px'; });
            table.style.width = width + 'px';
        }
        function mergeContinuations(blocks) {
            var merged = [];
            blocks.forEach(function (block) {
                var previous = merged[merged.length - 1];
                if (previous && previous.dataset.wordGroup === block.dataset.wordGroup && !block.matches('figure, [data-word-break]')) {
                    if (block.matches('.an-preview-table-wrap')) {
                        var body = previous.querySelector('tbody');
                        Array.from(block.querySelector('tbody').children).forEach(function (row) {
                            var last = body.lastElementChild;
                            if (last && last.dataset.wordRow && last.dataset.wordRow === row.dataset.wordRow) {
                                Array.from(row.cells).forEach(function (cell, index) {
                                    while (cell.firstChild) last.cells[index].appendChild(cell.firstChild);
                                });
                            } else body.appendChild(row);
                        });
                    } else while (block.firstChild) previous.appendChild(block.firstChild);
                } else merged.push(block);
            });
            return merged;
        }
        function tableTail(block, content) {
            var body = block.querySelector('tbody');
            if (!body || !body.rows.length) return null;
            var tail = block.cloneNode(false), table = block.querySelector('table'), next = table.cloneNode(false);
            var group = table.querySelector('colgroup'), head = table.tHead;
            if (group) next.appendChild(group.cloneNode(true));
            if (head) {
                var repeated = head.cloneNode(true); repeated.dataset.wordUi = 'repeat-header';
                repeated.querySelectorAll('[contenteditable]').forEach(function (cell) { cell.contentEditable = 'false'; });
                next.appendChild(repeated);
            }
            var tailBody = document.createElement('tbody'); next.appendChild(tailBody); tail.appendChild(next);
            while (overflows(content) && body.rows.length > 1) tailBody.prepend(body.lastElementChild);
            if (overflows(content)) {
                var row = body.rows[0], bounds = content.getBoundingClientRect();
                var scale = bounds.width / content.clientWidth;
                var room = content.clientHeight - (row.getBoundingClientRect().top - bounds.top) / scale;
                var remainder = row.cloneNode(false);
                Array.from(row.cells).forEach(function (cell) { remainder.appendChild(cell.cloneNode(false)); });
                var iterations = 0, changed = false;
                while (room > 60 && overflows(content) && iterations++ < 12) {
                    var ratio = Math.max(.1, Math.min(.85, room / row.offsetHeight * .8)), progress = false;
                    Array.from(row.cells).forEach(function (cell, index) {
                        var length = cell.textContent.length;
                        if (length < 2) return;
                        var cut = Math.max(1, Math.floor(length * ratio));
                        var point = pointAt(cell, cut), range = document.createRange();
                        range.selectNodeContents(cell); range.setStart(point[0], point[1]);
                        remainder.cells[index].prepend(range.extractContents());
                        progress = true; changed = true;
                    });
                    if (!progress) break;
                }
                if (overflows(content)) {
                    Array.from(row.cells).forEach(function (cell, index) {
                        while (remainder.cells[index].firstChild) cell.appendChild(remainder.cells[index].firstChild);
                    });
                    while (tailBody.firstChild) body.appendChild(tailBody.firstChild);
                    return null;
                }
                if (changed) tailBody.prepend(remainder);
            }
            if (!tailBody.rows.length) return null;
            return tail;
        }
        function paragraphTail(block, content) {
            if (!block.matches('p, h3, h4') || block.textContent.length < 40) return null;
            var length = block.textContent.length, low = 0, high = length - 1;
            var parent = block.parentNode;
            while (low < high) {
                var middle = Math.ceil((low + high) / 2), point = pointAt(block, middle);
                var range = document.createRange(); range.selectNodeContents(block); range.setEnd(point[0], point[1]);
                var test = block.cloneNode(false); test.appendChild(range.cloneContents());
                parent.replaceChild(test, block);
                var fits = !overflows(content);
                parent.replaceChild(block, test);
                if (fits) low = middle; else high = middle - 1;
            }
            if (low < 20) return null;
            var cut = low, text = block.textContent;
            for (var i = low; i > Math.max(20, low - 40); i--) {
                if (/\s/.test(text[i - 1])) { cut = i; break; }
            }
            var start = pointAt(block, cut), remainder = document.createRange(); remainder.selectNodeContents(block);
            remainder.setStart(start[0], start[1]);
            var tail = block.cloneNode(false); tail.appendChild(remainder.extractContents());
            return tail;
        }
        function paginate(given) {
            if (destroyed || reflowing) return;
            reflowing = true;
            var offsets = selectionOffsets();
            var queue = mergeContinuations(given || contentBlocks());
            root.replaceChildren();
            var content = newPage();
            while (queue.length) {
                var block = queue.shift();
                if (block.hasAttribute('data-word-break')) { content.appendChild(block); content = newPage(); continue; }
                normaliseTable(block, content.clientWidth);
                content.appendChild(block);
                if (overflows(content)) {
                    var tail = tableTail(block, content) || paragraphTail(block, content);
                    if (tail) { queue.unshift(tail); content = newPage(); continue; }
                    if (content.children.length > 1) {
                        block.remove();
                        var heading = content.lastElementChild;
                        content = newPage();
                        if (heading && heading.matches('h3, h4')) content.appendChild(heading);
                        content.appendChild(block);
                        tail = tableTail(block, content) || paragraphTail(block, content);
                        if (tail) { queue.unshift(tail); content = newPage(); }
                    }
                    if (block.tagName === 'FIGURE' && overflows(block.parentElement)) {
                        var scale = block.parentElement.clientHeight / block.offsetHeight;
                        setFigureWidth(block, Number(block.dataset.width) * Math.min(1, scale * .95));
                    }
                }
            }
            var pages = Array.from(root.querySelectorAll('.an-word-page'));
            pages.forEach(function (page, i) {
                page.querySelector('.an-word-page-number').textContent = (i + 1) + ' / ' + pages.length;
                page.querySelector('.an-word-content').setAttribute('aria-label', 'Document page ' + (i + 1));
            });
            toolbar.querySelector('.an-word-status').textContent = pages.length + (pages.length === 1 ? ' page' : ' pages') + ' · Edits included in download';
            restoreOffsets(offsets);
            reflowing = false;
        }
        function remember() {
            var snapshot = root.innerHTML;
            var state = {html: snapshot, orientation: root.dataset.orientation};
            if (history[historyIndex] && history[historyIndex].html === snapshot && history[historyIndex].orientation === state.orientation) return;
            history.splice(historyIndex + 1); history.push(state);
            if (history.length > 20) history.shift();
            historyIndex = history.length - 1; updateToolbar();
        }
        function flush() { if (destroyed) return; clearTimeout(timer); timer = null; paginate(); remember(); }
        function schedule() {
            if (destroyed) return;
            clearTimeout(timer); timer = setTimeout(flush, 350);
            toolbar.querySelector('[data-word-action="undo"]').disabled = false;
        }
        function updateToolbar() {
            toolbar.querySelector('[data-word-action="undo"]').disabled = historyIndex < 1;
            toolbar.querySelector('[data-word-action="redo"]').disabled = historyIndex >= history.length - 1;
            toolbar.querySelector('[data-word-figure-controls]').hidden = !selectedFigure;
            if (selectedFigure) toolbar.querySelector('[data-word-width]').value = selectedFigure.dataset.width;
            ['bold', 'italic', 'underline'].forEach(function (command) {
                toolbar.querySelector('[data-word-action="' + command + '"]').setAttribute('aria-pressed', document.queryCommandState(command) ? 'true' : 'false');
            });
        }
        function selectFigure(figure) {
            root.querySelectorAll('figure.is-selected').forEach(function (other) { other.classList.remove('is-selected'); });
            selectedFigure = figure;
            if (figure) {
                figure.classList.add('is-selected'); activeEdit = null; savedRange = null;
                window.getSelection().removeAllRanges();
            }
            updateToolbar();
        }
        function selectionChanged() {
            if (destroyed || reflowing) return;
            var selection = window.getSelection();
            if (!selection.rangeCount || !root.contains(selection.anchorNode)) return;
            var element = selection.anchorNode.nodeType === Node.ELEMENT_NODE ? selection.anchorNode : selection.anchorNode.parentElement;
            var editable = element.closest('[contenteditable="true"]');
            if (editable) {
                activeEdit = editable; savedRange = selection.getRangeAt(0).cloneRange();
                if (selectedFigure) { selectedFigure.classList.remove('is-selected'); selectedFigure = null; }
                var size = Math.round(parseFloat(getComputedStyle(element).fontSize) * .75);
                if ([8, 9, 10, 11, 12, 14, 16, 18, 24, 30, 36].includes(size)) toolbar.querySelector('[data-word-size]').value = size;
                updateToolbar();
            }
        }
        function restoreRange() {
            if (!activeEdit || !activeEdit.isConnected) activeEdit = root.querySelector('[contenteditable="true"]');
            if (!activeEdit) return;
            activeEdit.focus({preventScroll: true});
            if (savedRange && root.contains(savedRange.startContainer)) {
                var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(savedRange);
            }
        }
        function setFigureWidth(figure, width) {
            width = Math.max(20, Math.min(100, width));
            figure.dataset.width = width.toFixed(2); figure.style.width = width + '%';
        }
        function alignFigure(figure, alignment) {
            figure.dataset.align = alignment;
            figure.style.marginLeft = alignment === 'left' ? '0' : 'auto';
            figure.style.marginRight = alignment === 'right' ? '0' : 'auto';
            figure.style.textAlign = alignment;
        }
        function placeCaret(element, end) {
            element.focus({preventScroll: true});
            var range = document.createRange(); range.selectNodeContents(element); range.collapse(!end);
            var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
            activeEdit = element; savedRange = range.cloneRange();
        }
        function newParagraph() { var p = document.createElement('p'); p.appendChild(document.createElement('br')); return initialiseBlock(p); }
        function insertBlock(pageBreak) {
            var current = selectedFigure || (activeEdit && activeEdit.closest('.an-word-block')) || contentBlocks().slice(-1)[0];
            var paragraph = newParagraph();
            if (current) current.after(paragraph); else root.querySelector('.an-word-content').appendChild(paragraph);
            if (pageBreak) {
                var marker = document.createElement('div'); marker.className = 'an-word-block'; marker.dataset.wordBreak = 'true';
                paragraph.before(marker);
            }
            placeCaret(paragraph, false); flush(); paragraph.scrollIntoView({block: 'nearest'});
        }
        toolbar.addEventListener('mousedown', function (event) { if (event.target.closest('button')) event.preventDefault(); });
        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-word-action]');
            if (!button) return;
            var action = button.dataset.wordAction;
            if (action === 'undo' || action === 'redo') {
                if (timer) flush();
                var next = historyIndex + (action === 'undo' ? -1 : 1);
                if (!history[next]) return;
                historyIndex = next; root.innerHTML = history[next].html; root.dataset.orientation = history[next].orientation;
                toolbar.querySelector('[data-word-orientation]').value = root.dataset.orientation;
                selectedFigure = null; activeEdit = null; savedRange = null;
                root.querySelectorAll('figure.is-selected').forEach(function (figure) { figure.classList.remove('is-selected'); });
                paginate(); updateToolbar(); return;
            }
            if (action === 'paragraph' || action === 'page-break') { insertBlock(action === 'page-break'); return; }
            if (action === 'earlier' || action === 'later') {
                if (!selectedFigure) return;
                var all = contentBlocks(), index = all.indexOf(selectedFigure);
                var target = all[index + (action === 'earlier' ? -1 : 1)];
                if (target) { if (action === 'earlier') target.before(selectedFigure); else target.after(selectedFigure); flush(); }
                return;
            }
            if (['left', 'center', 'right', 'justify'].includes(action)) {
                if (selectedFigure) alignFigure(selectedFigure, action === 'justify' ? 'center' : action);
                else {
                    restoreRange();
                    var range = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null;
                    root.querySelectorAll('[contenteditable="true"]').forEach(function (element) {
                        if (element === activeEdit || (range && !range.collapsed && range.intersectsNode(element))) element.style.textAlign = action;
                    });
                }
                flush(); return;
            }
            restoreRange(); document.execCommand(action, false, null); schedule(); updateToolbar();
        });
        toolbar.querySelector('[data-word-size]').addEventListener('change', function (event) {
            restoreRange(); document.execCommand('fontSize', false, '7');
            root.querySelectorAll('font[size="7"]').forEach(function (font) { font.removeAttribute('size'); font.style.fontSize = event.target.value + 'pt'; });
            schedule();
        });
        toolbar.querySelector('[data-word-orientation]').addEventListener('change', function (event) {
            root.dataset.orientation = event.target.value; flush();
        });
        toolbar.querySelector('[data-word-width]').addEventListener('input', function (event) {
            if (selectedFigure) { setFigureWidth(selectedFigure, Number(event.target.value)); schedule(); }
        });
        root.addEventListener('input', schedule);
        root.addEventListener('paste', function (event) {
            if (!event.target.closest('[contenteditable="true"]')) return;
            event.preventDefault(); document.execCommand('insertText', false, event.clipboardData.getData('text/plain')); schedule();
        });
        root.addEventListener('click', function (event) {
            var figure = event.target.closest('figure');
            if (figure && !event.target.closest('[contenteditable="true"]')) selectFigure(figure);
        });
        root.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && ['z', 'y'].includes(event.key.toLowerCase())) {
                event.preventDefault(); toolbar.querySelector('[data-word-action="' + ((event.key.toLowerCase() === 'y' || event.shiftKey) ? 'redo' : 'undo') + '"]').click(); return;
            }
            var element = event.target.closest('p[contenteditable], h3[contenteditable], h4[contenteditable]');
            var selection = window.getSelection();
            if (!element || !selection.rangeCount) return;
            var range = selection.getRangeAt(0);
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault(); range.deleteContents();
                var tail = document.createRange(); tail.selectNodeContents(element); tail.setStart(range.startContainer, range.startOffset);
                var paragraph = newParagraph(); paragraph.replaceChildren(tail.extractContents());
                if (!paragraph.childNodes.length) paragraph.appendChild(document.createElement('br'));
                element.after(paragraph); placeCaret(paragraph, false); flush(); return;
            }
            if (event.key === 'Backspace' && range.collapsed) {
                var before = range.cloneRange(); before.selectNodeContents(element); before.setEnd(range.startContainer, range.startOffset);
                if (before.toString() !== '') return;
                var all = contentBlocks(), previous = all[all.indexOf(element) - 1];
                if (previous && previous.hasAttribute('data-word-break')) { event.preventDefault(); previous.remove(); flush(); }
                else if (previous && previous.matches('p, h3, h4')) {
                    event.preventDefault(); var position = previous.textContent.length;
                    while (element.firstChild) previous.appendChild(element.firstChild); element.remove();
                    var point = pointAt(previous, position); previous.focus();
                    window.getSelection().setBaseAndExtent(point[0], point[1], point[0], point[1]); flush();
                }
            }
        });
        function clearDrop() { root.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-end').forEach(function (node) { node.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-end'); }); }
        root.addEventListener('pointerdown', function (event) {
            var grip = event.target.closest('[data-word-action="drag-figure"], .an-word-image-resize');
            if (!grip || event.button !== 0) return;
            event.preventDefault(); event.stopPropagation();
            var figure = grip.closest('figure'), resizing = grip.classList.contains('an-word-image-resize');
            selectFigure(figure); grip.setPointerCapture(event.pointerId);
            var startX = event.clientX, width = figure.getBoundingClientRect().width;
            var initialWidth = Number(figure.dataset.width), destination = null, after = false, moved = false;
            var scrollFrame = null, lastPointer = null;
            function updateDrop(pointer) {
                clearDrop();
                var viewport = container.getBoundingClientRect();
                var hit = document.elementFromPoint(pointer.clientX, Math.max(viewport.top + 2, Math.min(viewport.bottom - 2, pointer.clientY)));
                destination = hit && hit.closest('.an-word-block, .an-word-content');
                if (!destination || destination === figure || !root.contains(destination)) { destination = null; return; }
                if (destination.classList.contains('an-word-block')) {
                    var rect = destination.getBoundingClientRect(); after = pointer.clientY > rect.top + rect.height / 2;
                    destination.classList.add(after ? 'is-drop-after' : 'is-drop-before');
                } else destination.classList.add('is-drop-end');
            }
            function scrollDrag() {
                if (destroyed) return;
                var viewport = container.getBoundingClientRect();
                var previous = container.scrollTop;
                if (lastPointer.clientY < viewport.top + 35) container.scrollTop -= 12;
                if (lastPointer.clientY > viewport.bottom - 35) container.scrollTop += 12;
                if (container.scrollTop !== previous) updateDrop(lastPointer);
                scrollFrame = requestAnimationFrame(scrollDrag);
            }
            function move(pointer) {
                if (resizing) { setFigureWidth(figure, initialWidth * (width + pointer.clientX - startX) / width); updateToolbar(); return; }
                moved = true; figure.classList.add('an-word-dragging');
                lastPointer = {clientX: pointer.clientX, clientY: pointer.clientY}; updateDrop(lastPointer);
                if (scrollFrame === null) scrollFrame = requestAnimationFrame(scrollDrag);
            }
            function finish(pointer) {
                if (scrollFrame !== null) cancelAnimationFrame(scrollFrame);
                grip.removeEventListener('pointermove', move); grip.removeEventListener('pointerup', finish); grip.removeEventListener('pointercancel', finish);
                if (pointer.type !== 'pointercancel' && moved && destination) {
                    if (destination.classList.contains('an-word-content')) destination.appendChild(figure);
                    else if (after) destination.after(figure); else destination.before(figure);
                }
                figure.classList.remove('an-word-dragging'); clearDrop(); flush();
            }
            grip.addEventListener('pointermove', move); grip.addEventListener('pointerup', finish); grip.addEventListener('pointercancel', finish);
        });
        function syncTableWidths(event) {
            var handle = event.target.closest('.an-resize-handle');
            if (!handle || destroyed) return;
            var block = handle.closest('.an-word-block'), table = handle.closest('table');
            var widths = Array.from(table.querySelectorAll('colgroup col')).map(function (col) { return col.style.width; });
            contentBlocks().forEach(function (other) {
                if (other === block || other.dataset.wordGroup !== block.dataset.wordGroup) return;
                other.querySelectorAll('colgroup col').forEach(function (col, index) { col.style.width = widths[index]; });
            });
            schedule();
        }
        root.addEventListener('pointerup', syncTableWidths);
        root.addEventListener('keyup', syncTableWidths);
        root.addEventListener('dblclick', function (event) { setTimeout(function () { syncTableWidths(event); }, 0); });
        root.addEventListener('dragstart', function (event) { event.preventDefault(); });
        document.addEventListener('selectionchange', selectionChanged);

        function runsFrom(element) {
            var runs = [];
            function visit(node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    if (!node.textContent) return;
                    var style = getComputedStyle(node.parentElement);
                    var underlined = false, ancestor = node.parentElement;
                    while (ancestor && ancestor !== element.parentElement) {
                        if (getComputedStyle(ancestor).textDecorationLine.includes('underline')) underlined = true;
                        ancestor = ancestor.parentElement;
                    }
                    runs.push({text: node.textContent, bold: Number(style.fontWeight) >= 600 || style.fontWeight === 'bold',
                        italic: style.fontStyle === 'italic', underline: underlined, size: Math.round(parseFloat(style.fontSize) * .75 * 10) / 10});
                } else if (node.nodeType === Node.ELEMENT_NODE && !node.hasAttribute('data-word-ui')) {
                    if (node.tagName === 'BR') { runs.push({text: '\n'}); return; }
                    Array.from(node.childNodes).forEach(visit);
                    if (node !== element && node.matches('div, p') && node.nextSibling) runs.push({text: '\n'});
                }
            }
            visit(element); return runs;
        }
        function serialise() {
            flush();
            return {orientation: root.dataset.orientation, pages: Array.from(root.querySelectorAll('.an-word-content')).map(function (page) {
                return {blocks: Array.from(page.children).filter(function (block) { return !block.hasAttribute('data-word-break'); }).map(function (block) {
                    if (block.tagName === 'FIGURE') return {type: 'image', figure: Number(block.dataset.figureIndex), width: Number(block.dataset.width),
                        align: block.dataset.align, captionAlign: getComputedStyle(block.querySelector('figcaption')).textAlign,
                        caption: runsFrom(block.querySelector('figcaption'))};
                    var table = block.querySelector('table');
                    if (table) return {type: 'table', widths: Array.from(table.querySelectorAll('colgroup col')).map(function (col) { return parseFloat(col.style.width); }),
                        rows: Array.from(table.rows).map(function (row) { return {header: row.parentElement.tagName === 'THEAD',
                            cells: Array.from(row.cells).map(function (cell) { return {runs: runsFrom(cell), align: getComputedStyle(cell).textAlign}; })}; })};
                    return {type: 'paragraph', kind: block.tagName === 'H3' ? 'title' : (block.tagName === 'H4' ? 'heading' : 'text'),
                        align: getComputedStyle(block).textAlign, runs: runsFrom(block)};
                })};
            })};
        }
        paginate(initial); remember();
        root.querySelectorAll('img').forEach(function (image) { if (!image.complete) image.addEventListener('load', schedule, {once: true}); });
        return {serialise: serialise, flush: flush, destroy: function () {
            destroyed = true; clearTimeout(timer); document.removeEventListener('selectionchange', selectionChanged); toolbar.remove();
        }};
    };
})();
