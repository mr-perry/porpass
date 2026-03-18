<?php
/**
 * db.php — PDO database connection helper.
 *
 * Returns a shared PDO instance using credentials from the project .env file.
 * Call get_db() from any page that needs a database connection.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

/**
 * Returns a singleton PDO connection to the PORPASS database.
 *
 * @return PDO
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_DATABASE']
        );
        $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}