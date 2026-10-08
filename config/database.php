<?php
/**
 * MoneyLend - Database Connection Configuration
 *
 * Uses PDO (PHP Data Objects) for modern, clean, and prepared-statement ready
 * database communication with MySQL / MariaDB in XAMPP.
 */

// Load .env configuration if present (without external dependencies)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            list($name, $val) = explode('=', $line, 2);
            $name = trim($name);
            $val = trim($val);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $val;
            }
            if (getenv($name) === false) {
                putenv("{$name}={$val}");
            }
        }
    }
}

// Database Configuration Constants
// Allows environment variables or defaults to local XAMPP configuration
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost'));
    define('DB_PORT', getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306'));
    define('DB_NAME', getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'moneylend'));
    define('DB_USER', getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root'));
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ''));
    define('DB_CHARSET', getenv('DB_CHARSET') ?: ($_ENV['DB_CHARSET'] ?? 'utf8mb4'));
}

/**
 * Get or create the singleton PDO connection instance.
 *
 * @return PDO
 * @throws PDOException
 */
function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return associative arrays by default
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Native prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // macOS XAMPP compatibility check:
            // When connecting via localhost outside XAMPP Apache (e.g. system PHP CLI),
            // the Unix socket might be located at /Applications/XAMPP/xamppfiles/var/mysql/mysql.sock
            if (DB_HOST === 'localhost' && file_exists('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock')) {
                try {
                    $dsnSocket = "mysql:unix_socket=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock;dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                    $pdo = new PDO($dsnSocket, DB_USER, DB_PASS, $options);
                    return $pdo;
                } catch (PDOException $ex) {
                    error_log("MoneyLend Database Connection Error (Socket fallback): " . $ex->getMessage());
                }
            }

            // Secure logging: log the technical error to PHP error log without exposing credentials
            error_log("MoneyLend Database Connection Error: " . $e->getMessage());

            // Provide a user-friendly error message without sensitive credentials
            die("<strong>Database Connection Error:</strong> Unable to connect to the database. Please ensure your XAMPP MySQL service is running and the database '<strong>" . htmlspecialchars(DB_NAME) . "</strong>' exists.");
        }
    }

    return $pdo;
}
