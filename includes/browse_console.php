<?php
/**
 * Shared browse-console styles: the search field, results toolbar, Quick
 * Settings dropdown, paper list, loading state, and the right-hand
 * Browse/Filter sidebar cards.
 *
 * Used by archive/index.php (public repository) and the role dashboards so
 * the browse experience stays identical everywhere. Include inside <head>
 * AFTER includes/site_head.php (which supplies the design tokens).
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Cards or rows, applied before the first paint: read it afterwards and a
   list would draw as cards and then collapse, which on a long page is a
   visible lurch. Kept per device, beside the density and the palette the
   rest of Quick Settings remembers.

   papel_desk_view is the key this started life under, when only the review
   desks offered the switch; it is read as a fallback so nobody who set rows
   there is handed cards back. */
(function () {
    var v;
    try { v = localStorage.getItem('papel_view') || localStorage.getItem('papel_desk_view'); }
    catch (e) {}
    /* Shelf is offered by the repository, the student dashboard and My Library, not by
       the review desks: a desk is a queue worked through by status, not a
       shelf browsed. The setting is one per device and shared by every
       console, so a page that cannot draw a shelf falls back to cards rather
       than showing a list with no layout at all. The page says which it is by
       setting $VIEW_SHELF before this file is included. */
    var shelfOk = <?= !empty($VIEW_SHELF) ? 'true' : 'false' ?>;
    /* Two shelf views now, by the same licence: Hardbounds (stored as "shelf",
       its name before there were two, so nobody's saved choice is lost) and
       Bookshelf, the same books stood spine out. */
    var shelfy = v === 'shelf' || v === 'bookshelf';
    if (v !== 'rows' && !(shelfy && shelfOk)) { v = 'cards'; }
    document.documentElement.setAttribute('data-view', v);
})();
</script>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-browse-console.css')) ?>">
