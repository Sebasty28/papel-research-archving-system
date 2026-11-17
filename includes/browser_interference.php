<?php
/* A browser does not expose its installed extensions to a website. Check for
   ad-specific blocking evidence before showing a warning. */
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
    var reason = '';
    var dismissKey = 'papel_browser_warning_dismissed';
    function dismissed() {
        try { return sessionStorage.getItem(dismissKey) === '1'; }
        catch (error) { return false; }
    }
    function show() {
        if (!failed || !document.body || dismissed() || document.getElementById('papelBrowserWarning')) { return; }
        /* The support form must remain reachable from this warning. */
        if (/\/pages\/contact_support(?:\.php)?$/.test(location.pathname)) { return; }
        var previousFocus = document.activeElement;
        var layer = document.createElement('div');
        layer.id = 'papelBrowserWarning';
        layer.className = 'papel-browser-warning';
        layer.dataset.trigger = reason;
        layer.innerHTML = '<div class="papel-browser-warning__box" role="alertdialog" aria-modal="true" aria-labelledby="papelBrowserWarningTitle" aria-describedby="papelBrowserWarningText">' +
            '<h2 id="papelBrowserWarningTitle">A blocker may affect PAPEL</h2>' +
            '<p id="papelBrowserWarningText">An ad blocker or another browser extension may be hiding part of PAPEL. If you use one, allow PAPEL and reload. If this warning appears unexpectedly, you can dismiss it. If the problem continues, <a href="<?= e(BASE_URL) ?>/pages/contact_support.php">contact support</a>.</p>' +
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
        reason: '',
        report: function (trigger) {
            if (failed) { return; }
            failed = true;
            reason = trigger || 'unknown';
            this.reason = reason;
            if (document.body) { show(); }
            else { document.addEventListener('DOMContentLoaded', show, { once: true }); }
        }
    };

    function verifyAdScript() {
        /* A single failed request can be a brief network failure. Retry the
           ad-like path beside an ordinary local script; only a selective
           failure is evidence of filtering. Stop after three seconds so a
           delayed request cannot interrupt a later login or edit. */
        var control = document.createElement('script');
        var retry = document.createElement('script');
        var remaining = 2;
        var controlLoaded = false;
        var adLoaded = false;
        var finished = false;
        var timer = setTimeout(function () { finished = true; control.remove(); retry.remove(); }, 3000);
        function settle(which, loaded) {
            if (finished) { return; }
            if (which === 'control') controlLoaded = loaded;
            else adLoaded = loaded && window.papelAdProbeLoaded === true;
            if (--remaining) { return; }
            finished = true;
            clearTimeout(timer);
            control.remove();
            retry.remove();
            if (controlLoaded && !adLoaded) { window.papelBrowserInterference.report('ad-script'); }
        }
        var suffix = '?check=' + Date.now();
        control.src = <?= json_encode(BASE_URL . '/includes/browser_probe.js') ?> + suffix;
        retry.src = <?= json_encode(BASE_URL . '/includes/ads.js') ?> + suffix;
        control.onload = function () { settle('control', window.papelNeutralProbeLoaded === true); };
        control.onerror = function () { settle('control', false); };
        retry.onload = function () { settle('ad', true); };
        retry.onerror = function () { settle('ad', false); };
        document.head.appendChild(control);
        document.head.appendChild(retry);
    }

    function checkAdBlocker() {
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
                || parseFloat(style.width) === 0 || parseFloat(style.height) === 0;
            bait.remove();
            if (blocked) { window.papelBrowserInterference.report('ad-element'); }
            else if (window.papelAdProbeLoaded !== true) { verifyAdScript(); }
        }, 50);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAdBlocker, { once: true });
    } else if (document.body) {
        checkAdBlocker();
    }

})();
</script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>" src="<?= e(BASE_URL) ?>/includes/ads.js"></script>
