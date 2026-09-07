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
        /* Author names, split out of the stored list so a suggestion is one
           person rather than the whole "A, B and C" string the paper carries. */
        if (count($suggestions) < 8) {
            $stmtA = $conn->prepare("SELECT author_names FROM research_papers WHERE current_status = 'approved' AND author_names LIKE ? LIMIT 10");
            if ($stmtA) {
                $stmtA->bind_param('s', $term);
                $stmtA->execute();
                $resA = $stmtA->get_result();
                while ($row = $resA->fetch_assoc()) {
                    if (empty($row['author_names'])) continue;
                    foreach (preg_split('/\s*(?:,|;|\band\b|&)\s*/i', $row['author_names']) as $name) {
                        $name = trim($name);
                        if ($name !== '' && stripos($name, $q) !== false
                            && !in_array($name, $suggestions, true) && count($suggestions) < 8) {
                            $suggestions[] = $name;
                        }
                    }
                }
            }
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
    /* Authors are searched alongside the text. Looking a paper up by the person
       who wrote it is one of the first things anyone tries in a repository, and
       until now it silently returned nothing unless the name also happened to
       appear in the title or abstract. */
    $term = "%$search%";
    $where_extra .= " AND (title LIKE ? OR keywords LIKE ? OR abstract LIKE ? OR author_names LIKE ?)";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    $types .= 'ssss';
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
<?php require_once ROOT_PATH.'/includes/browse_card.php'; ?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* =========================================================
   PAPEL — Public Repository landing page
   Design tokens + header/footer/modal CSS now live in
   includes/site_head.php. This block is page-specific only.
   ========================================================= */
:root {
    --sidebar-w:    226px;
    --col-gap:      36px;
    /* The rendered height of the search field: .75rem of padding above and
       below its content. Named rather than left implicit because the sidebar
       is aligned against it — see .sidebar-right. Change the field's padding
       and this has to follow, or the two tops drift apart again. */
    --search-h:     50px;
}

/* ===== 3. Breadcrumb strip =====
   --maroon-surface-hover, not --dark-maroon. The two are the same colour on a
   light palette, which is why this went unnoticed; on a dark one --dark-maroon
   lifts so it can be read as text, and the strip came out as a bright band of
   the accent instead of a dark one. The surface tokens are the ones that do
   not lift. */
.crumb-bar { background: var(--maroon-surface-hover); }
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
}
/* The button lines up with the hero's wider edge rather than the text column,
   and the pull that does it is exactly 100px: half the 160px the wide wrap
   adds, plus 20. It only has anywhere to go once the window is wider than the
   wrap plus that pull on both sides — 1168 + 200. Applied unconditionally, as
   it was, it dragged the button past the right of the page on every laptop
   below that, and took a horizontal scrollbar with it: 76px of it at 1024,
   25px at 1280. */
@media (min-width: 1369px) {
    .banner-toggle-col {
        margin-right: calc(((var(--wrap-wide) - var(--wrap)) / -2) - 20px);
    }
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
/* At rest the control is one glyph. The words are still there — collapsed to
   no width rather than removed, so they are read out to anyone using a screen
   reader and slide open the moment the button is pointed at or tabbed to.
   A tooltip would do neither. */
.btn-banner-label {
    max-width: 0;
    opacity: 0;
    overflow: hidden;
    white-space: nowrap;
    transition: max-width .25s ease, opacity .18s ease;
}
.btn-banner-toggle:hover .btn-banner-label,
.btn-banner-toggle:focus-visible .btn-banner-label {
    max-width: 8rem;
    opacity: 1;
}
@media (prefers-reduced-motion: reduce) {
    .btn-banner-label { transition: none; }
}

/* ===== 6. Main layout ===== */
.layout {
    display: grid;
    grid-template-columns: 1fr var(--sidebar-w);
    gap: var(--col-gap);
    align-items: start;
    margin-top: -1.25rem;
    padding-bottom: 3rem;
}
/* The search field and the sidebar are in two different grids — the field in
   .search-row, the sidebar in .layout — so nothing tied their tops together
   and the sidebar sat 66px lower in both banner states.

   66px is not arbitrary: the sidebar column starts where .search-band ends,
   which is the search field (50px) plus the 1rem the layout is offset by.
   Measured the same either way round, because hiding the banner shortens the
   band by exactly as much as it lengthens the layout's margin — 86-20 and
   50+16 both land on 66.

   Only the sidebar rises; the results column keeps its place. Below 900px the
   layout is a single column and the sidebar follows the content, so the pull
   would drag it over the results — hence the media query. */
@media (min-width: 901px) {
    .sidebar-right {
        margin-top: calc(-1 * (var(--search-h, 50px) + 1rem));
        /* Raised to the search field's line, it now reaches 24px into the hero
           photo — the same overlap the search card has. That card floats over
           the photo on z-index 5; without the same treatment the sidebar is
           static, so the hero paints across its top edge and clips the Browse
           header. */
        position: relative;
        z-index: 5;
    }
}

/* ===== The sidebar on either side — this page's share of it =====
   The column swap itself lives in includes/browse_console.php, with the rest
   of the sidebar. What stays here is the search row, which only this page has:
   it carries the banner eye in the column above the sidebar, so it has to
   mirror too or the eye is left stranded over the results. */
@media (min-width: 901px) {
    html.sidebar-left .search-row { grid-template-columns: var(--sidebar-w) 1fr; }
    html.sidebar-left .search-shell      { grid-column: 2; }
    html.sidebar-left .banner-toggle-col { grid-column: 1; justify-content: flex-start; }
}

/* The pull that lines the eye up with the hero's wider edge now has to reach
   the other way. Same width rule as the right-hand version: it only has room
   once the window is wider than the wrap plus the pull on both sides. */
@media (min-width: 1369px) {
    html.sidebar-left .banner-toggle-col {
        margin-right: 0;
        margin-left: calc(((var(--wrap-wide) - var(--wrap)) / -2) - 20px);
    }
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
/* ===== The sidebar as a drawer, on a phone =====
   In one column the Browse and Filter cards land underneath every result —
   a long scroll from the search box they belong to, which is where the eye
   is. On a narrow screen they become a drawer instead, opened by a button
   beside the search field.

   The <aside> is not moved in the DOM to do this. An AJAX filter replaces
   its innerHTML in place, so the element has to stay exactly where the
   script goes looking for it; only its painting changes. */
.btn-tools-toggle,
.tools-backdrop,
.btn-tools-close { display: none; }

@media (max-width: 900px) {
    /* The lift tucks the results up beside the sidebar column on a wide
       screen, where the eye and this row sit off to the right. In one column
       that row is directly above the heading, and the lift dragged the
       heading up underneath it. */
    .layout { grid-template-columns: 1fr; gap: 2rem; margin-top: .75rem; }
    .search-row { grid-template-columns: 1fr; gap: .5rem; }
    .banner-toggle-col { grid-column: 1; }
    .hero { height: 190px; }

    /* Whichever state the banner is in, this row follows the search field
       rather than being pinned to a row number: pinned to row 1, which is
       what the collapsed-banner rule does on a wide screen, it would land on
       top of the search field once there is only one column. */
    .banner-toggle-col {
        grid-row: auto;
        align-items: center;
        gap: .5rem;
    }
    .hero.collapsed + .search-band .banner-toggle-col { grid-row: auto; }
    .btn-tools-toggle {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .45rem .85rem;
        border: 1px solid var(--border);
        border-radius: var(--r-control, 4px);
        background: var(--white);
        color: var(--maroon);
        font-family: var(--font-body);
        font-size: .875rem;
        cursor: pointer;
    }
    .btn-tools-toggle:hover { background: var(--cream); }

    .sidebar-right {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: min(88vw, 360px);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0;
        padding: 3rem 1rem 2rem;
        background: var(--cream);
        box-shadow: -4px 0 24px rgba(51, 0, 0, .18);
        transform: translateX(100%);
        transition: transform .3s cubic-bezier(.4, 0, .2, 1);
        /* Under the login slide-in (1200) on purpose: signing in should still
           come out on top of a drawer left open behind it. */
        z-index: 1090;
    }
    html.tools-open .sidebar-right { transform: translateX(0); }

    .tools-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        background: rgba(51, 0, 0, .40);
        opacity: 0;
        pointer-events: none;
        transition: opacity .3s;
        z-index: 1085;
    }
    html.tools-open .tools-backdrop { opacity: 1; pointer-events: auto; }

    /* Outside the <aside>, because everything inside it is replaced whenever
       a filter is applied and a close button in there would be swept away. */
    html.tools-open .btn-tools-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        top: .5rem;
        right: .5rem;
        width: 38px;
        height: 38px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: var(--maroon);
        cursor: pointer;
        z-index: 1095;
    }
    html.tools-open { overflow: hidden; }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar-right, .tools-backdrop { transition: none; }
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
                           value="<?= e($search) ?>" placeholder="Search by title, author, or keyword..." autocomplete="off">
                </div>
                <div id="searchSuggestions" class="suggestions-dropdown"></div>
            </form>
        </div>
        <div class="banner-toggle-col">
            <?php /* Shares the row the eye already sits on rather than taking
                     one of its own, which pushed the band down over the
                     heading below it. Only ever seen on a narrow screen; the
                     stylesheet hides it the moment the sidebar is a column
                     again. Worded for what it opens rather than drawn as a
                     hamburger — a hamburger reads as "the site's menu", and
                     this is the filters for the list underneath. */ ?>
            <button type="button" class="btn-tools-toggle" id="toolsToggle"
                    aria-controls="sidebarCol" aria-expanded="false">
                <span class="material-symbols-outlined mi-18">tune</span>
                <span>Browse &amp; Filters</span>
            </button>
            <?php /* The eye follows the same rule as the password toggle in
                     site_footer.php: it shows the action, not the state, so it
                     always agrees with the words beside it. Banner on screen →
                     "Hide Banner" and a struck-through eye. The words are kept
                     in the markup rather than being a tooltip, so they are read
                     out and can be revealed on keyboard focus as well as on
                     hover. */ ?>
            <button type="button" class="btn-banner-toggle" id="bannerToggle"
                    aria-label="Hide Banner" title="Hide Banner" aria-pressed="false">
                <span class="btn-banner-label" id="bannerToggleLabel">Hide Banner</span>
                <span class="material-symbols-outlined mi-18" id="bannerToggleIcon">visibility_off</span>
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
                                <span><?= e(paper_type_label($r['paper_type'])) ?></span>
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
        <?= browse_card_html($u ?? null) ?>
        <?= quick_action_card_html($u ?? null) ?>

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
                        $tl = paper_type_label($tv);
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
                        <input type="radio" name="sort" value="desc" <?= $sort_param === 'desc' ? 'checked' : '' ?>> Newest first
                    </label>
                    <label class="filter-radio">
                        <input type="radio" name="sort" value="asc" <?= $sort_param === 'asc' ? 'checked' : '' ?>> Oldest first
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
        /* Words, icon and label move together. The eye names what pressing it
           will do, so a hidden banner offers an open eye. */
        var text = collapsed ? 'Show Banner' : 'Hide Banner';
        document.getElementById('bannerToggleLabel').textContent = text;
        var icon = document.getElementById('bannerToggleIcon');
        if (icon) { icon.textContent = collapsed ? 'visibility' : 'visibility_off'; }
        bannerToggle.setAttribute('aria-label', text);
        bannerToggle.setAttribute('title', text);
        bannerToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
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
<script nonce="<?= csp_nonce() ?>">
/* The Browse & Filters drawer. Everything here is delegated or bound to
   elements outside the <aside>, because applying a filter replaces the whole
   inside of it and any listener attached in there would go with it. */
(function () {
    var root = document.documentElement;
    var btn  = document.getElementById('toolsToggle');
    if (!btn) { return; }

    function setOpen(on) {
        root.classList.toggle('tools-open', on);
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    function isOpen() { return root.classList.contains('tools-open'); }

    btn.addEventListener('click', function () { setOpen(!isOpen()); });

    ['toolsBackdrop', 'toolsClose'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('click', function () { setOpen(false); }); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { setOpen(false); }
    });

    /* Choosing a filter refreshes the list behind the drawer, so get out of
       the way and let the results be the thing on screen. */
    document.addEventListener('change', function (e) {
        if (isOpen() && e.target.closest('#sidebarCol')) { setOpen(false); }
    });
    document.addEventListener('click', function (e) {
        if (isOpen() && e.target.closest('#sidebarCol a[href]')) { setOpen(false); }
    });

    /* Back on a wide screen the sidebar is a column again and the drawer means
       nothing — but the open class would still be holding the page's scroll. */
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900 && isOpen()) { setOpen(false); }
    });
})();
</script>

<div class="tools-backdrop" id="toolsBackdrop"></div>
<button type="button" class="btn-tools-close" id="toolsClose" aria-label="Close Browse and Filters">
    <span class="material-symbols-outlined">close</span>
</button>

<?php require ROOT_PATH.'/includes/browse_console_js.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
