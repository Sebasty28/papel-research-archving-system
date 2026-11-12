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
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* =========================================================
   PAPEL: Public Repository landing page
   Design tokens + header/footer/modal CSS now live in
   includes/site_head.php. This block is page-specific only.
   ========================================================= */
:root {
    --sidebar-w:    226px;
    /* The same 6px the dashboards and the review desks leave
       (console_shell.php's own fallback): this page used 36px, so the one
       console with a banner over it was also the one with a corridor down the
       middle. The search field is laid out on the same token, so it widens
       with the results it searches. */
    --col-gap:      6px;
    /* The rendered height of the search field: .75rem of padding above and
       below its content. Named rather than left implicit because the sidebar
       is aligned against it: see .sidebar-right. Change the field's padding
       and this has to follow, or the two tops drift apart again. */
    --search-h:     50px;
    /* The banner's own height, and how far the search field is pulled up into
       it. Named because the photo's size is worked out from them in both
       banner states: see .hero img. Both change at the breakpoints below. */
    --hero-h:       240px;
    --hero-overlap: 24px;
}

/* ===== 3. Breadcrumb strip =====
   The strip's own background lives in site_head.php, which makes it tinted
   glass over the photo behind it. A copy of the solid colour here painted over
   that glass, on the one page whose photo most needed to show through. */
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
/* Current-location indicator: solid white arrow */
.crumb-arrow {
    color: #fff;
    font-size: 20px;
    margin: 0 .125rem;
    --mi-fill: 1;
    --mi-wght: 700;
}
.crumb-current { color: #fff; font-weight: 500; }

/* ===== 4. Hero banner =====
   The box stays the 240px it always was, so nothing below it moves; the photo
   inside is what reaches past it. Upwards it runs to the top of the page, under
   the navbar and the crumb strip, which are both made see-through over it (the
   rules just below). Downwards it runs to the bottom edge of the search field
   and fades out, gone by the field's bottom, so the photo melts into the page
   instead of stopping on a hard line behind the field.

   Neither the header nor the field is a fixed height: the header is 37px on a
   wide screen and 61px once the nav wraps, and the field is 50px or 70px. The
   script at the foot of the page measures both into these variables; the
   values here are only what shows before it runs.

   Hiding the banner does not take the photo away altogether. The strip of it
   behind the navbar and the maroon strip under it stays, so the glass of both
   still has something to show; it stops at the bottom of the maroon strip, and
   everything below goes. The photo is not resized for that: it is clipped.
   Its size is the same in both states, so the part behind the navbar is
   exactly the part that was there before, and nothing slides or rescales as
   it closes. */
.hero {
    --hero-lift: 0px;                             /* page top → top of this box */
    --hero-nav: 0px;                              /* page top → bottom of the crumb strip */
    --hero-fade: var(--search-h);                 /* the field's height */
    /* Bottom of this box → bottom of the field, with the banner open. Worked
       out rather than measured, because measured with the banner hidden it
       would be a different number and the photo would change size. */
    --hero-tail: calc(var(--hero-fade) - var(--hero-overlap));
    /* How far the fade runs: twice the field's height, so it starts one field
       above it. Over the field's height alone, as it first was, it read as a
       hard band: too short a run to fade a photo that bright. */
    --hero-fade-run: calc(var(--hero-fade) * 2);
    position: relative;
    height: var(--hero-h);
    background: none;
    transition: height .35s ease;
}
.hero.collapsed { height: 0; }
.hero img {
    position: absolute;
    left: 0;
    top: calc(-1 * var(--hero-lift));
    width: 100%;
    height: calc(var(--hero-h) + var(--hero-lift) + var(--hero-tail));
    object-fit: cover;
    display: block;
    clip-path: inset(0);
    transition: clip-path .35s ease;
    /* A mask rather than a gradient laid over the photo: it fades to whatever
       the page is painted in, so it needs no colour of its own and follows
       the theme into dark mode.

       Eased, not straight. A linear fade has a visible crease at each end,
       where the rate of change jumps from nothing to full and back, and the
       eye picks those out as the top and bottom of a band. These stops follow
       a cosine ease-in-out (0.5 + 0.5·cos πt), so the photo starts to thin
       imperceptibly and runs out just as gently. */
    -webkit-mask-image: var(--hero-mask);
            mask-image: var(--hero-mask);
    --hero-mask: linear-gradient(to bottom,
        black                  calc(100% - var(--hero-fade-run)),
        rgba(0, 0, 0, .976)    calc(100% - var(--hero-fade-run) * .9),
        rgba(0, 0, 0, .905)    calc(100% - var(--hero-fade-run) * .8),
        rgba(0, 0, 0, .794)    calc(100% - var(--hero-fade-run) * .7),
        rgba(0, 0, 0, .655)    calc(100% - var(--hero-fade-run) * .6),
        rgba(0, 0, 0, .5)      calc(100% - var(--hero-fade-run) * .5),
        rgba(0, 0, 0, .345)    calc(100% - var(--hero-fade-run) * .4),
        rgba(0, 0, 0, .206)    calc(100% - var(--hero-fade-run) * .3),
        rgba(0, 0, 0, .095)    calc(100% - var(--hero-fade-run) * .2),
        rgba(0, 0, 0, .024)    calc(100% - var(--hero-fade-run) * .1),
        transparent            100%);
}
/* Hidden: everything below the maroon strip is clipped away, leaving the top
   --hero-nav of the photo: the part behind the navbar and the strip. The edge
   it leaves is hard, but it falls exactly at the strip's bottom edge, so it
   reads as that edge rather than the photo's. */
.hero.collapsed img { clip-path: inset(0 0 calc(100% - var(--hero-nav)) 0); }

/* Slideshow: every photo is stacked in the same place by the rule above, and
   only the active one is opaque. The transition list is restated in full
   because it replaces the clip-path one rather than adding to it. A slide
   with no src yet (still waiting in data-src) stays transparent whatever its
   class, so a slow network shows the previous photo rather than a gap. */
.hero img.hero-slide {
    opacity: 0;
    transition: clip-path .35s ease, opacity 1.2s ease;
}
.hero img.hero-slide.is-active[src] { opacity: 1; }

/* The search field and the sidebar panel both sit on the photo, and a shadow
   cast only downwards left their top edges to fend for themselves against a
   bright wall. --shadow-up lifts them off it. Only while the banner is up:
   with it hidden there is plain page above both, and nothing to lift from.
   The focus state is restated because it replaces the whole shadow list. */
.hero:not(.collapsed) + .search-band .search-form {
    box-shadow: var(--shadow-up), var(--shadow-md);
}
.hero:not(.collapsed) + .search-band .search-form:focus-within {
    box-shadow: var(--shadow-up), 0 0 0 3px rgba(177,125,125,.20);
}
/* Above 900px, where the panel rises onto the photo beside the field. Below
   that it is a drawer and never touches the banner.

   The panel's shadow wraps its top corners and runs a short way down each
   side before fading, rather than sitting on the top edge alone. A box-shadow
   on the panel itself can only do all of a side or none of it, and all of it
   was a grey glow the full length of the column. So ::before, a strip across
   the top 3rem, casts it instead; its sides are only that tall, and the blur
   fades them out below. The part of its shadow that falls inside the panel
   is covered by ::after, which carries the panel's cream: moved off the
   panel because both pseudo-elements paint above the panel's own background,
   and the strip's shadow would otherwise have smudged it. */
@media (min-width: 901px) {
    .hero:not(.collapsed) ~ .layout .sidebar-right { background: none; }
    .hero:not(.collapsed) ~ .layout .sidebar-right::before,
    .hero:not(.collapsed) ~ .layout .sidebar-right::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        top: 0;
        border-radius: inherit;
        pointer-events: none;
    }
    .hero:not(.collapsed) ~ .layout .sidebar-right::before {
        height: 3rem;
        box-shadow: var(--shadow-up-rim);
        z-index: -2;
    }
    .hero:not(.collapsed) ~ .layout .sidebar-right::after {
        bottom: 0;
        background: var(--cream);
        z-index: -1;
    }
}

/* The photo runs under the crumb strip; site_head.php positions the strip so
   it paints over the photo rather than under it. */

/* The strip itself is tinted glass on every page now (site_head.php), banner
   up or hidden alike: the photo runs behind it in both states, and solid
   maroon would cut it in two with a band. */

/* ===== 5. Search band: sits over the lower edge of the hero,
   left-aligned to the main content column (matches Figma) ===== */
.search-band {
    position: relative;
    z-index: 5;
    margin-top: calc(-1 * var(--hero-overlap));
    transition: margin-top .35s ease;
}
/* With the banner hidden there is no hero to overlap: drop the pull-up
   so the search bar can't ride over the breadcrumb strip. */
.hero.collapsed + .search-band { margin-top: 1.25rem; }
/* Same reasoning for the layout below it: no photo to tuck under, so
   drop its pull-up too or it rides over the search bar/sidebar. */
.hero.collapsed ~ .layout { margin-top: 1rem; }
/* Search field and banner toggle share one row: hiding the banner must not
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
/* The row under the search field is gone: it held the banner eye once (that
   lives in the crumb strip now) and then only the phone's "Browse & Filters"
   button, which is now one of the tool buttons beside Quick Settings. */

/* Banner visibility toggle, in the crumb strip, at the far right, on the
   maroon. It sat in the search row until the banner started running up under
   the navbar; there it was a grey glyph floating on the photo's fade, and it
   moved to a different row whenever the banner was hidden. Here it has the
   same place in both states. */
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
/* Pinned to the strip rather than placed in the crumb row, so it lines up with
   the navbar's own controls directly above it: the navbar runs full width and
   holds them 1.5rem off the right edge at every width, while the crumbs sit
   in the narrower centred .wrap. (.crumb-bar is already positioned, for the
   banner: see the rule after .hero.) Anchored by its right edge, so the
   words slide open leftwards, into the empty middle of the strip.

   Lined up by what can be seen, not by the boxes. Every icon glyph carries
   blank space at its sides, and different amounts: measured at 18px, the eye's
   ink stops 2px inside its box, the avatar chevron's 6px inside its own plus
   the 2px padding of the button around it, the Login icon's 3px plus that
   button's 12px. Matching the boxes left the eye poking out past the chevron,
   so each case steps it in by the difference. */
.crumb-inner .btn-banner-toggle {
    position: absolute;
    right: calc(1.5rem + 6px);     /* signed in: under the chevron (2 + 6 − 2) */
    top: 50%;
    transform: translateY(-50%);
    padding: 0;
    /* Quiet at rest (it is a setting, not something to act on every visit)
       and full strength the moment it is pointed at, pressed or reached from
       the keyboard. It was white held back to 40%, which the strip's maroon
       mixed into a grey; this is the palette's own maroon instead, so the
       glyph reads as the site's colour rather than as a washed white, and
       nothing is faded any more, which leaves the hover a change of colour
       alone.

       The maroon, lightened, rather than the token on its own. The strip is
       82% maroon over the banner photo, and measured against the band as it
       is painted, --maroon itself comes out at 1.18:1 and is simply not
       there. --soft-maroon clears 3:1 but this palette resolves it to a grey
       rose that does not read as the site's colour at all. Mixing --maroon
       toward white keeps the hue and buys the contrast: 5.1:1 where the photo
       behind the band is dark, 2.8:1 at its very lightest. White is the mixing
       operand, not a colour of its own, and is right in either theme because
       the band is dark in both (--maroon-surface-hover does not lift).

       Read the pixels before changing this. The photo moves every five
       seconds, so what it looks like at a glance depends on which photo, and
       the two candidates are a shade apart on screen and far apart measured. */
    color: color-mix(in srgb, var(--maroon) 55%, #fff);
    opacity: 1;
    transition: color .2s ease;
}
.crumb-inner .btn-banner-toggle.is-guest {
    right: calc(1.5rem + 13px);    /* signed out: under the Login icon (12 + 3 − 2) */
}
.crumb-inner .btn-banner-toggle:hover,
.crumb-inner .btn-banner-toggle:active,
.crumb-inner .btn-banner-toggle:focus-visible { color: #fff; opacity: 1; }
/* At rest the control is one glyph. The words are still there: collapsed to
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
    /* 1rem below the search field, banner up or hidden alike. This was
       -1.25rem while the banner eye had a row of its own under the field,
       pulling the results back up over that row; with the row gone the two
       land in the same place. */
    margin-top: 1rem;
    padding-bottom: 3rem;
}
/* The search field and the sidebar are in two different grids (the field in
   .search-row, the sidebar in .layout) so nothing tied their tops together
   and the sidebar sat 66px lower in both banner states.

   66px is not arbitrary: the sidebar column starts where .search-band ends,
   which is the search field (50px) plus the 1rem the layout is offset by.

   Only the sidebar rises; the results column keeps its place. Below 900px the
   layout is a single column and the sidebar follows the content, so the pull
   would drag it over the results: hence the media query. */
@media (min-width: 901px) {
    .sidebar-right {
        margin-top: calc(-1 * (var(--search-h, 50px) + 1rem));
        /* Raised to the search field's line, it now reaches 24px into the hero
           photo: the same overlap the search card has. That card floats over
           the photo on z-index 5; without the same treatment the sidebar is
           static, so the hero paints across its top edge and clips the Browse
           header. */
        position: relative;
        z-index: 5;
    }
}

/* ===== The sidebar on either side: this page's share of it =====
   The column swap itself lives in includes/browse_console.php, with the rest
   of the sidebar. What stays here is the search row, which only this page has:
   the field has to cross to the other column too, or it sits over the sidebar
   instead of over the results it searches. */
@media (min-width: 901px) {
    html.sidebar-left .search-row { grid-template-columns: var(--sidebar-w) 1fr; }
    html.sidebar-left .search-shell { grid-column: 2; }
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
   In one column the Browse and Filter cards land underneath every result:
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
    .layout { grid-template-columns: 1fr; gap: 2rem; margin-top: .75rem; }
    .search-row { grid-template-columns: 1fr; gap: .5rem; }
    /* The photos are 3840x680, which is 5.6 times as wide as it is tall,
       and the box they fill is nothing like that shape once the window
       narrows. Covering it scales the photo to the box's height and throws
       the sides away, so the narrower the window the more it looks zoomed
       in: measured on one photo, the banner showed 75% of it at 1440px, 66%
       at 1280, 49% at 900, 39% at 600 and 29% at 390, which is four book
       spines where a desktop shows a wall of them.

       The box cannot be narrower, and filling it with an undistorted photo
       fixes the crop exactly, so the only way to widen the view is to make
       the box shorter. Two things come out of it, and the tail goes first
       because it costs nothing to see:

       - The tail is the part of the photo that carries on below the banner
         and fades out behind the search field. At 390px it was 28px of box
         for something the field covers anyway, so it is 14px here, with the
         fade shortened to match. The field is inside the page's gutter and
         the photo is not, so a few pixels of tail still have to survive in
         the 16px either side of it: that is what the 14 is for.
       - The banner itself then gives up what it must. 190/150/124 become
         150/115/100.

       Together, at 390px: a box of 237px becomes 199px, and the view widens
       from 29% of the photo to 35%. A phone cannot reach a desktop's
       framing whatever is done here - 85px of the box runs up behind the
       navbar and the maroon strip and is there however short the banner is,
       which caps a 390px screen at 61% even with no banner at all. */
    :root { --hero-h: 150px; }
    .hero {
        --hero-tail: 14px;
        /* The fade has to fit inside a tail this short, or it would start
           above the banner's own bottom edge and thin the photo where it is
           still meant to be solid. */
        --hero-fade-run: 64px;
    }

    /* Everything else about it comes from .toolbar-btn, which it now is; all
       this width decides is whether there is a drawer to open at all. */
    .btn-tools-toggle { display: inline-flex; }
    /* Marked while its drawer is open, the way a pressed tool button would be.
       The drawer covers this button at a phone's width, so this is seen on the
       way out rather than the way in, and on a tablet both are on screen. */
    html.tools-open .btn-tools-toggle { background: var(--cream); color: var(--dark-maroon); }

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

/* A paper this reader has an open manuscript request on is first in the list
   however the list is sorted, and says why: an order nobody can account for
   reads as a fault. */
.paper-pin {
    display: inline-flex;
    align-items: center;
    gap: .2rem;
    padding: .05rem .4rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--maroon) 12%, transparent);
    color: var(--maroon);
    font-family: var(--font-body);
    font-size: .625rem;
    font-weight: 600;
    letter-spacing: .02em;
    text-transform: uppercase;
    white-space: nowrap;
    vertical-align: middle;
}
.paper-pin .material-symbols-outlined { font-size: 13px; line-height: 1; }
/* A grant is the one a reader can act on, so it is the filled one: white on
   the accent, tick and words alike. */
.paper-pin.is-open { background: var(--maroon-surface); color: #fff; }

/* The chip at the foot of the card, under the programme. The column it is in
   is left-aligned, because its links read as a column of text; the chip is a
   mark rather than a line to read, and `margin-left: auto` on a box only as
   wide as its own words carries it to the card's right-hand edge, which is
   the corner the eye goes to last. */
.paper-pin-tag {
    display: flex;
    width: fit-content;
    margin: .3rem 0 0 auto;
}
/* The mark at the head of the title. Only Rows draws it: a row is one line,
   and a third line in its last column would grow every row on the page. */
.paper-pin-mark { display: none; margin-right: .4rem; }
html[data-view="rows"] .paper-list .paper-pin-tag  { display: none; }
html[data-view="rows"] .paper-list .paper-pin-mark { display: inline-flex; }
/* In a row, the tick alone: that line is already cut with an ellipsis, and
   the words are in its tooltip and on the card. */
html[data-view="rows"] .paper-list .paper-pin-mark .pin-word { display: none; }
.paper-pin-mark .pin-word { margin-left: .1rem; }

/* ===== The pager at the foot of the list ==============================
   The pages and the figures, in tokens only: a hardcoded hex breaks the dark
   palette in silence. */
.papel-pager {
    margin: 1.25rem 0 .5rem;
    padding: 1rem 0 .25rem;
    border-top: 1px solid var(--border);
    text-align: center;
}
.pager-nums {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: .1rem .45rem;
    font-size: .8125rem;
}
.pg-num, .pg-step {
    display: inline-flex;
    align-items: center;
    gap: .1rem;
    padding: .15rem .3rem;
    border-radius: var(--r-control, 4px);
    color: var(--maroon);
    text-decoration: none;
}
.pg-num:hover { text-decoration: underline; }
.pg-num:focus-visible, .pg-step:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.pg-num.is-here { color: var(--ink); font-weight: 700; }
.pg-step { font-weight: 600; }
/* **The underline goes under the word, not under the link.** Previous and
   Next are inline-flex boxes holding a word and an arrow, and a decoration on
   a flex container is drawn by each flex item for itself, from its own
   baseline: the word's line sat at one height and the arrow's at another, two
   or three pixels lower, which reads as a mistake rather than as a link. The
   arrow is a mark, not a word, and is not underlined at all. */
.pg-step:hover .pg-word { text-decoration: underline; }
.pg-step .material-symbols-outlined {
    font-size: 16px;
    /* Centred on the word rather than sitting on its own line's floor: the
       glyph box of a Material Symbol is taller than the letters beside it. */
    line-height: 1;
    display: block;
}
.pager-count { margin: .4rem 0 0; font-size: .6875rem; color: var(--grey); }

/* ===== The keyword strip ==============================================
   The papers' own keywords, rolling past above the results. A scroll box, so
   a swipe, a sideways wheel and the keyboard all work without a line of
   script; the script only nudges scrollLeft along and stops when anybody
   touches it. */
/* **A grid item's automatic minimum is its content, not zero.** The strip's
   track is one row of every keyword twice over, about 7,000px, and a scroll
   box does not hide that from the column it sits in: the results column grew
   to 7,184px, the page scrolled sideways, and the strip, being as wide as its
   own content, never scrolled at all, so nothing rolled and nothing could be
   dragged. Measured, not guessed: .layout's columns read `226px 7183.66px`.
   Scoped to this page rather than written into includes/browse_console.php,
   which the dashboards and the four review desks also read. */
.main-col { min-width: 0; }
.kw-rail {
    /* The middle of the toolbar: it takes what the count and the tools leave,
       and never pushes either of them out of the row. */
    flex: 1 1 10rem;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    /* A finger drags it sideways; the page still scrolls up and down under
       the same finger. */
    touch-action: pan-x pan-y;
    scrollbar-width: none;
    -ms-overflow-style: none;
    /* Faded at both ends, so a chip half out of the box reads as more to come
       rather than as a word cut off. */
    -webkit-mask-image: linear-gradient(to right, transparent, #000 1.5rem, #000 calc(100% - 1.5rem), transparent);
            mask-image: linear-gradient(to right, transparent, #000 1.5rem, #000 calc(100% - 1.5rem), transparent);
    cursor: grab;
    /* Dragging it would otherwise select the keywords it is made of, and the
       strip would fill with blue. The chips are links; the words are not
       there to be copied out of a moving strip. */
    user-select: none;
    -webkit-user-select: none;
}
.kw-rail::-webkit-scrollbar { display: none; }
/* Nothing in front of the strip: an empty end of the row takes no space. */
.toolbar-left:empty { display: none; }
/* Shown for a moment when a sixth keyword is pressed, in the colours a
   refusal is said in everywhere else on the site. */
.kw-limit {
    flex: 0 0 auto;
    padding: .05rem .4rem;
    border-radius: 999px;
    background: var(--bad-bg);
    color: var(--bad-text);
    white-space: nowrap;
}
.kw-limit[hidden] { display: none; }

/* ----- Every keyword, in a dialog ----------------------------------- */
.kw-dialog {
    /* Stated: the site's reset takes away the margin a modal dialog centres
       itself with, and it opened in the top left-hand corner. */
    margin: auto;
    width: min(42rem, calc(100vw - 2rem));
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--r-card, 8px);
    background: var(--white);
    color: var(--ink);
    box-shadow: 0 18px 50px rgba(51, 0, 0, .28);
    font-family: var(--font-body), 'Inter', sans-serif;
}
.kw-dialog::backdrop { background: rgba(51, 0, 0, .42); }
.kw-dialog-card { padding: 1rem 1.1rem 1rem; margin: 0; }
.kw-dialog-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: .75rem;
}
.kw-dialog-head h2 {
    margin: 0;
    font-family: var(--font-head), serif;
    font-size: 1.0625rem;
    color: var(--maroon);
}
.kw-dialog-count { font-size: .75rem; color: var(--grey); }
.kw-dialog-note { margin: .25rem 0 .7rem; font-size: .75rem; color: var(--grey); }
/* The one line that says the limit has been met, in the colours a refusal is
   said in everywhere else. */
.kw-dialog-note.is-full { color: var(--bad-text); font-weight: 600; }
.kw-dialog-list {
    display: flex;
    flex-wrap: wrap;
    /* Both of these, or the chips stretch to fill the height the floor below
       gives this box: a search matching one keyword drew it as a pill the
       height of five rows. align-items is the row, align-content the rows
       within the box. */
    align-items: flex-start;
    align-content: flex-start;
    gap: .35rem;
    /* As tall as it needs up to half the window, then it scrolls: there are as
       many keywords as the papers have between them. */
    /* A floor as well as a ceiling: typing in the find field empties the list
       for a moment at a time, and without this the whole dialog shrank to the
       height of its own buttons and grew again on the next letter. */
    min-height: 5.5rem;
    max-height: min(22rem, 50vh);
    /* The wheel stops at the end of this list rather than carrying on into
       whatever is behind the dialog. */
    overscroll-behavior: contain;
    overflow-y: auto;
    padding: .15rem .1rem .4rem;
}
.kw-pick { cursor: pointer; font: inherit; font-size: .75rem; }
.kw-dialog-acts {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding-top: .75rem;
    border-top: 1px solid var(--border);
}
/* The field on the left, the two buttons pushed to the right by it. */
.kw-dialog-find {
    flex: 1 1 10rem;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: .3rem;
    padding: .2rem .5rem;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--white);
    color: var(--grey);
}
.kw-dialog-find:focus-within { border-color: var(--maroon); }
.kw-dialog-find .material-symbols-outlined { font-size: 16px; }
.kw-dialog-find input {
    flex: 1 1 auto;
    min-width: 0;
    border: 0;
    outline: none;
    background: none;
    color: var(--ink);
    font: inherit;
    font-size: .75rem;
}
/* The X at the field's right-hand end. Its room is the field's own padding,
   so the words do not shift along when it appears. */
.kw-find-clear {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.1rem;
    height: 1.1rem;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: none;
    color: var(--grey);
    cursor: pointer;
}
.kw-find-clear:hover { background: var(--cream); color: var(--maroon); }
.kw-find-clear:focus-visible { outline: 2px solid var(--maroon); outline-offset: 1px; }
.kw-find-clear .material-symbols-outlined { font-size: 14px; }
.kw-find-clear[hidden] { display: none; }
.kw-dialog-none { font-size: .6875rem; color: var(--grey); }
.kw-dialog-none[hidden] { display: none; }
/* A chip the find has taken out of view. */
.kw-pick[hidden] { display: none; }

/* **The page behind a dialog does not scroll.** A modal <dialog> holds the
   focus and the top layer but not the wheel: the repository went on scrolling
   under it, which on a page as long as this one is the dialog apparently
   drifting up the window. Locked on <html> and never on <body>: the body
   becomes a scroll box of its own there, and the navbar, which is sticky,
   scrolls away under it. */
html.kw-locked { overflow: hidden; }
@media (max-width: 640px) {
    .kw-dialog { width: calc(100vw - 1.25rem); }
    .kw-dialog-card { padding: .85rem .85rem .85rem; }
    .kw-pick { font-size: .8125rem; padding: .25rem .6rem; }
}
.kw-rail.is-dragging { cursor: grabbing; }
.kw-track { display: flex; width: max-content; }
.kw-set { display: flex; gap: .3rem; padding-right: .3rem; }
.kw-chip {
    display: inline-block;
    padding: .15rem .55rem;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--cream);
    color: var(--maroon);
    font-size: .6875rem;
    line-height: 1.5;
    white-space: nowrap;
    text-decoration: none;
    transition: background .12s ease, color .12s ease, border-color .12s ease;
}
.kw-chip:hover, .kw-chip:focus-visible {
    background: var(--maroon-surface);
    border-color: var(--maroon-surface);
    color: #fff;
}
/* The keyword the results are already narrowed to. */
.kw-chip.is-on {
    background: var(--maroon-surface);
    border-color: var(--maroon-surface);
    color: #fff;
    font-weight: 600;
}
@media (max-width: 900px) {
    /* The keywords take the whole row: on a phone they and the tools on one
       line leave the keywords about 60px. The tools go up beside the heading
       (below), so the strip has the row to itself. */
    .kw-rail { flex-basis: 100%; order: 3; }
    .kw-chip { font-size: .75rem; padding: .2rem .6rem; }

    /* **The tools sit in the column's top right-hand corner**, on the
       heading's own line, rather than on the row below it where they were a
       second line of furniture above the first result.

       Taken out of the flow to get there: the heading and the toolbar are
       siblings in the results column, and two siblings cannot share a line
       without a box around them, which would mean rebuilding the column for
       the narrowest screen only. The column is the positioning context, so
       `right: 0` is its own right-hand edge and not the window's, which is
       what keeps the group flush with the corner rather than near it. The
       heading is given their width as padding so a long one wraps instead of
       running underneath them. */
    .main-col { position: relative; }
    .section-heading { padding-right: 9.5rem; min-height: 2.25rem; }
    .toolbar-right {
        position: absolute;
        top: 0;
        right: 0;
        gap: .1rem;
    }
    /* They are a row of buttons at the top of a page rather than a toolbar
       beside a count: the 40px tap target stays, the box around it goes. */
    .toolbar-right .toolbar-btn { width: 36px; height: 36px; }
    .browse-toolbar { padding-top: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar-right, .tools-backdrop { transition: none; }
    .kw-chip { transition: none; }
}
@media (max-width: 600px) {
    :root { --hero-h: 115px; --hero-overlap: 32px; }
    .section-heading { font-size: 1.375rem; }
    .paper-foot { grid-template-columns: 1fr; gap: .25rem; }
    /* Cards give the title and metadata the whole line. The action and
       programme share a footer without squeezing the date. Rows use the
       aligned, sideways scrolling columns in browse_console.php. */
    .paper-item .paper-side {
        display: flex; align-items: baseline; justify-content: space-between;
        flex-wrap: wrap; gap: .125rem .5rem;
    }
    .paper-item .paper-program {
        order: -1; flex: 1 1 8rem; min-width: 0;
        overflow-wrap: anywhere;
    }
    .paper-meta > span { overflow-wrap: anywhere; }
    /* The page's gutter drops to 1rem here and the menu button goes with it. */
    .crumb-inner .btn-banner-toggle,
    .crumb-inner .btn-banner-toggle.is-guest { right: calc(1rem + 11px); }
}
@media (max-width: 900px) {
    /* The card footer above has a different job in Rows: each part returns
       to the same column as the corresponding part in the next result. */
    html[data-view="rows"] .paper-list .paper-foot { display: contents; }
    html[data-view="rows"] .paper-list .paper-item .paper-side { display: block; flex-wrap: nowrap; }
    html[data-view="rows"] .paper-list .paper-item .paper-program {
        order: 0; flex: none; overflow-wrap: normal;
    }
}
/* The narrowest phones: .75rem of gutter, and the menu button's own padding
   down from 7px to 5px (includes/site_head.php). */
@media (max-width: 380px) {
    .crumb-inner .btn-banner-toggle,
    .crumb-inner .btn-banner-toggle.is-guest { right: calc(.75rem + 9px); }
}
/* A phone, compact: the same notch down the result cards take at this width
   (the block at the foot of includes/browse_console.php), for the three things
   on this page that are not cards. The banner gives up height too: at 150px it
   was a third of the screen before a single result. */
@media (max-width: 480px) {
    :root { --hero-h: 100px; --hero-overlap: 26px; }
    .section-heading { font-size: 1.1875rem; }
}
</style>
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
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current"><?= $has_filters ? 'Search Results' : 'Public Repository' ?></span>
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
<?php /* A slideshow of the campus photos, in a fresh random order on every
         visit: shuffled here rather than in the script so the first photo is
         already a random one at first paint, not swapped in a moment later.
         Only that first one carries a src; the rest wait in data-src until the
         page has loaded (see the slideshow script), so four photos nobody has
         seen yet do not hold up the first paint. The first is the only one
         announced: the others are the same subject and would be read out as
         four more images every five seconds.

         The set is whatever navbar-photo*.jpg is in the images folder, read
         from disk rather than listed here, so a photo can be added or dropped
         by adding or deleting the file: a hard-coded list broke the banner
         the first time one was removed. Made for a 3840×680 canvas. */
      $hero_photos = array_map('basename', glob(ROOT_PATH . '/assests/images/navbar-photo*.jpg') ?: []);
      shuffle($hero_photos); ?>
<section class="hero" id="heroBanner">
    <?php foreach ($hero_photos as $i => $photo): ?>
        <?php if ($i === 0): ?>
            <img class="hero-slide is-active" src="../assests/images/<?= e($photo) ?>" alt="PUP Biñan Campus">
        <?php else: ?>
            <img class="hero-slide" data-src="../assests/images/<?= e($photo) ?>" alt="" aria-hidden="true" decoding="async">
        <?php endif; ?>
    <?php endforeach; ?>
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
            <?php endif; ?>
            <div class="toolbar-right">
                <?php /* The two page arrows stood here, and before that beside the
                         count at the other end of the row. The pages are at the foot
                         of the list, with their numbers; in this row they were two
                         more chevrons next to a strip that is already scrolled
                         sideways, which is the one place a chevron means something
                         else. Their place is now the way to see every keyword at
                         once, which is what the strip beside it cannot do. */ ?>
                <?php if ($keyword_all): ?>
                    <button type="button" class="toolbar-btn" id="kwMoreBtn"
                            title="All keywords" aria-label="All keywords" aria-haspopup="dialog">
                        <span class="material-symbols-outlined">open_in_full</span>
                    </button>
                <?php endif; ?>
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

        <?php if ($total_pages > 1): ?>
            <?php
            /* The pager at the foot of the list, as a search engine draws it:
               the name of the place stretched by one letter per page. PAPEL
               has the E in the middle the way Google has its two o's, so one
               page reads exactly PAPEL and ten read PAPEEEEEEEEEEL.

               It sits **outside** the results well, not at the end of it: the
               well is a box 620px high with its own scrollbar, and in Shelf
               view it is a grid of books on painted boards, where a child of
               it would be laid out as one more book-sized cell with a board
               behind it. The empty state is a child of the well for its own
               reasons and has a rule to turn the grid off; a second such
               exception is worse than standing outside.

               A window of ten pages at most, moving with the page you are on,
               and held against the ends so the last pages still show ten. */
            $pg_window = 10;
            $pg_from   = max(1, min($page - 4, $total_pages - $pg_window + 1));
            $pg_to     = min($total_pages, $pg_from + $pg_window - 1);
            $pg_href   = function (int $n) use ($qs): string {
                return 'index.php?' . $qs . '&page=' . $n;
            };
            ?>
            <nav class="papel-pager" aria-label="Pages of results">
                <?php /* The pages and the count, nothing else. The site's name was
                         stretched across here, one letter a page, and it was a
                         wordmark where a reader wanted a control. */ ?>
                <div class="pager-nums">
                    <?php if ($page > 1): ?>
                        <a class="pg-step" href="<?= e($pg_href($page - 1)) ?>" rel="prev">
                            <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span><span class="pg-word">Previous</span>
                        </a>
                    <?php endif; ?>
                    <?php for ($i = $pg_from; $i <= $pg_to; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="pg-num is-here" aria-current="page"><?= $i ?></span>
                        <?php else: ?>
                            <a class="pg-num" href="<?= e($pg_href($i)) ?>" aria-label="Page <?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a class="pg-step" href="<?= e($pg_href($page + 1)) ?>" rel="next">
                            <span class="pg-word">Next</span><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </a>
                    <?php endif; ?>
                </div>
                <p class="pager-count">
                    Showing <?= $total_papers > 0 ? $start_item : 0 ?>-<?= $end_item ?>
                    of <?= number_format($total_papers) ?> papers
                </p>
            </nav>
        <?php endif; ?>

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

    /* Pressed by hand: that answer is kept, and the scroll below stops
       managing the banner for the rest of this visit. Showing it again and
       having it fold away on the next notch of the wheel is the page
       arguing with the person using it. */
    var bannerManual = false;
    bannerToggle.addEventListener('click', function() {
        var collapsed = !heroBanner.classList.contains('collapsed');
        bannerManual = true;
        applyBannerState(collapsed);
        try { localStorage.setItem('papel_banner_hidden', collapsed ? '1' : '0'); } catch (err) {}
    });

    /* ---- Scrolling down folds the banner away -------------------------
       The photo is 240px of the window (and the same again of a phone's), and
       the results sit in a well 620px high: a reader who has started reading
       the list is looking at the part of the page the banner is pushing down.
       So the first scroll down puts it away, and coming back to the very top
       brings it back.

       Two things this has to respect, both already written down in CLAUDE.md:

       - **A scroll event does not bubble.** On a desktop the window hardly
         scrolls at all here, because the well scrolls inside itself; on a
         phone the window is the scroller. One listener on the document in the
         **capture** phase hears both, and the position read is the two added
         together, so a notch in either one counts.
       - **Folding the banner moves the page under the reader.** The hero
         leaves the flow, the browser clamps the scroll, and that clamp
         arrives as another scroll event: without the lock below it reads as a
         scroll back to the top, the banner comes back, and the pair of them
         flap once a frame. Nothing is decided for half a second after each
         automatic change, which is longer than the clamp and far shorter than
         a second deliberate scroll.

       It is never written to localStorage: this is what the page does while
       it is being read, not an answer the reader has given. Their own answer
       is the toggle, and a banner they hid stays hidden (autoHidden is false,
       so nothing here ever brings it back). */
    var autoHidden = false;
    var lastPos    = 0;
    var lockUntil  = 0;

    function scrollPos() {
        var win  = window.pageYOffset || document.documentElement.scrollTop || 0;
        /* Looked up each time: an AJAX page swap replaces the whole results
           column, well and all, so a reference taken once would be to a node
           that is no longer on the page. */
        var well = document.querySelector('.paper-list.is-scrollable');
        return win + (well ? well.scrollTop : 0);
    }

    document.addEventListener('scroll', function () {
        if (bannerManual) { return; }
        var now  = Date.now();
        var at   = scrollPos();
        var down = at - lastPos;
        lastPos  = at;
        if (now < lockUntil) { return; }

        var collapsed = heroBanner.classList.contains('collapsed');
        if (!collapsed && down > 8 && at > 8) {
            applyBannerState(true);
            autoHidden = true;
            lockUntil  = now + 500;
            lastPos    = scrollPos();
        } else if (collapsed && autoHidden && at <= 2 && down < 0) {
            applyBannerState(false);
            autoHidden = false;
            lockUntil  = now + 500;
            lastPos    = scrollPos();
        }
    }, true);
}

// ===== Banner slideshow: the order was shuffled by the server; this walks
// it, one photo every five seconds. =====
(function () {
    var hero   = document.getElementById('heroBanner');
    var slides = hero ? hero.querySelectorAll('.hero-slide') : [];
    if (slides.length < 2) return;
    /* Someone who has asked their system for less motion gets the one random
       photo and no crossfade every five seconds. */
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var current = 0;
    /* After load, not straight away: the first photo and the results come
       first, and the other four are only needed five seconds from now. */
    window.addEventListener('load', function () {
        slides.forEach(function (img) {
            if (img.dataset.src) { img.src = img.dataset.src; delete img.dataset.src; }
        });
    });

    setInterval(function () {
        /* Nothing to animate with the banner hidden or the tab in the
           background, and advancing unseen would make the photo someone
           returns to a jump rather than a fade. */
        if (document.hidden || hero.classList.contains('collapsed')) return;
        var next = (current + 1) % slides.length;
        /* Not loaded yet (a slow connection): hold on the current photo
           rather than fade to nothing. */
        if (!slides[next].complete || !slides[next].getAttribute('src')) return;
        slides[current].classList.remove('is-active');
        slides[next].classList.add('is-active');
        current = next;
    }, 5000);
})();

// ===== The banner photo's reach: up under the navbar, down to the bottom of
// the search field. Measured, because neither the header nor the field is a
// fixed size (see .hero). Both readings are the same with the banner open or
// hidden (the top of the box never moves, only its height) so the photo
// keeps one size in both states. =====
(function () {
    var hero  = document.getElementById('heroBanner');
    var field = document.querySelector('.search-form');
    var crumb = document.querySelector('.crumb-bar');
    if (!hero || !field) return;
    function fit() {
        var h = hero.getBoundingClientRect();
        hero.style.setProperty('--hero-lift', Math.max(0, Math.round(h.top + window.scrollY)) + 'px');
        hero.style.setProperty('--hero-fade', Math.round(field.getBoundingClientRect().height) + 'px');
        // Where the hidden banner is cut: the bottom of the maroon strip, so
        // the glass of the navbar and of the strip both keep something behind
        // them. Read off the strip because the navbar is sticky and its own
        // position depends on the scroll.
        if (crumb) {
            hero.style.setProperty('--hero-nav',
                Math.max(0, Math.round(crumb.getBoundingClientRect().bottom + window.scrollY)) + 'px');
        }
    }
    fit();
    window.addEventListener('resize', fit);
    /* The header grows when the web fonts land and when the nav wraps, and the
       field changes height at the narrow breakpoints: none of which is a
       window resize on its own. */
    if (window.ResizeObserver) {
        var watch = new ResizeObserver(fit);
        [field, document.querySelector('.site-header'), document.querySelector('.crumb-bar')]
            .forEach(function (el) { if (el) watch.observe(el); });
    }
})();

// ===== AJAX result loading: search, filters, and pagination update just
// the results list instead of reloading the whole page. =====
var mainCol = document.getElementById('mainCol');
var searchForm = document.getElementById('searchForm');
// filterForm is resolved per-event via delegation (the sidebar is swapped too)

// Only reveal the loading state if the fetch is still pending after a
// short delay: keeps fast responses from flashing the overlay on/off.
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

/* Published, because the keyword strip loads results too and is wired in its
   own block above this one. */
window.papelLoadResults = function (url, pushState) { loadResults(url, pushState); };

function loadResults(url, pushState) {
    if (!mainCol) { window.location.href = url; return; }
    setResultsLoading(true);
    var ajaxUrl = url + (url.indexOf('?') > -1 ? '&' : '?') + 'ajax=1';
    fetch(ajaxUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('Request failed'); return r.json(); })
        .then(function (data) {
            /* **Where the keyword strip had been scrolled to.** The strip is
               inside the column this swap replaces, so a fresh one starts at
               its left-hand end: somebody who had scrolled a long way along it
               to find a keyword was put back at the beginning the moment they
               pressed one, and had to scroll all the way out again for the
               next one. Read now rather than when the request went out,
               because the strip can be scrolled while the results are being
               fetched. */
            var railAt = 0;
            var railWas = document.getElementById('kwRail');
            if (railWas) { railAt = railWas.scrollLeft; }

            mainCol.innerHTML = data.html;
            /* Put back before the frame is painted, so it never shows the
               start of the strip and then jumps. The keywords are the same on
               every page of results (they are counted from the whole
               repository, not from the filter), so the figure still means the
               same place in the same list. */
            var railNow = document.getElementById('kwRail');
            if (railNow && railAt) { railNow.scrollLeft = railAt; }
            // Re-render the sidebar too so the filter controls reflect the
            // state the server just applied (selects back to "All", etc.).
            if (data.sidebar) {
                var side = document.getElementById('sidebarCol');
                if (side) side.innerHTML = data.sidebar;
            }
            setResultsLoading(false);
            // The swap replaced the Quick Settings controls: re-tick them.
            if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
            // The swap replaced the keyword strip with a fresh node of its own.
            if (window.papelKeywordRail) window.papelKeywordRail();
            /* The search box is outside the column that was swapped, so it
               keeps whatever it held: after Back, or a keyword pressed from a
               page whose box said something else, it would be showing a search
               that is no longer the one on screen. The address is the
               authority. */
            var box = document.getElementById('searchInput');
            if (box) {
                var asked = '';
                try {
                    asked = new URL(url, window.location.href).searchParams.get('q') || '';
                } catch (err) {}
                if (box.value !== asked) { box.value = asked; }
            }
            if (pushState !== false) history.pushState({ papelAjax: true }, '', url);
        })
        .catch(function () { window.location.href = url; });
}

/* ===== The keyword strip ============================================

   A strip of the papers' own keywords, scrolled by hand: a swipe on a phone, a
   sideways wheel, the keyboard walking the chips, or a mouse drag, which is
   the one gesture a browser does not give for free and is all this block
   builds. It used to roll along by itself; a strip that moves is a strip a
   reader has to catch, so it stands still now.

   Rebound after every AJAX swap, which replaces the whole results column and
   the rail with it: window.papelKeywordRail is called from loadResults() as
   well as on load. */
(function () {
    window.papelKeywordRail = function () {
        var rail = document.getElementById('kwRail');
        if (!rail) { return; }

        var dragging = false;
        var moved    = false;   // a drag, not a press: the click is then dropped
        var fromX = 0, fromLeft = 0;

        /* **A chip is a link, and a link is draggable.** Press one and move and
           the browser starts a drag of its own: it fired `dragstart` after the
           first move, then `pointercancel`, which ended this one a notch in, so
           the strip moved 10px and stuck. Nothing here has anywhere to be
           dropped. */
        rail.addEventListener('dragstart', function (e) { e.preventDefault(); });

        rail.addEventListener('pointerdown', function (e) {
            /* A finger is left to the browser: native touch scrolling has
               momentum and a feel this cannot reproduce. */
            if (e.pointerType !== 'mouse') { return; }
            dragging = true;
            moved    = false;
            fromX    = e.clientX;
            fromLeft = rail.scrollLeft;
            rail.classList.add('is-dragging');
        });

        /* **Followed on the document, and never with setPointerCapture.**
           Capturing the pointer on the rail retargets the compatibility mouse
           events to it, and the click that follows is then raised on the rail
           rather than on the chip under the pointer: `closest('.kw-chip')` came
           back null and pressing a keyword did nothing at all, while a script
           calling .click() on the chip (which is how it was tested) went
           straight past the bug. The same rule as the section rows on the
           upload page, which follow the pointer on the document for a
           different reason. */
        document.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            var by = e.clientX - fromX;
            if (Math.abs(by) > 4) { moved = true; }
            rail.scrollLeft = fromLeft - by;
            e.preventDefault();
        });

        document.addEventListener('pointerup', function () {
            if (!dragging) { return; }
            dragging = false;
            rail.classList.remove('is-dragging');
            /* Only the click this drag is about to raise is swallowed. Cleared
               in a timeout rather than in the click handler, which never runs
               at all when a drag ends off a chip: left set, the flag ate the
               next real press of a keyword instead. */
            setTimeout(function () { moved = false; }, 0);
        });

        /* A press of a chip. Capture, so it runs before the delegated handler
           that loads whatever a link in the results column points at: a keyword
           is not a page to go to, it is one of up to five terms in the search
           box, and pressing it again takes it out.

           The chip's href stays a plain search for that one keyword, which is
           what happens with no script at all. */
        rail.addEventListener('click', function (e) {
            if (moved) {                       // a drag, not a press
                moved = false;
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var chip = e.target.closest ? e.target.closest('.kw-chip') : null;
            if (!chip || !window.papelKeywordPick) { return; }
            e.preventDefault();
            e.stopPropagation();
            window.papelKeywordPick(chip.textContent.trim());
        }, true);
    };

    window.papelKeywordRail();

    /* ---- choosing keywords ------------------------------------------
       The search box is the one place the choice lives: the chips write into
       it, the server reads it (as a comma-separated list of terms, each one
       narrowing the results further), and it is what the reader sees they have
       asked for. Nothing is kept anywhere else, so typing in the box by hand,
       pressing chips, and the Back button cannot disagree about what is
       chosen. */
    var MAX = 5;

    function terms() {
        var box = document.getElementById('searchInput');
        if (!box) { return []; }
        return box.value.split(',').map(function (t) {
            return t.replace(/\s+/g, ' ').trim();
        }).filter(function (t) { return t !== ''; });
    }

    /* The sixth keyword, said in the dialog every console on this site says
       things in (includes/action_dialogs.php, which site_footer.php brings in
       on every page). A chip that simply refused to light up reads as a strip
       that has stopped working; the reader has to be told there is a limit and
       what to do about it, which is to press one of the five again. */
    function sayLimit(chosen) {
        if (typeof window.papelShow === 'function') {
            window.papelShow('Five keywords at most', [
                'You have already chosen five. Press one of them again to take it '
                + 'out, then choose the one you want instead.',
                /* A plain line, not a [label, value] pair: that pair is set in
                   monospace for IDs and passwords, and a list of keywords read
                   as a code somebody had to decipher. */
                'Chosen: ' + chosen.join(', ')
            ]);
            return;
        }
        /* No dialog on the page for some reason: say it where the chips are
           rather than not at all. */
        var note = document.getElementById('kwLimit');
        if (!note) { return; }
        note.hidden = false;
        clearTimeout(note.papelTimer);
        note.papelTimer = setTimeout(function () { note.hidden = true; }, 2600);
    }

    /* **The X in the search field takes every keyword out.** It is the
       browser's own clear button on an `input type="search"`, and it only
       empties the box: the results underneath went on showing the search that
       had just been rubbed out, and the chips went on looking chosen. The
       `search` event is what that button raises (and Enter, which is why a box
       with words still in it is left to the form).

       Bound once, on a field that lives outside the column AJAX swaps replace,
       so unlike the strip it is not rebound after a swap. */
    (function () {
        var box = document.getElementById('searchInput');
        if (!box) { return; }
        box.addEventListener('search', function () {
            if (box.value.trim() !== '') { return; }      // Enter, not the X
            /* Nothing was being searched for: emptying an empty box should not
               fetch the whole repository again. */
            if (!/[?&]q=/.test(window.location.search)) { return; }
            if (window.papelLoadResults) { window.papelLoadResults('index.php?browse=1'); }
            else { window.location.href = 'index.php?browse=1'; }
        });
    })();

    /* ---- every keyword, in the dialog --------------------------------
       The strip shows the commonest thirty; this is the rest of them, and the
       way to pick several without pressing one, waiting for the results, and
       pressing the next. Nothing is applied until Done: Cancel and Escape
       leave the page exactly as it was, which is what a dialog with two
       buttons promises.

       The button that opens it is inside the column AJAX swaps replace, so it
       is reached by delegation; the dialog itself is outside that column and
       is wired once. */
    (function () {
        var dlg = document.getElementById('kwDialog');
        if (!dlg) { return; }
        var card   = document.getElementById('kwDialogCard');
        var list   = document.getElementById('kwDialogList');
        var count  = document.getElementById('kwDialogCount');
        var note   = document.getElementById('kwDialogNote');
        var find   = document.getElementById('kwDialogFind');
        var wipe   = document.getElementById('kwDialogFindClear');
        var none   = document.getElementById('kwDialogNone');
        var done   = document.getElementById('kwDialogDone');
        var cancel = document.getElementById('kwDialogCancel');
        var picked = [];        // in the order they were pressed

        function draw() {
            var full = picked.length >= MAX;
            count.textContent = picked.length + ' of ' + MAX + ' chosen';
            note.classList.toggle('is-full', full);
            note.textContent = full
                ? 'Five keywords at most. Press one of them again to take it out.'
                : 'Choose up to five. Press one again to take it out.';
            var chips = list.querySelectorAll('.kw-pick');
            for (var i = 0; i < chips.length; i++) {
                var on = picked.indexOf(chips[i].getAttribute('data-kw')) > -1;
                chips[i].classList.toggle('is-on', on);
                /* A chip that cannot be added says so to a screen reader as
                   well as by not lighting up, and stays pressable so it can be
                   taken out again. */
                chips[i].setAttribute('aria-pressed', on ? 'true' : 'false');
                chips[i].disabled = false;
            }
        }

        /* Narrowed as it is typed. A chip taken out of view is only hidden: it
           stays chosen and still counts, so finding one keyword, choosing it,
           and then searching for the next does not quietly undo the first. */
        function sift() {
            var want  = (find.value || '').trim().toLowerCase();
            if (wipe) { wipe.hidden = (find.value || '') === ''; }
            var chips = list.querySelectorAll('.kw-pick');
            var shown = 0;
            for (var i = 0; i < chips.length; i++) {
                var hit = !want || chips[i].getAttribute('data-kw').toLowerCase().indexOf(want) > -1;
                chips[i].hidden = !hit;
                if (hit) { shown++; }
            }
            none.hidden = shown > 0;
        }

        function open() {
            /* Seeded from the search box every time: it is the one place the
               choice lives, and this dialog is not redrawn by a swap. */
            picked = terms().slice(0, MAX);
            find.value = '';
            sift();
            draw();
            if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
            /* The page behind it is held still while it is up. */
            document.documentElement.classList.add('kw-locked');
            list.scrollTop = 0;
            if (card && card.focus) { card.focus({ preventScroll: true }); }
        }

        /* However it closes: Done, Cancel, Escape, or the browser's own doing. */
        dlg.addEventListener('close', function () {
            document.documentElement.classList.remove('kw-locked');
        });

        find.addEventListener('input', sift);
        if (wipe) {
            wipe.addEventListener('click', function () {
                find.value = '';
                sift();
                /* Back in the field, which is where somebody who has just
                   cleared it is about to type. */
                find.focus();
            });
        }
        /* The card is a <form method="dialog">, so Enter in this field would
           submit it and close the dialog, throwing away what had been chosen.
           There is nothing to submit: the filtering happens as it is typed. */
        find.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); }
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('#kwMoreBtn') : null;
            if (!btn) { return; }
            e.preventDefault();
            open();
        });

        list.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('.kw-pick') : null;
            if (!chip) { return; }
            var label = chip.getAttribute('data-kw');
            var at = picked.indexOf(label);
            if (at > -1) { picked.splice(at, 1); }
            else if (picked.length >= MAX) { draw(); return; }   // the note says why
            else { picked.push(label); }
            draw();
        });

        cancel.addEventListener('click', function () { dlg.close(); });

        done.addEventListener('click', function () {
            var box = document.getElementById('searchInput');
            dlg.close();
            if (!box) { return; }
            var wanted = picked.join(', ');
            if (box.value === wanted) { return; }   // nothing was changed
            box.value = wanted;
            var url = 'index.php?browse=1' + (wanted ? '&q=' + encodeURIComponent(wanted) : '');
            if (window.papelLoadResults) { window.papelLoadResults(url); }
            else { window.location.href = url; }
        });
    })();

    window.papelKeywordPick = function (label) {
        var box = document.getElementById('searchInput');
        if (!box) { return; }
        var now  = terms();
        var at   = -1;
        for (var i = 0; i < now.length; i++) {
            if (now[i].toLowerCase() === label.toLowerCase()) { at = i; break; }
        }
        if (at > -1) {
            now.splice(at, 1);                 // pressed again: take it out
        } else if (now.length >= MAX) {
            sayLimit(now);                     // five is the limit, and it says so
            return;
        } else {
            now.push(label);
        }
        /* Written into the box before the request goes out, so the words are
           on screen while the results are still being fetched. */
        box.value = now.join(', ');
        var url = 'index.php?browse=1' + (now.length ? '&q=' + encodeURIComponent(box.value) : '');
        if (window.papelLoadResults) { window.papelLoadResults(url); }
        else { window.location.href = url; }
    };
})();

/* ===== A book pressed on the shelf =====
   Taken off the shelf into the book viewer (includes/book_viewer.php) rather
   than followed to the paper's page: the viewer's card has the Read button
   that goes there. A press with a modifier or a middle button is left alone,
   so "open in a new tab" still opens the paper in a new tab. Delegated, and
   in the capture phase so it runs before the handler below that loads
   whatever a link in the results column points at: the books are replaced
   with the column on every AJAX swap. */
/* What the viewer is told about one book: asked for the one pressed, and
   again for each one the viewer's arrows step to. */
function shelfBookInfo(el) {
    var data;
    try { data = JSON.parse(el.getAttribute('data-bv') || '{}'); } catch (err) { return null; }
    var slot = el.closest('.paper-item');
    var save = slot ? slot.querySelector(':scope > .save-paper') : null;
    var face = el.querySelector('.bk-face');
    var mark = el.querySelector('.bk-mark');
    return {
        paperId:  data.id,
        title:    data.title,
        authors:  data.authors,
        kicker:   data.kicker,
        abstract: data.abstract,
        href:     data.href || '',
        signIn:   !!data.signIn,
        program:  data.program || '',
        year:     data.year || '',
        kind:     data.kind || '',
        /* The Save button in the card says what the one on the shelf says, and
           only where there is one: a reader with no library has none. */
        saved:    save ? save.classList.contains('is-saved') : null,
        cover:    el.getAttribute('data-cover'),
        face:     face ? face.innerHTML : '',
        mark:     mark ? mark.textContent : ''
    };
}
function openShelfBook(el) {
    if (!window.papelBookViewer) { return false; }
    var info = shelfBookInfo(el);
    if (!info) { return false; }
    /* The shelf as it stands, in its order, for the viewer's previous and next:
       the page of results on view, which is the shelf the reader is looking at. */
    var items = Array.prototype.slice.call(document.querySelectorAll('.paper-list .shelf-book[data-bv]'));
    /* On the Bookshelf the book stood spine out, a quarter turn from facing:
       it comes off the shelf from there, turning to its cover. */
    var turn = document.documentElement.getAttribute('data-view') === 'bookshelf' ? 90 : -28;
    window.papelBookViewer.open(el, info, { items: items, index: items.indexOf(el), infoFor: shelfBookInfo, turn: turn });
    return true;
}
document.addEventListener('click', function (e) {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
    var view = document.documentElement.getAttribute('data-view');
    if (view !== 'shelf' && view !== 'bookshelf') { return; }
    var el = e.target.closest ? e.target.closest('.paper-list .shelf-book[data-bv]') : null;
    if (el && openShelfBook(el)) { e.preventDefault(); e.stopPropagation(); }
}, true);
/* The book a reader who may not open the paper sees is a span made
   pressable; Enter and Space are what a button answers to. */
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    var el = e.target.closest ? e.target.closest('.paper-list span.shelf-book[data-bv]') : null;
    if (el && openShelfBook(el)) { e.preventDefault(); }
});

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
// delegation: a direct handler would be orphaned on a detached node.
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

/* Right-click is handled once for the whole site in includes/accessibility.php
   (via site_footer.php), which leaves links alone. A second blanket copy here
   put the browser's menu back out of reach on this page only. */

}); // end DOMContentLoaded
</script>
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
                <span class="kw-dialog-count" id="kwDialogCount"></span>
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
