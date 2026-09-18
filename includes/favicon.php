<?php
/**
 * The address-bar icon, in one place.
 *
 * Most pages pick this up through includes/site_head.php, which requires it.
 * The three that deliberately skip site_head.php — archive/login.php (its own
 * split-screen layout), pages/error_page.php (chrome-less by design) — require
 * it directly, so a reader never loses the mark just because a page opted out
 * of the shared header.
 *
 * Paths are absolute via BASE_URL: pages sit at several directory depths and a
 * relative href would resolve differently on each.
 *
 * All three are scaled-down copies of the logo on a white card — white to
 * match body's own background, so the icon reads as part of the site rather
 * than a tile sat on top of it. They come from Logo-Papel-Transparent.svg
 * composited onto white, not from the cream Logo-Papel.png recoloured: the
 * transparent art carries real alpha, so the mark's anti-aliased edges and its
 * 10%-opacity shadow overlay land on white cleanly instead of dragging cream
 * halos with them. The source is 1332x1271, squared by padding on white, since
 * resizing a non-square image into a square one would squash the mark.
 * Change the logo and these three have to be regenerated from it.
 *
 * The two the address bar uses have their corners rounded off to transparency
 * at roughly a ninth of their width, so the 16px and 32px draws carry the same
 * roundness rather than a radius that only suits one of them. The 180px
 * apple-touch icon is deliberately left square and opaque: iOS lays its own
 * mask over that one, and a transparent corner there comes out black instead
 * of showing what is behind it.
 *
 * Logo-Papel.svg and Logo-Papel.png are kept alongside as the full-size
 * originals, but neither is what the address bar loads — the SVG is a 400KB
 * raster in an SVG wrapper, which is a lot to send for a 16px icon.
 *
 * Nothing here leans on the bootstrap: error_page.php renders while the very
 * things that failed may still be missing, so BASE_URL is guarded and the
 * escaping does not go through e().
 */
$favicon_base = htmlspecialchars(
    (defined('BASE_URL') ? BASE_URL : '/capstone') . '/assests/images',
    ENT_QUOTES, 'UTF-8');
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= $favicon_base ?>/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $favicon_base ?>/favicon-180.png">
<link rel="shortcut icon" href="<?= $favicon_base ?>/favicon.ico">
