<?php
// Load environment variables
require_once __DIR__ . '/env-loader.php';

// Application Configuration
define('APP_NAME', 'EP Portal - Employee Management System');
define('APP_VERSION', '1.0.0');
define('BASE_URL', EnvLoader::get('BASE_URL', 'https://emp.appnomu.com/'));

// Security Configuration
define('ENCRYPTION_KEY', EnvLoader::get('ENCRYPTION_KEY', 'your-32-character-secret-key-here'));
define('JWT_SECRET', EnvLoader::get('JWT_SECRET', 'your-jwt-secret-key-here'));
define('OTP_EXPIRY_MINUTES', 15);
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 3600); // 1 hour
}

// File Upload Configuration
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOCUMENT_TYPES', ['pdf', 'doc', 'docx', 'txt']);

// Infobip API configuration (SMS, WhatsApp, Email)
define('INFOBIP_API_KEY', EnvLoader::get('INFOBIP_API_KEY'));
define('INFOBIP_BASE_URL', EnvLoader::get('INFOBIP_BASE_URL', 'https://api.infobip.com'));
define('INFOBIP_WHATSAPP_NUMBER', EnvLoader::get('INFOBIP_WHATSAPP_NUMBER'));
define('WHATSAPP_NUMBER', EnvLoader::get('INFOBIP_WHATSAPP_NUMBER')); // Main constant
define('WHATSAPP_SENDER_NUMBER', EnvLoader::get('INFOBIP_WHATSAPP_NUMBER')); // Alias for compatibility

define('SMS_API_KEY', EnvLoader::get('INFOBIP_API_KEY')); // Using same API key
define('SMS_SENDER_ID', 'AppNomu');

// FlutterWave V3 API Configuration
define('FLUTTERWAVE_PUBLIC_KEY', EnvLoader::get('FLUTTERWAVE_PUBLIC_KEY')); 
define('FLUTTERWAVE_SECRET_KEY', EnvLoader::get('FLUTTERWAVE_SECRET_KEY')); 
define('FLUTTERWAVE_ENCRYPTION_KEY', EnvLoader::get('FLUTTERWAVE_ENCRYPTION_KEY'));
define('FLUTTERWAVE_ENVIRONMENT', EnvLoader::get('FLUTTERWAVE_ENVIRONMENT', 'sandbox'));
define('FLUTTERWAVE_API_VERSION', 'v3');

// Cloudflare Configuration
define('CLOUDFLARE_API_TOKEN', EnvLoader::get('CLOUDFLARE_API_TOKEN'));

// Email Configuration
define('SMTP_HOST', EnvLoader::get('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', EnvLoader::get('SMTP_PORT', 587));
define('SMTP_USERNAME', EnvLoader::get('SMTP_USERNAME'));
define('SMTP_PASSWORD', EnvLoader::get('SMTP_PASSWORD'));
define('FROM_EMAIL', EnvLoader::get('FROM_EMAIL', 'AppNomu Employee Desk <support@appnomu.com>'));
define('FROM_NAME', 'AppNomu Employee Desk');

// Employee Number Configuration
define('EMPLOYEE_PREFIX', 'EP-');
define('EMPLOYEE_NUMBER_LENGTH', 10);

// Timezone
date_default_timezone_set('Africa/Kampala');

// Security validation - ensure critical keys are set
$requiredKeys = ['ENCRYPTION_KEY', 'JWT_SECRET', 'INFOBIP_API_KEY', 'FLUTTERWAVE_SECRET_KEY'];
foreach ($requiredKeys as $key) {
    if (!constant($key) || constant($key) === 'your-' . strtolower(str_replace('_', '-', $key)) . '-here') {
        error_log("Security Warning: $key not properly configured");
    }
}
