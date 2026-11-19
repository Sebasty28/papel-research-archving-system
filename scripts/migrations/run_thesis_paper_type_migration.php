<?php
/**
 * "Thesis" is the Undergraduate Thesis.
 *
 * "thesis" is the retired paper-type code for what the upload form now calls an
 * Undergraduate Thesis ('undergrad_thesis'). Papers filed before the rename still
 * carry it, so every filter offered "Thesis" beside "Undergraduate Thesis" and the
 * analytics charts drew one kind of paper as two slices. The owner asked for them
 * to be one category (20 November 2026).
 *
 * Reading an old row keeps working either way (paper_type_aliases() in
 * config/core.php), which is the tolerant path for a copy of the site this has
 * not been run on. This renames what is on file, live papers and archive alike.
 *
 * Safe to run twice: the second run finds nothing to rename and says 0.
 *
 *     php scripts/migrations/run_thesis_paper_type_migration.php
 *
 * A server with no shell runs
 * scripts/migrations/sql/2026-11-20_thesis_paper_type.sql in phpMyAdmin instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../../config/core.php';

$conn = db();
foreach (['research_papers', 'papers_archive'] as $table) {
    $stmt = $conn->prepare("UPDATE $table SET paper_type = 'undergrad_thesis' WHERE LOWER(TRIM(paper_type)) = 'thesis'");
    $stmt->execute();
    echo "$table: renamed {$stmt->affected_rows} paper(s) from Thesis to Undergraduate Thesis\n";
    $stmt->close();
}

echo "done.\n";
