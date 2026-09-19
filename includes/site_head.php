<?php
/**
 * Shared <head> fragment: fonts, Bootstrap, and the design tokens + CSS
 * for the universal site header, footer, and login modal.
 * Include this near the top of <head>, before any page-specific <style>
 * block, so page-specific rules can still override where needed.
 */
?>
<?php require_once __DIR__ . '/favicon.php'; ?>
<?php require_once __DIR__ . '/splash.php'; ?>
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
    /* Cast upwards, for something sitting on a photo: the Public Repository's
       search field and sidebar, whose top edges would otherwise have nothing
       to separate them from the banner behind.
       Top edge only. The spread pulls the shadow in by exactly its blur, and
       the offset lifts it by the same, so the blur never reaches the sides:
       with less spread than blur (it was -6px against 18px) it ran down the
       whole length of the sidebar as a grey glow on both edges. */
    --shadow-up:    0 -10px 10px -10px rgba(51,0,0,.16);
    /* The same idea, reaching round the top corners and a little way down
       the sides as well. A plain box-shadow cannot stop partway down a side,
       so this one is cast by a short strip across the top of the element
       rather than by the element — see .sidebar-right on the repository. */
    --shadow-up-rim: 0 -3px 10px rgba(51,0,0,.12);

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
/* width:100% is doing real work here, not padding out the rule.

   body is a column flex container, and .wrap sets margin:0 auto. Auto margins
   on the cross axis of a flex container switch off the default stretch, so
   <main> stopped filling the window and sized itself to its contents instead.
   Nobody noticed while pages were full: the content was wide enough to reach
   max-width anyway. It showed the moment a list came back empty, when the whole
   page narrowed to the width of "No papers found" and re-centred, so searching
   appeared to rearrange the site. Every page with a list did it.

   The explicit width restores the fill; max-width and the auto margins still
   centre the content exactly as before. */
body > main { flex: 1 0 auto; width: 100%; }
.site-footer { margin-top: auto; flex-shrink: 0; }

h1, h2, h3, .font-head { font-family: var(--font-head); }

.wrap { max-width: var(--wrap); margin: 0 auto; padding: 0 1.5rem; }
.wrap-wide { max-width: var(--wrap-wide); margin: 0 auto; padding: 0 1.5rem; }

/* ===== Breadcrumb strip =====
   Used on almost every signed-in page. It lived in console_shell.php and again
   in manage_console.php, so a page that included neither — analytics, say —
   rendered its crumbs as bare blue links. It belongs here with .wrap. */
.crumb-bar { background: var(--maroon-surface-hover); }
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
    background: var(--maroon-surface); color: #fff; border: none; border-radius: var(--r-control, 4px);
    padding: .4rem 1rem; font-family: var(--font-body); font-size: .75rem; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
}
.btn-sm-maroon:hover { background: var(--maroon-surface-hover); color: #fff; }
.btn-sm-outline {
    background: none; color: var(--maroon); border: 1px solid var(--soft-maroon);
    border-radius: var(--r-control, 4px); padding: .4rem 1rem; font-family: var(--font-body); font-size: .75rem;
    cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
}
.btn-sm-outline:hover { background: var(--cream); }

/* ===== Bootstrap buttons, wearing the site's colours =====
   A few pages still use Bootstrap's .btn-primary and .btn-outline-secondary.
   Bootstrap paints them from its own --bs-btn-* variables, and its hover, focus
   and active rules read those variables rather than any `background` a page
   sets afterwards. Overriding only the background therefore left buttons that
   were maroon at rest and Bootstrap blue the moment they were hovered or
   focused, and left the blue focus ring everywhere.

   Setting the variables here covers every state at once, on every page that
   includes this file, so a page does not have to remember to do it. */
.btn-primary {
    --bs-btn-color: #fff;
    --bs-btn-bg: var(--maroon-surface);
    --bs-btn-border-color: var(--maroon-surface);
    --bs-btn-hover-color: #fff;
    --bs-btn-hover-bg: var(--maroon-surface-hover);
    --bs-btn-hover-border-color: var(--maroon-surface-hover);
    --bs-btn-active-color: #fff;
    --bs-btn-active-bg: var(--maroon-surface-hover);
    --bs-btn-active-border-color: var(--maroon-surface-hover);
    --bs-btn-disabled-color: #fff;
    --bs-btn-disabled-bg: var(--soft-maroon);
    --bs-btn-disabled-border-color: var(--soft-maroon);
    --bs-btn-focus-shadow-rgb: 177, 125, 125;
}
.btn-outline-secondary,
.btn-secondary {
    --bs-btn-color: var(--maroon);
    --bs-btn-bg: transparent;
    --bs-btn-border-color: var(--soft-maroon);
    --bs-btn-hover-color: var(--maroon);
    --bs-btn-hover-bg: var(--cream);
    --bs-btn-hover-border-color: var(--soft-maroon);
    --bs-btn-active-color: var(--maroon);
    --bs-btn-active-bg: var(--cream);
    --bs-btn-active-border-color: var(--soft-maroon);
    --bs-btn-focus-shadow-rgb: 177, 125, 125;
}
/* Chrome paints its own widget for a button with no author border, and on
   Windows a focused one comes out in the platform blue regardless of the
   background above. */
.btn { appearance: none; -webkit-appearance: none; }

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

/* ===== Site header =====
   Glass from the start, on every page. There is always something behind it
   now — the campus photo strip (.nav-photo, below) at the top of the page, and
   the page itself once it scrolls — so a solid bar would only hide it. It used
   to turn to glass only after 8px of scroll; .scrolled now adds just the
   shadow that tells the bar apart from content passing under it. The frost is
   a token, so dark mode gets the dark surface rather than washed-out white. */
.site-header {
    background: var(--header-frost, rgba(255,255,255,.72));
    -webkit-backdrop-filter: blur(14px) saturate(180%);
    backdrop-filter: blur(14px) saturate(180%);
    border-bottom: 1px solid var(--header-frost-edge, rgba(177,125,125,.28));
    position: sticky;
    top: 0;
    z-index: 900;
    transition: background .3s ease, box-shadow .3s ease, border-color .3s ease;
}
.site-header.scrolled {
    box-shadow: 0 2px 16px var(--header-frost-shadow, rgba(51,0,0,.08));
}
/* Without backdrop-filter there is no blur to hide behind, so the same colour
   is used at nearly full opacity. */
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
    .site-header { background: var(--header-frost-solid, rgba(255,255,255,.97)); }
}

/* ===== The photo behind the navbar =====
   The Public Repository's campus banner, as it looks there with the banner
   hidden: a strip of the photo behind the navbar and nothing below it. Every
   other page carries that strip, so the bar looks the same wherever you are.
   It sits at the top of the page and scrolls away with it, as it does there.

   The crop has to match the repository's, or the photo behind the glass would
   shift between pages. There the photo fills a box as tall as the banner
   (240px, 190px, 150px at the breakpoints), plus the navbar, the 36px crumb
   strip, and the part of the search field below the banner (26px, 46px,
   38px); object-fit: cover then takes the middle of the picture. This box is
   built from the same numbers — change the banner's height in
   archive/index.php and these have to follow. --nav-h is the navbar's height,
   measured by site_header.php, since the bar wraps taller on a phone. */
:root {
    --nav-h: 37px;
    --nav-photo-h: 240px;
    --nav-photo-tail: 26px;
}
@media (max-width: 900px) { :root { --nav-photo-h: 190px; --nav-photo-tail: 46px; } }
@media (max-width: 600px) { :root { --nav-photo-h: 150px; --nav-photo-tail: 38px; } }
.nav-photo {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: var(--nav-h);
    overflow: hidden;
    pointer-events: none;
}
.nav-photo img {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: calc(var(--nav-photo-h) + var(--nav-h) + 36px + var(--nav-photo-tail));
    object-fit: cover;
    display: block;
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
    /* The hover circle is painted 28px across, the avatar's size beside it,
       while the button keeps its 34px to be clicked: the padding holds the
       target, and the background stops at the content box inside it. */
    padding: 3px;
    background-clip: content-box;
}
.nav-icon-btn:hover { background: var(--cream); background-clip: content-box; color: var(--maroon); }
.notif-badge {
    position: absolute;
    top: 2px; right: 2px;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 999px;
    background: var(--maroon-surface);
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
/* Turned over while the menu is open, as the Resources chevron is. Read off
   the menu rather than a class on this button: the menu is closed from
   several places — this button, its own ×, the bell, a click anywhere else —
   and every one of them already clears .open there. */
.avatar-group .avatar-caret { transition: transform .2s ease; }
.avatar-group:has(+ .user-dropdown.open) .avatar-caret { transform: rotate(180deg); }
.header-inner {
    display: flex;
    align-items: center;
    gap: 2rem;
    /* .wrap-wide caps this at 1328px and centres it, which on a wide screen
       left the wordmark and the controls floating a long way in from either
       edge. The bar itself now runs the full width; only its padding holds
       them off the glass. */
    max-width: none;
    /* The same height as the breadcrumb strip directly beneath it, so the two
       bars read as one band of chrome rather than a thick one sitting on a
       thin one. 36px is what .crumb-bar comes out at. */
    height: 36px;
}
.brand {
    position: relative;
    display: inline-flex;
    align-items: center;
    text-decoration: none;
    flex-shrink: 0;
}
/* The logo is a raster wrapped in an SVG, so `fill` cannot touch it. It is
   painted rather than drawn: the artwork supplies only the stencil, through
   mask-image, and the colour underneath is the theme's own accent token — so
   the mark follows every palette and dark mode alike, exactly as the wordmark
   it replaced did. The mask is a 4KB alpha-only crop of
   Logo-Papel-Transparent.svg; the SVG itself is 900KB, which is far too much
   to send on every page for a mark this size.
   Sized against .header-inner's 36px, so it sits in the bar with room to
   spare, and shaped by the mask's own proportions rather than a second
   hard-coded number that could drift out of step with it. */
.brand-mark {
    display: block;
    height: 1.75rem;
    aspect-ratio: 106 / 128;
    background-color: var(--pup-maroon);
    -webkit-mask: url('<?= e(BASE_URL) ?>/assests/images/Logo-Papel-mask.png') center / contain no-repeat;
            mask: url('<?= e(BASE_URL) ?>/assests/images/Logo-Papel-mask.png') center / contain no-repeat;
    transition: background-color .2s;
}
.brand:hover .brand-mark { background-color: var(--maroon); }
/* The name, drawn out on hover from behind a thin rule set just right of the
   mark, and put back behind it on the way out. Absolutely placed, so the
   brand keeps the mark's width either way — were it in the flow, the link
   would grow as it opened and the centred nav beside it would lurch sideways
   on every pass of the mouse.

   The rule is .brand::after: a 1.25rem strip hugging the mark with a 1px line
   down its middle. It only fades — it is the fixed edge the word appears from.
   The strip also bridges the gap between mark and word, so the pointer can
   cross from one to the other without leaving the link and closing it. It
   takes pointer events only while open; otherwise the empty space beside the
   logo would open it.

   The word's box starts on that line, padded clear of it. Its left edge is
   clipped by exactly as much as the word is shifted, on the same timing, so
   the visible edge stays on the line while the letters slide out from under
   it. Change one of the two .75rems without the other and the letters show
   left of the line; clip-path and transform must also keep the same duration
   and easing, for the same reason. Hidden by clip-path because a clipped
   region takes no pointer events. Out is quicker than in; leaving should not
   make anyone wait.

   The same mark and word open the expanded login panel, where it works at any
   width: that panel's only other controls are pinned to its far edge. */
.brand::after {
    content: "";
    position: absolute;
    left: 100%;
    top: 50%;
    width: 1.25rem;
    height: 1.75rem;
    transform: translateY(-50%);
    background: linear-gradient(var(--soft-maroon), var(--soft-maroon)) center / 1px 100% no-repeat;
    opacity: 0;
    pointer-events: none;
    transition: opacity .22s ease-in;
}
.brand:hover::after,
.brand:focus-visible::after {
    opacity: 1;
    pointer-events: auto;
    transition: opacity .3s ease-out;
}
.brand-word {
    position: absolute;
    left: calc(100% + .625rem);
    top: 50%;
    padding-left: .625rem;
    font-family: var(--font-head);
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: .01em;
    white-space: nowrap;
    color: var(--pup-maroon);
    clip-path: inset(-.25rem 100% -.25rem .75rem);
    opacity: 0;
    transform: translate(-.75rem, -50%);
    transition: clip-path .22s ease-in, opacity .22s ease-in, transform .22s ease-in, color .2s;
}
.brand:hover .brand-word,
.brand:focus-visible .brand-word {
    clip-path: inset(-.25rem -.25rem -.25rem 0);
    opacity: 1;
    transform: translate(0, -50%);
    transition: clip-path .38s cubic-bezier(.2, .8, .2, 1), opacity .3s ease-out,
                transform .38s cubic-bezier(.2, .8, .2, 1), color .2s;
}
.brand:hover .brand-word { color: var(--maroon); }
/* The navbar's stays shut below 1100px. Being out of the flow, the word
   overlays whatever is beside the mark rather than moving it, and in the bar
   something is: under 900px the header's controls sit straight after the
   mark, and just above it the Director's four-link nav, centred in the space
   left over, starts under the word until roughly 1000px wide. 1100px leaves
   room for a role to gain a link.
   Held shut here rather than opened only above it, so the animation itself
   is written once and the two logos cannot drift apart. */
@media (max-width: 1099px) {
    .site-header .brand:hover .brand-word,
    .site-header .brand:focus-visible .brand-word {
        clip-path: inset(-.25rem 100% -.25rem .75rem);
        opacity: 0;
        transform: translate(-.75rem, -50%);
    }
    .site-header .brand:hover::after,
    .site-header .brand:focus-visible::after {
        opacity: 0;
        pointer-events: none;
    }
}
@media (prefers-reduced-motion: reduce) {
    .brand-word,
    .brand:hover .brand-word,
    .brand:focus-visible .brand-word {
        transform: translate(0, -50%);
        transition: opacity .15s linear;
    }
}
.main-nav {
    display: flex;
    align-items: center;
    gap: 2.75rem;
    flex: 1;
    justify-content: center;
}
.main-nav > a {
    position: relative;
    color: var(--maroon);
    text-decoration: none;
    font-size: .875rem;
    font-weight: 400;
    padding: .375rem .875rem;
    border-radius: var(--r-control, 4px);
    transition: color .2s;
}
.main-nav > a:hover,
.main-nav > a.active { color: var(--dark-maroon); }

/* The current page, and the one being pointed at, are underlined rather than
   boxed in cream: over the glass and the photo behind it, a solid cream tile
   sat on the bar like a sticker. A bar drawn under the label rather than
   text-decoration, so the links and the Resources button (whose chevron is a
   glyph, and would be underlined along with the word) come out identical.
   It spans the label only — inset by the item's own side padding — and grows
   from the middle on hover; the current page's is solid and stays.
   Direct children only: the Resources dropdown's own links sit inside
   .main-nav too, and keep their menu-row highlight. */
.main-nav > a::after,
.nav-more-btn::after {
    content: '';
    position: absolute;
    left: .875rem;
    right: .875rem;
    bottom: .125rem;
    height: 2px;
    border-radius: 2px;
    background: var(--soft-maroon);
    transform: scaleX(0);
    transition: transform .2s ease, background-color .2s ease;
}
/* Under the chevron as well as the word, as Login's runs under its icon. The
   chevron's V ends 4.8px inside its 18px box and the R starts 1.3px inside
   its own, so the bar is pulled in by the difference to overhang both ends
   alike. The V is symmetrical, so turning it over while open changes nothing. */
.nav-more-btn::after { right: calc(.875rem + 3.5px); }
.main-nav > a:hover::after,
.nav-more-btn:hover::after { transform: scaleX(1); }
.main-nav > a.active::after,
.nav-more-btn.active::after { transform: scaleX(1); background: var(--maroon); }
@media (prefers-reduced-motion: reduce) {
    .main-nav > a::after, .nav-more-btn::after { transition: none; }
}

/* The phone menu button. Hidden until the bar runs out of room for the links
   themselves; the rule that shows it lives with the rest of the narrow layout
   at the foot of this file. */
.nav-burger {
    display: none;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
    background: none;
    color: var(--maroon);
    cursor: pointer;
}
.nav-burger:hover { background: var(--cream); }
.nav-burger .burger-open { display: none; }
html.nav-open .nav-burger .burger-shut { display: none; }
html.nav-open .nav-burger .burger-open { display: inline-flex; }

.nav-more { position: relative; }
.nav-more-btn {
    position: relative;
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
    border-radius: var(--r-control, 4px);
    cursor: pointer;
    transition: color .2s;
}
.nav-more-btn:hover,
.nav-more-btn.active { color: var(--dark-maroon); }
.nav-more-btn .material-symbols-outlined { transition: transform .2s ease; }
.nav-more-btn.open .material-symbols-outlined { transform: rotate(180deg); }
.nav-more-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-control, 4px);
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
    transition: color .15s;
}
.nav-more-dropdown a:hover,
.nav-more-dropdown a.active { color: var(--maroon); }

/* The same underline as the bar above, so the menu does not go back to cream
   tiles one level down. The row stays full-width to keep its hit area, so
   the bar hangs off the label's span instead — on the row it would run the
   width of the menu. The drop matches how far the navbar's bar sits below
   "Resources"; any closer and it touches the descenders in "Support". */
.nav-more-label { position: relative; }
.nav-more-label::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -.48em;
    height: 2px;
    border-radius: 2px;
    background: var(--soft-maroon);
    transform: scaleX(0);
    transition: transform .2s ease, background-color .2s ease;
}
.nav-more-dropdown a:hover .nav-more-label::after { transform: scaleX(1); }
.nav-more-dropdown a.active .nav-more-label::after { transform: scaleX(1); background: var(--maroon); }
@media (prefers-reduced-motion: reduce) {
    .nav-more-label::after { transition: none; }
}

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
    border-radius: var(--r-control, 4px);
    transition: color .2s;
    text-decoration: none;
    position: relative;
}
.btn-login-nav:hover { color: var(--dark-maroon); }
/* The navbar's underline rather than a cream tile, for the same reason the
   links lost theirs — see .main-nav > a::after. It runs under the icon as
   well as the word, the two being one control. The extra 1px at the right:
   the icon's glyph sits 2.5px inside its box, the L's 1.6px inside its own,
   so ending at the box left the bar hanging further past one end than the
   other. */
.btn-login-nav::after {
    content: '';
    position: absolute;
    left: .75rem;
    right: calc(.75rem + 1px);
    bottom: .125rem;
    height: 2px;
    border-radius: 2px;
    background: var(--soft-maroon);
    transform: scaleX(0);
    transition: transform .2s ease;
}
.btn-login-nav:hover::after { transform: scaleX(1); }
@media (prefers-reduced-motion: reduce) {
    .btn-login-nav::after { transition: none; }
}

.user-avatar-btn {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--maroon-surface);
    color: #fff;
    font-weight: 700;
    font-size: .8125rem;
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
    border-radius: var(--r-control, 4px);
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
    border-radius: var(--r-control, 4px);
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
    /* No border. The shadow already lifts the card off the page, and the two
       together read as an outline drawn twice. */
    border-radius: var(--r-control, 4px);
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
    background: var(--maroon-surface-hover);
    color: #fff;
}
.notif-dropdown-header span { font-weight: 700; font-size: .8125rem; }
.notif-mark-all {
    background: none;
    border: none;
    color: rgba(255,255,255,.85);
    font-size: .6875rem;
    cursor: pointer;
    font-family: inherit;
}
.notif-mark-all:hover { color: #fff; }
.notif-header-tools { display: inline-flex; align-items: center; gap: .25rem; }
.notif-close {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.5rem; height: 1.5rem; padding: 0;
    border: none; border-radius: var(--r-control, 4px); background: none;
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
    padding: .25rem .75rem; border: 1px solid transparent; border-radius: var(--r-control, 4px);
    background: none; color: var(--grey); font-family: inherit; font-size: .75rem;
    cursor: pointer;
}
.notif-tab:hover { color: var(--maroon); }
/* No outline. Which tab is on is already said three times over — the cream
   pill, the accent text and the heavier weight — so the border was the one
   part that could go without taking the state with it. */
.notif-tab.is-on { background: var(--cream); color: var(--maroon); font-weight: 500; }

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
    border-radius: var(--r-card, 8px);
    background: var(--cream);
    font-size: .75rem;
    color: var(--maroon);
    text-decoration: none;
    font-weight: 500;
}
.notif-view-all:hover { background: var(--soft-maroon); color: #fff; }

/* Shown once, centred, right after signing in — everything inside it is the
   same .notif-dropdown-header / .notif-item / .notif-view-all vocabulary
   above, just no longer pinned under the bell. Not .modal-backdrop: that name
   collides with one Bootstrap generates for its own dialogs (see
   .login-backdrop's own note on this), so every overlay in this codebase
   gets its own name instead. */
.notif-popup-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(51,0,0,.40);
    backdrop-filter: blur(3px);
    z-index: 1150;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.notif-popup-card {
    background: var(--white);
    border-radius: var(--r-card, 8px);
    box-shadow: var(--shadow-md);
    width: 100%;
    max-width: 24rem;
    max-height: min(30rem, 80vh);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: notifPopupIn .2s ease;
}
@keyframes notifPopupIn {
    from { opacity: 0; transform: translateY(10px) scale(.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.notif-popup-card .notif-dropdown-header { border-radius: var(--r-card, 8px) var(--r-card, 8px) 0 0; }
.notif-popup-card .notif-list { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

/* ===== Site footer ===== */
.site-footer {
    background: var(--maroon-surface-hover);
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
.panel-ctrl-btn {
    width: 34px; height: 34px;
    border: none;
    border-radius: var(--r-control, 4px);
    background: none;
    color: var(--ink);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
}
.panel-ctrl-btn .material-symbols-outlined { font-size: 24px; }
/* The expand glyph runs corner to corner while the close X sits inside a
   smaller square, so at a shared font-size it draws about a third larger —
   18x18 against 14x14, measured. Sized down until the ink matches, so the two
   controls beside each other finally read as one pair rather than a big
   button and a small one. */
#expandBtn .material-symbols-outlined { font-size: 18px; }
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
    font-weight: 700;
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
    border-radius: var(--r-card, 8px);
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
    border-radius: var(--r-control, 4px);
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
    border-radius: var(--r-control, 4px);
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
    /* White text on it, so the token that does not lift. */
    background: var(--maroon-surface);
    color: #fff;
    border: none;
    border-radius: var(--r-control, 4px);
    font-size: 1rem;
    font-weight: 400;
    cursor: pointer;
    font-family: var(--font-body);
    margin-top: .5rem;
    transition: background .2s;
}
.btn-panel-login:hover { background: var(--maroon-surface-hover); }
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
    border-radius: var(--r-card, 8px);
    font-size: .875rem;
    margin-bottom: 1rem;
}
.panel-alert.error { background: #fdeaea; color: var(--dark-maroon); }
.panel-alert.success { background: #e7f6ed; color: #1b5e35; }

@media (max-width: 900px) {
    /* These links used to be thrown away at this width — display:none and
       nothing in their place — so a phone had no way to reach About, Help
       Center, Contact Support, or, signed in, any role page at all. They drop
       into a sheet under the bar instead.

       The header is sticky and therefore a positioned ancestor, so the sheet
       hangs off it and the row above keeps its height whether it is open or
       shut. */
    .nav-burger { display: inline-flex; }
    /* A phone keeps the taller bar: the menu button is a 42px touch target and
       a 48px bar leaves it almost no room either side. */
    .header-inner { gap: .75rem; height: 60px; }

    .main-nav {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        flex-direction: column;
        align-items: stretch;
        justify-content: flex-start;
        flex: none;
        gap: 0;
        padding: .375rem 0 .625rem;
        /* Opaque on purpose: once the page scrolls the bar turns frosted, and
           a sheet that inherited that would have the page showing through the
           menu it is covering. */
        background: var(--white);
        border-bottom: 1px solid var(--border);
        box-shadow: 0 14px 28px rgba(51, 0, 0, .16);
        max-height: calc(100vh - 60px);
        overflow-y: auto;
        z-index: 950;
    }
    html.nav-open .main-nav { display: flex; }

    .main-nav a {
        padding: .8rem 1.25rem;   /* ~46px tall — sized for a fingertip */
        border-radius: 0;
        font-size: .9375rem;
    }
    /* In the sheet each link is a full-width row, so the bar — which spans
       the item between its side paddings — would run the width of the sheet.
       The word itself is underlined instead, and the Resources links that
       join the list here follow suit rather than keeping their cream rows. */
    .main-nav > a::after,
    .nav-more-label::after { display: none; }
    .main-nav > a:hover,
    .nav-more-dropdown a:hover {
        background: none;
        text-decoration: underline;
        text-decoration-thickness: 2px;
        text-underline-offset: .4em;
        text-decoration-color: var(--soft-maroon);
    }
    .main-nav > a.active,
    .nav-more-dropdown a.active {
        background: none;
        text-decoration: underline;
        text-decoration-thickness: 2px;
        text-underline-offset: .4em;
        text-decoration-color: var(--maroon);
    }

    /* No menu inside a menu on a phone. The Resources group is flattened into
       the list and its button, which exists only to open the thing that is now
       already open, steps aside. */
    .nav-more { width: 100%; }
    .nav-more-btn { display: none; }
    .nav-more-dropdown {
        display: block;
        position: static;
        /* The desktop rule centres this under its button with left:50% and a
           -50% translate. Going static drops the offset but NOT the transform,
           which then shifted the links half the sheet's width off the left
           edge — space reserved, nothing painted in it. */
        transform: none;
        left: auto;
        top: auto;
        min-width: 0;
        margin-top: .375rem;
        padding-top: .375rem;
        border: 0;
        border-top: 1px solid var(--border);
        border-radius: 0;
        box-shadow: none;
    }
    .nav-more-dropdown a { padding: .8rem 1.25rem; }

    .login-panel { width: 100%; }
}
@media (max-width: 600px) {
    .wrap, .wrap-wide { padding: 0 1rem; }
    .notif-dropdown { width: 92vw; right: -2vw; }
    .panel-body { padding: .5rem 1.25rem 2rem; }
    .role-selector { gap: .5rem; }
    .role-card { width: 84px; }
    .role-icon { width: 64px; height: 64px; }
    .role-icon .material-symbols-outlined { font-size: 28px; }
}
/* The narrowest phones still in use — an SE, or an Android held in a case that
   reports 320. Every element in the bar has to give up a little for the row to
   stay on one line. */
@media (max-width: 380px) {
    .wrap, .wrap-wide { padding: 0 .75rem; }
    .header-inner { gap: .5rem; }
    .header-right { gap: .375rem; }
    .nav-burger { width: 38px; height: 38px; }
    .btn-login-nav { font-size: .8125rem; }
}
</style>
<?php /* Which side the sidebar sits on, read before anything is painted — the
         same reason the palette is read here rather than at the foot of the
         page. Restored late, the column would draw on one side and jump to the
         other, which reads as a bug rather than a preference. Harmless on the
         pages that have no sidebar: nothing there matches the class. */ ?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    try {
        if (localStorage.getItem('papel_sidebar_side') === 'left') {
            document.documentElement.classList.add('sidebar-left');
        }
    } catch (e) {}
})();
</script>
<?php /* Palettes and light/dark. Included last so its :root wins over the
        literal token values above, without editing them. */ ?>
<?php require_once __DIR__ . '/theme.php'; ?>
<?php require_once __DIR__ . '/focus_ring.php'; ?>
<?php require_once __DIR__ . '/select_skin.php'; ?>
