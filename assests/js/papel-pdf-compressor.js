/* Compress selected PDFs before their File objects reach FormData. No network upload. */
(function () {
    'use strict';
    const workerUrl = new URL(document.currentScript.dataset.worker || 'pdf-compression/worker.js', document.currentScript.src);
    const mode = document.currentScript.dataset.mode === 'lossless' ? 'lossless' : 'smallest';
    const states = new Map();
    const completed = new WeakSet();
    const threshold = 10 * 1024 * 1024;
    const automaticLimit = 36 * 1024 * 1024;
    let queue = Promise.resolve();
    const size = bytes => bytes < 1048576 ? Math.ceil(bytes / 1024) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB';
    function note(input, message) {
        let el = input.parentElement.querySelector('.pdf-compression-status');
        if (!el) {
            el = document.createElement('small');
            el.className = 'pdf-compression-status';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            input.parentElement.appendChild(el);
        }
        el.textContent = message;
        el.hidden = !message;
        document.dispatchEvent(new Event('papel:pdf-compression'));
    }
    function run(file) {
        if (file.size >= automaticLimit) return Promise.resolve({ reason: 'capacity' });
        return new Promise((resolve) => {
            let worker;
            let timer;
            const done = result => {
                clearTimeout(timer);
                if (worker) worker.terminate();
                resolve(result);
            };
            try {
                worker = new Worker(workerUrl);
                timer = setTimeout(() => done({ reason: 'unavailable' }), 60000);
                worker.onmessage = e => done(e.data);
                worker.onerror = () => done({ reason: 'unavailable' });
                file.arrayBuffer().then(bytes => worker.postMessage({ bytes, mode }, [bytes])).catch(() => done({ reason: 'unavailable' }));
            } catch (_) { done({ reason: 'unavailable' }); }
        });
    }
    function changed(input) {
        const file = input.files && input.files[0];
        const previous = states.get(input);
        if (previous && previous.file === file) return;
        states.delete(input);
        if (!file || file.size < threshold || !(/\.pdf$/i.test(file.name) || file.type === 'application/pdf')) {
            note(input, '');
            return;
        }
        if (file.size >= automaticLimit) {
            note(input, 'Automatic compression is not available for files of 36 MB or more. Please compress this PDF first, then select it again. The upload limit is 30 MB.');
            return;
        }
        if (completed.has(file)) return;
        const state = { file, pending: true };
        states.set(input, state);
        note(input, 'Preparing ' + (mode === 'smallest' ? 'Smallest mode' : 'lossless compression') + ' (' + size(file.size) + ')...');
        // One worker at a time bounds memory when both manuscripts are attached together.
        state.promise = queue = queue.then(async () => {
            if (!input.isConnected || input.files[0] !== file) return;
            note(input, mode === 'smallest' ? 'Compressing PDF: reducing image size and quality...' : 'Compressing PDF without reducing quality...');
            const result = await run(file);
            if (!input.isConnected || input.files[0] !== file || states.get(input) !== state) return;
            let message;
            if (result.bytes && result.bytes.length < file.size) {
                try {
                    const replacement = new File([result.bytes], file.name, { type: 'application/pdf', lastModified: file.lastModified });
                    const transfer = new DataTransfer();
                    transfer.items.add(replacement);
                    input.files = transfer.files;
                    state.file = input.files[0];
                    completed.add(state.file);
                    message = (result.lossyImages ? 'Smallest mode: ' : 'Losslessly compressed: ') + size(file.size) + ' \u2192 ' + size(replacement.size)
                        + ' (' + Math.min(99.9, (1 - replacement.size / file.size) * 100).toFixed(1) + '% smaller).'
                        + (result.lossyImages ? ' Image quality reduced; preview before submitting.' : '');
                    // All existing preview, size, draft and drop-zone listeners see the final file.
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (_) { message = 'Compression could not be applied. Original PDF retained.'; }
            } else {
                message = result.reason === 'unchanged'
                    ? 'No further size saving available. Original PDF retained (' + size(file.size) + ').'
                    : 'Original PDF retained to preserve its contents. Safe compression was unavailable.';
            }
            note(input, message);
            if (state.file !== file) {
                const restore = document.createElement('button');
                restore.type = 'button';
                restore.className = 'pdf-compression-restore';
                restore.textContent = 'Use original';
                restore.addEventListener('click', () => {
                    if (states.get(input) !== state) return;
                    const transfer = new DataTransfer();
                    transfer.items.add(file);
                    input.files = transfer.files;
                    state.file = input.files[0];
                    completed.add(state.file);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    note(input, 'Original PDF selected (' + size(file.size) + ').');
                });
                input.parentElement.querySelector('.pdf-compression-status').append(' ', restore);
            }
        }).catch(() => {
            if (states.get(input) === state) note(input, 'Compression unavailable. Original PDF retained.');
        }).finally(() => {
            state.pending = false;
            document.dispatchEvent(new Event('papel:pdf-compression'));
        });
    }
    document.addEventListener('change', e => {
        if (e.target.matches('#uploadForm input[type="file"]')) changed(e.target);
    }, true);
    function pending() {
        return Array.from(states.entries()).some(([input, state]) =>
            input.isConnected && input.files[0] === state.file && state.pending);
    }
    window.papelPdfCompressor = {
        pending,
        clear(input) { states.delete(input); note(input, ''); }
    };
    // Capture before the existing submit handler can create FormData.
    document.addEventListener('submit', e => {
        if (e.target.id !== 'uploadForm' || !pending()) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (window.papelAlert) window.papelAlert('Your PDF is still being compressed. Please wait for it to finish, then submit.');
    }, true);
})();
