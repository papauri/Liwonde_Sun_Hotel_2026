<?php
/**
 * Modal Component - Reusable centered modal/popup
 * 
 * Usage:
 * 1. Include this file: <?php include 'includes/modal.php'; ?>
 * 2. Call renderModal() function to display a modal
 * 
 * @param string $id - Unique modal ID (required)
 * @param string $title - Modal title (required)
 * @param string $content - Modal body content (required)
 * @param array $options - Optional parameters:
 *   - 'size': 'sm' (small), 'md' (medium, default), 'lg' (large), 'xl' (extra large)
 *   - 'show_close': true/false (default: true)
 *   - 'close_on_overlay': true/false (default: true)
 *   - 'close_on_escape': true/false (default: true)
 *   - 'footer': Footer content (optional)
 *   - 'class': Additional CSS classes (optional)
 *   - 'attributes': Additional HTML attributes (optional)
 */

if (!function_exists('renderModal')) {
    function renderModal($id, $title, $content, $options = []) {
        // Default options
        $defaults = [
            'size' => 'md',
            'show_close' => true,
            'close_on_overlay' => true,
            'close_on_escape' => true,
            'footer' => null,
            'class' => '',
            'attributes' => ''
        ];
        
        $opts = array_merge($defaults, $options);
        
        // Size classes
        $sizeClasses = [
            'sm' => 'modal-sm',
            'md' => 'modal-md',
            'lg' => 'modal-lg',
            'xl' => 'modal-xl'
        ];
        $sizeClass = $sizeClasses[$opts['size']] ?? 'modal-md';
        
        // Build attributes
        $dataAttrs = '';
        if ($opts['close_on_overlay']) {
            $dataAttrs .= ' data-close-on-overlay="true"';
        }
        if ($opts['close_on_escape']) {
            $dataAttrs .= ' data-close-on-escape="true"';
        }
        if (!empty($opts['attributes'])) {
            $dataAttrs .= ' ' . $opts['attributes'];
        }
        ?>
        <!-- Modal: <?php echo htmlspecialchars($id); ?> -->
        <div class="modal-overlay" id="<?php echo htmlspecialchars($id); ?>-overlay" data-modal-overlay></div>
        <div class="modal-wrapper <?php echo htmlspecialchars($sizeClass); ?> <?php echo htmlspecialchars($opts['class']); ?>" 
             id="<?php echo htmlspecialchars($id); ?>" 
             data-modal<?php echo $dataAttrs; ?>>
            <div class="modal-container">
                <?php if ($opts['show_close']): ?>
                    <button class="modal-close" data-modal-close aria-label="Close modal">
                        <i class="fas fa-times"></i>
                    </button>
                <?php endif; ?>
                
                <?php if (!empty($title)): ?>
                    <div class="modal-header">
                        <h3 class="modal-title"><?php echo $title; ?></h3>
                    </div>
                <?php endif; ?>
                
                <div class="modal-body">
                    <?php echo $content; ?>
                </div>
                
                <?php if (!empty($opts['footer'])): ?>
                    <div class="modal-footer">
                        <?php echo $opts['footer']; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
?>
