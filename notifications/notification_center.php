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

/* Marking everything read is a change, so it is a POST and it is checked. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    csrf_verify();
    $mk = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $mk->bind_param('i', $u['user_id']);
    $mk->execute();
    flash('success', 'All notifications marked as read.');
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
.nc-tab {
    padding: .35rem .9rem; border: 1px solid var(--border); border-radius: 999px;
    background: var(--white); color: var(--ink); font-size: .8125rem; text-decoration: none;
}
.nc-tab:hover { border-color: var(--soft-maroon); color: var(--maroon); }
.nc-tab.is-on { background: var(--cream); border-color: var(--maroon); color: var(--maroon); font-weight: 500; }

.nc-list {
    background: var(--white); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden;
}
.nc-item {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .875rem 1.125rem;
    border-bottom: 1px solid var(--border);
    text-decoration: none; background: var(--white);
    transition: background .15s;
}
.nc-item:last-child { border-bottom: none; }
.nc-item:hover { background: var(--cream); }
.nc-item.is-unread { background: var(--cream); }
.nc-ico {
    width: 2rem; height: 2rem; flex: 0 0 2rem; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--white); border: 1px solid var(--border); color: var(--maroon);
}
.nc-ico .material-symbols-outlined { font-size: 18px; }
.nc-body { flex: 1 1 auto; min-width: 0; }
.nc-text { display: block; font-size: .8125rem; color: var(--ink); line-height: 1.55; }
.nc-item.is-unread .nc-text { font-weight: 500; }
.nc-meta { display: block; font-size: .6875rem; color: var(--grey); margin-top: .2rem; }
.nc-dot {
    width: .45rem; height: .45rem; flex: 0 0 auto; margin-top: .55rem;
    border-radius: 50%; background: var(--maroon); opacity: 0;
}
.nc-item.is-unread .nc-dot { opacity: 1; }

.nc-empty { padding: 3rem 1rem; text-align: center; color: var(--grey); font-size: .8125rem; }
.nc-empty .material-symbols-outlined { font-size: 34px; display: block; margin: 0 auto .5rem; opacity: .5; }

@media (max-width: 600px) {
    .nc-head { flex-wrap: wrap; }
    .nc-head form { margin-left: 0; width: 100%; }
}
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

        <div class="nc-head">
            <div>
                <h1>Notifications</h1>
                <p>
                    <?= number_format($total) ?> in total<?= $unread ? ', ' . number_format($unread) . ' unread' : '' ?>.
                    Selecting one opens the paper it is about.
                </p>
            </div>
            <?php if ($unread): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn-sm-outline">
                        <span class="material-symbols-outlined mi-18">done_all</span> Mark all as read
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
                <div class="nc-empty" id="ncNoUnread" hidden>
                    <span class="material-symbols-outlined">notifications_off</span>
                    Nothing unread.
                </div>
                <?php foreach ($notifs as $n): ?>
                    <?php
                    $kind = $kinds[$n['notification_type']] ?? ['icon' => 'notifications', 'label' => 'Notice'];
                    $href = notification_link(isset($n['paper_id']) ? (int)$n['paper_id'] : null, $u['user_role']);
                    ?>
                    <a class="nc-item <?= $n['is_read'] ? '' : 'is-unread' ?>"
                       href="<?= e($href) ?>" data-notif-id="<?= (int)$n['notification_id'] ?>">
                        <span class="nc-dot" aria-hidden="true"></span>
                        <span class="nc-ico"><span class="material-symbols-outlined"><?= e($kind['icon']) ?></span></span>
                        <span class="nc-body">
                            <span class="nc-text"><?= e($n['message']) ?></span>
                            <span class="nc-meta">
                                <?= e($kind['label']) ?> ·
                                <?= e(date('M j, Y \a\t g:i A', strtotime($n['created_at']))) ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var handler = <?= json_encode(BASE_URL.'/notifications/notifications_handler.php') ?>;
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
                list.querySelectorAll('.nc-item').forEach(function (i) {
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

    document.querySelectorAll('.nc-item.is-unread').forEach(function (item) {
        item.addEventListener('click', function () {
            var body = 'action=mark_read&notification_id=' +
                       encodeURIComponent(item.getAttribute('data-notif-id'));
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
});
</script>
<?php require ROOT_PATH.'/includes/scroll_jump.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
