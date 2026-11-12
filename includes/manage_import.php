<?php
/**
 * "Import from a file" on the three management consoles.
 *
 * One include for the students console, the advisers console and the Director's
 * admins console, configured by $kind. The reading and the rules live in
 * config/account_import.php; this is the screen and the POST handling.
 *
 * The shape of it:
 *
 *   1. A button beside the search field opens a drawer down the right-hand
 *      side. Everything the import owns lives in there, so the form and the
 *      roll keep the whole page and neither is displaced by it.
 *   2. The card takes the file, plus whatever the file cannot carry (a
 *      student's programme, year and section; a default position for staff).
 *   3. Preview parses it and holds the result in the session, then redirects,
 *      so a refresh does not re-upload and the back button behaves. Coming back
 *      with rows waiting, the drawer opens itself: the upload returned to this
 *      page and the answer to it is inside.
 *   4. The preview lists every row with its generated password and anything
 *      wrong with it, and nothing is created until Create is pressed.
 *   5. Create reads the rows **from the session**, not from the page, so the
 *      only thing the browser decides is which lines to include. Each row is
 *      then checked again from scratch.
 *
 * Why the session rather than hidden fields: a 300-row file would be 300 sets
 * of hidden inputs carrying names and emails through the browser and back, and
 * every one of them would have to be treated as hostile on return. Keeping the
 * parsed rows on the server means the create step trusts nothing from the page
 * except a list of line numbers.
 */

require_once __DIR__ . '/../config/account_import.php';

/* ───────────────────────────── the stash ────────────────────────────── */

function manage_import_stash(string $kind): ?array {
    start_session_once();
    return $_SESSION['papel_import'][$kind] ?? null;
}

function manage_import_set(string $kind, ?array $data): void {
    start_session_once();
    if ($data === null) { unset($_SESSION['papel_import'][$kind]); return; }
    $_SESSION['papel_import'][$kind] = $data;
}

/* ──────────────────────────── the handler ───────────────────────────── */

/**
 * Deal with the import's three actions, if this POST is one of them.
 *
 * Call it from inside the page's own POST block, after csrf_verify(), and let
 * it fall through when the action is not an import. It always ends in a
 * redirect, as the rest of these pages do, so a refresh repeats nothing.
 *
 * @param string $self  the page to come back to, e.g. 'faculty_manage_students.php'
 */
function manage_import_handle(string $kind, mysqli $conn, string $self, int $createdBy): void {
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['import_preview', 'import_create', 'import_discard'], true)) {
        return;
    }
    $back = static function () use ($self) { header('Location: ' . $self); exit; };

    if ($action === 'import_discard') {
        manage_import_set($kind, null);
        $back();
    }

    if ($action === 'import_preview') {
        try {
            /* Whatever the file cannot carry, checked here once rather than
               once per row: it belongs to the batch, not to the person. */
            $ctx = ['created_by' => $createdBy];
            if ($kind === 'student') {
                $ctx['program']       = (string)($_POST['program'] ?? '');
                $ctx['academic_year'] = trim((string)($_POST['academic_year'] ?? ''));
                $ctx['section']       = trim((string)($_POST['section'] ?? ''));

                if (!array_key_exists($ctx['program'], programs_map())) {
                    throw new UserFacingException('Choose the academic program these students belong to.');
                }
                foreach ([academic_year_problem($ctx['academic_year']), section_problem($ctx['section'])] as $why) {
                    if ($why !== null) { throw new UserFacingException($why); }
                }
            } else {
                $ctx['position'] = (string)($_POST['default_position'] ?? '');
            }

            $sheet = account_import_read($_FILES['import_file'] ?? []);
            if (!$sheet) { throw new UserFacingException('That file has nothing in it.'); }

            $parsed = account_import_rows($sheet, $kind, $ctx, $conn);
            if (!$parsed['rows']) {
                throw new UserFacingException('No rows were found in that file. Check that the names are on the first sheet, under headings like '
                    . implode(', ', account_import_kind($kind)['headers']) . '.');
            }

            manage_import_set($kind, [
                'rows'       => $parsed['rows'],
                'headerless' => $parsed['headerless'],
                'ctx'        => $ctx,
                'file'       => (string)($_FILES['import_file']['name'] ?? ''),
            ]);
        } catch (UserFacingException $e) {
            manage_import_set($kind, null);
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            manage_import_set($kind, null);
            error_log('account import preview: ' . $e->getMessage());
            flash('error', 'That file could not be read. Save it as CSV and try again.');
        }
        $back();
    }

    /* ---- create ---- */
    $stash = manage_import_stash($kind);
    if (!$stash) {
        flash('error', 'That import is no longer on file. Upload it again.');
        $back();
    }

    /* The page returns line numbers and nothing else. */
    $wanted = array_flip(array_map('intval', (array)($_POST['rows'] ?? [])));
    $rows = array_values(array_filter($stash['rows'], static fn($r) => isset($wanted[$r['line'] ?? -1])));
    if (!$rows) {
        flash('error', 'No rows were ticked, so nothing was created.');
        $back();
    }

    $notify = ($_POST['notify'] ?? '') === '1';
    $result = account_import_create($rows, $kind, $stash['ctx'], $conn, $notify);

    manage_import_set($kind, null);

    $spec = account_import_kind($kind);
    $said = $result['created'] . ' ' . ($result['created'] === 1 ? $spec['noun'] : $spec['plural']) . ' created';
    if ($notify && $result['created']) { $said .= ', credentials emailed'; }
    if ($result['skipped']) { $said .= '. ' . $result['skipped'] . ' skipped'; }
    if ($result['failed'])  { $said .= '. ' . $result['failed'] . ' failed'; }
    flash($result['created'] ? 'success' : 'error', $said . '.');

    /* The passwords, shown once. Nothing stores them, and the email is optional,
       so this is the only chance to write them down. */
    if ($result['created']) {
        flash('import_log', json_encode($result['log']));
    }
    $back();
}

/* ───────────────────────────── the card ─────────────────────────────── */

/**
 * The upload card. Goes inside the create panel's column, under the form.
 */
function manage_import_card(string $kind): void {
    $spec = account_import_kind($kind);
    $open = manage_import_stash($kind) !== null;
    ?>
    <section class="mgmt-panel import-card" aria-label="Choose a file">
        <div class="mgmt-panel-body">
            <h3 class="import-h">
                <span class="material-symbols-outlined" aria-hidden="true">upload_file</span>
                Import from a file
            </h3>
            <p class="import-lead">
                A CSV or Excel (.xlsx) list of <?= e($spec['plural']) ?>. Nothing is created until
                you have seen the rows and pressed Create.
            </p>

            <form method="post" enctype="multipart/form-data" class="import-form">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="import_preview">

                <?php if ($kind === 'student'): ?>
                    <p class="import-note">
                        The file carries the Student ID, name and email. These three are the same
                        for everybody in it, so they are chosen here:
                    </p>
                    <div class="mgmt-field">
                        <label for="imp_program">Academic program <span class="req">*</span></label>
                        <select name="program" id="imp_program" required>
                            <option value="">Choose a program</option>
                            <?php foreach (array_keys(programs_map()) as $p): ?>
                                <option value="<?= e($p) ?>"><?= e($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mgmt-field">
                        <div class="mgmt-pair">
                            <div>
                                <label for="imp_ay">Academic year <span class="req">*</span></label>
                                <input type="text" name="academic_year" id="imp_ay" list="ayList"
                                       maxlength="20" placeholder="e.g. 2026-2027" required>
                            </div>
                            <div>
                                <label for="imp_section">Section <span class="req">*</span></label>
                                <input type="text" name="section" id="imp_section" list="sectionList"
                                       maxlength="40" placeholder="e.g. 4-1" required>
                            </div>
                        </div>
                    </div>
                    <small class="mgmt-hint">The section sets how long the accounts last, so one file is one section.</small>
                <?php else: ?>
                    <div class="mgmt-field">
                        <label for="imp_position">Position <span class="req">*</span></label>
                        <select name="default_position" id="imp_position" required>
                            <?php foreach ($spec['positions'] as $p): ?>
                                <option value="<?= e($p) ?>"><?= e($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="mgmt-hint">Used for any row whose file has no Position column of its own.</small>
                    </div>
                <?php endif; ?>

                <div class="mgmt-field">
                    <label for="imp_file">File <span class="req">*</span></label>
                    <input type="file" name="import_file" id="imp_file"
                           accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    <small class="mgmt-hint">
                        Headings on the first row: <?= e(implode(', ', $spec['headers'])) ?>.
                        Up to <?= ACCOUNT_IMPORT_MAX_ROWS ?> rows. The old .xls format is not read; save as .xlsx or CSV.
                    </small>
                </div>

                <button type="submit" class="btn-sm-maroon import-go">
                    <span class="material-symbols-outlined" aria-hidden="true">table_view</span>
                    <?= $open ? 'Replace with another file' : 'Preview the file' ?>
                </button>
            </form>
        </div>
    </section>
    <?php
}

/* ──────────────────────────── the preview ───────────────────────────── */

/**
 * The parsed rows. Prints nothing when there is no import waiting, so the tab
 * shows just the upload card until a file has been read.
 */
function manage_import_preview(string $kind): void {
    $stash = manage_import_stash($kind);
    if (!$stash) { return; }

    $spec  = account_import_kind($kind);
    $rows  = $stash['rows'];
    $over  = array_filter($rows, static fn($r) => !empty($r['over']));
    $rows  = array_values(array_filter($rows, static fn($r) => empty($r['over'])));

    $ready = array_filter($rows, 'account_import_usable');
    $nReady = count($ready);
    ?>
    <section class="mgmt-panel import-preview" aria-label="Rows found in the file">
        <div class="mgmt-panel-body">
            <div class="import-sum">
                <div>
                    <h3 class="import-h">
                        <span class="material-symbols-outlined" aria-hidden="true">fact_check</span>
                        <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> in
                        <?= e($stash['file'] !== '' ? $stash['file'] : 'the file') ?>
                    </h3>
                    <p class="import-lead">
                        <strong><?= $nReady ?></strong> ready to create<?php
                        $bad = count($rows) - $nReady;
                        if ($bad) { echo ', <strong>' . $bad . '</strong> left out'; } ?>.
                        Untick anybody you do not want.
                    </p>
                </div>
                <form method="post" class="import-discard">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="import_discard">
                    <button type="submit" class="btn-sm-outline">Discard</button>
                </form>
            </div>

            <?php if ($stash['headerless']): ?>
                <p class="import-warn">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    No headings were recognised, so the columns were read in order:
                    <?= e(implode(', ', $spec['headers'])) ?>. Check the first row below is a person
                    and not a heading.
                </p>
            <?php endif; ?>
            <?php if ($over): ?>
                <p class="import-warn">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    Only the first <?= ACCOUNT_IMPORT_MAX_ROWS ?> rows were read. Split the file and import the rest after.
                </p>
            <?php endif; ?>

            <form method="post" class="import-create">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="import_create">

                <div class="import-scroll">
                    <table class="import-table">
                        <thead>
                            <tr>
                                <th class="import-tick">
                                    <input type="checkbox" id="impAll" checked
                                           aria-label="Tick or untick every row that can be created">
                                </th>
                                <th>Line</th>
                                <th><?= e($spec['id_label']) ?></th>
                                <th>Name</th>
                                <th>Email</th>
                                <?php if ($kind !== 'student'): ?><th>Position</th><?php endif; ?>
                                <th>Password</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $ok = account_import_usable($r); ?>
                            <tr class="<?= $ok ? '' : 'is-bad' ?>">
                                <td class="import-tick">
                                    <?php if ($ok): ?>
                                        <input type="checkbox" name="rows[]" value="<?= (int)$r['line'] ?>" checked
                                               class="import-row-tick"
                                               aria-label="Create <?= e($r['name']) ?>">
                                    <?php endif; ?>
                                </td>
                                <td class="import-line"><?= (int)$r['line'] ?></td>
                                <td><?= e($r['id']) ?></td>
                                <td><?= e($r['name']) ?></td>
                                <td><?= e($r['email']) ?></td>
                                <?php if ($kind !== 'student'): ?><td><?= e($r['position']) ?></td><?php endif; ?>
                                <td class="import-pw"><?= $ok ? e($r['password']) : '' ?></td>
                                <td>
                                    <?php if ($ok): ?>
                                        <span class="import-ok">ready</span>
                                    <?php elseif ($r['exists'] !== ''): ?>
                                        <span class="import-skip"><?= e($r['exists']) ?></span>
                                    <?php else: ?>
                                        <ul class="import-why">
                                            <?php foreach ($r['problems'] as $why): ?>
                                                <li><?= e($why) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="import-foot">
                    <label class="import-notify">
                        <input type="checkbox" name="notify" value="1">
                        <span>
                            Email each person their password.
                            <small>Off by default: these messages reach real inboxes and cannot be recalled.</small>
                        </span>
                    </label>

                    <button type="submit" class="btn-sm-maroon"
                            <?= $nReady ? '' : 'disabled' ?>
                            data-confirm="Create <?= $nReady ?> account<?= $nReady === 1 ? '' : 's' ?>? Their passwords are shown once, on the next screen."
                            data-confirm-yes="Create <?= $nReady ?> account<?= $nReady === 1 ? '' : 's' ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
                        Create <?= $nReady ?> account<?= $nReady === 1 ? '' : 's' ?>
                    </button>
                </div>
            </form>
        </div>
    </section>

    <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    (function () {
        /* The header tick drives the rest. Only rows that can be created have a
           box at all, so there is nothing to guard against here. */
        var all = document.getElementById('impAll');
        if (!all) { return; }
        var ticks = Array.prototype.slice.call(document.querySelectorAll('.import-row-tick'));

        all.addEventListener('change', function () {
            ticks.forEach(function (t) { t.checked = all.checked; });
        });
        ticks.forEach(function (t) {
            t.addEventListener('change', function () {
                var on = ticks.filter(function (x) { return x.checked; }).length;
                all.checked = on === ticks.length;
                all.indeterminate = on > 0 && on < ticks.length;
            });
        });
    })();
    </script>
    <?php
}

/* ------------------ the button and its drawer ----------------------- */

/**
 * The button, beside the search field at the top of the page.
 *
 * The count is the number of rows waiting in a preview, so a file already read
 * is visible without opening anything.
 */
function manage_import_button(string $kind): void {
    $stash = manage_import_stash($kind);
    $waiting = $stash ? count(array_filter($stash['rows'], static fn($r) => empty($r['over']))) : 0;
    ?>
    <button type="button" class="btn-sm-maroon import-open" id="importOpen"
            aria-haspopup="dialog" aria-controls="importDrawer" aria-expanded="false">
        <span class="material-symbols-outlined" aria-hidden="true">upload_file</span>
        Import file
        <?php if ($waiting): ?><span class="import-count"><?= $waiting ?></span><?php endif; ?>
    </button>
    <?php
}

/**
 * The drawer: everything the import owns, in a panel down the right-hand side.
 *
 * Goes last inside <main>, after the grid. It is fixed, so its place in the
 * document decides nothing but the tab order.
 *
 * It was a tab on the roll's strip before this, and a card in the grid before
 * that. The grid is two columns, so the card landed beside the form rather than
 * under it and took the roll's column.
 *
 * Two things it has to respect, both written down elsewhere in this project at
 * the cost of a build each:
 *
 *   - **Not `.modal-backdrop`.** Bootstrap generates that class and these pages
 *     load Bootstrap; the site's login slide-in used the name once and every
 *     Bootstrap modal inherited its z-index and rendered under its own veil.
 *   - **Lock the page on `<html>`, never on `<body>`.** A body given
 *     `overflow: hidden` becomes a scroll box of its own and the sticky navbar
 *     scrolls away inside it.
 *
 * It sits at 19000, under the 20000 of this page's help dialog and of
 * action_dialogs.php, so the "Create N accounts?" confirmation opens over the
 * drawer rather than under it.
 */
function manage_import_drawer(string $kind): void {
    start_session_once();
    /* Read without consuming: flash() clears what it returns, and
       manage_import_result() below is what should consume this one. The drawer
       opens itself for a result as well as for a preview, since the passwords
       are shown once and must not sit behind a closed panel. */
    $hasResult = !empty($_SESSION['flash']['import_log']);
    $waiting   = manage_import_stash($kind) !== null;
    $spec      = account_import_kind($kind);
    ?>
    <div class="import-veil" id="importVeil" hidden></div>

    <aside class="import-drawer" id="importDrawer" role="dialog" aria-modal="true"
           aria-labelledby="importDrawerTitle" hidden>
        <div class="import-drawer-head">
            <span class="material-symbols-outlined" aria-hidden="true">upload_file</span>
            <h2 id="importDrawerTitle">Import <?= e($spec['plural']) ?></h2>
            <button type="button" class="import-close" id="importClose" aria-label="Close">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>
        <div class="import-drawer-body">
            <?php manage_import_result(); ?>
            <?php manage_import_preview($kind); ?>
            <?php manage_import_card($kind); ?>
        </div>
    </aside>

    <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    (function () {
        var open   = document.getElementById('importOpen'),
            veil   = document.getElementById('importVeil'),
            drawer = document.getElementById('importDrawer'),
            close  = document.getElementById('importClose');
        if (!open || !veil || !drawer || !close) { return; }

        var root = document.documentElement, lastFocus = null;

        function show() {
            lastFocus = document.activeElement;
            veil.hidden = drawer.hidden = false;
            /* Shown first, opened on the next frame: given both in one go the
               panel is already in place when the transition is asked for, and
               it slides nowhere. */
            requestAnimationFrame(function () {
                veil.classList.add('is-open');
                drawer.classList.add('is-open');
            });
            root.classList.add('import-open');
            open.setAttribute('aria-expanded', 'true');
            close.focus();
        }

        function hide() {
            veil.classList.remove('is-open');
            drawer.classList.remove('is-open');
            root.classList.remove('import-open');
            open.setAttribute('aria-expanded', 'false');
            /* Left in the page until the slide has finished, so it does not
               vanish mid-transition. */
            window.setTimeout(function () {
                if (!drawer.classList.contains('is-open')) { veil.hidden = drawer.hidden = true; }
            }, 220);
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        }

        open.addEventListener('click', show);
        close.addEventListener('click', hide);
        veil.addEventListener('click', hide);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !drawer.hidden) { hide(); }
        });

        <?php if ($waiting || $hasResult): ?>
        /* Rows are waiting, or passwords have just been shown. Opening on load
           is the whole point: the upload came back to this page and the answer
           to it is in here. */
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', show);
        } else { show(); }
        <?php endif; ?>
    })();
    </script>
    <?php
}

/**
 * The passwords of a batch just created, shown once.
 *
 * Mirrors includes/password_once.php, which does the same for a single account:
 * nothing stores a password, and the email is optional on an import, so this is
 * the only time anybody can write them down.
 */
function manage_import_result(): void {
    $raw = flash('import_log');
    if (!$raw) { return; }
    $log = json_decode($raw, true);
    if (!is_array($log) || !$log) { return; }
    ?>
    <section class="mgmt-panel import-result" aria-label="Accounts just created">
        <div class="mgmt-panel-body">
            <h3 class="import-h">
                <span class="material-symbols-outlined" aria-hidden="true">key</span>
                What happened to each row
            </h3>
            <p class="import-lead">
                Passwords are shown here once and are stored nowhere. Copy any you need before
                leaving this page; after that, use Reset to set a new one.
            </p>
            <div class="import-scroll">
                <table class="import-table">
                    <thead><tr><th>Name</th><th>ID</th><th>Password</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $line): ?>
                        <tr class="<?= empty($line['ok']) ? 'is-bad' : '' ?>">
                            <td><?= e((string)($line['name'] ?? '')) ?></td>
                            <td><?= e((string)($line['id'] ?? '')) ?></td>
                            <td class="import-pw"><?= e((string)($line['pw'] ?? '')) ?></td>
                            <td><?= e((string)($line['what'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php
}
