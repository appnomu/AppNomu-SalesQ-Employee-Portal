<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';

// Start secure session first
startSecureSession();
requireAdmin();

// Get date range for reports (default to current month)
$dateFrom = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : date('Y-m-t');
$reportType = isset($_GET['report_type']) ? sanitizeInput($_GET['report_type']) : 'overview';

// Employee Statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_employees,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_employees,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_employees,
        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_employees
    FROM users WHERE role = 'employee'
");
$stmt->execute();
$employeeStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Leave Request Statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
        SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as total_approved_days
    FROM leave_requests 
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$leaveStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Task Statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
    FROM tasks 
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$taskStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Ticket Statistics with Response Analytics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_tickets,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_tickets,
        AVG(CASE WHEN resolved_at IS NOT NULL THEN 
            TIMESTAMPDIFF(HOUR, created_at, resolved_at) ELSE NULL END) as avg_resolution_hours
    FROM tickets 
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$ticketStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Ticket Response Rate Analytics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT t.id) as tickets_with_responses,
        COUNT(tr.id) as total_responses,
        AVG(response_count.responses_per_ticket) as avg_responses_per_ticket,
        SUM(CASE WHEN tr.is_internal = 0 THEN 1 ELSE 0 END) as external_responses,
        SUM(CASE WHEN tr.is_internal = 1 THEN 1 ELSE 0 END) as internal_responses
    FROM tickets t
    LEFT JOIN ticket_responses tr ON t.id = tr.ticket_id
    LEFT JOIN (
        SELECT ticket_id, COUNT(*) as responses_per_ticket 
        FROM ticket_responses 
        GROUP BY ticket_id
    ) response_count ON t.id = response_count.ticket_id
    WHERE t.created_at BETWEEN ? AND ?
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$responseStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Document Statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_documents,
        COUNT(DISTINCT f.user_id) as employees_with_docs,
        SUM(f.file_size) as total_storage_bytes,
        COUNT(CASE WHEN f.file_type = 'pdf' THEN 1 END) as pdf_count,
        COUNT(CASE WHEN f.file_type IN ('jpg', 'jpeg', 'png') THEN 1 END) as image_count,
        COUNT(CASE WHEN f.file_type IN ('doc', 'docx') THEN 1 END) as doc_count,
        AVG(f.file_size) as avg_file_size
    FROM file_uploads f
    WHERE f.category = 'document' AND f.created_at BETWEEN ? AND ?
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$documentStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Detailed Ticket Report with Responses
$stmt = $db->prepare("
    SELECT 
        t.id,
        t.subject,
        t.category,
        t.priority,
        t.status,
        t.created_at,
        t.resolved_at,
        u.employee_number,
        ep.first_name,
        ep.last_name,
        COUNT(tr.id) as response_count,
        MAX(tr.created_at) as last_response_at,
        TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.resolved_at, NOW())) as hours_open
    FROM tickets t
    JOIN users u ON t.employee_id = u.id
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    LEFT JOIN ticket_responses tr ON t.id = tr.ticket_id
    WHERE t.created_at BETWEEN ? AND ?
    GROUP BY t.id
    ORDER BY t.created_at DESC
    LIMIT 50
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$detailedTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Withdrawal Statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_withdrawals,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_withdrawals,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_withdrawals,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_withdrawals,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_completed_amount,
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending_amount
    FROM salary_withdrawals 
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$withdrawalStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Add formatFileSize function
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Department-wise Employee Distribution
$stmt = $db->prepare("
    SELECT 
        ep.department,
        COUNT(*) as employee_count,
        AVG(ep.monthly_salary) as avg_salary
    FROM employee_profiles ep
    JOIN users u ON ep.user_id = u.id
    WHERE u.role = 'employee' AND u.status = 'active' AND ep.department IS NOT NULL
    GROUP BY ep.department
    ORDER BY employee_count DESC
");
$stmt->execute();
$departmentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly Activity Trends (last 6 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as activity_count,
        action
    FROM audit_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), action
    ORDER BY month DESC, activity_count DESC
");
$stmt->execute();
$activityTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Performing Employees (based on completed tasks)
$stmt = $db->prepare("
    SELECT 
        u.employee_number,
        ep.first_name,
        ep.last_name,
        ep.department,
        COUNT(t.id) as completed_tasks
    FROM users u
    JOIN employee_profiles ep ON u.id = ep.user_id
    LEFT JOIN tasks t ON u.id = t.assigned_to AND t.status = 'completed'
    WHERE u.role = 'employee' AND t.completed_at BETWEEN ? AND ?
    GROUP BY u.id
    HAVING completed_tasks > 0
    ORDER BY completed_tasks DESC
    LIMIT 10
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Reports - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border: none;
            height: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .stats-card h3 {
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stats-card small {
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .chart-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            height: 420px;
            transition: all 0.3s ease;
        }
        
        .chart-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }
        
        .chart-container h5 {
            font-weight: 600;
            color: #495057;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .report-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }
        
        .report-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }
        
        .report-section h5 {
            font-weight: 600;
            color: #495057;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .table {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.01);
        }
        
        .table td {
            padding: 12px 15px;
            vertical-align: middle;
        }
        
        .badge {
            font-size: 0.85rem;
            padding: 6px 12px;
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .text-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
                        <a class="nav-link active" href="reports.php">
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
                    <div class="d-flex justify-content-between align-items-center mb-5">
                        <div>
                            <h1 class="text-gradient mb-2"><i class="fas fa-chart-bar me-3"></i>Reports & Analytics</h1>
                            <p class="text-muted fs-5">System performance metrics and business intelligence</p>
                        </div>
                        <div>
                            <button class="btn btn-primary btn-lg shadow" onclick="exportReport()">
                                <i class="fas fa-download me-2"></i>Export Report
                            </button>
                        </div>
                    </div>
                    
                    <!-- Date Range Filter -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Report Type</label>
                                    <select name="report_type" class="form-select">
                                        <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>Overview</option>
                                        <option value="tickets" <?php echo $reportType === 'tickets' ? 'selected' : ''; ?>>Ticket Analytics</option>
                                        <option value="documents" <?php echo $reportType === 'documents' ? 'selected' : ''; ?>>Document Reports</option>
                                        <option value="employees" <?php echo $reportType === 'employees' ? 'selected' : ''; ?>>Employee Analytics</option>
                                        <option value="performance" <?php echo $reportType === 'performance' ? 'selected' : ''; ?>>Performance Metrics</option>
                                        <option value="financial" <?php echo $reportType === 'financial' ? 'selected' : ''; ?>>Financial Summary</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date From</label>
                                    <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date To</label>
                                    <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-2"></i>Generate Report
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Overview Statistics -->
                    <div class="row mb-5 g-4">
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-users fa-2x text-primary mb-3"></i>
                                </div>
                                <h3 class="text-primary"><?php echo $employeeStats['active_employees']; ?></h3>
                                <small class="text-muted">Active Employees</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-calendar-times fa-2x text-warning mb-3"></i>
                                </div>
                                <h3 class="text-warning"><?php echo $leaveStats['pending_requests']; ?></h3>
                                <small class="text-muted">Pending Leaves</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-tasks fa-2x text-info mb-3"></i>
                                </div>
                                <h3 class="text-info"><?php echo $taskStats['in_progress_tasks']; ?></h3>
                                <small class="text-muted">Active Tasks</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-check-circle fa-2x text-success mb-3"></i>
                                </div>
                                <h3 class="text-success"><?php echo $ticketStats['resolved_tickets']; ?></h3>
                                <small class="text-muted">Resolved Tickets</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-money-bill-wave fa-2x text-primary mb-3"></i>
                                </div>
                                <h3 class="text-primary"><?php echo $withdrawalStats['completed_withdrawals']; ?></h3>
                                <small class="text-muted">Completed Withdrawals</small>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-coins fa-2x text-success mb-3"></i>
                                </div>
                                <h3 class="text-success">UGX <?php echo number_format($withdrawalStats['total_completed_amount'] ?? 0); ?></h3>
                                <small class="text-muted">Total Disbursed</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Stats Row for Tickets & Documents -->
                    <div class="row mb-5 g-4">
                        <div class="col-lg-3 col-md-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-comments fa-2x text-info mb-3"></i>
                                </div>
                                <h3 class="text-info"><?php echo number_format($responseStats['avg_responses_per_ticket'] ?? 0, 1); ?></h3>
                                <small class="text-muted">Avg Responses/Ticket</small>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-clock fa-2x text-warning mb-3"></i>
                                </div>
                                <h3 class="text-warning"><?php echo $ticketStats['avg_resolution_hours'] > 0 ? number_format($ticketStats['avg_resolution_hours'], 1) . 'h' : '-1.0h'; ?></h3>
                                <small class="text-muted">Avg Resolution Time</small>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-file-alt fa-2x text-primary mb-3"></i>
                                </div>
                                <h3 class="text-primary"><?php echo $documentStats['total_documents'] ?? 0; ?></h3>
                                <small class="text-muted">Total Documents</small>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="stats-card text-center">
                                <div class="mb-2">
                                    <i class="fas fa-hdd fa-2x text-success mb-3"></i>
                                </div>
                                <h3 class="text-success"><?php echo formatFileSize($documentStats['total_storage_bytes'] ?? 0); ?></h3>
                                <small class="text-muted">Storage Used</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Report Type Specific Sections -->
                    <?php if ($reportType === 'tickets'): ?>
                    <!-- Ticket Analytics Section -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Ticket Status Distribution</h5>
                                <canvas id="ticketStatusChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Ticket Priority Distribution</h5>
                                <canvas id="ticketPriorityChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Response Rate Analytics</h5>
                                <canvas id="responseRateChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Ticket Categories</h5>
                                <canvas id="ticketCategoryChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($reportType === 'documents'): ?>
                    <!-- Document Analytics Section -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Document Type Distribution</h5>
                                <canvas id="documentTypeChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Storage Usage by Type</h5>
                                <canvas id="storageChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="chart-container">
                                <h5 class="mb-3">Document Upload Trends</h5>
                                <canvas id="uploadTrendsChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <!-- Default Overview Charts -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Department Distribution</h5>
                                <canvas id="departmentChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Task Status Distribution</h5>
                                <canvas id="taskChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Department Statistics -->
                    <div class="report-section">
                        <h5 class="mb-3"><i class="fas fa-building me-2"></i>Department Statistics</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Employee Count</th>
                                        <th>Average Salary</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departmentStats as $dept): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                            <td><?php echo $dept['employee_count']; ?></td>
                                            <td>UGX <?php echo number_format($dept['avg_salary'] ?? 0); ?></td>
                                            <td>
                                                <?php 
                                                $percentage = ($dept['employee_count'] / $employeeStats['active_employees']) * 100;
                                                echo number_format($percentage, 1) . '%';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Top Performers -->
                    <?php if (!empty($topPerformers)): ?>
                    <div class="report-section">
                        <h5 class="mb-3"><i class="fas fa-trophy me-2"></i>Top Performing Employees</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Completed Tasks</th>
                                        <th>Avg Hours/Task</th>
                                        <th>Performance Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topPerformers as $index => $performer): ?>
                                        <tr>
                                            <td>
                                                <?php if ($index < 3): ?>
                                                    <i class="fas fa-medal text-<?php echo $index === 0 ? 'warning' : ($index === 1 ? 'secondary' : 'warning'); ?> me-2"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($performer['first_name'] . ' ' . $performer['last_name']); ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($performer['employee_number']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($performer['department'] ?? 'N/A'); ?></td>
                                            <td><span class="badge bg-success"><?php echo $performer['completed_tasks']; ?></span></td>
                                            <td>N/A</td>
                                            <td>
                                                <?php 
                                                $score = min(100, ($performer['completed_tasks'] * 10));
                                                echo number_format(max(0, $score), 1) . '%';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Summary Cards -->
                    <div class="row">
                        <div class="col-md-3">
                            <div class="report-section text-center">
                                <h6 class="text-muted">Leave Approval Rate</h6>
                                <h3 class="text-success">
                                    <?php 
                                    $approvalRate = $leaveStats['total_requests'] > 0 ? 
                                        ($leaveStats['approved_requests'] / $leaveStats['total_requests']) * 100 : 0;
                                    echo number_format($approvalRate, 1) . '%';
                                    ?>
                                </h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="report-section text-center">
                                <h6 class="text-muted">Task Completion Rate</h6>
                                <h3 class="text-info">
                                    <?php 
                                    $completionRate = $taskStats['total_tasks'] > 0 ? 
                                        ($taskStats['completed_tasks'] / $taskStats['total_tasks']) * 100 : 0;
                                    echo number_format($completionRate, 1) . '%';
                                    ?>
                                </h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="report-section text-center">
                                <h6 class="text-muted">Ticket Resolution Rate</h6>
                                <h3 class="text-primary">
                                    <?php 
                                    $resolutionRate = $ticketStats['total_tickets'] > 0 ? 
                                        (($ticketStats['resolved_tickets'] + $ticketStats['closed_tickets']) / $ticketStats['total_tickets']) * 100 : 0;
                                    echo number_format($resolutionRate, 1) . '%';
                                    ?>
                                </h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="report-section text-center">
                                <h6 class="text-muted">Withdrawal Success Rate</h6>
                                <h3 class="text-warning">
                                    <?php 
                                    $successRate = $withdrawalStats['total_withdrawals'] > 0 ? 
                                        ($withdrawalStats['completed_withdrawals'] / $withdrawalStats['total_withdrawals']) * 100 : 0;
                                    echo number_format($successRate, 1) . '%';
                                    ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Department Distribution Chart
        const departmentData = <?php echo json_encode($departmentStats); ?>;
        const deptCtx = document.getElementById('departmentChart').getContext('2d');
        new Chart(deptCtx, {
            type: 'doughnut',
            data: {
                labels: departmentData.map(d => d.department),
                datasets: [{
                    data: departmentData.map(d => d.employee_count),
                    backgroundColor: [
                        '#667eea', '#764ba2', '#f093fb', '#f5576c',
                        '#4facfe', '#00f2fe', '#43e97b', '#38f9d7'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Task Status Chart
        const taskData = <?php echo json_encode($taskStats); ?>;
        const taskCtx = document.getElementById('taskChart').getContext('2d');
        new Chart(taskCtx, {
            type: 'bar',
            data: {
                labels: ['Pending', 'In Progress', 'Completed'],
                datasets: [{
                    label: 'Tasks',
                    data: [
                        taskData.pending_tasks,
                        taskData.in_progress_tasks,
                        taskData.completed_tasks
                    ],
                    backgroundColor: ['#ffc107', '#0dcaf0', '#198754']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Ticket Analytics Charts
        <?php if ($reportType === 'tickets'): ?>
        // Ticket Status Chart
        const ticketStatusCtx = document.getElementById('ticketStatusChart').getContext('2d');
        new Chart(ticketStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Open', 'In Progress', 'Resolved', 'Closed'],
                datasets: [{
                    data: [
                        <?php echo $ticketStats['open_tickets'] ?? 0; ?>,
                        <?php echo $ticketStats['in_progress_tickets'] ?? 0; ?>,
                        <?php echo $ticketStats['resolved_tickets'] ?? 0; ?>,
                        <?php echo $ticketStats['closed_tickets'] ?? 0; ?>
                    ],
                    backgroundColor: ['#dc3545', '#ffc107', '#198754', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Ticket Priority Chart
        const ticketPriorityCtx = document.getElementById('ticketPriorityChart').getContext('2d');
        <?php
        $stmt = $db->prepare("SELECT priority, COUNT(*) as count FROM tickets WHERE created_at BETWEEN ? AND ? GROUP BY priority");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $priorityData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $priorityCounts = ['low' => 0, 'medium' => 0, 'high' => 0, 'urgent' => 0];
        foreach ($priorityData as $row) {
            $priorityCounts[$row['priority']] = $row['count'];
        }
        ?>
        new Chart(ticketPriorityCtx, {
            type: 'bar',
            data: {
                labels: ['Low', 'Medium', 'High', 'Urgent'],
                datasets: [{
                    label: 'Tickets',
                    data: [
                        <?php echo $priorityCounts['low']; ?>,
                        <?php echo $priorityCounts['medium']; ?>,
                        <?php echo $priorityCounts['high']; ?>,
                        <?php echo $priorityCounts['urgent']; ?>
                    ],
                    backgroundColor: ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Response Rate Chart
        const responseRateCtx = document.getElementById('responseRateChart').getContext('2d');
        new Chart(responseRateCtx, {
            type: 'pie',
            data: {
                labels: ['External Responses', 'Internal Notes'],
                datasets: [{
                    data: [
                        <?php echo $responseStats['external_responses'] ?? 0; ?>,
                        <?php echo $responseStats['internal_responses'] ?? 0; ?>
                    ],
                    backgroundColor: ['#0dcaf0', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Ticket Category Chart
        const ticketCategoryCtx = document.getElementById('ticketCategoryChart').getContext('2d');
        <?php
        $stmt = $db->prepare("SELECT category, COUNT(*) as count FROM tickets WHERE created_at BETWEEN ? AND ? GROUP BY category");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $categoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        new Chart(ticketCategoryCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(function($row) { return '"' . $row['category'] . '"'; }, $categoryData)); ?>],
                datasets: [{
                    label: 'Tickets',
                    data: [<?php echo implode(',', array_column($categoryData, 'count')); ?>],
                    backgroundColor: '#667eea'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Document Analytics Charts
        <?php if ($reportType === 'documents'): ?>
        // Document Type Chart
        const documentTypeCtx = document.getElementById('documentTypeChart').getContext('2d');
        new Chart(documentTypeCtx, {
            type: 'doughnut',
            data: {
                labels: ['PDF', 'Images', 'Documents', 'Other'],
                datasets: [{
                    data: [
                        <?php echo $documentStats['pdf_count'] ?? 0; ?>,
                        <?php echo $documentStats['image_count'] ?? 0; ?>,
                        <?php echo $documentStats['doc_count'] ?? 0; ?>,
                        <?php echo ($documentStats['total_documents'] ?? 0) - ($documentStats['pdf_count'] ?? 0) - ($documentStats['image_count'] ?? 0) - ($documentStats['doc_count'] ?? 0); ?>
                    ],
                    backgroundColor: ['#dc3545', '#28a745', '#007bff', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Storage Usage Chart
        const storageCtx = document.getElementById('storageChart').getContext('2d');
        <?php
        $stmt = $db->prepare("
            SELECT 
                file_type,
                COUNT(*) as count,
                SUM(file_size) as total_size
            FROM file_uploads 
            WHERE category = 'document' AND created_at BETWEEN ? AND ?
            GROUP BY file_type
        ");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $storageData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        new Chart(storageCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(function($row) { return '"' . strtoupper($row['file_type']) . '"'; }, $storageData)); ?>],
                datasets: [{
                    label: 'Storage (MB)',
                    data: [<?php echo implode(',', array_map(function($row) { return round($row['total_size'] / 1024 / 1024, 2); }, $storageData)); ?>],
                    backgroundColor: '#17a2b8'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Upload Trends Chart
        const uploadTrendsCtx = document.getElementById('uploadTrendsChart').getContext('2d');
        <?php
        $stmt = $db->prepare("
            SELECT 
                DATE(created_at) as upload_date,
                COUNT(*) as daily_uploads
            FROM file_uploads 
            WHERE category = 'document' AND created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY upload_date
        ");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $trendsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        new Chart(uploadTrendsCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($row) { return '"' . date('M j', strtotime($row['upload_date'])) . '"'; }, $trendsData)); ?>],
                datasets: [{
                    label: 'Daily Uploads',
                    data: [<?php echo implode(',', array_column($trendsData, 'daily_uploads')); ?>],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>
        
        function exportReport() {
            // Implementation for report export
            alert('Export functionality will be implemented based on requirements (PDF, Excel, CSV)');
        }
    </script>
</body>
</html>
