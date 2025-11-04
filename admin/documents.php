<?php
require_once '../config/session-security.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/system-settings.php';
require_once '../config/config.php';

// Start secure session first
startSecureSession();
requireAdmin();

$success = '';
$error = '';

// Handle document actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_document'])) {
        $employeeId = intval($_POST['employee_id']);
        $documentName = sanitizeInput($_POST['document_name']);
        
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/';
            $fileName = 'doc_' . $employeeId . '_' . time() . '_' . $_FILES['document_file']['name'];
            $uploadPath = $uploadDir . $fileName;
            
            // Validate file type
            $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            $fileExtension = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
            
            if (in_array($fileExtension, $allowedTypes) && $_FILES['document_file']['size'] <= 10000000) {
                if (move_uploaded_file($_FILES['document_file']['tmp_name'], $uploadPath)) {
                    try {
                        // Save document info to database
                        $stmt = $db->prepare("
                            INSERT INTO file_uploads (user_id, file_name, original_name, file_path, file_type, file_size, category) 
                            VALUES (?, ?, ?, ?, ?, ?, 'document')
                        ");
                        $stmt->execute([
                            $employeeId, 
                            $fileName, 
                            $documentName ?: $_FILES['document_file']['name'], 
                            $uploadPath, 
                            $fileExtension, 
                            $_FILES['document_file']['size']
                        ]);
                        
                        // Log activity
                        logActivity($_SESSION['user_id'], 'admin_document_upload', 'file_uploads', $db->lastInsertId(), "Uploaded document for employee ID: $employeeId");
                        
                        $success = 'Document uploaded successfully for employee!';
                    } catch (Exception $e) {
                        $error = 'Failed to save document info: ' . $e->getMessage();
                    }
                } else {
                    $error = 'Failed to upload document';
                }
            } else {
                $error = 'Invalid file type or size too large (max 10MB)';
            }
        } else {
            $error = 'Please select a file to upload';
        }
    } elseif (isset($_POST['delete_document'])) {
        $documentId = intval($_POST['document_id']);
        
        // Get document info before deletion
        $stmt = $db->prepare("SELECT file_name, file_path, user_id FROM file_uploads WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            // Delete file from filesystem
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM file_uploads WHERE id = ?");
            $stmt->execute([$documentId]);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'admin_document_delete', 'file_uploads', $documentId, "Deleted document for employee ID: " . $document['user_id']);
            
            $success = 'Document deleted successfully!';
        } else {
            $error = 'Document not found';
        }
    }
}

// Get filter parameters
$employeeFilter = isset($_GET['employee']) ? intval($_GET['employee']) : '';
$typeFilter = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';

// Build query with filters
$whereConditions = ["f.category = 'document'"];
$params = [];

if (!empty($employeeFilter)) {
    $whereConditions[] = "f.user_id = ?";
    $params[] = $employeeFilter;
}

if (!empty($typeFilter)) {
    $whereConditions[] = "f.file_type = ?";
    $params[] = $typeFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM file_uploads f 
    JOIN users u ON f.user_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    {$whereClause}
");
$countStmt->execute($params);
$totalDocuments = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalDocuments / $perPage);

// Get employee documents with employee info - newest first
$stmt = $db->prepare("
    SELECT f.*, 
           u.employee_number, 
           ep.first_name, 
           ep.last_name, 
           ep.department
    FROM file_uploads f 
    JOIN users u ON f.user_id = u.id 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    {$whereClause}
    ORDER BY f.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all employees for dropdown
$stmt = $db->prepare("
    SELECT u.id, u.employee_number, ep.first_name, ep.last_name, ep.department
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.role = 'employee' 
    ORDER BY ep.first_name, ep.last_name
");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get document statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_documents,
        COUNT(DISTINCT f.user_id) as employees_with_docs,
        SUM(f.file_size) as total_size,
        COUNT(CASE WHEN f.file_type = 'pdf' THEN 1 END) as pdf_count,
        COUNT(CASE WHEN f.file_type IN ('jpg', 'jpeg', 'png') THEN 1 END) as image_count,
        COUNT(CASE WHEN f.file_type IN ('doc', 'docx') THEN 1 END) as doc_count
    FROM file_uploads f
    WHERE f.category = 'document'
");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Documents - AppNomu SalesQ</title>
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
            transition: transform 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .table-responsive {
            border-radius: 8px;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            font-size: 0.875rem;
            color: #495057;
        }
        
        .table td {
            vertical-align: middle;
            font-size: 0.875rem;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .file-icon {
            font-size: 1.5rem;
        }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .pagination-sm .page-link {
            padding: 0.25rem 0.5rem;
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
                        <a class="nav-link active" href="documents.php">
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
                            <h2><i class="fas fa-folder-open me-2"></i>Employee Documents</h2>
                            <p class="text-muted">Manage employee documents and file uploads</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="fas fa-plus me-2"></i>Upload Document
                        </button>
                    </div>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stats-card text-center">
                                <h3 class="text-primary"><?php echo $stats['total_documents']; ?></h3>
                                <small class="text-muted">Total Documents</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card text-center">
                                <h3 class="text-success"><?php echo $stats['employees_with_docs']; ?></h3>
                                <small class="text-muted">Employees with Docs</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card text-center">
                                <h3 class="text-info"><?php echo formatFileSize($stats['total_size']); ?></h3>
                                <small class="text-muted">Total Storage</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card text-center">
                                <div class="row">
                                    <div class="col-4">
                                        <small class="text-danger d-block"><?php echo $stats['pdf_count']; ?></small>
                                        <small class="text-muted">PDF</small>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-primary d-block"><?php echo $stats['doc_count']; ?></small>
                                        <small class="text-muted">DOC</small>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-success d-block"><?php echo $stats['image_count']; ?></small>
                                        <small class="text-muted">IMG</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Employee</label>
                                    <select name="employee" class="form-select">
                                        <option value="">All Employees</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>" <?php echo $employeeFilter == $employee['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">File Type</label>
                                    <select name="type" class="form-select">
                                        <option value="">All Types</option>
                                        <option value="pdf" <?php echo $typeFilter === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                                        <option value="doc" <?php echo $typeFilter === 'doc' ? 'selected' : ''; ?>>DOC</option>
                                        <option value="docx" <?php echo $typeFilter === 'docx' ? 'selected' : ''; ?>>DOCX</option>
                                        <option value="jpg" <?php echo $typeFilter === 'jpg' ? 'selected' : ''; ?>>JPG</option>
                                        <option value="png" <?php echo $typeFilter === 'png' ? 'selected' : ''; ?>>PNG</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter me-1"></i>Filter
                                        </button>
                                        <a href="documents.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i>Clear
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Documents Table -->
                    <div class="card">
                        <div class="card-body p-0">
                            <?php if (empty($documents)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-folder-open fa-5x text-muted mb-3"></i>
                                    <h4 class="text-muted">No documents found</h4>
                                    <p class="text-muted">Upload documents for employees to get started</p>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                        <i class="fas fa-plus me-2"></i>Upload Document
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">Type</th>
                                                <th width="30%">Document Name</th>
                                                <th width="20%">Employee</th>
                                                <th width="10%">Size</th>
                                                <th width="15%">Upload Date</th>
                                                <th width="20%">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($documents as $doc): ?>
                                                <?php
                                                $iconClass = 'fas fa-file';
                                                $iconColor = 'text-secondary';
                                                
                                                switch (strtolower($doc['file_type'])) {
                                                    case 'pdf':
                                                        $iconClass = 'fas fa-file-pdf';
                                                        $iconColor = 'text-danger';
                                                        break;
                                                    case 'doc':
                                                    case 'docx':
                                                        $iconClass = 'fas fa-file-word';
                                                        $iconColor = 'text-primary';
                                                        break;
                                                    case 'jpg':
                                                    case 'jpeg':
                                                    case 'png':
                                                        $iconClass = 'fas fa-file-image';
                                                        $iconColor = 'text-success';
                                                        break;
                                                }
                                                ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <i class="<?= $iconClass ?> file-icon <?= $iconColor ?>"></i>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($doc['original_name']) ?>">
                                                            <?= htmlspecialchars($doc['original_name']) ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= strtoupper($doc['file_type']) ?> Document
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold">
                                                            <?= htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= htmlspecialchars($doc['employee_number']) ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark">
                                                            <?= formatFileSize($doc['file_size']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div><?= date('M j, Y', strtotime($doc['created_at'])) ?></div>
                                                        <small class="text-muted"><?= date('g:i A', strtotime($doc['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <?php if (in_array(strtolower($doc['file_type']), ['pdf', 'jpg', 'jpeg', 'png'])): ?>
                                                                <button onclick="viewDocument('<?= htmlspecialchars($doc['file_name']) ?>', '<?= htmlspecialchars($doc['original_name']) ?>', '<?= strtolower($doc['file_type']) ?>')" 
                                                                        class="btn btn-outline-primary" title="View Document">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <a href="../uploads/<?= htmlspecialchars($doc['file_name']) ?>" 
                                                               download="<?= htmlspecialchars($doc['original_name']) ?>" 
                                                               class="btn btn-outline-success" title="Download">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <button onclick="deleteDocument(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['original_name']) ?>')" 
                                                                    class="btn btn-outline-danger" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                    <div class="card-footer">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="text-muted small">
                                                Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $totalDocuments); ?> of <?php echo $totalDocuments; ?> documents
                                            </div>
                                            <nav>
                                                <ul class="pagination pagination-sm mb-0">
                                                    <?php if ($page > 1): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                                <i class="fas fa-chevron-left"></i>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    $startPage = max(1, $page - 2);
                                                    $endPage = min($totalPages, $page + 2);
                                                    
                                                    for ($i = $startPage; $i <= $endPage; $i++): 
                                                    ?>
                                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                                <?php echo $i; ?>
                                                            </a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php if ($page < $totalPages): ?>
                                                        <li class="page-item">
                                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                                <i class="fas fa-chevron-right"></i>
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </nav>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Upload Document for Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select Employee *</label>
                            <select class="form-select" name="employee_id" required>
                                <option value="">Choose employee...</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['id']; ?>">
                                        <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Document Name</label>
                            <input type="text" class="form-control" name="document_name" 
                                   placeholder="Enter document name (optional)">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Select File *</label>
                            <input type="file" class="form-control" name="document_file" required
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small class="text-muted">
                                Supported formats: PDF, DOC, DOCX, JPG, PNG<br>
                                Maximum size: 10MB
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_document" class="btn btn-primary">
                            <i class="fas fa-upload me-2"></i>Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentModalLabel">Document Viewer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="height: 80vh;">
                    <div id="documentViewer" class="w-100 h-100 d-flex align-items-center justify-content-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a id="downloadBtn" href="#" download class="btn btn-primary">
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this document?</p>
                    <p class="text-muted mb-0"><strong id="deleteDocumentName"></strong></p>
                    <small class="text-danger">This action cannot be undone.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="document_id" id="deleteDocumentId">
                        <button type="submit" name="delete_document" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>Delete Document
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function viewDocument(fileName, originalName, fileType) {
        const modal = new bootstrap.Modal(document.getElementById('documentModal'));
        const viewer = document.getElementById('documentViewer');
        const modalTitle = document.getElementById('documentModalLabel');
        const downloadBtn = document.getElementById('downloadBtn');
        
        modalTitle.textContent = originalName;
        downloadBtn.href = '../uploads/' + fileName;
        downloadBtn.download = originalName;
        
        viewer.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
        modal.show();
        
        setTimeout(() => {
            if (fileType === 'pdf') {
                viewer.innerHTML = `<iframe src="../uploads/${fileName}" class="w-100 h-100 border-0" style="min-height: 600px;"></iframe>`;
            } else if (['jpg', 'jpeg', 'png'].includes(fileType)) {
                viewer.innerHTML = `<div class="text-center w-100 h-100 d-flex align-items-center justify-content-center"><img src="../uploads/${fileName}" class="img-fluid" style="max-height: 100%; max-width: 100%; object-fit: contain;" alt="${originalName}"></div>`;
            }
        }, 500);
    }
    
    function deleteDocument(id, name) {
        document.getElementById('deleteDocumentId').value = id;
        document.getElementById('deleteDocumentName').textContent = name;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
    </script>
</body>
</html>
