<?php
require_once __DIR__.'/../config/core.php'; 
require_once __DIR__.'/../config/groq_config.php';
require_once __DIR__.'/../config/gdrive_config.php';
start_session_once(); 
$conn=db();
$u = current_user();

// Check guest expiry
if (isset($_SESSION['guest_login']) && isset($_SESSION['guest_expire'])) {
    if (time()> $_SESSION['guest_expire']) {
        header('Location: ../logout.php?from=archive&expired=1');
        exit;
    }
}

$can_view = $u || isset($_SESSION['guest_login']);

$id = (int)($_GET['id'] ?? 0); 
if($id<=0){ header('Location: index.php'); exit; }
$_s = $conn->prepare("SELECT * FROM research_papers WHERE paper_id=? AND current_status = 'approved'");
$_s->bind_param('i', $id); $_s->execute();
$paper = $_s->get_result()->fetch_assoc();
if(!$paper) {
    $_s2 = $conn->prepare("SELECT * FROM papers_archive WHERE paper_id=?");
    $_s2->bind_param('i', $id); $_s2->execute();
    $paper = $_s2->get_result()->fetch_assoc();
}
if(!$paper){ http_response_code(404); echo '<div style="padding:2rem;text-align:center;font-family:sans-serif;"><h2>Paper Not Found</h2><p>The requested paper could not be found.</p><a href="index.php">Back to Archive</a></div>'; exit; }

// Resolve role-specific Groq key — must be defined before the AI extraction block below
$_roleKeyMap = [
    'super_admin' => 'GROQ_API_KEY_SUPERADMIN',
    'admin'       => 'GROQ_API_KEY_ADMIN',
    'faculty'     => 'GROQ_API_KEY_FACULTY',
];
$_roleKeyConst  = $_roleKeyMap[$u['user_role'] ?? ''] ?? 'GROQ_API_KEY';
$archiveGroqKey = defined($_roleKeyConst) ? constant($_roleKeyConst) : null;

// Auto-extract IMRAD data if missing
if (empty($paper['ai_summary'])) {
    try {
        $text = '';
        if (!empty($paper['gdrive_file_id'])) {
            $text = extract_gdrive_pdf_text($paper['gdrive_file_id']);
        }
        
        if ((empty($text) || strlen($text) < 100) && !empty($paper['file_path'])) {
            $localPath = paper_file_disk_path($paper['file_path']);
            if ($localPath !== null) {
                $text = extract_pdf_text($localPath);
            }
        }

        if ($text && strlen($text)> 100) {
            $analysis = generate_statistical_analysis($text, $archiveGroqKey);
            if (!empty($analysis) && isset($analysis['summary'])) {
                $table = isset($paper['archived_date']) ? 'papers_archive' : 'research_papers';
                $stmt = $conn->prepare("UPDATE `$table` SET ai_summary=?, ai_methodology=?, ai_sample_size=?, ai_statistical_methods=?, ai_variables=?, ai_research_field=? WHERE paper_id=?");
                $ai_summary   = $analysis['summary']             ?? '';
                $ai_method    = $analysis['methodology']         ?? '';
                $ai_sample    = $analysis['sample_size']         ?? '';
                $ai_stat      = $analysis['statistical_methods'] ?? '';
                $ai_vars      = $analysis['variables']           ?? '';
                $ai_field     = $analysis['research_field']      ?? '';
                $stmt->bind_param('ssssssi', $ai_summary, $ai_method, $ai_sample, $ai_stat, $ai_vars, $ai_field, $id);
                $stmt->execute();
                $_rp = $conn->prepare("SELECT * FROM `$table` WHERE paper_id=?");
                $_rp->bind_param('i', $id); $_rp->execute();
                $paper = $_rp->get_result()->fetch_assoc();
            }
        }
    } catch (Exception $e) { 
        error_log('IMRAD extraction error: ' . $e->getMessage());
    }
}

/* There is no download feature: a paper is read in place, through the viewer.
   The old ?download=1 endpoint had no button pointing at it and has been
   removed along with the readership counters it fed. */

// Permission check for full access
$can_full_access = $u && in_array($u['user_role'], ['admin', 'super_admin']);

/* Who may open the manuscript itself. Staff roles only — the record is public,
   the file is not. The Head of Academic Programs oversees the output of every
   program, so reading a published paper is squarely part of that; their desk
   deliberately has no approve control, and this does not give them one. */
$can_view_file = $u && in_array($u['user_role'], ['admin', 'faculty', 'super_admin', 'head_academic'], true);

/* Handle Manual AI Regeneration.

   Nothing in the codebase posts regenerate_ai any more — the button that drove
   it is gone, so the only way in is a hand-written request. That is precisely
   why the token check matters: without it any page could make a signed-in
   adviser or coordinator spend Groq quota and overwrite the stored AI summary,
   methodology and field of any paper by id.

   Refused outright rather than redirected: a stale form cannot be the cause
   when no form posts this at all. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_ai']) && ($can_full_access || ($u && $u['user_role'] === 'faculty'))) {
    csrf_verify();
    try {
        $text = '';
        // 1. Try extracting from Google Drive
        if (!empty($paper['gdrive_file_id'])) {
            $text = extract_gdrive_pdf_text($paper['gdrive_file_id']);
        }

        // 1.5 Auto-detect: If extraction failed or ID missing, search GDrive by title
        if (empty($text) || strlen($text) < 100) {
            $foundId = search_gdrive_file_by_name($paper['title']);
            if ($foundId) {
                $newText = extract_gdrive_pdf_text($foundId);
                if ($newText && strlen($newText)> 100) {
                    $text = $newText;
                    // Update DB with the correct file ID
                    $table = isset($paper['archived_date']) ? 'papers_archive' : 'research_papers';
                    $_lnk = $conn->prepare("UPDATE `$table` SET gdrive_file_id=? WHERE paper_id=?");
                    $_lnk->bind_param('si', $foundId, $id); $_lnk->execute();
                    flash('success', 'File auto-detected in Google Drive and linked!');
                }
            }
        }
        
        // 2. Fallback: Try extracting from local file if GDrive failed or returned empty text
        if ((empty($text) || strlen($text) < 100) && !empty($paper['file_path'])) {
            $localPath = paper_file_disk_path($paper['file_path']);
            if ($localPath !== null) {
                $text = extract_pdf_text($localPath);
            }
        }

            if ($text && strlen($text)> 100) {
                $analysis = generate_statistical_analysis($text, $archiveGroqKey);
                
                if (isset($analysis['error'])) {
                    flash('error', 'AI Error: ' . $analysis['error']);
                } elseif (!empty($analysis) && isset($analysis['summary'])) {
                    $table = isset($paper['archived_date']) ? 'papers_archive' : 'research_papers';
                    $stmt = $conn->prepare("UPDATE " . $table . " SET ai_summary=?, ai_methodology=?, ai_sample_size=?, ai_statistical_methods=?, ai_variables=?, ai_research_field=? WHERE paper_id=?");
                    $stmt->bind_param('ssssssi', 
                        $analysis['summary'], 
                        $analysis['methodology'], 
                        $analysis['sample_size'], 
                        $analysis['statistical_methods'], 
                        $analysis['variables'], 
                        $analysis['research_field'], 
                        $id
                    );
                    $stmt->execute();
                    flash('success', 'AI Analysis regenerated successfully using the main paper from Google Drive!');
                    header("Location: view_paper.php?id=$id");
                    exit;
                } else {
                    flash('error', 'AI response was empty or invalid format.');
                }
            }
    } catch (Exception $e) {
        flash('error', 'An error occurred during AI analysis. Please try again.');
        error_log('AI regeneration error for paper ' . $id . ': ' . $e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($paper['title']) ?> · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<?php require_once ROOT_PATH.'/includes/paper_record_css.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Short answers sit together on one line; the long ones are prose below. */
.vp-ai-chips { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .25rem; }
.vp-ai-chip {
    display: inline-flex; align-items: baseline; gap: .4rem;
    padding: .4rem .75rem; border: 1px solid var(--border); border-radius: var(--r-control, 4px);
    background: var(--cream);
}
.vp-ai-chip-label {
    font-size: .625rem; text-transform: uppercase; letter-spacing: .04em; color: var(--grey);
}
.vp-ai-chip-value { font-size: .8125rem; color: var(--maroon); font-weight: 500; }
.timer-badge {
    position: fixed; top: 72px; right: 1.5rem; z-index: 950;
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .4rem .75rem; border-radius: var(--r-badge, 2px);
    background: var(--maroon); color: #fff; font-size: .75rem;
    box-shadow: 0 4px 14px rgba(51,0,0,.2);
}
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<?php if (isset($_SESSION['guest_login']) && isset($_SESSION['guest_expire'])): ?>
    <div class="timer-badge">
        <span class="material-symbols-outlined mi-18">timer</span>
        <span id="guestTimer">Guest session</span>
    </div>
<?php endif; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="<?= e(BASE_URL) ?>/archive/index.php?browse=1">Public Repository</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current">Paper</span>
    </div>
</div>

<main class="wrap">
    <div class="pd-wrap">

        <div class="pd-top">
            <a class="pd-back" href="<?= e(BASE_URL) ?>/archive/index.php?browse=1">
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
                <span class="pd-badge">Published</span>
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

        <?php $keywords = array_values(array_filter(array_map('trim', explode(',', (string)($paper['keywords'] ?? ''))))); ?>
        <?php if ($keywords): ?>
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">sell</span> Keywords</h2>
            <div class="pd-chips">
                <?php foreach ($keywords as $kw): ?><span class="pd-chip"><?= e($kw) ?></span><?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php
        /* The paper as its authors wrote it. Stored as sanitised HTML per
           section; papers filed before the section editors existed have only
           the plain abstract, which stands in for the whole. */
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
        if (!$sections && trim((string)($paper['abstract'] ?? '')) !== '') {
            $sections['abstract'] = '<p>' . nl2br(e((string)$paper['abstract'])) . '</p>';
        }
        ?>
        <?php if ($sections): ?>
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">article</span> The Paper</h2>
            <?php foreach ($sectionLabels as $key => $label): ?>
                <?php if (empty($sections[$key])) continue; ?>
                <div class="pd-section">
                    <h3><?= e($label) ?></h3>
                    <div class="pd-prose pd-prose-scroll"><?= $sections[$key] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($paper['ai_summary'])): ?>
        <div class="pd-card">
            <h2><span class="material-symbols-outlined">auto_awesome</span> At a Glance</h2>

            <?php /* Two of these fields are a couple of words and three run to
                     a thousand characters. Putting them all in the same narrow
                     grid squeezed paragraphs into columns barely wider than the
                     label — so the short ones stay as chips and the long ones
                     are given the full width to read in. */ ?>
            <?php
            $aiChips = array_filter([
                'Research Field' => $paper['ai_research_field'] ?? '',
                'Sample Size'    => $paper['ai_sample_size'] ?? '',
            ], function ($v) { return trim((string)$v) !== ''; });
            $aiBlocks = array_filter([
                'Methodology'         => $paper['ai_methodology'] ?? '',
                'Statistical Methods' => $paper['ai_statistical_methods'] ?? '',
                'Variables'           => $paper['ai_variables'] ?? '',
            ], function ($v) { return trim((string)$v) !== ''; });
            ?>

            <?php if ($aiChips): ?>
                <div class="vp-ai-chips">
                    <?php foreach ($aiChips as $label => $value): ?>
                        <span class="vp-ai-chip">
                            <span class="vp-ai-chip-label"><?= e($label) ?></span>
                            <span class="vp-ai-chip-value"><?= e($value) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="pd-section">
                <h3>Summary</h3>
                <div class="pd-prose"><?= nl2br(e($paper['ai_summary'])) ?></div>
            </div>

            <?php foreach ($aiBlocks as $label => $value): ?>
                <div class="pd-section">
                    <h3><?= e($label) ?></h3>
                    <div class="pd-prose"><?= nl2br(e($value)) ?></div>
                </div>
            <?php endforeach; ?>

            <div class="pd-note pd-note--after">
                <span class="material-symbols-outlined">info</span>
                <span>Summarised automatically from the manuscript. The paper above is the
                      authors' own words.</span>
            </div>

        </div>
        <?php endif; ?>

        <div class="pd-card">
            <h2><span class="material-symbols-outlined">folder_open</span> Manuscript</h2>
            <?php if (!$can_view): ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">lock</span>
                    <span>Sign in to read this paper.</span>
                </div>
            <?php elseif (!$can_view_file): ?>
                <?php /* The record is public; the file itself is not. Supporting
                         documents and the review checklist are never shown here —
                         they belong to the submission, not to the published paper. */ ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">info</span>
                    <span>The full manuscript is available to staff. Everything the authors
                          wrote is on this page.</span>
                </div>
            <?php else: ?>
                <div class="pd-files">
                    <?php $fileHref = paper_file_url($paper['gdrive_file_id'] ?? null, $paper['file_path'] ?? null); ?>
                    <?php if ($fileHref): ?>
                        <a class="pd-file" href="<?= e($fileHref) ?>" target="_blank" rel="noopener"
                           title="Show the manuscript beside the record">
                            <span class="pd-file-name">Manuscript</span>
                            <span class="pd-file-ico"><span class="material-symbols-outlined">picture_as_pdf</span></span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<?php
require ROOT_PATH.'/includes/pdf_dock.php';
require ROOT_PATH.'/includes/scroll_jump.php';
$CARD_COLLAPSE_SELECTOR = '.pd-card';
require ROOT_PATH.'/includes/card_collapse.php';
require ROOT_PATH.'/includes/site_footer.php';
?>
<?php if (isset($_SESSION['guest_login']) && isset($_SESSION['guest_expire'])): ?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* A guest session ends on its own, so the badge counts it down rather than
   letting the page expire mid-read without warning. */
(function () {
    var ends = <?= (int)$_SESSION['guest_expire'] ?> * 1000;
    var el = document.getElementById('guestTimer');
    if (!el) return;
    function tick() {
        var left = Math.max(0, ends - Date.now());
        if (left <= 0) { el.textContent = 'Session ended'; return; }
        var m = Math.floor(left / 60000), s = Math.floor((left % 60000) / 1000);
        el.textContent = m + 'm ' + (s < 10 ? '0' : '') + s + 's left';
        setTimeout(tick, 1000);
    }
    tick();
})();
</script>
<?php endif; ?>
</body>
</html>
