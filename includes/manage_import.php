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
                'name_from'  => $parsed['name_from'],
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

    /* The preview is editable, so the page returns the corrected values as well
       as the ticks. They are a member of staff's typing, exactly as the create
       form's boxes are, and they are checked the same way: every row goes
       through account_import_check_row() and the duplicate lookup again inside
       account_import_create(). Nothing from the page is believed about whether
       a row is fit to create; only about what it now says.

       The stash decides which of the file's lines exist, so one the file never
       had cannot be slipped in under `cell`. Rows *added* on the preview are a
       separate matter and arrive under their own names (`new_rows`,
       `new_cell`): adding one is the same act as typing a person into the
       create form beside this drawer, which whoever is on this page may already
       do. What guards it is unchanged, and is what guards every other row:
       account_import_check_row() and the duplicate lookup run again below, and
       the position must be one this console creates. */
    $wanted = array_flip(array_map('intval', (array)($_POST['rows'] ?? [])));
    $cells  = (array)($_POST['cell'] ?? []);

    /* Lines taken out of the preview. They are dropped from the stash too, or
       the screen that reports the result, which is drawn from the stash and not
       from the page, would show them again. */
    $dropped = [];
    foreach (explode(',', (string)($_POST['dropped'] ?? '')) as $gone) {
        $gone = trim($gone);
        if ($gone !== '') { $dropped[(int)$gone] = true; }
    }

    $rows = [];
    foreach ($stash['rows'] as $row) {
        $line = $row['line'] ?? -1;
        if (isset($dropped[$line])) { continue; }
        if (!isset($wanted[$line])) { continue; }
        $edit = (array)($cells[$line] ?? []);
        $row['id']       = trim((string)($edit['id']    ?? $row['id']));
        $row['name']     = normalize_person_name((string)($edit['name'] ?? $row['name']));
        $row['email']    = strtolower(trim((string)($edit['email'] ?? $row['email'])));
        $row['position'] = $kind === 'student' ? '' : trim((string)($edit['position'] ?? $row['position']));
        $rows[] = $row;
    }

    /* The rows added on the preview. Their keys are positions in the post and
       nothing else, so they are given line numbers after the file's only if
       they have to be shown again. */
    $added = [];
    $newWanted = array_flip(array_map('strval', (array)($_POST['new_rows'] ?? [])));
    foreach ((array)($_POST['new_cell'] ?? []) as $key => $edit) {
        if (!isset($newWanted[(string)$key])) { continue; }
        if (count($rows) + count($added) >= ACCOUNT_IMPORT_MAX_ROWS) { break; }
        $edit = (array)$edit;
        $added[] = [
            'line'     => null,
            'added'    => true,
            'id'       => trim((string)($edit['id'] ?? '')),
            'name'     => normalize_person_name((string)($edit['name'] ?? '')),
            'email'    => strtolower(trim((string)($edit['email'] ?? ''))),
            'position' => $kind === 'student' ? '' : trim((string)($edit['position'] ?? '')),
            'password' => '',
            'problems' => [],
            'exists'   => '',
        ];
    }
    /* A row added and left entirely blank is somebody who pressed Add and
       changed their mind: ignored, exactly as a blank row in a file is. */
    $added = array_values(array_filter($added, static fn($r) => $r['id'] !== '' || $r['name'] !== '' || $r['email'] !== ''));
    $rows = array_merge($rows, $added);

    if (!$rows) {
        flash('error', 'No rows were ticked, so nothing was created.');
        $back();
    }

    $notify = ($_POST['notify'] ?? '') === '1';
    $result = account_import_create($rows, $kind, $stash['ctx'], $conn, $notify);

    /* Rows that failed are kept, with what was typed into them, so they can be
       corrected and tried again. Clearing the lot would throw away the edits
       along with the mistake. Everything through: the import is finished. */
    if ($result['created'] + $result['failed'] + $result['skipped'] > 0 && ($result['failed'] || $result['skipped'])) {
        $done = [];
        foreach ($result['log'] as $line) {
            if (!empty($line['ok'])) { $done[$line['id']] = true; }
        }
        $keep = [];
        $lastLine = 0;
        foreach ($stash['rows'] as $row) {
            if (!empty($row['over'])) { continue; }
            if (isset($dropped[$row['line'] ?? -1])) { continue; }   // taken out on the preview
            foreach ($rows as $edited) {
                if (($edited['line'] ?? null) === ($row['line'] ?? null)) { $row = $edited; break; }
            }
            if (isset($done[$row['id']])) { continue; }      // created, nothing left to do
            $row['problems'] = account_import_check_row($row, $kind);
            $row['exists']   = account_import_existing($conn, $kind, $row['id'], $row['email']);
            $row['password'] = $row['problems'] ? '' : account_import_password($row['name'], $row['id']);
            $keep[] = $row;
            $lastLine = max($lastLine, (int)($row['line'] ?? 0));
        }
        /* An added row that did not go through is kept like any other, so it can
           be corrected and tried again. It is given a line number after the
           file's own only now, because until this moment it had no place in the
           file and nothing should have pretended otherwise. */
        foreach ($added as $row) {
            if (isset($done[$row['id']])) { continue; }
            $row['line']     = ++$lastLine;
            $row['problems'] = account_import_check_row($row, $kind);
            $row['exists']   = account_import_existing($conn, $kind, $row['id'], $row['email']);
            $row['password'] = $row['problems'] ? '' : account_import_password($row['name'], $row['id']);
            $keep[] = $row;
        }
        $stash['rows'] = $keep;
        manage_import_set($kind, $keep ? $stash : null);
    } else {
        manage_import_set($kind, null);
    }

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
                <?php endif; ?>

                <div class="mgmt-field">
                    <label for="imp_file">File <span class="req">*</span></label>
                    <input type="file" name="import_file" id="imp_file"
                           accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    <small class="mgmt-hint">
                        Headings on the first row: <?= e(implode(', ', $spec['headers'])) ?>.
                        The name may be written the roll's way instead, as
                        <em>Surname, First Name Middle Name</em>, as
                        <em>First Name Middle Name Last Name</em>, or in a column each; they
                        are put together into one name in the preview.
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
                        <?php /* The counts are written by script as rows are mended, taken
                                 out or added, so what is said here cannot drift from the
                                 table under it. These are the opening figures. */ ?>
                        <span id="importRowCount"><?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></span> in
                        <?= e($stash['file'] !== '' ? $stash['file'] : 'the file') ?>
                    </h3>
                    <p class="import-lead">
                        <span id="importTally"><strong><?= $nReady ?></strong> ready to create<?php
                        $bad = count($rows) - $nReady;
                        if ($bad) { echo ', <strong>' . $bad . '</strong> needing attention'; } ?>.</span>
                        Every box below can be corrected here: fix a row and it is checked again as
                        you type, with no need to mend the spreadsheet and upload it afresh.
                    </p>
                </div>
                <?php /* Undo and Redo stand beside Discard because they are the same
                         question asked smaller: Discard throws away every correction and
                         the file with it, Undo takes back the last one. They are
                         type="button" and sit outside the create form, so neither can
                         submit anything; everything they do is in the page. */ ?>
                <div class="import-tools">
                    <div class="import-history" role="group" aria-label="Your corrections">
                        <button type="button" class="btn-sm-outline" id="importUndo" disabled
                                title="Undo the last correction (Ctrl+Z)">
                            <span class="material-symbols-outlined" aria-hidden="true">undo</span>
                            <span>Undo</span>
                        </button>
                        <button type="button" class="btn-sm-outline" id="importRedo" disabled
                                title="Redo the correction just undone (Ctrl+Y)">
                            <span class="material-symbols-outlined" aria-hidden="true">redo</span>
                            <span>Redo</span>
                        </button>
                    </div>
                    <form method="post" class="import-discard">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="import_discard">
                        <button type="submit" class="btn-sm-outline">Discard</button>
                    </form>
                </div>
            </div>

            <?php if ($stash['headerless']): ?>
                <p class="import-warn">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    No headings were recognised, so the columns were read in order:
                    <?= e(implode(', ', $spec['headers'])) ?>. Check the first row below is a person
                    and not a heading.
                </p>
            <?php endif; ?>
            <?php /* An account has one full_name, so a roll that keeps the parts of a
                     name in separate columns is put together into the one Name box
                     below. That is a change to what was uploaded, and a change nobody
                     is told about reads as the file having been misread. */ ?>
            <?php if (!empty($stash['name_from'])):
                $nameCols = array_values($stash['name_from']);
                $lastCol  = array_pop($nameCols);
                $saidCols = $nameCols ? implode(', ', $nameCols) . ' and ' . $lastCol : $lastCol;
            ?>
                <p class="import-warn">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    <?= e($saidCols) ?> were read as one name, in the order a name is
                    written. Check a row or two below.
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
                    <table class="import-table is-editable">
                        <thead>
                            <tr>
                                <th class="import-tick">
                                    <input type="checkbox" id="impAll"
                                           aria-label="Tick or untick every row">
                                </th>
                                <th class="import-act"><span aria-label="Remove a row"></span></th>
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
                            $ok = account_import_usable($r);
                            $taken = ($r['exists'] ?? '') !== '';
                            $line = (int)$r['line']; ?>
                            <?php /* A row whose ID or email is already on an account cannot be
                                     created, so it is greyed and its tick is locked rather than
                                     left to fail on the server. Editing either box unlocks it
                                     again: the clash may be exactly what the edit mends. */ ?>
                            <tr class="import-row <?= $ok ? '' : 'is-bad' ?> <?= $taken ? 'is-taken' : '' ?>"
                                data-line="<?= $line ?>" <?= $taken ? 'data-taken="1"' : '' ?>>
                                <td class="import-tick">
                                    <input type="checkbox" name="rows[]" value="<?= $line ?>"
                                           class="import-row-tick" <?= $ok ? 'checked' : '' ?>
                                           <?= $taken ? 'disabled' : '' ?>
                                           aria-label="Create line <?= $line ?>">
                                </td>
                                <td class="import-act">
                                    <?php /* Taking a row out is a correction like any other: it
                                             goes on the Undo stack, and the line is only really
                                             gone from the import once Create is pressed, which is
                                             when the typed values stop being the page's and
                                             become the server's. */ ?>
                                    <button type="button" class="import-row-del"
                                            title="Remove this row from the import"
                                            aria-label="Remove line <?= $line ?> from the import">
                                        <span class="material-symbols-outlined" aria-hidden="true">close</span>
                                    </button>
                                </td>
                                <td><input type="text" class="import-cell" data-field="id"
                                           name="cell[<?= $line ?>][id]" value="<?= e($r['id']) ?>"
                                           aria-label="<?= e($spec['id_label']) ?>, line <?= $line ?>"></td>
                                <td><input type="text" class="import-cell" data-field="name"
                                           name="cell[<?= $line ?>][name]" value="<?= e($r['name']) ?>"
                                           aria-label="Full name, line <?= $line ?>"></td>
                                <td><input type="text" class="import-cell" data-field="email"
                                           name="cell[<?= $line ?>][email]" value="<?= e($r['email']) ?>"
                                           aria-label="Email, line <?= $line ?>"></td>
                                <?php if ($kind !== 'student'): ?>
                                <td>
                                    <?php /* A list, not a box: the position decides the role stored, and
                                             only three are allowed here. Typing one in would invite a
                                             spelling the server has to refuse. */ ?>
                                    <select class="import-cell" data-field="position"
                                            name="cell[<?= $line ?>][position]"
                                            aria-label="Position, line <?= $line ?>">
                                        <option value=""<?= $r['position'] === '' ? ' selected' : '' ?>>Choose</option>
                                        <?php foreach ($spec['positions'] as $pos): ?>
                                            <option value="<?= e($pos) ?>"<?= $r['position'] === $pos ? ' selected' : '' ?>><?= e($pos) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <?php endif; ?>
                                <td class="import-pw"><?= $ok ? e($r['password']) : '' ?></td>
                                <td class="import-status">
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

                <?php /* Lines taken out of the preview, so the create pass can drop them
                         from the stash as well. Without it a removed row would be back on
                         the screen that reports the result, since that screen is drawn
                         from the stash and not from the page. */ ?>
                <input type="hidden" name="dropped" id="importDropped" value="">

                <button type="button" class="import-add" id="importAddRow">
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    Add a row
                </button>

                <?php /* The shape of a row added here. Its fields are named apart from the
                         file's (new_cell / new_rows), so the two cannot be confused on the
                         server, and the key is only a position in the post. */ ?>
                <template id="importNewRow">
                    <tr class="import-row is-new is-bad" data-new="1">
                        <td class="import-tick">
                            <input type="checkbox" name="new_rows[]" value="__K__"
                                   class="import-row-tick" checked aria-label="Create this added row">
                        </td>
                        <td class="import-act">
                            <button type="button" class="import-row-del"
                                    title="Remove this row from the import"
                                    aria-label="Remove this added row">
                                <span class="material-symbols-outlined" aria-hidden="true">close</span>
                            </button>
                        </td>
                        <td><input type="text" class="import-cell" data-field="id"
                                   name="new_cell[__K__][id]" value=""
                                   aria-label="<?= e($spec['id_label']) ?>, added row"></td>
                        <td><input type="text" class="import-cell" data-field="name"
                                   name="new_cell[__K__][name]" value=""
                                   aria-label="Full name, added row"></td>
                        <td><input type="text" class="import-cell" data-field="email"
                                   name="new_cell[__K__][email]" value=""
                                   aria-label="Email, added row"></td>
                        <?php if ($kind !== 'student'): ?>
                        <td>
                            <select class="import-cell" data-field="position"
                                    name="new_cell[__K__][position]" aria-label="Position, added row">
                                <option value="" selected>Choose</option>
                                <?php foreach ($spec['positions'] as $pos): ?>
                                    <option value="<?= e($pos) ?>"><?= e($pos) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php endif; ?>
                        <td class="import-pw">on create</td>
                        <td class="import-status"></td>
                    </tr>
                </template>

                <div class="import-foot">
                    <label class="import-notify">
                        <input type="checkbox" name="notify" value="1">
                        <span>
                            Email each person their password.
                            <small>Off by default: these messages reach real inboxes and cannot be recalled.</small>
                        </span>
                    </label>

                    <?php /* The count follows the ticks, which follow the corrections, so
                             it is written by script rather than fixed here. data-confirm is
                             rewritten with it: action_dialogs.php reads the attribute when
                             the button is pressed, so keeping it in step is enough. */ ?>
                    <button type="submit" class="btn-sm-maroon" id="importCreate"
                            data-confirm="Create <?= $nReady ?> account<?= $nReady === 1 ? '' : 's' ?>? Their passwords are shown once, on the next screen."
                            data-confirm-yes="Create accounts">
                        <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
                        <span id="importCreateLabel">Create <?= $nReady ?> account<?= $nReady === 1 ? '' : 's' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </section>

    <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    (function () {
        var all = document.getElementById('impAll');
        if (!all) { return; }
        var table  = document.querySelector('.import-table'),
            tbody  = table ? table.querySelector('tbody') : null,
            button = document.getElementById('importCreate'),
            label  = document.getElementById('importCreateLabel'),
            tally  = document.getElementById('importTally'),
            rowSum = document.getElementById('importRowCount'),
            dropped = document.getElementById('importDropped'),
            addBtn = document.getElementById('importAddRow'),
            tpl    = document.getElementById('importNewRow'),
            KIND   = <?= json_encode($kind) ?>,
            MAXROWS = <?= (int)ACCOUNT_IMPORT_MAX_ROWS ?>;
        if (!tbody) { return; }

        /* Rows are taken out and added here, so nothing may hold a list of them
           from load. Every reader asks the table as it stands, and the handlers
           are delegated to the body rather than bound per row. */
        function rowList()  { return Array.prototype.slice.call(tbody.querySelectorAll('.import-row')); }
        function tickList() { return Array.prototype.slice.call(tbody.querySelectorAll('.import-row-tick')); }

        /* The rules the forms use, published by includes/manage_validate.php.
           Absent only if that file is not on the page, in which case a row is
           simply not re-checked here and the server still decides. */
        var RULES = window.papelManageRules || null;

        function problemsFor(row) {
            if (!RULES) { return null; }
            var get = function (f) {
                var el = row.querySelector('[data-field="' + f + '"]');
                return el ? el.value.trim() : '';
            };
            var out = [];
            [RULES.name(get('name'), false),
             KIND === 'student' ? RULES.studentNumber(get('id'), false) : RULES.staffId(get('id'), false),
             RULES.email(get('email'), false)].forEach(function (why) {
                if (why) { out.push(why); }
            });
            if (KIND !== 'student' && !get('position')) {
                out.push('Choose a position for this row.');
            }
            return out;
        }

        /* A row already on file keeps saying so until its ID and email are both
           changed: the clash was found on the server and cannot be re-tested
           here, so the honest thing is to leave the sentence and let the create
           pass decide. */
        function wasExisting(row) {
            var skip = row.querySelector('.import-skip');
            return skip ? skip.textContent.trim() : '';
        }

        function paint(row) {
            var problems = problemsFor(row);
            if (problems === null) { return; }
            var cell = row.querySelector('.import-status');
            var still = wasExisting(row);

            row.classList.toggle('is-bad', problems.length > 0);

            if (problems.length) {
                var ul = document.createElement('ul');
                ul.className = 'import-why';
                problems.forEach(function (why) {
                    var li = document.createElement('li');
                    li.textContent = why;          // a person's typing, never markup
                    ul.appendChild(li);
                });
                cell.textContent = '';
                cell.appendChild(ul);
            } else if (still && row.dataset.taken) {
                /* still clashing: leave the sentence saying so */
            } else {
                var span = document.createElement('span');
                span.className = 'import-ok';
                span.textContent = (row.dataset.edited || row.dataset.new) ? 'checked on create' : 'ready';
                cell.textContent = '';
                cell.appendChild(span);
            }
        }

        /* What the heading and the sentence under it say. Written here rather
           than left as the server printed them, because a row taken out or
           added changes both, and a count that contradicts the table under it
           is worse than no count. */
        function summarise() {
            var rows = rowList(), ready = 0;
            rows.forEach(function (r) {
                var tick = r.querySelector('.import-row-tick');
                if (!r.classList.contains('is-bad') && tick && !tick.disabled) { ready++; }
            });
            if (rowSum) {
                rowSum.textContent = rows.length + ' row' + (rows.length === 1 ? '' : 's');
            }
            if (tally) {
                var bad = rows.length - ready;
                tally.textContent = '';
                var strong = document.createElement('strong');
                strong.textContent = String(ready);
                tally.appendChild(strong);
                tally.appendChild(document.createTextNode(' ready to create'));
                if (bad > 0) {
                    tally.appendChild(document.createTextNode(', '));
                    var s2 = document.createElement('strong');
                    s2.textContent = String(bad);
                    tally.appendChild(s2);
                    tally.appendChild(document.createTextNode(' needing attention'));
                }
                tally.appendChild(document.createTextNode('.'));
            }
        }

        function count() {
            var ticks = tickList();
            var on = ticks.filter(function (t) { return t.checked; }).length;
            all.checked = on === ticks.length && on > 0;
            all.indeterminate = on > 0 && on < ticks.length;
            if (label) { label.textContent = 'Create ' + on + ' account' + (on === 1 ? '' : 's'); }
            if (button) {
                button.disabled = on === 0;
                button.setAttribute('data-confirm',
                    'Create ' + on + ' account' + (on === 1 ? '' : 's')
                    + '? Their passwords are shown once, on the next screen.');
            }
            summarise();
        }

        /* ─────────────────────── Undo and Redo ───────────────────────────
           Every box here is editable, so a correction could be made but never
           taken back: the only way out of a mistyped cell was Discard, which
           throws the whole file away. An entry is one change, with the row
           state it dragged along: editing a clashing row's ID unlocks its tick
           and blanks its password, and undoing that edit has to lock it again,
           or the preview would claim a clash was mended by an edit that is no
           longer on the page.

           The recorders are delegated to the table body and registered BEFORE
           the re-check handler at the end of this block, so they see the row as
           it was: listeners on one element fire in the order they were added,
           and the re-check is what unlocks the row. */
        var undoStack = [], redoStack = [], HISTORY_MAX = 100,
            applying  = false,          // a change we are making ourselves
            openEdit  = null,           // the cell being typed in, not yet an entry
            idleTimer = null,
            newKey    = 0,              // only a position in the post, never an identity
            undoBtn   = document.getElementById('importUndo'),
            redoBtn   = document.getElementById('importRedo');

        function rowState(row) {
            var tick = row.querySelector('.import-row-tick'),
                pw   = row.querySelector('.import-pw');
            return {
                edited:  row.dataset.edited || '',
                taken:   row.dataset.taken || '',
                isTaken: row.classList.contains('is-taken'),
                on:      tick ? tick.checked : false,
                locked:  tick ? tick.disabled : false,
                pwText:  pw ? pw.textContent : null,
                pwPend:  pw ? pw.classList.contains('import-pw-pending') : false,
                pwEdit:  pw ? (pw.dataset.wasEdited || '') : ''
            };
        }

        function putRowState(row, st) {
            if (!st) { return; }
            if (st.edited) { row.dataset.edited = st.edited; } else { delete row.dataset.edited; }
            if (st.taken)  { row.dataset.taken  = st.taken;  } else { delete row.dataset.taken; }
            row.classList.toggle('is-taken', st.isTaken);
            var tick = row.querySelector('.import-row-tick');
            if (tick) { tick.checked = st.on; tick.disabled = st.locked; }
            var pw = row.querySelector('.import-pw');
            if (pw && st.pwText !== null) {
                pw.textContent = st.pwText;
                pw.classList.toggle('import-pw-pending', st.pwPend);
                if (st.pwEdit) { pw.dataset.wasEdited = st.pwEdit; } else { delete pw.dataset.wasEdited; }
            }
        }

        function historyButtons() {
            if (undoBtn) { undoBtn.disabled = undoStack.length === 0; }
            if (redoBtn) { redoBtn.disabled = redoStack.length === 0; }
        }

        function push(entry) {
            undoStack.push(entry);
            if (undoStack.length > HISTORY_MAX) { undoStack.shift(); }
            redoStack.length = 0;        // a new correction ends the redo path
            historyButtons();
        }

        /* The lines taken out, so the create pass drops them from the stash as
           well: the screen that reports the result is drawn from the stash, not
           from this page, and a removed row would otherwise be back on it. */
        function syncDropped() {
            if (!dropped) { return; }
            var out = [];
            rowList().forEach(function (r) { if (r.dataset.line) { out.push(r.dataset.line); } });
            var gone = (dropped.dataset.all || '').split(',').filter(function (l) {
                return l !== '' && out.indexOf(l) < 0;
            });
            dropped.value = gone.join(',');
        }

        /* `which` is 'before' or 'after': one function for both directions, so
           undo and redo cannot drift apart. */
        function run(entry, which) {
            applying = true;
            (entry.cells || []).forEach(function (c) {
                c.el.value = c[which];
                /* The site draws every select as a button (select_skin.php),
                   which repaints its label on the select's own change event.
                   Without this the value would be restored and the button would
                   go on showing the position that was undone. */
                if (c.el.tagName === 'SELECT') {
                    c.el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            (entry.ticks || []).forEach(function (t) { t.el.checked = t[which]; });
            /* A row put back goes where it was, not at the end: the preview is
               read against the spreadsheet beside it. */
            (entry.dom || []).forEach(function (d) {
                var wanted = d[which];                    // 'in' or 'out'
                if (wanted === 'in' && !d.el.parentNode) {
                    if (d.next && d.next.parentNode === d.parent) { d.parent.insertBefore(d.el, d.next); }
                    else { d.parent.appendChild(d.el); }
                } else if (wanted === 'out' && d.el.parentNode) {
                    d.next = d.el.nextSibling;
                    d.el.parentNode.removeChild(d.el);
                }
            });
            (entry.rows  || []).forEach(function (r) { putRowState(r.row, r[which]); });
            applying = false;
            (entry.rows || []).forEach(function (r) { paint(r.row); });
            snapTicks();
            syncDropped();
            count();
            historyButtons();
        }

        /* A burst of typing in one cell is one correction, not one per letter:
           the entry stays open until the cell is left, another is edited, or
           the typing stops for three quarters of a second. */
        function commitEdit() {
            if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
            if (!openEdit) { return; }
            var e = openEdit;
            openEdit = null;
            if (e.el.value === e.before) { return; }
            e.el._prev = e.el.value;
            push({
                cells: [{ el: e.el, before: e.before, after: e.el.value }],
                rows:  [{ row: e.row, before: e.rowBefore, after: rowState(e.row) }]
            });
        }

        function noteEdit(cell, row) {
            if (applying) { return; }
            if (openEdit && openEdit.el !== cell) { commitEdit(); }
            if (!openEdit) {
                openEdit = { el: cell, row: row,
                             before: cell._prev !== undefined ? cell._prev : cell.value,
                             rowBefore: rowState(row) };
            }
            if (idleTimer) { clearTimeout(idleTimer); }
            idleTimer = setTimeout(commitEdit, 750);
        }

        /* The ticks are compared against a mark left on each box rather than a
           list kept here, so the header tick, which moves every row at once, is
           one entry, and a row added or taken out cannot put the list out of
           step with the table. */
        function snapTicks() { tickList().forEach(function (t) { t._snap = t.checked; }); }
        function noteTicks() {
            if (applying) { return; }
            var moved = [];
            tickList().forEach(function (t) {
                if (t._snap !== undefined && t.checked !== t._snap) {
                    moved.push({ el: t, before: t._snap, after: t.checked });
                }
            });
            snapTicks();
            if (moved.length) { push({ ticks: moved }); }
        }

        function undo() {
            commitEdit();
            var entry = undoStack.pop();
            if (!entry) { return; }
            run(entry, 'before');
            (entry.cells || []).forEach(function (c) { c.el._prev = c.before; });
            redoStack.push(entry);
            historyButtons();
        }

        function redoLast() {
            var entry = redoStack.pop();
            if (!entry) { return; }
            run(entry, 'after');
            (entry.cells || []).forEach(function (c) { c.el._prev = c.after; });
            undoStack.push(entry);
            historyButtons();
        }

        if (undoBtn) { undoBtn.addEventListener('click', undo); }
        if (redoBtn) { redoBtn.addEventListener('click', redoLast); }

        /* Ctrl+Z and Ctrl+Y, but never inside a text box: the browser's own undo
           is already the right answer there, and taking it would make a
           half-typed correction unfixable without losing the whole cell. */
        document.addEventListener('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey)) { return; }
            var k = e.key.toLowerCase();
            if (k !== 'z' && k !== 'y') { return; }
            var el = document.activeElement;
            if (el && el.tagName === 'INPUT' && el.type === 'text' && el.classList.contains('import-cell')) { return; }
            if (!document.querySelector('.import-drawer.is-open')) { return; }
            e.preventDefault();
            (k === 'y' || e.shiftKey) ? redoLast() : undo();
        });

        /* ───────────────── taking a row out, and adding one ──────────────
           Both are corrections like any other, so both go on the Undo stack.
           Nothing is created until Create is pressed, which is the moment the
           page's typing becomes the server's business; until then a row taken
           out is only off this screen. */
        function removeRow(row) {
            commitEdit();
            var entry = { dom: [{ el: row, parent: row.parentNode, next: row.nextSibling,
                                  before: 'in', after: 'out' }] };
            push(entry);
            run(entry, 'after');
        }

        function addRow() {
            commitEdit();
            if (!tpl || rowList().length >= MAXROWS) { return; }
            var html = tpl.innerHTML.split('__K__').join(String(++newKey));
            var box = document.createElement('tbody');
            box.innerHTML = html;
            var row = box.querySelector('tr');
            if (!row) { return; }
            /* Out of the box it was parsed in, or it still has a parent and the
               insert below, which puts back only a row that has none, does
               nothing: the first press appeared to add no row at all while the
               entry it pushed worked perfectly from then on. */
            box.removeChild(row);
            var entry = { dom: [{ el: row, parent: tbody, next: null,
                                  before: 'out', after: 'in' }] };
            push(entry);
            run(entry, 'after');
            row.querySelectorAll('.import-cell').forEach(function (c) { c._prev = c.value; });
            paint(row);
            count();
            var first = row.querySelector('.import-cell');
            if (first) { first.focus(); }
        }

        if (addBtn) { addBtn.addEventListener('click', addRow); }

        /* ─────────────── one set of delegated handlers ──────────────────
           Registered in this order on the table body: record first, re-check
           second, because the re-check is what unlocks a clashing row and the
           recorder has to see the row as it was. */
        tbody.addEventListener('input', function (e) {
            var cell = e.target.closest ? e.target.closest('.import-cell') : null;
            if (cell) { noteEdit(cell, cell.closest('.import-row')); }
        });
        tbody.addEventListener('change', function (e) {
            var cell = e.target.closest ? e.target.closest('.import-cell') : null;
            if (cell) { noteEdit(cell, cell.closest('.import-row')); commitEdit(); return; }
            if (e.target.classList && e.target.classList.contains('import-row-tick')) {
                count(); noteTicks();
            }
        });
        tbody.addEventListener('focusout', function (e) {
            if (e.target.closest && e.target.closest('.import-cell')) { commitEdit(); }
        });
        tbody.addEventListener('click', function (e) {
            var del = e.target.closest ? e.target.closest('.import-row-del') : null;
            if (!del) { return; }
            e.preventDefault();
            var row = del.closest('.import-row');
            if (row) { removeRow(row); }
        });

        all.addEventListener('change', function () {
            tickList().forEach(function (t) { if (!t.disabled) { t.checked = all.checked; } });
            count();
            noteTicks();
        });

        /* The re-check, after the recorders above. */
        tbody.addEventListener('input', recheckFrom);
        tbody.addEventListener('change', recheckFrom);

        function recheckFrom(e) {
            if (applying) { return; }
            var cell = e.target.closest ? e.target.closest('.import-cell') : null;
            if (!cell) { return; }
            var row = cell.closest('.import-row');
            if (!row) { return; }

            row.dataset.edited = '1';
            /* A clash is found on the server and cannot be re-tested here,
               so changing the ID or the email is taken as an attempt to mend
               it: the row is unlocked and the create pass decides. Changing
               the name alone cannot mend a clash, so it does not unlock. */
            if (row.dataset.taken && (cell.dataset.field === 'id' || cell.dataset.field === 'email')) {
                var tick = row.querySelector('.import-row-tick');
                if (tick && tick.disabled) { tick.disabled = false; tick.checked = true; }
                row.classList.remove('is-taken');
                delete row.dataset.taken;
            }
            /* The password comes from the name and the ID, so an edited
               row's old one is wrong. It is worked out again on the
               server at create; saying so beats showing a stale value. */
            var pw = row.querySelector('.import-pw');
            if (pw && !pw.dataset.wasEdited && !row.dataset.new) {
                pw.dataset.wasEdited = '1';
                pw.textContent = 'on create';
                pw.classList.add('import-pw-pending');
            }
            paint(row);
            count();
        }

        rowList().forEach(function (row) {
            row.querySelectorAll('.import-cell').forEach(function (c) { c._prev = c.value; });
        });
        if (dropped) {
            dropped.dataset.all = rowList().map(function (r) { return r.dataset.line || ''; })
                                           .filter(function (l) { return l !== ''; }).join(',');
        }
        snapTicks();
        count();
        historyButtons();
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
