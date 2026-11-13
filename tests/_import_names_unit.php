<?php
/**
 * The name columns a spreadsheet may arrive with.
 *
 * A roll is exported from the registrar, from a Google Form, or from whatever
 * the adviser before kept, so the name comes under a dozen headings and in two
 * orders, sometimes spread over three columns. An account has one full_name,
 * so the preview shows one Name box: this is the list of what has to be read
 * into it, and what must come out.
 *
 * The first block is the BSIT 4-1 roll this was written from: "SURNAME, FIRST
 * NAME" in one column and "MIDDLE NAME" in the next.
 *
 *     php tests/_import_names_unit.php
 */
require_once __DIR__ . '/../config/core.php';
require_once __DIR__ . '/../config/account_import.php';

$fail = 0;
$ran  = 0;

/**
 * One sheet, read the way account_import_rows() reads one: the map from the
 * headings, then each row through account_import_name_from() and
 * normalize_person_name(), which is what reaches the preview.
 */
function names_of(array $sheet): array {
    [$map, $headerAt] = account_import_map($sheet, 'student');
    $out = [];
    foreach ($sheet as $i => $cells) {
        if ($i <= $headerAt) { continue; }
        $out[] = normalize_person_name(account_import_name_from($cells, $map));
    }
    return $out;
}

function want(string $label, $got, $expect, &$fail, &$ran) {
    $ran++;
    if ($got !== $expect) {
        $fail++;
        printf("  FAIL %s\n       got  %s\n       want %s\n", $label,
            var_export($got, true), var_export($expect, true));
    }
}

/* ── the roll as the campus exports it ─────────────────────────────────── */

$sheet = [
    ['STUDENT NUMBER', 'SURNAME, FIRST NAME', 'MIDDLE NAME', 'EMAIL', 'YEAR - SECTION'],
    ['2023-00219-BN-0', 'ABIERA, VINCENT', 'GADIANE', 'a@example.com', 'BSIT 4-1'],
    ['2023-00172-BN-0', 'ALMAZORA, JOSE RYLE ANDRE', 'GUICO', 'b@example.com', 'BSIT 4-1'],
    ['2023-00161-BN-0', 'BERING, CHAR MAE GRACE', '', 'c@example.com', 'BSIT 4-1'],
];
want('surname-first + middle column', names_of($sheet), [
    'Vincent Gadiane Abiera',
    'Jose Ryle Andre Guico Almazora',
    'Char Mae Grace Bering',
], $fail, $ran);

/* The headings the user asked for, each with the same person in it. */
$one = static function (string $heading, string $value): array {
    return names_of([
        ['Student ID', $heading, 'Email'],
        ['2023-00219-BN-0', $value, 'a@example.com'],
    ]);
};

want('Surname, First Name Middle Name',
    $one('Surname, First Name Middle Name', 'ABIERA, VINCENT GADIANE'),
    ['Vincent Gadiane Abiera'], $fail, $ran);

want('Last Name, First Name Middle Name',
    $one('Last Name, First Name Middle Name', 'ABIERA, VINCENT GADIANE'),
    ['Vincent Gadiane Abiera'], $fail, $ran);

want('First Name Middle Name Surname',
    $one('First Name Middle Name Surname', 'VINCENT GADIANE ABIERA'),
    ['Vincent Gadiane Abiera'], $fail, $ran);

want('First Name Middle Name Last Name',
    $one('First Name Middle Name Last Name', 'VINCENT GADIANE ABIERA'),
    ['Vincent Gadiane Abiera'], $fail, $ran);

/* The heading says the order when nobody typed a comma, which is the whole
   reason it is read at all. */
want('surname first, no comma',
    $one('Surname First Name', 'ABIERA VINCENT'),
    ['Vincent Abiera'], $fail, $ran);

/* ...and the typing wins over the heading where the two disagree. */
want('given-first heading, comma typed',
    $one('First Name Last Name', 'ABIERA, VINCENT'),
    ['Vincent Abiera'], $fail, $ran);

/* ── a column each ─────────────────────────────────────────────────────── */

want('three columns of their own', names_of([
    ['Student ID', 'First Name', 'Middle Name', 'Last Name', 'Email'],
    ['2023-00219-BN-0', 'VINCENT', 'GADIANE', 'ABIERA', 'a@example.com'],
    ['2023-00161-BN-0', 'CHAR MAE GRACE', '', 'BERING', 'c@example.com'],
]), ['Vincent Gadiane Abiera', 'Char Mae Grace Bering'], $fail, $ran);

want('first and last only', names_of([
    ['Student ID', 'Given Name', 'Surname', 'Email'],
    ['2023-00219-BN-0', 'Vincent', 'Abiera', 'a@example.com'],
]), ['Vincent Abiera'], $fail, $ran);

want('a Last Name column beside a given-name one', names_of([
    ['Student ID', 'First Name Middle Name', 'Last Name', 'Email'],
    ['2023-00219-BN-0', 'VINCENT GADIANE', 'ABIERA', 'a@example.com'],
]), ['Vincent Gadiane Abiera'], $fail, $ran);

want('M.I. as its own column', names_of([
    ['Student ID', 'Surname, First Name', 'M.I.', 'Email'],
    ['2023-00219-BN-0', 'ABIERA, VINCENT', 'G.', 'a@example.com'],
]), ['Vincent G. Abiera'], $fail, $ran);

/* ── what was already accepted must go on being accepted ───────────────── */

want('one Full Name column', names_of([
    ['Student ID', 'Full Name', 'Email'],
    ['2023-00219-BN-0', 'Vincent Gadiane Abiera', 'a@example.com'],
]), ['Vincent Gadiane Abiera'], $fail, $ran);

want('Full Name written surname first', names_of([
    ['Student ID', 'Full Name', 'Email'],
    ['2023-00219-BN-0', 'Reyes, Rayver S.', 'a@example.com'],
]), ['Rayver S. Reyes'], $fail, $ran);

want('no headings at all', names_of([
    ['2023-00219-BN-0', 'Vincent Gadiane Abiera', 'a@example.com'],
]), ['Vincent Gadiane Abiera'], $fail, $ran);

/* ── the surname is not always one word ────────────────────────────────── */

want('a particle, surname first', names_of([
    ['Student ID', 'Surname, First Name', 'Middle Name', 'Email'],
    ['2023-00219-BN-0', 'DELA CRUZ, JUAN', 'SANTOS', 'a@example.com'],
]), ['Juan Santos Dela Cruz'], $fail, $ran);

want('a particle, given first', names_of([
    ['Student ID', 'First Name Last Name', 'Middle Name', 'Email'],
    ['2023-00219-BN-0', 'JUAN DELA CRUZ', 'SANTOS', 'a@example.com'],
]), ['Juan Santos Dela Cruz'], $fail, $ran);

want('a suffix, given first', names_of([
    ['Student ID', 'First Name Last Name', 'Middle Name', 'Email'],
    ['2023-00219-BN-0', 'Rayver Reyes Jr.', 'Santos', 'a@example.com'],
]), ['Rayver Santos Reyes Jr.'], $fail, $ran);

want('a suffix written last, surname first', names_of([
    ['Student ID', 'Surname, First Name', 'Email'],
    ['2023-00219-BN-0', 'Reyes, Rayver S., Jr.', 'a@example.com'],
]), ['Rayver S. Reyes Jr.'], $fail, $ran);

/* ── the headings themselves ───────────────────────────────────────────── */

$heads = [
    'surname first name middle name' => ['name', ['last', 'first', 'middle']],
    'last name first name middle name' => ['name', ['last', 'first', 'middle']],
    'first name middle name surname' => ['name', ['first', 'middle', 'last']],
    'first name middle name last name' => ['name', ['first', 'middle', 'last']],
    'surname first name'             => ['name', ['last', 'first']],
    'last name'                      => ['last', ['last']],
    'surname'                        => ['last', ['last']],
    'family name'                    => ['last', ['last']],
    'first name'                     => ['first', ['first']],
    'given name'                     => ['first', ['first']],
    'middle name'                    => ['middle', ['middle']],
    'middle initial'                 => ['middle', ['middle']],
    'mi'                             => ['middle', ['middle']],
];
foreach ($heads as $key => $expect) {
    $got = account_import_name_header($key);
    want('heading "' . $key . '"', $got, ['field' => $expect[0], 'parts' => $expect[1]], $fail, $ran);
}

/* Headings that say nothing about a name, and must go on saying nothing: the
   name reader runs before the list of spellings, so a false positive here
   would take a column away from the field that wanted it. */
foreach (['name', 'full name', 'complete name', 'student name', 'email', 'e-mail',
          'student number', 'faculty id', 'year section', 'position'] as $key) {
    want('heading "' . $key . '" is not a name part',
        account_import_name_header($key), null, $fail, $ran);
}

printf("%s  %d checks, %d failed\n", $fail ? 'FAIL' : 'ok  ', $ran, $fail);
exit($fail ? 1 : 0);
