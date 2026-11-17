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
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-shelf-view.css')) ?>">
