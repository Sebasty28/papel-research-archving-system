<?php
/**
 * The staff management pages, in PAPEL's own clothes.
 *
 * Manage Students, Manage Faculty and Manage Admins were written against
 * Bootstrap's defaults — blue buttons, its greys, its rounded corners — so they
 * read as a different product from the rest of the site. All three use the same
 * handful of Bootstrap classes, so re-skinning those classes brings the three
 * into line at once, without rewriting two thousand lines of markup that is
 * otherwise working.
 *
 * The page's own structure, its forms and its JavaScript are untouched. Only
 * how it looks changes.
 *
 * Include inside <head>, after site_head.php.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ---- Type ----
   Bootstrap sets its own system font stack on <body>, which these pages then
   inherited — so they were the only screens in PAPEL not set in Inter and Plus
   Jakarta Sans. Headings take the display face, everything else the body face,
   exactly as the rest of the site does. */
body,
.card, .card-body, .table, .btn, .form-control, .form-select, .form-label,
.modal, .modal-body, .alert, .badge, .nav-link, .input-group-text,
.dropdown-menu, .list-group-item {
    font-family: var(--font-body), 'Inter', -apple-system, sans-serif;
}
h1, h2, h3, h4, h5, h6,
.modal-title, .card-header, .page-title {
    font-family: var(--font-head), 'Plus Jakarta Sans', sans-serif;
    letter-spacing: -.01em;
}

/* The breadcrumb strip and the two small buttons now live in site_head.php,
   which every page includes. */

/* ---- Page frame ---- */
.mc-page { max-width: 68rem; margin: 0 auto; padding: 1.75rem 0 3rem; }
.mc-head { display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.mc-head h1 {
    font-family: var(--font-head); font-size: 1.375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .2rem;
}
.mc-head p { font-size: .8125rem; color: var(--grey); margin: 0; }
.mc-head .mc-actions { margin-left: auto; display: flex; gap: .5rem; flex-wrap: wrap; }

/* ---- Cards ---- */
.card, .content-card {
    background: var(--white);
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    box-shadow: none !important;
    margin-bottom: 1.125rem;
}
.card-body { padding: 1.375rem 1.5rem; }
.card-header {
    background: var(--white) !important;
    border-bottom: 1px solid var(--border) !important;
    font-family: var(--font-head);
    font-weight: 600;
    color: var(--maroon);
}

/* ---- Type ---- */
.mc-page h2, .mc-page h3, .mc-page h4, .mc-page h5 {
    font-family: var(--font-head);
    color: var(--maroon);
}
.text-muted { color: var(--grey) !important; }
.text-danger { color: var(--maroon) !important; }

/* ---- Tables ---- */
.table {
    --bs-table-bg: transparent;
    font-size: .8125rem;
    color: var(--ink);
    margin-bottom: 0;
}
.table > :not(caption) > * > * { padding: .7rem .75rem; }
.table thead th {
    font-size: .6875rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--grey);
    font-weight: 600;
    background: var(--cream);
    border-bottom: 1px solid var(--border) !important;
    white-space: nowrap;
}
.table tbody td { border-bottom: 1px solid var(--border) !important; vertical-align: middle; }
.table-hover tbody tr:hover > * { background: var(--cream) !important; }
.table-responsive { border-radius: 10px; overflow: auto; }

/* ---- Buttons ----
   Bootstrap's palette is replaced wholesale: one accent for the action being
   encouraged, an outline for everything else, so a page of controls does not
   read as five competing priorities. */
.btn {
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: .8125rem;
    padding: .45rem 1rem;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    transition: background .15s, color .15s, border-color .15s;
}
.btn-sm { padding: .3rem .7rem; font-size: .75rem; }
.btn-primary, .btn-success, .btn-info, .btn-dark {
    background: var(--maroon) !important;
    border-color: var(--maroon) !important;
    color: #fff !important;
}
.btn-primary:hover, .btn-success:hover, .btn-info:hover, .btn-dark:hover {
    background: var(--dark-maroon) !important;
    border-color: var(--dark-maroon) !important;
}
.btn-secondary, .btn-outline-secondary, .btn-outline-primary, .btn-light, .btn-warning {
    background: var(--white) !important;
    border-color: var(--border) !important;
    color: var(--maroon) !important;
}
.btn-secondary:hover, .btn-outline-secondary:hover, .btn-outline-primary:hover,
.btn-light:hover, .btn-warning:hover {
    background: var(--cream) !important;
    border-color: var(--soft-maroon) !important;
}
/* Deleting stays visually distinct — it is the one action that cannot be undone. */
.btn-danger, .btn-outline-danger {
    background: var(--white) !important;
    border-color: var(--soft-maroon) !important;
    color: var(--dark-maroon) !important;
}
.btn-danger:hover, .btn-outline-danger:hover {
    background: var(--dark-maroon) !important;
    border-color: var(--dark-maroon) !important;
    color: #fff !important;
}
.btn:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; box-shadow: none; }

/* ---- Forms ---- */
.form-label {
    font-size: .75rem;
    font-weight: 500;
    color: var(--ink);
    margin-bottom: .3rem;
}
.form-control, .form-select {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .5rem .7rem;
    font-family: var(--font-body);
    font-size: .8125rem;
    color: var(--ink);
    background-color: var(--white);
}
.form-control:focus, .form-select:focus {
    border-color: var(--maroon);
    box-shadow: none;
    outline: none;
}
.form-control::placeholder { color: var(--grey); }
.input-group-text { background: var(--cream); border-color: var(--border); color: var(--grey); font-size: .8125rem; }

/* ---- Tabs and filter pills ---- */
.nav-tabs { border-bottom: 1px solid var(--border); gap: .25rem; }
.nav-tabs .nav-link {
    border: none;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    color: var(--grey);
    font-size: .8125rem;
    padding: .5rem .9rem;
}
.nav-tabs .nav-link:hover { color: var(--maroon); border-bottom-color: var(--soft-maroon); }
.nav-tabs .nav-link.active {
    color: var(--maroon);
    background: none;
    border-bottom-color: var(--maroon);
    font-weight: 500;
}
.filter-btn {
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: var(--white) !important;
    color: var(--ink) !important;
    font-size: .75rem;
    padding: .3rem .8rem;
}
.filter-btn:hover { border-color: var(--soft-maroon) !important; color: var(--maroon) !important; }
.filter-btn.active {
    background: var(--cream) !important;
    border-color: var(--maroon) !important;
    color: var(--maroon) !important;
    font-weight: 500;
}

/* ---- Alerts ---- */
.alert {
    border-radius: 10px;
    border: 1px solid var(--border);
    font-size: .8125rem;
    padding: .75rem 1rem;
}
.alert-success { background: #e7f6ed; border-color: #bfe3cd; color: #1b5e35; }
.alert-danger, .alert-warning { background: #fdeaea; border-color: var(--soft-maroon); color: var(--dark-maroon); }
.alert-info { background: var(--cream); border-color: var(--border); color: var(--ink); }

/* ---- Modals ---- */
.modal-content {
    border: none;
    border-radius: 12px;
    box-shadow: 0 18px 48px rgba(51, 0, 0, .28);
}
.modal-header {
    border-bottom: 1px solid var(--maroon);
    padding: 1rem 1.25rem;
}
.modal-title {
    font-family: var(--font-head);
    font-size: 1rem;
    font-weight: 500;
    color: var(--maroon);
}
.modal-body { padding: 1.25rem; font-size: .8125rem; color: var(--ink); }
.modal-footer { border-top: none; padding: 0 1.25rem 1.25rem; }

/* ---- Badges ---- */
.badge {
    font-family: var(--font-body);
    font-weight: 500;
    font-size: .6875rem;
    padding: .3rem .55rem;
    border-radius: 999px;
}
.bg-primary, .bg-info, .bg-success { background: var(--cream) !important; color: var(--maroon) !important; }
.bg-secondary { background: var(--cream) !important; color: var(--grey) !important; }
.bg-danger, .bg-warning { background: #fdeaea !important; color: var(--dark-maroon) !important; }

/* ---- Empty state ---- */
.mc-empty { padding: 3rem 1rem; text-align: center; color: var(--grey); font-size: .8125rem; }
.mc-empty .material-symbols-outlined { font-size: 34px; display: block; margin: 0 auto .5rem; opacity: .5; }

@media (max-width: 700px) {
    .mc-head .mc-actions { margin-left: 0; width: 100%; }
    .card-body { padding: 1.125rem; }
}
</style>
