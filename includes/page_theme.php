<?php
/**
 * Shared "content page" theme — the look established on pages/settings.php:
 *   • maroon breadcrumb strip (no gradient hero banner)
 *   • page title on plain white
 *   • soft-cream panel carrying white cards on top (mirrors the archive sidebar)
 *   • Plus Jakarta Sans headings / Inter body, nothing bolded
 *
 * Include inside <head>, AFTER includes/site_head.php (which supplies the
 * design tokens) and BEFORE any page-specific <style> block.
 *
 * Markup shape:
 *   <div class="crumb-bar"><div class="wrap crumb-inner"> … </div></div>
 *   <div class="page-body">
 *     <div class="page-intro"><h1>…</h1><p>…</p></div>
 *     <div class="page-shell">
 *       <div class="page-card">
 *         <div class="page-card-header"><i class="bi bi-…"></i><h2>…</h2></div>
 *         <div class="page-card-body"> … </div>
 *       </div>
 *     </div>
 *   </div>
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
body {
    font-family: var(--font-body);
    background: var(--white);
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* ===== Breadcrumb strip — same maroon bar the archive pages use ===== */
.crumb-bar { background: var(--dark-maroon); }
.crumb-inner {
    display: flex;
    align-items: center;
    gap: .25rem;
    padding-top: .5rem;
    padding-bottom: .5rem;
    font-size: .75rem;
    color: rgba(255,255,255,.85);
}
.crumb-inner a { color: #fff; text-decoration: none; font-weight: 400; }
.crumb-inner a:hover { text-decoration: underline; }
.crumb-arrow {
    color: #fff;
    font-size: 20px;
    margin: 0 .125rem;
    --mi-fill: 1;
}
.crumb-current { color: #fff; font-weight: 400; }

/* ===== Page shell ===== */
.page-body { flex: 1; max-width: var(--wrap); margin: 0 auto; padding: 1.75rem 1.5rem 3rem; width: 100%; }
.page-intro { margin-bottom: 1.25rem; }
.page-intro h1 {
    font-family: var(--font-head);
    font-size: 1.6875rem;
    font-weight: 500;
    line-height: 1.2;
    color: var(--pup-maroon);
    margin-bottom: .25rem;
}
.page-intro p { font-size: .875rem; color: var(--grey); max-width: 70ch; }

/* Soft-cream panel carries the column; cards sit on top of it in white. */
.page-shell {
    background: var(--cream);
    border-radius: 10px;
    padding: .75rem;
}

.page-card {
    background: var(--white);
    border: 1px solid rgba(177,125,125,.22);
    border-radius: 8px;
    margin-bottom: .75rem;
    overflow: hidden;
}
.page-card:last-child { margin-bottom: 0; }

.page-card-header {
    display: flex;
    align-items: center;
    gap: .625rem;
    padding: .75rem 1.25rem;
    border-bottom: 1px solid var(--maroon);
}
/* Material Symbols glyphs sit low inside their line box; the base class
   corrects that with vertical-align:middle, but that property is ignored on
   a flex item, so the icon renders ~2px below the label's optical centre.
   position:relative lifts it without affecting layout height. */
.page-card-header .material-symbols-outlined {
    color: var(--maroon);
    font-size: 20px;
    flex-shrink: 0;
    position: relative;
    top: -2px;
}
/* Bootstrap-icons variant used by the public content pages */
.page-card-header i[class^="bi"],
.page-card-header i[class*=" bi-"] {
    color: var(--maroon);
    font-size: 18px;
    flex-shrink: 0;
    line-height: 1;
}
.page-card-header h2 {
    font-family: var(--font-head);
    font-size: 1rem;
    font-weight: 500;
    color: var(--maroon);
    /* Final 1px optical nudge to settle the label against the icon. */
    position: relative;
    top: 1px;
}
.page-card-header .hint { margin-left: auto; font-size: .75rem; color: var(--grey); font-weight: 400; }

.page-card-body { padding: 1.25rem; }

/* ===== Body copy inside cards — nothing bolded ===== */
.page-card-body p { color: var(--ink); line-height: 1.75; font-size: .875rem; margin-bottom: .875rem; }
.page-card-body p:last-child { margin-bottom: 0; }
.page-card-body strong { font-weight: 400; color: var(--maroon); }
.page-card-body h3 {
    font-family: var(--font-head);
    font-size: .9375rem;
    font-weight: 500;
    color: var(--maroon);
    margin-top: 1.5rem;
    margin-bottom: .5rem;
}
.page-card-body h3:first-child { margin-top: 0; }
.page-card-body ul, .page-card-body ol { margin: 0 0 .875rem 1.25rem; }
.page-card-body li { font-size: .875rem; color: var(--ink); line-height: 1.7; margin-bottom: .35rem; }
.page-card-body a { color: var(--maroon); }

/* ===== Shared table styling ===== */
.page-table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
.page-table th {
    background: var(--cream);
    padding: .625rem .875rem;
    text-align: left;
    font-size: .75rem;
    font-weight: 400;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--maroon);
    border-bottom: 1px solid var(--maroon);
}
.page-table td {
    padding: .625rem .875rem;
    border-bottom: 1px solid var(--border);
    color: var(--ink);
    font-size: .8125rem;
    vertical-align: top;
}
.page-table tr:last-child td { border-bottom: none; }
.page-chip {
    display: inline-block;
    padding: .15rem .6rem;
    border-radius: 999px;
    background: rgba(130,7,7,.08);
    color: var(--maroon);
    font-size: .75rem;
    font-weight: 400;
    white-space: nowrap;
}

/* ===== Shared form controls ===== */
.page-field { margin-bottom: 1rem; }
.page-field label { display: block; font-size: .8125rem; font-weight: 400; color: var(--ink); margin-bottom: .35rem; }
.page-field input, .page-field select, .page-field textarea {
    width: 100%;
    padding: .625rem .75rem;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-family: inherit;
    font-size: .875rem;
    color: var(--ink);
    background: var(--cream);
    transition: border-color .2s, box-shadow .2s;
}
.page-field textarea { min-height: 130px; resize: vertical; }
.page-field input:focus, .page-field select:focus, .page-field textarea:focus {
    outline: none;
    border-color: var(--maroon);
    box-shadow: 0 0 0 3px rgba(130,7,7,.10);
    background: var(--white);
}
.btn-page {
    padding: .625rem 1.5rem;
    background: var(--maroon);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-size: .875rem;
    font-weight: 400;
    cursor: pointer;
    transition: background .2s;
}
.btn-page:hover { background: var(--dark-maroon); }

/* ===== Alerts ===== */
.alert {
    padding: .75rem 1rem;
    border-radius: 8px;
    font-size: .875rem;
    margin-bottom: 1.25rem;
}
.alert.error { background: #fdeaea; color: var(--dark-maroon); }
.alert.success { background: #e7f6ed; color: #1b5e35; }

.page-note { font-size: .75rem; color: var(--grey); line-height: 1.6; }
.page-note a { color: var(--maroon); }

@media (max-width: 700px) {
    .page-intro h1 { font-size: 1.375rem; }
    .page-card-body { padding: 1rem; }
}
</style>
