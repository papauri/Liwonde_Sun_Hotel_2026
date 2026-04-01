<?php
/**
 * Seed department notification emails into email_settings.
 *
 * Usage:
 *   php scripts/seed-department-email-settings.php
 */

require_once __DIR__ . '/../config/database.php';

function valid_email_or_empty($value) {
    $value = trim((string)$value);
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

try {
    $admin = valid_email_or_empty(getEmailSetting('email_admin_email', ''));
    if ($admin === '') {
        $admin = valid_email_or_empty(getSetting('email_main', ''));
    }

    $map = [
        'conference_admin_email' => [
            valid_email_or_empty(getEmailSetting('conference_admin_email', '')),
            valid_email_or_empty(getSetting('conference_email', '')),
            $admin,
        ],
        'gym_admin_email' => [
            valid_email_or_empty(getEmailSetting('gym_admin_email', '')),
            valid_email_or_empty(getSetting('gym_email', '')),
            $admin,
        ],
        'restaurant_admin_email' => [
            valid_email_or_empty(getEmailSetting('restaurant_admin_email', '')),
            valid_email_or_empty(getSetting('email_restaurant', '')),
            valid_email_or_empty(getSetting('restaurant_email', '')),
            $admin,
        ],
    ];

    foreach ($map as $key => $candidates) {
        $chosen = '';
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                $chosen = $candidate;
                break;
            }
        }

        if ($chosen === '') {
            echo "Skipping {$key}: no valid fallback email found." . PHP_EOL;
            continue;
        }

        $ok = updateEmailSetting($key, $chosen, 'Department notification recipient', false);
        echo ($ok ? "Updated" : "Failed") . " {$key} => {$chosen}" . PHP_EOL;
    }

    echo "Done." . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
