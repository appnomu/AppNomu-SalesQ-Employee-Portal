<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/url-helper.php';

/**
 * Generate unique employee number
 */
function generateEmployeeNumber() {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    $length = EMPLOYEE_NUMBER_LENGTH - strlen(EMPLOYEE_PREFIX);
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return EMPLOYEE_PREFIX . $randomString;
}

/**
 * Generate OTP code
 */
function generateOTP($length = 6) {
    return str_pad(rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Enhanced input sanitization with validation
 */
function sanitizeInput($data, $type = 'string') {
    if ($data === null) {
        return null;
    }
    
    $data = trim($data);
    
    switch ($type) {
        case 'email':
            return filter_var($data, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($data, FILTER_SANITIZE_URL);
        case 'int':
            return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'alphanumeric':
            return preg_replace('/[^a-zA-Z0-9]/', '', $data);
        case 'filename':
            return preg_replace('/[^a-zA-Z0-9._-]/', '', $data);
        case 'html':
            return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        case 'raw':
            return strip_tags($data);
        default:
            return strip_tags($data);
    }
}

/**
 * Safe output for HTML display
 */
function safeOutput($data) {
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Enhanced email validation
 */
function isValidEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Additional checks
    $domain = substr(strrchr($email, '@'), 1);
    if (!$domain || !checkdnsrr($domain, 'MX')) {
        return false;
    }
    
    // Check for common disposable email domains
    $disposableDomains = ['10minutemail.com', 'tempmail.org', 'guerrillamail.com'];
    if (in_array(strtolower($domain), $disposableDomains)) {
        return false;
    }
    
    return true;
}

/**
 * Enhanced phone number validation
 */
function isValidPhone($phone) {
    // Remove all non-digit characters except +
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Check basic format
    if (!preg_match('/^\+?[1-9]\d{7,14}$/', $cleanPhone)) {
        return false;
    }
    
    // Uganda-specific validation (optional)
    if (strpos($cleanPhone, '+256') === 0 || strpos($cleanPhone, '256') === 0) {
        // Uganda phone numbers should be 13 digits with country code
        $ugandaPhone = str_replace('+', '', $cleanPhone);
        return preg_match('/^256[0-9]{9}$/', $ugandaPhone);
    }
    
    return true;
}

/**
 * Format phone number for international use
 */
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (!str_starts_with($phone, '+')) {
        $phone = '+' . $phone;
    }
    return $phone;
}

/**
 * Generate secure token
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Encrypt data
 */
function encryptData($data) {
    $key = ENCRYPTION_KEY;
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt data
 */
function decryptData($encryptedData) {
    $key = ENCRYPTION_KEY;
    $data = base64_decode($encryptedData);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Log user activity
 */
function logActivity($userId, $action, $tableName, $recordId, $details = null) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, NOW())
        ");
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([$userId, $action, $tableName, $recordId, json_encode($details), $ipAddress, $userAgent]);
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("Activity logging failed: " . $e->getMessage());
    }
}

/**
 * Detect browser from user agent
 */
function getBrowser($userAgent) {
    $browserArray = [
        'Edg' => 'Microsoft Edge',
        'Chrome' => 'Google Chrome',
        'Firefox' => 'Mozilla Firefox',
        'Safari' => 'Safari',
        'Opera' => 'Opera',
        'MSIE' => 'Internet Explorer',
        'Trident' => 'Internet Explorer'
    ];

    foreach ($browserArray as $regex => $value) {
        if (preg_match("/$regex/i", $userAgent)) {
            return $value;
        }
    }
    
    return 'Unknown Browser';
}

/**
 * Detect operating system from user agent
 */
function getOS($userAgent) {
    $osArray = [
        'Windows NT 10' => 'Windows 10',
        'Windows NT 6.3' => 'Windows 8.1',
        'Windows NT 6.2' => 'Windows 8',
        'Windows NT 6.1' => 'Windows 7',
        'Windows NT 6.0' => 'Windows Vista',
        'Windows NT 5.1' => 'Windows XP',
        'Windows NT 5.0' => 'Windows 2000',
        'Mac OS X' => 'Mac OS X',
        'Macintosh' => 'Mac OS',
        'Ubuntu' => 'Ubuntu',
        'Linux' => 'Linux',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'BlackBerry' => 'BlackBerry',
        'Windows Phone' => 'Windows Phone'
    ];

    foreach ($osArray as $regex => $value) {
        if (preg_match("/$regex/i", $userAgent)) {
            return $value;
        }
    }
    
    return 'Unknown OS';
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isAuthenticated() && $_SESSION['role'] === 'admin';
}

/**
 * Require authentication
 */
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: /auth/login.php');
        exit();
    }
}

/**
 * Require admin access
 */
function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        header('Location../employee/dashboard');
        exit();
    }
}

/**
 * Format currency
 */
function formatCurrency($amount, $currency = 'USD') {
    return number_format($amount, 2) . ' ' . $currency;
}

/**
 * Calculate working days between dates
 */
function calculateWorkingDays($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day'); // Include end date
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    $workingDays = 0;
    foreach ($period as $date) {
        $dayOfWeek = $date->format('N');
        if ($dayOfWeek < 6) { // Monday = 1, Sunday = 7
            $workingDays++;
        }
    }
    
    return $workingDays;
}

/**
 * Enhanced secure file upload
 */
function uploadFile($file, $category, $userId, $relatedId = null) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception('No file uploaded');
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('File size exceeds maximum allowed size');
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = ($category === 'profile_picture') ? ALLOWED_IMAGE_TYPES : ALLOWED_DOCUMENT_TYPES;
    
    if (!in_array($extension, $allowedTypes)) {
        throw new Exception('File type not allowed');
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain'
    ];
    
    if (!isset($allowedMimes[$extension]) || $mimeType !== $allowedMimes[$extension]) {
        throw new Exception('File type validation failed');
    }
    
    // Generate secure filename
    $fileName = hash('sha256', uniqid() . $userId . time()) . '.' . $extension;
    $uploadDir = UPLOAD_PATH . $category . '/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filePath = $uploadDir . $fileName;
    
    // Additional security: scan for malicious content (basic check)
    $fileContent = file_get_contents($file['tmp_name']);
    if (strpos($fileContent, '<?php') !== false || strpos($fileContent, '<script') !== false) {
        throw new Exception('Potentially malicious file content detected');
    }
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to upload file');
    }
    
    // Set secure file permissions
    chmod($filePath, 0644);
    
    // Save to database
    global $db;
    $stmt = $db->prepare("
        INSERT INTO file_uploads (user_id, file_name, original_name, file_path, file_type, file_size, category, related_id, mime_type) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $userId,
        $fileName,
        sanitizeInput($file['name'], 'filename'),
        $filePath,
        $file['type'],
        $file['size'],
        $category,
        $relatedId,
        $mimeType
    ]);
    
    return $filePath;
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Redirect with message using clean URLs
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    $cleanUrl = cleanUrl($url);
    header("Location: $cleanUrl");
    exit();
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Get browser information from user agent
 */
function getBrowserInfo($userAgent) {
    $browsers = [
        'Chrome' => '/Chrome\/([0-9.]+)/',
        'Firefox' => '/Firefox\/([0-9.]+)/',
        'Safari' => '/Safari\/([0-9.]+)/',
        'Edge' => '/Edg\/([0-9.]+)/',
        'Opera' => '/Opera\/([0-9.]+)/',
        'Internet Explorer' => '/MSIE ([0-9.]+)/'
    ];
    
    foreach ($browsers as $browser => $pattern) {
        if (preg_match($pattern, $userAgent, $matches)) {
            return $browser . ' ' . $matches[1];
        }
    }
    
    return 'Unknown Browser';
}

/**
 * Get operating system information from user agent
 */
function getOSInfo($userAgent) {
    $os_array = [
        'Windows 11' => '/Windows NT 10.0.*; Win64; x64.*rv:/',
        'Windows 10' => '/Windows NT 10.0/',
        'Windows 8.1' => '/Windows NT 6.3/',
        'Windows 8' => '/Windows NT 6.2/',
        'Windows 7' => '/Windows NT 6.1/',
        'Windows Vista' => '/Windows NT 6.0/',
        'Windows XP' => '/Windows NT 5.1/',
        'Mac OS X' => '/Mac OS X/',
        'macOS' => '/Macintosh.*Mac OS X/',
        'Linux' => '/Linux/',
        'Ubuntu' => '/Ubuntu/',
        'Android' => '/Android/',
        'iOS' => '/iPhone|iPad/',
    ];
    
    foreach ($os_array as $os => $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return $os;
        }
    }
    
    return 'Unknown OS';
}
?>
