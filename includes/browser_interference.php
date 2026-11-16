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
.papel-browser-warning__box a { color: #800000; text-decoration: underline; }
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
.papel-browser-warning__actions button[data-action="close"] { background: #fff; color: #800000; }
.papel-browser-warning__actions button:focus-visible { outline: 3px solid #c88484; outline-offset: 2px; }
/* Common blocker cosmetic rules hide this bait. It stays outside the visible
   page and is removed as soon as the check finishes. */
.papel-ad-probe { position: absolute; left: -10000px; width: 12px; height: 12px; }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var failed = false;
    var dismissKey = 'papel_browser_warning_dismissed';
    function dismissed() {
        try { return sessionStorage.getItem(dismissKey) === '1'; }
        catch (error) { return false; }
    }
    function show() {
        if (!failed || !document.body || dismissed() || document.getElementById('papelBrowserWarning')) { return; }
        /* The support form must remain reachable from this warning. */
        if (/\/pages\/contact_support\.php$/.test(location.pathname)) { return; }
        var previousFocus = document.activeElement;
        var layer = document.createElement('div');
        layer.id = 'papelBrowserWarning';
        layer.className = 'papel-browser-warning';
        layer.innerHTML = '<div class="papel-browser-warning__box" role="alertdialog" aria-modal="true" aria-labelledby="papelBrowserWarningTitle" aria-describedby="papelBrowserWarningText">' +
            '<h2 id="papelBrowserWarningTitle">Something may be affecting PAPEL</h2>' +
            '<p id="papelBrowserWarningText">A browser extension or failed page resource may be interfering with PAPEL. If you use an ad blocker, turn it off for PAPEL and reload. Otherwise, you can dismiss this warning. If the problem continues, <a href="<?= e(BASE_URL) ?>/pages/contact_support.php">contact support</a>.</p>' +
            '<div class="papel-browser-warning__actions"><button type="button" data-action="reload">Reload page</button><button type="button" data-action="close">Dismiss</button></div></div>';
        document.body.appendChild(layer);
        var support = layer.querySelector('a');
        var reload = layer.querySelector('[data-action="reload"]');
        var close = layer.querySelector('[data-action="close"]');
        function dismiss() {
            try { sessionStorage.setItem(dismissKey, '1'); } catch (error) { /* Storage can be disabled. */ }
            layer.remove();
            if (previousFocus && previousFocus.focus && previousFocus.isConnected) { previousFocus.focus(); }
        }
        reload.addEventListener('click', function () { location.reload(); });
        close.addEventListener('click', dismiss);
        layer.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); dismiss(); return; }
            if (event.key !== 'Tab') { return; }
            event.preventDefault();
            var controls = [support, reload, close];
            var index = controls.indexOf(document.activeElement);
            controls[(index + (event.shiftKey ? controls.length - 1 : 1)) % controls.length].focus();
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
