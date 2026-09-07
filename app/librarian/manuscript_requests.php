<?php
/**
 * Manuscript access requests, the Librarian's desk.
 *
 * The public repository shows a paper's record to everyone but its actual PDF
 * to staff only (see archive/view_paper.php's $can_view_file). A student who
 * wants the file itself asks here instead of it simply being open to all —
 * the Librarian grants a time-limited look at one paper, or turns it down.
 *
 * Built on the same shell as app/support_requests.php (not console_shell.php
 * / review_console.php, which are for the paper-card dashboards and don't
 * fit a plain management list) — .mgmt-wrap, .mgmt-tabs, .mgmt-table, the
 * same data-confirm dialog every other console uses.
 */
require_once '../../config/core.php';
require_role(['admin', 'super_admin', 'librarian']);
$conn = db();
$u = current_user();

const MANUSCRIPT_MIN_HOURS  = 1;
const MANUSCRIPT_MAX_HOURS  = 24;
const MANUSCRIPT_ACTIVE_CAP = 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['request_id'] ?? 0);

    if ($action === 'grant') {
        $duration = (int)($_POST['duration'] ?? 2);
        $duration = max(MANUSCRIPT_MIN_HOURS, min(MANUSCRIPT_MAX_HOURS, $duration));

        $find = $conn->prepare("SELECT * FROM manuscript_requests WHERE request_id = ? AND status = 'pending'");
        $find->bind_param('i', $reqId);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();

        if (!$row) {
            flash('error', 'That request is no longer pending.');
        } elseif (student_manuscript_active_count((int)$row['student_user_id']) >= MANUSCRIPT_ACTIVE_CAP) {
            flash('error', 'This student already has ' . MANUSCRIPT_ACTIVE_CAP
                          . ' manuscripts unlocked at once. Let one expire, or deny this request.');
        } else {
            $expires = date('Y-m-d H:i:s', strtotime("+$duration hours"));
            /* The WHERE repeats status='pending' so two librarians racing to
               act on the same row can't both succeed — whichever UPDATE lands
               second affects nothing. */
            $upd = $conn->prepare(
                "UPDATE manuscript_requests
                    SET status='granted', duration_hours=?, granted_by=?, granted_at=NOW(), expires_at=?
                  WHERE request_id = ? AND status='pending'");
            $upd->bind_param('iisi', $duration, $u['user_id'], $expires, $reqId);
            $upd->execute();
            if ($upd->affected_rows > 0) {
                create_notification((int)$row['student_user_id'], (int)$row['paper_id'], 'manuscript',
                    'Your request for manuscript access was granted, until '
                    . date('F j, Y g:i A', strtotime($expires)) . '.');
                flash('success', 'Access granted.');
            } else {
                flash('error', 'That request is no longer pending.');
            }
            $upd->close();
        }
    } elseif ($action === 'deny') {
        $find = $conn->prepare("SELECT * FROM manuscript_requests WHERE request_id = ? AND status = 'pending'");
        $find->bind_param('i', $reqId);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();

        $upd = $conn->prepare(
            "UPDATE manuscript_requests SET status='denied', denied_by=?, denied_at=NOW()
              WHERE request_id = ? AND status='pending'");
        $upd->bind_param('ii', $u['user_id'], $reqId);
        $upd->execute();

        if ($row && $upd->affected_rows > 0) {
            create_notification((int)$row['student_user_id'], (int)$row['paper_id'], 'manuscript',
                'Your request for manuscript access was denied.');
            flash('success', 'Request denied.');
        } else {
            flash('error', 'That request is no longer pending.');
        }
        $upd->close();
    } elseif ($action === 'delete') {
        /* One row or many — a single-row delete button posts the same array
           with one value in it, so there is only ever one code path to keep
           correct. No status restriction: a librarian clearing a pending or
           granted row out from under a student is exactly what "delete" means
           here, not just tidying up settled history. Deleting a still-open
           grant also revokes it immediately — student_manuscript_access()
           has nothing left to find once the row is gone. */
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['request_ids'] ?? []))));
        if ($ids) {
            $in    = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $del   = $conn->prepare("DELETE FROM manuscript_requests WHERE request_id IN ($in)");
            $del->bind_param($types, ...$ids);
            $del->execute();
            $n = $del->affected_rows;
            $del->close();
            flash($n ? 'success' : 'error',
                  $n ? $n . ' request' . ($n === 1 ? '' : 's') . ' deleted.'
                     : 'Nothing was deleted — it may already be gone.');
        } else {
            flash('error', 'Nothing was selected to delete.');
        }
    }

    header('Location: manuscript_requests.php');
    exit;
}

/** How much of a grant is left, in words — same shape as guest_time_left() in librarian_manage_guests.php. */
function manuscript_time_left(string $expiresAt): string {
    $left = strtotime($expiresAt) - time();
    if ($left <= 0) return 'Expired';
    $h = intdiv($left, 3600);
    $m = intdiv($left % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm left';
    return $m . 'm left';
}

/**
 * One tab's sort chips (oldest/newest, by whichever date that table shows)
 * and its bulk-delete bar, identical in every pane bar what they act on —
 * pulled out once rather than written three times over.
 */
function manuscript_toolbar(): void {
    ?>
    <div class="mgmt-toolbar">
        <span class="mgmt-sort js-sort">
            <span class="mgmt-chips-label">Sort</span>
            <button type="button" class="mgmt-chip is-on" data-sort="oldest">Oldest first</button>
            <button type="button" class="mgmt-chip" data-sort="newest">Newest first</button>
        </span>
        <span class="mgmt-bulk-bar" hidden>
            <span class="js-sel-count">0 selected</span>
            <form method="post" class="js-delete-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="mgmt-act is-danger"
                        data-confirm="Delete the selected requests? This cannot be undone.">
                    <span class="material-symbols-outlined mi-18">delete</span> Delete selected
                </button>
            </form>
        </span>
    </div>
    <?php
}

$paperJoin = "LEFT JOIN research_papers p ON p.paper_id = r.paper_id
              LEFT JOIN papers_archive pa ON pa.paper_id = r.paper_id
              JOIN users s ON s.user_id = r.student_user_id";
$select    = "r.*, s.full_name AS student_name, s.student_id AS student_no,
              COALESCE(p.title, pa.title) AS paper_title";

/* Each tab's own sort chips (below) re-order client-side by the same field
   this default ORDER BY uses — the date that tab's own table already shows
   (Requested / Granted / decided) — so the "Oldest first" chip that starts
   ticked always matches what the server actually sent, oldest first. */
$pending = $conn->query("SELECT $select FROM manuscript_requests r $paperJoin
                          WHERE r.status = 'pending' ORDER BY r.created_at ASC")
                ->fetch_all(MYSQLI_ASSOC);
$granted = $conn->query("SELECT $select FROM manuscript_requests r $paperJoin
                          WHERE r.status = 'granted' AND r.expires_at > NOW()
                          ORDER BY r.granted_at ASC")
                ->fetch_all(MYSQLI_ASSOC);
$history = $conn->query("SELECT $select FROM manuscript_requests r $paperJoin
                          WHERE r.status = 'denied' OR (r.status = 'granted' AND r.expires_at <= NOW())
                          ORDER BY COALESCE(r.denied_at, r.granted_at) ASC
                          LIMIT 200")
                ->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manuscript Requests · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_page.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
<style nonce="<?= csp_nonce() ?>">
/* .mgmt-sort, .mgmt-chip and .mgmt-bulk-bar's own contents (.mgmt-act) are
   already styled by manage_page.php; this only adds the row that holds them
   side by side above each tab's table. */
.mgmt-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: .5rem;
    padding: .625rem .875rem; border-bottom: 1px solid var(--border);
}
.mgmt-bulk-bar { display: flex; align-items: center; gap: .75rem; }
.mgmt-bulk-bar .js-sel-count { font-size: .75rem; color: var(--grey); }
.mgmt-bulk-bar form { display: contents; }
/* .mgmt-sort's own margin-left:auto (manage_page.php) is built for riding at
   the end of the tab row, which is not this context — left undone, it pushes
   Sort to the far right here regardless of justify-content above. */
.mgmt-toolbar .mgmt-sort { margin-left: 0; }
/* The border read as a stray box around plain text; the cream fill, the
   maroon text and the bold weight already say which one is on — the same
   reasoning .notif-tab (site_head.php) drops its own border for. */
.mgmt-toolbar .mgmt-chip,
.mgmt-toolbar .mgmt-chip:hover,
.mgmt-toolbar .mgmt-chip.is-on { border-color: transparent; }
/* The duration select sits beside three borderless buttons (Grant, Deny,
   Delete); its box was the only outlined thing in that row. Scoped to just
   this one select — .sel-btn is shared by every dropdown on the site. */
.mr-duration-select + .sel-btn,
.mr-duration-select + .sel-btn:hover,
.mr-duration-select + .sel-btn[aria-expanded="true"] { border-color: transparent; }
/* Left at their native appearance, the select-all and per-row checkboxes
   render in the browser's own default blue rather than the theme — the same
   fix .rc-check input (review_console.php) already applies elsewhere. */
.js-select-all, .js-row-check { accent-color: var(--maroon); }
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Manuscript Requests</span>
    </div>
</div>

<main class="wrap mgmt-wrap">

    <?php flash_banner(); ?>

    <div class="mgmt-head">
        <h1>Manuscript Requests</h1>
        <p>Students asking to read the actual manuscript of a published paper. Grant a
           request to unlock it for a while, or turn it down.</p>
    </div>

    <section>
        <div class="mgmt-tabs" role="tablist">
            <button type="button" class="mgmt-tab is-on" data-pane="pendingPane" role="tab">
                Pending
                <span class="count"><?= count($pending) ?></span>
            </button>
            <button type="button" class="mgmt-tab" data-pane="grantedPane" role="tab">
                Granted
                <span class="count"><?= count($granted) ?></span>
            </button>
            <button type="button" class="mgmt-tab" data-pane="historyPane" role="tab">
                History
                <span class="count"><?= count($history) ?></span>
            </button>
        </div>

        <div id="pendingPane" class="js-pane">
            <div class="mgmt-panel">
                <?php manuscript_toolbar(); ?>
                <div class="mgmt-scroll">
                    <table class="mgmt-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="js-select-all" aria-label="Select all"></th>
                                <th>Who is asking</th>
                                <th>Paper</th>
                                <th>Requested</th>
                                <th>Active grants</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$pending): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="mgmt-empty">
                                        <span class="material-symbols-outlined">inbox</span>
                                        Nothing is waiting for you.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($pending as $r): ?>
                            <?php $activeCount = student_manuscript_active_count((int)$r['student_user_id']); ?>
                            <tr data-sort-time="<?= strtotime($r['created_at']) ?>">
                                <td><input type="checkbox" class="js-row-check" value="<?= (int)$r['request_id'] ?>" aria-label="Select this request"></td>
                                <td class="mgmt-name">
                                    <?= e($r['student_name']) ?>
                                    <span class="mgmt-sub"><?= e($r['student_no']) ?></span>
                                </td>
                                <td>
                                    <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$r['paper_id'] ?>" target="_blank" rel="noopener">
                                        <?= e($r['paper_title'] ?? 'Untitled paper') ?>
                                    </a>
                                </td>
                                <td class="mgmt-date"><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                                <td>
                                    <span class="mgmt-tag <?= $activeCount >= MANUSCRIPT_ACTIVE_CAP ? 'is-lapsed' : '' ?>">
                                        <?= (int)$activeCount ?> / <?= MANUSCRIPT_ACTIVE_CAP ?> active
                                    </span>
                                </td>
                                <td>
                                    <div class="mgmt-actions">
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="grant">
                                            <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                                            <select name="duration" class="mr-duration-select" required>
                                                <?php for ($h = 1; $h <= 24; $h++): ?>
                                                    <option value="<?= $h ?>" <?= $h === 2 ? 'selected' : '' ?>>
                                                        <?= $h ?> hour<?= $h === 1 ? '' : 's' ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                            <button type="submit" class="mgmt-act">Grant</button>
                                        </form>
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="deny">
                                            <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                                            <button type="submit" class="mgmt-act is-danger"
                                                    data-confirm="Deny <?= e($r['student_name']) ?>'s request for &quot;<?= e($r['paper_title'] ?? 'this paper') ?>&quot;?">
                                                Deny
                                            </button>
                                        </form>
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="request_ids[]" value="<?= (int)$r['request_id'] ?>">
                                            <button type="submit" class="mgmt-act is-danger"
                                                    data-confirm="Delete this request from <?= e($r['student_name']) ?>? This cannot be undone.">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="grantedPane" class="js-pane" hidden>
            <div class="mgmt-panel">
                <?php manuscript_toolbar(); ?>
                <div class="mgmt-scroll">
                    <table class="mgmt-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="js-select-all" aria-label="Select all"></th>
                                <th>Who asked</th>
                                <th>Paper</th>
                                <th>Granted</th>
                                <th>Expires</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$granted): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="mgmt-empty">
                                        <span class="material-symbols-outlined">lock_open</span>
                                        Nobody currently has access.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($granted as $r): ?>
                            <tr data-sort-time="<?= strtotime($r['granted_at']) ?>">
                                <td><input type="checkbox" class="js-row-check" value="<?= (int)$r['request_id'] ?>" aria-label="Select this request"></td>
                                <td class="mgmt-name">
                                    <?= e($r['student_name']) ?>
                                    <span class="mgmt-sub"><?= e($r['student_no']) ?></span>
                                </td>
                                <td>
                                    <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$r['paper_id'] ?>" target="_blank" rel="noopener">
                                        <?= e($r['paper_title'] ?? 'Untitled paper') ?>
                                    </a>
                                </td>
                                <td class="mgmt-date"><?= e(date('M j, Y g:i A', strtotime($r['granted_at']))) ?></td>
                                <td class="mgmt-id">
                                    <?= e(date('M j, Y g:i A', strtotime($r['expires_at']))) ?>
                                    <span class="mgmt-sub"><?= e(manuscript_time_left($r['expires_at'])) ?></span>
                                </td>
                                <td>
                                    <div class="mgmt-actions">
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="request_ids[]" value="<?= (int)$r['request_id'] ?>">
                                            <button type="submit" class="mgmt-act is-danger"
                                                    data-confirm="Delete this grant for <?= e($r['student_name']) ?>? Their access ends immediately, and this cannot be undone.">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="historyPane" class="js-pane" hidden>
            <div class="mgmt-panel">
                <?php manuscript_toolbar(); ?>
                <div class="mgmt-scroll">
                    <table class="mgmt-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="js-select-all" aria-label="Select all"></th>
                                <th>Who asked</th>
                                <th>Paper</th>
                                <th>Outcome</th>
                                <th>When</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$history): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="mgmt-empty">
                                        <span class="material-symbols-outlined">history</span>
                                        Nothing has been decided yet.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($history as $r): ?>
                            <?php
                            $isDenied = $r['status'] === 'denied';
                            $whenAt   = $isDenied ? $r['denied_at'] : $r['granted_at'];
                            ?>
                            <tr class="mgmt-row-off" data-sort-time="<?= strtotime($whenAt) ?>">
                                <td><input type="checkbox" class="js-row-check" value="<?= (int)$r['request_id'] ?>" aria-label="Select this request"></td>
                                <td class="mgmt-name">
                                    <?= e($r['student_name']) ?>
                                    <span class="mgmt-sub"><?= e($r['student_no']) ?></span>
                                </td>
                                <td>
                                    <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$r['paper_id'] ?>" target="_blank" rel="noopener">
                                        <?= e($r['paper_title'] ?? 'Untitled paper') ?>
                                    </a>
                                </td>
                                <td>
                                    <?= $isDenied ? 'Denied' : 'Granted, ' . (int)$r['duration_hours'] . 'h — expired' ?>
                                </td>
                                <td class="mgmt-date">
                                    <?= e(date('M j, Y g:i A', strtotime($whenAt))) ?>
                                </td>
                                <td>
                                    <div class="mgmt-actions">
                                        <form method="post">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="request_ids[]" value="<?= (int)$r['request_id'] ?>">
                                            <button type="submit" class="mgmt-act is-danger"
                                                    data-confirm="Delete this record for <?= e($r['student_name']) ?>? This cannot be undone.">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

</main>

<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.mgmt-tab');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                t.classList.toggle('is-on', t === tab);
                document.getElementById(t.dataset.pane).hidden = (t !== tab);
            });
        });
    });

    /* Each pane sorts and selects on its own — "oldest" means something
       different to a queue of open requests than it does to a pile of past
       decisions, and a tick in Pending has nothing to do with Granted. */
    document.querySelectorAll('.js-pane').forEach(function (pane) {
        var body  = pane.querySelector('tbody');
        var chips = pane.querySelectorAll('.js-sort .mgmt-chip');
        if (body && chips.length) {
            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    chips.forEach(function (c) { c.classList.toggle('is-on', c === chip); });
                    var dir  = chip.dataset.sort === 'newest' ? -1 : 1;
                    var rows = [].slice.call(body.querySelectorAll('tr[data-sort-time]'));
                    rows.sort(function (a, b) {
                        return dir * ((+a.dataset.sortTime) - (+b.dataset.sortTime));
                    }).forEach(function (row) { body.appendChild(row); });
                });
            });
        }

        /* The hidden request_ids[] fields are rebuilt on every checkbox
           change rather than gathered at submit time: a confirmed dialog in
           action_dialogs.php submits the form with form.submit(), which —
           unlike an actual click — never fires a submit event, so there is
           nothing left to catch that late. */
        var selectAll  = pane.querySelector('.js-select-all');
        var bar        = pane.querySelector('.mgmt-bulk-bar');
        var countEl    = bar ? bar.querySelector('.js-sel-count') : null;
        var deleteForm = bar ? bar.querySelector('.js-delete-form') : null;
        var deleteBtn  = deleteForm ? deleteForm.querySelector('button') : null;
        if (!bar || !deleteForm) return;

        function rowChecks() { return pane.querySelectorAll('.js-row-check'); }

        function syncBulk() {
            var boxes   = [].slice.call(rowChecks());
            var checked = boxes.filter(function (b) { return b.checked; });
            var n = checked.length;

            bar.hidden = n === 0;
            if (countEl) countEl.textContent = n + ' selected';
            if (deleteBtn) {
                deleteBtn.setAttribute('data-confirm',
                    'Delete ' + n + ' selected request' + (n === 1 ? '' : 's') + '? This cannot be undone.');
            }
            deleteForm.querySelectorAll('input[name="request_ids[]"]').forEach(function (i) { i.remove(); });
            checked.forEach(function (b) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'request_ids[]';
                input.value = b.value;
                deleteForm.appendChild(input);
            });
            if (selectAll) {
                selectAll.checked = n > 0 && n === boxes.length;
                selectAll.indeterminate = n > 0 && n < boxes.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks().forEach(function (b) { b.checked = selectAll.checked; });
                syncBulk();
            });
        }
        pane.addEventListener('change', function (e) {
            if (e.target.classList.contains('js-row-check')) syncBulk();
        });
        syncBulk();
    });
});
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
