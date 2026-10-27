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

    /* The palettes, in the order they are offered: Old Classic, and Old Night,
       its dark twin. The swatch is the colour people recognise the palette by.
       Old Night's is its maroon against its own night surface, split
       corner to corner, so it reads as Old Classic after dark rather than as
       a second, unrelated maroon. */
    var COLOURS = [
        { id: 'classic',   label: 'Old Classic', swatch: '#6B0F0F' },
        { id: 'old-night', label: 'Old Night',   swatch: 'linear-gradient(135deg, #6B0F0F 0 50%, #1E1813 50% 100%)' }
    ];
    /* Which of them is dark. The same list is in theme.php, which has to know
       before this file has loaded so the first paint is already right. */
    var DARK_COLOURS = { 'old-night': 1 };
    /* Withdrawn palettes that were dark, whose owners keep a dark site. */
    var WAS_DARK = { 'modern-dark': 1, 'quiet-dark': 1 };
    var PALETTES = {};
    COLOURS.forEach(function (c) { PALETTES[c.id] = 1; });

    /* Light or dark is no longer asked; it follows from the palette. */
    function modeFor(colour) { return DARK_COLOURS[colour] ? 'dark' : 'light'; }

    /* Anyone still holding a palette that was withdrawn is moved to the
       default (Old Classic), and anyone who had chosen dark keeps a dark
       site on Old Night. theme.php does the same before first paint; this
       repeats it for the stored value read here. */
    function currentColour() {
        var colour = getStored('papel_color', 'classic');
        if (!PALETTES[colour]) {
            colour = (WAS_DARK[colour] || getStored('papel_theme', '') === 'dark')
                ? 'old-night' : 'classic';
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
               already built above it (Density, then Card Details: called
               in that order for exactly this reason). This used to insert
               itself above the last section, which was the Theme rows; with
               those gone that rule would have put the colours above Density
               instead. */
            panel.appendChild(section);
        });
    }

    /* ---- Cards or rows ----
       Offered on every page that lists papers: the repository, the student's
       own submissions and the four review desks. The attribute is already on
       <html> before the first paint (browse_console.php); this draws the
       switch and remembers the answer.

       papel_desk_view is the key this started life under, when only the
       review desks had the switch, and is read as a fallback so nobody who
       set rows there is handed cards back. */
    var VIEW_KEY = 'papel_view';
    var VIEW_OPTIONS = [['cards', 'Cards'], ['rows', 'Rows']];

    function currentView() {
        var v = getStored(VIEW_KEY, '') || getStored('papel_desk_view', '');
        return v === 'rows' ? 'rows' : 'cards';
    }

    /* Marked on #mainCol rather than looked for in the list itself: the three
       consoles that have one replace that column by AJAX, so a tab with no
       results would otherwise drop the switch out of Quick Settings on the
       very page that still offers it. The marker sits on the column, which
       the swap never replaces. */
    function buildViewSection() {
        if (!document.querySelector('#mainCol[data-view-console]')) return;
        document.querySelectorAll('.quick-settings-dropdown').forEach(function (panel) {
            if (panel.querySelector('.qs-view')) return;          // already built

            var section = document.createElement('div');
            section.className = 'qs-section qs-view';

            var label = document.createElement('span');
            label.className = 'qs-section-label';
            label.textContent = 'View';
            section.appendChild(label);

            VIEW_OPTIONS.forEach(function (opt) {
                var row = document.createElement('label');
                row.className = 'qs-radio';
                var input = document.createElement('input');
                input.type = 'radio';
                input.name = 'qs_view';
                input.value = opt[0];
                row.appendChild(input);
                row.appendChild(document.createTextNode(opt[1]));
                section.appendChild(row);
            });

            /* Above Density, which is the first section the pages write by
               hand; the link to the full settings is a section too, and it
               stays at the top, so the one to go in front of is the first
               with a heading of its own. */
            var before = null;
            panel.querySelectorAll('.qs-section').forEach(function (sec) {
                if (!before && sec.querySelector('.qs-section-label')) before = sec;
            });
            before ? panel.insertBefore(section, before) : panel.appendChild(section);
        });
    }

    var CARD_DETAIL_KEY = 'papel_card_detail';
    var CARD_DETAIL_OPTIONS = [['expanded', 'Expanded'], ['collapsed', 'Collapsed']];

    /* Only the dashboards and review desks build a paper-card toggle
       (includes/browse_console_js.php's own "Collapsible paper cards" IIFE,
       further down this file): the public repository's rows have no
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

    /* ---- The same choices, on the list itself ----
       Right-clicking a paper puts View, Density and Card Details under the
       pointer rather than across the page on the gear. The controls are the
       panel's own, names included, so the change handler further down applies
       and stores them exactly as it does there and the sync puts every copy of
       them back in step. Nothing here is a second source of truth.

       Which sections appear is decided the same way the panel decides: the
       markers on #mainCol, so the repository is not offered a Card Details row
       for cards it has never had. */
    var DENSITY_OPTIONS = [['default', 'Default'],
                           ['comfortable', 'Comfortable'],
                           ['compact', 'Compact']];
    var ctxMenu = null;

    /* A form, not a div, and that is the whole reason it is one: radios with
       the same name belong to one group per form owner, so outside a form the
       menu's "Compact" and the panel's "Compact" were the same radio. Choosing
       in the menu silently unchecked the panel's copy and left that panel
       showing nothing selected at all. Inside a form of its own the menu has
       its own group, while the names stay what the change handler listens for. */
    function ctxBox() {
        if (!ctxMenu) {
            ctxMenu = document.createElement('form');
            ctxMenu.className = 'qs-context';
            ctxMenu.setAttribute('role', 'menu');
            ctxMenu.hidden = true;
            // Nothing to send: Enter on a radio would otherwise reload the page.
            ctxMenu.addEventListener('submit', function (e) { e.preventDefault(); });
            document.body.appendChild(ctxMenu);
        }
        return ctxMenu;
    }

    function ctxGroup(label, name, options, current) {
        var section = document.createElement('div');
        section.className = 'qs-section';

        var head = document.createElement('span');
        head.className = 'qs-section-label';
        head.textContent = label;
        section.appendChild(head);

        options.forEach(function (opt) {
            var row = document.createElement('label');
            row.className = 'qs-radio';
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = name;
            input.value = opt[0];
            input.checked = (opt[0] === current);
            row.appendChild(input);
            row.appendChild(document.createTextNode(opt[1]));
            section.appendChild(row);
        });
        return section;
    }

    function closeCtx() {
        if (ctxMenu) ctxMenu.hidden = true;
    }

    function openCtx(x, y) {
        var m = ctxBox();
        m.innerHTML = '';
        if (document.querySelector('#mainCol[data-view-console]')) {
            m.appendChild(ctxGroup('View', 'qs_view', VIEW_OPTIONS, currentView()));
        }
        m.appendChild(ctxGroup('Density', 'qs_density', DENSITY_OPTIONS,
                               getStored('papel_density', 'default')));
        if (document.querySelector('#mainCol[data-card-console]')) {
            m.appendChild(ctxGroup('Card Details', 'qs_card_detail', CARD_DETAIL_OPTIONS,
                                   getStored(CARD_DETAIL_KEY, 'expanded')));
        }

        // Measured while shown, then nudged back inside the window.
        m.hidden = false;
        var w = m.offsetWidth, h = m.offsetHeight;
        m.style.left = Math.round(Math.max(8, Math.min(x, window.innerWidth - w - 8))) + 'px';
        m.style.top  = Math.round(Math.max(8, Math.min(y, window.innerHeight - h - 8))) + 'px';

        var first = m.querySelector('input:checked') || m.querySelector('input');
        if (first) first.focus();
    }

    document.addEventListener('contextmenu', function (e) {
        var row = e.target.closest
            ? e.target.closest('.paper-list .paper-card, .paper-list .paper-item')
            : null;
        if (!row) { closeCtx(); return; }
        /* A link keeps the browser's own menu, which is where "open link in a
           new tab" lives and how a repository is read: a paper in one tab, the
           list still in another. accessibility.php suppresses that menu
           everywhere else on the page and lets it through on links, so leaving
           links alone here is all that is needed. Everywhere else on the row,
           these settings. */
        if (e.target.closest('a[href], button, input, textarea, select')) { closeCtx(); return; }

        e.preventDefault();
        openCtx(e.clientX, e.clientY);
    });

    /* Chosen, applied by the handler below, and gone: a menu that stays open
       after an answer is a panel, and there is already one of those.

       The sync waits a tick. This listener is registered before the one that
       actually applies and stores the choice, so running it straight away read
       the old value back out of storage and re-ticked the panel to the setting
       that had just been replaced. */
    document.addEventListener('change', function (e) {
        if (!ctxMenu || ctxMenu.hidden || !ctxMenu.contains(e.target)) return;
        closeCtx();
        setTimeout(syncQuickSettings, 0);
    });

    document.addEventListener('click', function (e) {
        if (ctxMenu && !ctxMenu.hidden && !ctxMenu.contains(e.target)) closeCtx();
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeCtx();
    });
    window.addEventListener('scroll', closeCtx, true);
    window.addEventListener('resize', closeCtx);

    // Reflect saved preferences onto <html> and tick the matching controls.
    function syncQuickSettings() {
        var density    = getStored('papel_density', 'default');
        var colour     = currentColour();
        var cardDetail = getStored(CARD_DETAIL_KEY, 'expanded');
        var view       = currentView();
        var el         = document.documentElement;

        el.setAttribute('data-density', density);
        el.setAttribute('data-color', colour);
        el.setAttribute('data-mode', modeFor(colour));
        el.setAttribute('data-view', view);

        buildViewSection();
        buildCardDetailSection();
        buildColourSection();
        document.querySelectorAll('input[name="qs_view"]').forEach(function (i) { i.checked = (i.value === view); });
        document.querySelectorAll('input[name="qs_density"]').forEach(function (i) { i.checked = (i.value === density); });
        document.querySelectorAll('input[name="qs_color"]').forEach(function (i) { i.checked = (i.value === colour); });
        document.querySelectorAll('input[name="qs_card_detail"]').forEach(function (i) { i.checked = (i.value === cardDetail); });

        /* review_console.php replaces #mainCol by AJAX on every tab/filter
           change and calls this function afterwards: the "Collapsible paper
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
        // "View Full Settings" is a plain link: let it navigate, just tidy up.
        if (e.target.closest('#quickSettingsFull')) { closeQuickSettings(); return; }
        // Clicks inside the panel shouldn't dismiss it; anything else should.
        if (e.target.closest('#quickSettingsDropdown')) { e.stopPropagation(); return; }
        closeQuickSettings();
    });

    // Every one of these takes effect at once and is remembered.
    document.addEventListener('change', function (e) {
        if (e.target.name === 'qs_view') {
            var view = e.target.value === 'rows' ? 'rows' : 'cards';
            document.documentElement.setAttribute('data-view', view);
            try { localStorage.setItem(VIEW_KEY, view); } catch (err) {}
            /* The same switch is drawn in more than one panel on a page that
               has both a toolbar and a settings page open behind it. */
            document.querySelectorAll('input[name="qs_view"]').forEach(function (i) {
                i.checked = (i.value === view);
            });
        } else if (e.target.name === 'qs_density') {
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
                        // Escape first, then highlight: never inject raw text.
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
        // A key event can be dispatched at the document, which has no closest().
        var input = e.target.closest && e.target.closest('.search-input[data-suggest-url]');
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
   Which card comes first varies with the data (the review desks only render
   "What you can do" when there is something to put in it) so hand-placing the
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
           would move to: the same rule the banner eye follows: the control
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
   that underneath is rarely needed again: the title, who is on it and
   where it stands is the part worth scanning a long list for, which is also
   exactly what the public repository's own plain list shows. This folds
   the rest away without losing it.

   Unlike includes/card_collapse.php, the part that stays visible is not a
   bare <h2> (.card-status sits beside the title and has to stay put) so
   .card-head is the head instead. The toggle is appended into .card-status
   but pinned there with CSS (position: absolute, in a padding-right gutter
   that column reserves), not inserted into the text itself: otherwise its
   own width would push "Status: ..." out further than "View Details" below
   it, or than .card-people's Research Adviser/Coordinator lines in the
   track underneath, and the three would no longer share a right edge.
   student_dashboard.php never replaces its list by AJAX, but review_console.php
   does: every tab and filter change swaps #mainCol's whole innerHTML in,
   fresh cards with no toggle of their own. So this also runs from
   window.papelSyncQuickSettings(), the hook review_console.php's loadResults()
   already calls after each swap; the data-collapsed guard below skips a card
   that already has one, which a plain page load's cards do by the time that
   hook is reachable at all.

   Starting state follows Quick Settings' own "Card Details" row: read
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


/* ===== The card behind a row =====
   A row says what a long list is scanned by and puts the rest away. Resting
   on one brings the rest back: the card it came from, cloned, shown beside
   it until the pointer leaves.

   Cloned rather than rebuilt from the same data, so there is nothing to keep
   in step: whatever the card shows on this page, this shows. The rows
   layout is written as `html[data-view="rows"] .paper-list ...`, so a copy
   that lives on <body> instead of in the list matches none of it and draws
   as a card without being told to.

   Only where there is a pointer that hovers. On a touch screen every tap
   would open one of these over the thing that was tapped. */
(function () {
    if (!window.matchMedia || !matchMedia("(hover: hover)").matches) return;

    var ROWS = ".paper-list .paper-card, .paper-list .paper-item";
    var DELAY = 220;          // long enough that passing over a list is quiet
    var peek = null, timer = null, shownFor = null;

    function panel() {
        if (!peek) {
            peek = document.createElement("div");
            peek.className = "row-peek";
            /* It repeats what is already on the row for anyone reading the
               page rather than looking at it, so it is not announced twice. */
            peek.setAttribute("aria-hidden", "true");
            peek.hidden = true;
            document.body.appendChild(peek);
        }
        return peek;
    }

    function inRows() {
        return document.documentElement.getAttribute("data-view") === "rows";
    }

    function rowOf(node) {
        return (node && node.closest) ? node.closest(ROWS) : null;
    }

    function fill(row) {
        var p = panel();
        var copy = row.cloneNode(true);
        /* Every id would now be in the document twice, and a label, a toggle
           or an aria-controls pointing at one would find whichever came
           first. Names go for the same reason: a form control with a name is
           a form control the page can be asked for. */
        copy.querySelectorAll("[id]").forEach(function (el) { el.removeAttribute("id"); });
        copy.querySelectorAll("[name]").forEach(function (el) { el.removeAttribute("name"); });
        copy.querySelectorAll(".paper-card-toggle").forEach(function (el) { el.remove(); });
        // What Card Details folded away is exactly the detail this is for.
        if (copy.hasAttribute("data-collapsed")) copy.setAttribute("data-collapsed", "0");
        p.innerHTML = "";
        p.appendChild(copy);
        return p;
    }

    /* Under the row, over it when the page runs out below, and lined up with
       the list so it reads as that row opening rather than as something
       arriving from elsewhere. */
    function place(row) {
        var p = panel();
        var r = row.getBoundingClientRect();
        var list = (row.parentElement || row).getBoundingClientRect();
        p.style.width = Math.round(Math.min(Math.max(list.width, 320),
                                            window.innerWidth - 16)) + "px";
        p.hidden = false;
        var h = p.offsetHeight;
        var top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        p.style.top = Math.round(top) + "px";
        p.style.left = Math.round(Math.max(8,
            Math.min(list.left, window.innerWidth - p.offsetWidth - 8))) + "px";
    }

    function open(row) {
        if (!inRows() || !document.body.contains(row)) return;
        /* Something deliberate is already open over this row: the right-click
           menu, or the window a click opened. Either outranks a card that
           appears merely because the pointer is resting. */
        if (document.querySelector(".qs-context:not([hidden]), .row-view-back:not([hidden])")) return;
        shownFor = row;
        fill(row);
        place(row);
        requestAnimationFrame(function () {
            if (peek && shownFor === row) peek.classList.add("is-open");
        });
    }

    function close() {
        clearTimeout(timer);
        shownFor = null;
        if (peek) { peek.classList.remove("is-open"); peek.hidden = true; }
    }

    document.addEventListener("mouseover", function (e) {
        if (!inRows()) return;
        var row = rowOf(e.target);
        if (!row) { if (shownFor) close(); return; }
        if (row === shownFor) return;      // moving about inside the same row
        clearTimeout(timer);
        timer = setTimeout(function () { open(row); }, DELAY);
    });

    document.addEventListener("mouseout", function (e) {
        var row = rowOf(e.target);
        if (!row) return;
        // Still inside the same row: a move between its own children.
        if (e.relatedTarget && row.contains(e.relatedTarget)) return;
        close();
    });

    /* The same card for the keyboard, since a row is reached by tabbing to
       the title inside it. */
    document.addEventListener("focusin", function (e) {
        if (!inRows()) return;
        var row = rowOf(e.target);
        if (row) open(row); else close();
    });

    document.addEventListener("focusout", function (e) {
        var row = rowOf(e.target);
        if (!row) return;
        // Still inside the same row: the tab moved between its own links.
        if (e.relatedTarget && row.contains(e.relatedTarget)) return;
        close();
    });

    /* It is placed against the viewport, so anything that moves the row out
       from under it takes it away: scrolling (the list has its own scroller,
       hence the capture), resizing, a click, Escape, or going back to cards. */
    window.addEventListener("scroll", close, true);
    window.addEventListener("resize", close);
    document.addEventListener("click", close, true);
    document.addEventListener("contextmenu", close, true);
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
    document.addEventListener("change", function (e) {
        if (e.target.name === "qs_view" || e.target.name === "qs_density") close();
    });
})();


/* ===== The record, opened small =====
   A click on a row opens that paper's own page in a window in the middle of
   the screen; two clicks go to it properly, the same place the title link
   goes. The page is framed rather than rebuilt, so there is nothing here that
   can fall behind what the record actually says.

   The single click waits a quarter of a second before it does anything, which
   is how it tells one click from the first of two. That delay is invisible for
   what it opens, and it is the only way both gestures can live on the same
   spot. The title link and the buttons keep their own behaviour: they are the
   parts of a row that already do something. */
(function () {
    /* Not inside the window this very feature opens. The record page carries a
       Back link to the list, and a list that can open records in a window is a
       list that opens one inside the one it is already in, and again inside
       that. One level is a preview; two is a hall of mirrors. */
    if (window.self !== window.top) return;

    var ROWS = ".paper-list .paper-card, .paper-list .paper-item";
    var WAIT = 260;
    var timer = null, back = null, frame = null, name = null, go = null;

    function recordUrl(row) {
        var a = row.querySelector(".paper-title a[href]");
        return a ? a.getAttribute("href") : null;
    }

    function panel() {
        if (back) return back;
        back = document.createElement("div");
        back.className = "row-view-back";
        back.hidden = true;
        back.setAttribute("role", "dialog");
        back.setAttribute("aria-modal", "true");
        back.setAttribute("aria-label", "Paper preview");
        back.innerHTML =
            '<div class="row-view">'
          +   '<div class="row-view-head">'
          +     '<span class="row-view-name"></span>'
          +     '<a class="row-view-btn" data-go target="_self">'
          +       '<span class="material-symbols-outlined mi-18">open_in_full</span>Open the page</a>'
          +     '<button type="button" class="row-view-btn" data-close aria-label="Close the preview">'
          +       '<span class="material-symbols-outlined mi-18">close</span></button>'
          +   '</div>'
          +   '<iframe class="row-view-frame" title="Paper preview" referrerpolicy="same-origin"></iframe>'
          + '</div>';
        document.body.appendChild(back);
        frame = back.querySelector(".row-view-frame");
        name  = back.querySelector(".row-view-name");
        go    = back.querySelector("[data-go]");

        /* The page inside brings its own navbar, breadcrumb and footer, and in a
           window of this size that is a second set of site furniture wrapped
           around the one thing being looked at. They are hidden through the
           frame's own DOM (same origin, so this is allowed) rather than by
           asking the page to render differently: no shared include has to learn
           about being embedded, and a style element injected there would be
           refused by the CSP for having no nonce. Re-run on every load, since
           the reader can follow links inside the window. */
        frame.addEventListener("load", function () {
            var doc;
            try { doc = frame.contentDocument; } catch (err) { return; }   // not ours
            if (!doc) return;
            /* The same goes for the furniture fixed to the window's edge. Inside the
               frame it is pinned to the frame's edge instead, so the accessibility tab
               and the "Make it yours" card showed twice, one copy just inside the
               other, which read as the window looking into itself. The page around
               the frame already offers both. */
            [".site-header", ".nav-photo", ".crumb-bar", ".site-footer",
             "#a11y-widget", "#themeWelcome"].forEach(function (sel) {
                doc.querySelectorAll(sel).forEach(function (el) { el.style.display = "none"; });
            });

            /* Back belongs to the page, and inside a window the page's "back"
               is the window itself: following it would leave the list showing
               inside a box on top of the same list. So it closes this instead.
               Bound on every load, since each one brings a new document. */
            doc.querySelectorAll(".pd-back").forEach(function (b) {
                b.addEventListener("click", function (e) { e.preventDefault(); shut(); });
            });

            /* And if the frame ends up anywhere that is not a paper's record,
               there is nothing left for the window to be showing. */
            if (!doc.querySelector(".pd-heading")) shut();
        });

        back.addEventListener("click", function (e) {
            if (e.target === back || e.target.closest("[data-close]")) shut();
        });
        return back;
    }

    function shut() {
        if (!back || back.hidden) return;
        back.classList.remove("is-open");
        back.hidden = true;
        // Emptied, so the page inside stops running the moment it is closed.
        frame.setAttribute("src", "about:blank");
        document.body.style.overflow = "";
    }

    function show(row) {
        var url = recordUrl(row);
        if (!url) return;                     // nothing to open: a draft, or a guest
        var p = panel();
        var title = row.querySelector(".paper-title");
        name.textContent = title ? title.textContent.replace(/\s+/g, " ").trim() : "Paper";
        go.setAttribute("href", url);
        frame.setAttribute("src", url);
        p.hidden = false;
        document.body.style.overflow = "hidden";
        requestAnimationFrame(function () { p.classList.add("is-open"); });
    }

    function rowFor(e) {
        if (!e.target.closest) return null;
        var row = e.target.closest(ROWS);
        if (!row) return null;
        // The title and the buttons already have a job on a single click.
        if (e.target.closest("a[href], button, input, textarea, select")) return null;
        return row;
    }

    document.addEventListener("click", function (e) {
        var row = rowFor(e);
        if (!row) return;
        if (e.detail > 1) return;             // the second of a pair; dblclick has it
        // A click that dismisses the right-click menu is not a click on the row.
        var menu = document.querySelector(".qs-context");
        if (menu && !menu.hidden) return;
        // Nor is the one that ends a drag across the text.
        var sel = window.getSelection && window.getSelection();
        if (sel && String(sel).trim() !== "") return;

        clearTimeout(timer);
        timer = setTimeout(function () { show(row); }, WAIT);
    });

    document.addEventListener("dblclick", function (e) {
        var row = rowFor(e);
        if (!row) return;
        clearTimeout(timer);                  // the preview the first click had queued
        shut();
        var url = recordUrl(row);
        if (url) window.location.href = url;
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") shut();
    });
})();
</script>
