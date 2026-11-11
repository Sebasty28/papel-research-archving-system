<?php
/**
 * What the three management consoles must take and must refuse.
 *
 * Every acceptance is a value really on file (the registrar's student numbers,
 * the staff IDs the campus has issued, the sections and academic years in the
 * roll) and every refusal is something somebody typed into one of these boxes:
 * the point of the file is that tightening a rule cannot quietly start
 * refusing a real account, and loosening one cannot let the mashing back in.
 *
 * The same rules are stated for the browser in includes/manage_validate.php.
 *
 *     php tests/_account_rules_unit.php
 */
require_once __DIR__ . '/../config/core.php';

$fail = 0;
function check($label, $got, $want, &$fail) {
    $ok = ($want === 'ok') ? ($got === null) : ($got !== null);
    if (!$ok) {
        $fail++;
        printf("  FAIL %-22s %-34s -> %s\n", $label, '', $got === null ? 'accepted' : $got);
    }
    return $ok;
}

$cases = [
    // name,                     value,                      ok?
    ['name', 'Juan Dela Cruz',                 'ok'],
    ['name', 'Dela Cruz-Santos',               'ok'],
    ['name', "O'Brien",                        'ok'],
    ['name', 'Dr. Maria Santos',               'ok'],
    ['name', 'Peña',                           'ok'],
    ['name', 'Rayver S. Reyes',                'ok'],
    ['name', 'adasdadasd',                     'no'],
    ['name', '232543534',                      'no'],
    ['name', 'asdfghjk',                       'no'],
    ['name', 'Juan2',                          'no'],
    ['name', 'J',                              'no'],
    ['name', '',                               'no'],
    ['name', 'Juan <b>Cruz</b>',               'no'],

    ['sid', '2026-00001-BN-0',                 'ok'],
    ['sid', '2023-00053-BN-0',                 'ok'],
    ['sid', '202300053',                       'ok'],
    ['sid', 'jfjksdfksjfjksdbfs',              'no'],
    ['sid', '989743&^%$2743',                  'no'],
    ['sid', '2026-00001-BN-0-',                'no'],
    ['sid', '2026--00001',                     'no'],
    ['sid', '12345',                           'no'],
    ['sid', 'BN-2026-00001',                   'no'],
    ['sid', '2026 00001',                      'no'],
    ['sid', '',                                'no'],

    ['staff', 'FAC-2026-001',                  'ok'],
    ['staff', 'FC-00123',                      'ok'],
    ['staff', 'HAP-00123',                     'ok'],
    ['staff', 'DIR-2026-001',                  'ok'],
    ['staff', 'LIB-00123',                     'ok'],
    ['staff', 'COORDINATOR-01',                'ok'],
    ['staff', 'jfjksdfksjfjksdbfs',            'no'],
    ['staff', 'FAC 2026 001',                  'no'],
    ['staff', 'FACULTY',                       'no'],
    ['staff', 'FAC-',                          'no'],
    ['staff', '',                              'no'],

    ['email', 'student@example.com',           'ok'],
    ['email', 'juan.dela.cruz@pup.edu.ph',     'ok'],
    ['email', 'a@b.co',                        'ok'],
    ['email', 'fsjdfhsjfhjsd@gmail',           'no'],
    ['email', 'student@example',               'no'],
    ['email', 'student.example.com',           'no'],
    ['email', 'student@',                      'no'],
    ['email', '',                              'no'],

    ['ay', '26-27',                            'ok'],
    ['ay', '24-25',                            'ok'],
    ['ay', '2026-2027',                        'ok'],
    ['ay', '26/27',                            'ok'],
    ['ay', '99-00',                            'ok'],
    ['ay', '26-29',                            'no'],
    ['ay', '2627',                             'no'],
    ['ay', 'asdasd',                           'no'],
    ['ay', '',                                 'no'],

    ['section', '4-1',                         'ok'],
    ['section', '1-1',                         'ok'],
    ['section', '4A',                          'ok'],
    ['section', '4 - 1',                       'ok'],
    ['section', 'Ladderized',                  'ok'],
    ['section', 'ladderized',                  'ok'],
    ['section', '5-1',                         'no'],
    ['section', 'asdasd',                      'no'],
    ['section', '41',                          'no'],
    ['section', '',                            'no'],
];

$run = ['name' => 'person_name_problem', 'sid' => 'student_number_problem',
        'email' => 'account_email_problem', 'ay' => 'academic_year_problem',
        'section' => 'section_problem'];

foreach ($cases as [$kind, $value, $want]) {
    $got = $kind === 'staff'
        ? staff_id_problem($value, 'Faculty ID')
        : $run[$kind]($value);
    $ok = ($want === 'ok') ? ($got === null) : ($got !== null);
    if (!$ok) {
        $fail++;
        printf("FAIL  %-8s %-26s wanted %s, got %s\n", $kind, '"' . $value . '"', $want,
               $got === null ? 'accepted' : '"' . $got . '"');
    }
}

/* The section rule exists to keep student_expiry_date() answerable, so the
   accepted shapes must all produce a date. */
foreach (['4-1', '1-1', '4A', '4 - 1', 'Ladderized'] as $sec) {
    if (student_expiry_date($sec, '26-27') === null) {
        $fail++;
        echo "FAIL  section \"$sec\" is accepted but gives no expiry date\n";
    }
}
/* And a student number the registrar writes must still be accepted by the
   older wrapper the create form calls. */
$v = validate_student_id('2026-00001-BN-0');
if (!$v['valid']) { $fail++; echo "FAIL  validate_student_id rejects a real student number\n"; }

echo $fail === 0
    ? sprintf("All %d cases pass.\n", count($cases) + 6)
    : sprintf("%d FAILED\n", $fail);
exit($fail === 0 ? 0 : 1);
