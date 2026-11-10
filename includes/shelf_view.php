<?php
/**
 * Shelf: a third way to look at a list of papers, beside Cards and Rows.
 *
 * Each result becomes a book standing on a shelf board, the same book My
 * Library shelves (app/my_library.php): three faces in CSS, no images, ten
 * covers and four designs, so it follows the palette and the dark page like
 * everything else.
 *
 * Two consoles offer it, and they mean different things by a paper:
 *
 *   The public repository  a paper is something to read, so a cover is
 *                          coloured by its programme.
 *   The student dashboard  a paper is a submission, so a cover is coloured by
 *                          where it has got to and says so across the top. The
 *                          approval track cannot go on a book; it is one click
 *                          away on the paper's own page, as it is from a card.
 *
 * The review desks deliberately do not offer it: a desk is a queue worked
 * through by status, not a shelf browsed.
 *
 * A console prints shelf_book_html() once per result, inside the result and
 * before its own content. Only one of the two is ever shown: in Cards and Rows
 * the book is display:none, and in Shelf the result's own content is. Two
 * copies in the markup rather than one restyled, because a repository row and
 * a dashboard card hold quite different things and a cover holds the same
 * three lines whichever it is standing in. It also keeps the switch instant
 * and lets an AJAX swap know nothing about it, which is the bargain Rows
 * already struck (see includes/browse_console.php).
 *
 * A page declares support in two places: $VIEW_SHELF = true before including
 * browse_console.php, which is read before the first paint so a stored
 * "shelf" is never applied to a page that cannot draw one, and
 * data-view-console="shelf" on its #mainCol, which is what puts Shelf in
 * Quick Settings (see currentView() in browse_console_js.php).
 *
 * Include inside <head>, after browse_console.php.
 */

if (!function_exists('shelf_cover_index')) {
    /**
     * Which of the ten cover colours a paper wears: its programme's place in
     * programs_map(), so a programme is one colour on every shelf and the
     * first (BSIT) is the site's own maroon. One the map does not know is
     * given a colour from its name, so it is at least the same every time.
     *
     * The same function My Library uses, by the same rule, so a paper is the
     * same colour on both shelves.
     */
    function shelf_cover_index(?string $program): int
    {
        $program = trim((string)$program);
        $at = array_search($program, array_keys(programs_map()), true);
        if ($at !== false) { return (int)$at % 10; }
        return $program === '' ? 0 : (int)(abs(crc32($program)) % 10);
    }

    /** "Bering" for one author, "Begino et al." for several: what fits a cover. */
    function shelf_cover_byline(?string $authors): string
    {
        $names = array_values(array_filter(array_map('trim', explode(',', (string)$authors)), 'strlen'));
        if (!$names) { return ''; }
        $parts   = preg_split('/\s+/u', $names[0]) ?: [$names[0]];
        $surname = (string)end($parts);
        return $surname . (count($names) > 1 ? ' et al.' : '');
    }

    /**
     * One book.
     *
     * @param array $b paper_id, title, top (the line above the title),
     *                 by (the byline), href (null for a reader who may not
     *                 open it), cover (0-9), band (a status written across
     *                 the top, dashboard only), band_tone (its colour class).
     */
    function shelf_book_html(array $b): string
    {
        $id    = (int)($b['paper_id'] ?? 0);
        $href  = $b['href'] ?? null;
        $band  = trim((string)($b['band'] ?? ''));
        $title = (string)($b['title'] ?? '');

        /* The height and the design are the paper's own number, not its
           programme: every paper on file is BSIT, and a shelf of one colour
           in one shape is a row of identical books. */
        $attrs = ' data-cover="' . (int)($b['cover'] ?? 0) . '"'
               . ' data-look="' . ($id % 4) . '"'
               . ' data-tall="' . ($id % 3) . '"';
        if ($band !== '') { $attrs .= ' data-band="' . e((string)($b['band_tone'] ?? 'draft')) . '"'; }

        $face = '<span class="shelf-cover">';
        if ($band !== '') {
            $face .= '<span class="shelf-band">' . e($band) . '</span>';
        } elseif (($b['top'] ?? '') !== '') {
            $face .= '<span class="shelf-top">' . e((string)$b['top']) . '</span>';
        }
        $face .= '<span class="shelf-title">' . e($title) . '</span>';
        if (($b['by'] ?? '') !== '') {
            $face .= '<span class="shelf-by">' . e((string)$b['by']) . '</span>';
        }
        $face .= '</span>';

        /* A link where the paper can be opened, a span where it cannot (a
           reader who is not signed in, a draft with nothing filed yet). One
           focusable thing per book either way, carrying the cover's own words
           as its name, so the shelf is read and walked like any list of
           links. */
        return $href === null
            ? '<span class="shelf-book"' . $attrs . '>' . $face . '</span>'
            : '<a class="shelf-book" href="' . e((string)$href) . '"' . $attrs . '>' . $face . '</a>';
    }
}

/* The stylesheet goes out once a page. */
if (defined('PAPEL_SHELF_VIEW_PRINTED')) { return; }
define('PAPEL_SHELF_VIEW_PRINTED', true);
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* Out of the way in the two views that are not this one. */
.shelf-book { display: none; }

/* ===== The shelf =====
   One grid. Every row is --row tall, and the background below it is one board
   (its top, its front edge, the shade under it) plus the shadow the board
   above throws on the wall, sized to a row and repeated down the grid, so
   there is a board under every row however many columns the width allows.
   The tones are mixed from --ink and --white rather than being greys of their
   own. The same construction and the same figures as My Library's shelf. */
html[data-view="shelf"] .paper-list {
    --bw: 108px;                 /* a book's width  */
    --bh: 156px;                 /* its height      */
    --bd: 20px;                  /* its thickness   */
    --board: 20px;               /* the shelf board */
    --row: calc(var(--bh) + 38px + var(--board));
    --wall:       color-mix(in srgb, var(--ink) 4%, var(--white));
    --board-top:  var(--white);
    --board-edge: color-mix(in srgb, var(--ink) 15%, var(--white));
    --board-lip:  color-mix(in srgb, var(--ink) 30%, var(--white));

    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(calc(var(--bw) + 30px), 1fr));
    grid-auto-rows: var(--row);
    gap: 0;
    padding: 0 1.25rem;
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

    /* Sized by its books. The two consoles each give the results well a
       height of its own (the repository scrolls it, the dashboard stretches
       it to the sidebar), and the board is a background repeated down
       whatever that height is: three books on a column built for ten stood
       on one shelf with four empty ones below them. A shelf is as tall as
       what is on it. */
    height: auto; max-height: none; min-height: 0; flex: none;
    overflow: visible;
    align-content: start;
}
/* The rail sets the column's height in Cards; in Shelf the shelf does, and
   stretching it would put the empty boards back. */
html[data-view="shelf"] .layout:has(.dash-shell) > .main-col { align-self: start; }
/* Said again against the two rules that set that height by name, which are
   more particular than the block above and so outrank it: the dashboard gives
   the well height:0 and min-height:10rem and lets flex grow it to the rail
   (includes/console_shell.php, above 901px). A book then stood below the last
   board, the shelf being shorter than the books on it. */
html[data-view="shelf"] .paper-list.is-scrollable,
html[data-view="shelf"] .dash-shell .paper-list.is-scrollable {
    height: auto; max-height: none; min-height: 0; flex: none; overflow: visible;
}
/* A book is sized by the shelf, not by how roomy the reader likes a card. */
html[data-view="shelf"][data-density="compact"] .paper-list,
html[data-view="shelf"][data-density="comfortable"] .paper-list { padding: 0 1.25rem; }

/* ===== A result becomes the slot a book stands in =====
   Its own perspective, so a book at the end of a shelf is seen from the same
   angle as one in the middle rather than skewed towards the centre. Every
   card rule it carries in the other two views is taken off here. */
html[data-view="shelf"] .paper-list .paper-item,
html[data-view="shelf"] .paper-list .paper-card {
    /* A column, so the bookmark rests on the book's own top edge however
       tall that book is. Anchored to the slot instead, it floated a fixed
       distance down from the top and so hung clear above the two shorter
       heights. */
    display: flex; flex-direction: column;
    align-items: center; justify-content: flex-end;
    position: relative;
    margin: 0; padding: 0 0 var(--board); border: 0; border-radius: 0;
    background: none; box-shadow: none; cursor: default;
    perspective: 900px;
    overflow: visible;
}
html[data-view="shelf"] .paper-list .paper-item::before,
html[data-view="shelf"] .paper-list .paper-item::after,
html[data-view="shelf"] .paper-list .paper-card::before,
html[data-view="shelf"] .paper-list .paper-card::after { display: none; }

/* The result's own content: the book beside it says the same things, in the
   three lines a cover has room for. */
html[data-view="shelf"] .paper-list .paper-item > *,
html[data-view="shelf"] .paper-list .paper-card > * { display: none; }
html[data-view="shelf"] .paper-list .shelf-book { display: block; }
/* The bookmark stays: it is the one control that still makes sense on a
   shelf, and it sits in the slot's top right corner clear of the book. */
html[data-view="shelf"] .paper-list .paper-item > .save-paper {
    display: inline-flex;
    /* Above the book in the column (it is printed after it), at the right of
       the cover, with the book's own turn making room for it. */
    order: -1;
    align-self: center; margin: 0 0 -10px var(--bw);
    z-index: 4;
}

/* ===== The book ===== */
html[data-view="shelf"] .paper-list .shelf-book {
    position: relative;
    width: var(--bw); height: var(--bh);
    margin-bottom: 0;
    text-decoration: none; color: #fff;
    transform-style: preserve-3d;
    transform: rotateY(-28deg);
    transition: transform .4s cubic-bezier(.2, .8, .2, 1);
}
html[data-view="shelf"] .paper-list [data-tall="1"].shelf-book { height: calc(var(--bh) - 9px); }
html[data-view="shelf"] .paper-list [data-tall="2"].shelf-book { height: calc(var(--bh) - 17px); }

a.shelf-book:hover, a.shelf-book:focus-visible { color: #fff; }
html[data-view="shelf"] .paper-list a.shelf-book:hover,
html[data-view="shelf"] .paper-list a.shelf-book:focus-visible {
    transform: rotateY(-8deg) translateY(-10px) scale(1.04);
    z-index: 3;
}
html[data-view="shelf"] .paper-list a.shelf-book:active {
    transform: rotateY(0deg) translateY(-6px) scale(1.02);
}
html[data-view="shelf"] .paper-list a.shelf-book:focus-visible { outline: none; }
html[data-view="shelf"] .paper-list a.shelf-book:focus-visible .shelf-cover {
    box-shadow: 0 0 0 2px var(--white), 0 0 0 4px var(--maroon);
}

/* The cover: the face the words are printed on. */
html[data-view="shelf"] .paper-list .shelf-cover {
    --pt: 10px; --pr: 9px; --pb: 9px; --pl: 13px;
    position: absolute; inset: 0;
    display: flex; flex-direction: column;
    padding: var(--pt) var(--pr) var(--pb) var(--pl);
    border-radius: 2px 4px 4px 2px;
    transform: translateZ(calc(var(--bd) / 2));
    background-color: var(--cover, var(--maroon-surface));
    /* The hinge: a soft dark fold down the spine side, then a hairline of
       light where the board turns. */
    background-image: linear-gradient(90deg,
        rgba(0, 0, 0, .30) 0, rgba(0, 0, 0, 0) 7%,
        rgba(255, 255, 255, .13) 8.5%, rgba(255, 255, 255, 0) 11%);
    overflow: hidden;
}
/* The page edges, stood on their side at the book's right. Paper is paper on
   a dark page too, so its ground is a literal white, tinted with the
   palette's own soft tone so the leaves are drawn in its colour. */
html[data-view="shelf"] .paper-list .shelf-book::before {
    content: ''; position: absolute; top: 2px; left: 0;
    width: calc(var(--bd) - 2px); height: calc(100% - 4px);
    transform: translateX(calc(var(--bw) - var(--bd) / 2 - 1px)) rotateY(90deg);
    background: repeating-linear-gradient(90deg,
        color-mix(in srgb, var(--soft-maroon) 8%, #fff) 0 1.5px,
        color-mix(in srgb, var(--soft-maroon) 38%, #fff) 1.5px 2.5px);
}
/* The back of the book, one thickness behind the cover, and the shadow it
   throws on the wall. */
html[data-view="shelf"] .paper-list .shelf-book::after {
    content: ''; position: absolute; inset: 0;
    border-radius: 2px 4px 4px 2px;
    transform: translateZ(calc(var(--bd) / -2));
    background-color: var(--cover, var(--maroon-surface));
    box-shadow: -10px 6px 16px rgba(0, 0, 0, .32);
}

/* ===== What is printed on it ===== */
html[data-view="shelf"] .paper-list .shelf-top,
html[data-view="shelf"] .paper-list .shelf-band {
    font-size: 8.5px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase;
    padding-bottom: 5px; margin-bottom: 7px;
    border-bottom: 1px solid rgba(255, 255, 255, .38);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    flex-shrink: 0;
}
html[data-view="shelf"] .paper-list .shelf-title {
    font-family: var(--font-head); font-size: 11px; font-weight: 700; line-height: 1.24;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6;
    overflow: hidden;
    /* Hyphenated, not cut wherever the line ends: "Communicatio / n" is what
       overflow-wrap: anywhere makes of a long word on a narrow cover. */
    overflow-wrap: break-word; -webkit-hyphens: auto; hyphens: auto;
    /* Never squeezed: a title given less room than its lines need shows the
       top half of the last one. The shorter books get a line fewer instead. */
    flex-shrink: 0;
}
html[data-view="shelf"] .paper-list [data-tall="1"] .shelf-title,
html[data-view="shelf"] .paper-list [data-tall="2"] .shelf-title { -webkit-line-clamp: 5; }
html[data-view="shelf"] .paper-list [data-look="1"][data-tall="2"] .shelf-title { -webkit-line-clamp: 4; }
html[data-view="shelf"] .paper-list .shelf-by {
    margin-top: auto; padding-top: 6px;
    font-size: 8.5px; letter-spacing: .03em; opacity: .88;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* ===== Four designs =====
   The colour is the programme's (or, on the dashboard, the status's), so a
   shelf of one programme would be a row of identical books. The design is the
   paper's own number, modulo four, drawn in black and white washes over
   --cover so each works on every colour.
     0  a rule under the heading   1  the heading in a dark band
     2  a hairline frame           3  a pale foot the byline stands on */
html[data-view="shelf"] .paper-list [data-look="1"] .shelf-top,
html[data-view="shelf"] .paper-list [data-look="1"] .shelf-band {
    margin: calc(var(--pt) * -1) calc(var(--pr) * -1) 8px calc(var(--pl) * -1);
    padding: var(--pt) var(--pr) 6px var(--pl);
    background: rgba(0, 0, 0, .28); border-bottom: 0;
}
html[data-view="shelf"] .paper-list [data-look="2"] .shelf-top,
html[data-view="shelf"] .paper-list [data-look="2"] .shelf-band { border-bottom: 0; margin-bottom: 4px; }
html[data-view="shelf"] .paper-list [data-look="2"] .shelf-cover::after {
    content: ''; position: absolute; inset: 5px 5px 5px 9px;
    border: 1px solid rgba(255, 255, 255, .36); border-radius: 1px; pointer-events: none;
}
html[data-view="shelf"] .paper-list [data-look="3"] .shelf-cover::before {
    content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 24%;
    background: rgba(255, 255, 255, .15);
}
html[data-view="shelf"] .paper-list [data-look="3"] .shelf-by { position: relative; }

/* ===== The ten covers =====
   A cover is an object, not a surface of the page: like the navbar's maroon
   it does not lift on a dark page, and white is printed on all of them. The
   first is the site's own accent, so it changes with the palette; the rest
   are one lightness and one strength of colour apart from it. White clears
   7:1 on every one. The same ten My Library uses, in the same order, so one
   paper is one colour wherever it is shelved. */
html[data-view="shelf"] .paper-list [data-cover="0"] { --cover: var(--maroon-surface); }
html[data-view="shelf"] .paper-list [data-cover="1"] { --cover: hsl(212 38% 30%); }
html[data-view="shelf"] .paper-list [data-cover="2"] { --cover: hsl(158 34% 26%); }
html[data-view="shelf"] .paper-list [data-cover="3"] { --cover: hsl(26 46% 31%); }
html[data-view="shelf"] .paper-list [data-cover="4"] { --cover: hsl(268 28% 34%); }
html[data-view="shelf"] .paper-list [data-cover="5"] { --cover: hsl(188 42% 26%); }
html[data-view="shelf"] .paper-list [data-cover="6"] { --cover: hsl(338 36% 33%); }
html[data-view="shelf"] .paper-list [data-cover="7"] { --cover: hsl(84 28% 27%); }
html[data-view="shelf"] .paper-list [data-cover="8"] { --cover: hsl(232 30% 36%); }
html[data-view="shelf"] .paper-list [data-cover="9"] { --cover: hsl(44 46% 28%); }

/* ===== Where a paper is in the approval chain =====
   The dashboard's covers, which say where a submission has got to rather than
   what programme it belongs to. Four states, each its own colour, and the
   word written across the top of the book. Mixed towards the page's own ink
   so they sit in the palette rather than beside it; white is printed on all
   four and clears 7:1 on each. */
html[data-view="shelf"] .paper-list [data-band="draft"]    { --cover: hsl(28 10% 34%); }
html[data-view="shelf"] .paper-list [data-band="process"]  { --cover: hsl(38 52% 29%); }
html[data-view="shelf"] .paper-list [data-band="approved"] { --cover: hsl(152 40% 24%); }
html[data-view="shelf"] .paper-list [data-band="declined"] { --cover: var(--maroon-surface); }
html[data-view="shelf"] .paper-list [data-band="archived"] { --cover: hsl(210 12% 32%); }

/* Nothing to shelve. The notice is a child of the results well, so in Shelf
   it would be laid out as one book-sized cell in the first column, and the
   boards would be drawn behind an empty shelf saying there is nothing on it.
   It takes the whole row, and the wall and its boards come off. */
html[data-view="shelf"] .paper-list:has(> .empty-state) {
    display: block; background: none; padding: 0;
}
html[data-view="shelf"] .paper-list > .empty-state { grid-column: 1 / -1; }

/* ===== Narrow screens =====
   Two to a shelf on a phone, where a third would leave a book 70px wide with
   room for three words. */
@media (max-width: 700px) {
    html[data-view="shelf"] .paper-list {
        padding: 0 .5rem;
        grid-template-columns: repeat(auto-fill, minmax(calc(var(--bw) + 18px), 1fr));
    }
}
@media (prefers-reduced-motion: reduce) {
    html[data-view="shelf"] .paper-list .shelf-book { transition: none; }
}
</style>
