<?php
/**
 * Who may run a scheduled job.
 *
 * The two scripts under notifications/cron/ are deployed with the rest of the
 * site (deploy-staging.yml excludes scripts/** but not this folder), so they
 * were ordinary URLs that anybody who knew the path could run: one archives
 * every approved paper over five years old, pulling them out of the public
 * repository, and the other sends the reminder mail to real inboxes. Neither
 * asked who was calling.
 *
 * A shell is the way in that needs no secret, because having one is already
 * more access than this grants. The hosts these copies run on have no shell,
 * though, so a web cron has to be able to reach them too: that one carries
 * CRON_KEY from .env, in `?key=` or an `X-Papel-Cron` header.
 *
 * **It fails closed.** A copy with no CRON_KEY set has no web way in at all,
 * which is the state every copy is in until somebody sets one, so adding this
 * file cannot leave anything more open than it found it.
 *
 * Require after config/core.php, which is what loads .env.
 */

/**
 * The decision alone, with nothing read from the request or the environment,
 * so every branch of it can be driven from a test.
 */
function cron_access_allowed(string $configuredKey, string $given, bool $isCli): bool
{
    if ($isCli) return true;
    $configuredKey = trim($configuredKey);
    // No key configured is not "any key will do": it is "not over the web".
    if ($configuredKey === '' || $given === '') return false;
    return hash_equals($configuredKey, $given);
}

/**
 * Refuses anything that is not a shell or a correctly keyed request.
 *
 * Answers **404**, not 403: a job nobody is allowed to run should not confirm
 * that it is there. Logs the refusal, since a run of these in the error log is
 * how anybody would find out the path is being probed.
 */
function require_cron_access(string $job): void
{
    $given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_PAPEL_CRON'] ?? '');
    if (cron_access_allowed((string)($_ENV['CRON_KEY'] ?? ''), $given, PHP_SAPI === 'cli')) {
        return;
    }

    error_log(sprintf(
        'Cron job "%s" refused over the web from %s (%s)',
        $job,
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown address'),
        trim((string)($_ENV['CRON_KEY'] ?? '')) === '' ? 'no CRON_KEY is set' : 'wrong or missing key'
    ));

    http_response_code(404);
    exit;
}
