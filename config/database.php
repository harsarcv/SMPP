<?php
class Database {
    private static $instance = null;
    private $connection;
    
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $port;
    private $timezone;
    
    private function __construct() {
        $this->loadEnv();
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->dbname = getenv('DB_NAME') ?: 'test';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
        $this->timezone = getenv('APP_TIMEZONE') ?: 'Asia/Jakarta';
        date_default_timezone_set($this->timezone);
        
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8mb4";
            $this->connection = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            
            // Set time_zone untuk konsistensi (gunakan offset WIB)
            $this->connection->exec("SET time_zone = '+07:00'");
            
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check your environment configuration.");
        }
    }
    
    private function loadEnv() {
        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (!file_exists($envPath)) {
            return;
        }
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                }
                if (!getenv($key)) {
                    putenv("{$key}={$value}");
                }
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
}
