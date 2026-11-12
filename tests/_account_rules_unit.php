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

    /* FA 0000 BN 2026, the shape every staff ID issued from now on is written
       in. The old shapes are no longer issued and are refused here; what keeps
       the accounts already carrying one editable is $keeping, below. */
    ['staff', 'FA0000BN2026',                  'ok'],
    ['staff', 'FA0001BN2026',                  'ok'],
    ['staff', 'FA9999BN2000',                  'ok'],
    ['staff', 'fa0001bn2026',                  'ok'],   // a code, so the case is cleaned rather than refused
    ['staff', 'FAC-2026-001',                  'no'],
    ['staff', 'FC-00123',                      'no'],
    ['staff', 'COORDINATOR-01',                'no'],
    ['staff', 'FA-0001-BN-2026',               'no'],   // the new shape has no dashes in it
    ['staff', 'FA001BN2026',                   'no'],   // three digits where four are wanted
    ['staff', 'FA00001BN2026',                 'no'],   // five
    ['staff', 'FA0001BN26',                    'no'],   // a two-digit year
    ['staff', 'FA0001QC2026',                  'no'],   // another campus
    ['staff', 'RC0001BN2026',                  'no'],   // another prefix
    ['staff', 'FA0001BN9999',                  'no'],   // a slipped digit in the year
    ['staff', 'FA0001BN1026',                  'no'],
    ['staff', 'FA0001BN2026X',                 'no'],
    ['staff', 'jfjksdfksjfjksdbfs',            'no'],
    ['staff', 'FA 0001 BN 2026',               'no'],
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

/* An ID the account already carries is accepted as it stands, whatever shape
   it is in. These six are the staff IDs really on file, issued before the
   format was settled; without this nobody could correct such an account's
   email without reissuing its ID at the same time. */
foreach (['FAC-2026-001', 'FC-00123', 'HAP-00123',
          'DIR-2026-001', 'LIB-00123', 'COORDINATOR-01'] as $old) {
    if (staff_id_problem($old, 'Faculty ID', $old) !== null) {
        $fail++;
        echo "FAIL  staff \"$old\" is on file and must still be accepted unchanged\n";
    }
}
/* The licence is for that one ID and no other: changing it means the new
   shape, and a posted value cannot borrow another account's exemption. */
if (staff_id_problem('FC-00124', 'Faculty ID', 'FC-00123') === null) {
    $fail++;
    echo "FAIL  staff an old-shape ID the account never had must be refused\n";
}
if (staff_id_problem('FA0001BN2026', 'Faculty ID', 'FC-00123') !== null) {
    $fail++;
    echo "FAIL  staff moving an old account to the new shape must be allowed\n";
}

/* Surname first, as a registrar's roll writes it. The pair is what the three
   consoles really do: normalize_person_name() and then person_name_problem(),
   so a comma survives to the rule only in a shape the turn-round does not
   recognise, and is refused there in words. */
foreach ([['Reyes, Rayver S.',      'Rayver S. Reyes'],
          ['Dela Cruz, Juan',       'Juan Dela Cruz'],
          ['REYES, RAYVER S.',      'Rayver S. Reyes'],   // turned round and re-cased
          ['Reyes Jr., Rayver S.',  'Rayver S. Reyes Jr.'],
          ['Reyes, Rayver S., Jr.', 'Rayver S. Reyes Jr.'],
          ['Reyes ,  Rayver S.',    'Rayver S. Reyes'],
          ['Rayver S. Reyes',       'Rayver S. Reyes'],   // nothing to turn round
          ['Reyes,',                'Reyes,'],            // halfway through being typed
          ['A, B, C',               'A, B, C']] as [$in, $want]) {
    $got = normalize_person_name($in);
    if ($got !== $want) {
        $fail++;
        printf("FAIL  name  \"%s\" -> \"%s\", wanted \"%s\"\n", $in, $got, $want);
    }
}
/* A comma left standing is not a name, whichever way it got there. */
foreach (['Reyes,', 'A, B, C'] as $bad) {
    if (person_name_problem(normalize_person_name($bad)) === null) {
        $fail++;
        echo "FAIL  name  \"$bad\" is not a name and must be refused\n";
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
    ? sprintf("All %d cases pass.\n", count($cases) + 6 + 8 + 11)
    : sprintf("%d FAILED\n", $fail);
exit($fail === 0 ? 0 : 1);
