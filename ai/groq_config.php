<?php
// Load Composer's autoloader so we can use external libraries like PDF parser
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Load prompt injection protection
require_once __DIR__ . '/prompt_guard.php';

// Check if Groq API key is already defined, if not, load it from .env file
if (!defined('GROQ_API_KEY')) {
    // Helper function to read .env file and load environment variables
    if (!function_exists('load_env')) {
        function load_env($file = __DIR__ . '/.env')
        {
            if (!file_exists($file))
                return; // Exit if .env file doesn't exist
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0)
                    continue; // Skip comment lines
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2); // Split key=value pairs
                    $_ENV[trim($key)] = trim($value); // Store in environment
                }
            }
        }
    }

    // Try loading from current directory, then root directory
    if (file_exists(__DIR__ . '/.env')) {
        load_env(__DIR__ . '/.env');
    } elseif (file_exists(__DIR__ . '/../.env')) {
        load_env(__DIR__ . '/../.env');
    }

    // Define constants for Groq API configuration
    define('GROQ_API_KEY', $_ENV['GROQ_API_KEY'] ?? ''); // Your API key
    define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions'); // Groq endpoint
    // Kept in step with config/groq_config.php, which is the file every page
    // actually loads — nothing requires this one. Llama 3.3 70B was
    // decommissioned on Groq (2026-08-16).
    define('GROQ_MODEL', $_ENV['GROQ_MODEL'] ?? 'openai/gpt-oss-120b'); // AI model to use
}

/**
 * Sends a request to Groq AI API and gets a response
 * Think of this as asking the AI a question and waiting for an answer
 * 
 * @param string $systemPrompt - Tells the AI what role to play (e.g., "You are a research expert")
 * @param string $userPrompt - The actual question or task we want the AI to do
 * @param int $maxTokens - Maximum length of AI's response (tokens are like words)
 * @return array - Returns success/failure status and the AI's response
 */
function call_groq_api($systemPrompt, $userPrompt, $maxTokens = 1024)
{
    if (empty(GROQ_API_KEY)) {
        return ['success' => false, 'error' => 'Groq API Key is missing or invalid.'];
    }

    // Prepare the data we're sending to Groq AI
    $payload = [
        'model' => GROQ_MODEL, // Which AI model to use
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt], // AI's instructions
            ['role' => 'user', 'content' => $userPrompt] // Our question
        ],
        'temperature' => 0.7, // How creative the AI should be (0=strict, 1=creative)
        'max_tokens' => $maxTokens, // Limit response length
        'top_p' => 1,
        'stream' => false // Get complete response at once, not word-by-word
    ];

    // Initialize cURL to make HTTP request to Groq API
    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, // Return response as string
        CURLOPT_POST => true, // Use POST method
        CURLOPT_POSTFIELDS => json_encode($payload), // Send our data as JSON
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim(GROQ_API_KEY) // Authenticate with API key
        ],
        CURLOPT_SSL_VERIFYPEER => true, // Verify SSL certificate for security
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30, // Wait max 30 seconds for response
        CURLOPT_CONNECTTIMEOUT => 10 // Wait max 10 seconds to connect
    ]);

    // Execute the request and get response
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP status code
    $curlError = curl_error($ch);
    curl_close($ch);

    // Check if there was a connection error
    if ($curlError) {
        error_log("Groq CURL Error: $curlError");
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }

    // Check if API returned an error (anything other than 200 OK)
    if ($httpCode !== 200) {
        error_log("Groq API Error: HTTP $httpCode - $response");
        return ['success' => false, 'error' => "API error: HTTP $httpCode"];
    }

    // Parse the JSON response from API
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        // Validate output for prompt leakage before returning
        $aiContent = PromptGuard::validateOutput($result['choices'][0]['message']['content']);
        return ['success' => true, 'response' => $aiContent];
    }

    // Something went wrong with the response format
    return ['success' => false, 'error' => 'Invalid API response'];
}

/**
 * Extracts readable text from a PDF file
 * This is like converting a PDF document into plain text that we can analyze
 * 
 * @param string $filePath - Path to the PDF file on the server
 * @return string - The extracted text content (up to 10,000 characters)
 */
function extract_pdf_text($filePath)
{
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
        $text = preg_replace('/\s+/', ' ', $text); // Replace multiple spaces with single space
        $text = trim($text); // Remove leading/trailing whitespace

        // Return first 10000 characters - enough for AI to analyze title, abstract, etc.
        return substr($text, 0, 25000); // Increased to 25k to capture Results/Discussion

    } catch (Exception $e) {
        error_log('PDF parsing error: ' . $e->getMessage());

        // Fallback method: Try basic text extraction if library fails
        $content = @file_get_contents($filePath); // Read PDF as raw binary
        if (!$content)
            return ''; // Return empty if file can't be read

        $text = '';
        // Look for text between parentheses in PDF structure (basic extraction)
        if (preg_match_all('/\(([^)]+)\)/s', $content, $matches)) {
            $text = implode(' ', $matches[1]);
        }

        // Clean up: remove non-printable characters, keep only readable text
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim(substr($text, 0, 20000)); // Return up to 20k chars for fallback
    }
}

/**
 * Uses AI to extract metadata (title, authors, year, etc.) from research paper text
 * This is like having an AI assistant read the paper and pull out key information
 * 
 * @param string $pdfText - The text content extracted from the PDF
 * @return array|null - Returns array with title, authors, year, keywords, abstract or null if failed
 */
function extract_metadata_with_groq($pdfText)
{
    // Tell the AI what its job is: extract metadata from research papers
    $systemPrompt = "You are an expert research paper analyzer. Extract metadata from academic papers with high accuracy. Look for title page, abstract section, author information, and keywords. Return ONLY valid JSON.";

    // Build detailed instructions for the AI on what to look for and how to format the response
    $userPrompt = "Analyze this research paper and extract the following information. Look carefully at the first few pages for title page and abstract:\n\n";
    $userPrompt .= "Required fields (return null if not found):\n";
    $userPrompt .= "- title: The main research paper title (usually on first page, often in larger font or bold)\n";
    $userPrompt .= "- authors: List of author names (look for 'by', 'authors:', or names under title). Return as array.\n";
    $userPrompt .= "- year: Publication or completion year (look for dates, copyright, or 'Year:')\n";
    $userPrompt .= "- keywords: Research keywords (look for 'Keywords:', 'Key terms:', or similar). Return as array.\n";
    $userPrompt .= "- abstract: The abstract text (look for 'Abstract:', 'Summary:', or similar section)\n\n";

    // Show the AI exactly what JSON format we want back
    $userPrompt .= "Return ONLY this JSON structure:\n";
    $userPrompt .= "{\n";
    $userPrompt .= '  "title": "exact title here",' . "\n";
    $userPrompt .= '  "authors": ["Author 1", "Author 2"],' . "\n";
    $userPrompt .= '  "year": 2024,' . "\n";
    $userPrompt .= '  "keywords": ["keyword1", "keyword2"],' . "\n";
    $userPrompt .= '  "abstract": "abstract text here"' . "\n";
    $userPrompt .= "}\n\n";

    // Give the AI the first 6000 characters of the paper (usually contains all metadata)
    $userPrompt .= "Research Paper Text (first 6000 characters):\n" . substr($pdfText, 0, 6000);

    // Send request to Groq AI
    $result = call_groq_api($systemPrompt, $userPrompt, 1200);

    // If AI request failed, return null
    if (!$result['success']) {
        return null;
    }

    // Clean up the AI's response to get pure JSON
    $response = trim($result['response']);
    $response = preg_replace('/```json\s*|\s*```/', '', $response); // Remove markdown code blocks
    $response = preg_replace('/^[^{]*/', '', $response); // Remove anything before first {
    $response = preg_replace('/[^}]*$/', '', $response); // Remove anything after last }

    // Convert JSON string to PHP array
    $metadata = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // If JSON is invalid, log error and return null
        error_log("JSON decode error: " . json_last_error_msg() . " Response: " . $response);
        return null;
    }

    // Success! Return the extracted metadata
    return $metadata;
}

/**
 * Uses AI to analyze research paper statistics and generate insights
 * This is like having a data analyst look at your numbers and tell you what they mean
 * 
 * @param array $statsData - Array of statistics (program, total papers, approved, revisions)
 * @return string - Human-readable analysis text with insights and recommendations
 */
function generate_analytics_insight($statsData)
{
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

    // Ask the AI to analyze the data
    $result = call_groq_api($systemPrompt, $userPrompt, 500);

    // If AI request failed, return a fallback message
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
 * @return array - Associative array with analysis fields
 */
function generate_statistical_analysis($pdfText)
{
    $systemPrompt = "You are a research expert. Analyze the research paper text and extract the IMRAD sections (Introduction, Methodology, Results, Discussion) in paragraph form. Return ONLY valid JSON.";

    $userPrompt = "Analyze this text (from a research paper) and extract the following details in DETAILED paragraph form (provide comprehensive details, approx 5-8 sentences per section):\n\n";
    $userPrompt .= "1. introduction: A comprehensive paragraph summarizing the Introduction (background, problem statement, and specific objectives).\n";
    $userPrompt .= "2. methodology: A comprehensive paragraph summarizing the Methodology (research design, participants/sampling, instruments used, and data gathering procedure).\n";
    $userPrompt .= "3. results: A comprehensive paragraph summarizing the key Results, statistical findings, and data analysis.\n";
    $userPrompt .= "4. discussion: A comprehensive paragraph summarizing the Discussion, conclusions, and recommendations.\n";
    $userPrompt .= "5. research_field: The academic field (e.g., Information Technology).\n";
    $userPrompt .= "6. sample_size: Brief mention of sample size/participants (e.g. '50 students').\n\n";

    $userPrompt .= "Return ONLY this JSON structure:\n";
    $userPrompt .= "{\n";
    $userPrompt .= '  "summary": "paragraph for Introduction...",' . "\n";
    $userPrompt .= '  "methodology": "paragraph for Methodology...",' . "\n";
    $userPrompt .= '  "statistical_methods": "paragraph for Results...",' . "\n";
    $userPrompt .= '  "variables": "paragraph for Discussion...",' . "\n";
    $userPrompt .= '  "sample_size": "...",' . "\n";
    $userPrompt .= '  "research_field": "..."' . "\n";
    $userPrompt .= "}\n\n";

    // Send first 20000 chars to ensure we catch results/discussion which are often at the end
    $userPrompt .= "Text:\n" . substr($pdfText, 0, 20000);

    $result = call_groq_api($systemPrompt, $userPrompt, 2000);

    if (!$result['success'])
        return [];

    $response = trim($result['response']);
    $response = preg_replace('/```json\s*|\s*```/', '', $response);
    $response = preg_replace('/^[^{]*/', '', $response);
    $response = preg_replace('/[^}]*$/', '', $response);

    return json_decode($response, true) ?? [];
}

/**
 * Checks similarity between a new abstract and a list of existing abstracts using AI
 * 
 * @param string $newAbstract - The abstract of the paper being uploaded
 * @param array $existingAbstracts - Array of strings (abstracts) from approved papers
 * @return array - ['percentage' => int, 'reason' => string]
 */
function check_similarity_groq($newAbstract, $existingAbstracts)
{
    if (empty($existingAbstracts))
        return ['percentage' => 0, 'reason' => 'No existing papers to compare.'];

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

    $result = call_groq_api($systemPrompt, $userPrompt, 500);

    if (!$result['success'])
        return ['percentage' => 0, 'reason' => 'AI check failed'];

    $data = json_decode($result['response'], true);
    return ['percentage' => (int) ($data['highest_similarity_percentage'] ?? 0), 'reason' => $data['reason'] ?? ''];
}