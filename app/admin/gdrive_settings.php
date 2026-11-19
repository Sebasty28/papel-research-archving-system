<?php
/**
 * Storage folder, where every uploaded paper is kept.
 *
 * This was a field tucked into a sidebar card on the Director's dashboard,
 * and for a while it was the Director's alone to change. It is the Research
 * Coordinator's as well now: running the research operation is their job,
 * and they could already connect Drive.
 * which gave no room to say what the setting actually does. It is one of the
 * few settings that changes where people's files physically land, so it gets a
 * page that explains itself before it asks for anything.
 */
require_once '../../config/core.php';
/* The Director and the Research Coordinator, who runs research on the
   campus and could already connect Drive without being able to say which
   folder the papers went into. Not require_role(['super_admin','admin']),
   which would also let in the Head of Academic Programs: that role is an
   admin at level 2 and differs by nothing else. */
require_research_operations();
require_once '../../config/gdrive_config.php';

$conn = db();
$u    = current_user();
$SELF = 'gdrive_settings.php';
$inventoryReady = gdrive_inventory_ready(true);

// Small requests keep the first page usable even when there are hundreds of PDFs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_pdf_accounts') {
    csrf_verify();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!$inventoryReady) throw new RuntimeException('The PDF account list cannot be saved yet.');
        $ids = $_POST['file_ids'] ?? [];
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 4) {
            http_response_code(400);
            throw new RuntimeException('Choose up to four PDFs per check.');
        }
        $allowed = gdrive_pdf_references();
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($allowed[$id])) {
                http_response_code(400);
                throw new RuntimeException('This PDF is not in this site’s records.');
            }
        }
        $client = get_gdrive_client();
        $client->setHttpClient(new GuzzleHttp\Client(['timeout' => 6, 'connect_timeout' => 3]));
        $identity = gdrive_connected_identity($client);
        if (!$identity['verified']) throw new RuntimeException('Google could not confirm the connected account. Reconnect or try again.');
        $service = new Google_Service_Drive($client);
        foreach (array_unique($ids) as $id) {
            try {
                $file = $service->files->get($id, ['fields' => gdrive_inventory_fields(), 'supportsAllDrives' => true]);
            } catch (Throwable $e) {
                $code = (int)$e->getCode();
                $errors = $e instanceof Google_Service_Exception ? $e->getErrors() : [];
                $reason = $errors[0]['reason'] ?? '';
                $unavailable = $code === 404 || ($code === 403 && in_array($reason, ['forbidden', 'insufficientFilePermissions'], true));
                gdrive_inventory_record_failure($id, $unavailable ? 'unavailable' : 'error', $identity['email']);
                if (!$unavailable) throw $e;
                continue;
            }
            if (!gdrive_inventory_record_file($file, $identity['email'])) throw new RuntimeException('The PDF check could not be saved. Try again.');
        }
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if (http_response_code() < 400) http_response_code(503);
        error_log('Drive PDF account check: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'The PDF account check could not finish. Check the Drive connection and try again.']);
    }
    exit;
}

if (isset($_GET['pdf_inventory'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $identity = gdrive_connected_identity();
    $pdfInventory = gdrive_inventory_view($identity['verified'] ? $identity['email'] : '');
    require ROOT_PATH . '/includes/storage_pdf_lists.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'update_gdrive_folder') {
        $folder_id = trim($_POST['gdrive_folder_id'] ?? '');
        if ($folder_id === '') {
            flash('error', 'Enter a folder link or ID first.');
        } elseif (!is_gdrive_connected()) {
            // A folder can only be checked from the account it will be written to.
            flash('error', 'Connect Google Drive first, so the folder can be checked against the account it will be written to.');
        } else {
            /* Looked at before it is kept. This used to save whatever was pasted, and
               staging ended up pointing at a folder in a Google account the connected
               one cannot see, so every paper failed at the last step with nothing on
               this page to say why. A folder is saved only once Drive confirms the
               connected account can add files to it; if Drive cannot be asked, it is
               not saved either, since "could not check" is not "fine". */
            $check = gdrive_check_folder($folder_id);
            if ($check['state'] !== 'ok') {
                flash('error', 'That folder was not saved. ' . gdrive_folder_explain($check));
            } elseif (extract_gdrive_folder_id($folder_id) === get_gdrive_parent_folder_id()) {
                flash('success', 'That folder is already in use. Nothing changed.');
            } elseif (update_gdrive_parent_folder_id($folder_id, $u['user_id'], $check['name'])) {
                flash('success', 'Storage folder updated to "' . $check['name'] . '". New uploads will go here.');
            } else {
                flash('error', 'That folder could not be saved. Check the link or ID and try again.');
            }
        }
    } elseif (($_POST['action'] ?? '') === 'save_gdrive_continuity') {
        $dates = [
            'gdrive_storage_renewal_date' => trim((string)($_POST['gdrive_storage_renewal_date'] ?? '')),
            'gcloud_billing_review_date' => trim((string)($_POST['gcloud_billing_review_date'] ?? '')),
        ];
        if (!gdrive_valid_continuity_date($dates['gdrive_storage_renewal_date'])
            || !gdrive_valid_continuity_date($dates['gcloud_billing_review_date'])) {
            flash('error', 'Enter valid dates, or leave a date empty if it is not known yet.');
        } elseif (gdrive_save_continuity_dates($dates, (int)$u['user_id'])) {
            flash('success', 'Reminder dates saved. Update them after each renewal or billing review.');
        } else {
            flash('error', 'The reminder dates could not be saved. Please try again.');
        }
    }
    // Return to the reminder tab after saving; URL fragments are not sent with POSTs.
    $returnTab = ($_POST['action'] ?? '') === 'save_gdrive_continuity' ? '#continuityReminders' : '';
    header("Location: $SELF$returnTab");
    exit;
}

// Set by pages/gdrive_callback.php, and said once: on the page Google sends the reader back to.
$justConnected = !empty($_SESSION['gdrive_connected_popup']);
unset($_SESSION['gdrive_connected_popup']);

$current   = get_gdrive_parent_folder_id();
$connected = is_gdrive_connected();
$authUrl   = get_gdrive_auth_url();
$recordedAccount = gdrive_current_account();
$liveAccount = $connected ? gdrive_connected_identity() : ['verified' => false, 'email' => '', 'name' => ''];
$continuityDates = gdrive_continuity_dates();

function storage_reminder_status(string $value): array
{
    if ($value === '' || !gdrive_valid_continuity_date($value)) {
        return ['class' => 'is-missing', 'text' => 'No date recorded'];
    }
    $today = new DateTimeImmutable('today');
    $due = new DateTimeImmutable($value);
    $days = (int)$today->diff($due)->format('%r%a');
    if ($days < 0) return ['class' => 'is-past', 'text' => 'Date passed ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago'];
    if ($days === 0) return ['class' => 'is-soon', 'text' => 'Due today'];
    return ['class' => $days <= 30 ? 'is-soon' : 'is-good',
        'text' => $days . ' day' . ($days === 1 ? '' : 's') . ' away'];
}
$storageReminder = storage_reminder_status($continuityDates['gdrive_storage_renewal_date']);
$cloudReminder = storage_reminder_status($continuityDates['gcloud_billing_review_date']);
$pdfInventory = gdrive_inventory_view($liveAccount['verified'] ? $liveAccount['email'] : '');

/* The folder as Google sees it, not just as it is written down. "Connected" says
   the account is let in; it says nothing about whether this folder is somewhere
   that account can write, and it is the folder that decides whether a paper can
   be submitted. Asked only when there is a connection to ask with. */
$folderCheck = $connected ? gdrive_check_folder($current) : null;
$folderBad   = $folderCheck && gdrive_folder_is_unusable($folderCheck);
$folderAccess = ($connected && $current && $folderCheck && $folderCheck['state'] === 'ok')
    ? gdrive_folder_access($current)
    : ['state' => $current ? 'unavailable' : 'unset', 'permissions' => [], 'truncated' => false];
$folderWideAccess = false;
foreach ($folderAccess['permissions'] as $permission) {
    if (in_array($permission['type'], ['anyone', 'domain'], true)) $folderWideAccess = true;
}
/* What is waiting to be cleared. Asked for only where there is a folder it
   could be in, so a page with no storage folder makes no Drive call for it. */
$trash = ($connected && $current && !$folderBad)
    ? gdrive_trash_contents()
    : ['folder_id' => '', 'count' => 0, 'capped' => false];
$accessRoleNames = [
    'owner' => 'Owner', 'organizer' => 'Manager', 'fileOrganizer' => 'Content manager',
    'writer' => 'Editor', 'commenter' => 'Commenter', 'reader' => 'Viewer',
];

// Who set it, and when: a setting this consequential should say who touched it.
$meta = null;
$ms = $conn->prepare(
    "SELECT s.updated_at, us.full_name
     FROM system_settings s LEFT JOIN users us ON us.user_id = s.updated_by
     WHERE s.setting_key = 'gdrive_parent_folder_id' LIMIT 1");
$ms->execute();
$meta = $ms->get_result()->fetch_assoc() ?: null;
$ms->close();

// How much is already filed away there, so it is clear what a change does and
// does not affect.
$stored = 0;
$sc = $conn->query("SELECT COUNT(*) AS n FROM research_papers WHERE gdrive_file_id IS NOT NULL AND gdrive_file_id <> ''");
if ($sc) $stored = (int)($sc->fetch_assoc()['n'] ?? 0);

$historyReady = gdrive_history_ready();
$history = [];
$historyPage = max(1, (int)($_GET['history_page'] ?? 1));
$historyPages = 1;
if ($historyReady) {
    $count = $conn->query('SELECT COUNT(*) AS n FROM gdrive_setting_history');
    $total = (int)$count->fetch_assoc()['n'];
    $historyPages = max(1, (int)ceil($total / 20));
    $historyPage = min($historyPage, $historyPages);
    $offset = ($historyPage - 1) * 20;
    $hs = $conn->prepare(
        'SELECT h.event_type, h.account_email, h.account_name, h.folder_id, h.folder_name, '
        . 'h.changed_at, us.full_name AS staff_name '
        . 'FROM gdrive_setting_history h LEFT JOIN users us ON us.user_id = h.changed_by '
        . 'ORDER BY h.history_id DESC LIMIT 20 OFFSET ?'
    );
    $hs->bind_param('i', $offset);
    $hs->execute();
    $history = $hs->get_result()->fetch_all(MYSQLI_ASSOC);
    $hs->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Storage Folder · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.set-wrap { max-width: 52rem; margin: 0 auto; padding: 2rem 0 3rem; }
.set-head h1 {
    font-family: var(--font-head); font-size: 1.5rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .35rem;
}
.set-head p { font-size: .875rem; color: var(--grey); line-height: 1.7; margin: 0 0 1.75rem; max-width: 44rem; }

.set-card {
    background: var(--white); border: 1px solid var(--border); border-radius: var(--r-card, 8px);
    padding: 1.5rem; margin-bottom: 1.25rem;
}
.set-card h2 {
    font-family: var(--font-head); font-size: 1rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .75rem;
}
.set-card p, .set-card li { font-size: .8125rem; color: var(--ink); line-height: 1.75; }
.set-card p { margin: 0 0 .75rem; }
.set-card p:last-child, .set-card ol:last-child, .set-card ul:last-child { margin-bottom: 0; }
.set-card ol, .set-card ul { margin: 0 0 .75rem; padding-left: 1.25rem; }
.set-card code {
    font-family: monospace; font-size: .75rem; background: var(--cream);
    color: var(--dark-maroon); padding: .1rem .35rem; border-radius: var(--r-card, 8px);
}

/* Current state, stated plainly before the control that changes it. */
.set-status { display: flex; align-items: flex-start; gap: .75rem; }
.set-status-ico {
    width: 2.25rem; height: 2.25rem; flex: 0 0 2.25rem; border-radius: var(--r-badge, 2px);
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--cream); color: var(--maroon);
}
.set-status.is-unset .set-status-ico { background: #fdeaea; }
.set-status-id {
    font-family: monospace; font-size: .8125rem; color: var(--maroon);
    word-break: break-all; text-decoration: none;
}
.set-status-id:hover { text-decoration: underline; }
.set-status-note { font-size: .75rem; color: var(--grey); margin: .25rem 0 0; }

.set-field { display: block; margin-bottom: .875rem; }
.set-field span {
    display: block; font-size: .75rem; font-weight: 500; color: var(--ink); margin-bottom: .35rem;
}
.set-field input {
    width: 100%; border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    padding: .625rem .75rem; font-family: monospace; font-size: .8125rem;
    color: var(--ink); background: var(--white);
}
.set-field input:focus { outline: none; border-color: var(--maroon); }
.set-actions { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.set-actions .set-hint { font-size: .75rem; color: var(--grey); }

.set-flash { border-radius: var(--r-card, 8px); padding: .75rem 1rem; font-size: .8125rem; margin-bottom: 1.25rem; }
/* Tokens, not a pair of literals: these turned white-on-pale in Old Night, the
   one palette they were never checked against. With script running, the banner
   is lifted into the site's snackbar anyway (includes/flash_dismiss.php) and
   this is what is seen without it. */
.set-flash.ok  { background: var(--ok-bg);  color: var(--ok-text); }
.set-flash.bad { background: var(--bad-bg); color: var(--bad-text); }

.set-history-scroll { overflow-x: auto; }
.set-history { width: 100%; border-collapse: collapse; font-size: .75rem; }
.set-history th, .set-history td { text-align: left; vertical-align: top; padding: .65rem .5rem; border-bottom: 1px solid var(--border); }
.set-history th { color: var(--maroon); font-weight: 600; white-space: nowrap; }
.set-history td { color: var(--ink); line-height: 1.5; }
.set-history tbody tr:last-child td { border-bottom: 0; }
.set-history a { overflow-wrap: anywhere; }
.set-history small { display: block; color: var(--grey); font-size: .7rem; }
.set-history-pages { display: flex; align-items: center; gap: .75rem; margin-top: 1rem; font-size: .75rem; }

.set-reminders { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; margin: .75rem 0 1rem; }
.set-reminder { border: 1px solid var(--border); border-radius: var(--r-card, 8px); padding: .875rem 1rem; background: var(--cream); }
.set-reminder strong, .set-reminder span { display: block; }
.set-reminder strong { font-size: .8125rem; color: var(--ink); }
.set-reminder span { font-size: .75rem; margin-top: .3rem; color: var(--grey); }
.set-reminder.is-soon { border-color: var(--warn-text); }
.set-reminder.is-past { border-color: var(--bad-text); }
.set-reminder.is-soon span, .set-reminder.is-past span { font-weight: 600; color: var(--maroon); }
.set-date-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
.set-date-fields input { font-family: inherit; }
.set-reminder-banner { background: var(--cream); border: 1px solid var(--border); border-left: 4px solid var(--maroon); border-radius: var(--r-card, 8px); padding: .75rem 1rem; margin: 0 0 1.25rem; font-size: .8125rem; line-height: 1.6; }

@media (max-width: 600px) {
    .set-wrap { padding: 1.25rem 0 2rem; }
    .set-card { padding: 1.125rem; }
    .set-reminders, .set-date-fields { grid-template-columns: 1fr; }
}
</style>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/storage_folder.css')) ?>">
<script src="<?= e(asset_url('assests/js/storage_folder.js')) ?>" defer></script>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Public Repository</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <?php /* The reader's own desk, not the Director's. Hard-coded to
                 super_admin_review_dashboard.php, this crumb sent a Research
                 Coordinator to a page require_role() refuses, and a refused
                 role is signed out: a breadcrumb that logs you out. */ ?>
        <a href="<?= e(role_home((string)($u['user_role'] ?? ''))) ?>"><?= e(role_home_label((string)($u['user_role'] ?? ''))) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Storage Folder</span>
    </div>
</div>

<main class="wrap storage-main">
    <div class="set-wrap">

        <?php if ($msg = flash('success')): ?>
            <div class="set-flash ok"><?= e($msg) ?></div>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?>
            <div class="set-flash bad"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php /* The subtitle sits behind an info mark, as a field's hint does on the
                 account consoles: the panes below get its line. The button is beside
                 the h1, not in it, or the heading would be read as "Storage folder
                 About this page". */ ?>
        <div class="set-head storage-head">
            <div class="storage-head-row">
                <h1>Storage folder</h1>
                <button type="button" class="storage-info" id="storageInfoBtn" aria-label="About this page"
                        aria-expanded="false" aria-describedby="storageInfo">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                </button>
                <button type="button" class="storage-export-open" id="storageExportOpen"
                        aria-haspopup="dialog" aria-controls="storageExportDialog">
                    <span class="material-symbols-outlined" aria-hidden="true">download</span>
                    Export PDF lists
                </button>
            </div>
            <p class="storage-info-tip" id="storageInfo" role="tooltip">
                Manage the Google connection, the storage folder and the renewal reminders, and
                find the PDFs kept in the current Google account and in earlier ones.
            </p>
        </div>

        <?php if (in_array($storageReminder['class'], ['is-soon', 'is-past'], true)
            || in_array($cloudReminder['class'], ['is-soon', 'is-past'], true)): ?>
            <div class="set-reminder-banner">
                <strong>Google service reminder:</strong>
                Storage plan — <?= e($storageReminder['text']) ?>;
                Cloud billing — <?= e($cloudReminder['text']) ?>.
                <a href="#continuityReminders">Check or update the dates</a>.
            </div>
        <?php endif; ?>

        <div class="storage-layout" id="storageLayout">
            <section class="storage-pane storage-settings" aria-label="Storage settings">
                <div class="storage-tabs" role="tablist" aria-label="Storage settings sections">
                    <button type="button" class="storage-tab" role="tab" id="storage-tab-connection" aria-controls="storage-panel-connection" aria-selected="true" tabindex="0">Connection</button>
                    <button type="button" class="storage-tab" role="tab" id="storage-tab-reminders" aria-controls="storage-panel-reminders" aria-selected="false" tabindex="-1">Reminders</button>
                    <button type="button" class="storage-tab" role="tab" id="storage-tab-access" aria-controls="storage-panel-access" aria-selected="false" tabindex="-1">Folder access</button>
                    <button type="button" class="storage-tab" role="tab" id="storage-tab-history" aria-controls="storage-panel-history" aria-selected="false" tabindex="-1">History</button>
                    <button type="button" class="storage-tab" role="tab" id="storage-tab-help" aria-controls="storage-panel-help" aria-selected="false" tabindex="-1">Help</button>
                </div>
                <div class="storage-pane-body" id="storageSettingsBody">
                    <div role="tabpanel" id="storage-panel-connection" aria-labelledby="storage-tab-connection" tabindex="0">
        <div class="set-card">
            <h2>Connection</h2>
            <div class="set-status <?= $connected ? '' : 'is-unset' ?>">
                <span class="set-status-ico">
                    <span class="material-symbols-outlined"><?= $connected ? 'cloud_done' : 'cloud_off' ?></span>
                </span>
                <div>
                    <?php if ($connected): ?>
                        <strong>Google Drive is connected.</strong>
                        <?php if ($liveAccount['verified']): ?>
                            <p class="set-status-note">Google account PAPEL is using now: <strong><?= e($liveAccount['email']) ?></strong> (checked with Google).</p>
                        <?php elseif ($recordedAccount['email'] !== ''): ?>
                            <p class="set-status-note">Last recorded Gmail: <strong><?= e($recordedAccount['email']) ?></strong>. Google could not confirm the current account just now.</p>
                        <?php else: ?>
                            <p class="set-status-note">Google could not confirm the connected Gmail just now. Reconnect and check the account shown here.</p>
                        <?php endif; ?>
                        <p class="set-status-note">This is PAPEL's saved Google connection, which may differ from the Gmail open in your browser.</p>
                        <p class="set-status-note">
                            <?php if ($folderBad): ?>
                                The connection works, but <strong>the storage folder below cannot be used</strong>,
                                so papers still cannot be submitted.
                            <?php elseif ($folderCheck && $folderCheck['state'] === 'ok'): ?>
                                The connected account can add files to the selected folder. Check an older PDF separately to confirm that it can still be opened.
                            <?php else: ?>
                                The connection works, but Google could not confirm the storage folder just now.
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <strong>Google Drive is not connected.</strong>
                        <p class="set-status-note">
                            Google is refusing the saved authorisation, so <strong>no paper can be submitted
                            right now</strong>: uploads fail at the last step. Reconnecting fixes it; nothing
                            already in Drive is affected.
                        </p>
                    <?php endif; ?>
                    <p style="margin:.75rem 0 0;">
                        <a class="<?= $connected ? 'btn-sm-outline' : 'btn-sm-maroon' ?>" href="<?= e($authUrl) ?>">
                            <?= $connected ? 'Reconnect' : 'Connect Google Drive' ?>
                        </a>
                    </p>
                    <p class="set-status-note" style="margin-top:.5rem;">
                        You will be sent to Google to choose an account that can add files to the storage folder,
                        then brought back here. If you choose another account, check that it can also open older PDFs.
                    </p>
                </div>
            </div>
        </div>
        <div class="set-card">
            <h2>Storage folder in use</h2>
            <div class="set-status <?= ($current && !$folderBad) ? '' : 'is-unset' ?>">
                <span class="set-status-ico">
                    <span class="material-symbols-outlined"><?= ($current && !$folderBad) ? 'folder' : 'folder_off' ?></span>
                </span>
                <div>
                    <?php if ($current): ?>
                        <a class="set-status-id" target="_blank" rel="noopener"
                           href="https://drive.google.com/drive/folders/<?= e($current) ?>"><?= e($current) ?></a>
                        <p class="set-status-note">
                            <?php if ($meta && $meta['updated_at']): ?>
                                Set by <?= e($meta['full_name'] ?: 'an administrator') ?>
                                on <?= e(date('M j, Y', strtotime($meta['updated_at']))) ?>.
                            <?php endif; ?>
                            <?= $stored ?> paper<?= $stored === 1 ? '' : 's' ?> filed in Drive so far.
                        </p>
                        <?php if ($folderCheck && $folderCheck['state'] === 'ok'): ?>
                            <p class="set-status-note"><strong>Reachable:</strong> "<?= e($folderCheck['name']) ?>".
                                The connected account can add files to it.</p>
                        <?php elseif ($folderBad): ?>
                            <p class="set-status-note"><strong>This folder cannot be used.</strong>
                                <?= e(gdrive_folder_explain($folderCheck)) ?>
                                <strong>No paper can be submitted until a usable folder is saved below.</strong></p>
                        <?php elseif ($folderCheck): ?>
                            <p class="set-status-note"><?= e(gdrive_folder_explain($folderCheck)) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <strong>No folder set.</strong>
                        <p class="set-status-note">
                            Uploads land in the root of the connected Google account, where they are
                            harder to find and easy to move by accident.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php /* Where a returned paper's PDFs go. Nothing empties it: somebody
                 opens it in Drive and clears it, which is the whole point of it
                 being a folder rather than a deletion. */ ?>
        <div class="set-card">
            <h2>Removed PDFs</h2>
            <div class="set-status <?= $trash['count'] > 0 ? '' : 'is-unset' ?>">
                <span class="set-status-ico"><span class="material-symbols-outlined">delete_sweep</span></span>
                <div>
                    <?php if ($trash['folder_id'] !== ''): ?>
                        <strong><?= $trash['capped'] ? 'More than ' . $trash['count'] : $trash['count'] ?>
                            PDF<?= $trash['count'] === 1 && !$trash['capped'] ? '' : 's' ?> waiting in Trash.</strong>
                        <p class="set-status-note">
                            When a paper is returned to its student, or the student files it again, its old
                            PDFs are moved to a <strong>Trash</strong> folder inside the storage folder rather
                            than deleted. Nothing clears that folder by itself, so open it in Drive and empty
                            it when you are sure none of it is wanted.
                        </p>
                        <p class="set-status-note">
                            <a class="set-status-id" target="_blank" rel="noopener noreferrer"
                               href="https://drive.google.com/drive/folders/<?= e(rawurlencode($trash['folder_id'])) ?>">Open the Trash folder in Drive</a>
                        </p>
                    <?php elseif (!$connected): ?>
                        <strong>Connect Google Drive to see this.</strong>
                        <p class="set-status-note">Returned papers' PDFs are moved to a Trash folder inside the
                            storage folder rather than deleted.</p>
                    <?php else: ?>
                        <strong>No Trash folder yet.</strong>
                        <p class="set-status-note">
                            One is made inside the storage folder the first time a paper is returned, and the
                            PDFs it replaced are moved there rather than deleted. A storage folder has to be
                            saved and usable first.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="set-card">
            <h2>Change the folder</h2>
            <form method="post" action="<?= e($SELF) ?>" id="folderForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_gdrive_folder">
                <label class="set-field">
                    <span>Folder link or ID</span>
                    <input type="text" name="gdrive_folder_id" id="folderInput" maxlength="500"
                           value="<?= e($current) ?>"
                           placeholder="https://drive.google.com/drive/folders/… or the bare ID">
                </label>
                <div class="set-actions">
                    <button type="submit" class="btn-sm-maroon">Save folder</button>
                    <span class="set-hint">Paste the whole sharing URL if you like. The ID is read out of it.</span>
                </div>
            </form>
        </div>
                    </div>
                    <div role="tabpanel" id="storage-panel-reminders" aria-labelledby="storage-tab-reminders" tabindex="0" hidden>
        <div class="set-card" id="continuityReminders">
            <h2>Google service reminders</h2>
            <p>Record the next date you need to check or renew each service. PAPEL cannot read subscription or billing dates from the Drive connection. These dates are reminders entered by staff; they do not renew a plan or confirm that payment succeeded.</p>
            <div class="set-reminders">
                <div class="set-reminder <?= e($storageReminder['class']) ?>">
                    <strong>Drive or Workspace storage plan</strong>
                    <span><?= $continuityDates['gdrive_storage_renewal_date'] && gdrive_valid_continuity_date($continuityDates['gdrive_storage_renewal_date']) ? e(date('M j, Y', strtotime($continuityDates['gdrive_storage_renewal_date']))) . ' · ' : '' ?><?= e($storageReminder['text']) ?></span>
                </div>
                <div class="set-reminder <?= e($cloudReminder['class']) ?>">
                    <strong>Google Cloud billing review</strong>
                    <span><?= $continuityDates['gcloud_billing_review_date'] && gdrive_valid_continuity_date($continuityDates['gcloud_billing_review_date']) ? e(date('M j, Y', strtotime($continuityDates['gcloud_billing_review_date']))) . ' · ' : '' ?><?= e($cloudReminder['text']) ?></span>
                </div>
            </div>
            <form method="post" action="<?= e($SELF) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_gdrive_continuity">
                <div class="set-date-fields">
                    <label class="set-field"><span>Storage plan renewal or check date</span>
                        <input type="date" name="gdrive_storage_renewal_date" value="<?= e($continuityDates['gdrive_storage_renewal_date']) ?>"></label>
                    <label class="set-field"><span>Cloud billing review date</span>
                        <input type="date" name="gcloud_billing_review_date" value="<?= e($continuityDates['gcloud_billing_review_date']) ?>"></label>
                </div>
                <div class="set-actions"><button type="submit" class="btn-sm-maroon">Save reminder dates</button>
                    <span class="set-hint">Leave a date empty if the service has no fixed renewal date.</span></div>
            </form>
        </div>
                    </div>
                    <div role="tabpanel" id="storage-panel-access" aria-labelledby="storage-tab-access" tabindex="0" hidden>
        <div class="set-card" id="folderAccess">
            <h2>Who can access this folder</h2>
            <p>This is the sharing list Google reports for the selected folder right now. It may include people, groups, a whole domain, or anyone with the link.</p>
            <?php if (!$connected): ?>
                <p>Connect Google Drive to check the folder's sharing list.</p>
            <?php elseif (!$current): ?>
                <p>Set a storage folder first.</p>
            <?php elseif ($folderAccess['state'] !== 'ok'): ?>
                <p>Google could not show the sharing list. Open the folder in Google Drive and choose <strong>Share</strong> to check it there.</p>
            <?php elseif (!$folderAccess['permissions']): ?>
                <p>Google returned no visible sharing entries. Check the folder's <strong>Share</strong> panel in Google Drive.</p>
            <?php else: ?>
                <?php if ($folderWideAccess): ?><p><strong>Broad access is listed below.</strong> Check whether domain or link sharing is appropriate for unpublished papers.</p><?php endif; ?>
                <div class="set-history-scroll">
                    <table class="set-history">
                        <thead><tr><th scope="col">Account or audience</th><th scope="col">Type</th><th scope="col">Access</th></tr></thead>
                        <tbody>
                        <?php foreach ($folderAccess['permissions'] as $permission): ?>
                            <tr>
                                <td>
                                    <?php if ($permission['type'] === 'anyone'): ?>
                                        <?= $permission['discoverable'] ? 'Anyone on the web' : 'Anyone with the link' ?>
                                    <?php elseif ($permission['type'] === 'domain'): ?>
                                        <?= e($permission['domain'] ?: 'A Google Workspace domain') ?>
                                    <?php else: ?>
                                        <?= e($permission['email'] ?: ($permission['name'] ?: 'Email not shown by Google')) ?>
                                        <?php if ($permission['name'] !== '' && $permission['email'] !== ''): ?><small><?= e($permission['name']) ?></small><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(ucfirst($permission['type'])) ?></td>
                                <td><?= e($accessRoleNames[$permission['role']] ?? ucfirst($permission['role'])) ?>
                                    <?php if ($permission['expires'] !== ''): ?><small>Until <?= e(date('M j, Y', strtotime($permission['expires']))) ?></small><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($folderAccess['truncated']): ?><p>Google returned more permissions than this page can show. Check the full list in Drive.</p><?php endif; ?>
                <p>Group members and people covered by domain sharing are not listed one by one. Individual PDFs can also have separate sharing permissions. Google Cloud Console project access is separate from this Drive folder list.</p>
            <?php endif; ?>
        </div>
                    </div>
                    <div role="tabpanel" id="storage-panel-history" aria-labelledby="storage-tab-history" tabindex="0" hidden>
        <div class="set-card" id="storageHistory">
            <h2>Connection and folder history</h2>
            <p>Each row records a Google Drive connection or a change to the storage folder. The folder link shows where new uploads were set to go at that time. Papers already uploaded stay in their original folder. Changes made before this history was added are not included.</p>
            <?php if (!$historyReady): ?>
                <p><strong>History is not available yet.</strong> The database could not create its history table automatically. Run <code>scripts/migrations/sql/2026-11-19_gdrive_setting_history.sql</code> in this site's phpMyAdmin database. The current connection and folder still work.</p>
            <?php elseif (!$history): ?>
                <p>No changes have been recorded yet. History starts when the first connection or folder change is made after the database update; older changes cannot be recovered.</p>
            <?php else: ?>
                <div class="set-history-scroll">
                    <table class="set-history">
                        <thead><tr><th scope="col">When (PHT)</th><th scope="col">Change</th><th scope="col">Google account</th><th scope="col">Folder at that time</th><th scope="col">Changed by</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?= e(date('M j, Y g:i A', strtotime($row['changed_at']))) ?></td>
                                <td><?= $row['event_type'] === 'connected' ? 'Connected Drive' : 'Changed folder' ?></td>
                                <td>
                                    <?= $row['account_email'] !== '' ? e($row['account_email']) : 'Account email unavailable' ?>
                                    <?php if ($row['account_name'] !== ''): ?><small><?= e($row['account_name']) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['folder_id'] !== ''): ?>
                                        <?php if ($row['folder_name'] !== ''): ?><strong><?= e($row['folder_name']) ?></strong><br><?php endif; ?>
                                        <a href="https://drive.google.com/drive/folders/<?= e($row['folder_id']) ?>" target="_blank" rel="noopener noreferrer"><?= e($row['folder_id']) ?></a>
                                    <?php else: ?>No folder set<?php endif; ?>
                                </td>
                                <td><?= e($row['staff_name'] ?: 'Former staff account') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($historyPages > 1): ?>
                    <nav class="set-history-pages" aria-label="Storage history pages">
                        <?php if ($historyPage > 1): ?><a href="?history_page=<?= $historyPage - 1 ?>#storageHistory">Newer</a><?php endif; ?>
                        <span>Page <?= $historyPage ?> of <?= $historyPages ?></span>
                        <?php if ($historyPage < $historyPages): ?><a href="?history_page=<?= $historyPage + 1 ?>#storageHistory">Older</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
                    </div>
                    <div role="tabpanel" id="storage-panel-help" aria-labelledby="storage-tab-help" tabindex="0" hidden>
        <div class="set-card">
            <h2>Finding the folder ID</h2>
            <ol>
                <li>Open <a href="https://drive.google.com" target="_blank" rel="noopener">Google Drive</a> and go to the folder you want to use.</li>
                <li>Look at the address bar. It ends with <code>/folders/<strong>THE_ID</strong></code>.</li>
                <li>Copy that last part (or the whole address) and paste it above.</li>
            </ol>
        </div>
        <div class="set-card">
            <h2>Before you change it</h2>
            <ul>
                <li><strong>Papers already uploaded stay where they are.</strong> This only redirects
                    what is uploaded from now on; nothing is moved, and no link breaks.</li>
                <li><strong>The connected Google account must be able to write to the folder</strong>:
                    it should own the folder, or have been given edit access to it.</li>
                <li><strong>Keep the folder private.</strong> Papers sit there before they are approved,
                    and a folder shared with "anyone with the link" would put unpublished work in reach.</li>
            </ul>
        </div>
                    </div>
                </div>
            </section>
            <div class="storage-grip" id="storageGrip" role="separator" aria-label="Resize storage panels" aria-orientation="vertical" aria-controls="storageSettingsBody" aria-valuemin="25" aria-valuemax="65" aria-valuenow="40" tabindex="0"></div>
            <section class="storage-pane storage-pdfs" aria-labelledby="storagePdfTitle">
                <?php /* Expand fills the window under the navbar, not the monitor: the
                         browser's own full screen would take the navbar and the tabs
                         away, and nothing here needs the screen's last few pixels. */ ?>
                <div class="storage-pane-title">
                    <h2 id="storagePdfTitle"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span> PDFs by Google account</h2>
                    <button type="button" class="storage-expand" id="storagePdfExpand" aria-pressed="false"
                            aria-label="Expand the PDF lists to fill the page" title="Expand to fill the page">
                        <span class="material-symbols-outlined" aria-hidden="true">open_in_full</span>
                    </button>
                </div>
                <div class="storage-pane-body">
                    <p class="storage-hint">See PDFs owned by the current account and by previous accounts. Each account has its own list. Open PDF uses the Google account signed in to your browser.</p>
                    <div class="storage-tools" aria-label="Choose PDF accounts">
                        <button type="button" class="storage-filter" data-filter="all" aria-pressed="true">All accounts</button>
                        <button type="button" class="storage-filter" data-filter="current" aria-pressed="false">Show current account PDFs</button>
                        <button type="button" class="storage-filter" data-filter="previous" aria-pressed="false">Show previous / other account PDFs</button>
                    </div>
                    <label class="storage-search"><span>Find a paper, PDF or Google account</span><input type="search" id="storagePdfSearch" placeholder="Search these lists" autocomplete="off"></label>
                    <div class="storage-tools">
                        <button type="button" class="btn-sm-outline" id="checkPdfAccounts" <?= !$inventoryReady || !$liveAccount['verified'] ? 'disabled' : '' ?>>Check PDF accounts</button>
                        <button type="button" class="btn-sm-outline" id="stopPdfCheck" hidden>Stop checking</button>
                        <span class="storage-check-status" id="pdfCheckStatus" role="status" aria-live="polite"></span>
                    </div>
                    <?php if (!$inventoryReady): ?>
                        <p class="storage-hint">The PDF account table could not be created. Run <code>scripts/migrations/sql/2026-11-19_gdrive_file_inventory.sql</code> in this site's phpMyAdmin database, then reload.</p>
                    <?php elseif (!$liveAccount['verified'] && $connected): ?>
                        <p class="storage-hint">Google could not confirm the connected account just now, so PDF access cannot be checked. Reload the page to try again. Saved ownership information is shown below.</p>
                    <?php elseif (!$liveAccount['verified']): ?>
                        <p class="storage-hint">Connect Google Drive to identify the current account and check PDF access. Saved ownership information is shown below.</p>
                    <?php endif; ?>
                    <p class="storage-hint">Ownership and access reflect the last check. Checking reads file details; it does not move PDFs or change sharing.</p>
                    <div id="storagePdfGroups" data-token="<?= e(csrf_token()) ?>" data-auto-check="<?= $inventoryReady && $liveAccount['verified'] ? '1' : '0' ?>">
                        <?php require ROOT_PATH . '/includes/storage_pdf_lists.php'; ?>
                    </div>
                </div>
            </section>
        </div>

    </div>
</main>

<!-- The change is confirmed first, in the site's own dialog: it decides where
     other people's files are written, and a stray click should not do that. -->
<div class="papel-dialog-backdrop" id="confirmDialog" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
  <div class="papel-dialog">
    <div class="papel-dialog-head">
      <span class="material-symbols-outlined">folder</span>
      <h2 id="confirmTitle">Change the storage folder?</h2>
    </div>
    <div class="papel-dialog-body">
      New uploads will be written to <strong id="confirmTarget">this folder</strong>.
      Papers already in Drive stay where they are.
    </div>
    <div class="papel-dialog-foot">
      <button type="button" class="btn-sm-outline" id="confirmNo">Cancel</button>
      <button type="button" class="btn-sm-maroon" id="confirmYes">Save folder</button>
    </div>
  </div>
</div>

<?php /* Formats as radios, not a <select>: select_skin.php draws a select's menu
         in <body>, which a top-layer <dialog> would cover. The download goes to a
         hidden frame, so the page does not start a navigation for it. */ ?>
<dialog class="storage-export" id="storageExportDialog" aria-labelledby="storageExportTitle">
    <div class="storage-export-head">
        <h2 id="storageExportTitle">Export PDF lists</h2>
        <button type="button" class="storage-export-close" data-export-close aria-label="Close export options">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
    </div>
    <form action="<?= e(BASE_URL) ?>/app/admin/storage_pdfs_export.php" method="post"
          target="storageExportDownload" data-no-loading data-no-pill id="storageExportForm">
        <?= csrf_field() ?>
        <fieldset>
            <legend>Accounts</legend>
            <label><input type="checkbox" name="scopes[]" value="current" checked> Current account</label>
            <label><input type="checkbox" name="scopes[]" value="previous" checked> Previous and other accounts</label>
            <label><input type="checkbox" name="scopes[]" value="unconfirmed" checked> Shared drives and PDFs with no confirmed account</label>
        </fieldset>
        <fieldset>
            <legend>PDFs</legend>
            <label><input type="radio" name="only" value="all" checked> Every PDF</label>
            <label><input type="radio" name="only" value="attention"> Only PDFs that are not accessible (view only, not accessible or not checked)</label>
        </fieldset>
        <fieldset>
            <legend>Format</legend>
            <div class="storage-export-formats">
                <label><input type="radio" name="format" value="xlsx" checked> Excel (.xlsx)</label>
                <label><input type="radio" name="format" value="pdf"> PDF</label>
                <label><input type="radio" name="format" value="csv"> CSV</label>
                <label><input type="radio" name="format" value="docx"> Word (.docx)</label>
            </div>
        </fieldset>
        <p class="storage-export-error" id="storageExportError" role="alert" hidden>Choose at least one kind of account.</p>
        <div class="storage-export-actions">
            <button type="button" class="btn-sm-outline" data-export-close>Cancel</button>
            <button type="submit" class="btn-sm-maroon">Download</button>
        </div>
    </form>
</dialog>
<iframe name="storageExportDownload" title="PDF list export download" hidden></iframe>

<?php if ($justConnected && $connected):
    /* What the reader has just done, and whether it is enough: "connected" says the
       account is let in, not that it can write to the folder papers go into. The
       folder was checked above against the new token, so this is Google's answer. */
    $popupEmail = $liveAccount['verified'] ? $liveAccount['email'] : $recordedAccount['email'];
    $popupState = $current === '' ? 'unset' : ($folderCheck['state'] ?? 'unknown');
    $popupFix   = $folderBad || $popupState === 'unset';
    $popupOlder = false;
    foreach ($pdfInventory['groups'] as $group) {
        if (in_array($group['category'], ['previous', 'other'], true) && $group['files']) $popupOlder = true;
    }
?>
<div class="papel-dialog-backdrop storage-connected" id="connectedDialog" role="dialog" aria-modal="true"
     aria-labelledby="connectedTitle" aria-describedby="connectedBody">
  <div class="papel-dialog">
    <div class="papel-dialog-head">
      <span class="material-symbols-outlined" aria-hidden="true"><?= $folderBad ? 'warning' : 'cloud_done' ?></span>
      <h2 id="connectedTitle"><?= $folderBad ? 'Connected, but the folder cannot be used' : 'Google Drive is connected' ?></h2>
    </div>
    <div class="papel-dialog-body" id="connectedBody">
      <?php if ($popupEmail !== ''): ?>
        <p>PAPEL now uploads papers with the Google account <strong><?= e($popupEmail) ?></strong>.</p>
      <?php else: ?>
        <p>PAPEL is connected to Google Drive, but Google did not say which account was chosen.</p>
      <?php endif; ?>
      <?php if ($folderBad): ?>
        <p><?= e(gdrive_folder_explain($folderCheck)) ?></p>
        <p>No paper can be submitted until this is fixed. Share the folder with this account as an Editor,
           reconnect with the account that owns the folder, or save a folder this account can edit.</p>
      <?php elseif ($popupState === 'ok'): ?>
        <p>This account can add files to the storage folder<?= $folderCheck['name'] !== '' ? ' <strong>' . e($folderCheck['name']) . '</strong>' : '' ?>,
           so students can submit papers.</p>
      <?php elseif ($popupState === 'unset'): ?>
        <p>No storage folder is saved yet. Save one under Change the folder before students submit papers.</p>
      <?php else: ?>
        <p>Google could not be asked about the storage folder just now. Check Storage folder in use before
           students submit papers.</p>
      <?php endif; ?>
      <?php if ($popupOlder): ?>
        <p>PDFs uploaded with an earlier account stay in that account. PDFs by Google account lists them.</p>
      <?php endif; ?>
    </div>
    <div class="papel-dialog-foot">
      <?php if ($popupFix): ?>
        <button type="button" class="btn-sm-outline" data-connected-close>Close</button>
        <button type="button" class="btn-sm-maroon" data-connected-folder>Change the folder</button>
      <?php else: ?>
        <button type="button" class="btn-sm-maroon" data-connected-close>OK</button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var form   = document.getElementById('folderForm');
    var input  = document.getElementById('folderInput');
    var dlg    = document.getElementById('confirmDialog');
    var target = document.getElementById('confirmTarget');
    var yes    = document.getElementById('confirmYes');
    var no     = document.getElementById('confirmNo');

    function close() { dlg.classList.remove('open'); document.body.style.overflow = ''; }

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed === '1') return;
        e.preventDefault();
        // Show the id the way the server will read it, so what is confirmed is
        // what gets saved rather than whatever was pasted.
        var v = (input.value || '').trim();
        var m = v.match(/\/folders\/([a-zA-Z0-9_-]+)/) || v.match(/[?&]id=([a-zA-Z0-9_-]+)/);
        target.textContent = m ? m[1] : (v || 'this folder');
        dlg.classList.add('open');
        document.body.style.overflow = 'hidden';
        no.focus();
    });

    yes.addEventListener('click', function () {
        form.dataset.confirmed = '1';
        close();
        form.submit();
    });
    no.addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dlg.classList.contains('open')) close();
    });
});
</script>
<?php
// Same cards, same behaviour: the Director's settings page folds away too.
$CARD_COLLAPSE_SELECTOR = '.set-card';
require ROOT_PATH.'/includes/card_collapse.php';
require ROOT_PATH.'/includes/site_footer.php';
?>
</body>
</html>
