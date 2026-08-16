<?php
/**
 * Shared console shell - the dashboard look the student page established.
 *
 * Search row, tab strip, toolbar, the card list and its progress tracker. The
 * browse chrome underneath it (sidebar cards, filters, suggestions, quick
 * settings) comes from includes/browse_console.php, which this expects to have
 * been included already.
 *
 * Every role dashboard uses this, so a change here reaches all of them at once
 * and none of them can quietly drift from the others.
 *
 * Include inside <head>, after site_head.php and browse_console.php.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
body { background: var(--white); display: flex; flex-direction: column; min-height: 100vh; }
/* The breadcrumb strip lives in site_head.php now — every page needs it, not
   just the ones with a console layout. */
/* ===== Two-column layout, mirroring the public repository ===== */
.layout {
    display: grid;
    grid-template-columns: 1fr var(--sidebar-w, 226px);
    gap: var(--col-gap, 36px);
    align-items: start;
    padding-top: 1.5rem;
    padding-bottom: 3rem;
}
.main-col { position: relative; min-width: 0; }
/* Soft-cream panel carries the console; paper cards sit on it in white. */
.dash-shell { background: var(--cream); border-radius: 10px; padding: .875rem; }
/* ===== Search + upload row ===== */
.dash-top { display: flex; align-items: center; gap: .75rem; margin-bottom: .75rem; }
.dash-top .search-shell { flex: 1; min-width: 0; position: relative; }
.btn-upload {
    display: inline-flex; align-items: center; gap: .5rem;
    background: var(--maroon); color: #fff; text-decoration: none;
    border: none; border-radius: 8px;
    padding: .75rem 1.5rem; font-family: inherit; font-size: .875rem;
    white-space: nowrap; flex-shrink: 0;
    transition: background .2s;
}
.btn-upload:hover { background: var(--dark-maroon); color: #fff; }
.btn-upload .material-symbols-outlined { font-size: 20px; }
/* ===== Tabs + toolbar row ===== */
.dash-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; flex-wrap: wrap; margin-bottom: .75rem;
    /* Matches .browse-toolbar on the public repository */
    font-size: .6875rem; color: var(--ink);
}
.dash-tabs { display: flex; align-items: center; gap: .25rem; flex-wrap: wrap; }
.dash-tab {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .4rem .875rem; border-radius: 6px;
    font-size: .8125rem; color: var(--grey); text-decoration: none;
    border: none; background: none; font-family: inherit; cursor: pointer;
    transition: background .2s, color .2s;
}
/* The console sits on a cream panel, so the shared cream hover would be
   invisible here — hover to white instead for contrast. */
.dash-tab:hover { background: var(--white); color: var(--maroon); }
.dash-tab.active { background: var(--white); color: var(--maroon); }
.dash-shell .toolbar-btn:hover { background: var(--white); color: var(--maroon); }
/* Quick Settings now sits on the left of the bar, so the panel opens to the
   right instead of hanging off the left edge. */
.dash-tabs .quick-settings-dropdown { left: 0; right: auto; }
.dash-shell .card-tool:hover { background: var(--white); }
.dash-tab .count { font-size: .6875rem; color: var(--soft-maroon); }
/* ===== Paper cards ===== */
.paper-card {
    background: var(--white);
    border: 1px solid rgba(177,125,125,.22);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: .75rem;
}
.paper-card:last-child { margin-bottom: 0; }
/* The colour lives on the heading, not only on the link inside it: a draft's
   title is plain text, since the archive has nothing to link it to yet. */
.paper-card .paper-title { font-size: 1rem; font-weight: 500; line-height: 1.4; margin-bottom: .35rem; color: var(--ink); }
.paper-card .paper-title a { color: inherit; text-decoration: none; }
.paper-card .paper-title a:hover { color: var(--maroon); text-decoration: underline; }
.card-head { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 1rem; align-items: start; }
.card-status { text-align: right; font-size: .6875rem; line-height: 1.7; white-space: nowrap; }
.card-status .status-value { color: var(--maroon); }
.card-status .paper-action { display: block; }
/* ===== Progress tracker ===== */
.card-track { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 1.5rem; align-items: center; margin-top: 1rem; }
.track { display: flex; align-items: flex-start; position: relative; --steps: 3; --dot: 26px; }
.track-step { display: flex; flex-direction: column; align-items: center; gap: .35rem; flex: 1; z-index: 2; min-width: 0; }
.track-dot {
    width: 26px; height: 26px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--white); border: 2px solid var(--border);
    color: var(--border); flex-shrink: 0;
}
.track-dot .material-symbols-outlined { font-size: 18px; --mi-fill: 1; }
.track-step.done .track-dot { background: var(--maroon); border-color: var(--maroon); color: #fff; }
.track-step.current .track-dot { border-color: var(--maroon); color: var(--maroon); }
.track-label { font-size: .625rem; color: var(--grey); text-align: center; line-height: 1.25; }
.track-step.done .track-label, .track-step.current .track-label { color: var(--maroon); }
/* Connector runs edge-to-edge between the first and last dots — never past
   them. Each step is flex:1, so the outer dot centres sit at 50%/steps; add
   the dot's radius to land exactly on their inner edges. */
.track-line {
    position: absolute;
    top: calc(var(--dot) / 2 - 1px);
    left: calc(50% / var(--steps) + var(--dot) / 2);
    right: calc(50% / var(--steps) + var(--dot) / 2);
    height: 2px;
    background: var(--border);
    z-index: 1;
}
.track-line-fill { height: 100%; background: var(--maroon); transition: width .4s ease; }
/* ---- Card actions, small buttons and the confirmation dialog ----
   Shared vocabulary: every console uses the same two button sizes and the
   same dialog, so a destructive action looks the same wherever it is. */
.card-people { font-size: .6875rem; color: var(--ink); line-height: 1.8; text-align: left; white-space: nowrap; }
.card-people .who { color: var(--maroon); }
.card-feedback {
    margin-top: .875rem; padding: .625rem .875rem;
    background: #fdeaea; border-radius: 6px;
    font-size: .75rem; color: var(--dark-maroon); line-height: 1.6;
}
.card-actions { margin-top: .875rem; display: flex; gap: .5rem; flex-wrap: wrap; }
/* .btn-sm-maroon and .btn-sm-outline now live in site_head.php. */
/* Confirmation dialog, matching the one used on the upload page. */
.papel-dialog-backdrop {
    position: fixed; inset: 0; z-index: 20000;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem; background: rgba(51, 0, 0, .45);
    opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.papel-dialog-backdrop.open { opacity: 1; pointer-events: auto; }
.papel-dialog {
    width: 100%; max-width: 26rem; background: var(--white);
    border-radius: 12px; box-shadow: 0 18px 48px rgba(51, 0, 0, .28); overflow: hidden;
}
.papel-dialog-head {
    display: flex; align-items: center; gap: .5rem;
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--maroon);
}
.papel-dialog-head .material-symbols-outlined {
    color: var(--maroon); font-size: 20px; position: relative; top: -2px;
}
.papel-dialog-head h2 {
    font-family: var(--font-head); font-size: 1rem; font-weight: 500;
    color: var(--maroon); position: relative; top: 1px; margin: 0;
}
.papel-dialog-body { padding: 1.25rem; font-size: .875rem; color: var(--ink); line-height: 1.6; }
.papel-dialog-body strong { font-weight: 500; color: var(--maroon); }
.papel-dialog-foot { display: flex; justify-content: flex-end; gap: .5rem; padding: 0 1.25rem 1.25rem; }
.papel-dialog-foot .btn-sm-outline,
.papel-dialog-foot .btn-sm-maroon { padding: .5rem 1.1rem; font-size: .8125rem; }
@media (max-width: 900px) {
    .card-people { white-space: normal; }
}

@media (max-width: 900px) {
    .layout { grid-template-columns: 1fr; gap: 2rem; }
    .card-track { grid-template-columns: 1fr; gap: .75rem; }
}
@media (max-width: 600px) {
    .dash-top { flex-direction: column; align-items: stretch; }
    .btn-upload { justify-content: center; }
    .card-head { grid-template-columns: 1fr; }
    .card-status { text-align: left; }
}
</style>
