<?php
require_once __DIR__ . '/../config/config.php';

class InfobipAPI {
    private $apiKey;
    private $baseUrl;
    
    public function __construct() {
        $this->apiKey = INFOBIP_API_KEY;
        $this->baseUrl = INFOBIP_BASE_URL;
    }
    
    /**
     * Send SMS
     */
    public function sendSMS($to, $message, $from = 'AppNomu') {
        $url = $this->baseUrl . '/sms/2/text/advanced';
        
        $data = [
            'messages' => [
                [
                    'from' => $from,
                    'destinations' => [
                        ['to' => $to]
                    ],
                    'text' => $message
                ]
            ]
        ];
        
        return $this->makeRequest($url, $data);
    }
    
    /**
     * Send Email
     */
    public function sendEmail($to, $subject, $message, $from = 'AppNomu Employee Desk <support@appnomu.com>') {
        $url = $this->baseUrl . '/email/1/send';
        
        // Clean the subject to remove any HTML tags that might have leaked in
        $cleanSubject = strip_tags(trim($subject));
        
        $data = [
            'from' => $from,
            'to' => $to,
            'subject' => $cleanSubject,
            'html' => $message
        ];
        
        return $this->makeEmailRequest($url, $data);
    }
    
    /**
     * Send WhatsApp message
     */
    public function sendWhatsApp($to, $message) {
        $url = $this->baseUrl . '/whatsapp/1/message/text';
        
        $data = [
            'from' => '447860099299', // Your WhatsApp sender number
            'to' => $to,
            'content' => [
                'text' => $message
            ]
        ];
        
        return $this->makeRequest($url, $data);
    }
    
    /**
     * Send WhatsApp Template Message
     */
    public function sendWhatsAppTemplate($to, $templateName, $placeholders = []) {
        $url = $this->baseUrl . '/whatsapp/1/message/template';
        
        // Clean phone number
        $to = preg_replace('/[^0-9]/', '', $to);
        
        $data = [
            'messages' => [
                [
                    'from' => '256393019882',
                    'to' => $to,
                    'content' => [
                        'templateName' => $templateName,
                        'templateData' => [
                            'body' => [
                                'placeholders' => array_values($placeholders) // Ensure indexed array
                            ]
                        ],
                        'language' => 'en'
                    ]
                ]
            ]
        ];
        
        // Log for debugging
        error_log("WhatsApp Template Request: " . json_encode($data, JSON_PRETTY_PRINT));
        
        return $this->makeRequest($url, $data);
    }
    
    /**
     * Make HTTP request to Infobip API
     */
    private function makeRequest($url, $data) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: App ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception('API Error: ' . ($decodedResponse['requestError']['serviceException']['text'] ?? 'Unknown error'));
        }
        
        return $decodedResponse;
    }
    
    /**
     * Make HTTP request for Email API (uses multipart/form-data)
     */
    private function makeEmailRequest($url, $data) {
        $ch = curl_init();
        
        // Create multipart form data
        $postFields = [];
        foreach ($data as $key => $value) {
            if ($key === 'to' && is_array($value)) {
                $postFields[$key] = json_encode($value);
            } else {
                $postFields[$key] = $value;
            }
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: App ' . $this->apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception('API Error: ' . ($decodedResponse['requestError']['serviceException']['text'] ?? 'Unknown error'));
        }
        
        return $decodedResponse;
    }
}

/**
 * Send OTP via specified method
 */
function sendOTP($userId, $otpCode, $method, $recipient, $type = 'login', $deviceInfo = null) {
    global $db;
    
    $infobip = new InfobipAPI();
    
    // Get user full name if userId is provided and valid
    $userName = null;
    $fullName = null;
    if ($userId && $userId > 0 && isset($db) && $db !== null) {
        try {
            $stmt = $db->prepare("
                SELECT ep.first_name, ep.last_name 
                FROM users u 
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && $user['first_name']) {
                $userName = $user['first_name'];
                $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
            }
        } catch (Exception $e) {
            // Ignore database errors in test mode
        }
    }
    
    // Extract device information
    $deviceDetails = '';
    if ($deviceInfo) {
        $browser = $deviceInfo['browser'] ?? 'Unknown Browser';
        $os = $deviceInfo['os'] ?? 'Unknown OS';
        $ip = $deviceInfo['ip'] ?? 'Unknown IP';
        $deviceDetails = "Browser: $browser, OS: $os, IP: $ip";
    }
    
    $greeting = $userName ? "Hello $userName, " : "";
    $message = "{$greeting}Your AppNomu security code is: $otpCode. Valid for " . OTP_EXPIRY_MINUTES . " minutes. Do not share this code.";
    
    try {
        switch ($method) {
            case 'sms':
                $result = $infobip->sendSMS($recipient, $message, SMS_SENDER_ID);
                break;
            case 'email':
                $subject = 'AppNomu - Security Verification Code';
                $actionType = $type === 'login' ? 'sign in to' : 'authorize a withdrawal from';
                $htmlMessage = generateOTPEmailTemplate($fullName ?: $userName, $otpCode, $actionType, $deviceDetails);
                $result = $infobip->sendEmail($recipient, $subject, $htmlMessage);
                break;
            case 'whatsapp':
                require_once __DIR__ . '/whatsapp.php';
                // WhatsApp templates are strict - only send OTP code without device info
                $result = sendWhatsAppOTP($recipient, $otpCode, '2fa_auth');
                break;
            default:
                throw new Exception('Invalid delivery method');
        }
        
        // Log notification (only if database is available and userId is valid)
        if (isset($db) && $db !== null && $userId && $userId > 0) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO notification_logs (user_id, type, subject, message, status, external_id, sent_at) 
                    VALUES (?, ?, ?, ?, 'sent', ?, NOW())
                ");
                
                $externalId = $result['messageId'] ?? $result['messages'][0]['messageId'] ?? null;
                $stmt->execute([
                    $userId,
                    $method,
                    $type === 'login' ? 'Login OTP' : 'Withdrawal OTP',
                    $message,
                    $externalId
                ]);
            } catch (Exception $e) {
                // Ignore logging errors in test mode
            }
        }
        
        // Check if message was sent successfully
        $isSuccess = false;
        $messageId = null;
        
        // Handle WhatsApp response format (from dedicated WhatsApp class)
        if (isset($result['success'])) {
            $isSuccess = $result['success'];
            $messageId = $result['message_id'] ?? null;
        }
        // Handle SMS/Email response format  
        elseif (isset($result['messages']) && is_array($result['messages']) && count($result['messages']) > 0) {
            $messageId = $result['messages'][0]['messageId'] ?? null;
            $status = $result['messages'][0]['status'] ?? [];
            // Email API returns PENDING_ENROUTE as success status
            $isSuccess = isset($status['name']) && in_array($status['name'], ['PENDING_ENROUTE', 'DELIVERED', 'SENT']);
        } elseif (isset($result['messageId'])) {
            $messageId = $result['messageId'];
            $isSuccess = true;
        }
        
        return [
            'success' => $isSuccess,
            'message_id' => $messageId,
            'details' => $isSuccess ? 'Message sent successfully' : 'Message failed to send',
            'raw_response' => $result
        ];
        
    } catch (Exception $e) {
        // Log failed notification (only if database is available and userId is valid)
        if (isset($db) && $db !== null && $userId && $userId > 0) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO notification_logs (user_id, type, subject, message, status, error_message) 
                    VALUES (?, ?, ?, ?, 'failed', ?)
                ");
                
                $stmt->execute([
                    $userId,
                    $method,
                    $type === 'login' ? 'Login OTP' : 'Withdrawal OTP',
                    $message,
                    $e->getMessage()
                ]);
            } catch (Exception $logError) {
                // Ignore logging errors in test mode
            }
        }
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Send SMS via Infobip
 */
function sendSMS($to, $message, $from = 'AppNomu') {
    $infobip = new InfobipAPI();
    return $infobip->sendSMS($to, $message, $from);
}

/**
 * Send Email via Infobip
 */
function sendEmail($to, $subject, $message, $from = 'AppNomu Employee Desk <support@appnomu.com>') {
    // Clean the subject to remove any HTML tags that might have leaked in
    $cleanSubject = strip_tags(trim($subject));
    
    $infobip = new InfobipAPI();
    return $infobip->sendEmail($to, $cleanSubject, $message, $from);
}

/**
 * Send WhatsApp via Infobip
 */
function sendWhatsApp($to, $message) {
    $infobip = new InfobipAPI();
    return $infobip->sendWhatsApp($to, $message);
}

/**
 * Generate professional OTP email template
 */
function generateOTPEmailTemplate($fullName, $otpCode, $actionType, $deviceDetails) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
            .container { max-width: 500px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 30px; }
            .header { text-align: center; margin-bottom: 30px; }
            .otp-code { font-size: 28px; font-weight: bold; color: #007bff; text-align: center; margin: 20px 0; letter-spacing: 2px; }
            .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2 style='color: #333; margin: 0;'>AppNomu Employee Portal</h2>
                <p style='color: #666; margin: 10px 0 0 0;'>Security Verification</p>
            </div>
            
            <p>Hello " . ($fullName ?: 'Team Member') . ",</p>
            
            <p>Your security code for AppNomu Employee Portal:</p>
            
            <div class='otp-code'>$otpCode</div>
            
            <p style='text-align: center; color: #666; font-size: 14px;'>Valid for " . OTP_EXPIRY_MINUTES . " minutes</p>
            
            <p style='color: #666; font-size: 14px;'>Do not share this code with anyone.</p>
            
            <div class='footer'>
                <p>&copy; " . date('Y') . " AppNomu Employee Portal</p>
            </div>
        </div>
    </body>
    </html>";
}
?>
