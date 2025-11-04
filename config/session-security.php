<?php
/**
 * Enhanced Session Security Configuration
 * Call this before session_start() in your application
 */

// Define SESSION_TIMEOUT if not already defined (fallback)
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 3600); // 1 hour default
}

// Prevent session fixation attacks
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session settings
    ini_set('session.cookie_httponly', 1);     // Prevent JavaScript access
    ini_set('session.cookie_secure', 1);       // HTTPS only
    ini_set('session.cookie_samesite', 'Strict'); // CSRF protection
    ini_set('session.use_strict_mode', 1);     // Reject uninitialized session IDs
    ini_set('session.use_only_cookies', 1);    // Only use cookies for session ID
    ini_set('session.cookie_lifetime', 0);     // Session cookie expires when browser closes
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT); // Session timeout
    ini_set('session.gc_probability', 1);      // Garbage collection probability
    ini_set('session.gc_divisor', 100);        // Garbage collection divisor
    
    // Set session name to something non-default
    session_name('EP_PORTAL_SESSION');
    
    // Set session save path to a secure location (if needed)
    // session_save_path('/path/to/secure/sessions');
}

/**
 * Start secure session with additional validation
 */
function startSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    
    session_start();
    
    // Validate session security
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['created_at'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
    
    // Check session timeout
    if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at'] > SESSION_TIMEOUT)) {
        destroySecureSession();
        return false;
    }
    
    // Validate IP address (optional - can cause issues with load balancers)
    if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
        // Log suspicious activity
        error_log('Session IP mismatch detected: ' . $_SESSION['user_ip'] . ' vs ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        // Uncomment to enforce IP validation:
        // destroySecureSession();
        // return false;
    }
    
    // Regenerate session ID periodically (every 30 minutes)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
    
    return true;
}

/**
 * Destroy session securely
 */
function destroySecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Clear session data
        $_SESSION = array();
        
        // Delete session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/', '', true, true);
        }
        
        // Destroy session
        session_destroy();
    }
}

/**
 * Check if session is valid and user is authenticated
 */
function validateSession() {
    if (!startSecureSession()) {
        return false;
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return false;
    }
    
    // Update last activity
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Require authentication with secure session
 */
function requireSecureAuth() {
    if (!validateSession()) {
        destroySecureSession();
        header('Location: /auth/login');
        exit();
    }
}

/**
 * Require admin access with secure session
 */
function requireSecureAdmin() {
    requireSecureAuth();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: /employee/dashboard');
        exit();
    }
}
?>
