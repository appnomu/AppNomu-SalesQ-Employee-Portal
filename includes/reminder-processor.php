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

class ReminderProcessor {
    private $db;
    private $infobip;
    
    public function __construct($database) {
        $this->db = $database;
        // Set timezone to match your server/application timezone
        date_default_timezone_set('Africa/Kampala'); // UTC+3 for Uganda
        $this->infobip = new InfobipAPI();
    }
    
    /**
     * Process all pending reminders that are due
     */
    public function processPendingReminders() {
        $processed = 0;
        
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
        
        foreach ($reminders as $reminder) {
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
                    $errorMessage = 'Invalid delivery method';
                    break;
            }
            
            // Update reminder status with additional check to prevent duplicates
            if ($success) {
                $stmt = $this->db->prepare("
                    UPDATE reminders 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE id = ? AND status = 'pending'
                ");
                $updated = $stmt->execute([$reminder['id']]);
                
                if ($stmt->rowCount() === 0) {
                    // Reminder was already processed by another instance
                    return;
                }
            } else {
                $stmt = $this->db->prepare("UPDATE reminders SET status = 'failed' WHERE id = ?");
                $stmt->execute([$reminder['id']]);
            }
            
            // Log the activity
            $this->logReminderActivity($reminder, $success, $errorMessage);
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            
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
        $message = "Hi {$employeeName}, Reminder: {$reminder['title']} - " . 
                  date('M j, Y g:i A', strtotime($reminder['reminder_datetime'])) . 
                  ". AppNomu EP Portal";
        
        $result = $this->infobip->sendSMS($reminder['phone'], $message, SMS_SENDER_ID);
        return $result !== false;
    }
    
    /**
     * Send WhatsApp reminder using approved template
     */
    private function sendWhatsAppReminder($reminder, $employeeName) {
        // Format time for WhatsApp template
        $reminderTime = date('M j, Y g:i A', strtotime($reminder['reminder_datetime']));
        
        // Use the reminder template (you'll need to provide the template name)
        $templateName = 'reminder_notification'; // Replace with your approved template name
        
        require_once __DIR__ . '/whatsapp.php';
        $whatsapp = new InfobipWhatsApp();
        $result = $whatsapp->sendReminder($reminder['phone'], $employeeName, $reminder['title'], $reminderTime);
        return $result !== false;
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
        $stmt = $this->db->prepare("
            INSERT INTO system_notifications (user_id, title, message, type) 
            VALUES (?, ?, ?, 'reminder')
        ");
        
        $message = $reminder['description'] ? $reminder['description'] : 
                  "Scheduled for " . date('M j, Y g:i A', strtotime($reminder['reminder_datetime']));
        
        return $stmt->execute([$reminder['user_id'], $reminder['title'], $message]);
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
