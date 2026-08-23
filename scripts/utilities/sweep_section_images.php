<?php
/**
 * Delete pictures pasted into a section whose draft was then abandoned.
 *
 * A picture is uploaded the moment it is pasted, before the draft that holds it
 * has been saved. If the student then discards the draft, or deletes the
 * picture again and saves, the file stays on disk with nothing pointing at it.
 * Nothing else in the system ever removes those.
 *
 * A file is kept when its name appears in the stored section HTML of any paper,
 * in research_papers or in papers_archive. Everything else is an orphan.
 *
 * Files newer than the grace period are never touched, whatever the database
 * says: between pasting a picture and the autosave that records it, the only
 * thing pointing at the file is a box in somebody's browser. The default of 24
 * hours is far longer than that gap.
 *
 * The upload endpoint already runs this on one upload in fifty, so in normal
 * use it never needs running by hand. This is for looking at what is there, and
 * for clearing a backlog after a busy period.
 *
 * Safe to run twice. Nothing in the database changes.
 *
 *   php scripts/utilities/sweep_section_images.php --dry-run
 *   php scripts/utilities/sweep_section_images.php
 *   php scripts/utilities/sweep_section_images.php --hours=72
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    // Deleting files is not something a stray URL should be able to start.
    http_response_code(404);
    exit;
}

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true) || in_array('-n', $args, true);
$hours  = SECTION_IMAGE_GRACE_HOURS;
foreach ($args as $arg) {
    if (preg_match('/^--hours=(\d+)$/', $arg, $m)) $hours = (int)$m[1];
}

function human_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

echo "Section picture sweep\n";
echo "  grace period : {$hours} hours\n";
echo '  mode         : ' . ($dryRun ? "dry run, nothing will be deleted\n" : "deleting\n");
echo "\n";

$r = section_images_sweep($dryRun, $hours);

echo "  pictures on disk        : {$r['scanned']}\n";
echo "  named by some paper     : {$r['referenced']}\n";
echo "  too recent to judge     : {$r['kept_recent']}\n";
echo '  ' . ($dryRun ? 'would be removed        : ' : 'removed                 : ') . $r['removed'] . "\n";
echo '  space ' . ($dryRun ? 'that would free  : ' : 'freed             : ') . human_size($r['freed']) . "\n";

if ($r['names']) {
    echo "\n  " . ($dryRun ? 'Would remove' : 'Removed') . ":\n";
    foreach (array_slice($r['names'], 0, 40) as $name) {
        echo "    {$name}\n";
    }
    if (count($r['names']) > 40) {
        echo '    ... and ' . (count($r['names']) - 40) . " more\n";
    }
}

echo "\nDone.\n";
