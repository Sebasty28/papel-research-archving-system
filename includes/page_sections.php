<?php
/**
 * A content page split into sections: a list down the left, and the chosen
 * section's card beside it, one card showing at a time.
 *
 * Include inside <head>, AFTER includes/page_theme.php — the cards are its
 * .page-card, and the shell is its .page-shell with one class added.
 *
 * Markup shape (the ids pair up by name: tab-<name> ↔ sec-<name>):
 *   <div class="page-shell page-sections">
 *     <nav class="page-sections-nav" role="tablist" aria-label="…" aria-orientation="vertical">
 *       <button type="button" class="page-sections-tab" role="tab" id="tab-one"
 *               aria-controls="sec-one" aria-selected="true" data-section="one">
 *         <span class="material-symbols-outlined">…</span> One
 *       </button>
 *       …
 *     </nav>
 *     <div class="page-sections-panels">
 *       <section class="page-card" id="sec-one" role="tabpanel" aria-labelledby="tab-one"> … </section>
 *       …
 *     </div>
 *   </div>
 *
 * The chosen section is kept in the address's #fragment, so a reload, the Back
 * button and a link from elsewhere (settings.php#password) all land on the
 * right card; a fragment naming something inside a card opens that card.
 * Without JavaScript nothing is hidden and the cards stack.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.page-sections {
    display: grid;
    grid-template-columns: 14rem minmax(0, 1fr);
    gap: .75rem;
    align-items: start;
}
.page-sections-nav {
    display: flex;
    flex-direction: column;
    padding: .375rem 0;
    background: var(--white);
    border: 1px solid rgba(177, 125, 125, .22);
    border-radius: var(--r-card, 8px);
}
.page-sections-tab {
    position: relative;
    display: flex;
    align-items: center;
    gap: .625rem;
    width: 100%;
    padding: .7rem 1rem;
    border: none;
    background: none;
    color: var(--ink);
    font-family: inherit;
    font-size: .875rem;
    text-align: left;
    cursor: pointer;
    transition: color .15s;
}
.page-sections-tab .material-symbols-outlined { font-size: 20px; color: var(--grey); transition: color .15s; }
/* Marked the way the navbar marks the page you are on — a bar rather than a
   filled block — turned on its side to run down the left of the list: soft
   where the pointer is, solid on the section that is open. */
.page-sections-tab::before {
    content: '';
    position: absolute;
    left: 0;
    top: .45rem;
    bottom: .45rem;
    width: 3px;
    border-radius: 0 2px 2px 0;
    background: var(--soft-maroon);
    transform: scaleY(0);
    transition: transform .2s ease, background-color .2s ease;
}
.page-sections-tab:hover { color: var(--dark-maroon); }
.page-sections-tab:hover::before { transform: scaleY(1); }
.page-sections-tab[aria-selected="true"] { color: var(--maroon); }
.page-sections-tab[aria-selected="true"] .material-symbols-outlined { color: var(--maroon); }
.page-sections-tab[aria-selected="true"]::before { transform: scaleY(1); background: var(--maroon); }
/* The site's ring (focus_ring.php) is drawn 2px outside, with !important. Here
   that spilt over the list's border, and in the phone tab row, which scrolls
   and so clips, it lost its top and bottom edges. Same ring, drawn inside. */
.page-sections-nav .page-sections-tab:focus-visible { outline-offset: -2px !important; border-radius: var(--r-control, 4px); }
/* A panel that is shown is the last card in its column, so the stacked
   layout's bottom margin has nothing to separate it from. */
.page-sections-panels.is-tabbed > .page-card { margin-bottom: 0; }
@media (prefers-reduced-motion: reduce) {
    .page-sections-tab::before { transition: none; }
}

@media (max-width: 700px) {
    /* On a phone the list becomes a row of tabs across the top, scrolled
       sideways when they do not all fit, and the bar goes back to lying under
       the label, as it does in the navbar. */
    .page-sections { grid-template-columns: 1fr; }
    .page-sections-nav {
        flex-direction: row;
        overflow-x: auto;
        padding: 0 .25rem;
        scrollbar-width: none;
    }
    .page-sections-nav::-webkit-scrollbar { display: none; }
    .page-sections-tab { width: auto; flex: 0 0 auto; padding: .8rem .75rem; white-space: nowrap; }
    .page-sections-tab::before {
        top: auto; bottom: 0; left: .75rem; right: .75rem;
        width: auto; height: 2px;
        border-radius: 2px;
        transform: scaleX(0);
    }
    .page-sections-tab:hover::before,
    .page-sections-tab[aria-selected="true"]::before { transform: scaleX(1); }
}
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var shell = document.querySelector('.page-sections');
    if (!shell) return;
    var tabs   = Array.prototype.slice.call(shell.querySelectorAll('.page-sections-tab'));
    var panels = shell.querySelector('.page-sections-panels');
    if (!tabs.length || !panels) return;

    function showSection(name, moveFocus) {
        var tab = tabs.filter(function (t) { return t.dataset.section === name; })[0] || tabs[0];
        tabs.forEach(function (t) {
            var on = (t === tab);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            // One tab stop for the whole list; the arrow keys move within it.
            t.tabIndex = on ? 0 : -1;
            var panel = document.getElementById(t.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
        });
        if (moveFocus) tab.focus();
        return tab.dataset.section;
    }
    /* The #fragment is usually a section's own name, but it can also be the id
       of something inside one — help_center.php#forgot-password, linked from
       the sign-in panel, is a single question in the FAQ. That opens the
       section holding it, so the page's own script can then find it on screen;
       left to fall back to the first section, it would only work while that
       question happened to live there. */
    function sectionFor(hash) {
        var name = (hash || '').replace('#', '');
        if (!name) return '';
        if (tabs.some(function (t) { return t.dataset.section === name; })) return name;
        var el = document.getElementById(name);
        var panel = el && el.closest('[role="tabpanel"]');
        var owner = panel && tabs.filter(function (t) { return t.getAttribute('aria-controls') === panel.id; })[0];
        return owner ? owner.dataset.section : '';
    }
    function remember(name) {
        /* replaceState rather than setting location.hash: the hash would
           scroll the page to the card's id, jumping the list out from under
           the pointer that just chose it. */
        try { history.replaceState(null, '', '#' + name); } catch (err) {}
    }

    panels.classList.add('is-tabbed');
    showSection(sectionFor(location.hash), false);

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            remember(showSection(t.dataset.section, false));
        });
        t.addEventListener('keydown', function (e) {
            var i = tabs.indexOf(t), next = null;
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            else if (e.key === 'Home') next = tabs[0];
            else if (e.key === 'End') next = tabs[tabs.length - 1];
            if (!next) return;
            e.preventDefault();
            remember(showSection(next.dataset.section, true));
        });
    });
    // Back and Forward between sections chosen by a link.
    window.addEventListener('hashchange', function () {
        showSection(sectionFor(location.hash), false);
    });
});
</script>
