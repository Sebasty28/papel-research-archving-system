<?php
/**
 * Shared chrome that has to exist on every page: the focus indicator, and the
 * scrollbar.
 *
 * Both are things the browser draws for you, in its own colours, unless you say
 * otherwise. On Windows that meant a grey trough with stepper arrows down the
 * right-hand edge of an otherwise maroon-and-cream site.
 *
 * This file is included from site_head.php, and directly by the three pages
 * that do not use it, which is why every value carries a literal fallback: the
 * two sign-in pages and the error page define no palette of their own.
 *
 * ---------------------------------------------------------------------------
 * One focus indicator for the whole site.
 *
 * Keyboard users had almost nothing to go on. Roughly twenty rules across the
 * app answered `:focus` with `outline: none` and a 1px border colour change,
 * which is invisible on a dark border and gone entirely for anyone who cannot
 * distinguish the accent from the surrounding grey. This restores a real ring.
 *
 * Three things it deliberately does:
 *
 *   - `outline` rather than `border` or padding, so nothing moves when focus
 *     lands. A ring that shifts the layout makes the page jump as you tab.
 *   - `:focus-visible`, not `:focus`, so the ring appears for keyboard and
 *     assistive use and not on a mouse click. That was the reasonable instinct
 *     behind those `outline: none` rules; this keeps it without the cost.
 *   - `!important`, which is not something to reach for lightly. Those twenty
 *     rules are spread over pages that each carry their own stylesheet, and
 *     several of them are more specific than anything that can be written here.
 *     The alternative was editing every one and hoping the next page added does
 *     not repeat the pattern.
 *
 * Colour: the accent, which sits at roughly 8:1 on the site's white and cream.
 * The offset puts the ring outside the element, so on a maroon button the ring
 * is drawn against the page behind it rather than against the button. On the
 * genuinely dark surfaces, the breadcrumb bar and the footer, the ring is
 * switched to white by redefining one variable.
 *
 * Included from site_head.php, and directly by the pages that do not use it.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
:root {
    --focus-ring: var(--maroon, #820707);
    /* The edge of a text field is the only thing saying it is a field, so it
       is held to 3:1 against the page (WCAG 1.4.11). The site's ordinary
       --border sits at 1.43:1, which is right for a divider between two rows
       and not enough for the outline of a control. Dividers keep it. */
    --border-control: #A88A8A;
    /* The scrollbar's thumb: the maroon the buttons and the crumb bar are
       drawn in, rather than the soft accent it used to be, which reads as
       gold on the cream track. */
    --scroll-thumb:        var(--maroon-surface, #820707);
    --scroll-thumb-strong: var(--maroon-surface-hover, #630000);
}
html[data-color="classic"]   { --border-control: #A68C86; }   /* 3.13:1 on white */
/* Old Night's surface is a warm espresso, so the outline is a muted gold
   rather than the grey-blue the old blue-black dark used. */
html[data-mode="dark"]       { --border-control: #8A7F7C; }   /* 4.52:1 on #1E1813 */
/* A dark maroon thumb on Old Night's near-black page would be barely there,
   so the dark palette keeps its own accent: muted gold, brightening as the
   thumb is pointed at or dragged. */
html[data-mode="dark"] {
    --scroll-thumb:        var(--accent-soft, #8A7348);
    --scroll-thumb-strong: var(--accent-light, #D8B472);
}

/* Applied narrowly: the things you type into or choose from. */
input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
select,
textarea {
    border-color: var(--border-control, #A88A8A);
}

a:focus-visible,
button:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
summary:focus-visible,
[tabindex]:focus-visible,
[role="button"]:focus-visible,
[role="tab"]:focus-visible {
    outline: 2px solid var(--focus-ring) !important;
    outline-offset: 2px !important;
}

/* The things you type into are sealed by their own edge rather than ringed.
   An outline sits outside the border, so a focused field showed two lines
   with a sliver of page between them; what is wanted is one line all the way
   round. So the outline goes and the field's own border becomes the mark,
   taking the focus colour at the weight it already has. The halo some pages
   add on focus goes with it, for the same reason: it is a second edge.

   Only the colour is set, never the width. A field with no border of its own
   is a field inside something else that is already drawing one: the search
   box is an input with border:none sitting in a bordered shell. Giving that
   input an edge put a second rectangle inside the first. Colouring an edge
   that is not there paints nothing, which is exactly right.

   Checkboxes and radios keep the full ring. They are small, they have no
   border of their own to colour, and the outline is the only mark focus
   makes on them. */
input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):focus-visible,
select:focus-visible,
textarea:focus-visible,
/* A skinned dropdown is a button standing in for a select (select_skin.php),
   and it sits in the same row as the fields it is drawn to match. */
.sel-btn:focus-visible {
    outline: none !important;
    border-color: var(--focus-ring) !important;
    box-shadow: none !important;
}

/* Bootstrap repaints a focused field from its own --bs-body-bg, a white the
   theme never swaps: in Old Night every box on the site turned white the
   moment it was clicked into and dark again when it was left. Pointed back at
   the tokens the field already carries unfocused, so focus changes the edge
   and nothing else. The specificity is there to outrank .form-control:focus
   whichever order the two stylesheets happen to load in. */
:root .form-control:focus,
:root .form-select:focus {
    background-color: var(--white, #FFFFFF);
    color: var(--ink, #2B1B0E);
}

/* A mouse click should not leave a ring behind. */
:focus:not(:focus-visible) { outline: none; }

/* Dark surfaces: same ring, opposite colour, so it is visible on both. */
.crumb-bar,
.site-footer,
.login-panel-head,
[data-mode="dark"] { --focus-ring: #FFFFFF; }

/* Inside a maroon control the ring would otherwise be drawn accent-on-accent
   where the offset overlaps the element's own shadow. */
.btn-sm-maroon:focus-visible,
.mgmt-submit:focus-visible,
.ad-btn-go:focus-visible {
    outline-color: var(--dark-maroon, #5C0505) !important;
    outline-offset: 3px !important;
}

/* ---- The scrollbar -------------------------------------------------------
   Same language the paper list already used: a maroon thumb on the cream
   tint. Applied to every scrolling element rather than the window alone, so
   the roll, the notification list and the review desk all match.

   Firefox takes the two-property form; the ::-webkit rules cover Chrome and
   Edge. Chrome understands both now, and where it does, `scrollbar-color`
   wins, which is why the widths agree.

   The stepper arrows go. They are Windows chrome, they are not drawn on any
   other platform this site is read on, and each one is a 17px grey square in
   the middle of the accent. */
* {
    scrollbar-width: thin;
    scrollbar-color: var(--scroll-thumb) var(--cream, #FFF5F5);
}

::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-track {
    background: var(--cream, #FFF5F5);
    border-left: 1px solid var(--border, #E6D4D4);
}
::-webkit-scrollbar-thumb {
    background: var(--scroll-thumb);
    border-radius: var(--r-control, 4px);
    /* A little of the track shows through around the thumb, so it reads as
       sitting in the groove rather than filling it. */
    border: 2px solid var(--cream, #FFF5F5);
    background-clip: padding-box;
}
::-webkit-scrollbar-thumb:hover,
::-webkit-scrollbar-thumb:active { background: var(--scroll-thumb-strong); }
::-webkit-scrollbar-button { display: none; height: 0; width: 0; }
::-webkit-scrollbar-corner { background: var(--cream, #FFF5F5); }
</style>
