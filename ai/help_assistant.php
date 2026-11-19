<?php
require_once '../config/core.php';
require_once __DIR__ . '/../config/groq_config.php'; // GROQ_MODEL + the dedicated chatbot keys
require_once __DIR__ . '/corpus_guard.php'; // requires prompt_guard.php
require_once __DIR__ . '/rate_limiter.php';

header('Content-Type: application/json');

// Check if user is logged in
$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['reply' => 'Please login to use the virtual assistant.']);
    exit;
}

// ── Rate Limiting: Max 20 messages per 10 minutes per user ──
$limiter = new RateLimiter(db());
$rateCheck = $limiter->check((int) $u['user_id'], 'chatbot', 20, 600);
if (!$rateCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['reply' => $rateCheck['message']]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
// The same token check as app/student/student_assistant.php: a page on another site cannot spend these keys.
if (!is_array($input) || !hash_equals(csrf_token(), is_string($input['_token'] ?? null) ? $input['_token'] : '')) {
    http_response_code(403);
    echo json_encode(['reply' => 'This page has expired. Refresh it and try again.']);
    exit;
}
$message = is_string($input['message'] ?? null) ? $input['message'] : '';

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['reply' => 'Please say something.']);
    exit;
}

/* The same guard as the site's Corpus (ai/corpus_guard.php): a password, a
   key, code, a link or gibberish goes no further than here. Nothing calls
   this endpoint any more, but it is still a URL that spends AI keys, so it is
   held to the same rules rather than left as the easier way in. */
$check = CorpusGuard::checkInput($message);
if (!$check['ok']) {
    http_response_code(400);
    echo json_encode(['reply' => $check['message'], 'blocked' => $check['reason']]);
    exit;
}
$message = $check['input'];

$userName = $u['full_name'] ?? 'User';
$userRole = ucfirst($u['user_role'] ?? 'Guest');

// The real limit, so the assistant never promises a size the server will refuse.
$maxUpload = max_upload_label();

// System Prompt / Context for Help Center (hardened with security rules)
$systemContext = "You are Corpus, the PAPEL Support AI. Provide general support guidance without accessing private records.
Your specific role is to help users solve problems they encounter on the platform.

Guidelines for helping:
- **Identify the Problem:** Listen to the user's issue (e.g., upload failed, login error, missing paper).
- **Provide a Solution:**
  - Upload issues: Check PDF format, $maxUpload limit, or internet connection.
  - Status questions: Explain where to find status in 'My Library'.
  - Rejections: Explain how to view feedback and re-submit.
  - System errors: Advise using the 'Contact Support' page.
- **Be Direct:** Focus on the solution.

This is the Help Center. You are NOT the research writing assistant. You are the technical support guide." . PromptGuard::getSecurityRules() . CorpusGuard::rules();

// Groq API Configuration
/* Not asked for any more: the session says which engine to start on, and the
   rest of the key pool stands behind it for this call. See ai_engine_current()
   and groq_pool_request() in config/groq_config.php. */
$engine = ai_engine_current();

if (!groq_key_pool()) {
    http_response_code(500);
    echo json_encode(['reply' => "AI service is not configured. Please contact the administrator."]);
    exit;
}

// ✅ User content is isolated in its own role: never interpolated into system prompt
$payload = [
    'model' => GROQ_MODEL,
    'messages' => [
        ['role' => 'system', 'content' => $systemContext],
        ['role' => 'user', 'content' => $message]  // kept separate from system
    ],
    'temperature' => 0.1,
    'max_tokens' => 500,
    'top_p' => 1,
    'stream' => false
];

/* One shared walker down the key pool, rather than this page's own idea of
   what to do when a key is spent. The hand-rolled version here tried the
   primary key, then one named fallback, and gave up with two more keys
   untouched; worse, a 401 on a revoked key was not a 429, so it stopped the
   chatbot dead. See groq_pool_request() in config/groq_config.php. */
$out = groq_pool_request($payload, $engine);

/* The engine the session starts on follows whichever key answered, so the next
   message does not begin on one that has just refused. */
if (groq_used_fallback()) ai_engine_adopt();

if (!$out['success']) {
    /* A failure here means every key in the pool refused, so "try again later"
       is the honest answer and saying which limit it was helps nobody in a
       help chat. The server log names the key and the reason. */
    $reply = ((int)$out['http_code'] === 429)
        ? "Every one of my AI keys has reached its limit for the moment. Please try again in a minute."
        : "I'm having trouble connecting to the AI service right now. Please try again later.";
    http_response_code(500);
    echo json_encode(['reply' => $reply]);
    exit;
}

$data = json_decode($out['body'], true);
$reply = $data['choices'][0]['message']['content'] ?? 'I am not sure how to respond to that.';

// ── Prompt Injection Protection: Validate output before returning ──
$reply = CorpusGuard::cleanOutput($reply);

echo json_encode(['reply' => $reply, 'engine' => ai_engine_current()]);
