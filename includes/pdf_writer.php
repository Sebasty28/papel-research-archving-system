<?php
/**
 * A small PDF writer: headings, paragraphs, tables and figures.
 *
 * There is no PDF library in this project and none can be assumed on the host,
 * so this writes the file itself. It is deliberately narrow — it does what the
 * analytics export needs and nothing more:
 *
 *   - A4 pages with automatic breaks and a running footer.
 *   - Helvetica and Helvetica-Bold, which are two of the fourteen fonts every
 *     reader has built in, so nothing has to be embedded.
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
    /* A4 in points, and a margin wide enough that a bound copy still reads. */
    private const W = 595.28;
    private const H = 841.89;
    private const MARGIN = 48.0;

    private array $pages = [];        // each: ['content' => string, 'images' => [name => ref]]
    private string $buf = '';         // the page being written
    private array $imagesOnPage = [];
    private array $images = [];       // name => ['data' =>, 'w' =>, 'h' =>]
    private float $y;
    private int $imageSeq = 0;
    private string $title;
    private string $subtitle;

    public function __construct(string $title, string $subtitle = '')
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->newPage();
        $this->coverBlock();
    }

    // ---- public drawing API ------------------------------------------------

    /** A section heading, kept with at least a little of what follows it. */
    public function heading(string $text, float $size = 13.0): void
    {
        $this->need($size + 26);
        $this->y -= 10;
        $this->text($text, self::MARGIN, $this->y, $size, true, [0.51, 0.03, 0.03]);
        $this->y -= 5;
        // A rule under the heading, the width of the text block.
        $this->line(self::MARGIN, $this->y, self::W - self::MARGIN, $this->y,
                    0.6, [0.85, 0.78, 0.78]);
        $this->y -= 12;
    }

    public function paragraph(string $text, float $size = 9.5): void
    {
        foreach ($this->wrap($text, self::W - self::MARGIN * 2, $size, false) as $line) {
            $this->need($size + 4);
            $this->text($line, self::MARGIN, $this->y, $size, false, [0.2, 0.2, 0.2]);
            $this->y -= $size + 3.5;
        }
        $this->y -= 4;
    }

    /**
     * A table. Columns are given as [label, width, align]; align is 'l' or 'r'.
     * The header repeats when the table runs onto another page, because a
     * column of numbers with no headings on page two is unreadable.
     */
    public function table(array $columns, array $rows, float $size = 8.5): void
    {
        $rowH = $size + 9;
        $this->need($rowH * 3);
        $drawHead = function () use ($columns, $size, $rowH) {
            $x = self::MARGIN;
            $top = $this->y;
            $this->rect(self::MARGIN, $top - $rowH + 3, self::W - self::MARGIN * 2, $rowH,
                        [0.98, 0.94, 0.94]);
            foreach ($columns as $c) {
                $this->cell($c[0], $x, $top, $c[1], $size, true, $c[2] ?? 'l',
                            [0.36, 0.02, 0.02]);
                $x += $c[1];
            }
            $this->y -= $rowH;
            $this->line(self::MARGIN, $this->y + 3, self::W - self::MARGIN, $this->y + 3,
                        0.7, [0.78, 0.68, 0.68]);
        };
        $drawHead();

        $stripe = false;
        foreach ($rows as $r) {
            if ($this->y - $rowH < self::MARGIN + 30) {
                $this->newPage();
                $drawHead();
                $stripe = false;
            }
            if ($stripe) {
                $this->rect(self::MARGIN, $this->y - $rowH + 3, self::W - self::MARGIN * 2,
                            $rowH, [0.985, 0.975, 0.975]);
            }
            $x = self::MARGIN;
            foreach ($columns as $i => $c) {
                $this->cell((string)($r[$i] ?? ''), $x, $this->y, $c[1], $size, false,
                            $c[2] ?? 'l', [0.15, 0.15, 0.15]);
                $x += $c[1];
            }
            $this->y -= $rowH;
            $this->line(self::MARGIN, $this->y + 3, self::W - self::MARGIN, $this->y + 3,
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

        $maxW = self::W - self::MARGIN * 2;
        $w = min($maxW, 420.0);
        $h = $w * ($ih / $iw);
        if ($h > 260) { $h = 260.0; $w = $h * ($iw / $ih); }

        $this->need($h + 26);
        $name = 'Im' . (++$this->imageSeq);
        $this->images[$name] = ['data' => $jpeg, 'w' => $iw, 'h' => $ih];
        $this->imagesOnPage[$name] = true;

        $x = (self::W - $w) / 2;
        $this->y -= $h;
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $this->y, $name);
        // A hairline around the figure so it sits on the page rather than floating.
        $this->rectStroke($x, $this->y, $w, $h, 0.5, [0.88, 0.84, 0.84]);
        $this->y -= 12;
        if ($caption !== '') {
            $this->text($caption, $x, $this->y, 8, false, [0.42, 0.42, 0.42]);
            $this->y -= 12;
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

        $objects[$fontRegular] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica "
            . "/Encoding /WinAnsiEncoding >>";
        $objects[$fontBold] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold "
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
                . sprintf("/MediaBox [0 0 %.2F %.2F] ", self::W, self::H)
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
        $this->y = self::H - self::MARGIN;
    }

    private function closePage(): void
    {
        // Running footer: the page number and where it came from.
        $num = count($this->pages) + 1;
        $this->text('PAPEL Analytics', self::MARGIN, self::MARGIN - 18, 7.5, false,
                    [0.55, 0.55, 0.55]);
        $label = 'Page ' . $num;
        $w = $this->widthOf($label, 7.5, false);
        $this->text($label, self::W - self::MARGIN - $w, self::MARGIN - 18, 7.5, false,
                    [0.55, 0.55, 0.55]);
        $this->pages[] = ['content' => $this->buf, 'images' => $this->imagesOnPage];
        $this->buf = '';
        $this->imagesOnPage = [];
    }

    /** The title block at the top of page one. */
    private function coverBlock(): void
    {
        $this->rect(0, self::H - 96, self::W, 96, [0.51, 0.03, 0.03]);
        $this->text($this->title, self::MARGIN, self::H - 52, 19, true, [1, 1, 1]);
        if ($this->subtitle !== '') {
            $this->text($this->subtitle, self::MARGIN, self::H - 72, 9.5, false,
                        [0.93, 0.85, 0.85]);
        }
        $this->y = self::H - 96 - 28;
    }

    private function need(float $space): void
    {
        if ($this->y - $space < self::MARGIN + 24) $this->newPage();
    }

    private function text(string $s, float $x, float $y, float $size, bool $bold,
                          array $rgb): void
    {
        $this->buf .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, $this->esc($s));
    }

    private function cell(string $s, float $x, float $y, float $w, float $size,
                          bool $bold, string $align, array $rgb): void
    {
        $s = $this->clip($s, $w - 8, $size, $bold);
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
        $s = strtr($s, [
            "\xE2\x80\x94" => '-', "\xE2\x80\x93" => '-',      // em and en dash
            "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xE2\x80\xA6" => '...', "\xC2\xA0" => ' ',
            "\xC3\xB1" => "\xF1",                              // n-tilde, for Binan
        ]);
        // Anything still multibyte is not in WinAnsi; drop to a question mark
        // rather than emitting a byte the reader will mis-render.
        $s = preg_replace('/[\x80-\xFF]/', '?', $s) ?? $s;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    /** Helvetica advance widths, per 1000 units, for ASCII 32-126. */
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

    private function clip(string $s, float $w, float $size, bool $bold): string
    {
        if ($this->widthOf($s, $size, $bold) <= $w) return $s;
        while ($s !== '' && $this->widthOf($s . '...', $size, $bold) > $w) {
            $s = substr($s, 0, -1);
        }
        return $s . '...';
    }

    private function wrap(string $s, float $w, float $size, bool $bold): array
    {
        $out = [];
        foreach (preg_split('/\R/', $s) as $para) {
            $line = '';
            foreach (explode(' ', $para) as $word) {
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
