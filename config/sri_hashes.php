<?php
// config/sri_hashes.php

/**
 * Sub Resource Integrity (SRI) Hashes for external CDNs (ID-004)
 */

define('SRI_BOOTSTRAP_CSS', 'sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN');
define('SRI_BOOTSTRAP_JS', 'sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL');

// Chart.js 4.4.0
define('SRI_CHART_JS', 'sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g');

/* Bootstrap Icons 1.11.1.
   This was wrong until 24 November 2026, and nothing had noticed because
   nothing required this file: the four pages loading the stylesheet each
   wrote their own <link> with no integrity attribute, which is what an
   active ZAP scan of localhost reported. A wrong hash is worse than none,
   since the browser refuses the file and the icons vanish with no error a
   reader can see, so compute it from the bytes the CDN really serves:
     curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
   and check every hash here the same way before trusting it. */
define('SRI_BOOTSTRAP_ICONS', 'sha384-fFxL8wXRpg9gVqGpY+URMtLr3fLL0WBbo4NBQ+IWwMDYjIjI5VQ46XlJm5+BUlXJ');

// Note: Google Fonts and unpkg.com (@phosphor-icons) generate dynamic content based on browser,
// so they do not support static SRI hashes reliably. We secure them via CSP instead.
