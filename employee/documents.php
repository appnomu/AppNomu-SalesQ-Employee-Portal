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
$success = '';
$error = '';

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $documentName = sanitizeInput($_POST['document_name']);
    $documentType = sanitizeInput($_POST['document_type']);
    
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/';
        $fileName = 'doc_' . $userId . '_' . time() . '_' . $_FILES['document_file']['name'];
        $uploadPath = $uploadDir . $fileName;
        
        // Validate file type
        $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $fileExtension = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
        
        if (in_array($fileExtension, $allowedTypes) && $_FILES['document_file']['size'] <= 10000000) {
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $uploadPath)) {
                // Set proper file permissions for web access
                chmod($uploadPath, 0644);
                try {
                    // Save document info to database
                    $stmt = $db->prepare("
                        INSERT INTO file_uploads (user_id, file_name, original_name, file_path, file_type, file_size, category) 
                        VALUES (?, ?, ?, ?, ?, ?, 'document')
                    ");
                    $stmt->execute([
                        $userId, 
                        $fileName, 
                        $documentName ?: $_FILES['document_file']['name'], 
                        $uploadPath, 
                        $fileExtension, 
                        $_FILES['document_file']['size']
                    ]);
                    
                    // Log activity
                    logActivity($userId, 'document_upload', 'file_uploads', $db->lastInsertId());
                    
                    $success = 'Document uploaded successfully!';
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
}

// Document deletion is restricted to admin only
// Employees can only view and download their documents

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$documentsPerPage = 6;
$offset = ($page - 1) * $documentsPerPage;

// Get total count for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) as total
    FROM file_uploads 
    WHERE user_id = ? AND category = 'document'
");
$countStmt->execute([$userId]);
$totalDocuments = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalDocuments / $documentsPerPage);

// Get paginated user documents
$stmt = $db->prepare("
    SELECT * FROM file_uploads 
    WHERE user_id = ? AND category = 'document' 
    ORDER BY created_at DESC
    LIMIT $documentsPerPage OFFSET $offset
");
$stmt->execute([$userId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        body {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            min-height: 100vh;
            color: #e0e0e0;
        }
        .sidebar {
            background: linear-gradient(135deg, #1e1e1e 0%, #2a2a2a 100%);
            min-height: 100vh;
            box-shadow: 2px 0 5px rgba(0,0,0,0.3);
        }
        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            padding: 12px 15px;
            margin: 2px 0;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white !important;
        }
        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white !important;
            font-weight: 600;
        }
        .document-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .file-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
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
        .form-control {
            background-color: #3a3a3a;
            border: 1px solid #555;
            color: #e0e0e0;
        }
        .form-control:focus {
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
                        <a class="nav-link" href="leave-requests.php">
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
                        <a class="nav-link active" href="documents.php">
                            <i class="fas fa-file-pdf me-2"></i>Documents
                        </a>
                        <a class="nav-link" href="reminders.php">
                            <i class="fas fa-bell me-2"></i>Reminders
                        </a>
                    </nav>
                    
                    <div class="mt-auto pt-4">
                        <a href="../auth/logout.php" class="nav-link text-light">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-folder-open me-2"></i>My Documents</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="fas fa-plus me-2"></i>Upload Document
                    </button>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($documents)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-folder-open fa-5x text-muted mb-3"></i>
                        <h4 class="text-muted">No documents uploaded yet</h4>
                        <p class="text-muted">Upload your first document to get started</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="fas fa-plus me-2"></i>Upload Document
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($documents as $doc): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="document-card p-4 h-100">
                                    <div class="text-center">
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
                                        <i class="<?= $iconClass ?> file-icon <?= $iconColor ?>"></i>
                                        <h6 class="mb-2"><?= htmlspecialchars($doc['original_name']) ?></h6>
                                        <small class="text-muted d-block mb-2">
                                            <?= strtoupper($doc['file_type']) ?> • <?= formatFileSize($doc['file_size']) ?>
                                        </small>
                                        <small class="text-muted d-block mb-3">
                                            Uploaded: <?= date('M j, Y', strtotime($doc['created_at'])) ?>
                                        </small>
                                        
                                        <div class="btn-group w-100">
                                            <?php if (in_array(strtolower($doc['file_type']), ['pdf', 'jpg', 'jpeg', 'png'])): ?>
                                                <button onclick="viewDocument('<?= htmlspecialchars($doc['file_name']) ?>', '<?= htmlspecialchars($doc['original_name']) ?>', '<?= strtolower($doc['file_type']) ?>')" 
                                                        class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-eye me-1"></i>View
                                                </button>
                                            <?php endif; ?>
                                            <a href="../uploads/<?= htmlspecialchars($doc['file_name']) ?>" 
                                               download="<?= htmlspecialchars($doc['original_name']) ?>" 
                                               class="btn btn-outline-success btn-sm">
                                                <i class="fas fa-download me-1"></i>Download
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Documents pagination" class="mt-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted small">
                                    Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $documentsPerPage, $totalDocuments); ?> of <?php echo $totalDocuments; ?> documents
                                </div>
                                <ul class="pagination pagination-sm mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link bg-dark border-secondary text-light" href="?page=<?php echo ($page - 1); ?>">
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
                                            <a class="page-link <?php echo $i == $page ? 'bg-primary border-primary' : 'bg-dark border-secondary text-light'; ?>" 
                                               href="?page=<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link bg-dark border-secondary text-light" href="?page=<?php echo ($page + 1); ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Document Name</label>
                            <input type="text" class="form-control" name="document_name" 
                                   placeholder="Enter document name (optional)">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Document Type</label>
                            <select class="form-control" name="document_type">
                                <option value="">Select type (optional)</option>
                                <option value="contract">Contract</option>
                                <option value="certificate">Certificate</option>
                                <option value="id_document">ID Document</option>
                                <option value="resume">Resume/CV</option>
                                <option value="other">Other</option>
                            </select>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function viewDocument(fileName, originalName, fileType) {
        const modal = new bootstrap.Modal(document.getElementById('documentModal'));
        const viewer = document.getElementById('documentViewer');
        const modalTitle = document.getElementById('documentModalLabel');
        const downloadBtn = document.getElementById('downloadBtn');
        
        // Set modal title and download link
        modalTitle.textContent = originalName;
        downloadBtn.href = '../uploads/' + fileName;
        downloadBtn.download = originalName;
        
        // Show loading spinner
        viewer.innerHTML = `
            <div class="d-flex justify-content-center align-items-center" style="height: 400px;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        
        // Show modal
        modal.show();
        
        // Load document content - EXACT COPY FROM ADMIN VERSION
        setTimeout(() => {
            if (fileType === 'pdf') {
                viewer.innerHTML = `<iframe src="../uploads/${fileName}" class="w-100 h-100 border-0" style="min-height: 600px;"></iframe>`;
            } else if (['jpg', 'jpeg', 'png'].includes(fileType)) {
                viewer.innerHTML = `<div class="text-center w-100 h-100 d-flex align-items-center justify-content-center"><img src="../uploads/${fileName}" class="img-fluid" style="max-height: 100%; max-width: 100%; object-fit: contain;" alt="${originalName}"></div>`;
            }
        }, 500);
    }
    
    // Handle file upload form submission
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';
        
        // Re-enable button after form submission (in case of errors)
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }, 5000);
    });
    </script>
</body>
</html>

<?php
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
