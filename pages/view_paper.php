<?php
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../config/gdrive_config.php';
start_session_once();
$u = current_user();
$conn = db();

// Guest check
$is_guest = !$u || (isset($u['user_role']) && $u['user_role'] === 'guest') || isset($_SESSION['guest_login']);

$paper_id = (int)($_GET['id'] ?? 0);
if ($paper_id <= 0) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM research_papers WHERE paper_id=?");
$stmt->bind_param('i', $paper_id);
$stmt->execute();
$paper = $stmt->get_result()->fetch_assoc();

if (!$paper) { http_response_code(404); header('Location: index.php'); exit; }

// Fetch supporting documents
$docsStmt = $conn->prepare("SELECT * FROM supporting_documents WHERE paper_id=? AND gdrive_file_id IS NOT NULL AND gdrive_file_id != ''");
$docsStmt->bind_param('i', $paper_id);
$docsStmt->execute();
$docs = $docsStmt->get_result();

// Visibility Logic
$show_keywords = !$is_guest;
$show_full_paper = !$is_guest;
$can_download = $u && in_array($u['user_role'], ['super_admin', 'admin', 'librarian']); // Admin = Research Coordinator

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($paper['title']) ?> · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/Luz0x+O0E7kE2Eir3D" crossorigin="anonymous">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
    .paper-header { background: white; padding: 40px 0; border-bottom: 1px solid #e0e0e0; margin-bottom: 30px; }
    .paper-title { font-weight: 800; color: #810403; }
    .meta-badge { font-size: 0.9rem; padding: 8px 16px; border-radius: 20px; background: #fcf8f7; color: #810403; font-weight: 600; display: inline-block; margin-right: 10px; border: 1px solid #dca92c; }
    .section-title { font-weight: 700; color: #475569; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 1px; margin-bottom: 15px; border-bottom: 2px solid #dca92c; display: inline-block; padding-bottom: 5px; }
    .abstract-text { font-size: 1.1rem; line-height: 1.8; color: #334155; text-align: justify; }
    .imrad-card { background: #fff; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .blur-text { color: transparent; text-shadow: 0 0 8px rgba(0,0,0,0.5); user-select: none; }
    /* Paper Preview Style */
    .paper-preview { background: white; padding: 40px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px; }
    .paper-preview h5 { font-weight: 800; color: #1e293b; margin-top: 25px; margin-bottom: 15px; text-transform: uppercase; font-size: 1.1rem; }
    .paper-preview p { text-align: justify; line-height: 1.7; color: #334155; margin-bottom: 15px; }
    .paper-preview .preview-title { text-align: center; font-weight: 800; font-size: 1.5rem; margin-bottom: 10px; color: #0f172a; text-transform: uppercase; }
    .paper-preview .preview-meta { text-align: center; margin-bottom: 30px; color: #64748b; font-style: italic; }
    .paper-preview .abstract-section { margin-bottom: 30px; padding: 20px; background: #f8fafc; border-radius: 8px; }
    .paper-preview .imrad-section { margin-bottom: 30px; }
    /* Rich text stored by the upload page's section editors. Only the tags the
       sanitiser allows can appear here, so the rules stay deliberately narrow. */
    /* Matches the upload editor: justified, 1.5 line height — so a paper reads
       the same way it was written. */
    .rich-text { text-align: justify; line-height: 1.5; color: #334155; }
    .rich-text p { margin-bottom: 15px; line-height: 1.5; }
    .rich-text p:last-child { margin-bottom: 0; }
    .rich-text ul, .rich-text ol { margin: 0 0 15px 1.5rem; text-align: left; }
    .rich-text li { margin-bottom: 6px; }
    .rich-text blockquote { margin: 0 0 15px; padding-left: 14px; color: #475569; }
    /* A wide results table scrolls inside its own section rather than forcing
       the whole page sideways. */
    .rich-text { overflow-x: auto; }
    .rich-text table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 0.9rem; }
    /* currentColor keeps the rules matched to the body text colour, the same way
       they are shown in the upload editor. */
    .rich-text th, .rich-text td { border: 1px solid currentColor; background: #fff; padding: 8px 10px; text-align: left; vertical-align: top; }
    .rich-text th { font-weight: 600; }
    .rich-text caption { caption-side: top; text-align: left; padding-bottom: 6px; font-size: 0.85rem; color: #64748b; }
    /* Cell fills and border colours arrive as inline styles, which already beat
       the rules above; these only cover links, which carry no inline styling. */
    .rich-text a { color: #810403; text-decoration: underline; }
    .rich-text a:hover { color: #630000; }
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="paper-header">
    <div class="container">
        <a href="javascript:history.back()" class="text-decoration-none text-muted mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back</a>
        <h1 class="paper-title display-5 mb-3"><?= e($paper['title']) ?></h1>
        <div class="mb-3">
            <span class="meta-badge"><i class="bi bi-calendar3"></i> <?= e($paper['year']) ?></span>
            <span class="meta-badge"><i class="bi bi-journal-bookmark"></i> <?= e(ucfirst($paper['paper_type'])) ?></span>
            <span class="meta-badge"><i class="bi bi-people"></i> <?= e($paper['author_names']) ?></span>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <div class="col-lg-8">
            <!-- Paper Preview (IMRAD Format) -->
            <div class="paper-preview">
                <div class="preview-title"><?= e($paper['title']) ?></div>
                <div class="preview-meta">
                    <?= e($paper['author_names']) ?><br>
                    <?= e($paper['program_category'] ?? 'Polytechnic University of the Philippines') ?>
                </div>

                <?php
                // Papers submitted through the section editors carry each IMRAD
                // part as sanitised HTML in imrad_content. Older rows only have
                // the plain-text abstract (and sometimes an ai_summary write-up),
                // so fall back to rendering those the way they always were.
                $imrad = [];
                if (!empty($paper['imrad_content'])) {
                    $decoded = json_decode($paper['imrad_content'], true);
                    if (is_array($decoded)) $imrad = $decoded;
                }
                $sectionTitles = [
                    'introduction'       => 'Introduction',
                    'methodology'        => 'Methodology',
                    'results_discussion' => 'Results and Discussion',
                    'conclusion'         => 'Conclusion',
                ];
                ?>

                <div class="abstract-section">
                    <h5 class="text-center">Abstract</h5>
                    <?php if (!empty($imrad['abstract'])): ?>
                        <div class="rich-text"><?= rich_text_sanitize($imrad['abstract']) ?></div>
                    <?php else: ?>
                        <p><?= nl2br(e($paper['abstract'])) ?></p>
                    <?php endif; ?>
                    <p class="mt-3 mb-0"><strong>Keywords:</strong> <?= e($paper['keywords']) ?></p>
                </div>

                <?php foreach ($sectionTitles as $key => $heading): ?>
                    <?php if (!empty($imrad[$key])): ?>
                        <div class="imrad-section">
                            <h5 class="text-center"><?= e($heading) ?></h5>
                            <div class="rich-text"><?= rich_text_sanitize($imrad[$key]) ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if (empty($imrad) && !empty($paper['ai_summary'])): ?>
                    <div class="imrad-section">
                        <h5 class="text-center">IMRAD Write-up</h5>
                        <p style="white-space: pre-line;"><?= nl2br(e($paper['ai_summary'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Full Paper (Restricted) -->
            <div class="mb-5">
                <div class="section-title">Full Paper</div>
                <?php if($show_full_paper): ?>
                    <div class="card p-4 text-center bg-light border-0">
                        <i class="bi bi-file-earmark-pdf text-danger display-4 mb-3"></i>
                        <h5>Full Document Available</h5>
                        <div class="d-flex justify-content-center gap-3 mt-3">
                            <?php if($paper['gdrive_file_id']): ?>
                                <a href="<?= get_gdrive_link($paper['gdrive_file_id']) ?>" target="_blank" class="btn btn-primary px-4"><i class="bi bi-eye"></i> View Paper</a>
                            <?php else: ?>
                                <a href="../<?= e($paper['file_path']) ?>" target="_blank" class="btn btn-primary px-4"><i class="bi bi-eye"></i> View Paper</a>
                            <?php endif; ?>

                            <?php if($can_download): ?>
                                <a href="download.php?id=<?= $paper['paper_id'] ?>" class="btn btn-outline-dark px-4"><i class="bi bi-download"></i> Download</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-lock-fill me-2"></i>
                        <strong>Restricted Access:</strong> Guests cannot view the full paper. Please <a href="../archive/index.php?login_modal=1">login</a> to view.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Keywords (Restricted) -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <div class="section-title mb-3">Keywords</div>
                    <?php if($show_keywords): ?>
                        <div>
                            <?php foreach(explode(',', $paper['keywords']) as $k): ?>
                                <span class="badge bg-secondary me-1 mb-1"><?= e(trim($k)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="blur-text">
                            Lorem ipsum dolor sit amet keywords hidden
                        </div>
                        <small class="text-muted d-block mt-2"><i class="bi bi-eye-slash"></i> Hidden for guests</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Supporting Documents (Restricted) -->
            <?php if($show_full_paper && $docs->num_rows> 0): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <div class="section-title mb-3">Supporting Documents</div>
                    <div class="d-flex flex-column gap-2">
                        <?php while($doc = $docs->fetch_assoc()): ?>
                            <?php 
                                $docName = ucwords(str_replace('_', ' ', $doc['document_type']));
                                $docLink = $doc['gdrive_file_id'] ? get_gdrive_link($doc['gdrive_file_id']) : '#';
                            ?>
                            <a href="<?= e($docLink) ?>" target="_blank" class="btn btn-sm btn-outline-secondary text-start">
                                <i class="bi bi-file-earmark-pdf me-2"></i> <?= e($docName) ?>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="section-title mb-3">About this Entry</div>
                    <ul class="list-unstyled small text-muted">
                        <li class="mb-2"><i class="bi bi-clock me-2"></i> Uploaded: <?= date('M d, Y', strtotime($paper['upload_date'])) ?></li>
                        <li class="mb-2"><i class="bi bi-building me-2"></i> Program: <?= e($paper['program_category'] ?? 'N/A') ?></li>
                        <li><i class="bi bi-shield-check me-2"></i> Status: <?= ucfirst($paper['current_status']) ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>