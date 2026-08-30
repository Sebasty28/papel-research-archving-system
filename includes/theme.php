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
 * thing nobody had designed. Now the palette says whether it is light or dark,
 * and Modern Dark and Quiet Dark are simply palettes that happen to be dark.
 *
 * Two attributes on <html> carry it:
 *   data-color  maroon | classic | quiet-light | modern-light
 *               | modern-dark | quiet-dark
 *   data-mode   light | dark        (derived from the palette, never chosen)
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

    /* The palettes that are dark. Named here and in browse_console_js.php,
       which is the only other place that has to know before the CSS does. */
    var DARK = { 'modern-dark': 1, 'quiet-dark': 1 };

    var colour = get('papel_color', 'maroon');
    /* Palettes that no longer exist. Anyone still carrying one is moved to
       maroon rather than left on an attribute no stylesheet answers to, which
       would strand them on the bare :root defaults.
         lightblue -> green   was an earlier rename;
         green, blue, white   were withdrawn when the list was reworked. */
    if (colour === 'lightblue' || colour === 'green' || colour === 'blue' ||
        colour === 'white') {
        colour = 'maroon';
        try { localStorage.setItem('papel_color', colour); } catch (e) {}
    }
    /* The old light/dark preference is gone. Someone who had chosen dark keeps
       a dark site by being moved to the palette that is its closest match,
       rather than being silently returned to a white one. */
    var oldTheme = get('papel_theme', '');
    if (oldTheme) {
        if (oldTheme === 'dark' && !DARK[colour]) {
            colour = 'modern-dark';
            try { localStorage.setItem('papel_color', colour); } catch (e) {}
        }
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
   The four editor palettes, after VS Code's own.

   They borrow the hue and the surface colours, not the syntax colours: an
   editor tints twenty kinds of token, and a repository has one accent. Where
   VS Code's own accent falls short of the contrast the rest of this site is
   held to, the accent is darkened for text and the recognisable colour is
   kept for the swatch — the dot in the picker is what makes it recognisable,
   and the text is what has to be readable.
   --------------------------------------------------------------- */

/* Quiet Light. The muted purple on near-white that gives the theme its name;
   #705697 in the editor is 5.8:1 on white, so text takes a deeper shade. */
html[data-color="quiet-light"] {
    --accent:       #574281;
    --accent-dark:  #3E2E5D;
    --accent-soft:  #B0A2C6;
    --accent-tint:  #F7F5FB;
    --accent-light: #C3B0E2;
    --ink-base:     #2A2338;
    --border-base:  #E2DCEC;
}

/* Light Modern. VS Code's current default light theme: the blue on white,
   with its very pale grey panels. */
html[data-color="modern-light"] {
    --accent:       #00519E;
    --accent-dark:  #003E78;
    --accent-soft:  #9FB9D4;
    --accent-tint:  #F3F7FB;
    --accent-light: #6CB6FF;
    --ink-base:     #14293F;
    --border-base:  #DCE4EC;
}

/* Dark Modern. The accent is the lifted blue, because on a dark palette the
   accent is read as text far more often than it is used as a fill. */
html[data-color="modern-dark"] {
    --accent:       #0078D4;
    --accent-dark:  #005A9E;
    --accent-soft:  #3A4655;
    --accent-tint:  rgba(0, 120, 212, .12);
    --accent-light: #6CB6FF;
    --ink-base:     #CCCCCC;
    --border-base:  #2B2B2B;
}

/* Quiet Dark. VS Code ships Quiet Light but no dark twin, so this is the
   counterpart rather than a copy: the same muted purple, on the soft neutral
   dark that theme's palette implies. */
html[data-color="quiet-dark"] {
    /* --accent fills buttons and the breadcrumb bar with white text on it, so
       it is the deeper purple: the lavender the palette is known by sits at
       3.82:1 under white, which fails. That lavender is --accent-light, where
       it is read as text and clears 7:1. */
    --accent:       #63508C;
    --accent-dark:  #4E3F6E;
    --accent-soft:  #4A4557;
    --accent-tint:  rgba(196, 176, 228, .12);
    --accent-light: #C4B0E4;
    --ink-base:     #C9C7CF;
    --border-base:  #34373D;
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

/* ---------------------------------------------------------------
   3b. The dark palettes bring their own surfaces.

   The block above is one dark scheme, written when dark was a switch that sat
   on top of whichever colour was chosen. Now that a dark palette is a palette,
   each carries the surfaces it is named after, so Modern Dark is VS Code's
   neutral #1F1F1F and Quiet Dark is the softer one its lighter twin implies.
   Only the surface tokens are restated; everything else still comes from the
   block above.
   --------------------------------------------------------------- */
html[data-mode="dark"][data-color="modern-dark"] {
    --white:        #1F1F1F;      /* VS Code's editor background          */
    --cream:        rgba(255, 255, 255, .05);
    --ink:          #E4E4E4;      /* 13.6:1 on the surface                */
    --grey:         #B0B0B0;      /*  7.6:1, so AAA for secondary text    */
    --border:       #2B2B2B;
    --border-soft:  rgba(255, 255, 255, .11);
    --header-frost:        rgba(24, 24, 24, .74);
    --header-frost-solid:  rgba(24, 24, 24, .97);
    --header-frost-edge:   rgba(255, 255, 255, .12);
}
html[data-mode="dark"][data-color="modern-dark"] body { background: #181818; }

html[data-mode="dark"][data-color="quiet-dark"] {
    --white:        #24262B;      /* softer and slightly warmer than above */
    --cream:        rgba(255, 255, 255, .05);
    --ink:          #E3E1E8;
    --grey:         #B6B2C2;      /* 7.31:1, so secondary text clears AAA */
    --border:       #34373D;
    --border-soft:  rgba(255, 255, 255, .11);
    --header-frost:        rgba(29, 31, 35, .74);
    --header-frost-solid:  rgba(29, 31, 35, .97);
    --header-frost-edge:   rgba(255, 255, 255, .12);
}
html[data-mode="dark"][data-color="quiet-dark"] body { background: #1D1F23; }

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
