<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();

// Log logout activity if user is authenticated
if (isset($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id']);
}

// Destroy session completely
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit();
?>
