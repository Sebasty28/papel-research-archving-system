<?php
require_once '../../config/core.php';
require_once '../../config/groq_config.php'; // GROQ_MODEL + the dedicated chatbot keys
require_role(['student', 'faculty', 'admin', 'head_academic', 'super_admin']);

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['reply' => 'Please say something.']);
    exit;
}

// Get current user context
$u = current_user();
$studentName = $u['full_name'] ?? 'Student';

// The real limit, so the assistant never promises a size the server will refuse.
$maxUpload = max_upload_label();
// And the most one submission may carry in all, which two PDFs together can pass.
$maxPost = max_post_label();

// System Prompt / Context
$systemContext = "You are Corpus, the Virtual Assistant for the PAPEL Research Repository. You are assisting a student named $studentName.
Your primary goal is to guide them on how to upload their research and explain the requirements.

Key Information for the Student:
- Required Documents: Ethics Clearance, Consent Form, and Data Collection instruments.
- File Requirements: PDF format only, maximum size $maxUpload.
- Manuscript Type: In step 1 they tick IMRAD, Full Manuscript, or both. Each type ticked needs its own PDF in step 2, one of each, so a paper handed in both ways has two files and is still one paper. If they tick both, the files together (with any supporting documents) also have to fit within $maxPost.
- Process: They can use the 'Extract with AI' button to auto-fill metadata from their PDF. With two PDFs it reads the IMRAD one.
- Tone: Be concise, friendly, and professional.

Formatting (the chat window is narrow, so answers must be easy to scan):
- Start with one short sentence that answers the question directly.
- Put two or more items in a list: '- ' for requirements or options, '1. ' for steps done in order. One idea per item, under about 20 words.
- Keep paragraphs to one or two sentences, with a blank line between paragraphs and before and after a list.
- Use **bold** only for key terms: document names, file limits, button and page names. Never bold a whole sentence.
- Do not use headings, tables or code blocks.
- End with at most one short line offering further help.

STRICT RESTRICTIONS:
1. DO NOT write, generate, explain, or analyze code/programming scripts under ANY circumstances.
2. DO NOT answer general knowledge, math, science, or history questions unrelated to the university or research repository.
3. If the user asks for something outside of your role (e.g., writing an essay or writing code), politely decline. State that you are Corpus, the PAPEL Research Repository assistant, and can only help with platform-related and research submission tasks. Keep the refusal to one sentence, then list two or three things you can help with as a short bulleted list.";

// Helper function to read .env file
if (!function_exists('load_env')) {
    function load_env($file = __DIR__ . '/../../.env') {
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
load_env(__DIR__ . '/../../.env');

// Groq API Configuration
/* Not asked for any more: the session says which engine to start on, and the
   rest of the key pool stands behind it for this call. See ai_engine_current()
   and groq_pool_request() in config/groq_config.php. */
$engine = ai_engine_current();

if (!groq_key_pool()) {
    http_response_code(500);
    echo json_encode(['reply' => "The AI assistant is not configured yet. Please tell an administrator."]);
    exit;
}

$payload = [
    'model' => GROQ_MODEL,
    'messages' => [
        ['role' => 'system', 'content' => $systemContext],
        ['role' => 'user', 'content' => $message]
    ],
    'temperature' => 0.7,
    'max_tokens' => 500,
    'top_p' => 1,
    'stream' => false
];

/* One shared walker down the key pool, rather than this page's own idea of
   what to do when a key is spent. The hand-rolled version here tried the
   primary key, then one named fallback, and gave up with two more keys
   untouched; worse, a 401 on a revoked key was not a 429, so it stopped the
   assistant dead. See groq_pool_request() in config/groq_config.php. */
$out      = groq_pool_request($payload, $engine);
$httpCode = (int)$out['http_code'];
$response = $out['body'];
/* A connection error is the one failure that is not about a key, so it is the
   one this page still reports in its own words. */
$curlError = (!$out['success'] && strpos((string)$out['error'], 'Connection error') === 0)
    ? substr((string)$out['error'], strlen('Connection error: ')) : '';

/* The engine the session starts on follows whichever key answered, so the next
   message does not begin on one that has just refused. */
if (groq_used_fallback()) ai_engine_adopt();

// Handle Connection Errors
if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'reply' => "I'm having trouble connecting to the server. (Error: $curlError)",
        'debug_error' => $curlError
    ]);
    exit;
}

/* Handle API Errors. Every one of these means the whole key pool refused, not
   one key: groq_pool_request() has already walked it. So the wording is about
   the service and never about "the" key, and 401 is a fault to report rather
   than something the student can wait out. */
if (!$out['success']) {
    $errorMessage = 'AI Service Unavailable';

    if ($httpCode === 401 || $httpCode === 403) {
        $errorMessage = 'The AI service is not set up correctly. Please tell an administrator.';
    } elseif ($httpCode === 429) {
        $errorMessage = 'All of my AI keys have reached their limit for the moment. Please try again in a minute.';
    } elseif ($httpCode >= 500) {
        $errorMessage = 'The AI service is currently down. Please try again later.';
    }

    // A walk that never got an HTTP answer has no code to pass on as a status.
    http_response_code($httpCode >= 400 ? $httpCode : 500);
    echo json_encode([
        'reply' => $errorMessage,
        'http_code' => $httpCode
    ]);
    exit;
}

// Process Successful Response
$data = json_decode($response, true);
$reply = $data['choices'][0]['message']['content'] ?? 'I am not sure how to respond to that.';

echo json_encode(['reply' => $reply, 'engine' => ai_engine_current()]);
