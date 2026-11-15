/* Keep the issued ID's letters and separators in the box while only its
   numeric slots can be typed. Existing older IDs remain intact on edit. */
(function () {
    'use strict';
    function digits(value, kind) {
        value = String(value || '').toUpperCase();
        if (kind === 'faculty') {
            return value.replace(/^FA/, '').replace(/BN20/, '').replace(/\D/g, '').slice(0, 6);
        }
        return value.replace(/^20/, '').replace(/-BN-/, '').replace(/\D/g, '').slice(0, 8);
    }
    function display(raw, kind) {
        if (kind === 'faculty') {
            return 'FA' + raw.slice(0, 4) + (raw.length >= 4 ? 'BN20' + raw.slice(4, 6) : '');
        }
        return '20' + raw.slice(0, 2) + (raw.length >= 2 ? '-' + raw.slice(2, 7) : '')
            + (raw.length >= 7 ? '-BN-' + raw.slice(7, 8) : '');
    }
    function caret(value, kind, count) {
        if (!count) { return 2; }
        for (var i = 2; i < value.length; i++) {
            if (/\d/.test(value[i]) && !(kind === 'faculty' && i >= 8 && i < 10)) {
                count--;
                if (!count) { return i + 1; }
            }
        }
        return value.length;
    }
    function wire(el) {
        var kind = el.dataset.idMask;
        function update() {
            var keeping = el.dataset.keeping || '';
            if (keeping && el.value === keeping && !/^(?:FA\d{4}BN20\d{2}|20\d{2}-\d{5}-BN-\d)$/.test(keeping)) {
                return;
            }
            var before = el.value.slice(0, el.selectionStart == null ? el.value.length : el.selectionStart);
            var count = digits(before, kind).length;
            var next = display(digits(el.value, kind), kind);
            if (el.value !== next) { el.value = next; }
            if (document.activeElement === el) {
                var at = caret(next, kind, count);
                el.setSelectionRange(at, at);
            }
        }
        el.addEventListener('input', update);
        el.addEventListener('focus', function () { if (!el.value) { el.value = kind === 'faculty' ? 'FA' : '20'; } });
        if (el.form) {
            el.form.addEventListener('reset', function () {
                setTimeout(function () { el.value = kind === 'faculty' ? 'FA' : '20'; }, 0);
            });
        }
        if (!el.value) { el.value = kind === 'faculty' ? 'FA' : '20'; }
    }
    document.querySelectorAll('input[data-id-mask]').forEach(wire);
})();
