<!-- Accessibility Widget -->
<?php
/* Signed-in readers can restore the tab in Settings. Public readers use the
   footer's restore button, so their hide choice can persist too. */
$a11ySettingsUrl = defined('BASE_URL') ? BASE_URL . '/pages/settings.php#appearance' : '';
$a11yCanRestore  = $a11ySettingsUrl !== '' && function_exists('current_user') && current_user();
?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-accessibility.css')) ?>">

<?php /* Read before the tab below is parsed, so a hidden tab is never drawn
         for a frame first and then pulled away. The footer offers a restore
         control on public pages as well as signed-in ones. */ ?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
try {
    if (localStorage.getItem('papel_a11y_tab_hidden') === '1') {
        document.documentElement.classList.add('a11y-tab-hidden');
    }
    /* The phone's switch is separate: with nothing stored, the stylesheet
       hides the tab at that width. Both switches are read for every visitor;
       the footer can restore a hidden desktop tab without Settings. */
    if (localStorage.getItem('papel_a11y_tab_phone') === '1') {
        document.documentElement.classList.add('a11y-tab-phone');
    }
} catch (e) {}
</script>

<div id="a11y-widget">
    <div id="a11y-menu" role="dialog" aria-label="Accessibility options">
        <div class="a11y-header">
            <h3>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px;" aria-hidden="true">
                    <circle cx="12" cy="4.4" r="1.9" fill="currentColor" stroke="none"/>
                    <path d="M4.8 8h14.4"/>
                    <path d="M12 8v6"/>
                    <path d="M12 14l-3.3 6"/>
                    <path d="M12 14l3.3 6"/>
                </svg>Accessibility
            </h3>
            <div class="a11y-header-actions">
                <button id="a11y-reset" title="Reset all settings">Reset</button>
                <button id="a11y-close" title="Close" aria-label="Close">&#215;</button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Text Size</div>
            <div class="a11y-font-control">
                <button class="a11y-font-btn" id="a11y-font-dec" aria-label="Decrease font size">&#8722;</button>
                <span id="a11y-font-val">100%</span>
                <button class="a11y-font-btn" id="a11y-font-inc" aria-label="Increase font size">&#43;</button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Content</div>
            <div class="a11y-grid">
                <button class="a11y-btn" data-cls="a11y-highlight-titles">
                    <span class="a11y-icon">T&#772;</span>Highlight Headings
                </button>
                <button class="a11y-btn" data-cls="a11y-highlight-links">
                    <span class="a11y-icon">&#128279;</span>Highlight Links
                </button>
                <button class="a11y-btn" data-cls="a11y-dyslexia-font">
                    <span class="a11y-icon" style="font-family:'Comic Sans MS',cursive">A</span>Dyslexia Font
                </button>
                <button class="a11y-btn" data-cls="a11y-letter-spacing">
                    <span class="a11y-icon">A&#8239;B</span>Letter Spacing
                </button>
                <button class="a11y-btn" data-cls="a11y-line-height">
                    <span class="a11y-icon">&#8597;</span>Line Height
                </button>
                <button class="a11y-btn" data-cls="a11y-font-weight">
                    <span class="a11y-icon"><b>B</b></span>Bold Text
                </button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Color &amp; Contrast</div>
            <div class="a11y-grid">
                <?php /* One at a time. These six are six ways of repainting the same
                         page and they were independent switches, so Dark Contrast and
                         Monochrome and Vivid Colors could all be on at once: three
                         filters multiplied together, a page nobody designed, and no way
                         to tell which of the three was the one making it unreadable.
                         data-group makes them a radio set in the handler below; pressing
                         the one that is on still turns it off, so "none of them" is
                         still reachable. */ ?>
                <button class="a11y-btn" data-group="color" data-cls="a11y-dark-contrast">
                    <span class="a11y-icon">&#9899;</span>Dark Contrast
                </button>
                <button class="a11y-btn" data-group="color" data-cls="a11y-light-contrast">
                    <span class="a11y-icon">&#9898;</span>Light Contrast
                </button>
                <button class="a11y-btn" data-group="color" data-cls="a11y-high-contrast">
                    <span class="a11y-icon">&#9680;</span>High Contrast
                </button>
                <button class="a11y-btn" data-group="color" data-cls="a11y-high-saturation">
                    <span class="a11y-icon">&#127752;</span>Vivid Colors
                </button>
                <button class="a11y-btn" data-group="color" data-cls="a11y-low-saturation">
                    <span class="a11y-icon">&#127787;</span>Muted Colors
                </button>
                <button class="a11y-btn" data-group="color" data-cls="a11y-monochrome">
                    <span class="a11y-icon">&#9636;</span>Monochrome
                </button>
            </div>
        </div>

        <div class="a11y-section">
            <div class="a11y-section-title">Tools</div>
            <div class="a11y-grid">
                <button class="a11y-btn" id="a11y-reading-guide-btn">
                    <span class="a11y-icon">&#8213;</span>Reading Guide
                </button>
                <button class="a11y-btn" data-cls="a11y-stop-animations">
                    <span class="a11y-icon">&#9208;</span>Stop Animations
                </button>
                <button class="a11y-btn" data-cls="a11y-big-cursor">
                    <span class="a11y-icon">&#8598;</span>Large Cursor
                </button>
            </div>
        </div>
    </div>

    <button id="a11y-toggle" aria-label="Open accessibility menu" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="4.4" r="1.9" fill="currentColor" stroke="none"/>
            <path d="M4.8 8h14.4"/>
            <path d="M12 8v6"/>
            <path d="M12 14l-3.3 6"/>
            <path d="M12 14l3.3 6"/>
        </svg>
        <span class="a11y-tab-label">Accessibility</span>
    </button>
</div>
<div id="a11y-reading-guide"></div>

<script src="<?= e(asset_url('assests/js/papel-accessibility.js')) ?>" data-can-restore="<?= $a11yCanRestore ? '1' : '0' ?>" data-settings-url="<?= e($a11ySettingsUrl) ?>"></script>

<!-- ===== Global submit-button loading state ===== -->
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
.is-submitting { position: relative; pointer-events: none; opacity: .85; cursor: progress !important; }
.btn-load-spinner {
    display: inline-block;
    width: 1em; height: 1em;
    margin-right: .5em;
    vertical-align: -.15em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: btnLoadSpin .6s linear infinite;
}
@keyframes btnLoadSpin { to { transform: rotate(360deg); } }
</style>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var LOADING_LABEL = 'Processing…';

    function startLoading(btn) {
        if (!btn || btn.dataset.loading === '1') return;
        btn.dataset.loading = '1';
        btn.classList.add('is-submitting');

        if (btn.tagName === 'BUTTON') {
            btn.dataset.originalHtml = btn.innerHTML;
            var label = btn.getAttribute('data-loading-text') || LOADING_LABEL;
            btn.innerHTML = '<span class="btn-load-spinner"></span>' + label;
        } else { // input[type=submit|button|image]
            btn.dataset.originalValue = btn.value;
            btn.value = btn.getAttribute('data-loading-text') || LOADING_LABEL;
        }
        // Keep it visually disabled but still let the browser submit the form.
        btn.setAttribute('aria-busy', 'true');
    }

    function resetLoading(btn) {
        if (!btn || btn.dataset.loading !== '1') return;
        if (btn.tagName === 'BUTTON' && btn.dataset.originalHtml !== undefined) {
            btn.innerHTML = btn.dataset.originalHtml;
        } else if (btn.dataset.originalValue !== undefined) {
            btn.value = btn.dataset.originalValue;
        }
        btn.classList.remove('is-submitting');
        btn.removeAttribute('aria-busy');
        delete btn.dataset.loading;
    }

    // Fire on real form submissions (after the browser passes HTML5 validation).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.hasAttribute('data-no-loading')) return;

        var btn = e.submitter ||
                  form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
        if (!btn || btn.hasAttribute('data-no-loading')) return;

        // Defer one tick so we can detect if a validator / AJAX handler cancelled the submit.
        setTimeout(function () {
            if (e.defaultPrevented) return;   // navigation was stopped: don't get stuck spinning
            startLoading(btn);
        }, 0);
    }, false);

    // Restore buttons when returning via browser back/forward (bfcache).
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            document.querySelectorAll('[data-loading="1"]').forEach(resetLoading);
        }
    });
}());
</script>
