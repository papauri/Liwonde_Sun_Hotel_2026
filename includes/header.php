    <?php
    // Load base URL helper
    require_once __DIR__ . '/../config/base-url.php';
    ?>
    <header class="header">
        <div class="container">
            <nav class="navbar">
                <!-- Mobile: Hotel icon on far left (separate element) -->
                <a href="<?php echo siteUrl('/'); ?>" class="logo-hotel-icon-link" aria-label="Go to home">
                    <svg class="logo-hotel-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 22V6L12 2L22 6V22H14V16C14 15.4696 13.7893 14.9609 13.4142 14.5858C13.0391 14.2107 12.5304 14 12 14C11.4696 14 10.9609 14.2107 10.5858 14.5858C10.2107 14.9609 10 15.4696 10 16V22H2Z" stroke="url(#logoGradient)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M6 10H8M6 14H8M16 10H18M16 14H18" stroke="url(#logoGradient)" stroke-width="1.5" stroke-linecap="round"/>
                        <defs>
                            <linearGradient id="logoGradient" x1="2" y1="2" x2="22" y2="22">
                                <stop offset="0%" stop-color="#D4AF37"/>
                                <stop offset="50%" stop-color="#FFD700"/>
                                <stop offset="100%" stop-color="#B8860B"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </a>
                
                <!-- Mobile: Logo text in middle (separate element) -->
                <a href="<?php echo siteUrl('/'); ?>" class="logo-text-link" aria-label="Go to home">
                    <span class="logo-text"><?php echo htmlspecialchars($site_name); ?></span>
                </a>
                
                <!-- Desktop: Original logo structure (hidden on mobile) -->
                <a href="<?php echo siteUrl('/'); ?>" class="logo" aria-label="Go to home">
                    <?php if (!empty($site_logo)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" class="logo-image" />
                    <?php endif; ?>
                    <span class="logo-text"><?php echo htmlspecialchars($site_name); ?></span>
                    <svg class="logo-hotel-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 22V6L12 2L22 6V22H14V16C14 15.4696 13.7893 14.9609 13.4142 14.5858C13.0391 14.2107 12.5304 14 12 14C11.4696 14 10.9609 14.2107 10.5858 14.5858C10.2107 14.9609 10 15.4696 10 16V22H2Z" stroke="url(#logoGradient)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M6 10H8M6 14H8M16 10H18M16 14H18" stroke="url(#logoGradient)" stroke-width="1.5" stroke-linecap="round"/>
                        <defs>
                            <linearGradient id="logoGradient2" x1="2" y1="2" x2="22" y2="22">
                                <stop offset="0%" stop-color="#D4AF37"/>
                                <stop offset="50%" stop-color="#FFD700"/>
                                <stop offset="100%" stop-color="#B8860B"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </a>
                
                <?php
                // Determine current page for active nav highlighting
                $current_file = basename($_SERVER['PHP_SELF']);
                
                // Function to check if nav link is active
                // Works for any page added via site_pages (dynamic or hardcoded)
                function is_nav_active($link_file) {
                    global $current_file;

                    // Normalise to bare filename so sub-paths like ./spa.php still match
                    $link_base = basename($link_file);

                    // Direct match
                    if ($current_file === $link_base) {
                        return true;
                    }

                    // Special case: room.php (detail page) highlights "Rooms" nav
                    if ($current_file === 'room.php' && $link_base === 'rooms-gallery.php') {
                        return true;
                    }

                    return false;
                }

                // ── Load pages from site_pages table ─────────────────
                $_nav_pages = [];
                $_nav_booking = null; // CTA button handled separately
                try {
                    if (isset($pdo)) {
                        $nav_stmt = $pdo->query("
                            SELECT page_key, title, file_path, icon
                            FROM site_pages
                            WHERE is_enabled = 1 AND show_in_nav = 1
                            ORDER BY nav_position ASC
                        ");
                        $all_nav = $nav_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($all_nav as $np) {
                            if ($np['page_key'] === 'booking') {
                                $_nav_booking = $np;
                            } else {
                                $_nav_pages[] = $np;
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Table doesn't exist yet — fall back to hardcoded
                    $_nav_pages = null;
                }

                // Fallback: if no DB pages loaded, use the original hardcoded nav
                if (empty($_nav_pages) && $_nav_pages !== []) {
                    $_nav_pages = [
                        ['page_key' => 'home',       'title' => 'Home',       'file_path' => 'index.php',        'icon' => 'fa-home'],
                        ['page_key' => 'rooms',      'title' => 'Rooms',      'file_path' => 'rooms-gallery.php','icon' => 'fa-bed'],
                        ['page_key' => 'restaurant', 'title' => 'Restaurant', 'file_path' => 'restaurant.php',   'icon' => 'fa-utensils'],
                        ['page_key' => 'gym',        'title' => 'Gym',        'file_path' => 'gym.php',          'icon' => 'fa-dumbbell'],
                        ['page_key' => 'conference', 'title' => 'Conference', 'file_path' => 'conference.php',   'icon' => 'fa-briefcase'],
                        ['page_key' => 'events',     'title' => 'Events',     'file_path' => 'events.php',       'icon' => 'fa-calendar-alt'],
                    ];
                    $_nav_booking = ['page_key' => 'booking', 'title' => 'Book Now', 'file_path' => 'booking.php', 'icon' => 'fa-calendar-check'];
                }
                ?>
                
                <ul class="nav-menu" id="primary-nav">
                    <?php foreach ($_nav_pages as $navp): ?>
                    <li class="nav-item">
                        <a href="<?php echo siteUrl($navp['file_path']); ?>" class="nav-link <?php echo is_nav_active($navp['file_path']) ? 'active' : ''; ?>">
                            <span class="link-text"><?php echo htmlspecialchars($navp['title']); ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                    <?php if ($_nav_booking): ?>
                    <li class="nav-item nav-item-cta">
                        <a href="<?php echo siteUrl($_nav_booking['file_path']); ?>" class="nav-cta <?php echo is_nav_active($_nav_booking['file_path']) ? 'active' : ''; ?>">
                            <i class="fas <?php echo htmlspecialchars($_nav_booking['icon']); ?>"></i> <?php echo htmlspecialchars($_nav_booking['title']); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <button class="mobile-menu-btn" type="button" aria-controls="primary-nav" aria-expanded="false" aria-label="Open menu">
                    <span class="menu-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </nav>
        </div>
    </header>

    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" role="presentation"></div>