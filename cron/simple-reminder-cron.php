<?php
/**
 * Simplified Reminder Cron Job
 * Based on the working web test
 */

// Set the working directory to the project root
chdir(__DIR__ . '/..');

// Set timezone
date_default_timezone_set('Africa/Kampala');

$timestamp = date('Y-m-d H:i:s');

try {
    // Include required files
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/reminder-processor.php';
    
    $processor = new ReminderProcessor($db);
    $processed = $processor->processPendingReminders();
    
    // Log to database
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('reminder-cron', NOW(), 'success', ?)
    ");
    $stmt->execute(["Processed {$processed} reminders"]);
    
    echo "[{$timestamp}] Processed {$processed} reminders\n";
    
    if ($processed > 0) {
        echo "[{$timestamp}] Successfully sent {$processed} reminder notifications\n";
    }
    
} catch (Exception $e) {
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    // Log error to database
    try {
        require_once __DIR__ . '/../config/database.php';
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('reminder-cron', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    exit(1);
}
?>
