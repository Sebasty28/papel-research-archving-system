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
.survey-dialog iframe { display: block; width: 100%; min-height: 0; flex: 1; border: 0; background: #fff; }
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
        <a class="survey-dialog-link" href="<?= e($surveyFormUrl) ?>" target="_blank" rel="noopener noreferrer">Open in Google Forms</a>
        <button class="survey-dialog-close" id="surveyDialogClose" type="button" aria-label="Close survey">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
    </div>
    <iframe id="surveyFrame" title="PAPEL survey on Google Forms" data-src="<?= e($surveyEmbedUrl) ?>" referrerpolicy="strict-origin-when-cross-origin"></iframe>
</dialog>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var ribbon = document.getElementById('surveyRibbon');
    var dialog = document.getElementById('surveyDialog');
    var frame = document.getElementById('surveyFrame');
    var close = document.getElementById('surveyDialogClose');
    if (!ribbon || !dialog || !frame || !close) return;

    // The paper preview and other embedded PAPEL pages must not repeat the invite.
    if (window.self !== window.top) {
        ribbon.remove();
        dialog.remove();
        return;
    }

    ribbon.addEventListener('click', function () {
        if (!frame.src) frame.src = frame.dataset.src;
        dialog.showModal();
    });
    close.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', function () { ribbon.focus(); });
})();
</script>
