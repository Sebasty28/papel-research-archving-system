<?php
/** Remember file owners before a later reconnection loses access to them. */
function gdrive_inventory_schema_sql(): string
{
    return "CREATE TABLE IF NOT EXISTS gdrive_file_inventory (
        file_id VARCHAR(255) NOT NULL,
        owner_email VARCHAR(255) NOT NULL DEFAULT '',
        owner_name VARCHAR(255) NOT NULL DEFAULT '',
        drive_id VARCHAR(255) NOT NULL DEFAULT '',
        parent_id VARCHAR(255) NOT NULL DEFAULT '',
        file_name VARCHAR(500) NOT NULL DEFAULT '',
        access_state VARCHAR(20) NOT NULL DEFAULT 'unchecked',
        checked_by_email VARCHAR(255) NOT NULL DEFAULT '',
        checked_at DATETIME NULL,
        owner_checked_at DATETIME NULL,
        PRIMARY KEY (file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

function gdrive_inventory_ready(bool $create = false): bool
{
    static $ready = null;
    try {
        if ($ready === null) {
            $r = db()->query("SHOW TABLES LIKE 'gdrive_file_inventory'");
            $ready = $r && $r->num_rows > 0;
        }
        // Never run DDL from an upload: it would commit the paper transaction.
        if (!$ready && $create) $ready = db()->query(gdrive_inventory_schema_sql()) === true;
    } catch (Throwable $e) {
        error_log('Drive inventory unavailable: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function gdrive_inventory_fields(): string
{
    /* sha256Checksum is Drive's own fingerprint of the file's bytes. It rides on the
       call that is already being made so that a paper filed before the upload page
       kept one gets its fingerprint here (manuscript_hash_remember()), with no
       download. Google leaves it out for a file with no binary content, which is
       no harm: nothing is filled in. */
    return 'id,name,owners(emailAddress,displayName),driveId,parents,trashed,capabilities(canDownload),sha256Checksum';
}

/** Best effort: a metadata failure must never turn a successful upload into a failure. */
function gdrive_inventory_record_file($file, string $checkedBy = ''): bool
{
    // Independent of the inventory table: this fills a manuscript's fingerprint and nothing else.
    try {
        manuscript_hash_remember((string)$file->getId(), (string)$file->getSha256Checksum());
    } catch (Throwable $e) {
        error_log('Drive checksum not read: ' . $e->getMessage());
    }
    if (!gdrive_inventory_ready()) return false;
    try {
        $owners = $file->getOwners() ?: [];
        $email = isset($owners[0]) ? (string)$owners[0]->getEmailAddress() : '';
        $name = isset($owners[0]) ? (string)$owners[0]->getDisplayName() : '';
        $drive = (string)$file->getDriveId();
        $parents = $file->getParents() ?: [];
        $parent = (string)($parents[0] ?? '');
        $id = (string)$file->getId();
        $filename = (string)$file->getName();
        $cap = $file->getCapabilities();
        $state = $file->getTrashed() ? 'trashed' : ($cap && $cap->getCanDownload() === false ? 'restricted' : 'available');
        $ownerCheckedAt = ($email !== '' || $drive !== '') ? date('Y-m-d H:i:s') : null;
        $stmt = db()->prepare("INSERT INTO gdrive_file_inventory
            (file_id, owner_email, owner_name, drive_id, parent_id, file_name, access_state, checked_by_email, checked_at, owner_checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE owner_email=IF(VALUES(owner_checked_at) IS NULL,owner_email,VALUES(owner_email)),
            owner_name=IF(VALUES(owner_checked_at) IS NULL,owner_name,VALUES(owner_name)),
            drive_id=IF(VALUES(owner_checked_at) IS NULL,drive_id,VALUES(drive_id)), parent_id=VALUES(parent_id), file_name=VALUES(file_name),
            access_state=VALUES(access_state), checked_by_email=VALUES(checked_by_email), checked_at=NOW(),
            owner_checked_at=COALESCE(VALUES(owner_checked_at),owner_checked_at)");
        $stmt->bind_param('sssssssss', $id, $email, $name, $drive, $parent, $filename, $state, $checkedBy, $ownerCheckedAt);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('Drive file owner could not be recorded: ' . $e->getMessage());
        return false;
    }
}

/** Keep the last known owner if the new connection cannot see a file. A 404 is not proof of deletion. */
function gdrive_inventory_record_failure(string $id, string $state, string $checkedBy): void
{
    $stmt = db()->prepare("INSERT INTO gdrive_file_inventory (file_id, access_state, checked_by_email, checked_at)
        VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE access_state=VALUES(access_state),
        checked_by_email=VALUES(checked_by_email), checked_at=NOW()");
    $stmt->bind_param('sss', $id, $state, $checkedBy);
    if (!$stmt->execute()) throw new RuntimeException('Could not save the PDF check');
    $stmt->close();
}

/** Only PDFs referenced by this installation; never enumerate unrelated files in a person's Drive. */
function gdrive_pdf_references(): array
{
    $papers = "(SELECT paper_id, title, gdrive_file_id FROM research_papers
        UNION ALL SELECT a.paper_id, a.title, a.gdrive_file_id FROM papers_archive a
        WHERE NOT EXISTS (SELECT 1 FROM research_papers r WHERE r.paper_id=a.paper_id))";
    // Codes, not words: the labels come from core.php's helpers below, so "full" and
    // "copyright_doc" are never printed as stored. A copy without document_name still works.
    $docName = supporting_documents_have_name() ? 'd.document_name' : 'NULL';
    $queries = ["SELECT p.paper_id, p.title, p.gdrive_file_id AS file_id, 'primary' AS kind, '' AS detail, '' AS detail_name FROM $papers p"];
    if (paper_manuscripts_ready()) {
        $queries[] = "SELECT p.paper_id, p.title, m.gdrive_file_id, 'manuscript', m.format, ''
            FROM paper_manuscripts m JOIN $papers p ON p.paper_id=m.paper_id";
    }
    $queries[] = "SELECT p.paper_id, p.title, d.gdrive_file_id, 'supporting', d.document_type, $docName
        FROM supporting_documents d JOIN $papers p ON p.paper_id=d.paper_id";
    $result = db()->query(implode(' UNION ALL ', $queries));
    if (!$result) throw new RuntimeException('Could not read the PDF records');
    $files = [];
    while ($row = $result->fetch_assoc()) {
        $id = trim((string)$row['file_id']);
        if ($id === '') continue;
        if (!isset($files[$id])) {
            $files[$id] = ['file_id' => $id, 'title' => $row['title'], 'kinds' => [], 'paper_id' => (int)$row['paper_id']];
        }
        if ($row['kind'] === 'manuscript') {
            $label = 'Manuscript (' . manuscript_format_label((string)$row['detail']) . ')';
        } elseif ($row['kind'] === 'supporting') {
            $label = 'Supporting document: ' . supporting_doc_label($row['detail'], $row['detail_name']);
        } else {
            $label = 'Manuscript';
        }
        $files[$id]['kinds'][$label] = true;
    }
    // The paper's own columns mirror its primary manuscript, so a file a manuscript row
    // already names needs no second, vaguer label beside it.
    foreach ($files as &$file) {
        if (count($file['kinds']) > 1) unset($file['kinds']['Manuscript']);
    }
    unset($file);
    uasort($files, static function ($a, $b) { return strcasecmp($a['title'], $b['title']); });
    return $files;
}

/** No inference from upload dates or from permission lists: sharing is not ownership. */
function gdrive_inventory_groups(array $files, array $cached, array $historyEmails, string $currentEmail): array
{
    $currentEmail = strtolower(trim($currentEmail));
    $known = [];
    foreach ($historyEmails as $email) {
        $email = strtolower(trim($email));
        if ($email !== '') $known[$email] = true;
    }
    $groups = [];
    $add = static function (string $key, string $label, string $category) use (&$groups): void {
        if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'label' => $label, 'category' => $category, 'files' => []];
    };
    if ($currentEmail !== '') $add('account:' . $currentEmail, $currentEmail, 'current');
    foreach ($known as $email => $_) {
        $add('account:' . $email, $email, $email === $currentEmail ? 'current' : 'previous');
    }
    foreach ($files as $id => $file) {
        $meta = $cached[$id] ?? [];
        $email = strtolower(trim((string)($meta['owner_email'] ?? '')));
        if (!empty($meta['drive_id'])) {
            $key = 'drive:' . $meta['drive_id'];
            $add($key, 'Shared drive · ' . $meta['drive_id'], 'shared');
        } elseif ($email !== '') {
            $key = 'account:' . $email;
            $add($key, $email, $email === $currentEmail ? 'current' : (isset($known[$email]) ? 'previous' : 'other'));
        } else {
            $key = 'unknown';
            $add($key, 'Account not confirmed', 'unknown');
        }
        $file['meta'] = $meta;
        $file['access_current'] = $currentEmail !== '' && strtolower((string)($meta['checked_by_email'] ?? '')) === $currentEmail;
        // Without Google's word on who is connected, no check can be said to be this account's.
        $file['identity_known'] = $currentEmail !== '';
        $groups[$key]['files'][] = $file;
    }
    $rank = ['current' => 0, 'previous' => 1, 'other' => 2, 'shared' => 3, 'unknown' => 4];
    uasort($groups, static function ($a, $b) use ($rank) {
        return ($rank[$a['category']] <=> $rank[$b['category']]) ?: strcasecmp($a['label'], $b['label']);
    });
    return $groups;
}

function gdrive_inventory_view(string $currentEmail): array
{
    $files = gdrive_pdf_references();
    $cached = [];
    if (gdrive_inventory_ready()) {
        $r = db()->query('SELECT * FROM gdrive_file_inventory');
        if ($r) while ($row = $r->fetch_assoc()) $cached[$row['file_id']] = $row;
    }
    $known = [];
    if (gdrive_history_ready()) {
        $r = db()->query("SELECT DISTINCT account_email FROM gdrive_setting_history WHERE account_email <> ''");
        if ($r) while ($row = $r->fetch_assoc()) $known[] = $row['account_email'];
    }
    $pending = [];
    foreach ($files as $id => $_) {
        if (!isset($cached[$id]) || strtolower((string)$cached[$id]['checked_by_email']) !== strtolower($currentEmail)) $pending[] = $id;
    }
    return ['groups' => gdrive_inventory_groups($files, $cached, $known, $currentEmail),
        'ids' => array_keys($files), 'pending' => $pending, 'total' => count($files)];
}

/** What each list is called: the account's relation to the one connected now. */
function gdrive_inventory_category_label(string $category): string
{
    $labels = ['current' => 'Current account', 'previous' => 'Previous account',
        'other' => 'Other account', 'shared' => 'Shared drive', 'unknown' => 'Account not confirmed'];
    return $labels[$category] ?? 'Other account';
}

/**
 * One answer to "can PAPEL still open this PDF?", for the list and the export alike.
 * Only a check made by the account connected now counts: access is a property of the
 * account asking, so an earlier account's "available" says nothing about this one.
 * View only is not accessible to PAPEL: it streams a file's contents (alt=media),
 * which an account that may not download cannot do.
 */
function gdrive_inventory_access(array $file): array
{
    $unchecked = ['key' => 'unknown', 'label' => 'Not checked',
        'detail' => 'Not checked with the connected account yet. Press Check PDF accounts.'];
    if (isset($file['identity_known']) && !$file['identity_known']) {
        return ['key' => 'unknown', 'label' => 'Not confirmed',
            'detail' => 'Google could not confirm the connected account just now, so access cannot be shown. Reload the page to try again.'];
    }
    if (empty($file['access_current'])) return $unchecked;
    switch ((string)($file['meta']['access_state'] ?? '')) {
        case 'available':
            return ['key' => 'ok', 'label' => 'Accessible',
                'detail' => 'The connected account can open and download this PDF.'];
        case 'restricted':
            return ['key' => 'limited', 'label' => 'View only',
                'detail' => 'The connected account can see this PDF but cannot download it, so PAPEL may not be able to open it.'];
        case 'trashed':
            return ['key' => 'gone', 'label' => 'In Drive trash',
                'detail' => 'This PDF is in the Google Drive trash. Restore it in Drive to open it again.'];
        case 'unavailable':
            return ['key' => 'gone', 'label' => 'Not accessible',
                'detail' => 'The connected account cannot open this PDF, or it is no longer in Drive.'];
        case 'error':
            return ['key' => 'unknown', 'label' => 'Check failed',
                'detail' => 'Google could not be asked about this PDF. Press Check PDF accounts to try again.'];
        default:
            return $unchecked;
    }
}

/** How many PDFs in one list give each access answer. */
function gdrive_inventory_access_counts(array $files): array
{
    $counts = ['ok' => 0, 'limited' => 0, 'gone' => 0, 'unknown' => 0];
    foreach ($files as $file) $counts[gdrive_inventory_access($file)['key']]++;
    return $counts;
}

/** The export's rows, one list per account, in the order the page shows them. */
function gdrive_inventory_export_rows(array $groups): array
{
    $rows = [];
    foreach ($groups as $group) {
        $rows[$group['key']] = [];
        foreach ($group['files'] as $file) {
            $meta = $file['meta'];
            $rows[$group['key']][] = [
                (string)$group['label'],
                gdrive_inventory_category_label($group['category']),
                (string)$file['title'],
                implode('; ', array_keys($file['kinds'])),
                (string)($meta['file_name'] ?? ''),
                gdrive_inventory_access($file)['label'],
                !empty($meta['checked_at']) ? date('Y-m-d H:i', strtotime($meta['checked_at'])) : '',
                !empty($meta['owner_checked_at']) ? date('Y-m-d', strtotime($meta['owner_checked_at'])) : '',
                'https://drive.google.com/file/d/' . rawurlencode((string)$file['file_id']) . '/view',
            ];
        }
    }
    return $rows;
}

/**
 * The Google Drive mark, for a link that opens a file in Drive. The six
 * paths are Google's own artwork, carried over byte for byte from Wikimedia
 * Commons' File:Google_Drive_icon_(2020).svg (viewBox 0 0 87.3 78, Author
 * Google LLC, tagged public domain there as too simple to copyright; Drive's
 * name and mark can still be a trademark, which this does not grant any
 * right to beyond showing where the link goes, the way a "Open in Drive"
 * button does everywhere else). Do not hand-simplify this again.
 */
function gdrive_drive_mark_svg(): string
{
    return '<svg class="storage-drive-mark" viewBox="0 0 87.3 78" width="17" height="15" aria-hidden="true" focusable="false">'
         . '<path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>'
         . '<path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0 -1.2 4.5h27.5z" fill="#00ac47"/>'
         . '<path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>'
         . '<path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/>'
         . '<path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#2684fc"/>'
         . '<path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>'
         . '</svg>';
}
