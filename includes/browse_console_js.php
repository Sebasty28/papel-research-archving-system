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
<script src="<?= e(asset_url('assests/js/papel-browse-console.js')) ?>"></script>

<?php
/* ===== The Browse & Filters drawer =====
   On a narrow screen the right-hand <aside> stops being a column and becomes a
   drawer, opened by the tune button in the toolbar. This used to live in
   archive/index.php alone; the dashboards and the four review desks have the
   same <aside id="sidebarCol">, and on a phone theirs was just as far down the
   page, so the whole thing moved here and all of them get it.

   Printed once per page. Every page that has a drawer requires this file
   exactly once, but the guard is cheap and a second backdrop would sit over the
   first with nothing closing it. */
if (!defined('PAPEL_TOOLS_DRAWER_DONE')):
    define('PAPEL_TOOLS_DRAWER_DONE', 1);
?>
<div class="tools-backdrop" id="toolsBackdrop"></div>
<?php /* Outside the <aside>, because everything inside it is replaced whenever a
         filter is applied and a close button in there would be swept away. */ ?>
<button type="button" class="btn-tools-close" id="toolsClose" aria-label="Close Browse and Filters">
    <span class="material-symbols-outlined">close</span>
</button>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Everything here is delegated or bound to elements outside the <aside>,
   because applying a filter replaces the whole inside of it and any listener
   attached in there would go with it. */
(function () {
    var root = document.documentElement;
    if (!document.getElementById('toolsToggle')) { return; } // no drawer on this page

    function setOpen(on) {
        root.classList.toggle('tools-open', on);
        var btn = document.getElementById('toolsToggle');
        if (btn) btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    function isOpen() { return root.classList.contains('tools-open'); }

    /* An AJAX tab or filter refresh replaces #mainCol, including this button.
       Listen on the document so the new button opens the same drawer. */
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('#toolsToggle')) {
            setOpen(!isOpen());
        }
    });

    /* Delegated, not bound. This block runs where it stands and the backdrop
       and the close button are printed just above it, but a page may yet print
       its own later; delegation does not care when they arrive. */
    document.addEventListener('click', function (e) {
        if (!isOpen() || !e.target.closest) { return; }
        if (e.target.closest('#toolsClose, #toolsBackdrop')) { setOpen(false); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { setOpen(false); }
    });

    /* Choosing a filter refreshes the list behind the drawer, so get out of the
       way and let the results be the thing on screen. */
    document.addEventListener('change', function (e) {
        if (isOpen() && e.target.closest('#sidebarCol')) { setOpen(false); }
    });
    document.addEventListener('click', function (e) {
        if (isOpen() && e.target.closest('#sidebarCol a[href]')) { setOpen(false); }
    });

    /* Back on a wide screen the sidebar is a column again and the drawer means
       nothing, but the open class would still be holding the page's scroll. */
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900 && isOpen()) { setOpen(false); }
    });
})();
</script>
<?php endif; ?>

<?php
/* ===== The tab row's chevrons =====
   .dash-tabs is one line that slides sideways when the tabs do not fit, and
   this says which way it can go: a chevron at the end the line carries on
   past, none at an end that is already there, so a row that fits shows
   neither and a row in the middle shows both. The look is in
   includes/console_shell.php, on .dash-tabs-glide, the wrapper round the
   scrolling box.

   Here rather than on one page because the student dashboard and the four
   review desks draw the same row, out of two files, and both already require
   this one. A page with no such row prints the block and does nothing. */
if (!defined('PAPEL_TABS_GLIDE_DONE')):
    define('PAPEL_TABS_GLIDE_DONE', 1);
?>
<script src="<?= e(asset_url('assests/js/papel-browse-console-drawer.js')) ?>"></script>
<?php endif; ?>
