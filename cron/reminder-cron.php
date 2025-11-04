#!/usr/bin/env php
<?php
/**
 * Reminder Cron Job
 * This script should be run every minute via cron to process pending reminders
 * 
 * Cron entry example:
 * * * * * * /usr/bin/php /path/to/EPportal/cron/reminder-cron.php >> /var/log/ep-reminders.log 2>&1
 */

// Allow execution from cron (which may use different SAPI)
if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
    die('This script cannot be run from a web browser.');
}

// Set the working directory to the project root
chdir(__DIR__ . '/..');

// Set timezone
date_default_timezone_set('Africa/Kampala');

// Include the reminder processor
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reminder-processor.php';

try {
    $processor = new ReminderProcessor($db);
    $processed = $processor->processPendingReminders();
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Processed {$processed} reminders\n";
    
    // Log cron execution to database for monitoring
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('reminder-cron', NOW(), 'success', ?)
        ");
        $stmt->execute(["Processed {$processed} reminders"]);
    } catch (Exception $logError) {
        // Continue even if logging fails
        echo "[{$timestamp}] Warning: Could not log execution - " . $logError->getMessage() . "\n";
    }
    
    if ($processed > 0) {
        echo "[{$timestamp}] Successfully sent {$processed} reminder notifications\n";
    }
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    // Log error to database
    try {
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
