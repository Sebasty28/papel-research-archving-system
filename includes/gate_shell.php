<?php
/** Shared shell for the password gate and the dual-position desk chooser.
 * Include in <head> after site_head.php and page_theme.php. */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.gate-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem;
    width: 100%;
    padding: .55rem 1.25rem;
    background: var(--white);
    border-bottom: 1px solid var(--border);
}
.gate-head { display: inline-flex; align-items: center; gap: .5rem; }
.gate-name {
    font-family: var(--font-head), serif;
    font-size: 1.0625rem; font-weight: 600; letter-spacing: .01em;
    color: var(--maroon);
}
.gate-bar .btn-sm-outline { text-decoration: none; }
.gate-bar .btn-sm-outline .material-symbols-outlined { font-size: 17px; vertical-align: -3px; }
.gate-body { width: 100%; max-width: 34rem; margin: 2.5rem auto; padding: 0 1rem; }
.gate-lede { margin: 0 0 1rem; font-size: .875rem; line-height: 1.6; color: var(--ink); }
@media (max-width: 600px) {
    .gate-bar { padding: .75rem 1rem; }
    .gate-body { margin: 1rem auto; padding: 0 .75rem; }
    .gate-body .page-card-header { padding: .875rem 1rem; }
    .gate-body .page-card-body { padding: 1rem; }
}
</style>
