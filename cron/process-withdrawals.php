#!/usr/bin/env php
<?php
/**
 * Withdrawal Processing Cron Job
 * Processes pending salary withdrawals and updates their status
 * Checks Flutterwave transaction status and sends notifications
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
    require_once __DIR__ . '/../includes/flutterwave.php';
    require_once __DIR__ . '/../includes/infobip.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    $timestamp = date('Y-m-d H:i:s');
    $processed = 0;
    $flutterwave = new FlutterwaveAPI();
    $infobip = new InfobipAPI();
    
    // Get pending and processing withdrawals
    $stmt = $db->prepare("
        SELECT sw.*, u.email, u.phone, ep.first_name, ep.last_name 
        FROM salary_withdrawals sw
        JOIN users u ON sw.employee_id = u.id
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE sw.status IN ('pending', 'processing') 
        AND sw.flutterwave_reference IS NOT NULL
        ORDER BY sw.created_at ASC
        LIMIT 50
    ");
    $stmt->execute();
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($withdrawals as $withdrawal) {
        try {
            // Check transaction status with Flutterwave
            $status = $flutterwave->getTransactionStatus($withdrawal['flutterwave_reference']);
            
            if ($status && isset($status['status'])) {
                $newStatus = '';
                $message = '';
                
                switch (strtolower($status['status'])) {
                    case 'successful':
                    case 'completed':
                        $newStatus = 'completed';
                        $message = 'Withdrawal completed successfully';
                        break;
                    case 'failed':
                        $newStatus = 'failed';
                        $message = $status['message'] ?? 'Transaction failed';
                        break;
                    case 'pending':
                        $newStatus = 'processing';
                        $message = 'Transaction is being processed';
                        break;
                }
                
                if ($newStatus && $newStatus !== $withdrawal['status']) {
                    // Update withdrawal status
                    $updateStmt = $db->prepare("
                        UPDATE salary_withdrawals 
                        SET status = ?, 
                            failure_reason = ?,
                            processed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE processed_at END,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $newStatus, 
                        $newStatus === 'failed' ? $message : null,
                        $newStatus,
                        $withdrawal['id']
                    ]);
                    
                    // Send notification to employee
                    $employeeName = trim($withdrawal['first_name'] . ' ' . $withdrawal['last_name']);
                    $amount = number_format($withdrawal['net_amount'], 2);
                    
                    if ($newStatus === 'completed') {
                        $notificationMessage = "Your salary withdrawal of UGX {$amount} has been completed successfully.";
                    } elseif ($newStatus === 'failed') {
                        $notificationMessage = "Your salary withdrawal of UGX {$amount} has failed. Reason: {$message}";
                    } else {
                        $notificationMessage = "Your salary withdrawal of UGX {$amount} is being processed.";
                    }
                    
                    // Send SMS notification
                    if (!empty($withdrawal['phone'])) {
                        $infobip->sendSMS($withdrawal['phone'], $notificationMessage);
                    }
                    
                    // Send email notification
                    if (!empty($withdrawal['email'])) {
                        $emailSubject = "Withdrawal Status Update - EP Portal";
                        $infobip->sendEmail($withdrawal['email'], $emailSubject, $notificationMessage);
                    }
                    
                    $processed++;
                    echo "[{$timestamp}] Updated withdrawal ID {$withdrawal['id']} to {$newStatus}\n";
                }
            }
            
        } catch (Exception $e) {
            echo "[{$timestamp}] Error processing withdrawal ID {$withdrawal['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // Log execution
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('process-withdrawals', NOW(), 'success', ?)
    ");
    $stmt->execute(["Processed {$processed} withdrawal status updates"]);
    
    echo "[{$timestamp}] Processed {$processed} withdrawal status updates\n";
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    // Log error
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('process-withdrawals', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    exit(1);
}
?>
