<?php
// Backup the current login.php and create a clean version
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/infobip.php';

// Start secure session first
startSecureSession();

// Security: Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// Redirect if already logged in
if (isAuthenticated()) {
    header('Location: ../index.php');
    exit();
}

$error = '';
$clientIP = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($_POST) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $deliveryMethod = 'email'; // Default to email, user can choose in OTP verification
        
        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields';
        } elseif (!isValidEmail($email)) {
            $error = 'Please enter a valid email address';
        } else {
            // Check user credentials
            $stmt = $db->prepare("
                SELECT u.*, ep.first_name, ep.last_name 
                FROM users u 
                LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                WHERE u.email = ? AND u.status = 'active'
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if ($user && verifyPassword($password, $user['password_hash'])) {
                // Store user info in session temporarily (no OTP generation yet)
                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_user_data'] = $user;
                
                // Log activity
                logActivity($user['id'], 'login_attempt', 'users', $user['id']);
                
                // Security: Regenerate session ID
                session_regenerate_id(true);
                
                // Redirect to OTP verification page where user will choose delivery method
                header('Location: verify-otp.php');
                exit();
            } else {
                $error = 'Invalid email or password';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Login - AppNomu SalesQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            background-color: #ffffff !important;
            color: #495057 !important;
        }
        .form-control:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
            background-color: #ffffff !important;
        }
        .form-control::placeholder {
            color: #6c757d !important;
            opacity: 1;
        }
        .btn-login {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="login-card p-5">
                    <div class="text-center mb-4">
                        <img src="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png" 
                             alt="AppNomu SalesQ" 
                             style="max-height: 80px; margin-bottom: 20px;">
                        <h2 class="fw-bold">AppNomu SalesQ</h2>
                        <p class="text-muted">Employee Management System</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" required 
                                   placeholder="Enter your email address"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required 
                                   placeholder="Enter your password">
                        </div>


                        <button type="submit" class="btn btn-login w-100 py-2 text-white">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <small class="text-muted">
                            Secure login with OTP verification
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
