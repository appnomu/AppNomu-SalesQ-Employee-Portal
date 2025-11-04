<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Handle salary allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'allocate_salary') {
        $employeeId = (int)$_POST['employee_id'];
        $amount = (float)$_POST['amount'];
        $type = $_POST['allocation_type'];
        $notes = $_POST['notes'] ?? '';
        $period = date('Y-m');
        
        try {
            // Get employee details and monthly salary
            $stmt = $db->prepare("
                SELECT u.phone, ep.first_name, ep.last_name, ep.monthly_salary 
                FROM users u 
                JOIN employee_profiles ep ON u.id = ep.user_id 
                WHERE u.id = ?
            ");
            $stmt->execute([$employeeId]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Validate allocation amount for monthly salary type
            if ($type === 'monthly' && $amount > $employee['monthly_salary']) {
                $_SESSION['error_message'] = "Cannot allocate UGX " . number_format($amount) . ". Employee's monthly salary is only UGX " . number_format($employee['monthly_salary']);
                header('Location: salary-management.php');
                exit();
            }
            
            $db->beginTransaction();
            
            // Insert salary allocation record
            $stmt = $db->prepare("
                INSERT INTO salary_allocations (employee_id, period, allocated_amount, allocation_type, allocated_by, notes)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    allocated_amount = allocated_amount + VALUES(allocated_amount),
                    allocation_date = CURRENT_TIMESTAMP,
                    notes = CONCAT(COALESCE(notes, ''), '\n', VALUES(notes))
            ");
            $stmt->execute([$employeeId, $period, $amount, $type, $_SESSION['user_id'], $notes]);
            
            // Update employee profile
            $stmt = $db->prepare("
                UPDATE employee_profiles 
                SET period_allocated_amount = period_allocated_amount + ?,
                    current_period = ?,
                    last_salary_reset = CURDATE(),
                    salary_status = CASE 
                        WHEN withdrawn_amount >= (period_allocated_amount + ?) THEN 'exhausted'
                        WHEN withdrawn_amount > 0 THEN 'partial'
                        ELSE 'allocated'
                    END
                WHERE user_id = ?
            ");
            $stmt->execute([$amount, $period, $amount, $employeeId]);
            
            $db->commit();
            
            // Send SMS and WhatsApp notifications
            try {
                require_once '../includes/infobip.php';
                $infobip = new InfobipAPI();
                
                $employeeName = $employee['first_name'] . ' ' . $employee['last_name'];
                $formattedAmount = number_format($amount);
                $allocationTypeText = ucfirst($type);
                
                // SMS Notification
                $smsMessage = "Hello {$employee['first_name']}, UGX {$formattedAmount} has been allocated to your account as {$allocationTypeText}. Check EP Portal for details.";
                $infobip->sendSMS($employee['phone'], $smsMessage, 'AppNomu');
                
                // WhatsApp Notification (using approved template)
                $whatsappParams = [
                    $employee['first_name'],
                    $formattedAmount,
                    $allocationTypeText,
                    date('M j, Y')
                ];
                $infobip->sendWhatsAppTemplate($employee['phone'], 'salary_allocated', $whatsappParams);
                
            } catch (Exception $e) {
                error_log("Salary notification error: " . $e->getMessage());
            }
            
            $_SESSION['success_message'] = "Salary allocated successfully and notification sent!";
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error_message'] = "Error allocating salary: " . $e->getMessage();
        }
        
        header('Location: salary-management.php');
        exit();
    }
    
    if ($_POST['action'] === 'allocate_monthly_salaries') {
        try {
            $db->beginTransaction();
            $period = date('Y-m');
            
            // Add monthly salary to existing allocated amounts (preserve unwithdawn balances)
            $stmt = $db->prepare("
                UPDATE employee_profiles ep
                JOIN users u ON ep.user_id = u.id
                SET 
                    ep.current_period = ?,
                    ep.period_allocated_amount = ep.period_allocated_amount + ep.monthly_salary,
                    ep.last_salary_reset = CURDATE(),
                    ep.salary_status = CASE 
                        WHEN ep.withdrawn_amount >= (ep.period_allocated_amount + ep.monthly_salary) THEN 'exhausted'
                        WHEN ep.withdrawn_amount > 0 THEN 'partial'
                        ELSE 'allocated'
                    END
                WHERE u.role = 'employee' AND ep.monthly_salary > 0
            ");
            $stmt->execute([$period]);
            $updatedEmployees = $stmt->rowCount();
            
            // Create monthly allocation records
            $stmt = $db->prepare("
                INSERT INTO salary_allocations (employee_id, period, allocated_amount, allocation_type, allocated_by, notes)
                SELECT 
                    u.id,
                    ? as period,
                    ep.monthly_salary as allocated_amount,
                    'monthly' as allocation_type,
                    ? as allocated_by,
                    CONCAT('Monthly salary allocation for ', ?) as notes
                FROM users u
                JOIN employee_profiles ep ON u.id = ep.user_id
                WHERE u.role = 'employee' AND ep.monthly_salary > 0
            ");
            $stmt->execute([$period, $_SESSION['user_id'], date('F Y')]);
            $allocatedEmployees = $stmt->rowCount();
            
            $db->commit();
            
            // Send notifications to all employees
            try {
                require_once '../includes/infobip.php';
                $infobip = new InfobipAPI();
                
                // Get all employees who received allocation
                $stmt = $db->prepare("
                    SELECT u.phone, ep.first_name, ep.monthly_salary 
                    FROM users u 
                    JOIN employee_profiles ep ON u.id = ep.user_id 
                    WHERE u.role = 'employee' AND ep.monthly_salary > 0
                ");
                $stmt->execute();
                $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($employees as $emp) {
                    $formattedAmount = number_format($emp['monthly_salary']);
                    
                    // SMS Notification
                    $smsMessage = "Hello {$emp['first_name']}, your monthly salary of UGX {$formattedAmount} has been allocated for " . date('F Y') . ". Check EP Portal for details.";
                    $infobip->sendSMS($emp['phone'], $smsMessage, 'AppNomu');
                    
                    // WhatsApp Notification
                    $whatsappParams = [
                        $emp['first_name'],
                        $formattedAmount,
                        'Monthly',
                        date('M j, Y')
                    ];
                    $infobip->sendWhatsAppTemplate($emp['phone'], 'salary_allocated', $whatsappParams);
                }
                
            } catch (Exception $e) {
                error_log("Bulk salary notification error: " . $e->getMessage());
            }
            
            $_SESSION['success_message'] = "Monthly salaries allocated to $allocatedEmployees employees! Notifications sent via SMS and WhatsApp.";
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error_message'] = "Error allocating salaries: " . $e->getMessage();
        }
        
        header('Location: salary-management.php');
        exit();
    }
}

// Get employees with salary info
$stmt = $db->prepare("
    SELECT u.id, u.employee_number, ep.first_name, ep.last_name, ep.department, ep.position,
           ep.monthly_salary as salary, ep.withdrawn_amount, ep.period_allocated_amount, ep.current_period,
           ep.last_salary_reset, ep.salary_status,
           (ep.period_allocated_amount - COALESCE(ep.withdrawn_amount, 0)) as available_balance
    FROM users u
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    WHERE u.role = 'employee'
    ORDER BY ep.first_name, ep.last_name
");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent salary allocations
$stmt = $db->prepare("
    SELECT sa.*, u.employee_number, ep.first_name, ep.last_name,
           admin.employee_number as admin_number
    FROM salary_allocations sa
    JOIN users u ON sa.employee_id = u.id
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    LEFT JOIN users admin ON sa.allocated_by = admin.id
    ORDER BY sa.allocation_date DESC
    LIMIT 20
");
$stmt->execute();
$recentAllocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Management - AppNomu SalesQ</title>
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
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
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
                        <a class="nav-link" href="withdrawals.php">
                            <i class="fas fa-money-bill-wave me-2"></i>Withdrawals
                        </a>
                        <a class="nav-link active" href="salary-management.php">
                            <i class="fas fa-coins me-2"></i>Salary Management
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
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i>Reports
                        </a>
                        <a class="nav-link" href="settings.php">
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
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h2 class="mb-1"><i class="fas fa-dollar-sign me-2 text-success"></i>Salary Management</h2>
                                <p class="text-muted mb-0">Allocate, track, and manage employee salary payments</p>
                            </div>
                            <div>
                                <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#allocateMonthlySalariesModal">
                                    <i class="fas fa-calendar-check me-2"></i>Allocate Monthly Salaries
                                </button>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#allocateSalaryModal">
                                    <i class="fas fa-plus-circle me-2"></i>Individual Allocation
                                </button>
                            </div>
                        </div>
                        
                        <!-- Info Cards -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="bg-primary bg-opacity-10 rounded p-3">
                                                    <i class="fas fa-calendar-alt fa-2x text-primary"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="text-muted mb-1">Current Period</h6>
                                                <h4 class="mb-0"><?php echo date('F Y'); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <div class="bg-success bg-opacity-10 rounded p-3">
                                                    <i class="fas fa-users fa-2x text-success"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <h6 class="text-muted mb-1">Total Employees</h6>
                                                <h4 class="mb-0"><?php echo count($employees); ?></h4>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-info mb-0 h-100 d-flex align-items-center">
                                    <i class="fas fa-info-circle fa-2x me-3"></i>
                                    <div>
                                        <strong>Quick Guide:</strong> Use "Allocate Monthly Salaries" to add base salaries for all employees at once. 
                                        Use "Individual Allocation" for bonuses, advances, or adjustments.
                                    </div>
                                </div>
                            </div>
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
                    
                    
                    <!-- Employee Salary Status -->
                    <div class="card mb-4 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-users me-2 text-primary"></i>Employee Salary Overview</h5>
                                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#helpModal">
                                    <i class="fas fa-question-circle me-1"></i>Understanding Status & Reset
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="fw-semibold">Employee Details</th>
                                            <th class="fw-semibold">Base Salary</th>
                                            <th class="fw-semibold">Total Allocated</th>
                                            <th class="fw-semibold">Withdrawn</th>
                                            <th class="fw-semibold">Available Balance</th>
                                            <th class="fw-semibold">Status</th>
                                            <th class="fw-semibold">Last Reset Date</th>
                                            <th class="fw-semibold text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $employee): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-primary bg-opacity-10 rounded-circle p-2 me-2">
                                                            <i class="fas fa-user text-primary"></i>
                                                        </div>
                                                        <div>
                                                            <strong class="d-block"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></strong>
                                                            <small class="text-muted"><?php echo htmlspecialchars($employee['employee_number']); ?></small>
                                                            <br><span class="badge bg-light text-dark"><?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <strong class="text-dark">UGX <?php echo number_format($employee['salary'] ?? 0); ?></strong>
                                                    <br><small class="text-muted">Per month</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info bg-opacity-10 text-info px-3 py-2">
                                                        <i class="fas fa-wallet me-1"></i>UGX <?php echo number_format($employee['period_allocated_amount'] ?? 0); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2">
                                                        <i class="fas fa-arrow-down me-1"></i>UGX <?php echo number_format($employee['withdrawn_amount'] ?? 0); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong class="text-success fs-6">
                                                        <i class="fas fa-money-bill-wave me-1"></i>UGX <?php echo number_format($employee['available_balance'] ?? 0); ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $employee['salary_status'] === 'allocated' ? 'success' : 
                                                            ($employee['salary_status'] === 'partial' ? 'warning' : 
                                                            ($employee['salary_status'] === 'exhausted' ? 'danger' : 'secondary')); 
                                                    ?>">
                                                        <?php echo ucfirst($employee['salary_status'] ?? 'pending'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo $employee['last_salary_reset'] ? date('M j, Y', strtotime($employee['last_salary_reset'])) : 'Never'; ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            onclick="allocateToEmployee(<?php echo $employee['id']; ?>, '<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>')">
                                                        <i class="fas fa-plus-circle me-1"></i>Allocate
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Allocations -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Salary Allocations</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Amount</th>
                                            <th>Type</th>
                                            <th>Period</th>
                                            <th>Allocated By</th>
                                            <th>Date</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAllocations as $allocation): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($allocation['first_name'] . ' ' . $allocation['last_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($allocation['employee_number']); ?></small>
                                                </td>
                                                <td>
                                                    <strong class="text-success">UGX <?php echo number_format($allocation['allocated_amount']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $allocation['allocation_type'] === 'monthly' ? 'primary' : 
                                                            ($allocation['allocation_type'] === 'bonus' ? 'success' : 
                                                            ($allocation['allocation_type'] === 'advance' ? 'warning' : 'info')); 
                                                    ?>">
                                                        <?php echo ucfirst($allocation['allocation_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $allocation['period']; ?></td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($allocation['admin_number'] ?? 'System'); ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M j, Y g:i A', strtotime($allocation['allocation_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($allocation['notes'] ?? ''); ?></small>
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

    <!-- Allocate Salary Modal -->
    <div class="modal fade" id="allocateSalaryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Allocate Salary</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="allocate_salary">
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['id']; ?>">
                                        <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (UGX)</label>
                            <input type="number" name="amount" class="form-control" min="1000" step="1000" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Allocation Type</label>
                            <select name="allocation_type" class="form-select" required>
                                <option value="monthly">Monthly Salary</option>
                                <option value="bonus">Bonus</option>
                                <option value="advance">Advance</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this allocation"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Allocate Salary</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Allocate Monthly Salaries Modal -->
    <div class="modal fade" id="allocateMonthlySalariesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Allocate Monthly Salaries</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Monthly Salary Allocation:</strong> This will add each employee's monthly salary to their current available balance, preserving any unwithdawn amounts.
                    </div>
                    <p>This action will:</p>
                    <ul>
                        <li><strong>Preserve</strong> existing unwithdawn balances</li>
                        <li><strong>Add</strong> monthly salary to current allocated amount</li>
                        <li><strong>Update</strong> current period to <?php echo date('Y-m'); ?></li>
                        <li><strong>Create</strong> allocation records for tracking</li>
                    </ul>
                    <div class="alert alert-success">
                        <i class="fas fa-shield-alt me-2"></i>
                        <strong>Safe Operation:</strong> No employee money will be lost - all previous balances are preserved.
                    </div>
                    <p><strong>Ready to allocate monthly salaries to all employees?</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="allocate_monthly_salaries">
                        <button type="submit" class="btn btn-success">Allocate Monthly Salaries</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Help Modal - Understanding Status & Reset -->
    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i>Understanding Salary Status & Reset</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="text-primary mb-3"><i class="fas fa-info-circle me-2"></i>Salary Status Explained</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h6 class="card-title"><span class="badge bg-success">Allocated</span></h6>
                                    <p class="card-text small mb-0">Employee has been allocated salary but hasn't withdrawn any amount yet. Full balance is available.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-warning">
                                <div class="card-body">
                                    <h6 class="card-title"><span class="badge bg-warning">Partial</span></h6>
                                    <p class="card-text small mb-0">Employee has withdrawn some money but still has remaining balance available to withdraw.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-danger">
                                <div class="card-body">
                                    <h6 class="card-title"><span class="badge bg-danger">Exhausted</span></h6>
                                    <p class="card-text small mb-0">Employee has withdrawn all allocated money. No balance remaining until next allocation.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-secondary">
                                <div class="card-body">
                                    <h6 class="card-title"><span class="badge bg-secondary">Pending</span></h6>
                                    <p class="card-text small mb-0">No salary has been allocated to this employee yet for the current period.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6 class="text-primary mb-3"><i class="fas fa-sync-alt me-2"></i>What Does "Last Reset" Mean?</h6>
                    <div class="alert alert-light border">
                        <p class="mb-2"><strong>Last Reset Date</strong> shows when the employee's salary was last allocated or updated.</p>
                        <ul class="mb-0">
                            <li><strong>Purpose:</strong> Tracks when money was added to employee's account</li>
                            <li><strong>Updates when:</strong> Monthly salaries are allocated or individual allocations are made</li>
                            <li><strong>"Never":</strong> Means the employee has never received any salary allocation</li>
                        </ul>
                    </div>
                    
                    <hr>
                    
                    <h6 class="text-primary mb-3"><i class="fas fa-calculator me-2"></i>How It Works</h6>
                    <div class="bg-light p-3 rounded">
                        <p class="mb-2"><strong>Example:</strong></p>
                        <ol class="mb-0">
                            <li>Employee's monthly salary: <strong>UGX 1,000,000</strong></li>
                            <li>You allocate monthly salary → <strong>Allocated: UGX 1,000,000</strong> (Status: Allocated)</li>
                            <li>Employee withdraws UGX 300,000 → <strong>Available: UGX 700,000</strong> (Status: Partial)</li>
                            <li>Next month, you allocate again → <strong>Available: UGX 1,700,000</strong> (Previous balance preserved!)</li>
                        </ol>
                    </div>
                    
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="fas fa-shield-alt me-2"></i><strong>Important:</strong> Unwithdawn balances are always preserved when allocating new salaries!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got It!</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function allocateToEmployee(employeeId, employeeName) {
            const modal = document.getElementById('allocateSalaryModal');
            const employeeSelect = modal.querySelector('select[name="employee_id"]');
            employeeSelect.value = employeeId;
            
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    </script>
</body>
</html>
