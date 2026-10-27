<?php
/**
 * Unit cases for the abstract finder used by Extract with AI (extract_abstract_from_text()
 * and pdf_text_clip() in config/groq_config.php).
 *
 *     php tests/_abstract_unit.php
 *
 * One line per case, "PASS name" or "FAIL name: detail", then "SUMMARY passed=N failed=N".
 * Each case is the shape of a real paper the finder once got wrong (named in the case);
 * the text is made up, so nothing here needs the PDFs or the network.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/core.php';
require_once __DIR__ . '/../config/groq_config.php';

$passed = 0;
$failed = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $name\n"; }
    else     { $failed++; echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}

$body = 'This study evaluates the feasibility of establishing a company in Biñan City that makes a single-use soap bar '
      . 'for people on the move. The market study projects a demand of twenty million units by 2026, the technical '
      . 'study sets out the plant and its equipment, and the financial study finds the venture profitable within three '
      . 'years. The findings confirm that the product is market-viable and technically feasible.';

$r = extract_abstract_from_text("Approval Sheet ... ABSTRACT $body Acknowledgment The researchers thank their adviser.");
t('plain: the abstract is the text from the heading to the next heading (SnapSoap)', $r === $body, (string)$r);

$r = extract_abstract_from_text("Title page text ABSTRACT $body Keywords: soap, feasibility, Laguna");
t('plain: it stops at the keywords', $r === $body, (string)$r);

$r = extract_abstract_from_text("Polytechnic University of the Philippines ABSTRACT I. INTRODUCTION Most of the technologies that were "
    . "developing were influenced by the cultural environments of every nation, and the classes that used them. " . str_repeat('More introduction here. ', 40)
    . " Keywords: communication");
t('an ABSTRACT heading with nothing under it is no abstract, not the Introduction (IMRAD-CommEase)', $r === null, (string)$r);

$r = extract_abstract_from_text("Table of Contents Acknowledgement……………iv Abstract……………………v MARKETING ASPECT……1 "
    . "Industry Analysis The personal care industry is large. " . str_repeat('It grows every year. ', 40));
t('a line of the table of contents is not the heading (ShampEase contents page)', $r === null, (string)$r);

$r = extract_abstract_from_text("TABLE OF CONTENTS ABSTRACT.........5 MARKET STUDY.........8 ABSTRACT Title: HuskeNails: A Study "
    . "Researchers: Gazo, Gabriela M. Prolo, Janna C. Degree: Bachelor of Science in Industrial Engineering "
    . "Institution: Polytechnic University of the Philippines Year: 2025-2026 Adviser: Engr. Gio M. Marcos $body 5 Table of Contents");
t('the Title / Researchers / Degree / Year / Adviser block is taken off (HuskeNails)', $r === $body, (string)$r);

$r = extract_abstract_from_text("ABSTRACT Title : A Study Researcher : Arceo, Jessa Margaret A. Degree : Bachelor of Science "
    . "Adviser : Engr. Gio M. Marcos Hair care products are known to be one of the essential items used for personal hygiene. "
    . "Conventional liquid shampoos are popular among consumers; nonetheless, they leak. POLYTECHNIC UNIVERSITY OF THE PHILIPPINES vi "
    . "This study employed descriptive research techniques together with market analysis to collect information concerning consumer "
    . "tastes. The researchers concluded that the product is feasible. POLYTECHNIC UNIVERSITY OF THE PHILIPPINES vii TABLE OF CONTENTS");
t('the adviser\'s name ends where the prose begins, and the running head and page numbers are taken out (ShampEase)',
    $r !== null && strpos($r, 'Hair care products') === 0 && strpos($r, 'POLYTECHNIC') === false && substr($r, -9) === 'feasible.', (string)$r);

$r = extract_abstract_from_text(str_repeat('Chapter one of the paper, without a summary of any kind. ', 60));
t('a paper with no abstract gives none, so only the metadata is filled (DUOTUMBLER)', $r === null, (string)$r);

$r = extract_abstract_from_text("ABSTRACT This is short. Keywords: x");
t('a fragment too short to be an abstract is none', $r === null, (string)$r);

$r = extract_abstract_from_text("ABSTRACT " . str_repeat('A sentence that goes on and on. ', 400));
t('text that never stops is none, not six thousand characters of the paper', $r === null, strlen((string)$r) . ' chars');

$r = extract_abstract_from_text("ABSTRACT \u{200B}" . $body . " \u{200B}5 Table of Contents");
t('zero-width spaces between words do not get in the way (HuskeNails PDF)', $r === $body, (string)$r);

// The bytes of the peso sign, cut in the middle: what substr() used to leave at the end of the text.
$broken = "ABSTRACT $body Acknowledgment " . substr("\u{20B1}", 0, 2);
$r = extract_abstract_from_text($broken);
t('invalid UTF-8 at the end of the text does not stop it finding the abstract (Coconut Coir)', $r === $body, (string)$r);

$clip = pdf_text_clip(str_repeat('a', 7999) . "\u{00F1}bc", 8000);
t('pdf_text_clip never splits a character', mb_check_encoding($clip, 'UTF-8') && strlen($clip) <= 8000, bin2hex(substr($clip, -3)));
t('and the text it gives can always be sent (json_encode does not fail on it) (YUKI Bites)',
    json_encode(['t' => pdf_text_clip(str_repeat('x', 7999) . "\u{20B1}", 8000)]) !== false);
t('without it, the same cut could not be sent at all', json_encode(['t' => substr(str_repeat('x', 7999) . "\u{20B1}", 0, 8000)]) === false);

$meta = build_metadata_result(['authors' => ['A', 'B']], '', 'Student Name');
t('no title found is an empty title, so the page keeps the box as it was', $meta['title'] === '' && $meta['abstract'] === '', json_encode($meta));

echo "SUMMARY passed=$passed failed=$failed\n";
exit($failed ? 1 : 0);
