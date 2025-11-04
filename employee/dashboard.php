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

// Get employee profile
$stmt = $db->prepare("
    SELECT u.*, ep.* 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Get dashboard statistics
$stats = [];

// My leave requests
$stmt = $db->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE employee_id = ?");
$stmt->execute([$userId]);
$stats['total_leaves'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// My pending tasks
$stmt = $db->prepare("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = ? AND status IN ('pending', 'in_progress')");
$stmt->execute([$userId]);
$stats['pending_tasks'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// My open tickets
$stmt = $db->prepare("SELECT COUNT(*) as count FROM tickets WHERE employee_id = ? AND status IN ('open', 'in_progress')");
$stmt->execute([$userId]);
$stats['open_tickets'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// My unread notifications
$stmt = $db->prepare("SELECT COUNT(*) as count FROM system_notifications WHERE user_id = ? AND is_read = FALSE");
$stmt->execute([$userId]);
$stats['unread_notifications'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent notifications
$stmt = $db->prepare("
    SELECT * FROM system_notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent leave requests
$stmt = $db->prepare("
    SELECT lr.*, lt.name as leave_type_name
    FROM leave_requests lr 
    JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
    ORDER BY lr.created_at DESC 
    LIMIT 5
");
$stmt->execute([$userId]);
$recentLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent tasks
$stmt = $db->prepare("
    SELECT t.*, u.employee_number, ep.first_name as assigned_by_name, ep.last_name as assigned_by_lastname
    FROM tasks t 
    LEFT JOIN users u ON t.assigned_by = u.id
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    WHERE t.assigned_to = ?
    ORDER BY t.created_at DESC 
    LIMIT 5
");
$stmt->execute([$userId]);
$recentTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent tickets
$stmt = $db->prepare("
    SELECT * FROM tickets 
    WHERE employee_id = ?
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$userId]);
$recentTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Employee Dashboard - AppNomu SalesQ</title>
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
        .stat-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        .table-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            background: #2a2a2a;
            border: 1px solid #404040;
        }
        .table-card .table {
            color: #e0e0e0;
        }
        .table-dark {
            --bs-table-bg: #2a2a2a;
            --bs-table-border-color: #404040;
        }
        .profile-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            color: white;
            border-radius: 15px;
            border: 1px solid #404040;
        }
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
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
                        <small>Employee Panel</small>
                    </div>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link active" href="dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a class="nav-link" href="profile">
                            <i class="fas fa-user me-2"></i>My Profile
                        </a>
                        <a class="nav-link" href="leave-requests">
                            <i class="fas fa-calendar-alt me-2"></i>Leave Requests
                        </a>
                        <a class="nav-link" href="tasks">
                            <i class="fas fa-tasks me-2"></i>My Tasks
                        </a>
                        <a class="nav-link" href="tickets">
                            <i class="fas fa-ticket-alt me-2"></i>Support Tickets
                        </a>
                        <a class="nav-link" href="withdrawal-salary">
                            <i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawal
                        </a>
                        <a class="nav-link" href="documents">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="reminders">
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
                        <a href="../auth/logout" class="nav-link text-white-50">
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
                        <h2>Welcome back, <?php echo $employee['first_name']; ?>!</h2>
                        <div class="text-muted">
                            <i class="fas fa-calendar me-2"></i><?php echo date('F j, Y'); ?>
                        </div>
                    </div>
                    
                    <!-- Profile Card -->
                    <div class="card profile-card mb-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <div class="profile-avatar">
                                        <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                                    </div>
                                </div>
                                <div class="col">
                                    <h4 class="mb-1"><?php echo $employee['first_name'] . ' ' . $employee['last_name']; ?></h4>
                                    <p class="mb-1"><?php echo $employee['position'] ?? 'Employee'; ?> • <?php echo $employee['department'] ?? 'General'; ?></p>
                                    <small class="opacity-75">Employee ID: <?php echo $employee['employee_number']; ?></small>
                                </div>
                                <div class="col-auto">
                                    <a href="profile" class="btn btn-outline-light btn-sm">
                                        <i class="fas fa-edit me-1"></i>Edit Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-primary me-3">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div>
                                        <h3 class="mb-0"><?php echo $stats['total_leaves']; ?></h3>
                                        <small class="text-muted">Leave Requests</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-warning me-3">
                                        <i class="fas fa-tasks"></i>
                                    </div>
                                    <div>
                                        <h3 class="mb-0"><?php echo $stats['pending_tasks']; ?></h3>
                                        <small class="text-muted">Pending Tasks</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-info me-3">
                                        <i class="fas fa-ticket-alt"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Open Tickets</h6>
                                        <h4 class="mb-0"><?= $stats['open_tickets'] ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center">
                                    <div class="stat-icon bg-danger me-3">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Notifications</h6>
                                        <h4 class="mb-0"><?= $stats['unread_notifications'] ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card table-card">
                                <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                    <h5 class="mb-0" style="color: #e0e0e0;">
                                        <i class="fas fa-bolt me-2"></i>Quick Actions
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3 mb-2">
                                            <a href="leave-requests?action=new" class="btn btn-outline-primary w-100">
                                                <i class="fas fa-plus me-2"></i>Request Leave
                                            </a>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <a href="tickets?action=new" class="btn btn-outline-info w-100">
                                                <i class="fas fa-headset me-2"></i>Create Ticket
                                            </a>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <a href="withdrawals" class="btn btn-outline-success w-100">
                                                <i class="fas fa-money-bill-wave me-2"></i>Withdraw Salary
                                            </a>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <a href="reminders?action=new" class="btn btn-outline-warning w-100">
                                                <i class="fas fa-bell me-2"></i>Set Reminder
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Notifications -->
                    <?php if (!empty($notifications)): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card table-card">
                                <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0" style="color: #e0e0e0;">
                                            <i class="fas fa-bell me-2"></i>Recent Notifications
                                            <?php if ($stats['unread_notifications'] > 0): ?>
                                                <span class="badge bg-danger ms-2" id="unread-count"><?= $stats['unread_notifications'] ?></span>
                                            <?php endif; ?>
                                        </h5>
                                        <?php if ($stats['unread_notifications'] > 0): ?>
                                            <button class="btn btn-outline-light btn-sm" onclick="markAllNotificationsRead()">
                                                <i class="fas fa-check-double me-1"></i>Mark All Read
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php foreach ($notifications as $notification): ?>
                                        <div class="d-flex align-items-center p-3 border-bottom border-secondary <?= !$notification['is_read'] ? 'bg-dark' : '' ?>" 
                                             id="notification-<?= $notification['id'] ?>" 
                                             style="cursor: pointer;" 
                                             onclick="markNotificationRead(<?= (int)$notification['id'] ?>)"
                                             data-notification-id="<?= (int)$notification['id'] ?>">
                                            <div class="me-3">
                                                <i class="fas fa-<?= $notification['type'] == 'reminder' ? 'clock' : 'info-circle' ?> text-<?= $notification['type'] == 'reminder' ? 'warning' : 'info' ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 text-light"><?= htmlspecialchars($notification['title']) ?></h6>
                                                <p class="mb-1 text-muted small"><?= htmlspecialchars($notification['message']) ?></p>
                                                <small class="text-muted"><?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?></small>
                                            </div>
                                            <div class="ms-2 d-flex align-items-center">
                                                <?php if (!$notification['is_read']): ?>
                                                    <span class="badge bg-primary me-2">New</span>
                                                <?php endif; ?>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" onclick="event.stopPropagation()">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-dark">
                                                        <?php if (!$notification['is_read']): ?>
                                                            <li><a class="dropdown-item" href="#" onclick="event.preventDefault(); markNotificationRead(<?= (int)$notification['id'] ?>)">
                                                                <i class="fas fa-check me-2"></i>Mark as Read
                                                            </a></li>
                                                        <?php endif; ?>
                                                        <li><a class="dropdown-item text-danger" href="#" onclick="event.preventDefault(); deleteNotification(<?= (int)$notification['id'] ?>)">
                                                            <i class="fas fa-trash me-2"></i>Delete
                                                        </a></li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Activities -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card table-card">
                                <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                    <h6 class="mb-0" style="color: #e0e0e0;">
                                        <i class="fas fa-calendar-check me-2"></i>Recent Leave Requests
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($recentLeaves)): ?>
                                        <div class="p-3 text-center text-muted">
                                            <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                                            <p>No leave requests yet</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recentLeaves as $leave): ?>
                                            <div class="list-group-item" style="background-color: #2a2a2a; border-color: #404040; color: #e0e0e0;">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo $leave['leave_type_name']; ?></h6>
                                                        <p class="mb-1 small"><?php echo date('M j', strtotime($leave['start_date'])); ?> - <?php echo date('M j', strtotime($leave['end_date'])); ?></p>
                                                        <small style="color: #b0b0b0;"><?php echo $leave['total_days']; ?> days</small>
                                                    </div>
                                                    <span class="badge bg-<?php echo $leave['status'] === 'pending' ? 'warning' : ($leave['status'] === 'approved' ? 'success' : 'danger'); ?>">
                                                        <?php echo ucfirst($leave['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card table-card">
                                <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                    <h6 class="mb-0" style="color: #e0e0e0;">
                                        <i class="fas fa-tasks me-2"></i>Recent Tasks
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($recentTasks)): ?>
                                        <div class="p-3 text-center text-muted">
                                            <i class="fas fa-tasks fa-2x mb-2"></i>
                                            <p>No tasks assigned yet</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recentTasks as $task): ?>
                                            <div class="list-group-item" style="background-color: #2a2a2a; border-color: #404040; color: #e0e0e0;">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo $task['title']; ?></h6>
                                                        <p class="mb-1 small" style="color: #b0b0b0;">
                                                            By: <?php echo $task['assigned_by_name'] . ' ' . $task['assigned_by_lastname']; ?>
                                                        </p>
                                                        <?php if ($task['due_date']): ?>
                                                            <small style="color: #b0b0b0;">Due: <?php echo date('M j, Y', strtotime($task['due_date'])); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <span class="badge bg-<?php echo $task['priority'] === 'urgent' ? 'danger' : ($task['priority'] === 'high' ? 'warning' : 'info'); ?>">
                                                            <?php echo ucfirst($task['priority']); ?>
                                                        </span>
                                                        <br>
                                                        <span class="badge bg-<?php echo $task['status'] === 'pending' ? 'secondary' : ($task['status'] === 'in_progress' ? 'primary' : 'success'); ?> mt-1">
                                                            <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card table-card">
                                <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                    <h6 class="mb-0" style="color: #e0e0e0;">
                                        <i class="fas fa-ticket-alt me-2"></i>Recent Tickets
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($recentTickets)): ?>
                                        <div class="p-3 text-center text-muted">
                                            <i class="fas fa-ticket-alt fa-2x mb-2"></i>
                                            <p>No support tickets yet</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recentTickets as $ticket): ?>
                                            <div class="list-group-item" style="background-color: #2a2a2a; border-color: #404040; color: #e0e0e0;">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo $ticket['subject']; ?></h6>
                                                        <p class="mb-1 small" style="color: #b0b0b0;"><?php echo $ticket['ticket_number']; ?></p>
                                                        <small style="color: #b0b0b0;"><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></small>
                                                    </div>
                                                    <span class="badge bg-<?php echo $ticket['status'] === 'open' ? 'danger' : ($ticket['status'] === 'in_progress' ? 'warning' : 'success'); ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Mark single notification as read
    function markNotificationRead(notificationId) {
        console.log('markNotificationRead called with ID:', notificationId);
        
        if (!notificationId || notificationId <= 0) {
            alert('Invalid notification ID: ' + notificationId);
            return;
        }
        
        const requestData = {
            notification_id: parseInt(notificationId),
            action: 'mark_read'
        };
        
        console.log('Sending request:', requestData);
        
        fetch('mark-notification-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                // Remove the notification visually
                const notificationElement = document.getElementById('notification-' + notificationId);
                if (notificationElement) {
                    notificationElement.style.transition = 'opacity 0.3s ease';
                    notificationElement.style.opacity = '0.5';
                    notificationElement.classList.remove('bg-dark');
                    
                    // Remove "New" badge
                    const badge = notificationElement.querySelector('.badge.bg-primary');
                    if (badge) {
                        badge.remove();
                    }
                    
                    // Update unread count
                    updateUnreadCount(-1);
                }
            } else {
                console.error('Server error:', data);
                alert('Error: ' + data.message + (data.debug ? '\nDebug: ' + JSON.stringify(data.debug) : ''));
            }
        })
        .catch(error => {
            console.error('Network error:', error);
            alert('Failed to mark notification as read: ' + error.message);
        });
    }

    // Mark all notifications as read
    function markAllNotificationsRead() {
        fetch('mark-notification-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                notification_id: 0,
                action: 'mark_all_read'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove all "New" badges and dark backgrounds
                document.querySelectorAll('[id^="notification-"]').forEach(element => {
                    element.classList.remove('bg-dark');
                    element.style.opacity = '0.5';
                    
                    const badge = element.querySelector('.badge.bg-primary');
                    if (badge) {
                        badge.remove();
                    }
                });
                
                // Hide unread count and mark all button
                const unreadCount = document.getElementById('unread-count');
                if (unreadCount) {
                    unreadCount.style.display = 'none';
                }
                
                const markAllButton = document.querySelector('button[onclick="markAllNotificationsRead()"]');
                if (markAllButton) {
                    markAllButton.style.display = 'none';
                }
                
                // Update stats card
                const statsCard = document.querySelector('.stat-card .stat-icon.bg-danger').parentElement.parentElement;
                if (statsCard) {
                    statsCard.querySelector('h4').textContent = '0';
                }
                
                alert('All notifications marked as read');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to mark all notifications as read');
        });
    }

    // Delete notification
    function deleteNotification(notificationId) {
        if (!confirm('Are you sure you want to delete this notification?')) {
            return;
        }
        
        fetch('mark-notification-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                notification_id: notificationId,
                action: 'delete'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the notification element
                const notificationElement = document.getElementById('notification-' + notificationId);
                if (notificationElement) {
                    notificationElement.style.transition = 'all 0.3s ease';
                    notificationElement.style.transform = 'translateX(100%)';
                    notificationElement.style.opacity = '0';
                    
                    setTimeout(() => {
                        notificationElement.remove();
                        
                        // Check if this was the last notification
                        const remainingNotifications = document.querySelectorAll('[id^="notification-"]');
                        if (remainingNotifications.length === 0) {
                            // Hide the entire notifications section
                            const notificationsSection = document.querySelector('.row.mb-4');
                            if (notificationsSection && notificationsSection.querySelector('.card-header h5').textContent.includes('Notifications')) {
                                notificationsSection.style.display = 'none';
                            }
                        }
                    }, 300);
                    
                    // Update unread count if it was unread
                    const wasUnread = notificationElement.classList.contains('bg-dark');
                    if (wasUnread) {
                        updateUnreadCount(-1);
                    }
                }
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to delete notification');
        });
    }

    // Update unread count
    function updateUnreadCount(change) {
        const unreadCount = document.getElementById('unread-count');
        const statsCard = document.querySelector('.stat-icon.bg-danger').parentElement.nextElementSibling.querySelector('h4');
        
        if (unreadCount && statsCard) {
            const currentCount = parseInt(unreadCount.textContent) || 0;
            const newCount = Math.max(0, currentCount + change);
            
            unreadCount.textContent = newCount;
            statsCard.textContent = newCount;
            
            if (newCount === 0) {
                unreadCount.style.display = 'none';
                const markAllButton = document.querySelector('button[onclick="markAllNotificationsRead()"]');
                if (markAllButton) {
                    markAllButton.style.display = 'none';
                }
            }
        }
    }
    </script>
</body>
</html>
