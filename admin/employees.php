<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';
require_once '../config/config.php';

// Start secure session first
startSecureSession();
requireAdmin();

$message = getFlashMessage();

// Handle employee creation
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create') {
    $firstName = sanitizeInput($_POST['first_name']);
    $lastName = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = formatPhoneNumber(sanitizeInput($_POST['phone']));
    $department = sanitizeInput($_POST['department']);
    $position = sanitizeInput($_POST['position']);
    $salary = floatval($_POST['salary']);
    $hireDate = $_POST['hire_date'];
    
    $errors = [];
    
    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
        $errors[] = 'Please fill in all required fields';
    }
    
    if (!isValidEmail($email)) {
        $errors[] = 'Please enter a valid email address';
    }
    
    if (!isValidPhone($phone)) {
        $errors[] = 'Please enter a valid phone number';
    }
    
    // Check if email or phone already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$email, $phone]);
    if ($stmt->fetch()) {
        $errors[] = 'Email or phone number already exists';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Generate employee number
            do {
                $employeeNumber = generateEmployeeNumber();
                $stmt = $db->prepare("SELECT id FROM users WHERE employee_number = ?");
                $stmt->execute([$employeeNumber]);
            } while ($stmt->fetch());
            
            // Generate temporary password
            $tempPassword = generateSecureToken(8);
            $hashedPassword = hashPassword($tempPassword);
            
            // Create user account
            $stmt = $db->prepare("
                INSERT INTO users (employee_number, email, phone, password_hash, role, status) 
                VALUES (?, ?, ?, ?, 'employee', 'active')
            ");
            $stmt->execute([$employeeNumber, $email, $phone, $hashedPassword]);
            $userId = $db->lastInsertId();
            
            // Create employee profile
            $stmt = $db->prepare("
                INSERT INTO employee_profiles (user_id, first_name, last_name, department, position, hire_date, monthly_salary) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $firstName, $lastName, $department, $position, $hireDate, $salary]);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'employee_created', 'users', $userId);
            
            $db->commit();
            
            // Send welcome notifications (Email + SMS)
            $notificationsSent = [];
            
            // Send welcome email
            try {
                require_once '../includes/infobip.php';
                $infobip = new InfobipAPI();
                $subject = 'Welcome to AppNomu SalesQ Employee Portal - Your Account Details';
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
<!-- Header -->
<tr><td style='background-color:#1e88e5;padding:20px;text-align:center;'>
<h1 style='margin:0;color:#ffffff;font-size:20px;font-weight:600;'>Welcome to AppNomu</h1>
<p style='margin:5px 0 0 0;color:#bbdefb;font-size:13px;'>Employee Portal</p>
</td></tr>
<!-- Content -->
<tr><td style='padding:30px 20px;'>
<p style='margin:0 0 15px 0;color:#333;font-size:15px;'>Dear <strong>$firstName $lastName</strong>,</p>
<p style='margin:0 0 20px 0;color:#555;font-size:14px;line-height:1.5;'>Your employee account has been created. Welcome to the team!</p>
<table width='100%' cellpadding='15' cellspacing='0' border='0' style='background-color:#e3f2fd;border-left:4px solid #1e88e5;margin:20px 0;'>
<tr><td>
<p style='margin:0 0 12px 0;color:#1e88e5;font-size:13px;font-weight:bold;'>Your Login Credentials</p>
<p style='margin:0 0 8px 0;color:#666;font-size:11px;'>EMPLOYEE NUMBER</p>
<p style='margin:0 0 15px 0;color:#1e88e5;font-size:16px;font-weight:bold;font-family:monospace;'>$employeeNumber</p>
<p style='margin:0 0 8px 0;color:#666;font-size:11px;'>EMAIL</p>
<p style='margin:0 0 15px 0;color:#1e88e5;font-size:14px;font-weight:bold;word-break:break-all;'>$email</p>
<p style='margin:0 0 8px 0;color:#666;font-size:11px;'>PASSWORD</p>
<p style='margin:0;color:#1e88e5;font-size:16px;font-weight:bold;font-family:monospace;word-break:break-all;'>$tempPassword</p>
</td></tr>
</table>
<table width='100%' cellpadding='12' cellspacing='0' border='0' style='background-color:#fff3e0;border-left:3px solid #ff9800;margin:20px 0;'>
<tr><td>
<p style='margin:0 0 5px 0;color:#e65100;font-size:12px;font-weight:bold;'>Important:</p>
<p style='margin:0;color:#555;font-size:12px;line-height:1.5;'>This is your permanent password. Keep it secure.</p>
</td></tr>
</table>
<table width='100%' cellpadding='12' cellspacing='0' border='0' style='background-color:#f1f8e9;border-left:3px solid #8bc34a;margin:20px 0;'>
<tr><td style='text-align:center;'>
<p style='margin:0 0 5px 0;color:#558b2f;font-size:12px;font-weight:bold;'>Need Help?</p>
<p style='margin:0;color:#558b2f;font-size:16px;font-weight:bold;'>+256 200 948 420</p>
<p style='margin:5px 0 0 0;color:#689f38;font-size:11px;'>Mon-Fri, 8AM-6PM</p>
</td></tr>
</table>
</td></tr>
<!-- Footer -->
<tr><td style='background-color:#263238;padding:25px 20px;text-align:center;'>
<p style='margin:0 0 10px 0;color:#ffffff;font-size:15px;font-weight:bold;'>AppNomu SalesQ</p>
<p style='margin:0 0 15px 0;color:#90a4ae;font-size:12px;line-height:1.5;'>77 Market Street, Bugiri Municipality, Uganda</p>
<table width='100%' cellpadding='10' cellspacing='0' border='0'>
<tr><td align='center'>
<a href='https://www.facebook.com/appnomu' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#3b5998;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>f</a>
<a href='https://x.com/appnomuSalesQ' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#1da1f2;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>X</a>
<a href='https://www.linkedin.com/company/our-appnomu/' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#0077b5;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:12px;'>in</a>
<a href='https://www.youtube.com/@AppNomusalesQ' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#ff0000;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>▶</a>
<a href='https://www.instagram.com/myappnomu' style='display:inline-block;width:32px;height:32px;line-height:32px;background-color:#e4405f;color:#ffffff;text-decoration:none;border-radius:50%;margin:0 4px;font-size:14px;'>📷</a>
</td></tr>
</table>
<p style='margin:15px 0 0 0;color:#78909c;font-size:11px;'>© 2025 AppNomu SalesQ. All rights reserved.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
                ";
                $emailResult = $infobip->sendEmail($email, $subject, $message);
                if ($emailResult) {
                    $notificationsSent[] = 'Email';
                }
            } catch (Exception $e) {
                error_log("Welcome email failed: " . $e->getMessage());
            }
            
            // Send welcome SMS
            try {
                $smsMessage = "Dear $firstName $lastName,\nThanks for joining AppNomu SalesQ team. Your login details:\nEmployee #: $employeeNumber\nPassword: $tempPassword\nKeep it secure.\nCall +256200948420 for Help\n- AppNomu Team";
                $smsResult = $infobip->sendSMS($phone, $smsMessage);
                if ($smsResult) {
                    $notificationsSent[] = 'SMS';
                }
            } catch (Exception $e) {
                error_log("Welcome SMS failed: " . $e->getMessage());
            }
            
            // WhatsApp notifications removed due to template approval challenges
            // Focus on SMS and Email for reliable delivery
            
            $successMessage = 'Employee created successfully! Temporary password: ' . $tempPassword;
            if (!empty($notificationsSent)) {
                $successMessage .= ' | Notifications sent: ' . implode(', ', $notificationsSent);
            }
            redirectWithMessage('employees.php', $successMessage, 'success');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to create employee: ' . $e->getMessage();
        }
    }
}

// Handle employee edit
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $employeeId = intval($_POST['employee_id']);
    $firstName = sanitizeInput($_POST['first_name']);
    $lastName = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = formatPhoneNumber(sanitizeInput($_POST['phone']));
    $department = sanitizeInput($_POST['department']);
    $position = sanitizeInput($_POST['position']);
    $salary = floatval($_POST['salary']);
    $hireDate = $_POST['hire_date'];
    $status = sanitizeInput($_POST['status']);
    
    $errors = [];
    
    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
        $errors[] = 'Please fill in all required fields';
    }
    
    if (!isValidEmail($email)) {
        $errors[] = 'Please enter a valid email address';
    }
    
    if (!isValidPhone($phone)) {
        $errors[] = 'Please enter a valid phone number';
    }
    
    // Check if email or phone already exists (excluding current employee)
    $stmt = $db->prepare("SELECT id FROM users WHERE (email = ? OR phone = ?) AND id != ?");
    $stmt->execute([$email, $phone, $employeeId]);
    if ($stmt->fetch()) {
        $errors[] = 'Email or phone number already exists';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Update user account
            $stmt = $db->prepare("
                UPDATE users 
                SET email = ?, phone = ?, status = ?
                WHERE id = ? AND role = 'employee'
            ");
            $stmt->execute([$email, $phone, $status, $employeeId]);
            
            // Update employee profile
            $stmt = $db->prepare("
                UPDATE employee_profiles 
                SET first_name = ?, last_name = ?, department = ?, position = ?, hire_date = ?, monthly_salary = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$firstName, $lastName, $department, $position, $hireDate, $salary, $employeeId]);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'employee_updated', 'users', $employeeId);
            
            $db->commit();
            
            redirectWithMessage('employees.php', 'Employee updated successfully', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to update employee: ' . $e->getMessage();
        }
    }
}

// Handle employee status update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $employeeId = intval($_POST['employee_id']);
    $newStatus = sanitizeInput($_POST['status']);
    
    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'employee'");
    $stmt->execute([$newStatus, $employeeId]);
    
    logActivity($_SESSION['user_id'], 'employee_status_updated', 'users', $employeeId);
    
    redirectWithMessage('employees.php', 'Employee status updated successfully', 'success');
}

// Get employees list
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$department = $_GET['department'] ?? '';

$whereClause = "WHERE u.role = 'employee'";
$params = [];

if ($search) {
    $whereClause .= " AND (ep.first_name LIKE ? OR ep.last_name LIKE ? OR u.email LIKE ? OR u.employee_number LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status) {
    $whereClause .= " AND u.status = ?";
    $params[] = $status;
}

if ($department) {
    $whereClause .= " AND ep.department = ?";
    $params[] = $department;
}

$stmt = $db->prepare("
    SELECT u.*, ep.first_name, ep.last_name, ep.department, ep.position, ep.hire_date, ep.salary, ep.monthly_salary, ep.profile_picture
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    $whereClause
    ORDER BY ep.first_name, ep.last_name
");
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$stmt = $db->prepare("SELECT DISTINCT department FROM employee_profiles WHERE department IS NOT NULL ORDER BY department");
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Employees - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
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
        .table-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        .employee-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
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
                        <a class="nav-link active" href="employees.php">
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
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-2"></i>Settings
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <div class="text-white-50 small">
                            <i class="fas fa-user me-2"></i><?php echo $_SESSION['user_name']; ?>
                        </div>
                        <a href="../auth/logout.php" class="nav-link text-white-50">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2>Employees</h2>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createEmployeeModal">
                            <i class="fas fa-plus me-2"></i>Add Employee
                        </button>
                    </div>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message['message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($errors) && !empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo $error; ?></div>
                            <?php endforeach; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filters -->
                    <div class="card table-card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <input type="text" class="form-control" name="search" placeholder="Search employees..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="status">
                                        <option value="">All Status</option>
                                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="department">
                                        <option value="">All Departments</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept; ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                                                <?php echo $dept; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Employees Table -->
                    <div class="card table-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Employee Number</th>
                                            <th>Contact</th>
                                            <th>Department</th>
                                            <th>Position</th>
                                            <th>Hire Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $employee): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if (!empty($employee['profile_picture']) && file_exists(__DIR__ . '/../uploads/' . $employee['profile_picture'])): ?>
                                                        <img src="../uploads/<?php echo htmlspecialchars($employee['profile_picture']); ?>" 
                                                             alt="Profile Picture" class="employee-avatar me-3" style="object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="employee-avatar me-3">
                                                            <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="fw-bold"><?php echo $employee['first_name'] . ' ' . $employee['last_name']; ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><code><?php echo $employee['employee_number']; ?></code></td>
                                            <td>
                                                <div><?php echo $employee['email']; ?></div>
                                                <small class="text-muted"><?php echo $employee['phone']; ?></small>
                                            </td>
                                            <td><?php echo $employee['department'] ?? '-'; ?></td>
                                            <td><?php echo $employee['position'] ?? '-'; ?></td>
                                            <td><?php echo $employee['hire_date'] ? date('M j, Y', strtotime($employee['hire_date'])) : '-'; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $employee['status'] === 'active' ? 'success' : ($employee['status'] === 'inactive' ? 'secondary' : 'danger'); ?>">
                                                    <?php echo ucfirst($employee['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="viewEmployee(<?php echo $employee['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-outline-warning" onclick="editEmployee(<?php echo $employee['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-outline-danger" onclick="resetEmployee(<?php echo $employee['id']; ?>)">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Employee Modal -->
    <div class="modal fade" id="createEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone *</label>
                                    <input type="tel" class="form-control" name="phone" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Department</label>
                                    <input type="text" class="form-control" name="department">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Position</label>
                                    <input type="text" class="form-control" name="position">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Hire Date</label>
                                    <input type="date" class="form-control" name="hire_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Salary</label>
                                    <input type="number" class="form-control" name="salary" step="0.01" min="0">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Employee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewEmployee(id) {
            // Fetch employee details and show in modal
            fetch(`../api/get-employee-clean?id=${id}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showEmployeeModal(data.employee);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Error fetching employee details: ' + error.message);
                });
        }
        
        function showEmployeeModal(employee) {
            const modalHtml = `
                <div class="modal fade" id="viewEmployeeModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Employee Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-3 text-center">
                                        ${employee.profile_picture ? 
                                            `<img src="../uploads/${employee.profile_picture}" alt="Profile Picture" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #dee2e6;">` : 
                                            `<div class="rounded-circle mb-3 mx-auto d-flex align-items-center justify-content-center" style="width: 120px; height: 120px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-size: 2rem; font-weight: bold;">
                                                ${employee.first_name.charAt(0)}${employee.last_name.charAt(0)}
                                            </div>`
                                        }
                                        <h5 class="mb-1">${employee.first_name} ${employee.last_name}</h5>
                                        <p class="text-muted mb-0">${employee.employee_number}</p>
                                        <span class="badge bg-${employee.status === 'active' ? 'success' : employee.status === 'inactive' ? 'secondary' : 'danger'} mt-2">
                                            ${employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                                        </span>
                                    </div>
                                    <div class="col-md-9">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Email</label>
                                                    <p class="form-control-plaintext">${employee.email}</p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Phone</label>
                                                    <p class="form-control-plaintext">${employee.phone}</p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Department</label>
                                                    <p class="form-control-plaintext">${employee.department || '-'}</p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Position</label>
                                                    <p class="form-control-plaintext">${employee.position || '-'}</p>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Hire Date</label>
                                                    <p class="form-control-plaintext">${employee.hire_date ? new Date(employee.hire_date).toLocaleDateString() : '-'}</p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Date of Birth</label>
                                                    <p class="form-control-plaintext">${employee.date_of_birth ? new Date(employee.date_of_birth).toLocaleDateString() : '-'}</p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Gender</label>
                                                    <p class="form-control-plaintext">${employee.gender ? employee.gender.charAt(0).toUpperCase() + employee.gender.slice(1) : '-'}</p>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Monthly Salary</label>
                                                    <p class="form-control-plaintext">${employee.salary ? 'UGX ' + parseFloat(employee.salary).toLocaleString() : '-'}</p>
                                                </div>
                                            </div>
                                        </div>
                                        ${employee.address ? `
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Address</label>
                                            <p class="form-control-plaintext">${employee.address}</p>
                                        </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-warning" onclick="editEmployee(${employee.id})">
                                    <i class="fas fa-edit me-2"></i>Edit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('viewEmployeeModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('viewEmployeeModal'));
            modal.show();
        }
        
        function editEmployee(id) {
            // Fetch employee details and show edit modal
            fetch(`../api/get-employee-clean?id=${id}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showEditEmployeeModal(data.employee);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Error fetching employee details: ' + error.message);
                });
        }
        
        function showEditEmployeeModal(employee) {
            const modalHtml = `
                <div class="modal fade" id="editEmployeeModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Employee - ${employee.first_name} ${employee.last_name}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="editEmployeeForm">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="employee_id" value="${employee.id}">
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">First Name *</label>
                                                <input type="text" class="form-control" name="first_name" value="${employee.first_name || ''}" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Last Name *</label>
                                                <input type="text" class="form-control" name="last_name" value="${employee.last_name || ''}" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email *</label>
                                                <input type="email" class="form-control" name="email" value="${employee.email || ''}" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Phone *</label>
                                                <input type="tel" class="form-control" name="phone" value="${employee.phone || ''}" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Department</label>
                                                <input type="text" class="form-control" name="department" value="${employee.department || ''}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Position</label>
                                                <input type="text" class="form-control" name="position" value="${employee.position || ''}">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Hire Date</label>
                                                <input type="date" class="form-control" name="hire_date" value="${employee.hire_date || ''}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Monthly Salary</label>
                                                <input type="number" class="form-control" name="salary" step="0.01" min="0" value="${employee.salary || ''}">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" name="status">
                                                    <option value="active" ${employee.status === 'active' ? 'selected' : ''}>Active</option>
                                                    <option value="inactive" ${employee.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                                    <option value="suspended" ${employee.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-save me-2"></i>Update Employee
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('editEmployeeModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
            modal.show();
        }
        
        function resetEmployee(id) {
            if (confirm('Are you sure you want to reset this employee\'s password? A new temporary password will be generated and sent via SMS and Email.')) {
                // Show loading state
                const loadingModal = showLoadingModal('Resetting password and sending notifications...');
                
                fetch('../api/reset-employee-password', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '<?php echo $_SESSION['csrf_token']; ?>'
                    },
                    body: JSON.stringify({ employee_id: id })
                })
                .then(response => response.json())
                .then(data => {
                    loadingModal.hide();
                    if (data.success) {
                        showSuccessModal(data.employee_name, data.sms_sent, data.email_sent, data.notifications_sent);
                    } else {
                        showErrorModal(data.message);
                    }
                })
                .catch(error => {
                    loadingModal.hide();
                    console.error('Error:', error);
                    showErrorModal('An error occurred while resetting the password.');
                });
            }
        }

        function showLoadingModal(message) {
            const modalHtml = `
                <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-body text-center p-4">
                                <div class="spinner-border text-primary mb-3" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <h5>${message}</h5>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('loadingModal'));
            modal.show();
            return modal;
        }

        function showSuccessModal(employeeName, smsSent, emailSent, notificationsSent) {
            const modalHtml = `
                <div class="modal fade" id="successModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-check-circle me-2"></i>Password Reset Successful
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="text-center mb-3">
                                    <i class="fas fa-user-check text-success" style="font-size: 3rem;"></i>
                                </div>
                                <h6 class="text-center mb-3">Password reset completed for:</h6>
                                <div class="alert alert-success text-center">
                                    <strong>${employeeName}</strong>
                                </div>
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-paper-plane me-2"></i>Notifications Sent:</h6>
                                    <p class="mb-1"><strong>${notificationsSent}</strong></p>
                                    ${smsSent ? '<span class="badge bg-success me-2"><i class="fas fa-sms me-1"></i>SMS Delivered</span>' : '<span class="badge bg-warning me-2"><i class="fas fa-sms me-1"></i>SMS Failed</span>'}
                                    ${emailSent ? '<span class="badge bg-success"><i class="fas fa-envelope me-1"></i>Email Delivered</span>' : '<span class="badge bg-warning"><i class="fas fa-envelope me-1"></i>Email Failed</span>'}
                                </div>
                                ${(!smsSent && !emailSent) ? 
                                    '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>All notifications failed. Please contact the employee directly with their new password.</div>' :
                                    '<div class="alert alert-success"><i class="fas fa-check me-2"></i>Employee has been notified of their new password via the successful delivery methods above.</div>'
                                }
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                                    <i class="fas fa-check me-2"></i>Done
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove loading modal
            const loadingModal = document.getElementById('loadingModal');
            if (loadingModal) loadingModal.remove();
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('successModal'));
            modal.show();
            
            // Auto-remove modal after it's hidden
            modal._element.addEventListener('hidden.bs.modal', () => {
                modal._element.remove();
            });
        }

        function showErrorModal(message) {
            const modalHtml = `
                <div class="modal fade" id="errorModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Password Reset Failed
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="text-center mb-3">
                                    <i class="fas fa-times-circle text-danger" style="font-size: 3rem;"></i>
                                </div>
                                <div class="alert alert-danger">
                                    <strong>Error:</strong> ${message}
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-2"></i>Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove loading modal
            const loadingModal = document.getElementById('loadingModal');
            if (loadingModal) loadingModal.remove();
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('errorModal'));
            modal.show();
            
            // Auto-remove modal after it's hidden
            modal._element.addEventListener('hidden.bs.modal', () => {
                modal._element.remove();
            });
        }
    </script>
</body>
</html>
