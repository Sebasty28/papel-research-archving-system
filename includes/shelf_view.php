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

/* The colours, by programme group, and the palette's own stylesheet: shared
   with My Library, so a paper is one colour on every shelf. */
require_once __DIR__ . '/book_covers.php';

if (!function_exists('shelf_cover_index')) {
    /**
     * Which cover a paper's book wears: its programme's group
     * (book_cover_group(), includes/book_covers.php). Kept under this name
     * because the consoles call it; it used to be an index into a palette of
     * ten, by the programme's place in programs_map(), which coloured a
     * programme by where it happened to sit in a list.
     */
    function shelf_cover_index(?string $program): string
    {
        return book_cover_group($program);
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
     * @param array $b paper_id, title, href (null for a reader who may not
     *                 open it), cover (a group from book_cover_group()), and
     *                 what book_cover_face() stamps on it: band, kind, by,
     *                 by_label, mark. top is still read, as the mark, for a
     *                 caller written before the cover had a foot.
     *                 band_tone is the band's colour on the dashboard.
     *                 viewer, where given, is what the book viewer's card says
     *                 about the paper (includes/book_viewer.php): pressing the
     *                 book then opens the viewer rather than following its link.
     */
    function shelf_book_html(array $b): string
    {
        $id    = (int)($b['paper_id'] ?? 0);
        $href  = $b['href'] ?? null;
        $band  = trim((string)($b['band'] ?? ''));
        $title = (string)($b['title'] ?? '');

        /* The height is the paper's own number, the way no two bound papers on
           a shelf are quite the same height. There is one cover now, the
           hardbound (includes/book_covers.php), so no design number. */
        $attrs = ' data-cover="' . e((string)($b['cover'] ?? 'other')) . '"'
               . ' data-tall="' . ($id % 3) . '"'
               /* How thick it is, which only the Bookshelf view shows: four
                  thicknesses by the paper's number, as no two bound papers on
                  a shelf are the same thickness either. */
               . ' data-thick="' . (intdiv($id, 3) % 4) . '"';
        /* A band is words across the top and, on the dashboard, a colour of its
           own for where a submission has got to. A band_tone of '' is the words
           alone, over the programme's own colour: the repository says a
           manuscript is requested without repainting the book as if its
           programme had changed. */
        $tone = array_key_exists('band_tone', $b) ? (string)$b['band_tone'] : 'draft';
        if ($band !== '' && $tone !== '') { $attrs .= ' data-band="' . e($tone) . '"'; }

        /* The board, and what is stamped on it, are book_cover_face()'s, the
           same as My Library's; only the faces that make it a 3D object on
           this shelf are this file's. */
        $face = '<span class="shelf-cover bk-face">'
              . book_cover_face([
                    'title'    => $title,
                    'band'     => $band,
                    'kind'     => $b['kind'] ?? '',
                    'by'       => $b['by'] ?? '',
                    'by_label' => $b['by_label'] ?? '',
                    'mark'     => $b['mark'] ?? ($b['top'] ?? ''),
                ])
              . '</span>';
        /* A bookmark ribbon, a fourth face of the book, tucked between its
           pages. Drawn only where the slot also holds a Save button and only
           while that button says the paper is saved (see the stylesheet). It
           is part of the book rather than of the button so that it is in the
           book's own 3D space: the cover and the page edge then hide the part
           that is inside, by depth and not by a picture of depth, and it moves
           with the book when the book is taken down off the shelf. */
        $face .= '<span class="shelf-ribbon" aria-hidden="true"></span>';

        /* The spine, a fourth face, on the book's left. Hardbounds turns it
           away; Bookshelf stands the book with it facing out. Stamped as the
           campus's bound papers are, and as the book viewer's spine is: the
           programme at the head, the title, the year at the foot, each between
           a pair of bars. Hidden from a screen reader, which has the cover's
           words already.
           The mark is "programme · year", but either half can be missing, so
           the year is told apart by its shape rather than by its place. The
           dashboard's books have no mark at all: their head is where the
           submission has got to (the band, as on its cover) and their foot the
           year out of the date under the title. */
        $mark  = (string)($b['mark'] ?? ($b['top'] ?? ''));
        $parts = array_values(array_filter(array_map('trim', explode("\u{00b7}", $mark)), 'strlen'));
        $head  = '';
        $year  = '';
        foreach ($parts as $part) {
            if ($year === '' && preg_match('/^(19|20)\d{2}$/', $part)) { $year = $part; }
            elseif ($head === '') { $head = $part; }
        }
        if ($head === '') { $head = $band; }
        if ($year === '' && preg_match('/\b(19|20)\d{2}\b/', (string)($b['by'] ?? ''), $m)) { $year = $m[0]; }
        $spine = '<span class="shelf-spine" aria-hidden="true">';
        if ($head !== '') { $spine .= '<span class="sp-end">' . e($head) . '</span><span class="sp-bars"></span>'; }
        $spine .= '<span class="sp-title">' . e($title) . '</span>';
        if ($year !== '') { $spine .= '<span class="sp-bars"></span><span class="sp-end">' . e($year) . '</span>'; }
        $face .= $spine . '</span>';

        /* A link where the paper can be opened, a span where it cannot (a
           reader who is not signed in, a draft with nothing filed yet). One
           focusable thing per book either way, carrying the cover's own words
           as its name, so the shelf is read and walked like any list of
           links. */
        if (!empty($b['viewer']) && is_array($b['viewer'])) {
            /* One attribute, JSON, rather than six: it is read once, when the
               book is pressed, and the book already carries enough attributes. */
            $attrs .= ' data-bv="' . e(json_encode($b['viewer'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)) . '"';
            /* A reader who may not open the paper still has a book to take off
               the shelf and look at, so that one is pressable too. */
            if ($href === null) { $attrs .= ' tabindex="0" role="button" aria-label="' . e($title) . '"'; }
        }

        return $href === null
            ? '<span class="shelf-book"' . $attrs . '>' . $face . '</span>'
            : '<a class="shelf-book" href="' . e((string)$href) . '"' . $attrs . '>' . $face . '</a>';
    }
}

/* The stylesheet goes out once a page. */
if (defined('PAPEL_SHELF_VIEW_PRINTED')) { return; }
define('PAPEL_SHELF_VIEW_PRINTED', true);
?>
<?php book_covers_style(); ?>
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
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list {
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
html:is([data-view="shelf"], [data-view="bookshelf"]) .layout:has(.dash-shell) > .main-col { align-self: start; }
/* Said again against the two rules that set that height by name, which are
   more particular than the block above and so outrank it: the dashboard gives
   the well height:0 and min-height:10rem and lets flex grow it to the rail
   (includes/console_shell.php, above 901px). A book then stood below the last
   board, the shelf being shorter than the books on it. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list.is-scrollable,
html:is([data-view="shelf"], [data-view="bookshelf"]) .dash-shell .paper-list.is-scrollable {
    height: auto; max-height: none; min-height: 0; flex: none; overflow: visible;
}
/* A book is sized by the shelf, not by how roomy the reader likes a card. */
html:is([data-view="shelf"], [data-view="bookshelf"])[data-density="compact"] .paper-list,
html:is([data-view="shelf"], [data-view="bookshelf"])[data-density="comfortable"] .paper-list { padding: 0 1.25rem; }

/* ===== A result becomes the slot a book stands in =====
   Its own perspective, so a book at the end of a shelf is seen from the same
   angle as one in the middle rather than skewed towards the centre. Every
   card rule it carries in the other two views is taken off here. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-card {
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
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item::before,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item::after,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-card::before,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-card::after { display: none; }

/* The result's own content: the book beside it says the same things, in the
   three lines a cover has room for. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item > *,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-card > * { display: none; }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-book { display: block; }
/* The bookmark stays: it is the one control that still makes sense on a
   shelf, and it sits in the slot's top right corner clear of the book. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item > .save-paper {
    display: inline-flex;
    /* Above the book in the column (it is printed after it), at the right of
       the cover, with the book's own turn making room for it. */
    order: -1;
    align-self: center; margin: 0 0 -10px var(--bw);
    z-index: 4;
}

/* ===== The book ===== */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-book {
    position: relative;
    width: var(--bw); height: var(--bh);
    margin-bottom: 0;
    text-decoration: none; color: var(--cover-foil);
    transform-style: preserve-3d;
    transform: rotateY(-28deg);
    transition: transform .4s cubic-bezier(.2, .8, .2, 1);
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-tall="1"].shelf-book { height: calc(var(--bh) - 9px); }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-tall="2"].shelf-book { height: calc(var(--bh) - 17px); }

a.shelf-book:hover, a.shelf-book:focus-visible { color: var(--cover-foil); }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list a.shelf-book:hover,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list a.shelf-book:focus-visible {
    transform: rotateY(-8deg) translateY(-10px) scale(1.04);
    z-index: 3;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list a.shelf-book:active {
    transform: rotateY(0deg) translateY(-6px) scale(1.02);
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list a.shelf-book:focus-visible { outline: none; }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list a.shelf-book:focus-visible .shelf-cover {
    box-shadow: 0 0 0 2px var(--white), 0 0 0 4px var(--maroon);
}

/* ===== The bookmark, as a ribbon in the pages =====
   Saved, a paper's bookmark is a ribbon tucked between its pages with the
   end standing up out of the top; not saved, it is the mark beside the book
   it always was; and pressing it carries it from the one place to the other.

   **It is a face of the book, not the button.** The button is the slot's
   control and stays where it is; the ribbon is a <span> inside the book, in
   the book's own preserve-3d space, half way through its thickness
   (translateZ(0), between the cover at +bd/2 and the back at -bd/2). So the
   part inside is hidden by the cover and the page edge themselves, by depth
   rather than by a picture of depth, and the ribbon is taken down off the
   shelf with the book when it is hovered. Drawn only where the slot holds a
   Save button (the repository; the dashboard has none) and only while it
   says saved, which is a class the save script already keeps on every copy
   of the button (includes/save_paper.php). */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-ribbon {
    position: absolute;
    /* Near the fore-edge, where a bookmark comes out of a closed book, and
       clear of the page edge's own face so the two planes never cross. */
    right: 13px;
    top: -22px;
    width: 12px;
    height: 74px;
    /* Swallowtailed at the end that shows, the way a ribbon is cut. */
    clip-path: polygon(0 0, 50% 16%, 100% 0, 100% 100%, 0 100%);
    background:
        linear-gradient(90deg, rgba(0, 0, 0, .28), rgba(255, 255, 255, .14) 45%, rgba(0, 0, 0, .22)),
        var(--maroon-surface);
    pointer-events: none;
    opacity: 0;
    /* Out of the book and above it, until it is saved. */
    transform: translateZ(0) translateY(-36px);
    transition: transform .5s cubic-bezier(.2, .9, .25, 1), opacity .25s ease;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item:has(> .save-paper.is-saved) .shelf-ribbon {
    opacity: 1;
    transform: translateZ(0) translateY(0);
}
/* About to be pulled out: the end comes up a little under the pointer. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item:has(> .save-paper.is-saved:hover) .shelf-ribbon,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item:has(> .save-paper.is-saved:focus-visible) .shelf-ribbon {
    transform: translateZ(0) translateY(-6px);
}

/* Saved, the mark beside the book gives way to the ribbon, and the button
   becomes the place to take hold of it: its glyph fades, its hit area moves
   over the ribbon's end, and pressing it there takes the paper back out.
   Not removed: it is still the one control, still focusable, still read out
   as "Remove from My Library". */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item > .save-paper .material-symbols-outlined {
    transition: opacity .25s ease;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item > .save-paper.is-saved .material-symbols-outlined {
    opacity: 0;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item > .save-paper.is-saved {
    width: 26px; height: 30px;
    justify-content: center;
    margin: 0 0 -26px calc(var(--bw) - 50px);
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .paper-item > .save-paper:focus-visible {
    outline: 2px solid var(--maroon); outline-offset: 1px;
}

/* The cover: the front face of the book. Only where it sits in the book is
   this file's; the board and everything stamped on it are .bk-face's, in
   includes/book_covers.php, so a book is the same here and in My Library.
   Nothing below may set its padding, display or background: at this
   selector's weight it would beat .bk-face's and undo the cover. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-cover {
    position: absolute; inset: 0;
    border-radius: 2px 4px 4px 2px;
    transform: translateZ(calc(var(--bd) / 2));
    overflow: hidden;
}
/* The page edges, stood on their side at the book's right. Paper is paper on
   a dark page too, so its ground is a literal white, tinted with the
   palette's own soft tone so the leaves are drawn in its colour. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-book::before {
    content: ''; position: absolute; top: 2px; left: 0;
    width: calc(var(--bd) - 2px); height: calc(100% - 4px);
    transform: translateX(calc(var(--bw) - var(--bd) / 2 - 1px)) rotateY(90deg);
    background: repeating-linear-gradient(90deg,
        color-mix(in srgb, var(--soft-maroon) 8%, #fff) 0 1.5px,
        color-mix(in srgb, var(--soft-maroon) 38%, #fff) 1.5px 2.5px);
}
/* The back of the book, one thickness behind the cover, and the shadow it
   throws on the wall. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-book::after {
    content: ''; position: absolute; inset: 0;
    border-radius: 2px 4px 4px 2px;
    transform: translateZ(calc(var(--bd) / -2));
    background-color: var(--cover, var(--maroon-surface));
    box-shadow: -10px 6px 16px rgba(0, 0, 0, .32);
}

/* ===== What is stamped on it, and its colour =====
   includes/book_covers.php: one hardbound cover, by programme group, which
   My Library reads too. */

/* ===== Where a paper is in the approval chain =====
   The dashboard's covers, which say where a submission has got to rather than
   what programme it belongs to. Four states, each its own colour, and the
   word stamped across the head of the board. Mixed towards the page's own
   ink so they sit in the palette rather than beside it. Gold is stamped on
   all of them, and the spine is a shade of each (book_covers.php works it
   out from --cover on the book itself, so these need say nothing more). */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-band="draft"]    { --cover: hsl(28 10% 34%); }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-band="process"]  { --cover: hsl(38 52% 29%); }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-band="approved"] { --cover: hsl(152 40% 24%); }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-band="declined"] { --cover: var(--maroon-surface); }
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list [data-band="archived"] { --cover: hsl(210 12% 32%); }

/* Nothing to shelve. The notice is a child of the results well, so in Shelf
   it would be laid out as one book-sized cell in the first column, and the
   boards would be drawn behind an empty shelf saying there is nothing on it.
   It takes the whole row, and the wall and its boards come off. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list:has(> .empty-state) {
    display: block; background: none; padding: 0;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list > .empty-state { grid-column: 1 / -1; }

/* ===== The spine, the book's fourth face =====
   On the left of the book, turned to face left, the way the page edge is on
   the right facing right. Hardbounds holds the book turned so it faces away;
   Bookshelf stands the book so it faces the reader. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-spine {
    position: absolute; left: 0; top: 0;
    width: var(--bd); height: 100%;
    transform: translateX(calc(var(--bd) / -2)) rotateY(-90deg);
    display: flex; flex-direction: column; align-items: center;
    gap: 4px; padding: 8px 0 7px;
    background-color: var(--cover-spine, var(--cover));
    background-image:
        /* round, as cloth over a rounded back is: dark at both edges */
        linear-gradient(90deg, rgba(0, 0, 0, .34), rgba(255, 255, 255, .12) 46%, rgba(0, 0, 0, .34)),
        radial-gradient(circle, rgba(255, 255, 255, .05) 0 .5px, transparent .9px),
        radial-gradient(circle, rgba(0, 0, 0, .055) 0 .5px, transparent 1px);
    background-size: auto, 3px 3px, 5px 5px;
    color: var(--cover-spine-foil, var(--cover-foil));
    font-family: var(--font-body), 'Inter', sans-serif;
    font-weight: 800; text-transform: uppercase;
    text-shadow: 0 1px 0 rgba(0, 0, 0, .35);
    border-radius: 2px;
    overflow: hidden;
}
/* Read top to bottom, as a spine standing on a shelf is. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .sp-end,
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .sp-title {
    writing-mode: vertical-rl;
    white-space: nowrap; overflow: hidden;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .sp-end {
    flex-shrink: 0; max-height: 56px;
    font-size: 6px; letter-spacing: .06em;
    /* A programme typed in by hand can be a whole name, and a status
       ("ON PROCESS") is longer than a code. */
    text-overflow: ellipsis;
}
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .sp-bars {
    flex-shrink: 0;
    width: 62%; height: 3px;
    border-top: 1px solid currentColor; border-bottom: 1px solid currentColor;
}
/* As many lines as the spine is thick, centred, with an ellipsis where the
   last one is cut: on one line a spine held about ten letters of a title.
   Held up in the viewer the whole title is on the spine and on the cover.
   -webkit-line-clamp counts lines along the block axis, which in vertical
   writing runs across the spine, so it clamps here as it does on a card. */
html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .sp-title {
    flex: 1 1 auto; min-height: 0;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;
    white-space: normal; overflow-wrap: break-word;
    text-align: center;
    font-size: 7px; letter-spacing: .03em; line-height: 1.05;
    text-overflow: ellipsis;
}

/* ===== Bookshelf: the same books, stood spine out =====
   The way a bookshelf of bound papers looks, after mawise/bookshelf (MIT):
   books side by side, spines out, of different thicknesses and heights, on
   the same boards Hardbounds stands its books on. Pressing a spine hands the
   book to the book viewer, which starts it edge on, where it stood, and pulls
   it out towards the reader turning it to its cover (includes/book_viewer.php).

   It is the Hardbounds book turned a quarter round, not a second book: the
   spine is a face it always had. So the cover, the ribbon and everything the
   viewer copies are the same, and only the turn and the room it takes differ. */
html[data-view="bookshelf"] .paper-list {
    /* Taller than Hardbounds' books, in the proportion of a bound A4 paper
       seen on its spine: at 156px a title had room for about four words, and
       a long one was broken in the middle. Nothing sees the cover on this
       shelf, and the viewer sizes the book it flies out from this height. */
    --bh: 184px;
    display: flex; flex-wrap: wrap;
    align-items: flex-end; align-content: flex-start;
    gap: 0;
}
/* Each slot is as wide as its book's spine and one row tall, so the rows of a
   wrapping flex line fall on the boards painted every --row down the wall. */
html[data-view="bookshelf"] .paper-list .paper-item,
html[data-view="bookshelf"] .paper-list .paper-card {
    --book-h: var(--bh);
    flex: 0 0 auto;
    display: block;
    width: calc(var(--bd) + 3px);
    height: var(--row);
    padding: 0;
    perspective: 900px;
    perspective-origin: 50% 62%;
}
/* Four thicknesses and three heights, by the paper's own number. */
html[data-view="bookshelf"] .paper-list :is(.paper-item, .paper-card):has(> .shelf-book[data-thick="1"]) { --bd: 24px; }
html[data-view="bookshelf"] .paper-list :is(.paper-item, .paper-card):has(> .shelf-book[data-thick="2"]) { --bd: 28px; }
html[data-view="bookshelf"] .paper-list :is(.paper-item, .paper-card):has(> .shelf-book[data-thick="3"]) { --bd: 33px; }
html[data-view="bookshelf"] .paper-list :is(.paper-item, .paper-card):has(> .shelf-book[data-tall="1"]) { --book-h: calc(var(--bh) - 9px); }
html[data-view="bookshelf"] .paper-list :is(.paper-item, .paper-card):has(> .shelf-book[data-tall="2"]) { --book-h: calc(var(--bh) - 17px); }

/* The book stands on the board with its spine centred in the slot: turned a
   quarter round, and pushed back by half its width, so the spine is in the
   plane of the page and drawn at its own size. */
html[data-view="bookshelf"] .paper-list .shelf-book {
    position: absolute; left: 50%; bottom: var(--board);
    margin: 0 0 0 calc(var(--bw) / -2);
    transform: translateZ(calc(var(--bw) / -2)) rotateY(90deg);
}
/* The back board's shadow is cast on the wall behind a book seen face on;
   edge on, it is only a smear down the side of the spine. */
html[data-view="bookshelf"] .paper-list .shelf-book::after { box-shadow: none; }
/* Reached for: drawn a little out of the row and up off the board. */
html[data-view="bookshelf"] .paper-list a.shelf-book:hover,
html[data-view="bookshelf"] .paper-list a.shelf-book:focus-visible,
html[data-view="bookshelf"] .paper-list span.shelf-book:focus-visible {
    transform: translateZ(calc(var(--bw) / -2 + 18px)) translateY(-8px) rotateY(90deg);
    z-index: 3;
}
html[data-view="bookshelf"] .paper-list a.shelf-book:active {
    transform: translateZ(calc(var(--bw) / -2 + 10px)) translateY(-4px) rotateY(90deg);
}
html[data-view="bookshelf"] .paper-list a.shelf-book:focus-visible .shelf-cover { box-shadow: none; }
html[data-view="bookshelf"] .paper-list .shelf-book:focus-visible .shelf-spine {
    box-shadow: 0 0 0 2px var(--white), 0 0 0 4px var(--maroon);
}
/* A thicker spine takes another line, then a larger title. */
html[data-view="bookshelf"] .paper-list .shelf-book[data-thick="1"] .sp-title { font-size: 7.5px; line-height: 1.1; }
html[data-view="bookshelf"] .paper-list .shelf-book[data-thick="2"] .sp-title { -webkit-line-clamp: 3; font-size: 7.5px; line-height: 1.1; }
html[data-view="bookshelf"] .paper-list .shelf-book[data-thick="3"] .sp-title { -webkit-line-clamp: 3; font-size: 8.5px; line-height: 1.08; }

/* The bookmark: the mark sits above the spine; saved, the ribbon stands up
   out of the top of the book behind the spine, turned to face the reader as
   the spine does, and the mark's hit area moves over its end. */
html[data-view="bookshelf"] .paper-list .paper-item > .save-paper {
    position: absolute; left: 50%;
    bottom: calc(var(--board) + var(--book-h) + 6px);
    margin: 0 0 0 -10px;
    order: 0;
}
html[data-view="bookshelf"] .paper-list .paper-item > .save-paper.is-saved {
    width: 20px; height: 26px;
    bottom: calc(var(--board) + var(--book-h) - 4px);
    margin: 0 0 0 -10px;
}
html[data-view="bookshelf"] .paper-list .shelf-ribbon {
    right: auto; left: 2px;
    width: 12px;
    transform: rotateY(-90deg) translateY(-36px);
}
html[data-view="bookshelf"] .paper-list .paper-item:has(> .save-paper.is-saved) .shelf-ribbon {
    transform: rotateY(-90deg) translateY(0);
}
html[data-view="bookshelf"] .paper-list .paper-item:has(> .save-paper.is-saved:hover) .shelf-ribbon,
html[data-view="bookshelf"] .paper-list .paper-item:has(> .save-paper.is-saved:focus-visible) .shelf-ribbon {
    transform: rotateY(-90deg) translateY(-6px);
}

/* ===== Narrow screens =====
   Two to a shelf on a phone, where a third would leave a book 70px wide with
   room for three words. */
@media (max-width: 700px) {
    html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list {
        padding: 0 .5rem;
        grid-template-columns: repeat(auto-fill, minmax(calc(var(--bw) + 18px), 1fr));
    }
}
@media (prefers-reduced-motion: reduce) {
    html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-book,
    html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .shelf-ribbon,
    html:is([data-view="shelf"], [data-view="bookshelf"]) .paper-list .save-paper .material-symbols-outlined { transition: none; }
}
</style>
