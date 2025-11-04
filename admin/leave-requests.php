<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';
require_once '../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is admin
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../employee/dashboard');
    exit();
}

$message = getFlashMessage();

// Handle leave request approval/rejection
if ($_POST && isset($_POST['action'])) {
    $requestId = intval($_POST['request_id']);
    $action = $_POST['action'];
    $rejectionReason = sanitizeInput($_POST['admin_comments'] ?? '');
    
    if (in_array($action, ['approve', 'reject']) && $requestId > 0) {
        try {
            // Get leave request details
            $stmt = $db->prepare("
                SELECT lr.*, u.phone, u.employee_number, ep.first_name, ep.last_name, lt.name as leave_type_name
                FROM leave_requests lr
                JOIN users u ON lr.employee_id = u.id
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id
                JOIN leave_types lt ON lr.leave_type_id = lt.id
                WHERE lr.id = ?
            ");
            $stmt->execute([$requestId]);
            $leaveRequest = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($leaveRequest) {
                $status = ($action === 'approve') ? 'approved' : 'rejected';
                
                // Update leave request
                $stmt = $db->prepare("
                    UPDATE leave_requests 
                    SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $_SESSION['user_id'], $rejectionReason, $requestId]);
                
                // Log activity
                logActivity($_SESSION['user_id'], 'leave_request_' . $status, 'leave_requests', $requestId);
                
                // Send SMS notification to employee
                try {
                    require_once '../includes/infobip.php';
                    $infobip = new InfobipAPI();
                    
                    if ($leaveRequest['phone']) {
                        $employeeName = trim($leaveRequest['first_name'] . ' ' . $leaveRequest['last_name']);
                        $statusText = ($status === 'approved') ? 'APPROVED' : 'REJECTED';
                        $startDate = date('M j, Y', strtotime($leaveRequest['start_date']));
                        $endDate = date('M j, Y', strtotime($leaveRequest['end_date']));
                        
                        $smsMessage = "Your leave request has been {$statusText}. " .
                                    "Type: {$leaveRequest['leave_type_name']}, " .
                                    "Duration: {$startDate} to {$endDate} ({$leaveRequest['total_days']} days).";
                        
                        if ($adminComments) {
                            $smsMessage .= " Comments: " . substr($adminComments, 0, 100);
                        }
                        
                        $smsMessage .= " - EP Portal";
                        
                        $infobip->sendSMS($leaveRequest['phone'], $smsMessage, SMS_SENDER_ID);
                    }
                    
                } catch (Exception $e) {
                    error_log("Leave approval SMS notification error: " . $e->getMessage());
                }
                
                $messageText = "Leave request " . $status . " successfully";
                redirectWithMessage('/admin/leave-requests.php', $messageText, 'success');
            } else {
                redirectWithMessage('/admin/leave-requests.php', 'Leave request not found', 'error');
            }
            
        } catch (Exception $e) {
            redirectWithMessage('/admin/leave-requests.php', 'Failed to update leave request: ' . $e->getMessage(), 'error');
        }
    }
}

// Get all leave requests
$stmt = $db->prepare("
    SELECT lr.*, u.employee_number, ep.first_name, ep.last_name, lt.name as leave_type_name,
           approver.employee_number as approver_number,
           approver_profile.first_name as approver_first_name,
           approver_profile.last_name as approver_last_name
    FROM leave_requests lr 
    JOIN users u ON lr.employee_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    JOIN leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users approver ON lr.approved_by = approver.id
    LEFT JOIN employee_profiles approver_profile ON approver.id = approver_profile.user_id
    ORDER BY lr.created_at DESC
");
$stmt->execute();
$leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                        <a class="nav-link active" href="leave-requests.php">
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
                    <h2 class="mb-4">Leave Requests Management</h2>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message['message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Leave Requests Table -->
                    <div class="card table-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Leave Type</th>
                                            <th>Duration</th>
                                            <th>Days</th>
                                            <th>Reason</th>
                                            <th>Status</th>
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
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($leaveRequests as $request): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?php echo $request['first_name'] . ' ' . $request['last_name']; ?></strong>
                                                        <br><small class="text-muted"><?php echo $request['employee_number']; ?></small>
                                                    </div>
                                                </td>
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
                                                    <?php if ($request['approved_by']): ?>
                                                        <br><small class="text-muted">by <?php echo $request['approver_first_name'] . ' ' . $request['approver_last_name']; ?></small>
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
                                                        <button class="btn btn-outline-success" onclick="approveLeaveRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger" onclick="rejectLeaveRequest(<?php echo $request['id']; ?>)">
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
    
    <!-- Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approvalModalTitle">Approve Leave Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="approvalForm">
                    <input type="hidden" name="request_id" id="requestId">
                    <input type="hidden" name="action" id="actionType">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Admin Comments</label>
                            <textarea class="form-control" name="admin_comments" rows="3" 
                                      placeholder="Optional comments about this decision..."></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            The employee will receive an SMS notification about this decision.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="submitBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewLeaveRequest(id) {
            fetch(`../api/get-leave-request?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showLeaveRequestModal(data.request);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while fetching leave request details.');
                });
        }

        function showLeaveRequestModal(request) {
            const modalHtml = `
                <div class="modal fade" id="viewLeaveRequestModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-calendar-alt me-2"></i>Leave Request Details
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Employee Information</h6>
                                        <p><strong>Name:</strong> ${request.first_name} ${request.last_name}</p>
                                        <p><strong>Employee Number:</strong> ${request.employee_number}</p>
                                        <p><strong>Department:</strong> ${request.department || 'N/A'}</p>
                                        <p><strong>Position:</strong> ${request.position || 'N/A'}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Leave Details</h6>
                                        <p><strong>Leave Type:</strong> ${request.leave_type_name}</p>
                                        <p><strong>Start Date:</strong> ${new Date(request.start_date).toLocaleDateString()}</p>
                                        <p><strong>End Date:</strong> ${new Date(request.end_date).toLocaleDateString()}</p>
                                        <p><strong>Total Days:</strong> ${request.total_days} days</p>
                                        <p><strong>Status:</strong> 
                                            <span class="badge bg-${request.status === 'pending' ? 'warning' : (request.status === 'approved' ? 'success' : 'danger')}">
                                                ${request.status.charAt(0).toUpperCase() + request.status.slice(1)}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6 class="text-muted mb-2">Reason for Leave</h6>
                                        <div class="alert alert-light">
                                            ${request.reason}
                                        </div>
                                    </div>
                                </div>
                                ${request.rejection_reason ? `
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <h6 class="text-muted mb-2">Admin Comments</h6>
                                            <div class="alert alert-info">
                                                ${request.rejection_reason}
                                            </div>
                                        </div>
                                    </div>
                                ` : ''}
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <p><strong>Submitted:</strong> ${new Date(request.created_at).toLocaleString()}</p>
                                    </div>
                                    <div class="col-md-6">
                                        ${request.approved_at ? `<p><strong>Processed:</strong> ${new Date(request.approved_at).toLocaleString()}</p>` : ''}
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                ${request.status === 'pending' ? `
                                    <button type="button" class="btn btn-success" onclick="approveLeaveRequest(${request.id})" data-bs-dismiss="modal">
                                        <i class="fas fa-check me-2"></i>Approve
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="rejectLeaveRequest(${request.id})" data-bs-dismiss="modal">
                                        <i class="fas fa-times me-2"></i>Reject
                                    </button>
                                ` : ''}
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal
            const existingModal = document.getElementById('viewLeaveRequestModal');
            if (existingModal) existingModal.remove();
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('viewLeaveRequestModal'));
            modal.show();
            
            // Auto-remove modal after it's hidden
            modal._element.addEventListener('hidden.bs.modal', () => {
                modal._element.remove();
            });
        }
        
        function approveLeaveRequest(id) {
            document.getElementById('approvalModalTitle').textContent = 'Approve Leave Request';
            document.getElementById('requestId').value = id;
            document.getElementById('actionType').value = 'approve';
            document.getElementById('submitBtn').className = 'btn btn-success';
            document.getElementById('submitBtn').textContent = 'Approve';
            
            new bootstrap.Modal(document.getElementById('approvalModal')).show();
        }
        
        function rejectLeaveRequest(id) {
            document.getElementById('approvalModalTitle').textContent = 'Reject Leave Request';
            document.getElementById('requestId').value = id;
            document.getElementById('actionType').value = 'reject';
            document.getElementById('submitBtn').className = 'btn btn-danger';
            document.getElementById('submitBtn').textContent = 'Reject';
            
            new bootstrap.Modal(document.getElementById('approvalModal')).show();
        }
    </script>
</body>
</html>
