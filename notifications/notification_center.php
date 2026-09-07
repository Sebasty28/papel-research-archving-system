<?php
/**
 * Everything PAPEL has told this person, in one place.
 *
 * The bell's dropdown shows the last eight; this is the whole record, with the
 * same two views (all, unread) and the same behaviour — a notification is about
 * a paper, so clicking one opens that paper in whichever page this reader is
 * allowed to see it on.
 *
 * Laid out in the site's own card shell, so it reads as part of PAPEL rather
 * than a page of its own.
 */
require_once __DIR__.'/../config/core.php';
require_login();

$conn = db();
$u    = current_user();

/* Marking everything read — or back to unread — is a change, so it is a POST
   and it is checked. */
$bulk = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bulk === 'delete_selected') {
    csrf_verify();
    /* AND user_id = ? even though every id already came from this reader's
       own list — the same defence-in-depth notifications_handler.php's own
       delete uses, so a crafted id from elsewhere still does nothing. */
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['notification_ids'] ?? []))));
    if ($ids) {
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $types  = str_repeat('i', count($ids)) . 'i';
        $params = $ids;
        $params[] = $u['user_id'];
        $del = $conn->prepare("DELETE FROM notifications WHERE notification_id IN ($in) AND user_id = ?");
        $del->bind_param($types, ...$params);
        $del->execute();
        $n = $del->affected_rows;
        $del->close();
        flash($n ? 'success' : 'error',
              $n ? $n . ' notification' . ($n === 1 ? '' : 's') . ' deleted.'
                 : 'Nothing was deleted — it may already be gone.');
    } else {
        flash('error', 'Nothing was selected to delete.');
    }
    header('Location: notification_center.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($bulk === 'mark_all_read' || $bulk === 'mark_all_unread')) {
    csrf_verify();
    $toRead = ($bulk === 'mark_all_read') ? 1 : 0;
    /* `is_read <> ?` rather than a bare update, so the row count reflects what
       actually changed. */
    $mk = $conn->prepare("UPDATE notifications SET is_read = ? WHERE user_id = ? AND is_read <> ?");
    $mk->bind_param('iii', $toRead, $u['user_id'], $toRead);
    $mk->execute();
    flash('success', $toRead
        ? 'All notifications marked as read.'
        : 'All notifications marked as unread.');
    header('Location: notification_center.php');
    exit;
}

$filter = ($_GET['show'] ?? '') === 'unread' ? 'unread' : 'all';

/* Everything is loaded and the tabs filter it in the browser, so switching
   views costs nothing and keeps your place on the page. */
$sql = "SELECT notification_id, paper_id, notification_type, message, is_read, created_at
        FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 200";
$rows = $conn->prepare($sql);
$rows->bind_param('i', $u['user_id']);
$rows->execute();
$notifs = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
$rows->close();

$cs = $conn->prepare("SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread FROM notifications WHERE user_id = ?");
$cs->bind_param('i', $u['user_id']);
$cs->execute();
$counts = $cs->get_result()->fetch_assoc();
$total  = (int)($counts['total'] ?? 0);
$unread = (int)($counts['unread'] ?? 0);
$cs->close();

/* What each kind of notification is about, said in a word. The type column is
   free text, so anything unrecognised simply gets the default. */
$kinds = [
    'submission' => ['icon' => 'inbox',        'label' => 'Submission'],
    'decline'    => ['icon' => 'undo',         'label' => 'Returned'],
    'approval'   => ['icon' => 'check_circle', 'label' => 'Approved'],
    'reminder'   => ['icon' => 'schedule',     'label' => 'Reminder'],
    'manuscript' => ['icon' => 'menu_book',    'label' => 'Manuscript'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.nc-wrap { max-width: 52rem; margin: 0 auto; padding: 1.75rem 0 3rem; }
.nc-head { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
.nc-head h1 {
    font-family: var(--font-head); font-size: 1.375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .2rem;
}
.nc-head p { font-size: .8125rem; color: var(--grey); margin: 0; }
.nc-head form { margin-left: auto; }

.nc-tabs { display: flex; gap: .375rem; margin-bottom: 1rem; }
/* No outline, and the same shape as the bell dropdown's tabs so the two lists
   of the same thing look like the same thing. An inactive tab is plain text
   rather than a white box, because with the border gone a white box on a white
   page would have been nothing at all; the active one keeps the cream pill.
   The border stays in the box at `transparent` so nothing shifts. */
.nc-tab {
    padding: .35rem .9rem; border: 1px solid transparent; border-radius: var(--r-control, 4px);
    background: none; color: var(--grey); font-size: .8125rem; text-decoration: none;
    cursor: pointer; font-family: inherit;
}
.nc-tab:hover { color: var(--maroon); }
.nc-tab.is-on { background: var(--cream); color: var(--maroon); font-weight: 500; }

.nc-list {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--r-card, 8px); overflow: hidden;
}
/* Select-all and the bulk-delete bar, above the rows themselves — same shape
   as .mgmt-toolbar (app/librarian/manuscript_requests.php), just local to
   this page since nothing else here uses .mgmt-*. */
.nc-list-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: .5rem;
    padding: .625rem 1.125rem;
    border-bottom: 1px solid var(--border);
}
.nc-select-all-label {
    display: inline-flex; align-items: center; gap: .5rem;
    font-size: .75rem; color: var(--grey); cursor: pointer;
}
.nc-bulk-bar { display: flex; align-items: center; gap: .75rem; }
.nc-bulk-bar .js-sel-count { font-size: .75rem; color: var(--grey); }
.nc-bulk-bar form { display: contents; }

/* A checkbox beside the link rather than inside it — <a> cannot properly
   nest an <input>, and keeping them siblings means selecting a row never has
   to fight the row's own click-to-open behaviour. */
.nc-row {
    display: flex; align-items: center; gap: .625rem;
    padding-left: 1.125rem;
    border-bottom: 1px solid var(--border);
    background: var(--white);
    transition: background .15s;
}
.nc-row:last-child { border-bottom: none; }
.nc-row:hover { background: var(--cream); }
.nc-row.is-unread { background: var(--cream); }
.nc-row .js-row-check,
.nc-select-all-label input { accent-color: var(--maroon); flex: 0 0 auto; }
.nc-item {
    display: flex; align-items: flex-start; gap: .75rem;
    flex: 1 1 auto; min-width: 0;
    padding: .875rem 1.125rem .875rem 0;
    text-decoration: none;
}
.nc-body { flex: 1 1 auto; min-width: 0; }
.nc-text { display: block; font-size: .8125rem; color: var(--ink); line-height: 1.55; }
.nc-row.is-unread .nc-text { font-weight: 500; }
.nc-meta { display: block; font-size: .6875rem; color: var(--grey); margin-top: .2rem; }
.nc-dot {
    width: .45rem; height: .45rem; flex: 0 0 auto; margin-top: .55rem;
    border-radius: 50%; background: var(--maroon); opacity: 0;
}
.nc-row.is-unread .nc-dot { opacity: 1; }

.nc-empty { padding: 3rem 1rem; text-align: center; color: var(--grey); font-size: .8125rem; }
.nc-empty .material-symbols-outlined { font-size: 34px; display: block; margin: 0 auto .5rem; opacity: .5; }

@media (max-width: 600px) {
    .nc-head { flex-wrap: wrap; }
    .nc-head form { margin-left: 0; width: 100%; }
}

/* No outline on this one. .btn-sm-outline is shared with two dozen buttons
   across the site, so the border is dropped here rather than from the class
   itself — transparent, not none, so the button keeps its size. */
.nc-markall { border-color: transparent; }
.nc-markall:hover { background: var(--cream); }
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e(role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Notifications</span>
    </div>
</div>

<main class="wrap">
    <div class="nc-wrap">

        <?php flash_banner(); ?>

        <div class="nc-head">
            <div>
                <h1>Notifications</h1>
                <p>
                    <span id="ncCounts"><?= number_format($total) ?> in total<?= $unread ? ', ' . number_format($unread) . ' unread' : '' ?></span>.
                    Selecting one opens the paper it is about.
                </p>
            </div>
            <?php if ($total): ?>
                <?php /* Whichever way the list can still go. With something
                         unread the offer is to read it all; once everything is
                         read the only move left is to put it back, so the
                         button says that instead of sitting there greyed out or
                         disappearing. */ ?>
                <?php $allRead = ($unread === 0); ?>
                <form method="post" id="ncBulkForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"
                           value="<?= $allRead ? 'mark_all_unread' : 'mark_all_read' ?>">
                    <button type="submit" class="btn-sm-outline nc-markall">
                        <span class="material-symbols-outlined mi-18" data-mk-icon><?= $allRead ? 'mark_email_unread' : 'done_all' ?></span>
                        <span data-mk-label><?= $allRead ? 'Mark all as unread' : 'Mark all as read' ?></span>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="nc-tabs" role="tablist">
            <button type="button" class="nc-tab <?= $filter === 'all' ? 'is-on' : '' ?>" data-filter="all" role="tab">All</button>
            <button type="button" class="nc-tab <?= $filter === 'unread' ? 'is-on' : '' ?>" data-filter="unread" role="tab">
                Unread<?= $unread ? ' (' . number_format($unread) . ')' : '' ?>
            </button>
        </div>

        <?php if (!$notifs): ?>
            <div class="nc-list">
                <div class="nc-empty">
                    <span class="material-symbols-outlined">notifications_off</span>
                    No notifications yet.
                </div>
            </div>
        <?php else: ?>
            <div class="nc-list" id="ncList">
                <div class="nc-list-toolbar">
                    <label class="nc-select-all-label">
                        <input type="checkbox" class="js-select-all" aria-label="Select all">
                        <span>Select all</span>
                    </label>
                    <span class="nc-bulk-bar" hidden>
                        <span class="js-sel-count">0 selected</span>
                        <form method="post" class="js-delete-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_selected">
                            <button type="submit" class="btn-sm-outline nc-markall"
                                    data-confirm="Delete the selected notifications? This cannot be undone.">
                                <span class="material-symbols-outlined mi-18">delete</span> Delete selected
                            </button>
                        </form>
                    </span>
                </div>
                <div class="nc-empty" id="ncNoUnread" hidden>
                    <span class="material-symbols-outlined">notifications_off</span>
                    Nothing unread.
                </div>
                <?php foreach ($notifs as $n): ?>
                    <?php
                    $kind = $kinds[$n['notification_type']] ?? ['icon' => 'notifications', 'label' => 'Notice'];
                    $href = notification_link(isset($n['paper_id']) ? (int)$n['paper_id'] : null,
                                              $u['user_role'], (string)($n['notification_type'] ?? ''));
                    /* Same split as the bell's list: the row shows the first
                       line, and an account notice keeps the rest for the
                       dialog rather than printing a password down the page. */
                    $parts = notification_parts($n);
                    ?>
                    <div class="nc-row <?= $n['is_read'] ? '' : 'is-unread' ?>">
                    <input type="checkbox" class="js-row-check" value="<?= (int)$n['notification_id'] ?>" aria-label="Select this notification">
                    <a class="nc-item"
                       href="<?= e($href) ?>" data-notif-id="<?= (int)$n['notification_id'] ?>"
                       <?php if ($parts['is_account'] && $parts['detail'] !== ''): ?>
                           data-notif-popup="<?= e($parts['summary']) ?>"
                           data-notif-detail="<?= e($parts['detail']) ?>"
                       <?php endif; ?>>
                        <span class="nc-dot" aria-hidden="true"></span>
                        <span class="nc-body">
                            <span class="nc-text"><?= e($parts['summary']) ?></span>
                            <span class="nc-meta">
                                <?= e($kind['label']) ?> ·
                                <?= e(date('M j, Y \a\t g:i A', strtotime($n['created_at']))) ?>
                            </span>
                        </span>
                    </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var handler = <?= json_encode(BASE_URL.'/notifications/notifications_handler.php') ?>;
    // The handler can delete now, so it refuses a post it cannot place.
    var ncToken = <?= json_encode(csrf_token()) ?>;
    /* Mark it read on the way out. The link is followed either way — losing
       the bookkeeping is a smaller problem than blocking the click. */
    /* Filtering, without leaving the page. */
    var list = document.getElementById('ncList');
    var none = document.getElementById('ncNoUnread');
    document.querySelectorAll('.nc-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var unreadOnly = tab.dataset.filter === 'unread';
            document.querySelectorAll('.nc-tab').forEach(function (t) {
                t.classList.toggle('is-on', t === tab);
            });
            var shown = 0;
            if (list) {
                list.querySelectorAll('.nc-row').forEach(function (i) {
                    var show = !unreadOnly || i.classList.contains('is-unread');
                    i.hidden = !show;
                    if (show) shown++;
                });
            }
            if (none) none.hidden = !(unreadOnly && shown === 0);
            // Keep the address bar honest without reloading anything.
            history.replaceState(null, '',
                unreadOnly ? 'notification_center.php?show=unread' : 'notification_center.php');
        });
    });
    // A link straight to ?show=unread should arrive already filtered.
    <?php if ($filter === 'unread'): ?>
    var startTab = document.querySelector('.nc-tab[data-filter="unread"]');
    if (startTab) startTab.click();
    <?php endif; ?>

    /* An account notice has nowhere to go — what it is about is the message
       itself — so it opens where it is, exactly as it does in the bell's list.
       Bound to every row, not only the unread ones, because reading it a second
       time should still show the detail. */
    document.querySelectorAll('.nc-item[data-notif-popup]').forEach(function (item) {
        item.addEventListener('click', function (e) {
            if (!window.papelShow) { return; }
            e.preventDefault();
            var lines = [item.getAttribute('data-notif-popup')];
            (item.getAttribute('data-notif-detail') || '').split('\n')
                .forEach(function (row) {
                    if (!row.trim()) { return; }
                    var at = row.indexOf(':');
                    lines.push(at === -1
                        ? row
                        : [row.slice(0, at).trim(), row.slice(at + 1).trim()]);
                });
            window.papelShow('Your account was updated', lines);
        });
    });

    document.querySelectorAll('.nc-row.is-unread .nc-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var body = 'action=mark_read&notification_id=' +
                       encodeURIComponent(item.getAttribute('data-notif-id')) +
                       '&_token=' + encodeURIComponent(ncToken);
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(handler,
                        new Blob([body], { type: 'application/x-www-form-urlencoded' }));
                } else {
                    fetch(handler, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body, keepalive: true
                    });
                }
            } catch (err) { /* the link still opens */ }
        });
    });

    /* Select-all (respecting whichever tab is currently shown) and the
       bulk-delete bar. The hidden notification_ids[] fields are rebuilt on
       every checkbox change rather than gathered at submit time: a confirmed
       dialog in action_dialogs.php submits the form with form.submit(),
       which — unlike an actual click — never fires a submit event, so there
       is nothing left to catch that late. */
    if (list) {
        var selectAll  = list.querySelector('.js-select-all');
        var bar        = list.querySelector('.nc-bulk-bar');
        var countEl    = bar ? bar.querySelector('.js-sel-count') : null;
        var deleteForm = bar ? bar.querySelector('.js-delete-form') : null;
        var deleteBtn  = deleteForm ? deleteForm.querySelector('button') : null;

        function rowChecks() { return list.querySelectorAll('.js-row-check'); }
        function visibleChecks() {
            return [].slice.call(rowChecks()).filter(function (b) { return !b.closest('.nc-row').hidden; });
        }

        function syncBulk() {
            var boxes   = [].slice.call(rowChecks());
            var checked = boxes.filter(function (b) { return b.checked; });
            var n = checked.length;

            if (bar) bar.hidden = n === 0;
            if (countEl) countEl.textContent = n + ' selected';
            if (deleteBtn) {
                deleteBtn.setAttribute('data-confirm',
                    'Delete ' + n + ' selected notification' + (n === 1 ? '' : 's') + '? This cannot be undone.');
            }
            if (deleteForm) {
                deleteForm.querySelectorAll('input[name="notification_ids[]"]').forEach(function (i) { i.remove(); });
                checked.forEach(function (b) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'notification_ids[]';
                    input.value = b.value;
                    deleteForm.appendChild(input);
                });
            }
            if (selectAll) {
                var visible = visibleChecks();
                var visChecked = visible.filter(function (b) { return b.checked; }).length;
                selectAll.checked = visible.length > 0 && visChecked === visible.length;
                selectAll.indeterminate = visChecked > 0 && visChecked < visible.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                visibleChecks().forEach(function (b) { b.checked = selectAll.checked; });
                syncBulk();
            });
        }
        list.addEventListener('change', function (e) {
            if (e.target.classList.contains('js-row-check')) syncBulk();
        });
        syncBulk();
    }
});
</script>
<?php require ROOT_PATH.'/includes/scroll_jump.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
