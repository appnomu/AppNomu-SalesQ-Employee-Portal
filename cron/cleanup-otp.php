#!/usr/bin/env php
<?php
/**
 * OTP Cleanup Cron Job
 * Removes expired OTP records from the database
 * Run daily to keep the database clean
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
    
    $timestamp = date('Y-m-d H:i:s');
    
    // Delete expired OTP records (older than 24 hours)
    $stmt = $db->prepare("
        DELETE FROM otp_verifications 
        WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        OR (is_used = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
    ");
    $stmt->execute();
    $deletedOtp = $stmt->rowCount();
    
    // Log execution
    $stmt = $db->prepare("
        INSERT INTO cron_logs (script_name, execution_time, status, message) 
        VALUES ('cleanup-otp', NOW(), 'success', ?)
    ");
    $stmt->execute(["Cleaned {$deletedOtp} expired OTP records"]);
    
    echo "[{$timestamp}] Cleaned {$deletedOtp} expired OTP records\n";
    
} catch (Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    // Log error
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (script_name, execution_time, status, message) 
            VALUES ('cleanup-otp', NOW(), 'error', ?)
        ");
        $stmt->execute([$e->getMessage()]);
    } catch (Exception $logError) {
        // Continue even if logging fails
    }
    
    exit(1);
}
?>
