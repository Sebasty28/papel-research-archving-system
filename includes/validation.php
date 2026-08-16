<?php
// Profanity filter
function contains_profanity(string $text): bool {
    $badWords = ['fuck', 'shit', 'damn', 'bitch', 'ass', 'bastard', 'crap', 'piss', 'dick', 'cock', 'pussy', 'slut', 'whore', 'fag', 'nigger', 'cunt', 'puta', 'gago', 'tangina', 'bobo', 'tanga', 'ulol', 'putangina'];
    $lower = strtolower($text);
    foreach($badWords as $word){
        if(strpos($lower, $word) !== false) return true;
    }
    return false;
}

// Gibberish detection
function is_gibberish(string $text): bool {
    $text = trim($text);
    if(strlen($text) < 5) return false; // Too short to reliably detect
    
    // Check for repeated characters (e.g., "aaaaaaa", "111111")
    if(preg_match('/(.)\1{4,}/', $text)) return true;

    // Check for common keyboard mash patterns
    if(preg_match('/asdf|qwer|zxcv|hjkl|jkl;|fghj|cvbn|tyui/i', $text)) return true;
    
    // Check vowel ratio (gibberish usually has very low vowel count) - only for longer text
    if (strlen($text)> 8) {
        $vowels = preg_match_all('/[aeiou]/i', $text);
        $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyz]/i', $text);
        if($consonants> 0 && ($vowels / $consonants) < 0.15) return true;
    }
    
    return false;
}

function validate_feedback(string $feedback): array {
    $feedback = trim($feedback);
    
    if(empty($feedback)){
        return ['valid' => false, 'error' => 'Feedback cannot be empty'];
    }
    
    if(is_gibberish($feedback)){
        return ['valid' => false, 'error' => 'Feedback appears to be gibberish. Please provide meaningful feedback'];
    }
    
    if(contains_profanity($feedback)){
        return ['valid' => false, 'error' => 'Feedback contains inappropriate language. Please be professional'];
    }
    
    return ['valid' => true];
}
