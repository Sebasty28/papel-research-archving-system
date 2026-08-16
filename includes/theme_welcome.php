<?php
/**
 * The one-time question: which colour, and light or dark.
 *
 * Shown on a reader's first visit only. Everything already works without it —
 * PAPEL opens in maroon and light — so this is an offer, not a gate: dismissing
 * it keeps those defaults and it never asks again. That is why it is a small
 * card rather than a modal that blocks the page.
 *
 * The choice is stored under the same keys as Quick Settings and the settings
 * page, so all three are the same setting seen from different places.
 *
 * Include once before the footer, on pages that already load the theme layer.
 */
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.tw-card {
    position: fixed;
    right: 1.5rem;
    bottom: 1.5rem;
    z-index: 960;
    width: min(21rem, calc(100vw - 3rem));
    padding: 1.125rem 1.25rem;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--white);
    box-shadow: 0 12px 32px rgba(0, 0, 0, .18);
    display: none;
}
.tw-card.is-open { display: block; }
.tw-card h2 {
    font-family: var(--font-head); font-size: .9375rem; font-weight: 600;
    color: var(--maroon); margin: 0 0 .25rem;
}
.tw-card p { font-size: .75rem; color: var(--grey); line-height: 1.6; margin: 0 0 .875rem; }
.tw-label {
    display: block; font-size: .6875rem; text-transform: uppercase;
    letter-spacing: .04em; color: var(--grey); margin: 0 0 .4rem;
}
.tw-swatches { display: flex; gap: .5rem; margin-bottom: .875rem; flex-wrap: wrap; }
.tw-swatch {
    width: 2rem; height: 2rem; border-radius: 50%;
    border: 2px solid var(--border); cursor: pointer; padding: 0;
    transition: transform .12s, border-color .12s;
}
.tw-swatch:hover { transform: scale(1.08); }
.tw-swatch.is-on { border-color: var(--ink); transform: scale(1.08); }
.tw-modes { display: flex; gap: .4rem; margin-bottom: 1rem; }
.tw-mode {
    flex: 1 1 0; padding: .45rem; border: 1px solid var(--border); border-radius: 8px;
    background: var(--white); color: var(--ink); font-family: inherit; font-size: .75rem;
    cursor: pointer;
}
.tw-mode.is-on { border-color: var(--maroon); color: var(--maroon); background: var(--cream); }
.tw-foot { display: flex; gap: .5rem; justify-content: flex-end; }

@media (max-width: 600px) {
    .tw-card { right: 1rem; left: 1rem; bottom: 1rem; width: auto; }
}
</style>

<div class="tw-card" id="themeWelcome" role="dialog" aria-labelledby="twTitle" aria-modal="false">
    <h2 id="twTitle">Make it yours</h2>
    <p>Pick a colour and a theme. You can change these any time from Quick&nbsp;Settings.</p>

    <span class="tw-label">Colour</span>
    <div class="tw-swatches" id="twSwatches"></div>

    <span class="tw-label">Theme</span>
    <div class="tw-modes">
        <button type="button" class="tw-mode" data-mode="light">Light</button>
        <button type="button" class="tw-mode" data-mode="dark">Dark</button>
        <button type="button" class="tw-mode" data-mode="system">System</button>
    </div>

    <div class="tw-foot">
        <button type="button" class="btn-sm-outline" id="twSkip">Keep defaults</button>
        <button type="button" class="btn-sm-maroon" id="twSave">Save</button>
    </div>
</div>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    var card = document.getElementById('themeWelcome');
    if (!card) return;

    var KEY = 'papel_theme_asked';
    var get = function (k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };
    var set = function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} };

    // Asked once. Anyone who has already chosen is not a first-timer either.
    if (get(KEY, '') || get('papel_color', '') || get('papel_theme', '')) return;

    var COLOURS = [
        ['maroon', '#820707', 'PUP Maroon'],
        ['lightblue', '#2E86AB', 'PUP Light Blue'],
        ['blue', '#14487F', 'PUP Blue'],
        ['white', '#3B3B3B', 'PUP White Modern'],
        ['classic', '#6B0F0F', 'PUP Old Classic']
    ];
    var chosenColour = 'maroon';
    var chosenTheme  = 'light';

    var host = document.getElementById('twSwatches');
    COLOURS.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'tw-swatch' + (c[0] === chosenColour ? ' is-on' : '');
        b.style.background = c[1];
        b.title = c[2];
        b.setAttribute('aria-label', c[2]);
        b.dataset.colour = c[0];
        b.addEventListener('click', function () {
            chosenColour = c[0];
            host.querySelectorAll('.tw-swatch').forEach(function (s) { s.classList.remove('is-on'); });
            b.classList.add('is-on');
            // Show it straight away — choosing blind is not much of a choice.
            document.documentElement.setAttribute('data-color', chosenColour);
        });
        host.appendChild(b);
    });

    function resolveMode(t) {
        if (t === 'dark' || t === 'light') return t;
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark' : 'light';
    }

    var modes = card.querySelectorAll('.tw-mode');
    modes.forEach(function (m) {
        if (m.dataset.mode === chosenTheme) m.classList.add('is-on');
        m.addEventListener('click', function () {
            chosenTheme = m.dataset.mode;
            modes.forEach(function (x) { x.classList.remove('is-on'); });
            m.classList.add('is-on');
            document.documentElement.setAttribute('data-theme', chosenTheme);
            document.documentElement.setAttribute('data-mode', resolveMode(chosenTheme));
        });
    });

    function close() {
        card.classList.remove('is-open');
        set(KEY, '1');
    }

    document.getElementById('twSave').addEventListener('click', function () {
        set('papel_color', chosenColour);
        set('papel_theme', chosenTheme);
        if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
        close();
    });

    /* Keeping the defaults still counts as an answer — the preview is undone
       so the page ends up as maroon and light, which is what was offered. */
    document.getElementById('twSkip').addEventListener('click', function () {
        document.documentElement.setAttribute('data-color', 'maroon');
        document.documentElement.setAttribute('data-theme', 'light');
        document.documentElement.setAttribute('data-mode', 'light');
        close();
    });

    card.classList.add('is-open');
});
</script>
