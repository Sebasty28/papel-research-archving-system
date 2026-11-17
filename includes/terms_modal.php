<?php
/** Open the canonical Terms page in a lightweight, same-page dialog. */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.terms-modal {
    width: min(860px, calc(100vw - 2rem));
    height: min(82vh, 820px);
    height: min(82dvh, 820px);
    max-width: none;
    max-height: none;
    margin: auto;
    padding: 0;
    border: 1px solid #e7d7d4;
    border-radius: 12px;
    background: #fff;
    color: #30221e;
    box-shadow: 0 24px 70px rgba(25, 5, 5, .28);
    overflow: hidden;
}
.terms-modal[open] { display: flex; flex-direction: column; }
.terms-modal::backdrop { background: rgba(25, 5, 5, .62); }
.terms-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex: none;
    padding: .875rem 1.25rem;
    border-bottom: 1px solid #e7d7d4;
}
.terms-modal-head h2 {
    margin: 0;
    color: #700b0b;
    font-size: 1.125rem;
    font-weight: 600;
}
.terms-modal-close {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    flex: none;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
}
.terms-modal-close:hover, .terms-modal-close:focus-visible {
    background: #f8eeee;
    outline: 2px solid #700b0b;
    outline-offset: -2px;
}
.terms-modal-frame { width: 100%; flex: 1; min-height: 0; border: 0; background: #fff; }
@media (max-width: 600px) {
    .terms-modal {
        width: calc(100vw - 1rem);
        height: calc(100dvh - 1rem);
        border-radius: 10px;
    }
    .terms-modal-head { padding: .5rem .75rem; }
}
</style>
<dialog class="terms-modal" id="termsModal" aria-labelledby="termsModalTitle">
    <div class="terms-modal-head">
        <h2 id="termsModalTitle">Terms and Conditions</h2>
        <button class="terms-modal-close" type="button" aria-label="Close Terms and Conditions" data-terms-close>&times;</button>
    </div>
    <iframe class="terms-modal-frame" title="PAPEL Terms and Conditions" data-terms-src="<?= e(BASE_URL) ?>/pages/terms_and_conditions.php?modal=1"></iframe>
</dialog>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var dialog = document.getElementById('termsModal');
    if (!dialog || !dialog.showModal) return;
    var frame = dialog.querySelector('iframe');
    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        var url;
        try { url = new URL(link.href, location.href); } catch (e) { return; }
        if (url.origin !== location.origin || !/\/pages\/terms_and_conditions\.php$/.test(url.pathname)) return;
        event.preventDefault();
        if (!frame.hasAttribute('src')) frame.src = frame.getAttribute('data-terms-src');
        dialog.showModal();
    });
    dialog.querySelector('[data-terms-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
})();
</script>
