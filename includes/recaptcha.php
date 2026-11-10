<?php
/**
 * The "I'm not a robot" check on the two sign-in surfaces.
 *
 * Sign-in is ID + password and the IDs are guessable by design (student
 * numbers run in sequence, staff IDs follow FAC-2026-00n) so the only thing
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
 * leaves the server, and .env is gitignored: keep it that way.
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
 * refusing with a message that explains itself: the alternative is a form
 * nobody can submit and no way to find out why.
 */
function recaptcha_scripts(): string
{
    if (!recaptcha_enabled()) return '';
    $n = csp_nonce();
    return <<<HTML
<style nonce="{$n}">
/* Google ships the tick box at a fixed 304x78 and will not resize it, so
   scaling is the only lever there is. The wrapper's height is brought in to
   match, because a transform leaves the original box behind in the layout and
   the form would otherwise keep a band of empty space under the widget. */
.papel-recaptcha {
    display: flex;
    justify-content: center;
    overflow: hidden;
    height: 66px;                 /* 78 * .85 */
    margin: .75rem 0 .25rem;
    transition: height .2s ease, margin .2s ease, opacity .2s ease;
}
.papel-recaptcha .g-recaptcha {
    transform: scale(.85);
    transform-origin: center top;
}

/* Out of the way until there is something to protect. Clipped to nothing
   rather than display:none on purpose: Google measures the container when it
   builds the frame, and one that was never laid out gets a widget of zero
   size that stays zero when it is shown again. */
.papel-recaptcha.is-gated {
    height: 0;
    margin: 0;
    opacity: 0;
    pointer-events: none;
}

/* The narrowest phones: 304px plus the panel's own padding is wider than the
   screen, so it comes down again. */
@media (max-width: 380px) {
    .papel-recaptcha { height: 59px; }        /* 78 * .76 */
    .papel-recaptcha .g-recaptcha { transform: scale(.76); }
}
@media (prefers-reduced-motion: reduce) {
    .papel-recaptcha { transition: none; }
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

    /* The tick box waits until both fields have something in them. Asking
       somebody to prove they are human before they have typed anything put the
       slowest step of signing in first, and an empty form cannot be submitted
       in any case. Both sign-in surfaces name their fields the same way, so
       this finds them without either page having to say where they are. */
    function wireReveal() {
        document.querySelectorAll('.papel-recaptcha').forEach(function (box) {
            var form = box.closest('form');
            if (!form) { return; }
            /* The two surfaces disagree on what the first field is called
               (the slide-in posts "identifier", the standalone page posts
               "username") so both are accepted rather than the widget
               silently staying put on whichever one was not named here. */
            var id = form.querySelector(
                'input[name="identifier"], input[name="username"]');
            var pw = form.querySelector('input[name="password"]');
            // Not a sign-in form: leave the widget alone rather than hide
            // something that nothing will ever bring back.
            if (!id || !pw) { return; }

            function sync() {
                var ready = id.value.trim() !== '' && pw.value !== '';
                box.classList.toggle('is-gated', !ready);
            }
            ['input', 'change', 'paste', 'blur'].forEach(function (ev) {
                id.addEventListener(ev, sync);
                pw.addEventListener(ev, sync);
            });
            box.classList.add('is-gated');
            sync();
            /* A password manager can fill both fields without firing any of
               the events above, and it often lands after this runs. */
            setTimeout(sync, 400);
            setTimeout(sync, 1200);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireReveal);
    } else {
        wireReveal();
    }

    /* Google appends the image challenge directly to <body>, separately from
       the scaled checkbox. On a phone it positions that frame at the checkbox's
       left edge, which can put half of it off-screen. Move the outer box into
       the viewport, but leave its iframe unscaled so touch targets keep their
       native coordinates. Observe only that box after it appears. */
    function wireChallenge() {
        var popup = null;
        var frame = null;
        var fitted = false;
        var queued = false;
        var finder = new MutationObserver(find);

        function fit() {
            if (!popup || !frame || !frame.getClientRects().length ||
                getComputedStyle(popup).visibility !== 'visible' ||
                frame.offsetHeight < 200) return;

            var view = window.visualViewport;
            var width = view ? view.width : document.documentElement.clientWidth;
            var height = view ? view.height : document.documentElement.clientHeight;
            if (width > 600 && !fitted) return;

            /* Google's 1px frame border is the only excess on a 320px phone.
               Remove it so the actual iframe fits without scaling its tiles. */
            if (popup.style.border !== '0px') popup.style.border = '0px';
            var left = Math.round((view ? view.offsetLeft : 0) +
                                  Math.max(0, (width - popup.offsetWidth) / 2));
            var top = Math.round((view ? view.offsetTop : 0) +
                                 Math.max(0, (height - popup.offsetHeight) / 2));
            var wanted = {
                position: 'fixed', left: left + 'px', top: top + 'px',
                transform: 'none'
            };
            Object.keys(wanted).forEach(function (name) {
                if (popup.style.getPropertyValue(name) !== wanted[name]) {
                    popup.style.setProperty(name, wanted[name]);
                }
            });
            fitted = true;
        }

        function schedule() {
            if (queued) return;
            queued = true;
            requestAnimationFrame(function () { queued = false; fit(); });
        }

        function find() {
            frame = document.querySelector('iframe[src*="/recaptcha/api2/bframe"]');
            if (!frame) return;
            popup = frame;
            while (popup.parentElement && popup.parentElement !== document.body) {
                popup = popup.parentElement;
            }
            if (popup.parentElement !== document.body) { popup = null; return; }
            finder.disconnect();
            new MutationObserver(schedule).observe(popup, {
                attributes: true, attributeFilter: ['style']
            });
            schedule();
        }

        finder.observe(document.body, { childList: true, subtree: true });
        find();
        window.addEventListener('resize', schedule);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', schedule);
            window.visualViewport.addEventListener('scroll', schedule);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireChallenge);
    } else {
        wireChallenge();
    }
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
