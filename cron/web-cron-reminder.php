<?php
/**
 * Web-based Reminder Cron
 * Alternative to server cron - can be triggered via URL
 * URL: https://emp.appnomu.com/cron/web-cron-reminder.php?secret=reminder-cron-2025
 */

// Security check
$secret = $_GET['secret'] ?? '';
if ($secret !== 'reminder-cron-2025') {
    http_response_code(403);
    die('Access denied');
}

// Set content type for plain text output
header('Content-Type: text/plain');

// Set timezone
date_default_timezone_set('Africa/Kampala');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reminder-processor.php';

echo "=== EP Portal Web Cron - Reminder Processing ===\n";
echo "Execution Time: " . date('Y-m-d H:i:s') . "\n";
echo "Timezone: " . date_default_timezone_get() . "\n\n";

try {
    // Check if reminder processor exists
    if (!class_exists('ReminderProcessor')) {
        throw new Exception('ReminderProcessor class not found');
    }
    
    // Initialize processor
    $processor = new ReminderProcessor($db);
    echo "✅ Reminder processor initialized\n";
    
    // Check pending reminders
    $stmt = $db->prepare("
        SELECT COUNT(*) as pending 
        FROM reminders 
        WHERE status = 'pending' AND reminder_datetime <= NOW()
    ");
    $stmt->execute();
    $pendingCount = $stmt->fetchColumn();
    echo "📋 Pending reminders to process: $pendingCount\n\n";
    
    // Process reminders
    $processed = $processor->processPendingReminders();
    echo "🚀 Processing complete: $processed reminders processed\n";
    
    // Log execution to database
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('web-cron-reminder', NOW(), 'success', ?)
        ");
        $stmt->execute(["Web cron processed $processed reminders"]);
        echo "📝 Execution logged to database\n";
    } catch (Exception $logError) {
        echo "⚠️  Warning: Could not log execution - " . $logError->getMessage() . "\n";
    }
    
    // Show recent activity
    echo "\n=== Recent Reminder Activity ===\n";
    $stmt = $db->prepare("
        SELECT r.title, r.reminder_datetime, r.status, r.processed_at, u.email
        FROM reminders r
        JOIN users u ON r.user_id = u.id
        WHERE r.updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY r.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($recentActivity)) {
        echo "No recent reminder activity\n";
    } else {
        foreach ($recentActivity as $activity) {
            echo "- {$activity['title']} ({$activity['email']}) - {$activity['status']}\n";
        }
    }
    
    echo "\n✅ Web cron execution completed successfully\n";
    echo "Next execution: Trigger this URL again or set up server cron\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Log error to database
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('web-cron-reminder', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    http_response_code(500);
}

echo "\n=== End of Execution ===\n";
?>
