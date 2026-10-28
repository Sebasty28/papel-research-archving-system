<?php
/**
 * The upload form's Preview tab: the paper's page in the Public Repository, drawn
 * from what is on the form right now, before anything is filed.
 *
 * It is the real page (archive/view_paper.php) in its preview mode, handed a paper
 * built from the post the way the handlers in student_upload_ai.php build one, so
 * what the student sees here cannot drift from what readers will see. Nothing is
 * written: no row, no file, no Drive. The form posts its typed fields into the
 * tab's frame (never its PDFs) and does so again, after a pause, as they change.
 *
 * The paper is drawn as it would be on submit, not as a draft keeps it: a section
 * outside the author's choice is left out, as it will be on the page. Anything not
 * yet answered is drawn as the page draws a gap ("Not provided"), so a half-filled
 * form previews as half a page rather than being refused.
 */
require_once __DIR__ . '/../../config/core.php';
require_role(['student', 'faculty', 'admin', 'super_admin', 'head_academic', 'librarian']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('The preview is drawn from the upload form.');
}
csrf_verify();

$u = current_user();

$title   = trim((string)($_POST['title'] ?? ''));
$authors = trim((string)($_POST['authors'] ?? ''));
$year    = (int)($_POST['year'] ?? 0);
if ($year < 1900 || $year > 2100) { $year = (int)date('Y'); }

/* A full date wins over the Year box, as on submit; "the year only" stores none. */
$researchDate = null;
$posted = trim((string)($_POST['research_date'] ?? ''));
if ($posted !== '' && empty($_POST['research_date_unknown'])) {
    $rd = DateTime::createFromFormat('Y-m-d', $posted);
    if ($rd && $rd->format('Y-m-d') === $posted && (int)$rd->format('Y') >= 1900 && (int)$rd->format('Y') <= 2100) {
        $researchDate = $posted;
        $year = (int)$rd->format('Y');
    }
}

/* The sections, as submit stores them: sanitised here (this is untrusted markup, and
   it is put on a page), and those outside the author's choice left empty. */
$scope = section_scope_for($_POST['section_scope'] ?? null, $_POST['custom_sections'] ?? '', false)
      ?? array_keys(paper_section_labels());
$sections = [];
foreach (array_keys(paper_section_labels()) as $key) {
    $sections[$key] = in_array($key, $scope, true) ? rich_text_sanitize((string)($_POST[$key] ?? '')) : '';
}
$sections[PAPER_SCOPE_KEY] = $scope;
$custom = paper_custom_clean($_POST['custom_sections'] ?? '', $_POST, $scope, false);
if ($custom) { $sections[PAPER_CUSTOM_KEY] = $custom; }

$statusArr = $_POST['paper_status'] ?? [];
$pubStatus = is_array($statusArr) && $statusArr ? implode(', ', $statusArr) : '';

$program = trim((string)($_POST['program_category'] ?? ''));
if ($program === '') {
    $ps = db()->prepare('SELECT program FROM users WHERE user_id = ?');
    $ps->bind_param('i', $u['user_id']);
    $ps->execute();
    $program = trim((string)($ps->get_result()->fetch_assoc()['program'] ?? ''));
}

$GLOBALS['papelPreviewPaper'] = [
    'paper_id'             => 0,
    // An untitled form still has a heading to look at, and the tab says what it is.
    'title'                => $title !== '' ? $title : 'Your paper\'s title',
    'author_names'         => $authors !== '' ? $authors : ($u['full_name'] ?? ''),
    'year'                 => $year,
    'research_date'        => $researchDate,
    'abstract'             => rich_text_to_plain($sections['abstract']),
    'imrad_content'        => json_encode($sections, JSON_UNESCAPED_UNICODE),
    'keywords'             => trim((string)($_POST['keywords'] ?? '')),
    // Other is drawn with whatever has been typed beside it so far.
    'paper_type'           => (string)paper_type_from_post($_POST['paper_type'] ?? '', $_POST['paper_type_other'] ?? '', false),
    'research_type'        => trim((string)($_POST['research_type'] ?? '')),
    'manuscript_type'      => manuscript_types_posted($_POST['manuscript_type'] ?? [], $_POST['manuscript_type_other'] ?? ''),
    'publication_status'   => $pubStatus,
    'publication_location' => in_array('Published Paper', (array)$statusArr, true) ? trim((string)($_POST['publication_location'] ?? '')) : '',
    'program_category'     => $program,
    'current_status'       => 'approved',
    /* "At a Glance": what Extract with AI wrote into the form's hidden fields, which
       submit stores as they are. Empty until the student has extracted. */
    'ai_summary'             => trim((string)($_POST['ai_summary'] ?? '')),
    'ai_methodology'         => trim((string)($_POST['ai_methodology'] ?? '')),
    'ai_statistical_methods' => trim((string)($_POST['ai_statistical_methods'] ?? '')),
    'ai_variables'           => trim((string)($_POST['ai_variables'] ?? '')),
    'ai_sample_size'         => trim((string)($_POST['ai_sample_size'] ?? '')),
    'ai_research_field'      => trim((string)($_POST['ai_research_field'] ?? '')),
];

define('PAPEL_PAPER_PREVIEW', true);
require ROOT_PATH . '/archive/view_paper.php';
