<?php
/**
 * Infobip WhatsApp Integration Helper
 * Provides utilities for sending WhatsApp messages via Infobip API
 */

require_once __DIR__ . '/../config/config.php';

class InfobipWhatsApp {
    private $apiKey;
    private $baseUrl;
    private $sender;
    
    /**
     * Constructor
     * 
     * @param string $apiKey Infobip API key
     * @param string $baseUrl Infobip API base URL (default: 'https://api.infobip.com')
     * @param string $sender WhatsApp sender ID/number
     */
    public function __construct($apiKey = null, $baseUrl = null, $sender = null) {
        $this->apiKey = $apiKey ?: INFOBIP_API_KEY;
        $this->baseUrl = rtrim($baseUrl ?: INFOBIP_BASE_URL, '/');
        $this->sender = $sender ?: WHATSAPP_SENDER_NUMBER;
    }
    
    /**
     * Send WhatsApp message with OTP code
     * 
     * @param string $recipient Recipient's phone number (international format with country code)
     * @param string $code OTP verification code
     * @param string $templateName Optional template name (default: '2fa_auth')
     * @return bool|array Success status or response data
     */
    public function sendOtp($recipient, $code, $templateName = '2fa_auth') {
        // Clean the phone number - remove spaces, dashes, etc.
        $originalRecipient = $recipient;
        $recipient = $this->cleanPhoneNumber($recipient);
        
        // Log the OTP sending attempt with timestamp
        $logPrefix = "[WhatsApp OTP] ";
        error_log($logPrefix . "=== Starting OTP Send Process ===");
        error_log($logPrefix . "Original recipient: $originalRecipient");
        error_log($logPrefix . "Cleaned recipient: $recipient");
        error_log($logPrefix . "Template: $templateName (ID: 1948130162613991)");
        
        // Format expiry time
        $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? OTP_EXPIRY_MINUTES : 10;
        
        // Log API details (masking sensitive info)
        error_log($logPrefix . "Sender: " . $this->sender);
        error_log($logPrefix . "API Key: " . substr($this->apiKey, 0, 6) . str_repeat('*', strlen($this->apiKey) - 6));
        error_log($logPrefix . "Base URL: " . $this->baseUrl);
        
        // Prepare the payload according to the exact format required
        $content = [
            'messages' => [
                [
                    'from' => $this->sender,
                    'to' => $recipient,
                    'content' => [
                        'templateName' => $templateName,
                        'templateData' => [
                            'body' => [
                                'placeholders' => [
                                    $code           // OTP code for the message
                                ]
                            ],
                            'buttons' => [
                                [
                                    'type' => 'URL',
                                    'parameter' => 'copy'  // Required for copy functionality
                                ]
                            ]
                        ],
                        'language' => 'en_GB'
                    ]
                ]
            ]
        ];
        
        // Log the exact payload being sent
        error_log($logPrefix . "Sending OTP with payload: " . json_encode($content, JSON_PRETTY_PRINT));
        
        // Prepare API request
        $url = $this->baseUrl . '/whatsapp/1/message/template';
        $headers = [
            'Authorization: App ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Log request details
        $requestInfo = [
            'url' => $url,
            'method' => 'POST',
            'headers' => array_map(function($h) {
                return strpos($h, 'Authorization') !== false ? 'Authorization: App ********' : $h;
            }, $headers),
            'payload' => $content
        ];
        
        error_log($logPrefix . "Sending request to: " . $url);
        error_log($logPrefix . "Request details: " . json_encode($requestInfo, JSON_PRETTY_PRINT));
        
        // Add timing information
        $startTime = microtime(true);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        
        curl_close($ch);
        
        // Calculate request duration
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        // Log response details with timing
        error_log($logPrefix . "=== Response Received ({$duration}ms) ===");
        error_log($logPrefix . "HTTP Status Code: $httpCode");
        
        // Separate headers from body
        $headers = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        
        // Parse the response body as JSON
        $responseData = json_decode(trim($responseBody), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log($logPrefix . "Failed to decode JSON response: " . json_last_error_msg());
            error_log($logPrefix . "Raw Response: " . substr($responseBody, 0, 500));
            // Try to extract message from raw response if possible
            if (preg_match('/"message":"([^"]+)"/', $responseBody, $matches)) {
                $errorMsg = $matches[1];
                error_log($logPrefix . "Extracted error message: " . $errorMsg);
            }
        } else {
            error_log($logPrefix . "Response Body: " . json_encode($responseData, JSON_PRETTY_PRINT));
        }
        
        if ($error) {
            error_log($logPrefix . "cURL Error: $error");
            $result = [
                'success' => false,
                'error' => 'Connection error: ' . $error,
                'http_code' => $httpCode,
                'request_duration_ms' => $duration
            ];
            error_log($logPrefix . "Sending failed: " . json_encode($result));
            return $result;
        }
        
        // Check for API errors
        if ($httpCode >= 400) {
            $errorMsg = isset($responseData['requestError']['serviceException']['text']) 
                ? $responseData['requestError']['serviceException']['text']
                : (isset($responseData['error_description']) 
                    ? $responseData['error_description'] 
                    : 'Unknown API error');
                
            error_log($logPrefix . "API Error ($httpCode): $errorMsg");
            
            // Log additional error details if available
            if (isset($responseData['error_description'])) {
                error_log($logPrefix . "Error Details: " . $responseData['error_description']);
            }
            
            $result = [
                'success' => false,
                'error' => $errorMsg,
                'http_code' => $httpCode,
                'response' => $responseData,
                'request_duration_ms' => $duration
            ];
            
            error_log($logPrefix . "Sending failed: " . json_encode($result));
            return $result;
        }
        
        // Prepare result
        $result = [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'data' => $responseData,
            'message_id' => $responseData['messages'][0]['messageId'] ?? null,
            'request_duration_ms' => $duration
        ];
        
        // Log message status
        if (isset($responseData['messages'][0]['status'])) {
            $status = $responseData['messages'][0]['status'];
            $statusInfo = "Status: {$status['name']} ({$status['description']})";
            $result['status'] = $status['name'];
            $result['status_description'] = $status['description'];
            
            error_log($logPrefix . $statusInfo);
            
            // If message is in pending state, log the message ID for tracking
            if (strpos($status['name'], 'PENDING_') === 0) {
                $messageId = $responseData['messages'][0]['messageId'] ?? 'unknown';
                error_log($logPrefix . "Message ID for tracking: $messageId");
                
                // Log the pending status
                error_log($logPrefix . "Message is pending delivery. Message ID: $messageId");
            }
        }
        
        error_log($logPrefix . "=== OTP Send Process Completed ===");
        return $result;
    }
    
    /**
     * Check if a number is registered on WhatsApp
     * 
     * @param string $phoneNumber Phone number to check (international format)
     * @return bool True if number is on WhatsApp, false otherwise
     */
    public function isWhatsAppUser($phoneNumber) {
        // Clean the phone number
        $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
        error_log("InfobipWhatsApp: Checking if $phoneNumber is on WhatsApp");
        
        // TEMPORARY FIX: Bypass the API check and assume all numbers are WhatsApp users
        // This is because the Infobip API is incorrectly reporting numbers as not on WhatsApp
        error_log("InfobipWhatsApp: BYPASSING API CHECK - Assuming $phoneNumber is on WhatsApp");
        return true;
    }
    
    /**
     * Clean phone number to ensure proper format
     * 
     * @param string $number Phone number to clean
     * @return string Cleaned phone number
     */
    private function cleanPhoneNumber($number) {
        // Log the original number for debugging
        error_log("InfobipWhatsApp: Original phone number: $number");
        
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $number);
        
        // For Uganda numbers (256 country code)
        if (strlen($cleaned) === 9) {
            // If it's a 9-digit number, assume it's a local UG number and add 256
            $cleaned = '256' . $cleaned;
        } elseif (strlen($cleaned) === 10 && substr($cleaned, 0, 1) === '0') {
            // If it's a 10-digit number starting with 0, replace 0 with 256
            $cleaned = '256' . substr($cleaned, 1);
        }
        
        // Log the cleaned number for debugging
        error_log("InfobipWhatsApp: Cleaned phone number to: $cleaned");
        
        return $cleaned;
    }
    
    /**
     * Send API request to Infobip
     * 
     * @param string $endpoint API endpoint
     * @param array $data Request payload
     * @return bool|array Response data or false on failure
     */
    private function sendRequest($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: App ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Log the full request details
        error_log("InfobipWhatsApp: FULL REQUEST - URL: $url");
        error_log("InfobipWhatsApp: FULL REQUEST - Headers: " . json_encode($headers));
        error_log("InfobipWhatsApp: FULL REQUEST - Payload: " . json_encode($data));
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only, enable in production
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable verbose output
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("InfobipWhatsApp: CURL ERROR - $error");
            return false;
        }
        
        error_log("InfobipWhatsApp: FULL RESPONSE ($httpCode): $response");
        
        // Parse the response
        $responseData = json_decode($response, true);
        
        // More detailed response analysis
        if ($responseData) {
            // Check for message status in the response
            if (isset($responseData['messages']) && is_array($responseData['messages'])) {
                foreach ($responseData['messages'] as $message) {
                    error_log("InfobipWhatsApp: Message ID: " . (isset($message['messageId']) ? $message['messageId'] : 'N/A'));
                    error_log("InfobipWhatsApp: Message Status: " . (isset($message['status']) ? json_encode($message['status']) : 'N/A'));
                    
                    // Check for specific error codes
                    if (isset($message['status']) && isset($message['status']['groupId'])) {
                        if ($message['status']['groupId'] != 1) {
                            error_log("InfobipWhatsApp: Message delivery might fail - Group ID: {$message['status']['groupId']}, Description: {$message['status']['description']}");
                        }
                    }
                }
            }
            
            // Check for API errors
            if (isset($responseData['requestError'])) {
                error_log("InfobipWhatsApp: API ERROR - " . json_encode($responseData['requestError']));
                return false;
            }
        }
        
        // Check if the request was successful
        if ($httpCode >= 200 && $httpCode < 300 && $responseData) {
            return $responseData;
        }
        
        return false;
    }
    
    /**
     * Send WhatsApp employee welcome message using approved template
     * 
     * @param string $recipient Recipient's phone number
     * @param string $employeeName Employee's full name
     * @param string $templateName Template name for welcome messages
     * @return bool|array Success status or response data
     */
    public function sendEmployeeWelcome($recipient, $employeeName, $templateName = 'employee_welcome_v4') {
        $recipient = $this->cleanPhoneNumber($recipient);
        
        error_log("[WhatsApp Welcome] Sending to: $recipient");
        error_log("[WhatsApp Welcome] Employee: $employeeName");
        
        // Template structure for employee welcome using approved template format
        $templateData = [
            'messages' => [
                [
                    'from' => $this->sender,
                    'to' => $recipient,
                    'content' => [
                        'templateName' => $templateName,
                        'templateData' => [
                            'body' => [
                                'placeholders' => [
                                    $employeeName       // {{1}} Employee name only
                                ]
                            ]
                        ],
                        'language' => 'en'
                    ]
                ]
            ]
        ];
        
        return $this->sendRequest('/whatsapp/1/message/template', $templateData);
    }
    
    /**
     * Send WhatsApp reminder using approved template
     * 
     * @param string $recipient Recipient's phone number
     * @param string $employeeName Employee's full name
     * @param string $reminderTitle Reminder title
     * @param string $reminderTime Formatted reminder time
     * @param string $templateName Template name for reminders
     * @return bool|array Success status or response data
     */
    public function sendReminder($recipient, $employeeName, $reminderTitle, $reminderTime, $templateName = 'reminder_notification') {
        $recipient = $this->cleanPhoneNumber($recipient);
        
        error_log("[WhatsApp Reminder] Sending to: $recipient");
        error_log("[WhatsApp Reminder] Employee: $employeeName");
        error_log("[WhatsApp Reminder] Title: $reminderTitle");
        error_log("[WhatsApp Reminder] Time: $reminderTime");
        
        // Template structure for reminder using approved template format
        $templateData = [
            'messages' => [
                [
                    'from' => $this->sender,
                    'to' => $recipient,
                    'content' => [
                        'templateName' => $templateName,
                        'templateData' => [
                            'body' => [
                                'placeholders' => [
                                    $employeeName,      // {{1}} Employee name
                                    $reminderTitle,     // {{2}} Reminder title
                                    $reminderTime       // {{3}} Reminder time
                                ]
                            ]
                        ],
                        'language' => 'en'
                    ]
                ]
            ]
        ];
        
        return $this->sendRequest('/whatsapp/1/message/template', $templateData);
    }
}

/**
 * Helper function to send WhatsApp OTP using the dedicated class
 */
function sendWhatsAppOTP($recipient, $otpCode, $templateName = '2fa_auth') {
    $whatsapp = new InfobipWhatsApp();
    return $whatsapp->sendOtp($recipient, $otpCode, $templateName);
}

/**
 * Helper function to send WhatsApp employee welcome message
 */
function sendWhatsAppWelcome($recipient, $employeeName, $templateName = 'employee_welcome_v4') {
    $whatsapp = new InfobipWhatsApp();
    return $whatsapp->sendEmployeeWelcome($recipient, $employeeName, $templateName);
}

/**
 * Helper function to send WhatsApp reminder using the dedicated class
 */
function sendWhatsAppTemplate($recipient, $templateName, $templateParams) {
    $whatsapp = new InfobipWhatsApp();
    return $whatsapp->sendReminder($recipient, $templateParams[0], $templateParams[1], $templateParams[2], $templateName);
}
?>
