<?php
/**
 * Shared <head> fragment: fonts, Bootstrap, and the design tokens + CSS
 * for the universal site header, footer, and login modal.
 * Include this near the top of <head>, before any page-specific <style>
 * block, so page-specific rules can still override where needed.
 */
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* =========================================================
   PAPEL — universal design tokens + site header/footer/modal
   ========================================================= */
:root {
    --ink:          #330000;
    --pup-maroon:   #800000;
    --dark-maroon:  #630000;
    --soft-maroon:  #B17D7D;
    --maroon:       #820707;
    --white:        #FFFFFF;
    --cream:        #FFF5F5;
    --grey:         #9F9F9F;

    --border:       #E6D4D4;
    --border-soft:  rgba(177,125,125,.35);
    --shadow-sm:    0 1px 3px rgba(51,0,0,.07);
    --shadow-md:    0 6px 18px rgba(51,0,0,.10);

    --font-head:    'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;

    --wrap:         1168px;
    --wrap-wide:    1328px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: var(--font-body);
    background: var(--white);
    color: var(--ink);
    font-size: .875rem;
    line-height: 1.55;
    -webkit-font-smoothing: antialiased;
    /* A short page used to end wherever its content did, leaving the footer
       stranded in the middle of the window with blank space beneath it. The
       page now always fills the window and the footer is pushed to the bottom
       of it, however little there is to show. */
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
body > main { flex: 1 0 auto; }
.site-footer { margin-top: auto; flex-shrink: 0; }

h1, h2, h3, .font-head { font-family: var(--font-head); }

.wrap { max-width: var(--wrap); margin: 0 auto; padding: 0 1.5rem; }
.wrap-wide { max-width: var(--wrap-wide); margin: 0 auto; padding: 0 1.5rem; }

/* ===== Breadcrumb strip =====
   Used on almost every signed-in page. It lived in console_shell.php and again
   in manage_console.php, so a page that included neither — analytics, say —
   rendered its crumbs as bare blue links. It belongs here with .wrap. */
.crumb-bar { background: var(--dark-maroon); }
.crumb-inner {
    display: flex; align-items: center; gap: .25rem;
    padding-top: .5rem; padding-bottom: .5rem;
    font-size: .75rem; color: rgba(255,255,255,.85);
}
.crumb-inner a { color: #fff; text-decoration: none; }
.crumb-inner a:hover { text-decoration: underline; }
.crumb-arrow { color: #fff; font-size: 20px; margin: 0 .125rem; --mi-fill: 1; }
.crumb-current { color: #fff; }

/* ===== The two small buttons the whole site uses ===== */
.btn-sm-maroon {
    background: var(--maroon); color: #fff; border: none; border-radius: 6px;
    padding: .4rem 1rem; font-family: var(--font-body); font-size: .75rem; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
}
.btn-sm-maroon:hover { background: var(--dark-maroon); color: #fff; }
.btn-sm-outline {
    background: none; color: var(--maroon); border: 1px solid var(--soft-maroon);
    border-radius: 6px; padding: .4rem 1rem; font-family: var(--font-body); font-size: .75rem;
    cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
}
.btn-sm-outline:hover { background: var(--cream); }

/* Google Material Icons — inline sizing */
.material-symbols-outlined {
    font-family: 'Material Symbols Outlined';
    font-weight: normal;
    font-style: normal;
    font-size: 24px;
    line-height: 1;
    letter-spacing: normal;
    text-transform: none;
    display: inline-block;
    white-space: nowrap;
    direction: ltr;
    vertical-align: middle;
    font-feature-settings: 'liga';
    -webkit-font-feature-settings: 'liga';
    -webkit-font-smoothing: antialiased;
    font-variation-settings:
        'FILL' var(--mi-fill, 0),
        'wght' var(--mi-wght, 400),
        'GRAD' var(--mi-grad, 0),
        'opsz' var(--mi-opsz, 24);
}
.mi-18 { font-size: 18px; }
.mi-20 { font-size: 20px; }
.mi-fill { --mi-fill: 1; }

/* ===== Site header ===== */
.site-header {
    background: var(--white);
    border-bottom: 1px solid var(--border-soft);
    position: sticky;
    top: 0;
    z-index: 900;
    transition: background .3s ease, box-shadow .3s ease, border-color .3s ease;
}
.site-header.scrolled {
    background: rgba(255,255,255,.72);
    -webkit-backdrop-filter: blur(14px) saturate(180%);
    backdrop-filter: blur(14px) saturate(180%);
    border-bottom-color: rgba(177,125,125,.28);
    box-shadow: 0 2px 16px rgba(51,0,0,.08);
}
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
    .site-header.scrolled { background: rgba(255,255,255,.97); }
}

.nav-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 50%;
    background: none;
    color: var(--ink);
    cursor: pointer;
    text-decoration: none;
    transition: background .2s, color .2s;
    position: relative;
}
.nav-icon-btn:hover { background: var(--cream); color: var(--maroon); }
.notif-badge {
    position: absolute;
    top: 2px; right: 2px;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 999px;
    background: var(--maroon);
    color: #fff;
    font-size: .5625rem;
    font-weight: 700;
    line-height: 15px;
    text-align: center;
}
.avatar-group {
    display: inline-flex;
    align-items: center;
    gap: .125rem;
    background: none;
    border: none;
    cursor: pointer;
    padding: .125rem;
    color: var(--maroon);
    font-family: inherit;
}
.avatar-group .material-symbols-outlined { font-size: 18px; }
.header-inner {
    display: flex;
    align-items: center;
    gap: 2rem;
    height: 60px;
}
.brand {
    display: inline-block;
    text-decoration: none;
    flex-shrink: 0;
    font-family: var(--font-head);
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: .01em;
    color: var(--pup-maroon);
    transition: color .2s;
}
.brand:hover { color: var(--maroon); }
.main-nav {
    display: flex;
    align-items: center;
    gap: 2.75rem;
    flex: 1;
    justify-content: center;
}
.main-nav a {
    color: var(--maroon);
    text-decoration: none;
    font-size: .875rem;
    font-weight: 400;
    padding: .375rem .875rem;
    border-radius: 6px;
    transition: color .2s, background .2s;
}
.main-nav a:hover,
.main-nav a.active { color: var(--dark-maroon); background: var(--cream); }

.nav-more { position: relative; }
.nav-more-btn {
    display: inline-flex;
    align-items: center;
    gap: .125rem;
    background: none;
    border: none;
    color: var(--maroon);
    font-family: inherit;
    font-size: .875rem;
    font-weight: 400;
    padding: .375rem .875rem;
    border-radius: 6px;
    cursor: pointer;
    transition: color .2s, background .2s;
}
.nav-more-btn:hover,
.nav-more-btn.active { color: var(--dark-maroon); background: var(--cream); }
.nav-more-btn .material-symbols-outlined { transition: transform .2s ease; }
.nav-more-btn.open .material-symbols-outlined { transform: rotate(180deg); }
.nav-more-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-md);
    min-width: 190px;
    z-index: 1000;
    overflow: hidden;
    display: none;
}
.nav-more-dropdown.open { display: block; }
.nav-more-dropdown a {
    display: block;
    padding: .625rem 1rem;
    font-size: .875rem;
    color: var(--ink);
    text-decoration: none;
    transition: background .15s, color .15s;
}
.nav-more-dropdown a:hover,
.nav-more-dropdown a.active { background: var(--cream); color: var(--maroon); }

.header-right {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-shrink: 0;
    position: relative;
}
.btn-login-nav {
    display: inline-flex;
    align-items: center;
    gap: .375rem;
    background: none;
    border: none;
    color: var(--maroon);
    font-family: inherit;
    font-size: .875rem;
    font-weight: 400;
    cursor: pointer;
    padding: .375rem .75rem;
    border-radius: 6px;
    transition: color .2s, background .2s;
    text-decoration: none;
}
.btn-login-nav:hover { color: var(--dark-maroon); background: var(--cream); }

.user-avatar-btn {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: var(--maroon);
    color: #fff;
    font-weight: 700;
    font-size: .875rem;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.user-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-md);
    min-width: 190px;
    z-index: 1000;
    overflow: hidden;
    display: none;
}
.user-dropdown.open { display: block; }
.user-dropdown { min-width: 250px; }
.dd-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
    padding: .875rem 1rem;
    border-bottom: 1px solid var(--border);
}
.dd-identity { display: flex; flex-direction: column; gap: .1rem; min-width: 0; }
.dd-name {
    font-family: var(--font-head);
    font-size: .9375rem;
    font-weight: 700;
    color: var(--ink);
    line-height: 1.3;
}
.dd-role { font-size: .8125rem; color: var(--grey); line-height: 1.3; }
.dd-close {
    background: none;
    border: none;
    padding: 0;
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    color: var(--grey);
    cursor: pointer;
    transition: background .15s, color .15s;
}
.dd-close:hover { background: var(--cream); color: var(--maroon); }
.dd-actions { padding: .375rem 0; }
.user-dropdown .dd-actions a {
    display: flex;
    align-items: center;
    gap: .625rem;
    width: 100%;
    padding: .625rem 1rem;
    font-size: .875rem;
    color: var(--ink);
    text-decoration: none;
    background: none;
    border: none;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.user-dropdown .dd-actions a:hover { background: var(--cream); color: var(--maroon); }

/* Notification dropdown */
.notif-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-md);
    width: 340px;
    max-height: 26rem;
    /* The panel itself must not scroll. It did, and so did the list inside it,
       which put two scrollbars side by side and made either one hard to catch.
       The header, tabs and footer stay put; only the list moves. */
    overflow: hidden;
    z-index: 1000;
    display: none;
    flex-direction: column;
}
.notif-dropdown.open { display: flex; }
.notif-dropdown-header,
.notif-tabs,
.notif-view-all { flex: 0 0 auto; }
.notif-dropdown-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem 1rem;
    background: var(--dark-maroon);
    color: #fff;
}
.notif-dropdown-header span { font-weight: 700; font-size: .8125rem; }
.notif-mark-all {
    background: none;
    border: none;
    color: rgba(255,255,255,.85);
    font-size: .6875rem;
    cursor: pointer;
    text-decoration: underline;
    font-family: inherit;
}
.notif-mark-all:hover { color: #fff; }
.notif-header-tools { display: inline-flex; align-items: center; gap: .25rem; }
.notif-close {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.5rem; height: 1.5rem; padding: 0;
    border: none; border-radius: 6px; background: none;
    color: rgba(255, 255, 255, .8); cursor: pointer;
}
.notif-close:hover { background: rgba(255, 255, 255, .18); color: #fff; }

/* Two views of the same list, filtered in the browser — the eight most recent
   are already loaded, so switching should not cost a round trip. */
.notif-tabs {
    display: flex; gap: .375rem;
    padding: .5rem .75rem;
    border-bottom: 1px solid var(--border-soft);
    background: var(--white);
}
.notif-tab {
    padding: .25rem .75rem; border: 1px solid transparent; border-radius: 999px;
    background: none; color: var(--grey); font-family: inherit; font-size: .75rem;
    cursor: pointer;
}
.notif-tab:hover { color: var(--maroon); }
.notif-tab.is-on { background: var(--cream); border-color: var(--soft-maroon); color: var(--maroon); font-weight: 500; }

.notif-list { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

/* The dot is the whole unread signal: a tinted row alone is easy to miss, and
   two shades of tint for read/unread is harder to tell apart than one dot. */
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: .5rem;
    width: 100%;
    text-align: left;
    padding: .625rem 1rem;
    border-bottom: 1px solid var(--border-soft);
    background: var(--white);
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
}
.notif-item.unread { background: var(--cream); }
.notif-item:hover { background: rgba(177,125,125,.12); }
.notif-dot {
    width: .4rem; height: .4rem; flex: 0 0 auto; margin-top: .4rem;
    border-radius: 50%; background: var(--maroon); opacity: 0;
}
.notif-item.unread .notif-dot { opacity: 1; }
.notif-body { flex: 1 1 auto; min-width: 0; }
.notif-text {
    display: block; font-size: .75rem; color: var(--ink);
    line-height: 1.45; margin-bottom: .2rem;
}
.notif-item.unread .notif-text { font-weight: 500; }
.notif-item small { font-size: .6875rem; color: var(--grey); }
.notif-empty { padding: 2rem 1rem; text-align: center; color: var(--grey); font-size: .75rem; }
.notif-view-all {
    display: block;
    text-align: center;
    margin: .5rem;
    padding: .55rem;
    border-radius: 8px;
    background: var(--cream);
    font-size: .75rem;
    color: var(--maroon);
    text-decoration: none;
    font-weight: 500;
}
.notif-view-all:hover { background: var(--soft-maroon); color: #fff; }

/* ===== Site footer ===== */
.site-footer {
    background: var(--dark-maroon);
    color: rgba(255,255,255,.75);
    min-height: 68px;
    display: flex;
    align-items: center;
}
.footer-inner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    width: 100%;
    font-size: .6875rem;
}
.footer-links { display: flex; flex-wrap: wrap; gap: 1.25rem; }
.footer-links a {
    color: rgba(255,255,255,.75);
    text-decoration: none;
    transition: color .2s;
}
.footer-links a:hover { color: #fff; text-decoration: underline; }

/* ===== Login modal / slide-in panel =====
   This used to be called .modal-backdrop — the same class Bootstrap generates
   for its own dialogs. Every Bootstrap modal in PAPEL therefore picked up this
   blur and this z-index, which sits above Bootstrap's dialog layer (1055), so
   the dialog appeared behind its own veil. The name is ours now. */
.login-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(51,0,0,.40);
    backdrop-filter: blur(3px);
    z-index: 1100;
    opacity: 0;
    pointer-events: none;
    transition: opacity .3s;
}
.login-backdrop.open { opacity: 1; pointer-events: all; }

.login-panel {
    position: fixed;
    top: 0; right: 0;
    height: 100%;
    width: 480px;
    background: var(--white);
    z-index: 1200;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform .35s cubic-bezier(.4,0,.2,1);
    overflow-y: auto;
}
.login-panel.open { transform: translateX(0); }
.login-panel.expanded { width: 100%; left: 0; }

.panel-topbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: .75rem 1rem;
    gap: .5rem;
    flex-shrink: 0;
}
.panel-topbar-brand { margin-right: auto; display: none; }
.login-panel.expanded .panel-topbar-brand { display: flex; align-items: center; gap: .5rem; }
.panel-topbar-brand a { text-decoration: none; }
.panel-topbar-brand span {
    font-family: var(--font-head);
    /* The wordmark stays bold, matching the navbar brand — it is the one
       exception to the panel's otherwise unbolded type. */
    font-weight: 800;
    font-size: 1.25rem;
    letter-spacing: .01em;
    color: var(--pup-maroon);
    transition: color .2s;
}
.panel-topbar-brand a:hover span { color: var(--maroon); }
.panel-ctrl-btn {
    width: 34px; height: 34px;
    border: none;
    border-radius: 6px;
    background: none;
    color: var(--ink);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
}
.panel-ctrl-btn .material-symbols-outlined { font-size: 24px; }
.panel-ctrl-btn:hover { background: var(--cream); color: var(--maroon); }

.panel-body {
    flex: 1;
    padding: .5rem 2.5rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.login-panel.expanded .panel-body { max-width: 480px; margin: 0 auto; width: 100%; }

.panel-title {
    font-family: var(--font-head);
    font-size: 1.5rem;
    font-weight: 500;
    color: var(--pup-maroon);
    text-align: center;
    margin-bottom: .375rem;
}
.panel-subtitle {
    font-family: var(--font-body);
    font-weight: 400;
    font-size: .875rem;
    color: var(--grey);
    text-align: center;
    margin-bottom: 1.5rem;
}

.role-selector {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.75rem;
    justify-content: center;
    width: 100%;
}
.role-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .625rem;
    border: none;
    cursor: pointer;
    background: none;
    padding: 0;
    transition: opacity .2s;
    width: 105px;
    flex-shrink: 0;
    position: relative;
    user-select: none;
}
.role-card:not(.active) { opacity: .55; }
.role-card:not(.active):hover { opacity: .85; }
.role-dot {
    position: absolute;
    top: 7px; left: 8px;
    width: 11px; height: 11px;
    border-radius: 50%;
    background: var(--pup-maroon);
    border: 1px solid var(--maroon);
    display: none;
}
.role-card.active .role-dot { display: block; }
.role-icon {
    width: 105px; height: 105px;
    border-radius: 10px;
    background: var(--cream);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--grey);
    border: 1px solid transparent;
    transition: all .2s;
}
.role-card.active .role-icon { color: var(--pup-maroon); border-color: var(--maroon); }
.role-card:hover .role-icon { background: rgba(130,7,7,.08); }
.role-icon .material-symbols-outlined { font-size: 44px; }
.role-label {
    font-family: var(--font-body);
    font-size: .8125rem;
    font-weight: 400;
    color: var(--grey);
    text-align: center;
    line-height: 1.2;
    white-space: normal;
    overflow-wrap: break-word;
}
.role-card.active .role-label { color: var(--maroon); }

.login-form { width: 100%; }
.lf-group { margin-bottom: 1.125rem; width: 100%; }
.lf-group-tight { margin-bottom: .25rem; }
.lf-label {
    display: block;
    font-family: var(--font-body);
    font-size: .875rem;
    font-weight: 400;
    color: var(--ink);
    margin-bottom: .375rem;
}
.lf-input {
    width: 100%;
    padding: .75rem .875rem;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: .9rem;
    font-family: var(--font-body);
    font-weight: 400;
    color: var(--ink);
    background: var(--cream);
    transition: border-color .2s, box-shadow .2s;
}
.lf-input:focus {
    outline: none;
    border-color: var(--maroon);
    box-shadow: 0 0 0 3px rgba(130,7,7,.10);
    background: var(--white);
}
.lf-password-wrap { position: relative; }
.lf-password-wrap .lf-input { padding-right: 2.75rem; }
.lf-password-toggle {
    position: absolute;
    top: 50%;
    right: .5rem;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    border-radius: 6px;
    color: var(--grey);
    cursor: pointer;
    transition: color .2s, background .2s;
}
.lf-password-toggle:hover { color: var(--maroon); background: var(--cream); }
.birthdate-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .5rem; }
.birthdate-row .lf-input { padding: .625rem .5rem; font-size: .8125rem; }
select.lf-input {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23820707' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .6rem center;
}
.birthdate-row select.lf-input { padding-right: 1.6rem; }
.lf-forgot {
    display: block;
    font-family: var(--font-body);
    text-align: right;
    margin-top: .4rem;
    font-size: .8125rem;
    font-weight: 400;
    color: var(--maroon);
    text-decoration: none;
}
.lf-forgot:hover { text-decoration: underline; }
.btn-panel-login {
    width: 100%;
    padding: .875rem;
    background: var(--maroon);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 400;
    cursor: pointer;
    font-family: var(--font-body);
    margin-top: .5rem;
    transition: background .2s;
}
.btn-panel-login:hover { background: var(--dark-maroon); }
.panel-footer-text {
    font-family: var(--font-body);
    font-weight: 400;
    font-size: .75rem;
    color: var(--ink);
    text-align: center;
    margin-top: 1.5rem;
    line-height: 1.6;
}
.panel-footer-text a {
    display: inline-block;
    margin-top: .35rem;
    color: var(--maroon);
    font-weight: 400;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.panel-footer-text a:hover { color: var(--dark-maroon); }

.panel-alert {
    width: 100%;
    padding: .75rem 1rem;
    border-radius: 8px;
    font-size: .875rem;
    margin-bottom: 1rem;
}
.panel-alert.error { background: #fdeaea; color: var(--dark-maroon); }
.panel-alert.success { background: #e7f6ed; color: #1b5e35; }

@media (max-width: 900px) {
    .main-nav { display: none; }
    .login-panel { width: 100%; }
}
@media (max-width: 600px) {
    .wrap, .wrap-wide { padding: 0 1rem; }
    .brand { font-size: 1.375rem; }
    .notif-dropdown { width: 92vw; right: -2vw; }
    .panel-body { padding: .5rem 1.25rem 2rem; }
    .role-selector { gap: .5rem; }
    .role-card { width: 84px; }
    .role-icon { width: 64px; height: 64px; }
    .role-icon .material-symbols-outlined { font-size: 28px; }
}
</style>
<?php /* Palettes and light/dark. Included last so its :root wins over the
        literal token values above, without editing them. */ ?>
<?php require_once __DIR__ . '/theme.php'; ?>
