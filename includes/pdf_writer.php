<?php
/**
 * A small PDF writer: headings, paragraphs, tables and figures.
 *
 * There is no PDF library in this project and none can be assumed on the host,
 * so this writes the file itself. It is deliberately narrow: it does what the
 * analytics export needs and nothing more:
 *
 *   - Folio pages with automatic breaks and a running footer.
 *   - Arial and Arial-Bold are requested from the PDF reader. Readers without
 *     Arial use their configured sans-serif substitute.
 *   - Tables with a tinted header row, hairline rules and right-aligned
 *     numbers.
 *   - JPEG figures, embedded with DCTDecode.
 *
 * Why JPEG and not PNG: a PDF can carry a JPEG byte-for-byte, because the
 * format's own compression is one the PDF understands. A PNG would have to be
 * decoded to raw pixels first and re-compressed, and GD is not enabled here.
 * The charts are handed over as JPEG by the browser for that reason.
 *
 * Text is written as WinAnsi. Characters outside it (the en dash the site likes,
 * curly quotes) are folded to an ASCII equivalent rather than dropped, so a
 * heading never arrives with a hole in it.
 */

class PdfWriter
{
    /* Folio (8.5 x 13 inches) in points, upright. Landscape is the same sheet
       turned, so the two are one pair of numbers swapped rather than a second
       paper size: a report printed either way comes off the same stack. */
    private const PAGE_W = 612.0;
    private const PAGE_H = 936.0;
    private const MARGIN = 48.0;

    /* The sheet this document is written on. Instance values and not
       constants, because one request can write a landscape report while the
       class is also the portrait one every other caller still asks for. */
    private float $w;
    private float $h;

    private array $pages = [];        // each: ['content' => string, 'images' => [name => ref]]
    private string $buf = '';         // the page being written
    private array $imagesOnPage = [];
    private array $images = [];       // name => ['data' =>, 'w' =>, 'h' =>]
    private float $y;
    private int $imageSeq = 0;
    private string $title;
    private string $subtitle;

    /**
     * $landscape turns the sheet. Every width in this class is measured from
     * $this->w, and table columns are scaled to the text block (see table()),
     * so a caller that was written for the upright page needs no other change:
     * its columns simply grow into the wider measure.
     */
    public function __construct(string $title, string $subtitle = '', bool $landscape = false)
    {
        $this->w = $landscape ? self::PAGE_H : self::PAGE_W;
        $this->h = $landscape ? self::PAGE_W : self::PAGE_H;
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->newPage();
        $this->coverBlock();
    }

    /** The text block's width: what a caller's columns are scaled to fill. */
    public function textWidth(): float { return $this->w - self::MARGIN * 2; }

    public function isLandscape(): bool { return $this->w > $this->h; }

    // ---- public drawing API ------------------------------------------------

    /** A section heading, kept with at least a little of what follows it. */
    public function heading(string $text, float $size = 11.0): void
    {
        $this->need($size + 26);
        $this->y -= 10;
        $this->text($text, self::MARGIN, $this->y, $size, true, [0.51, 0.03, 0.03]);
        $this->y -= 5;
        // A rule under the heading, the width of the text block.
        $this->line(self::MARGIN, $this->y, $this->w - self::MARGIN, $this->y,
                    0.6, [0.85, 0.78, 0.78]);
        $this->y -= 12;
    }

    public function paragraph(string $text, float $size = 11.0): void
    {
        $lines = $this->wrap($text, $this->w - self::MARGIN * 2, $size, false);
        foreach ($lines as $i => $line) {
            $this->need($size + 4);
            $spaces = substr_count($line, ' ');
            $justify = $i < count($lines) - 1 && $spaces > 0 && $line !== '';
            $wordSpace = $justify ? max(0, ($this->w - self::MARGIN * 2 - $this->widthOf($line, $size, false)) / $spaces) : 0;
            $this->text($line, self::MARGIN, $this->y, $size, false, [0.2, 0.2, 0.2], $wordSpace);
            $this->y -= $size + 3.5;
        }
        $this->y -= 4;
    }

    /**
     * A table. Columns are given as [label, width, align]; align is 'l' or 'r'.
     * The header repeats when the table runs onto another page, because a
     * column of numbers with no headings on page two is unreadable.
     */
    public function table(array $columns, array $rows, float $size = 11.0): void
    {
        /* Columns arrive as points measured for the upright page. Scaled to
           whatever text block this document actually has, the same call site
           fills a landscape sheet without naming a second set of numbers, and
           a spec that does not quite add up to the measure stops leaving a
           ragged strip down the right-hand edge. */
        $given = 0.0;
        foreach ($columns as $c) $given += (float)$c[1];
        if ($given > 0) {
            $scale = $this->textWidth() / $given;
            foreach ($columns as $i => $c) $columns[$i][1] = (float)$c[1] * $scale;
        }
        $rowH = $size + 9;
        $this->need($rowH * 3);
        $drawHead = function () use ($columns, $size, $rowH) {
            $x = self::MARGIN;
            $top = $this->y;
            $this->rect(self::MARGIN, $top - $rowH + 3, $this->w - self::MARGIN * 2, $rowH,
                        [0.98, 0.94, 0.94]);
            foreach ($columns as $c) {
                $this->cell($c[0], $x, $top, $c[1], $size, true, $c[2] ?? 'l',
                            [0.36, 0.02, 0.02]);
                $x += $c[1];
            }
            $this->y -= $rowH;
            $this->line(self::MARGIN, $this->y + 3, $this->w - self::MARGIN, $this->y + 3,
                        0.7, [0.78, 0.68, 0.68]);
        };
        $drawHead();

        $stripe = false;
        foreach ($rows as $r) {
            $cells = [];
            $lines = 1;
            foreach ($columns as $i => $c) {
                $cells[$i] = $this->wrap((string)($r[$i] ?? ''), $c[1] - 10, $size, false);
                $lines = max($lines, count($cells[$i]));
            }
            $dataH = max($rowH, $lines * ($size + 3) + 6);
            if ($this->y - $dataH < self::MARGIN + 30) {
                $this->newPage();
                $drawHead();
                $stripe = false;
            }
            if ($stripe) {
                $this->rect(self::MARGIN, $this->y - $dataH + 3, $this->w - self::MARGIN * 2,
                            $dataH, [0.985, 0.975, 0.975]);
            }
            $x = self::MARGIN;
            foreach ($columns as $i => $c) {
                foreach ($cells[$i] as $lineIndex => $lineText) {
                    $this->cell($lineText, $x, $this->y - $lineIndex * ($size + 3), $c[1], $size, false,
                                $c[2] ?? 'l', [0.15, 0.15, 0.15]);
                }
                $x += $c[1];
            }
            $this->y -= $dataH;
            $this->line(self::MARGIN, $this->y + 3, $this->w - self::MARGIN, $this->y + 3,
                        0.35, [0.90, 0.86, 0.86]);
            $stripe = !$stripe;
        }
        $this->y -= 8;
    }

    /** A figure: a JPEG, centred, with a caption under it. */
    public function figure(string $jpeg, string $caption = ''): bool
    {
        $size = $this->jpegSize($jpeg);
        if (!$size) return false;
        [$iw, $ih] = $size;

        /* The cap grows with the measure, so a chart on a landscape sheet is
           drawn at the size that page affords instead of sitting at its
           upright width with a band of empty paper beside it. The height cap
           comes down to match, a turned sheet being the shorter one. */
        $maxW = $this->textWidth();
        $w = min($maxW, 420.0 * $maxW / (self::PAGE_W - self::MARGIN * 2));
        $h = $w * ($ih / $iw);
        $capH = $this->isLandscape() ? 240.0 : 260.0;
        if ($h > $capH) { $h = $capH; $w = $h * ($iw / $ih); }

        $this->need($h + 38);
        $name = 'Im' . (++$this->imageSeq);
        $this->images[$name] = ['data' => $jpeg, 'w' => $iw, 'h' => $ih];
        $this->imagesOnPage[$name] = true;

        $x = ($this->w - $w) / 2;
        $this->y -= $h;
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $this->y, $name);
        // A hairline around the figure so it sits on the page rather than floating.
        $this->rectStroke($x, $this->y, $w, $h, 0.5, [0.88, 0.84, 0.84]);
        $this->y -= 12;
        if ($caption !== '') {
            $this->text($caption, $x, $this->y, 11, true, [0.36, 0.02, 0.02]);
            $this->y -= 17;
        }
        $this->y -= 6;
    return true;
    }

    public function spacer(float $pt = 8.0): void { $this->y -= $pt; }

    /** Finish the document and return the bytes. */
    public function output(): string
    {
        $this->closePage();

        $objects = [];
        $fontRegular = null;
        $fontBold = null;

        // 1 catalog, 2 pages, then fonts, images, and per page a pair.
        $n = 0;
        $catalog = ++$n;
        $pagesObj = ++$n;
        $fontRegular = ++$n;
        $fontBold = ++$n;

        $imageObj = [];
        foreach ($this->images as $name => $img) {
            $imageObj[$name] = ++$n;
        }

        $pageObj = [];
        $contentObj = [];
        foreach ($this->pages as $i => $_p) {
            $pageObj[$i] = ++$n;
            $contentObj[$i] = ++$n;
        }

        $objects[$catalog] = "<< /Type /Catalog /Pages $pagesObj 0 R >>";

        $kids = [];
        foreach ($pageObj as $ref) $kids[] = "$ref 0 R";
        $objects[$pagesObj] = "<< /Type /Pages /Count " . count($pageObj)
            . " /Kids [" . implode(' ', $kids) . "] >>";

        $objects[$fontRegular] = "<< /Type /Font /Subtype /Type1 /BaseFont /Arial "
            . "/Encoding /WinAnsiEncoding >>";
        $objects[$fontBold] = "<< /Type /Font /Subtype /Type1 /BaseFont /Arial-Bold "
            . "/Encoding /WinAnsiEncoding >>";

        foreach ($this->images as $name => $img) {
            $objects[$imageObj[$name]] = "<< /Type /XObject /Subtype /Image "
                . "/Width {$img['w']} /Height {$img['h']} /ColorSpace /DeviceRGB "
                . "/BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img['data'])
                . " >>\nstream\n" . $img['data'] . "\nendstream";
        }

        foreach ($this->pages as $i => $page) {
            $xobjects = '';
            foreach (array_keys($page['images']) as $name) {
                $xobjects .= "/$name " . $imageObj[$name] . " 0 R ";
            }
            $res = "<< /Font << /F1 $fontRegular 0 R /F2 $fontBold 0 R >>";
            if ($xobjects !== '') $res .= " /XObject << $xobjects>>";
            $res .= " >>";

            $objects[$pageObj[$i]] = "<< /Type /Page /Parent $pagesObj 0 R "
                . sprintf("/MediaBox [0 0 %.2F %.2F] ", $this->w, $this->h)
                . "/Resources $res /Contents {$contentObj[$i]} 0 R >>";
            $objects[$contentObj[$i]] = "<< /Length " . strlen($page['content']) . " >>\n"
                . "stream\n" . $page['content'] . "\nendstream";
        }

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= $n; $i++) {
            $offsets[$i] = strlen($out);
            $out .= "$i 0 obj\n" . $objects[$i] . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($n + 1) . " /Root $catalog 0 R "
             . "/Info << /Title (" . $this->esc($this->title) . ") "
             . "/Producer (PAPEL) /CreationDate (D:" . date('YmdHis') . ") >> >>\n"
             . "startxref\n$xref\n%%EOF";
        return $out;
    }

    // ---- internals ---------------------------------------------------------

    private function newPage(): void
    {
        if ($this->buf !== '') $this->closePage();
        $this->buf = '';
        $this->imagesOnPage = [];
        $this->y = $this->h - self::MARGIN;
    }

    private function closePage(): void
    {
        // Running footer: the page number and where it came from.
        $num = count($this->pages) + 1;
        $this->text('PAPEL Analytics', self::MARGIN, self::MARGIN - 18, 11, false,
                    [0.55, 0.55, 0.55]);
        $label = 'Page ' . $num;
        $w = $this->widthOf($label, 11, false);
        $this->text($label, $this->w - self::MARGIN - $w, self::MARGIN - 18, 11, false,
                    [0.55, 0.55, 0.55]);
        $this->pages[] = ['content' => $this->buf, 'images' => $this->imagesOnPage];
        $this->buf = '';
        $this->imagesOnPage = [];
    }

    /** The title block at the top of page one. */
    private function coverBlock(): void
    {
        $this->rect(0, $this->h - 96, $this->w, 96, [0.51, 0.03, 0.03]);
        $this->text($this->title, self::MARGIN, $this->h - 52, 11, true, [1, 1, 1]);
        if ($this->subtitle !== '') {
            $this->text($this->subtitle, self::MARGIN, $this->h - 72, 11, false,
                        [0.93, 0.85, 0.85]);
        }
        $this->y = $this->h - 96 - 28;
    }

    private function need(float $space): void
    {
        if ($this->y - $space < self::MARGIN + 24) $this->newPage();
    }

    private function text(string $s, float $x, float $y, float $size, bool $bold,
                          array $rgb, float $wordSpace = 0): void
    {
        $this->buf .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F Tw %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $wordSpace, $x, $y, $this->esc($s));
    }

    /**
     * One line of one cell. It does not shorten what it is given: the owner's
     * rule (23 November 2026) is that a long value is shown in full, never cut
     * to an ellipsis. table() has already broken the value into lines that fit
     * the column, including inside a word with no space to break at, so what
     * arrives here fits and the cell only has to place it.
     */
    private function cell(string $s, float $x, float $y, float $w, float $size,
                          bool $bold, string $align, array $rgb): void
    {
        $tx = $align === 'r' ? $x + $w - 6 - $this->widthOf($s, $size, $bold) : $x + 3;
        $this->text($s, $tx, $y - $size - 1, $size, $bold, $rgb);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $w,
                          array $rgb): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $w, $x1, $y1, $x2, $y2);
    }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    private function rectStroke(float $x, float $y, float $w, float $h, float $lw,
                                array $rgb): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n",
            $rgb[0], $rgb[1], $rgb[2], $lw, $x, $y, $w, $h);
    }

    /** Text to PDF string: WinAnsi, with the usual typography folded to ASCII. */
    private function esc(string $s): string
    {
        static $fold = [
            "\xE2\x80\x94" => '-', "\xE2\x80\x93" => '-',      // em and en dash
            /* The rest of the dash family, which a title pasted out of Word
               carries: a paper really on file is spelled "E-Commerce" with
               U+2011, the non-breaking hyphen, and it printed as "E?Commerce"
               in every PDF export. A character folded to a question mark is
               text the document does not show, the fault the n of Binan was
               once an instance of. */
            "\xE2\x80\x90" => '-', "\xE2\x80\x91" => '-',      // hyphen, non-breaking hyphen
            "\xE2\x80\x92" => '-', "\xE2\x80\x95" => '-',      // figure dash, horizontal bar
            "\xE2\x88\x92" => '-',                              // minus sign
            "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xE2\x80\xA6" => '...', "\xC2\xA0" => ' ',
            /* Spaces that are not the space: a word break the reader sees,
               so it is written as one rather than lost to a question mark. */
            "\xE2\x80\x82" => ' ', "\xE2\x80\x83" => ' ',      // en space, em space
            "\xE2\x80\x89" => ' ', "\xE2\x80\xAF" => ' ',      // thin space, narrow no-break space
            "\xE2\x80\x8B" => '',                              // zero width space
        ];
        /* One character at a time, as UTF-8. Latin-1 (U+00A0 to U+00FF: the n of
           Binan in either case, an accented name, the middle dot in every export
           title) is in WinAnsi at the same number; anything else is a question
           mark rather than a byte the reader would mis-render. This used to map
           the n-tilde first and then replace every byte above 127, which took
           the mapped byte too: "Binan" printed as "Bi?an" in every PDF export. */
        $out = preg_replace_callback('/[^\x00-\x7F]/u', function ($m) use ($fold) {
            if (isset($fold[$m[0]])) return $fold[$m[0]];
            $cp = mb_ord($m[0], 'UTF-8');
            return ($cp >= 0xA0 && $cp <= 0xFF) ? chr($cp) : '?';
        }, $s);
        // Not UTF-8 at all, so nothing above ASCII can be trusted.
        if ($out === null) $out = preg_replace('/[\x80-\xFF]/', '?', $s) ?? '';
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $out);
    }

    /** Approximate sans-serif advance widths, per 1000 units, for ASCII 32-126. */
    private function widthOf(string $s, float $size, bool $bold): float
    {
        static $reg = null, $bld = null;
        if ($reg === null) {
            $reg = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
                    556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
                    1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
                    667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
                    333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
                    556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
            $bld = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
                    556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
                    975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
                    667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
                    333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
                    611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
        }
        $table = $bold ? $bld : $reg;
        $total = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $total += ($c >= 32 && $c <= 126) ? $table[$c - 32] : 556;
        }
        return $total * $size / 1000;
    }

    /**
     * A word with no space in it, cut into pieces that each fit the measure.
     *
     * An email address, a Drive file name or a hyphenless keyword is one
     * "word" to wrap(), which can only break at a space: such a value used to
     * overflow its column and be cut to "someone@exampl..." by clip(). Broken
     * here instead, every character of it is on the page over as many lines as
     * it takes. At least one character goes on a line whatever the measure,
     * or a column narrower than a single letter would loop for ever.
     */
    private function breakWord(string $s, float $w, float $size, bool $bold): array
    {
        $out  = [];
        $line = '';
        $len  = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $try = $line . $s[$i];
            if ($line !== '' && $this->widthOf($try, $size, $bold) > $w) {
                $out[] = $line;
                $line  = $s[$i];
            } else {
                $line = $try;
            }
        }
        if ($line !== '') $out[] = $line;
        return $out;
    }

    private function wrap(string $s, float $w, float $size, bool $bold): array
    {
        $out = [];
        foreach (preg_split('/\R/', $s) as $para) {
            $line = '';
            foreach (explode(' ', $para) as $word) {
                /* A word wider than the measure cannot be fitted by moving it
                   to a line of its own, so it is cut into pieces that do fit.
                   Nothing is dropped and nothing is replaced by an ellipsis. */
                if ($this->widthOf($word, $size, $bold) > $w) {
                    $pieces = $this->breakWord($word, $w, $size, $bold);
                    foreach ($pieces as $k => $piece) {
                        if ($k === 0 && $line !== ''
                            && $this->widthOf($line . ' ' . $piece, $size, $bold) <= $w) {
                            $line .= ' ' . $piece;
                        } else {
                            if ($line !== '') $out[] = $line;
                            $line = $piece;
                        }
                    }
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->widthOf($try, $size, $bold) > $w && $line !== '') {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Width and height from a JPEG's SOF marker. */
    private function jpegSize(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 4 || substr($data, 0, 2) !== "\xFF\xD8") return null;
        $i = 2;
        while ($i < $len - 1) {
            if ($data[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($data[$i + 1]);
            $i += 2;
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($i + 1 >= $len) return null;
            $seg = (ord($data[$i]) << 8) + ord($data[$i + 1]);
            // SOF0..SOF15, excluding the four that are not frame headers.
            if ($marker >= 0xC0 && $marker <= 0xCF
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($i + 7 >= $len) return null;
                $h = (ord($data[$i + 3]) << 8) + ord($data[$i + 4]);
                $w = (ord($data[$i + 5]) << 8) + ord($data[$i + 6]);
                return ($w > 0 && $h > 0) ? [$w, $h] : null;
            }
            $i += $seg;
        }
        return null;
    }
}
