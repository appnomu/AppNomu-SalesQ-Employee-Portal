<?php
/**
 * Web Cron with Processing Lock to Prevent Duplicates
 */

// Security check
$allowedUserAgents = ['curl', 'wget', 'cron-job.org', 'EasyCron'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isAllowed = false;

foreach ($allowedUserAgents as $allowed) {
    if (stripos($userAgent, $allowed) !== false) {
        $isAllowed = true;
        break;
    }
}

if (isset($_GET['secret']) && $_GET['secret'] === 'reminder-cron-2025') {
    $isAllowed = true;
}

if (!$isAllowed) {
    http_response_code(403);
    die('Access denied');
}

// Processing lock to prevent duplicates
$lockFile = __DIR__ . '/processing.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 30) { // 30 second lock
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'locked',
            'message' => 'Processing already in progress'
        ]);
        exit;
    }
}

// Create lock file
file_put_contents($lockFile, time());

try {
    chdir(__DIR__ . '/..');
    date_default_timezone_set('Africa/Kampala');
    
    require_once __DIR__ . '/../config/database.php';
    $db->exec("SET time_zone = '+03:00'");
    
    // Start database transaction to ensure atomic processing
    $db->beginTransaction();
    
    require_once __DIR__ . '/../includes/reminder-processor.php';
    
    $processor = new ReminderProcessor($db);
    $processed = $processor->processPendingReminders();
    
    // Commit transaction
    $db->commit();
    
    // Log execution
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('web-cron-lock', NOW(), 'success', ?)
    ");
    $stmt->execute(["Locked cron processed {$processed} reminders"]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'processed' => $processed,
        'message' => "Processed {$processed} reminders"
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} finally {
    // Remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
?>
