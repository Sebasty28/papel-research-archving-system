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

echo "SUMMARY passed=$passed failed=$failed\n";
exit($failed ? 1 : 0);
