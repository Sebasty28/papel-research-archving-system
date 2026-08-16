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

// System Prompt / Context
$systemContext = "You are PUPPY the Ai dog assistant, the Virtual Assistant for the PAPEL Research Repository. You are assisting a student named $studentName.
Your primary goal is to guide them on how to upload their research and explain the requirements.

Key Information for the Student:
- Required Documents: Ethics Clearance, Consent Form, and Data Collection instruments.
- File Requirements: PDF format only, maximum size 50MB.
- Process: They can use the 'Extract with AI' button to auto-fill metadata from their PDF.
- Tone: Be concise, friendly, and professional.
- Formatting: Use Markdown bolding (**text**) to highlight important details, requirements, and keywords. Use line breaks to separate ideas cleanly.

STRICT RESTRICTIONS:
1. DO NOT write, generate, explain, or analyze code/programming scripts under ANY circumstances.
2. DO NOT answer general knowledge, math, science, or history questions unrelated to the university or research repository.
3. If the user asks for something outside of your role (e.g., writing an essay or writing code), politely decline. State that you are PUPPY, the PAPEL Research Repository assistant, and can only help with platform-related and research submission tasks.";

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
// Groq API Configuration — model switching
$modelChoice = $input['model_choice'] ?? '1';

// Pick primary and fallback keys based on model choice
if ($modelChoice === '2') {
    $apiKey = defined('GROQ_API_KEY_CHATBOT_2') ? GROQ_API_KEY_CHATBOT_2 : ($_ENV['GROQ_API_KEY'] ?? '');
    $fallbackKey = defined('GROQ_API_KEY_CHATBOT') ? GROQ_API_KEY_CHATBOT : '';
} else {
    $apiKey = defined('GROQ_API_KEY_CHATBOT') ? GROQ_API_KEY_CHATBOT : ($_ENV['GROQ_API_KEY'] ?? '');
    $fallbackKey = defined('GROQ_API_KEY_CHATBOT_2') ? GROQ_API_KEY_CHATBOT_2 : '';
}

$apiUrl = 'https://api.groq.com/openai/v1/chat/completions';

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

// Helper: fire a single cURL request to Groq
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlError, $curlErrno];
};

// Try primary key
[$httpCode, $response, $curlError, $curlErrno] = $fireRequest($apiKey);

// Auto-fallback: if rate-limited (429) and a different fallback key exists, retry once
if ($httpCode === 429 && !empty($fallbackKey) && $fallbackKey !== $apiKey) {
    error_log("Student Chatbot: primary key rate-limited (429), retrying with fallback key...");
    [$httpCode, $response, $curlError, $curlErrno] = $fireRequest($fallbackKey);
}

// Handle Connection Errors
if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'reply' => "I'm having trouble connecting to the server. (Error: $curlError)",
        'debug_error' => $curlError
    ]);
    exit;
}

// Handle API Errors
if ($httpCode !== 200) {
    $errorMessage = 'AI Service Unavailable';
    
    if ($httpCode === 401) {
        $errorMessage = 'Authentication Error (Invalid API Key)';
    } elseif ($httpCode === 429) {
        $errorMessage = 'I am receiving too many requests right now. Please try again in a moment.';
    } elseif ($httpCode>= 500) {
        $errorMessage = 'The AI service is currently down. Please try again later.';
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'reply' => $errorMessage,
        'http_code' => $httpCode
    ]);
    exit;
}

// Process Successful Response
$data = json_decode($response, true);
$reply = $data['choices'][0]['message']['content'] ?? 'I am not sure how to respond to that.';

echo json_encode(['reply' => $reply]);
