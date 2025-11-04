<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is employee
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../admin/dashboard');
    exit();
}

$userId = $_SESSION['user_id'];
$message = getFlashMessage();

// Handle leave request submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create') {
    $leaveTypeId = intval($_POST['leave_type_id']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $reason = sanitizeInput($_POST['reason']);
    
    $errors = [];
    
    if (empty($leaveTypeId) || empty($startDate) || empty($endDate) || empty($reason)) {
        $errors[] = 'Please fill in all required fields';
    }
    
    if (strtotime($startDate) < strtotime(date('Y-m-d'))) {
        $errors[] = 'Start date cannot be in the past';
    }
    
    if (strtotime($endDate) < strtotime($startDate)) {
        $errors[] = 'End date must be after start date';
    }
    
    if (empty($errors)) {
        $totalDays = calculateWorkingDays($startDate, $endDate);
        
        try {
            $stmt = $db->prepare("
                INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, total_days, reason) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $leaveTypeId, $startDate, $endDate, $totalDays, $reason]);
            
            logActivity($userId, 'leave_request_created', 'leave_requests', $db->lastInsertId());
            
            // Send notifications to admin and employee
            try {
                require_once '../includes/infobip.php';
                $infobip = new InfobipAPI();
                
                // Get admin details
                $stmt = $db->prepare("SELECT email, phone FROM users WHERE role = 'admin' LIMIT 1");
                $stmt->execute();
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get employee details
                $stmt = $db->prepare("SELECT phone FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Send email to admin
                if ($admin && $admin['email']) {
                    $subject = 'New Leave Request - EP Portal';
                    $emailMessage = "
                        <h2>New Leave Request</h2>
                        <p>Employee: {$_SESSION['user_name']}</p>
                        <p>Employee ID: {$_SESSION['employee_number']}</p>
                        <p>Leave Type: " . getLeaveTypeName($leaveTypeId) . "</p>
                        <p>Duration: $startDate to $endDate ($totalDays days)</p>
                        <p>Reason: $reason</p>
                        <p>Please review and approve/reject this request.</p>
                    ";
                    $infobip->sendEmail($admin['email'], $subject, $emailMessage);
                }
                
                // Send SMS to admin
                if ($admin && $admin['phone']) {
                    $adminSmsMessage = "New leave request from {$_SESSION['user_name']} ({$_SESSION['employee_number']}). " . 
                                     getLeaveTypeName($leaveTypeId) . " from $startDate to $endDate ($totalDays days). " .
                                     "Please review in EP Portal.";
                    $infobip->sendSMS($admin['phone'], $adminSmsMessage, SMS_SENDER_ID);
                }
                
                // Send SMS confirmation to employee
                if ($employee && $employee['phone']) {
                    $employeeSmsMessage = "Your leave request has been submitted successfully. " .
                                        "Type: " . getLeaveTypeName($leaveTypeId) . ", " .
                                        "Duration: $startDate to $endDate ($totalDays days). " .
                                        "You will be notified once reviewed. - EP Portal";
                    $infobip->sendSMS($employee['phone'], $employeeSmsMessage, SMS_SENDER_ID);
                }
                
            } catch (Exception $e) {
                // Notification sending failed, but leave request was created
                error_log("Leave request notification error: " . $e->getMessage());
            }
            
            redirectWithMessage('leave-requests.php', 'Leave request submitted successfully', 'success');
        } catch (Exception $e) {
            $errors[] = 'Failed to submit leave request: ' . $e->getMessage();
        }
    }
}

// Get leave types
$stmt = $db->prepare("SELECT * FROM leave_types ORDER BY name");
$stmt->execute();
$leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employee's leave requests
$stmt = $db->prepare("
    SELECT lr.*, lt.name as leave_type_name, 
           approver.employee_number as approver_number,
           approver_profile.first_name as approver_first_name,
           approver_profile.last_name as approver_last_name
    FROM leave_requests lr 
    JOIN leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users approver ON lr.approved_by = approver.id
    LEFT JOIN employee_profiles approver_profile ON approver.id = approver_profile.user_id
    WHERE lr.employee_id = ?
    ORDER BY lr.created_at DESC
");
$stmt->execute([$userId]);
$leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getLeaveTypeName($id) {
    global $db;
    $stmt = $db->prepare("SELECT name FROM leave_types WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Leave Requests - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #e0e0e0;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e1e1e 0%, #2a2a2a 100%);
            box-shadow: 2px 0 5px rgba(0,0,0,0.3);
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
        .leave-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .leave-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .btn-primary {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            border: none;
        }
        .modal-content {
            background-color: #2a2a2a;
            border: 1px solid #404040;
        }
        .modal-header {
            border-bottom: 1px solid #404040;
        }
        .modal-footer {
            border-top: 1px solid #404040;
        }
        .form-control, .form-select {
            background-color: #3a3a3a;
            border: 1px solid #555;
            color: #e0e0e0;
        }
        .form-control:focus, .form-select:focus {
            background-color: #3a3a3a;
            border-color: #4a90e2;
            color: #e0e0e0;
            box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
        }
        .form-label {
            color: #e0e0e0;
        }
        .alert-success {
            background-color: #1e4d2b;
            border-color: #2d5a3d;
            color: #a3d9a5;
        }
        .alert-danger {
            background-color: #4d1e1e;
            border-color: #5a2d2d;
            color: #d9a3a3;
        }
        .alert-info {
            background-color: #1e3a4d;
            border-color: #2d4d5a;
            color: #a3c9d9;
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
                        <small>Employee Panel</small>
                    </div>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user me-2"></i>My Profile
                        </a>
                        <a class="nav-link active" href="leave-requests.php">
                            <i class="fas fa-calendar-alt me-2"></i>Leave Requests
                        </a>
                        <a class="nav-link" href="tasks.php">
                            <i class="fas fa-tasks me-2"></i>My Tasks
                        </a>
                        <a class="nav-link" href="tickets.php">
                            <i class="fas fa-ticket-alt me-2"></i>Support Tickets
                        </a>
                        <a class="nav-link" href="withdrawal-salary.php">
                            <i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawal
                        </a>
                        <a class="nav-link" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="reminders.php">
                            <i class="fas fa-bell me-2"></i>Reminders
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <div class="text-white-50 small">
                            <i class="fas fa-user me-2"></i><?php echo $_SESSION['user_name']; ?>
                        </div>
                        <div class="text-white-50 small">
                            <i class="fas fa-id-badge me-2"></i><?php echo $_SESSION['employee_number']; ?>
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
                        <h2>Leave Requests</h2>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createLeaveModal">
                            <i class="fas fa-plus me-2"></i>Request Leave
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
                    
                    <!-- Leave Requests Table -->
                    <div class="card table-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-dark table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Leave Type</th>
                                            <th>Duration</th>
                                            <th>Days</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Approved By</th>
                                            <th>Submitted</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($leaveRequests)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">No leave requests found</p>
                                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createLeaveModal">
                                                    <i class="fas fa-plus me-2"></i>Submit Your First Request
                                                </button>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($leaveRequests as $request): ?>
                                            <tr>
                                                <td>
                                                    <i class="fas fa-calendar me-2"></i><?php echo $request['leave_type_name']; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($request['start_date'])); ?><br>
                                                    <small class="text-muted">to <?php echo date('M j, Y', strtotime($request['end_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $request['total_days']; ?> days</span>
                                                </td>
                                                <td>
                                                    <div style="max-width: 200px;">
                                                        <?php echo strlen($request['reason']) > 50 ? substr($request['reason'], 0, 50) . '...' : $request['reason']; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'approved' ? 'success' : 'danger'); ?>">
                                                        <?php echo ucfirst($request['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($request['approved_by']): ?>
                                                        <?php echo $request['approver_first_name'] . ' ' . $request['approver_last_name']; ?>
                                                        <br><small class="text-muted"><?php echo date('M j, Y', strtotime($request['approved_at'])); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-outline-primary" onclick="viewLeaveRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($request['status'] === 'pending'): ?>
                                                        <button class="btn btn-outline-danger" onclick="cancelLeaveRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Leave Request Modal -->
    <div class="modal fade" id="viewLeaveModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Leave Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="leaveDetailsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Leave Request Modal -->
    <div class="modal fade" id="createLeaveModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Leave Type *</label>
                                    <select class="form-select" name="leave_type_id" required>
                                        <option value="">Select leave type</option>
                                        <?php foreach ($leaveTypes as $type): ?>
                                            <option value="<?php echo $type['id']; ?>">
                                                <?php echo $type['name']; ?> (Max: <?php echo $type['max_days_per_year']; ?> days/year)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Total Days</label>
                                    <input type="number" class="form-control" id="totalDays" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Start Date *</label>
                                    <input type="date" class="form-control" name="start_date" id="startDate" 
                                           min="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">End Date *</label>
                                    <input type="date" class="form-control" name="end_date" id="endDate" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason *</label>
                            <textarea class="form-control" name="reason" rows="4" 
                                      placeholder="Please provide a detailed reason for your leave request..." required></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Your leave request will be sent to your supervisor for approval. 
                            You will receive a notification once it's reviewed.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate working days
        function calculateWorkingDays(startDate, endDate) {
            let start = new Date(startDate);
            let end = new Date(endDate);
            let workingDays = 0;
            
            while (start <= end) {
                let dayOfWeek = start.getDay();
                if (dayOfWeek !== 0 && dayOfWeek !== 6) { // Not Sunday (0) or Saturday (6)
                    workingDays++;
                }
                start.setDate(start.getDate() + 1);
            }
            
            return workingDays;
        }
        
        // Update total days when dates change
        document.getElementById('startDate').addEventListener('change', updateTotalDays);
        document.getElementById('endDate').addEventListener('change', updateTotalDays);
        
        function updateTotalDays() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (startDate && endDate) {
                const totalDays = calculateWorkingDays(startDate, endDate);
                document.getElementById('totalDays').value = totalDays;
            }
        }
        
        // Set minimum end date when start date changes
        document.getElementById('startDate').addEventListener('change', function() {
            document.getElementById('endDate').min = this.value;
        });
        
        function viewLeaveRequest(id) {
            const modal = new bootstrap.Modal(document.getElementById('viewLeaveModal'));
            const content = document.getElementById('leaveDetailsContent');
            
            // Show loading spinner
            content.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            // Fetch leave request details
            fetch('get-leave-details?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const leave = data.leave;
                        const statusColor = leave.status === 'pending' ? 'warning' : 
                                          (leave.status === 'approved' ? 'success' : 'danger');
                        
                        content.innerHTML = `
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-dark border-secondary mb-3">
                                        <div class="card-header">
                                            <h6 class="mb-0"><i class="fas fa-calendar me-2"></i>Leave Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <p><strong>Leave Type:</strong> ${leave.leave_type_name}</p>
                                            <p><strong>Start Date:</strong> ${new Date(leave.start_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                                            <p><strong>End Date:</strong> ${new Date(leave.end_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                                            <p><strong>Total Days:</strong> <span class="badge bg-info">${leave.total_days} days</span></p>
                                            <p><strong>Status:</strong> <span class="badge bg-${statusColor}">${leave.status.charAt(0).toUpperCase() + leave.status.slice(1)}</span></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-dark border-secondary mb-3">
                                        <div class="card-header">
                                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Request Details</h6>
                                        </div>
                                        <div class="card-body">
                                            <p><strong>Submitted:</strong> ${new Date(leave.created_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'})}</p>
                                            ${leave.approved_by ? `
                                                <p><strong>Reviewed By:</strong> ${leave.approver_first_name} ${leave.approver_last_name}</p>
                                                <p><strong>Reviewed On:</strong> ${new Date(leave.approved_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                                            ` : '<p><strong>Status:</strong> Pending review</p>'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card bg-dark border-secondary">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-comment me-2"></i>Reason</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-0">${leave.reason}</p>
                                </div>
                            </div>
                            ${leave.rejection_reason ? `
                                <div class="card bg-dark border-secondary mt-3">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="fas fa-user-shield me-2"></i>Admin Comments</h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-0">${leave.rejection_reason}</p>
                                    </div>
                                </div>
                            ` : ''}
                        `;
                    } else {
                        content.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error loading leave request details: ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Failed to load leave request details. Please try again.
                        </div>
                    `;
                });
        }
        
        function cancelLeaveRequest(id) {
            if (confirm('Are you sure you want to cancel this leave request?')) {
                // Implement cancel leave request
                alert('Cancel leave request - ID: ' + id);
            }
        }
    </script>
</body>
</html>
