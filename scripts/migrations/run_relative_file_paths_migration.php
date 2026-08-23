<?php
/**
 * Make stored file paths portable.
 *
 * Uploads were recorded as a fully qualified URL — BASE_URL with the relative
 * path glued on — so every row carried the address of the machine it was
 * uploaded from. "http://localhost/capstone/app/student/uploads/…" is fine as a
 * link on that one machine and useless everywhere else: move the project to
 * another computer, serve it on a different port, or open it over the LAN, and
 * the paths point somewhere that does not exist.
 *
 * It also broke things on the original machine. Anything that needed the file
 * rather than a link built a path by concatenation and got
 * "…/archive/../http://localhost/…", which can never resolve — so the download
 * button answered 404 and the local PDF text extraction silently found nothing.
 *
 * This rewrites each stored value to the part after /app/student/, which is
 * what the code now writes. paper_file_url() renders a correct link from it on
 * whatever host the site is being served from, and paper_file_disk_path()
 * turns it back into a real file.
 *
 * Rows that are already relative are left alone. Safe to run twice.
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (!in_array(current_user()['user_role'], ['super_admin', 'admin'], true)) {
        http_response_code(403);
        exit('Staff only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();
$marker = '/app/student/';

/* papers_archive is included deliberately: it outlives the papers it copies,
   so its rows would otherwise keep the stale absolute form forever. */
$targets = [
    ['research_papers',      'paper_id', 'file_path'],
    ['papers_archive',       'paper_id', 'file_path'],
    ['supporting_documents', 'doc_id',   'file_path'],
];

$totalChanged = 0;

foreach ($targets as [$table, $key, $col]) {
    $exists = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$exists || $exists->num_rows === 0) {
        printf("  skipped  %-22s (no such table)\n", $table);
        continue;
    }

    $rows = $conn->query("SELECT `$key` AS id, `$col` AS p FROM `$table`
                          WHERE `$col` IS NOT NULL AND `$col` <> ''")
                 ->fetch_all(MYSQLI_ASSOC);

    $set = $conn->prepare("UPDATE `$table` SET `$col` = ? WHERE `$key` = ?");
    $changed = $already = $odd = 0;

    foreach ($rows as $r) {
        $stored = (string)$r['p'];
        $at = strpos($stored, $marker);

        if ($at === false) {
            // Already relative, or something this migration does not understand.
            if (preg_match('#^https?://#i', $stored)) { $odd++; } else { $already++; }
            continue;
        }

        $relative = ltrim(substr($stored, $at + strlen($marker)), '/');
        if ($relative === '' || $relative === $stored) { $already++; continue; }

        $set->bind_param('si', $relative, $r['id']);
        $set->execute();
        $changed++;
    }

    printf("  %-22s %d rewritten, %d already relative%s\n",
           $table, $changed, $already,
           $odd ? ", $odd left alone (URL not pointing into app/student/)" : '');
    $totalChanged += $changed;
}

echo "\n$totalChanged path(s) made portable.\n";
if ($totalChanged) {
    echo "The files themselves have not moved — only how they are recorded.\n";
}
