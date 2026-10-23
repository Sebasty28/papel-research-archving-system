<?php
/**
 * auto_prepend_file for tests/m13_old_schema.py. Not part of the application.
 *
 *   php -d auto_prepend_file=tests/_old_schema_prepend.php -S localhost:8093 ...
 *
 * Two things, both so a scratch copy of the site behaves like staging:
 *
 *   - PAPEL_TEST_DB names the database to use instead of the one in .env.
 *     config.php defines DB_NAME from .env, and the first define() wins (the
 *     second only raises a warning), so defining it here, before config.php runs,
 *     points the whole application at the scratch copy.
 *   - mysqli throws on a failed query, as it does from PHP 8.1. The 8.0 on this
 *     machine returns false and carries on, which is how a fault can be invisible
 *     here and end a request on staging (8.4).
 *
 * Without PAPEL_TEST_DB it does nothing about the database, so a stray include can
 * never move a run onto a database nobody named.
 */
$scratch = getenv('PAPEL_TEST_DB');
if ($scratch !== false && $scratch !== '') {
    define('DB_NAME', $scratch);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}
