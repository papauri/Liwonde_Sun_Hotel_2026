<?php
/**
 * Theme Management System
 * Admin interface for managing site colors and themes
 */

require_once 'admin-init.php';
require_once '../includes/alert.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

$message = '';
$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'revert_default') {
            // Revert to default Navy & Gold theme
            $default_colors = [
                'navy_color' => '#0A1929',
                'deep_navy_color' => '#05090F',
                'gold_color' => '#D4AF37',
                'dark_gold_color' => '#B8941F',
                'accent_color' => '#D4AF37'
            ];
            
            foreach ($default_colors as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                    VALUES (?, ?, 'theme', NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            // Clear cache to apply default colors
            require_once __DIR__ . '/../config/cache.php';
            clearCache();
            
            $message = 'Successfully reverted to default Navy & Gold theme!';
            $success = true;
        } elseif ($action === 'save_colors') {
            $colors = [
                'navy_color' => $_POST['navy_color'] ?? '#0A1929',
                'deep_navy_color' => $_POST['deep_navy_color'] ?? '#05090F',
                'gold_color' => $_POST['gold_color'] ?? '#D4AF37',
                'dark_gold_color' => $_POST['dark_gold_color'] ?? '#B8941F',
                'accent_color' => $_POST['accent_color'] ?? '#D4AF37'
            ];
            
            foreach ($colors as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                    VALUES (?, ?, 'theme', NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            // Clear cache to apply new colors
            require_once __DIR__ . '/../config/cache.php';
            clearCache();
            
            $message = 'Theme colors updated successfully!';
            $success = true;
        } elseif ($action === 'apply_preset') {
            $preset = $_POST['preset'] ?? '';
            $presets = [
                'navy-gold' => [
                    'navy_color' => '#0A1929',
                    'deep_navy_color' => '#05090F',
                    'gold_color' => '#D4AF37',
                    'dark_gold_color' => '#B8941F',
                    'accent_color' => '#D4AF37'
                ],
                'burgundy-gold' => [
                    'navy_color' => '#722F37',
                    'deep_navy_color' => '#4A1A21',
                    'gold_color' => '#D4AF37',
                    'dark_gold_color' => '#B8941F',
                    'accent_color' => '#D4AF37'
                ],
                'forest-green' => [
                    'navy_color' => '#1B4D3E',
                    'deep_navy_color' => '#0F2E24',
                    'gold_color' => '#D4AF37',
                    'dark_gold_color' => '#B8941F',
                    'accent_color' => '#FFD700'
                ],
                'midnight-purple' => [
                    'navy_color' => '#2E1A47',
                    'deep_navy_color' => '#1A0D2E',
                    'gold_color' => '#D4AF37',
                    'dark_gold_color' => '#B8941F',
                    'accent_color' => '#E5D047'
                ]
            ];
            
            if (isset($presets[$preset])) {
                foreach ($presets[$preset] as $key => $value) {
                    $stmt = $pdo->prepare("
                        INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                        VALUES (?, ?, 'theme', NOW())
                        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                    ");
                    $stmt->execute([$key, $value, $value]);
                }
                
                // Clear cache
                require_once __DIR__ . '/../config/cache.php';
                clearCache();
                
                $message = 'Preset theme applied successfully!';
                $success = true;
            } else {
                $error = 'Invalid preset theme selected.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get current theme colors
$current_colors = [
    'navy_color' => '#0A1929',
    'deep_navy_color' => '#05090F',
    'gold_color' => '#D4AF37',
    'dark_gold_color' => '#B8941F',
    'accent_color' => '#D4AF37'
];

// Determine which preset is currently active
$current_preset = 'custom';

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE '%color%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $current_colors = array_merge($current_colors, $settings);
    
    // Check if current colors match any preset
    $presets = [
        'navy-gold' => [
            'navy_color' => '#0A1929',
            'deep_navy_color' => '#05090F',
            'gold_color' => '#D4AF37',
            'dark_gold_color' => '#B8941F',
            'accent_color' => '#D4AF37'
        ],
        'burgundy-gold' => [
            'navy_color' => '#722F37',
            'deep_navy_color' => '#4A1A21',
            'gold_color' => '#D4AF37',
            'dark_gold_color' => '#B8941F',
            'accent_color' => '#D4AF37'
        ],
        'forest-green' => [
            'navy_color' => '#1B4D3E',
            'deep_navy_color' => '#0F2E24',
            'gold_color' => '#D4AF37',
            'dark_gold_color' => '#B8941F',
            'accent_color' => '#FFD700'
        ],
        'midnight-purple' => [
            'navy_color' => '#2E1A47',
            'deep_navy_color' => '#1A0D2E',
            'gold_color' => '#D4AF37',
            'dark_gold_color' => '#B8941F',
            'accent_color' => '#E5D047'
        ]
    ];
    
    foreach ($presets as $preset_name => $preset_colors) {
        $matches = true;
        foreach ($preset_colors as $key => $value) {
            if (isset($current_colors[$key]) && $current_colors[$key] !== $value) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            $current_preset = $preset_name;
            break;
        }
    }
} catch (PDOException $e) {
    $error = 'Error loading theme colors: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .theme-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .theme-section h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gold);
        }
        
        .color-input-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .color-input-item {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
        }
        
        .color-input-item label {
            display: block;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 8px;
        }
        
        .color-input-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .color-preview {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            border: 3px solid #e0e0e0;
            flex-shrink: 0;
        }
        
        .color-input {
            flex: 1;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .preset-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .preset-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .preset-card:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
        }
        
        .preset-card.active {
            border-color: var(--gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .preset-preview {
            height: 80px;
            border-radius: 8px;
            margin-bottom: 12px;
            display: flex;
        }
        
        .preset-name {
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 4px;
        }
        
        .preset-description {
            font-size: 13px;
            color: #666;
        }
        
        .btn-primary {
            background: var(--gold);
            color: var(--navy);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            background: var(--dark-gold);
            transform: translateY(-2px);
        }
        
        .live-preview-box {
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
            background: var(--navy);
        }
        
        .preview-content {
            text-align: center;
            padding: 20px;
        }
        
        .preview-title {
            font-family: var(--font-serif);
            font-size: 28px;
            color: var(--gold);
            margin-bottom: 12px;
        }
        
        .preview-button {
            background: linear-gradient(135deg, var(--gold), var(--dark-gold));
            color: var(--navy);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-palette"></i> Theme Management
            </h2>
            <p class="text-muted">Customize your site's colors and appearance</p>
        </div>
        
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>
        
        <!-- Section Headers Info -->
        <div style="background: linear-gradient(135deg, #E8F4F8 0%, #F0F8FF 100%); border-left: 4px solid var(--gold); padding: 20px; border-radius: 8px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
            <div style="display: flex; align-items: start; gap: 16px;">
                <div style="flex-shrink: 0; width: 44px; height: 44px; background: var(--gold); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px;">
                    <i class="fas fa-heading"></i>
                </div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 8px 0; color: var(--navy); font-size: 18px; font-weight: 600;">
                        Manage Section Headers & Content
                    </h3>
                    <p style="margin: 0 0 12px 0; color: #555; font-size: 14px; line-height: 1.6;">
                        Looking to customize section titles, labels, and descriptions across your website? 
                        Use the <strong>Section Headers</strong> management tool to update all section content including labels, subtitles, titles, and descriptions.
                    </p>
                    <a href="section-headers-management.php" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--gold); color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; transition: all 0.3s ease;">
                        <i class="fas fa-heading"></i>
                        Manage Section Headers
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Preset Themes -->
        <div class="theme-section">
            <h2><i class="fas fa-swatchbook"></i> Preset Themes</h2>
            <p style="margin-bottom: 16px; color: #666;">Quickly apply professionally designed color schemes</p>
            
            <form method="POST" id="presetForm">
                <input type="hidden" name="action" value="apply_preset">
                
                <div class="preset-grid">
                    <div class="preset-card <?php echo $current_preset === 'navy-gold' ? 'active' : ''; ?>" onclick="selectPreset(this, 'navy-gold')">
                        <input type="radio" name="preset" value="navy-gold" style="display: none;" <?php echo $current_preset === 'navy-gold' ? 'checked' : ''; ?>>
                        <div class="preset-preview" style="background: linear-gradient(135deg, #0A1929 50%, #D4AF37 50%);"></div>
                        <div class="preset-name">Navy & Gold</div>
                        <div class="preset-description">Classic luxury elegance</div>
                        <?php if ($current_preset === 'navy-gold'): ?>
                            <div style="margin-top: 8px; color: var(--gold); font-weight: 600; font-size: 12px;"><i class="fas fa-check-circle"></i> Active</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="preset-card <?php echo $current_preset === 'burgundy-gold' ? 'active' : ''; ?>" onclick="selectPreset(this, 'burgundy-gold')">
                        <input type="radio" name="preset" value="burgundy-gold" style="display: none;" <?php echo $current_preset === 'burgundy-gold' ? 'checked' : ''; ?>>
                        <div class="preset-preview" style="background: linear-gradient(135deg, #722F37 50%, #D4AF37 50%);"></div>
                        <div class="preset-name">Burgundy & Gold</div>
                        <div class="preset-description">Warm, sophisticated ambiance</div>
                        <?php if ($current_preset === 'burgundy-gold'): ?>
                            <div style="margin-top: 8px; color: var(--gold); font-weight: 600; font-size: 12px;"><i class="fas fa-check-circle"></i> Active</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="preset-card <?php echo $current_preset === 'forest-green' ? 'active' : ''; ?>" onclick="selectPreset(this, 'forest-green')">
                        <input type="radio" name="preset" value="forest-green" style="display: none;" <?php echo $current_preset === 'forest-green' ? 'checked' : ''; ?>>
                        <div class="preset-preview" style="background: linear-gradient(135deg, #1B4D3E 50%, #D4AF37 50%);"></div>
                        <div class="preset-name">Forest Green</div>
                        <div class="preset-description">Nature-inspired tranquility</div>
                        <?php if ($current_preset === 'forest-green'): ?>
                            <div style="margin-top: 8px; color: var(--gold); font-weight: 600; font-size: 12px;"><i class="fas fa-check-circle"></i> Active</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="preset-card <?php echo $current_preset === 'midnight-purple' ? 'active' : ''; ?>" onclick="selectPreset(this, 'midnight-purple')">
                        <input type="radio" name="preset" value="midnight-purple" style="display: none;" <?php echo $current_preset === 'midnight-purple' ? 'checked' : ''; ?>>
                        <div class="preset-preview" style="background: linear-gradient(135deg, #2E1A47 50%, #E5D047 50%);"></div>
                        <div class="preset-name">Midnight Purple</div>
                        <div class="preset-description">Modern, regal atmosphere</div>
                        <?php if ($current_preset === 'midnight-purple'): ?>
                            <div style="margin-top: 8px; color: var(--gold); font-weight: 600; font-size: 12px;"><i class="fas fa-check-circle"></i> Active</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-magic"></i> Apply Selected Theme
                    </button>
                </div>
            </form>
            <form method="POST" style="display:inline; margin-top:8px;">
                <input type="hidden" name="action" value="revert_default">
                <button type="submit" class="btn-primary" style="background: #666; color: white;" onclick="return confirm('Are you sure you want to revert to the default Navy & Gold theme? All custom color changes will be lost.')">
                    <i class="fas fa-undo"></i> Revert to Default
                </button>
            </form>
        </div>
        
        <!-- Custom Colors -->
        <div class="theme-section">
            <h2><i class="fas fa-paint-brush"></i> Custom Color Scheme</h2>
            <p style="margin-bottom: 16px; color: #666;">Fine-tune each color for complete customization</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="save_colors">
                
                <div class="color-input-group">
                    <div class="color-input-item">
                        <label for="navy_color">Primary Navy Color</label>
                        <div class="color-input-wrapper">
                            <input type="color" id="navy_color_preview" class="color-preview" 
                                   value="<?php echo htmlspecialchars($current_colors['navy_color']); ?>"
                                   onchange="document.getElementById('navy_color').value = this.value;">
                            <input type="text" id="navy_color" name="navy_color" class="color-input"
                                   value="<?php echo htmlspecialchars($current_colors['navy_color']); ?>"
                                   onchange="document.getElementById('navy_color_preview').value = this.value;">
                        </div>
                    </div>
                    
                    <div class="color-input-item">
                        <label for="deep_navy_color">Deep Navy Color</label>
                        <div class="color-input-wrapper">
                            <input type="color" id="deep_navy_color_preview" class="color-preview"
                                   value="<?php echo htmlspecialchars($current_colors['deep_navy_color']); ?>"
                                   onchange="document.getElementById('deep_navy_color').value = this.value;">
                            <input type="text" id="deep_navy_color" name="deep_navy_color" class="color-input"
                                   value="<?php echo htmlspecialchars($current_colors['deep_navy_color']); ?>"
                                   onchange="document.getElementById('deep_navy_color_preview').value = this.value;">
                        </div>
                    </div>
                    
                    <div class="color-input-item">
                        <label for="gold_color">Gold Accent Color</label>
                        <div class="color-input-wrapper">
                            <input type="color" id="gold_color_preview" class="color-preview"
                                   value="<?php echo htmlspecialchars($current_colors['gold_color']); ?>"
                                   onchange="document.getElementById('gold_color').value = this.value;">
                            <input type="text" id="gold_color" name="gold_color" class="color-input"
                                   value="<?php echo htmlspecialchars($current_colors['gold_color']); ?>"
                                   onchange="document.getElementById('gold_color_preview').value = this.value;">
                        </div>
                    </div>
                    
                    <div class="color-input-item">
                        <label for="dark_gold_color">Dark Gold Color</label>
                        <div class="color-input-wrapper">
                            <input type="color" id="dark_gold_color_preview" class="color-preview"
                                   value="<?php echo htmlspecialchars($current_colors['dark_gold_color']); ?>"
                                   onchange="document.getElementById('dark_gold_color').value = this.value;">
                            <input type="text" id="dark_gold_color" name="dark_gold_color" class="color-input"
                                   value="<?php echo htmlspecialchars($current_colors['dark_gold_color']); ?>"
                                   onchange="document.getElementById('dark_gold_color_preview').value = this.value;">
                        </div>
                    </div>
                    
                    <div class="color-input-item">
                        <label for="accent_color">Accent Color</label>
                        <div class="color-input-wrapper">
                            <input type="color" id="accent_color_preview" class="color-preview"
                                   value="<?php echo htmlspecialchars($current_colors['accent_color']); ?>"
                                   onchange="document.getElementById('accent_color').value = this.value;">
                            <input type="text" id="accent_color" name="accent_color" class="color-input"
                                   value="<?php echo htmlspecialchars($current_colors['accent_color']); ?>"
                                   onchange="document.getElementById('accent_color_preview').value = this.value;">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Save Color Scheme
                </button>
            </form>
        </div>
        
        <!-- Live Preview -->
        <div class="theme-section">
            <h2><i class="fas fa-eye"></i> Live Preview</h2>
            <p style="margin-bottom: 16px; color: #666;">See how your colors will look on the site</p>
            
            <div class="live-preview-box">
                <div class="preview-content">
                    <h3 class="preview-title" style="color: var(--gold);">Welcome to Luxury</h3>
                    <p style="color: white; margin-bottom: 16px;">Experience unparalleled elegance and comfort</p>
                    <button class="preview-button">Book Your Stay</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function selectPreset(card, presetValue) {
        // Remove active class from all preset cards
        document.querySelectorAll('.preset-card').forEach(function(presetCard) {
            presetCard.classList.remove('active');
            // Remove any existing active indicators
            const existingIndicator = presetCard.querySelector('.active-indicator');
            if (existingIndicator) {
                existingIndicator.remove();
            }
        });
        
        // Add active class to clicked card
        card.classList.add('active');
        
        // Check the radio button
        const radio = card.querySelector('input[type=radio]');
        if (radio) {
            radio.checked = true;
        }
        
        // Add visual active indicator if it doesn't exist
        const existingCheckmark = card.querySelector('.active-indicator');
        if (!existingCheckmark) {
            const activeIndicator = document.createElement('div');
            activeIndicator.className = 'active-indicator';
            activeIndicator.style.cssText = 'margin-top: 8px; color: var(--gold); font-weight: 600; font-size: 12px;';
            activeIndicator.innerHTML = '<i class="fas fa-check-circle"></i> Selected';
            card.appendChild(activeIndicator);
        }
        
        // Update custom color inputs and preview with preset colors
        const presets = {
            'navy-gold': { navy_color: '#0A1929', deep_navy_color: '#05090F', gold_color: '#D4AF37', dark_gold_color: '#B8941F', accent_color: '#D4AF37' },
            'burgundy-gold': { navy_color: '#722F37', deep_navy_color: '#4A1A21', gold_color: '#D4AF37', dark_gold_color: '#B8941F', accent_color: '#D4AF37' },
            'forest-green': { navy_color: '#1B4D3E', deep_navy_color: '#0F2E24', gold_color: '#D4AF37', dark_gold_color: '#B8941F', accent_color: '#FFD700' },
            'midnight-purple': { navy_color: '#2E1A47', deep_navy_color: '#1A0D2E', gold_color: '#D4AF37', dark_gold_color: '#B8941F', accent_color: '#E5D047' }
        };
        
        if (presets[presetValue]) {
            const colors = presets[presetValue];
            for (const [key, value] of Object.entries(colors)) {
                const input = document.getElementById(key);
                const preview = document.getElementById(key + '_preview');
                if (input) input.value = value;
                if (preview) preview.value = value;
            }
            updateLivePreview();
        }
    }
    
    // Live preview - update CSS variables and preview box in real time
    function updateLivePreview() {
        const navyColor = document.getElementById('navy_color').value;
        const deepNavyColor = document.getElementById('deep_navy_color').value;
        const goldColor = document.getElementById('gold_color').value;
        const darkGoldColor = document.getElementById('dark_gold_color').value;
        const accentColor = document.getElementById('accent_color').value;
        
        // Update the live preview box
        const previewBox = document.querySelector('.live-preview-box');
        if (previewBox) {
            previewBox.style.background = navyColor;
        }
        
        const previewTitle = document.querySelector('.preview-title');
        if (previewTitle) {
            previewTitle.style.color = goldColor;
        }
        
        const previewButton = document.querySelector('.preview-button');
        if (previewButton) {
            previewButton.style.background = `linear-gradient(135deg, ${goldColor}, ${darkGoldColor})`;
            previewButton.style.color = navyColor;
        }
    }
    
    // Attach live preview listeners to all color inputs
    document.querySelectorAll('.color-input, .color-preview').forEach(function(input) {
        input.addEventListener('input', function() {
            // Sync color picker and text input
            const id = this.id.replace('_preview', '');
            const textInput = document.getElementById(id);
            const colorInput = document.getElementById(id + '_preview');
            if (this.type === 'color' && textInput) textInput.value = this.value;
            if (this.type === 'text' && colorInput) colorInput.value = this.value;
            updateLivePreview();
        });
    });
    </script>
    
    <?php require_once 'includes/admin-footer.php'; ?>