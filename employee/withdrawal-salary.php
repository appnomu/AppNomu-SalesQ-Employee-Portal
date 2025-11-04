<?php
require_once __DIR__ . '/../config/session-security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/flutterwave.php';
require_once __DIR__ . '/../includes/withdrawal-charges.php';

// Start secure session first
startSecureSession();
requireAuth();

// Ensure user is employee
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../admin/dashboard');
    exit();
}

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';
$step = $_GET['step'] ?? 'request';

// Get employee profile
$stmt = $db->prepare("SELECT u.*, ep.* FROM users u LEFT JOIN employee_profiles ep ON u.id = ep.user_id WHERE u.id = ?");
$stmt->execute([$userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_withdrawal') {
    $withdrawalAmount = (float)$_POST['test_amount'];
    $paymentMethod = $_POST['payment_method'];
    
    if ($withdrawalAmount <= 0) {
        $message = 'Please enter a valid withdrawal amount';
        $messageType = 'danger';
    } else {
        // Validate minimum withdrawal amounts
        $validationErrors = validateMinimumWithdrawal($withdrawalAmount, $paymentMethod);
        if (!empty($validationErrors)) {
            $message = implode('. ', $validationErrors);
            $messageType = 'danger';
        } else {
        try {
            $db->beginTransaction();
            
            // Calculate charges
            $charges = calculateWithdrawalCharges($withdrawalAmount, $paymentMethod);
            $netAmount = $withdrawalAmount - $charges;
            
            // Check available balance from period allocated amount (includes monthly salary + any top-ups)
            $availableBalance = ($employee['period_allocated_amount'] ?? 0) - ($employee['withdrawn_amount'] ?? 0);
            
            if ($withdrawalAmount > $availableBalance) {
                throw new Exception("Insufficient balance. Available: UGX " . number_format($availableBalance));
            }
            
            // Deduct full amount (including charges) immediately
            $stmt = $db->prepare("UPDATE employee_profiles SET withdrawn_amount = COALESCE(withdrawn_amount, 0) + ? WHERE user_id = ?");
            $stmt->execute([$withdrawalAmount, $userId]);
            
            // Process withdrawal directly with Flutterwave
            $flutterwave = new FlutterwaveAPI();
            $reference = 'SALARY_' . time() . '_' . $userId;
            
            if ($paymentMethod === 'bank_transfer') {
                $bankAccount = $_POST['bank_account'];
                $bankName = $_POST['bank_name'];
                
                $bankCode = getBankCode($bankName);
                $verification = $flutterwave->verifyAccount($bankAccount, $bankCode);
                $accountName = $verification['data']['account_name'];
                
                $transferResult = $flutterwave->initiateTransfer(
                    $netAmount,
                    $bankCode,
                    $bankAccount,
                    $accountName,
                    $reference,
                    "Salary withdrawal - Employee #$userId (Net: UGX " . number_format($netAmount) . ", Charges: UGX " . number_format($charges) . ")",
                    'UGX'
                );
                
                // Create withdrawal record
                $stmt = $db->prepare("
                    INSERT INTO salary_withdrawals 
                    (employee_id, amount, withdrawal_type, payment_method, bank_account, bank_name, status, flutterwave_reference, charges, net_amount) 
                    VALUES (?, ?, 'salary', ?, ?, ?, 'processing', ?, ?, ?)
                ");
                $stmt->execute([$userId, $withdrawalAmount, $paymentMethod, $bankAccount, $bankName, $reference, $charges, $netAmount]);
                
            } else {
                $mobileNumber = $_POST['mobile_number'];
                $provider = $_POST['mobile_money_provider'];
                
                $transferResult = $flutterwave->initiateMobileMoneyTransfer(
                    $netAmount,
                    $mobileNumber,
                    $provider,
                    $reference,
                    "Salary withdrawal - Employee #$userId (Net: UGX " . number_format($netAmount) . ", Charges: UGX " . number_format($charges) . ")",
                    'UGX'
                );
                
                // Create withdrawal record
                $stmt = $db->prepare("
                    INSERT INTO salary_withdrawals 
                    (employee_id, amount, withdrawal_type, payment_method, mobile_number, mobile_money_provider, status, flutterwave_reference, charges, net_amount) 
                    VALUES (?, ?, 'salary', ?, ?, ?, 'processing', ?, ?, ?)
                ");
                $stmt->execute([$userId, $withdrawalAmount, $paymentMethod, $mobileNumber, $provider, $reference, $charges, $netAmount]);
            }
            
            // Update withdrawal status based on transfer result
            $finalStatus = ($transferResult['status'] === 'success') ? 'completed' : 'failed';
            $failureReason = ($transferResult['status'] !== 'success') ? ($transferResult['message'] ?? 'Unknown error') : null;
            
            $stmt = $db->prepare("
                UPDATE salary_withdrawals 
                SET status = ?, processed_at = NOW(), failure_reason = ?
                WHERE flutterwave_reference = ?
            ");
            $stmt->execute([$finalStatus, $failureReason, $reference]);
            
            $db->commit();
            
            if ($transferResult['status'] === 'success') {
                $message = 'Withdrawal completed successfully! Net amount UGX ' . number_format($netAmount) . ' has been transferred (Charges: UGX ' . number_format($charges) . ')';
                $messageType = 'success';
            } else {
                $message = 'Withdrawal failed: ' . ($transferResult['message'] ?? 'Unknown error');
                $messageType = 'danger';
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $message = 'Error creating withdrawal: ' . $e->getMessage();
            $messageType = 'danger';
        }
        }
    }
}


// Handle URL messages
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'info';
}

// Get withdrawal history
$stmt = $db->prepare("
    SELECT * FROM salary_withdrawals 
    WHERE employee_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$userId]);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get employee profile for real account details
$stmt = $db->prepare("
    SELECT u.*, ep.* 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
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
        .card {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            border: 1px solid #404040;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        .card-header {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%) !important;
            border-bottom: 1px solid #404040;
            border-radius: 15px 15px 0 0 !important;
            color: #e0e0e0 !important;
        }
        .form-control, .form-select {
            background-color: #404040;
            border: 1px solid #555;
            color: #e0e0e0;
        }
        .form-control:focus, .form-select:focus {
            background-color: #4a4a4a;
            border-color: #666;
            color: #e0e0e0;
            box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.1);
        }
        .form-check {
            background: #404040 !important;
            border: 1px solid #555 !important;
        }
        .form-check:hover {
            background: #4a4a4a !important;
            border-color: #666 !important;
        }
        .bg-light {
            background: #404040 !important;
            border: 1px solid #555 !important;
        }
        .table-dark {
            background-color: #2d3748;
            color: #e0e0e0;
        }
        .btn {
            border: none;
        }
        .btn-success {
            background: linear-gradient(135deg, #404040 0%, #505050 100%);
            color: #e0e0e0;
        }
        .btn-warning {
            background: linear-gradient(135deg, #404040 0%, #505050 100%);
            color: #e0e0e0;
        }
        .text-muted {
            color: #aaa !important;
        }
        .input-group-text {
            background-color: #404040;
            border: 1px solid #555;
            color: #e0e0e0;
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
                        <a class="nav-link active" href="withdrawal-salary.php">
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
                    <h2><i class="fas fa-money-bill-wave me-2 text-success"></i>Salary Withdrawal</h2>
                    
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;">
                            <div class="d-flex align-items-center">
                                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle text-success' : ($messageType === 'danger' ? 'fa-exclamation-triangle text-danger' : 'fa-info-circle text-info'); ?> me-3 fs-5"></i>
                                <div class="flex-grow-1">
                                    <strong><?php echo $messageType === 'success' ? 'Success!' : ($messageType === 'danger' ? 'Error!' : 'Notice:'); ?></strong>
                                    <div class="mt-1"><?php echo $message; ?></div>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <!-- Real Test Form -->
                        <div class="col-md-6">
                            <div class="card real-test-card">
                                <div class="card-header">
                                    <h5><i class="fas fa-money-bill-wave me-2"></i>Salary Withdrawal</h5>
                                    <small>Request salary withdrawal to your preferred payment method</small>
                                </div>
                                <div class="card-body">
                                    <!-- Charges Notice -->
                                    <div class="card bg-dark border-secondary mb-3" style="border-radius: 8px;">
                                        <div class="card-body p-3">
                                            <h6 class="text-light mb-2"><i class="fas fa-info-circle text-info me-2"></i>Withdrawal Charges</h6>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <div class="d-flex align-items-center mb-1">
                                                        <i class="fas fa-mobile-alt text-primary me-2" style="font-size: 0.9rem;"></i>
                                                        <small class="text-light fw-bold">Mobile Money</small>
                                                    </div>
                                                    <div class="ms-3">
                                                        <small class="text-muted d-block">1K-105K: <span class="text-warning fw-bold">UGX 1,500</span></small>
                                                        <small class="text-muted d-block">Above 105K: <span class="text-warning fw-bold">UGX 5,000</span></small>
                                                        <small class="text-muted">Min: <span class="text-info">UGX 1,000</span></small>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="d-flex align-items-center mb-1">
                                                        <i class="fas fa-university text-success me-2" style="font-size: 0.9rem;"></i>
                                                        <small class="text-light fw-bold">Bank Transfer</small>
                                                    </div>
                                                    <div class="ms-3">
                                                        <small class="text-muted d-block">Any amount: <span class="text-warning fw-bold">UGX 10,000</span></small>
                                                        <small class="text-muted">Min: <span class="text-info">UGX 20,000</span></small>
                                                    </div>
                                                </div>
                                            </div>
                                            <hr class="border-secondary my-2">
                                            <small class="text-muted">
                                                <i class="fas fa-shield-alt text-info me-1"></i>
                                                Charges deducted from requested amount. You receive net amount.
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <form method="POST">
                                        <input type="hidden" name="action" value="request_withdrawal">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Withdrawal Amount (UGX)</label>
                                            <div class="input-group">
                                                <span class="input-group-text">UGX</span>
                                                <input type="number" class="form-control form-control-lg" name="test_amount" 
                                                       placeholder="Enter amount (UGX)" min="1000" step="100" required 
                                                       onchange="updateChargePreview()">
                                            </div>
                                            <div class="mt-2">
                                                <div class="card bg-info bg-opacity-10 border-info border-opacity-25" style="border-radius: 8px;">
                                                    <div class="card-body p-2">
                                                        <div class="row g-2 text-center">
                                                            <div class="col-4">
                                                                <small class="text-muted d-block">Total Allocated</small>
                                                                <strong class="text-info">UGX <?php echo number_format($employee['period_allocated_amount'] ?? 0); ?></strong>
                                                            </div>
                                                            <div class="col-4">
                                                                <small class="text-muted d-block">Withdrawn</small>
                                                                <strong class="text-warning">UGX <?php echo number_format($employee['withdrawn_amount'] ?? 0); ?></strong>
                                                            </div>
                                                            <div class="col-4">
                                                                <small class="text-muted d-block">Available</small>
                                                                <strong class="text-success">UGX <?php echo number_format(($employee['period_allocated_amount'] ?? 0) - ($employee['withdrawn_amount'] ?? 0)); ?></strong>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Payment Method</label>
                                            <select class="form-select form-select-lg" name="payment_method" id="paymentMethod" required onchange="togglePaymentFields(); updateChargePreview();">
                                                <option value="">Select payment method</option>
                                                <option value="bank_transfer"><i class="fas fa-university"></i> Bank Transfer</option>
                                                <option value="mobile_money"><i class="fas fa-mobile-alt"></i> Mobile Money</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Mobile Money Fields -->
                                        <div id="mobileMoneyFields" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Mobile Money Provider</label>
                                                <select class="form-select" name="mobile_money_provider">
                                                    <option value="">Select provider</option>
                                                    <option value="mtn">MTN Mobile Money</option>
                                                    <option value="airtel">Airtel Money</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Mobile Number</label>
                                                <input type="tel" class="form-control" name="mobile_number" 
                                                       value="<?php echo $employee['phone'] ?? ''; ?>"
                                                       placeholder="e.g., +256748410887" pattern="^\+256[0-9]{9}$">
                                                <div class="form-text">Enter mobile number with country code (+256)</div>
                                            </div>
                                        </div>
                                        
                                        <!-- Bank Transfer Fields -->
                                        <div id="bankFields" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Bank Account Number</label>
                                                <input type="text" class="form-control" name="bank_account" 
                                                       value="<?php echo $employee['account_number'] ?? ''; ?>"
                                                       placeholder="Enter bank account number">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Bank Name</label>
                                                <select class="form-select" name="bank_name">
                                                    <option value="">Select bank</option>
                                                    <option value="Stanbic Bank Uganda">Stanbic Bank Uganda</option>
                                                    <option value="Centenary Bank">Centenary Bank</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Charge Preview -->
                                        <div id="chargePreview" style="display: none;" class="mb-3">
                                            <div class="card bg-warning bg-opacity-10 border-warning border-opacity-25" style="border-radius: 8px;">
                                                <div class="card-body p-3">
                                                    <h6 class="text-warning mb-2"><i class="fas fa-calculator me-2"></i>Withdrawal Breakdown</h6>
                                                    <div class="row g-2">
                                                        <div class="col-6">
                                                            <small class="text-muted d-block">Requested Amount</small>
                                                            <strong id="grossAmount" class="text-light">UGX 0</strong>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted d-block">Processing Fee</small>
                                                            <strong id="chargeAmount" class="text-warning">UGX 0</strong>
                                                        </div>
                                                    </div>
                                                    <hr class="border-secondary my-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-muted">You will receive:</small>
                                                        <strong id="netAmount" class="text-success fs-6">UGX 0</strong>
                                                    </div>
                                                    <small id="chargeDescription" class="text-muted d-block mt-1"></small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success btn-lg w-100 shadow">
                                            <i class="fas fa-paper-plane me-2"></i>Request Withdrawal
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Real Test History -->
                        <div class="col-md-6">
                            <?php if (false): ?>
                            <?php else: ?>
                            
                            <!-- Withdrawal History -->
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-history me-2"></i>Withdrawal History</h5>
                                    <small>Your withdrawal transactions</small>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($withdrawals)): ?>
                                        <p class="text-muted text-center">No withdrawals yet</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-dark table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Amount (UGX)</th>
                                                        <th>Method</th>
                                                        <th>Status</th>
                                                        <th>Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($withdrawals as $withdrawal): ?>
                                                        <tr>
                                                            <td>UGX <?php echo number_format($withdrawal['amount']); ?></td>
                                                            <td><?php echo ucfirst(str_replace('_', ' ', $withdrawal['payment_method'])); ?></td>
                                                            <td>
                                                                <span class="badge bg-<?php 
                                                                    echo match($withdrawal['status']) {
                                                                        'pending_otp' => 'warning',
                                                                        'processing' => 'info',
                                                                        'completed' => 'success',
                                                                        'failed' => 'danger',
                                                                        default => 'secondary'
                                                                    };
                                                                ?>">
                                                                    <?php echo ucfirst(str_replace('_', ' ', $withdrawal['status'])); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo date('M j, Y g:i A', strtotime($withdrawal['created_at'])); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle payment method fields
        function togglePaymentFields() {
            const paymentMethod = document.getElementById('paymentMethod').value;
            const mobileMoneyFields = document.getElementById('mobileMoneyFields');
            const bankFields = document.getElementById('bankFields');
            
            if (paymentMethod === 'mobile_money') {
                mobileMoneyFields.style.display = 'block';
                bankFields.style.display = 'none';
            } else if (paymentMethod === 'bank_transfer') {
                mobileMoneyFields.style.display = 'none';
                bankFields.style.display = 'block';
            } else {
                mobileMoneyFields.style.display = 'none';
                bankFields.style.display = 'none';
            }
        }

        function updateChargePreview() {
            const amount = parseFloat(document.querySelector('input[name="test_amount"]').value) || 0;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const chargePreview = document.getElementById('chargePreview');
            
            if (amount > 0 && paymentMethod) {
                let charges = 0;
                let chargeDescription = '';
                let minAmount = 0;
                
                if (paymentMethod === 'mobile_money') {
                    minAmount = 1000;
                    if (amount >= 1000 && amount <= 105000) {
                        charges = 1500;
                        chargeDescription = 'Mobile Money fee (UGX 1K - 105K)';
                    } else if (amount > 105000) {
                        charges = 5000;
                        chargeDescription = 'Mobile Money fee (Above UGX 105K)';
                    }
                } else if (paymentMethod === 'bank_transfer') {
                    minAmount = 20000;
                    charges = 10000;
                    chargeDescription = 'Bank transfer fee';
                }
                
                const netAmount = amount - charges;
                
                // Update display
                document.getElementById('grossAmount').textContent = 'UGX ' + amount.toLocaleString();
                document.getElementById('chargeAmount').textContent = 'UGX ' + charges.toLocaleString();
                document.getElementById('netAmount').textContent = 'UGX ' + netAmount.toLocaleString();
                document.getElementById('chargeDescription').textContent = chargeDescription;
                
                // Show/hide preview
                if (amount >= minAmount) {
                    chargePreview.style.display = 'block';
                } else {
                    chargePreview.style.display = 'none';
                }
            } else {
                chargePreview.style.display = 'none';
            }
        }
    </script>
</body>
</html>
