<?php
/**
 * A small DOCX writer: headings, paragraphs, tables and figures.
 *
 * Like the spreadsheet, a .docx is a zip of XML, so it can be written directly
 * rather than pulling in a library. This covers the analytics export: a title
 * block, section headings, tables with a maroon header row and hairline rules,
 * and the charts as inline pictures.
 *
 * Word measures in half-points for type, twentieths of a point (twips) for
 * layout, and EMUs for pictures, which is why the numbers below look arbitrary.
 * A4 minus 2cm margins leaves 9638 twips of usable width, and the tables are
 * laid out against that.
 */

class DocxWriter
{
    private const USABLE_TWIPS = 9638;

    private string $body = '';
    private array $images = [];        // ['data' => png, 'w' => px, 'h' => px]
    private bool $landscape = false;

    /** Only text, formatting and references to the request's validated figures cross this boundary. */
    public function editedDocument(array $document, array $figures): void
    {
        $pages = $document['pages'] ?? null;
        if (!is_array($pages) || !$pages || count($pages) > 200) {
            throw new InvalidArgumentException('The edited document has an invalid page count.');
        }
        $this->landscape = ($document['orientation'] ?? '') === 'landscape';
        $usable = $this->landscape ? 14570 : self::USABLE_TWIPS;
        $blocks = 0;
        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page) || !is_array($page['blocks'] ?? null)) {
                throw new InvalidArgumentException('The edited document contains an invalid page.');
            }
            if ($pageIndex > 0) $this->pageBreak();
            foreach ($page['blocks'] as $block) {
                if (!is_array($block) || ++$blocks > 10000) {
                    throw new InvalidArgumentException('The edited document is too large.');
                }
                $type = $block['type'] ?? '';
                if ($type === 'paragraph') {
                    $this->body .= $this->editorParagraph($block);
                } elseif ($type === 'table') {
                    $this->body .= $this->editorTable($block, $usable);
                } elseif ($type === 'image') {
                    $index = $block['figure'] ?? -1;
                    if (!is_int($index) || !isset($figures[$index]) || $figures[$index]['type'] !== 'png') continue;
                    $figure = $figures[$index];
                    $width = max(20, min(100, (float)($block['width'] ?? 100)));
                    $this->image($figure['data'], max(1, $figure['w']), max(1, $figure['h']), '', [
                        'width' => (int)round(($usable / 15) * $width / 100),
                        'align' => $this->editorAlignment($block['align'] ?? 'center'),
                    ]);
                    $caption = $block['caption'] ?? [];
                    $this->body .= $this->editorParagraph([
                        'runs' => is_array($caption) ? $caption : [],
                        'align' => $block['captionAlign'] ?? ($block['align'] ?? 'center'), 'kind' => 'caption',
                    ]);
                }
            }
        }
    }

    private function editorAlignment($value): string
    {
        return ['left' => 'left', 'center' => 'center', 'right' => 'right',
                'justify' => 'both'][$value] ?? 'left';
    }

    private function editorRuns(array $runs, int $defaultSize = 20, string $color = '333333', bool $bold = false): string
    {
        if (count($runs) > 20000) throw new InvalidArgumentException('Too many text runs.');
        $xml = '';
        foreach ($runs as $run) {
            if (!is_array($run) || !is_string($run['text'] ?? null)) continue;
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $run['text']);
            $size = isset($run['size']) ? (int)round(max(8, min(36, (float)$run['size'])) * 2) : $defaultSize;
            $xml .= '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>'
                . '<w:sz w:val="' . $size . '"/><w:color w:val="' . $color . '"/>'
                . ((array_key_exists('bold', $run) ? !empty($run['bold']) : $bold) ? '<w:b/>' : '')
                . (!empty($run['italic']) ? '<w:i/>' : '')
                . (!empty($run['underline']) ? '<w:u w:val="single"/>' : '') . '</w:rPr>';
            $parts = preg_split('/\r\n|\r|\n/', $text);
            foreach ($parts as $i => $part) {
                if ($i) $xml .= '<w:br/>';
                $xml .= '<w:t xml:space="preserve">' . $this->x($part) . '</w:t>';
            }
            $xml .= '</w:r>';
        }
        return $xml ?: '<w:r><w:t/></w:r>';
    }

    private function editorParagraph(array $block): string
    {
        $kind = $block['kind'] ?? 'text';
        $size = $kind === 'title' ? 36 : ($kind === 'heading' ? 26 : ($kind === 'caption' ? 16 : 20));
        $color = in_array($kind, ['title', 'heading'], true) ? '820707' : ($kind === 'caption' ? '6E6A6E' : '333333');
        $runs = is_array($block['runs'] ?? null) ? $block['runs'] : [];
        $line = $kind === 'title' ? 312 : ($kind === 'heading' ? 336 : 384);
        return '<w:p><w:pPr><w:jc w:val="' . $this->editorAlignment($block['align'] ?? 'left') . '"/>'
            . '<w:spacing w:after="180" w:line="' . $line . '" w:lineRule="auto"/>'
            . (in_array($kind, ['title', 'heading'], true) ? '<w:keepNext/>' : '')
            . '</w:pPr>' . $this->editorRuns($runs, $size, $color, in_array($kind, ['title', 'heading'], true)) . '</w:p>';
    }

    private function editorTable(array $block, int $usable): string
    {
        $rows = $block['rows'] ?? [];
        if (!is_array($rows) || count($rows) > 10000) throw new InvalidArgumentException('Invalid table rows.');
        $count = min(30, max(1, count($rows[0]['cells'] ?? [])));
        $shares = [];
        for ($i = 0; $i < $count; $i++) $shares[] = max(1, min(2000, (float)($block['widths'][$i] ?? 100)));
        $total = array_sum($shares);
        $widths = array_map(fn($share) => (int)round($usable * $share / $total), $shares);
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $usable . '" w:type="dxa"/>'
            . '<w:tblLayout w:type="fixed"/><w:tblBorders>'
            . '<w:insideH w:val="single" w:sz="4" w:color="E6D4D4"/>'
            . '</w:tblBorders><w:tblCellMar><w:top w:w="90" w:type="dxa"/>'
            . '<w:bottom w:w="90" w:type="dxa"/><w:left w:w="120" w:type="dxa"/>'
            . '<w:right w:w="120" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) $xml .= '<w:gridCol w:w="' . $width . '"/>';
        $xml .= '</w:tblGrid>';
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['cells'] ?? null)) continue;
            $header = !empty($row['header']);
            $xml .= '<w:tr>' . ($header ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            for ($i = 0; $i < $count; $i++) {
                $cell = is_array($row['cells'][$i] ?? null) ? $row['cells'][$i] : [];
                $runs = is_array($cell['runs'] ?? null) ? $cell['runs'] : [];
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$i] . '" w:type="dxa"/>'
                    . ($header ? '<w:shd w:fill="820707"/>' : '') . '</w:tcPr><w:p><w:pPr>'
                    . '<w:jc w:val="' . $this->editorAlignment($cell['align'] ?? 'left') . '"/>'
                    . '<w:spacing w:line="384" w:lineRule="auto"/>'
                    . '</w:pPr>' . $this->editorRuns($runs, 18, $header ? 'FFFFFF' : '333333', $header)
                    . '</w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
    }

    public function title(string $text, string $subtitle = ''): void
    {
        $this->body .= $this->p($text, ['size' => 36, 'bold' => true, 'color' => '820707',
                                        'after' => 60]);
        if ($subtitle !== '') {
            $this->body .= $this->p($subtitle, ['size' => 19, 'color' => '6E6A6E',
                                                'after' => 240]);
        }
        // A maroon rule under the title block.
        $this->body .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="12" '
                     . 'w:space="1" w:color="820707"/></w:pBdr>'
                     . '<w:spacing w:after="240"/></w:pPr></w:p>';
    }

    public function heading(string $text): void
    {
        $this->body .= $this->p($text, ['size' => 26, 'bold' => true, 'color' => '630000',
                                        'before' => 280, 'after' => 120]);
    }

    public function paragraph(string $text, bool $muted = false): void
    {
        $this->body .= $this->p($text, ['size' => 20, 'after' => 140,
                                        'color' => $muted ? '6E6A6E' : '333333']);
    }

    /**
     * A table. $columns is [[label, widthShare, align], ...] where the shares
     * are relative and scaled to the page width, so a caller does not have to
     * know what a twip is.
     */
    public function table(array $columns, array $rows): void
    {
        $total = 0;
        foreach ($columns as $c) $total += $c[1];
        $grid = '';
        $widths = [];
        foreach ($columns as $c) {
            $w = (int)round(self::USABLE_TWIPS * ($c[1] / $total));
            $widths[] = $w;
            $grid .= '<w:gridCol w:w="' . $w . '"/>';
        }

        $x = '<w:tbl><w:tblPr>'
           . '<w:tblW w:w="' . self::USABLE_TWIPS . '" w:type="dxa"/>'
           . '<w:tblBorders>'
           . '<w:top w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
           . '<w:left w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
           . '<w:bottom w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
           . '<w:right w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
           . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="E6D4D4"/>'
           . '<w:insideV w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
           . '</w:tblBorders>'
           . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/>'
           . '<w:left w:w="90" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar>'
           . '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';

        // Header, repeated at the top of each page the table runs onto.
        $x .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($columns as $i => $c) {
            $x .= $this->cell($c[0], $widths[$i], [
                'bold' => true, 'color' => 'FFFFFF', 'shade' => '820707',
                'align' => $c[2] ?? 'left', 'size' => 18,
            ]);
        }
        $x .= '</w:tr>';

        $stripe = false;
        foreach ($rows as $row) {
            $x .= '<w:tr>';
            foreach ($columns as $i => $c) {
                $x .= $this->cell((string)($row[$i] ?? ''), $widths[$i], [
                    'align' => $c[2] ?? 'left', 'size' => 18, 'color' => '333333',
                    'shade' => $stripe ? 'FDF8F8' : null,
                ]);
            }
            $x .= '</w:tr>';
            $stripe = !$stripe;
        }
        $this->body .= $x . '</w:tbl>' . $this->p('', ['size' => 8, 'after' => 120]);
    }

    /** A PNG figure, centred, with a caption under it. */
    public function image(string $png, int $w, int $h, string $caption = '', array $options = []): void
    {
        $this->images[] = ['data' => $png, 'w' => $w, 'h' => $h];
        $id = count($this->images);

        // 600px across the page reads well; the height follows the aspect.
        $drawW = max(48, min(972, (int)($options['width'] ?? 600)));
        $drawH = (int)round($h * ($drawW / max(1, $w)));
        $cx = $drawW * 9525;
        $cy = $drawH * 9525;

        $align = in_array($options['align'] ?? '', ['left', 'center', 'right'], true) ? $options['align'] : 'center';
        $this->body .= '<w:p><w:pPr><w:jc w:val="' . $align . '"/><w:spacing w:before="120" w:after="60"/></w:pPr>'
            . '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:docPr id="' . $id . '" name="Figure ' . $id . '" descr="'
            . $this->x($caption) . '"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="figure' . $id . '.png"/>'
            . '<pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="rId' . (100 + $id) . '"/>'
            . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        if ($caption !== '') {
            $this->body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr>'
                . '<w:r><w:rPr><w:sz w:val="16"/><w:i/><w:color w:val="6E6A6E"/></w:rPr>'
                . '<w:t xml:space="preserve">' . $this->x($caption) . '</w:t></w:r></w:p>';
        }
    }

    public function pageBreak(): void
    {
        $this->body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    public function output(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->images as $i => $img) {
            $n = $i + 1;
            $rels .= '<Relationship Id="rId' . (100 + $n) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image' . $n . '.png"/>';
            $zip->addFromString('word/media/image' . $n . '.png', $img['data']);
        }
        $zip->addFromString('word/_rels/document.xml.rels', $rels . '</Relationships>');

        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:document '
             . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
             . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
             . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
             . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
             . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
             . '<w:body>' . $this->body
             . '<w:sectPr>' . ($this->landscape
                 ? '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
                 : '<w:pgSz w:w="11906" w:h="16838"/>')
             . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" '
             . 'w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
             . '</w:body></w:document>';
        $zip->addFromString('word/document.xml', $doc);

        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    // ---- internals ---------------------------------------------------------

    private function p(string $text, array $o = []): string
    {
        $rpr = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>'
             . '<w:sz w:val="' . ($o['size'] ?? 20) . '"/>'
             . (!empty($o['bold']) ? '<w:b/>' : '')
             . (!empty($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '')
             . '</w:rPr>';
        $ppr = '<w:pPr><w:spacing w:before="' . ($o['before'] ?? 0)
             . '" w:after="' . ($o['after'] ?? 0) . '"/></w:pPr>';
        return '<w:p>' . $ppr . '<w:r>' . $rpr
             . '<w:t xml:space="preserve">' . $this->x($text) . '</w:t></w:r></w:p>';
    }

    private function cell(string $text, int $width, array $o): string
    {
        $shade = !empty($o['shade'])
            ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['shade'] . '"/>' : '';
        $align = ($o['align'] ?? 'left') === 'right' ? '<w:jc w:val="right"/>' : '';
        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . $shade
             . '<w:vAlign w:val="center"/></w:tcPr>'
             . '<w:p><w:pPr>' . $align . '<w:spacing w:before="20" w:after="20"/></w:pPr>'
             . '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>'
             . '<w:sz w:val="' . ($o['size'] ?? 18) . '"/>'
             . (!empty($o['bold']) ? '<w:b/>' : '')
             . (!empty($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '')
             . '</w:rPr><w:t xml:space="preserve">' . $this->x($text) . '</w:t></w:r></w:p></w:tc>';
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
