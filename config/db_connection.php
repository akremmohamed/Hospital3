<?php
/**
 * Centralized Database Connection Manager
 * Supports PDO with connection pooling and fallback mechanisms
 * 
 * @package HMS-ERP
 * @version 2.0
 */

// Database credentials from secure environment or config file
$db_config = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => getenv('DB_NAME') ?: 'hms_erp',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'timezone' => '+03:00'
];

class DatabaseConnection {
    private static $instance = null;
    private $pdo = null;
    private $config = [];
    
    private function __construct($config) {
        $this->config = $config;
        $this->connect();
    }
    
    /**
     * Get singleton instance of database connection
     */
    public static function getInstance($config) {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    /**
     * Establish PDO connection
     */
    private function connect() {
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $this->config['host'],
                $this->config['name'],
                $this->config['charset']
            );
            
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
                    "SET NAMES %s COLLATE %s, time_zone = '%s'",
                    $this->config['charset'],
                    $this->config['collation'],
                    $this->config['timezone']
                )
            ]);
            
            // Explicitly set timezone
            $this->pdo->exec("SET time_zone = '{$this->config['timezone']}'");
            
        } catch (PDOException $e) {
            error_log('[Database] Connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        if ($this->pdo === null) {
            $this->connect();
        }
        
        // Test connection and reconnect if needed
        try {
            $this->pdo->query("SELECT 1");
        } catch (PDOException $e) {
            $this->connect();
        }
        
        return $this->pdo;
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->getConnection()->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->getConnection()->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollBack() {
        return $this->getConnection()->rollBack();
    }
    
    /**
     * Prepare and execute query with parameters
     */
    public function executeQuery($sql, $params = []) {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->getConnection()->lastInsertId();
    }
    
    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}
    
    /**
     * Prevent unserializing of singleton
     */
    public function __wakeup() {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}

// Initialize database connection
try {
    $db = DatabaseConnection::getInstance($db_config);
    $pdo = $db->getConnection();
} catch (RuntimeException $e) {
    // Log error but don't expose to client
    error_log('Database initialization failed: ' . $e->getMessage());
    $pdo = null;
}

// Helper function to get database connection
function getDB() {
    global $db;
    return $db ? $db->getConnection() : null;
}

// Helper function to check if database is available
function is_db_available() {
    global $pdo;
    if (!$pdo) return false;
    try {
        $pdo->query("SELECT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
?>