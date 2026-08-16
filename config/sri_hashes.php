<?php
// config/sri_hashes.php

/**
 * Sub Resource Integrity (SRI) Hashes for external CDNs (ID-004)
 */

define('SRI_BOOTSTRAP_CSS', 'sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN');
define('SRI_BOOTSTRAP_JS', 'sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL');

// Chart.js 4.4.0
define('SRI_CHART_JS', 'sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g');

// Bootstrap Icons 1.11.1
define('SRI_BOOTSTRAP_ICONS', 'sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/Luz0x+O0E7kE2Eir3D');

// Note: Google Fonts and unpkg.com (@phosphor-icons) generate dynamic content based on browser,
// so they do not support static SRI hashes reliably. We secure them via CSP instead.
