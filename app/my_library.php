<?php
/**
 * My Library: the papers a reader has saved, kept on a shelf.
 *
 * Every account has one (config/core.php, library_roles()). A paper is put on
 * it with the Save button on the paper's own page or on a repository row
 * (includes/save_paper.php), and taken off the same way.
 *
 * A student's library also holds the manuscripts they have asked a librarian
 * for: Requests is what is waiting or unlocked now, History is what was denied
 * or has run out, each with a way to ask again. Staff read the files anyway, so
 * for them the page is the shelf alone.
 *
 * The tabs are links (?tab=), not panes switched by script: asking again posts
 * and comes back, and a page that comes back should be the tab it left.
 *
 * The shelf is drawn in CSS. A book is a button with three faces (cover, page
 * edge, back) in a 3D space of its own, the boards are a background repeated
 * once per row of the grid, so any number of books at any width stands on a
 * board. Nothing in it is an image, which is how it follows the palette and
 * the dark page like everything else.
 */
require_once '../config/core.php';
require_role(library_roles());

$conn      = db();
$u         = current_user();
$uid       = (int)$u['user_id'];
$isStudent = $u['user_role'] === 'student';

/** A paper anybody signed in may open, from whichever table it now lives in. */
function library_paper_row(int $paperId): ?array {
    $conn = db();
    $stmt = $conn->prepare("SELECT * FROM research_papers WHERE paper_id = ? AND current_status = 'approved'");
    $stmt->bind_param('i', $paperId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) { return $row; }

    $stmt = $conn->prepare("SELECT * FROM papers_archive WHERE paper_id = ?");
    $stmt->bind_param('i', $paperId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ---- Request again, from the History tab ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('my_library.php?tab=history');
    $back = 'history';

    if (($_POST['action'] ?? '') === 'request_again' && $isStudent) {
        $paperId = (int)($_POST['paper_id'] ?? 0);
        $paper   = $paperId > 0 ? library_paper_row($paperId) : null;

        if (!$paper) {
            flash('error', 'That paper is no longer in the repository.');
        } else {
            $hasFile = (bool)array_filter(
                paper_manuscript_entries($paperId, $paper),
                function ($m) { return !empty($m['src']); }
            );
            /* The same helper the paper's own page files a request with, so
               the one-in-progress rule and the librarians' notice are not
               written a second time here. */
            [$filed, $said] = manuscript_request_file($u, $paperId, $paper, $hasFile);
            flash($filed ? 'success' : 'error', $said);
            /* Filed, it is waiting, and waiting is on the other tab. */
            if ($filed) { $back = 'requests'; }
        }
    }

    header('Location: my_library.php?tab=' . $back);
    exit;
}

/* ---- What is on the page ---- */
$saved = saved_papers($uid);

$requests = [];   // waiting for an answer, or unlocked right now
$history  = [];   // denied, or a grant that has run out
if ($isStudent && manuscript_requests_ready()) {
    $stmt = $conn->prepare(
        "SELECT r.*, COALESCE(p.title, pa.title) AS paper_title,
                COALESCE(p.author_names, pa.author_names) AS author_names,
                (p.paper_id IS NOT NULL OR pa.paper_id IS NOT NULL) AS readable
           FROM manuscript_requests r
           LEFT JOIN research_papers p ON p.paper_id = r.paper_id AND p.current_status = 'approved'
           LEFT JOIN papers_archive pa ON pa.paper_id = r.paper_id
          WHERE r.student_user_id = ?
          ORDER BY r.created_at DESC, r.request_id DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Newest first, so the first row met for a paper is its latest request.
       Asking again is offered only on that one, and only once it is settled:
       an older denial under a request that is waiting has nothing to ask. */
    $seen = [];
    foreach ($rows as $r) {
        $pid      = (int)$r['paper_id'];
        $isLatest = !isset($seen[$pid]);
        $seen[$pid] = true;

        $active = $r['status'] === 'granted' && !empty($r['expires_at'])
               && strtotime((string)$r['expires_at']) > time();
        if ($r['status'] === 'pending' || $active) {
            $r['is_active'] = $active;
            $requests[] = $r;
        } else {
            $r['can_again'] = $isLatest && (int)$r['readable'] === 1;
            $r['superseded'] = !$isLatest;
            $history[] = $r;
        }
    }
}

$tabs = ['saved' => 'Saved'];
if ($isStudent) { $tabs += ['requests' => 'Requests', 'history' => 'History']; }
$counts = ['saved' => count($saved), 'requests' => count($requests), 'history' => count($history)];
$tab = (string)($_GET['tab'] ?? 'saved');
if (!isset($tabs[$tab])) { $tab = 'saved'; }

/** How much of a grant is left, in words. */
function library_time_left(string $expiresAt): string {
    $left = strtotime($expiresAt) - time();
    if ($left <= 0) { return 'ended'; }
    $h = intdiv($left, 3600);
    $m = intdiv($left % 3600, 60);
    return $h > 0 ? $h . 'h ' . $m . 'm left' : max(1, $m) . 'm left';
}

/**
 * Which of the ten cover colours a paper wears: its programme's place in
 * programs_map(), so a programme is one colour on every shelf, and the first
 * (BSIT) is the site's own maroon. One the map does not know is given a colour
 * from its name, so it is at least the same colour every time.
 */
function library_cover_index(?string $program): int {
    $program = trim((string)$program);
    $at = array_search($program, array_keys(programs_map()), true);
    if ($at !== false) { return (int)$at % 10; }
    return $program === '' ? 0 : (int)(abs(crc32($program)) % 10);
}

/** "Bering" for one author, "Begino et al." for several: what fits a cover. */
function library_cover_byline(?string $authors): string {
    $names = array_values(array_filter(array_map('trim', explode(',', (string)$authors)), 'strlen'));
    if (!$names) { return ''; }
    $parts   = preg_split('/\s+/u', $names[0]) ?: [$names[0]];
    $surname = (string)end($parts);
    return $surname . (count($names) > 1 ? ' et al.' : '');
}

/** The abstract as plain text, cut to a paragraph. Cut by characters: see "Text cut by bytes is not text". */
function library_excerpt(?string $html): string {
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? ''));
    return mb_strlen($text) > 520 ? rtrim(mb_substr($text, 0, 520)) . '...' : $text;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Library · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH.'/includes/site_head.php'; ?>
<?php /* The dashboard's own search box, cream frame and tab strip, so this
         reads as another desk of the same site rather than a page with a look
         of its own. */ ?>
<?php require_once ROOT_PATH.'/includes/browse_console.php'; ?>
<?php require_once ROOT_PATH.'/includes/console_shell.php'; ?>
<?php require_once ROOT_PATH.'/includes/save_paper.php'; ?>
<?php require_once ROOT_PATH.'/includes/flash_banner.php'; ?>
<style nonce="<?= csp_nonce() ?>">
.lib-wrap { padding-top: 1.5rem; padding-bottom: 3rem; }
.lib-head { margin: 0 0 1rem; }
.lib-title {
    margin: 0; font-family: var(--font-head); font-size: 1.5rem; font-weight: 600;
    color: var(--maroon); line-height: 1.2;
}
.lib-lede { margin: .25rem 0 0; font-size: .875rem; color: var(--grey); }

/* The bar under the search: tabs at the left, the shelf's tools at the right.
   It wraps on a narrow screen, where the two do not fit one line and
   .dash-bar's own nowrap would push the tools off the edge. */
.lib-bar { flex-wrap: wrap; row-gap: .375rem; }
.lib-tools { margin-left: auto; display: flex; align-items: center; gap: .125rem; }
.lib-tools .lib-tools-label {
    font-size: .6875rem; letter-spacing: .06em; text-transform: uppercase;
    color: var(--grey); margin: 0 .25rem 0 .5rem;
}
.lib-tools .dash-tab .material-symbols-outlined { font-size: 18px; }
.lib-tools .lib-rule { width: 1px; height: 1.1rem; background: var(--border); margin: 0 .375rem; }

/* The white card inside the cream frame: a thin rim, modest padding. */
.lib-card {
    background: var(--white); border-radius: var(--r-card, 8px);
    padding: .75rem; box-shadow: 0 1px 2px rgba(51, 0, 0, .06);
}
.lib-note { margin: 0 0 .625rem; font-size: .75rem; color: var(--grey); }

/* ===== The shelf =====
   One grid. Every row is --row tall, and the background below is one board
   (its top, its front edge, the shade under it) and the shadow the board above
   casts on the wall, sized to a row and repeated down the grid. So there is a
   board under every row however many columns the width allows, and the two
   rows the grid is never shorter than are empty shelves waiting. The tones are
   mixed from --ink and --white, never a grey of their own, so they follow the
   palette and the dark page. */
.shelf-room {
    --bw: 108px;                 /* a book's width  */
    --bh: 156px;                 /* its height      */
    --bd: 20px;                  /* its thickness   */
    --board: 20px;               /* the shelf board */
    --row: calc(var(--bh) + 38px + var(--board));
    --wall:       color-mix(in srgb, var(--ink) 4%, var(--white));
    --board-top:  var(--white);
    --board-edge: color-mix(in srgb, var(--ink) 15%, var(--white));
    --board-lip:  color-mix(in srgb, var(--ink) 30%, var(--white));
}
/* The measurements are on the room rather than the shelf so the note on an
   empty shelf, which is the shelf's sibling, is sized from the same row. */
.shelf {
    list-style: none; margin: 0; padding: 0 1.25rem;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(calc(var(--bw) + 30px), 1fr));
    grid-auto-rows: var(--row);
    min-height: calc(var(--row) * 2);
    border-radius: var(--r-control, 4px);
    background-color: var(--wall);
    background-image:
        linear-gradient(to bottom,
            transparent calc(100% - var(--board)),
            var(--board-top)  calc(100% - var(--board)), var(--board-top)  calc(100% - 9px),
            var(--board-edge) calc(100% - 9px),          var(--board-edge) calc(100% - 2px),
            var(--board-lip)  calc(100% - 2px),          var(--board-lip)  100%),
        linear-gradient(to bottom, color-mix(in srgb, var(--ink) 10%, transparent), transparent 22px);
    background-size: 100% var(--row);
    background-repeat: repeat-y;
}
/* A slot is the space one book stands in, and the 3D space it turns in. Each
   has its own perspective, so a book at the end of a shelf is seen from the
   same angle as one in the middle rather than skewed towards the centre. */
.book-slot {
    display: flex; align-items: flex-end; justify-content: center;
    padding-bottom: var(--board);
    perspective: 900px;
    transition: opacity .25s;
}
.book-slot[hidden] { display: none; }
/* Taken off the shelf in the open book's window: it stays, faded, until the
   window closes, so pressing Save again there puts it straight back. */
.book-slot.is-leaving, .lib-row.is-leaving { opacity: .35; }

/* ===== A book =====
   Three faces. The cover is the element inside; the page edge (::before) is a
   strip stood on its side at the right; the back (::after) is the cover again,
   one thickness behind, and carries the shadow the book throws on the wall. */
.book {
    position: relative; display: block;
    width: var(--bw); height: var(--bh);
    padding: 0; border: 0; background: none; cursor: pointer;
    font: inherit; color: #fff; text-align: left;
    /* The cover's own margins, as variables: the banded cover below has to
       undo them exactly, here and at the larger size in the open book. */
    --pt: 10px; --pr: 9px; --pb: 9px; --pl: 13px;
    transform-style: preserve-3d;
    transform: rotateY(-28deg);
    transition: transform .4s cubic-bezier(.2, .8, .2, 1);
}
/* Three heights, by the paper's number, the way no two books on a real shelf
   are quite the same height. Only ever shorter than --bh, so the tallest
   still clears the board above. */
.shelf .book[data-tall="1"] { height: calc(var(--bh) - 9px); }
.shelf .book[data-tall="2"] { height: calc(var(--bh) - 17px); }
/* Taken down a little and turned to face the reader. */
.book:hover, .book:focus-visible { transform: rotateY(-8deg) translateY(-10px) scale(1.04); }
.book:active { transform: rotateY(0deg) translateY(-6px) scale(1.02); }
.book:focus-visible { outline: none; }
.book:focus-visible .book-cover { box-shadow: 0 0 0 2px var(--white), 0 0 0 4px var(--maroon); }

.book-cover {
    position: absolute; inset: 0;
    display: flex; flex-direction: column;
    padding: var(--pt) var(--pr) var(--pb) var(--pl);
    border-radius: 2px 4px 4px 2px;
    transform: translateZ(calc(var(--bd) / 2));
    background-color: var(--cover);
    /* The hinge: a soft dark fold down the spine side, then a hairline of
       light where the board turns. */
    background-image: linear-gradient(90deg,
        rgba(0, 0, 0, .30) 0, rgba(0, 0, 0, 0) 7%,
        rgba(255, 255, 255, .13) 8.5%, rgba(255, 255, 255, 0) 11%);
    overflow: hidden;
}
.book::before {
    content: ''; position: absolute; top: 2px; left: 0;
    width: calc(var(--bd) - 2px); height: calc(100% - 4px);
    transform: translateX(calc(var(--bw) - var(--bd) / 2 - 1px)) rotateY(90deg);
    /* Paper is paper on a dark page too, so its ground is a literal white
       (the same constant the site uses for text on maroon), tinted with the
       palette's own soft tone so the leaves are drawn in its colour. */
    background: repeating-linear-gradient(90deg,
        color-mix(in srgb, var(--soft-maroon) 8%, #fff) 0 1.5px,
        color-mix(in srgb, var(--soft-maroon) 38%, #fff) 1.5px 2.5px);
}
.book::after {
    content: ''; position: absolute; inset: 0;
    border-radius: 2px 4px 4px 2px;
    transform: translateZ(calc(var(--bd) / -2));
    background-color: var(--cover);
    box-shadow: -10px 6px 16px rgba(0, 0, 0, .32);
}

/* What is printed on the cover. */
.book-top {
    font-size: 8.5px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase;
    padding-bottom: 5px; margin-bottom: 7px;
    border-bottom: 1px solid rgba(255, 255, 255, .38);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.book-title {
    font-family: var(--font-head); font-size: 11px; font-weight: 700; line-height: 1.24;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6;
    overflow: hidden;
    /* Hyphenated, not cut wherever the line ends: "Communicatio / n" is what
       overflow-wrap: anywhere made of a long word on a narrow cover. */
    overflow-wrap: break-word; -webkit-hyphens: auto; hyphens: auto;
    /* Never squeezed: a title given less room than its lines need shows the
       top half of the last one, which is what the shorter books did. They are
       given a line fewer below instead. */
    flex-shrink: 0;
}
.shelf .book[data-tall="1"] .book-title,
.shelf .book[data-tall="2"] .book-title { -webkit-line-clamp: 5; }
.shelf .book[data-look="1"][data-tall="2"] .book-title { -webkit-line-clamp: 4; }
.book-by {
    margin-top: auto; padding-top: 6px;
    font-size: 8.5px; letter-spacing: .03em; opacity: .88;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.book-archived {
    position: absolute; right: 0; bottom: 22px;
    padding: 1px 5px; font-size: 7.5px; letter-spacing: .08em; text-transform: uppercase;
    background: rgba(0, 0, 0, .38); border-radius: 2px 0 0 2px;
}

/* ===== Four designs =====
   The colour is the programme's, so a shelf of one programme's papers would be
   a row of identical books: every paper on file when this was built was BSIT.
   The design is the paper's own (its number, modulo four), so two books of one
   colour still differ. All four are drawn in black and white washes over
   --cover, so each works on every colour.
     0  a rule under the heading (the plain cover above)
     1  the heading in a dark band across the top
     2  a hairline frame
     3  a pale foot the byline stands on */
.book[data-look="1"] .book-top {
    margin: calc(var(--pt) * -1) calc(var(--pr) * -1) 8px calc(var(--pl) * -1);
    padding: var(--pt) var(--pr) 6px var(--pl);
    background: rgba(0, 0, 0, .28); border-bottom: 0;
}
.book[data-look="2"] .book-top { border-bottom: 0; margin-bottom: 4px; }
.book[data-look="2"] .book-cover::after {
    content: ''; position: absolute; inset: 5px 5px 5px 9px;
    border: 1px solid rgba(255, 255, 255, .36); border-radius: 1px; pointer-events: none;
}
.book[data-look="3"] .book-cover::before {
    content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 24%;
    background: rgba(255, 255, 255, .15);
}
.book[data-look="3"] .book-by { position: relative; }

/* ===== The ten covers =====
   A cover is an object, not a surface of the page: like the navbar's maroon it
   does not lift on a dark page, and white is printed on all of them. The first
   is the site's own accent, so it changes with the palette; the rest are one
   lightness and one strength of colour apart from it, so the shelf reads as a
   set. White clears 7:1 on every one. */
.book[data-cover="0"] { --cover: var(--maroon-surface); }
.book[data-cover="1"] { --cover: hsl(212 38% 30%); }
.book[data-cover="2"] { --cover: hsl(158 34% 26%); }
.book[data-cover="3"] { --cover: hsl(26 46% 31%); }
.book[data-cover="4"] { --cover: hsl(268 28% 34%); }
.book[data-cover="5"] { --cover: hsl(188 42% 26%); }
.book[data-cover="6"] { --cover: hsl(338 36% 33%); }
.book[data-cover="7"] { --cover: hsl(84 28% 27%); }
.book[data-cover="8"] { --cover: hsl(232 30% 36%); }
.book[data-cover="9"] { --cover: hsl(44 46% 28%); }

/* ===== Shelf or list =====
   The same papers twice in the markup, and the attribute says which is shown:
   a shelf is for looking along, a list for finding one of forty. */
.lib-saved[data-view="shelf"] .lib-list,
.lib-saved[data-view="list"] .shelf { display: none; }

.lib-list, .lib-reqs { list-style: none; margin: 0; padding: 0; }
.lib-row, .lib-req {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .75rem .5rem; border-bottom: 1px solid var(--border-soft);
    transition: opacity .25s;
}
.lib-row[hidden] { display: none; }
.lib-row:last-child, .lib-req:last-child { border-bottom: 0; }
.lib-row-main, .lib-req-main { flex: 1 1 auto; min-width: 0; }
.lib-row-title, .lib-req-title {
    font-family: var(--font-head); font-size: .9375rem; font-weight: 600; line-height: 1.35;
    color: var(--ink); text-decoration: none;
}
.lib-row-title:hover, .lib-req-title:hover { color: var(--maroon); text-decoration: underline; }
.lib-row-sub { margin: .2rem 0 0; font-size: .75rem; color: var(--ink); }
.lib-row-meta, .lib-req-state { margin: .2rem 0 0; font-size: .75rem; color: var(--grey); }
.lib-row .save-paper { flex: 0 0 auto; margin-top: .1rem; }

.lib-req-ico { flex: 0 0 auto; font-size: 20px; color: var(--maroon); margin-top: .1rem; }
.lib-req-state strong { color: var(--ink); font-weight: 600; }
.lib-req-act { flex: 0 0 auto; align-self: center; }
.lib-req-act form { margin: 0; }

/* Nothing here yet, or nothing matching the search. */
.lib-empty { padding: 2.25rem 1rem; text-align: center; color: var(--grey); font-size: .875rem; }
.lib-empty .material-symbols-outlined { display: block; font-size: 32px; color: var(--soft-maroon); margin: 0 auto .5rem; }
.lib-empty a { color: var(--maroon); }
.lib-empty[hidden] { display: none; }
/* On the shelf it stands on the first board, where a book would, rather than
   replacing the shelves. Centred in the whole room it landed across the edge
   of that board, half on the wall and half on the shelf below. */
.shelf-room { position: relative; }
.shelf-room .lib-empty {
    position: absolute; inset: 0 0 auto 0; height: calc(var(--row) - var(--board));
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; padding: 1rem;
}
.shelf-room .lib-empty[hidden] { display: none; }

/* ===== The open book =====
   A <dialog>, so the browser holds the focus inside it, closes it on Escape
   and draws it above everything on the page without a z-index to pick. */
.lib-dialog {
    width: min(44rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem);
    /* Stated, not left to the browser: a modal dialog centres itself with
       margin: auto, and the site's reset (margin: 0 on everything) takes that
       away, which put the window in the top left corner of the page. */
    margin: auto;
    padding: 0; border: 0; border-radius: var(--r-card, 8px);
    background: var(--white); color: var(--ink);
    box-shadow: 0 18px 48px rgba(0, 0, 0, .35);
    overflow: auto;
}
.lib-dialog::backdrop { background: rgba(20, 8, 8, .55); }
.lib-dlg { position: relative; display: flex; gap: 1.5rem; padding: 1.5rem; }
.lib-dlg-book {
    --bw: 150px; --bh: 216px; --bd: 26px;
    flex: 0 0 auto; width: calc(var(--bw) + 34px); padding: .5rem 0 0 .5rem;
    perspective: 1100px;
}
.lib-dlg-book .book { cursor: default; transform: rotateY(-24deg); --pt: 15px; --pr: 13px; --pb: 13px; --pl: 18px; }
.lib-dlg-book .book-top { font-size: 10.5px; padding-bottom: 7px; margin-bottom: 10px; }
.lib-dlg-book .book-title { font-size: 15px; -webkit-line-clamp: 7; }
.lib-dlg-book .book-by { font-size: 10.5px; }
.lib-dlg-body { flex: 1 1 auto; min-width: 0; padding-right: 1.5rem; }
.lib-dlg-kicker { margin: 0 0 .375rem; font-size: .6875rem; letter-spacing: .06em; text-transform: uppercase; color: var(--grey); }
.lib-dlg-title { margin: 0; font-family: var(--font-head); font-size: 1.125rem; font-weight: 600; line-height: 1.3; color: var(--maroon); }
.lib-dlg-authors { margin: .375rem 0 0; font-size: .8125rem; color: var(--ink); }
.lib-dlg-abstract { margin: .875rem 0 0; font-size: .8125rem; line-height: 1.6; color: var(--ink); }
.lib-dlg-abstract:empty { display: none; }
.lib-dlg-saved { margin: .875rem 0 0; font-size: .75rem; color: var(--grey); }
.lib-dlg-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .625rem; margin-top: 1rem; }
.lib-dlg-actions .save-paper.is-labelled { margin-top: 0; }
.lib-dlg-close {
    position: absolute; top: .625rem; right: .625rem;
    display: inline-flex; padding: .25rem; border: 0; background: none;
    color: var(--grey); cursor: pointer; border-radius: var(--r-control, 4px);
}
.lib-dlg-close:hover { color: var(--maroon); background: var(--cream); }

@media (max-width: 700px) {
    .lib-tools { margin-left: 0; width: 100%; }
    .lib-tools .lib-tools-label { margin-left: .25rem; }
    .shelf { padding: 0 .5rem; grid-template-columns: repeat(auto-fill, minmax(calc(var(--bw) + 18px), 1fr)); }
    .lib-dlg { flex-direction: column; align-items: center; gap: 1rem; padding: 1.25rem 1rem; }
    .lib-dlg-book { --bw: 120px; --bh: 173px; --bd: 22px; }
    .lib-dlg-body { padding-right: 0; width: 100%; }
    .lib-req { flex-wrap: wrap; }
    .lib-req-act { width: 100%; padding-left: 2rem; }
    .dash-top { flex-wrap: wrap; }
    .dash-top .btn-upload { width: 100%; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) {
    .book, .book-slot, .lib-row { transition: none; }
}
</style>
</head>
<body>
<?php require ROOT_PATH.'/includes/site_header.php'; ?>

<div class="crumb-bar">
    <div class="wrap crumb-inner">
        <a href="<?= e(BASE_URL) ?>/archive/index.php">Home</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <a href="my_library.php">My Library</a>
        <span class="material-symbols-outlined crumb-arrow">chevron_right</span>
        <span class="crumb-current"><?= e($tabs[$tab]) ?></span>
    </div>
</div>

<main class="wrap lib-wrap">
    <?php flash_banner(); ?>

    <header class="lib-head">
        <h1 class="lib-title">My Library</h1>
        <p class="lib-lede">
            <?= $isStudent
                ? 'The papers you have saved, and the manuscripts you have asked a librarian for.'
                : 'The papers you have saved from the repository.' ?>
        </p>
    </header>

    <div class="dash-shell">
        <div class="dash-top">
            <div class="search-shell">
                <?php /* Narrows the shelf as it is typed in; nothing is sent
                         anywhere, so it is a field and not a form. */ ?>
                <div class="search-form" role="search">
                    <span class="btn-search-icon" aria-hidden="true">
                        <span class="material-symbols-outlined">search</span>
                    </span>
                    <input class="search-input" type="search" id="libSearch" autocomplete="off"
                           placeholder="Search your saved papers" aria-label="Search your saved papers"
                           <?= $tab === 'saved' && $saved ? '' : 'disabled' ?>>
                </div>
            </div>
            <a href="<?= e(BASE_URL) ?>/archive/index.php?browse=1" class="btn-upload">
                <span class="material-symbols-outlined">travel_explore</span> Browse Repository
            </a>
        </div>

        <div class="dash-bar lib-bar">
            <div class="dash-tabs-glide">
                <div class="dash-tabs">
                    <?php foreach ($tabs as $key => $label): ?>
                        <a class="dash-tab <?= $tab === $key ? 'active' : '' ?>"
                           href="my_library.php?tab=<?= e($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
                            <?= e($label) ?> <span class="count" data-count="<?= e($key) ?>"><?= (int)$counts[$key] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($tab === 'saved' && $saved): ?>
                <div class="lib-tools">
                    <span class="lib-tools-label">Sort</span>
                    <button type="button" class="dash-tab active" data-sort="recent" aria-pressed="true">Recently saved</button>
                    <button type="button" class="dash-tab" data-sort="title" aria-pressed="false">Title</button>
                    <button type="button" class="dash-tab" data-sort="year" aria-pressed="false">Year</button>
                    <span class="lib-rule" aria-hidden="true"></span>
                    <button type="button" class="dash-tab active" data-view-as="shelf" aria-pressed="true"
                            title="Shelf" aria-label="Show as a shelf">
                        <span class="material-symbols-outlined" aria-hidden="true">shelves</span>
                    </button>
                    <button type="button" class="dash-tab" data-view-as="list" aria-pressed="false"
                            title="List" aria-label="Show as a list">
                        <span class="material-symbols-outlined" aria-hidden="true">view_list</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="lib-card">
        <?php if ($tab === 'saved'): ?>

            <div class="lib-saved" id="libSaved" data-view="shelf">
                <?php /* Before the shelf is painted, so a reader who keeps the
                         list never sees the shelf first. */ ?>
                <script nonce="<?= csp_nonce() ?>">
                (function () {
                    try {
                        if (localStorage.getItem('papel.libraryView') === 'list') {
                            document.getElementById('libSaved').setAttribute('data-view', 'list');
                        }
                    } catch (err) {}
                })();
                </script>

                <div class="shelf-room">
                    <ul class="shelf" id="libShelf" aria-label="Saved papers, on a shelf">
                        <?php foreach ($saved as $p):
                            $pid     = (int)$p['paper_id'];
                            $code    = program_code($p['program_category'] ?? '');
                            $when    = paper_date_display($p['research_date'] ?? null, $p['year'] ?? null);
                            $type    = !empty($p['paper_type']) ? paper_type_label($p['paper_type']) : '';
                            $kicker  = implode(' • ', array_filter([$code, $type, $when], 'strlen'));
                            $top     = implode(' · ', array_filter([$code, (string)($p['year'] ?? '')], 'strlen'));
                            $savedOn = 'Saved ' . date('F j, Y', strtotime((string)$p['saved_at']));
                        ?>
                            <li class="book-slot"
                                data-paper="<?= $pid ?>"
                                data-search="<?= e(mb_strtolower($p['title'] . ' ' . ($p['author_names'] ?? '') . ' ' . $code)) ?>"
                                data-name="<?= e(mb_strtolower((string)$p['title'])) ?>"
                                data-year="<?= (int)($p['year'] ?? 0) ?>"
                                data-saved="<?= (int)strtotime((string)$p['saved_at']) ?>">
                                <button type="button" class="book" data-cover="<?= library_cover_index($p['program_category'] ?? '') ?>"
                                        data-look="<?= $pid % 4 ?>" data-tall="<?= $pid % 3 ?>"
                                        data-paper="<?= $pid ?>"
                                        data-title="<?= e($p['title']) ?>"
                                        data-authors="<?= e($p['author_names'] ?? '') ?>"
                                        data-kicker="<?= e($kicker) ?>"
                                        data-abstract="<?= e(library_excerpt($p['abstract'] ?? '')) ?>"
                                        data-saved-on="<?= e($savedOn) ?>"
                                        data-href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>"
                                        aria-haspopup="dialog"
                                        aria-label="<?= e($p['title'] . (!empty($p['author_names']) ? ', by ' . $p['author_names'] : '') . '. Open.') ?>">
                                    <span class="book-cover">
                                        <span class="book-top"><?= e($top !== '' ? $top : APP_NAME) ?></span>
                                        <span class="book-title"><?= e($p['title']) ?></span>
                                        <span class="book-by"><?= e(library_cover_byline($p['author_names'] ?? '')) ?></span>
                                        <?php if ((int)$p['is_archived'] === 1): ?>
                                            <span class="book-archived">Archived</span>
                                        <?php endif; ?>
                                    </span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="lib-empty" id="libShelfEmpty" <?= $saved ? 'hidden' : '' ?>>
                        <span class="material-symbols-outlined" aria-hidden="true">bookmark_add</span>
                        <span data-empty-text>Nothing on your shelf yet. Press Save on a paper in the
                            <a href="<?= e(BASE_URL) ?>/archive/index.php?browse=1">repository</a> to keep it here.</span>
                    </div>
                </div>

                <ul class="lib-list" id="libList" aria-label="Saved papers, as a list">
                    <?php foreach ($saved as $p):
                        $pid  = (int)$p['paper_id'];
                        $code = program_code($p['program_category'] ?? '');
                        $meta = array_filter([
                            paper_date_display($p['research_date'] ?? null, $p['year'] ?? null),
                            !empty($p['paper_type']) ? paper_type_label($p['paper_type']) : '',
                            $code,
                            (int)$p['is_archived'] === 1 ? 'Archived' : '',
                            'Saved ' . date('M j, Y', strtotime((string)$p['saved_at'])),
                        ], 'strlen');
                    ?>
                        <li class="lib-row"
                            data-paper="<?= $pid ?>"
                            data-search="<?= e(mb_strtolower($p['title'] . ' ' . ($p['author_names'] ?? '') . ' ' . $code)) ?>"
                            data-name="<?= e(mb_strtolower((string)$p['title'])) ?>"
                            data-year="<?= (int)($p['year'] ?? 0) ?>"
                            data-saved="<?= (int)strtotime((string)$p['saved_at']) ?>">
                            <div class="lib-row-main">
                                <a class="lib-row-title" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>"><?= e($p['title']) ?></a>
                                <?php if (!empty($p['author_names'])): ?>
                                    <p class="lib-row-sub"><?= e($p['author_names']) ?></p>
                                <?php endif; ?>
                                <p class="lib-row-meta"><?= e(implode(' • ', $meta)) ?></p>
                            </div>
                            <?= save_paper_button($pid, true) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="lib-empty" id="libNoMatch" hidden>
                    <span class="material-symbols-outlined" aria-hidden="true">search_off</span>
                    No saved paper matches that search.
                </div>
            </div>

        <?php elseif ($tab === 'requests'): ?>

            <p class="lib-note">Manuscripts you have asked a librarian to unlock. Up to three can be open at once.</p>
            <?php if (!manuscript_requests_ready()): ?>
                <div class="lib-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                    Requests for manuscript access are not open on this site yet. Please ask a librarian in person.
                </div>
            <?php elseif (!$requests): ?>
                <div class="lib-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">lock_open</span>
                    Nothing waiting and nothing unlocked. Ask for a manuscript from its paper's page, under Manuscript.
                </div>
            <?php else: ?>
                <ul class="lib-reqs">
                    <?php foreach ($requests as $r): $pid = (int)$r['paper_id']; ?>
                        <li class="lib-req">
                            <span class="material-symbols-outlined lib-req-ico" aria-hidden="true"><?= $r['is_active'] ? 'lock_open' : 'hourglass_top' ?></span>
                            <div class="lib-req-main">
                                <a class="lib-req-title" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>"><?= e($r['paper_title'] ?? 'A paper no longer in the repository') ?></a>
                                <?php if (!empty($r['author_names'])): ?>
                                    <p class="lib-row-sub"><?= e($r['author_names']) ?></p>
                                <?php endif; ?>
                                <p class="lib-req-state">
                                    <?php if ($r['is_active']): ?>
                                        <strong>Unlocked</strong> until <?= e(date('F j, Y g:i A', strtotime((string)$r['expires_at']))) ?>
                                        (<?= e(library_time_left((string)$r['expires_at'])) ?>).
                                    <?php else: ?>
                                        <strong>Waiting</strong> for a librarian to review it. Asked <?= e(date('F j, Y g:i A', strtotime((string)$r['created_at']))) ?>.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($r['is_active']): ?>
                                <div class="lib-req-act">
                                    <a class="btn-sm-maroon" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>#pd-sec-manuscript">
                                        <span class="material-symbols-outlined mi-18" aria-hidden="true">menu_book</span> Open manuscript
                                    </a>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php else: /* history */ ?>

            <p class="lib-note">Requests that were turned down, and access that has run out.</p>
            <?php if (!$history): ?>
                <div class="lib-empty">
                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                    No past requests.
                </div>
            <?php else: ?>
                <ul class="lib-reqs">
                    <?php foreach ($history as $r):
                        $pid    = (int)$r['paper_id'];
                        $denied = $r['status'] === 'denied';
                        $title  = $r['paper_title'] ?? 'A paper no longer in the repository';
                    ?>
                        <li class="lib-req">
                            <span class="material-symbols-outlined lib-req-ico" aria-hidden="true"><?= $denied ? 'block' : 'lock_clock' ?></span>
                            <div class="lib-req-main">
                                <?php if ((int)$r['readable'] === 1): ?>
                                    <a class="lib-req-title" href="<?= e(BASE_URL) ?>/archive/view_paper.php?id=<?= $pid ?>"><?= e($title) ?></a>
                                <?php else: ?>
                                    <span class="lib-req-title"><?= e($title) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($r['author_names'])): ?>
                                    <p class="lib-row-sub"><?= e($r['author_names']) ?></p>
                                <?php endif; ?>
                                <p class="lib-req-state">
                                    <?php if ($denied): ?>
                                        <strong>Denied</strong> on <?= e(date('F j, Y', strtotime((string)($r['denied_at'] ?: $r['created_at'])))) ?>.
                                    <?php else: ?>
                                        <strong>Ended</strong> on <?= e(date('F j, Y g:i A', strtotime((string)$r['expires_at']))) ?>,
                                        after <?= (int)$r['duration_hours'] ?> hour<?= (int)$r['duration_hours'] === 1 ? '' : 's' ?> of access.
                                    <?php endif; ?>
                                    <?php if ($r['superseded']): ?>You have asked again since.<?php endif; ?>
                                </p>
                            </div>
                            <?php if ($r['can_again']): ?>
                                <div class="lib-req-act">
                                    <form method="post" action="my_library.php?tab=history">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="request_again">
                                        <input type="hidden" name="paper_id" value="<?= $pid ?>">
                                        <button type="submit" class="btn-sm-outline"
                                                data-confirm="Ask a librarian again for the manuscript of &quot;<?= e($title) ?>&quot;?">
                                            <span class="material-symbols-outlined mi-18" aria-hidden="true">refresh</span> Request again
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php endif; ?>
        </div>
    </div>
</main>

<?php if ($tab === 'saved'): ?>
<dialog class="lib-dialog" id="libDialog" aria-labelledby="libDlgTitle">
    <div class="lib-dlg">
        <button type="button" class="lib-dlg-close" data-close aria-label="Close">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
        <div class="lib-dlg-book" aria-hidden="true"></div>
        <div class="lib-dlg-body">
            <p class="lib-dlg-kicker"></p>
            <h2 class="lib-dlg-title" id="libDlgTitle"></h2>
            <p class="lib-dlg-authors"></p>
            <p class="lib-dlg-abstract"></p>
            <p class="lib-dlg-saved"></p>
            <div class="lib-dlg-actions">
                <a class="btn-sm-maroon" data-read href="#">
                    <span class="material-symbols-outlined mi-18" aria-hidden="true">menu_book</span> Read paper
                </a>
                <span data-save-slot></span>
            </div>
        </div>
    </div>
</dialog>

<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function () {
    var root   = document.getElementById('libSaved');
    var shelf  = document.getElementById('libShelf');
    var list   = document.getElementById('libList');
    var dialog = document.getElementById('libDialog');
    if (!root || !shelf || !list) { return; }

    var search    = document.getElementById('libSearch');
    var noMatch   = document.getElementById('libNoMatch');
    var shelfNone = document.getElementById('libShelfEmpty');
    var tools     = document.querySelector('.lib-tools');

    function each(nodes, fn) { Array.prototype.forEach.call(nodes, fn); }
    function entries(id) {
        var want = String(id);
        return Array.prototype.filter.call(root.querySelectorAll('.book-slot, .lib-row'), function (el) {
            return el.getAttribute('data-paper') === want;
        });
    }

    /* ---- Shelf or list ---- */
    function showAs(view) {
        root.setAttribute('data-view', view);
        each(document.querySelectorAll('[data-view-as]'), function (b) {
            var on = b.getAttribute('data-view-as') === view;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        try { localStorage.setItem('papel.libraryView', view); } catch (err) {}
        tally();
    }

    /* ---- Sorting: both copies, so switching view keeps the order ---- */
    var ORDER = {
        recent: function (a, b) { return (+b.dataset.saved) - (+a.dataset.saved); },
        title:  function (a, b) { return a.dataset.name.localeCompare(b.dataset.name); },
        year:   function (a, b) { return (+b.dataset.year) - (+a.dataset.year) || a.dataset.name.localeCompare(b.dataset.name); }
    };
    function sortBy(key) {
        [shelf, list].forEach(function (box) {
            Array.prototype.slice.call(box.children).sort(ORDER[key]).forEach(function (el) { box.appendChild(el); });
        });
        each(document.querySelectorAll('[data-sort]'), function (b) {
            var on = b.getAttribute('data-sort') === key;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    /* ---- What is left, and what to say when nothing is ---- */
    function tally() {
        var all     = shelf.children.length;
        var showing = Array.prototype.filter.call(shelf.children, function (el) { return !el.hidden; }).length;
        var count   = document.querySelector('[data-count="saved"]');
        if (count) { count.textContent = all; }

        /* An empty library says how to fill it, on the shelf itself; a search
           that finds nothing says so underneath, in either view. */
        var listView = root.getAttribute('data-view') === 'list';
        shelfNone.hidden = all > 0;
        noMatch.hidden   = !(all > 0 && showing === 0);
        if (all === 0 && listView) { shelfNone.hidden = false; root.setAttribute('data-view', 'shelf'); }
        if (search) { search.disabled = all === 0; }
        if (tools)  { tools.hidden = all === 0; }
    }

    function filter() {
        var q = search ? search.value.trim().toLowerCase() : '';
        each(root.querySelectorAll('.book-slot, .lib-row'), function (el) {
            el.hidden = q !== '' && el.getAttribute('data-search').indexOf(q) === -1;
        });
        tally();
    }

    /* ---- The open book ---- */
    var openId = null;
    function fill(sel, text) { dialog.querySelector(sel).textContent = text || ''; }

    function openBook(book) {
        if (!dialog || typeof dialog.showModal !== 'function') {
            window.location.href = book.getAttribute('data-href');      // a browser with no <dialog>
            return;
        }
        var d = book.dataset;
        openId = d.paper;
        fill('.lib-dlg-kicker',   d.kicker);
        fill('.lib-dlg-title',    d.title);
        fill('.lib-dlg-authors',  d.authors);
        fill('.lib-dlg-abstract', d.abstract);
        fill('.lib-dlg-saved',    d.savedOn);
        dialog.querySelector('[data-read]').setAttribute('href', d.href);

        /* The same book, larger: a copy of its faces with everything that made
           it a control taken off, since this one is only a picture. */
        var stage = dialog.querySelector('.lib-dlg-book');
        var copy  = document.createElement('span');
        copy.className = 'book';
        copy.setAttribute('data-cover', book.getAttribute('data-cover'));
        copy.setAttribute('data-look', book.getAttribute('data-look'));
        copy.appendChild(book.querySelector('.book-cover').cloneNode(true));
        stage.textContent = '';
        stage.appendChild(copy);

        /* The shared Save button, built here in the saved state: the script in
           includes/save_paper.php answers for it like any other. */
        var slot = dialog.querySelector('[data-save-slot]');
        var btn  = document.createElement('button');
        btn.type = 'button';
        btn.className = 'save-paper is-labelled is-saved';
        btn.setAttribute('data-save-paper', d.paper);
        btn.setAttribute('aria-pressed', 'true');
        btn.title = 'Remove from My Library';
        var ico = document.createElement('span');
        ico.className = 'material-symbols-outlined';
        ico.setAttribute('aria-hidden', 'true');
        ico.textContent = 'bookmark';
        var word = document.createElement('span');
        word.className = 'save-paper-label';
        word.textContent = 'Saved';
        btn.appendChild(ico); btn.appendChild(word);
        slot.textContent = '';
        slot.appendChild(btn);

        dialog.showModal();
    }

    function takeOut(id) {
        entries(id).forEach(function (el) { el.remove(); });
        filter();
    }

    if (dialog) {
        dialog.addEventListener('click', function (e) {
            /* The dialog has no padding, so a click that lands on the dialog
               itself is a click on the backdrop round it. */
            if (e.target === dialog || (e.target.closest && e.target.closest('[data-close]'))) { dialog.close(); }
        });
        dialog.addEventListener('close', function () {
            /* Taken off the shelf while it was open, and not put back: gone
               now that there is no window left to put it back from. */
            if (openId !== null && entries(openId).some(function (el) { return el.classList.contains('is-leaving'); })) {
                takeOut(openId);
            }
            openId = null;
        });
    }

    shelf.addEventListener('click', function (e) {
        var book = e.target.closest ? e.target.closest('.book') : null;
        if (book) { openBook(book); }
    });

    /* Saved or unsaved, here or in another window on the same paper. */
    document.addEventListener('papel:paper-saved', function (e) {
        if (!e.detail) { return; }
        var id = String(e.detail.paperId), here = entries(id);
        if (!here.length) { return; }
        if (e.detail.saved) {
            here.forEach(function (el) { el.classList.remove('is-leaving'); });
        } else if (dialog && dialog.open && openId === id) {
            here.forEach(function (el) { el.classList.add('is-leaving'); });
        } else {
            takeOut(id);
        }
    });

    if (search) { search.addEventListener('input', filter); }
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-sort], [data-view-as]') : null;
        if (!t) { return; }
        if (t.hasAttribute('data-sort')) { sortBy(t.getAttribute('data-sort')); }
        else { showAs(t.getAttribute('data-view-as')); }
    });

    showAs(root.getAttribute('data-view') === 'list' ? 'list' : 'shelf');
});
</script>
<?php endif; ?>

<?php require ROOT_PATH.'/includes/site_footer.php'; ?>
</body>
</html>
