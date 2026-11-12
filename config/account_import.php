<?php
/**
 * Enrolling a list of people from a spreadsheet.
 *
 * The three management consoles each create one account at a time, which is
 * right for a correction and wrong for a section of forty. This reads a CSV or
 * an .xlsx, checks every row against the rules the forms already use, and
 * creates the accounts that pass.
 *
 * Three things worth knowing before changing it:
 *
 *   - **The rules are not restated here.** A row is checked with
 *     person_name_problem(), student_number_problem(), staff_id_problem(),
 *     account_email_problem() and the rest, in the same order the forms read
 *     them, so a file is refused for exactly the reasons typing would be. See
 *     "What a management console may be told" in config/core.php.
 *   - **Nothing is created from a preview.** The preview is re-checked from
 *     scratch when Create is pressed, because what comes back is a posted form
 *     and a posted form is a stranger's typing. The second pass is the one that
 *     counts; the first exists only so a person sees what is about to happen.
 *   - **Email is off unless asked for.** Creating an account normally sends its
 *     password to the person. Forty of those cannot be recalled, and a test run
 *     is the likeliest first use, so the caller has to say yes.
 *
 * There is deliberately no PhpSpreadsheet. It would add several thousand files
 * to a host that allows about thirty thousand and whose deploy trims vendor/ to
 * about nine hundred, to read three columns. The .xlsx reader below is the zip
 * and simplexml extensions, which are already here. The old binary .xls is a
 * different format altogether and is refused in words rather than half read.
 */

/** More than a cohort, and small enough that one request can still finish. */
const ACCOUNT_IMPORT_MAX_ROWS  = 300;
const ACCOUNT_IMPORT_MAX_BYTES = 2097152;   // 2 MB: a 300-row sheet is ~20 KB

/**
 * What each console imports, and what a row of its file may hold.
 *
 * 'positions' is the set a file may name for that console. It is the console's
 * own authority, not staff_positions() in full: the advisers' console must not
 * be able to mint a Director by typing one into a spreadsheet, for the same
 * reason createFaculty() checks its own MANAGES list.
 */
function account_import_kinds(): array {
    return [
        'student' => [
            'noun'      => 'student',
            'plural'    => 'students',
            'id_label'  => 'Student ID',
            'positions' => [],
            'headers'   => ['Student ID', 'Full Name', 'Email'],
        ],
        'faculty' => [
            'noun'      => 'staff account',
            'plural'    => 'staff accounts',
            'id_label'  => 'Faculty ID',
            'positions' => ['Research Adviser', 'Librarian'],
            'headers'   => ['Faculty ID', 'Full Name', 'Email', 'Position'],
        ],
        'admin' => [
            'noun'      => 'admin account',
            'plural'    => 'admin accounts',
            'id_label'  => 'Faculty ID',
            'positions' => ['Research Coordinator', 'Head of Academic Affairs', 'Librarian'],
            'headers'   => ['Faculty ID', 'Full Name', 'Email', 'Position'],
        ],
    ];
}

function account_import_kind(string $kind): array {
    $all = account_import_kinds();
    if (!isset($all[$kind])) {
        throw new InvalidArgumentException('Unknown import kind: ' . $kind);
    }
    return $all[$kind];
}

/* ─────────────────────────── the password ───────────────────────────── */

/**
 * The same password the Generate button builds, in PHP.
 *
 *     Rayver Reyes + 2023-00056-BN-0  ->  RayverReyes-056
 *
 * A twin of the rule in includes/password_generator.php, which is JavaScript
 * and runs only where somebody is typing. An import has nobody typing, and a
 * second format would mean two kinds of password in one roll, so the rule is
 * copied rather than reinvented. **Change both or neither**, and keep the two
 * lists below in step with the regexes there.
 */
function account_import_password(string $fullName, string $id): string {
    $SKIP = ['dr','prof','professor','engr','arch','atty','hon','rev','mr','mrs',
             'ms','miss','sir','maam','jr','sr','ii','iii','iv'];
    $PARTICLE = ['dela','delos','delas','de','del','dos','das','da','di','la',
                 'las','los','san','santa','sta','sto','van','von','bin'];

    $words = [];
    foreach (preg_split('/[\s,]+/', $fullName) as $raw) {
        $w = preg_replace('/[^A-Za-z]/', '', (string)$raw);
        if ($w !== '' && !in_array(strtolower($w), $SKIP, true)) { $words[] = $w; }
    }

    $title = static function (string $w): string {
        return mb_strtoupper(mb_substr($w, 0, 1)) . mb_strtolower(mb_substr($w, 1));
    };

    $name = '';
    if ($words) {
        /* Walk back over particles so the whole surname comes along, stopping at
           index 1: the first word is the given name and never part of it. */
        $end = count($words) - 1;
        while ($end > 1 && in_array(strtolower($words[$end - 1]), $PARTICLE, true)) { $end--; }
        $surname = implode('', array_map($title, array_slice($words, $end)));
        $name = count($words) === 1 ? $surname : $title($words[0]) . $surname;
    }
    if ($name === '') { return ''; }

    $tail = '';
    if (preg_match_all('/\d+/', $id, $m) && $m[0]) {
        $groups = $m[0];
        $long = array_values(array_filter($groups, static fn($g) => strlen($g) >= 3));
        $pick = $long ? end($long) : end($groups);
        $tail = substr($pick, -3);
    }
    /* An ID with letters but no digits: keep the shape and stay inside the
       password rule rather than emitting one with no digit in it. */
    if ($tail === '') { $tail = (string)random_int(100, 999); }

    return $name . '-' . $tail;
}

/* ──────────────────────── reading the file ──────────────────────────── */

/**
 * Every row of the first sheet, as arrays of strings.
 *
 * @throws UserFacingException for anything a person can fix by saving the file
 *         differently. The caller shows the message as it stands.
 */
function account_import_read(array $file): array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new UserFacingException(account_import_upload_error($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }
    if (($file['size'] ?? 0) > ACCOUNT_IMPORT_MAX_BYTES) {
        throw new UserFacingException('That file is larger than 2 MB. A list of names should be a few kilobytes, so this is probably the wrong file.');
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        return account_import_read_csv($file['tmp_name']);
    }
    if ($ext === 'xlsx') {
        return account_import_read_xlsx($file['tmp_name']);
    }
    if ($ext === 'xls') {
        throw new UserFacingException('The old .xls format cannot be read here. Open it in Excel and use File, Save As, and pick "Excel Workbook (.xlsx)" or "CSV".');
    }
    throw new UserFacingException('Upload a .csv or .xlsx file. ' . ($ext === '' ? 'That file has no extension.' : 'That one is a .' . $ext . '.'));
}

function account_import_upload_error(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_NO_FILE:   return 'Choose a file first.';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'That file is too large to upload.';
        case UPLOAD_ERR_PARTIAL:   return 'The file only partly uploaded. Try again.';
        default:                   return 'The file could not be uploaded. Try again.';
    }
}

function account_import_read_csv(string $path): array {
    $text = file_get_contents($path);
    if ($text === false) { throw new UserFacingException('The file could not be read.'); }

    /* Excel writes a byte order mark, and it lands on the first header, so
       "Student ID" arrives as "\xEF\xBB\xBFStudent ID" and matches nothing. */
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

    /* Excel in a Philippine or European locale writes semicolons. Count what
       the first line actually holds rather than assuming a comma. */
    $first = strtok($text, "\r\n") ?: '';
    $delim = ',';
    $best = substr_count($first, ',');
    foreach ([';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")] as $d => $n) {
        if ($n > $best) { $best = $n; $delim = $d; }
    }

    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
        if ($cells === [null]) { continue; }              // a blank line
        $rows[] = array_map(static fn($c) => trim((string)$c), $cells);
    }
    fclose($fh);
    return $rows;
}

/**
 * The first worksheet of an .xlsx, which is a zip of XML.
 *
 * Only what is needed to read a list of names: shared strings, inline strings
 * and plain values. Formulas are read as their cached result, which is what the
 * file holds anyway, and formatting is ignored. A date would come back as the
 * underlying serial number, which is why no column here is a date.
 */
function account_import_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new UserFacingException('This server cannot read .xlsx files. Save the list as CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new UserFacingException('That .xlsx could not be opened. It may be damaged, or not really a .xlsx.');
    }

    try {
        $prev = libxml_use_internal_errors(true);

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sst = simplexml_load_string($xml);
            if ($sst !== false) {
                foreach ($sst->si as $si) {
                    /* A run-formatted cell is several <r><t> pieces of one string. */
                    $shared[] = isset($si->t) ? (string)$si->t : implode('', array_map('strval', iterator_to_array($si->xpath('.//*[local-name()="t"]') ?: [])));
                }
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            /* A sheet renamed or reordered in Excel is not always sheet1.xml. */
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
                    $sheet = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        if ($sheet === false) {
            throw new UserFacingException('That .xlsx has no worksheet in it.');
        }

        $doc = simplexml_load_string($sheet);
        if ($doc === false) {
            throw new UserFacingException('That .xlsx could not be read. Try saving it as CSV.');
        }

        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref  = (string)$c['r'];
                $col  = account_import_col_index(preg_replace('/\d+/', '', $ref));
                $type = (string)$c['t'];

                if ($type === 's') {
                    $idx = (int)$c->v;
                    $val = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                } else {
                    $val = isset($c->v) ? (string)$c->v : '';
                }
                $cells[$col] = trim($val);
            }
            if (!$cells) { $rows[] = []; continue; }
            /* Cells are sparse in the file: an empty one is simply absent, so
               the gaps are filled in or the columns shift left. */
            $rows[] = array_map(
                static fn($i) => $cells[$i] ?? '',
                range(0, max(array_keys($cells)))
            );
        }
        return $rows;
    } finally {
        $zip->close();
        if (isset($prev)) { libxml_use_internal_errors($prev); }
    }
}

/** "A" => 0, "B" => 1, "AA" => 26. */
function account_import_col_index(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        if ($ch < 'A' || $ch > 'Z') { continue; }
        $n = $n * 26 + (ord($ch) - 64);
    }
    return max(0, $n - 1);
}

/* ─────────────────────── making sense of the rows ───────────────────── */

/**
 * Which column is which.
 *
 * Returns [map, headerRowIndex] where map is field => column index, or
 * [positional, -1] when the first row is data rather than headings. People
 * export these from a dozen places, so the names are matched loosely.
 */
function account_import_map(array $rows, string $kind): array {
    $want = [
        'id'       => ['id', 'student id', 'student no', 'student number', 'studentno', 'student_id',
                       'faculty id', 'faculty_id', 'staff id', 'employee id', 'employee no', 'id number', 'idnumber'],
        'name'     => ['name', 'full name', 'fullname', 'full_name', 'complete name', 'student name', 'staff name'],
        'email'    => ['email', 'e-mail', 'email address', 'emailaddress', 'mail'],
        'position' => ['position', 'role', 'designation', 'title', 'post'],
    ];
    if ($kind === 'student') { unset($want['position']); }

    foreach (array_slice($rows, 0, 5, true) as $i => $row) {
        $map = [];
        foreach ($row as $col => $cell) {
            $key = strtolower(preg_replace('/[^a-z0-9 ]/i', '', (string)$cell));
            $key = trim(preg_replace('/\s+/', ' ', $key));
            if ($key === '') { continue; }
            foreach ($want as $field => $names) {
                if (!isset($map[$field]) && in_array($key, $names, true)) { $map[$field] = $col; }
            }
        }
        /* Two of the three is enough to call it a heading row. One could be a
           person actually called "Name". */
        if (count(array_intersect_key($map, ['id' => 1, 'name' => 1, 'email' => 1])) >= 2) {
            return [$map, $i];
        }
    }

    /* No headings found: fall back to the order the instructions give, and the
       caller says so in the preview rather than silently guessing. */
    $map = ['id' => 0, 'name' => 1, 'email' => 2];
    if ($kind !== 'student') { $map['position'] = 3; }
    return [$map, -1];
}

/**
 * Everything wrong with one row, as sentences.
 *
 * Extracted so the preview and the create pass cannot drift apart. The create
 * pass runs it again: a preview is a moment old by the time anybody presses the
 * button, and in between somebody else may have enrolled the same person.
 */
function account_import_check_row(array $row, string $kind): array {
    $spec = account_import_kind($kind);
    $problems = [];

    /* The same rules the form reads, in the same order, so a file is refused
       for exactly the reasons typing it would be. */
    foreach ([person_name_problem($row['name']),
              $kind === 'student' ? student_number_problem($row['id']) : staff_id_problem($row['id']),
              account_email_problem($row['email'])] as $why) {
        if ($why !== null) { $problems[] = $why; }
    }

    if ($kind !== 'student') {
        $pos = (string)($row['position'] ?? '');
        if ($pos === '') {
            $problems[] = 'No position given, and no default chosen above.';
        } elseif (!in_array($pos, $spec['positions'], true)) {
            $problems[] = 'This console cannot create a "' . $pos . '". Use one of: '
                        . implode(', ', $spec['positions']) . '.';
        }
    }
    return $problems;
}

/**
 * Turn a file into checked rows, ready to show or to create.
 *
 * $defaults carries what the file does not: a student's programme, academic
 * year and section, which are properties of the batch rather than the person,
 * and the fallback position for a staff file whose Position column is missing.
 *
 * Every row comes back whether it is usable or not, each with its own list of
 * problems, because a preview that hides the bad rows is a preview that lies
 * about how many accounts are about to exist.
 */
function account_import_rows(array $sheet, string $kind, array $defaults, mysqli $conn): array {
    $spec = account_import_kind($kind);
    [$map, $headerAt] = account_import_map($sheet, $kind);

    $out      = [];
    $seenId   = [];
    $seenMail = [];
    $n = 0;

    foreach ($sheet as $i => $cells) {
        if ($i <= $headerAt) { continue; }

        $get = static function (string $field) use ($cells, $map): string {
            $col = $map[$field] ?? null;
            return $col === null ? '' : trim((string)($cells[$col] ?? ''));
        };

        $id    = $get('id');
        $name  = normalize_person_name($get('name'));
        $email = strtolower($get('email'));
        $pos   = $kind === 'student' ? '' : ($get('position') ?: (string)($defaults['position'] ?? ''));

        /* A trailing empty row is what every spreadsheet ends with. */
        if ($id === '' && $name === '' && $email === '') { continue; }

        $n++;
        if ($n > ACCOUNT_IMPORT_MAX_ROWS) {
            $out[] = ['over' => true, 'line' => $i + 1];
            break;
        }

        $problems = account_import_check_row(
            ['id' => $id, 'name' => $name, 'email' => $email, 'position' => $pos], $kind);

        /* Twice in one file is as much a clash as a clash with the database,
           and it would otherwise fail halfway through creating. */
        $idKey = strtolower($id);
        if ($idKey !== '' && isset($seenId[$idKey])) {
            $problems[] = 'The same ID appears on line ' . $seenId[$idKey] . ' of this file.';
        }
        if ($email !== '' && isset($seenMail[$email])) {
            $problems[] = 'The same email appears on line ' . $seenMail[$email] . ' of this file.';
        }
        if ($idKey !== '')  { $seenId[$idKey] = $i + 1; }
        if ($email !== '')  { $seenMail[$email] = $i + 1; }

        $existing = account_import_existing($conn, $kind, $id, $email);

        $out[] = [
            'line'     => $i + 1,
            'id'       => $id,
            'name'     => $name,
            'email'    => $email,
            'position' => $pos,
            'password' => $problems ? '' : account_import_password($name, $id),
            'problems' => $problems,
            'exists'   => $existing,
        ];
    }

    return [
        'rows'       => $out,
        'headerless' => $headerAt === -1,
        'kind'       => $kind,
    ];
}

/**
 * Is this person already on file?
 *
 * Returns '' or a sentence saying which part already exists. A row that matches
 * is skipped rather than changed: re-running a file that half failed is then
 * harmless, which is the whole point of saying no here.
 */
function account_import_existing(mysqli $conn, string $kind, string $id, string $email): string {
    $idCol = $kind === 'student' ? 'student_id' : 'faculty_id';

    if ($id !== '') {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE $idCol = ? LIMIT 1");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) { return 'That ID already has an account.'; }
    }
    if ($email !== '') {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) { return 'That email already has an account.'; }
    }
    return '';
}

/** A row the import may actually create: no problems, and nobody there already. */
function account_import_usable(array $row): bool {
    return empty($row['over']) && empty($row['problems']) && ($row['exists'] ?? '') === '';
}

/* ───────────────────────── creating them ────────────────────────────── */

/**
 * Create one account.
 *
 * Returns the message to show for that row. Throws for anything that should
 * stop it, which the caller records against the row and carries on, because one
 * bad row out of forty is not a reason to abandon the other thirty-nine.
 *
 * The student branch mirrors the INSERT in faculty_manage_students.php rather
 * than calling it: that handler is one long block of flash() and redirect, with
 * no function to call. If it is ever extracted, delete this copy and use it.
 */
function account_import_create_one(array $row, string $kind, array $ctx, mysqli $conn, bool $notify, string $password): string {
    if ($kind === 'student') {
        $username = substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $row['id'])), 0, 30);
        if ($username === '') { $username = 'user' . substr(bin2hex(random_bytes(4)), 0, 6); }

        $hash    = password_hash($password, PASSWORD_DEFAULT);
        $expires = student_expiry_date($ctx['section'], $ctx['academic_year']);
        $sid     = $row['id'] !== '' ? $row['id'] : null;

        $stmt = $conn->prepare(
            "INSERT INTO users (username,email,password,full_name,program,academic_year,section,expires_on,student_id,user_role,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,'student',?)");
        $stmt->bind_param('sssssssssi', $username, $row['email'], $hash, $row['name'],
                          $ctx['program'], $ctx['academic_year'], $ctx['section'], $expires,
                          $sid, $ctx['created_by']);
        if (!$stmt->execute()) {
            throw new RuntimeException('The database refused the row.');
        }

        if ($notify) {
            $body  = email_para('Dear ' . $row['name'] . ',');
            $body .= email_para('A student account has been created for you on ' . APP_NAME
                   . ', the research repository of PUP Biñan Campus. Your details are below.');
            $body .= email_details([
                'Student ID' => $row['id'],
                'Programme'  => $ctx['program'],
                'Section'    => $ctx['section'] . ' (A.Y. ' . $ctx['academic_year'] . ')',
                'Password'   => $password,
            ], ['Student ID', 'Password']);
            $body .= email_action('Sign in with your Student ID at', BASE_URL . '/archive/index.php');
            $body .= email_para('Please change your password after signing in for the first time. '
                   . 'If you were not expecting this message, speak to your research adviser.');
            $sent = send_email($row['email'], 'Your Account Credentials', $body);
            return $sent ? 'created, email sent' : 'created, email failed';
        }
        return 'created';
    }

    /* Staff and admins go through the services, so the role each position maps
       to, and the checks around it, stay in one place. */
    $data = [
        'full_name'  => $row['name'],
        'email'      => $row['email'],
        'faculty_id' => $row['id'],
        'password'   => $password,
    ];

    if ($kind === 'faculty') {
        $data['title'] = $row['position'];
        $service = new FacultyManagementService($conn);
        $service->createFaculty($data, $ctx['created_by'], $notify);
    } else {
        $data['position'] = $row['position'];
        $service = new AdminManagementService($conn);
        $service->createAdmin($data, $ctx['created_by'], $notify);
    }
    return $notify ? 'created, email sent' : 'created';
}

/**
 * Create every usable row, and say what happened to each.
 *
 * Not one transaction on purpose. A spreadsheet of forty is forty independent
 * enrolments, and a single bad row rolling back the other thirty-nine would be
 * worse than a partial result that says exactly which ones landed. Re-running
 * the same file afterwards skips what already exists.
 */
function account_import_create(array $rows, string $kind, array $ctx, mysqli $conn, bool $notify): array {
    $made = 0; $skipped = 0; $failed = 0; $log = [];

    foreach ($rows as $row) {
        if (!empty($row['over'])) { continue; }

        /* Checked again from scratch, never trusting what the preview decided.
           The preview is a moment old, the rows have been through a session,
           and in the meantime somebody else may have enrolled the same person. */
        $row['problems'] = account_import_check_row($row, $kind);
        $row['exists']   = account_import_existing($conn, $kind, $row['id'], $row['email']);

        if (!account_import_usable($row)) {
            $skipped++;
            $log[] = ['name' => $row['name'], 'id' => $row['id'], 'ok' => false,
                      'what' => $row['exists'] !== '' ? $row['exists'] : ($row['problems'][0] ?? 'skipped')];
            continue;
        }
        /* Generated here, not inside the create, so it can be reported back: it
           is written nowhere and the email is optional, so the screen that
           follows is the only chance anybody has to write it down. */
        $password = account_import_password($row['name'], $row['id']);
        try {
            $what = account_import_create_one($row, $kind, $ctx, $conn, $notify, $password);
            $made++;
            $log[] = ['name' => $row['name'], 'id' => $row['id'], 'ok' => true,
                      'what' => $what, 'pw' => $password];
        } catch (Throwable $e) {
            $failed++;
            /* A UserFacingException was written for a reader; anything else is
               a fault, and its text is for the log rather than the page. */
            $why = $e instanceof UserFacingException || $e instanceof InvalidArgumentException
                 ? $e->getMessage()
                 : 'could not be created';
            error_log('account import: ' . $row['id'] . ': ' . $e->getMessage());
            $log[] = ['name' => $row['name'], 'id' => $row['id'], 'ok' => false, 'what' => $why];
        }
    }

    return ['created' => $made, 'skipped' => $skipped, 'failed' => $failed, 'log' => $log];
}
