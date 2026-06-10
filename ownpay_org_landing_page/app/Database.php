<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page Database Helper
 * File: app/Database.php
 */

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = sprintf(
                    "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );

                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);
            } catch (PDOException $e) {
                // Log error silently, do not leak db credentials in production
                error_log("Database Connection Error: " . $e->getMessage());
                if (filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw $e;
                }
                http_response_code(500);
                echo "<h1>Internal Server Error</h1><p>A database connection error occurred.</p>";
                exit;
            }
        }

        return self::$instance;
    }
}
