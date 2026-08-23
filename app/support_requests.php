<?php
/**
 * Requests waiting for a desk: forgotten passwords and account corrections.
 *
 * Nothing is issued or corrected here: that happens on the roll the account sits
 * on, where the account can actually be edited. This page answers "what is
 * waiting for me", and every row is a way into the console that can act on it.
 *
 * Who sees what: a request goes to whoever created the account it is about, so
 * what you see here is the people you enrolled. The Director sees all of them,
 * being the desk of last resort when a request lands on the wrong one.
 */
require_once __DIR__ . '/../config/core.php';
require_role(['faculty', 'admin', 'super_admin', 'head_academic', 'librarian']);

$conn = db();
$u    = current_user();
$isDirector = ($u['user_role'] ?? '') === 'super_admin';

/* Marking one done. Resetting the password or saving the details clears the
   request on its own, so this is for the ones settled another way: answered in
   person, sent to the wrong desk, or asked twice. There is no "done" state to
   keep — the row goes. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['request_id'] ?? 0);

    /* Yours to clear, or anything at all if you are the Director. Without the
       second clause a request could be cleared off somebody else's desk by
       anyone who guessed the number. Read first, so the person who asked can be
       told, and so the scope is checked once rather than twice. */
    $where = "request_id = ?" . ($isDirector ? '' : ' AND handler_user_id = ?');
    $find = $conn->prepare("SELECT * FROM support_requests WHERE $where");
    if ($isDirector) $find->bind_param('i', $id);
    else             $find->bind_param('ii', $id, $u['user_id']);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();

    $gone = 0;
    if ($row) {
        support_request_settled_email($row);
        $del = $conn->prepare("DELETE FROM support_requests WHERE request_id = ?");
        $del->bind_param('i', $id);
        $del->execute();
        $gone = $del->affected_rows;
        $del->close();
    }

    flash($gone ? 'success' : 'error',
          $gone ? 'Marked as done and cleared from the list.'
                : 'That request is no longer on your list.');
    // Back to the tab they were on, rebuilt from the whitelist rather than
    // echoed from the query string.
    $back = $_GET['kind'] ?? '';
    $back = in_array($back, ['password', 'account'], true) ? '?kind=' . $back : '';
    header('Location: support_requests.php' . $back);
    exit;
}

$kinds = ['password' => 'Password reset', 'account' => 'Account correction'];
$filter = $_GET['kind'] ?? '';
if (!isset($kinds[$filter])) $filter = '';

$sql = "SELECT r.*, h.full_name AS handler_name
          FROM support_requests r
          LEFT JOIN users h ON h.user_id = r.handler_user_id
         WHERE " . ($isDirector ? '1' : 'r.handler_user_id = ?');
if ($filter !== '') $sql .= " AND r.kind = ?";
$sql .= " ORDER BY r.created_at DESC";

$stmt = $conn->prepare($sql);
if ($isDirector) {
    if ($filter !== '') $stmt->bind_param('s', $filter);
} else {
    if ($filter !== '') $stmt->bind_param('is', $u['user_id'], $filter);
    else                $stmt->bind_param('i', $u['user_id']);
}
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Counts for the tabs, taken without the kind filter so both always show.
$countSql = "SELECT kind, COUNT(*) c FROM support_requests"
          . ($isDirector ? '' : ' WHERE handler_user_id = ?') . ' GROUP BY kind';
$cs = $conn->prepare($countSql);
if (!$isDirector) $cs->bind_param('i', $u['user_id']);
$cs->execute();
$counts = ['password' => 0, 'account' => 0];
foreach ($cs->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $counts[$row['kind']] = (int)$row['c'];
}
$cs->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Support Requests · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_page.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
<style nonce="<?= csp_nonce() ?>">
/* A row is a link to the console that can act on it, so it behaves like one. */
.sr-row { cursor: pointer; }
.sr-row:hover { background: var(--cream); }
.sr-what { font-weight: 500; color: var(--ink); }
.sr-kind {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .125rem .5rem; border-radius: var(--r-badge, 2px);
    font-size: .6875rem; font-weight: 500;
    border: 1px solid var(--border); color: var(--grey);
}
.sr-kind.is-password { color: var(--maroon); border-color: var(--soft-maroon); background: var(--white); }
.sr-msg {
    display: block; margin-top: .25rem;
    font-size: .75rem; color: var(--grey); line-height: 1.6;
    /* Long enough to recognise the request, short enough that the table stays a
       table. The whole of it is in the email. */
    max-width: 46rem;
}
.sr-go { color: var(--maroon); font-size: .75rem; white-space: nowrap; }
.sr-done { text-align: right; white-space: nowrap; }
.sr-done-form { display: inline; }
/* The shared sub-line is clipped at 13rem, which suits a programme name; here it
   carries a role and an ID that both have to be read in full. */
.sr-row .mgmt-sub { max-width: none; overflow: visible; }
.sr-unknown {
    display: inline-block; margin-top: .25rem;
    padding: .0625rem .375rem; border-radius: var(--r-badge, 2px);
    background: var(--bad-bg); color: var(--bad-text);
    font-size: .6875rem; font-weight: 500;
}
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Support Requests</span>
    </div>
</div>

<main class="wrap mgmt-wrap">

    <?php flash_banner(); ?>

    <div class="mgmt-head">
        <h1>Support Requests</h1>
        <p>
            <?= $isDirector
                ? 'Every request for a new password or a correction to an account.'
                : 'People whose accounts you set up, asking for a new password or a correction. '
                . 'Open one to go to the roll it sits on.' ?>
        </p>
    </div>


    <section>
        <div class="mgmt-tabs" role="tablist">
            <a class="mgmt-tab <?= $filter === '' ? 'is-on' : '' ?>" href="support_requests.php">
                All
                <span class="count"><?= (int)($counts['password'] + $counts['account']) ?></span>
            </a>
            <a class="mgmt-tab <?= $filter === 'password' ? 'is-on' : '' ?>" href="support_requests.php?kind=password">
                Password resets
                <span class="count"><?= (int)$counts['password'] ?></span>
            </a>
            <a class="mgmt-tab <?= $filter === 'account' ? 'is-on' : '' ?>" href="support_requests.php?kind=account">
                Account corrections
                <span class="count"><?= (int)$counts['account'] ?></span>
            </a>
        </div>

        <div class="mgmt-panel">
            <div class="mgmt-scroll">
                <table class="mgmt-table">
                    <thead>
                        <tr>
                            <th>Who is asking</th>
                            <th>What for</th>
                            <?php if ($isDirector): ?><th>Addressed to</th><?php endif; ?>
                            <th>Received</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="<?= $isDirector ? 6 : 5 ?>">
                                <div class="mgmt-empty">
                                    <span class="material-symbols-outlined">inbox</span>
                                    Nothing is waiting for you.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $r): ?>
                        <?php
                        /* Where acting on it happens. The console is chosen by the
                           role of the person asking, and the account is named in the
                           query string so the roll can be searched for them. */
                        $go = support_console_for($r['requester_role'])
                            . '?q=' . rawurlencode($r['requester_ident']);
                        $when = strtotime($r['created_at']);
                        ?>
                        <tr class="sr-row" data-go="<?= e($go) ?>">
                            <td class="mgmt-name">
                                <?= e($r['requester_name']) ?>
                                <span class="mgmt-sub">
                                    <?= e(support_requester_roles()[$r['requester_role']] ?? $r['requester_role']) ?>
                                    · <?= e($r['requester_ident']) ?>
                                </span>
                                <?php if (!$r['requester_user_id']): ?>
                                    <?php /* The form now refuses a request whose ID matches no
                                             account, so this can only be a row recorded before that
                                             rule. Kept because it changes what the desk does: there
                                             is no roll to open, only a name to search for. */ ?>
                                    <span class="sr-unknown">No account with that ID</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sr-kind <?= $r['kind'] === 'password' ? 'is-password' : '' ?>">
                                    <?= e($kinds[$r['kind']] ?? $r['kind']) ?>
                                </span>
                                <span class="sr-msg"><?= e($r['message']) ?></span>
                            </td>
                            <?php if ($isDirector): ?>
                                <td>
                                    <?= e($r['handler_name'] ?: 'Account removed') ?>
                                    <span class="mgmt-sub"><?= e(support_handler_roles()[$r['handler_role']] ?? $r['handler_role']) ?></span>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?= e(date('j M Y', $when)) ?>
                                <span class="mgmt-sub"><?= e(date('g:i A', $when)) ?></span>
                            </td>
                            <td class="sr-go">Open the roll ›</td>
                            <td class="sr-done">
                                <?php /* Its own form, so the row's click-through does not
                                         swallow it; the shared dialog asks first, since a
                                         request cleared by mistake cannot be brought back. */ ?>
                                <form method="post" class="sr-done-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                                    <button type="submit" class="mgmt-act"
                                            data-confirm="Mark this request from <?= e($r['requester_name']) ?> as done? It will be removed from the list.">
                                        Done
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</main>

<script nonce="<?= csp_nonce() ?>">
/* The whole row is the target, which is a larger thing to hit than a link at
   one end of it. A middle-click or a modifier still opens a new tab. */
document.querySelectorAll('.sr-row').forEach(function (row) {
    row.addEventListener('click', function (e) {
        var go = row.dataset.go;
        if (!go) return;
        // Done is a button inside the row, and the row is a link: without this
        // the click through to the roll fires underneath the dialog.
        if (e.target.closest('.sr-done')) return;
        if (e.metaKey || e.ctrlKey || e.button === 1) window.open(go, '_blank', 'noopener');
        else window.location.href = go;
    });
});
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
