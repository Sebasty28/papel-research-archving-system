<?php
class ArchiveRepository {
    private $conn;

    /**
     * Every column that travels with a paper into the archive and back.
     *
     * Archiving deletes the row from research_papers, so a column missing from
     * this list is a column the paper loses for good. One list drives both
     * directions, which is what keeps them from drifting apart — they had,
     * and papers came back missing their text and their file link.
     */
    private const ARCHIVED_COLUMNS = [
        'paper_id', 'title', 'author_names', 'year', 'research_date', 'abstract',
        'imrad_content', 'keywords', 'is_published', 'publication_details',
        'file_path', 'file_size', 'uploaded_by', 'current_status', 'paper_type',
        'paper_format', 'is_imrad_complete', 'gdrive_file_id', 'ai_summary',
        'ai_methodology', 'ai_sample_size', 'ai_statistical_methods', 'ai_variables',
        'ai_research_field', 'ai_analyzed_at', 'backup_status', 'upload_date',
        'research_type', 'manuscript_type', 'publication_status',
        'publication_location', 'program_category', 'source_code_path',
    ];

    /** Bind types for ARCHIVED_COLUMNS, in the same order. */
    private const ARCHIVED_TYPES =
        'i'    // paper_id
      . 'ss'   // title, author_names
      . 'i'    // year
      . 'sss'  // research_date, abstract, imrad_content
      . 's'    // keywords
      . 'i'    // is_published
      . 'ss'   // publication_details, file_path
      . 'ii'   // file_size, uploaded_by
      . 'ss'   // current_status, paper_type
      . 's'    // paper_format
      . 'i'    // is_imrad_complete
      . 's'    // gdrive_file_id
      . 'sssss'// ai_summary, ai_methodology, ai_sample_size, ai_statistical_methods, ai_variables
      . 'sss'  // ai_research_field, ai_analyzed_at, backup_status
      . 's'    // upload_date
      . 'ssss' // research_type, manuscript_type, publication_status, publication_location
      . 'ss';  // program_category, source_code_path

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getActivePaper(int $paperId) {
        $stmt = $this->conn->prepare("SELECT * FROM research_papers WHERE paper_id=?");
        $stmt->bind_param('i', $paperId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getArchivedPaper(int $paperId) {
        $stmt = $this->conn->prepare("SELECT * FROM papers_archive WHERE paper_id=?");
        $stmt->bind_param('i', $paperId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Copies a paper into the archive.
     *
     * Archiving deletes the row from research_papers, so whatever is not
     * copied here is gone for good. Ten columns that papers_archive already
     * has were missing from this statement — including imrad_content, which
     * holds the paper's written sections, and the whole Step 1 record
     * (research/manuscript type, publication status, program). Archiving one
     * paper therefore emptied it out, and restoring it brought back a shell.
     * Every column of the paper is now written, including research_date and the
     * status it held when it was archived — papers_archive gained columns for
     * those so that archiving preserves the whole record rather than a summary
     * of it.
     */
    public function insertToArchive(array $paper, int $archivedBy) {
        $columns = self::ARCHIVED_COLUMNS;
        //          paper_id, title, authors, year, research_date, abstract, imrad …
        $types = self::ARCHIVED_TYPES . 'i';   // + archived_by

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO papers_archive (' . implode(', ', $columns)
             . ', archived_date, archived_by) VALUES (' . $placeholders . ', NOW(), ?)';

        $values = [];
        foreach ($columns as $c) $values[] = $paper[$c] ?? null;
        $values[] = $archivedBy;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        return $stmt->execute();
    }

    /**
     * Puts an archived paper back into circulation.
     *
     * The type string here used to be 'issississsisssssss', which does not line
     * up with the columns: file_path was bound as an integer and came back as
     * "0", gdrive_file_id as "1". A restored paper therefore lost the link to
     * its own file — silently, because the insert still succeeded. Building
     * the statement from one column list makes that class of mistake
     * impossible, and the columns match insertToArchive() so nothing is
     * dropped on the way back either.
     */
    public function insertToActive(array $paper) {
        $columns = self::ARCHIVED_COLUMNS;
        $types   = self::ARCHIVED_TYPES;

        /* A paper goes back to the status it held when it was archived. Rows
           archived before that status was recorded have nothing to go back to,
           so they return as approved — which is what they were, since approved
           is the only thing the Director can archive. */
        if (empty($paper['current_status'])) $paper['current_status'] = 'approved';

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO research_papers (' . implode(', ', $columns)
             . ") VALUES ($placeholders)";

        $values = [];
        foreach ($columns as $c) $values[] = $paper[$c] ?? null;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        return $stmt->execute();
    }

    public function deleteActive(int $paperId) {
        $stmt = $this->conn->prepare("DELETE FROM research_papers WHERE paper_id=?");
        $stmt->bind_param('i', $paperId);
        return $stmt->execute();
    }

    public function deleteArchive(int $paperId) {
        $stmt = $this->conn->prepare("DELETE FROM papers_archive WHERE paper_id=?");
        $stmt->bind_param('i', $paperId);
        return $stmt->execute();
    }

    // Additional methods for fetching public archive lists can be added here
}