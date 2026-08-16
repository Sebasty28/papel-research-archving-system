<?php
require_once '../config/core.php';
require_once __DIR__ . '/../config/groq_config.php'; // GROQ_MODEL + the dedicated chatbot keys
require_once __DIR__ . '/prompt_guard.php';
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
$message = $input['message'] ?? '';

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['reply' => 'Please say something.']);
    exit;
}

// ── Prompt Injection Protection: Sanitize user input ──
$check = PromptGuard::sanitizeInput($message);
if (!$check['safe']) {
    // Block the request entirely — do NOT send to AI
    echo json_encode(['reply' => $check['message']]);
    exit;
}
// Use the sanitized version from here on
$message = $check['input'];

$userName = $u['full_name'] ?? 'User';
$userRole = ucfirst($u['user_role'] ?? 'Guest');

// System Prompt / Context for Help Center (hardened with security rules)
$systemContext = "You are PUPPY the Ai dog assistant, the PAPEL Support AI. You are assisting a $userRole named $userName.
Your specific role is to help users solve problems they encounter on the platform.

Guidelines for helping:
- **Identify the Problem:** Listen to the user's issue (e.g., upload failed, login error, missing paper).
- **Provide a Solution:**
  - Upload issues: Check PDF format, 50MB limit, or internet connection.
  - Status questions: Explain where to find status in 'My Library'.
  - Rejections: Explain how to view feedback and re-submit.
  - System errors: Advise using the 'Contact Support' page.
- **Be Direct:** Focus on the solution.

This is the Help Center. You are NOT the research writing assistant. You are the technical support guide." . PromptGuard::getSecurityRules();

// Groq API Configuration — model switching (inspired by client_hs)
$modelChoice = $input['model_choice'] ?? '1';

// Pick primary and fallback keys based on model choice
if ($modelChoice === '2') {
    $apiKey = defined('GROQ_API_KEY_CHATBOT_2') ? GROQ_API_KEY_CHATBOT_2 : ($_ENV['GROQ_API_KEY'] ?? '');
    $fallbackKey = defined('GROQ_API_KEY_CHATBOT') ? GROQ_API_KEY_CHATBOT : '';
} else {
    $apiKey = defined('GROQ_API_KEY_CHATBOT') ? GROQ_API_KEY_CHATBOT : ($_ENV['GROQ_API_KEY'] ?? '');
    $fallbackKey = defined('GROQ_API_KEY_CHATBOT_2') ? GROQ_API_KEY_CHATBOT_2 : '';
}

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['reply' => "AI service is not configured. Please contact the administrator."]);
    exit;
}
$apiUrl = 'https://api.groq.com/openai/v1/chat/completions';

// ✅ User content is isolated in its own role — never interpolated into system prompt
$payload = [
    'model' => GROQ_MODEL,
    'messages' => [
        ['role' => 'system', 'content' => $systemContext],
        ['role' => 'user', 'content' => $message]  // kept separate from system
    ],
    'temperature' => 0.7,
    'max_tokens' => 500,
    'top_p' => 1,
    'stream' => false
];

/**
 * Helper: fire a single cURL request to Groq and return [httpCode, response, error]
 */
$fireRequest = function ($key) use ($apiUrl, $payload) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlError];
};

// Try primary key
[$httpCode, $response, $curlError] = $fireRequest($apiKey);

// Auto-fallback: if rate-limited (429) and a different fallback key exists, retry once
if ($httpCode === 429 && !empty($fallbackKey) && $fallbackKey !== $apiKey) {
    error_log("Chatbot: primary key rate-limited (429), retrying with fallback key...");
    [$httpCode, $response, $curlError] = $fireRequest($fallbackKey);
}

if ($curlError || $httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['reply' => "I'm having trouble connecting to the AI service right now. Please try again later."]);
    exit;
}

$data = json_decode($response, true);
$reply = $data['choices'][0]['message']['content'] ?? 'I am not sure how to respond to that.';

// ── Prompt Injection Protection: Validate output before returning ──
$reply = PromptGuard::validateOutput($reply);

echo json_encode(['reply' => $reply]);