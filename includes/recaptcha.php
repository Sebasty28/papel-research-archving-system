<?php
/**
 * The "I'm not a robot" check on the two sign-in surfaces.
 *
 * Sign-in is ID + password and the IDs are guessable by design — student
 * numbers run in sequence, staff IDs follow FAC-2026-00n — so the only thing
 * between a script and an account is a password rule that admits six
 * characters. login_attempts already slows a run down after the fact; this
 * stops most of them being made at all.
 *
 * The whole feature is inert until both keys are present in .env. That is
 * deliberate: a missing key must never be able to lock every user out of the
 * system, so with no keys configured there is no widget, no verification, and
 * sign-in behaves exactly as it did before.
 *
 * Register a site at https://google.com/recaptcha/admin (reCAPTCHA v2,
 * "I'm not a robot" tick box) and put the pair in .env:
 *
 *     RECAPTCHA_SITE_KEY=6Lc...
 *     RECAPTCHA_SECRET_KEY=6Lc...
 *
 * The site key is public and appears in the markup. The secret key never
 * leaves the server, and .env is gitignored — keep it that way.
 */

function recaptcha_enabled(): bool
{
    return defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== ''
        && defined('RECAPTCHA_SECRET_KEY') && RECAPTCHA_SECRET_KEY !== '';
}

/**
 * The tick box itself. Returns nothing at all when the feature is switched
 * off, so the forms can call it unconditionally.
 */
function recaptcha_field(): string
{
    if (!recaptcha_enabled()) return '';
    return '<div class="papel-recaptcha">'
         . '<div class="g-recaptcha" data-sitekey="' . e(RECAPTCHA_SITE_KEY) . '"'
         . ' data-callback="papelRecaptchaSolved" data-expired-callback="papelRecaptchaExpired"></div>'
         . '</div>';
}

/**
 * Google's script, plus the bit that keeps the submit button locked until the
 * challenge is solved.
 *
 * The button is disabled from JavaScript rather than in the markup on purpose.
 * If this script never runs the button stays clickable and the server does the
 * refusing with a message that explains itself — the alternative is a form
 * nobody can submit and no way to find out why.
 */
function recaptcha_scripts(): string
{
    if (!recaptcha_enabled()) return '';
    $n = csp_nonce();
    return <<<HTML
<style nonce="{$n}">
.papel-recaptcha { display: flex; justify-content: center; margin: .875rem 0 .25rem; }
/* The widget is a fixed 304px and does not shrink; on a narrow modal it is
   scaled down rather than allowed to push the panel wider than the screen. */
@media (max-width: 360px) {
    .papel-recaptcha { transform: scale(.88); transform-origin: center; }
}
[data-recaptcha-gate][disabled] { opacity: .55; cursor: not-allowed; }
</style>
<script nonce="{$n}">
(function () {
    function gates() { return document.querySelectorAll('[data-recaptcha-gate]'); }
    function lock(state) {
        gates().forEach(function (b) {
            b.disabled = state;
            b.title = state ? 'Confirm you are not a robot first' : '';
        });
    }
    // Only lock once we know this script is running; see the note above.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { lock(true); });
    } else {
        lock(true);
    }
    /* Google calls these by name off the global object, so they cannot be
       scoped inside this closure. */
    window.papelRecaptchaSolved  = function () { lock(false); };
    window.papelRecaptchaExpired = function () { lock(true); };
})();
</script>
<script src="https://www.google.com/recaptcha/api.js" async defer nonce="{$n}"></script>
HTML;
}

/**
 * Ask Google whether the token the browser sent is real.
 *
 * Returns true when the feature is off, so callers can verify unconditionally.
 * A transport failure returns false: if the check cannot be performed then it
 * has not been performed, and treating that as a pass would mean an attacker
 * who can reach the server but block its outbound traffic gets a free run.
 */
function recaptcha_verify(?string $token, ?string $ip = null): bool
{
    if (!recaptcha_enabled()) return true;

    $token = trim((string) $token);
    if ($token === '') return false;

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        error_log('reCAPTCHA verify unreachable: ' . ($err ?: "HTTP $code"));
        return false;
    }

    $data = json_decode($raw, true);
    if (empty($data['success'])) {
        // The codes say whether this was a bad token or a misconfigured key,
        // which is the difference between an attack and a broken deployment.
        error_log('reCAPTCHA rejected: ' . implode(',', $data['error-codes'] ?? ['unknown']));
        return false;
    }
    return true;
}

/** What to tell somebody whose check did not pass. */
function recaptcha_error_message(): string
{
    return 'Please confirm you are not a robot, then sign in again.';
}
