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
$filter_saved = ($_GET['saved'] ?? '') === '1';
// Whitelisted so it can be interpolated into ORDER BY safely
$sort_dir     = (($_GET['sort'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$sort_param   = $sort_dir === 'ASC' ? 'asc' : 'desc';
// Home layout disabled: browse view is the permanent landing page
$is_searching = true;

// Papers are only openable by signed-in users (or an active guest session)
$can_view = $u || isset($_SESSION['guest_login']);

/* Which of the papers on this page the reader has already saved, in one query
   for the whole list, and only for a reader who has a library to save into:
   an account, not a Library Pass (see user_has_library()). */
$can_save  = user_has_library($u);
$saved_ids = $can_save ? saved_paper_ids((int)$u['user_id']) : [];
/* Nobody without a library can narrow the repository to one, whatever the
   query string says: dropped here rather than guarded at each of the four
   places below that read it. */
if (!$can_save) { $filter_saved = false; }
// Signed-in members get the full browse console (toolbar + extended filters)
$is_member = (bool)$u;

/* The papers this reader has an open manuscript request on, pinned to the top
   of the list however it is sorted: paper_id => 'pending' | 'granted'. A
   student who has asked a librarian for a manuscript comes back here to look
   at it, and finding it again meant remembering the title and searching for it.
   Only for the student whose requests they are; for everybody else this is
   empty and the list is ordered exactly as it always was. */
$pinned_papers = ($u && ($u['user_role'] ?? '') === 'student')
               ? student_open_request_papers((int)$u['user_id'])
               : [];

/* Pagination: 20 results a page for everyone, so a page of the repository is
   a page worth scrolling. At 10 it scrolled about one screen and then asked
   for a click, which is the pager getting in the way of reading rather than
   helping. The figures ('Showing items 1-20 of 27', then 21 to 40) and both
   pagers are worked out from this one number. */
$per_page = 20;
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

/* **The search box holds a list, not one phrase.** Pressing keywords on the
   strip above the results writes them into it, comma separated, and typing the
   same list by hand does the same thing. Five at most, the number the strip
   will let anybody choose, so a pasted paragraph of commas cannot turn one
   page into forty LIKEs.

   **Any of them, not all of them.** Two keywords read as `and` is the usual
   way to narrow by tags, and on this repository it is also the way to show
   nothing at all: measured against the papers on file, the second keyword
   emptied the page almost every time (`Feasibility Study` and `job
   satisfaction` have no paper in common), which would make a five-keyword
   limit a limit on how fast somebody can reach an empty list. So a term is a
   way in rather than a sieve: a paper answering any of them is shown, and each
   keyword adds to what is on the page. One term behaves exactly as a search
   always did. */
const SEARCH_TERMS_MAX = 5;
$search_terms = [];
foreach (explode(',', $search) as $term) {
    $term = trim(preg_replace('/\s+/u', ' ', $term));
    if ($term !== '') { $search_terms[] = $term; }
}
$search_terms = array_slice($search_terms, 0, SEARCH_TERMS_MAX);

if ($search_terms) {
    /* Authors are searched alongside the text. Looking a paper up by the person
       who wrote it is one of the first things anyone tries in a repository, and
       until now it silently returned nothing unless the name also happened to
       appear in the title or abstract. */
    $one = '(title LIKE ? OR keywords LIKE ? OR abstract LIKE ? OR author_names LIKE ?)';
    $where_extra .= ' AND (' . implode(' OR ', array_fill(0, count($search_terms), $one)) . ')';
    foreach ($search_terms as $term) {
        $like = "%$term%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'ssss';
    }
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
if ($filter_saved) {
    /* A subquery rather than a join, so a paper appears once however the
       rest of the WHERE is built, and so the page's existing count and fetch
       statements take it without being rewritten. */
    $where_extra .= " AND paper_id IN (SELECT paper_id FROM paper_favorites WHERE user_id = ?)";
    $params[] = (int)$u['user_id'];
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

/* Pinned first, then the reader's own sort. Bound parameters, not ids written
   into the statement: they come from the database, but the one place this page
   interpolates anything into SQL is the whitelisted $sort_dir and it should
   stay the only one. The placeholders go after the WHERE's and before LIMIT's,
   which is the order they appear in the statement. A reader with nothing
   pinned adds nothing at all. */
$pin_order  = '';
$pin_params = [];
$pin_types  = '';
if ($pinned_papers) {
    $pin_ids    = array_map('intval', array_keys($pinned_papers));
    $pin_order  = '(paper_id IN (' . implode(',', array_fill(0, count($pin_ids), '?')) . ')) DESC, ';
    $pin_params = $pin_ids;
    $pin_types  = str_repeat('i', count($pin_ids));
}

// Main query
$main_sql = "SELECT paper_id, title, year, research_date, abstract, keywords, author_names, upload_date, paper_type, program_category, publication_status
             FROM research_papers WHERE $full_where
             ORDER BY $pin_order COALESCE(research_date, MAKEDATE(year, 1)) $sort_dir LIMIT ? OFFSET ?";
try {
    $stmt = $conn->prepare($main_sql);
} catch (mysqli_sql_exception $e) {
    if (strpos($e->getMessage(), "Unknown column 'program_category'") !== false) {
        echo '<div style="padding:2rem;text-align:center;font-family:sans-serif;"><h2>System Update Required</h2><p>Please contact your administrator.</p></div>';
        exit;
    }
    throw $e;
}
$fetch_params = array_merge($params, $pin_params, [$per_page, $offset]);
$fetch_types  = $types . $pin_types . 'ii';
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

/* How many of this reader's saved papers are in the repository right now:
   the number beside the switch. Not count($saved_ids), which also counts the
   ones that have since been archived out of it and would promise results the
   filter cannot show. */
$saved_count = 0;
if ($can_save && $saved_ids) {
    $sc = $conn->prepare("SELECT COUNT(*) FROM research_papers
                           WHERE $base_where AND paper_id IN (
                                 SELECT paper_id FROM paper_favorites WHERE user_id = ?)");
    $sc->bind_param('i', $u['user_id']);
    $sc->execute();
    $sc->bind_result($saved_count);
    $sc->fetch();
    $sc->close();
}

/* The keywords on the strip above the results: what the repository is about,
   taken from the papers themselves rather than written out by hand, so it says
   what is actually on file and grows with it.

   From $base_where, the whole repository, not $full_where: a strip that
   narrowed with the filters would empty itself on the search it had just been
   used to make, which is the one moment it is in the way. The commonest first,
   then alphabetically so the order does not shuffle between two of equal
   weight, and the first spelling met is the one shown (`machine learning` and
   `Machine Learning` are one keyword and should not be two chips). */
$keyword_chips = [];
$keyword_all   = [];
$kw_res = $conn->query("SELECT keywords FROM research_papers WHERE $base_where
                         AND keywords IS NOT NULL AND keywords <> ''");
if ($kw_res) {
    $kw_seen = [];   // lower-cased key => ['label' => first spelling, 'n' => papers]
    while ($row = $kw_res->fetch_assoc()) {
        foreach (explode(',', (string)$row['keywords']) as $kw) {
            $kw = trim(preg_replace('/\s+/u', ' ', $kw));
            /* A word nobody would press: an empty cell, a stray letter, or a
               whole sentence somebody pasted into the keywords box. */
            if ($kw === '' || mb_strlen($kw) < 3 || mb_strlen($kw) > 32) { continue; }
            $key = mb_strtolower($kw);
            if (!isset($kw_seen[$key])) { $kw_seen[$key] = ['label' => $kw, 'n' => 0]; }
            $kw_seen[$key]['n']++;
        }
    }
    uasort($kw_seen, function ($a, $b) {
        return $b['n'] <=> $a['n'] ?: strcasecmp($a['label'], $b['label']);
    });
    /* The strip takes the commonest thirty: it is a way in, not the index, and
       a strip of two hundred chips is a strip nobody reaches the end of. The
       whole list is kept for the dialog behind the button beside it, where
       there is room to show every one. */
    $keyword_all   = array_values($kw_seen);
    $keyword_chips = array_slice($keyword_all, 0, 30);
}

/* The chosen keywords, lower-cased, for marking the chips that are on. */
$search_lower = array_map('mb_strtolower', $search_terms);

$start_item = $offset + 1;
$end_item   = min($offset + $per_page, $total_papers);
$has_filters = $search || $filter_year || $filter_type || $filter_program || $filter_month || $filter_day || $filter_saved;

// Query string carried across pagination links
$qs = http_build_query(array_filter([
    'q' => $search, 'year' => $filter_year ?: null, 'type' => $filter_type,
    'program' => $filter_program, 'month' => $filter_month ?: null,
    'day' => $filter_day ?: null, 'sort' => $sort_param,
    'saved' => $filter_saved ? '1' : null, 'browse' => '1'
]));

// AJAX result refresh: search/filter/pagination fetch just the results
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
<?php
/* The loading skeleton's shape (includes/skeleton.php). Set before the shared
   head, which is where the skeleton reads it. */
$PAGE_SKELETON = 'repository';
?>
<?php /* Shelf is offered here, so the pre-paint view script may apply a
         stored one. Set before browse_console.php, which reads it. */ ?>
<?php $VIEW_SHELF = true; ?>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php require_once ROOT_PATH.'/includes/browse_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/shelf_view.php'; ?>
<?php require_once ROOT_PATH.'/includes/browse_card.php'; ?>
<?php /* Only where there is a library to save into: the include prints a
         stylesheet and a script as well as defining save_paper_button(), and
         a guest has no bookmark on any row for either to act on. */ ?>
<?php if ($can_save) { require_once ROOT_PATH.'/includes/save_paper.php'; } ?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-archive.css')) ?>">
</head>
<body>

<?php /* This page's own banner runs up behind the navbar, so the header's
         stand-in strip of the same photo is not wanted here. */
      $hero_under_nav = true; ?>
<?php /* The sidebar's two lists of destinations, offered in the menu sheet as
         well, so the phone has one menu rather than two buttons in two
         corners that each hold half of what there is. The links themselves,
         not a way to open the cards they live in: the same arrays the cards
         are built from, so the sheet cannot say anything the sidebar does
         not. "What you can do" is empty for a guest and for the roles with
         no actions of their own, and an empty array simply adds nothing.

         Filter is deliberately not here. These are places to go and a link
         can carry someone to one; the filters are a form to work in, which
         wants the drawer and the results behind it, and the tune button in
         the toolbar is still what opens that. */
      $nav_panel = ['label' => 'Control Panel', 'items' => array_merge(
          browse_card_links($u ?? null),
          quick_action_links($u ?? null)
      )]; ?>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<!-- ===== 3. Breadcrumb ===== -->
<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Public Repository</a>
        <?php if ($has_filters): ?>
            <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
            <span class="crumb-current">Search Results</span>
        <?php endif; ?>
        <?php /* The eye follows the same rule as the password toggle in
                 site_footer.php: it shows the action, not the state, so it
                 always agrees with the words beside it. Banner on screen →
                 "Hide Banner" and a struck-through eye. The words are kept in
                 the markup rather than being a tooltip, so they are read out
                 and can be revealed on keyboard focus as well as on hover. */ ?>
        <button type="button" class="btn-banner-toggle<?= $u ? '' : ' is-guest' ?>" id="bannerToggle"
                aria-label="Hide Banner" title="Hide Banner" aria-pressed="false">
            <span class="btn-banner-label" id="bannerToggleLabel">Hide Banner</span>
            <span class="material-symbols-outlined mi-18" id="bannerToggleIcon">visibility_off</span>
        </button>
    </div>
</div>

<!-- ===== 4. Hero banner ===== -->
<?php /* A slideshow of the campus photos, in the order nav_photo_order()
         gives (config/core.php), which the navbar strip uses too: one set of
         files and one order, where both used to glob and shuffle for
         themselves. It rotates by the day rather than per request, because
         this first photo is the page's Largest Contentful Paint and a file
         that changes every visit can be neither cached nor preloaded.
         Only that first one carries a src; the rest wait in data-src until the
         page has loaded (see the slideshow script), so four photos nobody has
         seen yet do not hold up the first paint. The first is the only one
         announced: the others are the same subject and would be read out as
         four more images every five seconds.

         The set is whatever navbar-photo*.jpg is in the images folder, read
         from disk rather than listed here, so a photo can be added or dropped
         by adding or deleting the file: a hard-coded list broke the banner
         the first time one was removed. Made for a 3840×680 canvas. */
      $hero_photos = nav_photo_order(); ?>
<section class="hero" id="heroBanner">
    <?php foreach ($hero_photos as $i => $photo): ?>
        <?php $set = nav_photo_srcset($photo); ?>
        <?php if ($i === 0): ?>
            <?php /* The LCP element on this page. site_head.php preloads the very
                     same file; imagesrcset there and srcset here must agree.

                     **Never `loading="lazy"` on this element.** It was, for one
                     release, to stop a phone fetching a photo it does not draw
                     (`display: none` does not stop a fetch). It cost the thing
                     this whole block exists to protect: measured locally with
                     the body throttled, the banner painted at **15,060ms**
                     against about 1,100ms before it, and on staging at 3,256ms
                     with the page's text already done at 1,256ms. A lazy image
                     is not considered until layout has run and is then fetched
                     at a low priority, so the preload's head start is thrown
                     away. Locally with no latency it measured clean, which is
                     exactly why it shipped.

                     The gate is a `<source>` instead, and it has to come
                     first: sources are resolved in order and the first whose
                     `media` matches wins, so below the breakpoint the browser
                     resolves this picture to a 1x1 transparent GIF carried
                     inline as a data URI, which is no request at all, and the
                     real candidates below are never considered. Above it this
                     source does not match, the WebP source is used, and the
                     image stays eager and `fetchpriority="high"`, consuming
                     the preload as it always did. */ ?>
            <picture>
                <source media="(max-width: 900px)" srcset="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">
                <?php if ($set !== ''): ?><source type="image/webp" srcset="<?= e($set) ?>" sizes="100vw"><?php endif; ?>
            <img class="hero-slide is-active" src="../assests/images/<?= e($photo) ?>"
                 alt="PUP Biñan Campus" fetchpriority="high">
            </picture>
        <?php else: ?>
            <picture>
                <?php if ($set !== ''): ?><source type="image/webp" data-srcset="<?= e($set) ?>" sizes="100vw"><?php endif; ?>
                <img class="hero-slide" data-src="../assests/images/<?= e($photo) ?>"
                     alt="" aria-hidden="true" decoding="async" loading="lazy">
            </picture>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php /* Zen Mode has no banner. The class is put on here, where the element
             stands, rather than at DOMContentLoaded with the rest of the
             banner's script: applied a frame later the photo paints at its full
             height and folds away in front of the reader, which is the flash
             every other pre-paint read in site_head.php exists to prevent. The
             reader's own "Hide Banner" answer is not touched; the script below
             restores it when Zen goes off.

             **Inside the banner, not after it.** The search band is pulled up
             into the photo by a negative margin that `.hero.collapsed +
             .search-band` cancels, and a script element between the two is
             still a sibling: standing there it broke that rule and the field
             rode up 24px under the navbar, in Zen and in the page's own
             banner-hidden state alike. The same `+` trap as the import
             drawer's panels and the upload page's step bar. */ ?>
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        if (!document.documentElement.classList.contains('zen-on')) { return; }
        var hero = document.getElementById('heroBanner');
        if (hero) { hero.classList.add('collapsed'); }
    })();
    </script>
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
                <?php if ($filter_saved): ?><input type="hidden" name="saved" value="1"><?php endif; ?>
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
    </div>
</div>

<!-- ===== 6. Main content + sidebar ===== -->
<main class="wrap layout">
    <?php /* The list here can be read as cards or as rows, so Quick Settings
             draws the switch: see buildViewSection() in browse_console_js.php
             for why the marker sits on the column rather than on the list. */ ?>
    <div class="main-col" id="mainCol" data-view-console="shelf">
        <?php ob_start(); ?>
        <h1 class="section-heading"><?= $has_filters ? 'Search Results' : 'Recent Researches' ?></h1>

        <!-- Browse toolbar: same for guests and members -->
        <div class="browse-toolbar">
            <?php /* The row used to open with "Showing items 1-20 of 27", then with a
                     "Keywords:" label. The figure is at the foot of the list, under
                     the pages, where somebody who has read to the end is asking it,
                     and the chips say what they are by being chips: both only pushed
                     the keywords along the row. The strip starts the row now.

                     The one thing left here is the note for a sixth keyword, and it
                     is only the fallback: the dialog every console uses says it
                     (window.papelShow, includes/action_dialogs.php). */ ?>
            <div class="toolbar-left">
                <span class="kw-limit" id="kwLimit" role="status" hidden>Five at most</span>
            </div>
            <?php if ($keyword_chips): ?>
                <?php /* The keywords of the papers on file, between the label and the
                         tools. It is a scroll box, so a swipe on a phone with its own
                         momentum, a sideways wheel and the keyboard all work with no
                         script at all; a mouse drag is the one gesture a browser does
                         not give for free, and that is all the script adds. Printed
                         once: it used to be printed twice so an automatic roll could
                         wrap without a seam, and the roll has gone. */ ?>
                <?php /* The strip and the way to see every keyword at once travel
                         together in one box, because on a phone they share a line:
                         the button cannot be in .toolbar-right there and also be at
                         the right-hand end of the strip, and CSS cannot move an
                         element between parents. One element in one place, put where
                         it belongs at each width by the stylesheet. */ ?>
                <div class="kw-row">
                <div class="kw-rail" id="kwRail" role="group" aria-label="Browse by keyword">
                    <div class="kw-track">
                        <span class="kw-set">
                            <?php foreach ($keyword_chips as $chip): ?>
                                <?php /* On when it is one of the terms in the box, not when it is
                                         the whole of it: several can be chosen at once. */ ?>
                                <?php $on = in_array(mb_strtolower($chip['label']), $search_lower, true); ?>
                                <a class="kw-chip<?= $on ? ' is-on' : '' ?>"
                                   href="index.php?q=<?= rawurlencode($chip['label']) ?>&amp;browse=1"
                                   title="<?= e($chip['label']) ?> &middot; <?= (int)$chip['n'] ?> paper<?= $chip['n'] === 1 ? '' : 's' ?>"><?= e($chip['label']) ?></a>
                            <?php endforeach; ?>
                        </span>
                    </div>
                </div>
                <?php /* The two page arrows stood where this does, and before that
                         beside the count at the other end of the row. The pages are at
                         the foot of the list, with their numbers; in this row they were
                         two more chevrons next to a strip that is already scrolled
                         sideways, which is the one place a chevron means something
                         else. Their place is now the way to see every keyword at once,
                         which is what the strip beside it cannot do.

                         It says so in words, and in words alone. It was the
                         `open_in_full` glyph, which is the one control in this row
                         whose job cannot be guessed from its mark: refresh, help,
                         filters and settings are all marks a reader already knows,
                         and "open in full" beside a strip of keywords could as
                         easily mean the strip itself. The words answer that by
                         themselves, so the glyph went with them rather than sitting
                         in front of them saying the same thing less clearly. There
                         is no aria-label: the text is the button's name, and a label
                         would say something else over the top of it. */ ?>
                <?php if ($keyword_all): ?>
                    <button type="button" class="toolbar-btn kw-more" id="kwMoreBtn"
                            title="View all keywords" aria-haspopup="dialog">View All Keywords</button>
                <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="toolbar-right">
                <a class="toolbar-btn" href="index.php?browse=1" title="Refresh (clears search and filters)"><span class="material-symbols-outlined">refresh</span></a>
                <a class="toolbar-btn" href="../pages/help_center.php" title="Help"><span class="material-symbols-outlined">help</span></a>
                <?php /* Only ever seen on a narrow screen; the stylesheet hides it the
                         moment the sidebar is a column again and the filters are simply
                         there. It used to be a worded button on a row of its own under
                         the search field, which cost that row's whole height on the one
                         screen with the least of it to spare. Here it is one more tool
                         beside Quick Settings, and the drawer it opens is closed by its
                         own X, the backdrop or Escape, since the drawer covers this
                         button while it is open. */ ?>
                <button type="button" class="toolbar-btn btn-tools-toggle" id="toolsToggle"
                        aria-controls="sidebarCol" aria-expanded="false"
                        title="Browse &amp; Filters" aria-label="Browse and Filters">
                    <span class="material-symbols-outlined">tune</span>
                </button>
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
            <?php while ($r = $result->fetch_assoc()):
                /* '' , 'pending' or 'granted': a request this reader has open on
                   this paper, which is why it is at the top of the list. Said on
                   the result as well as sorted, or the order looks arbitrary. */
                $pinState = $pinned_papers[(int)$r['paper_id']] ?? '';
                $pinWord  = $pinState === 'granted' ? 'Manuscript Request Granted' : 'Manuscript requested';
                $pinIcon  = $pinState === 'granted' ? 'check_circle' : 'push_pin';
            ?>
            <article class="paper-item<?= $pinState ? ' is-pinned' : '' ?>">
                <?php /* The same paper as a book, for the Shelf view. One of the
                         two is shown and the other is display:none; see
                         includes/shelf_view.php for why there are two. */ ?>
                <?= shelf_book_html([
                    'paper_id' => (int)$r['paper_id'],
                    'title'    => (string)$r['title'],
                    'cover'    => shelf_cover_index($r['program_category'] ?? ''),
                    /* What a bound paper has stamped on it: the kind of paper
                       under the title, who presented it, and the programme and
                       year at the foot (includes/book_covers.php). */
                    'kind'     => !empty($r['paper_type']) ? paper_type_label($r['paper_type']) : '',
                    'by'       => shelf_cover_byline($r['author_names'] ?? ''),
                    'by_label' => 'Presented by',
                    'mark'     => implode(' · ', array_filter([
                                      program_code($r['program_category'] ?? ''),
                                      (string)($r['year'] ?? ''),
                                  ], 'strlen')) ?: APP_NAME,
                    /* On the Shelf the band is stamped across the head of the
                       board, so a pinned book says why it is first where a chip
                       cannot be put. The words only (band_tone ''): it used to
                       repaint the book green or amber, which on a shelf coloured
                       by programme read as another programme's paper. */
                    'band'      => $pinState ? $pinWord : '',
                    'band_tone' => '',
                    // Nothing to open for a reader who is not signed in.
                    'href'     => $can_view ? 'view_paper.php?id=' . (int)$r['paper_id'] : null,
                    /* Pressed, the book is taken off the shelf into the viewer
                       (includes/book_viewer.php), and set down into this card.
                       The abstract only for a reader who may read the paper: the
                       paper's own page is where it is shown, and that page asks
                       for a sign-in. */
                    'viewer'   => [
                        'id'       => (int)$r['paper_id'],
                        'title'    => (string)$r['title'],
                        'authors'  => (string)($r['author_names'] ?? ''),
                        'kicker'   => implode(' • ', array_filter([
                                          program_code($r['program_category'] ?? ''),
                                          !empty($r['paper_type']) ? paper_type_label($r['paper_type']) : '',
                                          paper_date_display($r['research_date'] ?? null, $r['year'] ?? null),
                                      ], 'strlen')),
                        'abstract' => $can_view ? book_excerpt($r['abstract'] ?? '') : '',
                        'href'     => $can_view ? 'view_paper.php?id=' . (int)$r['paper_id'] : '',
                        'signIn'   => !$can_view,
                        /* For the held-up book, which stamps the cover in full: the
                           programme by its name rather than its code, and the kind
                           of paper and the year on lines of their own. */
                        'program'  => (string)($r['program_category'] ?? ''),
                        'year'     => (string)($r['year'] ?? ''),
                        'kind'     => !empty($r['paper_type']) ? paper_type_label($r['paper_type']) : '',
                    ],
                ]) ?>
                <h2 class="paper-title">
                    <?php if ($pinState): ?>
                        <?php /* The same news twice over, as the Shelf's book is: the
                                 chip at the foot of the card (below, in .paper-side),
                                 and this mark at the head of the title, which is the
                                 one Rows draws. A row is one line, and a third line in
                                 its last column would grow every row on the page by a
                                 third; the title's own line has room for a mark, and
                                 anything at the end of that line is cut by the ellipsis
                                 first. CSS shows one or the other, never both. */ ?>
                        <span class="paper-pin paper-pin-mark<?= $pinState === 'granted' ? ' is-open' : '' ?>"
                              title="<?= e($pinWord) ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($pinIcon) ?></span>
                            <span class="pin-word"><?= e($pinWord) ?></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($can_view): ?>
                        <a href="view_paper.php?id=<?= $r['paper_id'] ?>"><?= e($r['title']) ?></a>
                    <?php else: ?>
                        <span><?= e($r['title']) ?></span>
                    <?php endif; ?>
                </h2>

                <?php /* A child of the result itself, not of the title or of
                         .paper-side: the two views want it in two different
                         places (the card's top right corner, the row's last
                         column) and only a direct child can be put in either.
                         After the title in the markup, so it is read as
                         belonging to the paper it saves. */ ?>
                <?php if ($can_save): ?><?= save_paper_button((int)$r['paper_id'], isset($saved_ids[(int)$r['paper_id']])) ?><?php endif; ?>

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
                        <?php if ($pinState): ?>
                            <?php /* Last in the card's right-hand column, so it rests at
                                     the bottom right of the result: it is news about the
                                     reader's own request, not part of the paper's title. */ ?>
                            <span class="paper-pin paper-pin-tag<?= $pinState === 'granted' ? ' is-open' : '' ?>">
                                <span class="material-symbols-outlined" aria-hidden="true"><?= e($pinIcon) ?></span>
                                <?= e($pinWord) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endwhile; ?>

            <?php if ($result->num_rows === 0): ?>
            <div class="empty-state">
                <?php /* Narrowed to saved papers with none saved, the general
                         "nothing matched your criteria" is answering a question
                         nobody asked: what is missing is a saved paper, not a
                         better search. */ ?>
                <?php if ($filter_saved && $saved_count === 0): ?>
                    <span class="material-symbols-outlined">bookmark_add</span>
                    <p>You have not saved any papers yet. Press the bookmark on a paper to keep it.</p>
                    <a href="index.php?browse=1">Show all papers</a>
                <?php else: ?>
                    <span class="material-symbols-outlined">search_off</span>
                    <p>No papers found matching your criteria.</p>
                    <a href="index.php">Clear filters</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php require_once ROOT_PATH.'/includes/paper_pager.php'; ?>
        <?= paper_pager_html($page, $total_pages, $start_item, $end_item, $total_papers, 'papers',
            fn($n) => 'index.php?' . $qs . '&page=' . $n) ?>

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
                    <?php /* A crossed-out funnel, not a plain X: the X sits next to a
                             collapse chevron and reads as "close the card". */ ?>
                    <a class="card-tool" href="index.php?browse=1" title="Clear all filters" aria-label="Clear all filters"><span class="material-symbols-outlined">filter_alt_off</span></a>
                    <button class="card-tool card-chevron js-card-toggle" type="button" data-card="filterCard" aria-label="Collapse Filter"><span class="material-symbols-outlined">expand_more</span></button>
                </span>
            </div>
            <form id="filterForm" action="index.php" method="get">
                <?php if ($search): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                <input type="hidden" name="browse" value="1">

                <?php if ($can_save): ?>
                <?php /* First in the card, because it is the broadest cut of the
                         lot: everything below narrows whichever of the two this
                         is set to. Radios rather than a tick box, to match the
                         rest of the card and because the form is submitted from
                         a change handler that listens for radios and selects
                         (see the foot of this file). */ ?>
                <div class="filter-section">
                    <span class="filter-section-label">Show</span>
                    <label class="filter-radio">
                        <input type="radio" name="saved" value="" <?= $filter_saved ? '' : 'checked' ?>> All papers
                    </label>
                    <label class="filter-radio">
                        <input type="radio" name="saved" value="1" <?= $filter_saved ? 'checked' : '' ?>>
                        Saved papers <span class="filter-count"><?= (int)$saved_count ?></span>
                    </label>
                </div>
                <?php endif; ?>

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
                <button class="sidebar-link js-open-modal" type="button" data-role="guest">Sign in with a Library Pass</button>
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

<script src="<?= e(asset_url('assests/js/papel-archive.js')) ?>"></script>
<?php /* The Browse & Filters drawer, its backdrop, its close button and the
         script that works them are shared now: includes/browse_console_js.php,
         required just below. The dashboards and the four review desks have the
         same <aside id="sidebarCol"> and wanted the same drawer, so the copy
         that used to stand here moved there whole. The drawer's styling stays
         in this page's own stylesheet above, tangled as it is with the banner
         rules; only the behaviour is shared. */ ?>

<?php if ($keyword_all): ?>
    <?php /* Every keyword on file, for choosing from in one place rather than
             scrolling the strip for one that may be past its thirtieth chip.

             **Outside #mainCol on purpose.** That column is replaced whole on
             every AJAX swap, and a dialog that is torn out of the document
             while it is open closes itself and loses what was being chosen.
             Nothing of the choice is printed into it either: what is on is read
             from the search box each time it opens, which is the one place the
             choice lives.

             A <dialog>: the top layer, Escape, and focus held, with no z-index
             to pick on a page that already has a banner, a drawer and a strip.
             It needs `margin: auto` stated, because the site's reset takes away
             the margin a modal dialog centres itself with. */ ?>
    <dialog class="kw-dialog" id="kwDialog" aria-labelledby="kwDialogTitle">
        <?php /* tabindex="-1": with nothing marked autofocus a dialog focuses its
                 first focusable child, which here is a keyword, and a chip with a
                 focus ring round it reads as one already chosen. The card takes the
                 focus instead. Not the search field: on a phone that would bring the
                 keyboard up over half the keywords before anybody asked for it. */ ?>
        <form method="dialog" class="kw-dialog-card" id="kwDialogCard" tabindex="-1">
            <div class="kw-dialog-head">
                <h2 id="kwDialogTitle">Keywords</h2>
                <div class="kw-dialog-meta">
                    <span class="kw-dialog-count" id="kwDialogCount"></span>
                    <button type="button" class="kw-dialog-clear" id="kwDialogClear">Clear keywords</button>
                </div>
            </div>
            <p class="kw-dialog-note" id="kwDialogNote">Choose up to five. Press one again to take it out.</p>
            <div class="kw-dialog-list" id="kwDialogList">
                <?php foreach ($keyword_all as $chip): ?>
                    <button type="button" class="kw-chip kw-pick"
                            data-kw="<?= e($chip['label']) ?>"
                            title="<?= (int)$chip['n'] ?> paper<?= $chip['n'] === 1 ? '' : 's' ?>"><?= e($chip['label']) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="kw-dialog-acts">
                <?php /* Narrowing a list of a hundred and forty-six by eye is not
                         reading, it is hunting. The field filters as it is typed;
                         there is nothing to press. */ ?>
                <div class="kw-dialog-find">
                    <span class="material-symbols-outlined" aria-hidden="true">search</span>
                    <input type="text" id="kwDialogFind" autocomplete="off"
                           placeholder="Find a keyword" aria-label="Find a keyword">
                    <?php /* type="button": anything else in a <form method="dialog">
                             submits it and closes the dialog. Drawn only while there
                             is something to rub out. */ ?>
                    <button type="button" class="kw-find-clear" id="kwDialogFindClear"
                            aria-label="Clear" title="Clear" hidden>
                        <span class="material-symbols-outlined" aria-hidden="true">close</span>
                    </button>
                </div>
                <span class="kw-dialog-none" id="kwDialogNone" hidden>No keyword matches that.</span>
                <button type="button" class="btn-sm-outline" id="kwDialogCancel">Cancel</button>
                <button type="button" class="btn-sm-maroon" id="kwDialogDone">Done</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php /* The book viewer, for the Shelf view's books: see the press handler
         in the script above. */ ?>
<?php require ROOT_PATH.'/includes/book_viewer.php'; ?>
<?php require ROOT_PATH.'/includes/browse_console_js.php'; ?>
<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
