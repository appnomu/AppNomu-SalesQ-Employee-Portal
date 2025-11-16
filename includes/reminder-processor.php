<?php
/**
 * Reminder Processor - Handles automated reminder delivery
 * This script should be run via cron job every minute
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/infobip.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/error-logger.php';

class ReminderProcessor {
    private $db;
    private $infobip;
    private $logger;
    
    public function __construct($database) {
        $this->db = $database;
        $this->logger = new ErrorLogger($database);
        // Set timezone to match your server/application timezone
        date_default_timezone_set('Africa/Kampala'); // UTC+3 for Uganda
        
        try {
            $this->infobip = new InfobipAPI();
        } catch (Exception $e) {
            $this->logger->logError('REMINDER_INIT', 'Failed to initialize Infobip API: ' . $e->getMessage(), __FILE__, __LINE__);
            throw $e;
        }
    }
    
    /**
     * Process all pending reminders that are due
     */
    public function processPendingReminders() {
        $processed = 0;
        
        try {
            // Use database lock to prevent duplicate processing
            $stmt = $this->db->prepare("
                SELECT r.*, u.phone, u.email, ep.first_name, ep.last_name
                FROM reminders r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id
                WHERE r.status = 'pending' 
                AND r.reminder_datetime <= NOW()
                ORDER BY r.reminder_datetime ASC
                FOR UPDATE
            ");
            $stmt->execute();
            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->logger->logError('REMINDER_INFO', 'Found ' . count($reminders) . ' pending reminders to process', __FILE__, __LINE__, null, [
                'count' => count($reminders),
                'current_time' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($reminders as $reminder) {
                try {
                    // Mark as processing immediately to prevent duplicates
                    $stmt = $this->db->prepare("
                        UPDATE reminders 
                        SET status = 'processing' 
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$reminder['id']]);
                    
                    // Only process if we successfully marked it as processing
                    if ($stmt->rowCount() > 0) {
                        $this->processReminder($reminder);
                        $processed++;
                    }
                } catch (Exception $e) {
                    $this->logger->logError('REMINDER_PROCESS', 'Failed to process reminder ID ' . $reminder['id'] . ': ' . $e->getMessage(), __FILE__, __LINE__, $reminder['user_id'], [
                        'reminder_id' => $reminder['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->logError('REMINDER_FETCH', 'Failed to fetch pending reminders: ' . $e->getMessage(), __FILE__, __LINE__);
            throw $e;
        }
        
        return $processed;
    }
    
    /**
     * Process a single reminder
     */
    private function processReminder($reminder) {
        $employeeName = trim(($reminder['first_name'] ?? '') . ' ' . ($reminder['last_name'] ?? ''));
        if (empty($employeeName)) {
            $employeeName = 'Employee';
        }
        
        $success = false;
        $errorMessage = '';
        
        $this->logger->logError('REMINDER_START', 'Processing reminder ID ' . $reminder['id'], __FILE__, __LINE__, $reminder['user_id'], [
            'reminder_id' => $reminder['id'],
            'delivery_method' => $reminder['delivery_method'],
            'title' => $reminder['title']
        ]);
        
        try {
            switch ($reminder['delivery_method']) {
                case 'sms':
                    $success = $this->sendSMSReminder($reminder, $employeeName);
                    break;
                    
                case 'whatsapp':
                    $success = $this->sendWhatsAppReminder($reminder, $employeeName);
                    break;
                    
                case 'system':
                    $success = $this->createSystemNotification($reminder, $employeeName);
                    break;
                    
                default:
                    $errorMessage = 'Invalid delivery method: ' . $reminder['delivery_method'];
                    $this->logger->logError('REMINDER_INVALID_METHOD', $errorMessage, __FILE__, __LINE__, $reminder['user_id']);
                    break;
            }
            
            // Update reminder status
            if ($success) {
                $stmt = $this->db->prepare("
                    UPDATE reminders 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$reminder['id']]);
                
                $this->logger->logError('REMINDER_SUCCESS', 'Reminder sent successfully', __FILE__, __LINE__, $reminder['user_id'], [
                    'reminder_id' => $reminder['id'],
                    'delivery_method' => $reminder['delivery_method']
                ]);
            } else {
                $stmt = $this->db->prepare("UPDATE reminders SET status = 'failed' WHERE id = ?");
                $stmt->execute([$reminder['id']]);
                
                $this->logger->logError('REMINDER_FAILED', 'Reminder delivery failed: ' . $errorMessage, __FILE__, __LINE__, $reminder['user_id'], [
                    'reminder_id' => $reminder['id'],
                    'error' => $errorMessage
                ]);
            }
            
            // Log the activity
            $this->logReminderActivity($reminder, $success, $errorMessage);
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
            $this->logger->logError('REMINDER_EXCEPTION', 'Exception processing reminder: ' . $errorMessage, __FILE__, __LINE__, $reminder['user_id'], [
                'reminder_id' => $reminder['id'],
                'exception' => $e->getTraceAsString()
            ]);
            
            // Mark as failed
            $stmt = $this->db->prepare("
                UPDATE reminders 
                SET status = 'failed', sent_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reminder['id']]);
            
            $this->logReminderActivity($reminder, false, $errorMessage);
        }
    }
    
    /**
     * Send SMS reminder
     */
    private function sendSMSReminder($reminder, $employeeName) {
        try {
            $message = "Hi {$employeeName}, Reminder: {$reminder['title']} - " . 
                      date('M j, Y g:i A', strtotime($reminder['reminder_datetime'])) . 
                      ". AppNomu EP Portal";
            
            $this->logger->logError('REMINDER_SMS_ATTEMPT', 'Attempting to send SMS', __FILE__, __LINE__, $reminder['user_id'], [
                'phone' => $reminder['phone'],
                'message_length' => strlen($message)
            ]);
            
            $result = $this->infobip->sendSMS($reminder['phone'], $message, SMS_SENDER_ID);
            return $result !== false;
        } catch (Exception $e) {
            $this->logger->logError('REMINDER_SMS_ERROR', 'SMS send failed: ' . $e->getMessage(), __FILE__, __LINE__, $reminder['user_id']);
            return false;
        }
    }
    
    /**
     * Send WhatsApp reminder using approved template
     */
    private function sendWhatsAppReminder($reminder, $employeeName) {
        try {
            // Format time for WhatsApp template
            $reminderTime = date('M j, Y g:i A', strtotime($reminder['reminder_datetime']));
            
            $this->logger->logError('REMINDER_WHATSAPP_ATTEMPT', 'Attempting to send WhatsApp', __FILE__, __LINE__, $reminder['user_id'], [
                'phone' => $reminder['phone']
            ]);
            
            require_once __DIR__ . '/whatsapp.php';
            $whatsapp = new InfobipWhatsApp();
            $result = $whatsapp->sendReminder($reminder['phone'], $employeeName, $reminder['title'], $reminderTime);
            return $result !== false;
        } catch (Exception $e) {
            $this->logger->logError('REMINDER_WHATSAPP_ERROR', 'WhatsApp send failed: ' . $e->getMessage(), __FILE__, __LINE__, $reminder['user_id']);
            return false;
        }
    }
    
    /**
     * Send Email reminder
     */
    private function sendEmailReminder($reminder, $employeeName) {
        $subject = "Reminder: " . $reminder['title'];
        $message = $this->generateEmailTemplate($reminder, $employeeName);
        
        $result = $this->infobip->sendEmail($reminder['email'], $subject, $message);
        return $result !== false;
    }
    
    /**
     * Create system notification for in-app display
     */
    private function createSystemNotification($reminder, $employeeName) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO system_notifications (user_id, title, message, type) 
                VALUES (?, ?, ?, 'reminder')
            ");
            
            $message = $reminder['description'] ? $reminder['description'] : 
                      "Scheduled for " . date('M j, Y g:i A', strtotime($reminder['reminder_datetime']));
            
            $result = $stmt->execute([$reminder['user_id'], $reminder['title'], $message]);
            
            if ($result) {
                $this->logger->logError('REMINDER_SYSTEM_SUCCESS', 'System notification created', __FILE__, __LINE__, $reminder['user_id']);
            }
            
            return $result;
        } catch (Exception $e) {
            $this->logger->logError('REMINDER_SYSTEM_ERROR', 'System notification failed: ' . $e->getMessage(), __FILE__, __LINE__, $reminder['user_id']);
            return false;
        }
    }
    
    /**
     * Generate email template for reminders
     */
    private function generateEmailTemplate($reminder, $employeeName) {
        $reminderTime = date('M j, Y g:i A', strtotime($reminder['reminder_datetime']));
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #4a90e2;'>Reminder Notification</h2>
                <p>Hello {$employeeName},</p>
                <p>This is a reminder for:</p>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                    <h3 style='margin: 0 0 10px 0; color: #333;'>{$reminder['title']}</h3>
                    " . ($reminder['description'] ? "<p style='margin: 0;'>{$reminder['description']}</p>" : "") . "
                    <p style='margin: 10px 0 0 0; font-weight: bold;'>Scheduled: {$reminderTime}</p>
                </div>
                <p>Best regards,<br>AppNomu EP Portal Team</p>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Log reminder activity
     */
    private function logReminderActivity($reminder, $success, $errorMessage = '') {
        $stmt = $this->db->prepare("
            INSERT INTO notification_logs (user_id, type, subject, message, status, sent_at) 
            VALUES (?, 'reminder', ?, ?, ?, NOW())
        ");
        
        $status = $success ? 'sent' : 'failed';
        $message = $success ? "Reminder sent via {$reminder['delivery_method']}" : 
                  "Failed to send reminder: {$errorMessage}";
        
        $stmt->execute([
            $reminder['user_id'],
            $reminder['title'],
            $message,
            $status
        ]);
    }
}

// If running directly (via cron), process reminders
if (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
    try {
        $processor = new ReminderProcessor($db);
        $processed = $processor->processPendingReminders();
        echo date('Y-m-d H:i:s') . " - Processed {$processed} reminders\n";
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    }
}
?>
