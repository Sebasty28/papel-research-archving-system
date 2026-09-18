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

/* The repository listing already only links a paper's title for someone who
   can view it — everyone else gets a "Login to view details" button instead
   of an <a href>, per $can_view in archive/index.php. That only ever hid the
   link on the page a visitor was shown; the id in the URL was never actually
   checked here, so pasting or typing it read the full record anyway.
   require_login() is the same gate the rest of the site's protected pages
   use, and it already treats a valid guest pass as signed in — a guest's
   session is set by login_user() same as a member's — so this closes the
   direct-link gap without touching Guest Login itself. */
require_login();

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
   deliberately has no approve control, and this does not give them one. The
   Librarian is the one who grants everyone else's manuscript requests, so
   reading the file themselves is squarely part of that job too. */
$can_view_file = $u && in_array($u['user_role'], ['admin', 'faculty', 'super_admin', 'head_academic', 'librarian'], true);

/* A student who has been granted temporary access to this specific manuscript
   sees it too, for as long as the grant lasts — checked here, at read time,
   the same lazy-expiry style the guest-session timer above and
   student_expiry_date() both use. */
if (!$can_view_file && $u && $u['user_role'] === 'student') {
    $can_view_file = student_manuscript_access((int)$u['user_id'], $id);
}

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

/* A student asking a Librarian for temporary access to this paper's actual
   PDF. Anyone can post the form since this is a public page, but only a
   signed-in student can turn it into a request — everyone else is silently
   ignored rather than shown an error, since there is no way for them to have
   seen the button that posts it. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_manuscript'])) {
    csrf_verify();

    if ($u && $u['user_role'] === 'student') {
        $existing = student_manuscript_request((int)$u['user_id'], $id);
        $blocked = $existing && (
            $existing['status'] === 'pending'
            || ($existing['status'] === 'granted' && strtotime($existing['expires_at']) > time())
        );
        $fileHref = paper_file_url($paper['gdrive_file_id'] ?? null, $paper['file_path'] ?? null);

        if ($blocked) {
            flash('error', 'You already have a request in progress for this manuscript.');
        } elseif (!$fileHref) {
            flash('error', 'This paper has no manuscript file on record.');
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO manuscript_requests (paper_id, student_user_id, status, created_at)
                 VALUES (?, ?, 'pending', NOW())");
            $stmt->bind_param('ii', $id, $u['user_id']);
            $stmt->execute();
            $stmt->close();

            $lib = $conn->query("SELECT user_id FROM users WHERE user_role = 'librarian' AND is_active = 1");
            $note = $u['full_name'] . ' requested access to the manuscript for "' . $paper['title'] . '".';
            foreach ($lib->fetch_all(MYSQLI_ASSOC) as $librow) {
                create_notification((int)$librow['user_id'], $id, 'manuscript', $note);
            }
            flash('success', 'Request sent. A librarian will review it shortly.');
        }
    }
    header('Location: view_paper.php?id=' . $id . '#pd-sec-manuscript');
    exit;
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
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
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
    <div class="pd-wrap has-rail">

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
                <br>
                <button type="button" class="pd-rail-toggle" id="pdRailToggle"
                        title="Hide the contents and citation panel" aria-label="Hide the contents and citation panel" aria-pressed="false">
                    <span class="material-symbols-outlined" id="pdRailToggleIcon">right_panel_close</span>
                </button>
            </div>
        </div>

        <div class="pd-layout" id="pdLayout">
        <div class="pd-main">

        <div class="pd-card" id="pd-sec-info">
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

        <div class="pd-card" id="pd-sec-manuscript">
            <h2><span class="material-symbols-outlined">folder_open</span> Manuscript</h2>
            <?php flash_banner(); ?>
            <?php if (!$can_view): ?>
                <div class="pd-note">
                    <span class="material-symbols-outlined">lock</span>
                    <span>Sign in to read this paper.</span>
                </div>
            <?php elseif (!$can_view_file && $u && $u['user_role'] === 'student'): ?>
                <?php
                /* A student gets a way to ask a librarian for the actual file,
                   rather than only ever reading the staff-only note the elseif
                   below shows everyone else. Reaching this branch at all means
                   there is no currently-active grant — one would have already
                   made $can_view_file true above, landing in the final else
                   instead — so this only ever has pending / denied / lapsed /
                   never-asked to show, each with its own line, followed by the
                   "ask" button (skipped only while a request is already
                   pending). */
                $mrFileHref = paper_file_url($paper['gdrive_file_id'] ?? null, $paper['file_path'] ?? null);
                $mr         = student_manuscript_request((int)$u['user_id'], $id);
                ?>
                <?php if (!$mrFileHref): ?>
                    <div class="pd-note">
                        <span class="material-symbols-outlined">info</span>
                        <span>No manuscript file is on record for this paper.</span>
                    </div>
                <?php else: ?>
                    <?php if ($mr && $mr['status'] === 'pending'): ?>
                        <div class="pd-note">
                            <span class="material-symbols-outlined">hourglass_top</span>
                            <span>Your request is waiting for a librarian to review it.</span>
                        </div>
                    <?php elseif ($mr && $mr['status'] === 'denied'): ?>
                        <div class="pd-note">
                            <span class="material-symbols-outlined">block</span>
                            <span>Your last request for this manuscript was denied. You can ask again.</span>
                        </div>
                    <?php elseif ($mr && $mr['status'] === 'granted'): ?>
                        <div class="pd-note">
                            <span class="material-symbols-outlined">lock_clock</span>
                            <span>Your access to this manuscript has expired. You can request it again.</span>
                        </div>
                    <?php else: ?>
                        <div class="pd-note">
                            <span class="material-symbols-outlined">info</span>
                            <span>The full manuscript can be unlocked for a limited time by a librarian.
                                  Everything the authors wrote is on this page either way.</span>
                        </div>
                    <?php endif; ?>
                    <?php if (!$mr || $mr['status'] !== 'pending'): ?>
                        <form method="post">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="request_manuscript" value="1">
                            <button type="submit" class="btn-sm-maroon">
                                <span class="material-symbols-outlined mi-18">lock_open</span>
                                <span>Request manuscript access</span>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
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
                <?php
                /* can_view_file is true here either because this reader is
                   staff, or because a student's grant on this specific paper
                   is still open — the latter is worth a reminder of when it
                   runs out, since it is the one case where the file link
                   above can silently stop working again on its own. */
                if ($u && $u['user_role'] === 'student'):
                    $mr = student_manuscript_request((int)$u['user_id'], $id);
                    if ($mr && $mr['status'] === 'granted' && strtotime($mr['expires_at']) > time()):
                ?>
                    <div class="pd-note pd-note--after">
                        <span class="material-symbols-outlined">timer</span>
                        <span>Access expires <?= e(date('F j, Y g:i A', strtotime($mr['expires_at']))) ?>.</span>
                    </div>
                <?php
                    endif;
                endif;
                ?>
            <?php endif; ?>
        </div>

        <?php $keywords = array_values(array_filter(array_map('trim', explode(',', (string)($paper['keywords'] ?? ''))))); ?>
        <?php if ($keywords): ?>
        <div class="pd-card" id="pd-sec-keywords">
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
        <div class="pd-card" id="pd-sec-paper">
            <h2><span class="material-symbols-outlined">article</span> The Paper</h2>
            <?php foreach ($sectionLabels as $key => $label): ?>
                <?php if (empty($sections[$key])) continue; ?>
                <div class="pd-section" id="pd-sub-<?= e($key) ?>">
                    <h3><?= e($label) ?></h3>
                    <div class="pd-prose pd-prose-scroll"><?= $sections[$key] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($paper['ai_summary'])): ?>
        <div class="pd-card" id="pd-sec-glance">
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

        </div><!-- /.pd-main -->

        <aside class="pd-side">

            <?php
            /* One entry per card actually on the page, plus one per written
               section inside "The Paper" — a paper filed before every section
               had an editor might only have an abstract, so this walks the
               same $sections the card itself rendered rather than assuming
               all six exist. */
            $toc = [
                ['id' => 'pd-sec-info',       'label' => 'Basic Information', 'children' => []],
                ['id' => 'pd-sec-manuscript', 'label' => 'Manuscript',        'children' => []],
            ];
            if ($keywords) {
                $toc[] = ['id' => 'pd-sec-keywords', 'label' => 'Keywords', 'children' => []];
            }
            if ($sections) {
                $children = [];
                foreach ($sectionLabels as $key => $label) {
                    if (empty($sections[$key])) continue;
                    $children[] = ['id' => 'pd-sub-' . $key, 'label' => $label];
                }
                $toc[] = ['id' => 'pd-sec-paper', 'label' => 'The Paper', 'children' => $children];
            }
            if (!empty($paper['ai_summary'])) {
                $toc[] = ['id' => 'pd-sec-glance', 'label' => 'At a Glance', 'children' => []];
            }
            ?>
            <div class="pd-card pd-toc">
                <h2><span class="material-symbols-outlined">toc</span><span class="pd-card-title"> Table of Contents</span></h2>
                <nav class="pd-toc-nav" aria-label="Sections on this page">
                    <ul class="pd-toc-list">
                        <?php foreach ($toc as $item): ?>
                            <li>
                                <a href="#<?= e($item['id']) ?>" class="pd-toc-link" data-toc-target="<?= e($item['id']) ?>"><?= e($item['label']) ?></a>
                                <?php if ($item['children']): ?>
                                    <ul class="pd-toc-sublist">
                                        <?php foreach ($item['children'] as $child): ?>
                                            <li><a href="#<?= e($child['id']) ?>" class="pd-toc-link pd-toc-sublink" data-toc-target="<?= e($child['id']) ?>"><?= e($child['label']) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>

            <?php $citation = paper_apa_citation($paper, rtrim(BASE_URL, '/') . '/archive/view_paper.php?id=' . $id); ?>
            <div class="pd-card pd-cite" data-collapse-default="closed">
                <h2><span class="material-symbols-outlined">format_quote</span><span class="pd-card-title"> Cite This Paper</span></h2>
                <p class="pd-cite-label">APA (7th Edition)</p>
                <blockquote class="pd-cite-text" id="pdCiteText"><?= e($citation) ?></blockquote>
                <button type="button" class="pd-cite-copy" id="pdCiteCopy" data-copy-text="<?= e($citation) ?>">
                    <span class="material-symbols-outlined" id="pdCiteCopyIcon">content_copy</span>
                    <span id="pdCiteCopyLabel">Copy citation</span>
                </button>
            </div>

        </aside>

        </div><!-- /.pd-layout -->

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
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var HEADER_OFFSET = 76;   // clears the sticky site header once the crumb bar has scrolled away

    /* ----- Show/hide the contents & citation rail ----- */
    var railToggle = document.getElementById('pdRailToggle');
    var railIcon   = document.getElementById('pdRailToggleIcon');
    var layout     = document.getElementById('pdLayout');
    if (railToggle && layout) {
        function setRail(collapsed) {
            layout.classList.toggle('is-rail-collapsed', collapsed);
            railIcon.textContent = collapsed ? 'right_panel_open' : 'right_panel_close';
            railToggle.title = collapsed ? 'Show the contents and citation panel' : 'Hide the contents and citation panel';
            railToggle.setAttribute('aria-label', railToggle.title);
            railToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        }

        railToggle.addEventListener('click', function () {
            setRail(!layout.classList.contains('is-rail-collapsed'));
        });

        /* The manuscript opens down the right of the window, which is where
           the rail runs: with both up, the record is squeezed into whatever is
           left between them. The rail gives way while the manuscript is being
           read, and comes back as it was when the panel closes — a rail the
           reader had already hidden stays hidden. */
        var railWasOpen = false;
        document.addEventListener('papel:pdf-dock-open', function () {
            railWasOpen = !layout.classList.contains('is-rail-collapsed');
            if (railWasOpen) { setRail(true); }
        });
        document.addEventListener('papel:pdf-dock-close', function () {
            if (railWasOpen) { setRail(false); }
            railWasOpen = false;
        });
    }

    /* ----- Move the rail to the other side, like the Public Repository's own
       panel-side tool — same icon pair, same stored preference, so a choice
       made on either page carries over to this one. Built after
       card_collapse.php's own script has already run, so its chevron is
       already in each heading and the swap icon can be slotted in beside it
       without corrupting the "Hide/Show <title>" label that script reads off
       the heading's text before it adds anything of its own.

       One icon for the whole rail is enough — it lives on the Table of
       Contents heading only, not repeated on Cite This Paper below it. */
    var SIDE_KEY = 'papel_sidebar_side';
    var htmlEl = document.documentElement;

    function labelSwap(btn) {
        var to = htmlEl.classList.contains('sidebar-left') ? 'right' : 'left';
        btn.title = 'Move panel to the ' + to;
        btn.setAttribute('aria-label', btn.title);
    }

    document.querySelectorAll('.pd-toc > h2').forEach(function (head) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pd-side-swap';
        btn.setAttribute('aria-controls', 'pdLayout');
        ['side-icon-left:dock_to_right', 'side-icon-right:dock_to_left'].forEach(function (pair) {
            var bits = pair.split(':');
            var i = document.createElement('span');
            i.className = 'material-symbols-outlined ' + bits[0];
            i.textContent = bits[1];
            btn.appendChild(i);
        });
        labelSwap(btn);
        var chevron = head.querySelector('.card-collapse-btn');
        head.insertBefore(btn, chevron || null);
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.pd-side-swap');
        if (!btn) return;
        var left = !htmlEl.classList.contains('sidebar-left');
        htmlEl.classList.toggle('sidebar-left', left);
        document.querySelectorAll('.pd-side-swap').forEach(labelSwap);
        try { localStorage.setItem(SIDE_KEY, left ? 'left' : 'right'); } catch (err) {}
    });

    var tocLinks = Array.prototype.slice.call(document.querySelectorAll('.pd-toc-link'));

    tocLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            var target = document.getElementById(link.getAttribute('data-toc-target'));
            if (!target) return;
            e.preventDefault();
            var top = target.getBoundingClientRect().top + window.pageYOffset - HEADER_OFFSET;
            window.scrollTo({ top: top, behavior: 'smooth' });
            history.replaceState(null, '', '#' + target.id);
        });
    });

    /* Scroll-spy only tracks the finest-grained heading on the page — a
       parent entry that has its own sub-list would otherwise stay "active"
       for as long as any of its children are, since it spans all of them. */
    var spyLinks = tocLinks.filter(function (link) {
        var li = link.closest('li');
        return !(li && li.querySelector('ul.pd-toc-sublist'));
    });
    var spyTargets = spyLinks.map(function (link) {
        return document.getElementById(link.getAttribute('data-toc-target'));
    }).filter(Boolean);

    if (spyTargets.length && 'IntersectionObserver' in window) {
        var visible = {};
        function paintActive() {
            var activeId = null;
            for (var i = 0; i < spyTargets.length; i++) {
                if (visible[spyTargets[i].id]) { activeId = spyTargets[i].id; break; }
            }
            /* The last section can be shorter than the gap the rootMargin
               below leaves at the bottom of the viewport, so once the page is
               scrolled as far as it goes, that section's top never rises into
               the observer's narrow "active" band and it is never reported as
               intersecting. Being at the bottom of the page wins outright. */
            if (window.innerHeight + window.pageYOffset >= document.documentElement.scrollHeight - 2) {
                activeId = spyTargets[spyTargets.length - 1].id;
            }
            tocLinks.forEach(function (l) {
                l.classList.toggle('is-active', l.getAttribute('data-toc-target') === activeId);
            });
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { visible[entry.target.id] = entry.isIntersecting; });
            paintActive();
        }, { rootMargin: '-' + HEADER_OFFSET + 'px 0px -70% 0px', threshold: 0 });
        spyTargets.forEach(function (t) { observer.observe(t); });
        window.addEventListener('scroll', paintActive, { passive: true });
    }

    /* ----- Copy the citation ----- */
    var copyBtn = document.getElementById('pdCiteCopy');
    if (!copyBtn) return;
    var icon  = document.getElementById('pdCiteCopyIcon');
    var label = document.getElementById('pdCiteCopyLabel');

    function showCopied() {
        copyBtn.classList.add('is-copied');
        icon.textContent = 'check';
        label.textContent = 'Copied';
        setTimeout(function () {
            copyBtn.classList.remove('is-copied');
            icon.textContent = 'content_copy';
            label.textContent = 'Copy citation';
        }, 2000);
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); showCopied(); } catch (err) {}
        document.body.removeChild(ta);
    }

    copyBtn.addEventListener('click', function () {
        var text = copyBtn.getAttribute('data-copy-text') || '';
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(showCopied).catch(function () { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    });
});
</script>
</body>
</html>
