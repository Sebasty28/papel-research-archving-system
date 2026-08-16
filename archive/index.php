<?php
require_once __DIR__.'/../config/core.php';
require_once __DIR__.'/../config/gdrive_config.php';
start_session_once();
$u = current_user();

// AJAX search suggestions
if (isset($_GET['ajax_search'])) {
    ob_clean();
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    try {
        $conn = db();
        $term = "%$q%";
        $suggestions = [];
        $stmt = $conn->prepare("SELECT DISTINCT title FROM research_papers WHERE current_status = 'approved' AND title LIKE ? LIMIT 5");
        if ($stmt) {
            $stmt->bind_param('s', $term);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $suggestions[] = $row['title'];
        }
        if (count($suggestions) < 7) {
            $stmt2 = $conn->prepare("SELECT keywords FROM research_papers WHERE current_status = 'approved' AND keywords LIKE ? LIMIT 10");
            if ($stmt2) {
                $stmt2->bind_param('s', $term);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                while ($row = $res2->fetch_assoc()) {
                    if (empty($row['keywords'])) continue;
                    foreach (explode(',', $row['keywords']) as $kw) {
                        $kw = trim($kw);
                        if (stripos($kw, $q) !== false && !in_array($kw, $suggestions) && count($suggestions) < 8)
                            $suggestions[] = $kw;
                    }
                }
            }
        }
        echo json_encode(array_values(array_unique($suggestions)));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// Guest expiry
if (isset($_SESSION['guest_login']) && isset($_SESSION['guest_expire'])) {
    if (time() > $_SESSION['guest_expire']) {
        header('Location: ../logout.php?from=archive&expired=1');
        exit;
    }
}

$conn = db();
$search       = trim($_GET['q'] ?? '');
$filter_year  = (int)($_GET['year'] ?? 0);
$filter_type  = trim($_GET['type'] ?? '');
$filter_program = trim($_GET['program'] ?? '');
$filter_month = (int)($_GET['month'] ?? 0);
$filter_day   = (int)($_GET['day'] ?? 0);
// Whitelisted so it can be interpolated into ORDER BY safely
$sort_dir     = (($_GET['sort'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$sort_param   = $sort_dir === 'ASC' ? 'asc' : 'desc';
// Home layout disabled — browse view is the permanent landing page
$is_searching = true;

// Papers are only openable by signed-in users (or an active guest session)
$can_view = $u || isset($_SESSION['guest_login']);
// Signed-in members get the full browse console (toolbar + extended filters)
$is_member = (bool)$u;

// Pagination — 10 results per page for everyone
$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Login modal state (re-open after error redirect)
$open_modal = isset($_GET['login_modal']);
$modal_role = in_array($_GET['role'] ?? '', ['student', 'faculty', 'guest']) ? $_GET['role'] : 'student';

// --- Build WHERE clause ---
$base_where  = "current_status = 'approved'";
$where_extra = '';
$params      = [];
$types       = '';

if ($search) {
    $term = "%$search%";
    $where_extra .= " AND (title LIKE ? OR keywords LIKE ? OR abstract LIKE ?)";
    $params[] = $term; $params[] = $term; $params[] = $term;
    $types .= 'sss';
}
if ($filter_year > 0) {
    $where_extra .= " AND COALESCE(YEAR(research_date), year) = ?";
    $params[] = $filter_year;
    $types .= 'i';
}
if ($filter_type) {
    $where_extra .= " AND paper_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}
// Month/day narrow the research date (the date shown on each result row)
if ($filter_month >= 1 && $filter_month <= 12) {
    $where_extra .= " AND MONTH(research_date) = ?";
    $params[] = $filter_month;
    $types .= 'i';
}
if ($filter_day >= 1 && $filter_day <= 31) {
    $where_extra .= " AND DAY(research_date) = ?";
    $params[] = $filter_day;
    $types .= 'i';
}
if ($filter_program === 'Uncategorized') {
    $where_extra .= " AND (program_category IS NULL OR program_category = '')";
} elseif ($filter_program) {
    $where_extra .= " AND program_category = ?";
    $params[] = $filter_program;
    $types .= 's';
}

$full_where = $base_where . $where_extra;

// Count total
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM research_papers WHERE $full_where");
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_papers = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_papers / $per_page));

// Main query
$main_sql = "SELECT paper_id, title, year, research_date, abstract, keywords, author_names, upload_date, paper_type, program_category, publication_status
             FROM research_papers WHERE $full_where
             ORDER BY COALESCE(research_date, MAKEDATE(year, 1)) $sort_dir LIMIT ? OFFSET ?";
try {
    $stmt = $conn->prepare($main_sql);
} catch (mysqli_sql_exception $e) {
    if (strpos($e->getMessage(), "Unknown column 'program_category'") !== false) {
        echo '<div style="padding:2rem;text-align:center;font-family:sans-serif;"><h2>System Update Required</h2><p>Please contact your administrator.</p></div>';
        exit;
    }
    throw $e;
}
$fetch_params = array_merge($params, [$per_page, $offset]);
$fetch_types  = $types . 'ii';
if (!empty($fetch_params)) $stmt->bind_param($fetch_types, ...$fetch_params);
$stmt->execute();
$result = $stmt->get_result();

// Sidebar filter data
$years_res    = $conn->query("SELECT DISTINCT COALESCE(YEAR(research_date), year) AS year FROM research_papers WHERE $base_where ORDER BY year DESC");
$types_res    = $conn->query("SELECT DISTINCT paper_type FROM research_papers WHERE $base_where AND paper_type IS NOT NULL AND paper_type != '' ORDER BY paper_type ASC");

// Human-readable labels for paper types
$type_labels = [
    'research'   => 'Research Paper',
    'capstone'   => 'Capstone Project',
    'thesis'     => 'Thesis',
    'conference' => 'Conference Paper',
    'journal'    => 'Journal Article',
    'article'    => 'Article',
    'project'    => 'Project',
];

$program_options = [
    'Bachelor of Science in Information Technology'              => 'BS Information Technology',
    'Bachelor of Science in Industrial Engineering'             => 'BS Industrial Engineering',
    'Bachelor of Science in Computer Engineering'               => 'BS Computer Engineering',
    'Bachelor of Secondary Education major in English'          => 'BSEd English',
    'Bachelor of Secondary Education major in Social Studies'   => 'BSEd Social Studies',
    'Bachelor of Elementary Education'                          => 'BEEd',
    'Bachelor of Science in Psychology'                         => 'BS Psychology',
    'Diploma in Information Technology'                         => 'Diploma IT',
    'Diploma in Computer Engineering Technology'                => 'Diploma Computer Engineering',
    'Bachelor of Science in Business Administration major in Human Resource Management' => 'BSBA HRM',
    'Faculty Member'                                            => 'Faculty Member',
    'Other'                                                     => 'Others',
];
$active_programs = $program_options;
$used_res = $conn->query("SELECT DISTINCT program_category FROM research_papers WHERE $base_where AND program_category IS NOT NULL AND program_category != ''");
while ($row = $used_res->fetch_assoc()) {
    if (!isset($active_programs[$row['program_category']])) $active_programs[$row['program_category']] = $row['program_category'];
}
$uncat_res = $conn->query("SELECT COUNT(*) FROM research_papers WHERE $base_where AND (program_category IS NULL OR program_category = '')");
if ($uncat_res && $uncat_res->fetch_row()[0] > 0) $active_programs['Uncategorized'] = '⚠️ Uncategorized';

$start_item = $offset + 1;
$end_item   = min($offset + $per_page, $total_papers);
$has_filters = $search || $filter_year || $filter_type || $filter_program || $filter_month || $filter_day;

// Query string carried across pagination links
$qs = http_build_query(array_filter([
    'q' => $search, 'year' => $filter_year ?: null, 'type' => $filter_type,
    'program' => $filter_program, 'month' => $filter_month ?: null,
    'day' => $filter_day ?: null, 'sort' => $sort_param, 'browse' => '1'
]));

// AJAX result refresh — search/filter/pagination fetch just the results
// fragment instead of reloading the whole page. Nothing has been sent to
// the browser yet (buffered below), so header() below is still safe even
// though this flag is only acted on much further down the file.
$ajax_results = isset($_GET['ajax']) && $_GET['ajax'] === '1';
ob_start();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Public Repository · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/browse_console.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* =========================================================
   PAPEL — Public Repository landing page
   Design tokens + header/footer/modal CSS now live in
   includes/site_head.php. This block is page-specific only.
   ========================================================= */
:root {
    --sidebar-w:    226px;
    --col-gap:      36px;
}

/* ===== 3. Breadcrumb strip ===== */
.crumb-bar { background: var(--dark-maroon); }
.crumb-inner {
    display: flex;
    align-items: center;
    gap: .25rem;
    padding-top: .5rem;
    padding-bottom: .5rem;
    font-size: .75rem;
    color: rgba(255,255,255,.85);
}
.crumb-inner a { color: #fff; text-decoration: none; font-weight: 500; }
.crumb-inner a:hover { text-decoration: underline; }
/* Current-location indicator — solid white arrow */
.crumb-arrow {
    color: #fff;
    font-size: 20px;
    margin: 0 .125rem;
    --mi-fill: 1;
    --mi-wght: 700;
}
.crumb-current { color: #fff; font-weight: 500; }

/* ===== 4. Hero banner ===== */
.hero {
    position: relative;
    height: 240px;
    overflow: hidden;
    background: var(--dark-maroon);
    transition: height .35s ease, opacity .3s ease;
}
.hero.collapsed { height: 0; opacity: 0; }
.hero img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* ===== 5. Search band — sits over the lower edge of the hero,
   left-aligned to the main content column (matches Figma) ===== */
.search-band {
    position: relative;
    z-index: 5;
    margin-top: -24px;
    transition: margin-top .35s ease;
}
/* With the banner hidden there is no hero to overlap — drop the pull-up
   so the search bar can't ride over the breadcrumb strip. */
.hero.collapsed + .search-band { margin-top: 1.25rem; }
/* Same reasoning for the layout below it — no photo to tuck under, so
   drop its pull-up too or it rides over the search bar/sidebar. */
.hero.collapsed ~ .layout { margin-top: 1rem; }
/* Search field and banner toggle share one row — hiding the banner must not
   leave an empty full-width band between the search bar and the results. */
.search-row {
    display: grid;
    grid-template-columns: 1fr var(--sidebar-w);
    gap: .5rem var(--col-gap);
    align-items: start;
}
.search-shell {
    grid-column: 1;
    grid-row: 1;
    width: 100%;
    position: relative;
}
/* Second row, sidebar column: keeps the chip clear of the hero photo
   while staying aligned to the bottom-right of the search block. Nudged
   out by the wrap/wrap-wide gap so it lines up under "Login" in the
   header, which sits in the wider wrap-wide container. */
.banner-toggle-col {
    grid-column: 2;
    grid-row: 2;
    display: flex;
    justify-content: flex-end;
    margin-right: calc(((var(--wrap-wide) - var(--wrap)) / -2) - 20px);
}
/* Banner hidden: no photo to clear, so the chip moves up beside the search
   field. That collapses the grid to a single row and reclaims the gap. */
.hero.collapsed + .search-band .banner-toggle-col {
    grid-row: 1;
    align-self: end;
}

/* Banner visibility toggle */
.btn-banner-toggle {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    background: none;
    border: none;
    color: var(--ink);
    font-family: inherit;
    font-size: .6875rem;
    cursor: pointer;
    padding: .3rem 0;
    transition: color .2s;
}
.btn-banner-toggle:hover { color: var(--maroon); }
.btn-banner-toggle .material-symbols-outlined { transition: transform .3s ease; }
.btn-banner-toggle.is-collapsed .material-symbols-outlined { transform: rotate(180deg); }

/* ===== 6. Main layout ===== */
.layout {
    display: grid;
    grid-template-columns: 1fr var(--sidebar-w);
    gap: var(--col-gap);
    align-items: start;
    margin-top: -1.25rem;
    padding-bottom: 3rem;
}

/* Section heading with red underline */
.section-heading {
    font-family: var(--font-head);
    font-size: 1.6875rem;
    font-weight: 500;
    line-height: 1.2;
    color: var(--pup-maroon);
    padding-bottom: .5rem;
    border-bottom: 2px solid var(--maroon);
    margin-bottom: .5rem;
}


/* Site header/footer/login-modal CSS now live in includes/site_head.php */

/* ===== 10. Responsive (page-specific layout only) ===== */
@media (max-width: 900px) {
    .layout { grid-template-columns: 1fr; gap: 2rem; }
    .search-row { grid-template-columns: 1fr; gap: .5rem; }
    .banner-toggle-col { grid-column: 1; }
    .hero { height: 190px; }
}
@media (max-width: 600px) {
    .hero { height: 150px; }
    .search-band { margin-top: -32px; }
    .section-heading { font-size: 1.375rem; }
    .paper-foot { grid-template-columns: 1fr; gap: .25rem; }
}
</style>
</head>
<body>

<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- ===== 3. Breadcrumb ===== -->
<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current"><?= $has_filters ? 'Search Results' : 'Public Repository' ?></span>
    </div>
</div>

<!-- ===== 4. Hero banner ===== -->
<section class="hero" id="heroBanner">
    <img src="../assests/images/loginbackground.png" alt="PUP Biñan Campus">
</section>

<!-- ===== 5. Search band (overlaps the hero) ===== -->
<div class="search-band">
    <div class="wrap search-row">
        <div class="search-shell">
            <form action="index.php" method="get" id="searchForm">
                <?php if ($filter_year): ?><input type="hidden" name="year" value="<?= $filter_year ?>"><?php endif; ?>
                <?php if ($filter_type): ?><input type="hidden" name="type" value="<?= e($filter_type) ?>"><?php endif; ?>
                <?php if ($filter_program): ?><input type="hidden" name="program" value="<?= e($filter_program) ?>"><?php endif; ?>
                <?php if ($filter_month): ?><input type="hidden" name="month" value="<?= $filter_month ?>"><?php endif; ?>
                <?php if ($filter_day): ?><input type="hidden" name="day" value="<?= $filter_day ?>"><?php endif; ?>
                <?php if ($is_member): ?><input type="hidden" name="sort" value="<?= e($sort_param) ?>"><?php endif; ?>
                <input type="hidden" name="browse" value="1">
                <div class="search-form">
                    <button type="submit" class="btn-search-icon" aria-label="Search">
                        <span class="material-symbols-outlined">search</span>
                    </button>
                    <input class="search-input" type="search" name="q" id="searchInput"
                           data-suggest-url="index.php?ajax_search=1"
                           value="<?= e($search) ?>" placeholder="Type word to search..." autocomplete="off">
                </div>
                <div id="searchSuggestions" class="suggestions-dropdown"></div>
            </form>
        </div>
        <div class="banner-toggle-col">
            <button type="button" class="btn-banner-toggle" id="bannerToggle">
                <span id="bannerToggleLabel">Hide Banner</span>
                <span class="material-symbols-outlined mi-18">expand_less</span>
            </button>
        </div>
    </div>
</div>

<!-- ===== 6. Main content + sidebar ===== -->
<main class="wrap layout">
    <div class="main-col" id="mainCol">
        <?php ob_start(); ?>
        <h1 class="section-heading"><?= $has_filters ? 'Search Results' : 'Recent Researches' ?></h1>

        <!-- Browse toolbar — same for guests and members -->
        <div class="browse-toolbar">
            <div class="toolbar-left">
                <span>Showing items <?= $total_papers > 0 ? $start_item : 0 ?>-<?= $end_item ?> of <?= number_format($total_papers) ?></span>
                <?php if ($page > 1): ?>
                    <a class="toolbar-btn" href="index.php?<?= $qs ?>&page=<?= $page - 1 ?>" aria-label="Previous page"><span class="material-symbols-outlined">chevron_left</span></a>
                <?php else: ?>
                    <span class="toolbar-btn disabled"><span class="material-symbols-outlined">chevron_left</span></span>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a class="toolbar-btn" href="index.php?<?= $qs ?>&page=<?= $page + 1 ?>" aria-label="Next page"><span class="material-symbols-outlined">chevron_right</span></a>
                <?php else: ?>
                    <span class="toolbar-btn disabled"><span class="material-symbols-outlined">chevron_right</span></span>
                <?php endif; ?>
            </div>
            <div class="toolbar-right">
                <a class="toolbar-btn" href="index.php?browse=1" title="Refresh — clears search and filters"><span class="material-symbols-outlined">refresh</span></a>
                <a class="toolbar-btn" href="../pages/help_center.php" title="Help"><span class="material-symbols-outlined">help</span></a>
                <div class="quick-settings">
                    <button class="toolbar-btn" type="button" id="quickSettingsBtn" title="Quick Settings" aria-haspopup="true" aria-expanded="false"><span class="material-symbols-outlined">settings</span></button>
                    <div class="quick-settings-dropdown" id="quickSettingsDropdown">
                        <div class="qs-header">
                            <span>Quick Settings</span>
                            <button type="button" class="qs-close" id="quickSettingsClose" aria-label="Close"><span class="material-symbols-outlined mi-18">close</span></button>
                        </div>
                        <?php if ($u): ?>
                        <div class="qs-section">
                            <a class="qs-link" id="quickSettingsFull" href="<?= e(BASE_URL.'/pages/settings.php') ?>">View Full Settings</a>
                        </div>
                        <?php endif; ?>
                        <div class="qs-section">
                            <span class="qs-section-label">Density</span>
                            <label class="qs-radio"><input type="radio" name="qs_density" value="default"> Default</label>
                            <label class="qs-radio"><input type="radio" name="qs_density" value="comfortable"> Comfortable</label>
                            <label class="qs-radio"><input type="radio" name="qs_density" value="compact"> Compact</label>
                        </div>
                        <div class="qs-section">
                            <span class="qs-section-label">Theme</span>
                            <label class="qs-radio"><input type="radio" name="qs_theme" value="system"> System</label>
                            <label class="qs-radio"><input type="radio" name="qs_theme" value="light"> Light</label>
                            <label class="qs-radio"><input type="radio" name="qs_theme" value="dark"> Dark</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Research list -->
        <div class="paper-list is-scrollable">
            <?php while ($r = $result->fetch_assoc()): ?>
            <article class="paper-item">
                <h2 class="paper-title">
                    <?php if ($can_view): ?>
                        <a href="view_paper.php?id=<?= $r['paper_id'] ?>"><?= e($r['title']) ?></a>
                    <?php else: ?>
                        <span><?= e($r['title']) ?></span>
                    <?php endif; ?>
                </h2>

                <div class="paper-foot">
                    <div class="paper-info">
                        <?php if (!empty($r['author_names'])): ?>
                        <p class="paper-authors"><?= e($r['author_names']) ?></p>
                        <?php endif; ?>
                        <div class="paper-meta">
                            <span><?= e(paper_date_display($r['research_date'] ?? null, $r['year'] ?? null)) ?></span>
                            <?php if ($r['paper_type']): ?>
                                <span class="sep">•</span>
                                <span><?= e($type_labels[$r['paper_type']] ?? ucfirst($r['paper_type'])) ?></span>
                            <?php endif; ?>
                            <?php if ($r['publication_status']): ?>
                                <span class="sep">•</span>
                                <span><?= e($r['publication_status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="paper-side">
                        <?php if ($can_view): ?>
                            <a class="paper-action" href="view_paper.php?id=<?= $r['paper_id'] ?>">View details</a>
                        <?php else: ?>
                            <button type="button" class="paper-action js-open-modal" data-role="student">Login to view details</button>
                        <?php endif; ?>
                        <?php if ($r['program_category']): ?>
                        <span class="paper-program"><?= e($program_options[$r['program_category']] ?? $r['program_category']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endwhile; ?>

            <?php if ($result->num_rows === 0): ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">search_off</span>
                <p>No papers found matching your criteria.</p>
                <a href="index.php">Clear filters</a>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Held back until the sidebar is captured too, so one AJAX response
        // can refresh both columns and keep the filter controls in step.
        $main_col_html = ob_get_clean();
        echo $main_col_html;
        ?>
    </div>

    <!-- Right sidebar -->
    <aside class="sidebar-right" id="sidebarCol">
        <?php ob_start(); ?>
        <div class="sidebar-card" id="browseCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="browseCard">Browse</button>
                <span class="card-header-tools">
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="browseCard" aria-label="Collapse Browse"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <?php if ($is_member): ?>
            <div class="sidebar-card-body">
                <a href="<?= e(role_home($u['user_role'])) ?>" class="sidebar-link"><?= e(role_home_label($u['user_role'])) ?></a>
            </div>
            <?php else: ?>
            <div class="sidebar-card-body">
                <a href="index.php?browse=1" class="sidebar-link <?= !$has_filters ? 'active' : '' ?>">Public Repository</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="sidebar-card" id="filterCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="filterCard">Filter</button>
                <span class="card-header-tools">
                    <?php /* A crossed-out funnel, not a plain X — the X sits next to a
                             collapse chevron and reads as "close the card". */ ?>
                    <a class="card-tool" href="index.php?browse=1" title="Clear all filters" aria-label="Clear all filters"><span class="material-symbols-outlined">filter_alt_off</span></a>
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="filterCard" aria-label="Collapse Filter"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <form id="filterForm" action="index.php" method="get">
                <?php if ($search): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                <input type="hidden" name="browse" value="1">

                <div class="filter-section">
                    <span class="filter-section-label">Paper Type</span>
                    <label class="filter-radio">
                        <input type="radio" name="type" value="" <?= $filter_type === '' ? 'checked' : '' ?>> All Types
                    </label>
                    <?php
                    $types_res->data_seek(0);
                    while ($t = $types_res->fetch_assoc()):
                        $tv = $t['paper_type'];
                        $tl = $type_labels[$tv] ?? ucfirst($tv);
                    ?>
                    <label class="filter-radio">
                        <input type="radio" name="type" value="<?= e($tv) ?>" <?= $filter_type === $tv ? 'checked' : '' ?>>
                        <?= e($tl) ?>
                    </label>
                    <?php endwhile; ?>
                </div>

                <?php if (!$is_member): ?>
                <div class="filter-section">
                    <span class="filter-section-label">Year</span>
                    <select name="year" class="filter-select">
                        <option value="">All Year</option>
                        <?php
                        $years_res->data_seek(0);
                        while ($y = $years_res->fetch_assoc()):
                        ?>
                        <option value="<?= $y['year'] ?>" <?= $filter_year == $y['year'] ? 'selected' : '' ?>>
                            <?= $y['year'] ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="filter-section">
                    <span class="filter-section-label">Academic Program</span>
                    <select name="program" class="filter-select">
                        <option value="">All Program</option>
                        <?php foreach ($active_programs as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $filter_program === $val ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($is_member): ?>
                <div class="filter-section">
                    <span class="filter-section-label">Order By</span>
                    <label class="filter-radio">
                        <input type="radio" name="sort" value="asc" <?= $sort_param === 'asc' ? 'checked' : '' ?>> Ascending
                    </label>
                    <label class="filter-radio">
                        <input type="radio" name="sort" value="desc" <?= $sort_param === 'desc' ? 'checked' : '' ?>> Descending
                    </label>
                </div>

                <div class="filter-section">
                    <span class="filter-section-label">Date</span>
                    <div class="date-stack">
                    <select name="year" class="filter-select">
                        <option value="">All Year</option>
                        <?php
                        $years_res->data_seek(0);
                        while ($y = $years_res->fetch_assoc()):
                        ?>
                        <option value="<?= $y['year'] ?>" <?= $filter_year == $y['year'] ? 'selected' : '' ?>>
                            <?= $y['year'] ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                    <select name="month" class="filter-select">
                        <option value="">All Month</option>
                        <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                        <option value="<?= $mo ?>" <?= $filter_month === $mo ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$mo,1)) ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                    <select name="day" class="filter-select">
                        <option value="">All Date</option>
                        <?php for ($d = 1; $d <= 31; $d++): ?>
                        <option value="<?= $d ?>" <?= $filter_day === $d ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endfor; ?>
                    </select>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!$u): ?>
        <div class="sidebar-card" id="accountCard">
            <div class="sidebar-card-header is-toggle">
                <button class="card-title-btn js-card-toggle" type="button" data-card="accountCard">Account</button>
                <span class="card-header-tools">
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="accountCard" aria-label="Collapse Account"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <div class="sidebar-card-body">
                <button class="sidebar-link js-open-modal" type="button" data-role="student">Login</button>
                <button class="sidebar-link js-open-modal" type="button" data-role="guest">Guest Login</button>
            </div>
        </div>
        <div class="note-card">
            <strong>Note:</strong>
            Login to access other researches.
        </div>
        <?php endif; /* members reach the dashboard via Browse and log out via the avatar menu */ ?>
        <?php
        $sidebar_html = ob_get_clean();
        if ($ajax_results) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json');
            echo json_encode([
                'html'        => $main_col_html,
                'sidebar'     => $sidebar_html,
                'has_filters' => $has_filters,
            ]);
            exit;
        }
        echo $sidebar_html;
        ?>
    </aside>
</main>

<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

// ===== Hero banner show/hide (remembered per browser) =====
var bannerToggle = document.getElementById('bannerToggle');
var heroBanner   = document.getElementById('heroBanner');
if (bannerToggle && heroBanner) {
    function applyBannerState(collapsed) {
        heroBanner.classList.toggle('collapsed', collapsed);
        bannerToggle.classList.toggle('is-collapsed', collapsed);
        document.getElementById('bannerToggleLabel').textContent = collapsed ? 'Show Banner' : 'Hide Banner';
    }
    var stored = null;
    try { stored = localStorage.getItem('papel_banner_hidden'); } catch (err) {}
    if (stored === '1') applyBannerState(true);

    bannerToggle.addEventListener('click', function() {
        var collapsed = !heroBanner.classList.contains('collapsed');
        applyBannerState(collapsed);
        try { localStorage.setItem('papel_banner_hidden', collapsed ? '1' : '0'); } catch (err) {}
    });
}

// ===== AJAX result loading — search, filters, and pagination update just
// the results list instead of reloading the whole page. =====
var mainCol = document.getElementById('mainCol');
var searchForm = document.getElementById('searchForm');
// filterForm is resolved per-event via delegation (the sidebar is swapped too)

// Only reveal the loading state if the fetch is still pending after a
// short delay — keeps fast responses from flashing the overlay on/off.
var resultsLoadingTimer = null;
function setResultsLoading(isLoading) {
    if (!mainCol) return;
    clearTimeout(resultsLoadingTimer);
    if (isLoading) {
        resultsLoadingTimer = setTimeout(function () { mainCol.classList.add('is-loading'); }, 200);
    } else {
        mainCol.classList.remove('is-loading');
    }
}

function loadResults(url, pushState) {
    if (!mainCol) { window.location.href = url; return; }
    setResultsLoading(true);
    var ajaxUrl = url + (url.indexOf('?') > -1 ? '&' : '?') + 'ajax=1';
    fetch(ajaxUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('Request failed'); return r.json(); })
        .then(function (data) {
            mainCol.innerHTML = data.html;
            // Re-render the sidebar too so the filter controls reflect the
            // state the server just applied (selects back to "All", etc.).
            if (data.sidebar) {
                var side = document.getElementById('sidebarCol');
                if (side) side.innerHTML = data.sidebar;
            }
            setResultsLoading(false);
            // The swap replaced the Quick Settings controls — re-tick them.
            if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
            if (pushState !== false) history.pushState({ papelAjax: true }, '', url);
        })
        .catch(function () { window.location.href = url; });
}

// Pagination links, refresh, and the sidebar's "Clear filters" (X) button
// all live inside content that AJAX swaps replace, so they're bound here
// via delegation rather than direct listeners.
document.addEventListener('click', function (e) {
    var navLink = e.target.closest('#mainCol a[href], #filterCard a[href]');
    if (navLink) {
        var href = navLink.getAttribute('href');
        if (href && href.indexOf('index.php') === 0) {
            e.preventDefault();
            loadResults(href);
        }
    }
});

if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        loadResults('index.php?' + new URLSearchParams(new FormData(searchForm)).toString());
    });
}

// The sidebar is re-rendered on every swap, so the filter form is bound by
// delegation — a direct handler would be orphaned on a detached node.
document.addEventListener('submit', function (e) {
    var form = e.target.closest('#filterForm');
    if (!form) return;
    e.preventDefault();
    loadResults('index.php?' + new URLSearchParams(new FormData(form)).toString());
});
document.addEventListener('change', function (e) {
    var form = e.target.closest('#filterForm');
    if (!form) return;
    if (e.target.matches('input[type="radio"], select')) {
        loadResults('index.php?' + new URLSearchParams(new FormData(form)).toString());
    }
});

window.addEventListener('popstate', function () {
    loadResults(window.location.href, false);
});

document.addEventListener('contextmenu', function(e) { e.preventDefault(); });

}); // end DOMContentLoaded
</script>
<?php require ROOT_PATH.'/includes/browse_console_js.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
