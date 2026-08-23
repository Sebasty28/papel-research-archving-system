<?php
/**
 * A small XLSX writer: styled sheets, and figures anchored on their own sheet.
 *
 * An .xlsx is a zip of XML parts, so with the zip extension the format can be
 * written directly and no library is needed. This covers what the analytics
 * export wants: a title, section headings, header rows, numbers that are
 * actually numbers, sensible column widths, and the charts as pictures.
 *
 * The styles are declared up front and referred to by index, which is how the
 * format works: a cell carries a style id, not a colour.
 *
 * Images go on a second sheet rather than floating over the data. A picture
 * anchored across a table moves whenever a column is resized, and the first
 * thing anyone does with an export is resize a column.
 */

class XlsxWriter
{
    /* Style ids, in the order they are written into styles.xml below. */
    public const S_PLAIN   = 0;
    public const S_TITLE   = 1;
    public const S_SUB     = 2;
    public const S_SECTION = 3;
    public const S_HEAD    = 4;
    public const S_TEXT    = 5;
    public const S_NUM     = 6;
    public const S_PCT     = 7;
    public const S_MUTED   = 8;

    private array $rows = [];          // row index => [col index => [value, style, isNumber]]
    private int $r = 0;
    private array $widths = [];
    private array $images = [];        // ['data' => png, 'w' => , 'h' => ]
    private string $sheetName;
    private string $imageSheetName;

    public function __construct(string $sheetName = 'Analytics',
                                string $imageSheetName = 'Figures')
    {
        $this->sheetName = $sheetName;
        $this->imageSheetName = $imageSheetName;
    }

    public function widths(array $w): void { $this->widths = $w; }

    public function title(string $text, string $subtitle = ''): void
    {
        $this->put(0, $text, self::S_TITLE);
        $this->r++;
        if ($subtitle !== '') {
            $this->put(0, $subtitle, self::S_SUB);
            $this->r++;
        }
        $this->r++;
    }

    public function section(string $text): void
    {
        $this->r++;
        $this->put(0, $text, self::S_SECTION);
        $this->r++;
    }

    public function headRow(array $cells): void
    {
        foreach (array_values($cells) as $i => $c) $this->put($i, $c, self::S_HEAD);
        $this->r++;
    }

    /**
     * A row. Numbers are written as numbers so the spreadsheet can total them;
     * a value that only looks numeric, like a year label, can be forced to text
     * by passing it in $asText.
     */
    public function row(array $cells, array $asText = []): void
    {
        foreach (array_values($cells) as $i => $c) {
            $isNum = is_int($c) || is_float($c);
            if (!$isNum && !in_array($i, $asText, true) && $c !== '' && is_numeric($c)) {
                $c = $c + 0;
                $isNum = true;
            }
            $this->put($i, $c, $isNum ? self::S_NUM : self::S_TEXT, $isNum);
        }
        $this->r++;
    }

    public function note(string $text): void
    {
        $this->put(0, $text, self::S_MUTED);
        $this->r++;
    }

    public function blank(int $n = 1): void { $this->r += $n; }

    /** A PNG figure. Width and height are the image's own pixels. */
    public function image(string $png, int $w, int $h, string $caption = ''): void
    {
        $this->images[] = ['data' => $png, 'w' => $w, 'h' => $h, 'caption' => $caption];
    }

    public function output(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $hasImages = count($this->images) > 0;

        $zip->addFromString('[Content_Types].xml', $this->contentTypes($hasImages));
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook($hasImages));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($hasImages));
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());

        if ($hasImages) {
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->imageSheet());
            $zip->addFromString('xl/worksheets/_rels/sheet2.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', $this->drawing());
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($this->images as $i => $_img) {
                $n = $i + 1;
                $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $n . '.png"/>';
            }
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $rels . '</Relationships>');
            foreach ($this->images as $i => $img) {
                $zip->addFromString('xl/media/image' . ($i + 1) . '.png', $img['data']);
            }
        }

        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    // ---- parts -------------------------------------------------------------

    private function put(int $col, $value, int $style, bool $isNumber = false): void
    {
        $this->rows[$this->r][$col] = [$value, $style, $isNumber];
    }

    private function contentTypes(bool $img): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
           . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
           . '<Default Extension="xml" ContentType="application/xml"/>'
           . '<Default Extension="png" ContentType="image/png"/>'
           . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
           . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
           . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        if ($img) {
            $x .= '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        return $x . '</Types>';
    }

    private function workbook(bool $img): string
    {
        $sheets = '<sheet name="' . $this->x($this->sheetName) . '" sheetId="1" r:id="rId1"/>';
        if ($img) {
            $sheets .= '<sheet name="' . $this->x($this->imageSheetName) . '" sheetId="2" r:id="rId2"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRels(bool $img): string
    {
        $r = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
           . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
        if ($img) {
            $r .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>';
        }
        $r .= '<Relationship Id="rId' . ($img ? 3 : 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $r . '</Relationships>';
    }

    /** Fonts, fills, borders and the cell formats that combine them. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/></numFmts>'
        . '<fonts count="6">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="18"/><color rgb="FF820707"/><name val="Calibri"/></font>'
        .   '<font><sz val="10"/><color rgb="FF6E6A6E"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="12"/><color rgb="FF630000"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .   '<font><sz val="11"/><color rgb="FF333333"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF820707"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF5F5"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE6D4D4"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                     // plain
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                        // title
        .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                        // subtitle
        .   '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'          // section
        .   '<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>' // header
        .   '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'        // text
        .   '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>' // number
        .   '<xf numFmtId="164" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyNumberFormat="1"/>' // percent
        .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                        // muted note
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
    }

    private function sheet(): string
    {
        $cols = '';
        if ($this->widths) {
            $cols = '<cols>';
            foreach ($this->widths as $i => $w) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1)
                       . '" width="' . $w . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $xml = '';
        $maxRow = $this->rows ? max(array_keys($this->rows)) : 0;
        for ($r = 0; $r <= $maxRow; $r++) {
            if (!isset($this->rows[$r])) continue;
            $cells = '';
            foreach ($this->rows[$r] as $c => $cell) {
                [$value, $style, $isNum] = $cell;
                $ref = $this->colName($c) . ($r + 1);
                if ($isNum) {
                    $cells .= '<c r="' . $ref . '" s="' . $style . '"><v>'
                            . (0 + $value) . '</v></c>';
                } else {
                    $cells .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr">'
                            . '<is><t xml:space="preserve">' . $this->x((string)$value)
                            . '</t></is></c>';
                }
            }
            $xml .= '<row r="' . ($r + 1) . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>'
            . $cols
            . '<sheetData>' . $xml . '</sheetData></worksheet>';
    }

    private function imageSheet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>'
            . '<sheetData/><drawing r:id="rId1" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
            . '</worksheet>';
    }

    /**
     * One picture per block, stacked down the sheet. Anchored to a cell but
     * sized absolutely, so a column width cannot squash a chart.
     */
    private function drawing(): string
    {
        $emuPerPx = 9525;
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
           . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
        $row = 1;
        foreach ($this->images as $i => $img) {
            $n = $i + 1;
            // Charts come off a canvas at device scale; 620px wide reads well.
            $w = 620;
            $h = (int)round($img['h'] * ($w / max(1, $img['w'])));
            $x .= '<xdr:oneCellAnchor>'
                . '<xdr:from><xdr:col>1</xdr:col><xdr:colOff>0</xdr:colOff>'
                . '<xdr:row>' . $row . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                . '<xdr:ext cx="' . ($w * $emuPerPx) . '" cy="' . ($h * $emuPerPx) . '"/>'
                . '<xdr:pic><xdr:nvPicPr>'
                . '<xdr:cNvPr id="' . $n . '" name="' . $this->x($img['caption'] ?: 'Figure ' . $n) . '"/>'
                . '<xdr:cNvPicPr/></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId' . $n . '"/>'
                . '<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                . '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
            $row += (int)ceil($h / 20) + 3;   // leave a gap under each figure
        }
        return $x . '</xdr:wsDr>';
    }

    private function colName(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = (int)(($i - $m) / 26);
        }
        return $s;
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
