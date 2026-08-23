<?php
/**
 * The banner that says what just happened, in one place.
 *
 * Five pages had hand-rolled their own pair of these and the review desks had
 * none at all — which is how "Paper forwarded to the Research Coordinator" ended
 * up on Manage Students. flash() consumes what it reads, so a message set by one
 * page and never read by the page it redirects to survives in the session and
 * appears on whichever page next asks for one.
 *
 * Rendering it from here means every page that can set a message also shows it,
 * and shows it the same way.
 *
 * Include once, then call flash_banner() where the banner belongs. Calling it
 * twice on a page is harmless: the second call has nothing left to read.
 */

if (!function_exists('flash_banner')) {

    /**
     * Draw whichever of the two messages is waiting.
     *
     * @param bool $wrap whether to wrap the banners in a .wrap container, for
     *                   pages whose layout does not already provide one
     */
    function flash_banner(bool $wrap = false): void {
        $good = flash('success');
        $bad  = flash('error');
        if ($good === null && $bad === null) return;

        if ($wrap) echo '<div class="wrap">';
        if ($bad !== null)  flash_banner_one('is-bad', 'error', $bad, 'alert');
        if ($good !== null) flash_banner_one('is-good', 'check_circle', $good, 'status');
        if ($wrap) echo '</div>';
    }

    /** One banner. Separate so the two cannot drift apart. */
    function flash_banner_one(string $tone, string $icon, string $text, string $role): void {
        ?>
        <div class="mgmt-flash <?= e($tone) ?>" role="<?= e($role) ?>">
            <span class="material-symbols-outlined"><?= e($icon) ?></span>
            <span class="mgmt-flash-text"><?= e($text) ?></span>
            <?php /* Dismissable because it is not a dialog: it reports something
                     already done, and it should be possible to put it away
                     without navigating. */ ?>
            <button type="button" class="mgmt-flash-x js-flash-x" aria-label="Dismiss this message">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <?php
    }
}

/* Emitted once per page, however many banners are on it. The management
   consoles already style .mgmt-flash through manage_page.php; these rules add
   what the close button needs and give the same look to the pages that do not
   load that stylesheet. */
if (!defined('FLASH_BANNER_ASSETS')) {
    define('FLASH_BANNER_ASSETS', true);
    ?>
    <style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    .mgmt-flash {
        display: flex; align-items: flex-start; gap: .5rem;
        border-radius: var(--r-card, 8px); border: 1px solid var(--border);
        font-size: .8125rem; padding: .75rem 1rem; margin-bottom: 1rem;
        color: var(--ink); line-height: 1.6;
    }
    .mgmt-flash .material-symbols-outlined { font-size: 18px; flex: 0 0 auto; }
    .mgmt-flash.is-good { background: var(--ok-bg);  border-color: var(--ok-border);  color: var(--ok-text); }
    .mgmt-flash.is-bad  { background: var(--bad-bg); border-color: var(--bad-border); color: var(--bad-text); }
    /* Takes the leftover width so the button sits hard against the right edge
       whatever the message length. */
    .mgmt-flash-text { flex: 1 1 auto; }
    .mgmt-flash-x {
        flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
        width: 1.5rem; height: 1.5rem; margin: -.125rem -.25rem 0 0;
        padding: 0; border: 0; border-radius: var(--r-card, 8px);
        background: transparent; color: inherit; cursor: pointer;
        opacity: .55; transition: opacity .15s ease, background .15s ease;
    }
    .mgmt-flash-x:hover  { opacity: 1; background: rgba(0, 0, 0, .06); }
    .mgmt-flash-x:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; opacity: 1; }
    .mgmt-flash-x .material-symbols-outlined { font-size: 16px; }
    </style>
    <script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
    /* Delegated, so a banner added later is covered too, and so this works
       whether the script runs before or after the markup. */
    document.addEventListener('click', function (e) {
        var x = e.target.closest('.js-flash-x');
        if (x) x.closest('.mgmt-flash').remove();
    });
    </script>
    <?php
}
