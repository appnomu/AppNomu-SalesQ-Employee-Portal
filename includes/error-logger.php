<?php
/**
 * Centralized Error Logging System
 * Logs errors to database and file for easy debugging
 */

class ErrorLogger {
    private $db;
    private $logFile;
    
    public function __construct($database = null) {
        global $db;
        $this->db = $database ?? $db;
        $this->logFile = __DIR__ . '/../logs/error.log';
        
        // Ensure logs directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Log error to both file and database
     */
    public function logError($type, $message, $file = null, $line = null, $userId = null, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? json_encode($context) : null;
        
        // Log to file
        $logMessage = sprintf(
            "[%s] [%s] %s in %s:%s | User: %s | Context: %s\n",
            $timestamp,
            strtoupper($type),
            $message,
            $file ?? 'unknown',
            $line ?? 'unknown',
            $userId ?? 'guest',
            $contextJson ?? 'none'
        );
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Log to database
        try {
            $stmt = $this->db->prepare("
                INSERT INTO error_logs (type, message, file, line, user_id, context, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$type, $message, $file, $line, $userId, $contextJson]);
        } catch (Exception $e) {
            // If database logging fails, at least we have file log
            error_log("Failed to log to database: " . $e->getMessage());
        }
    }
    
    /**
     * Log upload error
     */
    public function logUploadError($message, $userId, $fileName, $fileError) {
        $context = [
            'file_name' => $fileName,
            'file_error' => $fileError,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit')
        ];
        
        $this->logError('upload_error', $message, __FILE__, __LINE__, $userId, $context);
    }
    
    /**
     * Log cron error
     */
    public function logCronError($cronName, $message, $context = []) {
        $context['cron_name'] = $cronName;
        $this->logError('cron_error', $message, __FILE__, __LINE__, null, $context);
    }
    
    /**
     * Log database error
     */
    public function logDatabaseError($message, $query = null, $userId = null) {
        $context = ['query' => $query];
        $this->logError('database_error', $message, __FILE__, __LINE__, $userId, $context);
    }
    
    /**
     * Get recent errors
     */
    public function getRecentErrors($limit = 100, $type = null) {
        $sql = "SELECT * FROM error_logs";
        if ($type) {
            $sql .= " WHERE type = ?";
        }
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        if ($type) {
            $stmt->execute([$type, $limit]);
        } else {
            $stmt->execute([$limit]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Clear old logs (older than 30 days)
     */
    public function clearOldLogs() {
        $stmt = $this->db->prepare("DELETE FROM error_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        return $stmt->execute();
    }
}

// Global error handler
function handleError($errno, $errstr, $errfile, $errline) {
    global $db;
    
    $logger = new ErrorLogger($db);
    $userId = $_SESSION['user_id'] ?? null;
    
    $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_NOTICE => 'NOTICE',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE'
    ];
    
    $type = $errorTypes[$errno] ?? 'UNKNOWN';
    $logger->logError($type, $errstr, $errfile, $errline, $userId);
    
    // Don't execute PHP internal error handler
    return true;
}

// Global exception handler
function handleException($exception) {
    global $db;
    
    $logger = new ErrorLogger($db);
    $userId = $_SESSION['user_id'] ?? null;
    
    $logger->logError(
        'EXCEPTION',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $userId,
        ['trace' => $exception->getTraceAsString()]
    );
}

// Set error and exception handlers
set_error_handler('handleError');
set_exception_handler('handleException');
?>
