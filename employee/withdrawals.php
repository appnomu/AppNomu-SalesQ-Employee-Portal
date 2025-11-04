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

// Get employee profile with salary info
$stmt = $db->prepare("
    SELECT u.*, ep.* 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle withdrawal request
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'request_withdrawal') {
    $amount = floatval($_POST['amount']);
    $withdrawalType = sanitizeInput($_POST['withdrawal_type']);
    $paymentMethod = sanitizeInput($_POST['payment_method']);
    $deliveryMethod = sanitizeInput($_POST['delivery_method']);
    
    // Payment method specific fields
    $bankAccount = null;
    $bankName = null;
    $mobileNumber = null;
    $mobileMoneyProvider = null;
    
    if ($paymentMethod === 'bank_transfer') {
        $bankAccount = sanitizeInput($_POST['bank_account']);
        $bankName = sanitizeInput($_POST['bank_name']);
    } elseif ($paymentMethod === 'mobile_money') {
        $mobileNumber = sanitizeInput($_POST['mobile_number']);
        $mobileMoneyProvider = sanitizeInput($_POST['mobile_money_provider']);
    }
    
    // Calculate withdrawal fees
    $withdrawalFee = 0;
    if ($paymentMethod === 'mobile_money') {
        if ($amount >= 1000 && $amount < 100000) {
            $withdrawalFee = 1500;
        } elseif ($amount >= 100000) {
            $withdrawalFee = 5000;
        }
    } elseif ($paymentMethod === 'bank_transfer') {
        $withdrawalFee = 5000; // Flat rate for bank transfers
    }
    
    $netAmount = $amount - $withdrawalFee;
    
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = 'Please enter a valid amount';
    }
    
    if ($amount < 1000) {
        $errors[] = 'Minimum withdrawal amount is UGX 1,000';
    }
    
    if ($netAmount <= 0) {
        $errors[] = 'Amount is too low to cover withdrawal fees';
    }
    
    if ($withdrawalType === 'full_salary' && $amount != $employee['salary']) {
        $errors[] = 'Full salary amount must match your current salary';
    }
    
    if ($withdrawalType === 'partial' && $amount > $employee['salary']) {
        $errors[] = 'Partial withdrawal cannot exceed your salary';
    }
    
    if (empty($paymentMethod)) {
        $errors[] = 'Please select a payment method';
    }
    
    if ($paymentMethod === 'bank_transfer' && (empty($bankAccount) || empty($bankName))) {
        $errors[] = 'Please provide bank account details';
    }
    
    if ($paymentMethod === 'mobile_money' && (empty($mobileNumber) || empty($mobileMoneyProvider))) {
        $errors[] = 'Please provide mobile money details';
    }
    
    if ($paymentMethod === 'bank_transfer' && empty($employee['phone'])) {
        $errors[] = 'Please update your phone number in your profile for OTP delivery';
    }
    
    if ($paymentMethod === 'mobile_money' && !preg_match('/^\+256[0-9]{9}$/', $mobileNumber)) {
        $errors[] = 'Please enter a valid mobile number with country code (+256)';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Check available balance first
            $stmt = $db->prepare("SELECT salary, withdrawn_amount FROM employee_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $availableBalance = ($profile['salary'] ?? 0) - ($profile['withdrawn_amount'] ?? 0);
            
            if ($netAmount > $availableBalance) {
                throw new Exception("Insufficient balance. Available: UGX " . number_format($availableBalance));
            }
            
            // Immediately deduct the net amount to prevent duplicate withdrawals
            $stmt = $db->prepare("
                UPDATE employee_profiles 
                SET withdrawn_amount = COALESCE(withdrawn_amount, 0) + ? 
                WHERE user_id = ?
            ");
            $stmt->execute([$netAmount, $userId]);
            
            // Create withdrawal record with fees
            if ($paymentMethod === 'bank_transfer') {
                $stmt = $db->prepare("
                    INSERT INTO salary_withdrawals (employee_id, amount, withdrawal_fee, net_amount, withdrawal_type, payment_method, bank_account, bank_name, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_otp')
                ");
                $stmt->execute([$userId, $amount, $withdrawalFee, $netAmount, $withdrawalType, $paymentMethod, $bankAccount, $bankName]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO salary_withdrawals (employee_id, amount, withdrawal_fee, net_amount, withdrawal_type, payment_method, mobile_number, mobile_money_provider, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_otp')
                ");
                $stmt->execute([$userId, $amount, $withdrawalFee, $netAmount, $withdrawalType, $paymentMethod, $mobileNumber, $mobileMoneyProvider]);
            }
            
            $withdrawalId = $db->lastInsertId();
            
            // Generate OTP
            $otpCode = generateOTP();
            $expiresAt = date('Y-m-d H:i:s', time() + (OTP_EXPIRY_MINUTES * 60));
            
            $stmt = $db->prepare("
                INSERT INTO otp_verifications (user_id, otp_code, otp_type, delivery_method, expires_at) 
                VALUES (?, ?, 'withdrawal', ?, ?)
            ");
            $stmt->execute([$userId, $otpCode, $deliveryMethod, $expiresAt]);
            
            // Send OTP - use mobile number for both SMS and WhatsApp
            require_once '../includes/infobip.php';
            $otpMobileNumber = ($paymentMethod === 'mobile_money') ? $mobileNumber : $employee['phone'];
            
            if ($deliveryMethod === 'whatsapp') {
                // Use WhatsApp OTP template
                require_once '../includes/whatsapp.php';
                $whatsapp = new InfobipWhatsApp();
                $result = $whatsapp->sendOtp($otpMobileNumber, $otpCode, '2fa_auth');
                
                if (!$result['success']) {
                    throw new Exception('Failed to send WhatsApp OTP: ' . ($result['error'] ?? 'Unknown error'));
                }
            } else {
                // Send SMS OTP
                sendOTP($userId, $otpCode, 'sms', $otpMobileNumber, 'withdrawal');
            }
            
            // Store withdrawal ID in session for OTP verification
            $_SESSION['pending_withdrawal_id'] = $withdrawalId;
            
            $db->commit();
            
            logActivity($userId, 'withdrawal_requested', 'salary_withdrawals', $withdrawalId);
            
            header('Location: withdrawals.php?step=verify_otp');
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to process withdrawal request: ' . $e->getMessage();
        }
    } else {
        // If validation fails, don't proceed with withdrawal
        error_log("Withdrawal validation failed for user $userId: " . implode(', ', $errors));
    }
}

// Handle OTP verification
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $otpCode = trim($_POST['otp_code'] ?? '');
    $withdrawalId = $_SESSION['pending_withdrawal_id'] ?? 0;
    
    // Simple validation - just check if we have 6 digits
    if (empty($otpCode) || !preg_match('/^\d{6}$/', $otpCode)) {
        $errors[] = 'Please enter a valid 6-digit OTP code';
    } elseif (!$withdrawalId) {
        $errors[] = 'Invalid withdrawal session';
    } else {
        // Verify OTP - use NOW() for timezone consistency
        $stmt = $db->prepare("
            SELECT * FROM otp_verifications 
            WHERE user_id = ? AND otp_code = ? AND otp_type = 'withdrawal' 
            AND expires_at > NOW() AND is_used = 0
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId, $otpCode]);
        $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($otpRecord) {
            try {
                $db->beginTransaction();
                
                // Mark OTP as used
                $stmt = $db->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?");
                $stmt->execute([$otpRecord['id']]);
                
                // Get withdrawal details for automatic processing
                $stmt = $db->prepare("SELECT * FROM salary_withdrawals WHERE id = ? AND employee_id = ?");
                $stmt->execute([$withdrawalId, $userId]);
                $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$withdrawal) {
                    throw new Exception('Withdrawal not found');
                }
                
                // Process withdrawal automatically using Flutterwave
                require_once '../includes/flutterwave.php';
                $flutterwave = new FlutterwaveAPI();
                $reference = 'SALARY_' . time() . '_' . $withdrawalId;
                
                if ($withdrawal['payment_method'] === 'bank_transfer') {
                    // Get bank code
                    $bankCode = getBankCode($withdrawal['bank_name']);
                    
                    // Verify account first
                    $verification = $flutterwave->verifyAccount($withdrawal['bank_account'], $bankCode);
                    $accountName = $verification['data']['account_name'];
                    
                    // Initiate transfer
                    $transferResult = $flutterwave->initiateTransfer(
                        $withdrawal['net_amount'],
                        $bankCode,
                        $withdrawal['bank_account'],
                        $accountName,
                        $reference,
                        "Salary withdrawal - Employee #{$userId}",
                        'UGX'
                    );
                } else {
                    // Mobile money transfer
                    $transferResult = $flutterwave->initiateMobileMoneyTransfer(
                        $withdrawal['net_amount'],
                        $withdrawal['mobile_number'],
                        $withdrawal['mobile_money_provider'],
                        $reference,
                        "Salary withdrawal - Employee #{$userId}",
                        'UGX'
                    );
                }
                
                if ($transferResult['status'] === 'success') {
                    // Update withdrawal with transfer details
                    $stmt = $db->prepare("
                        UPDATE salary_withdrawals 
                        SET otp_verified_at = NOW(), status = 'processing', 
                            flutterwave_reference = ?, processed_at = NOW()
                        WHERE id = ? AND employee_id = ?
                    ");
                    $stmt->execute([$reference, $withdrawalId, $userId]);
                    
                    $successMessage = 'OTP verified! Your withdrawal is being processed and funds will be transferred shortly.';
                } else {
                    // Transfer failed, update status
                    $stmt = $db->prepare("
                        UPDATE salary_withdrawals 
                        SET otp_verified_at = NOW(), status = 'failed', 
                            failure_reason = ?
                        WHERE id = ? AND employee_id = ?
                    ");
                    $stmt->execute([$transferResult['message'], $withdrawalId, $userId]);
                    
                    $successMessage = 'OTP verified but transfer failed: ' . $transferResult['message'];
                }
                
                $db->commit();
                unset($_SESSION['pending_withdrawal_id']);
                
                logActivity($userId, 'withdrawal_processed_automatically', 'salary_withdrawals', $withdrawalId);
                
                redirectWithMessage('withdrawals.php', $successMessage, $transferResult['status'] === 'success' ? 'success' : 'warning');
                
            } catch (Exception $e) {
                $db->rollBack();
                
                // If OTP verification fails, refund the deducted amount
                $stmt = $db->prepare("SELECT net_amount FROM salary_withdrawals WHERE id = ?");
                $stmt->execute([$withdrawalId]);
                $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($withdrawal) {
                    $stmt = $db->prepare("
                        UPDATE employee_profiles 
                        SET withdrawn_amount = COALESCE(withdrawn_amount, 0) - ? 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$withdrawal['net_amount'], $userId]);
                    
                    // Mark withdrawal as failed
                    $stmt = $db->prepare("UPDATE salary_withdrawals SET status = 'failed' WHERE id = ?");
                    $stmt->execute([$withdrawalId]);
                }
                
                $errors[] = 'Failed to process withdrawal: ' . $e->getMessage();
            }
        } else {
            // OTP verification failed - refund the deducted amount
            if ($withdrawalId) {
                try {
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("SELECT net_amount FROM salary_withdrawals WHERE id = ?");
                    $stmt->execute([$withdrawalId]);
                    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($withdrawal) {
                        // Refund the amount
                        $stmt = $db->prepare("
                            UPDATE employee_profiles 
                            SET withdrawn_amount = COALESCE(withdrawn_amount, 0) - ? 
                            WHERE user_id = ?
                        ");
                        $stmt->execute([$withdrawal['net_amount'], $userId]);
                        
                        // Mark withdrawal as cancelled
                        $stmt = $db->prepare("UPDATE salary_withdrawals SET status = 'cancelled' WHERE id = ?");
                        $stmt->execute([$withdrawalId]);
                        
                        logActivity($userId, 'withdrawal_cancelled_invalid_otp', 'salary_withdrawals', $withdrawalId);
                    }
                    
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Failed to refund withdrawal amount: " . $e->getMessage());
                }
            }
            
            $errors[] = 'Invalid or expired OTP code. Your withdrawal has been cancelled and amount refunded.';
        }
    }
}

// Get withdrawal history
$stmt = $db->prepare("
    SELECT * FROM salary_withdrawals 
    WHERE employee_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$withdrawalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentStep = $_GET['step'] ?? 'request';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png">
    <title>Salary Withdrawal - AppNomu SalesQ</title>
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
        .table-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border: 1px solid #404040;
        }
        .withdrawal-card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
            border: 1px solid #404040;
        }
        .withdrawal-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        .step {
            display: flex;
            align-items: center;
            padding: 0 1rem;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 0.5rem;
        }
        .step.active .step-number {
            background-color: #28a745;
            color: white;
        }
        .step.completed .step-number {
            background-color: #20c997;
            color: white;
        }
        .step.pending .step-number {
            background-color: #e9ecef;
            color: #6c757d;
        }
        .otp-input {
            width: 60px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            margin: 0 5px;
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
                        <a class="nav-link active" href="withdrawals.php">
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
                        <h2>Salary Withdrawal</h2>
                        <div class="text-muted">
                            <i class="fas fa-shield-alt me-2"></i>Secure OTP Protected
                        </div>
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
                    
                    <!-- Salary Info Card -->
                    <div class="card withdrawal-card mb-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h4 class="mb-1">Current Salary</h4>
                                    <h2 class="mb-0"><?php echo formatCurrency($employee['salary'] ?? 0, 'UGX'); ?></h2>
                                    <small class="opacity-75">Available for withdrawal</small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <i class="fas fa-money-bill-wave fa-4x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($currentStep === 'verify_otp'): ?>
                        <!-- OTP Verification Step -->
                        <div class="step-indicator">
                            <div class="step completed">
                                <div class="step-number">1</div>
                                <span>Request</span>
                            </div>
                            <div class="mx-3">→</div>
                            <div class="step active">
                                <div class="step-number">2</div>
                                <span>Verify OTP</span>
                            </div>
                            <div class="mx-3">→</div>
                            <div class="step pending">
                                <div class="step-number">3</div>
                                <span>Process</span>
                            </div>
                        </div>
                        
                        <div class="row justify-content-center">
                            <div class="col-md-6">
                                <div class="card table-card">
                                    <div class="card-body text-center">
                                        <i class="fas fa-shield-alt fa-3x text-success mb-3"></i>
                                        <h4>Verify Your Identity</h4>
                                        <p style="color: #b0b0b0;">Enter the OTP code sent to your registered contact</p>
                                        
                                        <form method="POST">
                                            <input type="hidden" name="action" value="verify_otp">
                                            <div class="mb-4">
                                                <div class="d-flex justify-content-center">
                                                    <input type="text" class="otp-input" maxlength="1" data-index="0">
                                                    <input type="text" class="otp-input" maxlength="1" data-index="1">
                                                    <input type="text" class="otp-input" maxlength="1" data-index="2">
                                                    <input type="text" class="otp-input" maxlength="1" data-index="3">
                                                    <input type="text" class="otp-input" maxlength="1" data-index="4">
                                                    <input type="text" class="otp-input" maxlength="1" data-index="5">
                                                </div>
                                                <input type="hidden" name="otp_code" id="otpCode">
                                            </div>
                                            <button type="submit" class="btn btn-success btn-lg">
                                                <i class="fas fa-check me-2"></i>Verify & Process Withdrawal
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Withdrawal Request Form -->
                        <div class="step-indicator">
                            <div class="step active">
                                <div class="step-number">1</div>
                                <span>Request</span>
                            </div>
                            <div class="mx-3">→</div>
                            <div class="step pending">
                                <div class="step-number">2</div>
                                <span>Verify OTP</span>
                            </div>
                            <div class="mx-3">→</div>
                            <div class="step pending">
                                <div class="step-number">3</div>
                                <span>Process</span>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card table-card">
                                    <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                        <h5 class="mb-0" style="color: #e0e0e0;">
                                            <i class="fas fa-money-bill-wave me-2"></i>New Withdrawal Request
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="request_withdrawal">
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Withdrawal Type *</label>
                                                        <select class="form-select" name="withdrawal_type" id="withdrawalType" required>
                                                            <option value="">Select withdrawal type</option>
                                                            <option value="full_salary">Full Salary</option>
                                                            <option value="partial">Partial Withdrawal</option>
                                                            <option value="advance">Salary Advance</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Amount (UGX) *</label>
                                                        <input type="number" class="form-control" name="amount" id="amount" 
                                                               step="0.01" min="1" max="<?php echo $employee['salary']; ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Payment Method *</label>
                                                        <select class="form-select" name="payment_method" id="paymentMethod" required onchange="togglePaymentFields()">
                                                            <option value="">Select payment method</option>
                                                            <option value="bank_transfer">Bank Transfer</option>
                                                            <option value="mobile_money">Mobile Money</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Bank Transfer Fields -->
                                            <div class="row" id="bankFields" style="display: none;">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Bank Account Number *</label>
                                                        <input type="text" class="form-control" name="bank_account" 
                                                               value="<?php echo $employee['bank_account_number'] ?? ''; ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Bank Name *</label>
                                                        <select class="form-select" name="bank_name">
                                                            <option value="">Select bank</option>
                                                            <option value="Access Bank">Access Bank</option>
                                                            <option value="First Bank of Nigeria">First Bank of Nigeria</option>
                                                            <option value="Guaranty Trust Bank">Guaranty Trust Bank</option>
                                                            <option value="United Bank For Africa">United Bank For Africa</option>
                                                            <option value="Zenith Bank">Zenith Bank</option>
                                                            <option value="Fidelity Bank">Fidelity Bank</option>
                                                            <option value="Union Bank of Nigeria">Union Bank of Nigeria</option>
                                                            <option value="Sterling Bank">Sterling Bank</option>
                                                            <option value="Stanbic IBTC Bank">Stanbic IBTC Bank</option>
                                                            <option value="Standard Chartered Bank">Standard Chartered Bank</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Mobile Money Fields -->
                                            <div class="row" id="mobileMoneyFields" style="display: none;">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Mobile Money Provider *</label>
                                                        <select class="form-select" name="mobile_money_provider">
                                                            <option value="">Select provider</option>
                                                            <option value="mtn">MTN Mobile Money</option>
                                                            <option value="airtel">Airtel Money</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Mobile Number *</label>
                                                        <input type="tel" class="form-control" name="mobile_number" 
                                                               placeholder="e.g., +256748410887" pattern="^\+256[0-9]{9}$">
                                                        <div class="form-text">Enter mobile number with country code (+256)</div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">OTP Delivery Method *</label>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="delivery_method" 
                                                                   value="sms" id="sms" checked>
                                                            <label class="form-check-label" for="sms">
                                                                <i class="fas fa-sms me-2"></i>SMS
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="delivery_method" 
                                                                   value="whatsapp" id="whatsapp">
                                                            <label class="form-check-label" for="whatsapp">
                                                                <i class="fab fa-whatsapp me-2"></i>WhatsApp
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <small class="text-muted">OTP will be sent to the mobile number you provide above</small>
                                            </div>
                                            
                                            <!-- Fee Information -->
                                            <div class="alert alert-info" id="feeInfo" style="display: none;">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <strong>Withdrawal Fees:</strong>
                                                <div id="feeDetails"></div>
                                            </div>
                                            
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>Important:</strong> You will receive an OTP code to verify this withdrawal. 
                                                Processing may take 1-3 business days.
                                            </div>
                                            
                                            <button type="submit" class="btn btn-success btn-lg">
                                                <i class="fas fa-paper-plane me-2"></i>Request Withdrawal
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card table-card">
                                    <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                                        <h6 class="mb-0" style="color: #e0e0e0;">
                                            <i class="fas fa-info-circle me-2"></i>Withdrawal Information
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled">
                                            <li class="mb-2">
                                                <i class="fas fa-clock text-primary me-2"></i>
                                                Processing time: 1-3 business days
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-shield-alt text-success me-2"></i>
                                                OTP verification required
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-university text-info me-2"></i>
                                                Bank transfer & Mobile Money
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-money-bill text-warning me-2"></i>
                                                <strong>Withdrawal Fees:</strong>
                                                <ul class="mt-1 mb-0" style="font-size: 0.85em;">
                                                    <li>Mobile Money: UGX 1,500 (1K-99K), UGX 5,000 (100K+)</li>
                                                    <li>Bank Transfer: UGX 5,000 (flat rate)</li>
                                                </ul>
                                            </li>
                                            <li class="mb-2">
                                                <i class="fas fa-bell text-warning me-2"></i>
                                                SMS/Email notifications
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Withdrawal History -->
                    <?php if (!empty($withdrawalHistory) && $currentStep !== 'verify_otp'): ?>
                    <div class="card table-card mt-4">
                        <div class="card-header" style="background-color: #1e1e1e; border-bottom: 1px solid #404040;">
                            <h5 class="mb-0" style="color: #e0e0e0;">
                                <i class="fas fa-history me-2"></i>Withdrawal History
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-dark table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Status</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($withdrawalHistory as $withdrawal): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($withdrawal['created_at'])); ?></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $withdrawal['withdrawal_type'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatCurrency($withdrawal['amount'], 'UGX'); ?></td>
                                            <td>
                                                <?php if (isset($withdrawal['mobile_money_provider'])): ?>
                                                    <?php echo ucfirst($withdrawal['mobile_money_provider']); ?> Mobile Money<br>
                                                    <small class="text-muted">****<?php echo substr($withdrawal['mobile_number'], -4); ?></small>
                                                <?php else: ?>
                                                    <?php echo $withdrawal['bank_name']; ?><br>
                                                    <small class="text-muted">****<?php echo substr($withdrawal['bank_account'], -4); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $withdrawal['status'] === 'completed' ? 'success' : 
                                                        ($withdrawal['status'] === 'failed' ? 'danger' : 
                                                        ($withdrawal['status'] === 'processing' ? 'warning' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($withdrawal['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo $withdrawal['flutterwave_reference'] ?? '-'; ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle payment method fields
        function togglePaymentFields() {
            const paymentMethod = document.getElementById('paymentMethod').value;
            const bankFields = document.getElementById('bankFields');
            const mobileMoneyFields = document.getElementById('mobileMoneyFields');
            
            
            // Hide all fields first
            if (bankFields) bankFields.style.display = 'none';
            if (mobileMoneyFields) mobileMoneyFields.style.display = 'none';
            
            // Clear required attributes
            if (bankFields) {
                bankFields.querySelectorAll('input, select').forEach(field => {
                    field.removeAttribute('required');
                });
            }
            if (mobileMoneyFields) {
                mobileMoneyFields.querySelectorAll('input, select').forEach(field => {
                    field.removeAttribute('required');
                });
            }
            
            // Show relevant fields and set required attributes
            if (paymentMethod === 'bank_transfer' && bankFields) {
                bankFields.style.display = 'block';
                bankFields.querySelectorAll('input[name="bank_account"], select[name="bank_name"]').forEach(field => {
                    field.setAttribute('required', 'required');
                });
            } else if (paymentMethod === 'mobile_money' && mobileMoneyFields) {
                mobileMoneyFields.style.display = 'block';
                mobileMoneyFields.querySelectorAll('input[name="mobile_number"], select[name="mobile_money_provider"]').forEach(field => {
                    field.setAttribute('required', 'required');
                });
            }
        }
        
        // Auto-fill amount based on withdrawal type
        document.getElementById('withdrawalType').addEventListener('change', function() {
            const amountField = document.getElementById('amount');
            const salary = <?php echo $employee['salary'] ?? 0; ?>;
            
            if (this.value === 'full_salary') {
                amountField.value = salary;
                amountField.readOnly = true;
            } else {
                amountField.value = '';
                amountField.readOnly = false;
                amountField.max = salary;
            }
        });
        
        // Update fee information based on amount and payment method
        function sanitizeInput(data) {
            data = data.trim();
            data = data.replace(/[^0-9]/g, '');
            return data;
        }
        const amount = sanitizeInput(document.getElementById('amount').value) || 0;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const feeInfo = document.getElementById('feeInfo');
            const feeDetails = document.getElementById('feeDetails');
            
            if (amount >= 1000 && paymentMethod) {
                let fee = 0;
                let feeText = '';
                
                if (paymentMethod === 'mobile_money') {
                    if (amount >= 1000 && amount < 100000) {
                        fee = 1500;
                        feeText = `Mobile Money Fee: UGX 1,500<br>Net Amount: UGX ${(amount - fee).toLocaleString()}`;
                    } else if (amount >= 100000) {
                        fee = 5000;
                        feeText = `Mobile Money Fee: UGX 5,000<br>Net Amount: UGX ${(amount - fee).toLocaleString()}`;
                    }
                } else if (paymentMethod === 'bank_transfer') {
                    fee = 5000;
                    feeText = `Bank Transfer Fee: UGX 5,000<br>Net Amount: UGX ${(amount - fee).toLocaleString()}`;
                }
                
                if (fee > 0) {
                    feeDetails.innerHTML = feeText;
                    feeInfo.style.display = 'block';
                } else {
                    feeInfo.style.display = 'none';
                }
            } else {
                feeInfo.style.display = 'none';
            }
        }
        
        // Add event listeners for amount and payment method changes
        document.getElementById('amount').addEventListener('input', updateFeeInfo);
        document.getElementById('paymentMethod').addEventListener('change', function() {
            togglePaymentFields();
            updateFeeInfo();
        });
        
        // OTP input handling
        document.querySelectorAll('.otp-input').forEach((input, index) => {
            input.addEventListener('input', function(e) {
                // Only allow numeric input
                this.value = this.value.replace(/[^0-9]/g, '');
                
                if (this.value.length === 1) {
                    // Auto-focus next input
                    if (index < 5) {
                        document.querySelectorAll('.otp-input')[index + 1].focus();
                    }
                } else if (this.value.length > 1) {
                    // Handle paste or multiple characters
                    this.value = this.value.charAt(0);
                    if (index < 5) {
                        document.querySelectorAll('.otp-input')[index + 1].focus();
                    }
                }
                updateOTPCode();
            });
            
            input.addEventListener('keydown', function(e) {
                // Handle backspace
                if (e.key === 'Backspace') {
                    if (this.value === '' && index > 0) {
                        document.querySelectorAll('.otp-input')[index - 1].focus();
                    } else {
                        this.value = '';
                        updateOTPCode();
                    }
                }
                
                // Handle arrow keys
                if (e.key === 'ArrowLeft' && index > 0) {
                    document.querySelectorAll('.otp-input')[index - 1].focus();
                }
                if (e.key === 'ArrowRight' && index < 5) {
                    document.querySelectorAll('.otp-input')[index + 1].focus();
                }
            });
            
            // Handle paste
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text');
                const digits = paste.replace(/[^0-9]/g, '').split('');
                
                document.querySelectorAll('.otp-input').forEach((otpInput, i) => {
                    if (digits[i]) {
                        otpInput.value = digits[i];
                    }
                });
                updateOTPCode();
                
                // Focus the next empty input or last input
                const nextEmpty = Array.from(document.querySelectorAll('.otp-input')).findIndex(inp => inp.value === '');
                if (nextEmpty !== -1) {
                    document.querySelectorAll('.otp-input')[nextEmpty].focus();
                } else {
                    document.querySelectorAll('.otp-input')[5].focus();
                }
            });
        });
        
        function updateOTPCode() {
            let otpCode = '';
            document.querySelectorAll('.otp-input').forEach(input => {
                otpCode += input.value;
            });
            document.getElementById('otpCode').value = otpCode;
            
            // Debug logging
            console.log('OTP Code updated:', otpCode);
            console.log('Hidden field value:', document.getElementById('otpCode').value);
        }
    </script>
</body>
</html>
