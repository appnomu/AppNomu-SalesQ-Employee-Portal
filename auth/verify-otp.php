<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/infobip.php';

// Start secure session first
startSecureSession();

// Redirect if no temp user session
if (!isset($_SESSION['temp_user_id'])) {
    error_log('Verify OTP: No temp_user_id in session, redirecting to login');
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

// Handle form submission
if ($_POST) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Security validation failed. Please try again.';
    } elseif (isset($_POST['otp_code'])) {
        $otpCode = sanitizeInput($_POST['otp_code']);
        $deliveryMethod = sanitizeInput($_POST['delivery_method'] ?? 'email');
        // Verify OTP
        $currentTime = date('Y-m-d H:i:s');
        $stmt = $db->prepare("
            SELECT *, expires_at, created_at, is_used FROM otp_verifications 
            WHERE user_id = ? AND otp_code = ? AND otp_type = 'login' 
            AND expires_at > ? AND is_used = FALSE
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$_SESSION['temp_user_id'], $otpCode, $currentTime]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($otpRecord) {
            // Mark OTP as used
            $stmt = $db->prepare("UPDATE otp_verifications SET is_used = TRUE WHERE id = ?");
            $stmt->execute([$otpRecord['id']]);
            
            // Complete login
            $user = $_SESSION['temp_user_data'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['employee_number'] = $user['employee_number'];
            
            // Clear temp data
            unset($_SESSION['temp_user_id'], $_SESSION['temp_user_data']);
            
            // Log successful login
            logActivity($user['id'], 'login_success', 'users', $user['id']);
            
            // Redirect based on user role
            if ($user['role'] === 'admin') {
                header('Location: ../admin/dashboard');
            } else {
                header('Location: ../employee/dashboard');
            }
            exit();
        } else {
            $error = 'Invalid or expired OTP code';
        }
    }
}

// Handle resend OTP
if (isset($_GET['resend'])) {
    $deliveryMethod = sanitizeInput($_GET['method'] ?? 'email');
    $user = $_SESSION['temp_user_data'];
    
    // Generate new OTP
    $otpCode = generateOTP();
    $expiresAt = date('Y-m-d H:i:s', time() + (OTP_EXPIRY_MINUTES * 60));
    
    // Store new OTP
    $stmt = $db->prepare("
        INSERT INTO otp_verifications (user_id, otp_code, otp_type, delivery_method, expires_at) 
        VALUES (?, ?, 'login', ?, ?)
    ");
    $stmt->execute([$user['id'], $otpCode, $deliveryMethod, $expiresAt]);
    
    try {
        require_once '../includes/infobip.php';
        $recipient = ($deliveryMethod === 'email') ? $user['email'] : $user['phone'];
        
        // Get device info for email OTP
        $deviceInfo = [
            'browser' => getBrowser($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'os' => getOS($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ];
        
        $otpResult = sendOTP($user['id'], $otpCode, $deliveryMethod, $recipient, 'login', $deviceInfo);
        
        if ($otpResult) {
            $success = 'New OTP sent successfully via ' . ucfirst($deliveryMethod) . '!';
        } else {
            throw new Exception('OTP sending failed');
        }
    } catch (Exception $e) {
        $error = 'Failed to send OTP via ' . ucfirst($deliveryMethod) . '. Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - EP Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #e3f2fd 0%, #ffffff 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .otp-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-top: 5vh;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        .header-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 2rem;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
        }
        .logo {
            height: 60px;
            margin-bottom: 1rem;
        }
        .card-body {
            padding: 2rem;
        }
        .delivery-method {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 0.5rem;
        }
        .delivery-method:hover {
            border-color: #007bff;
            background-color: #f8f9ff;
        }
        .delivery-method.active {
            border-color: #007bff;
            background-color: #007bff;
            color: white;
        }
        .delivery-method i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .otp-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            margin: 0 5px;
        }
        .otp-input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
            border-color: #007bff;
            color: white;
        }
        .header-section {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        .content-section {
            padding: 20px;
        }
        .logo {
            max-height: 50px;
            margin-bottom: 15px;
        }
        .otp-container {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid #dee2e6;
        }
        .resend-section {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
            border: 1px solid #dee2e6;
        }
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
        }
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }
        .alert-success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
            color: white;
        }
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 10px;
        }
        .btn-outline-primary {
            border: 2px solid #28a745;
            color: #28a745;
            background: #ffffff;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 12px 30px;
        }
        .btn-outline-primary:hover {
            background: #28a745;
            border-color: #28a745;
            color: white;
            transform: translateY(-1px);
        }
        .text-muted a {
            color: #6c757d;
            text-decoration: none;
        }
        .text-muted a:hover {
            color: #495057;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="otp-card">
                    <div class="header-section">
                        <img src="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png" 
                             alt="AppNomu SalesQ" class="logo">
                        <h2 class="mb-2" style="color: #495057;">Verify Your Identity</h2>
                        <p class="mb-0" style="color: #6c757d;">Complete verification to access your account</p>
                    </div>
                    <div class="card-body">
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!$success): ?>
                    <!-- Step 1: Choose delivery method and send OTP -->
                    <div id="deliveryStep" class="mb-4">
                        <div class="text-center mb-4">
                            <h4>Choose how to receive your OTP</h4>
                        </div>
                        
                        <div class="mb-3">
                            <h5 class="text-center mb-3">Delivery Method</h5>
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="delivery-method active text-center" data-method="email">
                                        <i class="fas fa-envelope me-1"></i><br><small>Email</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="delivery-method text-center" data-method="sms">
                                        <i class="fas fa-sms me-1"></i><br><small>SMS</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="delivery-method text-center" data-method="whatsapp">
                                        <i class="fab fa-whatsapp me-1"></i><br><small>WhatsApp</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-primary w-100 mb-3" onclick="sendOTP()">
                            <i class="fas fa-paper-plane me-2"></i>Send OTP Code
                        </button>
                        
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fab fa-whatsapp text-success me-1"></i>
                                WhatsApp OTP only works if your number is registered on WhatsApp
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                    <!-- Step 2: Enter OTP -->
                    <div id="otpStep">
                        <form method="POST" id="otpForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="mb-3">
                                <label class="form-label text-center d-block">🔐 Enter 6-Digit OTP</label>
                                <div class="otp-inputs d-flex justify-content-center gap-2 mb-3">
                                    <input type="text" class="otp-input" maxlength="1" data-index="0">
                                    <input type="text" class="otp-input" maxlength="1" data-index="1">
                                    <input type="text" class="otp-input" maxlength="1" data-index="2">
                                    <input type="text" class="otp-input" maxlength="1" data-index="3">
                                    <input type="text" class="otp-input" maxlength="1" data-index="4">
                                    <input type="text" class="otp-input" maxlength="1" data-index="5">
                                </div>
                                <input type="hidden" name="otp_code" id="otpCodeInput">
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-shield-alt me-2"></i>Verify OTP
                            </button>
                        </form>
                    </div>
                    
                    <!-- Resend section (only shown after OTP is sent) -->
                    <div id="resendSection" class="resend-section text-center">
                        <p class="mb-2"><small>Didn't receive the code?</small></p>
                        <div class="row g-1">
                            <div class="col-4">
                                <a href="?resend=1&method=email" class="btn btn-outline-primary btn-sm w-100 py-1">
                                    <i class="fas fa-envelope"></i><br><small>Email</small>
                                </a>
                            </div>
                            <div class="col-4">
                                <a href="?resend=1&method=sms" class="btn btn-outline-primary btn-sm w-100 py-1">
                                    <i class="fas fa-sms"></i><br><small>SMS</small>
                                </a>
                            </div>
                            <div class="col-4">
                                <a href="?resend=1&method=whatsapp" class="btn btn-outline-primary btn-sm w-100 py-1">
                                    <i class="fab fa-whatsapp"></i><br><small>WhatsApp</small>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="text-center mt-4">
                        <a href="login.php" class="text-muted">
                            <i class="fas fa-arrow-left me-1"></i>Back to Login
                        </a>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedMethod = 'email';
        
        // Delivery method selection
        document.querySelectorAll('.delivery-method').forEach(method => {
            method.addEventListener('click', function() {
                document.querySelectorAll('.delivery-method').forEach(m => m.classList.remove('active'));
                this.classList.add('active');
                selectedMethod = this.getAttribute('data-method');
            });
        });
        
        // Send OTP function
        function sendOTP() {
            // Show loading state
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
            
            // Redirect to send OTP
            window.location.href = '?resend=1&method=' + selectedMethod;
        }
        
        // OTP input handling (only if OTP inputs exist)
        const otpInputs = document.querySelectorAll('.otp-input');
        const otpCodeInput = document.getElementById('otpCodeInput');
        
        if (otpInputs.length > 0) {
            otpInputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    const value = e.target.value;
                    if (value && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }
                    updateOTPCode();
                });
                
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !e.target.value && index > 0) {
                        otpInputs[index - 1].focus();
                    }
                });
                
                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text');
                    if (pastedData.length === 6) {
                        pastedData.split('').forEach((char, i) => {
                            if (i < otpInputs.length) {
                                otpInputs[i].value = char;
                            }
                        });
                        updateOTPCode();
                    }
                });
            });
            
            function updateOTPCode() {
                const code = Array.from(otpInputs).map(input => input.value).join('');
                if (otpCodeInput) {
                    otpCodeInput.value = code;
                }
            }
            
            // Auto-submit when OTP is complete
            const otpForm = document.getElementById('otpForm');
            if (otpForm) {
                otpInputs.forEach(input => {
                    input.addEventListener('input', () => {
                        if (otpCodeInput.value.length === 6) {
                            setTimeout(() => {
                                otpForm.submit();
                            }, 500);
                        }
                    });
                });
            }
        }
    </script>
</body>
</html>
