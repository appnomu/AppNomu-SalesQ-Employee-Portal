<?php
/**
 * System Settings Helper
 * Provides functions to get system settings from database
 */

function getSystemSettings() {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings");
        $stmt->execute();
        $systemSettingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert to associative array for easy access
        $systemSettings = [];
        foreach ($systemSettingsData as $setting) {
            $systemSettings[$setting['setting_key']] = $setting['setting_value'];
        }
        
        return $systemSettings;
    } catch (PDOException $e) {
        // Return defaults if database error
        return [
            'system_name' => 'EP Portal',
            'timezone' => 'Africa/Kampala',
            'date_format' => 'Y-m-d',
            'currency' => 'UGX'
        ];
    }
}

function getSystemName() {
    $settings = getSystemSettings();
    return $settings['system_name'] ?? 'EP Portal';
}
?>
