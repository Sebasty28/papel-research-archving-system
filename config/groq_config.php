<?php
// Load Composer's autoloader so we can use external libraries like PDF parser
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Load .env and define all Groq constants (runs once)
if (!defined('GROQ_API_KEY')) {
    if (!function_exists('load_env')) {
        function load_env($file = __DIR__ . '/.env') {
            if (!file_exists($file)) return;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value);
                }
            }
        }
    }

    if (file_exists(__DIR__ . '/.env')) {
        load_env(__DIR__ . '/.env');
    } elseif (file_exists(__DIR__ . '/../.env')) {
        load_env(__DIR__ . '/../.env');
    }

    define('GROQ_API_KEY',            $_ENV['GROQ_API_KEY']            ?? '');
    define('GROQ_API_KEY_UPLOAD',     $_ENV['GROQ_API_KEY_UPLOAD']     ?? GROQ_API_KEY);
    define('GROQ_API_KEY_SUPERADMIN', $_ENV['GROQ_API_KEY_SUPERADMIN'] ?? GROQ_API_KEY);
    define('GROQ_API_KEY_ADMIN',      $_ENV['GROQ_API_KEY_ADMIN']      ?? GROQ_API_KEY);
    define('GROQ_API_KEY_FACULTY',    $_ENV['GROQ_API_KEY_FACULTY']    ?? GROQ_API_KEY);
    define('GROQ_API_KEY_STUDENT',    $_ENV['GROQ_API_KEY_STUDENT']    ?? GROQ_API_KEY);
    define('GROQ_API_KEY_CHATBOT',    $_ENV['GROQ_API_KEY_CHATBOT']    ?? GROQ_API_KEY);
    define('GROQ_API_KEY_CHATBOT_2',  $_ENV['GROQ_API_KEY_CHATBOT_2']  ?? GROQ_API_KEY);
    define('GROQ_API_KEY_UPLOAD_2',   $_ENV['GROQ_API_KEY_UPLOAD_2']   ?? GROQ_API_KEY);
    define('GROQ_API_URL',            'https://api.groq.com/openai/v1/chat/completions');
    // Longest pause worth taking when Groq asks us to wait out a rate limit.
    // Beyond this the caller is better off being told than left hanging.
    define('GROQ_RETRY_MAX_WAIT',     12);
    // Llama 3.3 70B Versatile was decommissioned on Groq (2026-08-16).
    // Override with GROQ_MODEL in .env if the model id changes again: every
    // call site reads this constant, so that is a one-line change.
    define('GROQ_MODEL',              $_ENV['GROQ_MODEL'] ?? 'openai/gpt-oss-120b');
}

/* ===== The two AI engines =====
   Aincrad and Alfheim are not two models. They are one model reached on two
   API keys, and a key carries its own usage window, so when one is spent the
   other still answers.

   Which of the two is in use is no longer a question put to the student: it is
   kept here, in the session, and flipped the moment a call is refused for the
   quota. The next call then starts on the engine that has room rather than on
   the one that has just run out. The names stay because the log lines, and
   anyone reading this code, need a way to say which key is meant. */
function ai_engine_names(): array {
    return [1 => 'Aincrad', 2 => 'Alfheim'];
}

/* The endpoints that use this do not all go through core.php. */
function ai_engine_session(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    if (function_exists('start_session_once')) { start_session_once(); return; }
    @session_start();
}

function ai_engine_current(): int {
    ai_engine_session();
    return ((int)($_SESSION['ai_engine'] ?? 1)) === 2 ? 2 : 1;
}

function ai_engine_name(?int $engine = null): string {
    $names = ai_engine_names();
    return $names[$engine ?? ai_engine_current()] ?? $names[1];
}

/* Called when an engine has had to hand a call over: the one that answered is
   the one to start from next time. */
function ai_engine_switch(): int {
    ai_engine_session();
    $was  = ai_engine_current();
    $next = $was === 1 ? 2 : 1;
    $_SESSION['ai_engine'] = $next;
    error_log(sprintf('AI engine: %s is spent for now, switching to %s.',
                      ai_engine_name($was), ai_engine_name($next)));
    return $next;
}

/**
 * Did the last call have to fall back to the other key?
 *
 * call_groq_api swaps keys inside one request, so the caller sees a success
 * and cannot otherwise tell that the engine it asked for had nothing left.
 * Read by the pages that keep the session on whichever engine is answering.
 */
function groq_used_fallback($set = null) {
    static $used = false;
    if ($set !== null) $used = (bool)$set;
    return $used;
}

/**
 * Sends a request to Groq AI API and gets a response
 * Think of this as asking the AI a question and waiting for an answer
 * 
 * @param string $systemPrompt - Tells the AI what role to play (e.g., "You are a research expert")
 * @param string $userPrompt - The actual question or task we want the AI to do
 * @param int $maxTokens - Maximum length of AI's response (tokens are like words)
 * @param string|null $apiKey - Optional specific API key to use
 * @param string|null $fallbackKey - Optional backup API key; auto-retried on HTTP 429 (rate limit)
 * @param bool $jsonMode - Ask the API itself for a raw JSON object (see below)
 * @param int $attempt - Internal: how many times this call has already retried
 * @return array - Returns success/failure status and the AI's response
 */
function call_groq_api($systemPrompt, $userPrompt, $maxTokens = 1024, $apiKey = null, $fallbackKey = null, $jsonMode = false, $attempt = 0) {
    $key = $apiKey ?? (defined('GROQ_API_KEY') ? GROQ_API_KEY : '');

    if (empty($key)) {
        return ['success' => false, 'error' => 'Groq API Key is missing or invalid.'];
    }

    // Prepare the data we're sending to Groq AI
    $payload = [
        'model' => GROQ_MODEL, // Which AI model to use
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt], // AI's instructions
            ['role' => 'user', 'content' => $userPrompt] // Our question
        ],
        'temperature' => 0, // How creative the AI should be (0=strict/consistent, 1=creative)
        'max_tokens' => $maxTokens, // Limit response length
        'top_p' => 1,
        'stream' => false // Get complete response at once, not word-by-word
    ];

    /* Ask the API for a raw JSON object rather than trusting the model to
       return one. Without this, the callers that parse JSON have to strip
       markdown fences and reasoning preambles: habits that differ per model,
       so a model swap can silently break parsing instead of failing loudly. */
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    // Initialize cURL to make HTTP request to Groq API
    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, // Return response as string
        CURLOPT_POST => true, // Use POST method
        // Substituted rather than refused: json_encode() returns false on one bad byte, and the request then goes out empty.
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($key) // Authenticate with API key
        ],
        CURLOPT_SSL_VERIFYPEER => true, // Verify SSL certificate for security
        CURLOPT_SSL_VERIFYHOST => 2,
        /* Under the host's own ceiling, deliberately. Free hosting cuts a
           request off at about 30 seconds and answers with its own page,
           which carries no JSON and so tells the student nothing. Giving up
           first means PHP is still alive to say what happened in the shape
           the page can read. */
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8
    ]);

    // Execute the request and get response
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch); // suppress PHP 8.5 deprecation: curl handle auto-closes on GC

    // Check if there was a connection error
    if ($curlError) {
        error_log("Groq CURL Error: $curlError");
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }

    /* ── Rate limits (429) ────────────────────────────────────────────────
       Swapping to the backup key was the only response here, which does not
       help for the limit that actually bites: tokens-per-minute is counted
       per *organisation*, so the second key runs into the identical ceiling
       and the retry is spent for nothing. One upload sends a metadata call
       and an analysis call back to back, which together can exceed a free
       tier's TPM, and the analysis then came back empty.

       Groq's reply says exactly how long to wait, so short waits are honoured
       before falling back to the key swap, which still helps for the per-key
       limits (requests per minute or per day). */
    if ($httpCode === 429) {
        $decoded = json_decode((string)$response, true);
        $detail  = (string)($decoded['error']['message'] ?? '');
        $isTpm   = stripos($detail, 'tokens per minute') !== false || stripos($detail, 'TPM') !== false;
        $wait    = preg_match('/try again in ([0-9.]+)\s*s/i', $detail, $m) ? (float)$m[1] : 0.0;

        if ($attempt < 1 && $wait > 0 && $wait <= GROQ_RETRY_MAX_WAIT) {
            error_log(sprintf('Groq 429 (%s): waiting %.1fs, then retrying.', $isTpm ? 'tokens/min' : 'rate limit', $wait));
            usleep((int)(($wait + 0.25) * 1000000));   // a little past the window
            return call_groq_api($systemPrompt, $userPrompt, $maxTokens, $key, $fallbackKey, $jsonMode, $attempt + 1);
        }

        if (!$isTpm && !empty($fallbackKey) && $fallbackKey !== $key) {
            error_log('Groq 429 on the primary key, retrying with the fallback key.');
            /* Noted for the caller: the engine it asked for is out of room for
               now, whatever this retry comes back with. */
            groq_used_fallback(true);
            return call_groq_api($systemPrompt, $userPrompt, $maxTokens, $fallbackKey, null, $jsonMode, $attempt);
        }

        if ($detail !== '') error_log('Groq 429, giving up: ' . $detail);
    }

    // Check if API returned an error (anything other than 200 OK)
    if ($httpCode !== 200) {
        error_log("Groq API Error: HTTP $httpCode - $response");
        return ['success' => false, 'error' => "API error: HTTP $httpCode", 'http_code' => $httpCode];
    }

    // Parse the JSON response from API
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        // Success! Return the AI's response
        return ['success' => true, 'response' => $result['choices'][0]['message']['content']];
    }

    // Something went wrong with the response format
    return ['success' => false, 'error' => 'Invalid API response'];
}

/**
 * Extracts readable text from a Google Drive PDF
 */
function extract_gdrive_pdf_text($fileId) {
    try {
        require_once __DIR__ . '/gdrive_config.php';
        $client = get_gdrive_client();
        if (!$client->getAccessToken()) return '';
        
        $service = new Google_Service_Drive($client);
        $file = $service->files->get($fileId, ['alt' => 'media']);
        $content = $file->getBody()->getContents();
        
        if (class_exists('\\Smalot\\PdfParser\\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($content);
            $text = $pdf->getText();
        } else {
            $text = '';
            if (preg_match_all('/\(([^)]+)\)/s', $content, $matches)) {
                $text = implode(' ', $matches[1]);
            }
            $text = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $text);
        }
        
        $text = preg_replace('/\s+/', ' ', $text);
        return trim(pdf_text_clip($text, 25000));
    } catch (Exception $e) {
        error_log('GDrive PDF extraction error: ' . $e->getMessage());
        return '';
    }
}

/**
 * Searches for a PDF file in Google Drive by name
 * 
 * @param string $fileName - The name of the file to search for (e.g. paper title)
 * @return string|null - The file ID if found, null otherwise
 */
function search_gdrive_file_by_name($fileName) {
    try {
        require_once __DIR__ . '/gdrive_config.php';
        $client = get_gdrive_client();
        if (!$client->getAccessToken()) return null;
        
        $service = new Google_Service_Drive($client);
        
        // Escape single quotes for the query
        $escapedName = str_replace("'", "\'", $fileName);
        
        // Strategy 1: Search by exact name
        $query = "name = '$escapedName' and mimeType = 'application/pdf' and trashed = false";
        $results = $service->files->listFiles(['q' => $query, 'fields' => 'files(id, name)', 'pageSize' => 1]);
        if (count($results->files)> 0) return $results->files[0]->id;
        
        // Strategy 2: Search by name + .pdf
        if (substr($fileName, -4) !== '.pdf') {
             $query = "name = '" . $escapedName . ".pdf' and mimeType = 'application/pdf' and trashed = false";
             $results = $service->files->listFiles(['q' => $query, 'fields' => 'files(id, name)', 'pageSize' => 1]);
             if (count($results->files)> 0) return $results->files[0]->id;
        }
        
        return null;
    } catch (Exception $e) {
        error_log('GDrive search error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Extracts readable text from a PDF file
 * This is like converting a PDF document into plain text that we can analyze
 * 
 * @param string $filePath - Path to the PDF file on the server
 * @return string - The extracted text content
 */
/**
 * The form's fields, from whatever the model returned.
 *
 * Both extraction paths end here: the one where this server calls the model,
 * and the one where the browser calls a proxy because the host cannot (see
 * AI_PROXY_URL). Keeping the assembly in one place is what stops the two
 * drifting apart, since the page fills the same inputs either way.
 *
 * @param array  $aiMeta   title/authors/year/keywords as the model gave them
 * @param string $abstract Lifted from the paper verbatim, never generated
 * @param string $fallbackAuthor Used when the model found no author at all
 */
function build_metadata_result(array $aiMeta, string $abstract, string $fallbackAuthor): array {
    $authors = $fallbackAuthor;
    if (isset($aiMeta['authors'])) {
        $authors = is_array($aiMeta['authors'])
            ? implode(', ', $aiMeta['authors'])
            : (string)$aiMeta['authors'];
    }

    $keywords = '';
    if (isset($aiMeta['keywords'])) {
        $keywords = is_array($aiMeta['keywords'])
            ? implode(', ', $aiMeta['keywords'])
            : (string)$aiMeta['keywords'];
    }

    return [
        // Empty when the AI found none, so the page leaves the box as it was rather than writing "Untitled".
        'title'                  => is_string($aiMeta['title'] ?? null) ? trim($aiMeta['title']) : '',
        'authors'                => $authors,
        'year'                   => $aiMeta['year'] ?? (int)date('Y'),
        'keywords'               => $keywords,
        'abstract'               => trim($abstract),
        'ai_summary'             => '',
        'ai_methodology'         => '',
        'ai_statistical_methods' => '',
        'ai_variables'           => '',
        'ai_sample_size'         => '',
        'ai_research_field'      => '',
    ];
}

/**
 * Text read from a PDF, made safe to search and to send: invalid UTF-8 replaced, then
 * cut to at most $bytes without splitting a character.
 *
 * substr() counts bytes, and cutting at a fixed byte splits "\u00F1", "\u20B1" or a curly
 * quote in two whenever one falls on the line. The whole text is then invalid UTF-8: every
 * /u pattern reading it fails (the abstract of a paper that plainly had one was not found),
 * and json_encode() returns false, so the request to the AI went out with no body at all
 * and the student was told "The AI could not identify the details in this document".
 */
function pdf_text_clip(string $text, int $bytes): string {
    $text = mb_scrub($text, 'UTF-8');
    return strlen($text) <= $bytes ? $text : mb_strcut($text, 0, $bytes, 'UTF-8');
}

function extract_pdf_text($filePath) {
    try {
        // Try to use the professional PDF parser library if available
        if (class_exists('\Smalot\PdfParser\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText(); // Extract all text from PDF
        } else {
            throw new Exception('PDF parser not available');
        }
        
        // Clean up the text: remove extra spaces, tabs, newlines
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return pdf_text_clip($text, 25000);
        
    } catch (Exception $e) {
        error_log('PDF parsing error: ' . $e->getMessage());
        
        // Fallback method: Try basic text extraction if library fails
        $content = @file_get_contents($filePath); // Read PDF as raw binary
        if (!$content) return ''; // Return empty if file can't be read

        $text = '';
        // Look for text between parentheses in PDF structure (basic extraction)
        if (preg_match_all('/\(([^)]+)\)/s', $content, $matches)) {
            $text = implode(' ', $matches[1]);
        }
        
        // Clean up: remove non-printable characters, keep only readable text
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return pdf_text_clip($text, 20000); // up to 20k bytes for the fallback
    }
}

/**
 * Records, and returns, why the last Groq call failed.
 *
 * Callers only see null when extraction fails, which cannot distinguish "the
 * model could not read this document" from "we are out of quota for the next
 * minute": two problems with very different advice for the student.
 *
 * @param array|null|false $set Pass an array to record, null to clear, omit to read
 * @return array|null ['error' => string, 'http_code' => int] or null
 */
function groq_last_failure($set = false) {
    static $failure = null;
    if ($set !== false) $failure = $set;
    return $failure;
}

/**
 * Uses AI to extract metadata (title, authors, year, etc.) from research paper text
 * This is like having an AI assistant read the paper and pull out key information
 * 
 * @param string $pdfText - The text content extracted from the PDF
 * @param string $modelChoice - Model 1 or 2
 * @return array|null - Returns array with title, authors, year, keywords, abstract or null if failed
 */
function extract_metadata_with_groq($pdfText, $modelChoice = '1') {
    // ── Step 1: Extract abstract directly from PDF text using pattern matching ──
    // The abstract is the text between the "Abstract" heading and the "Keywords" or "Introduction" section
    $directAbstract = extract_abstract_from_text($pdfText);
    
    // ── Step 2: Use AI to extract only title, authors, year, keywords ──
    $systemPrompt = "You are an expert research paper metadata extractor. Extract ONLY the title, authors, year, and keywords from the paper. Do NOT extract or generate an abstract. Return ONLY valid JSON.";
    
    $userPrompt = "Extract metadata from this research paper text.\n\n";
    $userPrompt .= "Required fields (return null if not found):\n";
    $userPrompt .= "- title: The main research paper title (usually on first page, often in larger font or bold)\n";
    $userPrompt .= "- authors: List of author names (look for 'by', 'authors:', or names under title). Return as array.\n";
    $userPrompt .= "- year: Publication or completion year (look for dates, copyright, or 'Year:')\n";
    $userPrompt .= "- keywords: Research keywords (look for 'Keywords:', 'Key terms:', 'Key Words:', or similar). Return as array.\n\n";
    
    $userPrompt .= "Return ONLY this JSON structure:\n";
    $userPrompt .= "{\n";
    $userPrompt .= '  "title": "exact title here",'."\n";
    $userPrompt .= '  "authors": ["Author 1", "Author 2"],'."\n";
    $userPrompt .= '  "year": 2024,'."\n";
    $userPrompt .= '  "keywords": ["keyword1", "keyword2"]'."\n";
    $userPrompt .= "}\n\n";
    
    $userPrompt .= "Research Paper Text (first 8000 characters):\n" . pdf_text_clip((string)$pdfText, 8000);

    /* The engine in play and the other one behind it. Which is which comes
       from ai_engine_current(); nobody picks it on the form any more. */
    if ((string)$modelChoice === '2') {
        $uploadKey = defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null;
        $uploadFallback = defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null;
    } else {
        $uploadKey = defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null;
        $uploadFallback = defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null;
    }
    $result = call_groq_api($systemPrompt, $userPrompt, 1200, $uploadKey, $uploadFallback, true);

    if (!$result['success']) {
        // Remember why, so the page can tell the student whether to wait and
        // retry or to give up and fill the form in by hand.
        groq_last_failure([
            'error'     => $result['error'] ?? 'Unknown error',
            'http_code' => $result['http_code'] ?? 0,
        ]);
        return null;
    }
    groq_last_failure(null);

    // Clean up the AI's response to get pure JSON
    $response = trim($result['response']);
    $response = preg_replace('/```json\s*|\s*```/', '', $response);
    $response = preg_replace('/^[^{]*/', '', $response);
    $response = preg_replace('/[^}]*$/', '', $response);
    
    // Convert JSON string to PHP array
    $metadata = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg() . " Response: " . $response);
        return null;
    }

    // ── Step 3: Set the abstract from our direct extraction (NOT from AI) ──
    $metadata['abstract'] = $directAbstract;

    return $metadata;
}

/**
 * The abstract, copied from the paper's own text, or null when the paper has none
 * that can be read.
 *
 * Never generated: what the repository shows as a paper's abstract has to be the
 * author's words. So when there is no abstract, the answer is null and only the
 * title, authors, year and keywords are filled in (the student writes the Abstract,
 * or leaves it out in Custom). A wrong abstract is worse than none, because it looks
 * like an answer; every rule here was written against a real paper it got wrong:
 *
 *   - a heading with nothing under it (IMRAD-CommEase: "ABSTRACT I. INTRODUCTION",
 *     the abstract itself an image) ran on until a "Keywords" pages later and handed
 *     back the Introduction. Now the text must start as prose and stop at the next
 *     heading.
 *   - the table of contents ("Abstract……………v", "ABSTRACT......5") is not a heading.
 *   - a thesis abstract page opens with Title, Researcher, Degree, Institution, Year
 *     and Adviser (the BSIE feasibility studies), which is not the abstract.
 *   - the page's running head ("POLYTECHNIC UNIVERSITY OF THE PHILIPPINES vi") and
 *     its page number fall in the middle of the text.
 *
 * @param string $pdfText Text of the PDF, as extract_pdf_text() gives it
 * @return string|null
 */
function extract_abstract_from_text($pdfText) {
    if (!is_string($pdfText) || strlen($pdfText) < 50) return null;
    // Invalid UTF-8 would make every pattern below fail (pdf_text_clip()).
    $pdfText = mb_scrub($pdfText, 'UTF-8');
    // Zero-width spaces come out of some PDFs between every word of a heading.
    $text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $pdfText) ?? $pdfText;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    if (!preg_match_all('/\b(?:ABSTRACT|Abstract)\b\s*[:.\-\x{2013}\x{2014}]?\s*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    foreach ($m[0] as [$heading, $at]) {
        /* By characters, not bytes: a byte count can cut a letter like the n with a
           tilde, or the peso sign, in two, and every /u pattern after it then fails. */
        $rest = mb_substr(substr($text, $at + strlen($heading)), 0, 8000);
        // A line of the table of contents: leader dots, then a page number.
        if (preg_match('/^[\s.\x{2026}\x{00B7}_]*[.\x{2026}\x{00B7}_]{3,}/u', $rest)) continue;

        $body = abstract_strip_header_block($rest);
        $body = abstract_cut_at_end($body);
        $body = abstract_clean($body);
        if ($body === null) continue;
        return $body;
    }
    return null;
}

/**
 * Takes off a thesis abstract page's opening block: "Title : ... Researcher : ...
 * Degree : ... Adviser : Engr. Gio M. Marcos", the abstract proper following straight
 * on. Two such labels at least, so a sentence that happens to start "Title:" is left.
 */
function abstract_strip_header_block(string $s): string {
    $label = '(?:Title|Researchers?|Authors?|Proponents?|Students?|Degree|Program(?:me)?|Course|Major|'
           . 'Specialization|Institution|School|College|Campus|University|Department|'
           . '(?:Academic |School )?Year|Semester|Date(?: Completed)?|Advis[eo]rs?|Research Advis[eo]r)';
    if (!preg_match_all('/(?:^|\s)' . $label . '\s*:\s*/u', mb_substr($s, 0, 1500), $lm, PREG_OFFSET_CAPTURE)) return $s;
    // The block opens the text, and its labels follow one another closely.
    if ($lm[0][0][1] > 3) return $s;
    $chain = [$lm[0][0]];
    for ($k = 1; $k < count($lm[0]); $k++) {
        if ($lm[0][$k][1] - $lm[0][$k - 1][1] > 400) break;
        $chain[] = $lm[0][$k];
    }
    if (count($chain) < 2) return $s;

    // The last label's value runs to where the prose begins.
    $last = end($chain);
    $after = ltrim(substr($s, $last[1] + strlen($last[0])));
    $words = preg_split('/ /u', $after) ?: [];
    $particles = ['de', 'dela', 'del', 'delos', 'los', 'la', 'van', 'von', 'y', 'and'];
    $n = count($words);
    for ($i = 0; $i < $n; $i++) {
        $next = $words[$i + 1] ?? '';
        /* A capitalised word followed by a lower-case one starts a sentence ("Hair care
           products", "This study explores"); a name is capitals and initials. */
        if (preg_match('/^[A-Z][a-z\x{00E0}-\x{00FF}]+$/u', $words[$i])
            && preg_match('/^[a-z\x{00E0}-\x{00FF}]/u', $next)
            && !in_array(strtolower($next), $particles, true)) {
            return implode(' ', array_slice($words, $i));
        }
    }
    return '';                              // a block and nothing after it: no abstract
}

/** Where the abstract stops: its keywords, or the next heading of the paper. */
function abstract_cut_at_end(string $s): string {
    $ends = [
        '/\b(?:Key\s?words?|KEY\s?WORDS?|Keyword\(s\)|Index Terms)\s*[:\-\x{2013}\x{2014}]/u',
        '/\b(?:(?:[IVX]+|\d+)\.?\s+)?(?:INTRODUCTION|Introduction)\b(?=\s+[A-Z])/u',
        '/\b(?:CHAPTER|Chapter)\s+(?:[IVX]+|\d+|ONE|One)\b/u',
        '/\b(?:TABLE OF CONTENTS|Table of Contents|LIST OF (?:TABLES|FIGURES)|List of (?:Tables|Figures))\b/u',
        '/\b(?:ACKNOWLEDGE?MENTS?|Acknowledge?ments?|DEDICATION|Dedication|APPROVAL SHEET|Approval Sheet)\b/u',
        '/\b(?:BACKGROUND OF THE STUDY|Background of the Study|STATEMENT OF THE PROBLEM)\b/u',
        '/\b(?:MARKETING|TECHNICAL|MANAGEMENT|FINANCIAL|SOCIO-ECONOMIC) ASPECTS?\b/u',
        '/\b(?:METHODOLOGY|RESULTS AND DISCUSSION|REFERENCES|BIBLIOGRAPHY)\b/u',
    ];
    $cut = strlen($s);
    foreach ($ends as $re) {
        if (preg_match($re, $s, $mm, PREG_OFFSET_CAPTURE) && $mm[0][1] < $cut) $cut = $mm[0][1];
    }
    return substr($s, 0, $cut);
}

/** The running head, page numbers and loose ends out; null when what is left is not an abstract. */
function abstract_clean(string $s): ?string {
    // The page's running head, and the page number printed beside it.
    $s = preg_replace('/\bPOLYTECHNIC UNIVERSITY OF THE PHILIPPINES(?:\s*[\x{2013}\-]\s*B[I\x{00CD}]\x{00D1}?AN CAMPUS)?\s+(?:[ivxlcdm]{1,6}|\d{1,3})\b\s*/u', '', $s) ?? $s;
    // A page number left at the very end, from the page the abstract ended on.
    $s = preg_replace('/\s+(?:[ivxlcdm]{1,6}|\d{1,3})\s*$/u', '', trim($s)) ?? $s;
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

    /* Prose, not the next heading or a fragment: it opens with a letter and runs to a
       few sentences. Thirty words is the shortest abstract worth keeping; past the
       limit it has almost certainly run on into the paper. */
    if ($s === '' || !preg_match('/^[\p{L}"\x{201C}(]/u', $s)) return null;
    $words = preg_split('/\s+/u', $s) ?: [];
    if (count($words) < 30 || mb_strlen($s) > 6000) return null;
    if (!preg_match('/[.!?]["\x{201D})]?$/u', $s)) {
        // Ends mid-sentence: keep it to the last full stop, if that leaves enough.
        $stop = max(strrpos($s, '. '), strrpos($s, '.'));
        if ($stop !== false && $stop > 0) {
            $kept = substr($s, 0, $stop + 1);
            if (count(preg_split('/\s+/u', $kept) ?: []) >= 30) return $kept;
        }
    }
    return $s;
}

/**
 * Uses AI to analyze research paper statistics and generate insights
 * This is like having a data analyst look at your numbers and tell you what they mean
 * 
 * @param array $statsData - Array of statistics (program, total papers, approved, revisions)
 * @return string - Human-readable analysis text with insights and recommendations
 */
function generate_analytics_insight($statsData, $apiKey = null) {
    // Tell the AI it's a research analytics expert
    $systemPrompt = "You are a research analytics expert. Analyze submission statistics and provide actionable insights for academic administrators.";
    
    // Build the request: give AI the statistics and ask for analysis
    $userPrompt = "Analyze these research paper statistics and provide insights:\n\n";
    $userPrompt .= json_encode($statsData, JSON_PRETTY_PRINT); // Convert stats to readable JSON
    $userPrompt .= "\n\nProvide a brief analysis (3-4 sentences) covering:\n";
    $userPrompt .= "1. Overall submission trends\n";
    $userPrompt .= "2. Programs with highest/lowest approval rates\n";
    $userPrompt .= "3. Areas needing attention\n";
    $userPrompt .= "4. Recommendations for improvement\n\n";
    $userPrompt .= "Return plain text analysis (no JSON, no markdown).";

    // $apiKey is passed by each dashboard (super admin / admin / faculty keys)
    $result = call_groq_api($systemPrompt, $userPrompt, 500, $apiKey);

    if (!$result['success']) {
        return "AI analysis unavailable. Please review the statistics manually.";
    }

    // Return the AI's analysis text
    return trim($result['response']);
}

/**
 * Generates a statistical analysis of the research paper
 * Extracts Methodology, Sample Size, Statistical Tools, etc.
 * 
 * @param string $pdfText - The text content of the paper
 * @param string|null $apiKey - Caller supplied key
 * @param string $modelChoice - Model 1 or 2
 * @return array - Associative array with analysis fields
 */
function generate_statistical_analysis($pdfText, $apiKey = null, $modelChoice = '1') {
    if (empty($pdfText) || strlen($pdfText) < 100) {
        error_log('PDF text too short for analysis: ' . strlen($pdfText) . ' chars');
        return [];
    }
    
    $systemPrompt = "You are a research expert. Analyze the research paper text and extract the IMRAD sections (Introduction, Methodology, Results, Discussion) in paragraph form. Base your response strictly on the provided text. Do not hallucinate or use external knowledge. Return ONLY valid JSON.";
    
    $userPrompt = "Analyze this text (from a research paper) and extract the following details in DETAILED paragraph form (provide comprehensive details, approx 5-8 sentences per section). Even if the paper is a Manuscript or other format, map the content to the IMRAD structure:\n\n";
    $userPrompt .= "1. introduction: A comprehensive paragraph summarizing the Introduction (background, problem statement, and specific objectives).\n";
    $userPrompt .= "2. methodology: A comprehensive paragraph summarizing the Methodology (research design, participants/sampling, instruments used, and data gathering procedure).\n";
    $userPrompt .= "3. results: A comprehensive paragraph summarizing the key Results, statistical findings, and data analysis.\n";
    $userPrompt .= "4. discussion: A comprehensive paragraph summarizing the Discussion, conclusions, and recommendations.\n";
    $userPrompt .= "5. research_field: The academic field (e.g., Information Technology).\n";
    $userPrompt .= "6. sample_size: Brief mention of sample size/participants (e.g. '50 students').\n\n";
    
    $userPrompt .= "Return ONLY this JSON structure:\n";
    $userPrompt .= "{\n";
    $userPrompt .= '  "summary": "paragraph for Introduction...",'."\n";
    $userPrompt .= '  "methodology": "paragraph for Methodology...",'."\n";
    $userPrompt .= '  "statistical_methods": "paragraph for Results...",'."\n";
    $userPrompt .= '  "variables": "paragraph for Discussion...",'."\n";
    $userPrompt .= '  "sample_size": "...",'."\n";
    $userPrompt .= '  "research_field": "..."'."\n";
    $userPrompt .= "}\n\n";
    
    $userPrompt .= "Text:\n" . pdf_text_clip((string)$pdfText, 20000);

    // Use caller-supplied key, or fall back to upload key, with backup fallback on rate-limit
    if ($modelChoice === '2') {
        $resolvedKey = $apiKey ?? (defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null);
        $uploadFallback = defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null;
    } else {
        $resolvedKey = $apiKey ?? (defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null);
        $uploadFallback = defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null;
    }
    $result = call_groq_api($systemPrompt, $userPrompt, 2000, $resolvedKey, $uploadFallback, true);

    if (!$result['success']) {
        error_log('Groq API failed: ' . ($result['error'] ?? 'Unknown error'));
        return ['error' => $result['error'] ?? 'Unknown API error'];
    }

    $response = trim($result['response']);
    $response = preg_replace('/```json\s*|\s*```/', '', $response);
    $response = preg_replace('/^[^{]*/', '', $response);
    $response = preg_replace('/[^}]*$/', '', $response);
    
    $analysis = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decode error: ' . json_last_error_msg());
        return ['error' => 'JSON decode error: ' . json_last_error_msg()];
    }
    
    return $analysis ?? [];
}
