<?php
/**
 * A student's own record of a submitted paper.
 *
 * The repository page (archive/view_paper.php) is written for a reader looking
 * something up. This is the other thing entirely: it is the author's copy of
 * what they filed — everything from Step 1 to Step 3 of the upload, the five
 * written sections, the files that went with it, and the checklist the Research
 * Adviser ticked when they approved it. That last part is the point: the
 * checklist is the only place a student can see what their adviser actually
 * confirmed was present.
 *
 * Owner-only. A student sees their own papers here and nobody else's.
 */
require_once '../../config/core.php';
require_role(['student']);
require_once '../../config/workflow.php';
require_once '../../config/gdrive_config.php';

$conn = db();
$u    = current_user();

$paper_id = (int)($_GET['id'] ?? 0);

/* Ownership is the whole access rule — the paper must be this student's.
   A returned paper carries the status 'draft', which is also what an
   untouched draft has, so the two are told apart by whether a reviewer has
   ever sent it back: a returned paper has a decline on record and everything
   worth reading here, while a draft that has never been submitted has nothing
   to show and belongs on the upload page. */
$ps = $conn->prepare(
    "SELECT * FROM research_papers
     WHERE paper_id = ? AND uploaded_by = ?
       AND (current_status <> 'draft'
            OR EXISTS (SELECT 1 FROM approval_workflow aw
                       WHERE aw.paper_id = research_papers.paper_id AND aw.status = 'declined'))");
$ps->bind_param('ii', $paper_id, $u['user_id']);
$ps->execute();
$paper = $ps->get_result()->fetch_assoc();
$ps->close();

if (!$paper) {
    flash('error', 'That paper could not be found in your submissions.');
    header('Location: student_dashboard.php');
    exit;
}

$status = (string)$paper['current_status'];

/* ---- The five written sections -----------------------------------------
   Stored as sanitised HTML in imrad_content, keyed by section. Papers filed
   before the section editors existed have only the plain `abstract` column,
   so that stands in and the page says as much rather than showing four empty
   boxes. */
$sectionLabels = paper_section_labels();
$sections = [];
if (!empty($paper['imrad_content'])) {
    $decoded = json_decode((string)$paper['imrad_content'], true);
    if (is_array($decoded)) {
        foreach ($sectionLabels as $key => $_) {
            if (!empty($decoded[$key]) && trim(strip_tags((string)$decoded[$key])) !== '') {
                $sections[$key] = (string)$decoded[$key];
            }
        }
    }
}
$sectionsAreLegacy = false;
if (!$sections && trim((string)$paper['abstract']) !== '') {
    $sections['abstract'] = '<p>' . nl2br(e((string)$paper['abstract'])) . '</p>';
    $sectionsAreLegacy = true;
}

/* ---- Files -------------------------------------------------------------- */
/* Every document this paper should have, whether or not it arrived. Showing
   only what was attached hides the thing a student most needs to see when a
   paper comes back — which file is missing. Ethics, consent and the data tool
   are demanded only of the paper types that gather original data; for anything
   else they are welcome but not owed, and are marked as such rather than as a
   fault. */
$docsRequired = paper_type_needs_documents($paper['paper_type'] ?? '');
$expected = [
    'manuscript'       => ['label' => 'Manuscript',              'required' => true],
    'ethics_clearance' => ['label' => 'Ethical Clearance',       'required' => $docsRequired],
    'consent_form'     => ['label' => 'Consent Form',            'required' => $docsRequired],
    'data_collection'  => ['label' => 'Data Collection Tool',    'required' => $docsRequired],
    'copyright_doc'    => ['label' => 'Copyright / IP Document', 'required' => $docsRequired],
];

$found = [];
if ($link = paper_file_url($paper['gdrive_file_id'] ?? null, $paper['file_path'] ?? null)) {
    $found['manuscript'] = $link;
}
$ds = $conn->prepare("SELECT document_type, file_path, gdrive_file_id FROM supporting_documents WHERE paper_id = ? ORDER BY doc_id ASC");
$ds->bind_param('i', $paper_id);
$ds->execute();
foreach ($ds->get_result()->fetch_all(MYSQLI_ASSOC) as $d) {
    // Papers resubmitted after a revision leave earlier copies behind, so the
    // newest of each kind wins. The copyright document is stored with a blank
    // type, which supporting_doc_label() already knows how to read.
    $label = supporting_doc_label($d['document_type'] ?? '');
    $key   = array_search($label, array_column($expected, 'label'), true);
    $key   = $key === false ? $label : array_keys($expected)[$key];
    if ($link = paper_file_url($d['gdrive_file_id'] ?? null, $d['file_path'] ?? null)) {
        $found[$key] = $link;   // later rows overwrite earlier ones
    }
}
$ds->close();

$files = [];
foreach ($expected as $key => $info) {
    $files[] = [
        'key'      => $key,
        'label'    => $info['label'],
        'href'     => $found[$key] ?? null,
        'required' => $info['required'],
    ];
}
$missingRequired = 0;
foreach ($files as $f) if (!$f['href'] && $f['required']) $missingRequired++;

/* ---- The adviser's checklist -------------------------------------------- */
$cs = $conn->prepare("SELECT * FROM paper_checklist WHERE paper_id = ? ORDER BY checklist_id DESC LIMIT 1");
$cs->bind_param('i', $paper_id);
$cs->execute();
$checklist = $cs->get_result()->fetch_assoc();
$cs->close();

$chapterItems = [
    'full_ch1'        => 'Chapter 1',
    'full_ch2'        => 'Chapter 2',
    'full_ch3'        => 'Chapter 3',
    'full_ch4'        => 'Chapter 4',
    'full_ch5'        => 'Chapter 5',
    'full_references' => 'References',
];
$checklistGroups = paper_checklist_groups($paper['manuscript_type'] ?? null);
$imradItems = [
    'imrad_intro'      => 'Introduction',
    'imrad_method'     => 'Methodology',
    'imrad_result'     => 'Results',
    'imrad_discussion' => 'Discussion',
    'imrad_references' => 'References',
];

/* ---- Who reviewed it ---------------------------------------------------- */
$reviewers = [];
$rv = $conn->prepare(
    "SELECT aw.review_level, us.full_name FROM approval_workflow aw
     JOIN users us ON us.user_id = aw.reviewer_id
     WHERE aw.paper_id = ? AND aw.status = 'approved' ORDER BY aw.reviewed_at ASC");
$rv->bind_param('i', $paper_id);
$rv->execute();
foreach ($rv->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $reviewers[$r['review_level']] = $r['full_name'];
$rv->close();

/* The most recent time this paper was sent back, and what was said. This is
   the reason a returned paper is worth opening at all, so it is carried to the
   top of the page rather than left as a footnote. */
$returned = null;
$fb = $conn->prepare(
    "SELECT aw.feedback, aw.review_level, aw.reviewed_at, us.full_name
     FROM approval_workflow aw
     LEFT JOIN users us ON us.user_id = aw.reviewer_id
     WHERE aw.paper_id = ? AND aw.status = 'declined'
     ORDER BY aw.reviewed_at DESC LIMIT 1");
$fb->bind_param('i', $paper_id);
$fb->execute();
$returned = $fb->get_result()->fetch_assoc() ?: null;
$fb->close();

$reviewerRole = [
    'faculty' => 'Research Adviser',
    'admin'   => 'Research Coordinator',
];
$needsRevision = $status === 'draft' && $returned;
$underReview   = in_array($status, ['pending_faculty', 'pending_admin', 'pending_admin_l1'], true);

$keywords = array_values(array_filter(array_map('trim', explode(',', (string)$paper['keywords']))));

$steps = workflow_progress_steps($status, (bool)$returned);
$done  = 0; foreach ($steps as $s) if ($s['state'] === 'done') $done++;
$fill  = count($steps) > 1 ? max(0, min(100, ($done - 1) / (count($steps) - 1) * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($paper['title']) ?> · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<?php require_once ROOT_PATH.'/includes/paper_record_css.php'; ?>

</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="student_dashboard.php">My Dashboard</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Paper Details</span>
    </div>
</div>

<main class="wrap">
    <div class="pd-wrap">

        <div class="pd-top">
            <a class="pd-back" href="student_dashboard.php">
                <span class="material-symbols-outlined mi-18">arrow_back</span> Back
            </a>
            <div class="pd-heading">
                <h1><?= e($paper['title']) ?></h1>
                <?php if (!empty($paper['author_names'])): ?>
                    <p class="pd-authors"><?= e($paper['author_names']) ?></p>
                <?php endif; ?>
                <div class="pd-meta">
                    <span><?= e(paper_date_display($paper['research_date'] ?? null, $paper['year'] ?? null)) ?></span>
                    <?php if (!empty($paper['paper_type'])): ?>
                        <span class="sep">•</span><span><?= e(paper_type_label($paper['paper_type'])) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($paper['publication_status'])): ?>
                        <span class="sep">•</span><span><?= e($paper['publication_status']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pd-status">
                Status
                <span class="pd-badge <?= $needsRevision ? 'is-warn' : '' ?>">
                    <?= e(workflow_status_badge_text($status, (bool)$returned)) ?>
                </span>
            </div>
        </div>

        <?php if ($needsRevision): ?>
            <div class="pd-callout">
                <span class="material-symbols-outlined">undo</span>
                <div>
                    <h2>Sent back for revision</h2>
                    <p>
                        Fix what is described below, then submit the paper again. Everything you
                        wrote is still here — editing reopens it exactly as you left it.
                    </p>
                    <?php if (!empty($returned['feedback'])): ?>
                        <p class="pd-said"><?= e($returned['feedback']) ?></p>
                    <?php endif; ?>
                    <span class="pd-who">
                        Returned by <?= e($returned['full_name'] ?: ($reviewerRole[$returned['review_level']] ?? 'a reviewer')) ?>
                        <?php if (!empty($returned['review_level']) && isset($reviewerRole[$returned['review_level']])): ?>
                            (<?= e($reviewerRole[$returned['review_level']]) ?>)
                        <?php endif; ?>
                        <?php if (!empty($returned['reviewed_at'])): ?>
                            on <?= e(date('F j, Y', strtotime($returned['reviewed_at']))) ?>
                        <?php endif; ?>
                    </span>
                    <div>
                        <a class="btn-sm-maroon" href="student_upload_ai.php?draft=<?= (int)$paper['paper_id'] ?>">
                            <span class="material-symbols-outlined mi-18">edit</span> Edit and Re-submit
                        </a>
                    </div>
                </div>
            </div>
        <?php elseif ($underReview): ?>
            <div class="pd-callout is-info">
                <span class="material-symbols-outlined">hourglass_top</span>
                <div>
                    <h2>With your reviewers</h2>
                    <p>
                        <?php if ($status === 'pending_faculty'): ?>
                            Your Research Adviser is reading this now. Once they approve it, it goes
                            to the Research Coordinator for the final decision.
                        <?php else: ?>
                            Your Research Adviser has approved this and passed it to the Research
                            Coordinator, who makes the final decision.
                        <?php endif; ?>
                        Nothing here can be changed while it is under review.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Where it stands -->
        <div class="pd-card">
            <div class="card-track" style="grid-template-columns: 1fr auto;">
                <div class="track">
                    <div class="track-line"><div class="track-line-fill" style="width: <?= (int)round($fill) ?>%"></div></div>
                    <?php foreach ($steps as $s): ?>
                        <div class="track-step <?= e($s['state']) ?>">
                            <span class="track-dot">
                                <?php if ($s['state'] === 'done'): ?>
                                    <span class="material-symbols-outlined">check</span>
                                <?php elseif ($s['state'] === 'current'): ?>
                                    <span class="material-symbols-outlined">more_horiz</span>
                                <?php endif; ?>
                            </span>
                            <span class="track-label"><?= e($s['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="pd-people">
                    <div>Research Adviser: <span class="who"><?= e($reviewers['faculty'] ?? 'Not yet reviewed') ?></span></div>
                    <div>Research Coordinator: <span class="who"><?= e($reviewers['admin'] ?? 'Not yet reviewed') ?></span></div>
                </div>
            </div>
        </div>

        <!-- Step 1 -->
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">description</span> Basic Information</h2>
            <div class="pd-facts">
                <?php
                $facts = [
                    'Academic Program'    => $paper['program_category'] ?? '',
                    'Paper / Research Type' => !empty($paper['paper_type']) ? paper_type_label($paper['paper_type']) : '',
                    'Manuscript Type'     => $paper['manuscript_type'] ?? '',
                    'Paper Status'        => $paper['publication_status'] ?? '',
                    'Published In'        => $paper['publication_location'] ?? '',
                    'Date Completed'      => !empty($paper['research_date']) ? date('F j, Y', strtotime($paper['research_date'])) : '',
                    'Submitted'           => !empty($paper['upload_date']) ? date('F j, Y', strtotime($paper['upload_date'])) : '',
                ];
                foreach ($facts as $label => $value): ?>
                    <div>
                        <span class="pd-fact-label"><?= e($label) ?></span>
                        <span class="pd-fact-value <?= trim((string)$value) === '' ? 'is-empty' : '' ?>">
                            <?= trim((string)$value) === '' ? 'Not provided' : e($value) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($keywords): ?>
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">sell</span> Keywords</h2>
            <div class="pd-chips">
                <?php foreach ($keywords as $kw): ?>
                    <span class="pd-chip"><?= e($kw) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Step 2 -->
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">article</span> Written Sections</h2>
            <?php if (!$sections): ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">info</span>
                    <span>No written sections were saved with this submission.</span>
                </div>
            <?php else: ?>
                <?php if ($sectionsAreLegacy): ?>
                    <div class="pd-note">
                        <span class="material-symbols-outlined">info</span>
                        <span>This paper was submitted before the separate section boxes existed,
                              so only the abstract was kept as written text.</span>
                    </div>
                <?php endif; ?>
                <?php foreach ($sectionLabels as $key => $label): ?>
                    <?php if (empty($sections[$key])) continue; ?>
                    <div class="pd-section">
                        <h3><?= e($label) ?></h3>
                        <?php /* Stored through rich_text_sanitize() on submit, so the markup
                                 here is already limited to the allowed tags. */ ?>
                        <div class="pd-prose pd-prose-scroll"><?= $sections[$key] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Step 3 -->
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">folder_open</span> Uploaded Files</h2>

            <?php if (!$docsRequired): ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">info</span>
                    <span>A <?= e(paper_type_label($paper['paper_type'])) ?> does not require the
                          ethics and consent paperwork. Anything you did attach is kept with the paper.</span>
                </div>
            <?php elseif ($missingRequired): ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">warning</span>
                    <span><?= $missingRequired ?> required
                          <?= $missingRequired === 1 ? 'document is' : 'documents are' ?> not attached.</span>
                </div>
            <?php endif; ?>

            <div class="pd-files">
                <?php foreach ($files as $f): ?>
                    <?php if ($f['href']): ?>
                        <a class="pd-file" href="<?= e($f['href']) ?>" target="_blank" rel="noopener"
                           title="Show <?= e($f['label']) ?> beside the record">
                            <span class="pd-file-name"><?= e($f['label']) ?></span>
                            <span class="pd-file-ico"><span class="material-symbols-outlined">picture_as_pdf</span></span>
                        </a>
                    <?php else: ?>
                        <?php /* Not a link — there is nothing to open. It stays on the page so the
                                 gap is visible, worded as required or simply not needed. */ ?>
                        <div class="pd-file is-missing <?= $f['required'] ? '' : 'is-optional' ?>">
                            <span class="pd-file-name"><?= e($f['label']) ?></span>
                            <span class="pd-file-ico"><span class="material-symbols-outlined">note_add</span></span>
                            <span class="pd-file-state">
                                <span class="material-symbols-outlined"><?= $f['required'] ? 'error' : 'remove' ?></span>
                                <?= $f['required'] ? 'Missing' : 'Not required' ?>
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- The checklist -->
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">checklist</span> Review Checklist</h2>

            <?php if (!$checklist): ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">info</span>
                    <span>Your Research Adviser did not record a checklist for this paper.
                          The files below are what was actually submitted.</span>
                </div>
            <?php endif; ?>

            <div class="pd-check-grid">
                <?php if ($checklist && $checklistGroups['full']): ?>
                    <div>
                        <div class="pd-check-head">Full Manuscript</div>
                        <?php foreach ($chapterItems as $col => $label): ?>
                            <?php $yes = !empty($checklist[$col]); ?>
                            <div class="pd-check-row <?= $yes ? 'is-yes' : ($returned ? 'is-gap' : 'is-no') ?>">
                                <span class="material-symbols-outlined"><?= $yes ? 'check_circle' : ($returned ? 'error' : 'radio_button_unchecked') ?></span>
                                <span class="pd-check-name"><?= e($label) ?></span>
                                <span class="pd-check-state"><?= $yes ? 'Indicated' : ($returned ? 'Missing' : 'Not indicated') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($checklist && $checklistGroups['imrad']): ?>
                    <div>
                        <div class="pd-check-head">IMRaD Sections</div>
                        <?php foreach ($imradItems as $col => $label): ?>
                            <?php $yes = !empty($checklist[$col]); ?>
                            <div class="pd-check-row <?= $yes ? 'is-yes' : ($returned ? 'is-gap' : 'is-no') ?>">
                                <span class="material-symbols-outlined"><?= $yes ? 'check_circle' : ($returned ? 'error' : 'radio_button_unchecked') ?></span>
                                <span class="pd-check-name"><?= e($label) ?></span>
                                <span class="pd-check-state"><?= $yes ? 'Indicated' : ($returned ? 'Missing' : 'Not indicated') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php /* Files are not read from the checklist table — this column reports
                         what is actually attached to the paper, so it cannot disagree with
                         the Uploaded Files card above it. */ ?>
                <div>
                    <div class="pd-check-head">Files</div>
                    <?php /* Straight from $files, the same list the cards above are drawn
                         from, so this column can never contradict them. */ ?>
                <?php foreach ($files as $f): ?>
                        <?php
                        $yes   = (bool)$f['href'];
                        $gap   = !$yes && $f['required'];
                        $state = $yes ? 'Attached' : ($f['required'] ? 'Missing' : 'Not required');
                        ?>
                        <div class="pd-check-row <?= $yes ? 'is-yes' : ($gap ? 'is-gap' : 'is-no') ?>">
                            <span class="material-symbols-outlined"><?= $yes ? 'check_circle' : ($gap ? 'error' : 'remove') ?></span>
                            <span class="pd-check-name"><?= e($f['label']) ?></span>
                            <span class="pd-check-state"><?= e($state) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($checklist): ?>
                <div class="pd-note pd-note--after">
                    <span class="material-symbols-outlined">verified_user</span>
                    <span>Recorded by <?= e($reviewers['faculty'] ?? 'your Research Adviser') ?>
                          when the paper was approved<?= !empty($checklist['created_at'])
                            ? ' on ' . e(date('F j, Y', strtotime($checklist['created_at']))) : '' ?>.</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($status === 'approved'): ?>
            <div class="pd-note">
                <span class="material-symbols-outlined">public</span>
                <span>This paper is published in the
                    <a href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$paper['paper_id'] ?>">public repository</a>,
                    where readers see the version above without your checklist or supporting documents.</span>
            </div>
        <?php endif; ?>

    </div>
</main>

<?php
require ROOT_PATH.'/includes/pdf_dock.php';
require ROOT_PATH.'/includes/scroll_jump.php';
$CARD_COLLAPSE_SELECTOR = '.pd-card';
require ROOT_PATH.'/includes/card_collapse.php';
require ROOT_PATH.'/includes/site_footer.php';
?>
</body>
</html>
