<?php
/**
 * Shared console shell - the dashboard look the student page established.
 *
 * Search row, tab strip, toolbar, the card list and its progress tracker. The
 * browse chrome underneath it (sidebar cards, filters, suggestions, quick
 * settings) comes from includes/browse_console.php, which this expects to have
 * been included already.
 *
 * Every role dashboard uses this, so a change here reaches all of them at once
 * and none of them can quietly drift from the others.
 *
 * Include inside <head>, after site_head.php and browse_console.php.
 */
?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-console-shell.css')) ?>">
