#!/usr/bin/env php
<?php
/**
 * Notification Processing Cron Job
 * Processes pending system notifications and sends them via appropriate channels
 * Cleans up old notifications and manages notification delivery
 */

// Prevent web access
if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
    die('This script cannot be run from a web browser.');
}

// Set working directory and timezone
chdir(__DIR__ . '/..');
date_default_timezone_set('Africa/Kampala');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/infobip.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    $timestamp = date('Y-m-d H:i:s');
    $processed = 0;
    $infobip = new InfobipAPI();
    
    // 1. Process failed notification logs and retry
    $stmt = $db->prepare("
        SELECT nl.*, u.email, u.phone, ep.first_name, ep.last_name
        FROM notification_logs nl
        JOIN users u ON nl.user_id = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE nl.status = 'failed'
        AND nl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY nl.created_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    $failedNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($failedNotifications as $notification) {
        try {
            $success = false;
            
            switch ($notification['type']) {
                case 'sms':
                    if (!empty($notification['phone'])) {
                        $result = $infobip->sendSMS($notification['phone'], $notification['message']);
                        $success = $result !== false;
                    }
                    break;
                    
                case 'email':
                    if (!empty($notification['email'])) {
                        $result = $infobip->sendEmail($notification['email'], $notification['subject'], $notification['message']);
                        $success = $result !== false;
                    }
                    break;
                    
                case 'whatsapp':
                    if (!empty($notification['phone'])) {
                        $result = $infobip->sendWhatsApp($notification['phone'], $notification['message']);
                        $success = $result !== false;
                    }
                    break;
            }
            
            if ($success) {
                // Update notification status to sent
                $updateStmt = $db->prepare("
                    UPDATE notification_logs 
                    SET status = 'sent', sent_at = NOW(), error_message = NULL
                    WHERE id = ?
                ");
                $updateStmt->execute([$notification['id']]);
                
                $processed++;
                echo "[{$timestamp}] Retried and sent notification ID {$notification['id']}\n";
            }
            
        } catch (Exception $e) {
            // Update error message
            $updateStmt = $db->prepare("
                UPDATE notification_logs 
                SET error_message = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$e->getMessage(), $notification['id']]);
            
            echo "[{$timestamp}] Failed to retry notification ID {$notification['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // 2. Clean up old read system notifications (older than 30 days)
    $stmt = $db->prepare("
        DELETE FROM system_notifications 
        WHERE is_read = 1 
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $cleanedNotifications = $stmt->rowCount();
    
    if ($cleanedNotifications > 0) {
        echo "[{$timestamp}] Cleaned {$cleanedNotifications} old read notifications\n";
        $processed += $cleanedNotifications;
    }
    
    // 3. Clean up old notification logs (older than 90 days)
    $stmt = $db->prepare("
        DELETE FROM notification_logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $stmt->execute();
    $cleanedLogs = $stmt->rowCount();
    
    if ($cleanedLogs > 0) {
        echo "[{$timestamp}] Cleaned {$cleanedLogs} old notification logs\n";
        $processed += $cleanedLogs;
    }
    
    // 4. Send daily summary notifications to admins (once per day at 8 AM)
    $currentHour = (int)date('H');
    if ($currentHour === 8) {
        // Get admin users
        $stmt = $db->prepare("
            SELECT u.*, ep.first_name, ep.last_name
            FROM users u
            LEFT JOIN employee_profiles ep ON u.id = ep.user_id
            WHERE u.role = 'admin' AND u.status = 'active'
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get daily statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_notifications,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM notification_logs 
            WHERE DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stats['total_notifications'] > 0) {
            $summaryMessage = "Daily Notification Summary:\n";
            $summaryMessage .= "Total: {$stats['total_notifications']}\n";
            $summaryMessage .= "Sent: {$stats['sent_count']}\n";
            $summaryMessage .= "Failed: {$stats['failed_count']}\n";
            $summaryMessage .= "Success Rate: " . round(($stats['sent_count'] / $stats['total_notifications']) * 100, 1) . "%";
            
            foreach ($admins as $admin) {
                if (!empty($admin['email'])) {
                    $infobip->sendEmail($admin['email'], "EP Portal - Daily Notification Summary", $summaryMessage);
                }
            }
            
            echo "[{$timestamp}] Sent daily summary to " . count($admins) . " admins\n";
            $processed += count($admins);
        }
    }
    
    // Log execution
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('process-notifications', NOW(), 'success', ?)
    ");
    $stmt->execute(["Processed {$processed} notification operations"]);
    
    echo "[{$timestamp}] Processed {$processed} notification operations\n";
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    // Log error
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('process-notifications', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    exit(1);
}
?>
