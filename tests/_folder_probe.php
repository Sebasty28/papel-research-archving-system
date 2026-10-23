<?php
/**
 * Drive folder facts for tests/m12_storage_folder.py. Read-only against Drive.
 *
 *     php tests/_folder_probe.php setting            the folder this site uses now
 *     php tests/_folder_probe.php check <id|url>     gdrive_check_folder() on one input
 *     php tests/_folder_probe.php file_id            the Drive id of a real paper's PDF (a file, not a folder)
 *
 * Each prints one JSON object.
 */
require_once __DIR__ . '/../config/core.php';
require_once __DIR__ . '/../config/gdrive_config.php';

$mode = $argv[1] ?? '';
function out($v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }

if ($mode === 'setting') {
    $r = db()->query("SELECT setting_value, updated_by, updated_at FROM system_settings WHERE setting_key='gdrive_parent_folder_id'")->fetch_assoc();
    out($r ?: ['setting_value' => null]);
}
if ($mode === 'check') {
    $c = gdrive_check_folder((string)($argv[2] ?? ''));
    out(['state' => $c['state'], 'name' => $c['name'], 'unusable' => gdrive_folder_is_unusable($c)]);
}
if ($mode === 'file_id') {
    $r = db()->query("SELECT gdrive_file_id FROM research_papers WHERE gdrive_file_id IS NOT NULL AND gdrive_file_id <> '' LIMIT 1")->fetch_assoc();
    out(['id' => $r['gdrive_file_id'] ?? '']);
}
out(['error' => 'unknown mode']);
