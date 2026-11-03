<?php
/**
 * The search field on a management console's tab row.
 *
 * One field for every tab on the page: a search is a question about the people
 * or the records, not about the tab you happen to be reading, and the tab counts
 * become what each tab holds of the matches, so the figures say where a match
 * is. Enter then walks to the next tab that has one.
 *
 * Required from a page's <head>, after includes/manage_page.php, which carries
 * the field's styling along with the rest of the .mgmt-* sheet. The markup goes
 * wherever the page wants it on the tab row (mgmt_search_field()).
 *
 * What a page has to hold to its end of this:
 *
 * - its rows are in a `.js-pane`, and its tabs are `.mgmt-tab[data-pane="..."]`,
 *   which is the shape all four consoles already have;
 * - a row that is not a record carries a `.mgmt-empty` (every console's empty
 *   state already does), since that is how a record is told from a message;
 * - a row may name what it answers to in `data-search`, lower-cased. Without one
 *   the field reads the row's own cells, skipping the cell that holds the
 *   actions, which is what the reader sees on the row anyway.
 *
 * **Rows are hidden with a class, never with `hidden`.** Manage Students filters
 * its own rows by programme, section and intake, and does that with `hidden`:
 * two filters writing one property means each undoes the other on its next pass,
 * and the chips would put back rows the search had just taken away. With the
 * search on a class of its own the two compose, a row being out of sight if
 * either has taken it out, and `mgmtSearch.hidden(row)` answers for both.
 */

if (!defined('ROOT_PATH')) { exit; }

/**
 * The field itself. Prints nothing the second time it is called on a page.
 *
 * $placeholder says what this console's rows can be searched by; it is the
 * field's label as well, so it reads as a sentence to a screen reader.
 */
function mgmt_search_field(string $placeholder = 'Search this list'): void {
    static $printed = false;
    if ($printed) { return; }
    $printed = true;
    ?>
    <span class="mgmt-search">
        <span class="material-symbols-outlined mi-18" aria-hidden="true">search</span>
        <input type="search" class="js-mgmt-search" autocomplete="off"
               placeholder="<?= e($placeholder) ?>"
               title="Press Enter to go to a tab that has matches"
               aria-label="<?= e($placeholder) ?>. Press Enter to go to a tab that has matches">
        <?php /* Where Enter landed, for a reader who cannot see the tab change.
                 Bootstrap's own class (site_head.php loads it): it takes the span
                 out of the flex row rather than hiding it, so a screen reader
                 still reads it. */ ?>
        <span class="visually-hidden js-mgmt-search-status" role="status" aria-live="polite"></span>
    </span>
    <?php
}
?>
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function () {
    var field = document.querySelector('.js-mgmt-search');
    if (!field) { return; }

    /* Out of sight because of the search, as against out of sight because of the
       page's own filter. See the note at the top of this file. */
    var HIDE   = 'is-search-off';
    var panes  = [].slice.call(document.querySelectorAll('.js-pane'));
    var tabs   = [].slice.call(document.querySelectorAll('.mgmt-tab[data-pane]'));
    var status = document.querySelector('.js-mgmt-search-status');
    if (!panes.length) { return; }

    /* The count each tab started with, read once: the server's figure is the one
       to put back when the field is emptied. */
    tabs.forEach(function (tab) {
        var c = tab.querySelector('.count');
        if (c) { c.dataset.total = c.textContent.trim(); }
    });

    /* A row is a record unless it is a message. Every console's empty state, and
       Manage Students' "no students match that filter", is a .mgmt-empty. */
    function records(pane) {
        return [].slice.call(pane.querySelectorAll('tbody tr')).filter(function (row) {
            return !row.querySelector('.mgmt-empty');
        });
    }

    /* What the row answers to: its own data-search, or its cells. The cell
       holding the actions is skipped, buttons and a row's reset-password dialog
       being no part of what is on the row to read. Worked out once and kept on
       the element rather than written back as an attribute, so a row that answers
       to nothing is not re-read on every keystroke. */
    function haystack(row) {
        if (row.papelHay === undefined) {
            if (row.dataset.search !== undefined) {
                row.papelHay = row.dataset.search.toLowerCase();
            } else {
                var parts = [];
                [].slice.call(row.children).forEach(function (cell) {
                    if (cell.querySelector('.mgmt-actions, input, button, select, textarea, .modal')) { return; }
                    parts.push(cell.textContent);
                });
                row.papelHay = parts.join(' ').replace(/\s+/g, ' ').trim().toLowerCase();
            }
        }
        return row.papelHay;
    }

    function rowHidden(row) {
        return row.hidden || row.classList.contains(HIDE);
    }

    /* The row that says the search is why a tab is empty. Made here rather than
       printed by every console, and kept apart from the page's own .js-no-match,
       which belongs to the page's filter and says something else. */
    function emptyRow(pane) {
        var body = pane.querySelector('tbody');
        if (!body) { return null; }
        var row = body.querySelector('.js-search-empty');
        if (!row) {
            var table = body.closest('table');
            var cols  = table ? table.querySelectorAll('thead th').length : 1;
            row = document.createElement('tr');
            row.className = 'js-search-empty';
            row.hidden = true;
            var cell = document.createElement('td');
            cell.colSpan = cols || 1;
            var box = document.createElement('div');
            box.className = 'mgmt-empty';
            var ico = document.createElement('span');
            ico.className = 'material-symbols-outlined';
            ico.textContent = 'search_off';
            box.appendChild(ico);
            box.appendChild(document.createTextNode('Nothing here matches that search.'));
            cell.appendChild(box);
            row.appendChild(cell);
            body.appendChild(row);
        }
        return row;
    }

    function say(text) { if (status) { status.textContent = text; } }

    /* How many records a tab is showing: matching the search *and* left in by
       whatever filter the page has of its own. */
    function shownIn(pane) {
        return records(pane).filter(function (row) { return !rowHidden(row); }).length;
    }

    function applyFilter() {
        var q = field.value.trim().toLowerCase();

        panes.forEach(function (pane) {
            var rows   = records(pane);
            var onPage = 0;

            rows.forEach(function (row) {
                var hit = !q || haystack(row).indexOf(q) !== -1;
                row.classList.toggle(HIDE, !hit);
                if (!row.hidden) { onPage++; }
            });

            var shown = shownIn(pane);

            /* A console's own empty state ("No staff accounts yet") is about an
               empty tab and stays true of one while a search is on; a tab that
               has records hands over to the row below instead. .js-no-match is
               left alone throughout: it is the page's filter's to show. */
            [].slice.call(pane.querySelectorAll('tbody tr')).forEach(function (row) {
                if (!row.querySelector('.mgmt-empty')) { return; }
                if (row.classList.contains('js-no-match') ||
                    row.classList.contains('js-search-empty')) { return; }
                row.hidden = !!q && rows.length > 0;
            });

            /* Only when the search is what emptied the tab: with nothing left by
               the page's own filter either, that filter has already said so. */
            var none = rows.length ? emptyRow(pane) : null;
            if (none) { none.hidden = !(q && shown === 0 && onPage > 0); }

            var tab   = tabs.filter(function (t) { return t.dataset.pane === pane.id; })[0];
            var count = tab ? tab.querySelector('.count') : null;
            if (count) {
                count.textContent = q ? String(shown) : count.dataset.total;
                count.classList.toggle('is-filtered', !!q);
            }
        });

        /* So a page with rows of its own to keep in step (a tick box that must
           not outlive the row it is on) can hear the filter without this file
           knowing anything about it. */
        document.dispatchEvent(new CustomEvent('mgmt-search', { detail: { query: q } }));
    }

    /* ---- Enter walks the tabs that matched ----
       Whoever is searching does not know which tab holds what they are after, and
       may well have typed into a tab that holds none of it. Enter goes to the
       next tab along that has something to show, so a second Enter walks on to
       the one after it and every tab that matched can be read without the mouse.
       The cursor stays in the field, there being more to type. */
    function tabName(tab) {
        return (tab.firstChild ? tab.firstChild.textContent : '').trim() || 'that tab';
    }

    function walkToMatch() {
        var here = -1, i;
        for (i = 0; i < tabs.length; i++) {
            if (tabs[i].classList.contains('is-on')) { here = i; break; }
        }
        /* Starting one past the tab on view, and ending on it: so the tab being
           read is the last place Enter settles, not the first. */
        for (i = 1; i <= tabs.length; i++) {
            var tab  = tabs[(here + i) % tabs.length];
            var pane = document.getElementById(tab.dataset.pane);
            var n    = pane ? shownIn(pane) : 0;
            if (n > 0) {
                if (tab !== tabs[here]) { tab.click(); }
                say(n + ' match' + (n === 1 ? '' : 'es') + ' in ' + tabName(tab));
                return;
            }
        }
        say('Nothing matches that search.');
    }

    field.addEventListener('input', function () { applyFilter(); say(''); });
    field.addEventListener('keydown', function (e) {
        /* Escape clears the field while the cursor is still in it, the way the
           browser's own clear button does. */
        if (e.key === 'Escape' && field.value !== '') {
            e.stopPropagation();
            field.value = '';
            applyFilter();
            say('');
            return;
        }
        if (e.key === 'Enter') {
            /* There is no form to submit, but Enter in a search field is a submit
               everywhere else on the site, so it is stopped explicitly rather
               than left to do nothing by accident. */
            e.preventDefault();
            if (field.value.trim() !== '') { walkToMatch(); }
        }
    });

    /* A chip reorders or re-filters the rows under it, so the search is applied
       again once it has: otherwise its own count, and the row that says nothing
       matched, are a step behind the page. */
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.mgmt-chip') && field.value.trim() !== '') {
            setTimeout(applyFilter, 0);
        }
    });

    /* A browser that kept what was typed across a reload (Firefox does)
       reinstates the text but not the filter. */
    if (field.value) { applyFilter(); }

    window.mgmtSearch = { hidden: rowHidden, apply: applyFilter };
});
</script>
