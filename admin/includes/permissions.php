<?php
/**
 * User Permissions Helper
 * Role-based access control (RBAC) for the admin panel
 * 
 * Permission Keys Map:
 * - dashboard: View dashboard
 * - bookings: Manage bookings
 * - calendar: View calendar
 * - blocked_dates: Manage blocked dates
 * - rooms: Room management
 * - gallery: Gallery management
 * - conference: Conference management
 * - gym: Gym inquiries
 * - menu: Menu management
 * - events: Events management
 * - reviews: Review management
 * - accounting: Accounting dashboard
 * - payments: Payment management
 * - invoices: Invoice management
 * - payment_add: Add payments
 * - reports: View reports
 * - theme: Theme management
 * - section_headers: Section headers
 * - booking_settings: Booking settings
 * - cache: Cache management
 * - user_management: User management (admin only)
 */

// Define all available permissions with metadata
function getAllPermissions() {
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'View the main dashboard',
            'icon' => 'fa-tachometer-alt',
            'category' => 'Core',
            'page' => 'dashboard.php'
        ],
        'bookings' => [
            'label' => 'Bookings',
            'description' => 'View and manage bookings',
            'icon' => 'fa-calendar-check',
            'category' => 'Reservations',
            'page' => 'bookings.php'
        ],
        'calendar' => [
            'label' => 'Calendar',
            'description' => 'View booking calendar',
            'icon' => 'fa-calendar',
            'category' => 'Reservations',
            'page' => 'calendar.php'
        ],
        'blocked_dates' => [
            'label' => 'Blocked Dates',
            'description' => 'Manage blocked/unavailable dates',
            'icon' => 'fa-ban',
            'category' => 'Reservations',
            'page' => 'blocked-dates.php'
        ],
        'rooms' => [
            'label' => 'Room Management',
            'description' => 'Manage rooms, prices, and facilities',
            'icon' => 'fa-bed',
            'category' => 'Property',
            'page' => 'room-management.php'
        ],
        'gallery' => [
            'label' => 'Gallery',
            'description' => 'Manage hotel gallery images',
            'icon' => 'fa-images',
            'category' => 'Property',
            'page' => 'gallery-management.php'
        ],
        'conference' => [
            'label' => 'Conference Rooms',
            'description' => 'Manage conference facilities',
            'icon' => 'fa-briefcase',
            'category' => 'Property',
            'page' => 'conference-management.php'
        ],
        'gym' => [
            'label' => 'Gym Inquiries',
            'description' => 'View gym membership inquiries',
            'icon' => 'fa-dumbbell',
            'category' => 'Property',
            'page' => 'gym-inquiries.php'
        ],
        'menu' => [
            'label' => 'Menu',
            'description' => 'Manage restaurant menu',
            'icon' => 'fa-utensils',
            'category' => 'Content',
            'page' => 'menu-management.php'
        ],
        'events' => [
            'label' => 'Events',
            'description' => 'Manage hotel events',
            'icon' => 'fa-calendar-alt',
            'category' => 'Content',
            'page' => 'events-management.php'
        ],
        'reviews' => [
            'label' => 'Reviews',
            'description' => 'Manage guest reviews',
            'icon' => 'fa-star',
            'category' => 'Content',
            'page' => 'reviews.php'
        ],
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'View accounting dashboard and financial data',
            'icon' => 'fa-calculator',
            'category' => 'Finance',
            'page' => 'accounting-dashboard.php'
        ],
        'payments' => [
            'label' => 'Payments',
            'description' => 'View and manage payments',
            'icon' => 'fa-money-bill-wave',
            'category' => 'Finance',
            'page' => 'payments.php'
        ],
        'invoices' => [
            'label' => 'Invoices',
            'description' => 'View and manage invoices',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Finance',
            'page' => 'invoices.php'
        ],
        'payment_add' => [
            'label' => 'Add Payment',
            'description' => 'Add new payment records',
            'icon' => 'fa-plus-circle',
            'category' => 'Finance',
            'page' => 'payment-add.php'
        ],
        'reports' => [
            'label' => 'Reports',
            'description' => 'View financial and booking reports',
            'icon' => 'fa-chart-bar',
            'category' => 'Finance',
            'page' => 'reports.php'
        ],
        'theme' => [
            'label' => 'Theme Management',
            'description' => 'Manage website theme and appearance',
            'icon' => 'fa-palette',
            'category' => 'Settings',
            'page' => 'theme-management.php'
        ],
        'section_headers' => [
            'label' => 'Section Headers',
            'description' => 'Manage page section headers',
            'icon' => 'fa-heading',
            'category' => 'Settings',
            'page' => 'section-headers-management.php'
        ],
        'booking_settings' => [
            'label' => 'Booking Settings',
            'description' => 'Configure booking system settings',
            'icon' => 'fa-cog',
            'category' => 'Settings',
            'page' => 'booking-settings.php'
        ],
        'pages' => [
            'label' => 'Page Management',
            'description' => 'Enable, disable and reorder website pages',
            'icon' => 'fa-file-alt',
            'category' => 'Settings',
            'page' => 'page-management.php'
        ],
        'cache' => [
            'label' => 'Cache Management',
            'description' => 'Manage website cache',
            'icon' => 'fa-bolt',
            'category' => 'Settings',
            'page' => 'cache-management.php'
        ],
        'user_management' => [
            'label' => 'User Management',
            'description' => 'Manage admin users and permissions',
            'icon' => 'fa-users-cog',
            'category' => 'Settings',
            'page' => 'user-management.php'
        ]
    ];
}

/**
 * Get the default permissions for a specific role
 */
function getDefaultPermissionsForRole($role) {
    $all_permissions = array_keys(getAllPermissions());
    
    switch ($role) {
        case 'admin':
            return $all_permissions; // Admin gets everything
            
        case 'manager':
            return array_diff($all_permissions, ['user_management', 'cache', 'theme', 'section_headers', 'pages']);
            
        case 'receptionist':
            return ['dashboard', 'bookings', 'calendar', 'blocked_dates', 'rooms', 'reviews', 'gym'];
            
        default:
            return ['dashboard'];
    }
}

/**
 * Check if a user has a specific permission
 * Admin role always has all permissions
 */
function hasPermission($user_id, $permission_key) {
    global $pdo;
    
    try {
        // Get user role
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();
        
        // Admin always has all permissions
        if ($role === 'admin') {
            return true;
        }
        
        // Check user_permissions table (may not exist yet)
        try {
            $stmt = $pdo->prepare("
                SELECT is_granted FROM user_permissions 
                WHERE user_id = ? AND permission_key = ?
            ");
            $stmt->execute([$user_id, $permission_key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result !== false) {
                return (bool)$result['is_granted'];
            }
        } catch (PDOException $e) {
            // Table doesn't exist yet - fall through to role defaults
        }
        
        // No explicit permission set - use role defaults
        $defaults = getDefaultPermissionsForRole($role);
        return in_array($permission_key, $defaults);
        
    } catch (PDOException $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all permissions for a specific user
 * Returns array of permission_key => is_granted
 */
function getUserPermissions($user_id) {
    global $pdo;
    $all_permissions = getAllPermissions();
    $result = [];
    
    try {
        // Get user role
        $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn();
        
        // Admin always has everything
        if ($role === 'admin') {
            foreach ($all_permissions as $key => $info) {
                $result[$key] = true;
            }
            return $result;
        }
        
        // Get explicit permissions (table may not exist yet)
        $explicit = [];
        try {
            $stmt = $pdo->prepare("SELECT permission_key, is_granted FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $explicit = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            // Table doesn't exist yet - use role defaults only
        }
        
        // Get role defaults
        $defaults = getDefaultPermissionsForRole($role);
        
        // Merge: explicit overrides defaults
        foreach ($all_permissions as $key => $info) {
            if (isset($explicit[$key])) {
                $result[$key] = (bool)$explicit[$key];
            } else {
                $result[$key] = in_array($key, $defaults);
            }
        }
        
    } catch (PDOException $e) {
        error_log("Get permissions error: " . $e->getMessage());
        $defaults = getDefaultPermissionsForRole('receptionist');
        foreach ($all_permissions as $key => $info) {
            $result[$key] = in_array($key, $defaults);
        }
    }
    
    return $result;
}

/**
 * Set permissions for a user
 * $permissions = ['bookings' => true, 'accounting' => false, ...]
 */
function setUserPermissions($user_id, $permissions, $granted_by = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted), granted_by = VALUES(granted_by), updated_at = NOW()
        ");
        
        foreach ($permissions as $key => $granted) {
            $stmt->execute([$user_id, $key, $granted ? 1 : 0, $granted_by]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Set permissions error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if current page requires permission and redirect if not allowed
 */
function requirePermission($permission_key) {
    if (!isset($_SESSION['admin_user_id'])) {
        header('Location: login.php');
        exit;
    }
    
    if (!hasPermission($_SESSION['admin_user_id'], $permission_key)) {
        header('Location: dashboard.php?error=access_denied');
        exit;
    }
}

/**
 * Map a page filename to its permission key
 */
function getPermissionForPage($page) {
    $map = [
        'dashboard.php' => 'dashboard',
        'bookings.php' => 'bookings',
        'booking-details.php' => 'bookings',
        'tentative-bookings.php' => 'bookings',
        'calendar.php' => 'calendar',
        'blocked-dates.php' => 'blocked_dates',
        'room-management.php' => 'rooms',
        'gallery-management.php' => 'gallery',
        'conference-management.php' => 'conference',
        'gym-inquiries.php' => 'gym',
        'menu-management.php' => 'menu',
        'events-management.php' => 'events',
        'reviews.php' => 'reviews',
        'accounting-dashboard.php' => 'accounting',
        'payments.php' => 'payments',
        'payment-details.php' => 'payments',
        'invoices.php' => 'invoices',
        'payment-add.php' => 'payment_add',
        'reports.php' => 'reports',
        'theme-management.php' => 'theme',
        'section-headers-management.php' => 'section_headers',
        'booking-settings.php' => 'booking_settings',
        'page-management.php' => 'pages',
        'cache-management.php' => 'cache',
        'user-management.php' => 'user_management',
        'process-checkin.php' => 'bookings',
    ];
    
    return $map[$page] ?? null;
}

/**
 * Get the allowed navigation items for a user
 * Returns filtered array of nav items the user can access
 */
function getNavItemsForUser($user_id) {
    $permissions = getUserPermissions($user_id);
    $all_permissions = getAllPermissions();
    $nav_items = [];
    
    foreach ($all_permissions as $key => $info) {
        if (isset($permissions[$key]) && $permissions[$key]) {
            $nav_items[] = [
                'key' => $key,
                'label' => $info['label'],
                'icon' => $info['icon'],
                'page' => $info['page'],
                'category' => $info['category']
            ];
        }
    }
    
    return $nav_items;
}
?>
