<?php
/**
 * Hotel Website - Site Settings API Endpoint
 * 
 * Returns dynamic site settings from database
 * Used by client integrations to avoid hardcoded values
 */

// Include database connection
require_once __DIR__ . '/../config/database.php';

// Include API authentication
require_once __DIR__ . '/auth.php';

// Set JSON response header
header('Content-Type: application/json');

try {
    // Get all site settings from database
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings ORDER BY setting_key");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to key-value array
    $settingsArray = [];
    foreach ($settings as $setting) {
        $settingsArray[$setting['setting_key']] = $setting['setting_value'];
    }
    
    // Build response with common settings
    $response = [
        'success' => true,
        'message' => 'Site settings retrieved successfully',
        'data' => [
            'hotel' => [
                'name' => $settingsArray['site_name'] ?? 'Hotel Website',
                'tagline' => $settingsArray['site_tagline'] ?? '',
                'url' => $settingsArray['site_url'] ?? '',
                'logo' => $settingsArray['site_logo'] ?? ''
            ],
            'contact' => [
                'phone_main' => $settingsArray['phone_main'] ?? '',
                'phone_reservations' => $settingsArray['phone_reservations'] ?? '',
                'email_main' => $settingsArray['email_main'] ?? '',
                'email_reservations' => $settingsArray['email_reservations'] ?? '',
                'address_line1' => $settingsArray['address_line1'] ?? '',
                'address_line2' => $settingsArray['address_line2'] ?? '',
                'address_country' => $settingsArray['address_country'] ?? '',
                'working_hours' => $settingsArray['working_hours'] ?? '24/7 Available'
            ],
            'booking' => [
                'check_in_time' => $settingsArray['check_in_time'] ?? '2:00 PM',
                'check_out_time' => $settingsArray['check_out_time'] ?? '11:00 AM',
                'change_policy' => $settingsArray['booking_change_policy'] ?? ''
            ],
            'currency' => [
                'symbol' => $settingsArray['currency_symbol'] ?? 'MWK',
                'code' => $settingsArray['currency_code'] ?? 'MWK'
            ],
            'social' => [
                'facebook' => $settingsArray['facebook_url'] ?? '',
                'instagram' => $settingsArray['instagram_url'] ?? '',
                'twitter' => $settingsArray['twitter_url'] ?? '',
                'linkedin' => $settingsArray['linkedin_url'] ?? ''
            ],
            'legal' => [
                'copyright_text' => $settingsArray['footer_credits'] ?? $settingsArray['copyright_text'] ?? (date('Y') . ' ' . ($settingsArray['site_name'] ?? 'Hotel Website') . '. All rights reserved.')
            ],
            'all_settings' => $settingsArray
        ]
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Site Settings API Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve site settings',
        'code' => 500
    ]);
}
