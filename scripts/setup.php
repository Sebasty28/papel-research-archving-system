<?php
/**
 * Set up PAPEL on a fresh clone, and say plainly what is missing.
 *
 * A clone of the repository is not a working copy. Four things are deliberately
 * not in git, and three of them will stop the app dead:
 *
 *   vendor/      the Composer packages. This is the one that catches people
 *                out: pages that talk to Google Drive load vendor/autoload.php,
 *                so the public repository page dies while Help Center and
 *                Contact Support carry on working. It looks like a broken page
 *                rather than a missing dependency, which is why this script
 *                checks for it first.
 *   .env         the database password and the API keys.
 *   the database itself, which is why database/schema.sql exists.
 *   uploads/     the folders are ignored, so they arrive empty or absent.
 *
 * Run it from the project root:
 *
 *     php scripts/setup.php              check everything, change nothing
 *     php scripts/setup.php --install    also create the database and run the
 *                                        migrations
 *
 * It is safe to run twice. Nothing is dropped, nothing is overwritten, and the
 * migrations it calls already check before they change anything.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line: php scripts/setup.php\n");
}

$root = dirname(__DIR__);
$install = in_array('--install', $argv, true);
$problems = 0;
$warnings = 0;

function head(string $t): void { echo "\n", $t, "\n", str_repeat('-', strlen($t)), "\n"; }
function ok(string $t): void   { echo "  [ ok ]   ", $t, "\n"; }
function bad(string $t, string $fix = ''): void {
    global $problems; $problems++;
    echo "  [ STOP ] ", $t, "\n";
    if ($fix) echo "           fix: ", $fix, "\n";
}
function warn(string $t, string $fix = ''): void {
    global $warnings; $warnings++;
    echo "  [ note ] ", $t, "\n";
    if ($fix) echo "           ", $fix, "\n";
}

echo "PAPEL setup\n";
echo "===========\n";
echo "project: $root\n";

// ---- 1. PHP itself ---------------------------------------------------------
head('PHP');
if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    ok('PHP ' . PHP_VERSION);
} else {
    bad('PHP ' . PHP_VERSION . ' is too old; 8.0 or newer is needed',
        'in XAMPP, use a build with PHP 8');
}
foreach (['mysqli' => true, 'zip' => true, 'openssl' => true, 'mbstring' => true,
          'curl' => true, 'dom' => true, 'gd' => false] as $ext => $required) {
    if (extension_loaded($ext)) {
        ok("extension $ext");
    } elseif ($required) {
        bad("extension $ext is missing",
            'enable it in php.ini, then restart Apache');
    } else {
        warn("extension $ext is off",
             'only affects image resizing; the app works without it');
    }
}

// ---- 2. the Composer packages ---------------------------------------------
head('Composer packages');
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    ok('vendor/autoload.php is present');
    require_once $autoload;
    foreach ([
        'Google\\Client'                  => 'google/apiclient, for Google Drive',
        'PhpOffice\\PhpSpreadsheet\\Spreadsheet' => null,   // not used; ignore
    ] as $class => $why) {
        if ($why === null) continue;
        if (class_exists($class)) ok("  $why");
        else warn("  $why is not installed", 'run: composer install');
    }
} else {
    bad('vendor/ is missing, so pages that use Google Drive will not load at all',
        'run: composer install    (this is almost certainly the problem)');
    echo "\n";
    echo "           This is what makes the public repository page fail while\n";
    echo "           Help Center and Contact Support keep working: only some\n";
    echo "           pages load the Drive client.\n";
}

// ---- 3. configuration ------------------------------------------------------
head('Configuration');
if (is_file($root . '/.env')) {
    ok('.env is present');
} else {
    bad('.env is missing',
        'copy .env.example to .env and fill in the database and API settings');
    if (is_file($root . '/.env.example')) {
        echo "           cp .env.example .env\n";
    }
}
require_once $root . '/config/config.php';
if (defined('CSRF_KEY') && CSRF_KEY === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET') {
    warn('CSRF_KEY is still the placeholder',
         'set a long random value in .env; changing it signs everyone out once');
}
echo "           BASE_URL is " . BASE_URL . "\n";
echo "           If the app is not at that address, links and redirects will\n";
echo "           404. On Linux the case matters as well as the spelling.\n";

// ---- 4. writable folders ---------------------------------------------------
head('Upload folders');
$dirs = ['uploads', 'uploads/research', 'uploads/supporting_docs', 'uploads/drafts',
         'uploads/temp', 'uploads/section_images', 'config/notifications'];
foreach ($dirs as $d) {
    $path = $root . '/' . $d;
    if (!is_dir($path)) {
        if ($install && @mkdir($path, 0775, true)) {
            ok("created $d");
        } else {
            warn("$d does not exist", $install ? 'could not create it' : 'run with --install to create it');
        }
    } elseif (!is_writable($path)) {
        bad("$d is not writable", 'give the web server write permission');
    } else {
        ok("$d");
    }
}

// ---- 5. the database -------------------------------------------------------
head('Database');
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
if ($conn->connect_errno) {
    bad('cannot reach MySQL at ' . DB_HOST . ':' . DB_PORT . ' — ' . $conn->connect_error,
        'start MySQL in the XAMPP control panel, and check DB_USER / DB_PASS in .env');
} else {
    ok('connected to MySQL ' . $conn->server_info);

    $dbName = DB_NAME;
    $exists = $conn->query("SHOW DATABASES LIKE '" . $conn->real_escape_string($dbName) . "'");
    if ($exists && $exists->num_rows) {
        ok("database `$dbName` exists");
    } elseif ($install) {
        if ($conn->query("CREATE DATABASE `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
            ok("created database `$dbName`");
        } else {
            bad("could not create `$dbName`: " . $conn->error);
        }
    } else {
        bad("database `$dbName` does not exist",
            'run with --install, or: mysql -u root -p < database/schema.sql');
    }

    if ($conn->select_db($dbName)) {
        $have = [];
        $res = $conn->query('SHOW TABLES');
        while ($res && $row = $res->fetch_array()) $have[] = $row[0];

        if (!$have) {
            if ($install) {
                $schema = $root . '/database/schema.sql';
                if (!is_file($schema)) {
                    bad('database/schema.sql is missing, so the tables cannot be built');
                } else {
                    echo "  ....     importing database/schema.sql\n";
                    $sql = file_get_contents($schema);
                    // The file is our own and has no procedures or triggers, so
                    // splitting on semicolons at line ends is enough.
                    $count = 0;
                    foreach (preg_split('/;\s*\r?\n/', $sql) as $stmt) {
                        $stmt = trim($stmt);
                        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                        if ($conn->query($stmt)) $count++;
                        else bad('  ' . $conn->error);
                    }
                    ok("imported the schema ($count statements)");
                }
            } else {
                bad('the database is empty',
                    'run with --install, or import database/schema.sql yourself');
            }
        } else {
            ok(count($have) . ' tables present');
        }

        // ---- migrations, which are all idempotent ----
        $migrations = glob($root . '/scripts/migrations/run_*.php') ?: [];
        sort($migrations);
        if ($install && $migrations) {
            head('Migrations');
            foreach ($migrations as $m) {
                $name = basename($m);
                echo "  ....     $name\n";
                $out = [];
                $code = 0;
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($m) . ' 2>&1', $out, $code);
                foreach ($out as $line) echo "             $line\n";
                if ($code !== 0) warn("$name exited with $code");
            }
        } elseif ($migrations) {
            warn(count($migrations) . ' migration scripts have not been run',
                 'run with --install to apply them');
        }

        // ---- somebody to sign in as ----
        $u = $conn->query("SELECT COUNT(*) c FROM users");
        $count = $u ? (int)$u->fetch_assoc()['c'] : 0;
        head('Accounts');
        if ($count > 0) {
            ok("$count account(s) already exist");
        } elseif ($install) {
            /* One Director, so there is a way in. The password is printed once
               here and nowhere else; it is stored hashed like any other. */
            $pw = 'Papel' . random_int(1000, 9999) . '!';
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (username, email, password, full_name, user_role,
                                    faculty_id, is_active)
                 VALUES ('director', 'director@example.test', ?, 'Director',
                         'super_admin', 'DIR-0001', 1)");
            $stmt->bind_param('s', $hash);
            if ($stmt->execute()) {
                ok('created the first Director account');
                echo "\n";
                echo "           Sign in on the Faculty / Admin tab with:\n";
                echo "             ID:       DIR-0001\n";
                echo "             password: $pw\n";
                echo "           Change it from Settings once you are in. This is\n";
                echo "           the only time it is shown.\n\n";
            } else {
                warn('could not create the Director account: ' . $conn->error);
            }
        } else {
            warn('there are no accounts, so there is no way to sign in',
                 'run with --install to create the first Director');
        }
    }
    $conn->close();
}

// ---- what to do next -------------------------------------------------------
head('Summary');
if ($problems === 0) {
    echo "  Nothing is blocking you";
    echo $warnings ? " ($warnings note" . ($warnings === 1 ? '' : 's') . " above).\n" : ".\n";
    echo "  Open " . BASE_URL . " and sign in.\n";
} else {
    echo "  $problems thing" . ($problems === 1 ? '' : 's') . " must be fixed before the app will run";
    echo $warnings ? ", and $warnings worth a look.\n" : ".\n";
    echo "  Work through the [ STOP ] lines above, then run this again.\n";
}
if (!$install && $problems > 0) {
    echo "\n  Most of it can be done for you:  php scripts/setup.php --install\n";
}
echo "\n";
exit($problems > 0 ? 1 : 0);
