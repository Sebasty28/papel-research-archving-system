<?php
/**
 * The one and only showing of a new password.
 *
 * Passwords are no longer kept in readable form, so there is no column to look
 * one up in. What that column was actually useful for was the moment just after
 * creating or resetting an account: something to copy and hand over. This is
 * that moment, and only that moment.
 *
 * Set it from the POST handler, beside the success flash:
 *
 *     flash('new_password', json_encode(['who' => $fullName, 'pw' => $pass]));
 *
 * Then include this file where the flashes are rendered. It reads through
 * flash(), so it clears itself: a refresh does not bring it back, and it is
 * never written to the database or to a log.
 */
$_pw_raw = flash('new_password');
if ($_pw_raw):
    $_pw = json_decode($_pw_raw, true);
    if (is_array($_pw) && !empty($_pw['pw'])):
?>
<div class="pw-once" role="status">
    <span class="material-symbols-outlined pw-once-ico">vpn_key</span>
    <div class="pw-once-body">
        <p class="pw-once-lead">
            <?php if (!empty($_pw['who'])): ?>
                Password for <strong><?= e($_pw['who']) ?></strong>
            <?php else: ?>
                The new password
            <?php endif; ?>
        </p>
        <div class="pw-once-row">
            <code id="pwOnceValue"><?= e($_pw['pw']) ?></code>
            <button type="button" class="pw-once-copy" id="pwOnceCopy"
                    aria-label="Copy password">
                <span class="material-symbols-outlined" id="pwOnceIcon">content_copy</span>
                <span id="pwOnceLabel">Copy</span>
            </button>
        </div>
        <p class="pw-once-note">
            Shown once. It is not stored anywhere, so copy it now.
            <?php /* What to do if it is lost depends on the page: an account is
                     reset, a guest pass is reissued. */ ?>
            <?= e($_pw['lost'] ?? 'If it is lost, use Reset on their row to set a new one.') ?>
        </p>
    </div>
</div>

<style nonce="<?= csp_nonce() ?>">
.pw-once {
    display: flex; align-items: flex-start; gap: .6rem;
    border: 1px solid var(--soft-maroon); border-left: 3px solid var(--maroon-surface);
    background: var(--cream); border-radius: var(--r-badge, 2px);
    padding: .85rem 1rem; margin-bottom: 1rem;
}
.pw-once-ico { font-size: 20px; color: var(--maroon); flex: 0 0 auto; }
.pw-once-body { min-width: 0; }
.pw-once-lead { margin: 0 0 .45rem; font-size: .8125rem; color: var(--ink); }
.pw-once-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.pw-once-row code {
    font-family: ui-monospace, SFMono-Regular, Consolas, Menlo, monospace;
    font-size: .95rem; letter-spacing: .02em;
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--r-card, 8px); padding: .35rem .6rem; color: var(--ink);
    user-select: all;
}
.pw-once-copy {
    display: inline-flex; align-items: center; gap: .3rem;
    font: inherit; font-size: .75rem; cursor: pointer;
    background: none; border: 1px solid var(--soft-maroon); border-radius: var(--r-control, 4px);
    padding: .35rem .6rem; color: var(--maroon);
}
.pw-once-copy:hover { background: var(--white); }
.pw-once-copy:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.pw-once-copy .material-symbols-outlined { font-size: 16px; }
.pw-once-copy.is-done { color: var(--ok-text); border-color: var(--ok-border); }
.pw-once-note { margin: .45rem 0 0; font-size: .75rem; color: var(--grey); }
</style>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var btn   = document.getElementById('pwOnceCopy'),
        val   = document.getElementById('pwOnceValue'),
        icon  = document.getElementById('pwOnceIcon'),
        label = document.getElementById('pwOnceLabel');
    if (!btn || !val) return;

    function done() {
        btn.classList.add('is-done');
        if (icon)  icon.textContent  = 'check';
        if (label) label.textContent = 'Copied';
        setTimeout(function () {
            btn.classList.remove('is-done');
            if (icon)  icon.textContent  = 'content_copy';
            if (label) label.textContent = 'Copy';
        }, 1800);
    }

    /* Selecting the text is the fallback, and it is not a poor one: the
       clipboard API needs a secure context, and this runs over plain HTTP the
       moment somebody opens the site by its LAN address rather than localhost. */
    function selectAndCopy() {
        var r = document.createRange();
        r.selectNodeContents(val);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
        try { if (document.execCommand('copy')) { done(); } } catch (e) { /* selected; copy by hand */ }
    }

    btn.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(val.textContent).then(done, selectAndCopy);
        } else {
            selectAndCopy();
        }
    });
})();
</script>
<?php
    endif;
endif;
