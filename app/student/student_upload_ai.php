<?php
error_reporting(0);
ini_set('display_errors', '0');
require_once '../../config/core.php';
/* Anyone who writes a paper, which is everyone.

   Staff upload their own work here too, and their submissions are published the
   moment they are made: they are the people who would otherwise be reviewing
   it, so there is nobody above them in the chain to ask.

   Drafts remain student-only, and deliberately. Every page that manages a draft
   is student-only — the Drafts tab on the student dashboard,
   student_draft_delete.php, student_cancel_submission.php — so a draft saved by
   a member of staff would be invisible the moment they left: no way to resume
   it, no way to delete it, and it would sit in the database counting as
   somebody's unfinished work forever. That is what closed this page to them in
   the first place. Rather than reopen that hole, staff fill the form and submit
   it in one sitting, and $canDraft below is what enforces it. */
require_role(['student', 'faculty', 'admin', 'super_admin', 'head_academic', 'librarian']);
require_once '../../config/groq_config.php';
require_once '../../config/gdrive_config.php';
require_once '../../ai/rate_limiter.php';
$conn = db();
$u = current_user();

/* A student's paper goes to their adviser; everyone else's is published as it is
   submitted. $canDraft follows from that: a draft only makes sense while there
   is a review to prepare for, and only students can see one afterwards. */
$isStudent = (($u['user_role'] ?? '') === 'student');
$canDraft  = $isStudent;

/* The student's own programme.
   A student belongs to one, it is set when their adviser creates the account,
   and every paper they submit is for it, so asking them to pick it from a list
   of ten was a question with one right answer that they could get wrong. It is
   read here rather than taken from the session, which only carries identity.
   The select below opens on it; a draft that already records a programme still
   wins, because the draft is applied after the page renders. */
$myProgram = '';
if ($isStudent) {
    $pq = $conn->prepare("SELECT program FROM users WHERE user_id = ? LIMIT 1");
    $pq->bind_param('i', $u['user_id']);
    $pq->execute();
    if ($row = $pq->get_result()->fetch_assoc()) {
        $myProgram = trim((string)($row['program'] ?? ''));
    }
} else {
    /* Staff do not belong to a programme, they belong to the faculty, so the
       question is not asked: the field states the answer instead of offering a
       list of degrees none of which is theirs. The value is one the rest of the
       system already knows — get_program_folder() files it under FACULTY, and
       program_code() prints it as it is. */
    $myProgram = 'Faculty Member';
}

// AJAX: Get recent publication locations for suggestions
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_locations') {
    header('Content-Type: application/json');
    try {
        $stmt = $conn->prepare("SELECT DISTINCT publication_location FROM research_papers WHERE publication_location IS NOT NULL AND publication_location != '' ORDER BY upload_date DESC LIMIT 50");
        $stmt->execute();
        $res = $stmt->get_result();
        $locations = [];
        while ($row = $res->fetch_assoc()) {
            $locations[] = $row['publication_location'];
        }
        echo json_encode(['success' => true, 'data' => array_values(array_unique($locations))]);
    } catch (Exception $e) { error_log('Location fetch error: ' . $e->getMessage()); echo json_encode(['success' => false, 'error' => 'Failed to load locations.']); }
    exit;
}

// AI extraction step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'extract_ai') {
  try {
    csrf_verify();
    
    // ── Rate Limiting: Max 10 AI extractions per hour per user ──
    // TEMPORARILY DISABLED for testing — re-enable before production
    // $limiter = new RateLimiter($conn);
    // $rateCheck = $limiter->check((int) $u['user_id'], 'ai_extract', 10, 3600);
    // if (!$rateCheck['allowed']) {
    //     http_response_code(429);
    //     echo json_encode(['success' => false, 'message' => $rateCheck['message']]);
    //     exit;
    // }
    
    validate_pdf_upload($_FILES['research_pdf']);
    $pdf = $_FILES['research_pdf']; 
    
    // AI processing
    $aiMeta = [];
    
    if (!defined('GROQ_API_KEY') || empty(GROQ_API_KEY)) {
      throw new Exception('Groq API Key is not configured in the system.');
    }
    
    $pdfText = extract_pdf_text($pdf['tmp_name']);
    if (!$pdfText || strlen($pdfText) < 50) {
      throw new UserFacingException('No readable text could be found in this PDF. It may be a scan of a printed page, password-protected, or empty. Choose "Fill Manually" to enter the details yourself.');
    }
    $modelChoice = $_POST['model_choice'] ?? '1';
    $extracted = extract_metadata_with_groq($pdfText, $modelChoice);
    if (is_array($extracted)) {
        $aiMeta = $extracted;
    } else {
        // A rate limit is not a problem with the student's paper, and telling
        // them to "try again" without saying why sends them round in circles.
        $failure = function_exists('groq_last_failure') ? groq_last_failure() : null;
        $code = (int)($failure['http_code'] ?? 0);
        if ($code === 429) {
            throw new UserFacingException(
                'Both AI engines have reached their usage limit for the moment. '
                . 'Wait a minute and press Extract again, or choose "Fill Manually" to type '
                . 'the details in yourself. Your paper is fine — this is a service limit.'
            );
        }
        if ($code >= 500) {
            throw new UserFacingException('The AI service is temporarily unavailable. Please try again shortly, or choose "Fill Manually".');
        }
        throw new UserFacingException('The AI could not identify the details in this document. You can choose "Fill Manually" to enter them yourself.');
    }
    
    // Safe handling of array/string fields
    $authors = $u['full_name'];
    if (isset($aiMeta['authors'])) {
        $authors = is_array($aiMeta['authors']) ? implode(', ', $aiMeta['authors']) : (string)$aiMeta['authors'];
    }

    $keywords = '';
    if (isset($aiMeta['keywords'])) {
        $keywords = is_array($aiMeta['keywords']) ? implode(', ', $aiMeta['keywords']) : (string)$aiMeta['keywords'];
    }

    // The abstract is the only section taken from the paper, and it is copied
    // verbatim rather than generated. The student writes the Introduction,
    // Methodology and Results and Discussion themselves.
    $result = [
      'title' => $aiMeta['title'] ?? 'Untitled',
      'authors' => $authors,
      'year' => $aiMeta['year'] ?? (int)date('Y'),
      'keywords' => $keywords,
      'abstract' => trim($aiMeta['abstract'] ?? ''),
      'ai_summary' => '',
      'ai_methodology' => '',
      'ai_statistical_methods' => '',
      'ai_variables' => '',
      'ai_sample_size' => '',
      'ai_research_field' => ''
    ];
    
    /* Similarity detection used to run here, comparing this abstract against
       the last fifty approved ones through the AI, one request per batch of
       ten. It has been removed: a repository records work that has already
       been reviewed and approved, and it holds no rule about two papers
       resembling each other, so the figure was informational only and
       nothing acted on it. Extraction is correspondingly quicker. */

    echo json_encode(['success'=>true,'data'=>$result]); 
    exit;
    
  } catch (Exception $e) {
    http_response_code(500);
    // Messages we wrote for the student are shown as written; anything else
    // stays generic so internal detail never reaches the browser. Either way
    // the full exception is logged against a reference.
    $shown = ($e instanceof UserFacingException)
        ? $e->getMessage()
        : 'AI extraction failed. Please try again, or choose "Fill Manually".';
    $safe = safe_error_message($e, $shown);
    echo json_encode(['success'=>false,'message'=>$safe['message'],'reference'=>$safe['reference']]);
    exit;
  }
}


/* ---------------------------------------------------------------------------
   Save (or update) a draft.

   Everything here tolerates blanks: a draft is unfinished by definition. The
   only hard requirement is that the row belongs to the signed-in student and
   is still a draft, so this can never overwrite a submitted paper.

   No Google Drive call — that belongs to submission. A draft must save even
   when Drive is unreachable, which is exactly when a student most needs their
   work kept.
   --------------------------------------------------------------------------- */
/* AJAX: a picture pasted, dropped or chosen inside a section editor.

   Pictures are stored as files and referenced by URL rather than inlined into
   the section HTML as data. That is not a preference: the four sections go into
   one column, and MariaDB's max_allowed_packet here is 1 MB, so a single
   screenshot carried as base64 would fail the save outright and take the whole
   paper with it. Files also let a reader's browser cache them.

   They are written under uploads/, which carries an .htaccess that switches PHP
   execution off for anything below it. */
const SECTION_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const SECTION_IMAGE_TYPES = [
    IMAGETYPE_PNG  => ['png',  'image/png'],
    IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
    IMAGETYPE_GIF  => ['gif',  'image/gif'],
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_section_image') {
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json');
  try {
    csrf_verify();

    $f = $_FILES['image'] ?? null;
    if (!$f || !is_uploaded_file($f['tmp_name'] ?? '') || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
      throw new UserFacingException('That picture did not arrive. Try pasting it again.');
    }
    if ((int)$f['size'] > SECTION_IMAGE_MAX_BYTES) {
      throw new UserFacingException('That picture is larger than 5 MB. Crop it, or save it at a smaller size, and paste it again.');
    }

    /* getimagesize() reads the file's own header rather than trusting its name
       or the type the browser declared, so a renamed script cannot pass itself
       off as a screenshot. It is core PHP, not GD, which this install does not
       load, and it is also why the file is never re-encoded here: the browser
       has already redrawn it through a canvas before sending. */
    $info = @getimagesize($f['tmp_name']);
    $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
    if (!isset(SECTION_IMAGE_TYPES[$type])) {
      throw new UserFacingException('That file is not a picture the repository can show. Use PNG, JPEG, GIF or WebP.');
    }
    [$ext, $expectMime] = SECTION_IMAGE_TYPES[$type];

    // The header said one thing; libmagic has to agree, or the file is carrying
    // two formats at once and one of them was not the one that was checked.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    if ($mime !== $expectMime) {
      throw new UserFacingException('That file is not a picture the repository can show. Use PNG, JPEG, GIF or WebP.');
    }
    // A picture claiming enormous dimensions is either broken or aimed at the
    // memory of whatever eventually opens it.
    if ((int)$info[0] < 1 || (int)$info[1] < 1 || (int)$info[0] > 12000 || (int)$info[1] > 12000) {
      throw new UserFacingException('That picture is too large to display. Resize it and paste it again.');
    }

    $uid = (int)$u['user_id'];
    $dir = ROOT_PATH . '/uploads/section_images/' . $uid;
    ensure_dir($dir);
    // Random rather than derived from the original name: two students pasting
    // "Screenshot 2026-08-19.png" must not collide, and the stored name is what
    // the sanitiser matches on, so it has to be a shape we control.
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
      throw new Exception('move_uploaded_file failed for section image');
    }

    $rel = 'uploads/section_images/' . $uid . '/' . $name;
    echo json_encode([
      'success' => true,
      'url'     => upload_url($rel),
      'width'   => (int)$info[0],
      'height'  => (int)$info[1],
    ]);

    /* One upload in fifty tidies up after drafts that were abandoned, the same
       way the login throttle sweeps its own stale rows. It runs after the
       answer has gone out, so nobody waits for it, and it can never turn a
       stored picture into a failed one. */
    if (random_int(1, 50) === 1) {
      if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
      else { flush(); }
      try { section_images_sweep(); }
      catch (Throwable $sweepError) {
        error_log('Section image sweep failed: ' . $sweepError->getMessage());
      }
    }
    exit;

  } catch (Throwable $e) {
    http_response_code(($e instanceof UserFacingException) ? 400 : 500);
    if (!($e instanceof UserFacingException)) {
      error_log('Section image upload failed: ' . $e->getMessage());
    }
    echo json_encode([
      'success' => false,
      'message' => ($e instanceof UserFacingException)
          ? $e->getMessage()
          : 'That picture could not be saved. Try again in a moment.',
    ]);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_draft') {
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json');
  try {
    csrf_verify();
    /* The page hides every draft control from staff, so this only fires for a
       stale tab or a hand-made request. Refused rather than honoured: a draft
       they cannot see again is worse than no draft. */
    if (!$canDraft) {
      throw new UserFacingException('Drafts are for student submissions. Your paper is published as soon as you submit it.');
    }
    $conn = db();

    $draftId = (int)($_POST['draft_id'] ?? 0);
    $title   = trim($_POST['title'] ?? '');
    $authors = trim($_POST['authors'] ?? '');
    if ($title === '')   { $title = 'Untitled draft'; }
    if ($authors === '') { $authors = $u['full_name'] ?? ''; }

    $year = (int)($_POST['year'] ?? 0);
    if ($year < 1900 || $year > 2100) { $year = (int)date('Y'); }

    $researchDate = trim($_POST['research_date'] ?? '');
    $rd = $researchDate !== '' ? DateTime::createFromFormat('Y-m-d', $researchDate) : false;
    $researchDateParam = ($rd && $rd->format('Y-m-d') === $researchDate) ? $researchDate : null;

    // Sections are stored exactly as on submit, minus the "must not be empty"
    // rule, so a half-written paper keeps its formatting and its tables.
    $sections = [];
    foreach (array_keys(paper_section_labels()) as $key) {
      $sections[$key] = rich_text_sanitize((string)($_POST[$key] ?? ''));
    }
    $imradContent = json_encode($sections, JSON_UNESCAPED_UNICODE);
    $abstract     = rich_text_to_plain($sections['abstract']);

    $keywords    = trim($_POST['keywords'] ?? '');
    $paperType   = trim($_POST['paper_type'] ?? '');
    $researchTy  = trim($_POST['research_type'] ?? '');
    $manuscript  = manuscript_types_posted($_POST['manuscript_type'] ?? []);
    $statusArr   = $_POST['paper_status'] ?? [];
    $pubStatus   = is_array($statusArr) ? implode(', ', $statusArr) : '';
    $pubLocation = trim($_POST['publication_location'] ?? '');
    $program     = trim($_POST['program_category'] ?? '') ?: $myProgram;

    // A draft may carry a PDF or not. When one is sent it is stored locally so
    // the student does not have to re-attach it next time; Drive is untouched.
    $filePath = null;
    if (!empty($_FILES['research_pdf']) && $_FILES['research_pdf']['error'] === UPLOAD_ERR_OK) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      if ($finfo->file($_FILES['research_pdf']['tmp_name']) === 'application/pdf') {
        $draftDir = __DIR__ . '/uploads/drafts/' . (int)$u['user_id'];
        ensure_dir($draftDir);
        $safe = safe_name(pathinfo($_FILES['research_pdf']['name'], PATHINFO_FILENAME));
        $dest = $draftDir . '/' . time() . '_' . $safe . '.pdf';
        if (move_uploaded_file($_FILES['research_pdf']['tmp_name'], $dest)) {
          $filePath = 'uploads/drafts/' . (int)$u['user_id'] . '/' . basename($dest);
        }
      }
    }

    /* Only ever update this student's own unsubmitted draft. A draft that has
       since been submitted or deleted is no longer somewhere to save into —
       the id is dropped and the work is kept as a new draft instead, because
       refusing the save outright would lose it. */
    $draftRowFile = null;
    if ($draftId > 0) {
      $own = $conn->prepare("SELECT file_path FROM research_papers WHERE paper_id=? AND uploaded_by=? AND current_status='draft' LIMIT 1");
      $own->bind_param('ii', $draftId, $u['user_id']);
      $own->execute();
      $row = $own->get_result()->fetch_assoc();
      $own->close();
      if ($row) $draftRowFile = $row['file_path'];
      else      $draftId = 0;
    }

    if ($draftId > 0) {
      if ($filePath === null) {
        $filePath = $draftRowFile;
      } elseif ($draftRowFile) {
        /* A replacement PDF arrived, so the one it replaces is now unreferenced.
           Each save used to leave its predecessor behind: one draft saved four
           times left four PDFs on disk, and only the last was ever read again.
           Only files under this student's own draft folder are removed — a path
           anywhere else belongs to a submitted paper and is not ours to touch. */
        $mine = 'uploads/drafts/' . (int)$u['user_id'] . '/';
        if (strpos($draftRowFile, $mine) === 0) {
          $stale = __DIR__ . '/' . $draftRowFile;
          if (is_file($stale)) { @unlink($stale); }
        }
      }

      $sql = "UPDATE research_papers SET title=?, author_names=?, year=?, research_date=?, abstract=?,
              imrad_content=?, keywords=?, file_path=?, paper_type=?, research_type=?, manuscript_type=?,
              publication_status=?, publication_location=?, program_category=?
              WHERE paper_id=? AND uploaded_by=? AND current_status='draft'";
      $st = $conn->prepare($sql);
      $st->bind_param('ssisssssssssssii',
        $title, $authors, $year, $researchDateParam, $abstract, $imradContent, $keywords,
        $filePath, $paperType, $researchTy, $manuscript, $pubStatus, $pubLocation, $program,
        $draftId, $u['user_id']);
      $st->execute();
    } else {
      if ($filePath === null) { $filePath = ''; }
      $sql = "INSERT INTO research_papers
              (title, author_names, year, research_date, abstract, imrad_content, keywords,
               file_path, uploaded_by, current_status, paper_type, research_type, manuscript_type,
               publication_status, publication_location, program_category)
              VALUES (?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,?,?)";
      $st = $conn->prepare($sql);
      $st->bind_param('ssisssssissssss',
        $title, $authors, $year, $researchDateParam, $abstract, $imradContent, $keywords,
        $filePath, $u['user_id'], $paperType, $researchTy, $manuscript,
        $pubStatus, $pubLocation, $program);
      $st->execute();
      $draftId = $conn->insert_id;
    }

    echo json_encode(['success' => true, 'draft_id' => $draftId]);
    exit;

  } catch (Exception $e) {
    http_response_code(500);
    $shown = ($e instanceof UserFacingException)
      ? $e->getMessage()
      : 'The draft could not be saved.';
    $safe = safe_error_message($e, $shown);
    echo json_encode(['success' => false, 'message' => $safe['message'], 'reference' => $safe['reference']]);
    exit;
  }
}


/* Resuming a draft. The dashboard links here with ?draft=<id>; the row is only
   loaded when it belongs to the signed-in student and is still a draft, so a
   guessed id cannot open somebody else's paper. */
$draftRow = null;
if (isset($_GET['draft'])) {
  $dId = (int)$_GET['draft'];
  if ($dId > 0) {
    $dq = db()->prepare("SELECT * FROM research_papers WHERE paper_id=? AND uploaded_by=? AND current_status='draft' LIMIT 1");
    $dq->bind_param('ii', $dId, $u['user_id']);
    $dq->execute();
    $draftRow = $dq->get_result()->fetch_assoc() ?: null;
  }
}

/* Which drafts this student still has.
 *
 * The copy kept on the device is what drives the "Continue your draft?" prompt,
 * and deleting a draft from the dashboard never touched it — so the prompt kept
 * coming back for work the student had already thrown away, and answering
 * Continue saved it again as a brand new draft. The page now knows which ids
 * are real, so a local copy pointing at a draft that is gone can be recognised
 * as stale and dropped. */
$liveDraftIds = [];
$lq = db()->prepare(
  "SELECT paper_id FROM research_papers WHERE uploaded_by=? AND current_status='draft'");
$lq->bind_param('i', $u['user_id']);
$lq->execute();
foreach ($lq->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
  $liveDraftIds[] = (int)$r['paper_id'];
}

// Handed to the page as JSON so the same restore path serves both a local
// autosave and a draft fetched from the dashboard.
$draftPayload = null;
if ($draftRow) {
  $sections = [];
  if (!empty($draftRow['imrad_content'])) {
    $decoded = json_decode($draftRow['imrad_content'], true);
    if (is_array($decoded)) $sections = $decoded;
  }
  $draftPayload = [
    'id'     => (int)$draftRow['paper_id'],
    'fields' => array_merge([
      'title'                => $draftRow['title'] === 'Untitled draft' ? '' : $draftRow['title'],
      'authors'              => $draftRow['author_names'],
      'year'                 => $draftRow['year'],
      'research_date'        => $draftRow['research_date'],
      'keywords'             => $draftRow['keywords'],
      'paper_type'           => $draftRow['paper_type'],
      'research_type'        => $draftRow['research_type'],
      'manuscript_type'      => $draftRow['manuscript_type'],
      'publication_location' => $draftRow['publication_location'],
      'program_category'     => $draftRow['program_category'],
    ], $sections),
    'status' => $draftRow['publication_status'],
    'file'   => $draftRow['file_path'],
  ];
}

// Final submission step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_paper') {
  try {
    csrf_verify();
    
    /* Validate paper type against the same list the dropdown was built from,
       so the form can never offer something the handler then refuses. A
       retired type is rejected here on purpose: it may still sit on an older
       paper, but nothing new may be filed under one. */
    $paperType = trim($_POST['paper_type'] ?? '');
    if (!array_key_exists($paperType, paper_types())) {
      throw new Exception('Invalid paper type');
    }
    
    // Ethics clearance, consent and the data-collection tool are only
    // demanded of the paper types that involve human participants and
    // original data gathering. Any other type may still attach them, but is
    // not held up waiting for paperwork it never needed.
    $requiredDocs = ['ethics_clearance', 'consent_form', 'data_collection', 'copyright_doc'];
    if (paper_type_needs_documents($paperType)) {
      foreach ($requiredDocs as $doc) {
        if (empty($_FILES[$doc]) || $_FILES[$doc]['error'] !== UPLOAD_ERR_OK) {
          throw new UserFacingException(
            'Please attach the ' . ucwords(str_replace('_', ' ', $doc)) .
            '. It is required for a ' . paper_type_label($paperType) . '.'
          );
        }
      }
    }

    // Validate PDF
    validate_pdf_upload($_FILES['research_pdf']);
    $pdf = $_FILES['research_pdf']; 
    
    /* The program decides which folder the paper is filed under and how it is
       counted in every report, so it has to be a real answer. If the form did
       not carry one, the student's own program stands in — but filing it as
       "Other" because nobody answered would put the paper somewhere no one
       looks, so that is refused instead. */
    $programCategory = trim($_POST['program_category'] ?? '');
    if ($programCategory === '') {
        $userStmt = $conn->prepare("SELECT program FROM users WHERE user_id=?");
        $userStmt->bind_param('i', $u['user_id']);
        $userStmt->execute();
        $userResult = $userStmt->get_result()->fetch_assoc();
        $programCategory = trim($userResult['program'] ?? '');
        $userStmt->close();
    }
    if ($programCategory === '') {
        throw new UserFacingException('Please choose your Academic Program before submitting.');
    }

    $programFolder = get_program_folder($programCategory);

    // Use form data from extracted fields (MUST BE BEFORE GDRIVE UPLOAD)
    $title = trim($_POST['title'] ?? '') ?: 'Untitled';
    $authors = trim($_POST['authors'] ?? '') ?: $u['full_name'];
    $year = (int)($_POST['year'] ?? 0) ?: (int)date('Y');
    if ($year < 1900 || $year> 2100) $year = (int)date('Y');

    // Completion date is required; it is the authoritative research date and
    // its year wins over the Year box.
    $researchDate = trim($_POST['research_date'] ?? '');
    $rd = $researchDate !== '' ? DateTime::createFromFormat('Y-m-d', $researchDate) : false;
    if (!$rd || $rd->format('Y-m-d') !== $researchDate) {
        throw new Exception('Please enter a valid date of completion.');
    }
    $ry = (int)$rd->format('Y');
    if ($ry < 1900 || $ry > 2100) {
        throw new Exception('The date of completion must fall between 1900 and 2100.');
    }
    $year = $ry;
    $researchDateParam = $researchDate;
    $keywords = trim($_POST['keywords'] ?? '');

    // The four IMRAD sections arrive as HTML from the rich-text editors, so this
    // is where untrusted markup stops. Each is stored as sanitised HTML in
    // imrad_content; `abstract` keeps a plain-text copy because the repository
    // cards and the search both read that column as text.
    $sectionLabels = paper_section_labels();
    $sectionKeys   = array_keys($sectionLabels);
    $sections = [];
    foreach ($sectionKeys as $key) {
        $clean = rich_text_sanitize((string)($_POST[$key] ?? ''));
        /* A section of nothing but a table of results, or a photograph of the
           apparatus, is written. The editor has always taken that view, so the
           submit handler has to as well: measuring the plain text alone let a
           table-only section through every step and refused it at the last. */
        $hasFigure = (bool)preg_match('/<(img|table)\b/i', $clean);
        if (rich_text_to_plain($clean) === '' && !$hasFigure) {
            throw new Exception('Please write the ' . $sectionLabels[$key] . ' section.');
        }
        $sections[$key] = $clean;
    }
    $imradContent = json_encode($sections, JSON_UNESCAPED_UNICODE);
    $abstract     = rich_text_to_plain($sections['abstract']);

    // Capture AI fields from hidden inputs
    $ai_summary = trim($_POST['ai_summary'] ?? '');
    $ai_methodology = trim($_POST['ai_methodology'] ?? '');
    $ai_statistical_methods = trim($_POST['ai_statistical_methods'] ?? '');
    $ai_variables = trim($_POST['ai_variables'] ?? '');
    $ai_sample_size = trim($_POST['ai_sample_size'] ?? '');
    $ai_research_field = trim($_POST['ai_research_field'] ?? '');
    
    // New Fields
    $researchType = trim($_POST['research_type'] ?? '');
    $manuscriptType = manuscript_types_posted($_POST['manuscript_type'] ?? []);
    
    /* The browser refuses to leave step 1 without one of these, but the browser
       is not the thing being trusted. */
    if ($manuscriptType === '') {
        throw new Exception('Please choose at least one manuscript type.');
    }

    // Handle paper status
    $paperStatusArr = $_POST['paper_status'] ?? [];
    if (empty($paperStatusArr)) {
        $paperStatusArr[] = 'Unpublished Paper';
    }
    $pubStatus = implode(', ', $paperStatusArr);
    
    $isPublished = in_array('Published Paper', $paperStatusArr);
    $pubLocation = $isPublished ? trim($_POST['publication_location'] ?? '') : null;

    // 1. Keyword Validation (At least 5)
    $kwArray = array_filter(array_map('trim', explode(',', $keywords)));
    if (count($kwArray) < 5) {
        throw new Exception("At least 5 keywords are required.");
    }

    // 2. Duplicate Title Check
    // $dupCheck = $conn->prepare("SELECT paper_id FROM research_papers WHERE title = ? AND current_status != 'declined' LIMIT 1");
    // $dupCheck->bind_param('s', $title);
    // $dupCheck->execute();
    // if ($dupCheck->get_result()->num_rows> 0) {
    //     throw new Exception("A paper with this title already exists.");
    // }


    // 3. Special Requirements: BSIT Code Upload
    // Create submission folder structure
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
      throw new Exception('Failed to store'); 
    }
    
    $sourceCodePath = null;

    // Upload to Google Drive with organized structure
    $gdriveId = null;
    // Note: The gdrive function receives the local path, not the URL path. This is correct.
    if (function_exists('upload_paper_to_gdrive')) {
      $gdriveId = upload_paper_to_gdrive($destPath, $title . '.pdf', $programFolder, (string)$year, $paperType, $title, 'PAPEL - DOCUMENTS', $u['user_id']);
    }
    if (!$gdriveId) {
        throw new Exception('Google Drive upload failed. Please check system configuration.');
    }
    
    // Insert paper
    /* Stored relative to app/student/, not as a full URL. The old absolute form
       baked this machine's BASE_URL into the row, so after the project moved to
       another computer — or was simply served on a different address — the file
       could not be found again, and nothing that needed the file rather than a
       link could resolve it at all. paper_file_url() still renders a correct
       link from a relative path, and paper_file_disk_path() reads either form. */
    $relPath = "uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder/" . basename($destPath);
    $fullUrlPath = $relPath;
    $sz = filesize($destPath) ?: 0;
    $null = null;
    
    /* A submitted paper goes to the Research Adviser. It used to be filed as
       'draft' — the same state as work never sent — so pressing Submit put the
       paper in the student's Drafts tab and no reviewer ever saw it. Nothing
       later promoted it, which is why every review queue stayed empty. */
    $initialStatus = ($u['user_role'] === 'student') ? 'pending_faculty' : 'approved';

    /* Submitting from a draft updates that draft rather than adding a second
       copy of the paper. It matters most for a returned paper: keeping the same
       paper_id keeps the reviewer's feedback and the approval history attached
       to it, and leaves nothing behind in Drafts. */
    $submitDraftId = (int)($_POST['draft_id'] ?? 0);
    if ($submitDraftId > 0) {
        $dchk = $conn->prepare("SELECT paper_id FROM research_papers WHERE paper_id=? AND uploaded_by=? AND current_status='draft'");
        $dchk->bind_param('ii', $submitDraftId, $u['user_id']);
        $dchk->execute();
        if (!$dchk->get_result()->fetch_row()) $submitDraftId = 0;
        $dchk->close();
    }

    // Try inserting with all columns (including new ones)
    $fullQuery = "INSERT INTO research_papers (title,author_names,year,research_date,abstract,imrad_content,keywords,file_path,file_size,uploaded_by,current_status,paper_type,gdrive_file_id,ai_summary,ai_methodology,ai_sample_size,ai_statistical_methods,ai_variables,ai_research_field, research_type, manuscript_type, publication_status, publication_location, program_category, source_code_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    try {
        $stmt = $conn->prepare($fullQuery);
        if (!$stmt) throw new Exception('Database query preparation failed.');

        $stmt->bind_param('ssisssssiisssssssssssssss', $title,$authors,$year,$researchDateParam,$abstract,$imradContent,$keywords,$fullUrlPath,$sz,$u['user_id'],$initialStatus,$paperType,$gdriveId,$ai_summary,$ai_methodology,$ai_sample_size,$ai_statistical_methods,$ai_variables,$ai_research_field, $researchType, $manuscriptType, $pubStatus, $pubLocation, $programCategory, $sourceCodePath);
        
        if (!$stmt->execute()) throw new Exception('Database insert failed.');
        
    } catch (Exception $e) {
        // If error is "Unknown column", fallback to basic insert
        if (strpos($e->getMessage(), "Unknown column") !== false) {
            $basicQuery = "INSERT INTO research_papers (title,author_names,year,abstract,keywords,file_path,file_size,uploaded_by,current_status,paper_type,gdrive_file_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($basicQuery);
            if (!$stmt) { @unlink($destPath); throw new Exception('Database query preparation failed.'); }
            
            $stmt->bind_param('ssisssiisss', $title,$authors,$year,$abstract,$keywords,$fullUrlPath,$sz,$u['user_id'],$initialStatus,$paperType,$gdriveId);
            if (!$stmt->execute()) { @unlink($destPath); throw new Exception('Database insert failed.'); }
        } else {
            @unlink($destPath);
            throw $e;
        }
    }
    
    $paper_id = $stmt->insert_id;

    /* Drive is where a submitted paper lives. The local file was only ever the
       staging copy the Drive upload reads from — keeping it meant every paper
       was stored twice, for good. It goes now that Drive has it (the upload
       throws above if it did not) and the row recording it exists.

       A paper is read through the viewer, which opens it on Drive, so nothing
       needs the local file once it is up there. */
    if (is_file($destPath)) { @unlink($destPath); }

    /* The draft this submission grew out of becomes the submission itself, so
       the new row replaces it: the values are copied across, the draft's own
       row is removed, and its stale attachment records go with it — the files
       attached during *this* submit are written again below. */
    if ($submitDraftId > 0 && $paper_id > 0) {
        $mv = $conn->prepare(
            "UPDATE research_papers dst,
                    (SELECT * FROM research_papers WHERE paper_id = ?) src
             SET dst.title = src.title, dst.author_names = src.author_names, dst.year = src.year,
                 dst.research_date = src.research_date, dst.abstract = src.abstract,
                 dst.imrad_content = src.imrad_content, dst.keywords = src.keywords,
                 dst.file_path = src.file_path, dst.file_size = src.file_size,
                 dst.gdrive_file_id = src.gdrive_file_id, dst.paper_type = src.paper_type,
                 dst.research_type = src.research_type, dst.manuscript_type = src.manuscript_type,
                 dst.publication_status = src.publication_status,
                 dst.publication_location = src.publication_location,
                 dst.program_category = src.program_category,
                 dst.source_code_path = src.source_code_path,
                 dst.ai_summary = src.ai_summary, dst.ai_methodology = src.ai_methodology,
                 dst.ai_sample_size = src.ai_sample_size,
                 dst.ai_statistical_methods = src.ai_statistical_methods,
                 dst.ai_variables = src.ai_variables, dst.ai_research_field = src.ai_research_field,
                 dst.upload_date = NOW(), dst.current_status = ?
             WHERE dst.paper_id = ? AND dst.uploaded_by = ? AND dst.current_status = 'draft'");
        $mv->bind_param('isii', $paper_id, $initialStatus, $submitDraftId, $u['user_id']);
        if ($mv->execute() && $mv->affected_rows > 0) {
            $mv->close();
            $conn->query("DELETE FROM supporting_documents WHERE paper_id = " . (int)$submitDraftId);
            $conn->query("DELETE FROM research_papers WHERE paper_id = " . (int)$paper_id);
            $paper_id = $submitDraftId;      // carry on against the original row
        } else {
            $mv->close();
        }
    }

    // Supporting docs
    $supDir = $destDir . "/supporting_documents"; 
    ensure_dir($supDir);
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    
    // Store whatever was attached. This loop is unaffected by the gating
    // above: an optional document that *was* provided is filed exactly like
    // a required one, so nothing a student attaches is ever discarded.
    $requiredDocs = ['ethics_clearance', 'consent_form', 'data_collection', 'copyright_doc'];
    foreach ($requiredDocs as $docType) {
      if (!empty($_FILES[$docType]) && $_FILES[$docType]['error'] === UPLOAD_ERR_OK) {
        if ($finfo->file($_FILES[$docType]['tmp_name']) === 'application/pdf') {
          $safe = safe_name(pathinfo($_FILES[$docType]['name'], PATHINFO_FILENAME));
          $dest = $supDir . "/{$paper_id}_{$docType}_{$safe}.pdf";
          if (move_uploaded_file($_FILES[$docType]['tmp_name'], $dest)) {
            $rel = "uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder/supporting_documents/" . basename($dest);
            $fullUrlRel = $rel;   // relative, for the reasons above
            
            // Upload to Google Drive
            $docGdriveId = null;
            if (function_exists('upload_supporting_doc_to_gdrive')) {
                $docGdriveId = upload_supporting_doc_to_gdrive($dest, ucfirst(str_replace('_', ' ', $docType)) . '.pdf', $programFolder, (string)$year, $paperType, $title, $u['user_id']);
            }
            if (!$docGdriveId) {
                throw new Exception("Failed to upload supporting document ($docType) to Google Drive.");
            }
            
            $st = $conn->prepare("INSERT INTO supporting_documents (paper_id,document_type,file_path,gdrive_file_id) VALUES (?,?,?,?)");
            $st->bind_param('isss', $paper_id, $docType, $fullUrlRel, $docGdriveId);
            $st->execute();
            
            // Drive has it and the row is written, so the staging copy can go.
            if (is_file($dest)) { @unlink($dest); }
          }
        }
      }
    }
    
    // Handle optional other document
    if (!empty($_FILES['other_doc']) && $_FILES['other_doc']['error'] === UPLOAD_ERR_OK) {
      if ($finfo->file($_FILES['other_doc']['tmp_name']) === 'application/pdf') {
        $otherName = trim($_POST['other_doc_name'] ?? '') ?: 'Other Document';
        $otherType = safe_name($otherName);
        $safe = safe_name(pathinfo($_FILES['other_doc']['name'], PATHINFO_FILENAME));
        $dest = $supDir . "/{$paper_id}_{$otherType}_{$safe}.pdf";
        if (move_uploaded_file($_FILES['other_doc']['tmp_name'], $dest)) {
          $rel = "uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder/supporting_documents/" . basename($dest);
          $fullUrlRel = $rel;   // relative, for the reasons above
          
          // Upload to Google Drive
          $otherGdriveId = null;
          if (function_exists('upload_supporting_doc_to_gdrive')) {
              $otherGdriveId = upload_supporting_doc_to_gdrive($dest, ucfirst(str_replace('_', ' ', $otherType)) . '.pdf', $programFolder, (string)$year, $paperType, $title, $u['user_id']);
          }
          if (!$otherGdriveId) {
              throw new Exception("Failed to upload optional document ($otherType) to Google Drive.");
          }
          $st = $conn->prepare("INSERT INTO supporting_documents (paper_id,document_type,file_path,gdrive_file_id) VALUES (?,?,?,?)"); // Note: 'otherType' might be too long for the column
          $st->bind_param('isss', $paper_id, $otherType, $fullUrlRel, $otherGdriveId);
          $st->execute();
          
          // Drive has it and the row is written, so the staging copy can go.
          if (is_file($dest)) { @unlink($dest); }
        }
      }
    }
    
    /* The folders held nothing but those staging files. rmdir only removes an
       empty directory, so anything still in there is left exactly as it is. */
    if (is_dir($supDir))  { @rmdir($supDir); }
    if (is_dir($destDir)) { @rmdir($destDir); }

    /* Tell the Research Adviser there is something waiting. The adviser is the
       faculty member who created this student's account, which is the same link
       their review queue is built on — so if there is no creator, nobody would
       ever see the paper, and that is worth recording rather than passing over
       in silence. */
    if ($u['user_role'] === 'student') {
        $adviserId = creator_of($u['user_id']);
        if ($adviserId) {
            create_notification($adviserId, $paper_id, 'submission',
                $u['full_name'] . ' submitted "' . $title . '" for your review.');
        } else {
            error_log('Paper ' . $paper_id . ' submitted by user ' . $u['user_id']
                    . ' who has no Research Adviser (users.created_by is empty) — it will not appear in any review queue.');
        }
    }

    $redirectUrl = role_home($u['user_role']);
    echo json_encode(['success'=>true,'message'=>'Upload complete','paper_id'=>$paper_id, 'redirect'=>$redirectUrl]);
    exit;
    
  } catch (Exception $e) {
    http_response_code(500);
    $safe = safe_error_message($e, 'Upload failed. Please try again.');
    echo json_encode(['success'=>false,'message'=>$safe['message'],'reference'=>$safe['reference']]);
    exit;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assests/css/papel-pdf-view.css">
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>"  src="../../assests/js/input-validation.js" defer></script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Enhanced Smooth Animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideInRight {
    from { opacity: 0; transform: translateX(30px); }
    to { opacity: 1; transform: translateX(0); }
}

@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-30px); }
    to { opacity: 1; transform: translateX(0); }
}

@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

@keyframes shimmer {
    0% { background-position: -1000px 0; }
    100% { background-position: 1000px 0; }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.05); opacity: 0.8; }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ── Papel Upload Overlay — clear blurred backdrop ── */
#uploadOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(40, 18, 18, 0.28);
    -webkit-backdrop-filter: blur(8px);
    backdrop-filter: blur(8px);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    flex-direction: column;
}
#uploadOverlay.active { display: flex; }

/* The loader: a book with its pages turning. Two resting halves and three
   leaves that flip across the spine on a stagger, so one is always in flight.
   Built from tokens rather than an image, so it follows the theme into dark
   mode and stays crisp at any pixel density. */
.page-turn {
    --pt-rule: var(--border);
    position: relative;
    width: 112px;
    height: 78px;
    margin-bottom: 1.6rem;
    perspective: 460px;      /* shallow enough that a turning page foreshortens */
}
/* The pages already read and the ones still to come. */
.page-turn-half {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 50%;
    background: var(--white);
    border: 1px solid var(--border);
}
.page-turn-left  { left: 0;  border-right: none; border-radius: var(--r-control, 4px) 0 0 var(--r-control, 4px); }
.page-turn-right { right: 0; border-left: none;  border-radius: 0 var(--r-control, 4px) var(--r-control, 4px) 0; }

/* Ruled lines, drawn rather than drawn on, so they scale with the box. */
.page-turn-half::before,
.page-turn-leaf::before {
    content: '';
    position: absolute;
    inset: 11px 9px;
    background: repeating-linear-gradient(to bottom,
                var(--pt-rule) 0 1.5px, transparent 1.5px 9px);
    opacity: .7;
}

/* The spine. A little darker than the rules so the fold reads as a fold. */
.page-turn::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 50%;
    width: 3px;
    transform: translateX(-50%);
    background: linear-gradient(to right,
                rgba(0,0,0,.14), rgba(0,0,0,.05), rgba(0,0,0,.14));
    z-index: 4;
}

.page-turn-leaf {
    position: absolute;
    top: 0;
    right: 0;
    width: 50%;
    height: 100%;
    background: var(--white);
    border: 1px solid var(--border);
    border-left: none;
    border-radius: 0 var(--r-control, 4px) var(--r-control, 4px) 0;
    transform-origin: left center;
    animation: pageTurn 2.4s cubic-bezier(.45,.05,.4,1) infinite;
    /* The leaf looks the same from behind, so the gradient stands in for the
       shadow a real page casts on itself as it lifts. */
    box-shadow: -6px 0 12px rgba(40,2,2,.10);
}
.page-turn-leaf:nth-child(4) { animation-delay: .8s; }
.page-turn-leaf:nth-child(5) { animation-delay: 1.6s; }

/* Fades out flat against the left half, which is exactly where a turned page
   comes to rest, then fades back in on the right for the next one. */
@keyframes pageTurn {
    0%   { transform: rotateY(0deg);    opacity: 0; }
    6%   { transform: rotateY(-8deg);   opacity: 1; }
    55%  { transform: rotateY(-160deg); opacity: 1; }
    63%  { transform: rotateY(-180deg); opacity: 0; }
    100% { transform: rotateY(-180deg); opacity: 0; }
}

/* Motion is the whole point of this one, so when it is unwelcome the book
   simply sits there and the progress bar carries the message instead. */
@media (prefers-reduced-motion: reduce) {
    .page-turn-leaf { animation: none; opacity: 0; }
}

/* White card */
.loading-content {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
    background: var(--white);
    border-radius: var(--r-card, 8px);
    padding: 2.5rem 2.75rem 2.25rem;
    box-shadow: 0 24px 60px rgba(40,2,2,.28);
    max-width: 90vw;
    animation: slideUp 0.5s cubic-bezier(0.34,1.56,0.64,1);
}
.loading-text {
    color: var(--maroon);
    font-size: 1.4rem;
    font-weight: 500;
    letter-spacing: .2px;
    text-align: center;
    margin-bottom: .4rem;
}
.loading-status {
    color: var(--grey);
    font-size: .95rem;
    font-weight: 500;
    text-align: center;
    min-height: 1.4em;
    transition: opacity .4s;
    margin-bottom: 1.5rem;
}

/* Progress track */
.upload-progress-track {
    width: min(320px, 72vw);
    height: 6px;
    background: var(--cream);
    border-radius: 99px;
    overflow: hidden;
    margin-bottom: .6rem;
}
.upload-progress-fill {
    height: 100%;
    width: 0%;
    border-radius: 99px;
    background: linear-gradient(90deg, var(--maroon), var(--soft-maroon));
    background-size: 200% 100%;
    animation: shimmer 1.8s linear infinite;
    transition: width .6s cubic-bezier(.4,0,.2,1);
}
@keyframes shimmer {
    0%   { background-position: 200% center; }
    100% { background-position: -200% center; }
}
.upload-progress-label {
    color: var(--grey);
    font-size: .72rem;
    font-weight: 500;
    letter-spacing: .8px;
    text-transform: uppercase;
}

.loading-dots { display: inline-block; }
.loading-dots::after { content: ''; animation: dots 1.5s steps(4, end) infinite; }
@keyframes dots {
    0%, 20% { content: ''; }
    40%      { content: '.'; }
    60%      { content: '..'; }
    80%, 100%{ content: '...'; }
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: var(--font-body);
  min-height: 100vh;
  background: var(--white);
}

/* Soft-cream panel carrying the form card, mirroring the dashboard shell. */
.upload-shell {
  background: var(--cream);
  border-radius: var(--r-card, 8px);
  padding: .875rem;
}

.upload-card {
  background: var(--white);
  border: 1px solid rgba(177,125,125,.22);
  border-radius: var(--r-card, 8px);
  overflow: hidden;
}

.card-body {
  padding: 1.25rem;
}

.page-title {
  font-family: var(--font-head);
  font-size: 1.6875rem;
  font-weight: 500;
  line-height: 1.2;
  color: var(--pup-maroon);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: .5rem;
}

.alert {
  border-radius: var(--r-card, 8px);
  border: none;
  padding: .75rem 1rem;
  font-size: .8125rem;
}

.alert-info {
  background: var(--cream);
  color: var(--maroon);
}

.alert-success {
  background: var(--cream);
  color: var(--dark-maroon);
}

/* Alerts carrying instructions stay put until dismissed, so they need a way
   out. The button sits inside the padding rather than adding to the height. */
.alert-dismissable {
  position: relative;
  padding-right: 2.5rem;
}
.alert-close {
  position: absolute;
  top: .5rem;
  right: .5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  padding: 0;
  border: none;
  border-radius: var(--r-control, 4px);
  background: none;
  color: inherit;
  opacity: .65;
  cursor: pointer;
  transition: opacity .15s, background .15s;
}
.alert-close:hover { opacity: 1; background: rgba(130, 7, 7, .10); }
.alert-close:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }

/* Small muted annotation beside a field label. */
.field-note {
  font-size: .6875rem;
  font-weight: 400;
  color: var(--grey);
}

.alert-warning {
  background: linear-gradient(135deg, var(--cream) 0%, var(--soft-maroon) 100%);
  color: #92400e;
}

/* ===== The "what is this for?" icon beside a label =====
   The card hangs off the icon rather than being placed by script: the wrapper
   is the positioned ancestor, so the card follows the icon wherever the row
   ends up and nothing has to be measured. */
.label-row {
  display: flex;
  align-items: center;
  gap: .35rem;
  margin-bottom: .35rem;
}
.field-help-wrap { position: relative; display: inline-flex; }
.field-help {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: none;
  color: var(--grey);
  cursor: pointer;
  transition: color .15s;
}
.field-help:hover,
.field-help[aria-expanded="true"] { color: var(--maroon); }
.field-help .material-symbols-outlined { font-size: 17px; }

/* Fixed, not absolute. The card it opens inside carries overflow:hidden to keep
   its children inside its rounded corners, and that clipped the help card off
   at the card's bottom edge mid-sentence. Fixed positioning answers to the
   viewport instead, so no ancestor can crop it; the cost is that the placing
   has to be done from script, just below. */
.help-card {
  position: fixed;
  z-index: 1200;
  display: block;
  width: 24rem;
  max-width: 80vw;
  padding: .8rem .95rem;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-card, 8px);
  box-shadow: var(--shadow-md);
  color: var(--ink);
  font-size: .8125rem;
  font-weight: 400;
  line-height: 1.55;
  text-align: left;
  white-space: normal;
  /* On a short window the card could be taller than the screen, and no amount
     of placing fixes that — so it scrolls inside itself instead. */
  max-height: calc(100vh - 1.5rem);
  overflow-y: auto;
}
.help-card[hidden] { display: none; }
.help-lede { margin: 0 0 .6rem; }
.help-card dl { margin: 0; }
.help-card dt { font-weight: 600; color: var(--maroon); }
.help-card dd { margin: 0 0 .55rem; }
.help-card dd:last-child { margin-bottom: 0; }


/* Checkboxes read across rather than down: three of them stacked took as much
   height as the rest of the step put together, for three words each. Wrapping
   is left on so a narrow window folds them instead of pushing the card wide. */
.check-row {
  display: flex;
  align-items: center;
  gap: .5rem 1.75rem;
  min-height: 38px;      /* what .form-select comes out at here */
  flex-wrap: wrap;
}
.check-row .form-check { margin-bottom: 0; }
/* A journal name is a short thing; the full width of the card overstated it. */
.pub-location { max-width: 32rem; }

.form-label {
  font-family: var(--font-body);
  font-weight: 400;
  color: var(--ink);
  margin-bottom: .35rem;
  font-size: .8125rem;
  display: flex;
  align-items: center;
  gap: .35rem;
}

.form-control, .form-select {
  border: 1.5px solid var(--border);
  border-radius: var(--r-control, 4px);
  padding: .75rem .875rem;
  font-size: .9rem;
  font-family: var(--font-body);
  font-weight: 400;
  color: var(--ink);
  transition: border-color .2s, box-shadow .2s;
  background: var(--cream);
}

.form-control:focus, .form-select:focus {
  border-color: var(--maroon);
  box-shadow: 0 0 0 3px rgba(130, 7, 7, .10);
  outline: none;
  background: var(--white);
}

/* ===== Section editors (Abstract / Introduction / Methodology / R&D) =====
   A document-style writing surface per IMRAD section: title row, formatting
   toolbar, then the page itself. Built on contenteditable so the toolbar can
   act on a selection the way a word processor does. */
.doc-editor {
  border: 1.5px solid var(--border);
  border-radius: var(--r-control, 4px);
  background: var(--white);
  margin-bottom: 1.25rem;
  overflow: hidden;
  transition: border-color .2s, box-shadow .2s;
}
.doc-editor:focus-within {
  border-color: var(--maroon);
  box-shadow: 0 0 0 3px rgba(130, 7, 7, .10);
}
.doc-editor.is-invalid { border-color: var(--maroon); background: #fdeaea; }

/* ===== Expanded section =====
   Fills the page rather than opening one. z-index 800 puts it below every
   piece of fixed furniture by construction — the site header is 900, the chat
   1000, the PDF preview 1200 and the accessibility widget higher still — so it
   can never cover any of them. The left and right edges follow the same dock
   widths the panels use, so an expanded box stops where a docked panel starts. */
.doc-editor.is-expanded {
  position: fixed;
  top: 60px;                 /* clears the site header */
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 800;
  margin: 0;
  border-radius: var(--r-card, 8px);
  border-width: 1px 0 0 0;
  display: flex;
  flex-direction: column;
  background: var(--white);
  box-shadow: 0 -4px 24px rgba(51, 0, 0, .10);
}
.doc-editor.is-expanded .doc-editor-head { flex: 0 0 auto; }
.doc-editor.is-expanded .doc-surface {
  flex: 1 1 auto;
  min-height: 0;
  max-height: none;
}
body.pdf-docked .doc-editor.is-expanded { right: var(--pdf-dock-w, 460px); }

/* While a section is expanded the chat and the accessibility button step out
   of the way — nothing is closed or reset, they are only hidden, so both come
   back exactly as they were, docked or floating, when the box is collapsed.
   The accessibility widget is moved to <html> by its own script, so the flag is
   set there as well as on <body>. The PDF preview deliberately stays. */
html.doc-expanded #a11y-widget,
body.doc-expanded #a11y-widget,
body.doc-expanded #chat-widget { display: none !important; }
/* Their reserved space goes with them, so the box gets the full width. */
body.doc-expanded.chat-docked-left  { padding-left: 0; }
body.doc-expanded.chat-docked-right { padding-right: 0; }
/* Holds the page's height so nothing jumps while a box is lifted out of flow. */
.doc-editor-spacer { margin-bottom: 1.25rem; }
.doc-expand { color: var(--grey); }
.doc-expand:hover { color: var(--maroon); }

/* Section tabs, along the bottom the way a spreadsheet names its sheets.
   They exist only while a section is expanded, because that is the only time
   the other four are out of sight — on the form itself they are all just
   there, one under the other. */
.doc-tabs { display: none; }
.doc-editor.is-expanded .doc-tabs {
  display: flex;
  align-items: flex-end;
  gap: .25rem;
  flex: 0 0 auto;
  padding: .4rem .75rem 0;
  border-top: 1px solid var(--border);
  background: var(--cream);
  overflow-x: auto;
  scrollbar-width: thin;
}
.doc-tab {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  position: relative;
  top: 1px;                       /* sits on the border, like a real tab */
  padding: .4rem .85rem;
  border: 1px solid var(--border);
  border-bottom: none;
  border-radius: var(--r-control, 4px) var(--r-control, 4px) 0 0;
  background: #fdf4f3;
  color: var(--ink);
  font-family: inherit;
  font-size: .75rem;
  line-height: 1.4;
  white-space: nowrap;
  cursor: pointer;
  transition: background .15s, color .15s;
}
.doc-tab:hover { background: var(--white); color: var(--maroon); }
.doc-tab:focus-visible { outline: 2px solid var(--maroon); outline-offset: -2px; }
.doc-tab.is-active {
  background: var(--white);
  color: var(--maroon);
  font-weight: 500;
  border-color: var(--maroon);
  box-shadow: 0 -2px 0 var(--maroon) inset;
}
/* A section still to be written carries a dot — the form needs all five, and
   this is the only view where the empty ones are otherwise invisible. */
.doc-tab-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--maroon); opacity: .4; flex: 0 0 auto;
}
.doc-tab.is-active .doc-tab-dot { opacity: .7; }

@media (max-width: 700px) {
  .doc-editor.is-expanded { top: 0; }
  .doc-tab { padding: .4rem .6rem; }
}

/* Section label, toolbar and word count share one row. Keeping them on
   separate lines cost a whole band of empty space above the tools. The label
   and the count both grow, so the toolbar settles in the middle of the row. */
/* One row, always. Rather than wrapping onto extra lines when the column is
   narrow, the tools that no longer fit move into a ⋮ menu — see reflowToolbar()
   — so the header stays a single tidy strip at any width. */
.doc-editor-head {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: .5rem;
  padding: .25rem .75rem;
  background: var(--cream);
  border-bottom: 1px solid var(--border);
  overflow: hidden;
}
.doc-editor-title {
  flex: 1 1 auto;
  min-width: 3.5rem;      /* shrinks, but never away entirely */
  font-family: var(--font-head);
  font-size: .8125rem;
  font-weight: 500;
  color: var(--maroon);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.doc-editor-count {
  flex: 1 1 auto;
  min-width: 3.25rem;
  text-align: right;
  font-size: .6875rem;
  color: var(--grey);
  white-space: nowrap;
}

.doc-toolbar {
  flex: 0 0 auto;         /* the tools keep their size; the labels give way */
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: nowrap;
  gap: .125rem;
}

/* Overflowed tools, stacked in the ⋮ menu. */
.doc-more-menu {
  display: flex;
  flex-wrap: wrap;
  gap: .125rem;
  max-width: 11rem;
}
.doc-more-menu .doc-tool-sep { display: none; }
/* Two classes, not one: the generic .doc-pop-wrap rule further down also sets
   display and would otherwise win on source order, leaving the ⋮ on screen with
   an empty menu behind it. */
.doc-pop-wrap.doc-more-wrap { display: none; }
.doc-pop-wrap.doc-more-wrap.is-needed { display: inline-flex; }
.doc-tool {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  border: none;
  border-radius: var(--r-control, 4px);
  background: none;
  color: var(--ink);
  cursor: pointer;
  transition: background .15s, color .15s;
}
.doc-tool:hover { background: var(--cream); color: var(--maroon); }
.doc-tool:active { background: var(--border); }
.doc-tool.active { background: var(--cream); color: var(--maroon); }
.doc-tool:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.doc-tool-sep {
  width: 1px;
  height: 18px;
  margin: 0 .25rem;
  background: var(--border);
}

.doc-surface {
  min-height: 200px;
  max-height: 460px;
  overflow-y: auto;
  padding: 1rem 1.125rem;
  font-family: var(--font-body);
  font-size: .875rem;
  line-height: 1.5;
  color: var(--ink);
  text-align: justify;
  outline: none;
}
/* Line height and body alignment are fixed for every paper so submissions read
   alike, and both are !important so pasted markup cannot bring its own. Table
   cells are deliberately left out of the alignment rule — a column of figures
   needs centring, and that is the one place the toolbar may set it. */
.doc-surface,
.doc-surface p,
.doc-surface div,
.doc-surface li,
.doc-surface blockquote,
.doc-surface span {
  line-height: 1.5 !important;
}
.doc-surface,
.doc-surface > p,
.doc-surface > div,
.doc-surface > blockquote {
  text-align: justify !important;
}
/* contenteditable has no placeholder of its own. `is-empty` is maintained in JS
   because an "empty" editor still holds <p><br></p> after the first edit, which
   :empty would not match. */
.doc-surface.is-empty::before {
  content: attr(data-placeholder);
  color: var(--grey);
  font-style: italic;
  pointer-events: none;
}
/* One gap between paragraphs, from one place. `div` is included because some
   browsers produce a <div> rather than a <p> when Enter is pressed — without
   this a typed paragraph would sit flush against the next while a pasted one
   had a gap. The trailing gap after the last paragraph is suppressed. */
.doc-surface p,
.doc-surface > div { margin: 0 0 .75rem; }
.doc-surface p:last-child,
.doc-surface > div:last-child { margin-bottom: 0; }
.doc-surface ul, .doc-surface ol { margin: 0 0 .75rem 1.5rem; text-align: left; }
.doc-surface li { margin-bottom: .25rem; }
/* No colour change: indenting must never recolour a student's text, and a
   blockquote is the element the browser reaches for when indenting. */
.doc-surface blockquote {
  margin: 0 0 .75rem;
  padding-left: .875rem;
}
/* Tables, whether inserted from the toolbar or pasted in from Word */
.doc-surface table {
  width: 100%;
  border-collapse: collapse;
  margin: 0 0 .75rem;
  table-layout: fixed;
  font-size: .8125rem;
}
.doc-surface th, .doc-surface td {
  /* currentColor makes the rule literally the font colour in use, so borders
     always match the text rather than drifting to their own shade. */
  border: 1px solid currentColor;
  background: var(--white);
  padding: .375rem .5rem;
  text-align: left;
  vertical-align: top;
  word-wrap: break-word;
  overflow-wrap: break-word;
}
.doc-surface th { font-weight: 500; }
/* The line between two columns is a handle. `table-layout: fixed` above is what
   makes this exact: the widths written while dragging are obeyed to the pixel
   rather than treated as a suggestion. */
.doc-surface td.is-col-edge, .doc-surface th.is-col-edge { cursor: col-resize; }
.doc-editor.is-resizing, .doc-editor.is-resizing * {
  cursor: col-resize !important;
  user-select: none !important;
  -webkit-user-select: none !important;
}
.doc-surface caption {
  caption-side: top;
  padding: 0 0 .375rem;
  text-align: left;
  font-size: .75rem;
  color: var(--grey);
}

/* Pictures inside the editor.

   Centred and on their own line, which is how a figure sits in a paper, and
   never wider than the box however large the original was. */
.doc-surface img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: .75rem auto;
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
}
/* contenteditable gives no sign that a picture is selected, and the right-click
   menu is the only way to size or remove one, so it is worth pointing at. */
.doc-surface img:hover { border-color: var(--soft-maroon); cursor: context-menu; }

/* While a picture is on its way to the server. The box is left usable: the
   upload does not block typing, and the picture lands where the caret was. */
.doc-editor.is-uploading .doc-editor-head::after {
    content: 'Adding picture…';
    margin-left: .5rem;
    font-size: .6875rem;
    color: var(--grey);
    white-space: nowrap;
}
.doc-editor.is-drop-target { border-color: var(--maroon); }
.doc-editor.is-drop-target .doc-surface { background: var(--cream); }

/* A rule between groups of the right-click menu, so sizing a picture does not
   sit flush against deleting it. */
.doc-context-sep {
    height: 1px;
    margin: .25rem 0;
    background: var(--border);
}

/* Reopens the explanation given on arriving at this step.

   A line of its own: trailing the sentence it belongs to, it read as a stray
   underlined link caught in the paragraph rather than as a control. `flex`
   rather than `inline-flex` is what breaks the line, and the fitted width stops
   a block-level button from stretching the whole way across. */
.btn-why-sections {
    display: flex;
    width: fit-content;
    align-items: center;
    gap: .375rem;
    margin-top: .75rem;
    padding: .3125rem .75rem;
    border: 1px solid var(--soft-maroon);
    border-radius: var(--r-control, 4px);
    background: var(--white);
    color: var(--maroon);
    font-family: var(--font-body);
    font-size: .8125rem;
    font-weight: 500;
    line-height: 1.4;
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
}
.btn-why-sections:hover {
    background: var(--cream);
    border-color: var(--maroon);
    color: var(--dark-maroon);
}
.btn-why-sections:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.btn-why-sections .material-symbols-outlined { font-size: 17px; }

/* The explanation itself, which is the one dialog on this page with more than a
   sentence in it. */
#papelDialogMessage p { margin: 0 0 .625rem; }
#papelDialogMessage p:last-child { margin-bottom: 0; }
#papelDialogMessage ul { margin: 0 0 .625rem; padding-left: 1.15rem; }
#papelDialogMessage li { margin-bottom: .2rem; }

/* Links inside the editor */
.doc-surface a { color: var(--maroon); text-decoration: underline; }

/* ----- Toolbar popovers -----
   Fixed and body-level: the editor clips its own overflow for rounded corners,
   which would slice a popover anchored inside the toolbar. */
.doc-pop-wrap { position: relative; display: inline-flex; }
.doc-pop {
  position: fixed;
  z-index: 3000;
  max-height: calc(100vh - 24px);
  overflow-y: auto;
  display: none;
  padding: .625rem;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-card, 8px);
  box-shadow: var(--shadow-md);
  white-space: nowrap;
}
.doc-pop.open { display: block; }
.doc-pop-label {
  margin: .5rem 0 .25rem;
  font-size: .625rem;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--grey);
}
.doc-align-row { display: flex; gap: .125rem; }
.doc-align-btn:disabled { opacity: .4; cursor: default; }

/* ----- Right-click menu over a table ----- */
.doc-context-menu {
  position: fixed;
  z-index: 3000;
  display: none;
  min-width: 11rem;
  padding: .25rem;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-control, 4px);
  box-shadow: var(--shadow-md);
}
.doc-context-menu.open { display: block; }
.doc-context-item {
  display: block;
  width: 100%;
  padding: .375rem .625rem;
  border: none;
  border-radius: var(--r-card, 8px);
  background: none;
  color: var(--ink);
  font-family: inherit;
  font-size: .75rem;
  text-align: left;
  cursor: pointer;
}
.doc-context-item:hover { background: var(--cream); color: var(--maroon); }
.doc-context-item.danger:hover { background: #fdeaea; color: var(--dark-maroon); }
.doc-grid-caption {
  font-size: .75rem;
  color: var(--grey);
  margin-bottom: .375rem;
  text-align: center;
}
.doc-grid-pick {
  display: grid;
  grid-template-columns: repeat(8, 18px);
  gap: 2px;
}
.doc-grid-cell {
  width: 18px;
  height: 18px;
  border: 1px solid var(--border);
  border-radius: var(--r-data, 0px);
  background: var(--white);
  cursor: pointer;
}
.doc-grid-cell.on { background: var(--soft-maroon); border-color: var(--maroon); }

.doc-table-actions {
  display: flex;
  flex-direction: column;
  margin-top: .5rem;
  padding-top: .5rem;
  border-top: 1px solid var(--border);
}
.doc-table-action {
  padding: .3125rem .5rem;
  border: none;
  border-radius: var(--r-control, 4px);
  background: none;
  color: var(--ink);
  font-family: inherit;
  font-size: .75rem;
  text-align: left;
  cursor: pointer;
}
.doc-table-action:hover:not(:disabled) { background: var(--cream); color: var(--maroon); }
.doc-table-action:disabled { color: var(--grey); cursor: default; opacity: .55; }

.doc-surface::-webkit-scrollbar { width: 10px; }
.doc-surface::-webkit-scrollbar-track { background: var(--cream); }
.doc-surface::-webkit-scrollbar-thumb {
  background: var(--soft-maroon);
  border-radius: var(--r-control, 4px);
  border: 2px solid var(--cream);
}
.doc-surface::-webkit-scrollbar-thumb:hover { background: var(--maroon); }

@media (max-width: 700px) {
  /* The header stays one row here too — the ⋮ menu absorbs whatever will not
     fit, so there is nothing to stack. */
  .doc-surface { min-height: 160px; }
}

.form-control::file-selector-button {
  background: var(--maroon-surface);
  color: #fff;
  border: none;
  padding: .4rem 1rem;
  border-radius: var(--r-control, 4px);
  margin-right: .75rem;
  font-family: var(--font-body);
  font-weight: 400;
  font-size: .8125rem;
  cursor: pointer;
  transition: background .2s;
}

.form-control::file-selector-button:hover { background: var(--maroon-surface-hover); }

.text-danger {
  color: var(--maroon) !important;
  font-weight: 400;
}

.text-muted {
  color: var(--grey) !important;
  font-size: .75rem;
}

.btn {
  padding: .75rem 1.5rem;
  border-radius: var(--r-control, 4px);
  font-family: var(--font-body);
  font-weight: 400;
  font-size: .875rem;
  transition: background .2s, color .2s, border-color .2s;
  border: none;
}

.btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.btn:hover::before { width: 0; height: 0; }

.btn-info {
  background: var(--white);
  color: var(--maroon);
  border: 1px solid var(--soft-maroon);
}

.btn-info:hover {
  background: var(--cream);
  color: var(--dark-maroon);
}

/* The third way out of the leave dialog, sitting in the corner of the header
   rather than among the buttons. It is neither the safe choice nor the
   recommended one, so it stays out of the row where those two live. */
.papel-dialog-x {
  margin-left: auto;          /* the far corner, whatever the title's length */
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  padding: 0;
  border: none;
  border-radius: var(--r-control, 4px);
  background: none;
  color: var(--grey);
  cursor: pointer;
}
.papel-dialog-x:hover { background: var(--cream); color: var(--maroon); }
.papel-dialog-x:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.papel-dialog-head .papel-dialog-x .material-symbols-outlined {
  font-size: 20px;
  top: 0;                      /* the head nudges its icons up; this one is centred */
  color: inherit;
}

/* The Bootstrap button colours live in includes/site_head.php, so every page
   gets them. This only keeps the background explicit for the rules below. */
.btn-primary {
  background: var(--maroon-surface);
  color: #fff;
}

.btn-primary:hover {
  background: var(--maroon-surface-hover);
  color: #fff;
}

.btn-outline-secondary {
  background: var(--white);
  color: var(--maroon);
  border: 1px solid var(--soft-maroon);
}

.btn-outline-secondary:hover {
  background: var(--cream);
  color: var(--dark-maroon);
  border-color: var(--soft-maroon);
}

hr {
  border-color: var(--border);
  opacity: 1;
  margin: 2.5rem 0;
  border-width: 2px;
}

.section-heading {
  font-family: var(--font-head);
  font-size: 1.25rem;
  font-weight: 500;
  color: var(--maroon);
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.progress {
  height: 36px;
  border-radius: 12px;
  background: var(--border);
  box-shadow: inset 0 3px 6px rgba(0, 0, 0, 0.12);
  overflow: hidden;
}

.progress-bar {
  background: linear-gradient(90deg, var(--maroon), var(--dark-maroon));
  font-weight: 500;
  font-size: 1rem;
  transition: width 0.4s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

/* Input field animations */
.mb-4, .mb-3 {
}

.mb-4:nth-child(1) { animation-delay: 0.1s; }
.mb-4:nth-child(2) { animation-delay: 0.2s; }
.mb-4:nth-child(3) { animation-delay: 0.3s; }
.mb-4:nth-child(4) { animation-delay: 0.4s; }

@media (max-width: 768px) {
  .card-body {
    padding: 2rem 1.5rem;
  }

  .page-title {
    font-size: 1.75rem;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
  }

  .loading-text { font-size: 1.5rem; }
  .page-turn { width: 128px; height: 90px; }
}

/* Chatbot Styles - Enhanced - LEFT SIDE */
#chat-widget {
    position: fixed;
    bottom: 1.5rem;
    left: 1.5rem;
    z-index: 1000;
    font-family: var(--font-body);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}
#chat-button {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--maroon);
    color: #fff;
    border: none;
    box-shadow: 0 4px 16px rgba(130,7,7,.35);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.375rem;
    transition: transform .2s, box-shadow .2s;
}
#chat-button:hover { transform: scale(1.07); box-shadow: 0 6px 20px rgba(130,7,7,.4); }

/* ----- PUPPY's face -----
   The mascot GIFs are opaque 320x320 squares on a pale ground, so they are
   cropped to a circle. Dropped in square, that ground reads as a grey patch
   stuck onto the maroon header rather than as the dog's avatar. */
.puppy-avatar {
    display: block;
    flex-shrink: 0;
    border-radius: 50%;
    object-fit: cover;
    background: var(--cream);
}
#puppyHeader {
    width: 48px;
    height: 48px;
    border: 2px solid var(--white);
}
/* Smaller than the 56px button so the maroon shows as a ring around the dog. */
#puppyFab { width: 48px; height: 48px; }
#chat-window {
    display: none;
    width: 360px;
    height: 520px;
    max-height: 85vh;
    background: var(--white);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border);
    flex-direction: column;
    overflow: hidden;
    margin-bottom: 14px;
}
#chat-header {
    background: var(--maroon);
    color: #fff;
    padding: 1rem;
    font-size: .875rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .5rem;
}
#chat-messages {
    flex: 1;
    padding: 1rem;
    overflow-y: auto;
    background: var(--cream);
    display: flex;
    flex-direction: column;
    gap: .625rem;
}
#chat-messages::-webkit-scrollbar { width: 8px; }
#chat-messages::-webkit-scrollbar-track { background: var(--cream); }
#chat-messages::-webkit-scrollbar-thumb { background: var(--border); border-radius: var(--r-card, 8px); }
#chat-input-area {
    padding: .75rem 1rem;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: .5rem;
    background: var(--white);
}
#chat-input {
    border: 1.5px solid var(--border);
    border-radius: var(--r-control, 4px);
    background: var(--cream);
    flex: 1;
    padding: .5rem .75rem;
    font-family: var(--font-body);
    font-size: .875rem;
    color: var(--ink);
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
#chat-input:focus {
    border-color: var(--maroon);
    box-shadow: 0 0 0 3px rgba(130,7,7,.10);
    background: var(--white);
}
.send-btn {
    background: var(--maroon);
    color: #fff;
    border: none;
    border-radius: var(--r-control, 4px);
    width: 36px;
    height: 36px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background .2s;
}
.send-btn:hover { background: var(--dark-maroon); }

/* The input + send button share one row; the wrapper is the form element. */
.chat-input-wrapper {
    display: flex;
    align-items: center;
    gap: .5rem;
    width: 100%;
}

/* Header: title and subtitle keep their own column and may shrink so the
   model selector never pushes the heading onto three lines. */
#chat-header > div:first-child { min-width: 0; flex: 1 1 auto; }
/* the nested title/subtitle column must also be allowed to shrink */
#chat-header > div:first-child > div { min-width: 0; }
/* the selector + close button keep their size and never overlap the title */
#chat-header > div:last-child { flex: 0 0 auto; }
#chat-header h5 {
    margin: 0;
    font-family: var(--font-head);
    font-size: .875rem;
    font-weight: 500;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#chat-header p {
    margin: 0;
    font-size: .6875rem;
    opacity: .85;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#chat-header .material-symbols-outlined { font-size: 22px; flex-shrink: 0; }

/* The heading and subtitle are clipped to keep the header on two lines, so
   hovering (or keyboard-focusing) the block reveals both in full. The card is
   anchored to the header rather than to the text column so it always spans the
   panel neatly, whether the chat is floating or docked to a side. */
#chat-header { position: relative; }
.chat-identity { cursor: default; outline: none; }
.chat-identity-tip {
    position: absolute;
    top: calc(100% + 6px);
    left: 1rem;
    right: 1rem;
    z-index: 5;
    padding: .625rem .75rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-4px);
    transition: opacity .16s ease, transform .16s ease, visibility .16s;
    pointer-events: none;
}
.chat-identity:hover .chat-identity-tip,
.chat-identity:focus-visible .chat-identity-tip {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}
.chat-identity-tip .tip-name {
    display: block;
    font-family: var(--font-head);
    font-size: .8125rem;
    font-weight: 500;
    line-height: 1.3;
    color: var(--maroon);
    white-space: normal;
}
.chat-identity-tip .tip-desc {
    display: block;
    margin-top: .125rem;
    font-size: .75rem;
    line-height: 1.45;
    color: var(--grey);
    white-space: normal;
}

#chatModelSelect {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    max-width: 122px;
    flex-shrink: 0;
    font-family: var(--font-body);
    font-size: .75rem;
    line-height: 1.2;
    color: #fff;
    background-color: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.28);
    border-radius: var(--r-control, 4px);
    padding: .25rem 1.5rem .25rem .55rem;
    cursor: pointer;
    outline: none;
    /* white chevron, matching the custom arrows used on the site's selects */
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23ffffff' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .5rem center;
    transition: background-color .2s, border-color .2s;
}
#chatModelSelect:hover {
    background-color: rgba(255,255,255,.22);
    border-color: rgba(255,255,255,.5);
}
#chatModelSelect:focus-visible {
    border-color: #fff;
    box-shadow: 0 0 0 2px rgba(255,255,255,.3);
}
#chatModelSelect option { color: var(--ink); background: var(--white); }
#chatCloseBtn {
    background: none;
    border: none;
    color: rgba(255,255,255,.85);
    cursor: pointer;
    padding: 0;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--r-control, 4px);
    flex-shrink: 0;
    transition: background .15s, color .15s;
}
#chatCloseBtn:hover { background: rgba(255,255,255,.16); color: #fff; }

/* Dock + move-side controls, mirroring the close button's treatment. */
#chatDockBtn,
#chatSideBtn {
    background: none;
    border: none;
    color: rgba(255,255,255,.85);
    cursor: pointer;
    padding: 0;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--r-control, 4px);
    flex-shrink: 0;
    transition: background .15s, color .15s;
}
#chatDockBtn:hover,
#chatSideBtn:hover { background: rgba(255,255,255,.16); color: #fff; }
/* Moving sides only makes sense once the panel is docked. */
#chat-widget:not(.is-docked) #chatSideBtn { display: none; }
/* The right edge is taken by the PDF preview. */
#chatSideBtn.is-blocked { opacity: .4; cursor: not-allowed; }

/* ===== Docked side panel =====
   The panel takes a column at the edge of the viewport and the page is
   padded by the same amount, so content slides over to make room rather
   than being covered — the navbar stays fully visible beside it. */
#chat-widget.is-docked {
    position: fixed;
    top: 0;
    bottom: 0;
    left: auto;
    right: auto;
    width: var(--chat-dock-w, 380px);
    max-width: 100vw;
    z-index: 950;
    display: block;
}
#chat-widget.is-docked.dock-left  { left: 0; }
#chat-widget.is-docked.dock-right { right: 0; }

#chat-widget.is-docked #chat-window {
    width: 100%;
    height: 100%;
    max-height: none;
    margin: 0;
    border-radius: var(--r-card, 8px);
    border: none;
    box-shadow: var(--shadow-md);
}
#chat-widget.is-docked.dock-left  #chat-window { border-right: 1px solid var(--border); }
#chat-widget.is-docked.dock-right #chat-window { border-left: 1px solid var(--border); }
#chat-widget.is-docked #chat-button { display: none !important; }

/* The page gives way to the docked panel. */
body { transition: padding-left .25s ease, padding-right .25s ease; }
body.chat-docked-left  { padding-left:  var(--chat-dock-w, 380px); }
body.chat-docked-right { padding-right: var(--chat-dock-w, 380px); }

/* Too narrow to share the screen — overlay instead of squeezing the form. */
@media (max-width: 900px) {
    #chat-widget.is-docked { width: 100vw; }
    body.chat-docked-left,
    body.chat-docked-right { padding-left: 0; padding-right: 0; }
}

/* Chat bubbles — same treatment as the Help Center's .chat-msg */
.message {
    padding: .625rem .875rem;
    border-radius: var(--r-card, 8px);
    max-width: 82%;
    font-size: .875rem;
    line-height: 1.5;
    word-wrap: break-word;
}
.message.user {
    background: var(--maroon);
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 3px;
}
.message.bot {
    background: var(--white);
    color: var(--ink);
    align-self: flex-start;
    border: 1px solid rgba(177,125,125,.22);
    border-bottom-left-radius: 3px;
}
.message.bot a { color: var(--maroon); }

/* Every answer is signed with the dog's face, sat at the foot of the bubble so
   a long reply still reads as coming from one speaker rather than restarting. */
.msg-row {
    display: flex;
    align-items: flex-end;
    gap: .5rem;
    max-width: 92%;
    align-self: flex-start;
}
.msg-row .message {
    align-self: auto;
    max-width: 100%;
}
.msg-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--cream);
    border: 1px solid var(--border);
}

/* Icon animations */
@keyframes iconBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

/* Breadcrumb strip — same maroon bar used site-wide */
.crumb-bar { background: var(--dark-maroon); }
.crumb-inner {
    display: flex; align-items: center; gap: .25rem;
    padding-top: .5rem; padding-bottom: .5rem;
    font-size: .75rem; color: rgba(255,255,255,.85);
}
.crumb-inner a { color: #fff; text-decoration: none; }
.crumb-inner a:hover { text-decoration: underline; }
.crumb-arrow { color: #fff; font-size: 20px; margin: 0 .125rem; --mi-fill: 1; }
.crumb-current { color: #fff; }

/* Step-by-step progress bar */
.upload-steps {
    background: var(--cream);
    border-radius: 10px;
    padding: 1rem .75rem;
    flex-wrap: wrap;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
}

.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    position: relative;
    z-index: 2;
    /* The indicator is also the navigation. */
    cursor: pointer;
    padding: .25rem .5rem;
    border-radius: var(--r-card, 8px);
    transition: background .15s;
}
.step-item:hover { background: rgba(130, 7, 7, .06); }
.step-item:focus-visible {
    outline: 2px solid var(--maroon);
    outline-offset: 2px;
    background: rgba(130, 7, 7, .06);
}
.step-item:hover .step-label { color: var(--maroon); }

.step-number {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--white);
    color: var(--grey);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-head);
    font-weight: 500;
    font-size: 1rem;
    transition: all .3s ease;
    border: 2px solid var(--border);
}

.step-item.active .step-number {
    background: var(--maroon);
    color: #fff;
    border-color: var(--maroon);
}

.step-item.completed .step-number {
    background: var(--soft-maroon);
    color: #fff;
    border-color: var(--soft-maroon);
}

.step-label {
    font-family: var(--font-body);
    font-size: .75rem;
    font-weight: 400;
    color: var(--grey);
    white-space: nowrap;
}

.step-item.active .step-label,
.step-item.completed .step-label { color: var(--maroon); }

.step-connector {
    width: 90px;
    height: 2px;
    background: var(--border);
    position: relative;
    z-index: 1;
    /* centre on the 44px circle, not on the taller circle+label column */
    align-self: flex-start;
    margin-top: 21px;
    flex-shrink: 1;
}

.step-connector.completed { background: var(--soft-maroon); }

/* Section styling */
.upload-section {
    display: none;
}
#step1 {
    display: block;
}
#pubLocationDiv,
#metadataFields,
#btnNextToSupporting,
#codeUploadCard {
    display: none;
}

.section-header {
    margin-bottom: 2rem;
}

.section-title {
    font-family: var(--font-head);
    font-size: 1.125rem;
    font-weight: 500;
    color: var(--maroon);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.step-badge {
    background: var(--maroon);
    color: #fff;
    padding: .2rem .625rem;
    border-radius: 999px;
    font-family: var(--font-body);
    font-size: .6875rem;
    font-weight: 400;
    white-space: nowrap;
    /* The badge is far smaller than the heading beside it, so sitting on the
       shared baseline leaves it looking dropped. Lift it onto the heading's
       optical centre without changing the line height. */
    position: relative;
    top: -2px;
}

/* The engine picker used to be a bare dropdown showing nothing but a code name.
   It now carries a label and an explanation of what choosing one actually does. */
.model-picker { max-width: 34rem; margin: 0 auto; text-align: center; }
.model-picker .form-label {
    display: block;
    margin-bottom: .375rem;
    font-size: .8125rem;
    color: var(--ink);
}
.model-picker .form-select {
    width: auto;
    min-width: 11rem;
    margin: 0 auto;
    font-size: .85rem;
    border-radius: var(--r-control, 4px);
    border: 2px solid var(--border);
    /* text-align centres the closed value, text-align-last is what Firefox
       honours. Matching the left padding to the space Bootstrap reserves for
       the chevron keeps the name optically centred rather than pushed left. */
    text-align: center;
    text-align-last: center;
    padding-left: 2.25rem;
    padding-right: 2.25rem;
}
.model-picker-note {
    display: block;
    margin-top: .5rem;
    font-size: .75rem;
    line-height: 1.55;
    color: var(--grey);
}

/* ===== Step 3 — supporting documents =====
   Same type and palette as the section editors in Step 2: Plus Jakarta Sans for
   headings, Inter for everything else, nothing bolded, maroon on cream. */
.docs-card {
    border: 1.5px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
    margin-bottom: 1.25rem;
    overflow: hidden;
}
.docs-card-head {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .625rem .875rem;
    background: var(--cream);
    border-bottom: 1px solid var(--border);
}
.docs-card-head .material-symbols-outlined {
    color: var(--maroon);
    position: relative;
    top: -1px;
}
.docs-card-head h4 {
    margin: 0;
    font-family: var(--font-head);
    font-size: .875rem;
    font-weight: 500;
    color: var(--maroon);
}
.docs-card-hint {
    margin-left: auto;
    font-size: .6875rem;
    color: var(--grey);
}
.docs-card-body { padding: 1rem .875rem; }
.docs-note {
    margin: 0 0 .875rem;
    font-size: .75rem;
    line-height: 1.55;
    color: var(--grey);
}
.docs-card-body .form-label {
    font-family: var(--font-body);
    font-size: .8125rem;
    font-weight: 400;
    color: var(--ink);
}
/* Filled in by syncSupportingDocs() — the marker depends on the paper type. */
.doc-req-mark { font-size: .75rem; }

/* Date Completed owns the full row so its helper line can run the whole width
   of the form and wrap only once it genuinely runs out of room. The input
   itself stays the width it had as a third-width column. */
.date-field .form-control { max-width: 24rem; }
.date-field small {
    display: block;
    margin-top: .25rem;
    max-width: none;
}

/* Checkboxes / radios — flat maroon fills, matching the sidebar filters
   and Quick Settings controls used elsewhere on the site. */
.form-check-input {
    appearance: none;
    -webkit-appearance: none;
    width: 16px;
    height: 16px;
    margin-top: .15rem;
    border: none;
    background-color: #E2DCDC;
    background-image: none;
    background-repeat: no-repeat;
    background-position: center;
    background-size: 12px 12px;
    cursor: pointer;
    flex-shrink: 0;
    transition: background-color .15s;
}
.form-check-input[type="radio"] { border-radius: 50%; }
.form-check-input[type="checkbox"] { border-radius: var(--r-control, 4px); }
.form-check-input:checked { background-color: var(--pup-maroon); }
/* Material Symbols "check" glyph, drawn in white once ticked. */
.form-check-input[type="checkbox"]:checked {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 -960 960 960' fill='%23ffffff'%3E%3Cpath d='M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z'/%3E%3C/svg%3E");
}
/* Radios keep the site's flat dot rather than a glyph. */
.form-check-input[type="radio"]:checked {
    background-image: radial-gradient(circle, #fff 0 2.5px, transparent 3px);
}
.form-check-input:focus {
    outline: 2px solid var(--maroon);
    outline-offset: 2px;
    box-shadow: none;
    border-color: transparent;
}
.form-check-label {
    font-family: var(--font-body);
    font-size: .8125rem;
    color: var(--ink);
    cursor: pointer;
}
.form-check { display: flex; align-items: flex-start; gap: .5rem; margin-bottom: .35rem; }

/* AI Extraction Zone */
.ai-extraction-zone {
    background: linear-gradient(135deg, var(--cream), var(--cream));
    border: 2px dashed var(--maroon);
    border-radius: var(--r-control, 4px);
    padding: 1.5rem;
    text-align: center;
}

.upload-zone {
    position: relative;
    cursor: pointer;
    transition: all 0.3s ease;
}

.upload-zone:hover {
}

.upload-icon {
    font-size: 2.25rem;
    margin-bottom: 1rem;
}

.file-input {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.btn-ai {
    background: var(--maroon);
    color: #fff;
    font-weight: 400;
    padding: .75rem 1.75rem;
    border-radius: var(--r-control, 4px);
    font-size: .875rem;
    border: none;
    transition: background .2s;
}

.btn-ai:hover { background: var(--dark-maroon); color: #fff; }

.btn-ai .ai-icon {
    display: inline-block;
}

.ai-extract-button-container {
}

/* Metadata fields */
.metadata-fields {
    background: var(--cream);
    border-radius: var(--r-control, 4px);
    padding: 1.25rem;
    border: 2px solid var(--border);
}

/* Supporting docs grid */
.supporting-docs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}

.doc-upload-card {
    background: white;
    border: 2px solid var(--border);
    border-radius: var(--r-card, 8px);
    padding: 1.25rem;
    text-align: center;
    transition: all 0.3s ease;
}

.doc-upload-card:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    border-color: var(--maroon);
}

.doc-upload-card.optional {
    border-style: dashed;
}

.doc-icon {
    font-size: 1.75rem;
    margin-bottom: 1rem;
}

.doc-icon.required::after {
    content: '*';
    color: #dc2626;
    font-size: 1.5rem;
    vertical-align: super;
}

.doc-upload-card h5 {
    font-weight: 500;
    color: var(--maroon);
    margin-bottom: 0.5rem;
}

.doc-upload-card .form-control {
    margin-top: 1rem;
}

/* Review summary */
.review-summary {
    background: var(--cream);
    border-radius: var(--r-card, 8px);
    padding: 1.25rem;
    border: 2px solid var(--border);
}

.review-item {
    display: flex;
    padding: 1rem;
    border-bottom: 1px solid var(--border);
    gap: 1rem;
}

.review-item:last-child {
    border-bottom: none;
}

.review-label {
    font-weight: 500;
    color: var(--ink);
    min-width: 180px;
}

.review-value {
    color: var(--maroon);
    font-weight: 500;
    flex: 1;
}

/* Button enhancements */
.btn-next {
    min-width: 0;
}

.btn-lg {
    padding: .75rem 1.75rem;
    font-size: .875rem;
}

/* File indicators */
.file-indicator {
    margin-top: 0.75rem;
    padding: 0.75rem;
    border-radius: var(--r-card, 8px);
    font-size: 0.9rem;
    font-weight: 500;
    text-align: center;
    transition: all 0.3s ease;
    background: var(--cream);
    border: 2px solid var(--border);
}

.file-indicator.selected {
    background: var(--cream);
    border-color: var(--maroon);
    color: var(--dark-maroon);
}

.file-indicator.selected i,
.file-indicator.selected .material-symbols-outlined {
    color: var(--maroon) !important;
}

.file-name {
    display: block;
    font-size: 0.85rem;
    margin-top: 0.25rem;
    color: var(--ink);
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ===== Responsive to the content column, not the window =====
   Docking the chat and the PDF preview pads the body from both sides, so the
   form can be squeezed to a few hundred pixels while the viewport is still
   1900px wide — width media queries never fire in that situation. Declaring the
   column a container lets these rules key off the space the form actually has. */
.upload-shell {
    container-type: inline-size;
    container-name: uploadcol;
}

@container uploadcol (max-width: 760px) {
    .upload-steps { padding: .875rem .5rem; }
    .step-number { width: 38px; height: 38px; font-size: .875rem; }
    .step-connector { width: 46px; margin-top: 18px; }
    .upload-card { padding: 1.5rem; }
    .ai-extraction-zone { padding: 1.75rem 1rem; }
    .supporting-docs-grid { grid-template-columns: 1fr; }
    /* Paired fields stack rather than squeezing into unusable columns. */
    .row > [class*="col-md-"] { flex: 0 0 100%; max-width: 100%; }
    .date-field .form-control, .model-picker { max-width: 100%; }
}

@container uploadcol (max-width: 540px) {
    /* The labels no longer fit side by side; the row scrolls rather than
       wrapping into a broken second line. `safe center` keeps it centred while
       it still fits and only falls back to starting at the left edge once it
       genuinely overflows — plain `center` would push the first step out of
       reach past the start of the scroll area. */
    .upload-steps {
        flex-wrap: nowrap;
        overflow-x: auto;
        justify-content: center;        /* fallback where `safe` is unsupported */
        justify-content: safe center;
    }
    .step-label { font-size: .6875rem; }
    .step-connector { width: 26px; }
    .upload-card { padding: 1rem; }
    /* The editor header needs no rule here: it stays one row and moves the
       tools that will not fit into its ⋮ menu. */
}

@media (max-width: 768px) {
    .upload-steps {
        overflow-x: auto;
        justify-content: center;        /* fallback where `safe` is unsupported */
        justify-content: safe center;
        padding: 1rem 0;
    }

    .step-connector {
        width: 60px;
    }

    .step-label {
        font-size: 0.75rem;
    }
    
    .supporting-docs-grid {
        grid-template-columns: 1fr;
    }
    
    .ai-extraction-zone {
        padding: 2rem 1rem;
    }
}

/* ===== In-page message dialog (replaces the browser's alert box) ===== */
.papel-dialog-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(51,0,0,.40);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    z-index: 20000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease;
}
.papel-dialog-backdrop.open { opacity: 1; pointer-events: auto; }
.papel-dialog {
    background: var(--white);
    border: 1px solid rgba(177,125,125,.22);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md);
    width: 100%;
    max-width: 440px;
    overflow: hidden;
}
/* For dialogs that explain rather than announce. Wide enough for a comfortable
   line, and capped so the text never runs to the full width of a large screen,
   where the eye loses its place returning to the next line. */
.papel-dialog.is-wide { max-width: 720px; }
.papel-dialog.is-wide .papel-dialog-body { padding: 1.125rem 1.5rem; }
.papel-dialog-head {
    display: flex;
    align-items: center;
    gap: .625rem;
    padding: .75rem 1.25rem;
    border-bottom: 1px solid var(--maroon);
}
.papel-dialog-head .material-symbols-outlined {
    color: var(--maroon);
    font-size: 20px;
    flex-shrink: 0;
    position: relative;
    top: -2px;
}
.papel-dialog-head h2 {
    font-family: var(--font-head);
    font-size: 1rem;
    font-weight: 500;
    color: var(--maroon);
    position: relative;
    top: 1px;
}
.papel-dialog-body {
    padding: 1.25rem;
    font-size: .875rem;
    color: var(--ink);
    line-height: 1.6;
}
/* ===== PDF preview panel =====
   Docks to the right the way the chat panel does: fixed full-height column,
   page content padded aside to give way rather than being covered. */
#pdf-preview {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: var(--pdf-dock-w, 460px);
    z-index: 1200;
    display: block;
    background: var(--white);
    border-left: 1px solid var(--border);
    box-shadow: -6px 0 24px rgba(51, 0, 0, .10);
}
#pdf-preview[hidden] { display: none; }
#pdf-preview-head {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 52px;
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: 0 1rem;
    background: var(--maroon);
    color: #fff;
}
#pdf-preview-name {
    flex: 1 1 auto;
    min-width: 0;
    font-family: var(--font-head);
    font-size: .8125rem;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pdf-preview-tools { display: flex; align-items: center; gap: .125rem; flex: 0 0 auto; }
.pdf-preview-tools button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;      /* min-, not fixed: the zoom label needs room for "100%" */
    height: 28px;
    padding: 0 .25rem;
    border: none;
    border-radius: var(--r-control, 4px);
    background: none;
    color: #fff;
    font-family: inherit;
    font-size: .6875rem;
    cursor: pointer;
    transition: background .15s;
}
.pdf-preview-tools button:hover { background: rgba(255, 255, 255, .18); }
.pdf-preview-tools button.active { background: rgba(255, 255, 255, .28); }
/* Fills everything below the header. */
#pdf-preview-body {
    position: absolute;
    top: 52px;          /* clears the header */
    left: 0;
    right: 0;
    bottom: 0;
}
/* The pages are drawn onto canvases we own, inside a scroller we size. The
   browser's built-in viewer was measured filling the full height and still
   painting into a strip of it, so the document is rendered here instead. */
#pdf-preview-scroll {
    position: absolute;
    inset: 0;
    overflow-y: auto;
    /* auto, not hidden: zoomed past fit-width the page is wider than the panel
       and has to be pannable. */
    overflow-x: auto;
    padding: .75rem;
    background: var(--cream);
    text-align: center;
}
#pdf-preview-scroll:focus-visible { outline: 2px solid var(--maroon); outline-offset: -2px; }
/* Page, text-layer and cursor styling comes from assests/css/papel-pdf-view.css,
   shared with the full-window viewer. */
#pdf-preview-status {
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    padding: 1rem;
    text-align: center;
    font-size: .8125rem;
    color: var(--grey);
}
#pdf-preview-status:empty { display: none; }
#pdf-preview-scroll::-webkit-scrollbar { width: 10px; }
#pdf-preview-scroll::-webkit-scrollbar-track { background: var(--cream); }
#pdf-preview-scroll::-webkit-scrollbar-thumb {
    background: var(--soft-maroon);
    border-radius: var(--r-card, 8px);
    border: 2px solid var(--cream);
}
#pdf-preview-scroll::-webkit-scrollbar-thumb:hover { background: var(--maroon); }
body.pdf-docked { padding-right: var(--pdf-dock-w, 460px); }

/* With the chat docked left and the preview right, the form is squeezed from
   both sides. Both widths are custom properties read by the panels themselves,
   so narrowing them here narrows the panels and hands the space back. */
@media (max-width: 1500px) {
    body.pdf-docked.chat-docked-left { --pdf-dock-w: 400px; --chat-dock-w: 330px; }
}
@media (max-width: 1250px) {
    body.pdf-docked.chat-docked-left { --pdf-dock-w: 340px; --chat-dock-w: 300px; }
}


/* Restore tab — pinned to the right edge once the preview is put away. */
#pdf-restore {
    position: fixed;
    top: 50%;
    right: 0;
    transform: translateY(-50%);
    z-index: 1150;          /* under the preview itself, over the page */
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .25rem;
    padding: .75rem .5rem;
    border: none;
    border-radius: var(--r-control, 4px) 0 0 var(--r-control, 4px);
    background: var(--maroon);
    color: #fff;
    font-family: var(--font-body);
    font-size: .625rem;
    letter-spacing: .04em;
    cursor: pointer;
    box-shadow: -2px 0 10px rgba(51, 0, 0, .22);
    transition: background .15s, padding .15s;
}
#pdf-restore[hidden] { display: none; }
#pdf-restore:hover { background: var(--dark-maroon); padding-right: .75rem; }
#pdf-restore:focus-visible { outline: 2px solid var(--white); outline-offset: -4px; }
.pdf-restore-label { writing-mode: vertical-rl; text-orientation: mixed; }

/* The file row becomes a control once a PDF is chosen. */
.file-indicator.selected#pdf-indicator { cursor: pointer; }
.file-indicator.selected#pdf-indicator:hover {
    background: var(--white);
    border-color: var(--dark-maroon);
}
.pdf-indicator-hint {
    display: block;
    margin-top: .125rem;
    font-size: .6875rem;
    color: var(--grey);
}

@media (max-width: 900px) {
    #pdf-preview { width: 100vw; }
    body.pdf-docked { padding-right: 0; }
}

/* The same dialog doubles as a prompt; the field sits under the message and
   above the buttons, so the padding is trimmed to avoid a double gap. */
.papel-dialog-input {
    padding-top: 0;
    padding-bottom: 0;
}
.papel-dialog-input .form-control {
    width: 100%;
    font-size: .875rem;
}
.papel-dialog-foot {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;          /* three actions still fit on a narrow phone */
    gap: .5rem;
    padding: 1.25rem;
}
/* A button label is a phrase, not a paragraph: without this "Leave without
   saving" broke onto three lines the moment a third action joined the row. */
.papel-dialog-foot .btn { white-space: nowrap; }
</style>
</head>
<body>

<!-- Upload / extraction overlay -->
<div id="uploadOverlay">
  <div class="loading-content">
    <!-- The book. Decorative: the text below it says what is happening, and
         a screen reader should hear that rather than five empty divs. -->
    <div class="page-turn" aria-hidden="true">
      <div class="page-turn-half page-turn-left"></div>
      <div class="page-turn-half page-turn-right"></div>
      <div class="page-turn-leaf"></div>
      <div class="page-turn-leaf"></div>
      <div class="page-turn-leaf"></div>
    </div>

    <!-- Text -->
    <div class="loading-text">Uploading your paper<span class="loading-dots"></span></div>
    <div class="loading-status" id="uploadStatusMsg">Preparing your files</div>

    <!-- Progress bar -->
    <div class="upload-progress-track">
      <div class="upload-progress-fill" id="uploadProgressFill"></div>
    </div>
    <div class="upload-progress-label" id="uploadProgressLabel">0%</div>
  </div>
</div>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- Breadcrumb -->
<div class="crumb-bar">
  <div class="wrap crumb-inner">
    <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
    <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
    <a href="<?= e($isStudent ? 'student_dashboard.php' : role_home($u['user_role'])) ?>"><?= e(role_home_label($u['user_role'])) ?></a>
    <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
    <span class="crumb-current">Upload Paper</span>
  </div>
</div>

<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-lg-11 col-xl-10 upload-shell">
      
      <!-- Progress Steps -->
      <div class="upload-steps mb-4">
        <div class="step-item active" data-step="1">
          <div class="step-number">1</div>
          <div class="step-label">Paper Details</div>
        </div>
        <div class="step-connector"></div>
        <div class="step-item" data-step="2">
          <div class="step-number">2</div>
          <div class="step-label">Upload & Details</div>
        </div>
        <div class="step-connector"></div>
        <div class="step-item" data-step="3">
          <div class="step-number">3</div>
          <div class="step-label">Supporting Docs</div>
        </div>
        <div class="step-connector"></div>
        <div class="step-item" data-step="4">
          <div class="step-number">4</div>
          <div class="step-label">Review & Submit</div>
        </div>
      </div>

      <div class="upload-shell">
        <div class="card upload-card">
          <div class="card-body">
            <h1 class="page-title">
                Upload Paper
            </h1>

            <?php if(!is_gdrive_connected()): ?>
              <div class="alert alert-danger text-center p-5">
                  <span class="material-symbols-outlined mi-fill" style="font-size: 3rem;">warning</span>
                  <h3 class="mt-3">Google Drive Not Configured</h3>
                  <p class="mb-0">The system Google Drive connection is not set up. Please contact your administrator to connect Google Drive before submissions can be accepted.</p>
              </div>
              <style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">#uploadForm { display: none; }</style>
            <?php endif; ?>


            <form id="uploadForm" enctype="multipart/form-data" method="post" novalidate>
              <?= csrf_field(); ?>
              <input type="hidden" name="action" value="upload_paper">

              <!-- STEP 1: Paper Details -->
              <div class="upload-section" id="step1">
                <div class="section-header">
                  <h3 class="section-title">
                    <span class="step-badge">Step 1</span>
                    Basic Information
                  </h3>
                </div>

                <div class="row g-4">
                  <div class="col-md-12">
                    <label class="form-label">Academic Program <span class="text-danger">*</span></label>
                    <?php if (!$isStudent): ?>
                      <?php /* Not a question for staff: they belong to the faculty rather
                               than to a degree, so the field states that instead of
                               offering ten programmes none of which is theirs. Posted as
                               a hidden field, exactly as a chosen one would be. */ ?>
                      <input type="text" class="form-control" value="Faculty Member" readonly
                             aria-label="Academic Program">
                      <input type="hidden" name="program_category" id="programSelect" value="Faculty Member">
                      <small class="text-muted">Papers you upload are filed under the faculty, not a programme.</small>
                    <?php else: ?>
                    <select class="form-select" name="program_category" id="programSelect" required>
                      <option value="">Select Program...</option>
                      <?php /* Read from programs_map() and written the way the
                               Manage Students console writes it — "BSIT: Bachelor
                               of Science in Information Technology". The ten
                               programmes were spelled out here in a shorthand of
                               their own, so a student saw their degree named one
                               way on their account and another way on this form. */ ?>
                      <?php foreach (programs_map() as $progName => $progCode): ?>
                        <option value="<?= e($progName) ?>"<?= $myProgram === $progName ? ' selected' : '' ?>><?= e($progCode) ?>: <?= e($progName) ?></option>
                      <?php endforeach; ?>
                      <?php /* Not programmes, and not in the map: kept so an
                               account already carrying one still matches. */ ?>
                      <option value="Faculty Member"<?= $myProgram === 'Faculty Member' ? ' selected' : '' ?>>Faculty Member</option>
                      <option value="Other"<?= $myProgram === 'Other' ? ' selected' : '' ?>>Others</option>
                    </select>
                    <?php endif; ?>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Paper / Research Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="paper_type" required>
                      <option value="">Select paper type...</option>
                      <?php foreach (paper_types() as $ptCode => $ptLabel): ?>
                        <option value="<?= e($ptCode) ?>"><?= e($ptLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-12">
                    <?php /* Two boxes rather than a list, because a paper can be
                             handed in as both. Ticking both asks for both files
                             in step 2. */ ?>
                    <div class="label-row">
                      <label class="form-label mb-0">Manuscript Type <span class="text-danger">*</span></label>
                      <span class="field-help-wrap">
                        <button type="button" class="field-help js-help" aria-expanded="false"
                                aria-label="What is manuscript type?">
                          <span class="material-symbols-outlined">help</span>
                        </button>
                        <span class="help-card" role="note" hidden>
                          <p class="help-lede">How the paper is written up. Tick both if you are handing it in both ways, and you will be asked for two files in the next step, one of each.</p>
                          <dl>
                            <dt>IMRAD</dt>
                            <dd>Introduction, Methodology, Results and Discussion, written straight through with no numbered chapters. Your adviser is not asked about chapters when reviewing one.</dd>
                            <dt>Full Manuscript</dt>
                            <dd>The complete write-up, with its numbered chapters.</dd>
                          </dl>
                        </span>
                      </span>
                    </div>
                    <div class="check-row">
                      <div class="form-check mb-0">
                        <input class="form-check-input js-manuscript" type="checkbox"
                               name="manuscript_type[]" id="mt_imrad" value="IMRAD">
                        <label class="form-check-label" for="mt_imrad">IMRAD</label>
                      </div>
                      <div class="form-check mb-0">
                        <input class="form-check-input js-manuscript" type="checkbox"
                               name="manuscript_type[]" id="mt_full" value="Full Manuscript">
                        <label class="form-check-label" for="mt_full">Full Manuscript</label>
                      </div>
                    </div>
                  </div>

                  <div class="col-12">
                      <div class="label-row">
                        <label class="form-label mb-0">Paper Status</label>
                        <span class="field-help-wrap">
                          <button type="button" class="field-help js-help" aria-expanded="false"
                                  aria-label="What is paper status?">
                            <span class="material-symbols-outlined">help</span>
                          </button>
                          <span class="help-card" role="note" hidden>
                            <p class="help-lede">Where the paper stands. Published and Unpublished are opposites, so ticking one clears the other. Presented can go with either.</p>
                            <dl>
                              <dt>Published Paper</dt>
                              <dd>It has appeared in a journal or in conference proceedings. You will be asked where.</dd>
                              <dt>Unpublished Paper</dt>
                              <dd>Finished, but not published anywhere.</dd>
                              <dt>Presented Paper</dt>
                              <dd>It has been presented at a conference or forum.</dd>
                            </dl>
                          </span>
                        </span>
                      </div>
                      <div class="check-row">
                          <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="paper_status[]" id="status_published" value="Published Paper">
                              <label class="form-check-label" for="status_published">Published Paper</label>
                          </div>
                          <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="paper_status[]" id="status_unpublished" value="Unpublished Paper" checked>
                              <label class="form-check-label" for="status_unpublished">Unpublished Paper</label>
                          </div>
                          <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="paper_status[]" id="status_presented" value="Presented Paper">
                              <label class="form-check-label" for="status_presented">Presented Paper</label>
                          </div>
                      </div>
                      <div class="mt-3 pub-location" id="pubLocationDiv">
                          <input type="text" class="form-control" name="publication_location" placeholder="Enter publication location/journal" list="pub_locations_list" autocomplete="off">
                          <datalist id="pub_locations_list"></datalist>
                      </div>
                  </div>
                </div>

                <div class="mt-4 text-end">
                  <button type="button" class="btn btn-primary btn-next" data-goto-step="2">
                    Next: Upload PDF
                  </button>
                </div>
              </div>

              <!-- STEP 2: Upload PDF -->
              <div class="upload-section" id="step2">
                <div class="section-header">
                  <h3 class="section-title">
                    <span class="step-badge">Step 2</span>
                    Upload PDF &amp; Enter Details
                  </h3>
                </div>

                <div class="ai-extraction-zone">
                  <div class="upload-zone">
                                        <h4>Drop your PDF here or click to browse</h4>
                    <p class="text-muted">Maximum file size: 50MB</p>
                    <input type="file" class="form-control file-input" id="pdfFile" name="research_pdf" accept="application/pdf" required>
                  </div>

                  <div class="file-indicator mt-3" id="pdf-indicator">
                    <span class="material-symbols-outlined mi-18 text-muted">cancel</span> No PDF selected
                  </div>

                  <div class="ai-extract-button-container mt-4 text-center">
                    <p class="fw-semibold mb-3" style="color:var(--maroon);">How would you like to enter your paper details?</p>
                  
                    <div class="mb-3 model-picker">
                      <label class="form-label" for="extractModelSelect">AI extraction engine</label>
                      <select id="extractModelSelect" class="form-select form-select-sm">
                        <option value="1">Aincrad</option>
                        <option value="2">Alfheim</option>
                      </select>
                      <small class="model-picker-note">
                        Aincrad and Alfheim are the two engines that read your PDF and pull out the
                        title, authors, year, keywords and abstract. They do the same job, so either
                        is fine. If one is busy and extraction fails, pick the other and try again.
                      </small>
                    </div>

                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                      <button type="button" class="btn btn-ai btn-lg" id="btnExtract" style="position:relative;">
                        Extract Metadata with AI
                      </button>
                      <button type="button" class="btn btn-outline-secondary btn-lg" id="btnManual">
                        Fill Manually
                      </button>
                    </div>
                    <p class="text-muted text-center mt-2 small">Let AI analyze your PDF automatically, or enter the details yourself</p>
                  </div>
                </div>

                <!-- Extracted / Manual Fields -->
                <div class="metadata-fields mt-5" id="metadataFields">
                  <h4 class="mb-4" id="metadataFieldsHeader">
                    <span class="step-badge" id="metadataBadge">Extracted</span>
                    Review &amp; Edit Metadata
                  </h4>
                
                  <div class="row g-3">
                    <div class="col-md-8">
                      <label class="form-label">Title</label>
                      <input class="form-control" id="titleField" name="title" placeholder="Paper title" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Year</label>
                      <input type="number" class="form-control" id="yearField" name="year" min="1900" max="2100" placeholder="2024" required>
                    </div>
                    <div class="col-md-12 date-field">
                      <label class="form-label">Date Completed <span class="text-danger">*</span></label>
                      <input type="date" class="form-control" id="researchDateField" name="research_date" min="1900-01-01" max="2100-12-31" required>
                      <small class="text-muted">When the research was finished. Shown on your paper card.</small>
                    </div>
                    <div class="col-md-12">
                      <label class="form-label">Authors</label>
                      <input class="form-control" id="authorsField" name="authors" placeholder="Author names (comma-separated)" required>
                    </div>
                    <div class="col-md-12">
                      <label class="form-label">Keywords <span class="text-danger">*</span></label>
                      <input class="form-control" id="keywordsField" name="keywords" placeholder="Enter at least 5 keywords (comma-separated)">
                      <small class="text-muted">Minimum 5 keywords required.</small>
                    </div>
                    <div class="col-md-12">
                      <label class="form-label">Abstract &amp; IMRAD <span class="field-note ms-2">AI-extracted &amp; editable</span></label>
                      <div class="alert alert-info mb-3">
                        Only the Abstract is taken from your PDF, copied word-for-word so you can
                        check it against your paper. Write the Introduction, Methodology, Results
                        and Discussion, Conclusion and References yourself. Every section is required.
                        <?php /* The same explanation the dialog gives on arriving at this step, so
                                 closing that dialog does not put it out of reach. */ ?>
                        <button type="button" class="btn-why-sections" id="btnWhySections">
                          <span class="material-symbols-outlined mi-18">help</span>
                          <span>Why do I have to type these?</span>
                        </button>
                      </div>

                      <?php
                      /* One editor per section. The set of sections and their
                         labels come from paper_section_labels(), which the submit
                         handler and the draft both validate against — so a section
                         cannot exist on the form without being saved, or be
                         demanded on submit without being shown here. Only the
                         wording of the placeholder lives on this page. */
                      $section_hints = [
                          'abstract'           => 'The exact abstract from your paper.',
                          'introduction'       => 'Background, the problem, and what your study set out to do.',
                          'methodology'        => 'Research design, respondents, instruments, and how you analysed the data.',
                          'results_discussion' => 'What you found, and what those findings mean.',
                          'conclusion'         => 'What the study concludes, and what you recommend next.',
                          'references'         => 'Every source you cited, in the citation style your program requires.',
                      ];
                      $paper_sections = [];
                      foreach (paper_section_labels() as $secKey => $secLabel) {
                          $paper_sections[] = [
                              'key'   => $secKey,
                              'name'  => $secKey,     // the hidden input the form posts
                              'label' => $secLabel,
                              'hint'  => $section_hints[$secKey] ?? '',
                          ];
                      }
                      foreach ($paper_sections as $sec): ?>
                        <div class="doc-editor" data-section="<?= e($sec['key']) ?>">
                          <div class="doc-editor-head">
                            <span class="doc-editor-title"><?= e($sec['label']) ?> <span class="text-danger">*</span></span>
                            <div class="doc-toolbar" role="toolbar" aria-label="<?= e($sec['label']) ?> formatting">
                            <button type="button" class="doc-tool" data-cmd="undo" title="Undo (Ctrl+Z)" aria-label="Undo"><span class="material-symbols-outlined mi-18">undo</span></button>
                            <button type="button" class="doc-tool" data-cmd="redo" title="Redo (Ctrl+Y or Ctrl+Shift+Z)" aria-label="Redo"><span class="material-symbols-outlined mi-18">redo</span></button>
                            <span class="doc-tool-sep"></span>
                            <button type="button" class="doc-tool" data-cmd="bold" title="Bold (Ctrl+B)" aria-label="Bold"><span class="material-symbols-outlined mi-18">format_bold</span></button>
                            <button type="button" class="doc-tool" data-cmd="italic" title="Italic (Ctrl+I)" aria-label="Italic"><span class="material-symbols-outlined mi-18">format_italic</span></button>
                            <button type="button" class="doc-tool" data-cmd="underline" title="Underline (Ctrl+U)" aria-label="Underline"><span class="material-symbols-outlined mi-18">format_underlined</span></button>
                            <button type="button" class="doc-tool" data-cmd="strikeThrough" title="Strikethrough" aria-label="Strikethrough"><span class="material-symbols-outlined mi-18">format_strikethrough</span></button>
                            <?php /* No alignment buttons here. They can only act on a table cell,
                                     so on a toolbar used mostly for prose they sat greyed out and
                                     read as broken. Alignment now lives on the right-click menu
                                     over a table, where it always applies. */ ?>
                            <span class="doc-tool-sep"></span>
                            <button type="button" class="doc-tool" data-cmd="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list"><span class="material-symbols-outlined mi-18">format_list_bulleted</span></button>
                            <button type="button" class="doc-tool" data-cmd="insertOrderedList" title="Numbered list" aria-label="Numbered list"><span class="material-symbols-outlined mi-18">format_list_numbered</span></button>
                            <button type="button" class="doc-tool" data-role="outdent" title="Decrease indent (Shift+Tab)" aria-label="Decrease indent"><span class="material-symbols-outlined mi-18">format_indent_decrease</span></button>
                            <button type="button" class="doc-tool" data-role="indent" title="Indent first line (Tab)" aria-label="Indent first line"><span class="material-symbols-outlined mi-18">format_indent_increase</span></button>
                            <span class="doc-tool-sep"></span>
                            <button type="button" class="doc-tool" data-role="link" title="Insert link (Ctrl+K)" aria-label="Insert link"><span class="material-symbols-outlined mi-18">link</span></button>
                            <button type="button" class="doc-tool" data-role="unlink" title="Remove link" aria-label="Remove link"><span class="material-symbols-outlined mi-18">link_off</span></button>
                            <span class="doc-tool-sep"></span>
                            <button type="button" class="doc-tool" data-role="image" title="Insert picture" aria-label="Insert picture"><span class="material-symbols-outlined mi-18">image</span></button>
                            <span class="doc-pop-wrap doc-table-wrap" data-pop="table">
                              <button type="button" class="doc-tool" data-role="table" title="Table" aria-label="Insert or edit table" aria-haspopup="true"><span class="material-symbols-outlined mi-18">table</span></button>
                            </span>
                            <button type="button" class="doc-tool" data-cmd="removeFormat" title="Clear formatting" aria-label="Clear formatting"><span class="material-symbols-outlined mi-18">format_clear</span></button>
                            </div>
                            <span class="doc-editor-count" data-role="count">0 words</span>
                            <button type="button" class="doc-tool doc-expand" data-role="expand" title="Expand this section (Esc to exit)" aria-label="Expand this section" aria-pressed="false"><span class="material-symbols-outlined mi-18">open_in_full</span></button>
                          </div>
                          <div class="doc-surface"
                               contenteditable="true"
                               role="textbox"
                               aria-multiline="true"
                               aria-label="<?= e($sec['label']) ?>"
                               data-role="surface"
                               data-placeholder="<?= e($sec['hint']) ?>"></div>
                          <input type="hidden" name="<?= e($sec['name']) ?>" data-role="value">
                        </div>
                      <?php endforeach; ?>
                    </div>
                  
                    <!-- Hidden fields for AI data -->
                    <input type="hidden" id="aiSummaryField" name="ai_summary">
                    <input type="hidden" id="aiMethodologyField" name="ai_methodology">
                    <input type="hidden" id="aiStatsField" name="ai_statistical_methods">
                    <input type="hidden" id="aiVariablesField" name="ai_variables">
                    <input type="hidden" id="aiSampleSizeField" name="ai_sample_size">
                    <input type="hidden" id="aiResearchFieldField" name="ai_research_field">
                  </div>
                </div>

                <div class="mt-4 d-flex justify-content-between">
                  <button type="button" class="btn btn-outline-secondary" data-goto-step="1">
                    Back
                  </button>
                  <button type="button" class="btn btn-primary btn-next" id="btnNextToSupporting" data-goto-step="3">
                    Next: Supporting Documents
                  </button>
                </div>
              </div>

              <!-- STEP 3: Supporting Documents -->
              <div class="upload-section" id="step3">
                <div class="section-header">
                  <h3 class="section-title">
                    <span class="step-badge">Step 3</span>
                    Supporting Documents
                  </h3>
                </div>

                <div class="alert alert-warning mb-4">
                  <div id="docsRequirementNote">
                    <strong>Required documents</strong>
                    <p class="mb-0 mt-1">These are needed before the paper can be submitted.</p>
                  </div>
                </div>

                <!-- Ethics bundle -->
                <div class="docs-card">
                  <div class="docs-card-head">
                    <span class="material-symbols-outlined mi-20">verified_user</span>
                    <h4>Ethics Clearance Bundle</h4>
                  </div>
                  <div class="docs-card-body">
                    <p class="docs-note">
                      Each file must be a PDF. The clearance, the consent form and the instrument
                      you used to gather data are reviewed together.
                    </p>
                    <div class="row g-3">
                      <div class="col-md-4">
                        <label class="form-label" for="ethics_clearance">Ethics Clearance</label>
                        <input type="file" name="ethics_clearance" id="ethics_clearance" class="form-control" accept="application/pdf">
                        <div class="file-indicator" id="ethics-indicator"><span class="material-symbols-outlined mi-18 text-muted">cancel</span> No file selected</div>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label" for="consent_form">Consent Form</label>
                        <input type="file" name="consent_form" id="consent_form" class="form-control" accept="application/pdf">
                        <div class="file-indicator" id="consent-indicator"><span class="material-symbols-outlined mi-18 text-muted">cancel</span> No file selected</div>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label" for="data_collection">Data Collection Tool</label>
                        <input type="file" name="data_collection" id="data_collection" class="form-control" accept="application/pdf">
                        <div class="file-indicator" id="data-indicator"><span class="material-symbols-outlined mi-18 text-muted">cancel</span> No file selected</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Copyright -->
                <div class="docs-card">
                  <div class="docs-card-head">
                    <span class="material-symbols-outlined mi-20">copyright</span>
                    <h4>Copyright Documentation</h4>
                  </div>
                  <div class="docs-card-body">
                    <label class="form-label" for="copyright_doc">Copyright / IP Document</label>
                    <input type="file" name="copyright_doc" id="copyright_doc" class="form-control" accept="application/pdf">
                    <div class="file-indicator" id="copyright-indicator"><span class="material-symbols-outlined mi-18 text-muted">cancel</span> No file selected</div>
                  </div>
                </div>

                <!-- Anything else -->
                <div class="docs-card">
                  <div class="docs-card-head">
                    <span class="material-symbols-outlined mi-20">attach_file</span>
                    <h4>Other Document</h4>
                    <span class="docs-card-hint">Optional</span>
                  </div>
                  <div class="docs-card-body">
                    <div class="row g-3">
                      <div class="col-md-5">
                        <label class="form-label" for="other_doc_name">Document name</label>
                        <input type="text" class="form-control" id="other_doc_name" name="other_doc_name" placeholder="What is this document?">
                      </div>
                      <div class="col-md-7">
                        <label class="form-label" for="other_doc">File</label>
                        <input type="file" name="other_doc" id="other_doc" class="form-control" accept="application/pdf">
                        <div class="file-indicator" id="other-indicator"><span class="material-symbols-outlined mi-18 text-muted">do_not_disturb_on</span> Optional</div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="mt-4 d-flex justify-content-between">
                  <button type="button" class="btn btn-outline-secondary" data-goto-step="2">
                    Back
                  </button>
                  <button type="button" class="btn btn-primary btn-next" data-goto-step="4">
                    Next: Review
                  </button>
                </div>
              </div>

              <!-- STEP 4: Review & Submit -->
              <div class="upload-section" id="step4">
                <div class="section-header">
                  <h3 class="section-title">
                    <span class="step-badge">Step 4</span>
                    Review & Submit
                  </h3>
                </div>

                <div class="review-summary">
                  <div class="review-item">
                    <div class="review-label">Paper Type:</div>
                    <div class="review-value" id="reviewPaperType">-</div>
                  </div>
                  <div class="review-item">
                    <div class="review-label">Research PDF:</div>
                    <div class="review-value" id="reviewPDF">-</div>
                  </div>
                  <div class="review-item">
                    <div class="review-label">Title:</div>
                    <div class="review-value" id="reviewTitle">-</div>
                  </div>
                  <div class="review-item">
                    <div class="review-label">Authors:</div>
                    <div class="review-value" id="reviewAuthors">-</div>
                  </div>
                  <div class="review-item">
                    <div class="review-label">Year:</div>
                    <div class="review-value" id="reviewYear">-</div>
                  </div>
                  <div class="review-item">
                    <div class="review-label">Supporting Docs:</div>
                    <div class="review-value" id="reviewDocs">-</div>
                  </div>
                  <div class="review-item">
                    <div class="review-label">Copyright:</div>
                    <div class="review-value" id="reviewCopyright">-</div>
                  </div>
                </div>

                <div class="alert alert-success mt-4">
                  <div class="d-flex align-items-start gap-3">
                    
                    <div>
                      <strong>Ready to Submit!</strong>
                      <p class="mb-0 mt-1">Please review all information above before final submission.</p>
                    </div>
                  </div>
                </div>

                <div class="mt-4 d-flex justify-content-between">
                  <button type="button" class="btn btn-outline-secondary" data-goto-step="3">
                    Back
                  </button>
                  <?php if ($canDraft): ?>
                  <button class="btn btn-outline-secondary btn-lg" id="btnSaveDraft" type="button">
                    Save as Draft
                  </button>
                  <?php endif; ?>
                  <button class="btn btn-primary btn-lg" id="btnUpload" type="submit">
                    Submit Paper
                  </button>
                </div>
              </div>

            </form>

          </div>
        </div>
      </div><!-- /.upload-shell -->
    </div>
  </div>
</div>

<!-- Chatbot Widget -->
<div id="chat-widget">
    <div id="chat-window" style="display: none;">
        <div id="chat-header">
            <div class="d-flex align-items-center gap-2">
                <button type="button" id="chatDockBtn" title="Dock to side panel" aria-label="Dock to side panel">
                    <span class="material-symbols-outlined mi-20" id="chatDockIcon">dock_to_left</span>
                </button>
                <button type="button" id="chatSideBtn" title="Move to right side" aria-label="Move to right side">
                    <span class="material-symbols-outlined mi-20" id="chatSideIcon">dock_to_right</span>
                </button>
                <img id="puppyHeader" class="puppy-avatar" width="34" height="34"
                     alt="PUPPY" src="<?= BASE_URL ?>/assests/images/papel_red_welcome.gif">
                <div class="chat-identity" tabindex="0" aria-describedby="chatIdentityTip">
                    <h5 class="mb-0">PUPPY the Ai dog assistant</h5>
                    <p class="mb-0 small">Here to help with uploading</p>
                    <div class="chat-identity-tip" id="chatIdentityTip" role="tooltip">
                        <span class="tip-name">PUPPY the Ai dog assistant</span>
                        <span class="tip-desc">Here to help with uploading</span>
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <select id="chatModelSelect">
                    <option value="1">Aincrad</option>
                    <option value="2">Alfheim</option>
                </select>
                <button type="button" id="chatCloseBtn"><span class="material-symbols-outlined mi-20">close</span></button>
            </div>
        </div>
        <div id="chat-messages">
            <div class="msg-row">
                <img class="msg-avatar" data-mood="welcome" alt="PUPPY"
                     src="<?= BASE_URL ?>/assests/images/papel_red_welcome.gif">
                <div class="message bot">Hi! I'm PUPPY the Ai dog assistant, your upload guide. Need help with requirements like Ethics Clearance or Consent Forms? Ask me!</div>
            </div>
        </div>
        <div id="chat-input-area">
            <form id="chatForm" class="chat-input-wrapper">
                <input type="text" id="chat-input" placeholder="Type your message..." autocomplete="off">
                <button type="submit" class="send-btn"><span class="material-symbols-outlined mi-18">send</span></button>
            </form>
        </div>
    </div>
    <button type="button" id="chat-button" title="Ask AI Assistant">
        <img id="puppyFab" class="puppy-avatar" width="48" height="48"
             alt="Ask PUPPY" src="<?= BASE_URL ?>/assests/images/papel_red_welcome.gif">
    </button>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
// Paths the PDF preview needs. Declared here, ahead of the code that reads
// them, rather than beside the markup further down — both are same-origin, so
// script-src 'self' already covers the library and its worker and the CSP needs
// no widening.
const PAPEL_PDF_WORKER     = <?= json_encode(BASE_URL . '/assests/js/pdfjs/pdf.worker.min.js', JSON_UNESCAPED_SLASHES) ?>;
const PAPEL_PDF_VIEWER_URL = <?= json_encode(BASE_URL . '/app/student/pdf_viewer.php', JSON_UNESCAPED_SLASHES) ?>;

/* ----- Supporting documents, by paper type -----
   Ethics clearance, consent and a data-collection tool are only demanded of the
   types that involve human participants and original data gathering. The list
   mirrors paper_type_needs_documents() in config/core.php — the server decides,
   this only keeps the form honest about what it is going to ask for. */
const DOCS_REQUIRED_FOR = <?= json_encode(array_values(array_filter(
    array_keys(paper_types() + paper_types_retired()),
    'paper_type_needs_documents'))) ?>;
/* Retired types are included so a draft filed under one still shows its name
   rather than a bare code; only paper_types() is offered in the dropdown. */
const PAPER_TYPE_LABELS = <?= json_encode(paper_types() + paper_types_retired(),
    JSON_UNESCAPED_UNICODE) ?>;
const REQUIRED_DOCS = [
    { name: 'ethics_clearance', label: 'Ethics Clearance' },
    { name: 'consent_form',     label: 'Consent Form' },
    { name: 'data_collection',  label: 'Data Collection Tool' },
    { name: 'copyright_doc',    label: 'Copyright / IP Document' }
];

function currentPaperType() {
    const el = document.querySelector('select[name="paper_type"]');
    return el ? el.value.trim().toLowerCase() : '';
}
function supportingDocsRequired() {
    return DOCS_REQUIRED_FOR.indexOf(currentPaperType()) !== -1;
}
function paperTypeLabel() {
    return PAPER_TYPE_LABELS[currentPaperType()] || 'paper of this type';
}

/* Repaints Step 3 whenever the paper type changes: the required markers, the
   browser's own `required` attributes and the explanatory banner all follow the
   same rule, so the form never asks for something it will not enforce. */
function syncSupportingDocs() {
    const required = supportingDocsRequired();

    REQUIRED_DOCS.forEach(function (doc) {
        const input = document.querySelector('input[name="' + doc.name + '"]');
        if (!input) return;
        input.required = required;

        /* The input's own label, found by its `for` — not "the first label in
           some ancestor". Copyright/IP sits outside the grid the other three
           are in, so climbing to a `col-` ancestor walked all the way up past
           Step 3 and marked the first label it met on the page: Academic
           Program, which then read "Academic Program * (optional)". */
        const label = document.querySelector('label[for="' + doc.name + '"]');
        if (!label) return;
        let mark = label.querySelector('.doc-req-mark');
        if (!mark) {
            mark = document.createElement('span');
            mark.className = 'doc-req-mark';
            label.appendChild(mark);
        }
        mark.className = 'doc-req-mark ' + (required ? 'text-danger' : 'text-muted');
        mark.textContent = required ? ' *' : ' (optional)';
    });

    const banner = document.getElementById('docsRequirementNote');
    if (banner) {
        banner.innerHTML = required
            ? '<strong>Required documents</strong>'
              + '<p class="mb-0 mt-1">A ' + paperTypeLabel() + ' must be submitted with all four documents below.</p>'
            : '<strong>Documents are optional for this paper type</strong>'
              + '<p class="mb-0 mt-1">A ' + paperTypeLabel() + ' does not require ethics clearance or a consent form. '
              + 'Attach anything you have; nothing here will hold up your submission.</p>';
        banner.parentElement.classList.toggle('alert-warning', required);
        banner.parentElement.classList.toggle('alert-info', !required);
    }
}

// Step navigation
let currentStep = 1;

/* ===== The help cards beside Manuscript Type and Paper Status =====
   Delegated from the document, so it does not matter that these sit inside a
   step which is hidden when the page loads. */
(function () {
    function cardOf(btn) { return btn.parentElement.querySelector('.help-card'); }

    function closeAll(except) {
        document.querySelectorAll('.js-help').forEach(function (b) {
            if (b === except) { return; }
            b.setAttribute('aria-expanded', 'false');
            var c = cardOf(b);
            if (c) { c.hidden = true; }
        });
    }

    /* Put the card under its icon, then pull it back inside the window: it is
       fixed, so nothing else will do that for it. If there is no room below —
       which is the usual case for the lower of the two — it opens upward. */
    function place(btn, card) {
        var GAP = 8, EDGE = 8;
        var b = btn.getBoundingClientRect();
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        var cr = card.getBoundingClientRect();
        var w = cr.width, h = cr.height;
        if (!w || !h) { return; }        // not laid out yet; the rAF pass will

        var left = Math.min(Math.max(EDGE, b.left), vw - w - EDGE);
        var top  = b.bottom + GAP;
        if (top + h > vh - EDGE) {
            var above = b.top - GAP - h;
            top = above >= EDGE ? above : Math.max(EDGE, vh - h - EDGE);
        }
        card.style.left = Math.round(left) + 'px';
        card.style.top  = Math.round(top) + 'px';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-help');
        if (!btn) {
            // A click anywhere else puts the open one away, unless it landed
            // inside the card itself.
            if (!e.target.closest('.help-card')) { closeAll(null); }
            return;
        }
        e.preventDefault();
        var card = cardOf(btn);
        if (!card) { return; }
        var opening = card.hidden;
        closeAll(btn);
        card.hidden = !opening;
        btn.setAttribute('aria-expanded', opening ? 'true' : 'false');

        if (opening) {
            place(btn, card);
            /* Again once the browser has laid it out. The first call can run
               before the card has a height, and a height of zero always looks
               like it fits below — which is how it ended up hanging off the
               bottom of a short window. */
            requestAnimationFrame(function () { place(btn, card); });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeAll(null); }
    });
    /* A fixed card would sit still while the page moved out from under it. */
    window.addEventListener('scroll', function () { closeAll(null); }, true);
    window.addEventListener('resize', function () { closeAll(null); });
})();

function handleStatusChange(checkbox) {
    const published = document.getElementById('status_published');
    const unpublished = document.getElementById('status_unpublished');
    const locationDiv = document.getElementById('pubLocationDiv');
    const locationInput = locationDiv.querySelector('input');

    if (checkbox === published && published.checked) {
        unpublished.checked = false;
        locationDiv.style.display = 'block';
        locationInput.required = true;
    } else if (checkbox === unpublished && unpublished.checked) {
        published.checked = false;
        locationDiv.style.display = 'none';
        locationInput.required = false;
        locationInput.value = '';
    } else if (checkbox === published && !published.checked) {
        unpublished.checked = true;
        locationDiv.style.display = 'none';
        locationInput.required = false;
    } else if (checkbox === unpublished && !unpublished.checked) {
        published.checked = true;
        locationDiv.style.display = 'block';
        locationInput.required = true;
    }
}

/* =========================================================================
   Section editors
   A small word-processor over contenteditable: one instance per IMRAD
   section, each with its own toolbar, word count and hidden input. The hidden
   input is what the form actually posts; the surface is only the UI.
   ========================================================================= */
const papelDoc = (function () {
    const editors = {};                      // section key -> { root, surface, input, count }
    /* Alignment is a table cell's business, and is offered on the right-click
       menu over a table and in the table popover. Neither is a toolbar button,
       so none of them has a pressed state to reflect. */
    const CELL_ALIGN = [
        ['Align left',   'left'],
        ['Align centre', 'center'],
        ['Align right',  'right'],
        ['Justify',      'justify'],
    ];
    const STATE_CMDS = ['bold', 'italic', 'underline', 'strikeThrough',
                        'insertUnorderedList', 'insertOrderedList'];

    // Mirrors rich_text_sanitize() in config/core.php. The server is still the
    // authority — this only keeps the live document clean, since pasted markup
    // is inserted into a contenteditable long before it is ever submitted.
    const ALLOWED = ['p','br','div','span','b','strong','i','em','u','strike','s','del','sub','sup',
                     'ul','ol','li','blockquote','a','img',
                     'table','thead','tbody','tfoot','tr','th','td','caption'];
    const DROPPED = ['script','style','iframe','object','embed','applet','noscript','template',
                     'svg','math','head','title','link','meta','base',
                     'form','input','button','select','textarea','option'];
    // No inline styling survives a paste at all. Line height and alignment are
    // fixed for every paper, and colours would carry Word's shading into the
    // repository — so a pasted passage arrives looking like everything the
    // student typed themselves.

    /* Rebuilds pasted content into flowing paragraphs.

       Copying from a PDF gives one hard line break per *visual* line, because
       that is how the page was laid out — PDF.js emits a <br> per line in its
       text layer. CSS never justifies a line that ends in a forced break, so
       pasted text stayed ragged no matter what text-align said, and it also
       kept the PDF's column width instead of reflowing to the editor's.

       A single break is therefore a wrapped line and becomes a space; a run of
       two or more is a real paragraph boundary. Inline formatting and tables
       are left alone. */
    function reflowBreaks(html) {
        const doc = new DOMParser().parseFromString('<div id="r">' + html + '</div>', 'text/html');
        const root = doc.getElementById('r');
        if (!root) return html;

        // Tables carry their own line structure; leave them untouched.
        const TABLE_KEY = '@@PAPEL_TABLE';
        const protect = [];
        root.querySelectorAll('table').forEach(function (t) {
            protect.push(t.outerHTML);
            t.replaceWith(doc.createTextNode(TABLE_KEY + (protect.length - 1) + '@@'));
        });

        // A run of two or more breaks is a paragraph boundary. It is marked
        // rather than kept: leaving a <br> there would show a blank line *and*
        // the paragraph's own bottom margin, which is the doubled gap.
        const SPLIT = '@@PAPEL_SPLIT@@';

        function reflow(container) {
            let node = container.firstChild;
            while (node) {
                const next = node.nextSibling;
                if (node.nodeType === 1 && node.nodeName === 'BR') {
                    let run = [node], probe = node.nextSibling;
                    while (probe && probe.nodeType === 1 && probe.nodeName === 'BR') {
                        run.push(probe);
                        probe = probe.nextSibling;
                    }
                    // One break is a wrapped line and rejoins the sentence;
                    // two or more start a new paragraph.
                    node.replaceWith(doc.createTextNode(run.length === 1 ? ' ' : SPLIT));
                    run.slice(1).forEach(function (b) { b.remove(); });
                    node = probe || next;
                    continue;
                }
                if (node.nodeType === 1) reflow(node);
                node = next;
            }
        }
        reflow(root);

        let out = root.innerHTML;

        // Real paragraphs, so the spacing between them comes from the
        // stylesheet alone and cannot double up.
        if (out.indexOf(SPLIT) !== -1) {
            const startsWithBlock = /^\s*<(p|div|ul|ol|blockquote|table)\b/i;
            out = out.split(SPLIT)
                .map(function (part) { return part.trim(); })
                .filter(function (part) { return part !== '' && part !== '<br>'; })
                .map(function (part) {
                    // A stashed table is block-level too; wrapping it in a <p>
                    // would be invalid markup the browser then tears apart.
                    if (startsWithBlock.test(part) || part.indexOf(TABLE_KEY) === 0) return part;
                    return '<p>' + part + '</p>';
                })
                .join('');
        }

        protect.forEach(function (tableHtml, i) {
            out = out.replace(TABLE_KEY + i + '@@', tableHtml);
        });
        // Runs of whitespace left by the stitching collapse to one space.
        return out.replace(/[ \t]{2,}/g, ' ');
    }

    /* Mirrors $safeImageSrc in rich_text_sanitize(). Only pictures this site
       stores are kept: a remote src would have every reader of the paper fetch
       it from somewhere else, and would break the day that host went away.
       Pictures pasted from a web page are re-hosted before this runs. */
    const IMG_SRC_RE = /(?:^|\/)uploads\/section_images\/\d+\/[a-f0-9]{32}\.(png|jpe?g|gif|webp)$/i;

    function safeImageSrc(src) {
        const v = String(src || '').trim();
        if (v === '') return null;
        if (/^[a-z][a-z0-9+.\-]*:/i.test(v) || v.indexOf('//') === 0) return null;
        if (v.indexOf('..') !== -1) return null;
        return IMG_SRC_RE.test(v) ? v : null;
    }

    // How many pictures the last sanitize had to turn away, so the student can
    // be told rather than left to notice a gap in what they pasted.
    let droppedImages = 0;

    /* Something that at least looks like a host: labels separated by dots and
       ending in a real suffix, optionally with a port and a path. */
    const HOST_RE = /^[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,}(?::\d{1,5})?(?:[\/?#]\S*)?$/i;
    const MAILTO_RE = /^mailto:[^\s@]+@[a-z0-9.\-]+\.[a-z]{2,}$/i;

    /* Mirrors $safeHref in rich_text_sanitize().

       This used to turn away only the schemes that can execute, which left
       every address without a scheme accepted as a relative one. That is how
       "asdasd" became a link: perfectly valid to a browser, and pointing at a
       page in this site that does not exist. An address now has to look like
       one.

       A bare host is allowed and given the scheme it was missing, because
       "pup.edu.ph" is what people type and refusing it would be pedantic. */
    function safeHref(href) {
        const raw = String(href || '').trim();
        if (raw === '') return null;
        // Browsers ignore whitespace and control characters when working out a
        // scheme, so "java\tscript:" has to be judged on what they would see.
        const probe = raw.replace(/[\s\x00-\x1f]/g, '');
        const scheme = /^([a-z][a-z0-9+.\-]*):/i.exec(probe);

        if (!scheme) return HOST_RE.test(probe) ? 'https://' + probe : null;

        const name = scheme[1].toLowerCase();
        if (name === 'mailto') return MAILTO_RE.test(probe) ? probe : null;
        if (name !== 'http' && name !== 'https') return null;
        return HOST_RE.test(probe.replace(/^https?:\/\//i, '')) ? probe : null;
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // For values going inside a quoted attribute, where a stray " would end it.
    function escAttr(s) {
        return esc(s).replace(/"/g, '&quot;');
    }

    /* A picture as markup rather than as a node.

       Everything that puts a picture into a box does it by handing this string
       to insertHtmlAtCaret, because that goes through execCommand and so lands
       on the browser's undo history along with the typing around it. */
    function imageMarkup(src, alt, width) {
        return '<img src="' + escAttr(src) + '" alt="' + escAttr(alt || '') + '"'
             + (width ? ' style="width:' + escAttr(width) + '"' : '') + '>';
    }

    /* Replaces one picture with another version of itself, as a single edit.

       Selecting the node first is what makes insertHTML replace it rather than
       insert beside it, and it is the whole reason resizing a picture can be
       undone: the browser sees one edit that swapped a selection for some
       markup, exactly as it sees a paste. */
    function replaceNode(ed, node, markup) {
        ed.surface.focus();
        const range = document.createRange();
        range.selectNode(node);
        const sel = window.getSelection();
        if (!sel) return false;
        sel.removeAllRanges();
        sel.addRange(range);
        if (markup === '') {
            try { return document.execCommand('delete', false, null); }
            catch (err) { node.remove(); return true; }
        }
        return insertHtmlAtCaret(ed, markup);
    }

    function sanitizeHtml(html) {
        // DOMParser builds an inert document: nothing in the pasted markup runs.
        const doc = new DOMParser().parseFromString('<div id="r">' + html + '</div>', 'text/html');
        const root = doc.getElementById('r');
        if (!root) return '';
        droppedImages = 0;

        (function walk(node) {
            let child = node.firstChild;
            while (child) {
                const next = child.nextSibling;

                if (child.nodeType === 3) { child = next; continue; }        // text
                if (child.nodeType !== 1) { node.removeChild(child); child = next; continue; }

                const tag = child.nodeName.toLowerCase();

                if (DROPPED.indexOf(tag) !== -1) {
                    node.removeChild(child);
                    child = next;
                    continue;
                }

                if (ALLOWED.indexOf(tag) === -1) {
                    // Unwrap, so the words inside a stray <font> are not lost.
                    const first = child.firstChild;
                    while (child.firstChild) node.insertBefore(child.firstChild, child);
                    node.removeChild(child);
                    child = first || next;
                    continue;
                }

                const isCell  = (tag === 'td' || tag === 'th');
                const isImage = (tag === 'img');
                /* A paragraph inside a cell is where the browser puts the
                   alignment when the cell holds one, so its text-align has to
                   survive too or centring a column silently comes undone at the
                   next save. Outside a cell it is still dropped: body text is
                   justified for every paper. */
                const inCellBlock = (tag === 'p' || tag === 'div')
                    && !!(child.closest && child.closest('td, th'));

                if (tag === 'a') {
                    /* The server keeps a link's destination; this did not, so a
                       page pasted with links arrived with every href stripped
                       and the server then unwrapped them all. */
                    const keptHref = safeHref(child.getAttribute('href'));
                    Array.prototype.slice.call(child.attributes).forEach(function (attr) {
                        child.removeAttribute(attr.name);
                    });
                    if (keptHref === null) {
                        // No usable destination: keep the words, drop the link.
                        const first = child.firstChild;
                        while (child.firstChild) node.insertBefore(child.firstChild, child);
                        node.removeChild(child);
                        child = first || next;
                        continue;
                    }
                    child.setAttribute('href', keptHref);
                    child.setAttribute('target', '_blank');
                    child.setAttribute('rel', 'noopener noreferrer nofollow');
                    walk(child);
                    child = next;
                    continue;
                }

                if (isImage) {
                    const keptSrc = safeImageSrc(child.getAttribute('src'));
                    if (keptSrc === null) {
                        // Void element: nothing inside it to preserve.
                        droppedImages++;
                        node.removeChild(child);
                        child = next;
                        continue;
                    }
                    const alt = (child.getAttribute('alt') || '').trim().slice(0, 300);
                    // How wide the student set it, if they set it at all.
                    const w = /(?:^|;)\s*width\s*:\s*([\d.]+)(%|px)\s*(?:;|$)/i
                                .exec(child.getAttribute('style') || '');
                    Array.prototype.slice.call(child.attributes).forEach(function (attr) {
                        child.removeAttribute(attr.name);
                    });
                    child.setAttribute('src', keptSrc);
                    child.setAttribute('alt', alt);
                    if (w && parseFloat(w[1]) > 0) {
                        child.setAttribute('style', 'width:' + parseFloat(w[1]) + w[2].toLowerCase());
                    }
                    child = next;
                    continue;
                }

                Array.prototype.slice.call(child.attributes).forEach(function (attr) {
                    const name = attr.name.toLowerCase();
                    if (isCell && (name === 'colspan' || name === 'rowspan')) {
                        const span = parseInt(attr.value, 10);
                        if (span > 1 && span <= 100) child.setAttribute(name, String(span));
                        else child.removeAttribute(name);
                        return;
                    }
                    /* Two declarations survive, and only where they can mean
                       something: how a *cell's* text is aligned, and how wide a
                       column is. Body text is justified for every paper, so
                       alignment on a paragraph is dropped along with fonts,
                       colours, Word's shading and url(). */
                    if (name === 'style' && (isCell || inCellBlock || tag === 'table')) {
                        const keep = [];
                        const align = (isCell || inCellBlock)
                            ? /text-align\s*:\s*(left|right|center|justify)\b/i.exec(attr.value)
                            : null;
                        if (align) keep.push('text-align:' + align[1].toLowerCase());
                        // auto and 0 only, which is all that centring a table
                        // needs and nothing that can carry a url() or a length
                        // large enough to push the page about.
                        if (tag === 'table') {
                            const m = /(?:^|;)\s*margin-(left|right)\s*:\s*(auto|0)\s*(?:;|$)/gi;
                            let hit;
                            while ((hit = m.exec(attr.value)) !== null) {
                                keep.push('margin-' + hit[1].toLowerCase() + ':' + hit[2].toLowerCase());
                            }
                        }
                        if (!inCellBlock) {
                            /* No \b after the unit: "%" is not a word character,
                               so a boundary after it never matches at the end of
                               the value and every percentage width was dropped. */
                            const width = /(?:^|;)\s*width\s*:\s*([\d.]+)(%|px)\s*(?:;|$)/i.exec(attr.value);
                            if (width && parseFloat(width[1]) > 0) {
                                keep.push('width:' + parseFloat(width[1]) + width[2].toLowerCase());
                            }
                        }
                        if (keep.length) child.setAttribute('style', keep.join(';'));
                        else child.removeAttribute('style');
                        return;
                    }
                    // Everything else goes, style attributes included.
                    child.removeAttribute(attr.name);
                });

                walk(child);
                child = next;
            }
        })(root);

        tidyTables(root);
        return root.innerHTML;
    }

    /* Pasted tables arrive padded.

       Word wraps every cell's text in its own <p>, Excel pads cells out with
       non-breaking spaces, a table copied from a PDF carries the line breaks of
       the page it was laid out on, and any of them may be sitting inside a
       one-cell table used purely as a frame. None of that is data, and all of it
       shows up as a lopsided table in the finished paper, so it is taken off
       here rather than left for the student to clean by hand. */
    function tidyTables(root) {
        // A table whose only content is another table is a frame, not data.
        // Unwrapped repeatedly, because Word nests them several deep.
        for (let pass = 0; pass < 4; pass++) {
            const frames = Array.prototype.filter.call(root.querySelectorAll('table'), function (t) {
                /* Only this table's own cells: querySelectorAll reaches through
                   the nested table as well, and counting those made every
                   wrapper look far too full to be a wrapper. */
                const own = Array.prototype.filter.call(t.querySelectorAll('th, td'), function (c) {
                    return c.closest('table') === t;
                });
                return own.length === 1 && own[0].querySelector('table');
            });
            if (!frames.length) break;
            frames.forEach(function (t) {
                const cell = t.querySelector('th, td');
                while (cell.firstChild) t.parentNode.insertBefore(cell.firstChild, t);
                t.remove();
            });
        }

        root.querySelectorAll('th, td').forEach(function (cell) {
            // A cell's own paragraphs are Word's doing, not the author's: they
            // add a blank line above and below every value in the column.
            cell.querySelectorAll('p, div').forEach(function (block) {
                // If that paragraph was the thing carrying the alignment, the
                // cell inherits it rather than losing it with the wrapper.
                const align = /text-align\s*:\s*(left|right|center|justify)\b/i
                                .exec(block.getAttribute('style') || '');
                if (align && !cell.style.textAlign) {
                    cell.style.textAlign = align[1].toLowerCase();
                }
                if (block.nextElementSibling || block.previousElementSibling) {
                    block.insertAdjacentHTML('afterend', '<br>');
                }
                while (block.firstChild) block.parentNode.insertBefore(block.firstChild, block);
                block.remove();
            });

            // Non-breaking spaces are how a spreadsheet pads a column to width.
            // They are spaces once the table has its own borders.
            const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, null);
            const texts = [];
            while (walker.nextNode()) texts.push(walker.currentNode);
            texts.forEach(function (t) {
                t.nodeValue = t.nodeValue.replace(/[\u00a0\s]+/g, ' ');
            });

            // A trailing <br> is the line break at the end of the cell's last
            // line, and an empty text node either side of it is nothing at all.
            while (cell.lastChild && (
                     (cell.lastChild.nodeType === 3 && cell.lastChild.nodeValue.trim() === '') ||
                     (cell.lastChild.nodeType === 1 && cell.lastChild.nodeName === 'BR'))) {
                cell.removeChild(cell.lastChild);
            }
            while (cell.firstChild && cell.firstChild.nodeType === 3
                   && cell.firstChild.nodeValue.trim() === '') {
                cell.removeChild(cell.firstChild);
            }

            /* The padding is trimmed off the first and last piece of *text* in
               the cell rather than its first and last child. Word puts the
               value inside a <span>, and trimming the cell's children then
               reached an element and did nothing at all. */
            const inner = [];
            const w2 = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, null);
            while (w2.nextNode()) inner.push(w2.currentNode);
            if (inner.length) {
                inner[0].nodeValue = inner[0].nodeValue.replace(/^ +/, '');
                inner[inner.length - 1].nodeValue = inner[inner.length - 1].nodeValue.replace(/ +$/, '');
            }
        });

        // A row where every cell is empty is the spacer row a layout table uses.
        root.querySelectorAll('tr').forEach(function (row) {
            const cells = row.querySelectorAll('th, td');
            if (!cells.length) { row.remove(); return; }
            const anything = Array.prototype.some.call(cells, function (c) {
                return c.textContent.trim() !== '' || c.querySelector('img');
            });
            if (!anything) row.remove();
        });

        // Whatever is left with no rows at all is not a table any more.
        root.querySelectorAll('table').forEach(function (t) {
            if (!t.querySelector('tr')) t.remove();
        });
    }

    /* A table that arrived as plain text.

       Excel, Sheets and Word all put tab-separated text on the clipboard
       alongside their HTML, and a table copied out of a PDF or a terminal has no
       HTML at all: its columns are held apart by runs of spaces. Both are real
       tables to the person pasting them, so both are rebuilt as one.

       Returns null for anything that does not look like a grid, which is how
       ordinary prose stays prose. */
    function textToTable(text) {
        const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n')
                        .filter(function (l) { return l.trim() !== ''; });
        if (lines.length < 2) return null;

        let rows = null;

        // Tabs are unambiguous: prose does not carry one on every line.
        if (lines.every(function (l) { return l.indexOf('\t') !== -1; })) {
            rows = lines.map(function (l) { return l.split('\t'); });
        } else {
            /* Columns held apart by padding. Far easier to mistake for prose, so
               this asks for more: the same number of columns on every line, and
               no "cell" long enough to be a sentence that merely happens to
               contain a double space. */
            const split = lines.map(function (l) { return l.trim().split(/\s{2,}/); });
            const width = split[0].length;
            const uniform = width >= 2 && split.every(function (r) { return r.length === width; });
            const short = split.every(function (r) {
                return r.every(function (c) { return c.length <= 120; });
            });
            if (uniform && short) rows = split;
        }
        if (!rows) return null;

        const cols = rows.reduce(function (m, r) { return Math.max(m, r.length); }, 0);
        if (cols < 2) return null;

        /* The first row is a header when it is complete and none of it is a
           number, which is what a row of column names looks like. */
        const first = rows[0];
        const isHeader = first.length === cols
            && first.every(function (c) { return c.trim() !== ''; })
            && !first.some(function (c) { return /^[-+]?[\d.,%]+$/.test(c.trim()); });

        const width = (100 / cols).toFixed(4);
        let out = '<table><tbody>';
        rows.forEach(function (r, i) {
            const tag = (isHeader && i === 0) ? 'th' : 'td';
            out += '<tr>';
            for (let c = 0; c < cols; c++) {
                // Width on the first row only: the rest inherit the column.
                const style = (i === 0) ? ' style="width:' + width + '%"' : '';
                out += '<' + tag + style + '>' + esc((r[c] || '').trim()) + '</' + tag + '>';
            }
            out += '</tr>';
        });
        return out + '</tbody></table>';
    }

    /* Web addresses typed or pasted as bare text become links.

       Only in the plain-text path: markup that arrived as HTML already carries
       whatever links it meant to have. */
    const URL_RE = /\b((?:https?:\/\/|www\.)[^\s<>()"']+[^\s<>()"'.,;:!?])/gi;

    function autoLink(escaped) {
        return escaped.replace(URL_RE, function (m) {
            const href = safeHref(m);
            if (!href) return m;
            return '<a href="' + href.replace(/"/g, '&quot;') + '" target="_blank"'
                 + ' rel="noopener noreferrer nofollow">' + m + '</a>';
        });
    }

    // Plain text from the extractor becomes real paragraphs, so the student can
    // format it straight away instead of one undifferentiated block.
    function textToHtml(text) {
        const blocks = String(text || '').replace(/\r\n?/g, '\n').split(/\n{2,}/);
        const html = blocks
            .map(b => b.trim())
            .filter(b => b !== '')
            // A single newline is a wrapped line from the source document, not a
            // deliberate break, so it is stitched back into the sentence. Only a
            // blank line starts a new paragraph. Keeping them as <br> would stop
            // the text justifying, because a line ending in a forced break is
            // always treated as a final line.
            .map(b => '<p>' + autoLink(esc(b).replace(/\s*\n\s*/g, ' ')) + '</p>')
            .join('');
        return html;
    }

    /* ----- Pictures -----

       A screenshot off the clipboard is 2 to 8 MB of PNG at the size of the
       screen it was taken on. It is redrawn through a canvas first, which costs
       nothing visible in a paper and saves both the upload and every reader of
       it afterwards. Smaller pictures are sent as they are: re-encoding a chart
       that is already the right size only makes it worse. */
    const IMAGE_MAX_EDGE    = 1600;              // px, longest side after redraw
    const IMAGE_REDRAW_OVER = 1.5 * 1024 * 1024; // redraw anything bigger, whatever its size
    const IMAGE_MAX_AT_ONCE = 8;

    function prepareImage(file) {
        return new Promise(function (resolve, reject) {
            const url = URL.createObjectURL(file);
            const probe = new Image();
            probe.onload = function () {
                const w = probe.naturalWidth, h = probe.naturalHeight;
                if (!w || !h) { URL.revokeObjectURL(url); reject(new Error('empty image')); return; }
                if (Math.max(w, h) <= IMAGE_MAX_EDGE && file.size <= IMAGE_REDRAW_OVER) {
                    URL.revokeObjectURL(url);
                    resolve(file);
                    return;
                }
                const scale  = IMAGE_MAX_EDGE / Math.max(w, h);
                const canvas = document.createElement('canvas');
                canvas.width  = Math.max(1, Math.round(w * Math.min(1, scale)));
                canvas.height = Math.max(1, Math.round(h * Math.min(1, scale)));
                const ctx = canvas.getContext('2d');
                /* A screenshot is text, and JPEG puts a halo round every letter
                   of it, so anything that came in as PNG or GIF leaves as PNG.
                   A photograph has no such problem and compresses far better.
                   JPEG cannot hold transparency either, hence the white ground:
                   without it the transparent parts come out black. */
                const keepPng = /png|gif/i.test(file.type || '');
                if (!keepPng) {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                }
                ctx.drawImage(probe, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                canvas.toBlob(function (blob) { resolve(blob || file); },
                              keepPng ? 'image/png' : 'image/jpeg', 0.85);
            };
            probe.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('unreadable image'));
            };
            probe.src = url;
        });
    }

    function uploadImage(blob) {
        const fd = new FormData();
        const type = (blob.type || 'image/png').toLowerCase();
        const ext  = type.indexOf('png')  !== -1 ? 'png'
                   : type.indexOf('gif')  !== -1 ? 'gif'
                   : type.indexOf('webp') !== -1 ? 'webp' : 'jpg';
        fd.append('action', 'upload_section_image');
        // The server names the stored file itself; this only has to be present.
        fd.append('image', blob, 'picture.' + ext);
        const token = document.querySelector('input[name="_token"]');
        if (token) fd.append(token.name, token.value);

        return fetch('student_upload_ai.php', { method: 'POST', body: fd })
            .then(function (r) {
                return r.json().catch(function () {
                    return { success: false, message: 'The server did not answer properly.' };
                });
            })
            .then(function (j) {
                if (!j || !j.success || !j.url) {
                    throw new Error((j && j.message) || 'That picture could not be saved.');
                }
                return j;
            });
    }

    /* Uploads first, inserts second.

       The obvious alternative is to show the picture straight away and swap the
       address in when the upload lands. It was not worth it: an autosave firing
       in that window would write a blob: address into the draft, which is a
       reference to memory in one tab of one browser and means nothing anywhere
       else. Waiting keeps everything that reaches the draft real. */
    function insertImages(ed, files) {
        let list = Array.prototype.filter.call(files, function (f) {
            return f && /^image\//i.test(f.type || '');
        });
        if (!list.length) return false;
        if (list.length > IMAGE_MAX_AT_ONCE) {
            list = list.slice(0, IMAGE_MAX_AT_ONCE);
            window.papelAlert('Only the first ' + IMAGE_MAX_AT_ONCE + ' pictures were added. Paste the rest separately.');
        }

        /* Where the picture goes is decided now. The caret moves the moment
           anything else takes focus, and the upload takes long enough for that
           to happen. */
        const sel = window.getSelection();
        let range = null;
        if (sel && sel.rangeCount) {
            const r = sel.getRangeAt(0);
            if (ed.surface.contains(r.commonAncestorContainer)) range = r.cloneRange();
        }
        if (!range) {
            range = document.createRange();
            range.selectNodeContents(ed.surface);
            range.collapse(false);
        }

        ed.root.classList.add('is-uploading');

        let chain = Promise.resolve();
        list.forEach(function (file) {
            chain = chain.then(function () {
                return prepareImage(file).then(uploadImage).then(function (res) {
                    /* Put the caret back where the paste happened, then insert
                       as markup. Going through insertHtmlAtCaret rather than
                       range.insertNode is what puts the picture on the undo
                       history: Ctrl+Z takes it out again, Ctrl+Y brings it
                       back, and both sit in order with the surrounding typing. */
                    ed.surface.focus();
                    const sel = window.getSelection();
                    if (sel) {
                        range.collapse(false);
                        sel.removeAllRanges();
                        sel.addRange(range);
                    }
                    insertHtmlAtCaret(ed, imageMarkup(res.url, ''));

                    /* Carry the caret forward, so pasting several pictures at
                       once puts them in the order they were given rather than
                       stacking them all at the first one's position. */
                    const now = window.getSelection();
                    if (now && now.rangeCount
                        && ed.surface.contains(now.getRangeAt(0).commonAncestorContainer)) {
                        range = now.getRangeAt(0).cloneRange();
                    }
                });
            });
        });

        chain.catch(function (err) {
            window.papelAlert(String((err && err.message) || 'That picture could not be added.'),
                              { tone: 'error' });
        }).then(function () {
            ed.root.classList.remove('is-uploading');
            ed.surface.focus();
            const s2 = window.getSelection();
            if (s2) { s2.removeAllRanges(); s2.addRange(range); }
            refresh(ed);
        });
        return true;
    }

    /* Puts already-sanitised markup in at the caret.

       execCommand keeps the browser's own undo stack, which is why it is tried
       first, but it is deprecated and it answers false rather than throwing when
       it will not act, so the result has to be checked. Whatever it declines is
       inserted by hand. The markup is parsed in a detached document and the
       nodes imported, so nothing in it can run on the way in. */
    function insertHtmlAtCaret(ed, html) {
        try { if (document.execCommand('insertHTML', false, html)) return true; }
        catch (err) { /* fall through to the manual path */ }

        /* Below this line the edit is made directly and the browser's undo
           history knows nothing about it, so Ctrl+Z will not take it back. That
           is the price of not losing the paste altogether, and it is only paid
           when execCommand has already refused to act. */

        const sel = window.getSelection();
        let range = null;
        if (sel && sel.rangeCount) {
            const r = sel.getRangeAt(0);
            if (ed.surface.contains(r.commonAncestorContainer)) range = r;
        }
        if (!range) {
            range = document.createRange();
            range.selectNodeContents(ed.surface);
            range.collapse(false);
        }
        range.deleteContents();

        const parsed = new DOMParser().parseFromString('<div id="i">' + html + '</div>', 'text/html');
        const src = parsed.getElementById('i');
        if (!src) return false;
        const frag = document.createDocumentFragment();
        // importNode copies rather than moves, so the source node has to be
        // taken out by hand or firstChild never changes and this never ends.
        while (src.firstChild) {
            const node = src.firstChild;
            src.removeChild(node);
            frag.appendChild(document.importNode(node, true));
        }
        const last = frag.lastChild;
        if (!last) return false;

        range.insertNode(frag);
        range.setStartAfter(last);
        range.collapse(true);
        if (sel) { sel.removeAllRanges(); sel.addRange(range); }
        return true;
    }

    /* Links the current selection.

       execCommand('createLink') has the same weakness as insertHTML: it answers
       false rather than throwing when it will not act, and a paste that was
       supposed to link the selected words left them unlinked with nothing to
       show for it. Anything it declines is wrapped by hand. */
    function linkSelection(ed, href) {
        let made = null;
        try {
            if (document.execCommand('createLink', false, href)) made = linkAtCaret(ed.surface);
        } catch (err) { /* fall through to the manual path */ }

        if (!made) {
            const sel = window.getSelection();
            if (!sel || !sel.rangeCount) return null;
            const range = sel.getRangeAt(0);
            if (range.collapsed || !ed.surface.contains(range.commonAncestorContainer)) return null;
            const a = document.createElement('a');
            a.setAttribute('href', href);
            try {
                // Fails when the selection starts inside one element and ends
                // inside another, which is when the contents have to be moved.
                range.surroundContents(a);
            } catch (err) {
                a.appendChild(range.extractContents());
                range.insertNode(a);
            }
            made = a;
        }

        if (made) {
            made.setAttribute('href', href);
            // Without noopener the page opened gets a handle back to this one.
            made.setAttribute('target', '_blank');
            made.setAttribute('rel', 'noopener noreferrer nofollow');
        }
        return made;
    }

    // One hidden input serves every editor: only one file dialog can be open.
    let imagePicker = null;

    function pickImage(ed) {
        if (!imagePicker) {
            imagePicker = document.createElement('input');
            imagePicker.type = 'file';
            imagePicker.accept = 'image/png,image/jpeg,image/gif,image/webp';
            imagePicker.multiple = true;
            imagePicker.hidden = true;
            document.body.appendChild(imagePicker);
        }
        imagePicker.value = '';                       // so the same file re-fires
        imagePicker.onchange = function () {
            if (imagePicker.files && imagePicker.files.length) insertImages(ed, imagePicker.files);
        };
        imagePicker.click();
    }

    // Pictures pasted from a web page point back at that site, so the allowlist
    // turns them away. Saying so once is better than a silent gap in the paste.
    function noteDroppedImages(count) {
        if (count > 0) {
            window.papelAlert(
                count === 1
                    ? 'One picture in what you pasted came from another website, so it was left out. Copy the picture on its own and paste it again, or use Insert picture.'
                    : count + ' pictures in what you pasted came from other websites, so they were left out. Copy each one on its own and paste it again, or use Insert picture.'
            );
        }
    }

    function plainText(ed) {
        return (ed.surface.innerText || ed.surface.textContent || '').replace(/ /g, ' ').trim();
    }

    function isBlank(ed) {
        return plainText(ed) === '' && !ed.surface.querySelector('img, table');
    }

    function refresh(ed) {
        const blank = isBlank(ed);
        ed.surface.classList.toggle('is-empty', blank);
        const words = blank ? 0 : plainText(ed).split(/\s+/).filter(Boolean).length;
        ed.count.textContent = words === 1 ? '1 word' : words.toLocaleString() + ' words';
        ed.input.value = blank ? '' : ed.surface.innerHTML;
        if (!blank) ed.root.classList.remove('is-invalid');
    }

    /* Every one of this editor's buttons, wherever it currently sits.

       Whatever no longer fits on the bar is moved into the overflow menu, and
       that menu is attached to <body> rather than to the editor, so a search
       rooted at the editor quietly misses it: a button that had moved kept
       whatever dimmed or pressed state it had at the time and never changed
       again. */
    function toolButtons(ed) {
        const list = Array.prototype.slice.call(ed.root.querySelectorAll('.doc-tool'));
        if (ed.overflow && ed.overflow.menu) {
            Array.prototype.push.apply(list,
                Array.prototype.slice.call(ed.overflow.menu.querySelectorAll('.doc-tool')));
        }
        return list;
    }

    function refreshToolbar(ed) {
        toolButtons(ed).forEach(function (btn) {
            const cmd = btn.getAttribute('data-cmd');
            if (STATE_CMDS.indexOf(cmd) === -1) return;
            let on = false;
            try { on = document.queryCommandState(cmd); } catch (err) { on = false; }
            btn.classList.toggle('active', on);
        });
    }

    /* ---------------- Popovers, colours, links, tables ---------------- */

    const GRID_ROWS = 6, GRID_COLS = 8;

    /* ----- Toolbar overflow -----
       The header is a single row at every width. Whatever no longer fits moves
       into a ⋮ menu, and comes back as soon as there is room again, so docking
       a panel or expanding the box never leaves a wrapped, ragged toolbar. */
    function setupOverflow(ed) {
        const head = ed.root.querySelector('.doc-editor-head');
        const toolbar = ed.root.querySelector('.doc-toolbar');
        if (!head || !toolbar) return;

        const wrap = document.createElement('span');
        wrap.className = 'doc-pop-wrap doc-more-wrap';
        wrap.setAttribute('data-pop', 'more');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'doc-tool';
        btn.setAttribute('data-role', 'more');
        btn.title = 'More tools';
        btn.setAttribute('aria-label', 'More tools');
        btn.setAttribute('aria-haspopup', 'true');
        btn.innerHTML = '<span class="material-symbols-outlined mi-18">more_vert</span>';
        wrap.appendChild(btn);
        toolbar.appendChild(wrap);

        const menu = makePopover(function (m) { m.classList.add('doc-more-menu'); });
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
            const wasOpen = menu.classList.contains('open');
            closePopovers();
            if (wasOpen) return;
            placeUnder(menu, btn);
            btn.classList.add('active');
        });

        ed.overflow = { head: head, toolbar: toolbar, wrap: wrap, menu: menu };
        reflowToolbar(ed);

        // Re-measure whenever the header's width changes for any reason —
        // a panel docking, the window resizing, the box being expanded.
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(function () { reflowToolbar(ed); }).observe(head);
        } else {
            window.addEventListener('resize', function () { reflowToolbar(ed); });
        }
    }

    function reflowToolbar(ed) {
        const o = ed.overflow;
        if (!o) return;

        // Start from everything on the bar, then take items off the end until
        // the row fits. Measuring after each move is what keeps it exact.
        while (o.menu.firstChild) o.toolbar.insertBefore(o.menu.firstChild, o.wrap);
        o.wrap.classList.remove('is-needed');

        if (o.head.scrollWidth <= o.head.clientWidth + 1) return;

        o.wrap.classList.add('is-needed');
        let guard = 60;
        while (o.head.scrollWidth > o.head.clientWidth + 1 && guard-- > 0) {
            const items = Array.prototype.slice.call(o.toolbar.children)
                .filter(function (c) { return c !== o.wrap; });
            if (!items.length) break;
            // Prepending keeps the menu in the bar's original order.
            o.menu.insertBefore(items[items.length - 1], o.menu.firstChild);
        }

        // A separator left stranded at either end of the bar looks like a slip.
        const left = Array.prototype.slice.call(o.toolbar.children)
            .filter(function (c) { return c !== o.wrap; });
        if (left.length && left[left.length - 1].classList.contains('doc-tool-sep')) {
            o.menu.insertBefore(left[left.length - 1], o.menu.firstChild);
        }
        if (!o.menu.children.length) o.wrap.classList.remove('is-needed');
    }

    /* ----- Section tabs, shown along the bottom while expanded -----
       Expanding a section hides the other four, so this is the way back to
       them without collapsing first. Built fresh on every expand, which keeps
       the "still empty" dots current without anything having to watch them. */
    function sectionTitle(ed) {
        const el = ed.root.querySelector('.doc-editor-title');
        if (!el) return ed.key;
        // The label carries a required asterisk that has no place on a tab.
        return el.textContent.replace('*', '').trim();
    }

    function buildTabs(ed) {
        const bar = document.createElement('div');
        bar.className = 'doc-tabs';
        bar.setAttribute('role', 'tablist');
        bar.setAttribute('aria-label', 'Paper sections');

        document.querySelectorAll('.doc-editor').forEach(function (root) {
            const other = editors[root.getAttribute('data-section')];
            if (!other) return;

            const isCurrent = other.key === ed.key;
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'doc-tab' + (isCurrent ? ' is-active' : '');
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
            tab.dataset.target = other.key;
            tab.appendChild(document.createTextNode(sectionTitle(other)));

            if (plainText(other) === '') {
                const dot = document.createElement('span');
                dot.className = 'doc-tab-dot';
                dot.title = 'Nothing written here yet';
                tab.appendChild(dot);
            }
            bar.appendChild(tab);
        });

        // Keep the caret where it is when a tab is pressed.
        bar.addEventListener('mousedown', function (e) { e.preventDefault(); });
        bar.addEventListener('click', function (e) {
            const tab = e.target.closest('.doc-tab');
            if (!tab) return;
            const next = editors[tab.dataset.target];
            if (next && next !== ed) toggleExpand(next);
        });

        ed.root.appendChild(bar);
        const active = bar.querySelector('.doc-tab.is-active');
        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    /* ----- Expanding a section ----- */
    function collapseExpanded() {
        document.querySelectorAll('.doc-editor.is-expanded').forEach(function (root) {
            root.classList.remove('is-expanded');
            const tabs = root.querySelector('.doc-tabs');
            if (tabs) tabs.remove();
            const spacer = root.previousElementSibling;
            if (spacer && spacer.classList.contains('doc-editor-spacer')) spacer.remove();
            const btn = root.querySelector('.doc-tool[data-role="expand"]');
            if (btn) {
                btn.setAttribute('aria-pressed', 'false');
                btn.title = 'Expand this section (Esc to exit)';
                btn.querySelector('.material-symbols-outlined').textContent = 'open_in_full';
            }
        });
        document.body.classList.remove('doc-expanded');
        document.documentElement.classList.remove('doc-expanded');
    }

    function toggleExpand(ed) {
        const wasExpanded = ed.root.classList.contains('is-expanded');
        collapseExpanded();
        if (wasExpanded) return;

        // A spacer keeps the page the same height while the box is lifted out
        // of the flow, so nothing shifts underneath it.
        const spacer = document.createElement('div');
        spacer.className = 'doc-editor-spacer';
        spacer.style.height = ed.root.getBoundingClientRect().height + 'px';
        ed.root.parentNode.insertBefore(spacer, ed.root);

        ed.root.classList.add('is-expanded');
        document.body.classList.add('doc-expanded');
        // The accessibility widget lives on <html>, outside <body>.
        document.documentElement.classList.add('doc-expanded');
        buildTabs(ed);       // the way across to the other four sections
        reflowToolbar(ed);   // far more room now — bring the tools back out

        const btn = ed.root.querySelector('.doc-tool[data-role="expand"]');
        if (btn) {
            btn.setAttribute('aria-pressed', 'true');
            btn.title = 'Return to the form (Esc)';
            btn.querySelector('.material-symbols-outlined').textContent = 'close_fullscreen';
        }
        ed.surface.focus();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('doc-expanded')) {
            collapseExpanded();
        }
    });

    function closePopovers() {
        document.querySelectorAll('.doc-pop.open').forEach(function (m) { m.classList.remove('open'); });
        document.querySelectorAll('.doc-pop-wrap .doc-tool.active').forEach(function (b) {
            b.classList.remove('active');
        });
    }

    // Popovers live on <body>, not inside the toolbar. The editor clips its own
    // overflow to keep its rounded corners, which would cut a nested popover in
    // half; a fixed-position layer escapes that and is positioned on open.
    // Focus is never taken, so the caret selection stays alive for the command.
    function makePopover(build) {
        const menu = document.createElement('div');
        menu.className = 'doc-pop';
        menu.addEventListener('mousedown', function (e) { e.preventDefault(); });
        build(menu);
        document.body.appendChild(menu);
        return menu;
    }

    // Anchors a fixed-position layer under a button, pulled back inside the
    // viewport when the button sits near an edge.
    function placeUnder(menu, button) {
        menu.style.visibility = 'hidden';
        menu.classList.add('open');
        const r = button.getBoundingClientRect();
        const w = menu.offsetWidth, h = menu.offsetHeight;
        let left = r.left + r.width / 2 - w / 2;
        let top  = r.bottom + 6;
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
        // No room below: flip above the button rather than run off-screen.
        if (top + h > window.innerHeight - 8) {
            top = Math.max(8, r.top - h - 6);
        }
        menu.style.left = left + 'px';
        menu.style.top  = top + 'px';
        menu.style.visibility = '';
    }


    function cellAtCaret(surface) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return null;
        let n = sel.getRangeAt(0).startContainer;
        if (n.nodeType === 3) n = n.parentNode;
        const cell = n.closest ? n.closest('td, th') : null;
        // Only cells belonging to *this* editor may be operated on.
        return (cell && surface.contains(cell)) ? cell : null;
    }

    function buildTable(rows, cols) {
        let html = '<table><thead><tr>';
        for (let c = 0; c < cols; c++) html += '<th><br></th>';
        html += '</tr></thead><tbody>';
        for (let r = 0; r < rows - 1; r++) {
            html += '<tr>';
            for (let c = 0; c < cols; c++) html += '<td><br></td>';
            html += '</tr>';
        }
        html += '</tbody></table><p><br></p>';
        return html;
    }

    // Row and column edits go through the DOM rather than execCommand, which
    // has no table operations at all.
    function tableAction(ed, action) {
        const cell = cellAtCaret(ed.surface);
        if (!cell) return false;
        const row   = cell.parentNode;
        const table = cell.closest('table');
        const index = Array.prototype.indexOf.call(row.cells, cell);
        if (!table) return false;

        const eachRow = function (fn) {
            Array.prototype.slice.call(table.rows).forEach(fn);
        };

        switch (action) {
            case 'row-above':
            case 'row-below': {
                const fresh = row.cloneNode(true);
                Array.prototype.slice.call(fresh.cells).forEach(function (c) {
                    // A cloned header row would repeat the headings; body cells only.
                    if (c.tagName === 'TH') {
                        const td = document.createElement('td');
                        td.innerHTML = '<br>';
                        c.parentNode.replaceChild(td, c);
                    } else {
                        c.innerHTML = '<br>';
                    }
                });
                row.parentNode.insertBefore(fresh, action === 'row-above' ? row : row.nextSibling);
                break;
            }
            case 'col-left':
            case 'col-right': {
                const at = action === 'col-left' ? index : index + 1;
                eachRow(function (r) {
                    const isHead = r.cells[0] && r.cells[0].tagName === 'TH';
                    const c = document.createElement(isHead ? 'th' : 'td');
                    c.innerHTML = '<br>';
                    r.insertBefore(c, r.cells[at] || null);
                });
                break;
            }
            case 'row-delete':
                if (table.rows.length <= 1) { table.remove(); break; }
                row.remove();
                break;
            case 'col-delete':
                if (row.cells.length <= 1) { table.remove(); break; }
                eachRow(function (r) { if (r.cells[index]) r.deleteCell(index); });
                break;
            case 'table-delete':
                table.remove();
                break;
            default:
                return false;
        }
        refresh(ed);
        return true;
    }

    /* ----- First-line indent -----
       Tab used to run execCommand('indent'), which wraps the block in a
       <blockquote>. That shifted the whole paragraph, added the blockquote's
       vertical margins, and picked up its colour — three surprises for what
       should just be a tab. Two em spaces give a real first-line indent
       instead: ordinary characters that survive the sanitiser untouched and
       leave the rest of the paragraph where it is. */
    const INDENT_CHAR = '\u2003';   // em space (U+2003)
    const INDENT = INDENT_CHAR + INDENT_CHAR;

    function listItemAtCaret(surface) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return null;
        let n = sel.getRangeAt(0).startContainer;
        if (n.nodeType === 3) n = n.parentNode;
        const li = n.closest ? n.closest('li') : null;
        return (li && surface.contains(li)) ? li : null;
    }

    // Shift+Tab walks back over the em spaces the indent inserted.
    function removeIndent() {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!range.collapsed) return;

        const node = range.startContainer;
        if (node.nodeType !== 3) return;

        let offset = range.startOffset, removed = 0;
        while (offset > 0 && removed < INDENT.length && node.data.charAt(offset - 1) === INDENT_CHAR) {
            offset--;
            removed++;
        }
        if (!removed) return;

        node.deleteData(offset, removed);
        range.setStart(node, offset);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
    }

    /* ----- Enter helpers -----
       "Did the last Enter leave an empty line?" is answered by looking at what
       sits immediately before the caret: a <br> with nothing but whitespace
       after it means the previous keypress was the line break, so this one
       should promote it to a paragraph. */
    function breakBeforeCaret(surface) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0 || !sel.isCollapsed) return null;

        const range = sel.getRangeAt(0);
        let node = range.startContainer;
        let offset = range.startOffset;

        // Step back over empty text, then look for a <br>.
        if (node.nodeType === 3) {
            if (node.data.slice(0, offset).trim() !== '') return null;
        } else if (offset > 0) {
            node = node.childNodes[offset - 1];
            if (node && node.nodeName === 'BR') return surface.contains(node) ? node : null;
            return null;
        }

        let probe = node.previousSibling;
        while (probe && probe.nodeType === 3 && probe.data.trim() === '') probe = probe.previousSibling;
        if (probe && probe.nodeName === 'BR' && surface.contains(probe)) return probe;
        return null;
    }

    function caretFollowsLineBreak(surface) {
        return breakBeforeCaret(surface) !== null;
    }

    function removeBreakBeforeCaret(surface) {
        const br = breakBeforeCaret(surface);
        if (br) br.remove();
    }

    // Tab and Shift+Tab, wherever the caret happens to be.
    function indentAtCaret(ed, back) {
        const cell = cellAtCaret(ed.surface);
        if (cell) { moveToCell(ed, cell, back ? -1 : 1); return; }

        // Inside a list, indenting means nesting the item — that is what
        // execCommand does well, and no blockquote is involved.
        if (listItemAtCaret(ed.surface)) {
            try { document.execCommand(back ? 'outdent' : 'indent', false, null); } catch (err) {}
        } else if (back) {
            removeIndent();
        } else {
            try { document.execCommand('insertText', false, INDENT); } catch (err) {}
        }
        refresh(ed);
        refreshToolbar(ed);
    }

    // Steps the caret one cell forward or back, in reading order. Tabbing past
    // the last cell appends a row, so a table can be filled without reaching
    // for the mouse.
    function moveToCell(ed, cell, direction) {
        const table = cell.closest('table');
        if (!table) return;
        const cells = Array.prototype.slice.call(table.querySelectorAll('th, td'));
        const at = cells.indexOf(cell);
        let next = cells[at + direction];

        if (!next) {
            if (direction < 0) return;                   // already at the first cell
            tableAction(ed, 'row-below');
            const grown = Array.prototype.slice.call(table.querySelectorAll('th, td'));
            next = grown[at + 1];
            if (!next) return;
        }

        const range = document.createRange();
        range.selectNodeContents(next);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        next.scrollIntoView({ block: 'nearest' });
    }

    function buildTableBody(ed, menu) {
        const grid = document.createElement('div');
        grid.className = 'doc-grid-pick';
        const caption = document.createElement('div');
        caption.className = 'doc-grid-caption';
        caption.textContent = 'Insert table';

        for (let r = 1; r <= GRID_ROWS; r++) {
            for (let c = 1; c <= GRID_COLS; c++) {
                const cellBtn = document.createElement('span');
                cellBtn.className = 'doc-grid-cell';
                cellBtn.dataset.r = r;
                cellBtn.dataset.c = c;
                cellBtn.addEventListener('mouseenter', function () {
                    caption.textContent = r + ' × ' + c;
                    grid.querySelectorAll('.doc-grid-cell').forEach(function (g) {
                        g.classList.toggle('on', +g.dataset.r <= r && +g.dataset.c <= c);
                    });
                });
                cellBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
                cellBtn.addEventListener('click', function () {
                    ed.surface.focus();
                    insertHtmlAtCaret(ed, buildTable(r, c));
                    refresh(ed);
                    closePopovers();
                });
                grid.appendChild(cellBtn);
            }
        }
        grid.addEventListener('mouseleave', function () {
            caption.textContent = 'Insert table';
            grid.querySelectorAll('.doc-grid-cell').forEach(function (g) { g.classList.remove('on'); });
        });

        menu.appendChild(caption);
        menu.appendChild(grid);

        const actions = [
            ['row-above',    'Insert row above'],
            ['row-below',    'Insert row below'],
            ['col-left',     'Insert column left'],
            ['col-right',    'Insert column right'],
            ['row-delete',   'Delete row'],
            ['col-delete',   'Delete column'],
            ['table-delete', 'Delete table'],
        ];
        const list = document.createElement('div');
        list.className = 'doc-table-actions';
        actions.forEach(function (pair) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'doc-table-action';
            b.dataset.action = pair[0];
            b.textContent = pair[1];
            b.addEventListener('mousedown', function (e) { e.preventDefault(); });
            b.addEventListener('click', function () {
                if (tableAction(ed, pair[0])) closePopovers();
            });
            list.appendChild(b);
        });
        menu.appendChild(list);

        // Alignment applies to the cell under the caret, and nowhere else —
        // body text is justified for every paper and is not adjustable.
        const alignHead = document.createElement('div');
        alignHead.className = 'doc-pop-label';
        alignHead.textContent = 'Cell alignment';
        menu.appendChild(alignHead);

        const alignRow = document.createElement('div');
        alignRow.className = 'doc-align-row';
        [
            ['left',    'format_align_left',    'Align left'],
            ['center',  'format_align_center',  'Centre'],
            ['right',   'format_align_right',   'Align right'],
            ['justify', 'format_align_justify',  'Justify'],
        ].forEach(function (item) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'doc-tool doc-align-btn';
            b.dataset.align = item[0];
            b.title = item[2];
            b.setAttribute('aria-label', item[2]);
            b.innerHTML = '<span class="material-symbols-outlined mi-18">' + item[1] + '</span>';
            b.addEventListener('mousedown', function (e) { e.preventDefault(); });
            b.addEventListener('click', function () { alignCell(ed, item[0]); });
            alignRow.appendChild(b);
        });
        menu.appendChild(alignRow);
    }

    // Sets the alignment of the cell the caret is in. Whole-column or
    // whole-table selections apply to every cell the selection touches.
    function alignCell(ed, how) {
        const cell = cellAtCaret(ed.surface);
        if (!cell) return;
        const table = cell.closest('table');

        const sel = window.getSelection();
        let targets = [cell];
        if (sel && !sel.isCollapsed && table) {
            targets = Array.prototype.slice.call(table.querySelectorAll('th, td'))
                .filter(function (c) { return sel.containsNode(c, true); });
            if (!targets.length) targets = [cell];
        }

        if (targets.length === 1) {
            /* One cell is exactly what execCommand is for, and going through it
               keeps the change on the browser's undo history alongside the
               typing, and leaves the caret where it was. */
            const cmd = { left: 'justifyLeft', center: 'justifyCenter',
                          right: 'justifyRight', justify: 'justifyFull' }[how];
            ed.surface.focus();
            if (cmd) { try { document.execCommand(cmd, false, null); } catch (err) {} }
            refresh(ed);
            return;
        }

        /* A column or a whole table at once. execCommand only ever touches the
           cell the caret is in, so the table is rebuilt with all of them
           changed and swapped in as one edit, which is what keeps Ctrl+Z able
           to take the whole thing back. Setting each cell's style directly
           would be invisible to the undo history. */
        if (!table) return;
        const clone = table.cloneNode(true);
        const all = Array.prototype.slice.call(table.querySelectorAll('th, td'));
        const cloned = clone.querySelectorAll('th, td');
        targets.forEach(function (c) {
            const i = all.indexOf(c);
            if (i >= 0 && cloned[i]) cloned[i].style.textAlign = how;
        });
        replaceNode(ed, table, clone.outerHTML);
        refresh(ed);
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.doc-pop-wrap') && !e.target.closest('.doc-pop')) closePopovers();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePopovers();
    });
    // A fixed layer does not travel with the page, so it is dismissed instead.
    document.addEventListener('scroll', closePopovers, true);
    window.addEventListener('resize', closePopovers);

    /* ----- Right-click menu, shown only over a table inside an editor ----- */

    const TABLE_MENU_ITEMS = [
        ['row-above',    'Insert row above',    'add'],
        ['row-below',    'Insert row below',    'add'],
        ['col-left',     'Insert column left',  'add'],
        ['col-right',    'Insert column right', 'add'],
        ['row-delete',   'Delete row',          'delete'],
        ['col-delete',   'Delete column',       'delete'],
        ['table-delete', 'Delete table',        'delete'],
    ];

    // Applied as a style rather than an attribute so it survives the sanitiser,
    // which keeps a width declaration on a picture and nothing else.
    const IMAGE_SIZES = [['Half width', '50%'], ['Three quarters', '75%'], ['Full width', '100%']];

    /* Moving a table means giving it a width first: it fills the box by
       default, and something already the full width cannot be anywhere but in
       the middle. The two menus therefore go together. */
    const TABLE_WIDTHS = [['Full width', ''], ['Three quarters', '75%'], ['Half width', '50%']];
    const TABLE_SIDES  = [['Table left', 'left'], ['Table centred', 'center'], ['Table right', 'right']];

    function tableStyle(table) {
        const style = table.getAttribute('style') || '';
        const w = /(?:^|;)\s*width\s*:\s*([\d.]+%)/i.exec(style);
        const ml = /(?:^|;)\s*margin-left\s*:\s*(auto|0)/i.exec(style);
        const mr = /(?:^|;)\s*margin-right\s*:\s*(auto|0)/i.exec(style);
        /* Absent means whatever the stylesheet gives, and that is 0 on both
           sides. Defaulting the right margin to auto instead made a table
           pushed right ("margin-left:auto" alone, since the browser drops the
           redundant "margin-right:0") read back as centred. */
        const left  = ml ? ml[1].toLowerCase() : '0';
        const right = mr ? mr[1].toLowerCase() : '0';
        return {
            width: w ? w[1] : '',
            side: (left === 'auto' && right === 'auto') ? 'center'
                : (left === 'auto') ? 'right' : 'left'
        };
    }

    /* Rebuilt as one edit through insertHTML rather than restyled in place, so
       moving a table can be undone like anything else. */
    function setTableStyle(ed, table, width, side) {
        const parts = [];
        if (width) parts.push('width:' + width);
        // A full-width table needs no margins at all; anything narrower is
        // pushed about with auto, which is the only value the sanitiser keeps.
        if (width) {
            // Only the side that has to give way is written. The browser drops
            // a margin of 0 when it re-serialises anyway, so writing one only
            // made the stored style disagree with what came back.
            if (side !== 'left')  parts.push('margin-left:auto');
            if (side !== 'right') parts.push('margin-right:auto');
        }
        const clone = table.cloneNode(true);
        if (parts.length) clone.setAttribute('style', parts.join(';'));
        else clone.removeAttribute('style');
        replaceNode(ed, table, clone.outerHTML);
        refresh(ed);
    }

    let ctxMenu = null;

    function hideContextMenu() {
        if (ctxMenu) ctxMenu.classList.remove('open');
    }

    function showContextMenu(ed, x, y, ctx) {
        ctx = ctx || {};
        if (!ctxMenu) {
            ctxMenu = document.createElement('div');
            ctxMenu.className = 'doc-context-menu';
            ctxMenu.addEventListener('mousedown', function (e) { e.preventDefault(); });
            document.body.appendChild(ctxMenu);
        }
        ctxMenu.innerHTML = '';

        function item(label, run, danger) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'doc-context-item' + (danger ? ' danger' : '');
            b.textContent = label;
            b.addEventListener('click', function () { run(); hideContextMenu(); });
            ctxMenu.appendChild(b);
        }
        function separator() {
            const hr = document.createElement('div');
            hr.className = 'doc-context-sep';
            ctxMenu.appendChild(hr);
        }

        if (ctx.image) {
            IMAGE_SIZES.forEach(function (size) {
                item(size[0], function () {
                    // Swapped rather than restyled, so the change is one edit
                    // the browser can undo like any other.
                    replaceNode(ed, ctx.image,
                                imageMarkup(ctx.image.getAttribute('src'),
                                            ctx.image.getAttribute('alt'), size[1]));
                    refresh(ed);
                });
            });
            separator();
            item('Remove picture', function () {
                replaceNode(ed, ctx.image, '');
                refresh(ed);
            }, true);
            separator();
        }

        item('Insert picture…', function () { pickImage(ed); });

        if (ctx.cell) {
            const table = ctx.cell.closest('table');
            if (table) {
                const now = tableStyle(table);
                separator();
                TABLE_WIDTHS.forEach(function (w) {
                    item(w[0] + (now.width === w[1] ? '  ✓' : ''), function () {
                        setTableStyle(ed, table, w[1], now.side);
                    });
                });
                // Nothing to move while the table already fills the box.
                if (now.width) {
                    separator();
                    TABLE_SIDES.forEach(function (sd) {
                        item(sd[0] + (now.side === sd[1] ? '  ✓' : ''), function () {
                            setTableStyle(ed, table, now.width, sd[1]);
                        });
                    });
                }
            }
            /* How the text sits in the cell that was right-clicked. The caret
               was put in that cell above, so the command has its target. */
            const current = getComputedStyle(ctx.cell).textAlign;
            separator();
            CELL_ALIGN.forEach(function (a) {
                item(a[0] + (current === a[1] ? '  ✓' : ''), function () {
                    alignCell(ed, a[1]);
                });
            });

            separator();
            TABLE_MENU_ITEMS.forEach(function (entry) {
                item(entry[1], function () { tableAction(ed, entry[0]); }, entry[2] === 'delete');
            });
        }

        // Place it at the pointer, then pull it back inside the viewport if the
        // click happened near the right or bottom edge.
        ctxMenu.style.visibility = 'hidden';
        ctxMenu.classList.add('open');
        const w = ctxMenu.offsetWidth, h = ctxMenu.offsetHeight;
        const left = Math.min(x, window.innerWidth  - w - 8);
        const top  = Math.min(y, window.innerHeight - h - 8);
        ctxMenu.style.left = Math.max(8, left) + 'px';
        ctxMenu.style.top  = Math.max(8, top) + 'px';
        ctxMenu.style.visibility = '';
    }

    document.addEventListener('click', hideContextMenu);
    document.addEventListener('scroll', hideContextMenu, true);
    window.addEventListener('resize', hideContextMenu);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hideContextMenu();
    });

    /* ----- Links ----- */

    function linkAtCaret(surface) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return null;
        let n = sel.getRangeAt(0).startContainer;
        if (n.nodeType === 3) n = n.parentNode;
        const a = n.closest ? n.closest('a') : null;
        return (a && surface.contains(a)) ? a : null;
    }

    function insertLink(ed) {
        const sel = window.getSelection();
        const existing = linkAtCaret(ed.surface);
        const selectedText = sel && !sel.isCollapsed ? sel.toString() : '';
        // The range is lost the moment the dialog takes focus, so hold on to it.
        const savedRange = (sel && sel.rangeCount) ? sel.getRangeAt(0).cloneRange() : null;

        window.papelPrompt('Enter the web address to link to.', {
            title: existing ? 'Edit link' : 'Insert link',
            icon: 'link',
            value: existing ? existing.getAttribute('href') : '',
            placeholder: 'https://example.com'
        }).then(function (url) {
            if (url === null) return;                       // cancelled

            ed.surface.focus();
            if (savedRange && sel) { sel.removeAllRanges(); sel.addRange(savedRange); }

            if (url === '') {                               // cleared = remove link
                try { document.execCommand('unlink', false, null); } catch (err) {}
                refresh(ed);
                return;
            }

            const href = safeHref(url);
            if (!href) {
                window.papelAlert('That is not a web address, so no link was added. Enter something like pup.edu.ph, https://pup.edu.ph or mailto:name@pup.edu.ph.', { tone: 'error' });
                return;
            }

            if (existing) {
                // Swapped for a new anchor rather than having its href set in
                // place, so that changing where a link points can be undone.
                const a = existing.cloneNode(true);
                a.setAttribute('href', href);
                replaceNode(ed, existing, a.outerHTML);
            } else if (selectedText) {
                linkSelection(ed, href);
            } else {
                // Nothing selected: insert the address as its own link text.
                // Built as an element so the browser handles attribute and text
                // escaping rather than hand-rolled string concatenation.
                const a = document.createElement('a');
                a.setAttribute('href', href);
                a.textContent = href;
                insertHtmlAtCaret(ed, a.outerHTML + '&nbsp;');
            }
            // Whatever execCommand produced, force our own link attributes.
            ed.surface.querySelectorAll('a[href]').forEach(function (a) {
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener noreferrer nofollow');
            });
            refresh(ed);
        });
    }

    function init(root) {
        const ed = {
            root:    root,
            key:     root.getAttribute('data-section'),
            surface: root.querySelector('[data-role="surface"]'),
            input:   root.querySelector('[data-role="value"]'),
            count:   root.querySelector('[data-role="count"]')
        };
        editors[ed.key] = ed;

        // Produce tags (<b>, <i>) rather than inline styles, and <p> on Enter —
        // both keep the stored markup simple enough to sanitise server-side.
        try { document.execCommand('styleWithCSS', false, false); } catch (err) {}
        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (err) {}

        root.querySelectorAll('.doc-tool[data-cmd]').forEach(function (btn) {
            const cmd = btn.getAttribute('data-cmd');
            // mousedown, not click: preventing the default here stops the button
            // from stealing the selection the command needs to act on.
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () {
                ed.surface.focus();
                try { document.execCommand(cmd, false, null); } catch (err) {}
                refresh(ed);
                refreshToolbar(ed);
            });
        });

        // Each popover button toggles its own menu and closes every other one.
        function wirePopover(role, build, onOpen) {
            const btn = root.querySelector('.doc-tool[data-role="' + role + '"]');
            if (!btn) return;
            const menu = makePopover(build);
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () {
                const wasOpen = menu.classList.contains('open');
                closePopovers();
                if (wasOpen) return;
                if (onOpen) onOpen(menu);
                placeUnder(menu, btn);
                btn.classList.add('active');
            });
        }

        // Table popover: size picker over the row and column edits.
        wirePopover('table', function (menu) {
            buildTableBody(ed, menu);
        }, function (menu) {
            // Row and column edits only apply with the caret inside a table.
            const inTable = !!cellAtCaret(ed.surface);
            menu.querySelectorAll('.doc-table-action, .doc-align-btn').forEach(function (b) {
                b.disabled = !inTable;
            });
        });

        const expandBtn = root.querySelector('.doc-tool[data-role="expand"]');
        if (expandBtn) {
            expandBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            expandBtn.addEventListener('click', function () { toggleExpand(ed); });
        }

        // The indent buttons go through the same path as Tab, so a click and a
        // keypress produce identical markup.
        ['indent', 'outdent'].forEach(function (role) {
            const btn = root.querySelector('.doc-tool[data-role="' + role + '"]');
            if (!btn) return;
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () {
                ed.surface.focus();
                indentAtCaret(ed, role === 'outdent');
            });
        });

        const linkBtn = root.querySelector('.doc-tool[data-role="link"]');
        if (linkBtn) {
            linkBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            linkBtn.addEventListener('click', function () { insertLink(ed); });
        }
        const imageBtn = root.querySelector('.doc-tool[data-role="image"]');
        if (imageBtn) {
            // mousedown is where the caret would be lost, so it is stopped
            // there rather than on click: the picture goes where the student
            // was typing, not at the top of the box.
            imageBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            imageBtn.addEventListener('click', function () { pickImage(ed); });
        }

        const unlinkBtn = root.querySelector('.doc-tool[data-role="unlink"]');
        if (unlinkBtn) {
            unlinkBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            unlinkBtn.addEventListener('click', function () {
                ed.surface.focus();
                try { document.execCommand('unlink', false, null); } catch (err) {}
                refresh(ed);
            });
        }

        ed.surface.addEventListener('keydown', function (e) {
            const mod = e.ctrlKey || e.metaKey;
            const key = e.key.toLowerCase();

            // Ctrl+K is the shortcut every editor uses for this.
            if (mod && key === 'k') {
                e.preventDefault();
                insertLink(ed);
                return;
            }

            // Undo and redo are driven explicitly rather than left to the
            // browser: toolbar edits go through execCommand, and handling the
            // keys the same way keeps both on one history. Ctrl+Y is the
            // Windows redo; Ctrl+Shift+Z is the same thing elsewhere.
            if (mod && key === 'z' && !e.shiftKey) {
                e.preventDefault();
                try { document.execCommand('undo', false, null); } catch (err) {}
                refresh(ed); refreshToolbar(ed);
                return;
            }
            if (mod && (key === 'y' || (key === 'z' && e.shiftKey))) {
                e.preventDefault();
                try { document.execCommand('redo', false, null); } catch (err) {}
                refresh(ed); refreshToolbar(ed);
                return;
            }

            /* Enter continues the same paragraph on a new line; pressing it
               again turns that line into a new paragraph.

               A paper's paragraphs are separated by a visible gap, so every
               Enter starting a fresh paragraph would space out lines that were
               only ever meant to wrap — an address block, a list of authors, a
               table caption. A single Enter therefore inserts a line break, and
               the second one promotes it to a real paragraph. */
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
                if (listItemAtCaret(ed.surface) || cellAtCaret(ed.surface)) return;  // native behaviour
                e.preventDefault();
                if (caretFollowsLineBreak(ed.surface)) {
                    removeBreakBeforeCaret(ed.surface);
                    try { document.execCommand('insertParagraph', false, null); } catch (err) {}
                } else {
                    try { document.execCommand('insertLineBreak', false, null); } catch (err) {}
                }
                refresh(ed);
                return;
            }
            // Shift+Enter is the conventional "hard break", and here it is the
            // way to force a new paragraph without pressing Enter twice.
            if (e.key === 'Enter' && e.shiftKey) {
                if (listItemAtCaret(ed.surface) || cellAtCaret(ed.surface)) return;
                e.preventDefault();
                try { document.execCommand('insertParagraph', false, null); } catch (err) {}
                refresh(ed);
                return;
            }

            if (e.key !== 'Tab') return;

            // Tab would otherwise move focus out of the editor entirely. Inside
            // a table it walks cells the way a spreadsheet does; in a list it
            // nests the item; everywhere else it indents the first line only.
            e.preventDefault();
            indentAtCaret(ed, e.shiftKey);
        });

        /* Right-clicking offers what applies where the click landed: the row
           and column operations over a table, the size and removal of a picture
           over a picture, and Insert picture anywhere at all. */
        /* The explanation, the first time one of these boxes is about to be
           used. On mousedown rather than after the click: the whole point is to
           say why the boxes exist before anything is typed into one. The click
           is held back, the note is shown, and the box takes the caret the
           moment it is closed, so nothing is lost by the interruption.

           Capture phase, because the surface's own handlers would otherwise
           move the caret first. Once per page load; the button above the boxes
           brings it back after that. */
        ed.surface.addEventListener('mousedown', function (e) {
            if (typeof explainSections !== 'function') return;
            if (typeof sectionsExplained !== 'undefined' && sectionsExplained) return;
            e.preventDefault();
            e.stopPropagation();
            explainSections().then(function () {
                // The box was clicked, so it is already on screen; focusing it
                // must not move the page underneath the reader.
                try { ed.surface.focus({ preventScroll: true }); }
                catch (err) { ed.surface.focus(); }
            });
        }, true);

        // Tabbing into a box counts as going to use it just as much as clicking.
        ed.surface.addEventListener('focus', function () {
            if (typeof explainSections === 'function') explainSections();
        });

        ed.surface.addEventListener('contextmenu', function (e) {
            const image = e.target && e.target.closest ? e.target.closest('img') : null;
            const cell  = e.target && e.target.closest ? e.target.closest('td, th') : null;
            const inCell = (cell && ed.surface.contains(cell)) ? cell : null;
            const inImage = (image && ed.surface.contains(image)) ? image : null;

            e.preventDefault();
            if (inCell) {
                /* Put the caret in the clicked cell so the actions target it,
                   unless there is already a selection covering that cell. A
                   column dragged across in order to centre it must survive the
                   right-click that opens the menu, or only one cell is aligned. */
                const sel = window.getSelection();
                const insideSelection = sel && !sel.isCollapsed
                    && sel.rangeCount && sel.containsNode(inCell, true);
                if (!insideSelection) {
                    const range = document.createRange();
                    range.selectNodeContents(inCell);
                    range.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            } else if (!inImage) {
                // Anywhere else, the caret follows the click, so a picture is
                // inserted where the student pointed rather than where they
                // last happened to be typing.
                let range = null;
                if (document.caretRangeFromPoint) {
                    range = document.caretRangeFromPoint(e.clientX, e.clientY);
                } else if (document.caretPositionFromPoint) {
                    const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
                    if (pos) {
                        range = document.createRange();
                        range.setStart(pos.offsetNode, pos.offset);
                        range.collapse(true);
                    }
                }
                if (range && ed.surface.contains(range.commonAncestorContainer)) {
                    const sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }
            closePopovers();
            showContextMenu(ed, e.clientX, e.clientY, { cell: inCell, image: inImage });
        });

        // Paste keeps structure — pictures and tables above all — but not
        // presentation. The clipboard HTML is run through the allowlist first,
        // so rows, columns and lists survive while Word's fonts, colours and
        // widths do not.
        function clipboardImages(clip) {
            const out = [];
            const files = clip.files && clip.files.length ? clip.files : (clip.items || []);
            Array.prototype.forEach.call(files, function (entry) {
                const f = (entry && typeof entry.getAsFile === 'function')
                        ? (entry.kind === 'file' ? entry.getAsFile() : null)
                        : entry;
                if (f && /^image\//i.test(f.type || '')) out.push(f);
            });
            return out;
        }

        function insertAndRefresh(ed, html, dropped) {
            if (!insertHtmlAtCaret(ed, html)) return false;
            refresh(ed);
            if (dropped) noteDroppedImages(dropped);
            return true;
        }

        ed.surface.addEventListener('paste', function (e) {
            const clip = e.clipboardData || window.clipboardData;
            if (!clip) return;

            const html = clip.getData('text/html');
            const text = clip.getData('text/plain');
            const sel  = window.getSelection();

            /* A screenshot is on the clipboard as a file with nothing beside it.
               Copying a picture out of a web page gives the file *and* that
               page's markup; both are taken, words first, because the markup's
               own <img> only points back at the site it came from. */
            const images = clipboardImages(clip);
            if (images.length) {
                e.preventDefault();
                if (html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    if ((doc.body.textContent || '').trim() !== '') {
                        const clean = reflowBreaks(sanitizeHtml(html));
                        droppedImages = 0;               // the files carry them
                        if (clean.trim() !== '') insertAndRefresh(ed, clean, 0);
                    }
                }
                insertImages(ed, images);
                return;
            }

            e.preventDefault();

            /* An address pasted over selected words links those words, which is
               what every other editor does and what the student expects. */
            const one = (text || '').trim();
            const oneHref = /^\S+$/.test(one) ? safeHref(one) : null;
            if (sel && !sel.isCollapsed && oneHref) {
                if (linkSelection(ed, oneHref)) {
                    refresh(ed);
                    return;
                }
                // Otherwise fall through and paste it as the text it is.
            }

            if (html) {
                const clean = reflowBreaks(sanitizeHtml(html));
                const missed = droppedImages;
                if (clean.trim() !== '' && insertAndRefresh(ed, clean, missed)) return;
            }

            if (text) {
                /* A grid of values is a table to the person who copied it,
                   whether it came with any markup or not. */
                const asTable = textToTable(text);
                if (asTable && insertAndRefresh(ed, asTable, 0)) return;

                // Otherwise the same per-line breaks as any other plain text, so
                // it is rebuilt into paragraphs rather than inserted line by line.
                const built = textToHtml(text);
                if (built && insertAndRefresh(ed, built, 0)) return;
            }

            // Nothing else fitted, so it goes in as the plain text it is.
            if (!insertHtmlAtCaret(ed, '<p>' + esc(text) + '</p>')) {
                ed.surface.textContent += text;
            }
            refresh(ed);
        });

        /* Dropping a picture file onto the box puts it where it was dropped.
           Anything else is still refused: a dropped .docx would otherwise be
           navigated to, replacing the page and everything not yet saved. */
        ed.surface.addEventListener('dragover', function (e) {
            if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                ed.root.classList.add('is-drop-target');
            }
        });
        ed.surface.addEventListener('dragleave', function (e) {
            if (e.target === ed.surface) ed.root.classList.remove('is-drop-target');
        });
        ed.surface.addEventListener('drop', function (e) {
            e.preventDefault();
            ed.root.classList.remove('is-drop-target');
            const files = (e.dataTransfer && e.dataTransfer.files) || [];
            if (!files.length) return;
            // Put the caret where the file landed, so that is where it appears.
            let range = null;
            if (document.caretRangeFromPoint) {
                range = document.caretRangeFromPoint(e.clientX, e.clientY);
            } else if (document.caretPositionFromPoint) {
                const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
                if (pos) {
                    range = document.createRange();
                    range.setStart(pos.offsetNode, pos.offset);
                    range.collapse(true);
                }
            }
            if (range && ed.surface.contains(range.commonAncestorContainer)) {
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
            insertImages(ed, files);
        });

        /* ----- Resizing a table's columns -----
           The border between two columns is the handle. Dragging it takes width
           from one column and gives it to its neighbour, so the table itself
           never changes size and the rest of the paragraph does not reflow.
           Widths are written as percentages, which keeps a table looking the
           same in the editor, in the expanded view and on the paper's page,
           whatever each is wide. */
        const EDGE_PX = 6;     // how close to the border counts as grabbing it
        const MIN_COL = 28;    // a column narrower than this cannot be read
        let drag = null;

        function edgeCell(e) {
            const cell = e.target && e.target.closest ? e.target.closest('td, th') : null;
            if (!cell || !ed.surface.contains(cell)) return null;
            // The last column has no neighbour to trade width with.
            if (!cell.nextElementSibling) return null;
            const box = cell.getBoundingClientRect();
            return (box.right - e.clientX <= EDGE_PX && e.clientX <= box.right) ? cell : null;
        }

        function columnCells(table, index) {
            const out = [];
            table.querySelectorAll('tr').forEach(function (tr) {
                const cell = tr.children[index];
                if (cell) out.push(cell);
            });
            return out;
        }

        ed.surface.addEventListener('mousemove', function (e) {
            if (drag) return;
            const cell = edgeCell(e);
            ed.surface.querySelectorAll('.is-col-edge').forEach(function (c) { c.classList.remove('is-col-edge'); });
            if (cell) cell.classList.add('is-col-edge');
        });

        ed.surface.addEventListener('mousedown', function (e) {
            const cell = edgeCell(e);
            if (!cell) return;
            const table = cell.closest('table');
            if (!table) return;

            e.preventDefault();      // not a text selection — a resize
            const index = Array.prototype.indexOf.call(cell.parentNode.children, cell);
            drag = {
                table: table,
                width: table.getBoundingClientRect().width,
                startX: e.clientX,
                left:  columnCells(table, index),
                right: columnCells(table, index + 1),
                leftPx:  cell.getBoundingClientRect().width,
                rightPx: cell.nextElementSibling.getBoundingClientRect().width
            };
            ed.root.classList.add('is-resizing');
        });

        document.addEventListener('mousemove', function (e) {
            if (!drag) return;
            let delta = e.clientX - drag.startX;
            // Neither column may be squeezed out of existence.
            delta = Math.max(delta, MIN_COL - drag.leftPx);
            delta = Math.min(delta, drag.rightPx - MIN_COL);

            const total = drag.width || 1;
            const leftPct  = ((drag.leftPx  + delta) / total) * 100;
            const rightPct = ((drag.rightPx - delta) / total) * 100;

            drag.left.forEach(function (c)  { c.style.width = leftPct.toFixed(2) + '%'; });
            drag.right.forEach(function (c) { c.style.width = rightPct.toFixed(2) + '%'; });
        });

        document.addEventListener('mouseup', function () {
            if (!drag) return;
            drag = null;
            ed.root.classList.remove('is-resizing');
            refresh(ed);        // the new widths belong in the posted value
        });

        ['input', 'keyup', 'mouseup', 'focus', 'blur'].forEach(function (evt) {
            ed.surface.addEventListener(evt, function () { refresh(ed); refreshToolbar(ed); });
        });

        refresh(ed);
        // Last, so every tool is wired before any of them can be moved aside.
        setupOverflow(ed);
        return ed;
    }

    document.addEventListener('selectionchange', function () {
        const active = document.activeElement;
        if (!active || !active.classList || !active.classList.contains('doc-surface')) return;
        const root = active.closest('.doc-editor');
        const ed = root && editors[root.getAttribute('data-section')];
        if (ed) refreshToolbar(ed);
    });

    return {
        initAll: function () { document.querySelectorAll('.doc-editor').forEach(init); },
        setText: function (key, text) {
            const ed = editors[key]; if (!ed) return;
            ed.surface.innerHTML = textToHtml(text);
            refresh(ed);
        },
        text:  function (key) { const ed = editors[key]; return ed ? plainText(ed) : ''; },
        html:  function (key) { const ed = editors[key]; return ed ? ed.input.value : ''; },
        // Restores formatted content, unlike setText which builds plain
        // paragraphs — used when a saved draft is put back.
        setHtml: function (key, html) {
            const ed = editors[key]; if (!ed) return;
            ed.surface.innerHTML = html || '';
            refresh(ed);
        },
        keys: function () { return Object.keys(editors); },
        clear: function (key) { this.setText(key, ''); },
        clearAll: function () { Object.keys(editors).forEach(k => this.setText(k, '')); },
        syncAll:  function () { Object.keys(editors).forEach(k => refresh(editors[k])); },
        // Returns the key of the first empty section, or null when all are filled.
        firstEmpty: function () {
            return Object.keys(editors).find(k => plainText(editors[k]) === '') || null;
        },
        flag: function (key) {
            const ed = editors[key]; if (!ed) return;
            ed.root.classList.add('is-invalid');
            ed.root.scrollIntoView({ behavior: 'smooth', block: 'center' });
            ed.surface.focus();
        },
        label: function (key) {
            const ed = editors[key]; if (!ed) return key;
            const t = ed.root.querySelector('.doc-editor-title');
            return t ? t.textContent.replace('*', '').trim() : key;
        }
    };
})();

/* =========================================================================
   PDF preview
   The chosen file is read straight out of the browser's memory through an
   object URL. Nothing is uploaded to open this — the file only reaches the
   server, and Google Drive, when the form is finally submitted.
   ========================================================================= */
const papelPdfPreview = (function () {
    let view = null;        // shared renderer, created on first open
    let objectUrl = null;   // only used to hand the file to the viewer tab

    function panel()    { return document.getElementById('pdf-preview'); }
    function scroller() { return document.getElementById('pdf-preview-scroll'); }

    function currentFile() {
        const input = document.getElementById('pdfFile');
        return (input && input.files && input.files[0]) ? input.files[0] : null;
    }

    function release() {
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        if (view) { view.destroy(); view = null; }
    }

    function showZoom(z) {
        const label = document.getElementById('pdfZoomLevel');
        if (label) label.textContent = Math.round(z * 100) + '%';
    }

    // The tab only makes sense while a file is actually chosen.
    function showRestoreTab() {
        const tab = document.getElementById('pdf-restore');
        if (tab) tab.hidden = !currentFile();
    }
    function hideRestoreTab() {
        const tab = document.getElementById('pdf-restore');
        if (tab) tab.hidden = true;
    }

    function showMode(mode) {
        const btn = document.getElementById('pdfPanMode');
        if (!btn) return;
        const panning = (mode === 'pan');
        btn.classList.toggle('active', panning);
        btn.setAttribute('aria-pressed', panning ? 'true' : 'false');
        btn.title = panning
            ? 'Drag mode — press T or Esc, or click, to select text instead'
            : 'Select mode — press H, or click, to drag the page instead';
        btn.querySelector('.material-symbols-outlined').textContent = panning ? 'pan_tool' : 'text_select_start';
    }

    // One renderer per panel, built lazily so the elements exist first.
    function ensureView() {
        if (view) return view;
        if (typeof papelPdfView === 'undefined') return null;
        view = papelPdfView.create({
            scroller:  scroller(),
            status:    document.getElementById('pdf-preview-status'),
            workerSrc: PAPEL_PDF_WORKER,
            onZoom:    showZoom,
            onMode:    showMode
        });
        showMode(view.getMode());
        return view;
    }

    return {
        open: function () {
            const file = currentFile();
            if (!file) return;

            document.getElementById('pdf-preview-name').textContent = file.name;
            panel().hidden = false;
            document.body.classList.add('pdf-docked');
            hideRestoreTab();

            // The preview now holds the right edge. A chat docked there moves to
            // the left rather than being closed, so the conversation is kept.
            const widget = document.getElementById('chat-widget');
            if (widget && widget.classList.contains('dock-right') && typeof applyChatDock === 'function') {
                applyChatDock(true, 'left');
            } else if (typeof syncChatSideAvailability === 'function') {
                syncChatSideAvailability();
            }

            const v = ensureView();
            if (v) v.load(file);
        },
        close: function () {
            if (view) view.clear();
            panel().hidden = true;
            document.body.classList.remove('pdf-docked');
            // The right edge is free again.
            if (typeof syncChatSideAvailability === 'function') syncChatSideAvailability();
            showRestoreTab();
        },
        // Same as closing, but named for what the student meant: put it away
        // for now. The restore tab is what makes it recoverable either way.
        minimise: function () { this.close(); },
        toggle: function () {
            if (panel().hidden) this.open(); else this.close();
        },
        zoomIn:     function () { if (view) view.zoomIn(); },
        zoomOut:    function () { if (view) view.zoomOut(); },
        resetZoom:  function () { if (view) view.resetZoom(); },
        toggleMode: function () { if (view) view.toggleMode(); },
        getMode:    function () { return view ? view.getMode() : 'select'; },
        setMode:    function (mode) { if (view) view.setMode(mode); },
        hint:       function (msg) { if (view) view.hint(msg); },

        /* Opens the full-window viewer. It is one of our own pages rather than
           the browser's PDF viewer, so the document is drawn by exactly the same
           renderer as the panel. The file never touches the server: the new tab
           announces itself and the file is handed over in memory, same-origin. */
        popOut: function () {
            const file = currentFile();
            if (!file) return;

            const w = window.open(PAPEL_PDF_VIEWER_URL, '_blank');
            if (!w) {
                window.papelAlert('Your browser blocked the new tab. Allow pop-ups for this site to open the full view.');
                return;
            }

            const origin = window.location.origin;
            function handOver(e) {
                if (e.origin !== origin || !e.data || e.data.type !== 'papel-pdf-ready') return;
                if (e.source !== w) return;
                window.removeEventListener('message', handOver);
                w.postMessage({ type: 'papel-pdf-file', file: file, name: file.name }, origin);
            }
            window.addEventListener('message', handOver);
            // Give up listening if the tab never reports in.
            setTimeout(function () { window.removeEventListener('message', handOver); }, 30000);
        },

        // Called whenever the chosen file changes, so the previous document does
        // not linger or keep pointing at the old file.
        reset: function () {
            this.close();
            release();
            // Picking a PDF shows it straight away — the student can check they
            // grabbed the right file before spending time on the rest of the form.
            if (currentFile()) this.open();
        }
    };
})();


/* =========================================================================
   Draft saving

   The wizard is long, and losing a half-written paper to a stray click is the
   worst thing this page could do. Everything typed is therefore kept on this
   device as it is entered, and offered back on return.

   One honest limit: a chosen file cannot be stored by a web page — the browser
   gives a page a handle to a file, not the file itself, and that handle dies
   with the tab. Every word is restored; the PDF and any supporting documents
   have to be picked again. The restore prompt says so plainly rather than
   letting a student discover it at the submit button.
   ========================================================================= */
const papelDraft = (function () {
    const KEY = 'papel_upload_draft_' + <?= json_encode((string)($u['user_id'] ?? 'anon'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    /* Set when the dashboard sent us here with ?draft=<id>.

       JSON_HEX_TAG matters here: a title is free text, and without it a title
       containing a closing script tag ended this block early — the rest of the
       draft became markup and papelDraft was never defined, which quietly broke
       the whole wizard. */
    const SERVER_DRAFT = <?= $draftPayload ? json_encode($draftPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) : 'null' ?>;
    // The drafts this student actually still has, so a local copy left over
    // from a deleted one can be told apart from unsaved work.
    const LIVE_DRAFTS = <?= json_encode($liveDraftIds) ?>;
    let draftId = SERVER_DRAFT ? SERVER_DRAFT.id : 0;
    const SKIP = ['_token', 'action'];
    let saveTimer = null;
    let submitting = false;
    /* What the form looked like the last time it was saved. dirty() used to ask
       only "is there anything typed?", which is true from the first keystroke
       and stays true after a save — so every link on the page kept asking to
       save a draft that was already saved, on page after page. Comparing
       against this makes "unsaved" mean what it says. */
    let savedSignature = null;

    function form() { return document.getElementById('uploadForm'); }

    // Everything the student has typed, plus where they had got to.
    function collect() {
        const f = form();
        if (!f) return null;
        if (typeof papelDoc !== 'undefined') papelDoc.syncAll();

        const fields = {};
        f.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (!el.name || SKIP.indexOf(el.name) !== -1) return;
            if (el.type === 'file') return;                 // cannot be stored
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) {
                    fields[el.name] = fields[el.name] || [];
                    if (Array.isArray(fields[el.name])) fields[el.name].push(el.value);
                }
                return;
            }
            fields[el.name] = el.value;
        });

        // Named so the student can see what to re-attach.
        const files = {};
        f.querySelectorAll('input[type="file"]').forEach(function (el) {
            if (el.files && el.files[0]) files[el.name] = el.files[0].name;
        });

        return {
            savedAt: Date.now(),
            step: typeof currentStep === 'number' ? currentStep : 1,
            /* Which row on the server these fields belong to. Without it, a
               draft picked up again from the local copy had no idea it already
               existed and saved itself as a second draft every time. */
            draftId: draftId,
            fields: fields,
            files: files
        };
    }

    /* Only the typed values matter for "has this changed" — the file list is
       rebuilt from scratch on every visit and would otherwise report a change
       that the student never made. */
    function signature(data) {
        if (!data) return null;
        try { return JSON.stringify(data.fields); } catch (err) { return null; }
    }

    // Anything worth keeping? A bare form should never trigger a prompt.
    function hasContent(data) {
        if (!data) return false;
        if (Object.keys(data.files).length) return true;
        return Object.keys(data.fields).some(function (name) {
            const v = data.fields[name];
            if (Array.isArray(v)) return v.length > 0;
            if (typeof v !== 'string') return false;
            const text = v.replace(/<[^>]*>/g, '').trim();
            // The year box is pre-filled, so on its own it means nothing.
            return text !== '' && name !== 'year';
        });
    }

    function write(data) {
        try { localStorage.setItem(KEY, JSON.stringify(data)); return true; }
        catch (err) { return false; }   // private mode, or quota
    }

    function read() {
        try {
            const raw = localStorage.getItem(KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (err) { return null; }
    }

    function apply(data) {
        const f = form();
        if (!f || !data) return;

        Object.keys(data.fields).forEach(function (name) {
            const value = data.fields[name];
            /* Two shapes arrive here. The copy saved in this browser uses the
               input's own name, "manuscript_type[]", and a real array. A draft
               reopened from the dashboard comes from the database, so it is
               named after the column, "manuscript_type", and the ticked ones
               are one comma-separated string. Accepting only the first shape
               is why a reopened draft came back with nothing ticked. */
            let nodes = f.querySelectorAll('[name="' + CSS.escape(name) + '"]');
            if (!nodes.length) {
                nodes = f.querySelectorAll('[name="' + CSS.escape(name + '[]') + '"]');
            }
            const ticked = Array.isArray(value)
                ? value
                : String(value === null || value === undefined ? '' : value)
                    .split(',').map(function (v) { return v.trim(); });
            nodes.forEach(function (el) {
                if (el.type === 'file') return;
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = ticked.indexOf(el.value) !== -1;
                } else {
                    el.value = value;
                }
            });
        });

        // The hidden inputs hold the markup; push it back into the editors.
        if (typeof papelDoc !== 'undefined') {
            papelDoc.keys().forEach(function (key) {
                if (typeof data.fields[key] === 'string') papelDoc.setHtml(key, data.fields[key]);
            });
        }

        // A draft with any metadata means the fields were already revealed.
        if (data.fields.title || data.fields.abstract) {
            const fieldsBox = document.getElementById('metadataFields');
            const nextBtn = document.getElementById('btnNextToSupporting');
            if (fieldsBox) fieldsBox.style.display = 'block';
            if (nextBtn) nextBtn.style.display = 'block';
        }

        if (typeof syncSupportingDocs === 'function') syncSupportingDocs();
        if (typeof updateReview === 'function') updateReview();

        // Step 2 onwards needs a PDF, which cannot be restored — so the student
        // lands back on Step 1 to re-attach it, with everything else in place.
        if (typeof goToStep === 'function') goToStep(1);
    }

    return {
        save: function () {
            const data = collect();
            if (!hasContent(data)) return false;
            return write(data);
        },
        saveSoon: function () {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(function () { papelDraft.save(); }, 700);
        },
        has: function () { return hasContent(read()); },
        stored: read,
        /* Cancels the pending autosave before removing the copy. Typing
           schedules a write 700ms later, so discarding within that window
           removed the copy and then had it written straight back, which is why
           "Continue your draft?" kept returning after the draft was discarded. */
        clear: function () {
            clearTimeout(saveTimer);
            saveTimer = null;
            try { localStorage.removeItem(KEY); } catch (err) {}
        },
        // Set while the form is being submitted, so leaving the page as a
        // result of a successful upload does not raise a warning.
        beginSubmit: function () { submitting = true; },
        isSubmitting: function () { return submitting; },
        dirty: function () {
            if (submitting) return false;
            const data = collect();
            if (!hasContent(data)) return false;
            return signature(data) !== savedSignature;
        },

        /* Saves to the server, where the dashboard can see it. Returns the
           draft's id so later saves update the same row instead of piling up
           duplicates. The PDF goes along when one is attached, so resuming from
           another visit does not always mean re-picking the file. */
        saveToServer: async function () {
            const f = form();
            if (!f) return 0;
            if (typeof papelDoc !== 'undefined') papelDoc.syncAll();

            const body = new FormData(f);
            body.set('action', 'save_draft');
            if (draftId) body.set('draft_id', String(draftId));
            // Supporting documents belong to submission, not to a draft.
            ['ethics_clearance', 'consent_form', 'data_collection', 'copyright_doc', 'other_doc']
                .forEach(function (n) { body.delete(n); });

            // Only ever run because the student asked to save, so it earns the
            // logo pill; a fetch is invisible to loading_bar.php's form hook.
            if (window.papelLoading) window.papelLoading.work.start();
            try {
                const res = await fetch('student_upload_ai.php', { method: 'POST', body: body });
                const json = await res.json();
                if (json && json.success) {
                    draftId = json.draft_id;
                    savedSignature = signature(collect());
                    return draftId;
                }
            } catch (err) { /* the local copy still holds everything */ }
            finally { if (window.papelLoading) window.papelLoading.work.done(); }
            return 0;
        },
        draftId: function () { return draftId; },
        serverDraft: function () { return SERVER_DRAFT; },

        // Offered once, on arrival.
        offerRestore: async function () {
            /* The baseline for "unsaved". Some boxes arrive already filled — the
               year, the student's own name — so without this the page counts as
               having unsaved work before the student has touched anything, and
               the leave prompt fires on the very first link they click. */
            savedSignature = signature(collect());

            // A draft opened from the dashboard is applied without asking —
            // clicking it *is* the request to continue.
            if (SERVER_DRAFT) {
                apply({ fields: SERVER_DRAFT.fields, files: {}, step: 1 });
                // Nothing has been changed yet, so there is nothing to save.
                savedSignature = signature(collect());
                if (SERVER_DRAFT.status) {
                    String(SERVER_DRAFT.status).split(',').map(function (v) { return v.trim(); })
                        .forEach(function (v) {
                            const box = document.querySelector('input[name="paper_status[]"][value="' + CSS.escape(v) + '"]');
                            if (box) box.checked = true;
                        });
                }
                return;
            }

            const data = read();
            if (!hasContent(data)) return;

            /* A local copy that came from a draft the student has since deleted
               is not unfinished work, it is a leftover. Prompting about it made
               a deleted draft look undeletable, and answering Continue wrote it
               straight back as a new draft. A copy with no draft id has never
               been on the server at all, so that one really is unsaved and is
               still offered. */
            if (data.draftId && LIVE_DRAFTS.indexOf(Number(data.draftId)) === -1) {
                papelDraft.clear();
                return;
            }

            const when = new Date(data.savedAt || Date.now());
            const files = Object.keys(data.files || {});
            const note = files.length
                ? ' You will need to attach ' + files.join(', ') + ' again — a browser cannot keep a file between visits.'
                : '';

            const restore = await window.papelConfirm(
                'You have an unfinished paper saved on this device from ' +
                when.toLocaleString() + '.' + note,
                { title: 'Continue your draft?', icon: 'history',
                  confirmText: 'Continue', cancelText: 'Start fresh' }
            );

            if (restore) {
                // Carry on saving into the same row this work came from.
                if (data.draftId) draftId = data.draftId;
                apply(data);
                /* savedSignature deliberately stays at the pristine baseline:
                   this copy came off the device, not the server, so it really
                   is unsaved and the leave prompt should still fire. */
            } else {
                papelDraft.clear();
            }
        }
    };
})();

// File indicator functions
function updatePdfIndicator(input) {
    const indicator = document.getElementById('pdf-indicator');
    papelPdfPreview.reset();
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        indicator.innerHTML = `<span class="material-symbols-outlined mi-18 mi-fill">check_circle</span> ${fileName}`
            + '<span class="pdf-indicator-hint">Click to preview</span>';
        indicator.classList.add('selected');
    } else {
        indicator.innerHTML = '<span class="material-symbols-outlined mi-18 text-muted">cancel</span> No PDF selected';
        indicator.classList.remove('selected');
    }
}

function updateFileIndicator(input, indicatorId) {
    const indicator = document.getElementById(indicatorId);
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        indicator.innerHTML = `<span class="material-symbols-outlined mi-18 mi-fill">check_circle</span> ${fileName}`;
        indicator.classList.add('selected');
    } else {
        const isOptional = indicatorId === 'other-indicator';
        indicator.innerHTML = isOptional 
            ? '<span class="material-symbols-outlined mi-18 text-muted">do_not_disturb_on</span> Optional' 
            : '<span class="material-symbols-outlined mi-18 text-muted">cancel</span> No file selected';
        indicator.classList.remove('selected');
    }
}

function goToStep(step) {
    // Validation before moving forward
    if (step> currentStep) {
        if (currentStep === 1) {
            const paperType = document.querySelector('select[name="paper_type"]').value;
            if (!paperType) {
                papelAlert('Please select a paper type');
                return;
            }
            /* By name, not by tag. A student picks a programme from a select;
               staff belong to the faculty rather than to a degree, so for them
               the field is a read-only box with a hidden input behind it. This
               looked only for a select, found nothing, and threw on .value —
               which killed the click handler, so the button appeared dead
               rather than complaining about anything. */
            const programEl = document.querySelector('[name="program_category"]');
            const program = programEl ? String(programEl.value).trim() : '';
            if (!program) {
                papelAlert('Please select an Academic Program');
                return;
            }
            /* `required` on a checkbox means that one box, not one of the
               group, so the "at least one" rule is made here. */
            if (!document.querySelectorAll('.js-manuscript:checked').length) {
                papelAlert('Please choose at least one manuscript type');
                return;
            }
        }
        if (currentStep === 2) {
            const pdfFile = document.getElementById('pdfFile').files[0];
            const titleField = document.getElementById('titleField').value;
            if (!pdfFile) {
                papelAlert('Please upload a PDF file');
                return;
            }
            if (!titleField) {
                papelAlert('Please extract metadata first by clicking "Extract Metadata with AI"');
                return;
            }
            // Completion date is required
            const researchDate = document.getElementById('researchDateField').value;
            if (!researchDate) {
                papelAlert('Please enter the date the research was completed.');
                return;
            }
            // Keyword Validation
            const keywords = document.getElementById('keywordsField').value;
            const kwCount = keywords.split(',').filter(k => k.trim().length> 0).length;
            if (kwCount < 5) {
                papelAlert('Please provide at least 5 keywords (comma-separated).');
                return;
            }
            // Every IMRAD section must be filled before moving on.
            papelDoc.syncAll();
            const missing = papelDoc.firstEmpty();
            if (missing) {
                papelAlert('Please write the ' + papelDoc.label(missing) + ' section before continuing.');
                papelDoc.flag(missing);
                return;
            }
        }
        if (currentStep === 3 && supportingDocsRequired()) {
            const missing = REQUIRED_DOCS.filter(function (d) {
                const input = document.querySelector('input[name="' + d.name + '"]');
                return !input || !input.files[0];
            });
            if (missing.length) {
                papelAlert('Please attach the ' + missing[0].label + '. It is required for a '
                    + paperTypeLabel() + '.');
                return;
            }
        }
    }
    
    // Hide all sections
    for (let i = 1; i <= 4; i++) {
        document.getElementById(`step${i}`).style.display = 'none';
        document.querySelector(`.step-item[data-step="${i}"]`).classList.remove('active');
    }
    
    // Show current section
    document.getElementById(`step${step}`).style.display = 'block';
    document.querySelector(`.step-item[data-step="${step}"]`).classList.add('active');
    
    // Mark previous steps as completed
    for (let i = 1; i < step; i++) {
        document.querySelector(`.step-item[data-step="${i}"]`).classList.add('completed');
        if (i < step - 1) {
            const connectors = document.querySelectorAll('.step-connector');
            if (connectors[i - 1]) {
                connectors[i - 1].classList.add('completed');
            }
        }
    }
    
    // Update review if going to step 4
    if (step === 4) {
        updateReview();
    }
    
    currentStep = step;
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* Why the sections have to be typed out.

   Shown once on arriving at step 2, and available from the "Why do I have to
   type these?" button after that. Once per visit rather than once ever: a
   student uploads a paper rarely enough that the second time is usually months
   later, and a note nobody can find again is worse than one seen twice. */
let sectionsExplained = false;

const CAN_DRAFT = <?= $canDraft ? 'true' : 'false' ?>;

const SECTIONS_NOTE =
    '<p>Your PDF and what you type here are read by different people, and they'
  + ' are not interchangeable.</p>'
  + (CAN_DRAFT
      ? '<p><strong>The PDF goes to your reviewers.</strong> Your Research Adviser'
        + ' and the Research Coordinator open it to check your work as you submitted'
        + ' it, formatting, figures, appendices and all.</p>'
      : '<p><strong>The PDF is the record of the paper itself.</strong> It is kept'
        + ' as you submitted it, formatting, figures, appendices and all, and is'
        + ' what anyone goes back to for the full document.</p>')
  + '<p><strong>What you type is what the repository shows.</strong> When your'
  + ' paper is published, a reader opens the page built from these boxes. The'
  + ' PDF is not what they read, and it is not what the search looks through.</p>'
  + '<p>That is why all six are required:</p>'
  + '<ul>'
  + '<li><strong>Abstract</strong> — taken from your PDF word for word, so check it.</li>'
  + '<li><strong>Introduction</strong></li>'
  + '<li><strong>Methodology</strong></li>'
  + '<li><strong>Results and Discussion</strong></li>'
  + '<li><strong>Conclusion</strong></li>'
  + '<li><strong>References</strong></li>'
  + '</ul>'
  + '<p>You can paste them straight from your document. Tables, pictures and'
  + ' links all survive the paste'
  + (CAN_DRAFT
      ? ', and a draft can be saved at any point.</p>'
      : '. Your paper is published as soon as you submit it, with no review step,'
        + ' so fill it in before you leave the page: drafts are for student'
        + ' submissions only.</p>');

function explainSections(force) {
    if (sectionsExplained && !force) return Promise.resolve(false);
    sectionsExplained = true;
    return window.papelAlert('', {
        title: 'Why you type the sections out',
        icon: 'menu_book',
        wide: true,
        html: SECTIONS_NOTE
    });
}

function updateReview() {
    const paperType = document.querySelector('select[name="paper_type"]');
    const pdfFile = document.getElementById('pdfFile');
    const title = document.getElementById('titleField');
    const authors = document.getElementById('authorsField');
    const year = document.getElementById('yearField');
    
    document.getElementById('reviewPaperType').textContent = 
        paperType.options[paperType.selectedIndex].text;
    document.getElementById('reviewPDF').textContent = 
        pdfFile.files[0] ? pdfFile.files[0].name : '-';
    document.getElementById('reviewTitle').textContent = title.value || '-';
    document.getElementById('reviewAuthors').textContent = authors.value || '-';
    document.getElementById('reviewYear').textContent = year.value || '-';
    
    // Count supporting docs
    let docCount = 0;
    if (document.querySelector('input[name="ethics_clearance"]').files[0]) docCount++;
    if (document.querySelector('input[name="consent_form"]').files[0]) docCount++;
    if (document.querySelector('input[name="data_collection"]').files[0]) docCount++;
    if (document.querySelector('input[name="other_doc"]').files[0]) docCount++;
    if (document.querySelector('input[name="copyright_doc"]').files[0]) docCount++;
    
    /* The wording follows whether this paper type needs the documents at
       all. "Missing" reads as a fault, and for a Journal Article or a
       Project there is no fault to report — nothing was ever required. */
    const docsNeeded = supportingDocsRequired();

    document.getElementById('reviewDocs').textContent =
        docCount ? docCount + ' document(s) attached'
                 : (docsNeeded ? 'None attached yet' : 'Not required for this paper type');

    const copyFile = document.querySelector('input[name="copyright_doc"]').files[0];
    document.getElementById('reviewCopyright').textContent =
        copyFile ? copyFile.name
                 : (docsNeeded ? 'Not attached yet' : 'Not applicable');
}

/* This page's overlay holds the middle of the window, which is where the logo
   pill (includes/loading_bar.php) would otherwise land, one on top of the
   other. These stack them instead: the pill sits just above the overlay's card
   for as long as the overlay is up, and goes back to the middle afterwards. */
function papelPillAboveOverlay() {
    const card  = document.querySelector('#uploadOverlay .loading-content');
    const badge = document.getElementById('papelWorkBadge');
    if (!card || !badge) return;
    /* Worked out from the heights rather than from the card's current box: the
       card rises into place on an animation, and reading its position while
       that is still running put the pill 30px too low, on top of it. The card
       is centred, so where it will come to rest is known without waiting. */
    const top = innerHeight / 2 - card.offsetHeight / 2 - 16 - badge.offsetHeight / 2;
    document.documentElement.style.setProperty(
        '--papel-pill-top', Math.max(badge.offsetHeight / 2 + 8, top) + 'px');
}
function papelPillRecentre() {
    document.documentElement.style.removeProperty('--papel-pill-top');
}

// Extract AI button handler
document.getElementById('btnExtract').addEventListener('click', function(){
  const pdfFile = document.getElementById('pdfFile').files[0];
  if(!pdfFile){
    papelAlert('Please select a PDF first');
    return;
  }

  const btn = this;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Extracting...';

  const overlay   = document.getElementById('uploadOverlay');
  const fillEl    = document.getElementById('uploadProgressFill');
  const labelEl  = document.getElementById('uploadProgressLabel');
  const statusEl  = document.getElementById('uploadStatusMsg');

  overlay.classList.add('active');
  papelPillAboveOverlay();
  fillEl.style.width  = '10%';
  labelEl.textContent = '10%';
  statusEl.textContent = 'Sending PDF to AI...';

  const aiMessages = [
    'Reading your PDF...',
    'Extracting title & authors...',
    'Analyzing abstract...',
    'Almost done...'
  ];
  let aiIdx = 0;
  const aiTimer = setInterval(function() {
    aiIdx = Math.min(aiIdx + 1, aiMessages.length - 1);
    const pct = 10 + aiIdx * 16;
    statusEl.style.opacity = '0';
    setTimeout(function() {
      statusEl.textContent = aiMessages[aiIdx];
      statusEl.style.opacity = '1';
      fillEl.style.width  = pct + '%';
      labelEl.textContent = pct + '%';
    }, 250);
  }, 3000);

  let fd = new FormData();
  fd.append('action', 'extract_ai');
  fd.append('research_pdf', pdfFile);
  fd.append('model_choice', document.getElementById('extractModelSelect').value);

  // Find CSRF token
  const tokenInput = document.querySelector('input[name="csrf_token"]') || document.querySelector('input[name="_token"]');
  if(tokenInput) fd.append(tokenInput.name, tokenInput.value);

  // Extraction is a background request, so the form-submit hook in
  // loading_bar.php never sees it — the logo pill has to be asked for.
  if (window.papelLoading) window.papelLoading.work.start();
  fetch('student_upload_ai.php', {method:'POST', body:fd})
  .then(r => r.json())
  .then(data => {
    if(data.success){
      document.getElementById('titleField').value = data.data.title || '';
      document.getElementById('yearField').value = data.data.year || '';
      document.getElementById('authorsField').value = data.data.authors || '';
      document.getElementById('keywordsField').value = data.data.keywords || '';
      // Only the Abstract is filled from the PDF; the other three stay blank
      // for the student to write.
      papelDoc.setText('abstract', data.data.abstract || '');
      document.getElementById('aiSummaryField').value = data.data.ai_summary || '';
      document.getElementById('aiMethodologyField').value = data.data.ai_methodology || '';
      document.getElementById('aiStatsField').value = data.data.ai_statistical_methods || '';
      document.getElementById('aiVariablesField').value = data.data.ai_variables || '';
      document.getElementById('aiSampleSizeField').value = data.data.ai_sample_size || '';
      document.getElementById('aiResearchFieldField').value = data.data.ai_research_field || '';
      

      // Show metadata fields and always allow proceeding
      document.getElementById('metadataBadge').className = 'step-badge';
      document.getElementById('metadataBadge').textContent = 'Extracted';
      document.getElementById('metadataFields').style.display = 'block';
      document.getElementById('btnNextToSupporting').style.display = 'block';
      
      // Extraction notice. It stays until the student dismisses it or runs
      // another extraction — it tells them what they still have to write, so
      // timing it out means the instruction vanishes before it has been read.
      const previousMsg = document.getElementById('extractMsg');
      if (previousMsg) previousMsg.remove();

      const successMsg = document.createElement('div');
      successMsg.id = 'extractMsg';
      successMsg.className = 'alert alert-success mt-3 alert-dismissable';
      successMsg.innerHTML = (papelDoc.text('abstract')
        ? '<strong>Extraction complete.</strong> Review the Abstract and edit if needed, '
          + 'then write your Introduction, Methodology, and Results and Discussion.'
        : '<strong>Extraction complete.</strong> No abstract could be found in your PDF — '
          + 'please write all four sections yourself.')
        + '<button type="button" class="alert-close" aria-label="Dismiss">'
        + '<span class="material-symbols-outlined mi-18">close</span></button>';
      successMsg.querySelector('.alert-close').addEventListener('click', function () {
        successMsg.remove();
      });
      btn.parentElement.parentElement.appendChild(successMsg);
    } else {
      papelAlert('❌ ' + (data.message || 'Extraction failed'));
    }
  })
  .catch(e => {
    console.error(e);
    papelAlert('❌ Error connecting to server');
  })
  .finally(() => {
    if (window.papelLoading) window.papelLoading.work.done();
    clearInterval(aiTimer);
    overlay.classList.remove('active');
    papelPillRecentre();
    fillEl.style.width  = '0%';
    labelEl.textContent = '0%';
    btn.disabled = false;
    btn.innerHTML = 'Extract Metadata with AI';
  });
});

// Form submission handler
document.getElementById('uploadForm').addEventListener('submit', function(e){
    e.preventDefault();

    // Flush every section editor into its hidden input before posting.
    papelDoc.syncAll();
    const missingSection = papelDoc.firstEmpty();
    if (missingSection) {
        papelAlert('Please write the ' + papelDoc.label(missingSection) + ' section before submitting.');
        papelDoc.flag(missingSection);
        return;
    }

    const overlay   = document.getElementById('uploadOverlay');
    const btn       = document.getElementById('btnUpload');
    const fillEl    = document.getElementById('uploadProgressFill');
    const labelEl   = document.getElementById('uploadProgressLabel');
    const statusEl  = document.getElementById('uploadStatusMsg');

    overlay.classList.add('active');
    papelPillAboveOverlay();
    btn.disabled = true;
    // Sent by XHR, which the form-submit hook in loading_bar.php cannot see.
    const pill = window.papelLoading ? window.papelLoading.work : null;
    if (pill) pill.start();

    // Cycling status messages timed to feel alive
    const messages = [
        'Preparing your files',
        'Uploading to secure storage',
        'Saving paper metadata',
        'Running document checks',
        'Almost there — finalizing'
    ];
    let msgIdx = 0;
    const msgTimer = setInterval(function() {
        msgIdx = (msgIdx + 1) % messages.length;
        statusEl.style.opacity = '0';
        setTimeout(function() {
            statusEl.textContent = messages[msgIdx];
            statusEl.style.opacity = '1';
        }, 250);
    }, 3500);

    const fd  = new FormData(this);
    /* If this paper was being written as a draft — or was sent back for
       revision and reopened — the server replaces that row instead of filing a
       second copy of the same paper. */
    const openDraft = (typeof papelDraft !== 'undefined') ? papelDraft.draftId() : 0;
    if (openDraft) fd.set('draft_id', String(openDraft));

    const xhr = new XMLHttpRequest();

    // Real upload progress
    xhr.upload.addEventListener('progress', function(ev) {
        if (!ev.lengthComputable) return;
        const pct = Math.round((ev.loaded / ev.total) * 90); // cap at 90 until server responds
        fillEl.style.width  = pct + '%';
        labelEl.textContent = pct + '%';
    });

    xhr.addEventListener('load', function(){
        clearInterval(msgTimer);
        fillEl.style.width  = '100%';
        labelEl.textContent = '100%';
        statusEl.textContent = 'Upload complete!';
        try {
            const res = JSON.parse(xhr.responseText);
            if(xhr.status === 200 && res.success){
                // The paper is filed; the local draft has done its job.
                papelDraft.beginSubmit();
                papelDraft.clear();
                if (pill) pill.leave();
                setTimeout(function() {
                    window.location.href = res.redirect || 'student_dashboard.php';
                }, 600);
            } else {
                papelAlert('❌ Upload failed: ' + (res.message || 'Unknown error'));
                if (pill) pill.done();
                overlay.classList.remove('active');
                papelPillRecentre();
                btn.disabled = false;
                fillEl.style.width = '0%';
            }
        } catch(err) {
            papelAlert('❌ Server error: ' + xhr.responseText.substring(0, 100));
            if (pill) pill.done();
            overlay.classList.remove('active');
            papelPillRecentre();
            btn.disabled = false;
            fillEl.style.width = '0%';
        }
    });

    xhr.addEventListener('error', function(){
        clearInterval(msgTimer);
        papelAlert('❌ Network error');
        if (pill) pill.done();
        overlay.classList.remove('active');
        papelPillRecentre();
        btn.disabled = false;
        fillEl.style.width = '0%';
    });

    xhr.open('POST', 'student_upload_ai.php');
    xhr.send(fd);
});

// Chatbot Logic
function toggleChat() {
    const win = document.getElementById('chat-window');
    const btn = document.getElementById('chat-button');

    if (win.style.display === 'flex') {
        win.style.display = 'none';
        btn.style.display = 'flex';
        applyChatDock(false, currentChatSide());   // release the page padding
    } else {
        win.style.display = 'flex';
        btn.style.display = 'none';
        setPuppyState('welcome');
        document.getElementById('chat-input').focus();
    }
}

/* The panel can float in the corner or dock to either edge of the screen,
   the way tool panels move around in an IDE. The choice is remembered. */
// The PDF preview owns the right edge while it is open; two panels stacked
// there would sit on top of each other.
function pdfPreviewOpen() {
    const p = document.getElementById('pdf-preview');
    return !!p && !p.hidden;
}

// Greys out the chat's "move to the other side" control while the right edge
// is taken, so the restriction is visible rather than a click that does nothing.
function syncChatSideAvailability() {
    const sideBtn = document.getElementById('chatSideBtn');
    if (!sideBtn) return;
    const blocked = pdfPreviewOpen();
    sideBtn.disabled = blocked;
    sideBtn.classList.toggle('is-blocked', blocked);
    if (blocked) {
        sideBtn.title = 'The right side is in use by the PDF preview';
        sideBtn.setAttribute('aria-label', 'The right side is in use by the PDF preview');
    }
}

function applyChatDock(docked, side) {
    const widget   = document.getElementById('chat-widget');
    const dockBtn  = document.getElementById('chatDockBtn');
    const dockIcon = document.getElementById('chatDockIcon');
    const sideBtn  = document.getElementById('chatSideBtn');
    const sideIcon = document.getElementById('chatSideIcon');
    if (!widget) return;

    side = (side === 'right') ? 'right' : 'left';
    // Anything asking for the right while the preview holds it goes left.
    if (side === 'right' && pdfPreviewOpen()) side = 'left';
    widget.classList.toggle('is-docked', docked);
    widget.classList.toggle('dock-left',  docked && side === 'left');
    widget.classList.toggle('dock-right', docked && side === 'right');

    // Push the page aside so the panel sits beside the content, not over it.
    document.body.classList.toggle('chat-docked-left',  docked && side === 'left');
    document.body.classList.toggle('chat-docked-right', docked && side === 'right');

    // Docking implies the panel is open — otherwise the floating button is
    // hidden while the window is still closed and nothing is visible at all.
    if (docked) {
        const win = document.getElementById('chat-window');
        const btn = document.getElementById('chat-button');
        if (win) win.style.display = 'flex';
        if (btn) btn.style.display = 'none';
    }

    if (dockIcon) dockIcon.textContent = docked ? 'picture_in_picture' : 'dock_to_left';
    if (dockBtn) {
        const l = docked ? 'Return to floating window' : 'Dock to side panel';
        dockBtn.title = l; dockBtn.setAttribute('aria-label', l);
    }
    if (sideIcon) sideIcon.textContent = side === 'left' ? 'dock_to_right' : 'dock_to_left';
    if (sideBtn) {
        const l = side === 'left' ? 'Move to right side' : 'Move to left side';
        sideBtn.title = l; sideBtn.setAttribute('aria-label', l);
    }
    syncChatSideAvailability();

    // Only the preferred side is remembered; the panel always starts closed
    // so it never takes over the page on load.
    try { localStorage.setItem('papel_chat_side', side); } catch (err) {}
}

function currentChatSide() {
    const widget = document.getElementById('chat-widget');
    return widget && widget.classList.contains('dock-right') ? 'right' : 'left';
}

function toggleChatDock() {
    const widget = document.getElementById('chat-widget');
    applyChatDock(!widget.classList.contains('is-docked'), currentChatSide());
}

function toggleChatSide() {
    applyChatDock(true, currentChatSide() === 'left' ? 'right' : 'left');
}

function restoreChatDock() {
    let side = 'left';
    try { side = localStorage.getItem('papel_chat_side') || 'left'; } catch (err) {}
    // Start floating and closed; only the side preference carries over.
    applyChatDock(false, side);
}

document.getElementById('chat-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendMessage();
});

/* ----- PUPPY's moods -----
   Two models, four moods. The model select picks the colour (1 Aincrad is the
   maroon dog, 2 Alfheim the yellow) and the conversation picks the mood, so the
   face is rebuilt whenever either changes. All eight are preloaded: swapping
   src on a cold cache leaves a blank frame mid-conversation, which reads as the
   widget breaking rather than as the dog thinking. */
const PUPPY_BASE    = <?= json_encode(BASE_URL . '/assests/images/', JSON_UNESCAPED_SLASHES) ?>;
const PUPPY_COLOURS = { '1': 'red', '2': 'yellow' };
const PUPPY_STATES  = ['welcome', 'processing', 'successful', 'alert'];

Object.keys(PUPPY_COLOURS).forEach(function (k) {
    PUPPY_STATES.forEach(function (st) {
        new Image().src = PUPPY_BASE + 'papel_' + PUPPY_COLOURS[k] + '_' + st + '.gif';
    });
});

let puppyRevertTimer = null;

function puppyColour() {
    const sel = document.getElementById('chatModelSelect');
    return PUPPY_COLOURS[sel ? sel.value : '1'] || 'red';
}

function setPuppyState(state) {
    if (PUPPY_STATES.indexOf(state) === -1) state = 'welcome';
    const src = PUPPY_BASE + 'papel_' + puppyColour() + '_' + state + '.gif';
    ['puppyHeader', 'puppyFab'].forEach(function (id) {
        const img = document.getElementById(id);
        if (!img) return;
        // Re-assigning an identical src does not restart a GIF, so only write on
        // a real change - otherwise the reaction plays once and never again.
        if (img.getAttribute('src') !== src) img.setAttribute('src', src);
    });
}

/* The model often answers in a few hundred milliseconds, which flashes
   "processing" past too fast to register and makes the reply feel canned rather
   than worked out. The answer is held until the thinking animation has had its
   moment; an answer that was already slower than this waits no longer. */
const PUPPY_MIN_THINK = 1500;

function puppyFinishThinking(since) {
    const left = PUPPY_MIN_THINK - (Date.now() - since);
    return left > 0 ? new Promise(function (r) { setTimeout(r, left); }) : Promise.resolve();
}

/* The reactions are momentary: they play, then the dog settles back to waiting.
   Without this the avatar sits on "successful" until the next message. */
function flashPuppyState(state, ms) {
    clearTimeout(puppyRevertTimer);
    setPuppyState(state);
    puppyRevertTimer = setTimeout(function () { setPuppyState('welcome'); }, ms || 2600);
}

async function sendMessage() {
    const input = document.getElementById('chat-input');
    const msg = input.value.trim();
    if (!msg) return;

    addMessage(msg, 'user');
    input.value = '';

    clearTimeout(puppyRevertTimer);          // a reaction still counting down must not
    setPuppyState('processing');             // overwrite the face mid-request
    const thinkingSince = Date.now();
    try {
        const modelChoice = document.getElementById('chatModelSelect').value;
        const res = await fetch('student_chatbot.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: msg, model_choice: modelChoice})
        });
        const data = await res.json();
        await puppyFinishThinking(thinkingSince);
        // A 200 carrying no reply is still a failure to the student, so the dog
        // reacts to what actually came back rather than to the status code.
        if (data && data.reply) {
            addMessage(data.reply, 'bot');
            flashPuppyState('successful', 3200);
        } else {
            addMessage('Error processing request', 'bot', 'alert');
            flashPuppyState('alert', 4000);
        }
    } catch (e) {
        console.error('Chatbot Error:', e);
        await puppyFinishThinking(thinkingSince);
        addMessage('Connection error', 'bot', 'alert');
        flashPuppyState('alert', 4000);
    }
}

/* Switching model mid-conversation would otherwise leave the transcript in the
   colour each line was written in, which reads as two different dogs answering. */
function repaintMessageAvatars() {
    document.querySelectorAll('.msg-avatar').forEach(function (img) {
        img.src = PUPPY_BASE + 'papel_' + puppyColour() + '_' + (img.dataset.mood || 'welcome') + '.gif';
    });
}

function addMessage(text, sender, mood) {
    const div = document.createElement('div');
    div.className = `message ${sender}`;
    div.id = 'msg-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
    
    // Escape HTML to prevent XSS
    let safeText = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    
    // 1. Remove escaped asterisks (sometimes AI outputs \*\*text\*\*)
    safeText = safeText.replace(/\\\*/g, '*');
    
    // 2. Headers (### text)
    safeText = safeText.replace(/^#{1,3}\s+(.*)$/gm, '<strong>$1</strong>');
    
    // 3. Bullet points (lines starting with -, *, or •) - processed BEFORE bolding
    safeText = safeText.replace(/^[\s]*[-*•][\s]+(.*)$/gm, '&bull; $1');
    
    // 4. Bold with double asterisks or underscores (**text** or __text__)
    safeText = safeText.replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
    safeText = safeText.replace(/__([\s\S]+?)__/g, '<strong>$1</strong>');
    
    // 5. Emphasis with single asterisk (*text*)
    safeText = safeText.replace(/\*([^\*\n]+)\*/g, '<strong>$1</strong>');
    
    // 6. Numbered lists (e.g., "1. Requirement")
    safeText = safeText.replace(/^[\s]*(\d+\.)[\s]+(.*)$/gm, '<strong>$1</strong> $2');
    
    // 7. Newlines to line breaks
    safeText = safeText.replace(/\n/g, '<br>');
    
    div.innerHTML = safeText;
    const container = document.getElementById('chat-messages');
    if (sender === 'bot') {
        /* The face is the dog's resting one on a normal answer and the worried
           one on a failure, so scrolling back shows which replies went wrong
           without having to re-read them. */
        const row = document.createElement('div');
        row.className = 'msg-row';
        const face = document.createElement('img');
        face.className = 'msg-avatar';
        face.alt = 'PUPPY';
        face.dataset.mood = mood || 'welcome';
        face.src = PUPPY_BASE + 'papel_' + puppyColour() + '_' + face.dataset.mood + '.gif';
        row.appendChild(face);
        row.appendChild(div);
        container.appendChild(row);
    } else {
        container.appendChild(div);
    }
    container.scrollTop = container.scrollHeight;
    return div.id;
}

// Wire up all inline-handler replacements
document.addEventListener('DOMContentLoaded', function() {
    // Status checkboxes
    document.getElementById('status_published').addEventListener('change', function() { handleStatusChange(this); });
    document.getElementById('status_unpublished').addEventListener('change', function() { handleStatusChange(this); });

    // Section editors must exist before anything tries to fill or read them.
    papelDoc.initAll();

    // Step navigation buttons (data-goto-step attribute)
    document.querySelectorAll('[data-goto-step]').forEach(function(btn) {
        btn.addEventListener('click', function() { goToStep(parseInt(this.getAttribute('data-goto-step'), 10)); });
    });

    // The explanation given on arriving at step 2, brought back on demand.
    const whyBtn = document.getElementById('btnWhySections');
    if (whyBtn) whyBtn.addEventListener('click', function () { explainSections(true); });

    // Manual entry button
    document.getElementById('btnManual').addEventListener('click', function() {
        var pdfFile = document.getElementById('pdfFile').files[0];
        if (!pdfFile) {
            papelAlert('Please select a PDF file first before entering details manually.');
            return;
        }
        // Clear all fields for fresh manual entry
        document.getElementById('titleField').value = '';
        document.getElementById('yearField').value = new Date().getFullYear();
        document.getElementById('authorsField').value = '';
        document.getElementById('keywordsField').value = '';
        papelDoc.clearAll();
        document.getElementById('aiSummaryField').value = '';
        document.getElementById('aiMethodologyField').value = '';
        document.getElementById('aiStatsField').value = '';
        document.getElementById('aiVariablesField').value = '';
        document.getElementById('aiSampleSizeField').value = '';
        document.getElementById('aiResearchFieldField').value = '';
        // The extraction notice does not describe what is on screen once the
        // student switches to manual entry.
        var extractMsg = document.getElementById('extractMsg');
        if (extractMsg) extractMsg.remove();
        // Update header to show Manual mode
        document.getElementById('metadataBadge').className = 'step-badge';
        document.getElementById('metadataBadge').textContent = 'Manual Entry';
        // Show the fields and Next button
        document.getElementById('metadataFields').style.display = 'block';
        document.getElementById('btnNextToSupporting').style.display = 'block';
        document.getElementById('metadataFields').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // Step 3 follows whatever paper type Step 1 is set to.
    const paperTypeSelect = document.querySelector('select[name="paper_type"]');
    if (paperTypeSelect) paperTypeSelect.addEventListener('change', syncSupportingDocs);
    syncSupportingDocs();

    /* ----- Draft saving -----
       Everything typed is kept on this device as it is entered, so a stray
       click or a closed tab cannot cost a half-written paper. */
    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        ['input', 'change'].forEach(function (evt) {
            uploadForm.addEventListener(evt, function () { papelDraft.saveSoon(); });
        });
        // The rich-text editors are contenteditable, so they do not raise the
        // form's own input event on every keystroke.
        document.querySelectorAll('.doc-surface').forEach(function (surface) {
            surface.addEventListener('input', function () { papelDraft.saveSoon(); });
        });
    }

    /* Closing or reloading the tab. The save happens either way — the prompt
       is the browser's own and only asks whether to stay; it cannot be relied
       on to run anything afterwards, so the write comes first. */
    window.addEventListener('beforeunload', function (e) {
        if (!CAN_DRAFT) return;
        if (papelDraft.isSubmitting()) return;
        if (!papelDraft.dirty()) return;
        papelDraft.save();
        e.preventDefault();
        e.returnValue = '';     // required for the browser to show its prompt
    });

    /* Leaving by a link on the page. Here we can ask properly, in the site's
       own dialog, instead of the browser's generic one. */
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        if (!CAN_DRAFT) return;
        if (papelDraft.isSubmitting() || !papelDraft.dirty()) return;

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (link.target === '_blank' || link.hasAttribute('download')) return;

        e.preventDefault();
        papelDraft.save();
        window.papelConfirm(
            'Save this paper as a draft before you go? It will appear under Drafts on '
            + 'your dashboard, ready to finish later.',
            { title: 'Leave this page?', icon: 'save',
              confirmText: 'Save draft and leave', cancelText: 'Stay here',
              altText: 'Discard this draft and leave' }
        ).then(async function (answer) {
            if (!answer) return;                       // stayed here

            /* Leaving without saving has to mean it, on both counts: no draft
               row is written, and the copy this page keeps on the device goes
               too. Leaving that copy behind made the next visit open with
               "Continue your draft?", which is the opposite of what was just
               asked for, and left the choice looking as though it had been
               ignored. */
            if (answer === 'alt') {
                papelDraft.clear();
            } else {
                await papelDraft.saveToServer();
            }
            papelDraft.beginSubmit();
            window.location.href = link.href;
        });
    });

    // Absent for staff, who have no drafts; the wiring below already guards on it.
    const saveDraftBtn = document.getElementById('btnSaveDraft');
    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', async function () {
            saveDraftBtn.disabled = true;
            const original = saveDraftBtn.textContent;
            saveDraftBtn.textContent = 'Saving...';
            papelDraft.save();
            const id = await papelDraft.saveToServer();
            saveDraftBtn.textContent = original;
            saveDraftBtn.disabled = false;
            if (id) {
                await window.papelAlert('Saved. You will find this under Drafts on your dashboard, '
                    + 'and you can carry on from here whenever you like.', { title: 'Draft saved' });
            } else {
                await window.papelAlert('The draft could not be saved to your dashboard just now. '
                    + 'Your work is still kept on this device.', { tone: 'error' });
            }
        });
    }

    /* ----- The browser's Back button -----
       Back is a navigation the page can actually intervene in, unlike closing
       the tab: an extra history entry is pushed on arrival, so pressing Back
       lands here first and we can ask in the site's own dialog rather than
       leaving silently. Pressing Back again from the answer is what really
       leaves.

       (Closing or reloading the tab is a different matter — browsers show
       their own fixed wording there and ignore any text a page supplies, so
       that one cannot be replaced, only triggered.) */
    let backGuardArmed = false;

    function armBackGuard() {
        if (backGuardArmed || !window.history || !history.pushState) return;
        history.pushState({ papelUploadGuard: true }, '', window.location.href);
        backGuardArmed = true;
    }

    window.addEventListener('popstate', function () {
        backGuardArmed = false;

        // Nothing worth keeping, or already on the way out: let Back do its job.
        if (!CAN_DRAFT) return;
        if (papelDraft.isSubmitting() || !papelDraft.dirty()) return;

        // Stay put while the question is on screen.
        armBackGuard();

        window.papelConfirm(
            'Save this paper as a draft before going back? It will appear under Drafts '
            + 'on your dashboard, ready to finish later.',
            { title: 'Leave this page?', icon: 'save',
              confirmText: 'Save draft and leave', cancelText: 'Stay here',
              altText: 'Discard this draft and leave' }
        ).then(async function (answer) {
            if (!answer) return;                       // stayed here
            // Discards the device copy as well; see the note in the link guard.
            if (answer === 'alt') {
                papelDraft.clear();
            } else {
                await papelDraft.saveToServer();
            }
            papelDraft.beginSubmit();

            // Somewhere definite rather than history arithmetic: the guard
            // entries make a plain history.back() land back on this page.
            const from = document.referrer;
            const sameSite = from && from.indexOf(window.location.origin) === 0
                             && from.indexOf('student_upload_ai.php') === -1;
            window.location.href = sameSite ? from : 'student_dashboard.php';
        });
    });

    armBackGuard();

    // Offer any earlier draft back, once the page has settled.
    if (CAN_DRAFT) setTimeout(function () { papelDraft.offerRestore(); }, 400);

    /* The progress indicator doubles as navigation. Going back is always free;
       going forward runs the same checks the Next button does, one step at a
       time, so a click cannot skip past a step that is not finished. */
    document.querySelectorAll('.upload-steps .step-item').forEach(function (item) {
        const target = parseInt(item.getAttribute('data-step'), 10);
        if (!target) return;
        item.setAttribute('role', 'button');
        item.setAttribute('tabindex', '0');

        function go() {
            if (target === currentStep) return;
            if (target < currentStep) { goToStep(target); return; }
            // Forward: walk up one step at a time so each gate is honoured.
            let guard = 8;
            while (currentStep < target && guard-- > 0) {
                const before = currentStep;
                goToStep(currentStep + 1);
                if (currentStep === before) return;   // a check stopped us
            }
        }
        item.addEventListener('click', go);
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
        });
    });

    // File indicators
    document.getElementById('pdfFile').addEventListener('change', function() { updatePdfIndicator(this); });

    // Clicking the chosen file opens it in the side panel.
    document.getElementById('pdf-indicator').addEventListener('click', function () {
        if (!this.classList.contains('selected')) return;
        papelPdfPreview.toggle();
    });
    document.getElementById('pdfPreviewClose').addEventListener('click', function () { papelPdfPreview.close(); });
    document.getElementById('pdfPreviewMin').addEventListener('click', function () { papelPdfPreview.minimise(); });
    document.getElementById('pdf-restore').addEventListener('click', function () { papelPdfPreview.open(); });
    document.getElementById('pdfPreviewFull').addEventListener('click', function () { papelPdfPreview.popOut(); });
    document.getElementById('pdfZoomIn').addEventListener('click', function () { papelPdfPreview.zoomIn(); });
    document.getElementById('pdfZoomOut').addEventListener('click', function () { papelPdfPreview.zoomOut(); });
    document.getElementById('pdfZoomLevel').addEventListener('click', function () { papelPdfPreview.resetZoom(); });
    document.getElementById('pdfPanMode').addEventListener('click', function () { papelPdfPreview.toggleMode(); });
    document.getElementById('ethics_clearance').addEventListener('change', function() { updateFileIndicator(this, 'ethics-indicator'); });
    document.getElementById('consent_form').addEventListener('change', function() { updateFileIndicator(this, 'consent-indicator'); });
    document.getElementById('data_collection').addEventListener('change', function() { updateFileIndicator(this, 'data-indicator'); });
    document.getElementById('copyright_doc').addEventListener('change', function() { updateFileIndicator(this, 'copyright-indicator'); });
    document.getElementById('other_doc').addEventListener('change', function() { updateFileIndicator(this, 'other-indicator'); });
    // Chatbot toggle buttons
    document.getElementById('chatCloseBtn').addEventListener('click', toggleChat);
    document.getElementById('chatDockBtn').addEventListener('click', toggleChatDock);
    document.getElementById('chatSideBtn').addEventListener('click', toggleChatSide);
    restoreChatDock();
    // Escape undocks before it closes the panel
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        /* A dialog sits above every one of these, so it takes Escape before any
           of them. This used to be checked below, after the PDF panel had
           already acted and returned, so dismissing a message also closed the
           preview behind it and shifted the page as the layout reflowed. */
        const openDialog = document.getElementById('papelDialog');
        if (openDialog && openDialog.classList.contains('open')) return;

        // The PDF preview is the most recently opened surface, so Escape
        // belongs to it first. It steps back rather than closing outright:
        // drag mode returns to selecting text, and only then does a second
        // Escape close the panel — so the key never strands the reader in a
        // mode they cannot get out of without reaching for the toolbar.
        const pdfPanel = document.getElementById('pdf-preview');
        if (pdfPanel && !pdfPanel.hidden) {
            e.preventDefault();
            if (papelPdfPreview.getMode() === 'pan') {
                papelPdfPreview.setMode('select');
                papelPdfPreview.hint('Select mode — drag across the text to copy it');
            } else {
                papelPdfPreview.close();
            }
            return;
        }
        const widget = document.getElementById('chat-widget');
        if (widget && widget.classList.contains('is-docked')) {
            e.preventDefault();
            applyChatDock(false, currentChatSide());
        }
    });
    document.getElementById('chat-button').addEventListener('click', toggleChat);

    // Colour follows the model, so the pick is visible on the dog itself.
    const puppyModelSel = document.getElementById('chatModelSelect');
    if (puppyModelSel) puppyModelSel.addEventListener('change', function () {
        setPuppyState('welcome');
        repaintMessageAvatars();
    });

    // Chat form submit
    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });
});

// Load publication location suggestions via AJAX
document.addEventListener('DOMContentLoaded', () => {
    fetch('student_upload_ai.php?action=get_locations')
        .then(r => r.json())
        .then(res => {
            if(res.success && res.data){
                const dl = document.getElementById('pub_locations_list');
                res.data.forEach(loc => {
                    const opt = document.createElement('option');
                    opt.value = loc;
                    dl.appendChild(opt);
                });
            }
        });
});
</script>

<!-- Preview of the PDF the student has chosen, rendered from the file in the
     browser's memory. Nothing here has been sent to the server yet. -->
<div id="pdf-preview" aria-label="PDF preview" hidden>
  <div id="pdf-preview-head">
    <span class="material-symbols-outlined mi-20">picture_as_pdf</span>
    <span id="pdf-preview-name">Selected PDF</span>
    <div class="pdf-preview-tools">
      <button type="button" id="pdfPanMode" title="Drag to move the page" aria-label="Drag to move the page" aria-pressed="false">
        <span class="material-symbols-outlined mi-20">pan_tool</span>
      </button>
      <button type="button" id="pdfZoomOut" title="Zoom out (Ctrl + mouse wheel)" aria-label="Zoom out">
        <span class="material-symbols-outlined mi-20">zoom_out</span>
      </button>
      <button type="button" id="pdfZoomLevel" title="Reset to fit width" aria-label="Reset zoom to fit width">100%</button>
      <button type="button" id="pdfZoomIn" title="Zoom in (Ctrl + mouse wheel)" aria-label="Zoom in">
        <span class="material-symbols-outlined mi-20">zoom_in</span>
      </button>
      <button type="button" id="pdfPreviewFull" title="Open in a new tab" aria-label="Open in a new tab">
        <span class="material-symbols-outlined mi-20">open_in_full</span>
      </button>
      <button type="button" id="pdfPreviewMin" title="Minimise (reopen from the tab on the right)" aria-label="Minimise preview">
        <span class="material-symbols-outlined mi-20">remove</span>
      </button>
      <button type="button" id="pdfPreviewClose" title="Close preview" aria-label="Close preview">
        <span class="material-symbols-outlined mi-20">close</span>
      </button>
    </div>
  </div>
  <div id="pdf-preview-body" class="papel-pdf-stage">
    <div id="pdf-preview-scroll" class="papel-pdf-scroll" tabindex="0"></div>
    <div id="pdf-preview-status"></div>
  </div>
</div>

<!-- Brings the preview back after it has been minimised or closed. Pinned to
     the right edge on every step, so the paper is never more than one click
     away — including from Steps 3 and 4, long after the file was chosen. -->
<button type="button" id="pdf-restore" hidden title="Show the PDF preview" aria-label="Show the PDF preview">
  <span class="material-symbols-outlined mi-20">picture_as_pdf</span>
  <span class="pdf-restore-label">PDF</span>
</button>

<script src="<?= BASE_URL ?>/assests/js/pdfjs/pdf.min.js"></script>
<script src="<?= BASE_URL ?>/assests/js/papel-pdf-view.js"></script>

<!-- Site dialog, used in place of the browser's alert box -->
<div class="papel-dialog-backdrop" id="papelDialog" role="dialog" aria-modal="true" aria-labelledby="papelDialogTitle">
  <div class="papel-dialog">
    <div class="papel-dialog-head">
      <span class="material-symbols-outlined" id="papelDialogIcon">info</span>
      <h2 id="papelDialogTitle">Notice</h2>
      <!-- Only appears when a caller offers a third answer, so every other
           dialog keeps a header with nothing in the corner. -->
      <button type="button" class="papel-dialog-x" id="papelDialogAlt" hidden
              aria-label="Leave without saving" title="Leave without saving">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="papel-dialog-body" id="papelDialogMessage"></div>
    <div class="papel-dialog-body papel-dialog-input" id="papelDialogInputWrap" hidden>
      <input type="text" class="form-control" id="papelDialogInput" autocomplete="off">
    </div>
    <div class="papel-dialog-foot">
      <button type="button" class="btn btn-outline-secondary" id="papelDialogCancel" hidden>Cancel</button>
      <button type="button" class="btn btn-primary" id="papelDialogOk">OK</button>
    </div>
  </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Replaces window.alert on this page so every message uses the site's own
   dialog. Kept API-compatible (papelAlert(message) -> Promise) so the
   existing call sites only needed their function name swapped. */
(function () {
    var backdrop = document.getElementById('papelDialog');
    var dialogEl = backdrop.querySelector('.papel-dialog');
    var msgEl    = document.getElementById('papelDialogMessage');
    var titleEl  = document.getElementById('papelDialogTitle');
    var iconEl   = document.getElementById('papelDialogIcon');
    var okBtn    = document.getElementById('papelDialogOk');
    var cancelBtn = document.getElementById('papelDialogCancel');
    var altBtn    = document.getElementById('papelDialogAlt');
    var inputWrap = document.getElementById('papelDialogInputWrap');
    var inputEl   = document.getElementById('papelDialogInput');
    var lastFocus = null;
    /* Where the page was when the dialog opened. Restoring focus on close
       scrolls to whatever is being focused, which is fine when it is on screen
       and jarring when it is not: closing this from a box near the bottom of
       the page used to jump more than a thousand pixels back up. */
    var lastScrollY = 0;
    var resolver = null;
    var isPrompt = false;
    var isConfirm = false;

    function close(value) {
        backdrop.classList.remove('open');
        dialogEl.classList.remove('is-wide');
        document.body.style.overflow = '';
        inputWrap.hidden = true;
        cancelBtn.hidden = true;
        altBtn.hidden = true;
        // preventScroll, then the recorded position: the first stops focus from
        // dragging the page to the restored element, the second undoes anything
        // that moved while the dialog was up.
        if (lastFocus && lastFocus.focus) {
            try { lastFocus.focus({ preventScroll: true }); }
            catch (err) { lastFocus.focus(); }
        }
        if (Math.abs(window.scrollY - lastScrollY) > 1) {
            window.scrollTo({ top: lastScrollY, behavior: 'auto' });
        }
        if (resolver) { var r = resolver; resolver = null; r(value); }
        isPrompt = false;
        isConfirm = false;
        okBtn.textContent = 'OK';
        cancelBtn.textContent = 'Cancel';
    }

    window.papelAlert = function (message, opts) {
        opts = opts || {};
        // Legacy call sites prefixed failures with a cross; map that to tone.
        var text = String(message == null ? '' : message);
        var isError = opts.tone === 'error' || text.indexOf('❌') === 0;
        text = text.replace(/^❌\s*/, '');

        /* opts.html is for markup written on this page and nowhere else. It is
           assigned rather than escaped, so a caller must never hand it anything
           that came from a person: everything else here goes through
           textContent precisely so that a message cannot become markup. */
        if (opts.html) { msgEl.innerHTML = opts.html; }
        else { msgEl.textContent = text; }
        // Long messages get the roomier box; everything else keeps the narrow
        // one, which is what makes a one-line notice read as a one-line notice.
        dialogEl.classList.toggle('is-wide', !!opts.wide);
        titleEl.textContent = opts.title || (isError ? 'Something went wrong' : 'Notice');
        iconEl.textContent = opts.icon || (isError ? 'error' : 'info');

        lastFocus = document.activeElement;
        lastScrollY = window.scrollY;
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
        okBtn.focus();
        return new Promise(function (resolve) { resolver = resolve; });
    };

    /* Same dialog, asking for a value instead of just announcing one. Resolves
       with the typed string, or null when cancelled — so callers can tell
       "cleared the field" apart from "changed their mind". */
    window.papelPrompt = function (message, opts) {
        opts = opts || {};
        msgEl.textContent = String(message == null ? '' : message);
        titleEl.textContent = opts.title || 'Enter a value';
        iconEl.textContent = opts.icon || 'edit';

        inputEl.value = opts.value || '';
        inputEl.placeholder = opts.placeholder || '';
        inputWrap.hidden = false;
        cancelBtn.hidden = false;
        isPrompt = true;

        lastFocus = document.activeElement;
        lastScrollY = window.scrollY;
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
        inputEl.focus();
        inputEl.select();
        return new Promise(function (resolve) { resolver = resolve; });
    };

    /* A question with two answers. Resolves true for the confirming button and
       false for the other, so callers read as `if (await papelConfirm(...))`. */
    window.papelConfirm = function (message, opts) {
        opts = opts || {};
        msgEl.textContent = String(message == null ? '' : message);
        titleEl.textContent = opts.title || 'Are you sure?';
        iconEl.textContent = opts.icon || 'help';

        okBtn.textContent = opts.confirmText || 'OK';
        cancelBtn.textContent = opts.cancelText || 'Cancel';
        cancelBtn.hidden = false;

        /* A third answer, for questions that have one, shown as an X in the
           header. Resolves the string 'alt' rather than a boolean so a caller
           cannot mistake it for yes: `if (leave)` would otherwise treat it as
           the confirming button. The text becomes the tooltip and the label a
           screen reader announces, since the button itself is only an icon. */
        if (opts.altText) {
            altBtn.setAttribute('aria-label', opts.altText);
            altBtn.setAttribute('title', opts.altText);
            altBtn.hidden = false;
        }
        inputWrap.hidden = true;
        isPrompt = false;
        isConfirm = true;

        lastFocus = document.activeElement;
        lastScrollY = window.scrollY;
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
        okBtn.focus();
        return new Promise(function (resolve) { resolver = resolve; });
    };

    okBtn.addEventListener('click', function () {
        close(isPrompt ? inputEl.value.trim() : (isConfirm ? true : undefined));
    });
    cancelBtn.addEventListener('click', function () { close(isConfirm ? false : null); });
    altBtn.addEventListener('click', function () { close('alt'); });
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) close(isConfirm ? false : (isPrompt ? null : undefined));
    });
    document.addEventListener('keydown', function (e) {
        if (!backdrop.classList.contains('open')) return;
        if (e.key === 'Escape') { e.preventDefault(); close(isConfirm ? false : (isPrompt ? null : undefined)); }
        else if (e.key === 'Enter') { e.preventDefault(); close(isPrompt ? inputEl.value.trim() : (isConfirm ? true : undefined)); }
    });
})();
</script>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>