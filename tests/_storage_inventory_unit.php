<?php
// Run after opening Storage Folder once locally. All writes use a temporary table.
require_once __DIR__ . '/../config/gdrive_config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function verify_inventory($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$files = [];
foreach (['a', 'b', 'c', 'd', 'e'] as $id) $files[$id] = ['file_id' => $id];
$cache = [
    'a' => ['owner_email' => 'CURRENT@example.com', 'checked_by_email' => 'current@example.com'],
    'b' => ['owner_email' => 'old1@example.com', 'checked_by_email' => 'old1@example.com'],
    'c' => ['owner_email' => 'old2@example.com'],
    'e' => ['drive_id' => 'team-drive'],
];
$groups = gdrive_inventory_groups($files, $cache, ['old1@example.com', 'old2@example.com'], 'current@example.com');
verify_inventory(count($groups) === 5, 'Three accounts, unknown owner and shared drive each have a list');
verify_inventory($groups['account:current@example.com']['category'] === 'current', 'Current account comparison ignores email case');
verify_inventory($groups['account:old1@example.com']['category'] === 'previous', 'Connection history identifies a previous account');
verify_inventory(!$groups['account:old1@example.com']['files'][0]['access_current'], 'An old account access check is stale after reconnecting');
verify_inventory(count($groups['unknown']['files']) === 1, 'Unidentified PDFs are never guessed into an account');
$groups = gdrive_inventory_groups($files, $cache, [], '');
verify_inventory(!in_array('current', array_column($groups, 'category'), true), 'No account is labelled current when Google identity is unavailable');

// The access answer the list and the export both print.
$access = function (array $meta, bool $current, bool $known = true): string {
    return gdrive_inventory_access(['meta' => $meta, 'access_current' => $current, 'identity_known' => $known])['label'];
};
verify_inventory($access(['access_state' => 'available'], true) === 'Accessible', 'A PDF the connected account can download is accessible');
verify_inventory($access(['access_state' => 'restricted'], true) === 'View only', 'A PDF that cannot be downloaded is view only, since PAPEL streams the file');
verify_inventory($access(['access_state' => 'unavailable'], true) === 'Not accessible', 'A PDF the connected account cannot open is not accessible');
verify_inventory($access(['access_state' => 'trashed'], true) === 'In Drive trash', 'A trashed PDF says so');
verify_inventory($access(['access_state' => 'available'], false) === 'Not checked', 'A check by another account says nothing about this one');
verify_inventory($access(['access_state' => 'available'], false, false) === 'Not confirmed', 'Without Google confirming the account, access is not claimed');
$tally = gdrive_inventory_access_counts([
    ['meta' => ['access_state' => 'available'], 'access_current' => true],
    ['meta' => ['access_state' => 'restricted'], 'access_current' => true],
    ['meta' => ['access_state' => 'unavailable'], 'access_current' => true],
    ['meta' => [], 'access_current' => false],
]);
verify_inventory($tally === ['ok' => 1, 'limited' => 1, 'gone' => 1, 'unknown' => 1], 'Each list tallies its access answers');

if (!gdrive_inventory_ready()) throw new RuntimeException('Open local Storage Folder before this test');
$conn = db();
$sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', gdrive_inventory_schema_sql());
// Explicit schema: CREATE TEMPORARY TABLE name LIKE name is invalid and can leave writes on the real table.
$conn->query($sql);
verify_inventory((int)$conn->query('SELECT COUNT(*) n FROM gdrive_file_inventory')->fetch_assoc()['n'] === 0, 'Temporary inventory starts empty');
$file = new Google_Service_Drive_DriveFile([
    'id' => 'inventory-unit-file', 'name' => '<script>PDF</script>.pdf',
    'owners' => [['emailAddress' => 'old1@example.com', 'displayName' => 'Old account']],
    'parents' => ['old-folder'], 'capabilities' => ['canDownload' => true],
]);
verify_inventory(gdrive_inventory_record_file($file, 'old1@example.com'), 'Owner metadata is saved');
gdrive_inventory_record_failure('inventory-unit-file', 'unavailable', 'current@example.com');
$row = $conn->query("SELECT * FROM gdrive_file_inventory WHERE file_id='inventory-unit-file'")->fetch_assoc();
verify_inventory($row['owner_email'] === 'old1@example.com' && $row['parent_id'] === 'old-folder', 'An inaccessible PDF retains its known owner and folder');
verify_inventory($row['access_state'] === 'unavailable' && $row['checked_by_email'] === 'current@example.com', 'Failed access is recorded against the account that checked');
$file->setOwners([new Google_Service_Drive_User(['emailAddress' => 'new-owner@example.com'])]);
verify_inventory(gdrive_inventory_record_file($file, 'current@example.com'), 'A later ownership transfer can be saved');
$row = $conn->query("SELECT * FROM gdrive_file_inventory WHERE file_id='inventory-unit-file'")->fetch_assoc();
verify_inventory($row['owner_email'] === 'new-owner@example.com', 'Successful rechecks update the owner');
$conn->query('DROP TEMPORARY TABLE gdrive_file_inventory');
echo "Inventory grouping and isolated persistence checks complete.\n";
