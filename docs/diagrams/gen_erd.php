<?php
/**
 * Generates the PAPEL entity-relationship diagram as a Figma-importable SVG.
 *
 * Columns are taken from database/schema.sql and the migration scripts, not
 * invented. The two widest tables are truncated with a count of what is left,
 * because research_papers alone has 32 columns and a box that tall would
 * dwarf everything it relates to.
 *
 * Same Figma constraints as the use case diagram: inline presentation
 * attributes, <text> for text, <g id> for layer names, polygons for crow's feet.
 */

$MAROON='#820707'; $DARK='#630000'; $INK='#330000'; $CREAM='#FFF5F5';
$BORDER='#E6D4D4'; $GREY='#6B5A5A'; $LINE='#B99A9A'; $WHITE='#FFFFFF';
$GOLD='#DCA92C'; $BLUE='#1B5E9E';
$FONT = "Inter, 'Segoe UI', Arial, sans-serif";
$MONO = "'JetBrains Mono', Consolas, 'Courier New', monospace";

$out = [];
function o($s) { global $out; $out[] = $s; }
function esc($s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/* col: [name, type, flag]  flag: PK | FK | '' */
$T = [];
$T['users'] = ['x'=>50,'y'=>132,'cols'=>[
  ['user_id','INT','PK'],['username','VARCHAR(100)',''],['email','VARCHAR(150)',''],
  ['password','VARCHAR(255)',''],['full_name','VARCHAR(200)',''],
  ['user_role','ENUM',''],['admin_type','ENUM',''],['admin_level','TINYINT',''],
  ['created_by','INT','FK'],['student_id','VARCHAR(50)',''],['faculty_id','VARCHAR(50)',''],
  ['program','VARCHAR(255)',''],['academic_year','VARCHAR(20)',''],['section','VARCHAR(40)',''],
  ['expires_on','DATE',''],['is_active','TINYINT',''],['last_login','TIMESTAMP',''],
  ['created_at','TIMESTAMP',''],
]];
$T['research_papers'] = ['x'=>470,'y'=>132,'cols'=>[
  ['paper_id','INT','PK'],['title','VARCHAR(500)',''],['author_names','TEXT',''],
  ['year','INT',''],['abstract','TEXT',''],['imrad_content','LONGTEXT',''],
  ['keywords','TEXT',''],['file_path','VARCHAR(500)',''],['gdrive_file_id','VARCHAR(255)',''],
  ['file_size','BIGINT',''],['uploaded_by','INT','FK'],['upload_date','TIMESTAMP',''],
  ['current_status','ENUM',''],['paper_type','VARCHAR(50)',''],['paper_format','ENUM',''],
  ['is_published','TINYINT',''],['is_imrad_complete','TINYINT',''],
  ['ai_summary','TEXT',''],['ai_analyzed_at','TIMESTAMP',''],
  ['… 13 more columns','',''],
]];
$T['approval_workflow'] = ['x'=>890,'y'=>132,'cols'=>[
  ['workflow_id','INT','PK'],['paper_id','INT','FK'],['reviewer_id','INT','FK'],
  ['review_level','ENUM',''],['admin_level','TINYINT',''],['status','ENUM',''],
  ['feedback','TEXT',''],['submitted_at','TIMESTAMP',''],['reviewed_at','TIMESTAMP',''],
]];
$T['supporting_documents'] = ['x'=>890,'y'=>380,'cols'=>[
  ['doc_id','INT','PK'],['paper_id','INT','FK'],['document_type','ENUM',''],
  ['file_path','VARCHAR(500)',''],['gdrive_file_id','VARCHAR(255)',''],['uploaded_at','TIMESTAMP',''],
]];
$T['paper_checklist'] = ['x'=>890,'y'=>562,'cols'=>[
  ['checklist_id','INT','PK'],['paper_id','INT','FK'],
  ['imrad_intro … references','TINYINT ×5',''],['full_ch1 … references','TINYINT ×6',''],
  ['created_at','TIMESTAMP',''],
]];
$T['imrad_checklist'] = ['x'=>890,'y'=>722,'cols'=>[
  ['checklist_id','INT','PK'],['paper_id','INT','FK'],
  ['has_introduction … references','TINYINT ×6',''],
  ['checked_by','INT','FK'],['checked_at','TIMESTAMP',''],
]];
$T['analytics'] = ['x'=>890,'y'=>882,'cols'=>[
  ['analytics_id','INT','PK'],['paper_id','INT','FK'],['view_count','INT',''],
  ['download_count','INT',''],['citation_count','INT',''],['approval_date','TIMESTAMP',''],
  ['time_to_approval','INT',''],
]];
$T['notifications'] = ['x'=>1310,'y'=>132,'cols'=>[
  ['notification_id','INT','PK'],['user_id','INT','FK'],['paper_id','INT','FK'],
  ['notification_type','ENUM',''],['message','TEXT',''],['is_read','TINYINT',''],
  ['created_at','TIMESTAMP',''],
]];
$T['paper_favorites'] = ['x'=>1310,'y'=>330,'cols'=>[
  ['favorite_id','INT','PK'],['user_id','INT','FK'],['paper_id','INT','FK'],
  ['created_at','TIMESTAMP',''],
]];
$T['manuscript_requests'] = ['x'=>1310,'y'=>470,'cols'=>[
  ['request_id','INT','PK'],['paper_id','INT','FK'],['student_user_id','INT','FK'],
  ['status','ENUM',''],['duration_hours','TINYINT',''],['granted_by','INT','FK'],
  ['granted_at','DATETIME',''],['expires_at','DATETIME',''],['denied_by','INT','FK'],
  ['denied_at','DATETIME',''],['created_at','DATETIME',''],
]];
$T['support_requests'] = ['x'=>1310,'y'=>752,'cols'=>[
  ['request_id','INT','PK'],['kind','ENUM',''],['requester_name','VARCHAR(200)',''],
  ['requester_email','VARCHAR(150)',''],['requester_ident','VARCHAR(50)',''],
  ['requester_user_id','INT','FK'],['handler_user_id','INT','FK'],
  ['message','TEXT',''],['created_at','DATETIME',''],
]];
$T['password_changes'] = ['x'=>1310,'y'=>982,'cols'=>[
  ['change_id','INT','PK'],['user_id','INT','FK'],['changed_by','INT','FK'],
  ['changed_at','DATETIME',''],
]];
$T['papers_archive'] = ['x'=>470,'y'=>700,'cols'=>[
  ['paper_id','INT','PK'],['title','VARCHAR(255)',''],['author_names','TEXT',''],
  ['uploaded_by','INT','FK'],['archived_by','INT','FK'],['archived_date','DATETIME',''],
  ['current_status','VARCHAR(32)',''],['gdrive_file_id','VARCHAR(255)',''],
  ['… 26 more columns','',''],
]];
$T['guest_sessions'] = ['x'=>50,'y'=>640,'cols'=>[
  ['guest_id','INT','PK'],['username','VARCHAR(50)',''],['password','VARCHAR(255)',''],
  ['plain_password','VARCHAR(255)',''],['created_at','DATETIME',''],['expires_at','DATETIME',''],
]];
$T['login_attempts'] = ['x'=>50,'y'=>820,'cols'=>[
  ['scope','VARCHAR','PK'],['attempts','INT',''],['first_attempt','DATETIME',''],
  ['locked_until','DATETIME',''],
]];
$T['system_settings'] = ['x'=>50,'y'=>962,'cols'=>[
  ['setting_key','VARCHAR','PK'],['setting_value','TEXT',''],['description','TEXT',''],
  ['updated_by','INT','FK'],['updated_at','TIMESTAMP',''],
]];

$BW = 330; $HDR = 30; $ROW = 19;
function boxH($t) { global $HDR, $ROW; return $HDR + count($t['cols']) * $ROW + 6; }

/* relationships: [fromTable, toTable, label, cardinality] — "1:N" or "1:1" */
$rels = [
  ['users','research_papers','uploaded_by','1:N'],
  ['users','approval_workflow','reviewer_id','1:N'],
  ['research_papers','approval_workflow','paper_id','1:N'],
  ['research_papers','supporting_documents','paper_id','1:N'],
  ['research_papers','paper_checklist','paper_id','1:1'],
  ['research_papers','imrad_checklist','paper_id','1:1'],
  ['research_papers','analytics','paper_id','1:1'],
  ['research_papers','notifications','paper_id','1:N'],
  ['research_papers','paper_favorites','paper_id','1:N'],
  ['research_papers','manuscript_requests','paper_id','1:N'],
  ['research_papers','papers_archive','archived copy','1:1'],
  ['users','notifications','user_id','1:N'],
  ['users','paper_favorites','user_id','1:N'],
  ['users','manuscript_requests','student_user_id','1:N'],
  ['users','support_requests','requester_user_id','1:N'],
  ['users','password_changes','user_id','1:N'],
  ['users','papers_archive','archived_by','1:N'],
];

$W = 1760; $H = 1230;

o('<svg xmlns="http://www.w3.org/2000/svg" width="'.$W.'" height="'.$H.'" viewBox="0 0 '.$W.' '.$H.'">');
o('<g id="Background"><rect x="0" y="0" width="'.$W.'" height="'.$H.'" fill="'.$WHITE.'"/></g>');

o('<g id="Title">');
o('<text x="40" y="50" font-family="'.$FONT.'" font-size="27" font-weight="700" fill="'.$MAROON.'">PAPEL — Entity Relationship Diagram</text>');
o('<text x="40" y="74" font-family="'.$FONT.'" font-size="14" fill="'.$GREY.'">MariaDB schema &#183; 16 of 20 tables shown &#183; PK = primary key, FK = foreign key</text>');
o('</g>');

/* ---------- relationship lines, behind the boxes ---------- */
function anchors($t, $BW) {
    $h = boxH($t);
    return [
        'l' => [$t['x'], $t['y'] + $h / 2],
        'r' => [$t['x'] + $BW, $t['y'] + $h / 2],
        'cy'=> $t['y'] + $h / 2,
    ];
}
function crow($x, $y, $dir, $col) {
    // three prongs opening back along $dir (-1 pointing left, +1 right)
    $L = 11; $s = 6;
    $p = '';
    foreach ([-$s, 0, $s] as $dy) {
        $p .= '<path d="M '.$x.' '.$y.' L '.($x + $dir * $L).' '.($y + $dy).'" stroke="'.$col.'" stroke-width="1.4" fill="none"/>';
    }
    return $p;
}
o('<g id="Relationships">');
foreach ($rels as [$from, $to, $label, $card]) {
    $a = anchors($T[$from], $BW); $b = anchors($T[$to], $BW);
    $fx = $T[$from]['x']; $tx = $T[$to]['x'];
    // leave from whichever side faces the target
    if ($tx >= $fx + $BW) { $sx = $a['r'][0]; $ex = $b['l'][0]; $sd = 1;  $ed = 1; }
    elseif ($tx + $BW <= $fx) { $sx = $a['l'][0]; $ex = $b['r'][0]; $sd = -1; $ed = -1; }
    else { $sx = $a['r'][0]; $ex = $b['r'][0]; $sd = 1; $ed = 1; }
    $sy = $a['cy']; $ey = $b['cy'];
    $mx = ($sx + $ex) / 2;
    $d = 'M '.$sx.' '.$sy.' L '.$mx.' '.$sy.' L '.$mx.' '.$ey.' L '.$ex.' '.$ey;
    o('<path d="'.$d.'" stroke="'.$LINE.'" stroke-width="1.3" fill="none"/>');
    // "1" at the parent end
    o('<text x="'.($sx + $sd * 12).'" y="'.($sy - 7).'" text-anchor="middle" font-family="'.$FONT.'" font-size="11" font-weight="700" fill="'.$BLUE.'">1</text>');
    if ($card === '1:N') {
        o(crow($ex, $ey, -$ed, $LINE));
        o('<text x="'.($ex - $ed * 20).'" y="'.($ey - 8).'" text-anchor="middle" font-family="'.$FONT.'" font-size="11" font-weight="700" fill="'.$BLUE.'">N</text>');
    } else {
        o('<path d="M '.($ex - $ed*10).' '.($ey-6).' L '.($ex - $ed*10).' '.($ey+6).'" stroke="'.$LINE.'" stroke-width="1.4"/>');
        o('<text x="'.($ex - $ed * 20).'" y="'.($ey - 8).'" text-anchor="middle" font-family="'.$FONT.'" font-size="11" font-weight="700" fill="'.$BLUE.'">1</text>');
    }
    o('<text x="'.$mx.'" y="'.(($sy + $ey)/2 - 5).'" text-anchor="middle" font-family="'.$FONT.'" font-size="10" fill="'.$GREY.'">'.esc($label).'</text>');
}
o('</g>');

/* ---------- entity boxes ---------- */
o('<g id="Entities">');
foreach ($T as $name => $t) {
    $h = boxH($t);
    o('<g id="Table '.esc($name).'">');
    o('<rect x="'.$t['x'].'" y="'.$t['y'].'" width="'.$BW.'" height="'.$h.'" rx="7" fill="'.$WHITE.'" stroke="'.$MAROON.'" stroke-width="1.6"/>');
    o('<path d="M '.$t['x'].' '.($t['y'] + $HDR).' h '.$BW.'" stroke="'.$MAROON.'" stroke-width="1.6"/>');
    o('<rect x="'.$t['x'].'" y="'.$t['y'].'" width="'.$BW.'" height="'.$HDR.'" rx="7" fill="'.$MAROON.'"/>');
    o('<rect x="'.$t['x'].'" y="'.($t['y'] + $HDR - 8).'" width="'.$BW.'" height="8" fill="'.$MAROON.'"/>');
    o('<text x="'.($t['x'] + 12).'" y="'.($t['y'] + 20).'" font-family="'.$FONT.'" font-size="14" font-weight="700" fill="'.$WHITE.'">'.esc($name).'</text>');
    foreach ($t['cols'] as $j => [$cn, $ct, $flag]) {
        $yy = $t['y'] + $HDR + 14 + $j * $ROW;
        if ($flag === 'PK') { $badge = 'PK'; $bc = $GOLD; }
        elseif ($flag === 'FK') { $badge = 'FK'; $bc = $BLUE; }
        else { $badge = ''; $bc = ''; }
        if ($badge !== '') {
            o('<rect x="'.($t['x'] + 8).'" y="'.($yy - 10).'" width="22" height="13" rx="3" fill="'.$bc.'"/>');
            o('<text x="'.($t['x'] + 19).'" y="'.($yy).'" text-anchor="middle" font-family="'.$FONT.'" font-size="9" font-weight="700" fill="'.$WHITE.'">'.$badge.'</text>');
        }
        $weight = $flag !== '' ? '600' : '400';
        o('<text x="'.($t['x'] + 36).'" y="'.$yy.'" font-family="'.$MONO.'" font-size="11" font-weight="'.$weight.'" fill="'.$INK.'">'.esc($cn).'</text>');
        if ($ct !== '') {
            o('<text x="'.($t['x'] + $BW - 10).'" y="'.$yy.'" text-anchor="end" font-family="'.$MONO.'" font-size="10" fill="'.$GREY.'">'.esc($ct).'</text>');
        }
    }
    o('</g>');
}
o('</g>');

/* ---------- legend ---------- */
$lx = 1310; $ly = 1092;
o('<g id="Legend">');
o('<rect x="'.$lx.'" y="'.$ly.'" width="330" height="106" rx="7" fill="'.$CREAM.'" stroke="'.$BORDER.'" stroke-width="1.4"/>');
o('<text x="'.($lx + 12).'" y="'.($ly + 22).'" font-family="'.$FONT.'" font-size="13" font-weight="700" fill="'.$MAROON.'">Legend</text>');
o('<rect x="'.($lx + 12).'" y="'.($ly + 32).'" width="22" height="13" rx="3" fill="'.$GOLD.'"/>');
o('<text x="'.($lx + 23).'" y="'.($ly + 42).'" text-anchor="middle" font-family="'.$FONT.'" font-size="9" font-weight="700" fill="'.$WHITE.'">PK</text>');
o('<text x="'.($lx + 42).'" y="'.($ly + 43).'" font-family="'.$FONT.'" font-size="12" fill="'.$INK.'">primary key</text>');
o('<rect x="'.($lx + 152).'" y="'.($ly + 32).'" width="22" height="13" rx="3" fill="'.$BLUE.'"/>');
o('<text x="'.($lx + 163).'" y="'.($ly + 42).'" text-anchor="middle" font-family="'.$FONT.'" font-size="9" font-weight="700" fill="'.$WHITE.'">FK</text>');
o('<text x="'.($lx + 182).'" y="'.($ly + 43).'" font-family="'.$FONT.'" font-size="12" fill="'.$INK.'">foreign key</text>');
o(crow($lx + 34, $ly + 66, -1, $LINE));
o('<text x="'.($lx + 42).'" y="'.($ly + 70).'" font-family="'.$FONT.'" font-size="12" fill="'.$INK.'">crow&#8217;s foot = the &#8220;many&#8221; end</text>');
o('<text x="'.($lx + 12).'" y="'.($ly + 92).'" font-family="'.$FONT.'" font-size="11" fill="'.$GREY.'">Not shown: ai_processing_log, ai_rate_limits,</text>');
o('<text x="'.($lx + 12).'" y="'.($ly + 105).'" font-family="'.$FONT.'" font-size="11" fill="'.$GREY.'">gdrive_settings, notification_schedule, storage_usage</text>');
o('</g>');

o('</svg>');

file_put_contents(__DIR__ . '/PAPEL_erd.svg', implode("\n", $out));
echo "erd svg written: " . strlen(implode("\n", $out)) . " bytes\n";
