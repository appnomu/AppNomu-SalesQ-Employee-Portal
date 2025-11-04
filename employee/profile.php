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

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = sanitizeInput($_POST['first_name']);
    $lastName = sanitizeInput($_POST['last_name']);
    $middleName = sanitizeInput($_POST['middle_name']);
    $dateOfBirth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $gender = !empty($_POST['gender']) ? $_POST['gender'] : null;
    $address = sanitizeInput($_POST['address']);
    $phone = sanitizeInput($_POST['phone']);
    
    // Handle profile picture upload
    $profilePicture = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/';
        $fileName = 'profile_' . $userId . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
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
                $error = 'Failed to upload profile picture. Check directory permissions.';
            }
        } else {
            $error = 'Invalid file type or size too large (max 5MB)';
        }
    } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle upload errors
        switch ($_FILES['profile_picture']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = 'File too large';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error = 'File upload incomplete';
                break;
            default:
                $error = 'File upload failed';
        }
    }
    
    if (empty($error)) {
        try {
            // Update user phone
            $stmt = $db->prepare("UPDATE users SET phone = ? WHERE id = ?");
            $stmt->execute([$phone, $userId]);
            
            // Check if profile exists
            $stmt = $db->prepare("SELECT id FROM employee_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $profileExists = $stmt->fetch();
            
            if ($profileExists) {
                // Update existing profile
                $stmt = $db->prepare("
                    UPDATE employee_profiles 
                    SET first_name = ?, last_name = ?, middle_name = ?, 
                        date_of_birth = ?, gender = ?, address = ?" . 
                        ($profilePicture ? ", profile_picture = ?" : "") . "
                    WHERE user_id = ?
                ");
                
                $params = [$firstName, $lastName, $middleName, $dateOfBirth, $gender, $address];
                if ($profilePicture) {
                    $params[] = $profilePicture;
                }
                $params[] = $userId;
                
                $stmt->execute($params);
            } else {
                // Insert new profile
                $stmt = $db->prepare("
                    INSERT INTO employee_profiles 
                    (user_id, first_name, last_name, middle_name, date_of_birth, gender, address, profile_picture) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $firstName, $lastName, $middleName, $dateOfBirth, $gender, $address, $profilePicture]);
            }
            
            // Log activity
            logActivity($userId, 'profile_update', 'employee_profiles', $userId);
            
            $success = 'Profile updated successfully!';
        } catch (Exception $e) {
            $error = 'Failed to update profile: ' . $e->getMessage();
        }
    }
}

// Get current profile data (fetch after potential update)
$stmt = $db->prepare("
    SELECT u.id, u.email, u.phone, u.employee_number, u.role,
           ep.first_name, ep.last_name, ep.middle_name, ep.date_of_birth, ep.gender, ep.address, ep.profile_picture,
           ep.department, ep.position, ep.salary
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>My Profile - AppNomu SalesQ</title>
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
        .profile-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: 1px solid #404040;
        }
        .profile-header {
            background: linear-gradient(135deg, #1e1e1e 0%, #2a2a2a 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 1rem;
            border-bottom: 1px solid #404040;
        }
        .profile-picture {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid white;
            object-fit: cover;
        }
        .btn-primary {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            border: none;
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
                        <a class="nav-link active" href="profile.php">
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
                        <a class="nav-link" href="documents.php">
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
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <?php if (!empty($profile['profile_picture']) && file_exists(__DIR__ . '/../uploads/' . $profile['profile_picture'])): ?>
                                    <img src="../uploads/<?= htmlspecialchars($profile['profile_picture']) ?>" 
                                         alt="Profile Picture" class="profile-picture">
                                <?php else: ?>
                                    <div class="profile-picture bg-light d-flex align-items-center justify-content-center">
                                        <i class="fas fa-user fa-2x text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col">
                                <h4 class="mb-1"><?= htmlspecialchars($profile['first_name'] ?? 'Employee') ?> <?= htmlspecialchars($profile['last_name'] ?? '') ?></h4>
                                <p class="mb-1"><?= htmlspecialchars($profile['employee_number']) ?></p>
                                <small><?= htmlspecialchars($profile['position'] ?? 'Employee') ?> - <?= htmlspecialchars($profile['department'] ?? 'General') ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="p-3">
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

                        <form method="POST" enctype="multipart/form-data">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" 
                                           value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" 
                                           value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" 
                                       value="<?= htmlspecialchars($profile['middle_name'] ?? '') ?>">
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" name="date_of_birth" 
                                           value="<?= htmlspecialchars($profile['date_of_birth'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Gender</label>
                                    <select class="form-control" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="male" <?= ($profile['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                                        <option value="female" <?= ($profile['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                        <option value="other" <?= ($profile['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Phone Number *</label>
                                <input type="tel" class="form-control" name="phone" 
                                       value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" rows="3"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Profile Picture</label>
                                <input type="file" class="form-control" name="profile_picture" accept="image/*">
                                <small class="text-muted">Max size: 5MB. Formats: JPG, PNG, GIF</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email (Read-only)</label>
                                    <input type="email" class="form-control" value="<?= htmlspecialchars($profile['email']) ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Employee Number (Read-only)</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($profile['employee_number']) ?>" readonly>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Department (Read-only)</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($profile['department'] ?? 'Not assigned') ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Position (Read-only)</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($profile['position'] ?? 'Not assigned') ?>" readonly>
                                </div>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
