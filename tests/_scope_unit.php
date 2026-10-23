<?php
/**
 * Unit tests for the section-scope helpers in config/core.php.
 *
 *     php tests/_scope_unit.php
 *
 * One line per case, "PASS name" or "FAIL name: detail", then a last line
 * "SUMMARY passed=N failed=N". tests/m10_section_scope.py runs this and reads
 * those lines, so the format is part of the contract.
 *
 * Nothing here touches the database or the network: the helpers under test are
 * pure functions of a string, which is the point of keeping them that way.
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

$all = array_keys(paper_section_labels());

/* ---- section_scope_parse ----------------------------------------------- */
t('parse: empty string is "nothing chosen"', section_scope_parse('') === null);
t('parse: null is "nothing chosen"', section_scope_parse(null) === null);
t('parse: only unknown keys is "nothing chosen"', section_scope_parse('bogus,,nope') === null);
t('parse: abstract alone', section_scope_parse('abstract') === ['abstract'], show(section_scope_parse('abstract')));
t('parse: abstract is forced in',
    section_scope_parse('references') === ['abstract', 'references'], show(section_scope_parse('references')));
t('parse: result follows the canonical order, not the posted one',
    section_scope_parse('references,introduction,abstract') === ['abstract', 'introduction', 'references'],
    show(section_scope_parse('references,introduction,abstract')));
t('parse: a list works as well as a string',
    section_scope_parse(['introduction']) === ['abstract', 'introduction']);
t('parse: duplicates collapse',
    section_scope_parse('abstract,abstract,introduction,introduction') === ['abstract', 'introduction']);
t('parse: whitespace is trimmed',
    section_scope_parse(' abstract , conclusion ') === ['abstract', 'conclusion']);
t('parse: keys are case sensitive, so a made-up spelling is dropped',
    section_scope_parse('Abstract,INTRODUCTION') === null, show(section_scope_parse('Abstract,INTRODUCTION')));
t('parse: nested arrays are ignored, not a warning',
    section_scope_parse([['introduction'], 'conclusion']) === ['abstract', 'conclusion'],
    show(section_scope_parse([['introduction'], 'conclusion'])));
t('parse: a number is not a section', section_scope_parse(7) === null);
t('parse: every key gives every key', section_scope_parse(implode(',', $all)) === $all);

/* ---- section_scope_resolve --------------------------------------------- */
t('resolve: nothing posted means every section', section_scope_resolve(null) === $all);
t('resolve: garbage means every section', section_scope_resolve('bogus') === $all);
t('resolve: a real choice is kept',
    section_scope_resolve('introduction') === ['abstract', 'introduction']);

/* ---- paper_section_scope (read back from storage) ---------------------- */
t('stored: null is unrecorded', paper_section_scope(null) === null);
t('stored: empty string is unrecorded', paper_section_scope('') === null);
t('stored: not JSON is unrecorded', paper_section_scope('not json at all') === null);
t('stored: JSON without the key is unrecorded (a paper filed before this)',
    paper_section_scope('{"abstract":"<p>x</p>","introduction":"<p>y</p>"}') === null);
t('stored: the recorded list is read back',
    paper_section_scope('{"abstract":"x","_scope":["abstract"]}') === ['abstract']);
t('stored: an empty list is unrecorded, not "nothing"', paper_section_scope('{"_scope":[]}') === null);
t('stored: a list of unknown keys is unrecorded', paper_section_scope('{"_scope":["bogus"]}') === null);
t('stored: a damaged list is repaired the same way a posted one is',
    paper_section_scope('{"_scope":["conclusion"]}') === ['abstract', 'conclusion']);
t('stored: a JSON array at the top is unrecorded', paper_section_scope('[1,2,3]') === null);

/* ---- paper_scope_list -------------------------------------------------- */
t('list: none', paper_scope_list([]) === '');
t('list: one', paper_scope_list(['A']) === 'A');
t('list: two', paper_scope_list(['A', 'B']) === 'A and B');
t('list: three', paper_scope_list(['A', 'B', 'C']) === 'A, B and C');

/* ---- paper_scope_info -------------------------------------------------- */
$legacy = paper_scope_info('{"abstract":"<p>x</p>"}');
t('info: a paper with no record is legacy', $legacy['kind'] === 'legacy' && $legacy['recorded'] === false);
t('info: legacy is treated as complete and claims nothing',
    $legacy['omitted'] === [] && $legacy['short'] === '' && !paper_scope_is_partial($legacy));
t('info: null content is legacy too', paper_scope_info(null)['kind'] === 'legacy');

$abs = paper_scope_info('{"_scope":["abstract"]}');
t('info: abstract only', $abs['kind'] === 'abstract' && $abs['short'] === 'Abstract only', show($abs));
t('info: abstract only leaves five out', count($abs['omitted']) === 5 && count($abs['provided']) === 1);
t('info: abstract only is partial', paper_scope_is_partial($abs));

$part = paper_scope_info('{"_scope":["abstract","introduction","references"]}');
t('info: three of six is partial', $part['kind'] === 'partial' && $part['short'] === '3 of 6 sections', show($part));
t('info: partial names what was provided',
    $part['provided'] === ['Abstract', 'Introduction', 'References']);
t('info: partial names what was left out',
    $part['omitted'] === ['Methodology', 'Results and Discussion', 'Conclusion']);

$full = paper_scope_info('{"_scope":' . json_encode($all) . '}');
t('info: all six is full', $full['kind'] === 'full' && $full['recorded'] === true && $full['short'] === 'All sections');
t('info: full is not partial', !paper_scope_is_partial($full));

/* ---- paper_scope_chip -------------------------------------------------- */
t('chip: none for a legacy paper', paper_scope_chip($legacy) === '');
t('chip: none for a complete paper', paper_scope_chip($full) === '');
$chip = paper_scope_chip($abs);
t('chip: abstract only carries its words', strpos($chip, 'Abstract only') !== false, $chip);
t('chip: uses the shared class', strpos($chip, 'class="scope-chip"') !== false);
t('chip: spells out what was left out in its tooltip', strpos($chip, 'Left out by the author') !== false);
t('chip: partial shows the count', strpos(paper_scope_chip($part), '3 of 6 sections') !== false);
t('chip: no inline style attribute (the CSP drops them)', strpos($chip, 'style=') === false);

/* ---- paper_scope_notice ------------------------------------------------ */
foreach (['reviewer', 'author', 'reader', 'brief'] as $who) {
    t("notice[$who]: empty for a complete paper", paper_scope_notice($full, $who) === '');
    t("notice[$who]: empty for a legacy paper", paper_scope_notice($legacy, $who) === '');
    $a = paper_scope_notice($abs, $who);
    $p = paper_scope_notice($part, $who);
    t("notice[$who]: abstract only is stated", stripos($a, 'abstract') !== false && $a !== '', $a);
    t("notice[$who]: partial names its sections", strpos($p, 'Introduction') !== false && strpos($p, 'References') !== false, $p);
    t("notice[$who]: no em dash", strpos($a . $p, "\xE2\x80\x94") === false);
}
t('notice[reviewer]: says the absence is not an omission',
    strpos(paper_scope_notice($abs, 'reviewer'), 'not an omission') !== false);
t('notice[reviewer]: says what the page will show',
    strpos(paper_scope_notice($abs, 'reviewer'), 'will show only what was provided') !== false);
t('notice[author]: speaks to the author',
    strpos(paper_scope_notice($abs, 'author'), 'You chose') === 0);
t('notice[reader]: speaks about the author',
    strpos(paper_scope_notice($abs, 'reader'), 'The author chose to publish only the Abstract') === 0);

/* ---- the reserved key must never collide with a section ---------------- */
t('key: _scope is not a section key', !in_array(PAPER_SCOPE_KEY, $all, true));
t('key: every section key is a plain lowercase word, so none can look like a meta key',
    count(array_filter($all, function ($k) { return preg_match('/^[a-z][a-z_]*$/', $k) === 1; })) === count($all));

/* ---- a stored object round-trips -------------------------------------- */
$sections = array_fill_keys($all, '');
$sections['abstract'] = '<p>Only this.</p>';
$sections[PAPER_SCOPE_KEY] = ['abstract'];
$json = json_encode($sections, JSON_UNESCAPED_UNICODE);
$back = paper_scope_info($json);
t('round trip: what the submit handler writes reads back as abstract only', $back['kind'] === 'abstract');
$decoded = json_decode($json, true);
$visible = [];
foreach (paper_section_labels() as $key => $_) {
    if (!empty($decoded[$key]) && trim(strip_tags((string)$decoded[$key])) !== '') { $visible[] = $key; }
}
t('round trip: the readers\' own loop sees only the abstract and ignores the meta key', $visible === ['abstract'], show($visible));

/* ---- the author's own sections ----------------------------------------- */
$six = array_keys(paper_section_labels());
$lbl = function (array $list) { return array_column($list, 'label'); };

t('custom key: custom_1, custom_12 and custom_24 are valid',
    paper_custom_key_valid('custom_1') && paper_custom_key_valid('custom_12') && paper_custom_key_valid('custom_24'));
t('custom key: 0, 25, 99, 012, a path and a number are not',
    !paper_custom_key_valid('custom_0') && !paper_custom_key_valid('custom_25') && !paper_custom_key_valid('custom_99') && !paper_custom_key_valid('custom_012')
    && !paper_custom_key_valid('custom_100')
    && !paper_custom_key_valid('../x') && !paper_custom_key_valid('custom_1 ') && !paper_custom_key_valid(1)
    && !paper_custom_key_valid(null) && !paper_custom_key_valid('Custom_1'));

$ps = paper_custom_presets();
t('presets: Full Manuscript, Undergraduate Thesis and Feasibility Study',
    array_column($ps, 'name') === ['Full Manuscript', 'Undergraduate Thesis', 'Feasibility Study'], show(array_column($ps, 'name')));
t('presets: Full Manuscript and Undergraduate Thesis are the same chapters',
    $ps[0]['items'] === $ps[1]['items'] && $ps[0]['items'][0] === 'Chapter 1' && end($ps[0]['items']) === 'References', show($ps[0]['items']));
t('presets: the Feasibility Study list is the adviser checklist\'s',
    $ps[2]['items'] === paper_checklist_templates()['feasibility']['items'], show($ps[2]['items']));
t('presets: every item is usable as a name as it stands and fits the limit',
    (function () use ($ps) {
        foreach ($ps as $p) {
            if (count($p['items']) > PAPER_CUSTOM_MAX) return false;
            foreach ($p['items'] as $i) { if ($i === '' || paper_checklist_label_clean($i) !== $i) return false; }
        }
        return true;
    })());

$post = ['custom_1' => '<p>Summary text</p>', 'custom_2' => '<p>Market text</p>'];
$ok   = [['key' => 'custom_1', 'label' => 'Executive Summary'], ['key' => 'custom_2', 'label' => 'Marketing Aspect']];

t('list: a JSON string and a list give the same answer',
    paper_custom_list(json_encode($ok), $six) === $ok && paper_custom_list($ok, $six) === $ok);
t('list: not JSON, not a list, null, a number: nothing, and no error',
    paper_custom_list('nope', $six) === [] && paper_custom_list(null, $six) === [] && paper_custom_list(7, $six) === []
    && paper_custom_list('{"a":1}', $six) === [] && paper_custom_list('"x"', $six) === []);
t('list: an entry that is not an object, or has no key or label, is dropped',
    paper_custom_list([['key' => 'custom_1', 'label' => 'A'], 'x', 5, null, ['key' => 'custom_2'], ['label' => 'B'], ['key' => 'custom_3', 'label' => '   ']], $six)
    === [['key' => 'custom_1', 'label' => 'A']]);
t('list: bad keys are dropped',
    paper_custom_list([['key' => 'custom_0', 'label' => 'A'], ['key' => 'custom_25', 'label' => 'B'], ['key' => '../x', 'label' => 'C'], ['key' => 'introduction', 'label' => 'D'], ['key' => 'custom_4', 'label' => 'E']], $six)
    === [['key' => 'custom_4', 'label' => 'E']]);
t('list: a key used twice is kept once, the first',
    paper_custom_list([['key' => 'custom_1', 'label' => 'A'], ['key' => 'custom_1', 'label' => 'B']], $six) === [['key' => 'custom_1', 'label' => 'A']]);
t('list: a name used twice is kept once, whatever the case or spacing',
    $lbl(paper_custom_list([['key' => 'custom_1', 'label' => 'Technical Aspect'], ['key' => 'custom_2', 'label' => '  TECHNICAL   aspect ']], $six)) === ['Technical Aspect']);
t('list: tags, control characters and runs of spaces are cleaned from a name',
    $lbl(paper_custom_list([['key' => 'custom_1', 'label' => "<b>Mar\x07ket</b>\t <i>Aspect</i>\n"]], $six)) === ['Mar ket Aspect'],
    show($lbl(paper_custom_list([['key' => 'custom_1', 'label' => "<b>Mar\x07ket</b>\t <i>Aspect</i>\n"]], $six))));
t('list: a script in a name is stripped, not kept',
    strpos(show(paper_custom_list([['key' => 'custom_1', 'label' => '<script>alert(1)</script>Findings']], $six)), '<') === false);
t('list: a name is cut to 80 characters',
    mb_strlen(paper_custom_list([['key' => 'custom_1', 'label' => str_repeat('x', 10000)]], $six)[0]['label']) === 80);
t('list: multibyte names survive and count as characters',
    $lbl(paper_custom_list([['key' => 'custom_1', 'label' => str_repeat("\u{00E9}", 90)]], $six)) === [str_repeat("\u{00E9}", 80)]);
t('list: no more than ' . PAPER_CUSTOM_MAX . ' are kept, the first ones',
    (function () use ($six) {
        $many = [];
        for ($i = 1; $i <= 12; $i++) { $many[] = ['key' => 'custom_' . $i, 'label' => 'S' . $i]; }
        for ($i = 0; $i < 500; $i++) { $many[] = ['key' => 'custom_1', 'label' => 'Extra' . $i]; }
        $got = paper_custom_list($many, $six);
        return count($got) === PAPER_CUSTOM_MAX && $got[11]['label'] === 'S12';
    })());
t('list: five hundred entries with fresh keys still stop at the limit',
    count(paper_custom_list(array_map(function ($i) { return ['key' => 'custom_' . (($i % 12) + 1), 'label' => 'N' . $i]; }, range(1, 500)), $six)) === PAPER_CUSTOM_MAX);
t('list: a name equal to a standard section that is being submitted is dropped',
    paper_custom_list([['key' => 'custom_1', 'label' => 'introduction']], $six) === []);
t('list: ... but kept when that standard section is left out, so References can follow the chapters',
    $lbl(paper_custom_list([['key' => 'custom_1', 'label' => 'Chapter 1'], ['key' => 'custom_2', 'label' => 'References']], ['abstract'])) === ['Chapter 1', 'References']);
t('list: the Abstract is always in play, so a custom Abstract is always dropped',
    paper_custom_list([['key' => 'custom_1', 'label' => 'Abstract']], ['abstract']) === []);

t('clean: bodies are taken from the post by key and sanitised',
    paper_custom_clean($ok, $post, $six, true) === [
        ['key' => 'custom_1', 'label' => 'Executive Summary', 'html' => rich_text_sanitize('<p>Summary text</p>')],
        ['key' => 'custom_2', 'label' => 'Marketing Aspect', 'html' => rich_text_sanitize('<p>Market text</p>')],
    ], show(paper_custom_clean($ok, $post, $six, true)));
t('clean: markup that is not allowed is removed from a body',
    strpos(paper_custom_clean([['key' => 'custom_1', 'label' => 'A']], ['custom_1' => '<p onclick="x()">hi</p><script>alert(1)</script>'], $six, true)[0]['html'], 'script') === false
    && strpos(paper_custom_clean([['key' => 'custom_1', 'label' => 'A']], ['custom_1' => '<p onclick="x()">hi</p>'], $six, true)[0]['html'], 'onclick') === false);
t('clean: an empty body is refused on submit, in words for the student',
    (function () use ($ok) {
        try { paper_custom_clean($ok, ['custom_1' => '<p>x</p>', 'custom_2' => '<p><br></p>'], ['abstract'], true); return false; }
        catch (UserFacingException $e) { return $e->getMessage() === 'Please write the Marketing Aspect section.'; }
    })());
t('clean: a body of only a table counts as written',
    count(paper_custom_clean([['key' => 'custom_1', 'label' => 'A']],
        ['custom_1' => '<table><tr><td></td></tr></table>'], $six, true)) === 1);
t('clean: ... and so does one of only a picture (the sanitiser keeps only this site\'s own pictures)',
    paper_section_html_is_empty('<p><img src="x.png"></p>') === false && paper_section_html_is_empty('<p><br></p>') === true
    && paper_section_html_is_empty('') === true && paper_section_html_is_empty('<p>  </p>') === true);
t('clean: a picture from anywhere else is removed, leaving the section empty and refused',
    (function () {
        try { paper_custom_clean([['key' => 'custom_1', 'label' => 'A']], ['custom_1' => '<img src="data:image/png;base64,AAAA">'], ['abstract'], true); return false; }
        catch (UserFacingException $e) { return true; }
    })());
t('clean: a draft keeps an empty body, and a body posted for a key not in the list is ignored',
    paper_custom_clean($ok, ['custom_3' => '<p>stray</p>'], $six, false) === [
        ['key' => 'custom_1', 'label' => 'Executive Summary', 'html' => ''],
        ['key' => 'custom_2', 'label' => 'Marketing Aspect', 'html' => ''],
    ]);
t('clean: nothing posted is nothing kept, and not an error',
    paper_custom_clean('', [], $six, true) === [] && paper_custom_clean('[]', [], $six, true) === []);

$stored = json_encode([
    'abstract' => '<p>A</p>', 'introduction' => '', 'methodology' => '', 'results_discussion' => '', 'conclusion' => '', 'references' => '',
    PAPER_SCOPE_KEY => ['abstract'],
    PAPER_CUSTOM_KEY => paper_custom_clean($ok, $post, ['abstract'], true),
], JSON_UNESCAPED_UNICODE);

t('stored: the sections come back as they went in',
    $lbl(paper_custom_sections($stored)) === ['Executive Summary', 'Marketing Aspect'] && paper_custom_sections($stored)[1]['html'] === rich_text_sanitize('<p>Market text</p>'));
t('stored: a paper with none, a damaged row and an empty string have none',
    paper_custom_sections(null) === [] && paper_custom_sections('') === [] && paper_custom_sections('not json') === []
    && paper_custom_sections('{"_custom":"x"}') === [] && paper_custom_sections('{"abstract":"<p>A</p>"}') === []);
t('stored: a damaged entry is skipped and the rest are kept',
    $lbl(paper_custom_sections(json_encode([PAPER_CUSTOM_KEY => [5, ['key' => 'x', 'label' => 'Bad'], ['key' => 'custom_2', 'label' => 'Good', 'html' => '<p>g</p>'], ['key' => 'custom_2', 'label' => 'Again']]]))) === ['Good']);
t('stored: more than the limit in a row is cut at the limit',
    count(paper_custom_sections(json_encode([PAPER_CUSTOM_KEY => array_map(function ($i) { return ['key' => 'custom_' . (($i % 12) + 1), 'label' => 'L' . $i, 'html' => '']; }, range(1, 100))]))) === PAPER_CUSTOM_MAX);

/* ---- paper_sections_for_display ---------------------------------------- */
$d = paper_sections_for_display($stored, 'ignored');
t('display: the standard six come first, then the author\'s own, in their order',
    array_keys($d['labels']) === array_merge($six, ['custom_1', 'custom_2']), show(array_keys($d['labels'])));
t('display: only sections with something in them are listed, and they are not legacy',
    array_keys($d['sections']) === ['abstract', 'custom_1', 'custom_2'] && $d['legacy'] === false, show(array_keys($d['sections'])));
t('display: the labels are the author\'s names',
    $d['labels']['custom_1'] === 'Executive Summary' && $d['labels']['abstract'] === 'Abstract');
$old = paper_sections_for_display(json_encode(['abstract' => '<p>A</p>', 'introduction' => '<p>I</p>']), 'x');
t('display: a paper with no sections of its own reads as it always did',
    array_keys($old['labels']) === $six && array_keys($old['sections']) === ['abstract', 'introduction'] && !$old['legacy']);
$leg = paper_sections_for_display(null, "Line one\nLine <b>two</b>");
t('display: with nothing stored the abstract column stands in, escaped, and says so',
    $leg['legacy'] === true && array_keys($leg['sections']) === ['abstract'] && strpos($leg['sections']['abstract'], '<b>') === false);
t('display: nothing stored and no abstract is nothing, not legacy',
    paper_sections_for_display(null, '  ') === ['labels' => paper_section_labels(), 'sections' => [], 'legacy' => false]);
t('display: a standard section holding only a picture is shown',
    array_keys(paper_sections_for_display(json_encode(['abstract' => '<p>A</p>', 'methodology' => '<p><img src="x.png"></p>']), '')['sections']) === ['abstract', 'methodology']);
t('display: a damaged value for a standard section does not break the page',
    array_keys(paper_sections_for_display(json_encode(['abstract' => ['x'], 'introduction' => 5, 'conclusion' => '<p>C</p>']), '')['sections']) === ['conclusion']);

/* ---- paper_scope_info with the author's own sections ------------------- */
$mk = function (array $scope, array $custom) {
    $o = [PAPER_SCOPE_KEY => $scope];
    foreach (paper_section_labels() as $k => $_) { $o[$k] = in_array($k, $scope, true) ? '<p>x</p>' : ''; }
    if ($custom) {
        $o[PAPER_CUSTOM_KEY] = array_map(function ($i, $n) { return ['key' => 'custom_' . ($i + 1), 'label' => $n, 'html' => '<p>x</p>']; }, array_keys($custom), $custom);
    }
    return json_encode($o);
};
$ci = paper_scope_info($mk(['abstract'], ['Executive Summary', 'Marketing Aspect']));
t('custom info: the standard six left out and some of their own is its own kind',
    $ci['kind'] === 'custom' && $ci['short'] === 'Custom: 3 sections' && $ci['custom'] === ['Executive Summary', 'Marketing Aspect'], show($ci));
t('custom info: everything provided is listed, standard first, and the six left out are the omitted',
    $ci['provided'] === ['Abstract', 'Executive Summary', 'Marketing Aspect'] && count($ci['omitted']) === 5 && $ci['keys'] === ['abstract'], show($ci));
t('custom info: it is flagged, so the chip and the notices show',
    paper_scope_is_partial($ci) && paper_scope_chip($ci) !== '' && strpos(paper_scope_chip($ci), 'Custom: 3 sections') !== false);
$cf = paper_scope_info($mk($six, ['Appendices']));
t('custom info: their own on top of all six standard is a full paper and draws nothing',
    $cf['kind'] === 'full' && !paper_scope_is_partial($cf) && paper_scope_chip($cf) === '' && paper_scope_notice($cf, 'reviewer') === '', show($cf));
$cp = paper_scope_info($mk(['abstract', 'conclusion'], ['Appendices']));
t('custom info: some standard left out and one of their own is custom, not "2 of 6"',
    $cp['kind'] === 'custom' && $cp['short'] === 'Custom: 3 sections', show($cp));
t('custom info: the reviewer\'s notice counts what was provided and names it',
    strpos(paper_scope_notice($ci, 'reviewer'), 'chose to submit 3 written sections: Abstract, Executive Summary and Marketing Aspect.') !== false
    && strpos(paper_scope_notice($ci, 'reviewer'), '3 of') === false, paper_scope_notice($ci, 'reviewer'));
t('custom info: the author\'s, the reader\'s and the brief notice all name the sections',
    strpos(paper_scope_notice($ci, 'author'), 'Executive Summary') !== false
    && strpos(paper_scope_notice($ci, 'reader'), 'Marketing Aspect') !== false
    && strpos(paper_scope_notice($ci, 'brief'), '3 sections') !== false);
t('custom info: a paper without any is untouched (kind, chip and words as before)',
    paper_scope_info($mk(['abstract'], []))['kind'] === 'abstract' && paper_scope_info($mk(['abstract', 'conclusion'], []))['short'] === '2 of 6 sections'
    && paper_scope_info($mk(['abstract'], []))['custom'] === []);
t('custom info: an old paper with no choice recorded stays legacy',
    paper_scope_info(json_encode(['abstract' => '<p>A</p>']))['kind'] === 'legacy' && paper_scope_info(null)['provided'] === array_values(paper_section_labels()));
t('custom info: the reserved keys are not sections of the six',
    !array_key_exists(PAPER_CUSTOM_KEY, paper_section_labels()) && PAPER_CUSTOM_KEY !== PAPER_SCOPE_KEY);

echo "SUMMARY passed=$passed failed=$failed\n";
exit($failed ? 1 : 0);
