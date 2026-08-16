<?php
/**
 * Storage folder — where every uploaded paper is kept.
 *
 * This was a field tucked into a sidebar card on the Director's dashboard,
 * which gave no room to say what the setting actually does. It is one of the
 * few settings that changes where people's files physically land, so it gets a
 * page that explains itself before it asks for anything.
 */
require_once '../../config/core.php';
require_role(['super_admin']);
require_once '../../config/gdrive_config.php';

$conn = db();
$u    = current_user();
$SELF = 'gdrive_settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'update_gdrive_folder') {
        $folder_id = trim($_POST['gdrive_folder_id'] ?? '');
        if ($folder_id === '') {
            flash('error', 'Enter a folder link or ID first.');
        } elseif (update_gdrive_parent_folder_id($folder_id, $u['user_id'])) {
            flash('success', 'Storage folder updated. New uploads will go here.');
        } else {
            flash('error', 'That folder could not be saved. Check the link or ID and try again.');
        }
    }
    header("Location: $SELF");
    exit;
}

$current   = get_gdrive_parent_folder_id();
$connected = is_gdrive_connected();
$authUrl   = get_gdrive_auth_url();

// Who set it, and when — a setting this consequential should say who touched it.
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
    background: var(--white); border: 1px solid var(--border); border-radius: 12px;
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
    color: var(--dark-maroon); padding: .1rem .35rem; border-radius: 4px;
}

/* Current state, stated plainly before the control that changes it. */
.set-status { display: flex; align-items: flex-start; gap: .75rem; }
.set-status-ico {
    width: 2.25rem; height: 2.25rem; flex: 0 0 2.25rem; border-radius: 9px;
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
    width: 100%; border: 1px solid var(--border); border-radius: 8px;
    padding: .625rem .75rem; font-family: monospace; font-size: .8125rem;
    color: var(--ink); background: var(--white);
}
.set-field input:focus { outline: none; border-color: var(--maroon); }
.set-actions { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.set-actions .set-hint { font-size: .75rem; color: var(--grey); }

.set-flash { border-radius: 8px; padding: .75rem 1rem; font-size: .8125rem; margin-bottom: 1.25rem; }
.set-flash.ok  { background: #e7f6ed; color: #1b5e35; }
.set-flash.bad { background: #fdeaea; color: var(--dark-maroon); }

@media (max-width: 600px) {
    .set-wrap { padding: 1.25rem 0 2rem; }
    .set-card { padding: 1.125rem; }
}
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="super_admin_review_dashboard.php">Oversight</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Storage Folder</span>
    </div>
</div>

<main class="wrap">
    <div class="set-wrap">

        <?php if ($msg = flash('success')): ?>
            <div class="set-flash ok"><?= e($msg) ?></div>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?>
            <div class="set-flash bad"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="set-head">
            <h1>Storage folder</h1>
            <p>
                Every paper a student submits is uploaded to one folder in the university's
                Google Drive. This page says which folder that is. Reviewers open papers from
                there, and the public repository links to it — so the setting decides where the
                files live, not what anyone can see.
            </p>
        </div>

        <?php /* Connection first: a folder ID is meaningless if the account it
                 lives in cannot be reached, and this is the only place in the
                 site where the connection can be re-established. */ ?>
        <div class="set-card">
            <h2>Connection</h2>
            <div class="set-status <?= $connected ? '' : 'is-unset' ?>">
                <span class="set-status-ico">
                    <span class="material-symbols-outlined"><?= $connected ? 'cloud_done' : 'cloud_off' ?></span>
                </span>
                <div>
                    <?php if ($connected): ?>
                        <strong>Google Drive is connected.</strong>
                        <p class="set-status-note">Students can submit papers, and reviewers can open them.</p>
                    <?php else: ?>
                        <strong>Google Drive is not connected.</strong>
                        <p class="set-status-note">
                            Google is refusing the saved authorisation, so <strong>no paper can be submitted
                            right now</strong> — uploads fail at the last step. Reconnecting fixes it; nothing
                            already in Drive is affected.
                        </p>
                    <?php endif; ?>
                    <p style="margin:.75rem 0 0;">
                        <a class="<?= $connected ? 'btn-sm-outline' : 'btn-sm-maroon' ?>" href="<?= e($authUrl) ?>">
                            <?= $connected ? 'Reconnect' : 'Connect Google Drive' ?>
                        </a>
                    </p>
                    <p class="set-status-note" style="margin-top:.5rem;">
                        You will be sent to Google to sign in with the account that owns the storage folder,
                        then brought back here.
                    </p>
                </div>
            </div>
        </div>

        <div class="set-card">
            <h2>Storage folder in use</h2>
            <div class="set-status <?= $current ? '' : 'is-unset' ?>">
                <span class="set-status-ico">
                    <span class="material-symbols-outlined"><?= $current ? 'folder' : 'folder_off' ?></span>
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
                    <span class="set-hint">Paste the whole sharing URL if you like — the ID is read out of it.</span>
                </div>
            </form>
        </div>

        <div class="set-card">
            <h2>Finding the folder ID</h2>
            <ol>
                <li>Open <a href="https://drive.google.com" target="_blank" rel="noopener">Google Drive</a> and go to the folder you want to use.</li>
                <li>Look at the address bar. It ends with <code>/folders/<strong>THE_ID</strong></code>.</li>
                <li>Copy that last part — or the whole address — and paste it above.</li>
            </ol>
        </div>

        <div class="set-card">
            <h2>Before you change it</h2>
            <ul>
                <li><strong>Papers already uploaded stay where they are.</strong> This only redirects
                    what is uploaded from now on; nothing is moved, and no link breaks.</li>
                <li><strong>The connected Google account must be able to write to the folder</strong> —
                    it should own the folder, or have been given edit access to it.</li>
                <li><strong>Keep the folder private.</strong> Papers sit there before they are approved,
                    and a folder shared with "anyone with the link" would put unpublished work in reach.</li>
            </ul>
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
// Same cards, same behaviour — the Director's settings page folds away too.
$CARD_COLLAPSE_SELECTOR = '.set-card';
require ROOT_PATH.'/includes/card_collapse.php';
require ROOT_PATH.'/includes/site_footer.php';
?>
</body>
</html>
