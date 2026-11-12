<?php
/**
 * Manuscript access requests, the Librarian's desk.
 *
 * The public repository shows a paper's record to everyone but its actual PDF
 * to staff only (see archive/view_paper.php's $can_view_file). A student who
 * wants the file itself asks here instead of it simply being open to all:
 * the Librarian grants a time-limited look at one paper, or turns it down.
 *
 * Built on the same shell as app/support_requests.php (not console_shell.php
 * / review_console.php, which are for the paper-card dashboards and don't
 * fit a plain management list): .mgmt-wrap, .mgmt-tabs, .mgmt-table, the
 * same data-confirm dialog every other console uses.
 */
require_once '../../config/core.php';
require_role(['admin', 'super_admin', 'librarian']);
$conn = db();
$u = current_user();

const MANUSCRIPT_MIN_HOURS  = 1;
const MANUSCRIPT_MAX_HOURS  = 24;
const MANUSCRIPT_ACTIVE_CAP = 3;

/* A copy of the site whose database has no table for requests yet (see
   manuscript_requests_ready()): the page opens empty, with a notice, instead of
   ending in the error page on its first query. */
$requestsReady = manuscript_requests_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$requestsReady) {
    csrf_verify();
    flash('error', 'There is nowhere to keep requests on this site yet. See Upload Diagnostics, under Database.');
    header('Location: manuscript_requests.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['request_id'] ?? 0);

    if ($action === 'grant') {
        $duration = (int)($_POST['duration'] ?? 2);
        $duration = max(MANUSCRIPT_MIN_HOURS, min(MANUSCRIPT_MAX_HOURS, $duration));

        /* One row or many, the way revoke below takes them: the row's own
           Grant posts a single id in the same array, so there is one path to
           keep correct rather than two that can drift apart. request_id, the
           singular name the row used to post, is still read, for a page a
           librarian had open before this changed. */
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['request_ids'] ?? [])))));
        if (!$ids && (int)($_POST['request_id'] ?? 0) > 0) { $ids = [(int)$_POST['request_id']]; }

        if (!$ids) {
            flash('error', 'Nothing was selected to grant.');
        } else {
            $granted = 0;   // actually unlocked
            $capped  = 0;   // left pending: the student is already at the cap
            $gone    = 0;   // someone else answered it first

            foreach ($ids as $reqId) {
                $find = $conn->prepare("SELECT * FROM manuscript_requests WHERE request_id = ? AND status = 'pending'");
                $find->bind_param('i', $reqId);
                $find->execute();
                $row = $find->get_result()->fetch_assoc();
                $find->close();

                if (!$row) { $gone++; continue; }

                /* Counted per request rather than once for the batch, and
                   from the database rather than from a tally held here: a
                   grant made earlier in this very loop is already in the
                   count, so selecting four papers for one student who has
                   none open unlocks three and leaves the fourth pending,
                   which is the cap doing its job. */
                if (student_manuscript_active_count((int)$row['student_user_id']) >= MANUSCRIPT_ACTIVE_CAP) {
                    $capped++;
                    continue;
                }

                $expires = date('Y-m-d H:i:s', strtotime("+$duration hours"));
                /* The WHERE repeats status='pending' so two librarians racing to
                   act on the same row can't both succeed: whichever UPDATE lands
                   second affects nothing. */
                $upd = $conn->prepare(
                    "UPDATE manuscript_requests
                        SET status='granted', duration_hours=?, granted_by=?, granted_at=NOW(), expires_at=?
                      WHERE request_id = ? AND status='pending'");
                $upd->bind_param('iisi', $duration, $u['user_id'], $expires, $reqId);
                $upd->execute();
                if ($upd->affected_rows > 0) {
                    $granted++;
                    create_notification((int)$row['student_user_id'], (int)$row['paper_id'], 'manuscript',
                        'Your request for manuscript access was granted, until '
                        . date('F j, Y g:i A', strtotime($expires)) . '.',
                        'Your Manuscript Request Was Granted');
                } else {
                    $gone++;
                }
                $upd->close();
            }

            /* Said piece by piece, because a batch can end three ways at once
               and "Access granted." over a request that was in fact left
               pending is the kind of thing nobody checks until a student asks
               why they still cannot read the paper. */
            $say = [];
            if ($granted) {
                $say[] = $granted === 1
                    ? 'Access granted.'
                    : $granted . ' requests granted for ' . $duration . ' hour' . ($duration === 1 ? '' : 's') . '.';
            }
            if ($capped) {
                $say[] = $capped . ($capped === 1 ? ' request was' : ' requests were')
                       . ' left pending: that student already has ' . MANUSCRIPT_ACTIVE_CAP
                       . ' manuscripts unlocked at once. Let one expire, or deny it.';
            }
            if ($gone) {
                $say[] = $gone . ($gone === 1 ? ' request is' : ' requests are') . ' no longer pending.';
            }
            flash($granted ? 'success' : 'error', implode(' ', $say));
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
                'Your request for manuscript access was denied.',
                'Your Manuscript Request Was Not Granted');
            flash('success', 'Request denied.');
        } else {
            flash('error', 'That request is no longer pending.');
        }
        $upd->close();
    } elseif ($action === 'revoke' || $action === 'delete') {
        /* Revoke, not delete. Ending somebody's access and rubbing out the
           record of it are two different acts, and this desk only ever needed
           the first: the student is told their access ended, History says who
           ended it and when, and the row is still there to be read on both
           sides. A deleted row answered none of those questions, and the
           student's own My Library simply lost the request.

           Written as `denied` with `denied_at` set, which stops the access by
           the strongest check there is (student_manuscript_access() asks for
           status='granted'), and `granted_at`, already on the row, is what
           tells a revocation from a refusal: manuscript_request_state().
           A new status would mean an ENUM migration on every copy of the site,
           and an ENUM this database does not know stores an empty string in
           silence.

           `delete` is still answered, for a page a librarian had open before
           this changed; it does the same thing, which is the safe reading of
           a button that is no longer offered. */
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['request_ids'] ?? [])))));
        if (!$ids && (int)($_POST['request_id'] ?? 0) > 0) { $ids = [(int)$_POST['request_id']]; }

        if (!$ids) {
            flash('error', 'Nothing was selected to revoke.');
        } else {
            $n = 0;     // grants actually ended
            $gone = 0;  // already ended, expired, or answered by somebody else
            foreach ($ids as $rid) {
                $find = $conn->prepare("SELECT * FROM manuscript_requests WHERE request_id = ? AND status = 'granted'");
                $find->bind_param('i', $rid);
                $find->execute();
                $row = $find->get_result()->fetch_assoc();
                $find->close();
                if (!$row) { $gone++; continue; }

                /* status='granted' repeated in the WHERE, as granting does:
                   two librarians acting on one row cannot both succeed. */
                $upd = $conn->prepare(
                    "UPDATE manuscript_requests SET denied_by=?, denied_at=NOW(), status='denied'
                      WHERE request_id = ? AND status='granted'");
                $upd->bind_param('ii', $u['user_id'], $rid);
                $upd->execute();
                if ($upd->affected_rows > 0) {
                    $n++;
                    /* Said to the student, because the file stops opening the
                       moment this runs and nothing else would explain it. The
                       countdown in their corner says the same thing at once
                       (includes/manuscript_timer.php). */
                    create_notification((int)$row['student_user_id'], (int)$row['paper_id'], 'manuscript',
                        "A librarian ended your access to this manuscript before it was due to expire. You can ask again from the paper\u{2019}s page.",
                        'Your Manuscript Access Was Ended');
                } else {
                    $gone++;
                }
                $upd->close();
            }
            $say = [];
            if ($n)    { $say[] = $n === 1 ? 'Access revoked.' : $n . ' grants revoked.'; }
            if ($gone) { $say[] = $gone . ($gone === 1 ? ' was' : ' were') . ' no longer open.'; }
            flash($n ? 'success' : 'error', implode(' ', $say) ?: 'Nothing was revoked.');
        }
    }

    header('Location: manuscript_requests.php');
    exit;
}

/* How much of a grant is left, in words, is manuscript_time_left() in
   config/core.php. It was declared here too, which is a fatal redeclaration
   the moment a shared one exists, and the student's live countdown reads the
   same figure: this desk saying "14m left" while the student's own page
   counts "14m 03s left" is two answers to one question. */

/**
 * What a row answers the search box with: the three things it shows in words,
 * as one lower-cased string on the row itself.
 *
 * Folded here rather than compared column by column in script, because the
 * three tables do not hold the same columns and the paper's title is a link
 * whose textContent carries the markup's own indentation.
 */
function manuscript_row_search(array $r): string {
    return mb_strtolower(trim(($r['student_name'] ?? '') . ' '
                            . ($r['student_no'] ?? '') . ' '
                            . ($r['paper_title'] ?? 'Untitled paper')));
}

/**
 * One tab's sort chips (oldest/newest, by whichever date that table shows)
 * and its bulk bar, identical in every pane bar what they act on: pulled out
 * once rather than written three times over.
 *
 * $kind is the pane: 'pending' offers Grant selected, 'granted' offers Revoke
 * selected, and History passes nothing, because a record is not an action. A
 * pane with no bulk action draws no bar and no tick boxes at all, rather than
 * a row of boxes that add up to "3 selected" and nothing to press.
 */
function manuscript_toolbar(string $kind = ''): void {
    ?>
    <div class="mgmt-toolbar">
        <span class="mgmt-sort js-sort">
            <span class="mgmt-chips-label">Sort</span>
            <button type="button" class="mgmt-chip is-on" data-sort="oldest">Oldest first</button>
            <button type="button" class="mgmt-chip" data-sort="newest">Newest first</button>
        </span>
        <?php if ($kind !== ''): ?>
        <span class="mgmt-bulk-bar" hidden>
            <span class="js-sel-count">0 selected</span>
            <?php if ($kind === 'pending'): ?>
                <?php /* Pending only: there is nothing to grant on the Granted or
                         History tabs, and a button that can only fail is worse than
                         no button. One duration for the whole batch, since that is
                         the single thing a librarian would otherwise set row by row
                         before pressing Grant on each; the per-row select stays for
                         answering one request on its own terms. */ ?>
                <form method="post" class="js-grant-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="grant">
                    <label class="mr-bulk-label" for="bulkDuration<?= $GLOBALS['mr_toolbar_n'] = ($GLOBALS['mr_toolbar_n'] ?? 0) + 1 ?>">for</label>
                    <select name="duration" id="bulkDuration<?= $GLOBALS['mr_toolbar_n'] ?>"
                            class="mr-duration-select js-bulk-duration" required>
                        <?php for ($h = 1; $h <= 24; $h++): ?>
                            <option value="<?= $h ?>" <?= $h === 2 ? 'selected' : '' ?>>
                                <?= $h ?> hour<?= $h === 1 ? '' : 's' ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="mgmt-act"
                            data-confirm="Grant the selected requests?">
                        <span class="material-symbols-outlined mi-18">lock_open</span> Grant selected
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($kind === 'granted'): ?>
                <?php /* Granted only: there is nothing to revoke on Pending (use Deny)
                         or in History, which is the record of what already happened. */ ?>
                <form method="post" class="js-revoke-form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="revoke">
                    <button type="submit" class="mgmt-act is-danger"
                            data-confirm="Revoke the selected grants? Their access ends at once. The record stays in History.">
                        <span class="material-symbols-outlined mi-18">lock</span> Revoke selected
                    </button>
                </form>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
    <?php
}

$paperJoin = "LEFT JOIN research_papers p ON p.paper_id = r.paper_id
              LEFT JOIN papers_archive pa ON pa.paper_id = r.paper_id
              JOIN users s ON s.user_id = r.student_user_id";
$select    = "r.*, s.full_name AS student_name, s.student_id AS student_no,
              COALESCE(p.title, pa.title) AS paper_title";

/* Each tab's own sort chips (below) re-order client-side by the same field
   this default ORDER BY uses: the date that tab's own table already shows
   (Requested / Granted / decided). So the "Oldest first" chip that starts
   ticked always matches what the server actually sent, oldest first. */
$pending = $granted = $history = [];
if ($requestsReady) {
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
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manuscript Requests · <?= e(APP_NAME) ?></title>
<?php
/* The loading skeleton's shape (includes/skeleton.php). Set before the shared
   head, which is where the skeleton reads it. */
$PAGE_SKELETON = 'table';
?>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_page.php'; ?>
<?php require_once ROOT_PATH.'/includes/manage_search.php'; ?>
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
/* "2 selected  for [2 hours] Grant selected": the word is
   what makes the duration read as part of the grant rather than as a stray
   control sitting in the bar. Tokens only, and the same muted tone the count
   beside it uses. */
.mgmt-bulk-bar .mr-bulk-label { font-size: .75rem; color: var(--grey); margin-left: .25rem; }
/* The bar carries four things now rather than two, and at the width the
   toolbar actually gets they broke onto two lines each ("Grant / selected"),
   which reads as three ragged stacks rather than one row of controls. They
   are all short, fixed labels, so none of them has any business wrapping. */
.mgmt-bulk-bar .js-sel-count,
.mgmt-bulk-bar .mr-bulk-label,
.mgmt-bulk-bar .mgmt-act { white-space: nowrap; }
.mgmt-bulk-bar .js-sel-count { font-size: .75rem; color: var(--grey); }
.mgmt-bulk-bar form { display: contents; }
/* .mgmt-sort's own margin-left:auto (manage_page.php) is built for riding at
   the end of the tab row, which is not this context: left undone, it pushes
   Sort to the far right here regardless of justify-content above. */
.mgmt-toolbar .mgmt-sort { margin-left: 0; }
/* The border read as a stray box around plain text; the cream fill, the
   maroon text and the bold weight already say which one is on: the same
   reasoning .notif-tab (site_head.php) drops its own border for. */
.mgmt-toolbar .mgmt-chip,
.mgmt-toolbar .mgmt-chip:hover,
.mgmt-toolbar .mgmt-chip.is-on { border-color: transparent; }
/* The duration select sits beside the borderless buttons (Grant, Deny,
   Revoke); its box was the only outlined thing in that row. Scoped to just
   this one select: .sel-btn is shared by every dropdown on the site. */
.mr-duration-select + .sel-btn,
.mr-duration-select + .sel-btn:hover,
.mr-duration-select + .sel-btn[aria-expanded="true"] { border-color: transparent; }
/* Left at their native appearance, the select-all and per-row checkboxes
   render in the browser's own default blue rather than the theme: the same
   fix .rc-check input (review_console.php) already applies elsewhere. */
.js-select-all, .js-row-check { accent-color: var(--maroon); }

/* ---- The tab row, with the search field at its end ----
   The field itself, and the count it marks, are in the shared .mgmt-* sheet
   (includes/manage_page.php); this is only the row it rides on, which this desk
   has to build for itself: the three management consoles put the field inside
   .mgmt-tabs beside their Sort chips, and this desk has no chips, so the field
   would have been the only thing in a tablist besides its tabs.

   .mgmt-tabs is wrapped rather than restyled: it keeps its own bottom border and
   the -1px that lifts the tab on view over it, so moving the rule to the wrapper
   would have left that underline floating a pixel short. The tabs sit at
   flex-end against the wrapper's rule and the negative margin still laps over it
   by the same pixel. */
.mr-tabrow {
    display: flex; align-items: flex-end; justify-content: space-between;
    gap: .75rem; flex-wrap: wrap;
    border-bottom: 1px solid var(--border);
    margin-bottom: .875rem;
}
.mr-tabrow .mgmt-tabs { border-bottom: 0; margin-bottom: 0; }
.mr-tabrow .mgmt-search { margin-bottom: .4rem; }

@media (max-width: 768px) {
    /* Two lines, with the field taking the second one whole: beside the tabs it
       squeezed them into wrapping a word at a time. */
    .mr-tabrow { align-items: stretch; }
    .mr-tabrow .mgmt-search { width: 100%; margin-bottom: .5rem; }
    .mr-tabrow .mgmt-search input { width: 100%; }
}
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
        <div class="mgmt-head-row">
            <div>
                <?php /* The pair of desks reads the same way from either side; a switcher that
                         only went one way would leave this page a dead end but for the navbar. */ ?>
                <div class="mgmt-head-title">
                    <h1>Manuscript Requests</h1>
                    <span class="mgmt-head-sep" aria-hidden="true">|</span>
                    <a class="mgmt-head-switch"
                       href="<?= e(BASE_URL) ?>/app/librarian/librarian_manage_guests.php">Library Passes</a>
                </div>
                <p>Students asking to read the actual manuscript of a published paper. Grant a
                   request to unlock it for a while, or turn it down.</p>
            </div>
            <?php /* The same action as the other desk, in the same corner: a Librarian
                     moving between the two should not find it has moved. */ ?>
            <a class="btn-sm-outline mgmt-head-action"
               href="<?= e(BASE_URL) ?>/app/student/student_upload_ai.php"
               title="Add a paper of your own. It is published straight away, with no review">
                <span class="material-symbols-outlined mi-20">upload_file</span>
                <span>Upload Paper</span>
            </a>
        </div>
    </div>

    <?php if (!$requestsReady): ?>
        <div class="mgmt-empty">
            <span class="material-symbols-outlined">database</span>
            This site's database has no table for manuscript requests yet, so students cannot ask for one.
            Upload Diagnostics, under Database, gives the statement that adds it.
        </div>
    <?php endif; ?>

    <section>
        <div class="mr-tabrow">
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
            <?php /* One field for all three tabs, not one per pane: a Librarian looking
                     for a student does not know yet whether that request is still
                     pending, granted or already decided, and the tab counts say which
                     tab to open while they type. Outside the tablist, which owns tabs
                     and nothing else. */ ?>
            <?php mgmt_search_field('Search name, ID or paper'); ?>
        </div>

        <div id="pendingPane" class="js-pane">
            <div class="mgmt-panel">
                <?php manuscript_toolbar('pending'); ?>
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
                            <tr data-sort-time="<?= strtotime($r['created_at']) ?>"
                                data-search="<?= e(manuscript_row_search($r)) ?>">
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
                                            <input type="hidden" name="request_ids[]" value="<?= (int)$r['request_id'] ?>">
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
                                        <?php /* No Delete here any more: Deny is the answer that
                                                 is recorded and that the student is told about. */ ?>
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
                <?php manuscript_toolbar('granted'); ?>
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
                            <tr data-sort-time="<?= strtotime($r['granted_at']) ?>"
                                data-search="<?= e(manuscript_row_search($r)) ?>">
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
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="request_ids[]" value="<?= (int)$r['request_id'] ?>">
                                            <button type="submit" class="mgmt-act is-danger"
                                                    data-confirm="Revoke this grant for <?= e($r['student_name']) ?>? Their access ends at once, they are told, and the record stays in History.">
                                                Revoke
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
                            <?php /* No tick boxes and no buttons: History is the record of
                                     what was done, and nothing is done to it from here. */ ?>
                            <tr>
                                <th>Who asked</th>
                                <th>Paper</th>
                                <th>Outcome</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$history): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="mgmt-empty">
                                        <span class="material-symbols-outlined">history</span>
                                        Nothing has been decided yet.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($history as $r): ?>
                            <?php
                            /* 'denied', 'revoked' or 'expired'. Revoked is a grant a
                               librarian took back: denied_at set on a row that was
                               granted first (manuscript_request_state()). Said in its
                               own words, or a revocation reads as a refusal. */
                            $state  = manuscript_request_state($r);
                            $whenAt = in_array($state, ['denied', 'revoked'], true)
                                    ? $r['denied_at'] : $r['granted_at'];
                            $outcome = $state === 'denied' ? 'Denied'
                                     : ($state === 'revoked'
                                        ? 'Granted, ' . (int)$r['duration_hours'] . 'h, revoked'
                                        : 'Granted, ' . (int)$r['duration_hours'] . 'h, expired');
                            ?>
                            <tr class="mgmt-row-off" data-sort-time="<?= strtotime($whenAt) ?>"
                                data-search="<?= e(manuscript_row_search($r)) ?>">
                                <td class="mgmt-name">
                                    <?= e($r['student_name']) ?>
                                    <span class="mgmt-sub"><?= e($r['student_no']) ?></span>
                                </td>
                                <td>
                                    <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$r['paper_id'] ?>" target="_blank" rel="noopener">
                                        <?= e($r['paper_title'] ?? 'Untitled paper') ?>
                                    </a>
                                </td>
                                <td><?= e($outcome) ?></td>
                                <td class="mgmt-date">
                                    <?= e(date('M j, Y g:i A', strtotime($whenAt))) ?>
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

    /* Each pane sorts and selects on its own: "oldest" means something
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
           action_dialogs.php submits the form with form.submit(), which,
           unlike an actual click, never fires a submit event, so there is
           nothing left to catch that late. */
        var selectAll  = pane.querySelector('.js-select-all');
        var bar        = pane.querySelector('.mgmt-bulk-bar');
        var countEl    = bar ? bar.querySelector('.js-sel-count') : null;
        /* One bulk action per pane, and not the same one: Grant on Pending,
           Revoke on Granted, none at all in History, which has no bar and no
           tick boxes. Everything below that touches either form is guarded, so
           this is the pane's own bar or nothing. */
        var killForm   = bar ? bar.querySelector('.js-revoke-form') : null;
        var killBtn    = killForm ? killForm.querySelector('button') : null;
        var grantForm  = bar ? bar.querySelector('.js-grant-form') : null;
        var grantBtn   = grantForm ? grantForm.querySelector('button') : null;
        var grantHours = grantForm ? grantForm.querySelector('.js-bulk-duration') : null;
        if (!bar) return;

        /* Both bulk forms carry the same ids, so they are filled from one
           place. Rebuilt on every change rather than gathered at submit time,
           for the reason above: a confirmed dialog submits with form.submit(),
           which fires no submit event. */
        function fillIds(form, boxes) {
            form.querySelectorAll('input[name="request_ids[]"]').forEach(function (i) { i.remove(); });
            boxes.forEach(function (b) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'request_ids[]';
                input.value = b.value;
                form.appendChild(input);
            });
        }

        /* Only the rows the search is showing. Select all ticking a row the
           search had taken out would have put it in the bulk action's payload,
           which acts on a row nobody on the page can see. mgmtSearch.hidden()
           answers for the search's own class as well as for `hidden`. */
        function rowChecks() {
            return [].slice.call(pane.querySelectorAll('.js-row-check'))
                     .filter(function (b) {
                         var row = b.closest('tr');
                         if (!row) { return true; }
                         return window.mgmtSearch ? !window.mgmtSearch.hidden(row) : !row.hidden;
                     });
        }

        function syncBulk() {
            var boxes   = [].slice.call(rowChecks());
            var checked = boxes.filter(function (b) { return b.checked; });
            var n = checked.length;

            bar.hidden = n === 0;
            if (countEl) countEl.textContent = n + ' selected';
            if (killForm) {
                fillIds(killForm, checked);
                if (killBtn) {
                    killBtn.setAttribute('data-confirm',
                        'Revoke ' + n + ' selected grant' + (n === 1 ? '' : 's')
                        + '? Their access ends at once. The record stays in History.');
                }
            }
            if (grantForm) {
                fillIds(grantForm, checked);
                if (grantBtn) {
                    var hrs = grantHours ? (+grantHours.value || 2) : 2;
                    /* Granting is not destructive, so the row's own Grant asks
                       nothing. A batch is worth confirming: it unlocks files
                       and writes to every one of those students, and the count
                       and the hours are exactly what is easy to get wrong. */
                    grantBtn.setAttribute('data-confirm',
                        'Grant ' + n + ' selected request' + (n === 1 ? '' : 's')
                        + ' for ' + hrs + ' hour' + (hrs === 1 ? '' : 's') + '?');
                }
            }
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
            /* So the question the dialog asks matches the hours now chosen. */
            if (e.target === grantHours) syncBulk();
        });
        syncBulk();
        pane.papelSync = syncBulk;
    });

    /* The search field is shared (includes/manage_search.php): it hides rows with
       a class of its own and says so on the document, and a tick that outlives
       the row it was on would be acted on unseen, so the bar is put right here. */
    document.addEventListener('mgmt-search', function () {
        document.querySelectorAll('.js-pane').forEach(function (pane) {
            var cleared = false;
            pane.querySelectorAll('.js-row-check').forEach(function (box) {
                var row = box.closest('tr');
                if (box.checked && row && window.mgmtSearch && window.mgmtSearch.hidden(row)) {
                    box.checked = false;
                    cleared = true;
                }
            });
            if (cleared && typeof pane.papelSync === 'function') { pane.papelSync(); }
        });
    });
});
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
