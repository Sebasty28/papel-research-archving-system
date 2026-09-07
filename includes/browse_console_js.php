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
        { id: 'google-light',  label: 'Light',         swatch: '#3C4043' },
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

    /* Anyone still holding a palette that was withdrawn is moved to the
       default (Old Classic), and anyone who had chosen dark keeps a dark
       site. theme.php does the same before first paint; this repeats it for
       the stored value read here. */
    function currentColour() {
        var colour = getStored('papel_color', 'classic');
        if (!PALETTES[colour]) {
            colour = (getStored('papel_theme', '') === 'dark') ? 'modern-dark'
                                                               : 'classic';
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

            /* Appended, so it lands after whatever syncQuickSettings() has
               already built above it (Density, then Card Details — called
               in that order for exactly this reason). This used to insert
               itself above the last section, which was the Theme rows; with
               those gone that rule would have put the colours above Density
               instead. */
            panel.appendChild(section);
        });
    }

    var CARD_DETAIL_KEY = 'papel_card_detail';
    var CARD_DETAIL_OPTIONS = [['expanded', 'Expanded'], ['collapsed', 'Collapsed']];

    /* Only the dashboards and review desks build a paper-card toggle
       (includes/browse_console_js.php's own "Collapsible paper cards" IIFE,
       further down this file) — the public repository's rows have no
       progress tracker to fold away, so there is nothing for this row to
       control there. Checked at build time rather than baked into a role
       list, so it stays correct on whatever page actually has the cards.

       That check has to be #mainCol's own data-card-console marker, not
       .paper-card directly: review_console.php and student_dashboard.php
       swap #mainCol's innerHTML by AJAX on every tab and filter change, so
       a tab that currently has zero results (Waiting for you with nothing
       queued, a student with no drafts) would otherwise make this section
       vanish from Quick Settings even though the page is still one that
       has paper cards elsewhere. The marker sits on #mainCol itself, which
       that swap never replaces, so it survives an empty result the same
       way a full one does. */
    function buildCardDetailSection() {
        if (!document.querySelector('#mainCol[data-card-console]')) return;
        document.querySelectorAll('.quick-settings-dropdown').forEach(function (panel) {
            if (panel.querySelector('.qs-card-detail')) return;   // already built

            var section = document.createElement('div');
            section.className = 'qs-section qs-card-detail';

            var label = document.createElement('span');
            label.className = 'qs-section-label';
            label.textContent = 'Card Details';
            section.appendChild(label);

            CARD_DETAIL_OPTIONS.forEach(function (opt) {
                var row = document.createElement('label');
                row.className = 'qs-radio';
                var input = document.createElement('input');
                input.type = 'radio';
                input.name = 'qs_card_detail';
                input.value = opt[0];
                row.appendChild(input);
                row.appendChild(document.createTextNode(opt[1]));
                section.appendChild(row);
            });

            panel.appendChild(section);
        });
    }

    // Reflect saved preferences onto <html> and tick the matching controls.
    function syncQuickSettings() {
        var density    = getStored('papel_density', 'default');
        var colour     = currentColour();
        var cardDetail = getStored(CARD_DETAIL_KEY, 'expanded');
        var el         = document.documentElement;

        el.setAttribute('data-density', density);
        el.setAttribute('data-color', colour);
        el.setAttribute('data-mode', modeFor(colour));

        buildCardDetailSection();
        buildColourSection();
        document.querySelectorAll('input[name="qs_density"]').forEach(function (i) { i.checked = (i.value === density); });
        document.querySelectorAll('input[name="qs_color"]').forEach(function (i) { i.checked = (i.value === colour); });
        document.querySelectorAll('input[name="qs_card_detail"]').forEach(function (i) { i.checked = (i.value === cardDetail); });

        /* review_console.php replaces #mainCol by AJAX on every tab/filter
           change and calls this function afterwards — the "Collapsible paper
           cards" IIFE further down only ever ran once, on the cards present
           at initial load, so a swapped-in card kept its progress tracker
           but lost the toggle that folds it away. Not yet defined the first
           time this runs (that IIFE hasn't executed yet at that point in the
           file), which is harmless: the initial cards get built directly by
           its own call moments later, same as before this existed. */
        if (window.papelBuildCardToggles) window.papelBuildCardToggles();
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
        } else if (e.target.name === 'qs_card_detail') {
            var collapsed = e.target.value === 'collapsed';
            document.querySelectorAll('.paper-card[data-collapsed]').forEach(function (card) {
                var btn      = card.querySelector('.paper-card-toggle');
                var titleEl  = card.querySelector('.paper-title');
                card.setAttribute('data-collapsed', collapsed ? '1' : '0');
                if (btn) {
                    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    var verb = collapsed ? 'Show' : 'Hide';
                    btn.title = verb + ' the review progress';
                    if (titleEl) btn.setAttribute('aria-label', verb + ' the review progress for ' + titleEl.textContent.trim());
                }
            });
            try { localStorage.setItem(CARD_DETAIL_KEY, e.target.value); } catch (err) {}
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


/* ===== Which side the sidebar sits on =====
   The control is placed here rather than written into each console's markup.
   Which card comes first varies with the data — the review desks only render
   "What you can do" when there is something to put in it — so hand-placing the
   button would land it on a different card from one page to the next, and on
   none at all when the top card is absent. Finding the first card header at
   run time puts it in the same corner every time. */
(function () {
    var aside = document.getElementById('sidebarCol');
    if (!aside) { return; }
    var root = document.documentElement;
    var KEY = 'papel_sidebar_side';

    function label(btn) {
        var to = root.classList.contains('sidebar-left') ? 'right' : 'left';
        btn.setAttribute('aria-label', 'Move panel to the ' + to);
        btn.title = 'Move panel to the ' + to;
    }

    function addButton() {
        var tools = aside.querySelector('.card-header-tools');
        if (!tools || tools.querySelector('.js-side-swap')) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'card-tool js-side-swap';
        btn.setAttribute('aria-controls', 'sidebarCol');
        /* Two glyphs, and the stylesheet shows whichever matches the side it
           would move to — the same rule the banner eye follows: the control
           shows the action, not the state. */
        ['side-icon-left:dock_to_right', 'side-icon-right:dock_to_left']
            .forEach(function (pair) {
                var bits = pair.split(':');
                var i = document.createElement('span');
                i.className = 'material-symbols-outlined ' + bits[0];
                i.textContent = bits[1];
                btn.appendChild(i);
            });
        label(btn);
        tools.insertBefore(btn, tools.firstChild);
    }

    function setSide(left) {
        root.classList.toggle('sidebar-left', left);
        aside.querySelectorAll('.js-side-swap').forEach(label);
        try { localStorage.setItem(KEY, left ? 'left' : 'right'); } catch (e) {}
    }

    /* Delegated, and the button is put back after every refresh: applying a
       filter replaces everything inside the aside, button included. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-side-swap');
        if (!btn) { return; }
        e.preventDefault();
        e.stopPropagation();          // the card's collapse toggle is alongside
        setSide(!root.classList.contains('sidebar-left'));
    });

    addButton();
    new MutationObserver(addButton).observe(aside, { childList: true, subtree: true });
})();


/* ===== Collapsible paper cards =====
   Every dashboard and review desk shows the same card: title, authors and
   status up top, then a progress tracker and (on a review desk) the
   supporting files and the approve/return buttons underneath. Read once,
   that underneath is rarely needed again — the title, who is on it and
   where it stands is the part worth scanning a long list for, which is also
   exactly what the public repository's own plain list shows. This folds
   the rest away without losing it.

   Unlike includes/card_collapse.php, the part that stays visible is not a
   bare <h2> — .card-status sits beside the title and has to stay put — so
   .card-head is the head instead. The toggle is appended into .card-status
   but pinned there with CSS (position: absolute, in a padding-right gutter
   that column reserves), not inserted into the text itself — otherwise its
   own width would push "Status: ..." out further than "View Details" below
   it, or than .card-people's Research Adviser/Coordinator lines in the
   track underneath, and the three would no longer share a right edge.
   student_dashboard.php never replaces its list by AJAX, but review_console.php
   does — every tab and filter change swaps #mainCol's whole innerHTML in,
   fresh cards with no toggle of their own. So this also runs from
   window.papelSyncQuickSettings(), the hook review_console.php's loadResults()
   already calls after each swap; the data-collapsed guard below skips a card
   that already has one, which a plain page load's cards do by the time that
   hook is reachable at all.

   Starting state follows Quick Settings' own "Card Details" row — read
   directly here rather than through the other IIFE above, the same way
   that one reads papel_sidebar_side independently of theme.php: one stored
   key, read wherever it is needed, rather than a shared helper threaded
   across every self-contained block in this file. */
(function () {
    function buildCardToggles() {
        var startCollapsed = (function () {
            try { return localStorage.getItem('papel_card_detail') === 'collapsed'; } catch (e) { return false; }
        })();

        document.querySelectorAll('.paper-card').forEach(function (card) {
            if (card.hasAttribute('data-collapsed')) return;   // already built
            var head   = card.querySelector(':scope > .card-head');
            var title  = card.querySelector('.paper-title');
            var status = card.querySelector('.card-status');
            if (!head || !title) return;

            var label = title.textContent.trim();

            var body = document.createElement('div');
            body.className = 'paper-card-body';
            var node = head.nextSibling;
            while (node) {
                var next = node.nextSibling;
                body.appendChild(node);
                node = next;
            }
            card.appendChild(body);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'paper-card-toggle';
            btn.setAttribute('aria-expanded', startCollapsed ? 'false' : 'true');
            btn.title = (startCollapsed ? 'Show' : 'Hide') + ' the review progress';
            btn.setAttribute('aria-label', (startCollapsed ? 'Show' : 'Hide') + ' the review progress for ' + label);
            btn.innerHTML = '<span class="material-symbols-outlined">expand_more</span>';
            // Position is entirely CSS's doing (absolute, top right of .card-status);
            // where it lands in the DOM only has to put it somewhere inside that box.
            if (status) {
                status.appendChild(btn);
            } else {
                title.insertBefore(btn, title.firstChild);
            }

            card.setAttribute('data-collapsed', startCollapsed ? '1' : '0');

            btn.addEventListener('click', function (e) {
                e.preventDefault();     // the title beside it may itself be a link
                e.stopPropagation();
                var closed = card.getAttribute('data-collapsed') === '1';
                card.setAttribute('data-collapsed', closed ? '0' : '1');
                btn.setAttribute('aria-expanded', closed ? 'true' : 'false');
                btn.title = (closed ? 'Hide' : 'Show') + ' the review progress';
                btn.setAttribute('aria-label', (closed ? 'Hide' : 'Show') + ' the review progress for ' + label);
            });
        });
    }

    buildCardToggles();
    window.papelBuildCardToggles = buildCardToggles;
})();
</script>
