<?php
/**
 * Shared browse-console behaviour: the Quick Settings dropdown (density /
 * theme) and the collapsible sidebar cards.
 *
 * These controls live inside markup that AJAX result swaps replace, so every
 * handler is delegated off `document` rather than bound directly. After a
 * swap, call window.papelSyncQuickSettings() to re-tick the controls.
 *
 * Include once near the end of <body>, before includes/site_footer.php.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    function getStored(key, fallback) {
        try { return localStorage.getItem(key) || fallback; } catch (err) { return fallback; }
    }

    function closeQuickSettings() {
        var dd = document.getElementById('quickSettingsDropdown');
        var btn = document.getElementById('quickSettingsBtn');
        if (dd) dd.classList.remove('open');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    /* The palettes, in the order they are offered. The swatch is drawn from
       the same colour the palette actually uses, so the dot in the menu is a
       true sample rather than an approximation kept in step by hand. */
    /* The swatch is the colour people recognise the palette by, which is not
       always the one used for text - Light Modern is known by VS Code's
       #005FB8 while its text runs darker to clear the contrast bar. */
    var COLOURS = [
        { id: 'maroon',        label: 'Maroon',        swatch: '#820707' },
        { id: 'classic',       label: 'Old Classic',   swatch: '#6B0F0F' },
        { id: 'quiet-light',   label: 'Quiet Light',   swatch: '#705697' },
        { id: 'modern-light',  label: 'Modern Light',  swatch: '#005FB8' },
        { id: 'modern-dark',   label: 'Modern Dark',   swatch: '#0078D4' },
        { id: 'quiet-dark',    label: 'Quiet Dark',    swatch: '#C4B0E4' }
    ];
    /* Which of them are dark. The same list is in theme.php, which has to know
       before this file has loaded so the first paint is already right. */
    var DARK_COLOURS = { 'modern-dark': 1, 'quiet-dark': 1 };
    var PALETTES = {};
    COLOURS.forEach(function (c) { PALETTES[c.id] = 1; });

    /* Light or dark is no longer asked; it follows from the palette. */
    function modeFor(colour) { return DARK_COLOURS[colour] ? 'dark' : 'light'; }

    /* Anyone still holding a palette that was withdrawn is moved to maroon,
       and anyone who had chosen dark keeps a dark site. theme.php does the
       same before first paint; this repeats it for the stored value read here. */
    function currentColour() {
        var colour = getStored('papel_color', 'maroon');
        if (!PALETTES[colour]) {
            colour = (getStored('papel_theme', '') === 'dark') ? 'modern-dark'
                                                               : 'maroon';
            try { localStorage.setItem('papel_color', colour); } catch (e) {}
        }
        try { localStorage.removeItem('papel_theme'); } catch (e) {}
        return colour;
    }

    /* The Quick Settings panel is written into four different pages. Rather
       than a fifth copy of this markup in each, the Colour section is added
       to whichever panels are on the page. */
    function buildColourSection() {
        document.querySelectorAll('.quick-settings-dropdown').forEach(function (panel) {
            if (panel.querySelector('.qs-colors')) return;      // already built

            var section = document.createElement('div');
            section.className = 'qs-section';

            var label = document.createElement('span');
            label.className = 'qs-section-label';
            label.textContent = 'Theme Colour';
            section.appendChild(label);

            var list = document.createElement('div');
            list.className = 'qs-colors';
            COLOURS.forEach(function (c) {
                var row = document.createElement('label');
                row.className = 'qs-color';

                var input = document.createElement('input');
                input.type = 'radio';
                input.name = 'qs_color';
                input.value = c.id;

                var dot = document.createElement('span');
                dot.className = 'qs-swatch';
                dot.style.background = c.swatch;

                row.appendChild(input);
                row.appendChild(dot);
                row.appendChild(document.createTextNode(c.label));
                list.appendChild(row);
            });
            section.appendChild(list);

            /* Appended, so it follows Density. This used to insert itself
               above the last section, which was the Theme rows; with those
               gone that rule would have put the colours above Density
               instead. */
            panel.appendChild(section);
        });
    }

    // Reflect saved preferences onto <html> and tick the matching controls.
    function syncQuickSettings() {
        var density = getStored('papel_density', 'default');
        var colour  = currentColour();
        var el      = document.documentElement;

        el.setAttribute('data-density', density);
        el.setAttribute('data-color', colour);
        el.setAttribute('data-mode', modeFor(colour));

        buildColourSection();
        document.querySelectorAll('input[name="qs_density"]').forEach(function (i) { i.checked = (i.value === density); });
        document.querySelectorAll('input[name="qs_color"]').forEach(function (i) { i.checked = (i.value === colour); });
    }
    window.papelSyncQuickSettings = syncQuickSettings;

    /* Nothing listens to the device's colour scheme any more. The palette is
       an explicit choice, so a laptop switching to night mode no longer
       changes a repository someone deliberately set to Maroon. */

    document.addEventListener('click', function (e) {
        // Collapsible sidebar cards
        var toggle = e.target.closest('.js-card-toggle');
        if (toggle) {
            var card = document.getElementById(toggle.getAttribute('data-card'));
            if (card) card.classList.toggle('collapsed');
            return;
        }

        var btn = e.target.closest('#quickSettingsBtn');
        if (btn) {
            e.stopPropagation();
            var dd = document.getElementById('quickSettingsDropdown');
            if (dd) {
                var open = dd.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            return;
        }
        if (e.target.closest('#quickSettingsClose')) { e.stopPropagation(); closeQuickSettings(); return; }
        // "View Full Settings" is a plain link — let it navigate, just tidy up.
        if (e.target.closest('#quickSettingsFull')) { closeQuickSettings(); return; }
        // Clicks inside the panel shouldn't dismiss it; anything else should.
        if (e.target.closest('#quickSettingsDropdown')) { e.stopPropagation(); return; }
        closeQuickSettings();
    });

    // Every one of these takes effect at once and is remembered.
    document.addEventListener('change', function (e) {
        if (e.target.name === 'qs_density') {
            document.documentElement.setAttribute('data-density', e.target.value);
            try { localStorage.setItem('papel_density', e.target.value); } catch (err) {}
        } else if (e.target.name === 'qs_color') {
            document.documentElement.setAttribute('data-color', e.target.value);
            // Dark is a property of the palette, so it changes with it.
            document.documentElement.setAttribute('data-mode', modeFor(e.target.value));
            try { localStorage.setItem('papel_color', e.target.value); } catch (err) {}
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncQuickSettings);
    } else {
        syncQuickSettings();
    }

    /* ===== Search typeahead =====
       Opt in by giving the search input a data-suggest-url that returns a JSON
       array of strings for ?q=... . Delegated so it keeps working after an
       AJAX result swap replaces the search field. */
    function suggestBox(input) {
        var box = document.getElementById(input.getAttribute('data-suggest-target') || 'searchSuggestions');
        return box;
    }
    function hideSuggest(input) {
        var box = suggestBox(input);
        if (box) box.style.display = 'none';
        input._sugIndex = -1;
    }
    function markActive(input) {
        var box = suggestBox(input);
        if (!box) return;
        box.querySelectorAll('.suggestion-item').forEach(function (el, i) {
            el.classList.toggle('active', i === input._sugIndex);
        });
    }

    document.addEventListener('input', function (e) {
        var input = e.target.closest('.search-input[data-suggest-url]');
        if (!input) return;
        var box = suggestBox(input);
        if (!box) return;

        clearTimeout(input._sugTimer);
        var q = input.value.trim();
        if (q.length < 2) { hideSuggest(input); return; }

        input._sugTimer = setTimeout(function () {
            var url = input.getAttribute('data-suggest-url');
            fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.length) { hideSuggest(input); return; }
                    box.innerHTML = '';
                    input._sugIndex = -1;
                    var esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    data.forEach(function (item, idx) {
                        var div = document.createElement('div');
                        div.className = 'suggestion-item';
                        // Escape first, then highlight — never inject raw text.
                        var safe = document.createElement('span');
                        safe.innerText = item;
                        div.innerHTML = safe.innerHTML.replace(new RegExp('(' + esc + ')', 'gi'), '<strong>$1</strong>');
                        div.addEventListener('click', function () {
                            input.value = item;
                            hideSuggest(input);
                            var form = input.closest('form');
                            if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
                        });
                        div.addEventListener('mouseenter', function () { input._sugIndex = idx; markActive(input); });
                        box.appendChild(div);
                    });
                    box.style.display = 'block';
                })
                .catch(function () { hideSuggest(input); });
        }, 250);
    });

    document.addEventListener('keydown', function (e) {
        var input = e.target.closest('.search-input[data-suggest-url]');
        if (!input) return;
        var box = suggestBox(input);
        if (!box || box.style.display !== 'block') return;
        var items = box.querySelectorAll('.suggestion-item');
        if (!items.length) return;
        if (input._sugIndex === undefined) input._sugIndex = -1;

        if (e.key === 'ArrowDown')      { e.preventDefault(); input._sugIndex = Math.min(input._sugIndex + 1, items.length - 1); markActive(input); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); input._sugIndex = Math.max(input._sugIndex - 1, 0); markActive(input); }
        else if (e.key === 'Enter' && input._sugIndex > -1) { e.preventDefault(); items[input._sugIndex].click(); }
        else if (e.key === 'Escape')    { hideSuggest(input); }
    });

    // Dismiss when clicking away from the field or its dropdown
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.search-input[data-suggest-url]').forEach(function (input) {
            var box = suggestBox(input);
            if (!box) return;
            if (!input.contains(e.target) && !box.contains(e.target)) box.style.display = 'none';
        });
    });
})();
</script>
