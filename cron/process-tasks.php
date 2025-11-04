#!/usr/bin/env php
<?php
/**
 * Task Processing Cron Job
 * Sends notifications for overdue tasks and upcoming deadlines
 * Updates task statuses and sends reminders
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
    
    // 1. Send notifications for overdue tasks
    $stmt = $db->prepare("
        SELECT t.*, u.email, u.phone, ep.first_name, ep.last_name,
               assignedBy.employee_number as assigned_by_number
        FROM tasks t
        JOIN users u ON t.assigned_to = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        LEFT JOIN users assignedBy ON t.assigned_by = assignedBy.id
        WHERE t.status IN ('pending', 'in_progress')
        AND t.due_date < NOW()
        AND t.due_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY t.due_date ASC
    ");
    $stmt->execute();
    $overdueTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($overdueTasks as $task) {
        $employeeName = trim($task['first_name'] . ' ' . $task['last_name']);
        $dueDate = date('M j, Y g:i A', strtotime($task['due_date']));
        
        $message = "OVERDUE TASK: '{$task['title']}' was due on {$dueDate}. Please complete it as soon as possible.";
        
        // Send SMS notification
        if (!empty($task['phone'])) {
            $infobip->sendSMS($task['phone'], $message);
        }
        
        // Send email notification
        if (!empty($task['email'])) {
            $emailSubject = "Overdue Task Reminder - EP Portal";
            $infobip->sendEmail($task['email'], $emailSubject, $message);
        }
        
        // Create system notification
        $stmt = $db->prepare("
            INSERT INTO system_notifications (user_id, title, message, type, created_at)
            VALUES (?, 'Overdue Task', ?, 'task', NOW())
        ");
        $stmt->execute([$task['assigned_to'], $message]);
        
        $processed++;
        echo "[{$timestamp}] Sent overdue notification for task ID {$task['id']}\n";
    }
    
    // 2. Send notifications for tasks due in next 24 hours
    $stmt = $db->prepare("
        SELECT t.*, u.email, u.phone, ep.first_name, ep.last_name
        FROM tasks t
        JOIN users u ON t.assigned_to = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE t.status IN ('pending', 'in_progress')
        AND t.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
        ORDER BY t.due_date ASC
    ");
    $stmt->execute();
    $upcomingTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($upcomingTasks as $task) {
        $employeeName = trim($task['first_name'] . ' ' . $task['last_name']);
        $dueDate = date('M j, Y g:i A', strtotime($task['due_date']));
        
        $message = "TASK REMINDER: '{$task['title']}' is due on {$dueDate}. Please ensure timely completion.";
        
        // Send SMS notification
        if (!empty($task['phone'])) {
            $infobip->sendSMS($task['phone'], $message);
        }
        
        // Create system notification
        $stmt = $db->prepare("
            INSERT INTO system_notifications (user_id, title, message, type, created_at)
            VALUES (?, 'Task Due Soon', ?, 'task', NOW())
        ");
        $stmt->execute([$task['assigned_to'], $message]);
        
        $processed++;
        echo "[{$timestamp}] Sent due soon notification for task ID {$task['id']}\n";
    }
    
    // 3. Auto-update task priorities based on due dates
    $stmt = $db->prepare("
        UPDATE tasks 
        SET priority = 'urgent', updated_at = NOW()
        WHERE status IN ('pending', 'in_progress')
        AND due_date < DATE_ADD(NOW(), INTERVAL 6 HOUR)
        AND priority != 'urgent'
    ");
    $stmt->execute();
    $urgentUpdates = $stmt->rowCount();
    
    if ($urgentUpdates > 0) {
        echo "[{$timestamp}] Updated {$urgentUpdates} tasks to urgent priority\n";
        $processed += $urgentUpdates;
    }
    
    // Log execution
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('process-tasks', NOW(), 'success', ?)
    ");
    $stmt->execute(["Processed {$processed} task notifications and updates"]);
    
    echo "[{$timestamp}] Processed {$processed} task notifications and updates\n";
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    // Log error
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('process-tasks', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    exit(1);
}
?>
