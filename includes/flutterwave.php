<?php
require_once __DIR__ . '/../config/config.php';

class FlutterwaveAPI {
    private $secretKey;
    private $publicKey;
    private $baseUrl;
    
    public function __construct() {
        $this->secretKey = FLUTTERWAVE_SECRET_KEY;
        $this->publicKey = FLUTTERWAVE_PUBLIC_KEY;
        $this->baseUrl = 'https://api.flutterwave.com/v3';
    }
    
    /**
     * Initiate bank transfer
     */
    public function initiateTransfer($amount, $bankCode, $accountNumber, $accountName, $reference, $narration = 'Salary withdrawal', $currency = 'NGN') {
        $url = $this->baseUrl . '/transfers';
        
        $data = [
            'account_bank' => $bankCode,
            'account_number' => $accountNumber,
            'amount' => $amount,
            'narration' => $narration,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => BASE_URL . 'api/flutterwave-callback.php',
            'debit_currency' => $currency,
            'beneficiary_name' => $accountName
        ];
        
        return $this->makeRequest($url, $data);
    }
    
    /**
     * Initiate mobile money transfer
     */
    public function initiateMobileMoneyTransfer($amount, $mobileNumber, $provider, $reference, $narration = 'Salary withdrawal', $currency = 'NGN') {
        $url = $this->baseUrl . '/transfers';
        
        // Map provider to Flutterwave network codes
        $networkMap = [
            'mtn' => 'MTN',
            'airtel' => 'AIRTEL',
            'glo' => 'GLO',
            '9mobile' => '9MOBILE'
        ];
        
        $network = $networkMap[$provider] ?? 'MTN';
        
        $data = [
            'account_bank' => 'MPS', // Mobile Payment Service
            'account_number' => $mobileNumber,
            'amount' => $amount,
            'narration' => $narration,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => BASE_URL . 'api/flutterwave-callback.php',
            'debit_currency' => $currency,
            'beneficiary_name' => 'Mobile Money User',
            'meta' => [
                'mobile_number' => $mobileNumber,
                'network' => $network
            ]
        ];
        
        return $this->makeRequest($url, $data);
    }
    
    /**
     * Verify account number
     */
    public function verifyAccount($accountNumber, $bankCode) {
        $url = $this->baseUrl . '/accounts/resolve';
        
        $data = [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode
        ];
        
        return $this->makeRequest($url, $data, 'POST');
    }
    
    /**
     * Get list of banks
     */
    public function getBanks($country = 'NG') {
        $url = $this->baseUrl . '/banks/' . $country;
        return $this->makeRequest($url, null, 'GET');
    }
    
    /**
     * Get transfer status
     */
    public function getTransferStatus($transferId) {
        $url = $this->baseUrl . '/transfers/' . $transferId;
        return $this->makeRequest($url, null, 'GET');
    }
    
    /**
     * Get wallet balance
     */
    public function getBalance($currency = 'NGN') {
        $url = $this->baseUrl . '/balances/' . $currency;
        return $this->makeRequest($url, null, 'GET');
    }
    
    /**
     * Make HTTP request to Flutterwave API
     */
    private function makeRequest($url, $data = null, $method = 'POST') {
        $ch = curl_init();
        
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception('Flutterwave API Error: ' . ($decodedResponse['message'] ?? 'Unknown error'));
        }
        
        return $decodedResponse;
    }
}

/**
 * Process salary withdrawal (supports both bank transfer and mobile money)
 */
function processSalaryWithdrawal($employeeId, $amount, $withdrawalType, $paymentMethod, $paymentDetails) {
    global $db;
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Generate unique reference
        $reference = 'EP_' . time() . '_' . $employeeId;
        
        $flutterwave = new FlutterwaveAPI();
        
        if ($paymentMethod === 'bank_transfer') {
            $bankAccount = $paymentDetails['bank_account'];
            $bankName = $paymentDetails['bank_name'];
            $bankCode = getBankCode($bankName);
            
            // Verify account
            $accountVerification = $flutterwave->verifyAccount($bankAccount, $bankCode);
            
            if ($accountVerification['status'] !== 'success') {
                throw new Exception('Invalid bank account details');
            }
            
            $accountName = $accountVerification['data']['account_name'];
            
            // Create withdrawal record
            $stmt = $db->prepare("
                INSERT INTO salary_withdrawals (employee_id, amount, withdrawal_type, payment_method, bank_account, bank_name, flutterwave_reference, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$employeeId, $amount, $withdrawalType, $paymentMethod, $bankAccount, $bankName, $reference]);
            $withdrawalId = $db->lastInsertId();
            
            // Initiate transfer
            $transferResult = $flutterwave->initiateTransfer(
                $amount,
                $bankCode,
                $bankAccount,
                $accountName,
                $reference,
                "Salary withdrawal - Employee #$employeeId"
            );
            
        } else { // mobile_money
            $mobileNumber = $paymentDetails['mobile_number'];
            $provider = $paymentDetails['mobile_money_provider'];
            
            // Create withdrawal record
            $stmt = $db->prepare("
                INSERT INTO salary_withdrawals (employee_id, amount, withdrawal_type, payment_method, mobile_number, mobile_money_provider, flutterwave_reference, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$employeeId, $amount, $withdrawalType, $paymentMethod, $mobileNumber, $provider, $reference]);
            $withdrawalId = $db->lastInsertId();
            
            // Initiate mobile money transfer
            $transferResult = $flutterwave->initiateMobileMoneyTransfer(
                $amount,
                $mobileNumber,
                $provider,
                $reference,
                "Salary withdrawal - Employee #$employeeId"
            );
        }
        
        if ($transferResult['status'] === 'success') {
            // Update withdrawal record
            $stmt = $db->prepare("
                UPDATE salary_withdrawals 
                SET status = 'processing', processed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$withdrawalId]);
            
            $db->commit();
            
            return [
                'success' => true,
                'withdrawal_id' => $withdrawalId,
                'reference' => $reference,
                'transfer_id' => $transferResult['data']['id']
            ];
        } else {
            throw new Exception('Transfer initiation failed: ' . $transferResult['message']);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        
        // Update withdrawal record with failure
        if (isset($withdrawalId)) {
            $stmt = $db->prepare("
                UPDATE salary_withdrawals 
                SET status = 'failed', failure_reason = ? 
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $withdrawalId]);
        }
        
        throw $e;
    }
}

/**
 * Get bank code from bank name (simplified mapping)
 */
function getBankCode($bankName) {
    $bankCodes = [
        'Access Bank' => '044',
        'Citibank Nigeria' => '023',
        'Diamond Bank' => '063',
        'Ecobank Nigeria' => '050',
        'Fidelity Bank' => '070',
        'First Bank of Nigeria' => '011',
        'First City Monument Bank' => '214',
        'Guaranty Trust Bank' => '058',
        'Heritage Bank' => '030',
        'Keystone Bank' => '082',
        'Polaris Bank' => '076',
        'Providus Bank' => '101',
        'Stanbic IBTC Bank' => '221',
        'Standard Chartered Bank' => '068',
        'Sterling Bank' => '232',
        'Union Bank of Nigeria' => '032',
        'United Bank For Africa' => '033',
        'Unity Bank' => '215',
        'Wema Bank' => '035',
        'Zenith Bank' => '057'
    ];
    
    return $bankCodes[$bankName] ?? '011'; // Default to First Bank
}
?>
