<?php
/** A site-wide invitation to the PAPEL feedback form. The form loads on demand. */
$surveyFormUrl = 'https://docs.google.com/forms/d/e/1FAIpQLSfho39j6Iyrh1UfLF-WOqti0M2VUyVRlh9pXuZ_xBdE-2w11w/viewform';
$surveyEmbedUrl = $surveyFormUrl . '?embedded=true';
?>
<style nonce="<?= csp_nonce() ?>">
.survey-ribbon {
    position: fixed;
    right: 0;
    bottom: max(76px, calc(env(safe-area-inset-bottom) + 76px));
    z-index: 930;
    display: inline-flex;
    align-items: center;
    gap: .55rem;
    padding: .75rem 1rem .75rem .8rem;
    border: 1px solid color-mix(in srgb, var(--maroon-surface) 70%, #fff);
    border-right: 0;
    border-radius: .65rem 0 0 .65rem;
    background: var(--maroon-surface);
    box-shadow: 0 4px 18px rgba(51, 0, 0, .22);
    color: #fff;
    font: 700 .8rem var(--font-head);
    cursor: pointer;
}
.survey-ribbon:hover { background: var(--maroon-surface-hover); color: #fff; }
.survey-ribbon:focus-visible,
.survey-dialog button:focus-visible,
.survey-dialog a:focus-visible { outline: 3px solid #e9b85f; outline-offset: 3px; }
.survey-ribbon .material-symbols-outlined { font-size: 1.25rem; }
/* The menu and the document reader need the phone's full touch area. */
@media (max-width: 900px) {
    html.nav-open .survey-ribbon,
    body:has(.pdf-dock.is-open) .survey-ribbon { display: none; }
    .survey-ribbon {
        max-width: min(16rem, calc(100vw - 1rem));
        padding: .6rem .75rem;
        font-size: .75rem;
        line-height: 1.2;
    }
}
.survey-dialog {
    width: min(980px, calc(100vw - 2rem));
    max-width: none;
    height: min(800px, calc(100dvh - 2rem));
    max-height: none;
    margin: auto;
    padding: 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: .8rem;
    background: var(--white);
    color: var(--ink);
    box-shadow: 0 20px 60px rgba(20, 0, 0, .35);
}
.survey-dialog::backdrop { background: rgba(22, 0, 0, .68); }
.survey-dialog[open] { display: flex; flex-direction: column; }
.survey-dialog-head {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: .8rem 1rem;
    background: var(--maroon-surface);
    color: #fff;
}
.survey-dialog-title { flex: 1; margin: 0; font: 700 1rem var(--font-head); }
.survey-dialog-link { color: #fff; font-size: .75rem; text-decoration: underline; text-underline-offset: 2px; }
.survey-dialog-link:hover { color: #fff; }
.survey-dialog-close {
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    flex: none;
    border: 0;
    border-radius: .35rem;
    background: transparent;
    color: #fff;
    cursor: pointer;
}
.survey-dialog-close:hover { background: rgba(255, 255, 255, .16); }
.survey-dialog-help { flex: none; border: 0; background: transparent; color: #fff; font: inherit; font-size: .75rem; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
.survey-dialog-body { position: relative; flex: 1; min-height: 0; width: 100%; background: #fff; }
.survey-dialog iframe { display: block; width: 100%; height: 100%; border: 0; background: #fff; }
.survey-dialog-layer { position: absolute; inset: 0; z-index: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .9rem; padding: 1.5rem; background: var(--white); color: var(--ink); text-align: center; }
.survey-dialog-layer[hidden] { display: none; }
.survey-dialog-layer h3 { margin: 0; color: var(--maroon-surface); font: 700 1.35rem var(--font-head); }
.survey-dialog-layer p { max-width: 38rem; margin: 0; line-height: 1.55; }
.survey-dialog-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .7rem; margin-top: .35rem; }
.survey-dialog-actions button, .survey-dialog-actions a { display: inline-flex; align-items: center; justify-content: center; min-height: 2.7rem; padding: .55rem .9rem; border: 1px solid var(--maroon-surface); border-radius: .45rem; background: var(--maroon-surface); color: #fff; font: 700 .85rem var(--font-head); text-decoration: none; cursor: pointer; }
.survey-dialog-actions a { background: transparent; color: var(--maroon-surface); }
.survey-dialog-actions button:hover { background: var(--maroon-surface-hover); }
.survey-dialog-actions a:hover { background: color-mix(in srgb, var(--maroon-surface) 8%, #fff); color: var(--maroon-surface); }
.survey-form-spinner { width: 2rem; height: 2rem; border: 3px solid var(--border); border-top-color: var(--maroon-surface); border-radius: 50%; animation: survey-spin .8s linear infinite; }
@keyframes survey-spin { to { transform: rotate(360deg); } }
.survey-ad-probe { position: absolute; display: block; width: 1px; height: 1px; overflow: hidden; pointer-events: none; opacity: 0; }
@media (max-width: 600px) {
    .survey-dialog { width: 100vw; height: 100dvh; border: 0; border-radius: 0; }
    .survey-dialog-head { padding: .65rem .75rem; }
    .survey-dialog-title { font-size: .9rem; }
}
</style>
<button class="survey-ribbon" id="surveyRibbon" type="button" aria-haspopup="dialog" aria-controls="surveyDialog">
    <span class="material-symbols-outlined" aria-hidden="true">rate_review</span>
    <span>Answer our Survey</span>
</button>
<dialog class="survey-dialog" id="surveyDialog" aria-labelledby="surveyDialogTitle">
    <div class="survey-dialog-head">
        <h2 class="survey-dialog-title" id="surveyDialogTitle">PAPEL Survey</h2>
        <button class="survey-dialog-help" id="surveyDialogHelp" type="button">Form not showing?</button>
        <a class="survey-dialog-link" href="<?= e($surveyFormUrl) ?>" target="_blank" rel="noopener noreferrer">Open in Google Forms</a>
        <button class="survey-dialog-close" id="surveyDialogClose" type="button" aria-label="Close survey">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
    </div>
    <div class="survey-dialog-body">
        <iframe id="surveyFrame" title="PAPEL survey on Google Forms" data-src="<?= e($surveyEmbedUrl) ?>" referrerpolicy="strict-origin-when-cross-origin"></iframe>
        <div class="survey-dialog-layer" id="surveyLoading" role="status" hidden>
            <span class="survey-form-spinner" aria-hidden="true"></span>
            <p>Loading Google Forms…</p>
        </div>
        <div class="survey-dialog-layer" id="surveyBlocked" role="alert" hidden>
            <h3 id="surveyBlockedTitle">Survey not loading</h3>
            <p id="surveyBlockedMessage">Your ad blocker may be preventing Google Forms from loading. Turn it off for pupbcpapel.com, then try again.</p>
            <div class="survey-dialog-actions">
                <button id="surveyRetry" type="button">Try again</button>
                <a href="<?= e($surveyFormUrl) ?>" target="_blank" rel="noopener noreferrer">Open in Google Forms</a>
            </div>
        </div>
        <span id="surveyAdProbe" class="survey-ad-probe adsbox ad-banner" aria-hidden="true"></span>
    </div>
</dialog>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var ribbon = document.getElementById('surveyRibbon');
    var dialog = document.getElementById('surveyDialog');
    var frame = document.getElementById('surveyFrame');
    var close = document.getElementById('surveyDialogClose');
    var help = document.getElementById('surveyDialogHelp');
    var loading = document.getElementById('surveyLoading');
    var blocked = document.getElementById('surveyBlocked');
    var blockedTitle = document.getElementById('surveyBlockedTitle');
    var blockedMessage = document.getElementById('surveyBlockedMessage');
    var retry = document.getElementById('surveyRetry');
    var probe = document.getElementById('surveyAdProbe');
    if (!ribbon || !dialog || !frame || !close || !help || !loading || !blocked || !retry) return;

    // The paper preview and other embedded PAPEL pages must not repeat the invite.
    if (window.self !== window.top) {
        ribbon.remove();
        dialog.remove();
        return;
    }

    var timer = 0;
    var loaded = false;
    var frameLoaded = false;
    var reachable = false;
    var adBlocked = false;
    var attempt = 0;
    function clearWait() { if (timer) clearTimeout(timer); timer = 0; }
    function probeBlocked() {
        if (!probe || !probe.isConnected) return true;
        var style = getComputedStyle(probe);
        var box = probe.getBoundingClientRect();
        return style.display === 'none' || style.visibility === 'hidden' || !box.width || !box.height;
    }
    function showBlockerMessage(detected) {
        clearWait();
        loading.hidden = true;
        blockedTitle.textContent = detected ? 'Ad blocker detected' : 'Survey not loading';
        blockedMessage.textContent = detected
            ? 'Turn off your ad blocker for pupbcpapel.com so Google Forms can load here. Then select Try again.'
            : 'Google Forms did not load. If an ad blocker is on, turn it off for pupbcpapel.com, then select Try again.';
        blocked.hidden = false;
    }
    function finishLoad() {
        if (!frameLoaded || !reachable || !dialog.open) return;
        var frameStyle = getComputedStyle(frame);
        var frameBox = frame.getBoundingClientRect();
        if (frameStyle.display === 'none' || frameStyle.visibility === 'hidden'
            || frameBox.width < 100 || frameBox.height < 100) {
            showBlockerMessage(true);
            return;
        }
        loaded = true;
        clearWait();
        loading.hidden = true;
        if (!adBlocked) blocked.hidden = true;
    }
    function beginLoad() {
        clearWait();
        var thisAttempt = ++attempt;
        loaded = false;
        frameLoaded = false;
        reachable = false;
        blocked.hidden = true;
        loading.hidden = false;
        frame.src = frame.dataset.src;
        // A blocked iframe can still fire "load" with a browser error page.
        // A no-CORS request independently detects a client-blocked Google URL.
        fetch(frame.dataset.src, { mode: 'no-cors', credentials: 'omit', cache: 'no-store' })
            .then(function () {
                if (thisAttempt !== attempt) return;
                reachable = true;
                finishLoad();
            })
            .catch(function () {
                if (thisAttempt === attempt && dialog.open) showBlockerMessage(adBlocked);
            });
        requestAnimationFrame(function () {
            adBlocked = probeBlocked();
            if (adBlocked && dialog.open) showBlockerMessage(true);
        });
        timer = setTimeout(function () {
            if (dialog.open && !loaded) showBlockerMessage(adBlocked);
        }, 10000);
    }
    frame.addEventListener('load', function () {
        if (!dialog.open || !frame.src) return;
        try {
            // A blocked frame may finish loading its initial blank document.
            if (frame.contentDocument && frame.contentDocument.URL === 'about:blank') {
                showBlockerMessage(adBlocked);
                return;
            }
        } catch (ignore) { /* A real Google Form is cross-origin. */ }
        frameLoaded = true;
        finishLoad();
    });
    frame.addEventListener('error', function () {
        if (dialog.open) showBlockerMessage(adBlocked);
    });
    ribbon.addEventListener('click', function () {
        dialog.showModal();
        if (!frame.src || !loaded) beginLoad();
        else if (probeBlocked()) showBlockerMessage(true);
    });
    help.addEventListener('click', function () { showBlockerMessage(probeBlocked()); });
    retry.addEventListener('click', beginLoad);
    close.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', function () { clearWait(); ribbon.focus(); });
})();
</script>
