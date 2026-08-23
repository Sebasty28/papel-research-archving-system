<?php
/**
 * Put every account's ID in the column that belongs to its role.
 *
 * student_id is a student's number and faculty_id is a staff number, but four
 * staff accounts carried theirs in student_id, left by an older creation path.
 * Nothing in the current code writes it there, so this is a one-off tidy rather
 * than a recurring problem.
 *
 * It mattered beyond tidiness: sign-in matches student_id *or* faculty_id, so
 * two different numbers opened the same account, and anything picking an ID by
 * "whichever column is filled" showed a Research Adviser's number labelled
 * "Student ID".
 *
 * Where both columns hold something, faculty_id is the one kept: that is the
 * number the desk that created the account issued. The other stops working as a
 * sign-in, which is the point — one account, one ID.
 *
 * Where only student_id holds something, it is moved across rather than
 * dropped, or the account would be left with no ID to sign in with at all.
 *
 * Students are never touched.
 *
 * Safe to run twice: the second run finds nothing to do.
 */
require_once __DIR__ . '/../../config/core.php';

if (PHP_SAPI !== 'cli') {
    require_login();
    if (current_user()['user_role'] !== 'super_admin') {
        http_response_code(403);
        exit('Director only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$conn = db();
$dry  = in_array('--dry-run', array_slice($argv ?? [], 1), true);

$rows = $conn->query(
    "SELECT user_id, full_name, user_role, student_id, faculty_id
       FROM users
      WHERE user_role <> 'student'
        AND student_id IS NOT NULL AND student_id <> ''
      ORDER BY user_id");

if (!$rows || $rows->num_rows === 0) {
    exit("  nothing to do  every staff account already keeps its ID in faculty_id\n");
}

echo $dry ? "Dry run, nothing will be written.\n\n" : '';
$moved = $cleared = 0;

while ($u = $rows->fetch_assoc()) {
    $staff = trim((string)$u['faculty_id']);
    $stray = trim((string)$u['student_id']);

    if ($staff === '') {
        // Nothing in faculty_id, so the stray value is the only ID there is.
        printf("  move     %-28s %s  ->  faculty_id\n", $u['full_name'], $stray);
        if (!$dry) {
            $q = $conn->prepare("UPDATE users SET faculty_id = ?, student_id = NULL WHERE user_id = ?");
            $q->bind_param('si', $stray, $u['user_id']);
            $q->execute();
            $q->close();
        }
        $moved++;
    } else {
        printf("  clear    %-28s keeps %-14s drops %s\n", $u['full_name'], $staff, $stray);
        if (!$dry) {
            $q = $conn->prepare("UPDATE users SET student_id = NULL WHERE user_id = ?");
            $q->bind_param('i', $u['user_id']);
            $q->execute();
            $q->close();
        }
        $cleared++;
    }
}

printf("\n  %d moved, %d cleared.\n", $moved, $cleared);
if (!$dry && $cleared > 0) {
    echo "\n  The dropped numbers no longer sign anybody in. Anyone using one\n"
       . "  should switch to the faculty ID shown above.\n";
}
echo "Done.\n";
