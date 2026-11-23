<?php
/**
 * What "this is already on file" means, as a list of cases.
 *
 * Runs paper_duplicate_problem() against the real database inside a transaction
 * that is always rolled back, so nothing is left behind. It needs
 * paper_manuscripts.file_hash (scripts/migrations/run_manuscript_file_hash_migration.php)
 * and two student accounts.
 *
 * Each refusal is a way a paper really arrives twice; each acceptance is a way
 * that must NOT be refused, because the form's own flow depends on it (a returned
 * paper resubmitted with the same file, a draft nobody has been asked to read,
 * a row left behind by a paper that no longer exists).
 *
 *     php tests/_duplicate_unit.php
 */
require_once __DIR__ . '/../config/core.php';

if (!paper_manuscripts_have_hash()) {
    fwrite(STDERR, "paper_manuscripts.file_hash is missing: run the migration first.\n");
    exit(1);
}

$conn = db();
$ids = $conn->query("SELECT user_id FROM users WHERE user_role = 'student' ORDER BY user_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($ids) < 2) { fwrite(STDERR, "Needs two student accounts.\n"); exit(1); }
$me    = (int)$ids[0]['user_id'];
$other = (int)$ids[1]['user_id'];

$ok = 0; $bad = 0;
function expect_ok($label, $got) {
    global $ok, $bad;
    if ($got === null) { $ok++; return; }
    $bad++; echo "REFUSED, should have passed: $label\n   -> $got\n";
}
function expect_no($label, $got, array $mustHave = [], array $mustNot = []) {
    global $ok, $bad;
    if ($got === null) { $bad++; echo "ACCEPTED, should have been refused: $label\n"; return; }
    foreach ($mustHave as $m) {
        if (stripos($got, $m) === false) { $bad++; echo "WRONG WORDS for $label: missing '$m'\n   -> $got\n"; return; }
    }
    foreach ($mustNot as $m) {
        if (stripos($got, $m) !== false) { $bad++; echo "LEAKED for $label: '$m' must not appear\n   -> $got\n"; return; }
    }
    $ok++;
}

function h(string $s): string { return hash('sha256', 'unit-test:' . $s); }

$conn->begin_transaction();
try {
    $paper = function (string $title, int $by, string $status) use ($conn): int {
        $st = $conn->prepare("INSERT INTO research_papers (title,author_names,year,abstract,keywords,file_path,file_size,uploaded_by,current_status,paper_type,gdrive_file_id)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $a = 'Unit Test'; $y = 2026; $ab = 'x'; $kw = 'a,b,c,d,e'; $fp = ''; $sz = 0; $pt = 'undergrad_thesis'; $gd = null;
        $st->bind_param('ssisssiisss', $title, $a, $y, $ab, $kw, $fp, $sz, $by, $status, $pt, $gd);
        $st->execute();
        return (int)$st->insert_id;
    };
    $file = function (int $paperId, string $fmt, string $hash) use ($conn): void {
        $st = $conn->prepare("INSERT INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size, file_hash) VALUES (?,?,?,?,?,?)");
        $p = ''; $g = null; $z = 1;
        $st->bind_param('isssis', $paperId, $fmt, $p, $g, $z, $hash);
        $st->execute();
    };

    // Somebody else's approved paper, my own pending one, my own returned one, a draft nobody was asked to read.
    $A = $paper('Zeta Quasar Study', $other, 'approved');          $file($A, 'full', h('A'));
    $B = $paper('My Own Work', $me, 'pending_faculty');            $file($B, 'full', h('B'));
    $C = $paper('Returned Thing', $me, 'draft');                   $file($C, 'full', h('C'));
    $conn->query("INSERT INTO approval_workflow (paper_id, reviewer_id, review_level, status) VALUES ($C, $other, 'faculty', 'declined')");
    $D = $paper('Pure Draft Title', $other, 'draft');
    // An archived paper (its row is in papers_archive, its file row stays), and a file row whose paper is gone.
    $E = 999999001; $F = 999999002;
    $st = $conn->prepare("INSERT INTO papers_archive (paper_id, title, uploaded_by, current_status) VALUES (?, 'Archived Old Study', ?, 'approved')");
    $st->bind_param('ii', $E, $other); $st->execute();
    $file($E, 'full', h('E'));
    $file($F, 'full', h('F'));

    $labels = ['imrad' => 'IMRAD', 'full' => 'Full Manuscript'];

    // ---- what must pass ------------------------------------------------------------
    expect_ok('a new file and a new title',
        paper_duplicate_problem(['full' => h('new1')], $labels, 'Brand New Title', $me));
    expect_ok('two different files in the two boxes',
        paper_duplicate_problem(['imrad' => h('new2'), 'full' => h('new3')], $labels, 'Brand New Title', $me));
    expect_ok('a returned paper resubmitted with the same file and title (its own draft)',
        paper_duplicate_problem(['full' => h('C')], $labels, 'Returned Thing', $me, $C));
    expect_ok('a row left by a paper that no longer exists',
        paper_duplicate_problem(['full' => h('F')], $labels, 'Brand New Title', $me));
    expect_ok('the title of somebody else\'s draft nobody has submitted',
        paper_duplicate_problem(['full' => h('new4')], $labels, 'Pure Draft Title', $me));
    expect_ok('no file to compare, a new title',
        paper_duplicate_problem([], $labels, 'Another New Title', $me));
    expect_ok('a title with quotes in it',
        paper_duplicate_problem(['full' => h('new5')], $labels, "x' OR '1'='1 -- \"", $me));

    // ---- what must be refused ------------------------------------------------------
    expect_no('one PDF in both boxes',
        paper_duplicate_problem(['imrad' => h('same'), 'full' => h('same')], $labels, 'Brand New Title', $me),
        ['IMRAD', 'Full Manuscript', 'same PDF']);
    expect_no('somebody else\'s PDF',
        paper_duplicate_problem(['full' => h('A')], $labels, 'Brand New Title', $me),
        ['already been submitted'], ['Zeta', 'Quasar']);
    expect_no('my own PDF on another paper',
        paper_duplicate_problem(['full' => h('B')], $labels, 'Brand New Title', $me),
        ['already filed under your paper', 'My Own Work']);
    expect_no('my returned paper\'s PDF when not continuing that draft',
        paper_duplicate_problem(['full' => h('C')], $labels, 'Brand New Title', $me),
        ['Returned Thing']);
    expect_no('somebody else\'s draft id does not count as mine',
        paper_duplicate_problem(['full' => h('C')], $labels, 'Brand New Title', $other, $C),
        ['already been submitted']);
    expect_no('an archived paper\'s PDF',
        paper_duplicate_problem(['full' => h('E')], $labels, 'Brand New Title', $me),
        ['already been submitted']);
    expect_no('the matching file is named for the right box',
        paper_duplicate_problem(['imrad' => h('new6'), 'full' => h('A')], $labels, 'Brand New Title', $me),
        ['Your Full Manuscript PDF']);
    expect_no('somebody else\'s title, other case and spacing',
        paper_duplicate_problem(['full' => h('new7')], $labels, '  zeta   QUASAR   study ', $me),
        ['title is already on file'], ['Zeta']);
    expect_no('my own title on a submitted paper',
        paper_duplicate_problem(['full' => h('new8')], $labels, 'my own work', $me),
        ['already submitted a paper with this title']);
    expect_no('the title of a returned paper (it was submitted once)',
        paper_duplicate_problem(['full' => h('new9')], $labels, 'Returned Thing', $other),
        ['title is already on file']);
    expect_no('an archived paper\'s title',
        paper_duplicate_problem(['full' => h('new10')], $labels, 'archived old study', $me),
        ['title is already on file']);
} finally {
    $conn->rollback();
}

$left = (int)$conn->query("SELECT COUNT(*) c FROM research_papers WHERE title IN ('Zeta Quasar Study','My Own Work','Returned Thing','Pure Draft Title')")->fetch_assoc()['c'];
if ($left !== 0) { $bad++; echo "ROLLBACK LEFT $left row(s) behind\n"; }

echo ($bad === 0 ? "OK" : "FAILED") . ": $ok passed, $bad failed\n";
exit($bad === 0 ? 0 : 1);
