<?php
declare(strict_types=1);

namespace Slayly\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private function __construct()
    {
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            new self();
        }
        return self::$instance;
    }

    private function initConnection(): void
    {
        if ($this->connection !== null) {
            return;
        }

        if (!defined('DB_HOST')) {
            require_once dirname(__DIR__) . '/config/config.php';
        }

        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('[Database] ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.');
        }
    }

    public function getConnection(): PDO
    {
        $this->initConnection();
        return $this->connection;
    }

    private function __clone() {}
    public function __wakeup() {}
}
