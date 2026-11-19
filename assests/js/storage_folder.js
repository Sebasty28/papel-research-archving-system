document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const layout = document.getElementById('storageLayout');
    if (!layout) return;

    // The page's subtitle, behind the info mark: hover, focus, or a press for a finger.
    const infoBtn = document.getElementById('storageInfoBtn');
    const infoTip = document.getElementById('storageInfo');
    if (infoBtn && infoTip) {
        let infoShut = null;
        const showInfo = function () { clearTimeout(infoShut); infoTip.classList.add('is-open'); infoBtn.setAttribute('aria-expanded', 'true'); };
        const hideInfo = function () { clearTimeout(infoShut); infoTip.classList.remove('is-open'); infoBtn.setAttribute('aria-expanded', 'false'); };
        const hideSoon = function () { clearTimeout(infoShut); infoShut = setTimeout(hideInfo, 120); };
        infoBtn.addEventListener('mouseenter', showInfo);
        infoBtn.addEventListener('mouseleave', hideSoon);
        infoBtn.addEventListener('focus', showInfo);
        infoBtn.addEventListener('blur', hideInfo);
        infoBtn.addEventListener('click', function (event) {
            event.preventDefault();
            if (infoTip.classList.contains('is-open') && event.detail === 0) hideInfo(); else showInfo();
        });
        infoTip.addEventListener('mouseenter', function () { clearTimeout(infoShut); });
        infoTip.addEventListener('mouseleave', hideSoon);
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') hideInfo(); });
        document.addEventListener('pointerdown', function (event) {
            if (!event.target.closest('#storageInfoBtn, #storageInfo')) hideInfo();
        });
    }

    // The PDF lists, expanded to the window under the navbar and back.
    const expand = document.getElementById('storagePdfExpand');
    const pdfPane = document.querySelector('.storage-pdfs');
    if (expand && pdfPane) {
        const isFull = function () { return document.documentElement.classList.contains('storage-pdf-full'); };
        /* The Accessibility tab floats on one edge of the window, over the first
           letters of every line once the pane runs to that edge. Measured, not
           assumed: the reader can hide it, and a phone shows it only on request. */
        const clearTab = function () {
            const tab = document.getElementById('a11y-toggle');
            let left = 6, right = 6;
            if (tab) {
                const r = tab.getBoundingClientRect();
                const seen = r.width > 0 && getComputedStyle(tab).visibility !== 'hidden';
                if (seen && r.left < 80) left = Math.ceil(r.right) + 6;
                else if (seen && r.right > window.innerWidth - 80) right = Math.ceil(window.innerWidth - r.left) + 6;
            }
            pdfPane.style.setProperty('--storage-full-left', left + 'px');
            pdfPane.style.setProperty('--storage-full-right', right + 'px');
        };
        window.addEventListener('resize', function () { if (isFull()) clearTab(); });
        document.addEventListener('papel:a11y-tab', function () { if (isFull()) clearTab(); });
        const setFull = function (on) {
            if (on) clearTab();
            document.documentElement.classList.toggle('storage-pdf-full', on);
            expand.setAttribute('aria-pressed', String(on));
            expand.setAttribute('aria-label', on ? 'Restore the PDF lists to their pane' : 'Expand the PDF lists to fill the page');
            expand.title = on ? 'Restore' : 'Expand to fill the page';
            expand.querySelector('.material-symbols-outlined').textContent = on ? 'close_fullscreen' : 'open_in_full';
        };
        expand.addEventListener('click', function () { setFull(!isFull()); });
        // Escape restores it, unless a dialog is up: that Escape is the dialog's.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isFull() && !document.querySelector('dialog[open], .papel-dialog-backdrop.open')) setFull(false);
        });
    }

    // Export: the choice in a dialog, the file into a hidden frame.
    const exportOpen = document.getElementById('storageExportOpen');
    const exportDialog = document.getElementById('storageExportDialog');
    const exportForm = document.getElementById('storageExportForm');
    if (exportOpen && exportDialog && exportForm && typeof exportDialog.showModal === 'function') {
        const exportError = document.getElementById('storageExportError');
        exportOpen.addEventListener('click', function () { exportDialog.showModal(); });
        exportDialog.querySelectorAll('[data-export-close]').forEach(function (button) {
            button.addEventListener('click', function () { exportDialog.close(); });
        });
        exportDialog.addEventListener('click', function (event) { if (event.target === exportDialog) exportDialog.close(); });
        exportDialog.addEventListener('close', function () { exportError.hidden = true; exportOpen.focus(); });
        exportForm.addEventListener('change', function () { exportError.hidden = true; });
        exportForm.addEventListener('submit', function (event) {
            if (!exportForm.querySelector('input[name="scopes[]"]:checked')) {
                event.preventDefault();
                exportError.hidden = false;
                exportForm.querySelector('input[name="scopes[]"]').focus();
                return;
            }
            // A download does not navigate away, so nothing else would close it.
            setTimeout(function () { if (exportDialog.open) exportDialog.close(); }, 0);
        });
    }
    const tabs = Array.from(document.querySelectorAll('.storage-tab'));
    const settingsBody = document.getElementById('storageSettingsBody');
    function showTab(tab, focus) {
        tabs.forEach(function (item) {
            const active = item === tab;
            item.setAttribute('aria-selected', String(active));
            item.tabIndex = active ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !active;
        });
        settingsBody.scrollTop = 0;
        if (focus) tab.focus();
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { showTab(tab, false); });
        tab.addEventListener('keydown', function (event) {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next !== undefined) { event.preventDefault(); showTab(tabs[next], true); }
        });
    });
    function followHash() {
        const target = document.getElementById(location.hash.slice(1));
        const panel = target && target.closest('[role="tabpanel"]');
        if (!panel) return;
        const tab = tabs.find(function (item) { return item.getAttribute('aria-controls') === panel.id; });
        if (tab) showTab(tab, false);
    }
    followHash();
    window.addEventListener('hashchange', followHash);
    document.querySelectorAll('.set-reminder-banner a').forEach(function (link) {
        link.addEventListener('click', function () { showTab(document.getElementById('storage-tab-reminders'), false); });
    });
    const grip = document.getElementById('storageGrip');
    let width = 40;
    try { width = Number(localStorage.getItem('papel.storagePanelWidth')) || 40; } catch (_) {}
    function resize(value) {
        width = Math.min(65, Math.max(25, value));
        layout.style.setProperty('--storage-left', width + '%');
        grip.setAttribute('aria-valuenow', String(Math.round(width)));
    }
    function rememberWidth() { try { localStorage.setItem('papel.storagePanelWidth', String(width)); } catch (_) {} }
    resize(width);
    grip.addEventListener('pointerdown', function (event) {
        if (event.button !== 0) return;
        event.preventDefault();
        grip.setPointerCapture(event.pointerId);
        function move(e) {
            const rect = layout.getBoundingClientRect();
            resize(100 * (e.clientX - rect.left) / rect.width);
        }
        function end() {
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('lostpointercapture', end);
            rememberWidth();
        }
        grip.addEventListener('pointermove', move);
        grip.addEventListener('lostpointercapture', end);
    });
    grip.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        resize(width + (event.key === 'ArrowLeft' ? -2 : 2));
        rememberWidth();
    });
    function fit() {
        layout.style.setProperty('--storage-height', Math.max(440, window.innerHeight - layout.getBoundingClientRect().top - 20) + 'px');
    }
    fit();
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);

    const host = document.getElementById('storagePdfGroups');
    const search = document.getElementById('storagePdfSearch');
    const filters = Array.from(document.querySelectorAll('.storage-filter'));
    const pages = new Map();
    let filter = 'all';
    const perPage = 15;
    function applyFilters(reset) {
        if (reset) pages.clear();
        const query = search.value.trim().toLowerCase();
        let visibleGroups = 0;
        host.querySelectorAll('.storage-account').forEach(function (group) {
            const category = group.dataset.category;
            const categoryMatches = filter === 'all' || (filter === 'current' ? category === 'current' : ['previous', 'other'].includes(category));
            const rows = Array.from(group.querySelectorAll('.storage-file'));
            const matches = rows.filter(function (row) { return row.dataset.search.includes(query); });
            group.hidden = !categoryMatches || (!!query && !matches.length);
            if (!group.hidden) visibleGroups++;
            if (query && !group.hidden) group.open = true;
            const totalPages = Math.max(1, Math.ceil(matches.length / perPage));
            const page = Math.min(pages.get(group.dataset.account) || 1, totalPages);
            pages.set(group.dataset.account, page);
            const displayed = new Set(matches.slice((page - 1) * perPage, page * perPage));
            rows.forEach(function (row) { row.hidden = !displayed.has(row); });
            const nav = group.querySelector('.storage-file-pages');
            nav.hidden = totalPages <= 1;
            nav.querySelector('span').textContent = 'Page ' + page + ' of ' + totalPages;
            nav.querySelector('[data-page="previous"]').disabled = page === 1;
            nav.querySelector('[data-page="next"]').disabled = page === totalPages;
        });
        const empty = host.querySelector('.storage-no-match');
        if (empty) empty.hidden = visibleGroups > 0;
    }
    filters.forEach(function (button) {
        button.addEventListener('click', function () {
            filter = button.dataset.filter;
            filters.forEach(function (other) { other.setAttribute('aria-pressed', String(other === button)); });
            applyFilters(true);
            if (filter !== 'all') host.querySelectorAll('.storage-account:not([hidden])').forEach(function (group) { group.open = true; });
        });
    });
    search.addEventListener('input', function () { applyFilters(true); });
    host.addEventListener('click', function (event) {
        const button = event.target.closest('[data-page]');
        if (!button) return;
        const group = button.closest('.storage-account');
        const page = pages.get(group.dataset.account) || 1;
        pages.set(group.dataset.account, page + (button.dataset.page === 'next' ? 1 : -1));
        applyFilters(false);
        group.querySelector('summary').scrollIntoView({block: 'nearest'});
    });
    applyFilters(true);

    const check = document.getElementById('checkPdfAccounts');
    const stop = document.getElementById('stopPdfCheck');
    const status = document.getElementById('pdfCheckStatus');
    let busy = false, stopped = false;
    stop.addEventListener('click', function () { stopped = true; stop.disabled = true; status.textContent = 'Finishing the current four PDFs…'; });
    async function refreshLists() {
        const open = new Set(Array.from(host.querySelectorAll('.storage-account[open]')).map(function (group) { return group.dataset.account; }));
        const response = await fetch('gdrive_settings.php?pdf_inventory=1', {credentials: 'same-origin', cache: 'no-store'});
        const html = await response.text();
        if (!response.ok || !html.includes('storage-pdf-data')) throw new Error('Reload Storage Folder to see the saved results.');
        host.innerHTML = html;
        host.querySelectorAll('.storage-account').forEach(function (group) { if (open.has(group.dataset.account)) group.open = true; });
        applyFilters(false);
    }
    async function scan(pendingOnly) {
        if (busy || check.disabled) return;
        const data = host.querySelector('.storage-pdf-data');
        const ids = JSON.parse(pendingOnly ? data.dataset.pending : data.dataset.ids);
        if (!ids.length) { if (!pendingOnly) status.textContent = 'No PDFs need checking.'; return; }
        busy = true; stopped = false; check.disabled = true; stop.hidden = false; stop.disabled = false;
        let completed = 0, failure = '';
        try {
            for (let i = 0; i < ids.length && !stopped; i += 4) {
                status.textContent = 'Checking PDF accounts: ' + completed + ' of ' + ids.length + '…';
                const batch = ids.slice(i, i + 4);
                const form = new URLSearchParams({action: 'check_pdf_accounts', _token: host.dataset.token});
                batch.forEach(function (id) { form.append('file_ids[]', id); });
                const response = await fetch('gdrive_settings.php', {method: 'POST', body: form, credentials: 'same-origin'});
                let result;
                try { result = await response.json(); } catch (_) { throw new Error('The session or connection expired. Reload the page and try again.'); }
                if (!response.ok || !result.success) throw new Error(result.message || 'The PDF account check could not finish.');
                completed += batch.length;
            }
        } catch (error) { failure = error.message; }
        try { await refreshLists(); } catch (error) { failure = failure || error.message; }
        busy = false; check.disabled = false; stop.hidden = true;
        status.textContent = failure || (stopped ? 'Stopped. ' : 'Check complete. ') + completed + ' of ' + ids.length + ' PDFs checked. See each list for the results.';
    }
    check.addEventListener('click', function () { scan(false); });
    if (host.dataset.autoCheck === '1') scan(true);

    /* Coming back from Google: say what was connected and whether that account can
       use the folder. Locked on <html>, never <body>, or the sticky navbar scrolls away. */
    const connected = document.getElementById('connectedDialog');
    if (connected) {
        const shut = function () {
            connected.classList.remove('open');
            document.documentElement.style.overflow = '';
        };
        connected.addEventListener('click', function (event) {
            if (event.target === connected || event.target.closest('[data-connected-close]')) { shut(); return; }
            if (!event.target.closest('[data-connected-folder]')) return;
            shut();
            showTab(document.getElementById('storage-tab-connection'), false);
            const input = document.getElementById('folderInput');
            const card = input.closest('[data-collapse-host]');
            if (card && card.getAttribute('data-collapsed') === '1') card.querySelector('.card-collapse-btn').click();
            input.scrollIntoView({block: 'center'});
            input.focus({preventScroll: true});
            input.select();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && connected.classList.contains('open')) shut();
        });
        connected.classList.add('open');
        document.documentElement.style.overflow = 'hidden';
        const first = connected.querySelector('[data-connected-folder], [data-connected-close]');
        if (first) first.focus({preventScroll: true});
    }
});
