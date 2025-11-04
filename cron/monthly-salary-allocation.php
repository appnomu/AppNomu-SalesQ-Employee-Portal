<?php
/**
 * Monthly Salary Allocation Cron Job
 * Automatically allocates monthly salaries to all employees on the 30th of each month
 * 
 * Usage: This script is called by web-cron.php
 * Schedule: Runs daily, but only executes on the 30th of each month
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/infobip.php';

// Set timezone to match system
date_default_timezone_set('Africa/Kampala');

// Log function for cron activities
function logCronActivity($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [SALARY-CRON] [$type] $message" . PHP_EOL;
    
    // Log to file
    $logFile = __DIR__ . '/../logs/salary-cron.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    
    // Also log to error log for debugging
    error_log($logMessage);
}

// Check if today is the 30th of the month
$currentDay = (int)date('d');
$currentMonth = date('Y-m');

logCronActivity("Salary allocation cron started. Current day: $currentDay, Current month: $currentMonth");

// Only run on the 30th of each month
if ($currentDay !== 30) {
    logCronActivity("Not the 30th of the month. Skipping salary allocation.");
    return;
}

// Check if salary allocation has already been done this month
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as allocation_count 
        FROM salary_allocations 
        WHERE period = ? AND allocation_type = 'monthly' AND notes LIKE '%Automated monthly salary allocation%'
    ");
    $stmt->execute([$currentMonth]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['allocation_count'] > 0) {
        logCronActivity("Monthly salary allocation already completed for $currentMonth. Skipping.");
        return;
    }
} catch (Exception $e) {
    logCronActivity("Error checking existing allocations: " . $e->getMessage(), 'error');
    return;
}

logCronActivity("Starting monthly salary allocation for $currentMonth");

try {
    $db->beginTransaction();
    
    // Get all employees with monthly salaries
    $stmt = $db->prepare("
        SELECT u.id, u.phone, u.employee_number, ep.first_name, ep.last_name, ep.monthly_salary
        FROM users u
        JOIN employee_profiles ep ON u.id = ep.user_id
        WHERE u.role = 'employee' AND ep.monthly_salary > 0
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($employees)) {
        logCronActivity("No employees found with monthly salaries. Skipping allocation.");
        $db->rollBack();
        return;
    }
    
    logCronActivity("Found " . count($employees) . " employees for salary allocation");
    
    $successCount = 0;
    $errorCount = 0;
    $totalAllocated = 0;
    
    foreach ($employees as $employee) {
        try {
            $employeeId = $employee['id'];
            $monthlySalary = $employee['monthly_salary'];
            $employeeName = $employee['first_name'] . ' ' . $employee['last_name'];
            
            // Create salary allocation record
            $stmt = $db->prepare("
                INSERT INTO salary_allocations (employee_id, period, allocated_amount, allocation_type, allocated_by, notes)
                VALUES (?, ?, ?, 'monthly', 1, ?)
            ");
            $notes = "Automated monthly salary allocation for " . date('F Y') . " - Processed on " . date('Y-m-d H:i:s');
            $stmt->execute([$employeeId, $currentMonth, $monthlySalary, $notes]);
            
            // Update employee profile
            $stmt = $db->prepare("
                UPDATE employee_profiles 
                SET period_allocated_amount = period_allocated_amount + ?,
                    current_period = ?,
                    last_salary_reset = CURDATE(),
                    salary_status = CASE 
                        WHEN withdrawn_amount >= (period_allocated_amount + ?) THEN 'exhausted'
                        WHEN withdrawn_amount > 0 THEN 'partial'
                        ELSE 'allocated'
                    END
                WHERE user_id = ?
            ");
            $stmt->execute([$monthlySalary, $currentMonth, $monthlySalary, $employeeId]);
            
            $successCount++;
            $totalAllocated += $monthlySalary;
            
            logCronActivity("Allocated UGX " . number_format($monthlySalary) . " to {$employeeName} (ID: {$employeeId})");
            
            // Send notifications (SMS and WhatsApp)
            try {
                $infobip = new InfobipAPI();
                $formattedAmount = number_format($monthlySalary);
                
                // SMS Notification
                $smsMessage = "Hello {$employee['first_name']}, your monthly salary of UGX {$formattedAmount} has been allocated for " . date('F Y') . ". Check EP Portal for details.";
                $smsResult = $infobip->sendSMS($employee['phone'], $smsMessage, 'AppNomu');
                
                // WhatsApp Notification
                $whatsappParams = [
                    $employee['first_name'],
                    $formattedAmount,
                    'Monthly',
                    date('M j, Y')
                ];
                $whatsappResult = $infobip->sendWhatsAppTemplate($employee['phone'], 'salary_allocated', $whatsappParams);
                
                logCronActivity("Notifications sent to {$employeeName} - SMS: " . ($smsResult ? 'Success' : 'Failed') . ", WhatsApp: " . ($whatsappResult ? 'Success' : 'Failed'));
                
            } catch (Exception $e) {
                logCronActivity("Notification error for {$employeeName}: " . $e->getMessage(), 'warning');
                // Continue processing even if notifications fail
            }
            
        } catch (Exception $e) {
            $errorCount++;
            logCronActivity("Error allocating salary to {$employeeName} (ID: {$employeeId}): " . $e->getMessage(), 'error');
        }
    }
    
    $db->commit();
    
    // Log summary
    logCronActivity("Monthly salary allocation completed successfully!");
    logCronActivity("Summary: {$successCount} successful, {$errorCount} errors, Total allocated: UGX " . number_format($totalAllocated));
    
    // Log to cron_logs table for monitoring
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (job_name, status, message, execution_time, created_at) 
            VALUES (?, 'success', ?, ?, NOW())
        ");
        $executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        $message = "Allocated salaries to {$successCount} employees. Total: UGX " . number_format($totalAllocated);
        $stmt->execute(['monthly_salary_allocation', $message, $executionTime]);
    } catch (Exception $e) {
        logCronActivity("Error logging to cron_logs: " . $e->getMessage(), 'warning');
    }
    
} catch (Exception $e) {
    $db->rollBack();
    logCronActivity("Critical error during salary allocation: " . $e->getMessage(), 'error');
    
    // Log failure to cron_logs table
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_logs (job_name, status, message, execution_time, created_at) 
            VALUES (?, 'failed', ?, ?, NOW())
        ");
        $executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        $stmt->execute(['monthly_salary_allocation', $e->getMessage(), $executionTime]);
    } catch (Exception $logError) {
        logCronActivity("Error logging failure to cron_logs: " . $logError->getMessage(), 'error');
    }
}

logCronActivity("Salary allocation cron completed");
?>
