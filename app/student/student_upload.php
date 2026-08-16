<?php
require_once '../../config/core.php';
require_once '../../config/gdrive_config.php';
require_once '../../config/groq_config.php';
require_role(['student']);
$conn = db();
$u = current_user();

// Handle final upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    csrf_verify();
    
    try {
        // Validate required supporting documents
        $required = ['ethics_clearance','consent_form','data_collection'];
        $provided = array_filter(array_map('strval', (array)($_POST['document_type'] ?? [])));
        foreach ($required as $t) { 
            if (!in_array($t, $provided, true)) { 
                throw new Exception("Missing required document: $t"); 
            } 
        }
        
        // Validate main PDF
        validate_pdf_upload($_FILES['research_pdf']);
        
        // Validate paper type
        $paperType = trim($_POST['paper_type'] ?? '');
        $validTypes = ['article','capstone','conference','journal','project','research','thesis']; // Sorted for binary search
        if (!binary_search_exists($paperType, $validTypes)) {
            throw new Exception('Invalid paper type');
        }
        
        $pdf = $_FILES['research_pdf'];
        if ($pdf['size']> 50*1024*1024) { 
            throw new Exception('PDF too large (max 50MB)'); 
        }
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($pdf['tmp_name']) !== 'application/pdf') { 
            throw new Exception('Only PDF files allowed'); 
        }
        
        // Get user program for folder structure
        $userStmt = $conn->prepare("SELECT program FROM users WHERE user_id=?");
        $userStmt->bind_param('i', $u['user_id']);
        $userStmt->execute();
        $userResult = $userStmt->get_result()->fetch_assoc();
        $program = $userResult['program'] ?? 'Other';
        $userStmt->close();

        $programFolder = get_program_folder($program);

        // Get metadata from form
        $title = trim($_POST['title'] ?? '') ?: 'Untitled';
        $authors = trim($_POST['authors'] ?? '') ?: $u['full_name'];
        $year = (int)($_POST['year'] ?? date('Y'));
        if ($year < 1900 || $year> 2100) $year = (int)date('Y');

        // Optional full completion date; when given it is authoritative and
        // its year wins over the Year box.
        $researchDate = trim($_POST['research_date'] ?? '');
        if ($researchDate !== '') {
            $rd = DateTime::createFromFormat('Y-m-d', $researchDate);
            if ($rd && $rd->format('Y-m-d') === $researchDate) {
                $ry = (int)$rd->format('Y');
                if ($ry >= 1900 && $ry <= 2100) { $year = $ry; } else { $researchDate = ''; }
            } else { $researchDate = ''; }
        }
        $researchDateParam = $researchDate !== '' ? $researchDate : null;
        $keywords = trim($_POST['keywords'] ?? '');
        $abstract = trim($_POST['abstract'] ?? '');

        // Save research PDF with program/type/year/month/submission_folder structure
        $y = date('Y'); $m = date('m');
        $unique = uniqid('paper_', true);
        $safeTitle = safe_name($title);
        if(strlen($safeTitle)> 30) $safeTitle = substr($safeTitle, 0, 30);
        $submissionFolder = "{$unique}_{$safeTitle}";
        
        $destDir = __DIR__ . "/uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder";
        ensure_dir($destDir);
        
        $safeBase = safe_name(pathinfo($pdf['name'], PATHINFO_FILENAME));
        $destPath = $destDir . "/{$safeBase}.pdf";
        
        if (!move_uploaded_file($pdf['tmp_name'], $destPath)) {
            throw new Exception('Failed to save PDF');
        }
        
        // Upload to Google Drive
        $gdriveId = null;
        if (function_exists('upload_paper_to_gdrive')) {
            $gdriveId = upload_paper_to_gdrive($destPath, $title . '.pdf', $programFolder, (string)$year, $paperType, $title, 'PAPEL - DOCUMENTS', $u['user_id']);
        }
        if (!$gdriveId) {
            throw new Exception('Google Drive upload failed. Please check system configuration.');
        }
        
        // Generate AI Analysis immediately upon upload
        $ai_summary = ''; $ai_methodology = ''; $ai_statistical_methods = ''; $ai_variables = ''; $ai_research_field = ''; $ai_sample_size = '';
        try {
            $pdfText = extract_pdf_text($destPath);
            if ($pdfText && strlen($pdfText)> 100) {
                $analysis = generate_statistical_analysis($pdfText);
                if (!empty($analysis) && !isset($analysis['error'])) {
                    $ai_summary = $analysis['summary'] ?? '';
                    $ai_methodology = $analysis['methodology'] ?? '';
                    $ai_statistical_methods = $analysis['statistical_methods'] ?? '';
                    $ai_variables = $analysis['variables'] ?? '';
                    $ai_research_field = $analysis['research_field'] ?? '';
                    $ai_sample_size = $analysis['sample_size'] ?? '';
                }
            }
        } catch (Exception $e) {
            error_log("AI Extraction failed during upload: " . $e->getMessage());
        }

        // Insert into database
        $relPath = "uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder/" . basename($destPath);
        $fullUrlPath = rtrim(BASE_URL, '/') . '/app/student/' . $relPath;
        $fileSize = filesize($destPath);
        
        $stmt = $conn->prepare("INSERT INTO research_papers 
            (title, author_names, year, research_date, abstract, keywords, file_path, file_size, uploaded_by, current_status, paper_type, gdrive_file_id,
             ai_analyzed_at, ai_summary, ai_methodology, ai_statistical_methods, ai_variables, ai_research_field, ai_sample_size)
            VALUES (?,?,?,?,?,?,?,?,?,'draft',?,?,NOW(),?,?,?,?,?,?)");
        
        $stmt->bind_param('ssissssisssssssss', 
            $title, $authors, $year, $researchDateParam, $abstract, $keywords, $fullUrlPath, $fileSize, $u['user_id'], $paperType, $gdriveId,
            $ai_summary, $ai_methodology, $ai_statistical_methods, $ai_variables, $ai_research_field, $ai_sample_size
        );
        
        if (!$stmt->execute()) {
            @unlink($destPath);
            throw new Exception('Database error: Could not save paper');
        }
        
        $paper_id = $stmt->insert_id;
        
        // Save supporting documents
        $supDir = $destDir . "/supporting_documents";
        ensure_dir($supDir);
        
        if (!empty($_FILES['supporting_docs'])) {
            $files = $_FILES['supporting_docs'];
            $types = (array)$_POST['document_type'];
            
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($finfo->file($files['tmp_name'][$i]) !== 'application/pdf') continue;
                
                $type = $types[$i] ?? 'other';
                $safe = safe_name(pathinfo($files['name'][$i], PATHINFO_FILENAME));
                $dest = $supDir . "/{$paper_id}_{$type}_{$safe}.pdf";
                
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    $rel = "uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder/supporting_documents/" . basename($dest);
                    $fullUrlRel = rtrim(BASE_URL, '/') . '/app/student/' . $rel;
                    
                    // Upload supporting doc to GDrive
                    $docGdriveId = null;
                    if (function_exists('upload_supporting_doc_to_gdrive')) {
                        $docGdriveId = upload_supporting_doc_to_gdrive($dest, ucfirst(str_replace('_', ' ', $type)) . '.pdf', $programFolder, (string)$year, $paperType, $title, $u['user_id']);
                    }
                    if (!$docGdriveId) {
                        throw new Exception("Failed to upload supporting document ($type) to Google Drive.");
                    }
                    $st = $conn->prepare("INSERT INTO supporting_documents (paper_id, document_type, file_path, gdrive_file_id) VALUES (?,?,?,?)");
                    $st->bind_param('isss', $paper_id, $type, $fullUrlRel, $docGdriveId);
                    $st->execute();
                    
                    // Remove local file to ensure GDrive storage only
                    // @unlink($dest); // Temporarily disabled to allow local file access
                }
            }
        }
        
        echo json_encode(['success' => true, 'paper_id' => $paper_id]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        $safe = safe_error_message($e, 'Upload failed. Please try again.');
        echo json_encode(['success' => false, 'error' => $safe['message'], 'reference' => $safe['reference']]);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upload Research · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.dropzone {
    border: 2px dashed #ced4da; 
    border-radius: .5rem; 
    padding: 24px; 
    text-align: center; 
    background: #fafafa; 
    cursor: pointer;
}
.dropzone.dragover { 
    background: #e3f2fd; 
    border-color: #2196f3; 
}
.ai-badge { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    color: white; 
    padding: 4px 10px; 
    border-radius: 6px; 
    font-size: 0.75rem; 
    font-weight: 600; 
}
.ai-extracted { 
    background: #e8f5e9; 
}
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h5 mb-3">
                        Upload Research Paper 
                    </h1>
                    
                    <?php if(!is_gdrive_connected()): ?>
                        <div class="alert alert-danger text-center p-4 mb-4">
                            <i class="bi bi-exclamation-triangle-fill" style="font-size: 2rem;"></i>
                            <h4 class="mt-2">Google Drive Not Configured</h4>
                            <p class="mb-0">The system Google Drive connection is not set up. Please contact your administrator.</p>
                        </div>
                        <style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">#uploadForm { display: none; }</style>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <strong>Upload:</strong> Please fill in the details and upload your research paper PDF.<br>
                        <strong>Required:</strong> Ethics Clearance, Consent Form, Data Collection documents.
                    </div>

                    <form id="uploadForm" enctype="multipart/form-data">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Paper Type</label>
                            <select class="form-select" name="paper_type" required>
                                <option value="">Select Type...</option>
                                <option value="research">Research Paper</option>
                                <option value="capstone">Capstone</option>
                                <option value="thesis">Thesis</option>
                                <option value="project">Project</option>
                                <option value="journal">Journal Article</option>
                                <option value="conference">Conference Paper</option>
                                <option value="article">Article</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Research PDF</label>
                            <div class="input-group">
                                <input type="file" class="form-control" id="research_pdf" name="research_pdf" 
                                       accept="application/pdf" required>
                            </div>
                            <small class="text-muted">Maximum 50MB</small>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="title" id="title" 
                                       placeholder="Enter research title" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year</label>
                                <input type="number" class="form-control" name="year" id="year" 
                                       min="1900" max="2100" value="<?= date('Y') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date Completed <span class="text-muted">(Optional)</span></label>
                                <input type="date" class="form-control" name="research_date" id="research_date"
                                       min="1900-01-01" max="2100-12-31">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Authors (comma-separated)</label>
                                <input type="text" class="form-control" name="authors" id="authors" 
                                       placeholder="e.g., Juan Dela Cruz, Maria Clara">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Keywords (comma-separated)</label>
                                <input type="text" class="form-control" name="keywords" id="keywords" 
                                       placeholder="e.g., technology, education, innovation">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Abstract</label>
                                <textarea class="form-control" name="abstract" id="abstract" rows="4" 
                                          placeholder="Enter abstract here..."></textarea>
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6>Supporting Documents (PDF only)</h6>
                        <div id="supportingList">
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <select name="document_type[]" class="form-select" required>
                                        <option value="">Choose type...</option>
                                        <option value="ethics_clearance">Ethics Clearance (required)</option>
                                        <option value="consent_form">Consent Form (required)</option>
                                        <option value="data_collection">Data Collection (required)</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <input type="file" name="supporting_docs[]" class="form-control" 
                                           accept="application/pdf" required>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="btnAddDoc" class="btn btn-sm btn-outline-secondary mb-3">
                            + Add Another Document
                        </button>

                        <div class="mb-3">
                            <div class="progress" style="height: 24px;">
                                <div id="progressBar" class="progress-bar" style="width: 0%">0%</div>
                            </div>
                            <small id="statusText" class="text-muted mt-2 d-block"></small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="btnSubmit">
                                Upload Research
                            </button>
                            <a href="student_dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
// Add supporting document row
document.getElementById('btnAddDoc').addEventListener('click', () => {
    const list = document.getElementById('supportingList');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2';
    row.innerHTML = `
        <div class="col-md-4">
            <select name="document_type[]" class="form-select" required>
                <option value="">Choose type...</option>
                <option value="ethics_clearance">Ethics Clearance (required)</option>
                <option value="consent_form">Consent Form (required)</option>
                <option value="data_collection">Data Collection (required)</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="col-md-8">
            <input type="file" name="supporting_docs[]" class="form-control" accept="application/pdf" required>
        </div>
    `;
    list.appendChild(row);
});

// Form submission
document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('btnSubmit');
    const progressBar = document.getElementById('progressBar');
    const statusText = document.getElementById('statusText');
    
    btn.disabled = true;
    btn.textContent = 'Uploading...';
    statusText.textContent = 'Uploading files...';
    
    const formData = new FormData(this);
    
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const percent = Math.round((e.loaded / e.total) * 100);
            progressBar.style.width = percent + '%';
            progressBar.textContent = percent + '%';
            
            if (percent === 100) {
                statusText.textContent = 'Processing...';
            }
        }
    });
    
    xhr.addEventListener('load', function() {
        try {
            const result = JSON.parse(xhr.responseText);
            
            if (xhr.status === 200 && result.success) {
                alert('Upload successful!');
                window.location.href = 'student_dashboard.php';
            } else {
                alert('Error: ' + (result.error || 'Upload failed'));
                btn.disabled = false;
                btn.textContent = 'Upload Research';
                statusText.textContent = '';
            }
        } catch (error) {
            alert('Error: ' + error.message);
            btn.disabled = false;
            btn.textContent = 'Upload Research';
            statusText.textContent = '';
        }
    });
    
    xhr.open('POST', 'student_upload.php');
    xhr.send(formData);
});
</script>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>