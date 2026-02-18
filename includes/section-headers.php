<?php
/**
 * Section Headers Helper Functions
 * Hotel Website - Dynamic Section Headers Management
 */

/**
 * Get section header from database
 * 
 * @param string $section_key Unique section key (e.g., 'home_rooms', 'restaurant_menu')
 * @param string $page Page identifier (e.g., 'index', 'restaurant')
 * @param array $fallback Fallback values if section not found: ['label' => '', 'subtitle' => '', 'title' => '', 'description' => '']
 * @return array Section header data
 */
function getSectionHeader($section_key, $page = 'global', $fallback = []) {
    global $pdo;
    
    // Default fallback structure
    $default_fallback = [
        'label' => '',
        'subtitle' => '',
        'title' => 'Section Title',
        'description' => ''
    ];
    
    $fallback = array_merge($default_fallback, $fallback);
    
    try {
        $stmt = $pdo->prepare("
            SELECT section_label, section_subtitle, section_title, section_description 
            FROM section_headers 
            WHERE section_key = ? AND page = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$section_key, $page]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($header) {
            return [
                'label' => $header['section_label'] ?? $fallback['label'],
                'subtitle' => $header['section_subtitle'] ?? $fallback['subtitle'],
                'title' => $header['section_title'] ?? $fallback['title'],
                'description' => $header['section_description'] ?? $fallback['description']
            ];
        }
        
        // If not found with specific page, try global
        if ($page !== 'global') {
            $stmt->execute([$section_key, 'global']);
            $header = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($header) {
                return [
                    'label' => $header['section_label'] ?? $fallback['label'],
                    'subtitle' => $header['section_subtitle'] ?? $fallback['subtitle'],
                    'title' => $header['section_title'] ?? $fallback['title'],
                    'description' => $header['section_description'] ?? $fallback['description']
                ];
            }
        }
        
        // Return fallback if no header found
        return $fallback;
        
    } catch (PDOException $e) {
        error_log("Error fetching section header: " . $e->getMessage());
        return $fallback;
    }
}

/**
 * Render section header HTML
 * 
 * @param string $section_key Unique section key
 * @param string $page Page identifier
 * @param array $fallback Fallback values
 * @param string $additional_classes Additional CSS classes for section-header div
 * @return void Outputs HTML directly
 */
function renderSectionHeader($section_key, $page = 'global', $fallback = [], $additional_classes = '') {
    $header = getSectionHeader($section_key, $page, $fallback);
    
    $classes = 'section-header';
    if (!empty($additional_classes)) {
        $classes .= ' ' . $additional_classes;
    }
    
    echo '<div class="' . htmlspecialchars($classes) . '">';
    
    if (!empty($header['label'])) {
        echo '<span class="section-label">' . htmlspecialchars($header['label']) . '</span>';
    }
    
    if (!empty($header['subtitle'])) {
        echo '<p class="section-subtitle">' . htmlspecialchars($header['subtitle']) . '</p>';
    }
    
    echo '<h2 class="section-title">' . htmlspecialchars($header['title']) . '</h2>';
    
    if (!empty($header['description'])) {
        echo '<p class="section-description">' . htmlspecialchars($header['description']) . '</p>';
    }
    
    echo '</div>';
}

/**
 * Get all section headers for a specific page
 * Useful for page-specific admin management
 * 
 * @param string $page Page identifier
 * @return array Array of section headers
 */
function getPageSectionHeaders($page) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM section_headers 
            WHERE page = ? OR page = 'global'
            ORDER BY display_order ASC, section_title ASC
        ");
        $stmt->execute([$page]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching page section headers: " . $e->getMessage());
        return [];
    }
}

/**
 * Update section header in database
 * 
 * @param string $section_key Section key
 * @param string $page Page identifier
 * @param array $data Header data to update
 * @return bool Success status
 */
function updateSectionHeader($section_key, $page, $data) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE section_headers 
            SET section_label = ?,
                section_subtitle = ?,
                section_title = ?,
                section_description = ?,
                updated_at = NOW()
            WHERE section_key = ? AND page = ?
        ");
        
        return $stmt->execute([
            $data['label'] ?? '',
            $data['subtitle'] ?? '',
            $data['title'] ?? '',
            $data['description'] ?? '',
            $section_key,
            $page
        ]);
    } catch (PDOException $e) {
        error_log("Error updating section header: " . $e->getMessage());
        return false;
    }
}
