<?php
/**
 * Room for two manuscript files on one paper.
 *
 * A paper can be handed in as IMRAD, as a Full Manuscript, or as both, and both
 * is still one paper: one record, one review, one approval, with two files.
 * research_papers has one file_path / gdrive_file_id / file_size, so the files
 * now live here, one row per file, keyed by the paper.
 *
 * It is a table of its own, not more columns on research_papers, because
 * papers_archive copies that table's columns by name and by a hand-counted
 * bind_param string (ArchiveRepository), and a column missed there once lost a
 * restored paper its file. A table keyed by paper_id is not touched when a
 * paper is archived or restored, and has no foreign key for the same reason
 * supporting_documents has none: its rows are meant to outlive the paper row.
 *
 * format is a VARCHAR holding 'imrad' or 'full', not an ENUM: a value outside
 * an ENUM is stored as an empty string here (CLAUDE.md), and a wrong format
 * ought to be refused in PHP, loudly.
 *
 * research_papers.file_path, gdrive_file_id and file_size keep holding the
 * paper's primary file (the full manuscript when there is one, else the IMRAD),
 * so everything that only needs "the paper's file" goes on working unchanged.
 *
 * Existing papers are backfilled from those columns, in research_papers and in
 * papers_archive. A paper that was ticked as both but carries one file gets that
 * one file, filed under the format the paper_format column names, because
 * nothing records which of the two it was. Drafts are left out: the PDF a draft
 * used to keep in uploads/drafts was never read back and is not a manuscript.
 *
 * Safe to run twice.
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

$made = $conn->query("SHOW TABLES LIKE 'paper_manuscripts'");
if ($made && $made->num_rows > 0) {
    echo "  skipped  paper_manuscripts already exists\n";
} elseif ($conn->query(
    "CREATE TABLE paper_manuscripts (
        manuscript_id  INT(11)      NOT NULL AUTO_INCREMENT,
        paper_id       INT(11)      NOT NULL,
        format         VARCHAR(20)  NOT NULL,
        file_path      VARCHAR(500) NOT NULL DEFAULT '',
        gdrive_file_id VARCHAR(255) DEFAULT NULL,
        file_size      BIGINT(20)   DEFAULT NULL,
        uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (manuscript_id),
        UNIQUE KEY uq_paper_format (paper_id, format)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
)) {
    echo "  created  paper_manuscripts\n";
} else {
    exit("  FAILED   paper_manuscripts: " . $conn->error . "\n");
}

/* The format a paper's one existing file stands for. A single recognised name in
   manuscript_type says it outright; two names, or none (older papers), fall back
   to the paper_format column. */
$format = "CASE
             WHEN manuscript_type = 'Full Manuscript' THEN 'full'
             WHEN manuscript_type = 'IMRAD'           THEN 'imrad'
             WHEN paper_format = 'Manuscript'         THEN 'full'
             ELSE 'imrad'
           END";

echo "\nFiling each paper's existing file...\n";
foreach (['research_papers', 'papers_archive'] as $table) {
    $sql = "INSERT IGNORE INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size)
            SELECT paper_id, $format, COALESCE(file_path, ''), NULLIF(gdrive_file_id, ''), file_size
              FROM `$table` p
             WHERE (COALESCE(file_path, '') <> '' OR COALESCE(gdrive_file_id, '') <> '')
               AND NOT (COALESCE(current_status, '') = 'draft' AND file_path LIKE 'uploads/drafts/%')
               AND NOT EXISTS (SELECT 1 FROM paper_manuscripts m WHERE m.paper_id = p.paper_id)";
    if ($conn->query($sql)) {
        printf("  %-16s %d filed\n", $table, $conn->affected_rows);
    } else {
        exit("  FAILED   $table: " . $conn->error . "\n");
    }
}

echo "\nNow on file:\n";
$res = $conn->query("SELECT format, COUNT(*) AS n FROM paper_manuscripts GROUP BY format ORDER BY format");
foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
    printf("  %-6s %d\n", $r['format'], $r['n']);
}
