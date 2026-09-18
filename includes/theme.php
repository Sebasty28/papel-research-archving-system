<?php
/**
 * Colour palettes and light/dark, for the whole site.
 *
 * The page has always been drawn from a handful of tokens — --maroon for the
 * accent, --white for surfaces, --cream for the tint behind them, --ink for
 * text. Nothing here changes that. A palette simply re-points those tokens at
 * a different set of colours, and dark mode re-points the surface ones. So
 * every page, including ones written long before this existed, follows along
 * without being touched.
 *
 * There is one choice, not two. A separate light/dark switch sat beside the
 * palette and the two could disagree — "Dark Green" in dark mode was a third
 * thing nobody had designed. Now the palette says whether it is light or dark.
 *
 * Two palettes, one of each: Old Classic, and Old Night, which is Old Classic
 * after dark — the same maroon and gold on warm espresso surfaces rather than
 * a generic dark theme. Seven used to be offered; the other five were
 * withdrawn, and anyone still holding one is moved across (see the script).
 *
 * Two attributes on <html> carry it:
 *   data-color  classic | old-night
 *   data-mode   light | dark        (derived from the palette, never chosen)
 *
 * classic ("Old Classic") is the default — a first-time reader with nothing
 * in storage yet lands there.
 *
 * data-mode is kept because every dark rule on the site keys off it. It is now
 * a consequence of the palette rather than a setting of its own.
 *
 * The script below runs before anything is painted, so a reader who chose a
 * dark palette never sees a white page flash first.
 *
 * Include inside <head>, immediately after the base tokens.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Runs before first paint: no flash of the wrong theme. */
(function () {
    var el = document.documentElement;
    var get = function (k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };

    /* The palettes on offer, and which of them is dark. The same two lists are
       in browse_console_js.php, theme_welcome.php and pages/settings.php;
       this one has to know before the CSS does, so the first paint is right. */
    var PALETTES = { 'classic': 1, 'old-night': 1 };
    var DARK = { 'old-night': 1 };
    /* Withdrawn palettes that were dark. Whoever chose one chose a dark site,
       so they keep one: they land on Old Night rather than a white page. */
    var WAS_DARK = { 'modern-dark': 1, 'quiet-dark': 1 };

    var stored = get('papel_color', 'classic');
    var colour = stored;
    /* Anything no stylesheet answers to any more — Maroon, Light, the four
       editor palettes, and the older green, blue and white — would strand the
       reader on the bare :root defaults, so it is replaced outright. */
    if (!PALETTES[colour]) { colour = WAS_DARK[colour] ? 'old-night' : 'classic'; }
    /* The old light/dark preference predates palettes. Someone who had chosen
       dark keeps a dark site. */
    var oldTheme = get('papel_theme', '');
    if (oldTheme === 'dark' && !DARK[colour]) { colour = 'old-night'; }
    if (colour !== stored) {
        try { localStorage.setItem('papel_color', colour); } catch (e) {}
    }
    if (oldTheme) {
        try { localStorage.removeItem('papel_theme'); } catch (e) {}
    }

    el.setAttribute('data-color', colour);
    el.setAttribute('data-mode', DARK[colour] ? 'dark' : 'light');
})();
</script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ---------------------------------------------------------------
   1. The palettes.

   Each one names the same five roles, so the rules underneath never
   need to know which palette is in use:
     --accent        the primary colour
     --accent-dark   pressed/active, and headings
     --accent-soft   borders and muted marks
     --accent-tint   the pale wash behind cards and hovers
     --accent-light  the accent lifted for legibility on a dark surface
   --------------------------------------------------------------- */
/* Old Classic is the default palette — bundled onto the bare :root so a
   reader whose browser never runs the script above (data-color absent
   entirely) still lands here. */
:root,
html[data-color="classic"] {
    --accent:       #6B0F0F;
    --accent-dark:  #4A0A0A;
    --accent-soft:  #C0A062;
    --accent-tint:  #FBF5E9;
    --accent-light: #D8B472;
    --ink-base:     #2B1B0E;
    --border-base:  #E6D9BF;
}

/* Old Night — Old Classic after dark. The same two colours carry it: the
   maroon still fills the buttons and the crumb strip, and the gold that was
   Old Classic's muted accent becomes the accent read as text, since maroon
   text on a dark page cannot be read. The surfaces (section 3b) are a warm
   espresso rather than the generic blue-black, so the page keeps the parchment
   feel of its daytime twin instead of turning into somebody else's theme.

   Measured against the surface #1E1813 and the tint over it (#2B231A):
     --accent-light  #D8B472   8.94:1 and 7.87:1   (text, so held to AAA)
     white on --accent       #7A1A1A  10.55:1
     white on --accent-dark  #5A1010  13.85:1      (the crumb strip, pressed) */
html[data-color="old-night"] {
    --accent:       #7A1A1A;
    --accent-dark:  #5A1010;
    --accent-soft:  #8A7348;
    --accent-tint:  rgba(216, 180, 114, .07);
    --accent-light: #D8B472;
    --ink-base:     #F0E6D4;
    --border-base:  #3B3026;
}

/* ---------------------------------------------------------------
   2. The site's long-standing token names, pointed at the palette.
   These override the literals in the block above this include.
   --------------------------------------------------------------- */
:root {
    --maroon:       var(--accent);
    --pup-maroon:   var(--accent);
    --dark-maroon:  var(--accent-dark);
    --soft-maroon:  var(--accent-soft);
    --cream:        var(--accent-tint);
    --ink:          var(--ink-base);
    --border:       var(--border-base);
    --white:        #FFFFFF;
    /* Muted text. #9F9F9F sat at 2.65:1 on white, then #6E6A6E at 5.32:1,
       which clears AA but not the AAA this palette is now held to. It carries
       hints, table headers, timestamps and empty-state copy on nearly every
       page, so it is the single token that decides whether the secondary text
       of the site is comfortable to read. 7.92:1 on white, 7.40:1 on the tint,
       and still plainly lighter than --ink. */
    --grey:         #545054;

    /* The accent doing two different jobs.
       --maroon is used both as text and as a background, which is fine while
       the page is light but pulls in opposite directions once it is dark: text
       has to lift to stay legible, a background carrying white text has to stay
       dark. These are the background form, and they do not lift. */
    --maroon-surface:       var(--accent);
    --maroon-surface-hover: var(--accent-dark);

    /* Success and failure, which are their own colours rather than the accent.
       Kept as tokens because they were hardcoded hex before, and a hardcoded
       green on a card that turns dark is simply not readable. */
    /* 6.97:1 on its own background, which rounds to AAA and is not AAA.
       8.35:1 now. */
    --ok-text:    #17512E;
    --ok-bg:      #E7F6ED;
    --ok-border:  #BFE3CD;
    --bad-text:   var(--accent-dark);
    --bad-bg:     #FDEAEA;
    --bad-border: var(--accent-soft);

    /* ---- Corner radius ----------------------------------------------
       One scale, four steps, because a repository is read as a document
       rather than played with as an app. Softer corners were reading as
       consumer software and, at 10px and 12px on a dense table, they also
       fought the grid.

         --r-badge    2px  a label that is read, not pressed: PDF, Thesis,
                           a programme code, a status chip
         --r-control  4px  anything you click or type into: buttons, fields,
                           selects, tabs, filter chips, pagination
         --r-card     8px  something that holds content: cards, panels,
                           modals, banners, the review record
         --r-data     0    table shells and grids, where any rounding pulls
                           the first and last cell out of line

       Circles keep 50% and are deliberately outside this scale: an avatar,
       an unread dot or a toggle knob is round because of what it is. */
    --r-badge:   2px;
    --r-control: 4px;
    --r-card:    8px;
    --r-data:    0px;
    --r-round:   50%;

    /* The sticky header once it is scrolled. Kept beside the palette because
       it is a surface colour, and it has to change with the mode. */
    --header-frost:        rgba(255, 255, 255, .72);
    --header-frost-solid:  rgba(255, 255, 255, .97);
    --header-frost-edge:   rgba(177, 125, 125, .28);
    --header-frost-shadow: rgba(51, 0, 0, .08);
}

/* ---------------------------------------------------------------
   3. Dark mode, for whichever palette is on.

   --white is the surface colour everywhere on the site, so turning it
   dark turns the site dark; the accent lifts to stay legible against
   it.

   That lift is right for the accent as *text* and wrong for it as a
   *background*. This block used to claim that text on the accent "stays
   readable either way" — it did not: white on the lifted accent measured
   2.57:1 across the navbar, breadcrumb, footer and primary buttons on
   every page, against the 4.5:1 the rest of the site meets. The surface
   tokens below therefore keep the dark values.
   --------------------------------------------------------------- */
html[data-mode="dark"] {
    /* A blue-black rather than the warm purple-black this used to be. The tint
       above it composites to #282C33, which is what the muted text below is
       measured against, since that is where most of it sits. */
    --white:        #1A1E26;
    --cream:        rgba(255, 255, 255, .06);
    --ink:          #E6EAF0;      /* 13.83:1 on the page, 11.61:1 on the tint */
    --grey:         #B4BDCA;      /*  8.80:1 and 7.39:1, so AAA on both       */
    --border:       #333A47;
    --border-soft:  rgba(255, 255, 255, .13);
    --maroon:       var(--accent-light);
    --pup-maroon:   var(--accent-light);
    --dark-maroon:  var(--accent-light);
    --soft-maroon:  var(--accent-soft);
    --shadow-sm:    0 1px 3px rgba(0, 0, 0, .5);
    --shadow-md:    0 6px 18px rgba(0, 0, 0, .55);
    --shadow-up:    0 -10px 10px -10px rgba(0, 0, 0, .5);
    --shadow-up-rim: 0 -3px 10px rgba(0, 0, 0, .45);

    /* Lifted to read on a dark card, and their tinted backgrounds turned into
       a wash of the same colour rather than the pale pink and mint that only
       work on white. */
    --ok-text:    #7FBB92;
    --ok-bg:      rgba(127, 187, 146, .12);
    --ok-border:  rgba(127, 187, 146, .32);
    --bad-text:   #F0A2A2;
    --bad-bg:     rgba(240, 162, 162, .12);
    --bad-border: rgba(240, 162, 162, .32);

    /* The same frosted header, in the dark surface rather than white. */
    --header-frost:        rgba(26, 30, 38, .72);
    --header-frost-solid:  rgba(26, 30, 38, .97);
    --header-frost-edge:   rgba(255, 255, 255, .14);
    --header-frost-shadow: rgba(0, 0, 0, .45);

    color-scheme: dark;                    /* native controls follow */
}
html[data-mode="dark"] body { background: #14171E; }

/* ---------------------------------------------------------------
   3b. The dark palette brings its own surfaces.

   The block above is one dark scheme, written when dark was a switch that sat
   on top of whichever colour was chosen. A dark palette carries the surfaces
   it is named after, so Old Night restates only those; everything else still
   comes from the block above.
   --------------------------------------------------------------- */
html[data-mode="dark"][data-color="old-night"] {
    --white:        #1E1813;      /* warm espresso rather than blue-black      */
    --cream:        rgba(216, 180, 114, .07);   /* a gold wash; #2B231A over --white */
    --ink:          #F0E6D4;      /* parchment: 14.20:1 on the surface, 12.50:1 on the tint */
    --grey:         #C4B69C;      /*  8.80:1 and 7.75:1, so AAA on both         */
    --border:       #3B3026;
    --border-soft:  rgba(240, 230, 212, .12);
    --header-frost:        rgba(30, 24, 19, .74);
    --header-frost-solid:  rgba(30, 24, 19, .97);
    --header-frost-edge:   rgba(240, 230, 212, .12);
}
html[data-mode="dark"][data-color="old-night"] body { background: #16110D; }

/* A few places paint a literal white that would glare on a dark page. */
html[data-mode="dark"] .doc-surface,
html[data-mode="dark"] .pd-prose td,
html[data-mode="dark"] .pd-prose th,
html[data-mode="dark"] .search-form,
html[data-mode="dark"] input,
html[data-mode="dark"] select,
html[data-mode="dark"] textarea {
    background: var(--white);
    color: var(--ink);
}
html[data-mode="dark"] img:not([src*=".svg"]) { filter: brightness(.92); }

/* ---------------------------------------------------------------
   4. The colour picker inside Quick Settings.
   --------------------------------------------------------------- */
.qs-colors { display: flex; flex-direction: column; gap: .1rem; }
.qs-color {
    display: flex; align-items: center; gap: .5rem;
    padding: .3rem .1rem; font-size: .8125rem; color: var(--ink); cursor: pointer;
}
/* The same control the Density and Theme rows use, which is a filled dot
   rather than the browser's ring. Those live in browse_console.php, which is
   not on every page carrying this panel, so the declarations are repeated here
   rather than depending on that file being present. */
.qs-color input[type="radio"] {
    appearance: none; -webkit-appearance: none;
    width: 12px; height: 12px;
    border-radius: 50%;
    background: #E2DCDC;
    margin: 0; flex: 0 0 auto; cursor: pointer;
}
/* --maroon, not --accent: under Old Night the raw accent is a dark maroon on a
   dark panel (1.67:1), and --maroon is the form of it that lifts to be seen. */
.qs-color input[type="radio"]:checked { background: var(--maroon); }
/* A solid dot of the palette's own accent — an inset ring here would hollow it
   out and leave only a rim of the colour it is meant to be showing. */
.qs-swatch {
    width: .95rem; height: .95rem; flex: 0 0 auto;
    border-radius: 50%; border: 1px solid var(--border);
}
</style>
