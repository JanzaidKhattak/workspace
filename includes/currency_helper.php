<?php
/**
 * Currency Helper Functions
 */

function getCurrencySymbol($currency_code = 'USD') {
    $symbols = [
        'USD' => '$',
        'AED' => 'د.إ',
        'PKR' => '₨',
        'INR' => '₹',
        'EUR' => '€'
    ];
    
    return $symbols[$currency_code] ?? '$';
}

function formatCurrency($amount, $currency_code = 'USD') {
    $symbol = getCurrencySymbol($currency_code);
    return $symbol . number_format($amount, 2);
}

function getCurrentCurrency($db) {
    try {
        $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'currency' LIMIT 1");
        $currency_result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $currency_result ? $currency_result['setting_value'] : 'USD';
    } catch (Exception $e) {
        return 'USD';
    }
}
?>