<?php

/**
 * Calculate withdrawal charges based on amount and payment method
 */
function calculateWithdrawalCharges($amount, $paymentMethod) {
    $charges = 0;
    
    if ($paymentMethod === 'mobile_money') {
        // Mobile Money charges
        if ($amount >= 1000 && $amount <= 105000) {
            $charges = 1500; // UGX 1,500 for amounts 1K to 105K
        } else if ($amount > 105000) {
            $charges = 5000; // UGX 5,000 for amounts above 105K
        }
    } else if ($paymentMethod === 'bank_transfer') {
        // Bank transfer charges - flat UGX 10,000 for any amount
        $charges = 10000;
    }
    
    return $charges;
}

/**
 * Validate minimum withdrawal amounts based on payment method
 */
function validateMinimumWithdrawal($amount, $paymentMethod) {
    $errors = [];
    
    if ($paymentMethod === 'bank_transfer') {
        if ($amount < 20000) {
            $errors[] = 'Minimum withdrawal amount for bank transfer is UGX 20,000';
        }
    } else if ($paymentMethod === 'mobile_money') {
        if ($amount < 1000) {
            $errors[] = 'Minimum withdrawal amount for mobile money is UGX 1,000';
        }
    }
    
    return $errors;
}

/**
 * Get charge breakdown for display
 */
function getChargeBreakdown($amount, $paymentMethod) {
    $charges = calculateWithdrawalCharges($amount, $paymentMethod);
    $netAmount = $amount - $charges;
    
    return [
        'gross_amount' => $amount,
        'charges' => $charges,
        'net_amount' => $netAmount,
        'charge_description' => getChargeDescription($amount, $paymentMethod)
    ];
}

/**
 * Get human-readable charge description
 */
function getChargeDescription($amount, $paymentMethod) {
    if ($paymentMethod === 'mobile_money') {
        if ($amount >= 1000 && $amount <= 105000) {
            return 'Mobile Money fee (UGX 1K - 105K)';
        } else if ($amount > 105000) {
            return 'Mobile Money fee (Above UGX 105K)';
        }
    } else if ($paymentMethod === 'bank_transfer') {
        return 'Bank transfer fee';
    }
    
    return 'Processing fee';
}
