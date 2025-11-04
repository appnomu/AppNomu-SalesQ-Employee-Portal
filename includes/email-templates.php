<?php
/**
 * Email Templates for EP Portal
 * Clean, professional templates that avoid spam triggers
 */

/**
 * Generate ticket creation email template for admin
 * Clean, professional design with priority indicators
 */
function generateTicketCreationEmailTemplate($ticketNumber, $employeeName, $employeeNumber, $subject, $category, $priority, $description) {
    $priorityColor = match($priority) {
        'high' => '#dc3545',
        'medium' => '#fd7e14', 
        'low' => '#28a745',
        default => '#6c757d'
    };
    
    $priorityIcon = match($priority) {
        'high' => '🔴',
        'medium' => '🟡', 
        'low' => '🟢',
        default => '⚪'
    };
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>New Support Ticket</title>
    </head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; line-height: 1.5; color: #2c3e50; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f8f9fa;'>
        
        <!-- Header -->
        <div style='background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); color: white; padding: 25px; border-radius: 8px 8px 0 0; text-align: center;'>
            <h2 style='margin: 0; font-size: 20px; font-weight: 600;'>🎫 New Support Ticket</h2>
            <p style='margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;'>Ticket #" . htmlspecialchars($ticketNumber) . " • Requires attention</p>
        </div>
        
        <!-- Employee Info -->
        <div style='background: white; padding: 20px; border-left: 1px solid #e9ecef; border-right: 1px solid #e9ecef;'>
            <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 3px solid #dc3545;'>
                <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;'>
                    <h3 style='margin: 0; color: #495057; font-size: 16px;'>" . htmlspecialchars($subject) . "</h3>
                    <span style='background: {$priorityColor}; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase;'>
                        {$priorityIcon} " . htmlspecialchars($priority) . "
                    </span>
                </div>
                <p style='margin: 0; font-size: 13px; color: #6c757d;'>
                    <strong>From:</strong> " . htmlspecialchars($employeeName) . " (" . htmlspecialchars($employeeNumber) . ")<br>
                    <strong>Category:</strong> " . htmlspecialchars(ucfirst($category)) . "
                </p>
            </div>
            
            <!-- Description -->
            <div style='background: #ffffff; border: 1px solid #e9ecef; border-radius: 6px; padding: 20px;'>
                <h4 style='margin: 0 0 12px 0; color: #495057; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;'>Description</h4>
                <div style='color: #212529; font-size: 15px; line-height: 1.6;'>
                    " . nl2br(htmlspecialchars($description)) . "
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div style='background: white; padding: 20px; border-radius: 0 0 8px 8px; border: 1px solid #e9ecef; border-top: none; text-align: center;'>
            <div style='background: #fff3cd; padding: 12px; border-radius: 6px; margin-bottom: 15px;'>
                <p style='margin: 0; color: #856404; font-size: 13px;'>
                    <strong>⚡ Action Required:</strong> Log in to the admin panel to review and respond
                </p>
            </div>
            
            <div style='border-top: 1px solid #e9ecef; padding-top: 15px; margin-top: 15px;'>
                <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                    AppNomu SalesQ Employee Portal<br>
                    <span style='color: #adb5bd;'>This is an automated notification • Please do not reply to this email</span>
                </p>
            </div>
        </div>
        
    </body>
    </html>";
}

/**
 * Generate ticket response email template for employee
 * Ultra-clean template - ONLY admin's response content, no auto-greetings or signatures
 */
function generateTicketResponseEmailTemplate($employeeName, $ticketNumber, $subject, $response, $responderName) {
    return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Ticket Response</title>
</head>
<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5;'>
    
    <!-- Header Card -->
    <div style='background: #4a90e2; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
        <h2 style='margin: 0; font-size: 18px;'>Ticket Response</h2>
        <p style='margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;'>#" . htmlspecialchars($ticketNumber) . " - " . htmlspecialchars($subject) . "</p>
    </div>
    
    <!-- Content Card -->
    <div style='background: white; padding: 25px; border: 1px solid #ddd; border-top: none;'>
        
        <!-- Response Content - NO GREETINGS, JUST THE MESSAGE -->
        <div style='background: #f9f9f9; padding: 20px; border-radius: 5px; border-left: 4px solid #4a90e2; margin-bottom: 20px;'>
            <div style='color: #333; font-size: 15px; line-height: 1.6;'>
                " . nl2br(htmlspecialchars($response)) . "
            </div>
        </div>
        
        <!-- Ticket Info -->
        <div style='background: #f0f8ff; padding: 15px; border-radius: 5px; font-size: 13px; color: #666;'>
            <strong>From:</strong> " . htmlspecialchars($responderName) . " • 
            <strong>To:</strong> " . htmlspecialchars($employeeName) . " • 
            <strong>Ticket:</strong> #" . htmlspecialchars($ticketNumber) . "
        </div>
        
    </div>
    
    <!-- Footer Card -->
    <div style='background: white; padding: 20px; border-radius: 0 0 8px 8px; border: 1px solid #ddd; border-top: none; text-align: center;'>
        <div style='background: #e8f4fd; padding: 12px; border-radius: 5px; margin-bottom: 15px;'>
            <p style='margin: 0; color: #2c5aa0; font-size: 13px;'>
                <strong>Continue the conversation:</strong> Log in to the Employee Portal to reply
            </p>
        </div>
        
        <p style='margin: 0; color: #999; font-size: 11px;'>
            AppNomu SalesQ Employee Portal<br>
            This is an automated notification - Please do not reply to this email
        </p>
    </div>
    
</body>
</html>";
}

/**
 * Generate ticket status update email template
 */
function generateTicketStatusUpdateEmailTemplate($employeeName, $ticketNumber, $subject, $oldStatus, $newStatus, $updatedBy) {
    $statusColor = match($newStatus) {
        'open' => '#007bff',
        'in_progress' => '#fd7e14',
        'resolved' => '#28a745',
        'closed' => '#6c757d',
        default => '#6c757d'
    };
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Ticket Status Update</title>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
        
        <div style='background: #e3f2fd; padding: 20px; border-radius: 8px; margin-bottom: 20px;'>
            <h2 style='color: #2c3e50; margin: 0 0 10px 0;'>Ticket Status Updated</h2>
            <p style='margin: 0; color: #6c757d;'>The status of your support ticket has been updated.</p>
        </div>
        
        <div style='background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
            <p style='margin: 0 0 15px 0; color: #212529;'>Dear " . htmlspecialchars($employeeName) . ",</p>
            
            <p style='margin: 0 0 20px 0; color: #495057;'>
                The status of your support ticket has been updated:
            </p>
            
            <table style='width: 100%; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 8px 0; font-weight: bold; color: #495057; width: 30%;'>Ticket Number:</td>
                    <td style='padding: 8px 0; color: #212529;'>" . htmlspecialchars($ticketNumber) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: bold; color: #495057;'>Subject:</td>
                    <td style='padding: 8px 0; color: #212529;'>" . htmlspecialchars($subject) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: bold; color: #495057;'>Previous Status:</td>
                    <td style='padding: 8px 0; color: #6c757d; text-decoration: line-through;'>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $oldStatus))) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: bold; color: #495057;'>New Status:</td>
                    <td style='padding: 8px 0;'>
                        <span style='background: {$statusColor}; color: white; padding: 4px 12px; border-radius: 4px; font-size: 14px;'>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $newStatus))) . "</span>
                    </td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: bold; color: #495057;'>Updated by:</td>
                    <td style='padding: 8px 0; color: #212529;'>" . htmlspecialchars($updatedBy) . "</td>
                </tr>
            </table>
        </div>
        
        <div style='background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; text-align: center;'>
            <p style='margin: 0; color: #495057; font-size: 14px;'>
                You can view the full ticket details by logging into the Employee Portal.
            </p>
        </div>
        
        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center;'>
            <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                AppNomu SalesQ Employee Portal<br>
                This is an automated notification. Please do not reply to this email.
            </p>
        </div>
        
    </body>
    </html>";
}
?>
