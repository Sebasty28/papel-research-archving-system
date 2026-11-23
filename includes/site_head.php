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
<?php require_once __DIR__ . '/skeleton.php'; ?>
<?php require_once __DIR__ . '/browser_interference.php'; ?>
<?php /* Bootstrap's stylesheet is the first thing in this head and the first
         request on a new origin, so the DNS lookup and the TLS handshake for it
         sit in front of the page's own first paint. Google Fonts already had
         this pair below; jsDelivr never did. */ ?>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<?php
/* ---- The Largest Contentful Paint, asked for before anything else ----
   On nearly every page the LCP element is the campus photo behind the navbar,
   and on the repository it is the same photo in that page's own banner. Either
   way it is nav_photo_order()[0], which is why that function rotates by the day
   instead of shuffling per request: a file this file cannot name is a file it
   cannot preload.

   Measured on staging before this: the image was discovered in the HTML but
   started at Low priority, was not queued until 1,011ms into the load, and then
   downloaded in 8ms. 593ms of a 1,117ms LCP was spent waiting for a turn. The
   preload here and the fetchpriority="high" in site_header.php are the two
   halves of the same fix; imagesrcset must match the srcset on the <img>
   exactly, or the browser preloads one file and then fetches another. */
$lcp_photo = function_exists('nav_photo_order') ? (nav_photo_order()[0] ?? '') : '';
if ($lcp_photo !== ''):
    $lcp_set = function_exists('nav_photo_srcset') ? nav_photo_srcset($lcp_photo) : '';
    /* Mobile pages have no campus photo, including the repository's hero.
       Gate the preload at the same breakpoint so phones make no photo request.
       The inverse query also handles fractional viewport widths at zoom. */
    $lcp_media = ' media="not all and (max-width: 900px)"';
?>
<?php if ($lcp_set !== ''): ?>
<?php /* type="image/webp" so a browser that cannot decode WebP skips this
         line rather than preloading a file it will not use. That browser
         takes the <img> src, which is still the JPEG. */ ?>
<link rel="preload" as="image" type="image/webp" fetchpriority="high"<?= $lcp_media ?>
      imagesrcset="<?= e($lcp_set) ?>" imagesizes="100vw">
<?php else: ?>
<link rel="preload" as="image" fetchpriority="high"<?= $lcp_media ?>
      href="<?= e(BASE_URL) ?>/assests/images/<?= e($lcp_photo) ?>">
<?php endif; ?>
<?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
<?php /* **The faces are served from this origin**, not from fonts.gstatic.com.
         A signed-out reader browsing the repository used to contact Google on
         every page for the fonts alone, which is the one third party the site
         reached without being asked (reCAPTCHA waits for a touch on a sign-in
         field, Drive PDFs are streamed server side). Self-hosting ends that,
         and takes a DNS lookup and a TLS handshake off the critical path: the
         woff2 is discovered on this connection rather than after a round trip
         to a second origin. The woff2 files are the same bytes Google serves.

         `assests/fonts/papel-fonts.css` is **generated**, not written: it is
         Google's own CSS2 response with the url() rewritten, so the
         @font-face blocks, the unicode-ranges and the variable weight ranges
         are still theirs and a weight resolves exactly as it did when these
         were remote. Add a family or a weight in
         `scripts/fetch_fonts.py` and run it again; do not edit the
         stylesheet by hand.

         **Ask for the axis values the site actually uses, not the whole
         variable font.** `opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200`
         is every axis at its full range, and Google answers it with the
         complete variable font: **4,025,760 bytes, 3.84MB**, at VeryHigh
         priority on every page, measured by reading Content-Length off the
         woff2 it serves. It was the largest thing on the site by a wide
         margin - the whole of the rest of the repository page is about 1MB.

         Grepped out of the stylesheets: FILL 0 and 1, GRAD 0, opsz 24, and
         wght 400. The breadcrumb chevron was the only icon asking for 700;
         using 400 there lets the whole site fetch the smaller static-weight
         font. If a new icon weight is added, add it in that script as well. */ ?>
<link rel="stylesheet" href="<?= e(asset_url('assests/fonts/papel-fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-site.css')) ?>">
<?php /* Which side the sidebar sits on, read before anything is painted: the
         same reason the palette is read here rather than at the foot of the
         page. Restored late, the column would draw on one side and jump to the
         other, which reads as a bug rather than a preference. Harmless on the
         pages that have no sidebar: nothing there matches the class.

         The left is the default, so the only stored answer that means anything
         is 'right'. Hence !== and not ===: a browser nobody has asked, and one
         with storage blocked, both start on the left rather than falling
         through to the other side. */ ?>
<?php /* And whether it is folded away to its rail at all, read in the same
         breath and for the same reason: restored late, the panel would paint
         in full and snap shut, taking the results column's width with it. */ ?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var side = null, folded = null;
    try {
        side = localStorage.getItem('papel_sidebar_side');
        folded = localStorage.getItem('papel_sidebar_folded');
    } catch (e) {}
    if (side !== 'right') { document.documentElement.classList.add('sidebar-left'); }
    if (folded === '1') { document.documentElement.classList.add('sidebar-folded'); }
})();
</script>
<?php /* Zen Mode, read in the same breath and for the same reason: a mode
         restored after the first paint is a page that puts its own furniture
         away in front of the reader. It also prints the switch the profile
         menu and Settings share. */ ?>
<?php require_once __DIR__ . '/zen_mode.php'; ?>
<?php /* Result view and density, for
         the two profile menus. Here rather than with the footer because
         site_header.php prints those menus and has to be able to call
         view_switcher(). */ ?>
<?php require_once __DIR__ . '/view_switcher.php'; ?>
<?php /* Palettes and light/dark. Included last so its :root wins over the
        literal token values above, without editing them. */ ?>
<?php require_once __DIR__ . '/theme.php'; ?>
<?php require_once __DIR__ . '/focus_ring.php'; ?>
<?php require_once __DIR__ . '/select_skin.php'; ?>
<?php /* The snackbars. In the head, not at the foot of the page: a banner
         bound for one has to be held back before it is drawn, or the page
         opens with it in place and blinks it away a moment later. */ ?>
<?php require_once __DIR__ . '/flash_dismiss.php'; ?>
