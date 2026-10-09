<?php
/**
 * Database connection.
 * Returns a single shared PDO instance so we're not opening a new
 * connection on every include across a request.
 */

require_once __DIR__ . '/config.php';

class Database
{
    private const HOST = '127.0.0.1';
    private const PORT = 3306;         // XAMPP's default; change if MySQL runs on another port (e.g. 3307)
    private const NAME = 'car_rental_db';
    private const USER = 'root';
    private const PASS = ''; // default XAMPP root has no password

    private static ?PDO $connection = null;

    /** A connection to the server without picking a database (tests/run.php uses it to build the test copy). */
    public static function serverConnection(): PDO
    {
        return new PDO('mysql:host=' . self::HOST . ';port=' . self::PORT . ';charset=utf8mb4', self::USER, self::PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            // AUTOWAY_DB lets the test suite (tests/run.php) use its own
            // throwaway copy instead of the real database.
            $name = getenv('AUTOWAY_DB') ?: self::NAME;
            $dsn = 'mysql:host=' . self::HOST . ';port=' . self::PORT . ';dbname=' . $name . ';charset=utf8mb4';

            try {
                self::$connection = new PDO($dsn, self::USER, self::PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
                ]);

                // Keep the DB session on the same clock as PHP (config.php sets
                // Asia/Manila). Without this, NOW()/CURDATE() and TIMESTAMP columns
                // follow whatever timezone the MySQL server happens to use, and
                // "revenue today" or "overdue" can be off by hours.
                self::$connection->exec("SET time_zone = '" . date('P') . "'");
            } catch (PDOException $e) {
                // Never leak DSN/credentials in the error shown to a browser.
                error_log('DB connection failed: ' . $e->getMessage());
                die('Database connection failed. Check config/database.php and confirm MySQL is running in XAMPP.');
            }
        }

        return self::$connection;
    }
}
