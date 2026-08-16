<?php
/**
 * Jump to the other end of a long page.
 *
 * One button that always points where you are not: near the top it offers the
 * bottom, near the bottom it offers the top, and in between it offers the top,
 * because that is what someone halfway down a record usually wants. It stays
 * out of the way until the page is actually long enough to need it.
 *
 * Parked above the accessibility widget, which sits in the same corner, so the
 * two never overlap.
 *
 * Include once, before the footer.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.scroll-jump {
    position: fixed;
    right: 24px;
    bottom: 88px;                   /* clears the accessibility button below it */
    z-index: 880;                   /* under the PDF panel, over the page */
    width: 2.75rem;
    height: 2.75rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--soft-maroon);
    border-radius: 50%;
    background: var(--white);
    color: var(--maroon);
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(51, 0, 0, .16);
    opacity: 0;
    visibility: hidden;
    transform: translateY(6px);
    transition: opacity .18s ease, transform .18s ease, visibility .18s, background .15s;
}
.scroll-jump.is-visible { opacity: 1; visibility: visible; transform: none; }
.scroll-jump:hover { background: var(--maroon); color: #fff; }
.scroll-jump:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.scroll-jump .material-symbols-outlined { font-size: 22px; transition: transform .18s ease; }

@media (max-width: 600px) {
    .scroll-jump { right: 16px; bottom: 80px; width: 2.5rem; height: 2.5rem; }
}
</style>

<button type="button" class="scroll-jump" id="scrollJump" title="Back to top" aria-label="Back to top">
    <span class="material-symbols-outlined" id="scrollJumpIcon">arrow_upward</span>
</button>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var btn  = document.getElementById('scrollJump');
    var icon = document.getElementById('scrollJumpIcon');
    if (!btn) return;

    var goingUp = true;      // what the button will do when pressed

    function update() {
        var doc     = document.documentElement;
        var top     = window.pageYOffset || doc.scrollTop;
        var height  = doc.scrollHeight - window.innerHeight;

        // A page that barely scrolls does not need the control at all.
        if (height < 400) { btn.classList.remove('is-visible'); return; }

        // Near the top the only useful jump is down; anywhere else it is up.
        goingUp = top >= 120;

        icon.textContent = goingUp ? 'arrow_upward' : 'arrow_downward';
        btn.title = goingUp ? 'Back to top' : 'Skip to the end';
        btn.setAttribute('aria-label', btn.title);

        // Shown the whole way down a long page — it is the direction that
        // changes, not whether there is anywhere to go.
        btn.classList.add('is-visible');
    }

    btn.addEventListener('click', function () {
        var doc = document.documentElement;
        window.scrollTo({
            top: goingUp ? 0 : doc.scrollHeight,
            behavior: 'smooth'
        });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
});
</script>
