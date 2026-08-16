<?php
/**
 * A reviewer's view of one paper.
 *
 * The same record the author sees, plus the two things only a reviewer needs:
 * a checklist they fill in as they read, and the decision at the foot of it.
 * Opening the paper itself is a link to the file in Drive — the reviewer reads
 * the PDF there and records what they found here.
 *
 * The decision is not carried out on this page. Both buttons post to the
 * reviewer's own desk, which already owns the approve and return handlers with
 * their guards, so there is one place where a paper's status can change rather
 * than two that could drift apart.
 */
require_once __DIR__ . '/../config/core.php';
require_role(['faculty', 'admin']);
require_once __DIR__ . '/../config/workflow.php';
require_once __DIR__ . '/../config/gdrive_config.php';

$conn = db();
$u    = current_user();

$paper_id = (int)($_GET['id'] ?? 0);

/* Which desk this reviewer belongs to, and therefore which papers are theirs
   to read: an adviser sees the papers of the students whose accounts they
   created; the Coordinator sees everything that has reached review. */
$isAdviser = ($u['user_role'] === 'faculty');
$deskUrl   = $isAdviser
    ? BASE_URL . '/app/faculty/faculty_review_dashboard.php'
    : BASE_URL . '/app/admin/admin_review_dashboard.php';

$sql = "SELECT rp.*, us.full_name AS author_name, us.program AS author_program, us.user_id AS author_id
        FROM research_papers rp JOIN users us ON us.user_id = rp.uploaded_by
        WHERE rp.paper_id = ?" . ($isAdviser ? " AND us.created_by = ?" : "");
$ps = $conn->prepare($sql);
if ($isAdviser) $ps->bind_param('ii', $paper_id, $u['user_id']);
else            $ps->bind_param('i', $paper_id);
$ps->execute();
$paper = $ps->get_result()->fetch_assoc();
$ps->close();

if (!$paper) {
    flash('error', 'That paper is not one of yours to review.');
    header('Location: ' . $deskUrl);
    exit;
}

$status = (string)$paper['current_status'];

/* Whether this paper is actually waiting on *this* reviewer. Anything else is
   read-only here — the decision belongs to whoever holds it now. */
$myQueue = $isAdviser
    ? ($status === 'pending_faculty')
    : in_array($status, ['pending_admin', 'pending_admin_l1'], true);

/* ---- The written sections ------------------------------------------------ */
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

/* ---- Files, present and missing ------------------------------------------ */
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
    $label = supporting_doc_label($d['document_type'] ?? '');
    $key   = array_search($label, array_column($expected, 'label'), true);
    $key   = $key === false ? $label : array_keys($expected)[$key];
    if ($link = paper_file_url($d['gdrive_file_id'] ?? null, $d['file_path'] ?? null)) {
        $found[$key] = $link;
    }
}
$ds->close();

$files = [];
foreach ($expected as $key => $info) {
    $files[] = ['key' => $key, 'label' => $info['label'],
                'href' => $found[$key] ?? null, 'required' => $info['required']];
}
$missingRequired = 0;
foreach ($files as $f) if (!$f['href'] && $f['required']) $missingRequired++;

/* ---- The checklist, as it stands ----------------------------------------- */
$cs = $conn->prepare("SELECT * FROM paper_checklist WHERE paper_id = ? ORDER BY checklist_id DESC LIMIT 1");
$cs->bind_param('i', $paper_id);
$cs->execute();
$checklist = $cs->get_result()->fetch_assoc();
$cs->close();

$chapterItems = [
    'full_ch1' => 'Chapter 1', 'full_ch2' => 'Chapter 2', 'full_ch3' => 'Chapter 3',
    'full_ch4' => 'Chapter 4', 'full_ch5' => 'Chapter 5', 'full_references' => 'References',
];
$imradItems = [
    'imrad_intro' => 'Introduction', 'imrad_method' => 'Methodology', 'imrad_result' => 'Results',
    'imrad_discussion' => 'Discussion', 'imrad_references' => 'References',
];

/* An IMRaD paper has no numbered chapters, so that half of the checklist is not
   asked for at all. A full manuscript is asked about both. */
$groups = paper_checklist_groups($paper['manuscript_type'] ?? null);

/* ---- History ------------------------------------------------------------- */
$reviewers = [];
$rv = $conn->prepare(
    "SELECT aw.review_level, us.full_name FROM approval_workflow aw
     JOIN users us ON us.user_id = aw.reviewer_id
     WHERE aw.paper_id = ? AND aw.status = 'approved' ORDER BY aw.reviewed_at ASC");
$rv->bind_param('i', $paper_id);
$rv->execute();
foreach ($rv->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $reviewers[$r['review_level']] = $r['full_name'];
$rv->close();

$returned = null;
$fb = $conn->prepare(
    "SELECT aw.feedback, aw.review_level, aw.reviewed_at, us.full_name
     FROM approval_workflow aw LEFT JOIN users us ON us.user_id = aw.reviewer_id
     WHERE aw.paper_id = ? AND aw.status = 'declined' ORDER BY aw.reviewed_at DESC LIMIT 1");
$fb->bind_param('i', $paper_id);
$fb->execute();
$returned = $fb->get_result()->fetch_assoc() ?: null;
$fb->close();

$keywords = array_values(array_filter(array_map('trim', explode(',', (string)$paper['keywords']))));
$steps = workflow_progress_steps($status, (bool)$returned);
$done  = 0; foreach ($steps as $s) if ($s['state'] === 'done') $done++;
$fill  = count($steps) > 1 ? max(0, min(100, ($done - 1) / (count($steps) - 1) * 100)) : 0;

$approveLabel = $isAdviser ? 'Approve and forward' : 'Approve and publish';
$approveLead  = $isAdviser
    ? 'This forwards the paper to the Research Coordinator, who makes the final decision.'
    : 'This publishes the paper to the public repository, where anyone can read it. It is the last step.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review · <?= e($paper['title']) ?> · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<?php require_once ROOT_PATH.'/includes/paper_record_css.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The site's own link colour — the browser's default blue belongs to nothing
   else on the page. */
.pd-repo-link {
    display: inline-flex; align-items: center; gap: .25rem;
    margin-top: .4rem; font-size: .75rem;
    color: var(--maroon); text-decoration: none;
}
.pd-repo-link:hover { color: var(--dark-maroon); text-decoration: underline; }

/* The checklist is filled in here rather than read, so its rows are controls. */
.rv-check-row {
    display: flex; align-items: center; gap: .5rem;
    padding: .25rem 0; font-size: .8125rem; color: var(--ink); cursor: pointer;
}
.rv-check-row input { accent-color: var(--maroon); flex: 0 0 auto; }
.rv-check-row span { flex: 1 1 auto; }
.rv-check-head {
    display: flex; align-items: center; justify-content: space-between; gap: .5rem;
    font-size: .75rem; font-weight: 600; color: var(--maroon);
    padding-bottom: .5rem; margin-bottom: .5rem; border-bottom: 1px solid var(--border);
}
.rv-tick-all {
    background: none; border: none; padding: 0; cursor: pointer;
    font-family: inherit; font-size: .6875rem; color: var(--grey); text-decoration: underline;
}
.rv-tick-all:hover { color: var(--maroon); }
.rv-field { display: block; margin-top: 1.25rem; }
.rv-field span.rv-label {
    display: block; font-size: .75rem; font-weight: 500; color: var(--ink); margin-bottom: .35rem;
}
.rv-field textarea {
    width: 100%; border: 1px solid var(--border); border-radius: 8px;
    padding: .625rem .75rem; font-family: var(--font-body); font-size: .8125rem;
    color: var(--ink); resize: vertical; background: var(--white);
}
.rv-field textarea:focus { outline: none; border-color: var(--maroon); }
.rv-actions {
    display: flex; align-items: center; gap: .625rem; flex-wrap: wrap; margin-top: 1.25rem;
    padding-top: 1.25rem; border-top: 1px solid var(--border);
}
.rv-actions .rv-spacer { margin-left: auto; font-size: .75rem; color: var(--grey); }
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e($deskUrl) ?>">Review Desk</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Reviewing</span>
    </div>
</div>

<main class="wrap">
    <div class="pd-wrap">

        <div class="pd-top">
            <a class="pd-back" href="<?= e($deskUrl) ?>">
                <span class="material-symbols-outlined mi-18">arrow_back</span> Back
            </a>
            <div class="pd-heading">
                <h1><?= e($paper['title']) ?></h1>
                <p class="pd-authors"><?= e($paper['author_names'] ?: $paper['author_name']) ?></p>
                <div class="pd-meta">
                    <span><?= e(paper_date_display($paper['research_date'] ?? null, $paper['year'] ?? null)) ?></span>
                    <span class="sep">•</span><span><?= e(paper_type_label($paper['paper_type'])) ?></span>
                    <span class="sep">•</span><span>Submitted by <?= e($paper['author_name']) ?></span>
                </div>
                <?php /* Only once published — before that there is no repository
                         page to open. */ ?>
                <?php if ($status === 'approved'): ?>
                    <a class="pd-repo-link" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= (int)$paper_id ?>">
                        <span class="material-symbols-outlined mi-18">open_in_new</span>
                        View in public repository
                    </a>
                <?php endif; ?>
            </div>
            <div class="pd-status">
                Status
                <span class="pd-badge"><?= e(workflow_status_badge_text($status, (bool)$returned)) ?></span>
            </div>
        </div>

        <?php if (!$myQueue): ?>
            <div class="pd-callout is-info">
                <span class="material-symbols-outlined">visibility</span>
                <div>
                    <h2>Read only</h2>
                    <p>This paper is not waiting on you, so it is shown here as a record.
                       <?php if ($status === 'approved'): ?>It has been approved and published.<?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($returned): ?>
            <div class="pd-callout">
                <span class="material-symbols-outlined">undo</span>
                <div>
                    <h2>Previously returned</h2>
                    <p class="pd-said"><?= e($returned['feedback']) ?></p>
                    <span class="pd-who">
                        By <?= e($returned['full_name'] ?: 'a reviewer') ?>
                        <?php if (!empty($returned['reviewed_at'])): ?>
                            on <?= e(date('F j, Y', strtotime($returned['reviewed_at']))) ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <div class="pd-card">
            <div class="card-track" style="grid-template-columns: 1fr auto;">
                <div class="track">
                    <div class="track-line"><div class="track-line-fill" style="width: <?= (int)round($fill) ?>%"></div></div>
                    <?php foreach ($steps as $s): ?>
                        <div class="track-step <?= e($s['state']) ?>">
                            <span class="track-dot">
                                <?php if ($s['state'] === 'done'): ?><span class="material-symbols-outlined">check</span>
                                <?php elseif ($s['state'] === 'current'): ?><span class="material-symbols-outlined">more_horiz</span><?php endif; ?>
                            </span>
                            <span class="track-label"><?= e($s['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="pd-people">
                    <div>Student: <span class="who"><?= e($paper['author_name']) ?></span></div>
                    <?php if ($paper['author_program']): ?>
                        <div>Program: <span class="who"><?= e($paper['author_program']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($reviewers['faculty'])): ?>
                        <div>Research Adviser: <span class="who"><?= e($reviewers['faculty']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <h2><span class="material-symbols-outlined">description</span> Basic Information</h2>
            <div class="pd-facts">
                <?php
                $facts = [
                    'Academic Program'      => $paper['program_category'] ?? '',
                    'Paper / Research Type' => !empty($paper['paper_type']) ? paper_type_label($paper['paper_type']) : '',
                    'Manuscript Type'       => $paper['manuscript_type'] ?? '',
                    'Paper Status'          => $paper['publication_status'] ?? '',
                    'Published In'          => $paper['publication_location'] ?? '',
                    'Date Completed'        => !empty($paper['research_date']) ? date('F j, Y', strtotime($paper['research_date'])) : '',
                    'Submitted'             => !empty($paper['upload_date']) ? date('F j, Y', strtotime($paper['upload_date'])) : '',
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
                <?php foreach ($keywords as $kw): ?><span class="pd-chip"><?= e($kw) ?></span><?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="pd-card">
            <h2><span class="material-symbols-outlined">article</span> Written Sections</h2>
            <?php if (!$sections): ?>
                <div class="pd-note"><span class="material-symbols-outlined">info</span>
                    <span>No written sections were saved with this submission.</span></div>
            <?php else: ?>
                <?php if ($sectionsAreLegacy): ?>
                    <div class="pd-note"><span class="material-symbols-outlined">info</span>
                        <span>This paper predates the separate section boxes, so only the abstract was kept as text.</span></div>
                <?php endif; ?>
                <?php foreach ($sectionLabels as $key => $label): ?>
                    <?php if (empty($sections[$key])) continue; ?>
                    <div class="pd-section">
                        <h3><?= e($label) ?></h3>
                        <div class="pd-prose pd-prose-scroll"><?= $sections[$key] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="pd-card">
            <h2><span class="material-symbols-outlined">folder_open</span> Uploaded Files</h2>
            <?php if (!$docsRequired): ?>
                <div class="pd-note"><span class="material-symbols-outlined">info</span>
                    <span>A <?= e(paper_type_label($paper['paper_type'])) ?> does not require the ethics and
                          consent paperwork, so anything missing below is not a fault.</span></div>
            <?php elseif ($missingRequired): ?>
                <div class="pd-note"><span class="material-symbols-outlined">warning</span>
                    <span><?= $missingRequired ?> required
                          <?= $missingRequired === 1 ? 'document is' : 'documents are' ?> not attached.</span></div>
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

        <?php /* The decision. Posted to the desk, which owns the handlers. */ ?>
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">checklist</span> Review Checklist</h2>

            <?php if (!$myQueue): ?>
                <div class="pd-note"><span class="material-symbols-outlined">lock</span>
                    <span>Only the reviewer this paper is waiting on can change the checklist.</span></div>
                <div class="pd-check-grid">
                    <?php
                    $shown = [];
                    if ($groups['full'])  $shown[] = ['Full Manuscript', $chapterItems];
                    if ($groups['imrad']) $shown[] = ['IMRaD Sections',  $imradItems];
                    foreach ($shown as $group): ?>
                        <div>
                            <div class="pd-check-head"><?= e($group[0]) ?></div>
                            <?php foreach ($group[1] as $col => $label): $yes = !empty($checklist[$col]); ?>
                                <div class="pd-check-row <?= $yes ? 'is-yes' : 'is-no' ?>">
                                    <span class="material-symbols-outlined"><?= $yes ? 'check_circle' : 'radio_button_unchecked' ?></span>
                                    <span class="pd-check-name"><?= e($label) ?></span>
                                    <span class="pd-check-state"><?= $yes ? 'Indicated' : 'Not indicated' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <form method="post" action="<?= e($deskUrl) ?>" id="reviewForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="paper_id" value="<?= (int)$paper_id ?>">
                    <input type="hidden" name="action" id="reviewAction" value="approve">

                    <div class="pd-check-grid">
                        <?php if ($groups['full']): ?>
                        <div>
                            <div class="rv-check-head">
                                <span>Full Manuscript</span>
                                <button type="button" class="rv-tick-all" data-group="full">Tick all</button>
                            </div>
                            <div data-group-box="full">
                                <?php foreach ($chapterItems as $col => $label): ?>
                                    <label class="rv-check-row">
                                        <input type="checkbox" name="<?= e($col) ?>" <?= !empty($checklist[$col]) ? 'checked' : '' ?>>
                                        <span><?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($groups['imrad']): ?>
                        <div>
                            <div class="rv-check-head">
                                <span>IMRaD Sections</span>
                                <button type="button" class="rv-tick-all" data-group="imrad">Tick all</button>
                            </div>
                            <div data-group-box="imrad">
                                <?php foreach ($imradItems as $col => $label): ?>
                                    <label class="rv-check-row">
                                        <input type="checkbox" name="<?= e($col) ?>" <?= !empty($checklist[$col]) ? 'checked' : '' ?>>
                                        <span><?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <label class="rv-field">
                        <span class="rv-label" id="feedbackLabel">Note for the student (optional when approving)</span>
                        <textarea name="feedback" id="reviewFeedback" rows="4"
                                  placeholder="What the student should know."></textarea>
                    </label>

                    <div class="rv-actions">
                        <button type="button" class="btn-sm-maroon" id="btnApprove">
                            <span class="material-symbols-outlined mi-18">check_circle</span>
                            <?= e($approveLabel) ?>
                        </button>
                        <button type="button" class="btn-sm-outline" id="btnReturn">
                            <span class="material-symbols-outlined mi-18">undo</span>
                            Return with feedback
                        </button>
                        <span class="rv-spacer" id="tickCount"></span>
                    </div>
                </form>
            <?php endif; ?>
        </div>

    </div>
</main>

<?php if ($myQueue): ?>
<!-- Confirming the decision. Approving moves the paper on and returning it
     sends it back, so both are asked about — and the question names what has
     not been ticked, because that is exactly what a reviewer in a hurry misses. -->
<div class="papel-dialog-backdrop" id="confirmDialog" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
  <div class="papel-dialog">
    <div class="papel-dialog-head">
      <span class="material-symbols-outlined" id="confirmIcon">check_circle</span>
      <h2 id="confirmTitle">Approve this paper?</h2>
    </div>
    <div class="papel-dialog-body">
      <p id="confirmLead" style="margin:0 0 .75rem;"></p>
      <p id="confirmGaps" style="margin:0 0 .75rem;color:var(--dark-maroon);"></p>
      <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.8125rem;cursor:pointer;">
        <input type="checkbox" id="confirmDone" style="accent-color:var(--maroon);margin-top:.2rem;">
        <span id="confirmDoneLabel">I have finished reading this paper and the decision is mine to record.</span>
      </label>
    </div>
    <div class="papel-dialog-foot">
      <button type="button" class="btn-sm-outline" id="confirmNo">Keep reviewing</button>
      <button type="button" class="btn-sm-maroon" id="confirmYes" disabled>Confirm</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('reviewForm');
    if (!form) return;

    var APPROVE_LABEL = <?= json_encode($approveLabel) ?>;
    var APPROVE_LEAD  = <?= json_encode($approveLead) ?>;

    var dlg   = document.getElementById('confirmDialog');
    var done  = document.getElementById('confirmDone');
    var yes   = document.getElementById('confirmYes');
    var boxes = form.querySelectorAll('input[type="checkbox"]');
    var count = document.getElementById('tickCount');

    function ticked() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) n++; });
        return n;
    }
    function showCount() {
        count.textContent = ticked() + ' of ' + boxes.length + ' checklist items ticked';
    }
    boxes.forEach(function (b) { b.addEventListener('change', showCount); });
    showCount();

    form.querySelectorAll('.rv-tick-all').forEach(function (btn) {
        btn.addEventListener('click', function () {
            /* The button carries data-group too, so a plain [data-group=...]
               lookup found the button itself and ticked nothing. The column of
               checkboxes has its own attribute. */
            var group = form.querySelector('[data-group-box="' + btn.dataset.group + '"]');
            if (!group) return;
            var inputs = group.querySelectorAll('input[type="checkbox"]');
            var turnOn = Array.prototype.some.call(inputs, function (i) { return !i.checked; });
            inputs.forEach(function (i) { i.checked = turnOn; });
            btn.textContent = turnOn ? 'Clear all' : 'Tick all';
            showCount();
        });
    });

    function ask(action) {
        var approving = action === 'approve';
        document.getElementById('reviewAction').value = action;

        document.getElementById('confirmTitle').textContent = approving ? APPROVE_LABEL + '?' : 'Return this paper?';
        document.getElementById('confirmIcon').textContent  = approving ? 'check_circle' : 'undo';
        document.getElementById('confirmLead').textContent  = approving
            ? APPROVE_LEAD
            : 'This sends the paper back to the student as a draft so they can revise and submit it again.';

        // Name what is still unticked rather than only counting it.
        var gaps = [];
        boxes.forEach(function (b) {
            if (!b.checked) gaps.push(b.parentNode.querySelector('span').textContent.trim());
        });
        var gapEl = document.getElementById('confirmGaps');
        gapEl.textContent = (approving && gaps.length)
            ? 'Still unticked: ' + gaps.join(', ') + '. The student sees this checklist.'
            : '';

        document.getElementById('confirmDoneLabel').textContent = approving
            ? 'I have finished reading this paper and I am ready to forward it.'
            : 'I have written what the student needs to change.';

        done.checked = false;
        yes.disabled = true;
        yes.textContent = approving ? APPROVE_LABEL : 'Return to student';
        dlg.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        dlg.classList.remove('open');
        document.body.style.overflow = '';
    }

    document.getElementById('btnApprove').addEventListener('click', function () { ask('approve'); });
    document.getElementById('btnReturn').addEventListener('click', function () {
        // Returning without saying why leaves the student guessing.
        var note = document.getElementById('reviewFeedback');
        if (note.value.trim() === '') {
            document.getElementById('feedbackLabel').textContent = 'What needs to change (required to return)';
            note.focus();
            return;
        }
        ask('decline');
    });

    done.addEventListener('change', function () { yes.disabled = !done.checked; });
    yes.addEventListener('click', function () { close(); form.submit(); });
    document.getElementById('confirmNo').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dlg.classList.contains('open')) close();
    });
});
</script>
<?php
require ROOT_PATH.'/includes/pdf_dock.php';
require ROOT_PATH.'/includes/scroll_jump.php';
$CARD_COLLAPSE_SELECTOR = '.pd-card';
require ROOT_PATH.'/includes/card_collapse.php';
require ROOT_PATH.'/includes/site_footer.php';
?>
</body>
</html>
