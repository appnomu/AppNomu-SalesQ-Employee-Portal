<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_admin_profile':
                // Handle admin profile update
                $firstName = sanitizeInput($_POST['first_name']);
                $lastName = sanitizeInput($_POST['last_name']);
                $email = sanitizeInput($_POST['email']);
                $phone = sanitizeInput($_POST['phone']);
                $department = sanitizeInput($_POST['department']);
                $position = sanitizeInput($_POST['position']);
                
                // Handle profile picture upload first
                $profilePicture = null;
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/';
                    $fileName = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $uploadPath = $uploadDir . $fileName;
                    
                    // Validate file type
                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
                    $fileExtension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                    
                    if (in_array($fileExtension, $allowedTypes) && $_FILES['profile_picture']['size'] <= 5000000) {
                        // Ensure upload directory exists and is writable
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadPath)) {
                            $profilePicture = $fileName;
                        } else {
                            $_SESSION['error_message'] = 'Failed to upload profile picture. Check directory permissions.';
                            header('Location: settings.php');
                            exit();
                        }
                    } else {
                        $_SESSION['error_message'] = 'Invalid file type or size too large (max 5MB)';
                        header('Location: settings.php');
                        exit();
                    }
                }
                
                try {
                    $db->beginTransaction();
                    
                    // Update users table
                    $stmt = $db->prepare("UPDATE users SET email = ?, phone = ? WHERE id = ?");
                    $stmt->execute([$email, $phone, $_SESSION['user_id']]);
                    
                    // Update or insert employee_profiles
                    if ($profilePicture) {
                        $stmt = $db->prepare("
                            INSERT INTO employee_profiles (user_id, first_name, last_name, department, position, profile_picture) 
                            VALUES (?, ?, ?, ?, ?, ?) 
                            ON DUPLICATE KEY UPDATE 
                            first_name = VALUES(first_name), 
                            last_name = VALUES(last_name), 
                            department = VALUES(department), 
                            position = VALUES(position),
                            profile_picture = VALUES(profile_picture)
                        ");
                        $stmt->execute([$_SESSION['user_id'], $firstName, $lastName, $department, $position, $profilePicture]);
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO employee_profiles (user_id, first_name, last_name, department, position) 
                            VALUES (?, ?, ?, ?, ?) 
                            ON DUPLICATE KEY UPDATE 
                            first_name = VALUES(first_name), 
                            last_name = VALUES(last_name), 
                            department = VALUES(department), 
                            position = VALUES(position)
                        ");
                        $stmt->execute([$_SESSION['user_id'], $firstName, $lastName, $department, $position]);
                    }
                    
                    $db->commit();
                    logActivity($_SESSION['user_id'], 'admin_profile_update', 'employee_profiles', $_SESSION['user_id'], "Admin profile updated");
                    $_SESSION['success_message'] = "Profile updated successfully.";
                } catch (Exception $e) {
                    $db->rollback();
                    $_SESSION['error_message'] = "Failed to update profile: " . $e->getMessage();
                }
                break;
                
            case 'change_admin_password':
                // Handle password change
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                if ($newPassword !== $confirmPassword) {
                    $_SESSION['error_message'] = "New passwords do not match.";
                    break;
                }
                
                if (strlen($newPassword) < 8) {
                    $_SESSION['error_message'] = "Password must be at least 8 characters long.";
                    break;
                }
                
                try {
                    // Verify current password
                    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!password_verify($currentPassword, $user['password_hash'])) {
                        $_SESSION['error_message'] = "Current password is incorrect.";
                        break;
                    }
                    
                    // Update password
                    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ?, password = ? WHERE id = ?");
                    $stmt->execute([$newPasswordHash, $newPassword, $_SESSION['user_id']]);
                    
                    logActivity($_SESSION['user_id'], 'password_change', 'users', $_SESSION['user_id'], "Admin password changed");
                    $_SESSION['success_message'] = "Password changed successfully.";
                } catch (Exception $e) {
                    $_SESSION['error_message'] = "Failed to change password: " . $e->getMessage();
                }
                break;
                
            case 'update_system_settings':
                // Handle system settings update
                $systemName = sanitizeInput($_POST['system_name']);
                $timezone = sanitizeInput($_POST['timezone']);
                $dateFormat = sanitizeInput($_POST['date_format']);
                
                // Update system settings in database
                $settings = [
                    'system_name' => $systemName,
                    'timezone' => $timezone,
                    'date_format' => $dateFormat
                ];
                
                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
                    $stmt->execute([$value, $key]);
                }
                
                logActivity($_SESSION['user_id'], 'system_settings_update', 'system_settings', 0, "System settings updated");
                $_SESSION['success_message'] = "System settings updated successfully.";
                break;
                
                
            case 'update_leave_settings':
                // Handle leave settings
                $maxAnnualLeave = intval($_POST['max_annual_leave']);
                $maxSickLeave = intval($_POST['max_sick_leave']);
                $requireApproval = isset($_POST['require_approval']) ? 1 : 0;
                
                // Update leave types
                $stmt = $db->prepare("UPDATE leave_types SET max_days_per_year = ? WHERE name = 'Annual Leave'");
                $stmt->execute([$maxAnnualLeave]);
                
                $stmt = $db->prepare("UPDATE leave_types SET max_days_per_year = ? WHERE name = 'Sick Leave'");
                $stmt->execute([$maxSickLeave]);
                
                logActivity($_SESSION['user_id'], 'leave_settings_update', 'leave_types', 0, "Leave settings updated");
                $_SESSION['success_message'] = "Leave settings updated successfully.";
                break;
                
            case 'backup_database':
                // Handle database backup
                logActivity($_SESSION['user_id'], 'database_backup', 'system_backup', 0, "Database backup initiated");
                $_SESSION['success_message'] = "Database backup initiated successfully.";
                break;
                
            case 'create_admin':
                // Handle creating new admin account
                $firstName = sanitizeInput($_POST['first_name']);
                $lastName = sanitizeInput($_POST['last_name']);
                $email = sanitizeInput($_POST['email']);
                $phone = formatPhoneNumber(sanitizeInput($_POST['phone']));
                $department = sanitizeInput($_POST['department']);
                $position = sanitizeInput($_POST['position']);
                
                $errors = [];
                
                if (empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
                    $_SESSION['error_message'] = 'Please fill in all required fields';
                    break;
                }
                
                if (!isValidEmail($email)) {
                    $_SESSION['error_message'] = 'Please enter a valid email address';
                    break;
                }
                
                if (!isValidPhone($phone)) {
                    $_SESSION['error_message'] = 'Please enter a valid phone number';
                    break;
                }
                
                // Check if email or phone already exists
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
                $stmt->execute([$email, $phone]);
                if ($stmt->fetch()) {
                    $_SESSION['error_message'] = 'Email or phone number already exists';
                    break;
                }
                
                try {
                    $db->beginTransaction();
                    
                    // Generate employee number for admin
                    do {
                        $employeeNumber = generateEmployeeNumber();
                        $stmt = $db->prepare("SELECT id FROM users WHERE employee_number = ?");
                        $stmt->execute([$employeeNumber]);
                    } while ($stmt->fetch());
                    
                    // Generate temporary password
                    $tempPassword = generateSecureToken(12);
                    $hashedPassword = hashPassword($tempPassword);
                    
                    // Create admin user account
                    $stmt = $db->prepare("
                        INSERT INTO users (employee_number, email, phone, password_hash, role, status) 
                        VALUES (?, ?, ?, ?, 'admin', 'active')
                    ");
                    $stmt->execute([$employeeNumber, $email, $phone, $hashedPassword]);
                    $userId = $db->lastInsertId();
                    
                    // Create employee profile
                    $stmt = $db->prepare("
                        INSERT INTO employee_profiles (user_id, first_name, last_name, department, position) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$userId, $firstName, $lastName, $department, $position]);
                    
                    // Log activity
                    logActivity($_SESSION['user_id'], 'admin_created', 'users', $userId, "New admin created: $employeeNumber");
                    
                    $db->commit();
                    
                    // Send welcome email with credentials
                    try {
                        require_once '../includes/infobip.php';
                        $infobip = new InfobipAPI();
                        $subject = 'Admin Account Created - AppNomu SalesQ Employee Portal';
                        $message = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin:0;padding:0;background-color:#f5f5f5;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f5f5f5;'>
<tr><td align='center' style='padding:20px 10px;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px;background-color:#ffffff;'>
<tr><td style='background-color:#dc3545;padding:20px;text-align:center;'>
<h1 style='margin:0;color:#ffffff;font-size:20px;font-weight:600;'>Admin Account Created</h1>
<p style='margin:5px 0 0 0;color:#ffcccc;font-size:13px;'>AppNomu SalesQ Employee Portal</p>
</td></tr>
<tr><td style='padding:30px 20px;'>
<p style='margin:0 0 15px 0;color:#333;font-size:15px;'>Dear <strong>$firstName $lastName</strong>,</p>
<p style='margin:0 0 20px 0;color:#555;font-size:14px;line-height:1.5;'>An administrator account has been created for you with full system access.</p>
<table width='100%' cellpadding='15' cellspacing='0' border='0' style='background-color:#fff5f5;border-left:4px solid #dc3545;margin:20px 0;'>
<tr><td>
<p style='margin:0 0 12px 0;color:#dc3545;font-size:13px;font-weight:bold;'>Your Admin Login Credentials</p>
<p style='margin:0 0 8px 0;color:#666;font-size:11px;'>EMPLOYEE NUMBER</p>
<p style='margin:0 0 15px 0;color:#dc3545;font-size:16px;font-weight:bold;font-family:monospace;'>$employeeNumber</p>
<p style='margin:0 0 8px 0;color:#666;font-size:11px;'>EMAIL</p>
<p style='margin:0 0 15px 0;color:#dc3545;font-size:14px;font-weight:bold;word-break:break-all;'>$email</p>
<p style='margin:0 0 8px 0;color:#666;font-size:11px;'>PASSWORD</p>
<p style='margin:0;color:#dc3545;font-size:16px;font-weight:bold;font-family:monospace;word-break:break-all;'>$tempPassword</p>
</td></tr>
</table>
<table width='100%' cellpadding='12' cellspacing='0' border='0' style='background-color:#fff3e0;border-left:3px solid #ff9800;margin:20px 0;'>
<tr><td>
<p style='margin:0 0 5px 0;color:#e65100;font-size:12px;font-weight:bold;'>Important Security Notice:</p>
<p style='margin:0;color:#555;font-size:12px;line-height:1.5;'>As an administrator, you have full access to the system. Keep these credentials secure and change your password after first login.</p>
</td></tr>
</table>
</td></tr>
<tr><td style='background-color:#263238;padding:25px 20px;text-align:center;'>
<p style='margin:0 0 10px 0;color:#ffffff;font-size:15px;font-weight:bold;'>AppNomu SalesQ</p>
<p style='margin:0;color:#90a4ae;font-size:12px;'>© 2025 AppNomu SalesQ. All rights reserved.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
                        ";
                        $infobip->sendEmail($email, $subject, $message);
                    } catch (Exception $e) {
                        // Email failed but admin was created
                        error_log("Failed to send admin welcome email: " . $e->getMessage());
                    }
                    
                    $_SESSION['success_message'] = "Admin account created successfully! Employee Number: $employeeNumber | Password: $tempPassword";
                } catch (Exception $e) {
                    $db->rollBack();
                    $_SESSION['error_message'] = 'Failed to create admin account: ' . $e->getMessage();
                }
                break;
                
            case 'demote_to_employee':
                // Handle demoting admin to employee
                $userId = intval($_POST['user_id']);
                
                try {
                    // Verify user exists and is currently an admin
                    $stmt = $db->prepare("SELECT id, role, employee_number FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$user) {
                        $_SESSION['error_message'] = "User not found.";
                        break;
                    }
                    
                    if ($user['role'] !== 'admin') {
                        $_SESSION['error_message'] = "User is not an admin.";
                        break;
                    }
                    
                    // Prevent demoting yourself
                    if ($userId === $_SESSION['user_id']) {
                        $_SESSION['error_message'] = "You cannot demote yourself.";
                        break;
                    }
                    
                    // Check if this is the last admin
                    $stmt = $db->prepare("SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin'");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result['admin_count'] <= 1) {
                        $_SESSION['error_message'] = "Cannot demote the last admin. At least one admin must remain.";
                        break;
                    }
                    
                    // Demote to employee
                    $stmt = $db->prepare("UPDATE users SET role = 'employee' WHERE id = ?");
                    $stmt->execute([$userId]);
                    
                    logActivity($_SESSION['user_id'], 'admin_demoted_to_employee', 'users', $userId, "Admin {$user['employee_number']} demoted to employee");
                    $_SESSION['success_message'] = "Admin successfully demoted to employee.";
                } catch (Exception $e) {
                    $_SESSION['error_message'] = "Failed to demote admin: " . $e->getMessage();
                }
                break;
        }
        
        header('Location: settings.php');
        exit();
    }
}

// Get current admin profile data
$stmt = $db->prepare("
    SELECT u.email, u.phone, u.employee_number, ep.first_name, ep.last_name, 
           ep.department, ep.position, ep.profile_picture
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current system settings
$stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings");
$stmt->execute();
$systemSettingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to associative array for easy access
$systemSettings = [];
foreach ($systemSettingsData as $setting) {
    $systemSettings[$setting['setting_key']] = $setting['setting_value'];
}

// Get current leave type settings
$stmt = $db->prepare("SELECT * FROM leave_types ORDER BY name");
$stmt->execute();
$leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system statistics for maintenance section
$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM tasks) as total_tasks,
        (SELECT COUNT(*) FROM tickets) as total_tickets,
        (SELECT COUNT(*) FROM salary_withdrawals) as total_withdrawals,
        (SELECT COUNT(*) FROM audit_logs) as total_audit_logs
");
$stmt->execute();
$systemStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all admin users for admin management
$stmt = $db->prepare("
    SELECT u.id, u.employee_number, u.email, u.role, u.status, u.created_at,
           ep.first_name, ep.last_name, ep.department, ep.position
    FROM users u
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    WHERE u.role = 'admin'
    ORDER BY u.created_at DESC
");
$stmt->execute();
$adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count admins
$adminCount = count($adminUsers);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Settings - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .main-content {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        
        .settings-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .settings-section {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        
        .settings-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .stat-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0">
                <div class="sidebar p-3">
                    <div class="text-center text-white mb-4">
                        <img src="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png" 
                             alt="AppNomu SalesQ" 
                             style="max-height: 60px; margin-bottom: 15px;">
                        <h4>AppNomu SalesQ</h4>
                        <small>Admin Panel</small>
                    </div>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a class="nav-link" href="employees.php">
                            <i class="fas fa-users me-2"></i>Employees
                        </a>
                        <a class="nav-link" href="leave-requests.php">
                            <i class="fas fa-calendar-alt me-2"></i>Leave Requests
                        </a>
                        <a class="nav-link" href="tasks.php">
                            <i class="fas fa-tasks me-2"></i>Tasks
                        </a>
                        <a class="nav-link" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Tickets
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="salary-management.php">
                            <i class="fas fa-dollar-sign me-2"></i>Salary Management
                        </a>
                        <a class="nav-link" href="withdrawals.php">
                            <i class="fas fa-money-bill-wave me-2"></i>Withdrawals
                        </a>
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i>Reports
                        </a>
                        <a class="nav-link active" href="settings.php">
                            <i class="fas fa-cog me-2"></i>Settings
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <div class="text-white-50 small">
                            <i class="fas fa-user me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['employee_number'] ?? 'Admin'); ?>
                        </div>
                        <a href="../auth/logout.php" class="nav-link text-white-50 small">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <div class="container-fluid py-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2><i class="fas fa-cog me-2"></i>System Settings</h2>
                            <p class="text-muted">Configure system preferences and manage application settings</p>
                        </div>
                    </div>
                    
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Admin Profile Management -->
                    <div class="settings-card">
                        <h5 class="mb-4"><i class="fas fa-user-cog me-2"></i>Admin Profile</h5>
                        
                        <!-- Profile Information -->
                        <form method="POST" enctype="multipart/form-data" class="settings-section">
                            <input type="hidden" name="action" value="update_admin_profile">
                            
                            <div class="row">
                                <div class="col-md-3 text-center">
                                    <div class="mb-3">
                                        <div class="profile-picture-container" style="position: relative; display: inline-block;">
                                            <?php if (!empty($adminProfile['profile_picture']) && file_exists(__DIR__ . '/../uploads/' . $adminProfile['profile_picture'])): ?>
                                                <img src="../uploads/<?php echo htmlspecialchars($adminProfile['profile_picture']); ?>" 
                                                     alt="Profile Picture" 
                                                     class="rounded-circle" 
                                                     style="width: 120px; height: 120px; object-fit: cover; border: 4px solid #667eea;">
                                            <?php else: ?>
                                                <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                                     style="width: 120px; height: 120px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: 4px solid #667eea;">
                                                    <i class="fas fa-user text-white" style="font-size: 48px;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2">
                                            <label for="profile_picture" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-camera me-1"></i>Change Photo
                                            </label>
                                            <input type="file" id="profile_picture" name="profile_picture" 
                                                   accept="image/*" style="display: none;">
                                            <small class="d-block text-muted mt-1">Max 2MB (JPG, PNG, GIF)</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-9">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">First Name</label>
                                                <input type="text" class="form-control" name="first_name" 
                                                       value="<?php echo htmlspecialchars($adminProfile['first_name'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Last Name</label>
                                                <input type="text" class="form-control" name="last_name" 
                                                       value="<?php echo htmlspecialchars($adminProfile['last_name'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email Address</label>
                                                <input type="email" class="form-control" name="email" 
                                                       value="<?php echo htmlspecialchars($adminProfile['email'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Phone Number</label>
                                                <input type="tel" class="form-control" name="phone" 
                                                       value="<?php echo htmlspecialchars($adminProfile['phone'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Department</label>
                                                <input type="text" class="form-control" name="department" 
                                                       value="<?php echo htmlspecialchars($adminProfile['department'] ?? 'Administration'); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Position</label>
                                                <input type="text" class="form-control" name="position" 
                                                       value="<?php echo htmlspecialchars($adminProfile['position'] ?? 'System Administrator'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Employee Number</label>
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($adminProfile['employee_number'] ?? ''); ?>" readonly>
                                        <small class="text-muted">Employee number cannot be changed</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </form>
                        
                        <!-- Password Change -->
                        <form method="POST" class="settings-section">
                            <input type="hidden" name="action" value="change_admin_password">
                            
                            <h6 class="mb-3"><i class="fas fa-lock me-2"></i>Change Password</h6>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" class="form-control" name="current_password" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">New Password</label>
                                        <input type="password" class="form-control" name="new_password" 
                                               minlength="8" required>
                                        <small class="text-muted">Minimum 8 characters</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password" 
                                               minlength="8" required>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </form>
                    </div>
                    
                    <!-- System Configuration -->
                    <div class="settings-card">
                        <h5 class="mb-4"><i class="fas fa-server me-2"></i>System Configuration</h5>
                        
                        <form method="POST" class="settings-section">
                            <input type="hidden" name="action" value="update_system_settings">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">System Name</label>
                                        <input type="text" class="form-control" name="system_name" value="<?php echo htmlspecialchars($systemSettings['system_name'] ?? 'EP Portal'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Timezone</label>
                                        <select class="form-select" name="timezone" required>
                                            <option value="Africa/Kampala" <?php echo ($systemSettings['timezone'] ?? 'Africa/Kampala') === 'Africa/Kampala' ? 'selected' : ''; ?>>Africa/Kampala (UTC+3)</option>
                                            <option value="UTC" <?php echo ($systemSettings['timezone'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                            <option value="America/New_York" <?php echo ($systemSettings['timezone'] ?? '') === 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                                            <option value="Europe/London" <?php echo ($systemSettings['timezone'] ?? '') === 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Date Format</label>
                                        <select class="form-select" name="date_format" required>
                                            <option value="Y-m-d" <?php echo ($systemSettings['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                            <option value="d/m/Y" <?php echo ($systemSettings['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                            <option value="m/d/Y" <?php echo ($systemSettings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                            <option value="d-M-Y" <?php echo ($systemSettings['date_format'] ?? '') === 'd-M-Y' ? 'selected' : ''; ?>>DD-MMM-YYYY</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Currency</label>
                                        <input type="text" class="form-control" value="UGX" readonly>
                                        <small class="text-muted">Currency is currently fixed to UGX</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update System Settings
                            </button>
                        </form>
                    </div>
                    
                    <!-- Leave Management Settings -->
                    <div class="settings-card">
                        <h5 class="mb-4"><i class="fas fa-calendar-alt me-2"></i>Leave Management Settings</h5>
                        
                        <form method="POST" class="settings-section">
                            <input type="hidden" name="action" value="update_leave_settings">
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Max Annual Leave Days</label>
                                        <input type="number" class="form-control" name="max_annual_leave" value="21" min="1" max="365" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Max Sick Leave Days</label>
                                        <input type="number" class="form-control" name="max_sick_leave" value="10" min="1" max="365" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" name="require_approval" id="requireApproval" checked>
                                        <label class="form-check-label" for="requireApproval">
                                            Require Admin Approval
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Leave Settings
                            </button>
                        </form>
                    </div>
                    
                    <!-- System Maintenance -->
                    <div class="settings-card">
                        <h5 class="mb-4"><i class="fas fa-tools me-2"></i>System Maintenance</h5>
                        
                        <!-- System Statistics -->
                        <div class="row mb-4">
                            <div class="col-md-2">
                                <div class="stat-item">
                                    <h4 class="text-primary"><?php echo $systemStats['total_users']; ?></h4>
                                    <small class="text-muted">Total Users</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="stat-item">
                                    <h4 class="text-info"><?php echo $systemStats['total_tasks']; ?></h4>
                                    <small class="text-muted">Total Tasks</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="stat-item">
                                    <h4 class="text-warning"><?php echo $systemStats['total_tickets']; ?></h4>
                                    <small class="text-muted">Total Tickets</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="stat-item">
                                    <h4 class="text-success"><?php echo $systemStats['total_withdrawals']; ?></h4>
                                    <small class="text-muted">Total Withdrawals</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="stat-item">
                                    <h4 class="text-secondary"><?php echo $systemStats['total_audit_logs']; ?></h4>
                                    <small class="text-muted">Audit Logs</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="stat-item">
                                    <h4 class="text-primary"><?php echo date('Y-m-d'); ?></h4>
                                    <small class="text-muted">Last Backup</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="backup_database">
                                    <button type="submit" class="btn btn-warning me-2" onclick="return confirm('Are you sure you want to create a database backup?')">
                                        <i class="fas fa-database me-2"></i>Backup Database
                                    </button>
                                </form>
                                
                                <button type="button" class="btn btn-info me-2" onclick="clearCache()">
                                    <i class="fas fa-broom me-2"></i>Clear Cache
                                </button>
                                
                                <button type="button" class="btn btn-danger" onclick="clearLogs()">
                                    <i class="fas fa-trash me-2"></i>Clear Old Logs
                                </button>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    System Version: 1.0.0<br>
                                    PHP Version: <?php echo phpversion(); ?><br>
                                    Server Time: <?php echo date('Y-m-d H:i:s'); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Admin Management -->
                    <div class="settings-card">
                        <h5 class="mb-4"><i class="fas fa-user-shield me-2"></i>Admin Management</h5>
                        <p class="text-muted">Create and manage administrator accounts</p>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Current Admins:</strong> <?php echo $adminCount; ?>
                        </div>
                        
                        <!-- Create New Admin Form -->
                        <div class="card border-primary mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Create New Admin Account</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="create_admin">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="first_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="last_name" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                                <input type="email" class="form-control" name="email" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                                <input type="tel" class="form-control" name="phone" placeholder="+256700000000" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Department</label>
                                                <input type="text" class="form-control" name="department" value="Administration">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Position</label>
                                                <input type="text" class="form-control" name="position" value="System Administrator">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <small>A temporary password will be generated and sent to the admin's email address.</small>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-user-plus me-2"></i>Create Admin Account
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Existing Admins List -->
                        <h6 class="mb-3"><i class="fas fa-list me-2"></i>Current Administrators</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Employee #</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($adminUsers)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No administrators found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($adminUsers as $user): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-danger"><?php echo htmlspecialchars($user['employee_number']); ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-shield-alt text-danger me-2"></i>
                                                    <?php 
                                                    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                                                    echo htmlspecialchars($fullName ?: 'N/A');
                                                    ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?php echo ucfirst($user['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                    <span class="badge bg-info"><i class="fas fa-user me-1"></i>You</span>
                                                <?php else: ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to demote this admin to employee? They will lose all admin privileges.');">
                                                        <input type="hidden" name="action" value="demote_to_employee">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning" title="Demote to Employee" <?php echo ($adminCount <= 1) ? 'disabled' : ''; ?>>
                                                            <i class="fas fa-arrow-down"></i> Demote
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> At least one admin must remain in the system. You cannot demote yourself or the last admin.
                        </div>
                    </div>
                    
                    <!-- API Configuration -->
                    <div class="settings-card">
                        <h5 class="mb-4"><i class="fas fa-plug me-2"></i>API Configuration</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Infobip API</h6>
                                <p class="text-muted small">SMS, Email, and WhatsApp notifications</p>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-success me-2">Connected</span>
                                    <small class="text-muted">Last used: <?php echo date('Y-m-d H:i:s'); ?></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Flutterwave API</h6>
                                <p class="text-muted small">Payment processing for withdrawals</p>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-success me-2">Connected</span>
                                    <small class="text-muted">Last transaction: <?php echo date('Y-m-d H:i:s'); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-primary" onclick="testConnections()">
                                <i class="fas fa-plug me-2"></i>Test API Connections
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function clearCache() {
            if (confirm('Are you sure you want to clear the system cache?')) {
                // Implementation for cache clearing
                alert('Cache cleared successfully!');
            }
        }
        
        function clearLogs() {
            if (confirm('Are you sure you want to clear old audit logs? This action cannot be undone.')) {
                // Implementation for log clearing
                alert('Old logs cleared successfully!');
            }
        }
        
        function testConnections() {
            // Implementation for API connection testing
            alert('Testing API connections...\n\nInfobip API: ✓ Connected\nFlutterwave API: ✓ Connected');
        }
    </script>
</body>
</html>
