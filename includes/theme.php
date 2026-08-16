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
 *   data-color  maroon | lightblue | blue | white | classic
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
    --accent-light: #E08A8A;
    --ink-base:     #330000;
    --border-base:  #E6D4D4;
}
html[data-color="lightblue"] {
    --accent:       #2E86AB;
    --accent-dark:  #1F5F7A;
    --accent-soft:  #9EC7DA;
    --accent-tint:  #F2FAFD;
    --accent-light: #79C4E3;
    --ink-base:     #10303D;
    --border-base:  #D3E6EF;
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
    --grey:         #9F9F9F;
}

/* ---------------------------------------------------------------
   3. Dark mode, for whichever palette is on.

   --white is the surface colour everywhere on the site, so turning it
   dark turns the site dark; the accent lifts to stay legible against
   it. Text sitting *on* the accent is a literal #fff throughout the
   stylesheets, so it stays readable either way.
   --------------------------------------------------------------- */
html[data-mode="dark"] {
    --white:        #17141A;
    --cream:        rgba(255, 255, 255, .06);
    --ink:          #ECE7EA;
    --grey:         #9C949A;
    --border:       #332C33;
    --border-soft:  rgba(255, 255, 255, .13);
    --maroon:       var(--accent-light);
    --pup-maroon:   var(--accent-light);
    --dark-maroon:  var(--accent-light);
    --soft-maroon:  var(--accent-soft);
    --shadow-sm:    0 1px 3px rgba(0, 0, 0, .5);
    --shadow-md:    0 6px 18px rgba(0, 0, 0, .55);
    color-scheme: dark;                    /* native controls follow */
}
html[data-mode="dark"] body { background: #100E12; }

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
.qs-color input { accent-color: var(--accent); flex: 0 0 auto; }
/* A solid dot of the palette's own accent — an inset ring here would hollow it
   out and leave only a rim of the colour it is meant to be showing. */
.qs-swatch {
    width: .95rem; height: .95rem; flex: 0 0 auto;
    border-radius: 50%; border: 1px solid var(--border);
}
</style>
