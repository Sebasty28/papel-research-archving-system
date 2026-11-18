<?php
/**
 * Read-only query plan snapshot for the public repository.
 * Run from the project root: php scripts/explain_public_repository.php
 * Repeat after the archive grows or a database index changes.
 */
require_once __DIR__ . '/../config/core.php';

$conn = db();
$count = (int)$conn->query("SELECT COUNT(*) FROM research_papers WHERE current_status = 'approved'")
                   ->fetch_row()[0];
$sampleType = $conn->query("SELECT paper_type FROM research_papers WHERE current_status = 'approved'
                             AND paper_type IS NOT NULL LIMIT 1")->fetch_row()[0] ?? '';
$sampleTypeSql = $conn->real_escape_string($sampleType);
$queries = [
    'count' => "SELECT COUNT(*) FROM research_papers WHERE current_status = 'approved'",
    'first_page' => "SELECT paper_id, title, year, research_date, abstract, keywords, author_names,
                            upload_date, paper_type, program_category, publication_status
                     FROM research_papers WHERE current_status = 'approved'
                     ORDER BY COALESCE(research_date, MAKEDATE(year, 1)) DESC LIMIT 20 OFFSET 0",
    'search' => "SELECT paper_id FROM research_papers WHERE current_status = 'approved'
                 AND (title LIKE '%study%' OR keywords LIKE '%study%'
                      OR abstract LIKE '%study%' OR author_names LIKE '%study%')
                 ORDER BY COALESCE(research_date, MAKEDATE(year, 1)) DESC LIMIT 20",
    'filter_type' => "SELECT paper_id FROM research_papers WHERE current_status = 'approved'
                      AND paper_type = '$sampleTypeSql'
                      ORDER BY COALESCE(research_date, MAKEDATE(year, 1)) DESC LIMIT 20",
    'filter_build_keywords' => "SELECT keywords FROM research_papers WHERE current_status = 'approved'
                                AND keywords IS NOT NULL AND keywords <> ''",
];

foreach ($queries as $name => $sql) {
    $rows = $conn->query('EXPLAIN ' . $sql)->fetch_all(MYSQLI_ASSOC);
    $plan = array_map(static function (array $row): array {
        return [
            'access' => $row['type'],
            'key' => $row['key'],
            'estimated_rows' => (int)$row['rows'],
            'extra' => $row['Extra'],
        ];
    }, $rows);
    echo json_encode(['approved_papers' => $count, 'query' => $name, 'plan' => $plan],
                     JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
