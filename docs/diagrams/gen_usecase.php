<?php
/**
 * Generates the PAPEL use case diagram as a Figma-importable SVG.
 *
 * Figma's SVG importer keeps <text> as editable text and turns <g id="..">
 * into named layers, but it drops <style> blocks and marker-end arrowheads.
 * So: every colour is an inline presentation attribute, and arrowheads are
 * drawn as real polygons.
 */

$W = 1780; $H = 1430;

/* PAPEL's own palette, so the diagram matches the system it documents. */
$MAROON = '#820707';
$DARK   = '#630000';
$INK    = '#330000';
$CREAM  = '#FFF5F5';
$BORDER = '#E6D4D4';
$GREY   = '#6B5A5A';
$LINE   = '#B99A9A';
$WHITE  = '#FFFFFF';
$GOLD   = '#DCA92C';
$FONT   = "Inter, 'Segoe UI', Arial, sans-serif";

$out = [];
function o($s) { global $out; $out[] = $s; }
function esc($s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/* ---------- geometry ---------- */
$BX = 430; $BY = 92; $BW = 860; $BH = 1180;      // system boundary
$LCX = 630; $RCX = 1090;                          // use case column centres
$UW = 178; $UH = 32;                              // ellipse radii

$rowTop = 150; $rowGap = 78;
function rowY($i) { global $rowTop, $rowGap; return $rowTop + $i * $rowGap; }

/* label => [column, row]  (| splits onto two lines) */
$left = [
  'signin'     => 'Sign In',
  'browse'     => 'Browse Public|Archive',
  'viewpaper'  => 'View Published|Paper',
  'upload'     => 'Upload Research|Paper',
  'aiextract'  => 'Extract Metadata|with AI',
  'draft'      => 'Save / Delete|Draft',
  'submit'     => 'Submit for Review',
  'cancel'     => 'Cancel Submission',
  'puppy'      => 'Ask PUPPY|Assistant',
  'reqms'      => 'Request Manuscript|Access',
  'fav'        => 'Favourite a Paper',
  'chgpw'      => 'Change Password',
  'support'    => 'Request Support',
  'notif'      => 'View Notifications',
];
$right = [
  'captcha'    => 'Verify reCAPTCHA',
  'review'     => 'Review Paper',
  'approve'    => 'Approve Paper',
  'return'     => 'Return with|Feedback',
  'publish'    => 'Publish Paper',
  'mstudents'  => 'Manage Students',
  'mfaculty'   => 'Manage Faculty',
  'madmins'    => 'Manage Admins',
  'mguests'    => 'Manage Guest|Passes',
  'grantms'    => 'Grant / Deny|Manuscript Access',
  'analytics'  => 'View Analytics',
  'gdrive'     => 'Configure|Google Drive',
  'pwaudit'    => 'Audit Password|Changes',
  'autoarch'   => 'Auto-archive Paper',
];

$pos = [];
$i = 0; foreach ($left  as $k => $v) { $pos[$k] = [$LCX, rowY($i)]; $i++; }
$i = 0; foreach ($right as $k => $v) { $pos[$k] = [$RCX, rowY($i)]; $i++; }

/* actors: id => [x, y, label, isSystem] */
$actors = [
  'student'   => [180,  430, 'Student',              false],
  'guest'     => [180,  880, 'Guest',                false],
  'adviser'   => [1590, 210, 'Research Adviser',     false],
  'coord'     => [1590, 430, 'Research Coordinator', false],
  'hap'       => [1590, 650, 'Head of Academic|Programs', false],
  'director'  => [1590, 870, 'Director',             false],
  'librarian' => [1590, 1090,'Librarian',            false],
  'gdriveSys' => [180,  1150,'Google Drive',         true],
  'groqSys'   => [180,  1290,'Groq AI',              true],
  'capSys'    => [1590, 1290,'Google reCAPTCHA',     true],
];

/* actor => use cases */
$links = [
  'student'   => ['signin','viewpaper','upload','draft','submit','cancel','puppy','reqms','fav','chgpw','support','notif'],
  'guest'     => ['signin','browse','viewpaper'],
  'adviser'   => ['review','approve','return','mstudents','notif'],
  'coord'     => ['review','approve','publish','mfaculty','analytics'],
  'hap'       => ['review','analytics'],
  'director'  => ['madmins','gdrive','pwaudit','analytics'],
  'librarian' => ['mguests','grantms'],
  'gdriveSys' => ['upload','publish','autoarch'],
  'groqSys'   => ['aiextract','puppy'],
  'capSys'    => ['captcha'],
];

/* ---------- header ---------- */
o('<svg xmlns="http://www.w3.org/2000/svg" width="'.$W.'" height="'.$H.'" viewBox="0 0 '.$W.' '.$H.'">');
o('<g id="Background"><rect x="0" y="0" width="'.$W.'" height="'.$H.'" fill="'.$WHITE.'"/></g>');

o('<g id="Title">');
o('<text x="40" y="52" font-family="'.$FONT.'" font-size="27" font-weight="700" fill="'.$MAROON.'">PAPEL — Use Case Diagram</text>');
o('<text x="40" y="76" font-family="'.$FONT.'" font-size="14" fill="'.$GREY.'">Research Archiving System for PUP Bi&#241;an &#183; actors, system boundary and use cases</text>');
o('</g>');

/* ---------- system boundary ---------- */
o('<g id="System Boundary">');
o('<rect x="'.$BX.'" y="'.$BY.'" width="'.$BW.'" height="'.$BH.'" rx="14" fill="'.$CREAM.'" stroke="'.$MAROON.'" stroke-width="2"/>');
o('<text x="'.($BX + $BW/2).'" y="'.($BY + 34).'" text-anchor="middle" font-family="'.$FONT.'" font-size="17" font-weight="700" fill="'.$MAROON.'">PAPEL Research Archiving System</text>');
o('</g>');

/* ---------- connection lines (drawn first, so shapes sit on top) ---------- */
o('<g id="Associations" stroke="'.$LINE.'" stroke-width="1.2" fill="none">');
foreach ($links as $a => $ucs) {
    [$ax, $ay, , $isSys] = $actors[$a];
    $fromLeft = $ax < $W / 2;
    $sx = $fromLeft ? $ax + 34 : $ax - 34;
    foreach ($ucs as $uc) {
        if (!isset($pos[$uc])) continue;
        [$ux, $uy] = $pos[$uc];
        $ex = $fromLeft ? $ux - $UW / 2 : $ux + $UW / 2;
        $dash = $isSys ? ' stroke-dasharray="5 4"' : '';
        o('<path d="M '.$sx.' '.$ay.' L '.$ex.' '.$uy.'"'.$dash.'/>');
    }
}
o('</g>');

/* ---------- <<include>> / <<extend>> ---------- */
function arrowHead($x1, $y1, $x2, $y2, $fill) {
    $a = atan2($y2 - $y1, $x2 - $x1); $L = 11; $w = 4.5;
    $p1 = ($x2) . ',' . ($y2);
    $p2 = ($x2 - $L * cos($a) + $w * sin($a)) . ',' . ($y2 - $L * sin($a) - $w * cos($a));
    $p3 = ($x2 - $L * cos($a) - $w * sin($a)) . ',' . ($y2 - $L * sin($a) + $w * cos($a));
    return '<polygon points="'.$p1.' '.$p2.' '.$p3.'" fill="'.$fill.'"/>';
}
o('<g id="Include and Extend">');
// Sign In  <<include>>  Verify reCAPTCHA
[$sx1, $sy1] = $pos['signin']; [$cx1, $cy1] = $pos['captcha'];
o('<path d="M '.($sx1 + $UW/2).' '.$sy1.' L '.($cx1 - $UW/2).' '.$cy1.'" stroke="'.$MAROON.'" stroke-width="1.3" stroke-dasharray="7 4" fill="none"/>');
o(arrowHead($sx1 + $UW/2, $sy1, $cx1 - $UW/2, $cy1, $MAROON));
o('<text x="'.(($sx1 + $cx1)/2).'" y="'.($sy1 - 8).'" text-anchor="middle" font-family="'.$FONT.'" font-size="12" font-style="italic" fill="'.$MAROON.'">&#171;include&#187;</text>');
// Extract Metadata with AI  <<extend>>  Upload
[$ax1, $ay1] = $pos['aiextract']; [$ux1, $uy1] = $pos['upload'];
o('<path d="M '.($ax1 - $UW/2 + 22).' '.($ay1 - $UH).' L '.($ux1 - $UW/2 + 22).' '.($uy1 + $UH).'" stroke="'.$GOLD.'" stroke-width="1.3" stroke-dasharray="7 4" fill="none"/>');
o(arrowHead($ax1 - $UW/2 + 22, $ay1 - $UH, $ux1 - $UW/2 + 22, $uy1 + $UH, $GOLD));
o('<text x="'.($ax1 - $UW/2 - 8).'" y="'.(($ay1 + $uy1)/2).'" text-anchor="end" font-family="'.$FONT.'" font-size="12" font-style="italic" fill="'.$GOLD.'">&#171;extend&#187;</text>');
o('</g>');

/* ---------- use case ellipses ---------- */
function ellipse($key, $label, $pos, $UW, $UH, $FONT, $WHITE, $MAROON, $INK) {
    [$x, $y] = $pos[$key];
    $s  = '<g id="UC '.esc(str_replace('|', ' ', $label)).'">';
    $s .= '<ellipse cx="'.$x.'" cy="'.$y.'" rx="'.($UW/2).'" ry="'.$UH.'" fill="'.$WHITE.'" stroke="'.$MAROON.'" stroke-width="1.6"/>';
    $lines = explode('|', $label);
    $n = count($lines);
    foreach ($lines as $j => $ln) {
        $dy = $n === 1 ? 5 : ($j === 0 ? -4 : 13);
        $s .= '<text x="'.$x.'" y="'.($y + $dy).'" text-anchor="middle" font-family="'.$FONT.'" font-size="13" fill="'.$INK.'">'.esc($ln).'</text>';
    }
    return $s . '</g>';
}
o('<g id="Use Cases">');
foreach ($left  as $k => $v) o(ellipse($k, $v, $pos, $UW, $UH, $FONT, $WHITE, $MAROON, $INK));
foreach ($right as $k => $v) o(ellipse($k, $v, $pos, $UW, $UH, $FONT, $WHITE, $MAROON, $INK));
o('</g>');

/* ---------- actors ---------- */
o('<g id="Actors">');
foreach ($actors as $id => [$x, $y, $label, $isSys]) {
    o('<g id="Actor '.esc(str_replace('|', ' ', $label)).'">');
    if ($isSys) {
        // External systems are boxes, not stick figures: they are not people.
        o('<rect x="'.($x - 62).'" y="'.($y - 26).'" width="124" height="52" rx="8" fill="'.$WHITE.'" stroke="'.$GREY.'" stroke-width="1.6" stroke-dasharray="5 4"/>');
        o('<text x="'.$x.'" y="'.($y - 4).'" text-anchor="middle" font-family="'.$FONT.'" font-size="11" font-style="italic" fill="'.$GREY.'">&#171;system&#187;</text>');
        o('<text x="'.$x.'" y="'.($y + 13).'" text-anchor="middle" font-family="'.$FONT.'" font-size="13" font-weight="600" fill="'.$INK.'">'.esc($label).'</text>');
    } else {
        o('<circle cx="'.$x.'" cy="'.($y - 30).'" r="13" fill="'.$WHITE.'" stroke="'.$DARK.'" stroke-width="2"/>');
        o('<path d="M '.$x.' '.($y - 17).' L '.$x.' '.($y + 12).' M '.($x - 18).' '.($y - 6).' L '.($x + 18).' '.($y - 6).
          ' M '.$x.' '.($y + 12).' L '.($x - 14).' '.($y + 34).' M '.$x.' '.($y + 12).' L '.($x + 14).' '.($y + 34).'" stroke="'.$DARK.'" stroke-width="2" fill="none" stroke-linecap="round"/>');
        $lines = explode('|', $label);
        foreach ($lines as $j => $ln) {
            o('<text x="'.$x.'" y="'.($y + 52 + $j * 16).'" text-anchor="middle" font-family="'.$FONT.'" font-size="13" font-weight="600" fill="'.$INK.'">'.esc($ln).'</text>');
        }
    }
    o('</g>');
}
o('</g>');

/* ---------- legend ---------- */
$lx = 40; $ly = 104;   // under the title: the bottom-left corner belongs to the external systems
o('<g id="Legend">');
o('<rect x="'.$lx.'" y="'.$ly.'" width="250" height="132" rx="8" fill="'.$WHITE.'" stroke="'.$BORDER.'" stroke-width="1.4"/>');
o('<text x="'.($lx + 14).'" y="'.($ly + 24).'" font-family="'.$FONT.'" font-size="13" font-weight="700" fill="'.$MAROON.'">Legend</text>');
$items = [
  ['solid', $LINE,  'association (actor uses)'],
  ['dash',  $GREY,  'external system'],
  ['dash',  $MAROON,'&#171;include&#187; — always runs'],
  ['dash',  $GOLD,  '&#171;extend&#187; — optional'],
];
foreach ($items as $j => [$style, $col, $txt]) {
    $yy = $ly + 48 + $j * 22;
    $da = $style === 'dash' ? ' stroke-dasharray="5 4"' : '';
    o('<path d="M '.($lx + 14).' '.$yy.' L '.($lx + 46).' '.$yy.'" stroke="'.$col.'" stroke-width="1.6"'.$da.' fill="none"/>');
    o('<text x="'.($lx + 56).'" y="'.($yy + 4).'" font-family="'.$FONT.'" font-size="12" fill="'.$INK.'">'.$txt.'</text>');
}
o('</g>');

o('</svg>');

$dir = __DIR__;
file_put_contents($dir . '/PAPEL_use_case_diagram.svg', implode("\n", $out));
echo "use case svg written: " . strlen(implode("\n", $out)) . " bytes\n";
