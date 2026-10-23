<?php
error_reporting(0);
ini_set('display_errors', '0');
require_once '../../config/core.php';
/* Anyone who writes a paper, which is everyone.

   Staff upload their own work here too, and their submissions are published the
   moment they are made: they are the people who would otherwise be reviewing
   it, so there is nobody above them in the chain to ask.

   Drafts remain student-only, and deliberately. Every page that manages a draft
   is student-only (the Drafts tab on the student dashboard,
   student_draft_delete.php, student_cancel_submission.php) so a draft saved by
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
       system already knows: get_program_folder() files it under FACULTY, and
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
    } catch (Throwable $e) { error_log('Location fetch error: ' . $e->getMessage()); echo json_encode(['success' => false, 'error' => 'Failed to load locations.']); }
    exit;
}

// AI extraction step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'extract_ai') {
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json');
  /* The Groq call is given 30 seconds of its own (CURLOPT_TIMEOUT in
     config/groq_config.php), and a server whose max_execution_time is 30 kills
     the script while curl is still waiting: the request dies with no answer
     and no log line of its own. Reading a thesis takes longer than a page
     does, so this one handler is allowed the time its own timeout implies. */
  if (function_exists('set_time_limit')) { @set_time_limit(120); }
  try {
    csrf_verify();
    
    // ── Rate Limiting: Max 10 AI extractions per hour per user ──
    // TEMPORARILY DISABLED for testing: re-enable before production
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
    /* Not the student's pick any anymore: whichever engine the session is on,
       with the other behind it for this call. If it had to hand over, the
       session follows, so the next extraction starts on the one with room. */
    $extracted = extract_metadata_with_groq($pdfText, (string)ai_engine_current());
    if (function_exists('groq_used_fallback') && groq_used_fallback()) {
        ai_engine_switch();
    }
    if (is_array($extracted)) {
        $aiMeta = $extracted;
    } else {
        // A rate limit is not a problem with the student's paper, and telling
        // them to "try again" without saying why sends them round in circles.
        $failure = function_exists('groq_last_failure') ? groq_last_failure() : null;
        $code = (int)($failure['http_code'] ?? 0);
        if ($code === 429) {
            throw new UserFacingException(
                'The AI has reached its usage limit for the moment. '
                . 'Wait a minute and press Extract again, or choose "Fill Manually" to type '
                . 'the details in yourself. Your paper is fine; this is a service limit.'
            );
        }
        if ($code >= 500) {
            throw new UserFacingException('The AI service is temporarily unavailable. Please try again shortly, or choose "Fill Manually".');
        }
        throw new UserFacingException('The AI could not identify the details in this document. You can choose "Fill Manually" to enter them yourself.');
    }
    
    /* The abstract is the only section taken from the paper, and it is copied
       verbatim rather than generated. The student writes the Introduction,
       Methodology and Results and Discussion themselves. */
    $result = build_metadata_result($aiMeta, (string)($aiMeta['abstract'] ?? ''), (string)$u['full_name']);
    
    /* Similarity detection used to run here, comparing this abstract against
       the last fifty approved ones through the AI, one request per batch of
       ten. It has been removed: a repository records work that has already
       been reviewed and approved, and it holds no rule about two papers
       resembling each other, so the figure was informational only and
       nothing acted on it. Extraction is correspondingly quicker. */

    echo json_encode(['success'=>true,'data'=>$result]); 
    exit;
    
  } catch (Throwable $e) {
    http_response_code(500);
    // Messages we wrote for the student are shown as written; anything else
    // stays generic so internal detail never reaches the browser. Either way
    // the full exception is logged against a reference.
    $shown = ($e instanceof UserFacingException)
        ? $e->getMessage()
        : 'AI extraction failed. Please try again, or choose "Fill Manually".';
    $safe = safe_error_message($e, $shown);
    echo json_encode(['success' => false] + $safe);   // message, reference, and detail on a copy of the site
    exit;
  }
}


/* ---------------------------------------------------------------------------
   Save (or update) a draft.

   Everything here tolerates blanks: a draft is unfinished by definition. The
   only hard requirement is that the row belongs to the signed-in student and
   is still a draft, so this can never overwrite a submitted paper.

   No Google Drive call, that belongs to submission. A draft must save even
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
    /* Which sections the author has chosen to submit, once they have chosen.
       Nothing chosen yet is left unrecorded rather than guessed at, so the page
       asks when the draft is next opened. Everything typed is kept whatever the
       choice: narrowing it and widening it again must not lose a section the
       student had already written. The choice is only enforced on submit. */
    $scope = section_scope_parse($_POST['section_scope'] ?? null);
    if ($scope !== null) { $sections[PAPER_SCOPE_KEY] = $scope; }
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

    /* A draft is what was typed, and no file. A page cannot be handed a chosen
       file back (the restore prompt says so plainly), so the PDF this used to
       keep on the server was written on every save and never read again. With a
       paper that has an IMRAD and a Full Manuscript it would have been two
       uploads of up to the file limit each, on every save. The PDFs stay in the
       browser until the paper is submitted, and nothing here touches Google
       Drive: a file reaches Drive only when the form is submitted. */

    /* Only ever update this student's own unsubmitted draft. A draft that has
       since been submitted or deleted is no longer somewhere to save into:
       the id is dropped and the work is kept as a new draft instead, because
       refusing the save outright would lose it. */
    if ($draftId > 0) {
      $own = $conn->prepare("SELECT paper_id FROM research_papers WHERE paper_id=? AND uploaded_by=? AND current_status='draft' LIMIT 1");
      $own->bind_param('ii', $draftId, $u['user_id']);
      $own->execute();
      $row = $own->get_result()->fetch_assoc();
      $own->close();
      if (!$row) $draftId = 0;
    }

    if ($draftId > 0) {
      /* file_path is left out on purpose: a draft that was a submitted paper
         once (sent back, or withdrawn) keeps the record of the file it was
         submitted with until it is submitted again. */
      $sql = "UPDATE research_papers SET title=?, author_names=?, year=?, research_date=?, abstract=?,
              imrad_content=?, keywords=?, paper_type=?, research_type=?, manuscript_type=?,
              publication_status=?, publication_location=?, program_category=?
              WHERE paper_id=? AND uploaded_by=? AND current_status='draft'";
      $st = $conn->prepare($sql);
      $st->bind_param('ssissssssssssii',
        $title, $authors, $year, $researchDateParam, $abstract, $imradContent, $keywords,
        $paperType, $researchTy, $manuscript, $pubStatus, $pubLocation, $program,
        $draftId, $u['user_id']);
      $st->execute();
    } else {
      // file_path is NOT NULL with no default, and a draft has no file.
      $sql = "INSERT INTO research_papers
              (title, author_names, year, research_date, abstract, imrad_content, keywords,
               file_path, uploaded_by, current_status, paper_type, research_type, manuscript_type,
               publication_status, publication_location, program_category)
              VALUES (?,?,?,?,?,?,?,'',?,'draft',?,?,?,?,?,?)";
      $st = $conn->prepare($sql);
      $st->bind_param('ssissssissssss',
        $title, $authors, $year, $researchDateParam, $abstract, $imradContent, $keywords,
        $u['user_id'], $paperType, $researchTy, $manuscript,
        $pubStatus, $pubLocation, $program);
      $st->execute();
      $draftId = $conn->insert_id;
    }

    echo json_encode(['success' => true, 'draft_id' => $draftId]);
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    $shown = ($e instanceof UserFacingException)
      ? $e->getMessage()
      : 'The draft could not be saved.';
    $safe = safe_error_message($e, $shown);
    echo json_encode(['success' => false] + $safe);   // message, reference, and detail on a copy of the site
    exit;
  }
}


/* AJAX: metadata, where this server cannot reach the model itself.
 *
 * On hosting that forbids outbound calls the browser does that part: it reads
 * the PDF with the copy of PDF.js the page already loads, sends the text to
 * the proxy in AI_PROXY_URL, and posts the fields back here. This endpoint
 * makes no outbound call of its own. It exists so the abstract is still lifted
 * by the same tested pattern matching as the ordinary path, and so the answer
 * the page receives has exactly one shape whichever route it came by.
 *
 * The text is accepted on trust as text, which is all it is: it is read for an
 * abstract and never stored, executed or echoed.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'extract_from_text') {
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json');
  try {
    csrf_verify();
    if (AI_PROXY_URL === '') {
      throw new UserFacingException('This server reads papers itself; it has no metadata proxy configured.');
    }

    $text = (string)($_POST['text'] ?? '');
    if (strlen($text) < 50) {
      throw new UserFacingException('No readable text could be found in this PDF. It may be a scan of a printed page, password-protected, or empty. Choose "Fill Manually" to enter the details yourself.');
    }

    /* What the proxy returned, re-checked here rather than trusted: it arrives
       by way of the browser, so it is treated as input like any other. */
    $aiMeta = json_decode((string)($_POST['ai'] ?? '[]'), true);
    if (!is_array($aiMeta)) { $aiMeta = []; }

    $abstract = extract_abstract_from_text($text);
    $result = build_metadata_result($aiMeta, (string)$abstract, (string)$u['full_name']);

    echo json_encode(['success' => true, 'data' => $result]);
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    $shown = ($e instanceof UserFacingException)
        ? $e->getMessage()
        : 'The details could not be read. Please try again, or choose "Fill Manually".';
    $safe = safe_error_message($e, $shown);
    echo json_encode(['success' => false] + $safe);   // message, reference, and detail on a copy of the site
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
 * and deleting a draft from the dashboard never touched it, so the prompt kept
 * coming back for work the student had already thrown away, and answering
 * Continue saved it again as a brand new draft. The page now knows which ids
 * are real, so a local copy pointing at a draft that is gone can be recognised
 * as stale and dropped. */
$liveDrafts = [];
$lq = db()->prepare(
  "SELECT paper_id, title FROM research_papers WHERE uploaded_by=? AND current_status='draft'");
$lq->bind_param('i', $u['user_id']);
$lq->execute();
foreach ($lq->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
  $liveDrafts[] = ['id' => (int)$r['paper_id'], 'title' => (string)$r['title']];
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
  /* The choice of sections goes back to the page as a field of its own, named
     like the input that carries it ("section_scope"), which is how everything
     else here is restored. The reserved key inside the stored object is not a
     field of the form, so it is taken out before the rest is merged in. */
  $draftScope = paper_section_scope($draftRow['imrad_content'] ?? null);
  unset($sections[PAPER_SCOPE_KEY]);
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
      'section_scope'        => $draftScope ? implode(',', $draftScope) : '',
    ], $sections),
    'status' => $draftRow['publication_status'],
  ];
}

// Final submission step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_paper') {
  /* A submission is all or nothing. Everything this request makes is kept where
     the catch below can reach it, so that if anything fails once the first file
     has reached Google Drive, the Drive files are deleted again, the staging
     copies removed and the database rolled back. A student who tries again is
     then not met by a half-made paper, or by files on Drive that nothing points
     to. A paper handed in both ways has two files to take back, which is the
     reason this is here. */
  $driveMade   = [];     // Drive file ids made by this request
  $stagedFiles = [];     // staging copies that may still be on disk
  $stagingDirs = [];     // staging folders, removed if they are left empty
  $inTx        = false;

  /* Filing a paper is about a dozen calls to Google Drive in a row (a folder
     looked up or made at each of five levels, then the file, then who may read
     it), and each one is a round trip. On a slow connection that runs past the
     60 seconds a shared host allows a script, and the host then kills it in the
     middle of the Drive work and answers with a page of its own: no paper, files
     left on Drive, and nothing the student can read. The upload gets the time its
     own steps need. The host may cap this; where it does, nothing is worse off. */
  if (function_exists('set_time_limit')) { @set_time_limit(180); }

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

    /* The format or formats the paper is handed in as come first, because there
       is one PDF to find for each of them and none for a format that was not
       ticked. The browser will not leave step 1 without one, but the browser is
       not the thing being trusted. */
    $manuscriptType = manuscript_types_posted($_POST['manuscript_type'] ?? []);
    if ($manuscriptType === '') {
        throw new UserFacingException('Please choose at least one manuscript type.');
    }
    $formats = paper_declared_formats($manuscriptType);
    $dual    = count($formats) > 1;
    if ($dual && !paper_manuscripts_ready()) {
        throw new UserFacingException(
            'Handing a paper in both ways needs a system update that has not been installed on this server yet. '
          . 'Tick one manuscript type for now, or ask your administrator to run the update.');
    }

    // One PDF for each format that was ticked.
    $pdfs = [];
    foreach ($formats as $fmt) {
        $label = manuscript_format_label($fmt);
        $f = $_FILES['pdf_' . $fmt] ?? null;
        // A page opened before this update still posts its one file as research_pdf.
        if ((!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
                && !$dual && !empty($_FILES['research_pdf'])) {
            $f = $_FILES['research_pdf'];
        }
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new UserFacingException('Please attach your ' . $label . ' PDF.');
        }
        try {
            validate_pdf_upload($f);
        } catch (Exception $bad) {
            throw new UserFacingException('Your ' . $label . ' PDF cannot be used: ' . $bad->getMessage());
        }
        $pdfs[$fmt] = $f;
    }
    // A file for a format that was not ticked is refused, not quietly stored.
    foreach (['imrad', 'full'] as $fmt) {
        $f = $_FILES['pdf_' . $fmt] ?? null;
        if (!in_array($fmt, $formats, true) && $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            throw new UserFacingException(
                'A ' . manuscript_format_label($fmt) . ' PDF was attached, but that manuscript type is not ticked. '
              . 'Tick it in step 1, or remove the file.');
        }
    }
    
    /* The program decides which folder the paper is filed under and how it is
       counted in every report, so it has to be a real answer. If the form did
       not carry one, the student's own program stands in, but filing it as
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
        throw new UserFacingException('Please enter a valid date of completion.');
    }
    $ry = (int)$rd->format('Y');
    if ($ry < 1900 || $ry > 2100) {
        throw new UserFacingException('The date of completion must fall between 1900 and 2100.');
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

    /* The sections the author chose to submit. A page opened before the choice
       existed posts none, and that keeps meaning "all of them". The browser is
       not what decides this: a section outside the choice is stored empty
       whatever was posted for it, and one inside it must be written, so what is
       filed always matches what the reviewers are told. */
    $scope = section_scope_resolve($_POST['section_scope'] ?? null);

    $sections = [];
    foreach ($sectionKeys as $key) {
        if (!in_array($key, $scope, true)) {
            $sections[$key] = '';
            continue;
        }
        $clean = rich_text_sanitize((string)($_POST[$key] ?? ''));
        /* A section of nothing but a table of results, or a photograph of the
           apparatus, is written. The editor has always taken that view, so the
           submit handler has to as well: measuring the plain text alone let a
           table-only section through every step and refused it at the last. */
        $hasFigure = (bool)preg_match('/<(img|table)\b/i', $clean);
        if (rich_text_to_plain($clean) === '' && !$hasFigure) {
            /* A plain Exception here was replaced by the generic line further
               down, so a student who got past the browser's own check was told
               only that the upload failed. This is written for them. */
            throw new UserFacingException('Please write the ' . $sectionLabels[$key] . ' section.');
        }
        $sections[$key] = $clean;
    }
    $sections[PAPER_SCOPE_KEY] = $scope;
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
        throw new UserFacingException('Please give at least 5 keywords, separated by commas.');
    }

    /* Papers live on Google Drive, so a server with no working connection to it
       cannot take one. That is a real possibility on a copy of the site: staging
       has its own database and its own token, written once by hand, and nothing
       sets it up when the code is deployed. It used to be found out only after
       the PDF had been moved and the folders made, and then reported with the
       same generic line as any other fault, so the cause was a server log nobody
       could open. It is asked first now, before anything is stored. */
    if (function_exists('upload_paper_to_gdrive') && function_exists('is_gdrive_connected') && !is_gdrive_connected()) {
        error_log('Submission refused: Google Drive is not connected on this server.');
        // The Research Coordinator and the Director can both connect it (pages/gdrive_callback.php).
        throw new UserFacingException('Papers cannot be stored on this server yet, because Google Drive has not been '
            . 'connected to it. Nothing was submitted. Please let your Research Coordinator or the Director know, '
            . 'then try again. Either of them can connect it.');
    }

    /* And the folder papers are filed under, which is a separate thing from the
       connection and fails separately. Staging was connected, and still refused
       every paper, because its folder setting named a folder in a Google account
       the connected one cannot see: Drive answered 404 "File not found" on the
       very last step, after the PDF had been moved, and the student read "Upload
       failed". Only a definite answer stops a submission. If Drive simply cannot
       be asked just now, the upload goes on and says what really went wrong. */
    if (function_exists('upload_paper_to_gdrive') && function_exists('gdrive_check_folder')) {
        $folderCheck = gdrive_check_folder(get_gdrive_parent_folder_id());
        if (gdrive_folder_is_unusable($folderCheck)) {
            error_log('Submission refused: the Google Drive storage folder ' . $folderCheck['id']
                . ' cannot be used (' . $folderCheck['state'] . ').');
            throw new UserFacingException(gdrive_folder_explain($folderCheck, 'student'));
        }
    }

    // 2. Duplicate Title Check
    // $dupCheck = $conn->prepare("SELECT paper_id FROM research_papers WHERE title = ? AND current_status != 'declined' LIMIT 1");
    // $dupCheck->bind_param('s', $title);
    // $dupCheck->execute();
    // if ($dupCheck->get_result()->num_rows> 0) {
    //     throw new Exception("A paper with this title already exists.");
    // }


    // 3. Each file is staged, then put on Google Drive in an organised structure.
    $y = date('Y'); $m = date('m');
    $unique = uniqid('paper_', true);
    $safeTitle = safe_name($title);
    if(strlen($safeTitle)> 30) $safeTitle = substr($safeTitle, 0, 30);
    $submissionFolder = "{$unique}_{$safeTitle}";

    $destDir = __DIR__ . "/uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder";
    ensure_dir($destDir);
    $stagingDirs[] = $destDir;

    $sourceCodePath = null;

    $primaryFormat = paper_primary_format($formats);
    $stored = [];    // format => where its file ended up: relative path, size, Drive id
    foreach ($formats as $fmt) {
      $pdf = $pdfs[$fmt];

      /* Two files can arrive with the same name, one chosen in each box, so with
         two the format goes in front of it. */
      $safeBase = safe_name(pathinfo($pdf['name'], PATHINFO_FILENAME));
      $destPath = $destDir . '/' . ($dual ? $fmt . '_' : '') . "{$safeBase}.pdf";

      if (!move_uploaded_file($pdf['tmp_name'], $destPath)) {
        throw new Exception('Could not move the uploaded PDF into ' . dirname($destPath)
            . ' (does the folder exist and can the web server write to it?)');
      }
      $stagedFiles[] = $destPath;

      /* Named for the format when there are two, or both would be "<title>.pdf"
         in the same Drive folder. A paper with one keeps the name it always had. */
      $driveName = $dual ? $title . ' (' . manuscript_format_label($fmt) . ').pdf' : $title . '.pdf';
      $gdriveId = null;
      // Note: The gdrive function receives the local path, not the URL path. This is correct.
      if (function_exists('upload_paper_to_gdrive')) {
        $gdriveId = upload_paper_to_gdrive($destPath, $driveName, $programFolder, (string)$year, $paperType, $title, 'PAPEL - DOCUMENTS', $u['user_id']);
      }
      if (!$gdriveId) {
        throw new Exception('Google Drive upload failed. Please check system configuration.');
      }
      $driveMade[] = $gdriveId;

      $stored[$fmt] = [
        'rel'   => "uploads/research/$programFolder/$paperType/$y/$m/$submissionFolder/" . basename($destPath),
        'size'  => filesize($destPath) ?: 0,
        'drive' => $gdriveId,
      ];

      /* Drive is where a submitted paper lives. The local file was only ever the
         staging copy the Drive upload reads from: keeping it meant every paper
         was stored twice, for good. It goes now that Drive has it (the upload
         throws above if it did not). A paper is read through the viewer, which
         opens it on Drive, so nothing needs the local file once it is up there. */
      @unlink($destPath);
    }

    /* Stored relative to app/student/, not as a full URL. The old absolute form
       baked this machine's BASE_URL into the row, so after the project moved to
       another computer (or was simply served on a different address) the file
       could not be found again, and nothing that needed the file rather than a
       link could resolve it at all. paper_file_url() still renders a correct
       link from a relative path, and paper_file_disk_path() reads either form.

       The paper's own file columns hold its primary manuscript, the full one
       when there are two, so everything that only needs "the paper's file" goes
       on working; paper_manuscripts below holds every file. */
    $primary     = $stored[$primaryFormat];
    $fullUrlPath = $primary['rel'];
    $sz          = $primary['size'];
    $gdriveId    = $primary['drive'];

    /* A submitted paper goes to the Research Adviser. It used to be filed as
       'draft' (the same state as work never sent) so pressing Submit put the
       paper in the student's Drafts tab and no reviewer ever saw it. Nothing
       later promoted it, which is why every review queue stayed empty. */
    $initialStatus = ($u['user_role'] === 'student') ? 'pending_faculty' : 'approved';

    /* Submitting from a draft updates that draft rather than adding a second
       copy of the paper. It matters most for a returned paper: keeping the same
       paper_id keeps the reviewer's feedback and the approval history attached
       to it, and leaves nothing behind in Drafts.

       What that draft still holds is noted before it is replaced. A paper that
       was withdrawn rather than returned comes back with its Drive files, which
       the new ones now stand in for; and a draft saved before drafts stopped
       keeping a PDF on this server may have one there. */
    $submitDraftId = (int)($_POST['draft_id'] ?? 0);
    $oldDriveIds   = [];
    $oldDraftPdf   = '';
    if ($submitDraftId > 0) {
        $dchk = $conn->prepare("SELECT paper_id, file_path FROM research_papers WHERE paper_id=? AND uploaded_by=? AND current_status='draft'");
        $dchk->bind_param('ii', $submitDraftId, $u['user_id']);
        $dchk->execute();
        $drow = $dchk->get_result()->fetch_assoc();
        $dchk->close();
        if ($drow) {
            $oldDraftPdf = (string)$drow['file_path'];
            $oldDriveIds = paper_drive_file_ids($submitDraftId);
        } else {
            $submitDraftId = 0;
        }
    }

    /* From the first row written to the last, one transaction: a failure part of
       the way through takes the paper back out instead of leaving it filed with
       some of its files. */
    $conn->begin_transaction();
    $inTx = true;

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
            if (!$stmt) throw new Exception('Database query preparation failed.');

            $stmt->bind_param('ssisssiisss', $title,$authors,$year,$abstract,$keywords,$fullUrlPath,$sz,$u['user_id'],$initialStatus,$paperType,$gdriveId);
            if (!$stmt->execute()) throw new Exception('Database insert failed.');
        } else {
            throw $e;
        }
    }

    $paper_id = $stmt->insert_id;

    /* The draft this submission grew out of becomes the submission itself, so
       the new row replaces it: the values are copied across, the draft's own
       row is removed, and its stale attachment records go with it: the files
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

    /* The paper's manuscript files, under whichever id the paper ended up with.
       A paper sent back or withdrawn still has the rows of what it was submitted
       with, so they are replaced, never added to. A server the migration has not
       reached has no such table, and the paper's own columns, which carry the one
       file such a server can hold, stand alone. */
    if (paper_manuscripts_ready()) {
        $dm = $conn->prepare("DELETE FROM paper_manuscripts WHERE paper_id = ?");
        $dm->bind_param('i', $paper_id);
        $dm->execute();
        $dm->close();

        $im = $conn->prepare("INSERT INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size) VALUES (?,?,?,?,?)");
        foreach ($stored as $fmt => $info) {
            $im->bind_param('isssi', $paper_id, $fmt, $info['rel'], $info['drive'], $info['size']);
            if (!$im->execute()) throw new Exception('Database insert failed.');
        }
        $im->close();
    }

    // Supporting docs
    $supDir = $destDir . "/supporting_documents";
    ensure_dir($supDir);
    $stagingDirs[] = $supDir;

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
            $stagedFiles[] = $dest;
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
            $driveMade[] = $docGdriveId;

            // document_type is a VARCHAR now, so 'copyright_doc' is kept as it is written.
            $st = $conn->prepare("INSERT INTO supporting_documents (paper_id,document_type,file_path,gdrive_file_id) VALUES (?,?,?,?)");
            $st->bind_param('isss', $paper_id, $docType, $fullUrlRel, $docGdriveId);
            if (!$st->execute()) throw new Exception('Database insert failed.');

            // Drive has it and the row is written, so the staging copy can go.
            if (is_file($dest)) { @unlink($dest); }
          }
        }
      }
    }

    // Handle optional other document
    if (!empty($_FILES['other_doc']) && $_FILES['other_doc']['error'] === UPLOAD_ERR_OK) {
      if ($finfo->file($_FILES['other_doc']['tmp_name']) === 'application/pdf') {
        $otherName = mb_substr(trim($_POST['other_doc_name'] ?? '') ?: 'Other Document', 0, 255);
        $otherType = safe_name($otherName);
        $safe = safe_name(pathinfo($_FILES['other_doc']['name'], PATHINFO_FILENAME));
        $dest = $supDir . "/{$paper_id}_{$otherType}_{$safe}.pdf";
        if (move_uploaded_file($_FILES['other_doc']['tmp_name'], $dest)) {
          $stagedFiles[] = $dest;
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
          $driveMade[] = $otherGdriveId;

          /* Kept as 'other', with the name the student typed beside it. The name
             used to be written into document_type, an ENUM with no such value,
             which stored an empty string and lost the name. */
          $st = $conn->prepare("INSERT INTO supporting_documents (paper_id,document_type,document_name,file_path,gdrive_file_id) VALUES (?,'other',?,?,?)");
          $st->bind_param('isss', $paper_id, $otherName, $fullUrlRel, $otherGdriveId);
          if (!$st->execute()) throw new Exception('Database insert failed.');

          // Drive has it and the row is written, so the staging copy can go.
          if (is_file($dest)) { @unlink($dest); }
        }
      }
    }

    $conn->commit();
    $inTx = false;

    /* The paper is filed, so what is on Drive and on disk is the paper's now and
       no longer this request's to take back. */
    $driveMade   = [];
    $stagedFiles = [];

    /* From here on the paper is filed and the transaction is closed, so nothing
       below can undo it, and nothing below may report it as a failed upload.
       It used to: a step after the commit that threw (the notification row,
       for one) fell into the catch at the foot of this handler, which answered
       "Upload failed" for a paper that was in fact filed. The student then tried
       again and filed it twice. These steps tidy up and tell people; a failure in
       one is logged against a reference, and the student is told what happened,
       which is that their paper was submitted. */
    try {
        /* What the new files replaced can go. A paper that was withdrawn, not
           returned, comes back to draft with its Drive files intact and has just been
           filed again with new ones; without this the old ones stayed on Drive for
           good, reachable by nobody. A returned paper has nothing to find here, its
           files were purged when it was returned. Best effort: delete_from_gdrive()
           never throws. */
        foreach ($oldDriveIds as $oldId) {
            delete_from_gdrive($oldId);
        }
        // And the copy an older draft kept on this server, but only from this student's own draft folder.
        if ($oldDraftPdf !== '') {
            $draftBase = realpath(__DIR__ . '/uploads/drafts/' . (int)$u['user_id']);
            $oldReal   = realpath(__DIR__ . '/' . $oldDraftPdf);
            if ($draftBase !== false && $oldReal !== false
                    && strpos($oldReal, $draftBase . DIRECTORY_SEPARATOR) === 0 && is_file($oldReal)) {
                @unlink($oldReal);
            }
        }

        /* The folders held nothing but those staging files. rmdir only removes an
           empty directory, so anything still in there is left exactly as it is. */
        foreach (array_reverse($stagingDirs) as $dir) {
            if (is_dir($dir)) { @rmdir($dir); }
        }

        /* Tell the Research Adviser there is something waiting. The adviser is the
           faculty member who created this student's account, which is the same link
           their review queue is built on, so if there is no creator, nobody would
           ever see the paper, and that is worth recording rather than passing over
           in silence. */
        if ($u['user_role'] === 'student') {
            $adviserId = creator_of($u['user_id']);
            if ($adviserId) {
                /* Said in the notice itself, because the adviser opens this to decide
                   what to do with the paper, and a paper filed with sections left out
                   on purpose should not be read as one that is missing them. Nothing
                   is added for a complete paper. */
                $scopeNote = paper_scope_notice(paper_scope_info($imradContent), 'brief');
                create_notification($adviserId, $paper_id, 'submission',
                    $u['full_name'] . ' submitted "' . $title . '" for your review.'
                    . ($scopeNote !== '' ? ' ' . $scopeNote : ''));
            } else {
                error_log('Paper ' . $paper_id . ' submitted by user ' . $u['user_id']
                        . ' who has no Research Adviser (users.created_by is empty), so it will not appear in any review queue.');
            }
        }
    } catch (Throwable $late) {
        safe_error_message($late, 'after the paper was filed');   // logs it, with a reference
    }

    $redirectUrl = role_home($u['user_role']);
    echo json_encode(['success'=>true,'message'=>'Upload complete','paper_id'=>$paper_id, 'redirect'=>$redirectUrl]);
    exit;
    
  } catch (Throwable $e) {
    if ($inTx) { $conn->rollback(); }
    /* Taken back, as the note at the top says: nothing this request put on Drive
       or on disk is left behind, and the paper's own row went with the rollback.
       Both are best effort, and neither throws. */
    foreach ($driveMade as $made)     { delete_from_gdrive($made); }
    foreach ($stagedFiles as $staged) { if (is_file($staged)) { @unlink($staged); } }
    foreach (array_reverse($stagingDirs) as $dir) { if (is_dir($dir)) { @rmdir($dir); } }

    /* The refusals written for the student to read ("Please attach your IMRAD
       PDF", "Your Full Manuscript PDF cannot be used: ...") go through as they
       are, as the extraction and draft handlers above already do. This handler
       used to put the generic line over them as well, so a corrupt or missing
       file was reported as a fault on our side. Anything else stays generic. */
    $userFacing = ($e instanceof UserFacingException);
    http_response_code($userFacing ? 422 : 500);
    $safe = safe_error_message($e, $userFacing ? $e->getMessage() : 'Upload failed. Please try again.');
    echo json_encode(['success' => false] + $safe);   // message, reference, and detail on a copy of the site
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

/* ── Papel Upload Overlay: clear blurred backdrop ── */
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

/* The loader: the PAPEL logo animation, the one the logo pill plays
   (includes/loading_bar.php), standing in the card. The overlay is this page's
   loader, so the page does not also ask for the pill while it is up: that would
   be the same animation on screen twice.

   The GIF is not transparent. It carries a flat off-white ground of its own,
   so it sits on a plate of that same colour. On the white card the plate melts
   away; in dark mode, where the card is not white, the logo keeps a light
   ground to read on instead of showing as a pale rectangle with square
   corners. This is the one colour on the page that does not follow the theme,
   because it is not ours to change: it is baked into the image. */
.loading-logo {
    margin-bottom: 1.4rem;
    padding: 6px 20px;
    border-radius: 999px;
    background: #FDFCFC;
    overflow: hidden;       /* the image is a rectangle; the plate is a pill */
}
.loading-logo img {
    display: block;
    width: min(190px, 52vw);
    height: auto;
}
/* The dark theme dims every picture on the site by 8% (theme.php). Here that
   turns the GIF's ground a shade darker than its plate and brings the square
   back. The id is for the specificity: the theme's rule outranks a class. */
#uploadOverlay .loading-logo img { filter: none; }

/* A GIF cannot be paused from CSS, so for anyone who has asked for less motion
   (the system setting, or the site's own "Stop animations") the logo is left
   out, as the pill leaves it out, and the progress bar carries the message. */
@media (prefers-reduced-motion: reduce) {
    .loading-logo { display: none; }
}
body.a11y-stop-animations .loading-logo { display: none; }

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
     of placing fixes that, so it scrolls inside itself instead. */
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
   piece of fixed furniture by construction (the site header and the PDF
   preview are 900, the chat 1000 and the accessibility widget higher still) so
   it can never cover any of them. The left and right edges follow the same dock
   widths the panels use, so an expanded box stops where a docked panel starts. */
.doc-editor.is-expanded {
  position: fixed;
  /* The navbar's own bottom edge, which pdf_dock.php keeps on <html> as the bar's
     height changes. This was a fixed 60px against a bar of 37px, and the 23px
     between them showed the breadcrumb strip above the box as a gap. Flush with
     the bar there is nothing for a rounded corner or a shadow to fall on, so
     the box has neither. */
  top: var(--pdf-dock-top, 37px);
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 800;
  margin: 0;
  border-radius: 0;
  border-width: 1px 0 0 0;
  display: flex;
  flex-direction: column;
  background: var(--white);
}
.doc-editor.is-expanded .doc-editor-head { flex: 0 0 auto; }
.doc-editor.is-expanded .doc-surface {
  flex: 1 1 auto;
  min-height: 0;
  max-height: none;
}
body.pdf-docked .doc-editor.is-expanded { right: var(--pdf-dock-w, 460px); }
/* And while the box is up the panel takes the box's edges. The box runs from
   below the navbar to the bottom of the window; the panel otherwise stops at
   the breadcrumb strip and the footer, which left a sliver of the strip above
   it and the footer's links below it, in its column, with nothing over them. */
/* Only while a box really is expanded. Nothing collapses a box when the step
   changes, so the body class can outlive it, and keyed on the class alone this
   would pin the panel to a box's edges with no box there to line up with. */
/* !important: pdf_dock.php writes the panel's live top and bottom onto it inline. */
body.doc-expanded:has(.doc-editor.is-expanded) .pdf-dock { top: var(--pdf-dock-top, 37px) !important; bottom: 0 !important; }

/* While a section is expanded the chat and the accessibility button step out
   of the way: nothing is closed or reset, they are only hidden, so both come
   back exactly as they were, docked or floating, when the box is collapsed.
   The accessibility widget is moved to <html> by its own script, so the flag is
   set there as well as on <body>. The PDF preview deliberately stays. */
html.doc-expanded #a11y-widget,
body.doc-expanded #a11y-widget,
body.doc-expanded #chat-widget { display: none !important; }
/* Their reserved space goes with them, so the box gets the full width. The
   inset the navbar reads is released with it: see --chat-inset-* below. */
body.doc-expanded.chat-docked-left  { padding-left: 0;  --chat-inset-left: 0px; }
body.doc-expanded.chat-docked-right { padding-right: 0; --chat-inset-right: 0px; }
/* Holds the page's height so nothing jumps while a box is lifted out of flow. */
.doc-editor-spacer { margin-bottom: 1.25rem; }
.doc-expand { color: var(--grey); }
.doc-expand:hover { color: var(--maroon); }

/* Section tabs, along the bottom the way a spreadsheet names its sheets.
   They exist only while a section is expanded, because that is the only time
   the other four are out of sight, on the form itself they are all just
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
/* A section still to be written carries a dot: the form needs all five, and
   this is the only view where the empty ones are otherwise invisible. */
.doc-tab-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--maroon); opacity: .4; flex: 0 0 auto;
}
.doc-tab.is-active .doc-tab-dot { opacity: .7; }

@media (max-width: 700px) {
  .doc-tab { padding: .4rem .6rem; }
}

/* Section label, toolbar and word count share one row. Keeping them on
   separate lines cost a whole band of empty space above the tools. The label
   and the count both grow, so the toolbar settles in the middle of the row. */
/* One row, always. Rather than wrapping onto extra lines when the column is
   narrow, the tools that no longer fit move into a ⋮ menu (see reflowToolbar())
   so the header stays a single tidy strip at any width. */
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
   cells are deliberately left out of the alignment rule: a column of figures
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
   browsers produce a <div> rather than a <p> when Enter is pressed, without
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
  /* The site's scrollbar colours (includes/focus_ring.php), so the editor's
     own bar matches every other one on the page. */
  background: var(--scroll-thumb, var(--maroon-surface));
  border-radius: var(--r-control, 4px);
  border: 2px solid var(--cream);
}
.doc-surface::-webkit-scrollbar-thumb:hover { background: var(--scroll-thumb-strong, var(--maroon-surface-hover)); }

@media (max-width: 700px) {
  /* The header stays one row here too: the ⋮ menu absorbs whatever will not
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
}

/* Chatbot Styles - Enhanced - LEFT SIDE */

/* The two values the script keeps live on the chat as the page scrolls. Declared
   so that they are not inherited: a changed custom property is otherwise
   re-resolved on every descendant, which is the whole conversation here. No
   initial value, so the fallbacks written where they are read still apply. */
@property --chat-lift     { syntax: '*'; inherits: false; }
@property --chat-dock-top { syntax: '*'; inherits: false; }

/* Corpus floats over the page, never over the footer. He used to sit at 1000, in
   front of everything, so on a page short enough to show the footer (or scrolled
   to its end) he lay across its text. The footer is in front of him now (902,
   against his 901), and watchChatLift() raises him by as much of the footer as is
   showing, so he rests above it rather than being buried: until that catches up
   with a quick scroll, the footer simply passes over him. 901 keeps him above the
   PDF panel and the scroll-to-top button (900, 880) as before; the ladder of
   levels is in CLAUDE.md. */
.site-footer { position: relative; z-index: 902; }
#chat-widget {
    position: fixed;
    bottom: calc(1.5rem + var(--chat-lift, 0px));
    left: 1.5rem;
    z-index: 901;
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
    /* The ring was white to lift the avatar off a maroon bar. The bar is cream
       now and the artwork's own ground is cream too, so a white ring left the
       circle with no edge at all: this is the site's hairline instead. */
    border: 2px solid var(--border);
}
/* Smaller than the 56px button so the maroon shows as a ring around the dog. */
#puppyFab { width: 48px; height: 48px; }

/* ----- Corpus's greeting card -----
   One short line at a time rises out of the button (chatNudge, in the script),
   so the assistant is noticed without a student having to go looking for it.

   It is made of the pieces the site's other floating cards are made of, not of
   a speech bubble of its own: the white surface, hairline and --r-card corners
   of .tw-card and .help-card, and the padding and type of the chat's own
   .message bubble, so a greeting looks like the first line of the conversation
   it is inviting. A round bubble with a trail of dots was tried first and read
   as a sticker laid on a document. The corner scale is restrained on purpose
   (see --r-card in theme.php): a repository is read, not played with.

   Positioned against #chat-widget, which is fixed, so it follows the button
   wherever the widget sits and takes no room in the layout. Hidden is
   visibility as well as opacity, so a card that has gone cannot be clicked, and
   the departure holds visibility back until the fade has finished. */
.chat-nudge {
    position: absolute;
    left: 0;
    bottom: calc(100% + 12px);
    width: max-content;
    /* Never wider than the room there is on a phone. */
    max-width: min(17rem, calc(100vw - 3rem));
    /* The chat's own .message padding, even on both sides. The cross overlaps
       the corner instead of taking room inside it, so none is kept back for it,
       which would only show as a wider right margin whenever it is out of sight. */
    padding: .625rem .875rem;
    background: var(--white);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md);
    cursor: pointer;
    opacity: 0;
    visibility: hidden;
    transform: translateY(6px);
    transition: opacity .16s ease, transform .16s ease, visibility 0s linear .16s;
}
.chat-nudge.is-on {
    opacity: 1;
    visibility: visible;
    transform: none;
    transition: opacity .2s ease, transform .2s ease, visibility 0s;
}
/* It is a way in to the chat, so it says so the way the site's other
   clickable cards do, with the soft maroon hairline. */
.chat-nudge:hover,
.chat-nudge:hover::after { border-color: var(--soft-maroon); }

.chat-nudge-text {
    display: block;
    font-family: var(--font-body);
    font-size: .875rem;
    line-height: 1.5;
    color: var(--ink);
}

/* The notch that ties the card to the dog: a square turned on its corner. Its
   top half sits inside the card, on the same white, and covers that stretch of
   the card's own hairline, so only the two lower edges show. */
.chat-nudge::after {
    content: '';
    position: absolute;
    left: 21px;
    bottom: -6px;
    width: 10px;
    height: 10px;
    background: var(--white);
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    transform: rotate(45deg);
}

/* Ends the greeting for this page view.

   It is a way out, not part of the message, so it stays out of sight until the
   pointer is on the card. A button showing the whole time made a few words of
   greeting look like a dialog. It overlaps the top right corner instead of
   sitting inside it, so the line never has to wrap around it. Where nothing can
   hover (a phone) it stays shown, because a control that can never appear is no
   control.

   It straddles the card's edge, so it carries its own surface and hairline, or
   the card's border would run through the mark. A control, so --r-control and
   not a circle: round is for an avatar or a toggle knob (see theme.php). */
.chat-nudge-x {
    position: absolute;
    top: -10px;
    right: -10px;
    width: 22px;
    height: 22px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    box-shadow: var(--shadow-sm);
    color: var(--grey);
    cursor: pointer;
    opacity: 0;
    transition: opacity .15s ease, color .15s, border-color .15s;
}
.chat-nudge:hover .chat-nudge-x { opacity: 1; }
@media (hover: none) {
    .chat-nudge-x { opacity: 1; }
}
.chat-nudge-x:hover { color: var(--maroon); border-color: var(--soft-maroon); }
.chat-nudge-x .material-symbols-outlined { font-size: 14px; }

/* No slide: the card fades in and out and stays where it is. */
@media (prefers-reduced-motion: reduce) {
    .chat-nudge,
    .chat-nudge.is-on { transform: none; }
    .chat-nudge { transition: opacity .2s ease, visibility 0s linear .2s; }
    .chat-nudge.is-on { transition: opacity .2s ease, visibility 0s; }
}
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
    /* The same ground the conversation sits on, so the panel reads as one
       surface rather than as a coloured cap on a pale box. The hairline is
       doing the work the colour change used to do: without it the header and
       the first message run together, since they are now the same cream. */
    background: var(--cream);
    color: var(--maroon);
    border-bottom: 1px solid var(--border);
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
#chat-messages::-webkit-scrollbar-thumb { background: var(--scroll-thumb, var(--maroon-surface)); border-radius: var(--r-card, 8px); }
#chat-messages::-webkit-scrollbar-thumb:hover { background: var(--scroll-thumb-strong, var(--maroon-surface-hover)); }
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
/* A name with a line about it underneath is a shape the site already has: the
   avatar menu's .dd-name / .dd-role. These are those rules, so the assistant is
   introduced in the same voice as the person signed in. On a pale header the
   descriptor can take --grey exactly as .dd-role does, rather than the faded
   white it needed while the bar behind it was maroon. */
#chat-header h5 {
    margin: 0;
    font-family: var(--font-head);
    font-size: .9375rem;
    font-weight: 700;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* The subtitle itself, named as the direct child rather than as any p in the
   header. The hover card hanging below is also inside the header, and a plain
   `#chat-header p` carried this nowrap and ellipsis into it: the card's
   paragraphs were being cut off mid-sentence on one line, and an ID in the
   selector meant the card's own rules could not win them back. */
#chat-header .chat-identity > p {
    margin: 0;
    font-family: var(--font-body);
    font-size: .8125rem;
    font-weight: 400;
    color: var(--grey);
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#chat-header .material-symbols-outlined { font-size: 22px; flex-shrink: 0; }

/* The heading and subtitle are clipped to keep the header on two lines, so
   hovering (or keyboard-focusing) the block reveals both in full, and with them
   what the assistant is actually for. The card used to repeat the two lines
   above it and nothing else, which told a reader who hovered it nothing they
   could not already see. The card is anchored to the header rather than to the
   text column so it always spans the panel neatly, whether the chat is floating
   or docked to a side. */
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
/* The same pair again, on white this time, so it can take --grey as .dd-role
   does rather than fading itself. */
.chat-identity-tip .tip-name {
    display: block;
    font-family: var(--font-head);
    font-size: .9375rem;
    font-weight: 700;
    line-height: 1.3;
    color: var(--maroon);
    white-space: normal;
}
.chat-identity-tip .tip-desc {
    display: block;
    margin-top: .125rem;
    font-family: var(--font-body);
    font-size: .8125rem;
    line-height: 1.45;
    color: var(--grey);
    white-space: normal;
}
/* What it is for, under the name. Bootstrap gives a p a bottom margin and a ul
   two rems of indent, both of which look like a mistake in a card this size. */
.chat-identity-tip .tip-body {
    margin: .5rem 0 0;
    font-family: var(--font-body);
    font-size: .8125rem;
    line-height: 1.5;
    color: var(--ink);
    white-space: normal;
}
.chat-identity-tip .tip-list {
    margin: .375rem 0 0;
    padding-left: 1.05rem;
    font-family: var(--font-body);
    font-size: .8125rem;
    line-height: 1.5;
    color: var(--ink);
}
.chat-identity-tip .tip-list li {
    margin-top: .25rem;
    white-space: normal;
}
.chat-identity-tip .tip-list li::marker { color: var(--maroon); }
/* The last line says what it will not do, so it reads as an aside. */
.chat-identity-tip .tip-limit {
    margin-top: .5rem;
    padding-top: .5rem;
    border-top: 1px solid var(--border);
    color: var(--grey);
}

/* Drawn for a pale header now, so the white these carried would be invisible.
   The site's own .nav-icon-btn tints to --cream on hover, which is this
   header's own ground, so the chip is white here instead. */
#chatCloseBtn {
    background: none;
    border: none;
    color: var(--maroon);
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
#chatCloseBtn:hover { background: var(--white); color: var(--dark-maroon); }

/* Dock + move-side controls, mirroring the close button's treatment. */
#chatDockBtn,
#chatSideBtn {
    background: none;
    border: none;
    color: var(--maroon);
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
#chatSideBtn:hover { background: var(--white); color: var(--dark-maroon); }
/* Moving sides only makes sense once the panel is docked. */
#chat-widget:not(.is-docked) #chatSideBtn { display: none; }
/* The right edge is taken by the PDF preview. */
#chatSideBtn.is-blocked { opacity: .4; cursor: not-allowed; }

/* ===== Docked side panel =====
   The panel takes a column at the edge of the viewport and the page is
   padded by the same amount, so content slides over to make room rather
   than being covered.

   It starts below the whole top band rather than at the top of the screen.
   Docked to the full height it stood in front of the bar, so the logo, the nav
   links and the avatar menu were all pushed into the remaining width and the
   panel held the corner the site's identity lives in.

   The band is the navbar and the breadcrumb strip together: one campus photo
   runs behind both (.nav-photo in site_head.php is sized nav + crumb), so
   cutting into it anywhere leaves the photo starting partway across the window.
   Both heights are published by site_header.php, measured from the elements
   themselves, so this follows them when the bar wraps taller on a narrow window
   or grows as the web fonts arrive.

   The z-index goes under the header's 900 for the same reason: the notification
   and avatar menus drop out of the bar, and at 950 the panel covered them. */
#chat-widget.is-docked {
    position: fixed;
    /* placeChatDockTop() keeps --chat-dock-top on the band's live bottom edge;
       the sum is the resting value, and what is used if that never runs. */
    top: var(--chat-dock-top, calc(var(--nav-h, 56px) + var(--crumb-h, 36px)));
    /* The same measurement that lifts the floating chat (watchChatLift): once the
       footer is in view the panel stops at its top edge, with the footer running
       the full width in front of it, instead of running on beside the footer and
       leaving its message box down in the footer's row. 0 until then. */
    bottom: var(--chat-lift, 0px);
    left: auto;
    right: auto;
    width: var(--chat-dock-w, 380px);
    max-width: 100vw;
    z-index: 880;
    display: block;
}
#chat-widget.is-docked.dock-left  { left: 0; }
#chat-widget.is-docked.dock-right { right: 0; }

#chat-widget.is-docked #chat-window {
    width: 100%;
    height: 100%;
    max-height: none;
    margin: 0;
    /* Square: three of its four edges are the edge of the screen, and a
       rounded corner there shows the page through the gap. */
    border-radius: 0;
    border: none;
    box-shadow: var(--shadow-md);
}
#chat-widget.is-docked.dock-left  #chat-window { border-right: 1px solid var(--border); }
#chat-widget.is-docked.dock-right #chat-window { border-left: 1px solid var(--border); }
#chat-widget.is-docked #chat-button { display: none !important; }

/* The page gives way to the docked panel.

   The same figure is published as --chat-inset-*, because the navbar has to
   undo exactly what the padding does and there is no way to read a computed
   padding from CSS. Setting both together means anything that cancels the
   padding cancels the navbar's correction with it; miss one and the bar hangs
   off the side of the window. */
/* Placed, not animated. The padding used to ease in over .25s while the
   navbar's correction below applied at once, so the bar's width was being
   worked out from a figure still in motion: it jumped to 1822px wide with the
   logo 300px off the left edge and slid back over the next quarter second,
   every time the panel was docked. Both now land together on the same frame. */
body.chat-docked-left {
    padding-left: var(--chat-dock-w, 380px);
    --chat-inset-left: var(--chat-dock-w, 380px);
}
body.chat-docked-right {
    padding-right: var(--chat-dock-w, 380px);
    --chat-inset-right: var(--chat-dock-w, 380px);
}

/* The navbar and the breadcrumb strip run the full width of the window, over
   the panel rather than beside it: the panel now starts below both, so a band
   stopping at the panel's edge would leave an empty strip where the logo used
   to be, and the campus photo behind the band would start partway across.

   The footer is the third band and does the same: the panel is behind it, and
   ends at its top edge (see --chat-lift on the docked panel). Left to the
   body's padding, the footer started where the panel ended and the panel ran on
   down beside it, so the footer looked cut short with the chat's message box
   sitting in its row.

   Only the chat's inset is given back here. The PDF preview pads the right as
   well and sits between the navbar's bands and the footer, so all three are
   let out over it by its own rules, with the preview's further down (search
   "PDF preview"). */
body > .site-header,
body > .crumb-bar,
body > .site-footer {
    margin-left:  calc(-1 * var(--chat-inset-left, 0px));
    margin-right: calc(-1 * var(--chat-inset-right, 0px));
    width: calc(100% + var(--chat-inset-left, 0px) + var(--chat-inset-right, 0px));
}

/* Too narrow to share the screen: overlay instead of squeezing the form. */
@media (max-width: 900px) {
    #chat-widget.is-docked { width: 100vw; }
    body.chat-docked-left,
    body.chat-docked-right {
        padding-left: 0;
        padding-right: 0;
        --chat-inset-left: 0px;
        --chat-inset-right: 0px;
    }
}

/* Chat bubbles: same treatment as the Help Center's .chat-msg */
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

/* A reply is built into paragraphs and lists (see formatBotReply), so the
   spacing lives here: one gap between blocks, a smaller one between list
   items, and nothing above the first block or below the last, which would
   double the bubble's own padding. */
.message.bot { line-height: 1.55; }
.message.bot > * { margin: 0; }
.message.bot > * + * { margin-top: .6rem; }
.message.bot ul,
.message.bot ol { padding-left: 1.2rem; }
.message.bot li + li { margin-top: .3rem; }
.message.bot li > ul { margin: .3rem 0 0; padding-left: 1rem; list-style: circle; }
.message.bot li::marker { color: var(--maroon); }
.message.bot ol > li::marker { font-weight: 600; }
.message.bot strong { font-weight: 600; }
.message.bot .msg-head {
    font-family: var(--font-head);
    font-weight: 600;
    color: var(--maroon);
}
.message.bot code {
    font-size: .8125rem;
    background: var(--cream);
    padding: 0 .3rem;
    border-radius: 4px;
}

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

/* ----- "Text loading" -----
   An empty bubble, in the dog's own voice, holding the place the answer is
   about to take. The wait used to be reported only by the bar across the top
   of the window (loading_bar.php), which is far enough from the chat panel
   that it read as the upload form saving; the request that fetches a reply now
   opts out of that bar with papelQuiet and says so here instead. */
.message.bot.is-typing {
    display: flex;
    align-items: center;
    gap: .3rem;
    /* Matches the height of a one-line reply, so the bubble does not resize
       under the answer when the dots are swapped for it. */
    padding: .75rem .875rem;
}
.typing-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--maroon);
    animation: chatTyping 1.2s infinite ease-in-out;
}
.typing-dot:nth-child(2) { animation-delay: .18s; }
.typing-dot:nth-child(3) { animation-delay: .36s; }
@keyframes chatTyping {
    0%, 60%, 100% { opacity: .3; transform: translateY(0); }
    30%           { opacity: 1; transform: translateY(-3px); }
}
/* Stopped dots still have to show that something is coming, so they hold at a
   readable strength rather than at the faint end of the cycle. */
@media (prefers-reduced-motion: reduce) {
    .typing-dot { animation: none; opacity: .55; }
}

/* Icon animations */
@keyframes iconBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

/* The breadcrumb strip is site_head.php's .crumb-bar and nothing more.
   This page used to restate the whole block, from a time when the strip really
   was a flat maroon bar. The shared one has since become frosted glass over the
   campus photo that runs behind the navbar, and a local `background:
   var(--dark-maroon)` loading after it put that back to opaque: the photo was
   being fetched on this page and then hidden under it, so the upload page was
   the one place the band did not match. The other rules here were duplicates of
   the shared ones, character for character. */

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



/* ===== Step 3: supporting documents =====
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
/* Filled in by syncSupportingDocs(): the marker depends on the paper type. */
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

/* Checkboxes / radios: flat maroon fills, matching the sidebar filters
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

/* ===== Step 2: the drop target and the file, in one box =====
   The dashed border used to wrap the whole step, the drop target, the file row
   and both buttons, 325px of box, and promised a drop anywhere inside it that
   only the top strip honoured. It then moved onto the drop target alone, with
   the file row in a second box beside it. One choice does not need two
   borders, and this is the step that has to fit a laptop screen, so they are
   one box now: the drop target on the left, and on its right "No PDF
   selected". The line is thin and solid; the dashes said "drop here", and the
   icon and the words already do.

   Once a PDF is chosen the prompt has done its job and goes. The box shows the
   file, its name over its size, with a tick where the upload icon was, and
   nothing else.

   The invisible file input still covers the whole box, so a click anywhere in
   it opens the file browser (to replace the file) and a file can be dropped
   anywhere on it. The one exception is the file's own name and size, once a
   PDF is chosen: they sit above the input and are the switch for the preview
   panel, as the separate row was. With no file that row lets the click
   through, so it never stands between a student and the browser. */
.ai-extraction-zone { text-align: center; }

/* One box per manuscript format ticked in step 1: IMRAD, Full Manuscript, or
   both. The column is spelled out (minmax(0, 1fr)) because an implicit one
   sizes itself to its content, and a long file name then stretched the box
   past the edge of the card instead of being cut short. */
.pdf-slots { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
.pdf-slot { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
/* The display above would otherwise win over the hidden attribute. */
.pdf-slot[hidden] { display: none; }
/* A name, and the limit they share, only when there are two boxes to tell
   apart. One box needs neither. */
.pdf-slot-label {
    display: none;
    grid-column: 1 / -1;
    text-align: left;
    font-family: var(--font-head);
    font-size: .875rem;
    font-weight: 600;
    color: var(--maroon);
}
.pdf-slots-note {
    display: none;
    margin: 0;
    text-align: left;
    font-size: .8125rem;
    color: var(--grey);
}
.pdf-slots.is-multi .pdf-slot-label,
.pdf-slots.is-multi .pdf-slots-note { display: block; }

.upload-zone {
    /* Named because the narrow layout lines the file row up under the text. */
    --zone-icon: 2rem;
    --zone-gap: .875rem;
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--zone-gap);
    padding: .875rem 1.25rem;
    background: var(--cream);
    border: 1px solid var(--maroon);
    border-radius: var(--r-control, 4px);
    text-align: left;
    cursor: pointer;
    transition: background .2s, border-color .2s;
}

.upload-zone:hover {
    background: var(--white);
    border-color: var(--dark-maroon);
}
/* The real control is the file input, at opacity 0 across the whole box, so
   its focus ring is invisible with it: a keyboard user tabbed onto the drop
   target and saw nothing happen. The ring goes on the box instead. */
.upload-zone:has(.file-input:focus-visible) {
    outline: 2px solid var(--focus-ring, var(--maroon));
    outline-offset: 2px;
}
.upload-zone-icon {
    flex-shrink: 0;
    font-size: var(--zone-icon);
    color: var(--maroon);
}
.upload-zone-text { flex: 1 1 0; min-width: 0; }
.upload-zone h4 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--ink);
}
.upload-zone p {
    margin: .125rem 0 0;
    font-size: .8125rem;
    line-height: 1.4;
}

/* No file yet: "No PDF selected", at the right-hand end of the same box. Not a
   .file-indicator: that is the bordered row the supporting documents use, and
   this one has no box of its own to restyle. */
.pdf-indicator {
    flex: 0 1 auto;
    margin: 0 0 0 auto;
    max-width: 45%;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    column-gap: .375rem;
    padding: .25rem .5rem;
    border-radius: var(--r-control, 4px);
    font-size: .875rem;
    font-weight: 500;
    line-height: 1.35;
    color: var(--grey);
    white-space: nowrap;
    /* Nothing to press until a file is chosen, so until then a click here is
       the file browser's, from the input underneath. */
    pointer-events: none;
}

/* A file is chosen: the row is the file itself, its name over its size, in the
   place the prompt was (the prompt is hidden, and the tick has taken the icon's
   place: see updatePdfIndicator). It hugs its text, so the rest of the box stays
   the file browser's, and it sits above the invisible input as the switch for the
   preview panel. Nowrap, so a long name is cut short beside the tick instead of
   dropping onto a row of its own. */
.upload-zone.has-file { flex-wrap: nowrap; }
.upload-zone.has-file .upload-zone-text { display: none; }
.pdf-indicator.selected {
    position: relative;
    z-index: 1;
    pointer-events: auto;
    cursor: pointer;
    display: block;
    margin: 0;
    max-width: 100%;
    padding: 0;
    font-size: 1rem;
    font-weight: 400;
    text-align: left;
    color: var(--ink);
}
/* One line, cut with an ellipsis: the row may shrink below its own width (its
   min-width), and the name is a block that clips. The whole name is the row's
   tooltip. */
.pdf-indicator .pdf-indicator-name {
    display: block;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pdf-indicator.selected:hover .pdf-indicator-name { text-decoration: underline; }
.pdf-indicator .pdf-indicator-size {
    display: block;
    margin-top: .125rem;
    font-size: .8125rem;
    font-weight: 400;
    line-height: 1.4;
}

.ai-extract-button-container {
    margin-top: 1.25rem;
}
.ai-extract-question {
    margin: 0 0 .625rem;
    font-weight: 600;
}
/* The hint under the buttons is a p, and Bootstrap gives every p a 1rem
   bottom margin: 16px of nothing at the foot of the block. */
.ai-extract-button-container > :last-child { margin-bottom: 0; }
/* Step 2 only. Every step shares .section-header and its 2rem, and the other
   steps have the room; this one is the step that has to fit a laptop screen. */
#step2 .section-header { margin-bottom: 1.25rem; }

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
   1900px wide: width media queries never fire in that situation. Declaring the
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
    /* Too narrow for "No PDF selected" to sit beside the prompt: under the
       text, in the same box, lined up with it. A chosen file keeps its one row,
       beside the tick (.has-file stays nowrap). */
    .upload-zone { flex-wrap: wrap; row-gap: .375rem; }
    .pdf-indicator:not(.selected) {
        flex: 1 1 calc(100% - var(--zone-icon) - var(--zone-gap));
        margin: 0 0 0 calc(var(--zone-icon) + var(--zone-gap));
        max-width: none;
        justify-content: flex-start;
        padding: .125rem 0;
    }
    .supporting-docs-grid { grid-template-columns: 1fr; }
    /* Paired fields stack rather than squeezing into unusable columns. */
    .row > [class*="col-md-"] { flex: 0 0 100%; max-width: 100%; }
    .date-field .form-control { max-width: 100%; }
}

@container uploadcol (max-width: 540px) {
    /* The labels no longer fit side by side; the row scrolls rather than
       wrapping into a broken second line. `safe center` keeps it centred while
       it still fits and only falls back to starting at the left edge once it
       genuinely overflows: plain `center` would push the first step out of
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

    /* Same switch as the container query above, for a browser without them. */
    .upload-zone { flex-wrap: wrap; row-gap: .375rem; }
    .pdf-indicator:not(.selected) {
        flex: 1 1 calc(100% - var(--zone-icon) - var(--zone-gap));
        margin: 0 0 0 calc(var(--zone-icon) + var(--zone-gap));
        max-width: none;
        justify-content: flex-start;
        padding: .125rem 0;
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
/* ===== PDF preview =====
   The panel is includes/pdf_dock.php, the one the paper pages, the review
   desk and the public repository open papers in; it is included at the foot of
   this page. Only the way this page makes room for it lives here.

   That file makes room by moving a <main class="wrap">, which this page does
   not have: the form is a Bootstrap .container straight under <body>. So room
   is made the way the chat panel makes its own, by padding the body. The panel
   sits between the maroon bands, below the navbar and the breadcrumb strip and
   above the footer, so all three are let back out across the full width over
   and under it: the same correction the chat's inset gets above. Without it
   each band stops short at the panel's edge, and the campus photo shows bare
   in the corner over the panel where the strip should be. */
body.pdf-docked { padding-right: var(--pdf-dock-w); }
body.pdf-docked > .site-header,
body.pdf-docked > .crumb-bar {
    margin-right: calc(-1 * var(--pdf-dock-w));
    width: calc(100% + var(--chat-inset-left, 0px) + var(--pdf-dock-w));
}
body.pdf-docked > .site-footer {
    margin-right: calc(-1 * var(--pdf-dock-w));
    /* The chat's inset too, as the bars above: with the chat docked left as well
       the footer's left edge is let out by the rule above, and without this its
       right edge fell that far short of the window's. */
    width: calc(100% + var(--chat-inset-left, 0px) + var(--pdf-dock-w));
}

/* With the chat docked left and the preview right, the form is squeezed from
   both sides, so both panels narrow. The preview's width is set on the root,
   where includes/pdf_dock.php keeps it: set on <body>, as it was, it would
   outrank the width the reader drags the panel to, so the drag would do
   nothing but save this figure over their own, which every paper page shares.
   Below 901px the panel covers the page instead and keeps its full width. */
@media (min-width: 901px) and (max-width: 1500px) {
    :root:has(> body.pdf-docked.chat-docked-left) { --pdf-dock-w: 400px; }
    body.pdf-docked.chat-docked-left { --chat-dock-w: 330px; }
}
@media (min-width: 901px) and (max-width: 1250px) {
    :root:has(> body.pdf-docked.chat-docked-left) { --pdf-dock-w: 340px; }
    body.pdf-docked.chat-docked-left { --chat-dock-w: 300px; }
}

/* Too narrow to share the screen: the panel covers the page instead. The
   floating chat sits higher (1000) than the panel (900), so it would land on
   the pages; it steps aside while the panel is up and is back, as it was, the
   moment the panel closes. The page's own panel used to cover it. */
@media (max-width: 900px) {
    body.pdf-docked #chat-widget:not(.is-docked) { display: none; }
    body.pdf-docked { padding-right: 0; }
    body.pdf-docked > .site-header,
    body.pdf-docked > .crumb-bar {
        margin-right: 0;
        width: calc(100% + var(--chat-inset-left, 0px));
    }
    body.pdf-docked > .site-footer { margin-right: 0; width: auto; }
}

/* ===== Which sections to submit =====
   The question asked on arriving at Step 2. It is the same dialog as every other
   question on this page, so the head, the rule under it and the footer are the
   ones the rest already use; it is only a little wider than the narrow notices,
   so six rows and their hints sit on one line each. */
.papel-dialog.is-choose { max-width: 520px; }
.papel-dialog.is-choose .papel-dialog-body { padding: 1.125rem 1.5rem 0; }
.papel-dialog.is-choose .papel-dialog-foot { padding: 1.125rem 1.5rem 1.25rem; }
.scope-pick-lead { margin: 0 0 .875rem; color: var(--grey); font-size: .8125rem; line-height: 1.55; }
.scope-pick-presets { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .875rem; }
/* The quick choices and the Change button share one look: small, outlined, and
   filled when they describe what is currently ticked. */
.scope-preset {
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
.scope-preset:hover { background: var(--cream); border-color: var(--maroon); }
.scope-preset:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.scope-preset.is-on,
.scope-preset.is-on:hover { background: var(--maroon); border-color: var(--maroon); color: var(--white); }
.scope-pick-list { border: 0; margin: 0; padding: 0; min-width: 0; }
.scope-opt {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin: 0 0 .375rem;
    padding: .5rem .75rem;
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    background: var(--white);
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.scope-opt:hover { border-color: var(--soft-maroon); background: var(--cream); }
.scope-opt.is-on { border-color: var(--maroon); }
.scope-opt.is-locked { cursor: default; background: var(--cream); }
.scope-opt input { flex: 0 0 auto; width: 1.0625rem; height: 1.0625rem; margin: 0; accent-color: var(--maroon); }
.scope-opt-name { font-weight: 500; color: var(--ink); }
.scope-opt-hint { margin-left: auto; padding-left: .5rem; font-size: .75rem; color: var(--grey); text-align: right; }
.scope-pick-sum { margin: .75rem 0 0; font-size: .8125rem; color: var(--maroon); }

/* The reminder at the top of Step 2: what was chosen, and the way to change it. */
.scope-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .625rem;
    margin: 0 0 1.25rem;
    padding: .625rem .875rem;
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    background: var(--cream);
    font-size: .8125rem;
    color: var(--ink);
}
.scope-bar .material-symbols-outlined { font-size: 18px; color: var(--maroon); flex: 0 0 auto; }
.scope-bar-text { flex: 1 1 auto; min-width: 0; }
.scope-bar-text strong { color: var(--maroon); font-weight: 600; }
/* A section the author chose not to submit is not asked for: it is hidden, not
   removed, so what was typed in it survives until the choice is widened again. */
.doc-editor.is-out-of-scope { display: none; }

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
    <!-- The PAPEL logo animation, the same file as the logo pill's (one download
         between them). Decorative: the text below it says what is happening. -->
    <div class="loading-logo" aria-hidden="true">
      <img src="<?= BASE_URL ?>/assests/images/Logo-Loading-PAPEL-badge.gif" alt="" width="300" height="99">
    </div>

    <!-- Text -->
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

                <?php /* Two to a row rather than one under the other: four full-width
                         groups put the Next button below the fold on a laptop, and
                         none of these four is wide enough to need the whole card.
                         The second row divides the same way as the first, so Paper
                         Status starts on the same edge as Paper / Research Type
                         above it. */ ?>
                <div class="row g-4">
                  <div class="col-md-6">
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
                               Manage Students console writes it: "BSIT: Bachelor
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

                  <div class="col-md-6">
                    <label class="form-label">Paper / Research Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="paper_type" required>
                      <option value="">Select paper type...</option>
                      <?php foreach (paper_types() as $ptCode => $ptLabel): ?>
                        <option value="<?= e($ptCode) ?>"><?= e($ptLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
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

                  <div class="col-md-6">
                      <div class="label-row">
                        <label class="form-label mb-0">Paper Status <span class="text-danger">*</span></label>
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
                    <span id="step2Title">Upload PDF &amp; Enter Details</span>
                  </h3>
                </div>

                <?php /* Which written sections this paper is submitted with. It is
                         asked on arriving here (askSectionScope() in the script
                         below) and kept in the hidden section_scope field, and this
                         bar is the way back to the question at any time. Reviewers
                         are told what was chosen, so changing it later is safe. */ ?>
                <div class="scope-bar" id="scopeBar">
                  <span class="material-symbols-outlined" aria-hidden="true">segment</span>
                  <span class="scope-bar-text">Sections you are submitting: <strong id="scopeBarValue">All sections</strong></span>
                  <button type="button" class="scope-preset" id="btnChangeScope">Change</button>
                </div>

                <div class="ai-extraction-zone">
                  <?php /* One box for each manuscript type ticked in step 1: a paper
                           handed in as IMRAD and as Full Manuscript is one paper with
                           two files, one of each. They are all here and hidden; the
                           script shows the ones that apply (syncPdfSlots), so a paper
                           handed in one way sees the single box it always has. They
                           are not "required" for the browser to enforce: the form is
                           novalidate, and a hidden required input could never be
                           satisfied. The step's own check and the server ask instead. */ ?>
                  <div class="pdf-slots" id="pdfSlots">
                    <?php foreach (['imrad' => 'IMRAD', 'full' => 'Full Manuscript'] as $slotKey => $slotLabel): ?>
                    <div class="pdf-slot" data-format="<?= e($slotKey) ?>" hidden>
                      <div class="pdf-slot-label"><?= e($slotLabel) ?> PDF</div>
                      <div class="upload-zone">
                        <span class="material-symbols-outlined upload-zone-icon" aria-hidden="true">upload_file</span>
                        <div class="upload-zone-text">
                          <h4>Drop your PDF here or click to browse</h4>
                          <p class="text-muted">Maximum file size: <?= e(max_upload_label()) ?></p>
                        </div>
                        <?php /* The state of the file shares the box with the drop target. It
                                 stays clear of the input's way until a file is chosen (see the
                                 Step 2 note in the stylesheet). */ ?>
                        <div class="pdf-indicator" id="pdf-indicator-<?= e($slotKey) ?>">
                          <span class="material-symbols-outlined mi-18 text-muted">cancel</span> No PDF selected
                        </div>
                        <input type="file" class="form-control file-input js-pdf" id="pdfFile_<?= e($slotKey) ?>"
                               name="pdf_<?= e($slotKey) ?>" data-format="<?= e($slotKey) ?>"
                               accept="application/pdf" aria-label="<?= e($slotLabel) ?> PDF">
                      </div>
                    </div>
                    <?php endforeach; ?>
                    <?php /* Both files travel in one request, which PHP refuses outright
                             when they pass post_max_size between them, however far each
                             is under its own limit. */ ?>
                    <p class="pdf-slots-note">Both files, with any supporting documents, have to fit within <?= e(max_post_label()) ?> in all.</p>
                  </div>

                  <div class="ai-extract-button-container text-center">
                    <p class="ai-extract-question">How would you like to enter your paper details?</p>
                  
                    <?php /* No engine picker: the two are the same model on two keys,
                             and which one is in play is decided by whichever still
                             has room (see ai_engine_current()). Asking the student
                             to choose put a service detail in front of somebody
                             trying to hand in a paper. */ ?>

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
                        <?php /* Rewritten by syncSectionScope() to follow the sections chosen;
                                 this is what it says before anything has been chosen. */ ?>
                        <span id="scopeNote">Only the Abstract is taken from your PDF, copied word-for-word so you can
                        check it against your paper. Write the Introduction, Methodology, Results
                        and Discussion, Conclusion and References yourself. Every section shown below is required.</span>
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
                         handler and the draft both validate against, so a section
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
                    <?php /* The sections the author chose to submit, comma-separated, empty
                             until they have chosen. A named field of the form, so the browser
                             copy, the server draft and the submission all carry it the way
                             they carry every other value. */ ?>
                    <input type="hidden" id="sectionScope" name="section_scope" value="">
                  </div>
                </div>

                <div class="mt-3 d-flex justify-content-between">
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
                    <div class="review-label" id="reviewPDFLabel">Research PDF:</div>
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
                    <div class="review-label">Sections:</div>
                    <div class="review-value" id="reviewSections">-</div>
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
                     alt="Corpus" src="<?= BASE_URL ?>/assests/images/papel_red_welcome.gif">
                <div class="chat-identity" tabindex="0" aria-describedby="chatIdentityTip">
                    <h5 class="mb-0">Corpus</h5>
                    <p class="mb-0 small">AI Chatbot of the PAPEL</p>
                    <?php /* The limit is read from the server rather than typed
                             here, for the same reason the assistant's own prompt
                             reads it: a figure in the description that the
                             upload then refuses is worse than no figure. */ ?>
                    <div class="chat-identity-tip" id="chatIdentityTip" role="tooltip">
                        <span class="tip-name">Corpus</span>
                        <span class="tip-desc">AI Chatbot of the PAPEL</span>
                        <p class="tip-body">Corpus is here to get your paper handed in properly. It knows what the repository asks for and what belongs in each box on this form.</p>
                        <ul class="tip-list">
                            <li>What to attach: Ethics Clearance, Consent Form and Data Collection instruments</li>
                            <li>What the file must be: a PDF, up to <?= e(max_upload_label()) ?></li>
                            <li>How Extract with AI fills the form from your manuscript</li>
                        </ul>
                        <p class="tip-body tip-limit">It will not write your paper or answer questions outside PAPEL. For anything beyond the submission, ask your research adviser.</p>
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" id="chatCloseBtn"><span class="material-symbols-outlined mi-20">close</span></button>
            </div>
        </div>
        <div id="chat-messages">
            <div class="msg-row">
                <img class="msg-avatar" data-mood="welcome" alt="Corpus"
                     src="<?= BASE_URL ?>/assests/images/papel_red_welcome.gif">
                <div class="message bot">Hi! I'm Corpus, your upload guide. Need help with requirements like Ethics Clearance or Consent Forms? Ask me!</div>
            </div>
        </div>
        <div id="chat-input-area">
            <form id="chatForm" class="chat-input-wrapper">
                <input type="text" id="chat-input" placeholder="Type your message..." autocomplete="off">
                <button type="submit" class="send-btn"><span class="material-symbols-outlined mi-18">send</span></button>
            </form>
        </div>
    </div>
    <button type="button" id="chat-button" title="Ask Corpus">
        <img id="puppyFab" class="puppy-avatar" width="48" height="48"
             alt="Ask Corpus" src="<?= BASE_URL ?>/assests/images/papel_red_welcome.gif">
    </button>
    <?php /* A visual duplicate of the button beside it, which already says "Ask
             Corpus", so it stays out of the accessibility tree and the tab order:
             announced every few seconds, it would only interrupt someone who
             cannot see it. The cross is therefore for the mouse; Escape stops
             the lines from the keyboard. */ ?>
    <div class="chat-nudge" id="chat-nudge" aria-hidden="true">
        <span class="chat-nudge-text" id="chat-nudge-text"></span>
        <button type="button" class="chat-nudge-x" id="chat-nudge-x" tabindex="-1"
                title="Hide these messages">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">

/* ----- Supporting documents, by paper type -----
   Ethics clearance, consent and a data-collection tool are only demanded of the
   types that involve human participants and original data gathering. The list
   mirrors paper_type_needs_documents() in config/core.php: the server decides,
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

        /* The input's own label, found by its `for`, not "the first label in
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
       fixed, so nothing else will do that for it. If there is no room below
       (the usual case for the lower of the two) it opens upward. */
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
               like it fits below, which is how it ended up hanging off the
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
    /* The sections the author chose to submit, or null while no choice has been
       made, which means every one of them: what this page always asked for. A
       section outside the choice is hidden and is not asked for, but it is never
       emptied, so widening the choice again brings back whatever was typed. */
    let scope = null;
    function inScope(key) { return scope === null || scope.indexOf(key) !== -1; }
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
    // authority: this only keeps the live document clean, since pasted markup
    // is inserted into a contenteditable long before it is ever submitted.
    const ALLOWED = ['p','br','div','span','b','strong','i','em','u','strike','s','del','sub','sup',
                     'ul','ol','li','blockquote','a','img',
                     'table','thead','tbody','tfoot','tr','th','td','caption'];
    const DROPPED = ['script','style','iframe','object','embed','applet','noscript','template',
                     'svg','math','head','title','link','meta','base',
                     'form','input','button','select','textarea','option'];
    // No inline styling survives a paste at all. Line height and alignment are
    // fixed for every paper, and colours would carry Word's shading into the
    // repository, so a pasted passage arrives looking like everything the
    // student typed themselves.

    /* Rebuilds pasted content into flowing paragraphs.

       Copying from a PDF gives one hard line break per *visual* line, because
       that is how the page was laid out: PDF.js emits a <br> per line in its
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

        return fetch('student_upload_ai.php', { method: 'POST', body: fd,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
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

        // Re-measure whenever the header's width changes for any reason:
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
        reflowToolbar(ed);   // far more room now: bring the tools back out

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
       vertical margins, and picked up its colour: three surprises for what
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

        // Inside a list, indenting means nesting the item, that is what
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

        // Alignment applies to the cell under the caret, and nowhere else:
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

        // Produce tags (<b>, <i>) rather than inline styles, and <p> on Enter:
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
               only ever meant to wrap: an address block, a list of authors, a
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

        // Paste keeps structure (pictures and tables above all) but not
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

            e.preventDefault();      // not a text selection: a resize
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
        // paragraphs: used when a saved draft is put back.
        setHtml: function (key, html) {
            const ed = editors[key]; if (!ed) return;
            ed.surface.innerHTML = html || '';
            refresh(ed);
        },
        keys: function () { return Object.keys(editors); },
        clear: function (key) { this.setText(key, ''); },
        clearAll: function () { Object.keys(editors).forEach(k => this.setText(k, '')); },
        syncAll:  function () { Object.keys(editors).forEach(k => refresh(editors[k])); },
        // Returns the key of the first empty section the author is submitting,
        // or null when all of those are filled. A section they chose to leave out
        // is not empty in any way that matters, so it is never reported.
        firstEmpty: function () {
            return Object.keys(editors).find(k => inScope(k) && plainText(editors[k]) === '') || null;
        },
        // Sets which sections are being submitted (a list of keys, or null for all)
        // and hides the boxes outside it.
        setScope: function (list) {
            scope = Array.isArray(list) ? list.slice() : null;
            Object.keys(editors).forEach(function (k) {
                editors[k].root.classList.toggle('is-out-of-scope', !inScope(k));
            });
        },
        inScope: inScope,
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
   One PDF box for each manuscript format

   Step 1 asks how the paper is written up: IMRAD, Full Manuscript, or both. A
   paper handed in both ways is one paper with two files, so step 2 has a box
   for each format that is ticked and for no other. All the boxes are in the
   markup, hidden, and syncPdfSlots() shows the ones that apply.
   ========================================================================= */
const PDF_FORMATS = [
    { key: 'imrad', label: 'IMRAD',           tick: 'mt_imrad' },
    { key: 'full',  label: 'Full Manuscript', tick: 'mt_full'  }
];
// The most one request may carry, for adding the files up before they are sent. 0 means no limit.
const POST_MAX       = <?= (int)max_post_bytes() ?>;
const POST_MAX_LABEL = <?= json_encode(max_post_label()) ?>;

function pdfInput(key)     { return document.getElementById('pdfFile_' + key); }
function pdfIndicator(key) { return document.getElementById('pdf-indicator-' + key); }
function pdfLabelOf(key)   { return PDF_FORMATS.filter(function (f) { return f.key === key; })[0].label; }
function pdfTicked(key) {
    const f = PDF_FORMATS.filter(function (x) { return x.key === key; })[0];
    const box = f ? document.getElementById(f.tick) : null;
    return !!(box && box.checked);
}
// The formats being handed in, in the order their files are listed.
function pdfActive() { return PDF_FORMATS.filter(function (f) { return pdfTicked(f.key); }); }
function pdfFileOf(key) {
    const input = pdfInput(key);
    return (input && input.files && input.files[0]) ? input.files[0] : null;
}

function clearPdf(key) {
    const input = pdfInput(key);
    if (!input) return;
    input.value = '';
    updatePdfIndicator(input);
}

/* Shows the boxes for the formats ticked in step 1 and hides the rest. Run on
   every tick, on arriving at step 2, and when a saved draft puts the ticks back. */
function syncPdfSlots() {
    const active = pdfActive();
    PDF_FORMATS.forEach(function (f) {
        const input = pdfInput(f.key);
        const slot = input ? input.closest('.pdf-slot') : null;
        const on = pdfTicked(f.key);
        if (slot) slot.hidden = !on;
        if (!on && pdfFileOf(f.key)) {
            /* Unticked after its file was chosen. The file goes with the box, and
               the student is told, rather than finding the box empty or, worse,
               a file for a format they did not mean to hand in being sent. */
            clearPdf(f.key);
            if (window.papelNote) {
                window.papelNote('The ' + f.label + ' PDF was removed, because that manuscript type is no longer ticked.', false);
            }
        }
    });
    const wrap = document.getElementById('pdfSlots');
    if (wrap) wrap.classList.toggle('is-multi', active.length > 1);
    const title = document.getElementById('step2Title');
    // The whole heading is one piece of text: it sits in a flex row, which would otherwise gap its halves.
    if (title) title.textContent = (active.length > 1 ? 'Upload PDFs' : 'Upload PDF') + ' & Enter Details';
}

/* The file Extract with AI reads. The form's sections are IMRAD-shaped
   (introduction, methodology, results and discussion, conclusion, references),
   so the IMRAD PDF is the one to read when there is one; otherwise it is
   whichever file is attached. */
function extractionSource() {
    const order = ['imrad', 'full'];
    for (let i = 0; i < order.length; i++) {
        const file = pdfTicked(order[i]) ? pdfFileOf(order[i]) : null;
        if (file) return { key: order[i], label: pdfLabelOf(order[i]), file: file };
    }
    return null;
}

/* Every file on the form added up against what one request may carry. Two PDFs
   that are each inside the limit can still be refused together, and PHP then
   throws the whole request away, token and all, so the student is told here,
   before anything is sent. Returns the sentence to show, or '' when it fits. */
function filesTooBig() {
    if (!POST_MAX) return '';
    let total = 0;
    document.querySelectorAll('#uploadForm input[type="file"]').forEach(function (input) {
        if (input.files && input.files[0]) total += input.files[0].size;
    });
    // Room for the form's own text: small beside a PDF, but the sections can carry tables.
    if (total + 262144 <= POST_MAX) return '';
    return 'Your files add up to ' + (total / 1048576).toFixed(1) + 'MB, and the server takes at most '
         + POST_MAX_LABEL + ' in one go, with everything else on this form. Please use smaller files.';
}

// What step 4 says the PDF is, or the PDFs are.
function reviewPdfText() {
    const active = pdfActive();
    if (!active.length) return '-';
    return active.map(function (f) {
        const file = pdfFileOf(f.key);
        return (active.length > 1 ? f.label + ': ' : '') + (file ? file.name : 'Not attached');
    }).join('; ');
}

/* =========================================================================
   PDF preview
   The panel is includes/pdf_dock.php, the one the paper pages, the review
   desk and the public repository open papers in, so a student checks their
   file in the same viewer that will later show it to everyone else. The chosen
   file is handed to it in memory: nothing is uploaded to open it, and it only
   reaches the server, and Google Drive, when the form is finally submitted.
   ========================================================================= */
const papelPdfPreview = (function () {
    /* Which box's file the panel is showing, so that clearing a box takes the
       panel away only when it was the one on view. */
    let shown = null;

    function currentFile(key) { return pdfFileOf(key); }
    // Named for its format only when there are two to tell apart.
    function title(key) {
        return (pdfActive().length > 1 ? pdfLabelOf(key) + ': ' : '') + pdfFileOf(key).name;
    }
    function dock() { return window.papelPdfDock || null; }

    return {
        /* On every change of the chosen file. Picking a PDF shows it straight
           away, so the student can check they grabbed the right one before
           spending time on the rest of the form; clearing the choice takes the
           panel, and the tab that brings it back, away with it. */
        reset: function (key) {
            const d = dock();
            if (!d) return;
            if (currentFile(key)) {
                d.openFile(currentFile(key), title(key));
                shown = key;
            } else if (shown === key) {
                d.forget();
                shown = null;
            }
        },
        // The chosen-file row is a switch for the panel.
        toggle: function (key) {
            const d = dock();
            const file = currentFile(key);
            if (!d || !file) return;
            if (d.isOpen() && shown === key) {
                d.minimise();
            } else {
                d.openFile(file, title(key));
                shown = key;
            }
        }
    };
})();


/* =========================================================================
   Draft saving

   The wizard is long, and losing a half-written paper to a stray click is the
   worst thing this page could do. Everything typed is therefore kept on this
   device as it is entered, and offered back on return.

   One honest limit: a chosen file cannot be stored by a web page: the browser
   gives a page a handle to a file, not the file itself, and that handle dies
   with the tab. Every word is restored; the PDF and any supporting documents
   have to be picked again. The restore prompt says so plainly rather than
   letting a student discover it at the submit button.
   ========================================================================= */
/* ===== Signed out while this page was open =====
   Signing out in another tab leaves this one looking perfectly alive: the form
   still types, the steps still move, and nothing it sends can be accepted. The
   server now says 401 to a background request from a session that has ended
   (require_role() in config/core.php), and this is what the page does about it:
   says so once, points at the sign-in page in a new tab so the form here is not
   thrown away, and leaves the local copy of the work alone. */
let papelSignedOutSaid = false;

async function papelSignedOut() {
    if (papelSignedOutSaid) return;
    papelSignedOutSaid = true;

    const again = await window.papelConfirm(
        'You have been signed out, most likely in another tab, so nothing on this page can be '
      + 'saved while that lasts. What you have typed is kept on this device: sign in again, come '
      + 'back to this tab, and carry on where you left off.',
        { title: 'You are signed out', icon: 'lock',
          confirmText: 'Sign in, in a new tab', cancelText: 'Stay on this page' }
    );
    if (again) {
        window.open(<?= json_encode(BASE_URL . '/archive/index.php?login_modal=1&role=student') ?>,
                    '_blank', 'noopener');
    }
}

/* Coming back to this tab is exactly when somebody has just signed out in
   another one, so that is when it is worth asking. The search endpoint is the
   cheapest thing on the page that goes through the same gate; what comes back
   is thrown away, only its status matters. */
document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible' || papelSignedOutSaid) return;
    fetch('student_upload_ai.php?ajax_search=1&q=__ping__', { cache: 'no-store' })
        .then(function (res) { if (res.status === 401) papelSignedOut(); })
        .catch(function () { /* offline, or the server is down: not this to answer */ });
});

const papelDraft = (function () {
    const KEY = 'papel_upload_draft_' + <?= json_encode((string)($u['user_id'] ?? 'anon'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    /* Set when the dashboard sent us here with ?draft=<id>.

       JSON_HEX_TAG matters here: a title is free text, and without it a title
       containing a closing script tag ended this block early: the rest of the
       draft became markup and papelDraft was never defined, which quietly broke
       the whole wizard. */
    const SERVER_DRAFT = <?= $draftPayload ? json_encode($draftPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) : 'null' ?>;
    /* The drafts this student actually still has, so a local copy left over
       from a deleted one can be told apart from unsaved work. Titles come
       along and are escaped like SERVER_DRAFT above, for the same reason. */
    const LIVE_DRAFTS = <?= json_encode($liveDrafts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    let draftId = SERVER_DRAFT ? SERVER_DRAFT.id : 0;
    const SKIP = ['_token', 'action'];
    let saveTimer = null;
    let submitting = false;
    /* What the form looked like the last time it was saved. dirty() used to ask
       only "is there anything typed?", which is true from the first keystroke
       and stays true after a save, so every link on the page kept asking to
       save a draft that was already saved, on page after page. Comparing
       against this makes "unsaved" mean what it says. */
    let savedSignature = null;

    function form() { return document.getElementById('uploadForm'); }

    function liveById(id) {
        const want = Number(id);
        return LIVE_DRAFTS.filter(function (d) { return d.id === want; })[0] || null;
    }

    /* The safety net for a device copy that has lost its draft id: without
       some way back to the row it came from, saving it writes a second draft
       holding the same work. Only an unambiguous match counts, so two drafts
       sharing a title are left alone rather than guessed between. */
    function liveByTitle(title) {
        const want = String(title == null ? '' : title).trim().toLowerCase();
        if (want === '' || want === 'untitled draft') return null;
        const hits = LIVE_DRAFTS.filter(function (d) {
            return String(d.title || '').trim().toLowerCase() === want;
        });
        return hits.length === 1 ? hits[0] : null;
    }

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

    /* Only the typed values matter for "has this changed": the file list is
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
            // The year box is pre-filled, so on its own it means nothing. Nor does
            // a choice of sections: it is an answer to a question, not work, and
            // counting it would raise "Continue where you left off?" for a student
            // who did nothing but pick one.
            return text !== '' && name !== 'year' && name !== 'section_scope';
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
        // The ticks are back, so are the boxes that go with them.
        if (typeof syncPdfSlots === 'function') syncPdfSlots();
        // The choice of sections is back in its field; the boxes follow it.
        if (typeof syncSectionScope === 'function') syncSectionScope();
        if (typeof updateReview === 'function') updateReview();

        // Step 2 onwards needs a PDF, which cannot be restored, so the student
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
           duplicates. What goes is the typed text: a draft never carries a PDF,
           so resuming one always means picking the file or files again. */
        saveToServer: async function () {
            const f = form();
            if (!f) return 0;
            if (typeof papelDoc !== 'undefined') papelDoc.syncAll();

            const body = new FormData(f);
            body.set('action', 'save_draft');
            if (draftId) body.set('draft_id', String(draftId));
            /* Files belong to submission, not to a draft: the PDFs and the supporting
               documents stay in this browser until the form is submitted. The server
               ignores them anyway, and sending them would be two uploads of up to
               the file limit each, on every save, to be thrown away. */
            ['pdf_imrad', 'pdf_full', 'research_pdf',
             'ethics_clearance', 'consent_form', 'data_collection', 'copyright_doc', 'other_doc']
                .forEach(function (n) { body.delete(n); });

            // Only ever run because the student asked to save, so it earns the
            // logo pill; a fetch is invisible to loading_bar.php's form hook.
            if (window.papelLoading) window.papelLoading.work.start();
            try {
                const res = await fetch('student_upload_ai.php', { method: 'POST', body: body,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                /* Signed out, here or in another tab: the server says so plainly
                   now (require_role() in config/core.php). Swallowed with
                   everything else, this left somebody typing into a form that
                   could not save a word of it. */
                if (res.status === 401) { papelSignedOut(); return 0; }
                const json = await res.json();
                if (json && json.success) {
                    draftId = json.draft_id;
                    savedSignature = signature(collect());
                    /* The copy on the device has to learn the id as well. Only
                       this closure variable used to be updated, so a student
                       who saved a draft and then left the page kept a copy
                       tagged 0; coming back, it had no row to save into and
                       made a second draft holding the same work. serverSaved
                       marks it as matching the server, and the next keystroke
                       drops the flag because collect() never sets it. */
                    const kept = read();
                    if (kept) {
                        kept.draftId = draftId;
                        kept.serverSaved = true;
                        write(kept);
                    }
                    // Signed in again: worth saying if it ever happens twice.
                    papelSignedOutSaid = false;
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
            /* The baseline for "unsaved". Some boxes arrive already filled (the
               year, the student's own name) so without this the page counts as
               having unsaved work before the student has touched anything, and
               the leave prompt fires on the very first link they click. */
            savedSignature = signature(collect());

            // A draft opened from the dashboard is applied without asking:
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
            if (data.draftId && !liveById(data.draftId)) {
                papelDraft.clear();
                return;
            }

            /* Copies written before the id was persisted are still on students'
               devices, untagged. Adopting the draft they plainly came from is
               what stops them being saved a second time. */
            if (!data.draftId) {
                const match = liveByTitle(data.fields && data.fields.title);
                if (match) data.draftId = match.id;
            }

            /* Saved to the server and untouched since. The draft is on the
               dashboard under Drafts, and Continue editing is the way back to
               it; asking here is asking about work that is not unsaved. */
            if (data.serverSaved && data.draftId) {
                papelDraft.clear();
                return;
            }

            /* And a copy with no draft id has nothing to check against: it was
               never on the server, so the only thing that can date it is the
               clock. This copy exists to rescue an interrupted session, a shut
               tab or a misclicked link, and those are picked up within hours.
               Days later it is a leftover: it cannot hold the PDF anyway, and
               a student who has since thrown their draft away is asked about
               work they believe they deleted, by a prompt that never stops
               coming back. The autosave rewrites savedAt on every keystroke,
               so this counts from the last thing they typed. */
            const STALE_AFTER = 48 * 60 * 60 * 1000;
            if (Date.now() - (data.savedAt || 0) > STALE_AFTER) {
                papelDraft.clear();
                return;
            }

            const when = new Date(data.savedAt || Date.now());
            // The names of the files, not of the inputs they were in ("pdf_imrad").
            const files = Object.keys(data.files || {}).map(function (k) { return data.files[k]; });
            const note = files.length
                ? ' You will need to attach ' + files.join(', ') + ' again. A browser cannot keep a file between visits.'
                : '';

            /* Only call it a draft when there is one. A copy with no draft id
               has never been saved anywhere, and calling that "your draft" is
               what made a deleted draft look as though it had come back. */
            const isDraft = !!data.draftId;
            const restore = await window.papelConfirm(
                (isDraft
                    ? 'Your draft has changes on this device that were never saved, from '
                    : 'You have unfinished work on this device, from ') +
                when.toLocaleString() + '.' + note,
                { title: isDraft ? 'Continue your draft?' : 'Continue where you left off?',
                  icon: 'history',
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

/* What a chosen file weighs, written as the rest of this page writes sizes
   ("40MB"). Whole kilobytes below a megabyte; the threshold stops 1023.6KB
   rounding up to a "1024KB" that is really 1.0MB. */
function fileSizeLabel(bytes) {
    if (bytes < 1024) return bytes + 'B';
    if (bytes < 1048576 - 512) return Math.round(bytes / 1024) + 'KB';
    return (bytes / 1048576).toFixed(1) + 'MB';
}

/* The box for one manuscript format has two faces. With no file it is the drop
   target: the upload icon, the prompt, and "No PDF selected" beside it. Once a
   PDF is chosen the prompt has nothing left to say, so the box shows the file
   instead: a tick where the icon was, then its name over its size (the CSS hides
   the prompt on .has-file). Choosing nothing, or clearing the choice, puts it
   all back. */
function updatePdfIndicator(input) {
    const indicator = pdfIndicator(input.dataset.format);
    const zone = indicator.closest('.upload-zone');
    const icon = zone ? zone.querySelector('.upload-zone-icon') : null;
    papelPdfPreview.reset(input.dataset.format);
    if (input.files && input.files[0]) {
        const file = input.files[0];
        /* The name goes in by textContent, not into the HTML string: a file
           name is whatever the student's disk allows, and on most systems that
           includes < and >. It also gets an element of its own, so it can be cut
           to one line. Wrapped, a typical manuscript file name ran to four lines
           and stretched the box to twice its height. The full name is the row's
           tooltip, and the preview panel's header shows it too. */
        const name = document.createElement('span');
        name.className = 'pdf-indicator-name';
        name.textContent = file.name;
        const size = document.createElement('span');
        size.className = 'pdf-indicator-size text-muted';
        size.textContent = fileSizeLabel(file.size);
        indicator.textContent = '';
        indicator.appendChild(name);
        indicator.appendChild(size);
        indicator.title = file.name + '\nClick to preview';
        indicator.classList.add('selected');
        if (zone) zone.classList.add('has-file');
        if (icon) { icon.textContent = 'check_circle'; icon.classList.add('mi-fill'); }
    } else {
        indicator.innerHTML = '<span class="material-symbols-outlined mi-18 text-muted">cancel</span> No PDF selected';
        indicator.removeAttribute('title');
        indicator.classList.remove('selected');
        if (zone) zone.classList.remove('has-file');
        if (icon) { icon.textContent = 'upload_file'; icon.classList.remove('mi-fill'); }
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
               looked only for a select, found nothing, and threw on .value,
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
            /* Marked required on the form, so it is asked for here too. One is
               ticked to begin with, but all three can be cleared. */
            if (!document.querySelectorAll('input[name="paper_status[]"]:checked').length) {
                papelAlert('Please choose at least one paper status');
                return;
            }
        }
        if (currentStep === 2) {
            const titleField = document.getElementById('titleField').value;
            // One PDF for every manuscript type ticked in step 1.
            const active = pdfActive();
            const lacking = active.filter(function (f) { return !pdfFileOf(f.key); });
            if (lacking.length) {
                papelAlert(active.length > 1
                    ? 'Please upload your ' + lacking[0].label + ' PDF.'
                    : 'Please upload a PDF file');
                return;
            }
            const tooBig = filesTooBig();
            if (tooBig) {
                papelAlert(tooBig);
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
        if (currentStep === 3) {
            // The supporting documents travel in the same request as the PDFs.
            const tooBig = filesTooBig();
            if (tooBig) {
                papelAlert(tooBig);
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
    
    // Step 2's boxes follow whatever step 1 has ticked by now.
    if (step === 2) {
        syncPdfSlots();
        /* Which sections to submit is asked after this call returns, not during
           it: a click on the step indicator that skips ahead runs goToStep once
           per step on the way, and the question belongs to wherever the student
           actually lands. It is asked only while no choice is recorded. */
        setTimeout(function () { askSectionScope(false); }, 0);
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

/* ---------------------------------------------------------------------------
   Which written sections this paper is submitted with.

   Asked once, on arriving at step 2, and kept in the hidden section_scope field
   so that it travels the way every other value does: into the copy kept in this
   browser, into the draft on the server, and into the submission. The server is
   what enforces it (section_scope_parse() in config/core.php and the submit
   handler); this is what asks, and what hides the boxes the author has chosen
   not to fill.
   --------------------------------------------------------------------------- */
// key => label, from the same list the server validates against.
const SECTION_LABELS = <?= json_encode(paper_section_labels(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
const SECTION_KEYS = Object.keys(SECTION_LABELS);
// A few words beside each name in the question, so it is answered knowing what each is.
const SECTION_HINTS = {
    abstract: 'Always included',
    introduction: 'Background and aims',
    methodology: 'Design and instruments',
    results_discussion: 'Findings and meaning',
    conclusion: 'Conclusions and next steps',
    references: 'Every source you cited'
};

function scopeField() { return document.getElementById('sectionScope'); }

// "A", "A and B", "A, B and C"
function joinNames(names) {
    if (names.length <= 1) return names.join('');
    return names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
}

// The recorded choice in the order the form lists the sections, or null when none is recorded.
function currentScope() {
    const field = scopeField();
    const raw = field ? String(field.value || '').trim() : '';
    if (!raw) return null;
    const picked = raw.split(',').map(function (s) { return s.trim(); })
        .filter(function (k) { return SECTION_KEYS.indexOf(k) !== -1; });
    if (!picked.length) return null;
    if (picked.indexOf('abstract') === -1) picked.push('abstract');      // never optional
    return SECTION_KEYS.filter(function (k) { return picked.indexOf(k) !== -1; });
}

// The choice in words: "All sections", "Abstract only", or the names.
function scopeWords(scope) {
    const list = scope || SECTION_KEYS;
    if (list.length === SECTION_KEYS.length) return 'All sections';
    if (list.length === 1) return 'Abstract only';
    return joinNames(list.map(function (k) { return SECTION_LABELS[k]; }));
}

// The notice above the boxes, which depends on what was chosen.
function scopeNoticeText(scope) {
    const lead = 'Only the Abstract is taken from your PDF, copied word-for-word so you can check it against your paper.';
    const others = (scope || SECTION_KEYS).filter(function (k) { return k !== 'abstract'; })
        .map(function (k) { return SECTION_LABELS[k]; });
    return lead + (others.length
        ? ' Write the ' + joinNames(others) + ' yourself.'
        : ' You chose to submit the Abstract only, so there is nothing else to write.')
        + ' Every section shown below is required.';
}

// Brings everything on the page into line with the recorded choice.
function syncSectionScope() {
    const scope = currentScope();
    papelDoc.setScope(scope);

    const value = document.getElementById('scopeBarValue');
    if (value) value.textContent = scopeWords(scope);
    const note = document.getElementById('scopeNote');
    if (note) note.textContent = scopeNoticeText(scope);
}

function setSectionScope(list) {
    const field = scopeField();
    if (!field) return;
    field.value = list ? list.join(',') : '';
    syncSectionScope();
    /* Deliberately not saved here. The autosave runs on typing and writes every
       field, this one included; the leave-page guard saves it when the form is
       dirty, and "Save as draft" posts it. Saving on the choice alone wrote a
       device copy of an otherwise empty form (the form's defaults already count
       as content), so the next visit opened with "Continue where you left off?"
       for a student who had only answered a question. */
}

/* Waits until no dialog is on screen. They all share one box with one answer
   slot, so opening a second while a first is up would leave the first never
   answered. An alert raised by the very click that brought the student here, or
   "Continue where you left off?" still waiting for its reply, are the likely ones. */
function dialogIdle() {
    return new Promise(function (resolve) {
        const box = document.getElementById('papelDialog');
        (function look() {
            if (!box || !box.classList.contains('open')) { resolve(); return; }
            setTimeout(look, 120);
        })();
    });
}

let scopeAsking = false;

/* Asks which sections to submit. On arriving at step 2 it asks only when no
   choice is recorded; the Change button passes force to ask again. Whatever the
   answer is, closing the box with Escape included, it is recorded as it stands
   ticked, and it starts as every section, so doing nothing keeps what this page
   has always done. */
async function askSectionScope(force) {
    if (scopeAsking) return;
    if (!force && currentScope() !== null) return;
    if (typeof window.papelChoose !== 'function') return;

    scopeAsking = true;
    try {
        await dialogIdle();
        // The student may have moved on while another dialog was up.
        if (!force && currentStep !== 2) return;

        const picked = await window.papelChoose({
            title: 'Which sections will you submit?',
            icon: 'segment',
            lead: 'Choose the written sections that go on your paper\'s page. Your PDF is always submitted in full'
                + (CAN_DRAFT ? ', and your reviewers read the PDF.' : '.'),
            confirmText: 'Continue',
            sections: SECTION_KEYS.map(function (k) {
                return { key: k, label: SECTION_LABELS[k], hint: SECTION_HINTS[k] || '', locked: k === 'abstract' };
            }),
            selected: currentScope() || SECTION_KEYS,
            describe: function (keys) { return 'On your paper\'s page: ' + scopeWords(keys) + '.'; }
        });
        if (Array.isArray(picked) && picked.length) setSectionScope(picked);
    } finally {
        scopeAsking = false;
    }
}

/* The explanation, written for the sections this author is submitting. It used to
   be a constant that said all six were required, which stopped being true when
   the choice was added. Everything put into it is either fixed text on this page
   or a section label from the same list the server validates against, never
   anything a person typed, which is what makes it safe to hand to the dialog as
   markup. */
function sectionsNote() {
    const scope = currentScope() || SECTION_KEYS;
    const partial = scope.length < SECTION_KEYS.length;
    const items = scope.map(function (k) {
        return k === 'abstract'
            ? '<li><strong>Abstract</strong>: taken from your PDF word for word, so check it.</li>'
            : '<li><strong>' + SECTION_LABELS[k] + '</strong></li>';
    }).join('');

    return '<p>Your PDF and what you type here are read by different people, and they'
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
      + '<p>That is why ' + (partial ? 'the sections you chose are' : 'all six are') + ' required:</p>'
      + '<ul>' + items + '</ul>'
      + (partial
          ? '<p>A section you leave out is simply not shown on your paper\'s page.'
            + (CAN_DRAFT
                ? ' <strong>Your reviewers are told you chose that</strong>, so it is not read as something missing.'
                : ' The page says that the author chose to publish only these sections.')
            + ' You can change your choice at any time with the Change button at the top of this step.</p>'
          : '<p>If you would rather submit fewer sections, the Change button at the top of this step'
            + ' lets you choose, for example, the Abstract only.'
            + (CAN_DRAFT ? ' Your reviewers are told what you chose.' : '') + '</p>')
      + '<p>You can paste them straight from your document. Tables, pictures and'
      + ' links all survive the paste'
      + (CAN_DRAFT
          ? ', and a draft can be saved at any point.</p>'
          : '. Your paper is published as soon as you submit it, with no review step,'
            + ' so fill it in before you leave the page: drafts are for student'
            + ' submissions only.</p>');
}

function explainSections(force) {
    if (sectionsExplained && !force) return Promise.resolve(false);
    sectionsExplained = true;
    return window.papelAlert('', {
        title: 'Why you type the sections out',
        icon: 'menu_book',
        wide: true,
        html: sectionsNote()
    });
}

function updateReview() {
    const paperType = document.querySelector('select[name="paper_type"]');
    document.getElementById('reviewPDFLabel').textContent =
        pdfActive().length > 1 ? 'Research PDFs:' : 'Research PDF:';
    const title = document.getElementById('titleField');
    const authors = document.getElementById('authorsField');
    const year = document.getElementById('yearField');
    
    document.getElementById('reviewPaperType').textContent = 
        paperType.options[paperType.selectedIndex].text;
    document.getElementById('reviewPDF').textContent = 
        reviewPdfText();
    document.getElementById('reviewTitle').textContent = title.value || '-';
    document.getElementById('reviewAuthors').textContent = authors.value || '-';
    document.getElementById('reviewYear').textContent = year.value || '-';
    // What will be on the paper's page, so a choice made a step ago is visible
    // at the point it is about to take effect.
    document.getElementById('reviewSections').textContent = scopeWords(currentScope());

    // Count supporting docs
    let docCount = 0;
    if (document.querySelector('input[name="ethics_clearance"]').files[0]) docCount++;
    if (document.querySelector('input[name="consent_form"]').files[0]) docCount++;
    if (document.querySelector('input[name="data_collection"]').files[0]) docCount++;
    if (document.querySelector('input[name="other_doc"]').files[0]) docCount++;
    if (document.querySelector('input[name="copyright_doc"]').files[0]) docCount++;
    
    /* The wording follows whether this paper type needs the documents at
       all. "Missing" reads as a fault, and for a Journal Article or a
       Project there is no fault to report: nothing was ever required. */
    const docsNeeded = supportingDocsRequired();

    document.getElementById('reviewDocs').textContent =
        docCount ? docCount + ' document(s) attached'
                 : (docsNeeded ? 'None attached yet' : 'Not required for this paper type');

    const copyFile = document.querySelector('input[name="copyright_doc"]').files[0];
    document.getElementById('reviewCopyright').textContent =
        copyFile ? copyFile.name
                 : (docsNeeded ? 'Not attached yet' : 'Not applicable');
}

/* The overlay is this page's loader, and its card plays the PAPEL logo
   animation, so neither path below asks for the logo pill
   (includes/loading_bar.php): it would be the same animation twice, one on top
   of the other. The overlay already dims and blurs the page and takes the
   clicks, which is the rest of what the pill's veil is for. */

/* ---------------------------------------------------------------------------
   Reading the paper in the browser.

   Hosting that forbids outbound calls cannot reach the model from PHP, but the
   student's own browser can. When AI_PROXY is set the page reads the PDF with
   PDF.js (the same copy includes/pdf_dock.php already loads for the viewer),
   sends the text to the proxy, and hands the fields to PHP, which lifts the
   abstract and assembles the answer. With AI_PROXY empty nothing changes: this
   server calls the model itself, as it does on a machine with outbound access.
   --------------------------------------------------------------------------- */
const AI_PROXY     = <?= json_encode(AI_PROXY_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const PDFJS_SRC    = <?= json_encode(asset_url('assests/js/pdfjs/pdf.min.js'), JSON_UNESCAPED_SLASHES) ?>;
const PDFJS_WORKER = <?= json_encode(asset_url('assests/js/pdfjs/pdf.worker.min.js'), JSON_UNESCAPED_SLASHES) ?>;
const PAPEL_JSON_HEADERS = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

/* On a copy of the site, the answer itself is shown. A body with no JSON in it
   comes from the host rather than from PAPEL, and its first line usually names
   the reason (a firewall refusal, a resource limit, a gateway timeout).
   Without it the only route to that line is the browser console, which is not
   somewhere a student goes, and the fault reads as unexplained. Never on
   production, where it would be noise in front of somebody handing work in. */
const SHOW_TECH_DETAIL = <?= json_encode(defined('APP_ENV') && APP_ENV !== 'production') ?>;

/* What to tell a person when the server answered with a failure: the sentence
   written for them, then the reference, so that it can be quoted and looked up in
   the server's log, and, only where the server sent it (a copy of the site, never
   production), the cause itself. It is all set as text. The page used to prefix
   its own "Upload failed:" to a message that already said so, and dropped the
   reference, which is the one thing that leads from the dialog to the cause. */
function papelFailureText(res, fallback) {
    let text = (res && res.message) ? String(res.message) : (fallback || 'Something went wrong.');
    if (res && res.reference) text += ' (reference ' + res.reference + ')';
    if (res && res.detail) text += ' Cause: ' + res.detail;
    return text;
}

// The same tolerance as papelReadJson, for a body already in hand: null when there is no JSON in it.
function papelParseJsonText(body) {
    try { return JSON.parse(body); } catch (err) {}
    const open = body.indexOf('{'), close = body.lastIndexOf('}');
    if (open !== -1 && close > open) {
        try { return JSON.parse(body.slice(open, close + 1)); } catch (err) {}
    }
    return null;
}

/* Finds the JSON in an answer rather than trusting the whole body to be JSON.
   PHP prints some warnings before a line of this app runs, and a host that cuts
   a request short replies with a page of its own; r.json() on either rejects,
   and the student used to hear that the server could not be reached. */
function papelReadJson(r) {
    return r.text().then(function (body) {
        try { return JSON.parse(body); }
        catch (err) {
            const open = body.indexOf('{'), close = body.lastIndexOf('}');
            if (open !== -1 && close > open) {
                try { return JSON.parse(body.slice(open, close + 1)); } catch (e) {}
            }
            const failure = new Error('The server answered HTTP ' + r.status + ' with no JSON in it.');
            failure.papelStatus = r.status;
            failure.papelBody = body;
            throw failure;
        }
    });
}

// Loaded only if the page does not have it: a second copy of PDF.js replaces
// the global the open viewer is still holding (see includes/pdf_dock.php).
function papelLoadPdfJs() {
    if (typeof pdfjsLib !== 'undefined') return Promise.resolve();
    return new Promise(function (resolve, reject) {
        const s = document.createElement('script');
        s.src = PDFJS_SRC;
        s.onload = resolve;
        s.onerror = function () { reject(new Error('The PDF reader could not be loaded.')); };
        document.head.appendChild(s);
    });
}

/* Stops at the character budget rather than reading the whole document: the
   model is asked only for the title, authors, year and keywords, and the
   abstract pattern looks at the front too, so a hundred pages of chapters are
   read for nothing. */
async function papelPdfText(file, maxChars) {
    await papelLoadPdfJs();
    if (pdfjsLib.GlobalWorkerOptions) { pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; }
    const doc = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
    let text = '';
    for (let n = 1; n <= doc.numPages && text.length < maxChars; n++) {
        const content = await (await doc.getPage(n)).getTextContent();
        text += content.items.map(function (i) { return i.str; }).join(' ') + '\n';
    }
    return text.replace(/\s+/g, ' ').trim().slice(0, maxChars);
}

// Either route, one answer shape, so the caller below does not know which ran.
async function papelExtractMetadata(pdfFile, fd) {
    if (!AI_PROXY) {
        return papelReadJson(await fetch('student_upload_ai.php', {
            method: 'POST', body: fd, headers: PAPEL_JSON_HEADERS
        }));
    }

    const text = await papelPdfText(pdfFile, 20000);
    if (text.length < 50) {
        return { success: false, message: 'No readable text could be found in this PDF. It may be a '
            + 'scan of a printed page, password-protected, or empty. Choose "Fill Manually" to enter '
            + 'the details yourself.' };
    }

    let proxied;
    try {
        proxied = await papelReadJson(await fetch(AI_PROXY, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text })
        }));
    } catch (err) {
        /* The proxy is a second address, so it fails in its own ways: offline,
           withdrawn, or an origin it does not recognise. Said as its own thing
           rather than as a fault in the paper. */
        return { success: false, message: 'The reading service could not be reached. Please try again '
            + 'shortly, or choose "Fill Manually" to type the details in yourself.' };
    }
    if (!proxied || !proxied.success) {
        return { success: false, message: (proxied && proxied.error) || 'The AI could not identify the '
            + 'details in this document. You can choose "Fill Manually" to enter them yourself.' };
    }

    const body = new FormData();
    body.set('action', 'extract_from_text');
    body.set('text', text);
    body.set('ai', JSON.stringify(proxied.data || {}));
    const token = document.querySelector('input[name="_token"]');
    if (token) { body.set(token.name, token.value); }
    return papelReadJson(await fetch('student_upload_ai.php', {
        method: 'POST', body: body, headers: PAPEL_JSON_HEADERS
    }));
}

// Extract AI button handler
document.getElementById('btnExtract').addEventListener('click', function(){
  /* The IMRAD PDF when there is one, since the form's sections are IMRAD-shaped;
     otherwise the one that is attached. Said aloud when there are two, so nobody
     is left wondering which of their files the AI read. */
  const source = extractionSource();
  if(!source){
    papelAlert('Please select a PDF first');
    return;
  }
  const pdfFile = source.file;

  /* Extraction sends this one PDF and nothing else, so it is measured on its
     own rather than through filesTooBig(), which adds up every file on the
     form for the submit. The check matters because PHP throws an over-sized
     body away before a line of this app runs: $_POST comes back empty, no
     handler recognises the request, and the page answers a background call
     with its own HTML. This is the only place it can be said plainly. */
  if (POST_MAX && pdfFile.size + 65536 > POST_MAX) {
    papelAlert('That PDF is ' + (pdfFile.size / 1048576).toFixed(1) + 'MB, and this server takes at most '
      + POST_MAX_LABEL + ' in one request. Save it at a smaller size, or choose "Fill Manually" '
      + 'and type the details in yourself.');
    return;
  }

  const sourceNote = pdfActive().length > 1 ? 'your ' + source.label + ' PDF' : 'your PDF';
  const readNote = pdfActive().length > 1 ? ', read from your ' + source.label + ' PDF' : '';

  const btn = this;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Extracting...';

  const overlay   = document.getElementById('uploadOverlay');
  const fillEl    = document.getElementById('uploadProgressFill');
  const labelEl  = document.getElementById('uploadProgressLabel');
  const statusEl  = document.getElementById('uploadStatusMsg');

  overlay.classList.add('active');
  fillEl.style.width  = '10%';
  labelEl.textContent = '10%';
  statusEl.textContent = 'Sending ' + sourceNote + ' to AI...';

  const aiMessages = [
    'Reading ' + sourceNote + '...',
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

  // Find CSRF token
  const tokenInput = document.querySelector('input[name="csrf_token"]') || document.querySelector('input[name="_token"]');
  if(tokenInput) fd.append(tokenInput.name, tokenInput.value);

  papelExtractMetadata(pdfFile, fd)
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
      // another extraction: it tells them what they still have to write, so
      // timing it out means the instruction vanishes before it has been read.
      const previousMsg = document.getElementById('extractMsg');
      if (previousMsg) previousMsg.remove();

      const successMsg = document.createElement('div');
      successMsg.id = 'extractMsg';
      successMsg.className = 'alert alert-success mt-3 alert-dismissable';
      /* What is left to write is whatever the author chose to submit besides the
         abstract. Names come from the section list, never from the paper. */
      const stillToWrite = (currentScope() || SECTION_KEYS).filter(function (k) { return k !== 'abstract'; })
        .map(function (k) { return SECTION_LABELS[k]; });
      successMsg.innerHTML = (papelDoc.text('abstract')
        ? '<strong>Extraction complete' + readNote + '.</strong> Review the Abstract and edit if needed'
          + (stillToWrite.length ? ', then write your ' + joinNames(stillToWrite) + '.' : '.')
        : '<strong>Extraction complete' + readNote + '.</strong> No abstract could be found in ' + sourceNote + ', so '
          + 'please write the Abstract' + (stillToWrite.length ? ' and the other sections you chose' : '') + ' yourself.')
        + '<button type="button" class="alert-close" aria-label="Dismiss">'
        + '<span class="material-symbols-outlined mi-18">close</span></button>';
      successMsg.querySelector('.alert-close').addEventListener('click', function () {
        successMsg.remove();
      });
      btn.parentElement.parentElement.appendChild(successMsg);
    } else {
      papelAlert('❌ ' + papelFailureText(data, 'Extraction failed'));
    }
  })
  .catch(e => {
    /* The whole answer goes to the console so it can be read or sent on: when
       the body carries no JSON at all it is the host replying, not this app,
       and the status is the only thing that says which host limit was hit. */
    console.error('[PAPEL] extraction failed', e);
    if (e && e.papelBody !== undefined) {
      console.error('[PAPEL] status ' + e.papelStatus + ', body follows:', e.papelBody);
    }
    if (navigator.onLine === false) {
      papelAlert('❌ You appear to be offline. Check your connection and press Extract again.');
      return;
    }
    /* The status is named because this message means the server answered with
       something no part of this app can produce, and which one it was decides
       where to look: 504 or 508 is the host cutting a long request short, 403
       its firewall, 200 a page where JSON was expected. */
    const status = (e && e.papelStatus) ? ' (HTTP ' + e.papelStatus + ')' : '';
    let detail = '';
    if (SHOW_TECH_DETAIL && e && typeof e.papelBody === 'string') {
        // Tags stripped and collapsed: the answer is usually a whole page, and
        // the sentence worth reading is a few words inside it.
        const plain = e.papelBody.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        if (plain) { detail = ' Server said: "' + plain.slice(0, 160) + '".'; }
    }
    papelAlert('❌ The server did not answer properly' + status + '.' + detail
      + ' Please try again, or choose "Fill Manually" to type the details in yourself.');
  })
  .finally(() => {
    clearInterval(aiTimer);
    overlay.classList.remove('active');
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

    // One PDF for every manuscript type ticked, and all of it small enough to send at once.
    const noPdf = pdfActive().filter(function (f) { return !pdfFileOf(f.key); })[0];
    if (noPdf) {
        papelAlert('Please upload your ' + noPdf.label + ' PDF.');
        return;
    }
    const tooBig = filesTooBig();
    if (tooBig) {
        papelAlert(tooBig);
        return;
    }

    const overlay   = document.getElementById('uploadOverlay');
    const btn       = document.getElementById('btnUpload');
    const fillEl    = document.getElementById('uploadProgressFill');
    const labelEl   = document.getElementById('uploadProgressLabel');
    const statusEl  = document.getElementById('uploadStatusMsg');

    overlay.classList.add('active');
    btn.disabled = true;

    // Cycling status messages timed to feel alive
    const messages = [
        'Preparing your files',
        'Uploading to secure storage',
        'Saving paper metadata',
        'Running document checks',
        'Almost there, finalizing'
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
    /* If this paper was being written as a draft (or was sent back for
       revision and reopened) the server replaces that row instead of filing a
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
        /* Read first, acted on second. This used to be one try block around both,
           so anything that threw while acting on a good answer was reported as the
           server having sent rubbish, with the good answer pasted into the dialog. */
        const res = papelParseJsonText(xhr.responseText || '');
        if (res && xhr.status === 200 && res.success) {
            // The paper is filed; the local draft has done its job.
            papelDraft.beginSubmit();
            papelDraft.clear();
            setTimeout(function() {
                window.location.href = res.redirect || 'student_dashboard.php';
            }, 600);
            return;
        }

        if (res) {
            papelAlert('❌ ' + papelFailureText(res, 'The paper could not be submitted.'));
        } else {
            /* No JSON in the answer at all, so it came from the host rather than
               from this application. The status says what kind of refusal it was,
               and on a copy of the site so do the words in it. */
            const plain = String(xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            papelAlert('❌ The server did not answer properly (HTTP ' + xhr.status + ').'
                + (SHOW_TECH_DETAIL && plain ? ' Server said: "' + plain.slice(0, 160) + '".' : '')
                + ' Nothing was submitted. Please try again.');
        }
        overlay.classList.remove('active');
        btn.disabled = false;
        fillEl.style.width = '0%';
    });

    xhr.addEventListener('error', function(){
        clearInterval(msgTimer);
        papelAlert('❌ Network error');
        overlay.classList.remove('active');
        btn.disabled = false;
        fillEl.style.width = '0%';
    });

    xhr.open('POST', 'student_upload_ai.php');
    xhr.send(fd);
});

/* ----- Corpus's greeting lines -----
   A few lines that rise out of the button one at a time (the look is
   .chat-nudge, in the stylesheet). It is a nudge rather than a notice, so it is
   built to give up easily: it plays a few rounds and stops, and opening the
   chat, the cross, or Escape pressed while a line is up each end it for the
   page.

   Ending it is not remembered across a reload. This page is one long form that
   a student rarely reloads, so remembering bought almost nothing, and a
   remembered "no" reads as a greeting that has stopped working: nothing on the
   page says it was switched off, and in a tab that had once had a stray click
   on the cross it never came back. */
const CHAT_NUDGE = {
    lines: [
        'Hello!',
        "I'm your upload guide.",         // no name on purpose: it speaks from Corpus's own button
        'Do you need help?',
        'Not sure what to attach? Ask me!',
        'I can explain Ethics Clearance and Consent Forms.'
    ],
    firstDelay: 2500,    // after the page is up, so it never competes with the load
    showFor:    4200,
    between:    900,     // long enough for one bubble to leave before the next arrives
    rest:       45000,   // the quiet spell between rounds
    rounds:     3,
    recheck:    600,     // how often a held-back line looks to see if the way is clear
    hoverGrace: 1500     // a line the pointer is resting on stays until it has left
};

const chatNudge = (function () {
    let timer = null;
    let index = 0;       // the next line within the round
    let round = 0;       // rounds played to the end
    let stopped = false;

    function bubble() { return document.getElementById('chat-nudge'); }
    function showing() { const n = bubble(); return !!n && n.classList.contains('is-on'); }
    function later(fn, ms) { clearTimeout(timer); timer = setTimeout(fn, ms); }

    /* Whether the student can see the button, which is where the line comes
       from. Asked of the screen rather than of a list of things that might be in
       the way, because the list never stops growing: the splash that covers the
       first load of a visit, a dialog, the upload overlay, the PDF expanded to
       fill the page (which hides the widget outright), the chat itself once it
       is open. Whatever it is, the button is no longer the topmost thing at its
       own centre. A line that would have played into that is held back, not
       spent, so "Hello!" is not used up under the splash. */
    function canShow() {
        if (document.hidden) return false;
        const btn = document.getElementById('chat-button');
        const r = btn ? btn.getBoundingClientRect() : null;
        if (!r || !r.width) return false;
        const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return !!top && btn.contains(top);
    }

    function leave() {
        const n = bubble();
        if (n) n.classList.remove('is-on');
        index++;
        if (index < CHAT_NUDGE.lines.length) { later(step, CHAT_NUDGE.between); return; }
        index = 0;
        round++;
        if (round < CHAT_NUDGE.rounds) later(step, CHAT_NUDGE.rest);
        else stopped = true;
    }

    function step() {
        if (stopped) return;
        if (!canShow()) { later(step, CHAT_NUDGE.recheck); return; }
        document.getElementById('chat-nudge-text').textContent = CHAT_NUDGE.lines[index];
        bubble().classList.add('is-on');
        later(leave, CHAT_NUDGE.showFor);
    }

    function stop() {
        stopped = true;
        clearTimeout(timer);
        const n = bubble();
        if (n) n.classList.remove('is-on');
    }

    function start() {
        const n = bubble();
        if (!n) return;

        /* The bubble opens the chat, as the button under it does. The cross sits
           inside it, so it is told apart here rather than given a listener that
           would also have to stop the click reaching the bubble. */
        n.addEventListener('click', function (e) {
            if (e.target.closest('#chat-nudge-x')) stop();
            else toggleChat();
        });
        // Someone reading the line, or reaching for it, should not have it taken away.
        n.addEventListener('mouseenter', function () { if (showing()) clearTimeout(timer); });
        n.addEventListener('mouseleave', function () { if (showing() && !stopped) later(leave, CHAT_NUDGE.hoverGrace); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && showing()) stop();
        });

        later(step, CHAT_NUDGE.firstDelay);
    }

    return { start: start, stop: stop };
})();

// Chatbot Logic
function toggleChat() {
    const win = document.getElementById('chat-window');
    const btn = document.getElementById('chat-button');

    if (win.style.display === 'flex') {
        win.style.display = 'none';
        btn.style.display = 'flex';
        applyChatDock(false, currentChatSide());   // release the page padding
    } else {
        chatNudge.stop();      // they have found Corpus; no more lines
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
    return !!(window.papelPdfDock && window.papelPdfDock.isOpen());
}

/* The panel says when it takes the right edge and when it gives it back. A chat
   docked there moves to the left rather than being closed, so the conversation
   is kept; the side switch is greyed out until the edge is free again. */
document.addEventListener('papel:pdf-dock-open', function () {
    const widget = document.getElementById('chat-widget');
    if (widget && widget.classList.contains('dock-right')) applyChatDock(true, 'left');
    else syncChatSideAvailability();
});
document.addEventListener('papel:pdf-dock-close', function () { syncChatSideAvailability(); });

// Greys out the chat's "move to the other side" control while the right edge
// is taken, so the restriction is visible rather than a click that does nothing.
function syncChatSideAvailability() {
    const sideBtn = document.getElementById('chatSideBtn');
    if (!sideBtn) return;
    const blocked = pdfPreviewOpen();
    sideBtn.disabled = blocked;
    sideBtn.classList.toggle('is-blocked', blocked);
    /* Labelled for what it will do once free again, as applyChatDock labels it:
       otherwise "in use by the PDF preview" stayed on it after the preview
       closed, until the chat itself next moved. */
    const l = blocked
        ? 'The right side is in use by the PDF preview'
        : (currentChatSide() === 'left' ? 'Move to right side' : 'Move to left side');
    sideBtn.title = l;
    sideBtn.setAttribute('aria-label', l);
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

    // Docking implies the panel is open: otherwise the floating button is
    // hidden while the window is still closed and nothing is visible at all.
    if (docked) {
        const win = document.getElementById('chat-window');
        const btn = document.getElementById('chat-button');
        if (win) win.style.display = 'flex';
        if (btn) btn.style.display = 'none';
        chatNudge.stop();
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

/* Where the top band currently ends.
 *
 * The docked panel starts below the navbar and the breadcrumb strip together,
 * which is right while the page is at the top. Only the navbar is sticky
 * though: scroll down and the strip leaves, and a panel still starting where
 * the strip used to be hangs 40px below the bar with a band of empty page
 * above it. So the figure is taken from the elements themselves on each scroll
 * rather than added up once, and the panel rides up to meet the bar.
 *
 * Written to a custom property rather than to the panel, so the CSS keeps the
 * fallback for anyone this script never reaches. Reads are batched into a
 * frame: a scroll handler that measures on every event is a scroll handler
 * that stutters.
 */
function placeChatDockTop() {
    const bar    = document.getElementById('siteHeader');
    const crumb  = document.querySelector('.crumb-bar');
    const widget = document.getElementById('chat-widget');
    if (!bar || !widget) return;
    let edge = bar.getBoundingClientRect().bottom;
    if (crumb) edge = Math.max(edge, crumb.getBoundingClientRect().bottom);
    /* On the panel itself, not on <html>: a custom property on the root is
       inherited by every element, so each change made the browser re-check the
       style of the whole page, and with a thesis open in the PDF panel that was
       50 to 100ms a step, on every step of a scroll. Only the chat reads it. */
    const px = Math.max(0, Math.round(edge)) + 'px';
    if (widget.style.getPropertyValue('--chat-dock-top') !== px) {
        widget.style.setProperty('--chat-dock-top', px);
    }
}

(function watchChatDockTop() {
    let queued = false;
    function onMove() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; placeChatDockTop(); });
    }
    placeChatDockTop();
    document.addEventListener('DOMContentLoaded', placeChatDockTop);
    /* Any scroll, heard on the way down: the window's own event never comes
       when the page scrolls inside <body>, and the panel then kept the top it
       started with (the PDF panel had the same fault; see includes/pdf_dock.php). */
    document.addEventListener('scroll', onMove, { capture: true, passive: true });
    window.addEventListener('resize', onMove);
})();

/* Corpus rests above the footer, not on it.
 *
 * He floats in the corner of the window, so once the footer is in view (a short
 * page shows it all the time, a long one at its end) he would sit on it, across
 * its text. He is lifted by as much of the footer as is showing, which leaves
 * him the same gap above it as he keeps from the bottom of the window. The
 * footer is also in front of him in the stacking order (see the .site-footer
 * rule above #chat-widget), so while this catches up with a quick scroll he goes
 * behind the footer, never over it.
 *
 * Written to a custom property on the widget. The floating chat adds it to its
 * bottom margin; the docked panel uses it as its bottom edge, so it ends at the
 * footer's top (the footer runs the full width in front of it) rather than
 * running on down beside the footer. */
(function watchChatLift() {
    function place() {
        const widget = document.getElementById('chat-widget');
        const footer = document.querySelector('.site-footer');
        if (!widget || !footer) return;
        const lift = Math.max(0, Math.round(window.innerHeight - footer.getBoundingClientRect().top)) + 'px';
        // This runs on every scroll; nothing is written unless the figure has moved.
        if (widget.style.getPropertyValue('--chat-lift') === lift) return;
        widget.style.setProperty('--chat-lift', lift);
    }
    place();
    document.addEventListener('DOMContentLoaded', place);
    /* Any scroll, heard on the way down: the window's own event never comes when
       the page scrolls inside <body> (see watchChatDockTop above). */
    document.addEventListener('scroll', place, { capture: true, passive: true });
    window.addEventListener('resize', place);
    // A step of the form, or a section folding, moves the footer without a scroll.
    if (window.ResizeObserver) new ResizeObserver(place).observe(document.body);
})();

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
   Two engines, four moods. The engine in use picks the colour (1 Aincrad is the
   maroon dog, 2 Alfheim the yellow) and the conversation picks the mood, so the
   face is rebuilt whenever either changes. Which engine that is comes back with
   each reply rather than from a dropdown: nobody chooses it now, and the colour
   is the only place it shows. All eight are preloaded: swapping src on a cold
   cache leaves a blank frame mid-conversation, which reads as the widget
   breaking rather than as the dog thinking. */
const PUPPY_BASE    = <?= json_encode(BASE_URL . '/assests/images/', JSON_UNESCAPED_SLASHES) ?>;
const PUPPY_COLOURS = { '1': 'red', '2': 'yellow' };
const PUPPY_STATES  = ['welcome', 'processing', 'successful', 'alert'];

Object.keys(PUPPY_COLOURS).forEach(function (k) {
    PUPPY_STATES.forEach(function (st) {
        new Image().src = PUPPY_BASE + 'papel_' + PUPPY_COLOURS[k] + '_' + st + '.gif';
    });
});

let puppyRevertTimer = null;

let puppyEngine = '1';                       // told by the server with each reply

function puppyColour() {
    return PUPPY_COLOURS[puppyEngine] || 'red';
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

/* ----- "Text loading" -----
   The dots that stand in for an answer while it is being fetched. They are put
   where the reply will appear, which is the only place the student is looking
   once they have pressed send; the request itself opts out of the bar at the
   top of the page (papelQuiet, loading_bar.php) so the same wait is not
   reported twice in two different corners of the window.

   Counted rather than flagged, for the same reason loading_bar.php counts its
   own: pressing Enter twice sends two messages, and the first reply back must
   not take the dots down while the second is still coming. */
const CHAT_TYPING_ID = 'chat-typing';
let chatTypingJobs = 0;

/* Held even when the reply is already in hand. An answer that lands in the same
   instant as the question reads as a canned string rather than as something
   written, and the bubble is worth nothing if it is never seen. */
const CHAT_TYPING_MIN = 5;

function showChatTyping() {
    chatTypingJobs++;
    const container = document.getElementById('chat-messages');
    const since = Date.now();
    if (!container || document.getElementById(CHAT_TYPING_ID)) return since;

    const row = document.createElement('div');
    row.className = 'msg-row';
    row.id = CHAT_TYPING_ID;

    /* The thinking face, and a mood the repaint understands: an engine that
       changes mid-wait swaps the colour of this avatar too, rather than
       leaving the dots signed by the other dog. */
    const face = document.createElement('img');
    face.className = 'msg-avatar';
    face.alt = '';
    face.dataset.mood = 'processing';
    face.src = PUPPY_BASE + 'papel_' + puppyColour() + '_processing.gif';

    const bubble = document.createElement('div');
    bubble.className = 'message bot is-typing';
    // Three bare spans say nothing to a screen reader on their own.
    bubble.setAttribute('role', 'status');
    bubble.setAttribute('aria-label', 'Corpus is typing');
    for (let i = 0; i < 3; i++) {
        const dot = document.createElement('span');
        dot.className = 'typing-dot';
        bubble.appendChild(dot);
    }

    row.appendChild(face);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
    return since;
}

function hideChatTyping() {
    chatTypingJobs = chatTypingJobs > 0 ? chatTypingJobs - 1 : 0;
    if (chatTypingJobs > 0) return;
    const row = document.getElementById(CHAT_TYPING_ID);
    if (row) row.remove();
}

/* Whatever is left of CHAT_TYPING_MIN since the dots went up; a reply that was
   already slower than that waits no longer. */
function chatTypingFloor(since) {
    const left = CHAT_TYPING_MIN - (Date.now() - since);
    return left > 0 ? new Promise(function (r) { setTimeout(r, left); }) : Promise.resolve();
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
    const typingSince = showChatTyping();

    /* What came back is settled first and written afterwards, so there is one
       place the dots come down and it is always the line before the answer
       goes up. Removing them from two branches left the reply sitting above
       its own "typing" bubble for a frame. */
    let reply = null;
    let mood = null;
    try {
        /* student_assistant.php, not student_chatbot.php: free hosting's
           firewall answers 403 to any path containing "bot", so PUPPY could
           not reach its own endpoint and every message came back as
           "Connection error". Keep "bot" out of filenames and URLs. */
        const res = await fetch('student_assistant.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: msg}),
            /* The dots in the panel are this request's progress report; the
               bar at the top of the page stays out of it. loading_bar.php */
            papelQuiet: true
        });
        const data = await res.json();
        await puppyFinishThinking(thinkingSince);
        await chatTypingFloor(typingSince);
        /* The engine can change under us, when the one that was answering runs
           out and the other takes over. The dog changes colour with it. */
        if (data && data.engine && String(data.engine) !== puppyEngine) {
            puppyEngine = String(data.engine);
            repaintMessageAvatars();
        }
        // A 200 carrying no reply is still a failure to the student, so the dog
        // reacts to what actually came back rather than to the status code.
        if (data && data.reply) {
            reply = data.reply;
        } else {
            reply = 'Error processing request';
            mood = 'alert';
        }
    } catch (e) {
        console.error('Chatbot Error:', e);
        await puppyFinishThinking(thinkingSince);
        await chatTypingFloor(typingSince);
        reply = 'Connection error';
        mood = 'alert';
    }

    hideChatTyping();
    addMessage(reply, 'bot', mood);
    flashPuppyState(mood ? 'alert' : 'successful', mood ? 4000 : 3200);
}

/* Switching model mid-conversation would otherwise leave the transcript in the
   colour each line was written in, which reads as two different dogs answering. */
function repaintMessageAvatars() {
    document.querySelectorAll('.msg-avatar').forEach(function (img) {
        img.src = PUPPY_BASE + 'papel_' + puppyColour() + '_' + (img.dataset.mood || 'welcome') + '.gif';
    });
}

/* The model answers in Markdown. Rendered as one run of text with <br>s, as it
   used to be, a bulleted answer was a wall of "•" lines and there was nothing
   for CSS to space, so it is built into real paragraphs and lists instead.
   Every line is escaped before any tag is added, and the only tags added are
   the fixed ones below, so nothing the model writes can become markup. */
function escapeChatText(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function formatChatInline(s) {
    return escapeChatText(s)
        .replace(/\\\*/g, '*')                                  // the model sometimes escapes its own stars
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/__(.+?)__/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*(?!\s)([^*]+?)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>');
    // Single underscores are left alone: file names like Consent_Form.pdf
    // would otherwise be italicised mid-word.
}

function formatBotReply(text) {
    const out = [];
    let para = [];
    let list = null;   // { type: 'ul'|'ol', start, items: [{ html, sub: [] }] }

    function flushPara() {
        if (para.length) { out.push('<p>' + para.join('<br>') + '</p>'); para = []; }
    }
    function flushList() {
        if (!list) return;
        const start = list.type === 'ol' && list.start > 1 ? ' start="' + list.start + '"' : '';
        out.push('<' + list.type + start + '>' + list.items.map(function (it) {
            const sub = it.sub.length ? '<ul>' + it.sub.map(function (s) { return '<li>' + s + '</li>'; }).join('') + '</ul>' : '';
            return '<li>' + it.html + sub + '</li>';
        }).join('') + '</' + list.type + '>');
        list = null;
    }
    function lastItem() { return list && list.items.length ? list.items[list.items.length - 1] : null; }

    text.replace(/\r\n?/g, '\n').split('\n').forEach(function (raw) {
        const indent = raw.match(/^\s*/)[0].length;
        const line = raw.trim();
        let m;
        /* A blank line ends a paragraph but not a list: the model often puts
           one between numbered steps, and closing the list there restarted
           the count at 1 for every step. */
        if (!line) { flushPara(); return; }

        if ((m = line.match(/^#{1,6}\s+(.*)$/))) {
            flushPara(); flushList();
            out.push('<p class="msg-head">' + formatChatInline(m[1]) + '</p>');
            return;
        }
        if ((m = line.match(/^[-*•]\s+(.*)$/))) {
            flushPara();
            // Indented under an item: a sub-point of it, not a new list.
            if (indent > 0 && lastItem()) { lastItem().sub.push(formatChatInline(m[1])); return; }
            if (!list || list.type !== 'ul') { flushList(); list = { type: 'ul', items: [] }; }
            list.items.push({ html: formatChatInline(m[1]), sub: [] });
            return;
        }
        if ((m = line.match(/^(\d+)[.)]\s+(.*)$/))) {
            flushPara();
            if (!list || list.type !== 'ol') { flushList(); list = { type: 'ol', start: +m[1], items: [] }; }
            list.items.push({ html: formatChatInline(m[2]), sub: [] });
            return;
        }
        // An indented plain line carries on the item above it.
        if (indent > 0 && lastItem()) { lastItem().html += '<br>' + formatChatInline(line); return; }
        flushList();
        para.push(formatChatInline(line));
    });
    flushPara(); flushList();
    return out.join('');
}

function addMessage(text, sender, mood) {
    const div = document.createElement('div');
    div.className = `message ${sender}`;
    div.id = 'msg-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    // What the student typed is shown as typed; only the dog's answers are Markdown.
    div.innerHTML = sender === 'bot'
        ? formatBotReply(text)
        : escapeChatText(text).replace(/\n/g, '<br>');
    const container = document.getElementById('chat-messages');
    if (sender === 'bot') {
        /* The face is the dog's resting one on a normal answer and the worried
           one on a failure, so scrolling back shows which replies went wrong
           without having to re-read them. */
        const row = document.createElement('div');
        row.className = 'msg-row';
        const face = document.createElement('img');
        face.className = 'msg-avatar';
        face.alt = 'Corpus';
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

    // The way back to the question of which sections to submit, at any time.
    const changeScopeBtn = document.getElementById('btnChangeScope');
    if (changeScopeBtn) changeScopeBtn.addEventListener('click', function () { askSectionScope(true); });
    // The editors exist by now, so the page can be put in line with the field's value.
    syncSectionScope();

    // Manual entry button
    document.getElementById('btnManual').addEventListener('click', function() {
        if (!extractionSource()) {
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

    /* Closing or reloading the tab. The save happens either way: the prompt
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

       (Closing or reloading the tab is a different matter: browsers show
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
    document.querySelectorAll('.js-pdf').forEach(function (input) {
        input.addEventListener('change', function () { updatePdfIndicator(this); });
        // Clicking the chosen file opens it in the side panel.
        pdfIndicator(input.dataset.format).addEventListener('click', function () {
            if (!this.classList.contains('selected')) return;
            papelPdfPreview.toggle(input.dataset.format);
        });
    });
    // The boxes follow the manuscript types ticked in step 1.
    document.querySelectorAll('.js-manuscript').forEach(function (box) {
        box.addEventListener('change', syncPdfSlots);
    });
    syncPdfSlots();

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
           of them. Its own handler runs before this one and has already closed
           it, taking the "open" class with it, so the class alone was never
           going to be seen here and one Escape closed the dialog and the
           preview behind it. The handler prevents the default when it acts;
           that is what is checked. The class stays for a dialog opened later. */
        if (e.defaultPrevented) return;
        const openDialog = document.getElementById('papelDialog');
        if (openDialog && openDialog.classList.contains('open')) return;

        /* The PDF preview is the most recently opened surface, so Escape belongs
           to it: includes/pdf_dock.php closes it. Nothing else moves on the same
           key, the chat included. */
        if (pdfPreviewOpen()) return;
        const widget = document.getElementById('chat-widget');
        if (widget && widget.classList.contains('is-docked')) {
            e.preventDefault();
            applyChatDock(false, currentChatSide());
        }
    });
    document.getElementById('chat-button').addEventListener('click', toggleChat);
    chatNudge.start();

    // Chat form submit
    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage();
    });
});

// Load publication location suggestions via AJAX
document.addEventListener('DOMContentLoaded', () => {
    fetch('student_upload_ai.php?action=get_locations',
          { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
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
    var isChoose = false;     // a list of options to tick, opened by papelChoose
    var picker = null;        // what that list currently holds

    /* Whatever opens the box starts from a plain one. A choice left half open by
       a second dialog taking the box over must not leave the next notice wearing
       its width, or answering with its options. */
    function resetMode() {
        isChoose = false;
        picker = null;
        dialogEl.classList.remove('is-choose');
    }

    function close(value) {
        backdrop.classList.remove('open');
        dialogEl.classList.remove('is-wide');
        dialogEl.classList.remove('is-choose');
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
        isChoose = false;
        picker = null;
        okBtn.textContent = 'OK';
        cancelBtn.textContent = 'Cancel';
    }

    window.papelAlert = function (message, opts) {
        opts = opts || {};
        resetMode();
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
       with the typed string, or null when cancelled, so callers can tell
       "cleared the field" apart from "changed their mind". */
    window.papelPrompt = function (message, opts) {
        opts = opts || {};
        resetMode();
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
        resetMode();
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

    /* A list of options to tick, in the same box as every other question: head,
       rule, body and buttons are the ones the rest use, and only the body differs.
       It resolves with the keys that are ticked, in the order they were offered.

       opts.sections  [{ key, label, hint, locked }]   locked ones are always ticked
       opts.selected  keys ticked to begin with
       opts.lead      a sentence above the choices
       opts.describe  function(keys) giving the line that says what the choice means

       Everything written into the box is set as text, never as markup. */
    function buildPicker(opts) {
        var sections = opts.sections || [];
        var on = {};
        (opts.selected || []).forEach(function (k) { on[k] = true; });
        sections.forEach(function (s) { if (s.locked) on[s.key] = true; });

        var root = document.createElement('div');
        root.className = 'scope-pick';

        if (opts.lead) {
            var lead = document.createElement('p');
            lead.className = 'scope-pick-lead';
            lead.textContent = opts.lead;
            root.appendChild(lead);
        }

        var presets = document.createElement('div');
        presets.className = 'scope-pick-presets';
        presets.setAttribute('role', 'group');
        presets.setAttribute('aria-label', 'Quick choices');
        var presetButtons = [
            { name: 'Abstract only', keys: sections.filter(function (s) { return s.locked; }).map(function (s) { return s.key; }) },
            { name: 'All sections',  keys: sections.map(function (s) { return s.key; }) }
        ].map(function (p) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'scope-preset';
            b.textContent = p.name;
            b.addEventListener('click', function () {
                boxes.forEach(function (box) { box.checked = p.keys.indexOf(box.value) !== -1; });
                refresh();
            });
            presets.appendChild(b);
            return { el: b, keys: p.keys };
        });
        root.appendChild(presets);

        var list = document.createElement('fieldset');
        list.className = 'scope-pick-list';
        var legend = document.createElement('legend');
        legend.className = 'visually-hidden';
        legend.textContent = 'Sections to submit';
        list.appendChild(legend);

        var boxes = [];
        sections.forEach(function (s) {
            var row = document.createElement('label');
            row.className = 'scope-opt' + (s.locked ? ' is-locked' : '');
            var box = document.createElement('input');
            box.type = 'checkbox';
            box.value = s.key;
            box.checked = !!on[s.key];
            if (s.locked) box.disabled = true;
            box.addEventListener('change', refresh);
            var name = document.createElement('span');
            name.className = 'scope-opt-name';
            name.textContent = s.label;
            var hint = document.createElement('span');
            hint.className = 'scope-opt-hint';
            hint.textContent = s.hint || '';
            row.appendChild(box);
            row.appendChild(name);
            row.appendChild(hint);
            list.appendChild(row);
            boxes.push(box);
        });
        root.appendChild(list);

        var sum = document.createElement('p');
        sum.className = 'scope-pick-sum';
        sum.setAttribute('aria-live', 'polite');
        root.appendChild(sum);

        function read() {
            return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
        }
        function refresh() {
            var keys = read();
            boxes.forEach(function (b) { b.parentNode.classList.toggle('is-on', b.checked); });
            // A quick choice is lit while it describes exactly what is ticked.
            presetButtons.forEach(function (p) {
                var same = p.keys.length === keys.length && p.keys.every(function (k) { return keys.indexOf(k) !== -1; });
                p.el.classList.toggle('is-on', same);
            });
            sum.textContent = opts.describe ? opts.describe(keys) : '';
        }
        refresh();

        return { root: root, read: read };
    }

    window.papelChoose = function (opts) {
        opts = opts || {};
        picker = buildPicker(opts);
        msgEl.textContent = '';
        msgEl.appendChild(picker.root);
        dialogEl.classList.remove('is-wide');
        dialogEl.classList.add('is-choose');
        titleEl.textContent = opts.title || 'Choose';
        iconEl.textContent = opts.icon || 'checklist';

        okBtn.textContent = opts.confirmText || 'Continue';
        cancelBtn.hidden = true;
        altBtn.hidden = true;
        inputWrap.hidden = true;
        isPrompt = false;
        isConfirm = false;
        isChoose = true;

        lastFocus = document.activeElement;
        lastScrollY = window.scrollY;
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
        okBtn.focus();
        return new Promise(function (resolve) { resolver = resolve; });
    };

    /* What each way of closing answers. The confirming button and Enter give the
       real answer; Escape and a click outside the box are "no". A choice has no
       "no": closing it keeps what is ticked, which starts as everything, so
       nothing is lost by closing it, and a person is never left unable to leave. */
    function answer() {
        if (isChoose && picker) return picker.read();
        if (isPrompt) return inputEl.value.trim();
        return isConfirm ? true : undefined;
    }
    function dismiss() {
        if (isChoose && picker) return picker.read();
        if (isConfirm) return false;
        return isPrompt ? null : undefined;
    }

    okBtn.addEventListener('click', function () { close(answer()); });
    cancelBtn.addEventListener('click', function () { close(isConfirm ? false : null); });
    altBtn.addEventListener('click', function () { close('alt'); });
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) close(dismiss());
    });
    document.addEventListener('keydown', function (e) {
        if (!backdrop.classList.contains('open')) return;
        if (e.key === 'Escape') { e.preventDefault(); close(dismiss()); }
        else if (e.key === 'Enter') {
            // A quick-choice button is pressed with Enter like any button, and
            // answering the whole question over its head would make it unusable
            // from the keyboard.
            var focused = document.activeElement;
            if (isChoose && focused && focused.classList && focused.classList.contains('scope-preset')) return;
            e.preventDefault();
            close(answer());
        }
    });
})();
</script>

<?php
/* The PDF panel every paper page uses (see "PDF preview" in the script above).
   After the page's own dialog, so the dialog's Escape is handled first. It
   docks on the right only: this page has no section to give it a home, and a
   home would leave it in Step 2 while the student is on Step 3 or 4. The tab on
   the right edge brings it back from any step instead. */
$PDF_DOCK_MINIMISE = true;
require ROOT_PATH.'/includes/pdf_dock.php';
?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>