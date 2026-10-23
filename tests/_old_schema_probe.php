<?php
/**
 * Facts about the scratch database, for tests/m13_old_schema.py.
 *
 *     php -d auto_prepend_file=tests/_old_schema_prepend.php tests/_old_schema_probe.php gaps
 *     php -d auto_prepend_file=tests/_old_schema_prepend.php tests/_old_schema_probe.php old_query
 *
 * A script file and not `php -r`: the CLI does not run auto_prepend_file for -r
 * code, so the database would not have been switched and mysqli would not have
 * been made to throw, and both probes would have read the live database quietly.
 * Each prints one line.
 */
$scratch = getenv('PAPEL_TEST_DB');
if ($scratch === false || $scratch === '' || $scratch === 'capstone_db') {
    fwrite(STDERR, "PAPEL_TEST_DB must name the scratch database.\n");
    exit(2);
}

$mode = $argv[1] ?? '';

if ($mode === 'gaps') {
    require_once __DIR__ . '/../config/core.php';
    echo json_encode(array_column(schema_gaps(), 'key')), "\n";
    exit(0);
}

if ($mode === 'old_query') {
    /* The statement the faculty desk used to run. mysqli throws here because the
       prepend asked it to, which is what PHP 8.1 and later do by themselves. */
    $c = new mysqli('localhost', 'root', '', $scratch);
    try {
        $c->query('SELECT document_name FROM supporting_documents LIMIT 1');
        echo "no error\n";
    } catch (mysqli_sql_exception $e) {
        echo 'threw: ' . $e->getMessage() . "\n";
    }
    exit(0);
}

fwrite(STDERR, "Unknown mode.\n");
exit(2);
