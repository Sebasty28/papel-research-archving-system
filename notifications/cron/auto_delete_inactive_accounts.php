<?php
// Delete accounts that were never signed into within the activation window.
// Run daily via cron: 0 */6 * * * php /path/to/auto_delete_inactive_accounts.php
// Or via web cron: .../auto_delete_inactive_accounts.php?key=<CRON_KEY>

require_once __DIR__.'/../../config/core.php';
require_once __DIR__.'/../../includes/cron_guard.php';
require_cron_access('auto_delete_inactive_accounts');

$count = purge_inactive_accounts();

$h = defined('ACCOUNT_ACTIVATION_HOURS') ? ACCOUNT_ACTIVATION_HOURS : 48;
error_log("Account cleanup: removed $count account(s) that were never signed into within {$h}h");
echo "Removed $count inactive account(s)\n";
