<?php
/**
 * Shared browse-console styles: the search field, results toolbar, Quick
 * Settings dropdown, paper list, loading state, and the right-hand
 * Browse/Filter sidebar cards.
 *
 * Used by archive/index.php (public repository) and the role dashboards so
 * the browse experience stays identical everywhere. Include inside <head>
 * AFTER includes/site_head.php (which supplies the design tokens).
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ===== Search field ===== */
.search-form {
    display: flex;
    align-items: center;
    gap: .875rem;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: .75rem 1rem;
    box-shadow: var(--shadow-md);
    transition: border-color .2s, box-shadow .2s;
}
.search-form:hover { border-color: var(--soft-maroon); }
.search-form:focus-within {
    border-color: var(--soft-maroon);
    box-shadow: 0 0 0 3px rgba(177,125,125,.20);
}
/* Magnifier picks up maroon whenever the field is engaged */
.search-form:hover .btn-search-icon,
.search-form:focus-within .btn-search-icon { color: var(--maroon); }
/* The magnifier is the submit control — Figma has no separate button */
.btn-search-icon {
    display: flex;
    align-items: center;
    background: none;
    border: none;
    padding: 0 .875rem 0 .125rem;
    border-right: 1px solid var(--border);
    color: var(--grey);
    cursor: pointer;
    flex-shrink: 0;
    transition: color .2s;
}
.btn-search-icon:hover { color: var(--maroon); }
.search-input {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-family: inherit;
    font-size: .875rem;
    color: var(--ink);
}
.search-input::placeholder { color: var(--grey); }

/* ===== Paper list ===== */
/* Row hover bleeds slightly past the column, so the cream block reads as a
   card while the text stays aligned with the heading rule above it. */
.paper-item {
    position: relative;
    padding: .875rem 1.25rem;
    margin: 0 -.75rem;
    border-radius: 6px;
    transition: background .2s ease;
}
.paper-item:hover { background: var(--cream); }
/* Separator drawn inside the padding so it lines up with the column edges */
.paper-item::after {
    content: '';
    position: absolute;
    left: 1.25rem;
    right: 1.25rem;
    bottom: 0;
    border-bottom: 1px solid var(--border-soft);
}
.paper-item:last-child::after { display: none; }
.paper-title {
    font-family: var(--font-head);
    font-size: .9375rem;
    font-weight: 600;
    line-height: 1.45;
    margin-bottom: .3rem;
}
.paper-title a, .paper-title span { color: var(--ink); text-decoration: none; }
.paper-title a:hover { color: var(--maroon); text-decoration: underline; }

/* Two columns under the title: authors/meta at left, actions at right.
   The right column starts ~62% across so "Login to view details" sits
   level with the authors line and the program level with the meta line. */
.paper-foot {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 38%;
    gap: 1rem;
    align-items: start;
}
.paper-info { min-width: 0; }
.paper-authors {
    font-size: .6875rem;
    color: var(--ink);
    margin-bottom: .15rem;
}
.paper-meta {
    font-size: .6875rem;
    color: var(--ink);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .4rem;
}
.paper-meta .sep { color: var(--soft-maroon); }
.paper-side {
    text-align: left;
    font-size: .6875rem;
    line-height: 1.6;
    min-width: 0;
}
.paper-action {
    color: var(--maroon);
    font-weight: 500;
    text-decoration: none;
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
    font-size: inherit;
    cursor: pointer;
    transition: color .2s;
}
.paper-action:hover { color: var(--dark-maroon); text-decoration: underline; }
.paper-program { display: block; color: var(--maroon); }

/* ===== Member browse console (signed-in only) =====
   Toolbar directly under the search bar + scrollable result well. */
.browse-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    padding: .5rem 0 .625rem;
    font-size: .6875rem;
    color: var(--ink);
}
.toolbar-left, .toolbar-right { display: flex; align-items: center; gap: .25rem; }
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border: none;
    border-radius: 5px;
    background: none;
    color: var(--maroon);
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
    transition: background .2s, color .2s;
}
.toolbar-btn:hover { background: var(--cream); color: var(--dark-maroon); }
.toolbar-btn.disabled { color: var(--border); pointer-events: none; }
.toolbar-btn .material-symbols-outlined { font-size: 18px; }

/* Quick Settings dropdown (Density / Theme) */
.quick-settings { position: relative; }
.quick-settings-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-md);
    width: 220px;
    z-index: 1000;
    display: none;
    overflow: hidden;
}
.quick-settings-dropdown.open { display: block; }
.qs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .625rem .875rem;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: .8125rem;
    color: var(--ink);
}
.qs-close {
    background: none;
    border: none;
    color: var(--grey);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 4px;
    padding: 0;
}
.qs-close:hover { background: var(--cream); color: var(--maroon); }
.qs-section { padding: .625rem .875rem; border-bottom: 1px solid var(--border); }
.qs-section:last-child { border-bottom: none; }
.qs-link {
    display: block;
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
    font-size: .8125rem;
    color: var(--maroon);
    cursor: pointer;
    text-align: left;
    text-decoration: none;
    width: 100%;
}
.qs-link:hover { color: var(--dark-maroon); text-decoration: underline; }
.qs-section-label {
    display: block;
    font-size: .6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--maroon);
    margin-bottom: .4rem;
}
.qs-radio {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .8125rem;
    color: var(--ink);
    padding: .3rem 0;
    cursor: pointer;
}
.qs-radio input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #E2DCDC;
    margin: 0;
    flex-shrink: 0;
    cursor: pointer;
}
.qs-radio input[type="radio"]:checked { background: var(--pup-maroon); }

/* Density — applied to a stable ancestor so it survives AJAX result swaps */
/* Applies to both result shapes: .paper-item (public repository rows) and
   .paper-card (dashboard cards). Default deliberately sets explicit values
   too, so switching back from another density actually restores it. */
[data-density="default"] .paper-item { padding: .875rem 1.25rem; }
[data-density="default"] .paper-card { padding: 1rem 1.25rem; }
[data-density="default"] .paper-item .paper-title { font-size: .9375rem; }
[data-density="default"] .paper-card .paper-title { font-size: 1rem; }

[data-density="compact"] .paper-item { padding: .5rem 1.25rem; }
[data-density="compact"] .paper-card { padding: .625rem .875rem; margin-bottom: .5rem; }
[data-density="compact"] .paper-item .paper-title { font-size: .8125rem; }
[data-density="compact"] .paper-card .paper-title { font-size: .875rem; margin-bottom: .2rem; }
[data-density="compact"] .paper-authors,
[data-density="compact"] .paper-meta,
[data-density="compact"] .paper-side { font-size: .625rem; }
[data-density="compact"] .card-track { margin-top: .625rem; }

[data-density="comfortable"] .paper-item { padding: 1.25rem 1.25rem; }
[data-density="comfortable"] .paper-card { padding: 1.5rem 1.5rem; margin-bottom: 1rem; }
[data-density="comfortable"] .paper-item .paper-title { font-size: 1rem; margin-bottom: .45rem; }
[data-density="comfortable"] .paper-card .paper-title { font-size: 1.0625rem; margin-bottom: .5rem; }
[data-density="comfortable"] .paper-authors,
[data-density="comfortable"] .paper-meta { font-size: .75rem; }
[data-density="comfortable"] .card-track { margin-top: 1.5rem; }

/* Result well — fixed height with its own scrollbar in both directions */
.paper-list.is-scrollable {
    height: 620px;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: .5rem;
    scrollbar-width: thin;
    scrollbar-color: var(--dark-maroon) var(--cream);
}
.paper-list.is-scrollable::-webkit-scrollbar { width: 8px; }
.paper-list.is-scrollable::-webkit-scrollbar-track { background: var(--cream); border-radius: 4px; }
.paper-list.is-scrollable::-webkit-scrollbar-thumb { background: var(--dark-maroon); border-radius: 4px; }

/* Loading state while search/filter/pagination fetch results via AJAX */
.main-col { position: relative; }
.main-col.is-loading::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    z-index: 20;
    background: linear-gradient(90deg, transparent, var(--soft-maroon), transparent);
    background-size: 50% 100%;
    animation: papel-loading-bar 1.1s ease-in-out infinite;
}
.main-col.is-loading .browse-toolbar,
.main-col.is-loading .paper-list {
    opacity: .5;
    transition: opacity .2s ease .1s;
}
.main-col .browse-toolbar,
.main-col .paper-list { transition: opacity .15s ease; }
@keyframes papel-loading-bar {
    0%   { background-position: -50% 0; }
    100% { background-position: 150% 0; }
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--grey);
}
.empty-state .material-symbols-outlined { font-size: 44px; display: block; margin: 0 auto .75rem; }
.empty-state a { color: var(--maroon); }

/* ===== 7. Sidebar =====
   A soft-cream panel carries the column; the cards sit on top of it in white. */
.sidebar-right {
    background: var(--cream);
    border-radius: 10px;
    padding: .75rem;
}
.sidebar-card {
    background: var(--white);
    border: 1px solid rgba(177,125,125,.22);
    border-radius: 8px;
    margin-bottom: .75rem;
    overflow: hidden;
}
.sidebar-card:last-child { margin-bottom: 0; }
.sidebar-card-header {
    background: var(--white);
    color: var(--maroon);
    padding: .5rem .75rem;
    font-family: var(--font-head);
    font-size: .75rem;
    font-weight: 500;
    border-bottom: 1px solid var(--maroon);
}
.sidebar-card-body { padding: .125rem 0; }
/* Collapsible card headers (member view) */
.sidebar-card-header.is-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    padding: 0 .75rem 0 0;
}
.card-title-btn {
    flex: 1;
    background: none;
    border: none;
    padding: .5rem .75rem;
    text-align: left;
    font-family: var(--font-head);
    font-size: .75rem;
    font-weight: 500;
    color: var(--maroon);
    cursor: pointer;
}
.card-header-tools { display: flex; align-items: center; gap: .125rem; }
.card-tool {
    display: inline-flex;
    align-items: center;
    background: none;
    border: none;
    padding: 0;
    color: var(--maroon);
    text-decoration: none;
    border-radius: 3px;
    cursor: pointer;
    transition: color .2s;
}
.card-tool:hover { color: var(--dark-maroon); }
.card-tool .material-symbols-outlined { font-size: 16px; }
.card-chevron { transition: transform .25s ease; }
.sidebar-card.collapsed .card-chevron { transform: rotate(-90deg); }
.sidebar-card.collapsed .sidebar-card-body,
.sidebar-card.collapsed form { display: none; }
.sidebar-link {
    display: block;
    width: 100%;
    text-align: left;
    padding: .5rem .75rem;
    font-size: .75rem;
    color: var(--ink);
    text-decoration: none;
    background: none;
    border: none;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.sidebar-link:hover { background: var(--cream); color: var(--maroon); }
.sidebar-link.active { color: var(--maroon); font-weight: 700; }

/* Filter groups flow continuously — no rules between them */
.filter-section { padding: .5rem .75rem .25rem; }
.filter-section:last-child { padding-bottom: .875rem; }
.filter-section-label {
    display: block;
    font-size: .75rem;
    font-weight: 500;
    color: var(--maroon);
    margin-bottom: .4rem;
}
.filter-radio {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .35rem;
    padding-left: .5rem;
    font-size: .75rem;
    color: var(--ink);
    cursor: pointer;
}
/* Grey dot when idle, solid PUP maroon when chosen */
.filter-radio input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    border: none;
    border-radius: 50%;
    background: #E2DCDC;
    flex-shrink: 0;
    cursor: pointer;
    margin: 0;
    transition: background .15s;
}
.filter-radio input[type="radio"]:checked { background: var(--pup-maroon); }
.filter-radio input[type="radio"]:focus-visible {
    outline: 2px solid var(--maroon);
    outline-offset: 2px;
}
.filter-select {
    width: auto;
    min-width: 118px;
    max-width: calc(100% - 1rem);
    margin-left: .5rem;
    margin-right: .5rem;
    padding: .3rem 1.75rem .3rem .45rem;
    border: 1px solid var(--soft-maroon);
    border-radius: 6px;
    font-family: inherit;
    font-size: .75rem;
    color: var(--ink);
    background: var(--white);
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
}
.filter-select:focus { outline: none; border-color: var(--maroon); }
/* Date group: year / month / day stacked, indented past the radio column */
.date-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .4rem;
    padding-left: .75rem;
}
.date-stack .filter-select { min-width: 112px; text-align: center; }

.note-card {
    border: 1px solid rgba(177,125,125,.22);
    border-radius: 8px;
    padding: .5rem .75rem;
    font-size: .75rem;
    color: var(--maroon);
    background: var(--white);
    line-height: 1.6;
}
.note-card strong { color: var(--pup-maroon); display: block; font-weight: 600; }
/* Search suggestions */
.suggestions-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0; right: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 6px;
    box-shadow: var(--shadow-md);
    z-index: 800;
    max-height: 280px;
    overflow-y: auto;
    display: none;
}
.suggestion-item {
    padding: .625rem 1rem;
    cursor: pointer;
    font-size: .875rem;
    color: var(--ink);
    border-bottom: 1px solid var(--border-soft);
}
.suggestion-item:last-child { border-bottom: none; }
.suggestion-item:hover, .suggestion-item.active { background: var(--cream); color: var(--maroon); }
</style>
