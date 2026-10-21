<?php
/**
 * Let supporting_documents say what a document is.
 *
 * document_type was an ENUM of ethics_clearance, consent_form, data_collection
 * and other, and MariaDB is not in strict mode here, so a value outside the list
 * is stored as an empty string while the insert still reports success. The
 * upload page writes 'copyright_doc' for the copyright document and the name the
 * student typed for the optional one ("Letter of endorsement"), so both were
 * stored as an empty string, and supporting_doc_label() read an empty string as
 * the copyright document. A named optional document therefore showed up as the
 * copyright document.
 *
 * This does three things:
 *   1. widens document_type to a VARCHAR, so a value is kept as it is written
 *      and a wrong one has to be caught in PHP instead of vanishing;
 *   2. adds document_name, the name a student gave an optional document;
 *   3. repairs the rows already written, reading what each one was from the name
 *      of the file stored with it: the uploader stages every file as
 *      "<paper id>_<kind>_<file name>.pdf".
 *
 * A row with an empty type and no such file name was written by the older
 * upload page, which only ever had the copyright document to leave blank, so
 * that reading stands. Safe to run twice.
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

/* 1. The column's type. */
$col = $conn->query("SHOW COLUMNS FROM supporting_documents LIKE 'document_type'");
$def = $col ? $col->fetch_assoc() : null;
if (!$def) {
    exit("  FAILED   supporting_documents.document_type not found\n");
}
if (stripos((string)$def['Type'], 'enum') === 0) {
    if ($conn->query("ALTER TABLE supporting_documents
                      MODIFY document_type VARCHAR(50) NOT NULL DEFAULT 'other'")) {
        echo "  widened  document_type is now " . 'VARCHAR(50)' . " (was " . $def['Type'] . ")\n";
    } else {
        exit("  FAILED   document_type: " . $conn->error . "\n");
    }
} else {
    echo "  skipped  document_type is already " . $def['Type'] . "\n";
}

/* 2. The name of an optional document. */
$has = $conn->query("SHOW COLUMNS FROM supporting_documents LIKE 'document_name'");
if ($has && $has->num_rows > 0) {
    echo "  skipped  document_name already exists\n";
} elseif ($conn->query("ALTER TABLE supporting_documents
                        ADD COLUMN document_name VARCHAR(255) NULL AFTER document_type")) {
    echo "  added    document_name\n";
} else {
    exit("  FAILED   document_name: " . $conn->error . "\n");
}

/* 3. The rows already written. Only an empty type, or 'other' with no name, can
   be one of the two the old column could not hold. */
echo "\nReading the rows with no usable type...\n";
$rows = $conn->query(
    "SELECT doc_id, paper_id, document_type, file_path
       FROM supporting_documents
      WHERE document_type = ''
         OR (document_type = 'other' AND (document_name IS NULL OR document_name = ''))"
)->fetch_all(MYSQLI_ASSOC);

if (!$rows) {
    exit("  skipped  every row already has its type\n");
}

$set = $conn->prepare("UPDATE supporting_documents SET document_type = ? WHERE doc_id = ?");
$changed = $left = 0;

foreach ($rows as $r) {
    // The path is a relative path or, on old rows, a whole URL: its last part is the file.
    $path = (string)parse_url((string)$r['file_path'], PHP_URL_PATH);
    $base = basename($path !== '' ? $path : (string)$r['file_path']);

    $type = $r['document_type'];
    if (preg_match('/^\d+_copyright_doc_/', $base)) {
        $type = 'copyright_doc';
    } elseif ($r['document_type'] === '' && preg_match('/^\d+_/', $base)) {
        // Staged by the current uploader and not the copyright document: an optional one.
        $type = 'other';
    } elseif ($r['document_type'] === '') {
        // Written by the older upload page, which left only the copyright document blank.
        $type = 'copyright_doc';
    }

    if ($type === $r['document_type']) {
        printf("  left     #%-5d paper %-5d %s\n", $r['doc_id'], $r['paper_id'], $base);
        $left++;
        continue;
    }

    $set->bind_param('si', $type, $r['doc_id']);
    $set->execute();
    printf("  %-13s #%-5d paper %-5d %s\n", $type, $r['doc_id'], $r['paper_id'], $base);
    $changed++;
}

echo "\n$changed repaired, $left left alone.\n";
echo "An optional document repaired here has no name to show, so it reads as \"Other Document\".\n";
