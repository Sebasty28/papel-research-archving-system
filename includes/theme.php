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
 * Two attributes on <html> carry it:
 *   data-color  maroon | green | blue | white | classic
 *   data-mode   light | dark        (resolved; "system" is worked out in JS)
 *
 * The script below runs before anything is painted, so a reader who chose dark
 * never sees a white page flash first.
 *
 * Include inside <head>, immediately after the base tokens.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Runs before first paint: no flash of the wrong theme. */
(function () {
    var el = document.documentElement;
    var get = function (k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };

    var colour = get('papel_color', 'maroon');
    /* Renamed when the palette became dark green. Without this, a reader who
       had chosen it would silently land back on maroon. */
    if (colour === 'lightblue') {
        colour = 'green';
        try { localStorage.setItem('papel_color', 'green'); } catch (e) {}
    }
    var theme  = get('papel_theme', 'light');    // PAPEL is a light site by default
    var dark   = theme === 'dark' ||
                 (theme === 'system' && window.matchMedia &&
                  window.matchMedia('(prefers-color-scheme: dark)').matches);

    el.setAttribute('data-color', colour);
    el.setAttribute('data-theme', theme);
    el.setAttribute('data-mode', dark ? 'dark' : 'light');
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
:root,
html[data-color="maroon"] {
    --accent:       #820707;
    --accent-dark:  #630000;
    --accent-soft:  #B17D7D;
    --accent-tint:  #FFF5F5;
    /* 6.50:1 on the new dark surface, where it needs 7. #E39494 gives 7.10. */
    --accent-light: #E39494;
    --ink-base:     #330000;
    --border-base:  #E6D4D4;
}
/* Dark green. Chosen against the same bar the rest of the palette meets:
   9.11:1 as text on white, and 9.11:1 for white text on it, so it works as
   both a heading colour and a button fill. --accent-light is the lift for
   dark mode, at 9.15:1 on the dark surface. */
html[data-color="green"] {
    --accent:       #14532D;
    --accent-dark:  #0E3D21;
    --accent-soft:  #8FB79E;
    --accent-tint:  #F2FAF5;
    --accent-light: #7FC79A;
    --ink-base:     #0B2E1A;
    --border-base:  #D6E7DC;
}
html[data-color="blue"] {
    --accent:       #14487F;
    --accent-dark:  #0D3159;
    --accent-soft:  #93AFCD;
    --accent-tint:  #F3F7FC;
    --accent-light: #7FAEE0;
    --ink-base:     #0B2540;
    --border-base:  #D6E1EF;
}
html[data-color="white"] {
    --accent:       #3B3B3B;
    --accent-dark:  #1C1C1C;
    --accent-soft:  #C4C4C4;
    --accent-tint:  #F6F6F6;
    --accent-light: #D8D8D8;
    --ink-base:     #1A1A1A;
    --border-base:  #E2E2E2;
}
html[data-color="classic"] {
    --accent:       #6B0F0F;
    --accent-dark:  #4A0A0A;
    --accent-soft:  #C0A062;
    --accent-tint:  #FBF5E9;
    --accent-light: #D8B472;
    --ink-base:     #2B1B0E;
    --border-base:  #E6D9BF;
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
.qs-color input[type="radio"]:checked { background: var(--accent); }
/* A solid dot of the palette's own accent — an inset ring here would hollow it
   out and leave only a rim of the colour it is meant to be showing. */
.qs-swatch {
    width: .95rem; height: .95rem; flex: 0 0 auto;
    border-radius: 50%; border: 1px solid var(--border);
}
</style>
