<?php
/**
 * A paper's book: its colour, and the one cover every book wears.
 *
 * Both shelves draw from this, the public repository's Shelf view
 * (includes/shelf_view.php) and My Library (app/my_library.php), together with
 * My Library's open-book dialog, so one paper is one book wherever it stands.
 * Each file keeps only what makes its book a 3D object in its own place (the
 * faces, the turn, the shelf); the board and everything printed on it are here.
 *
 * ---- The colour -------------------------------------------------------------
 * By the programme the paper was filed under, grouped the way the campus groups
 * them, each group a colour of its own (two, for the programmes that wear two):
 *
 *   edu    Education: BSEd English, BSEd Social Studies, BEEd   Blue
 *   hrm    BSBA Human Resource Management                       Red / Yellow
 *   psych  BS Psychology                                        Purple
 *   it     BSIT and the Diploma in IT                           Yellow / Blue
 *   cpe    BS Computer Engineering and DCPET                    Maroon / Black
 *   ie     BS Industrial Engineering                            Green
 *   other  anything else: the retired BS Accountancy, a
 *          programme typed in, a paper filed with none          a neutral brown
 *
 * It used to be an index into a palette of ten by the programme's position in
 * programs_map(), carried twice, once per shelf: a colour belonged to wherever a
 * programme happened to sit in a list, so adding one shifted every colour after
 * it, and related programmes came out in unrelated colours.
 *
 * ---- The cover --------------------------------------------------------------
 * One design: the hardbound a Philippine research paper is bound in. A plain
 * leatherette board, everything on it stamped in gold foil, in capitals,
 * centred: the title at the head, the kind of paper under it, then at the foot
 * who presented it and the programme and year. The spine cloth wraps a few
 * millimetres onto the board, with the hinge pressed into it beside that.
 *
 * It replaced four designs (a rule, a band, a frame, a pale foot) chosen by the
 * paper's number, which gave a shelf of one programme some variety and looked
 * like no book anybody at the campus has handed in.
 *
 * Four values make a cover:
 *   --cover          the board
 *   --cover-spine    the spine cloth where it wraps onto the board. A darker
 *                    shade of the board for a one-colour programme, and the
 *                    second colour for the three that wear two, which is how a
 *                    quarter-bound book is two colours.
 *   --cover-foil     the stamping: gold on every board but one
 *   --cover-foil-shadow  the edge the stamp is pressed in with
 *
 * The IT board is the one light one: gold on yellow cannot be read, so it is
 * stamped in navy and its edge is a highlight rather than a shadow. Gold clears
 * 4.5:1 on every other board; navy clears 9:1 on the yellow.
 *
 * They are object colours, like the navbar's maroon, and do not lift on a dark
 * page: a blue book is a blue book in either palette. So they are written as
 * colours rather than drawn from the page's tokens, which is the one place the
 * site does that.
 */

if (!function_exists('book_cover_group')) {
    /**
     * Which group a programme's books are coloured as.
     *
     * By the programme's code where it is a known one, and by the words in its
     * name otherwise, so a programme typed a little differently on an old paper
     * still lands with the rest of its kind rather than in `other`.
     */
    function book_cover_group(?string $program): string
    {
        $program = trim((string)$program);
        if ($program === '') { return 'other'; }

        $byCode = [
            'BSED-ENG' => 'edu', 'BSED-SS' => 'edu', 'BEED' => 'edu',
            'BSBA-HRM' => 'hrm',
            'BSPSYCH'  => 'psych',
            'BSIT'     => 'it',  'DIT'   => 'it',
            'BSCPE'    => 'cpe', 'DCPET' => 'cpe',
            'BSIE'     => 'ie',
        ];
        $code = function_exists('program_code') ? strtoupper(program_code($program)) : strtoupper($program);
        if (isset($byCode[$code])) { return $byCode[$code]; }

        $name = mb_strtolower($program);
        $byWords = [
            'education'               => 'edu',
            'human resource'          => 'hrm',
            'business administration' => 'hrm',
            'psychology'              => 'psych',
            'information technology'  => 'it',
            'computer engineering'    => 'cpe',
            'industrial engineering'  => 'ie',
        ];
        foreach ($byWords as $words => $group) {
            if (strpos($name, $words) !== false) { return $group; }
        }
        return 'other';
    }

    /**
     * What is stamped on a cover: the inside of the element that is the board.
     *
     * The caller owns that element (a <span class="shelf-cover bk-face"> on the
     * repository, a <span class="book-cover bk-face"> in My Library) because it
     * is the 3D face of a book built differently in each place; what is printed
     * on it is the same everywhere, so it is written once.
     *
     * @param array $b title; and, each optional:
     *                 band      words across the head of the board (a status on
     *                           the dashboard, a request on the repository)
     *                 kind      what kind of paper it is ("Undergraduate Thesis")
     *                 by        the byline ("Bering et al.")
     *                 by_label  printed above the byline ("Presented by"); left
     *                           out where `by` is not a person, as on the student
     *                           dashboard, which puts the date there
     *                 mark      the programme and year at the foot ("BSIT · 2025")
     */
    function book_cover_face(array $b): string
    {
        $html = '';
        $band = trim((string)($b['band'] ?? ''));
        if ($band !== '') {
            $html .= '<span class="bk-band">' . e($band) . '</span>';
        }
        $html .= '<span class="bk-title">' . e((string)($b['title'] ?? '')) . '</span>';
        $kind = trim((string)($b['kind'] ?? ''));
        if ($kind !== '') {
            $html .= '<span class="bk-kind">' . e($kind) . '</span>';
        }

        $foot = '';
        $by = trim((string)($b['by'] ?? ''));
        if ($by !== '') {
            $label = trim((string)($b['by_label'] ?? ''));
            if ($label !== '') { $foot .= '<span class="bk-by-label">' . e($label) . '</span>'; }
            $foot .= '<span class="bk-by">' . e($by) . '</span>';
        }
        $mark = trim((string)($b['mark'] ?? ''));
        if ($mark !== '') {
            $foot .= '<span class="bk-mark">' . e($mark) . '</span>';
        }
        if ($foot !== '') { $html .= '<span class="bk-foot">' . $foot . '</span>'; }
        return $html;
    }
}

if (!function_exists('book_excerpt')) {
    /**
     * A paper's abstract as plain text, cut to a paragraph: what the book
     * viewer's details card shows (includes/book_viewer.php). Cut by
     * characters, never by bytes: see "Text cut by bytes is not text".
     */
    function book_excerpt(?string $html, int $max = 520): string
    {
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? ''));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) . '...' : $text;
    }
}

if (!function_exists('book_covers_style')) {
/**
 * Prints the palette and the cover's stylesheet, once a page, wherever it is
 * called from <head>.
 *
 * A function rather than markup printed by including the file: My Library
 * needs book_cover_group() near the top of the page, long before <head>, and
 * a <style> printed there would stand in front of the doctype (quirks mode)
 * and in front of the redirect that page's post handling sends.
 */
function book_covers_style(): void
{
    static $printed = false;
    if ($printed) { return; }
    $printed = true;
?>
<style nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
/* ===== The palette =====
   Keyed on the attribute alone, at the lowest specificity there is, so that
   anything which colours a book for a reason of its own (the student
   dashboard's covers say where a submission has got to) only has to say so.

   The defaults first, so the groups after them win: the spine a shade of the
   board, worked out on the book itself, so a status colour on the dashboard
   gets its own darker spine without being told; gold stamping. */
[data-cover] {
    --cover-spine: color-mix(in srgb, var(--cover) 66%, #000);
    --cover-foil: hsl(44 80% 68%);
    --cover-foil-shadow: rgba(0, 0, 0, .42);
    /* The stamping on the spine itself, which only the book viewer shows
       (includes/book_viewer.php). Gold, but for the one spine gold cannot be
       read on: HRM's is yellow, and is stamped in its own dark red. */
    --cover-spine-foil: hsl(44 80% 68%);
}
[data-cover="edu"]   { --cover: hsl(214 58% 29%); }
[data-cover="hrm"]   { --cover: hsl(356 64% 33%); --cover-spine: hsl(45 96% 55%); --cover-spine-foil: hsl(356 64% 20%); }
[data-cover="psych"] { --cover: hsl(272 40% 33%); }
/* The one light board: stamped in navy, pressed in with a highlight. */
[data-cover="it"]    {
    --cover: hsl(46 94% 54%);
    --cover-spine: hsl(216 64% 31%);
    --cover-foil: hsl(216 66% 18%);
    --cover-foil-shadow: rgba(255, 255, 255, .45);
}
[data-cover="cpe"]   { --cover: hsl(0 66% 24%); --cover-spine: hsl(0 0% 8%); }
[data-cover="ie"]    { --cover: hsl(148 48% 23%); }
[data-cover="other"] { --cover: hsl(28 14% 30%); }

/* ===== The board =====
   A hardbound research paper as the campus binds them: leatherette, the spine
   cloth wrapped onto the front, the hinge pressed in beside it, and every word
   stamped in foil, in capitals, centred.

   Sized through --bk-scale, so My Library's open-book dialog draws the same
   cover larger without a second set of figures. Nothing here is an image: the
   grain is two dot grids a pixel apart and the sheen is a gradient, so it
   follows the colour like everything else. */
.bk-face {
    --s: var(--bk-scale, 1);
    display: flex; flex-direction: column; align-items: center;
    padding: calc(12px * var(--s)) calc(8px * var(--s)) calc(9px * var(--s)) calc(16px * var(--s));
    text-align: center;
    text-transform: uppercase;
    font-family: var(--font-body), 'Inter', sans-serif;
    color: var(--cover-foil);
    text-shadow: 0 1px 0 var(--cover-foil-shadow);
    background-color: var(--cover);
    background-image:
        /* light from the upper left, falling off across the board */
        linear-gradient(118deg, rgba(255, 255, 255, .13), rgba(255, 255, 255, 0) 34%,
                                rgba(0, 0, 0, 0) 64%, rgba(0, 0, 0, .17)),
        /* the hinge, pressed in: a dark crease and its lit edge */
        linear-gradient(90deg,
            transparent             calc(9px  * var(--s)),
            rgba(0, 0, 0, .34)      calc(9px  * var(--s)), rgba(0, 0, 0, .34)      calc(10px * var(--s)),
            rgba(255, 255, 255, .13) calc(10px * var(--s)), rgba(255, 255, 255, .13) calc(11px * var(--s)),
            transparent             calc(11px * var(--s))),
        /* the spine cloth where it wraps onto the board */
        linear-gradient(90deg, var(--cover-spine) 0 calc(7px * var(--s)), transparent calc(7px * var(--s))),
        /* leatherette: three faint grids of 3, 5 and 7px, sizes that never line
           up, so the eye finds a grain and not a pattern. Two grids, one of
           them strong, read as polka dots on the yellow board. */
        radial-gradient(circle, rgba(255, 255, 255, .05) 0 .5px, transparent .9px),
        radial-gradient(circle, rgba(0, 0, 0, .055) 0 .5px, transparent 1px),
        radial-gradient(circle, rgba(0, 0, 0, .04) 0 .6px, transparent 1.1px);
    background-size: auto, auto, auto, 3px 3px, 5px 5px, 7px 7px;
    background-position: 0 0, 0 0, 0 0, 0 0, 1px 2px, 3px 1px;
}

/* ===== What is stamped on it ===== */
/* Across the head, framed, when there is something to say about this copy
   rather than about the paper: a status, a request. */
.bk-face .bk-band {
    max-width: 100%;
    margin-bottom: calc(7px * var(--s));
    padding: calc(1.5px * var(--s)) calc(4px * var(--s));
    border: 1px solid currentColor;
    border-radius: 1px;
    font-size: calc(5.4px * var(--s)); font-weight: 700; letter-spacing: .12em;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    flex-shrink: 0;
}
.bk-face .bk-title {
    font-size: calc(8.2px * var(--s)); font-weight: 800; line-height: 1.3;
    letter-spacing: .02em;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6;
    overflow: hidden;
    /* Hyphenated, not cut wherever the line ends. */
    overflow-wrap: break-word; -webkit-hyphens: auto; hyphens: auto;
    /* Never squeezed: a title given less room than its lines need shows the
       top half of the last one. A shorter board gets a line fewer instead. */
    flex-shrink: 0;
}
.bk-face .bk-kind {
    margin-top: calc(9px * var(--s));
    font-size: calc(5.4px * var(--s)); font-weight: 600; line-height: 1.4; letter-spacing: .12em;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;
    overflow: hidden;
    flex-shrink: 0;
}
.bk-face .bk-foot {
    margin-top: auto;
    width: 100%;
    display: flex; flex-direction: column; align-items: center;
    gap: calc(1.5px * var(--s));
    padding-top: calc(6px * var(--s));
}
.bk-face .bk-by-label { font-size: calc(4.6px * var(--s)); letter-spacing: .14em; opacity: .85; }
.bk-face .bk-by,
.bk-face .bk-mark {
    max-width: 100%;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bk-face .bk-by   { font-size: calc(6px * var(--s)); font-weight: 700; letter-spacing: .05em; }
.bk-face .bk-mark { margin-top: calc(4px * var(--s)); font-size: calc(5.6px * var(--s)); font-weight: 700; letter-spacing: .16em; }

/* A shorter board, or one with words across its head, has a line of title
   fewer. */
[data-tall="1"] .bk-face .bk-title,
[data-tall="2"] .bk-face .bk-title,
.bk-face:has(.bk-band) .bk-title { -webkit-line-clamp: 5; }
[data-tall="2"] .bk-face:has(.bk-band) .bk-title { -webkit-line-clamp: 4; }
</style>
<?php
}
}
