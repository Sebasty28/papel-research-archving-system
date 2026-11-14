<?php
/**
 * Zen Mode: the site with its furniture put away.
 *
 * One switch, in the profile menu, that quiets everything on the page which is
 * not the page: the breadcrumb strip, the footer, the photo strip behind the
 * navbar, the accessibility tab, the scroll-to-top button, Corpus's floating
 * button and his greeting, the "Make it yours" card, the repository's photo
 * banner, and the console side panel, which folds to its rail rather than
 * going, so the filters are still a press away.
 *
 * What stays is deliberate. **The navbar stays**, because the switch that
 * turns this off lives in it: a mode with no way out is a trap, and a mode
 * whose way out is a keystroke nobody was told about is the same trap with a
 * secret. The notification bell, the manuscript countdown, the flash messages
 * and the loading bar stay too; a grant running out and a paper being returned
 * are news, not furniture.
 *
 * It is kept **on the device** (localStorage, `papel_zen`), like the palette,
 * the sidebar's side and the accessibility tab, and read here before the first
 * paint. Read any later and the page would draw its furniture and take it away
 * again, which is the flash the whole pre-paint block in site_head.php exists
 * to prevent. Off is the default, so the only answer worth storing is '1': a
 * browser nobody has asked, and one with storage blocked, both start with the
 * ordinary site.
 *
 * Required from includes/site_head.php, so every page that has a header has
 * this. A page with no furniture simply changes less.
 *
 * Three things to know before changing it:
 *
 * - **The class goes on `<html>`, and `sidebar-folded` goes with it.** Folding
 *   the side panel is a thing the site already does, with its own rail, its own
 *   buttons and its own shape in the loading skeleton. Zen borrows that class
 *   rather than writing a second set of rules for the same picture, and it
 *   never writes `papel_sidebar_folded`: the reader's own answer to that
 *   question is waiting for them when Zen goes off. `zen-on` matches nothing
 *   else in the tree; it was checked, for the reason `.modal-backdrop` is
 *   written down in CLAUDE.md.
 * - **Anything measured off a bar has to be told.** The strip is hidden, so
 *   `--crumb-h`, the repository's `--hero-nav` and the side panel's rail all
 *   have to be read again. Switching is instant and nothing reloads, so this
 *   raises `papel:zen` on the document and those three listen for it. A
 *   ResizeObserver does see an element go to `display: none`, but relying on
 *   that would leave the reason invisible at the places that depend on it.
 * - **The switch is printed twice**, by `zen_switch()`: once in the avatar
 *   dropdown and once in the phone sheet, exactly as the dark-mode button is.
 *   Change both or neither. Both are wired by delegation, so the one in
 *   Settings needs no script of its own either.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Before the first paint, with the sidebar's own pre-paint block: a mode
   restored late is a page that rearranges itself in front of the reader. */
(function () {
    var on = false;
    try { on = localStorage.getItem('papel_zen') === '1'; } catch (e) {}
    if (!on) { return; }
    var el = document.documentElement;
    el.classList.add('zen-on');
    /* The side panel folds in Zen. The class is the site's own, so the rail,
       the results column's width and the loading skeleton's shape all follow
       with nothing written here; the stored answer is left alone. */
    el.classList.add('sidebar-folded');
})();
</script>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ===== What Zen puts away =====
   One list, so there is one place to look for "why is that gone". Each is the
   element itself rather than a wrapper, and all of them are display: none
   rather than hidden, so everything that measures a bar reads 0 instead of a
   height nothing occupies. */
html.zen-on .crumb-bar,
html.zen-on .nav-photo,
html.zen-on .site-footer,
html.zen-on #a11y-toggle,
html.zen-on #scrollJump,
html.zen-on #chat-button,
html.zen-on #chat-nudge,
html.zen-on #themeWelcome {
    display: none;
}

/* ===== The switch itself =====
   A row in the profile menu, painted as the rows around it: the menu's own
   rules give it its type, padding and hover, and only the track and the knob
   are drawn here. The track is pushed to the far end of the row, where a
   switch is read for, and the row is the whole control. */
.zen-row { justify-content: flex-start; }
.zen-switch {
    /* One track, two sizes: the menus' own, and Settings', where it stands
       among that page's 42x24 switches and would read as a smaller, different
       control. The knob's travel is worked out from them, so a third size
       would need no further arithmetic. */
    --zen-w: 34px;
    --zen-h: 18px;
    --zen-k: 14px;
    position: relative;
    flex: 0 0 auto;
    margin-left: auto;
    width: var(--zen-w);
    height: var(--zen-h);
    border-radius: 999px;
    /* Mixed from the ink rather than a fixed grey, so the off state follows
       the palette into the dark one, as the skeleton's blocks do. */
    background: color-mix(in srgb, var(--ink) 24%, transparent);
    transition: background .18s ease;
}
.zen-knob {
    position: absolute;
    top: 2px;
    left: 2px;
    width: var(--zen-k);
    height: var(--zen-k);
    border-radius: 50%;
    background: var(--white);
    box-shadow: var(--shadow-sm);
    transition: transform .18s ease;
}
/* --maroon-surface, never --maroon: under Old Night --maroon is the accent
   light, which is plain white, and a white knob on a white track is a switch
   with no state at all. See "--maroon is the mark, --maroon-surface is the
   fill" in CLAUDE.md. */
[data-zen-toggle][aria-checked="true"] .zen-switch { background: var(--maroon-surface); }
[data-zen-toggle][aria-checked="true"] .zen-knob {
    transform: translateX(calc(var(--zen-w) - var(--zen-k) - 4px));
}
@media (prefers-reduced-motion: reduce) {
    .zen-switch, .zen-knob { transition: none; }
}
/* On its own, in Settings: the row beside it already says "Zen Mode" and what
   it does, so the control is the switch and nothing else, at that page's own
   size. Nothing of the menus' row styling reaches here, so the button has to
   give up the browser's own. */
button.zen-row-bare {
    display: inline-flex;
    align-items: center;
    padding: 0;
    background: none;
    border: 0;
    cursor: pointer;
}
button.zen-row-bare .zen-switch { --zen-w: 42px; --zen-h: 24px; --zen-k: 20px; margin-left: 0; }
button.zen-row-bare:focus-visible {
    outline: 2px solid var(--maroon-surface);
    outline-offset: 3px;
    border-radius: 999px;
}

/* Nothing is widened and no type is changed: this is a quieting, not a reading
   mode. The room the strip and the footer leave is the only change, and the
   page takes it by itself. */
</style>
<?php
/**
 * The switch, for the two profile menus and for Settings.
 *
 * `$where` only picks the clothes: 'sheet' is the phone menu, whose rows wrap
 * their glyph and word in .nav-action-content so a rule can be drawn under
 * them, and 'dropdown' is the avatar menu, whose rows are flat. The control,
 * its state and its wiring are the same in both, and in Settings, which asks
 * for 'row'.
 */
function zen_switch(string $where = 'dropdown'): void
{
    $label = 'Zen Mode';
    $icon  = '<span class="material-symbols-outlined mi-20" aria-hidden="true">self_improvement</span>';
    $track = '<span class="zen-switch" aria-hidden="true"><span class="zen-knob"></span></span>';

    /* role="switch" with aria-checked, not a checkbox: the control is a button
       in a menu of buttons, and the state belongs on it rather than on an
       input nobody can see. The script below keeps aria-checked in step, and
       it is what the stylesheet above draws from, so the two cannot disagree. */
    $cls = 'zen-row' . ($where === 'row' ? ' zen-row-bare' : '');
    echo '<button type="button" data-zen-toggle role="switch" aria-checked="false" class="' . $cls . '">';
    if ($where === 'sheet') {
        echo '<span class="nav-action-content">' . $icon .
             '<span class="nav-action-text">' . $label . '</span></span>';
    } elseif ($where === 'dropdown') {
        echo $icon . '<span>' . $label . '</span>';
    }
    /* 'row' is the switch alone: Settings prints the name and the sentence
       beside it, and a glyph and the words again inside the control would be
       the same thing said three times. */
    echo $track;
    echo '</button>';
}
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* The switch, and the one place the mode is turned on and off.

   Delegated on the document, so a control printed anywhere (either menu,
   Settings, a page that grows one later) works with no wiring of its own, and
   so a menu rebuilt in script does not lose it. */
(function () {
    var KEY  = 'papel_zen';
    var FOLD = 'papel_sidebar_folded';
    var el   = document.documentElement;

    function on() { return el.classList.contains('zen-on'); }

    function sync() {
        var state = on() ? 'true' : 'false';
        var all = document.querySelectorAll('[data-zen-toggle]');
        for (var i = 0; i < all.length; i++) {
            all[i].setAttribute('aria-checked', state);
            /* Named by what it is, not by what pressing it will do: a switch
               says its own state, unlike the eye on the banner, which is a
               button and says the action. */
            all[i].setAttribute('aria-label', 'Zen Mode');
        }
    }

    function apply(want) {
        if (want === on()) { return; }
        el.classList.toggle('zen-on', want);
        /* Off again, the reader's own answer about the side panel comes back.
           Zen never wrote it, so this is the only thing that has to remember
           the panel was not folded by them. */
        var folded = want;
        if (!want) {
            try { folded = localStorage.getItem(FOLD) === '1'; } catch (e) { folded = false; }
        }
        el.classList.toggle('sidebar-folded', folded);
        try { localStorage.setItem(KEY, want ? '1' : '0'); } catch (e) {}
        sync();
        /* Everything that measured a bar reads it again: --crumb-h in
           site_header.php, the repository's banner, and the side panel's rail.
           The mode changes with no reload, so nothing may be left holding a
           figure it took while the strip was still there. */
        document.dispatchEvent(new CustomEvent('papel:zen', { detail: { on: want } }));
    }

    /* In the **capture** phase, which is the whole reason this works at all:
       the avatar dropdown stops every click inside itself from bubbling (it is
       how pressing the menu does not close the menu), so a listener on the
       document in the ordinary phase never hears the switch being pressed and
       the control does nothing at all. The same trap the scroll listeners on
       the upload page are written down for, from the other side. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-zen-toggle]') : null;
        if (!btn) { return; }
        e.preventDefault();
        /* The menu stays open. Pressing a switch is not leaving the menu, and
           a menu that shuts itself gives nobody the chance to see what the
           switch did. */
        e.stopPropagation();
        apply(!on());
    }, true);

    /* Published for the pages that have their own reason to ask, and so the
       state can be driven in a check without pressing anything. */
    window.papelZen = {
        on: function () { return on(); },
        set: apply,
        toggle: function () { apply(!on()); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sync);
    } else {
        sync();
    }
})();
</script>
