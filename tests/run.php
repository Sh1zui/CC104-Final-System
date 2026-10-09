<?php
/**
 * AutoWay automated tests (Phase 14).
 *
 *   php tests/run.php                 run every suite
 *   php tests/run.php payments        run the suites whose name contains "payments"
 *
 * On XAMPP for Windows, from the project folder:
 *   C:\xampp\php\php.exe tests\run.php
 *
 * Each suite gets a fresh copy of database/car_rental.sql in a SEPARATE
 * database (car_rental_db_test, or $AUTOWAY_DB), so your real data is
 * never touched. MySQL must be running. Exit code 0 = everything passed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$test_db = getenv('AUTOWAY_DB') ?: 'car_rental_db_test';
if ($test_db === 'car_rental_db') {
    fwrite(STDERR, "Refusing to run against the real database (car_rental_db). Use a test database name.\n");
    exit(2);
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $test_db)) {
    fwrite(STDERR, "Test database name may only contain letters, digits, and _.\n");
    exit(2);
}

require_once $root . '/config/database.php'; // the Database class (connection details)

/** Rebuild the test database from the seed file. */
function reset_test_database(string $root, string $name): void
{
    $sql = file_get_contents($root . '/database/car_rental.sql');
    // The seed creates and uses car_rental_db; point it at the test copy instead.
    $sql = preg_replace('/\bcar_rental_db\b/', $name, $sql);
    $pdo = Database::serverConnection();
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    do {
        // Step through every statement's result so errors surface here.
    } while ($stmt->nextRowset());
    $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($name))->fetchColumn();
    if ($count < 16) {
        throw new RuntimeException("The test database has $count tables after import; expected 16.");
    }
}

$filter = $argv[1] ?? '';
$suites = glob(__DIR__ . '/*_test.php');
sort($suites);
if ($filter !== '') {
    $suites = array_values(array_filter($suites, fn ($f) => str_contains(basename($f), $filter)));
}
if (!$suites) {
    fwrite(STDERR, "No test suites match \"$filter\".\n");
    exit(2);
}

putenv('AUTOWAY_DB=' . $test_db);
$total_pass = $total_fail = 0;
$failed_suites = [];
$started = microtime(true);

echo "AutoWay tests, using database `$test_db`\n\n";

foreach ($suites as $file) {
    $name = basename($file, '.php');
    try {
        reset_test_database($root, $test_db);
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not prepare the test database: " . $e->getMessage() . "\nIs MySQL running?\n");
        exit(2);
    }

    // An argument list (not a shell string), so unusual characters in the
    // project path can't break the command on Windows or Linux.
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $file],
                      [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    $output = preg_split('/\r?\n/', (string) $raw);

    $pass = count(preg_grep('/^PASS /', $output));
    $fails = preg_grep('/^FAIL /', $output);
    $problems = preg_grep('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $output);
    $ok = !$fails && !$problems && $pass > 0;

    printf("%-26s %s  %d passed%s\n", $name, $ok ? 'ok  ' : 'FAIL', $pass, $fails ? ', ' . count($fails) . ' failed' : '');
    foreach (array_merge($fails, $problems) as $line) {
        echo "    $line\n";
    }
    $total_pass += $pass;
    $total_fail += count($fails) + count($problems);
    if (!$ok) {
        $failed_suites[] = $name;
    }
}

printf("\n%d checks passed, %d failed, in %.1fs.\n", $total_pass, $total_fail, microtime(true) - $started);
if ($failed_suites) {
    echo 'Failing: ' . implode(', ', $failed_suites) . "\n";
    exit(1);
}
echo "All suites passed.\n";
exit(0);
