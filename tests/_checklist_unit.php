<?php
/**
 * Unit tests for the review-checklist helpers in config/core.php.
 *
 *     php tests/_checklist_unit.php
 *
 * One line per case, "PASS name" or "FAIL name: detail", then a last line
 * "SUMMARY passed=N failed=N". tests/m14_review_checklist.py runs this and reads
 * those lines, so the format is part of the contract.
 *
 * Nothing here touches the database or the network: the helpers under test are
 * pure functions of what they are given. Saving and loading a row is checked
 * against a real database by m14, where it can be seen to work.
 */
require_once __DIR__ . '/../config/core.php';

$passed = 0;
$failed = 0;

function t(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $name\n"; }
    else     { $failed++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

function show($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

/** Whether calling $fn raises a UserFacingException, and its message if so. */
function refused(callable $fn): ?string {
    try { $fn(); } catch (UserFacingException $e) { return $e->getMessage(); }
    return null;
}

/** A posted list: labels in order, and the positions that were ticked. */
function post(string $template, array $labels, array $ticked = []): array {
    $p = ['ck_template' => $template, 'ck_label' => $labels];
    foreach ($ticked as $i) $p['ck_ok'][$i] = '1';
    return $p;
}

/* ---- the lists ---------------------------------------------------------- */
$tpl = paper_checklist_templates();
t('lists: keys and order', array_keys($tpl) === ['imrad', 'undergrad_thesis', 'capstone', 'feasibility', 'blank'], show(array_keys($tpl)));
t('lists: "Full Manuscript" is not a list any more', !isset($tpl['full']) && !isset($tpl['full_manuscript']));
$labels = array_column($tpl, 'label');
t('lists: no label says Full Manuscript', !preg_grep('/full manuscript/i', $labels), show($labels));
t('lists: IMRaD has its five', $tpl['imrad']['items'] === ['Introduction', 'Methodology', 'Results', 'Discussion', 'References']);
t('lists: thesis and capstone share the six chapters',
    $tpl['undergrad_thesis']['items'] === $tpl['capstone']['items'] && count($tpl['capstone']['items']) === 6
    && $tpl['capstone']['items'][5] === 'References');
t('lists: feasibility has an outline', count($tpl['feasibility']['items']) >= 5);
t('lists: blank is empty', $tpl['blank']['items'] === []);
$ok = true; $why = '';
foreach ($tpl as $key => $one) {
    $lower = array_map('mb_strtolower', $one['items']);
    if (count($lower) !== count(array_unique($lower))) { $ok = false; $why = "$key repeats a section"; }
    if (count($one['items']) > PAPER_CHECKLIST_MAX_ITEMS) { $ok = false; $why = "$key is over the limit"; }
    foreach ($one['items'] as $label) {
        if ($label !== paper_checklist_label_clean($label)) { $ok = false; $why = "$key: '$label' is not clean"; }
    }
}
t('lists: every section is unique, within the limits and already clean', $ok, $why);

$legacy = paper_checklist_legacy_columns();
t('legacy: eleven columns in all', count($legacy['imrad']) + count($legacy['chapters']) === 11);
t('legacy: the IMRaD columns are the IMRaD list, in order', array_values($legacy['imrad']) === $tpl['imrad']['items']);
t('legacy: the chapter columns are the chapters list, in order', array_values($legacy['chapters']) === $tpl['capstone']['items']);
t('legacy: column names are the ones the table has',
    array_keys($legacy['imrad']) === ['imrad_intro', 'imrad_method', 'imrad_result', 'imrad_discussion', 'imrad_references']
    && array_keys($legacy['chapters']) === ['full_ch1', 'full_ch2', 'full_ch3', 'full_ch4', 'full_ch5', 'full_references']);
t('family: imrad', paper_checklist_family('imrad') === 'imrad');
t('family: thesis and capstone are chapters', paper_checklist_family('undergrad_thesis') === 'chapters' && paper_checklist_family('capstone') === 'chapters');
t('family: feasibility, blank and nonsense fit no old column',
    paper_checklist_family('feasibility') === null && paper_checklist_family('blank') === null && paper_checklist_family('x') === null && paper_checklist_family(null) === null);

/* ---- which list a paper starts with ------------------------------------- */
$rows = [
    // [manuscript_type, paper_type, expected]
    ['IMRAD',                  'project',          'feasibility'],
    ['Full Manuscript',        'project',          'feasibility'],
    ['IMRAD, Full Manuscript', 'project',          'feasibility'],
    ['Full Manuscript',        'PROJECT',          'feasibility'],
    ['IMRAD',                  'capstone',         'imrad'],
    ['Full Manuscript',        'capstone',         'capstone'],
    ['IMRAD, Full Manuscript', 'capstone',         'capstone'],
    ['',                       'capstone',         'capstone'],
    [null,                     'capstone',         'capstone'],
    ['IMRAD',                  'undergrad_thesis', 'imrad'],
    ['Full Manuscript',        'undergrad_thesis', 'undergrad_thesis'],
    ['IMRAD, Full Manuscript', 'undergrad_thesis', 'undergrad_thesis'],
    [null,                     'undergrad_thesis', 'undergrad_thesis'],
    ['IMRAD',                  'thesis',           'imrad'],
    ['Full Manuscript',        'thesis',           'undergrad_thesis'],
    ['Full Manuscript',        'research',         'undergrad_thesis'],
    ['Full Manuscript',        'article',          'undergrad_thesis'],
    ['IMRAD',                  'journal',          'imrad'],
    ['Full Manuscript',        'journal',          'imrad'],
    ['Full Manuscript',        'conference',       'imrad'],
    ['IMRAD',                  'conference',       'imrad'],
    [null,                     null,               'undergrad_thesis'],
    ['Something Else',         '',                 'undergrad_thesis'],
];
foreach ($rows as [$format, $type, $want]) {
    $got = paper_checklist_default($format, $type);
    t('default: ' . show($format) . ' + ' . show($type) . ' -> ' . $want, $got === $want, "got $got");
}
$ok = true;
foreach ($rows as [$format, $type, $want]) { if (!isset($tpl[paper_checklist_default($format, $type)])) $ok = false; }
t('default: always a list that exists', $ok);

t('chapters label: capstone', paper_checklist_chapters_label('capstone') === 'Capstone Project Sections');
t('chapters label: thesis, including the retired names',
    paper_checklist_chapters_label('undergrad_thesis') === 'Undergraduate Thesis Sections'
    && paper_checklist_chapters_label('thesis') === 'Undergraduate Thesis Sections'
    && paper_checklist_chapters_label('research') === 'Undergraduate Thesis Sections');
t('chapters label: anything else is just Chapters',
    paper_checklist_chapters_label('journal') === 'Chapters' && paper_checklist_chapters_label(null) === 'Chapters');

/* ---- the paper types a student can choose ------------------------------- */
t('types: the dropdown offers a capstone, a thesis and a feasibility study',
    array_keys(paper_types()) === ['capstone', 'undergrad_thesis', 'project'], show(array_keys(paper_types())));
t('types: a journal article and a conference paper are no longer offered',
    !isset(paper_types()['journal']) && !isset(paper_types()['conference']));
t('types: but papers already filed under them keep their names',
    paper_type_label('journal') === 'Journal Article' && paper_type_label('conference') === 'Conference Paper');
t('types: and the retired ones are not on the offered list twice',
    !array_intersect_key(paper_types(), paper_types_retired()));

/* ---- one section name --------------------------------------------------- */
t('label: plain stays', paper_checklist_label_clean('Introduction') === 'Introduction');
t('label: spaces trimmed and collapsed', paper_checklist_label_clean("  Intro \t  duction  ") === 'Intro duction');
t('label: tags removed', paper_checklist_label_clean('<b>Bold</b> title') === 'Bold title', paper_checklist_label_clean('<b>Bold</b> title'));
t('label: a script tag leaves no tag', strpos(paper_checklist_label_clean('<script>alert(1)</script>Scope'), '<') === false);
// strip_tags drops a NUL outright (so the two halves join), every other control character becomes a space.
t('label: newlines and control characters do not survive', paper_checklist_label_clean("a\nb\x07c\x00d") === 'a b cd', show(paper_checklist_label_clean("a\nb\x07c\x00d")));
t('label: none of them is left in what is kept', !preg_match('/[\x00-\x1F\x7F]/', paper_checklist_label_clean("x\x01\x02\x1F\x7Fy\tz\r\n")));
t('label: cut at the limit', mb_strlen(paper_checklist_label_clean(str_repeat('x', 500))) === PAPER_CHECKLIST_MAX_LABEL);
t('label: cut by characters, not bytes', mb_strlen(paper_checklist_label_clean(str_repeat("\u{E9}", 200))) === PAPER_CHECKLIST_MAX_LABEL);
t('label: no trailing space after a cut', substr(paper_checklist_label_clean(str_repeat('a', 79) . ' b'), -1) !== ' ');
t('label: only white space is nothing', paper_checklist_label_clean("   \t ") === '');
t('label: an array is nothing', paper_checklist_label_clean(['x']) === '');
t('label: null is nothing', paper_checklist_label_clean(null) === '');
t('label: other scripts survive', paper_checklist_label_clean('Pananaliksik at Metodolohiya') === 'Pananaliksik at Metodolohiya');
t('label: broken UTF-8 is nothing, not an error', paper_checklist_label_clean("bad \xC3\x28 bytes") === '');

/* ---- what a reviewer posted --------------------------------------------- */
$imrad = $tpl['imrad']['items'];

$c = paper_checklist_clean(post('imrad', $imrad, [0, 2]));
t('clean: a ready-made list as it stands', $c['template'] === 'imrad' && $c['edited'] === false && count($c['items']) === 5, show($c));
t('clean: ticks follow the positions', array_column($c['items'], 'ok') === [true, false, true, false, false], show(array_column($c['items'], 'ok')));

$c = paper_checklist_clean(post('imrad', ['Introduction', 'Methodology', 'Findings', 'Discussion', 'References']));
t('clean: a renamed section is an edit', $c['edited'] === true && $c['items'][2]['label'] === 'Findings');
$c = paper_checklist_clean(post('imrad', array_merge($imrad, ['Appendix A'])));
t('clean: an added section is an edit and is kept', $c['edited'] === true && count($c['items']) === 6 && $c['items'][5]['label'] === 'Appendix A');
$c = paper_checklist_clean(post('imrad', array_slice($imrad, 0, 4)));
t('clean: a removed section is an edit', $c['edited'] === true && count($c['items']) === 4);
$c = paper_checklist_clean(post('imrad', array_reverse($imrad)));
t('clean: the same sections in another order are an edit too', $c['edited'] === true && $c['items'][0]['label'] === 'References');

$c = paper_checklist_clean(post('feasibility', $tpl['feasibility']['items'], [1]));
t('clean: another ready-made list', $c['template'] === 'feasibility' && $c['edited'] === false && $c['items'][1]['ok'] === true);

$c = paper_checklist_clean(post('blank', ['Scope', 'Budget']));
t('clean: a blank list is always custom', $c['template'] === 'blank' && $c['edited'] === true && count($c['items']) === 2);
t('clean: a list key nobody offered becomes blank', paper_checklist_clean(post('nonsense', ['A']))['template'] === 'blank');
t('clean: a missing list key becomes blank', paper_checklist_clean(['ck_label' => ['A']])['template'] === 'blank');
t('clean: <script> as a list key is not echoed back', paper_checklist_clean(post('<script>', ['A']))['template'] === 'blank');

$c = paper_checklist_clean(post('blank', ['Scope', 'scope', ' SCOPE ', 'Budget'], [0, 1]));
t('clean: the same section twice is one, case aside, keeping the first', count($c['items']) === 2 && $c['items'][0]['label'] === 'Scope' && $c['items'][0]['ok'] === true, show($c['items']));
$c = paper_checklist_clean(post('blank', ['  ', 'Scope', "\t"], [0, 1, 2]));
t('clean: empty names are dropped, and take their ticks with them', count($c['items']) === 1 && $c['items'][0]['ok'] === true);

$p = post('imrad', $imrad); $p['ck_ok'] = ['0' => '0', '1' => 'on', '2' => '1'];
$c = paper_checklist_clean($p);
t('clean: only a ticked value ticks', array_column($c['items'], 'ok') === [false, true, true, false, false], show(array_column($c['items'], 'ok')));

$p = ['ck_template' => 'blank', 'ck_label' => [5 => 'First', 2 => 'Second', 9 => 'Third'], 'ck_ok' => [2 => '1']];
$c = paper_checklist_clean($p);
t('clean: order and ticks follow the keys as posted, gaps and all',
    array_column($c['items'], 'label') === ['First', 'Second', 'Third'] && array_column($c['items'], 'ok') === [false, true, false], show($c['items']));

$p = post('blank', ['Keep']); $p['ck_label'][1] = ['nested', 'array'];
t('clean: a nested array in a label slot is skipped', count(paper_checklist_clean($p)['items']) === 1);

$n = PAPER_CHECKLIST_MAX_ITEMS;
$many = []; for ($i = 1; $i <= $n; $i++) $many[] = "Section $i";
t('clean: the limit itself is allowed', count(paper_checklist_clean(post('blank', $many))['items']) === $n);
$many[] = 'One too many';
$msg = refused(function () use ($many) { paper_checklist_clean(post('blank', $many)); });
t('clean: one past the limit is refused in words', $msg !== null && strpos($msg, (string)$n) !== false, (string)$msg);
$flood = []; for ($i = 0; $i < 5000; $i++) $flood[] = "S$i";
t('clean: a flood of fields is refused', refused(function () use ($flood) { paper_checklist_clean(post('blank', $flood)); }) !== null);
t('clean: nothing left is refused in words',
    ($m = refused(function () { paper_checklist_clean(post('blank', ['  ', ''])); })) !== null && stripos($m, 'at least one') !== false, (string)$m);
t('clean: an empty label list is refused', refused(function () { paper_checklist_clean(['ck_template' => 'imrad', 'ck_label' => []]); }) !== null);

$c = paper_checklist_clean(post('blank', [str_repeat('x', 9999)]));
t('clean: a huge label is cut, not refused', mb_strlen($c['items'][0]['label']) === PAPER_CHECKLIST_MAX_LABEL);
$c = paper_checklist_clean(post('blank', ['<img src=x onerror=alert(1)>Scope']));
t('clean: markup in a label does not survive', strpos($c['items'][0]['label'], '<') === false && $c['items'][0]['label'] === 'Scope', show($c['items']));

/* the old form's eleven fields */
$c = paper_checklist_clean(['imrad_intro' => 'on', 'imrad_result' => '1', 'full_ch3' => '1']);
t('legacy post: read as eleven flags', isset($c['legacy']) && count($c['legacy']) === 11, show($c));
t('legacy post: posted fields are 1, the rest 0',
    $c['legacy']['imrad_intro'] === 1 && $c['legacy']['imrad_result'] === 1 && $c['legacy']['full_ch3'] === 1
    && $c['legacy']['imrad_method'] === 0 && $c['legacy']['full_references'] === 0, show($c['legacy']));
t('legacy post: nothing posted is eleven zeros', array_sum(paper_checklist_clean([])['legacy']) === 0);
t('legacy post: a label list that is not an array falls back to it', isset(paper_checklist_clean(['ck_label' => 'x'])['legacy']));

/* ---- a list on a server that has no items_json -------------------------- */
$flags = paper_checklist_flags_from_list(paper_checklist_clean(post('imrad', $imrad, [0, 4])));
t('old columns: an IMRaD list maps by position', $flags['imrad_intro'] === 1 && $flags['imrad_references'] === 1 && $flags['imrad_method'] === 0 && $flags['full_ch1'] === 0 && count($flags) === 11, show($flags));
$flags = paper_checklist_flags_from_list(paper_checklist_clean(post('capstone', $tpl['capstone']['items'], [1, 5])));
t('old columns: a capstone list maps onto the chapter columns', $flags['full_ch2'] === 1 && $flags['full_references'] === 1 && $flags['full_ch1'] === 0 && $flags['imrad_intro'] === 0, show($flags));
$flags = paper_checklist_flags_from_list(paper_checklist_clean(post('undergrad_thesis', $tpl['undergrad_thesis']['items'], [0])));
t('old columns: so does a thesis list', $flags['full_ch1'] === 1);
t('old columns: an edited list is refused in words',
    ($m = refused(function () use ($imrad) { paper_checklist_flags_from_list(paper_checklist_clean(post('imrad', array_merge($imrad, ['Extra'])))); })) !== null && stripos($m, 'database update') !== false, (string)$m);
t('old columns: a list they cannot hold is refused', refused(function () use ($tpl) { paper_checklist_flags_from_list(paper_checklist_clean(post('feasibility', $tpl['feasibility']['items']))); }) !== null);
t('old columns: a blank list is refused', refused(function () { paper_checklist_flags_from_list(paper_checklist_clean(post('blank', ['A']))); }) !== null);

/* ---- where the editor starts -------------------------------------------- */
$s = paper_checklist_editor_state(null, 'capstone');
t('editor: no record starts on the default, nothing ticked',
    $s['template'] === 'capstone' && array_column($s['items'], 'label') === $tpl['capstone']['items'] && !array_filter(array_column($s['items'], 'ok')));
$s = paper_checklist_editor_state(null, 'not-a-list');
t('editor: an unknown default falls back to IMRaD', $s['template'] === 'imrad');

$oldChapters = ['groups' => [['key' => 'chapters', 'label' => 'x', 'items' => paper_checklist_legacy_items(['full_ch1' => 1, 'full_ch2' => 0, 'full_ch3' => 1, 'full_references' => 1], $legacy['chapters'])]],
                'edited' => false, 'legacy' => true, 'template' => null, 'recorded_at' => null];
$s = paper_checklist_editor_state($oldChapters, 'capstone');
t('editor: an old chapters row carries its ticks onto the chapters list', array_column($s['items'], 'ok') === [true, false, true, false, false, true], show(array_column($s['items'], 'ok')));
$s = paper_checklist_editor_state($oldChapters, 'imrad');
t('editor: and none onto IMRaD, which it never held', !array_filter(array_column($s['items'], 'ok')));

$saved = ['groups' => [['key' => 'feasibility', 'label' => 'x', 'items' => [['label' => 'Executive Summary', 'ok' => true], ['label' => 'Risks', 'ok' => false]]]],
          'edited' => true, 'legacy' => false, 'template' => 'feasibility', 'recorded_at' => null];
$s = paper_checklist_editor_state($saved, 'imrad');
t('editor: a list recorded before wins over the default', $s['template'] === 'feasibility' && count($s['items']) === 2 && $s['items'][0]['ok'] === true);

/* ---- what is stored reads back ----------------------------------------- */
$clean = paper_checklist_clean(post('feasibility', array_merge($tpl['feasibility']['items'], ['Pananaliksik / "quotes" & \u{E9}']), [0, 8]));
$json = json_encode(['v' => 1, 'template' => $clean['template'], 'edited' => $clean['edited'], 'items' => $clean['items']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$back = json_decode($json, true);
t('round trip: what is stored decodes to what was cleaned', $back['items'] === $clean['items'] && $back['template'] === 'feasibility' && $back['edited'] === true);
t('round trip: nothing in the JSON is more than the fields it names', array_keys($back) === ['v', 'template', 'edited', 'items']);

/* ---- Other, for a paper type and a manuscript type ------------------------ */
t('other type: a typed paper type is kept with its marker, cleaned',
    paper_type_from_post('other', "  <b>Case</b>\t Study ", true) === 'other:Case Study', show(paper_type_from_post('other', "  <b>Case</b>\t Study ", true)));
t('other type: and read back as the name alone',
    paper_type_label('other:Case Study') === 'Case Study' && paper_type_other_name('other:Case Study') === 'Case Study');
t('other type: a name is cut to the column, marker included',
    strlen(paper_type_from_post('other', str_repeat('Policybrief', 50), true)) <= 50);
/* The 500 x's this case used to send are now refused outright (other_name_problem()), so
   the cut is shown with a long name that is a name; the refusal has a case of its own. */
t('other type: mashing, a greeting and a sentence are refused on submit; a name is kept',
    (function () {
        $refused = function ($name) {
            try { paper_type_from_post('other', $name, true); return false; }
            catch (UserFacingException $e) { return true; }
        };
        return $refused('sdfsdfdsfsd') && $refused(str_repeat('x', 500)) && $refused('hello')
            && $refused('A study of the uses of bamboo trays')
            && paper_type_from_post('other', 'Case Study', true) === 'other:Case Study';
    })());
t('other type: Other with nothing typed is refused on submit, in words',
    (function () { try { paper_type_from_post('other', '   ', true); return false; } catch (UserFacingException $e) { return strpos($e->getMessage(), 'beside Other') !== false; } })());
t('other type: but a draft keeps it, name to come',
    paper_type_from_post('other', '', false) === 'other:' && paper_type_label('other:') === 'Other');
t('other type: an offered type is kept as its code; a retired or unknown one is refused on submit',
    paper_type_from_post('project', 'ignored', true) === 'project' && paper_type_from_post('journal', '', true) === null
    && paper_type_from_post('<script>', '', true) === null && paper_type_from_post(['x'], '', true) === null);
t('other type: the supporting documents stay optional',
    paper_type_needs_documents('other:Case Study') === false && paper_type_needs_documents('capstone') === true);
t('other type: the filter offers the form\'s types and then Other',
    array_keys(paper_type_filter_options()) === ['capstone', 'undergrad_thesis', 'project', 'other']);
$fp = []; $ft = '';
$frag = paper_type_filter_sql('rp.paper_type', 'other', $fp, $ft);
t('other type: filtering on Other matches every typed one',
    $frag === 'rp.paper_type LIKE ?' && $fp === ['other:%'] && $ft === 's', show([$frag, $fp, $ft]));
$fp = []; $ft = '';
t('other type: any other filter value is matched exactly',
    paper_type_filter_sql('rp.paper_type', 'capstone', $fp, $ft) === 'rp.paper_type = ?' && $fp === ['capstone']);
t('other type: a name that looks like a code is still only a name',
    paper_type_label('other:capstone') === 'capstone' && paper_type_label('capstone') === 'Capstone Project');

t('other manuscript: Other is stored with its name',
    manuscript_types_posted(['Other'], 'Policy Brief') === 'Other: Policy Brief');
t('other manuscript: and stands alone, whatever else was ticked',
    manuscript_types_posted(['IMRAD', 'Other', 'Full Manuscript'], 'Policy Brief') === 'Other: Policy Brief');
t('other manuscript: a comma cannot split the stored list',
    manuscript_types_posted(['Other'], 'Policy, Brief') === 'Other: Policy Brief'
    && paper_declared_formats(manuscript_types_posted(['Other'], 'A, B, IMRAD')) === ['other'], show(manuscript_types_posted(['Other'], 'A, B, IMRAD')));
t('other manuscript: tags and long names are cleaned and cut to the column',
    strpos(manuscript_types_posted(['Other'], '<script>x</script>Brief'), '<') === false
    && strlen(manuscript_types_posted(['Other'], str_repeat('y', 500))) <= 100);
t('other manuscript: no name yet is kept as Other (a draft), and the handler can tell',
    manuscript_types_posted(['Other'], '') === 'Other' && manuscript_other_name('Other') === '');
t('other manuscript: the format is other, its one file is the primary one',
    paper_declared_formats('Other: Policy Brief') === ['other'] && paper_primary_format(['other']) === 'other');
t('other manuscript: shown as the name the author typed',
    manuscript_type_label('Other: Policy Brief') === 'Policy Brief' && manuscript_type_label('IMRAD, Full Manuscript') === 'IMRAD, Full Manuscript'
    && manuscript_type_label('Other') === 'Other' && manuscript_type_label(null) === '');
t('other manuscript: the two named types are unchanged',
    manuscript_types_posted(['IMRAD', 'Full Manuscript'], 'ignored') === 'IMRAD, Full Manuscript'
    && paper_declared_formats('IMRAD, Full Manuscript') === ['imrad', 'full']);
t('other manuscript: the checklist starts on a blank list; the others start as before',
    paper_checklist_default('Other: Policy Brief', 'capstone') === 'blank'
    && paper_checklist_default('Other: Policy Brief', 'project') === 'feasibility'
    && paper_checklist_default('IMRAD', 'other:Case Study') === 'imrad'
    && paper_checklist_default('Full Manuscript', 'other:Case Study') === 'undergrad_thesis'
    && paper_checklist_default('Full Manuscript', 'capstone') === 'capstone');

echo "SUMMARY passed=$passed failed=$failed\n";
exit($failed ? 1 : 0);
