<?php
require_once __DIR__ . '/../config/session-security.php';

// Start secure session first
startSecureSession();

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// CSRF Protection
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token validation failed']);
    exit;
}

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['employee_id']) || !is_numeric($input['employee_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid employee ID']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    $employeeId = intval($input['employee_id']);
    
    // Verify employee exists
    $stmt = $db->prepare("SELECT u.*, ep.first_name, ep.last_name FROM users u LEFT JOIN employee_profiles ep ON u.id = ep.user_id WHERE u.id = ? AND u.role = 'employee'");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    
    // Generate new temporary password
    $newPassword = generateRandomPassword(12);
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password in database
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$hashedPassword, $employeeId]);
    
    if (!$result) {
        throw new Exception('Failed to update password');
    }
    
    // Send notifications to employee (SMS + Email)
    $smsResult = false;
    $emailResult = false;
    
    // Send SMS notification
    try {
        $smsResult = sendPasswordResetSMS($employee['phone'], $newPassword, $employee['first_name'], $employeeId);
    } catch (Exception $smsError) {
        error_log("SMS sending failed but continuing: " . $smsError->getMessage());
        $smsResult = false;
    }
    
    // Send Email notification
    try {
        $emailResult = sendPasswordResetEmail($employee['email'], $newPassword, $employee['first_name'], $employee['last_name'], $employeeId);
    } catch (Exception $emailError) {
        error_log("Email sending failed but continuing: " . $emailError->getMessage());
        $emailResult = false;
    }
    
    // Log the activity
    $adminId = $_SESSION['user_id'];
    $employeeName = $employee['first_name'] . ' ' . $employee['last_name'];
    $notificationStatus = [];
    if ($smsResult) $notificationStatus[] = 'SMS sent';
    if ($emailResult) $notificationStatus[] = 'Email sent';
    $notificationSummary = !empty($notificationStatus) ? implode(', ', $notificationStatus) : 'No notifications sent';
    
    $logMessage = "Password reset for employee: {$employeeName} (ID: {$employeeId}). Notifications: {$notificationSummary}";
    
    logActivity($adminId, 'password_reset', 'users', $employeeId, $logMessage);
    
    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully',
        'employee_name' => $employeeName,
        'sms_sent' => $smsResult,
        'email_sent' => $emailResult,
        'notifications_sent' => $notificationSummary
    ]);
    
} catch (Exception $e) {
    error_log("Password reset error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Debug: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine()]);
}

/**
 * Send password reset SMS to employee using same method as OTP
 */
function sendPasswordResetSMS($phoneNumber, $newPassword, $firstName, $userId) {
    // Get full name for personalization
    global $db;
    $fullName = $firstName;
    try {
        $stmt = $db->prepare("SELECT CONCAT(ep.first_name, ' ', ep.last_name) as full_name FROM employee_profiles ep INNER JOIN users u ON ep.user_id = u.id WHERE u.id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $fullName = $result['full_name'];
        }
    } catch (Exception $e) {
        // Use firstName if query fails
    }
    try {
        require_once __DIR__ . '/../includes/infobip.php';
        
        $message = "Dear {$fullName},\nYour AppNomu SalesQ login password has been reset.\nYour Password: {$newPassword}\nKeep it secure.\nCall +256200948420 for Help\n- AppNomu Team";
        
        // Use the same sendOTP function structure but for password reset
        $infobip = new InfobipAPI();
        $result = $infobip->sendSMS($phoneNumber, $message, 'AppNomu');
        
        // Check if SMS was sent successfully based on Infobip response format
        if (isset($result['messages']) && is_array($result['messages']) && count($result['messages']) > 0) {
            $status = $result['messages'][0]['status'] ?? [];
            $isSuccess = isset($status['name']) && in_array($status['name'], ['PENDING_ACCEPTED', 'PENDING_ENROUTE', 'DELIVERED', 'SENT']);
            
            // Log the SMS attempt
            global $db;
            if ($db && $userId) {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO notification_logs (user_id, type, subject, message, status, external_id, sent_at) 
                        VALUES (?, 'sms', 'Password Reset', ?, ?, ?, NOW())
                    ");
                    
                    $messageId = $result['messages'][0]['messageId'] ?? null;
                    $stmt->execute([
                        $userId,
                        $message,
                        $isSuccess ? 'sent' : 'failed',
                        $messageId
                    ]);
                } catch (Exception $logError) {
                    error_log("Failed to log SMS: " . $logError->getMessage());
                }
            }
            
            return $isSuccess;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("SMS sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset email to employee using Infobip
 */
function sendPasswordResetEmail($email, $newPassword, $firstName, $lastName, $userId) {
    try {
        require_once __DIR__ . '/../includes/infobip.php';
        
        $infobip = new InfobipAPI();
        $subject = 'AppNomu SalesQ Employee Portal - Password Reset';
        $message = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin:0;padding:0;background-color:#f5f5f5;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f5f5f5;'>
<tr><td align='center' style='padding:20px 10px;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px;background-color:#ffffff;'>
<!-- Header -->
<tr><td style='background-color:#1e88e5;padding:20px;text-align:center;'>
<h1 style='margin:0;color:#ffffff;font-size:20px;font-weight:600;'>Password Reset</h1>
<p style='margin:5px 0 0 0;color:#bbdefb;font-size:13px;'>AppNomu SalesQ</p>
</td></tr>
<!-- Content -->
<tr><td style='padding:30px 20px;'>
<p style='margin:0 0 15px 0;color:#333;font-size:15px;'>Dear <strong>{$firstName} {$lastName}</strong>,</p>
<p style='margin:0 0 20px 0;color:#555;font-size:14px;line-height:1.5;'>Your password has been reset by an administrator.</p>
<table width='100%' cellpadding='15' cellspacing='0' border='0' style='background-color:#e3f2fd;border-left:4px solid #1e88e5;margin:20px 0;'>
<tr><td>
<p style='margin:0 0 8px 0;color:#666;font-size:12px;text-transform:uppercase;'>Your New Password</p>
<p style='margin:0;color:#1e88e5;font-size:18px;font-weight:bold;font-family:monospace;word-break:break-all;'>{$newPassword}</p>
</td></tr>
</table>
<table width='100%' cellpadding='12' cellspacing='0' border='0' style='background-color:#fff3e0;border-left:3px solid #ff9800;margin:20px 0;'>
<tr><td>
<p style='margin:0 0 8px 0;color:#e65100;font-size:13px;font-weight:bold;'>Important:</p>
<p style='margin:0;color:#555;font-size:13px;line-height:1.6;'>This is your permanent password. Keep it secure and do not share it with anyone.</p>
</td></tr>
</table>
<table width='100%' cellpadding='12' cellspacing='0' border='0' style='background-color:#f1f8e9;border-left:3px solid #8bc34a;margin:20px 0;'>
<tr><td style='text-align:center;'>
<p style='margin:0 0 5px 0;color:#558b2f;font-size:13px;font-weight:bold;'>Need Help?</p>
<p style='margin:0;color:#558b2f;font-size:16px;font-weight:bold;'>+256 200 948 420</p>
<p style='margin:5px 0 0 0;color:#689f38;font-size:11px;'>Mon-Fri, 8AM-6PM</p>
</td></tr>
</table>
</td></tr>
<!-- Footer -->
<tr><td style='background-color:#263238;padding:25px 20px;text-align:center;'>
<p style='margin:0 0 10px 0;color:#ffffff;font-size:15px;font-weight:bold;'>AppNomu SalesQ</p>
<p style='margin:0 0 15px 0;color:#90a4ae;font-size:12px;line-height:1.5;'>77 Market Street, Bugiri Municipality, Uganda</p>
<table width='100%' cellpadding='10' cellspacing='0' border='0'>
<tr><td align='center'>
<a href='https://www.facebook.com/appnomu' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#3b5998;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>f</a>
<a href='https://x.com/appnomuSalesQ' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#1da1f2;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>X</a>
<a href='https://www.linkedin.com/company/our-appnomu/' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#0077b5;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:12px;'>in</a>
<a href='https://www.youtube.com/@AppNomusalesQ' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#ff0000;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>▶</a>
<a href='https://www.instagram.com/myappnomu' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#e4405f;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>📷</a>
</td></tr>
</table>
<p style='margin:15px 0 0 0;color:#78909c;font-size:11px;'>© 2025 AppNomu SalesQ. All rights reserved.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
        ";
        
        $result = $infobip->sendEmail($email, $subject, $message);
        
        // Log the email attempt
        global $db;
        if ($db && $userId) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO notification_logs (user_id, type, subject, message, status, sent_at) 
                    VALUES (?, 'email', ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $userId,
                    $subject,
                    strip_tags($message),
                    $result ? 'sent' : 'failed'
                ]);
            } catch (Exception $logError) {
                error_log("Failed to log email: " . $logError->getMessage());
            }
        }
        
        return $result ? true : false;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate a secure random password
 */
function generateRandomPassword($length = 12) {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $symbols = '!@#$%^&*';
    
    $password = '';
    
    // Ensure at least one character from each set
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];
    
    // Fill the rest randomly
    $allChars = $uppercase . $lowercase . $numbers . $symbols;
    for ($i = 4; $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    
    // Shuffle the password
    return str_shuffle($password);
}
?>
