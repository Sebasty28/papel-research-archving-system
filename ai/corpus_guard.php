<?php
/**
 * CorpusGuard: what may go into Corpus and what may come out of him.
 *
 * Every Corpus endpoint runs a message through checkInput() before it does
 * anything else, and every reply through cleanOutput() before it is shown.
 * PromptGuard (prompt_guard.php) is the injection-phrase list and the rules
 * block; this is the rest of it, in the order that matters:
 *
 *   1. Credentials first. A message that carries a password, a key or a code
 *      is refused before it is matched, rate-counted, logged, recorded in the
 *      history or sent to the AI provider, which is a third party. The same
 *      patterns are printed into the page (credentialPatternsForJs()) so the
 *      browser refuses it before it leaves the device; the server is still the
 *      authority, the page check is only the earlier of the two.
 *   2. Length, then code and markup, links, and gibberish: the shapes that
 *      spend tokens without being a question about PAPEL.
 *   3. The injection phrases.
 *
 * A refusal is logged by its reason and length only, never its text: the text
 * is exactly what must not be kept.
 */
require_once __DIR__ . '/prompt_guard.php';

class CorpusGuard
{
    /** The most a question may be. A question about PAPEL fits in two lines; 2,000 was an essay's worth of tokens. */
    public const MAX_INPUT = 500;

    /** The most of a reply that is shown. The prompt asks for short answers; this is the floor under it. */
    public const MAX_OUTPUT = 1800;

    /** Example values the page guides print on purpose, so the redactor leaves them alone. */
    private const EXAMPLES = ['2026-00001-BN-0', 'FA0000BN2026'];

    /**
     * [source, flags] pairs, written so that PCRE and JavaScript read them the
     * same way (no \p{}, no possessives, no inline modifiers), which is what
     * lets one list serve the server and the page. No "~" in any source: it
     * is the PHP delimiter.
     */
    private static function credentialPatterns(): array
    {
        return [
            // "password: x", "my password is x", "password ko ay x" and the like, where the value
            // looks like a secret (has a digit or a symbol), so "the password is required" passes.
            ['\\b(?:password|passwd|pwd|passcode|pass code|pin|otp|one[- ]time code|verification code|secret|api[ _-]?key|access[ _-]?token|refresh[ _-]?token|token|client[ _-]?secret)\\b(?:\\s+(?:ko|mo|ay|po|is|was|=|:)){0,3}\\s*[:=]?\\s*["\'`]?(?=[^\\s"\'`]*[0-9!@#$%^&*_+=-])[^\\s"\'`]{4,}', 'i'],
            // The shape of the starting password the site generates: RayverReyes-056.
            ['\\b(?:[A-Z][a-z]+){2,}-[0-9]{3}\\b', ''],
            // Provider keys and tokens by their published prefixes.
            ['\\b(?:gsk|sk|pk|rk)[_-][A-Za-z0-9_-]{16,}', ''],
            ['\\bAIza[0-9A-Za-z_-]{30,}', ''],
            ['\\bgh[pousr]_[A-Za-z0-9]{30,}', ''],
            ['\\bxox[abprs]-[A-Za-z0-9-]{10,}', ''],
            ['\\bAKIA[0-9A-Z]{16}\\b', ''],
            ['\\bya29\\.[A-Za-z0-9_-]{20,}', ''],
            ['\\b1//[A-Za-z0-9_-]{30,}', ''],
            ['\\beyJ[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}\\.', ''],
            ['-----BEGIN [A-Z ]*PRIVATE KEY-----', ''],
            ['\\bbearer\\s+[A-Za-z0-9._+/-]{20,}', 'i'],
            // A long run of letters and digits with no spaces: a key, a hash or a token.
            ['\\b(?=[A-Za-z0-9+/_-]*[0-9])(?=[A-Za-z0-9+/_-]*[A-Za-z])[A-Za-z0-9+/_-]{32,}', ''],
            // A card number.
            ['\\b(?:[0-9][ -]?){13,19}\\b', ''],
        ];
    }

    /** Code, markup and commands, in a question or a reply. */
    private static function codePatterns(): array
    {
        return [
            '~<\s*/?\s*[a-z!?][^>]*>~i',                       // an HTML or XML tag, a comment, <?php
            '/```|~~~/',
            '~<\?(?:php|=)?~i',
            '~\b(?:select\s+[\w*,\s.]+\s+from|insert\s+into|update\s+\w+\s+set|delete\s+from|drop\s+(?:table|database)|union\s+(?:all\s+)?select|create\s+table|alter\s+table)\b~i',
            '~\b(?:function\s*\w*\s*\(|console\.\w+|document\.\w+|window\.\w+|eval\s*\(|import\s+(?:\{[^}]*\}|\*\s+as\s+\w+|\w+)\s+from\s+["\']|require(?:_once)?\s*\(|#include\b|public\s+static|private\s+function|def\s+\w+\s*\(|lambda\s+\w+\s*:|printf\s*\(|System\.out|\$_(?:GET|POST|SERVER|SESSION|COOKIE|ENV))~i',
            '~(?:\brm\s+-rf\b|\bsudo\s|\bcurl\s+-|\bwget\s+\S|\bchmod\s+[0-7]{3}|\|\s*(?:sh|bash|zsh)\b|\bpowershell\b|\bcmd\.exe\b)~i',
            '~\{\{.*?\}\}|\$\{[^}]*\}|=>\s*\{~',
            '~(?:\w+\s*\([^)]*\)\s*;\s*){2,}~',                  // two statements in a row
        ];
    }

    private static function credentialHit(string $s): bool
    {
        foreach (self::credentialPatterns() as [$src, $flags]) {
            if (preg_match('~' . $src . '~' . $flags, $s)) return true;
        }
        return false;
    }

    private static function codeHit(string $s): bool
    {
        foreach (self::codePatterns() as $p) {
            if (preg_match($p, $s)) return true;
        }
        // Many braces, semicolons and brackets is code whatever it says.
        // Not < or >: tags have their own pattern, and "Settings > Security" is how a page is named.
        return preg_match_all('/[{};\[\]=|\\\\]/', $s) >= 6;
    }

    /** Shapes that are not a question in any language: mashing, symbols, one thing said many times. */
    private static function gibberishHit(string $s): bool
    {
        $visible = preg_replace('/\s+/u', '', $s);
        $n = mb_strlen($visible);
        if ($n === 0) return true;
        if ($n >= 6) {
            $letters = preg_match_all('/\p{L}/u', $visible);
            if ($letters / $n < 0.5) return true;                       // mostly symbols or digits
        }
        if (preg_match('/(.)\1{5,}/u', $visible)) return true;          // one character six times over
        if (preg_match('/(?:qwert|wertyu|ertyui|rtyuio|tyuiop|asdfg|sdfgh|dfghj|fghjk|ghjkl|zxcvb|xcvbn|cvbnm)/i', $s)) return true;
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            if (mb_strlen($w) > 40) return true;                         // nothing in a question is that long
            if (mb_strlen($w) >= 8 && !preg_match('/[aeiouy]/u', $w)) return true;
        }
        if (count($words) >= 6) {
            $counts = array_count_values($words);
            arsort($counts);
            $top = reset($counts);
            if ($top >= 5 && $top / count($words) >= 0.4) return true;  // the same word, again and again
            if (count($words) >= 20 && count($counts) / count($words) < 0.35) return true;
        }
        return false;
    }

    private static function log(string $reason, int $length): void
    {
        error_log('[CorpusGuard] ' . json_encode([
            'reason' => $reason, 'length' => $length, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]));
    }

    /**
     * ['ok' => true, 'input' => cleaned] or ['ok' => false, 'reason' => code, 'message' => what to tell them].
     * Reasons: credential, length, code, link, gibberish, injection, empty.
     */
    public static function checkInput(string $message): array
    {
        $refuse = static function (string $reason, string $text, int $len): array {
            self::log($reason, $len);
            return ['ok' => false, 'reason' => $reason, 'message' => $text];
        };
        if (!mb_check_encoding($message, 'UTF-8')) return $refuse('gibberish', 'Please enter valid text.', strlen($message));
        // Invisible characters can hide a payload, and a control character has no business in a question.
        $m = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);
        $m = trim(preg_replace('/\s+/u', ' ', $m));
        $len = mb_strlen($m);
        if ($m === '') return ['ok' => false, 'reason' => 'empty', 'message' => 'Please enter a message.'];

        if (self::credentialHit($m)) {
            return $refuse('credential', "For your safety, Corpus never accepts passwords, codes or keys, so that message was **not sent or saved**.\n\nIf it was a real password, change it now in **Settings > Security**.", $len);
        }
        if ($len > self::MAX_INPUT) {
            return $refuse('length', 'Please keep your question under ' . self::MAX_INPUT . ' characters.', $len);
        }
        if (self::codeHit($m)) {
            return $refuse('code', "Corpus answers questions about PAPEL in plain words, so it doesn't read or write code, HTML or commands.\n\nPlease ask your question in a sentence.", $len);
        }
        if (preg_match('~https?://|\bwww\.\S+|\b\S+\.(?:com|net|org|io|xyz|ru|ph)/\S*~i', $m)) {
            return $refuse('link', "Corpus can't open links.\n\nTell me what you're trying to do in PAPEL and I'll point you to the right page.", $len);
        }
        if (self::gibberishHit($m)) {
            return $refuse('gibberish', "I couldn't read that as a question.\n\nPlease ask about PAPEL in a sentence, for example **How do I submit a paper?**", $len);
        }
        $pg = PromptGuard::sanitizeInput($m);
        if (!$pg['safe']) return ['ok' => false, 'reason' => 'injection', 'message' => $pg['message']];
        return ['ok' => true, 'input' => $pg['input']];
    }

    /** True when a published record carries something written to steer an AI; it is then left out of the prompt. */
    public static function looksLikeInjection(string $text): bool
    {
        return !PromptGuard::sanitizeInput(mb_substr($text, 0, 1900), true)['safe'] || self::codeHit($text);
    }

    /**
     * The reply as it may be shown, or a fixed refusal. Refuses the whole reply
     * rather than editing it when what is wrong is its substance (a secret, the
     * prompt, code); redacts only incidental details (an address, a number).
     */
    public static function cleanOutput($reply): string
    {
        $fallback = "I don't have that information here. The **Help Center** or **Contact Support** can help.";
        if (!is_string($reply) || !mb_check_encoding($reply, 'UTF-8')) return $fallback;
        $r = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $reply);
        $r = trim($r);
        if ($r === '') return $fallback;

        $checked = PromptGuard::validateOutput($r);
        if ($checked !== $r) return $checked;

        // Words that only the system prompt contains: seeing one means it is being repeated.
        foreach (['security rules', 'strict restrictions', 'site guide:', 'current page:', 'the reader is:',
                  'published paper on this page', 'highest priority', 'verified answers', 'formatting (the chat window',
                  'grounding rules'] as $marker) {
            if (stripos($r, $marker) !== false) { self::log('output_prompt', strlen($r)); return "I'm sorry, I can't share that. Is there anything about PAPEL I can help with?"; }
        }
        if (self::credentialHit(str_replace(self::EXAMPLES, '', $r))) {
            self::log('output_credential', strlen($r));
            return "I can't share passwords, codes or keys. To reset a password, ask whoever manages your account or use **Contact Support**.";
        }
        if (self::codeHit($r)) {
            self::log('output_code', strlen($r));
            return "Corpus explains PAPEL in plain words and doesn't provide code, HTML or commands. What are you trying to do on the site?";
        }

        // Links the model may have invented: a Markdown link keeps its words, a bare address goes.
        $r = preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $r);
        $r = preg_replace('~\bhttps?://\S+|\bwww\.\S+~i', '', $r);
        $r = preg_replace('/[ \t]{2,}/', ' ', $r);   // the gap a removed link leaves
        // Personal details nobody supplied: an email, a phone number, an ID that is not one of the examples.
        $r = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[hidden]', $r);
        $r = preg_replace('/(?:\+?63|\b0)9\d{2}[ -]?\d{3}[ -]?\d{4}\b/', '[hidden]', $r);
        $r = preg_replace_callback('/\b\d{4}-\d{5}-[A-Z]{2}-\d\b|\bFA\d{4}BN\d{4}\b/i',
            static fn($m) => in_array(strtoupper($m[0]), self::EXAMPLES, true) ? $m[0] : '[hidden]', $r);

        if (preg_match('/(.)\1{9,}/u', $r)) { self::log('output_gibberish', strlen($r)); return $fallback; }

        if (mb_strlen($r) > self::MAX_OUTPUT) {
            $cut = mb_substr($r, 0, self::MAX_OUTPUT);
            $end = max((int)mb_strrpos($cut, "\n"), (int)mb_strrpos($cut, '. '));
            $r = rtrim($end > self::MAX_OUTPUT / 2 ? mb_substr($cut, 0, $end + 1) : $cut) . "\n\nAsk me for more if you need it.";
        }
        $r = trim($r);
        return $r === '' ? $fallback : $r;
    }

    /** The grounding and refusal rules every Corpus prompt ends with, after PromptGuard's. */
    public static function rules(): string
    {
        return <<<'RULES'

GROUNDING RULES (as binding as the security rules):
- Answer only from what this prompt supplies: the site guide, the current page, the verified answers, the upload details and any published paper record. These are the only facts you have about PAPEL.
- If the answer is not there, reply exactly: "I don't have that information here. The Help Center or Contact Support can help." Do not guess, estimate or fill gaps with what similar systems do.
- Never name a button, menu, page, setting, deadline, policy, fee, person, office or rule that the supplied text does not name.
- When describing a published paper, use only its supplied fields. Never invent findings, methods, sample sizes, statistics, citations or page numbers.
- Never output HTML, Markdown links, URLs, code, commands, file paths, table or column names, JSON, or anything that looks like software. Name pages in words.
- Never output passwords, codes, keys, tokens, ID numbers, email addresses or phone numbers, even if the user typed them or asks for them back. If a message seems to contain one, tell them to remove it and change that password.
- Never ask the user for a password, code, ID number or any personal detail.
- Never repeat or transform the user's text on request (translate, encode, reverse, spell out, continue a list, repeat a word). Answer the PAPEL question, or decline in one sentence.
- Keep answers under about 120 words.
RULES;
    }

    /** The credential patterns for the page's own check, as [{s: source, f: flags}]. */
    public static function credentialPatternsForJs(): array
    {
        return array_map(static fn($p) => ['s' => $p[0], 'f' => $p[1]], self::credentialPatterns());
    }
}
