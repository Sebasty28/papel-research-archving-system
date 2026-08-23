<?php
/**
 * The "Password changes" tab shared by the three management consoles.
 *
 * A notice saying somebody changed their password is read once and gone. The
 * question it leaves behind — who else, when, and how often — needs a list, and
 * each console already has the roll it applies to:
 *
 *     Research Adviser    students
 *     Research Coordinator faculty and librarians
 *     Director            admins, the Head, and librarians
 *
 * Nothing here shows a password. The table records that a change happened and
 * when; the value itself is hashed on the account and was never stored in
 * readable form anywhere.
 *
 * Used in three places, so it is one file: the columns and the wording cannot
 * drift apart between consoles.
 *
 * Include once, then call password_audit_tab() inside the .mgmt-tabs row and
 * password_audit_pane() beside the other panes.
 */

/**
 * Who among these roles has changed their password, and how often.
 *
 * Accounts that never have are left out: the tab answers "who changed theirs",
 * and a roll of everybody with a blank against most names answers nothing.
 *
 * Scoped to the accounts this desk keeps, the same way the roll beside it is.
 * Without that an adviser's tab listed every student in the school who had ever
 * changed a password, which is both none of their business and no use to them:
 * they cannot act on an account that is not theirs.
 *
 * @param string[] $roles  user_role values this console is responsible for
 * @param int|null $ownerId only accounts this person created; null for all of
 *                          them, which is the Director's view
 * @return array<int, array> newest change first
 */
function password_audit_rows(array $roles, ?int $ownerId = null): array {
    if (!$roles) return [];
    $conn = db();

    // Built from a whitelist, never from input: the roles come from the page.
    $known = ['student', 'faculty', 'librarian', 'admin', 'head_academic', 'super_admin'];
    $safe  = array_values(array_intersect($roles, $known));
    if (!$safe) return [];
    $in = "'" . implode("','", $safe) . "'";

    $res = $conn->query(
        "SELECT u.user_id, u.full_name, u.user_role, u.admin_level, u.is_active,
                u.student_id, u.faculty_id, u.username,
                COUNT(pc.change_id) AS changes,
                MAX(pc.changed_at)  AS last_changed,
                MIN(pc.changed_at)  AS first_changed,
                /* Who did the most recent one. A correlated lookup rather than a
                   join on the grouped rows, which would need the max repeated in
                   two places and still not say which row it came from. */
                (SELECT b.full_name
                   FROM password_changes p2
                   LEFT JOIN users b ON b.user_id = p2.changed_by
                  WHERE p2.user_id = u.user_id
                  ORDER BY p2.changed_at DESC, p2.change_id DESC
                  LIMIT 1) AS last_by,
                (SELECT p3.changed_by
                   FROM password_changes p3
                  WHERE p3.user_id = u.user_id
                  ORDER BY p3.changed_at DESC, p3.change_id DESC
                  LIMIT 1) AS last_by_id
           FROM users u
           JOIN password_changes pc ON pc.user_id = u.user_id
          WHERE u.user_role IN ($in)"
        . ($ownerId ? " AND u.created_by = " . (int)$ownerId : '') . "
          GROUP BY u.user_id
          ORDER BY last_changed DESC");

    $rows = [];
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
    return $rows;
}

/** The tab itself. Goes inside the page's existing .mgmt-tabs row. */
function password_audit_tab(array $rows): void {
    ?>
    <button type="button" class="mgmt-tab" data-pane="passwordPane" role="tab">
        Password changes
        <span class="count"><?= count($rows) ?></span>
    </button>
    <?php
}

/**
 * The pane. Goes beside the console's other .js-pane blocks.
 *
 * @param array  $rows what password_audit_rows() returned
 * @param string $who  what this console calls the people on it, for the empty state
 */
function password_audit_pane(array $rows, string $who = 'accounts'): void {
    ?>
    <div id="passwordPane" class="js-pane" hidden>
        <div class="mgmt-panel">
            <div class="mgmt-scroll">
                <table class="mgmt-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Last changed</th>
                            <th>First changed</th>
                            <th>Last set by</th>
                            <th>Times changed</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="5">
                                <div class="mgmt-empty">
                                    <span class="material-symbols-outlined">lock_reset</span>
                                    No <?= e($who) ?> have changed their password yet.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        // By role, not by whichever column is filled: a staff
                        // number in student_id must not be read as a student's.
                        $ident = account_identifier($r)['value'] ?: $r['username'];
                        $last  = strtotime((string)$r['last_changed']);
                        $first = strtotime((string)$r['first_changed']);
                        ?>
                        <tr class="<?= (int)$r['is_active'] === 1 ? '' : 'mgmt-row-off' ?>">
                            <td class="mgmt-name">
                                <?= e($r['full_name']) ?>
                                <span class="mgmt-sub">
                                    <?= e(account_position($r)) ?><?= $ident ? ' · ' . e($ident) : '' ?>
                                </span>
                            </td>
                            <?php /* Date and time on separate lines: the date is what is
                                     scanned down the column, the time is only wanted once
                                     a row has been picked out. */ ?>
                            <td>
                                <?= e(date('j M Y', $last)) ?>
                                <span class="mgmt-sub"><?= e(date('g:i A', $last)) ?></span>
                            </td>
                            <td>
                                <?= e(date('j M Y', $first)) ?>
                                <span class="mgmt-sub"><?= e(date('g:i A', $first)) ?></span>
                            </td>
                            <td>
                                <?php
                                /* Rows written before the console started
                                   recording resets have nobody named, and every
                                   one of those came from Settings, so an unnamed
                                   change is read as their own. */
                                $byId = (int)($r['last_by_id'] ?? 0);
                                $self = $byId === 0 || $byId === (int)$r['user_id'];
                                ?>
                                <?= $self ? 'Themselves' : e($r['last_by'] ?: 'A removed account') ?>
                                <span class="mgmt-sub">
                                    <?= $self ? 'changed it in Settings' : 'reset it for them' ?>
                                </span>
                            </td>
                            <td>
                                <?= (int)$r['changes'] ?>
                                <span class="mgmt-sub">
                                    <?= (int)$r['changes'] === 1 ? 'once' : 'times' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    /* Opened straight from a notification, which links here with ?tab=passwords.
       The console's own tab handler does the showing; this only presses the
       button, so the two cannot disagree about what "selected" looks like. */
    (function () {
        if (new URLSearchParams(location.search).get('tab') !== 'passwords') return;
        document.addEventListener('DOMContentLoaded', function () {
            /* One tick later. The console wires its tabs in its own
               DOMContentLoaded listener, registered after this one, and
               listeners run in the order they were added: clicking here
               directly pressed a button nothing was listening to yet. A
               timeout of zero runs once every listener has finished. */
            setTimeout(function () {
                var tab = document.querySelector('.mgmt-tab[data-pane="passwordPane"]');
                if (!tab) return;
                tab.click();
                tab.scrollIntoView({ block: 'center' });
            }, 0);
        });
    })();
    </script>
    <?php
}
