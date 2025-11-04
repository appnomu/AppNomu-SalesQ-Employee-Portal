<?php
/**
 * Web-based Cron Alternative
 * Call this URL every minute from an external cron service
 */

// Security: Only allow specific user agents or IP ranges
$allowedUserAgents = ['curl', 'wget', 'cron-job.org', 'EasyCron'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isAllowed = false;

foreach ($allowedUserAgents as $allowed) {
    if (stripos($userAgent, $allowed) !== false) {
        $isAllowed = true;
        break;
    }
}

// Also allow if called with secret parameter
if (isset($_GET['secret']) && $_GET['secret'] === 'reminder-cron-2025') {
    $isAllowed = true;
}

if (!$isAllowed) {
    http_response_code(403);
    die('Access denied');
}

// Set working directory and timezone
chdir(__DIR__ . '/..');
date_default_timezone_set('Africa/Kampala');

// Set database timezone to match PHP
try {
    require_once __DIR__ . '/../config/database.php';
    $db->exec("SET time_zone = '+03:00'");
} catch (Exception $e) {
    // Continue if timezone setting fails
}

$timestamp = date('Y-m-d H:i:s');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/reminder-processor.php';
    
    // Process reminders
    $processor = new ReminderProcessor($db);
    $processed = $processor->processPendingReminders();
    
    // Process monthly salary allocation (only on 30th of month)
    $salaryMessage = '';
    if ((int)date('d') === 30) {
        ob_start();
        include __DIR__ . '/monthly-salary-allocation.php';
        $salaryOutput = ob_get_clean();
        $salaryMessage = ' | Salary allocation executed';
    }
    
    // Log execution
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('web-cron', NOW(), 'success', ?)
    ");
    $stmt->execute(["Web cron processed {$processed} reminders{$salaryMessage}"]);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'timestamp' => $timestamp,
        'processed' => $processed,
        'message' => "Processed {$processed} reminders{$salaryMessage}"
    ]);
    
} catch (Exception $e) {
    // Log error
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('web-cron', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'timestamp' => $timestamp,
        'message' => $e->getMessage()
    ]);
}
?>
