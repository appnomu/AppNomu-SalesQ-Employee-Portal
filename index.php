<?php
require_once __DIR__ . '/config/session-security.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Start secure session first
startSecureSession();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login');
    exit();
}

// Redirect based on user role
if ($_SESSION['role'] === 'admin') {
    header('Location: admin/dashboard');
} else {
    header('Location: employee/dashboard');
}
exit();
?>
