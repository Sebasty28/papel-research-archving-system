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
    // Override with GROQ_MODEL in .env if the model id changes again — every
    // call site reads this constant, so that is a one-line change.
    define('GROQ_MODEL',              $_ENV['GROQ_MODEL'] ?? 'openai/gpt-oss-120b');
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
       markdown fences and reasoning preambles — habits that differ per model,
       so a model swap can silently break parsing instead of failing loudly. */
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    // Initialize cURL to make HTTP request to Groq API
    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, // Return response as string
        CURLOPT_POST => true, // Use POST method
        CURLOPT_POSTFIELDS => json_encode($payload), // Send our data as JSON
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($key) // Authenticate with API key
        ],
        CURLOPT_SSL_VERIFYPEER => true, // Verify SSL certificate for security
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30, // Wait max 30 seconds for response
        CURLOPT_CONNECTTIMEOUT => 10 // Wait max 10 seconds to connect
    ]);

    // Execute the request and get response
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch); // suppress PHP 8.5 deprecation — curl handle auto-closes on GC

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
       tier's TPM — and the analysis then came back empty.

       Groq's reply says exactly how long to wait, so short waits are honoured
       before falling back to the key swap, which still helps for the per-key
       limits (requests per minute or per day). */
    if ($httpCode === 429) {
        $decoded = json_decode((string)$response, true);
        $detail  = (string)($decoded['error']['message'] ?? '');
        $isTpm   = stripos($detail, 'tokens per minute') !== false || stripos($detail, 'TPM') !== false;
        $wait    = preg_match('/try again in ([0-9.]+)\s*s/i', $detail, $m) ? (float)$m[1] : 0.0;

        if ($attempt < 1 && $wait > 0 && $wait <= GROQ_RETRY_MAX_WAIT) {
            error_log(sprintf('Groq 429 (%s) — waiting %.1fs, then retrying.', $isTpm ? 'tokens/min' : 'rate limit', $wait));
            usleep((int)(($wait + 0.25) * 1000000));   // a little past the window
            return call_groq_api($systemPrompt, $userPrompt, $maxTokens, $key, $fallbackKey, $jsonMode, $attempt + 1);
        }

        if (!$isTpm && !empty($fallbackKey) && $fallbackKey !== $key) {
            error_log('Groq 429 on the primary key — retrying with the fallback key.');
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
        return trim(substr($text, 0, 25000));
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

        return substr($text, 0, 25000);
        
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

        return substr($text, 0, 20000); // Return up to 20k chars for fallback
    }
}

/**
 * Records, and returns, why the last Groq call failed.
 *
 * Callers only see null when extraction fails, which cannot distinguish "the
 * model could not read this document" from "we are out of quota for the next
 * minute" — two problems with very different advice for the student.
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
    
    $userPrompt .= "Research Paper Text (first 8000 characters):\n" . substr($pdfText, 0, 8000);

    // Use dedicated upload key with backup fallback on rate-limit
    if ($modelChoice === '2') {
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
 * Extracts the abstract directly from PDF text using pattern matching.
 * Looks for text between "Abstract" heading and "Keywords" / "Introduction" / "I." section.
 * This is more reliable than AI because it copies the exact text.
 *
 * @param string $pdfText - Raw text extracted from the PDF
 * @return string|null - The abstract text, or null if not found
 */
function extract_abstract_from_text($pdfText) {
    if (empty($pdfText) || strlen($pdfText) < 50) return null;

    // Normalize whitespace but preserve newlines for section detection
    $text = preg_replace('/[ \t]+/', ' ', $pdfText);

    // ── Strategy 1: Find text between "Abstract" and "Keywords" ──
    $patterns = [
        // "Abstract" ... "Keywords" (most common, most reliable)
        '/\b(?:ABSTRACT|Abstract)\s*[:\-]?\s*\n?(.*?)(?=\s*(?:Keywords?\s*[:\-]|KEY\s*WORDS?\s*[:\-]|Key\s*[Ww]ords?\s*[:\-]))/si',
        // "Abstract" ... Introduction with required number/roman prefix (strict: avoids matching "introduction" mid-sentence)
        '/\b(?:ABSTRACT|Abstract)\s*[:\-]?\s*\n?(.*?)(?=\s*(?:(?:\d+\.?\d*\.?\s+|I\.\s+)[Ii]ntroduction|INTRODUCTION\b))/s',
        // "Abstract" ... "Background" section heading
        '/\b(?:ABSTRACT|Abstract)\s*[:\-]?\s*\n?(.*?)(?=\s*(?:BACKGROUND\b|Background\s*[:\-]))/si',
        // "Abstract" ... any numbered/roman section heading (requires uppercase word after number)
        '/\b(?:ABSTRACT|Abstract)\s*[:\-]?\s*\n?(.*?)(?=\s*(?:[IVX]+\.\s+[A-Z][A-Z]|[0-9]+\.\s+[A-Z][A-Z]))/s',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $abstract = trim($matches[1]);
            // Clean up: remove extra whitespace and newlines
            $abstract = preg_replace('/\s+/', ' ', $abstract);
            $abstract = trim($abstract);

            // Validate: abstract should be at least 50 chars and not too long (max ~3000)
            if (strlen($abstract) >= 50 && strlen($abstract) <= 5000) {
                return $abstract;
            }
        }
    }

    // ── Strategy 2: Take text after "Abstract" heading and cut at known section names ──
    if (preg_match('/\b(?:ABSTRACT|Abstract)\s*[:\-]?\s*\n?\s*(.{100,3000})/s', $text, $matches)) {
        $candidate = $matches[1];
        // Cut at numbered headings, all-caps section names, or "Introduction:" pattern
        $parts = preg_split(
            '/\s+(?=(?:\d+\.?\s+[A-Z][a-z]|[IVX]+\.\s+[A-Z]|INTRODUCTION\b|METHODOLOGY\b|METHODS\b|RESULTS\b|DISCUSSION\b|CONCLUSION\b|REFERENCES\b|BACKGROUND\b))/s',
            $candidate,
            2
        );
        $abstract = trim(preg_replace('/\s+/', ' ', $parts[0]));
        if (strlen($abstract) >= 50 && strlen($abstract) <= 3000) {
            return $abstract;
        }
    }

    return null;
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
    
    $userPrompt .= "Text:\n" . substr($pdfText, 0, 20000);

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

/**
 * Checks similarity between a new abstract and a list of existing abstracts using AI
 * 
 * @param string $newAbstract - The abstract of the paper being uploaded
 * @param array $existingAbstracts - Array of strings (abstracts) from approved papers
 * @param string $modelChoice - Model 1 or 2
 * @return array - ['percentage' => int, 'reason' => string]
 */
function check_similarity_groq($newAbstract, $existingAbstracts, $modelChoice = '1') {
    if (empty($existingAbstracts)) return ['percentage' => 0, 'reason' => 'No existing papers to compare.'];

    $systemPrompt = "You are a plagiarism and similarity detection expert. Compare the 'Target Abstract' against the list of 'Existing Abstracts'. Analyze for semantic similarity, not just word matching.";
    
    $userPrompt = "Target Abstract:\n" . substr($newAbstract, 0, 2000) . "\n\n";
    $userPrompt .= "Existing Abstracts to check against:\n";
    
    foreach ($existingAbstracts as $index => $abstract) {
        $userPrompt .= "[$index] " . substr($abstract, 0, 1000) . "\n---\n";
    }
    
    $userPrompt .= "\nTask: Determine the highest similarity percentage found between the Target Abstract and ANY of the Existing Abstracts.\n";
    $userPrompt .= "Strictly follow this rule: If the semantic overlap is significant, rate it high. The acceptable limit is 15%.\n";
    $userPrompt .= "Return ONLY valid JSON in this format:\n";
    $userPrompt .= "{\n  \"highest_similarity_percentage\": 0,\n  \"most_similar_abstract_index\": -1,\n  \"reason\": \"brief explanation\"\n}";

    // Use dedicated upload key with backup fallback on rate-limit
    if ($modelChoice === '2') {
        $uploadKey = defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null;
        $uploadFallback = defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null;
    } else {
        $uploadKey = defined('GROQ_API_KEY_UPLOAD') ? GROQ_API_KEY_UPLOAD : null;
        $uploadFallback = defined('GROQ_API_KEY_UPLOAD_2') ? GROQ_API_KEY_UPLOAD_2 : null;
    }
    $result = call_groq_api($systemPrompt, $userPrompt, 500, $uploadKey, $uploadFallback, true);

    if (!$result['success']) return ['percentage' => 0, 'reason' => 'AI check failed'];

    $response = trim($result['response']);
    $response = preg_replace('/```json\s*|\s*```/', '', $response);
    $response = preg_replace('/^[^{]*/', '', $response);
    $response = preg_replace('/[^}]*$/', '', $response);

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['percentage' => 0, 'reason' => 'AI response parsing failed.'];
    }
    return [
        'percentage' => (int)($data['highest_similarity_percentage'] ?? 0), 
        'reason' => $data['reason'] ?? '',
        'index' => $data['most_similar_abstract_index'] ?? -1
    ];
}