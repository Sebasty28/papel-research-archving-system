<?php
/* A browser does not expose its installed extensions to a website. Check for
   blocked resources or hidden ad bait before showing a warning. */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.papel-browser-warning {
    position: fixed;
    inset: 0;
    z-index: 2147483200;
    display: grid;
    place-items: center;
    padding: 20px;
    background: rgba(51, 0, 0, .55);
}
.papel-browser-warning[hidden] { display: none; }
.papel-browser-warning__box {
    width: min(100%, 440px);
    padding: 24px;
    border-radius: 14px;
    background: #fff;
    color: #330000;
    box-shadow: 0 16px 45px rgba(0, 0, 0, .25);
    font: 400 15px/1.5 system-ui, sans-serif;
}
.papel-browser-warning__box h2 { margin: 0 0 10px; font-size: 20px; line-height: 1.3; }
.papel-browser-warning__box p { margin: 0 0 18px; }
.papel-browser-warning__actions { display: flex; flex-wrap: wrap; gap: 10px; }
.papel-browser-warning__actions button {
    min-height: 42px;
    padding: 8px 16px;
    border: 1px solid #800000;
    border-radius: 8px;
    background: #800000;
    color: #fff;
    cursor: pointer;
    font: inherit;
}
.papel-browser-warning__actions button:focus-visible { outline: 3px solid #c88484; outline-offset: 2px; }
/* Common blocker cosmetic rules hide this bait. It stays outside the visible
   page and is removed as soon as the check finishes. */
.papel-ad-probe { position: absolute; left: -10000px; width: 12px; height: 12px; }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var failed = false;
    function show() {
        if (!failed || !document.body || document.getElementById('papelBrowserWarning')) { return; }
        var layer = document.createElement('div');
        layer.id = 'papelBrowserWarning';
        layer.className = 'papel-browser-warning';
        layer.innerHTML = '<div class="papel-browser-warning__box" role="alertdialog" aria-modal="true" aria-labelledby="papelBrowserWarningTitle" aria-describedby="papelBrowserWarningText">' +
            '<h2 id="papelBrowserWarningTitle">A browser extension may affect PAPEL</h2>' +
            '<p id="papelBrowserWarningText">An ad blocker or another browser extension appears to be changing or blocking this page. Please turn it off for PAPEL, then reload. If the problem continues, check your connection or contact support.</p>' +
            '<div class="papel-browser-warning__actions"><button type="button" data-action="reload">Reload page</button></div></div>';
        document.body.appendChild(layer);
        var reload = layer.querySelector('[data-action="reload"]');
        reload.addEventListener('click', function () { location.reload(); });
        layer.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.key === 'Tab') {
                event.preventDefault();
                reload.focus();
            }
        });
        reload.focus();
    }

    window.papelBrowserInterference = {
        report: function () {
            if (failed) { return; }
            failed = true;
            if (document.body) { show(); }
            else { document.addEventListener('DOMContentLoaded', show, { once: true }); }
        }
    };

    function checkAdBlocker() {
        /* Some extensions replace a blocked script with an empty response, so
           its error event never fires. The local probe must have run before
           DOMContentLoaded if the browser allowed it through. */
        if (window.papelAdProbeLoaded !== true) {
            window.papelBrowserInterference.report();
        }
        var bait = document.createElement('div');
        bait.id = 'ad-banner';
        /* AdBlock's current generic rules hide ad_banner and adslot on the
           production domain; adsbox and ad-banner alone remain visible. */
        bait.className = 'papel-ad-probe adsbox ad-banner ad_banner adslot ad-placement';
        bait.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bait);
        setTimeout(function () {
            var style = bait.isConnected ? getComputedStyle(bait) : null;
            var blocked = !style || style.display === 'none' || style.visibility === 'hidden'
                || bait.offsetWidth === 0 || bait.offsetHeight === 0;
            bait.remove();
            if (blocked) { window.papelBrowserInterference.report(); }
        }, 50);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAdBlocker, { once: true });
    } else if (document.body) {
        checkAdBlocker();
    }

    /* Resource errors identify a real failure; a slow resource is not evidence.
       Fonts and optional images are excluded. */
    window.addEventListener('error', function (event) {
        var target = event.target;
        if (!target || !target.tagName) { return; }
        var tag = target.tagName.toLowerCase();
        if (tag === 'link') {
            if (target.rel !== 'stylesheet') { return; }
            /* A font service outage changes typography but does not break the
               controls. The site's own CSS and Bootstrap do. */
            var href = target.href || '';
            if (href.indexOf(location.origin + '/') !== 0 &&
                href.indexOf('https://cdn.jsdelivr.net/npm/bootstrap') !== 0) { return; }
        } else if (tag !== 'script') { return; }
        window.papelBrowserInterference.report();
    }, true);
})();
</script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>" src="<?= e(BASE_URL) ?>/includes/ads.js"></script>
