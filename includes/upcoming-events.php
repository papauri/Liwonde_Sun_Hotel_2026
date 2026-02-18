<?php
/**
 * Upcoming Events Section – Reusable Include
 * 
 * A modern, sleek timeline-style display of upcoming events.
 * Can be included on any page. Checks per-page visibility settings.
 * 
 * Usage: 
 *   $upcoming_events_page = 'index'; // page identifier
 *   include 'includes/upcoming-events.php';
 * 
 * Requires: config/database.php (for $pdo and getSetting)
 */

// Ensure section-headers helper is available
if (!function_exists('renderSectionHeader')) {
    require_once __DIR__ . '/section-headers.php';
}

// Wrap everything in a try-catch to guarantee the page never breaks
try {

// Determine current page identifier
$upcoming_events_page = $upcoming_events_page ?? 'index';

// Check if the section is globally enabled
$ue_enabled = getSetting('upcoming_events_enabled', '1');
if ($ue_enabled !== '1') return;

// Check if this page is in the allowed pages list
$ue_pages_json = getSetting('upcoming_events_pages', '["index"]');
$ue_pages = json_decode($ue_pages_json, true) ?: ['index'];
if (!in_array($upcoming_events_page, $ue_pages)) return;

// How many events to show
$ue_max = (int) getSetting('upcoming_events_max_display', '4');
$ue_max = max(1, min($ue_max, 8)); // clamp 1-8

// Check if show_in_upcoming column exists (migration may not be run yet)
$ue_col_exists = false;
try {
    $ue_col_check = $pdo->query("SHOW COLUMNS FROM events LIKE 'show_in_upcoming'");
    $ue_col_exists = ($ue_col_check && $ue_col_check->rowCount() > 0);
} catch (\Throwable $e) {
    error_log("Upcoming events column check error: " . $e->getMessage());
}

if (!$ue_col_exists) {
    // Auto-create the column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN show_in_upcoming TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured");
        // Set featured events to show in upcoming by default
        $pdo->exec("UPDATE events SET show_in_upcoming = 1 WHERE is_featured = 1");
        $ue_col_exists = true;
    } catch (\Throwable $e) {
        error_log("Upcoming events auto-migration error: " . $e->getMessage());
        return; // Can't proceed without the column
    }
}

// Fetch upcoming events marked for display
$upcoming_events_list = [];
try {
    $ue_stmt = $pdo->prepare("
        SELECT id, title, description, event_date, start_time, end_time, 
               location, ticket_price, image_path
        FROM events
        WHERE is_active = 1
          AND show_in_upcoming = 1
          AND event_date >= CURDATE()
        ORDER BY event_date ASC, start_time ASC
        LIMIT :ue_limit
    ");
    $ue_stmt->bindValue(':ue_limit', $ue_max, PDO::PARAM_INT);
    $ue_stmt->execute();
    $upcoming_events_list = $ue_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Upcoming events fetch error: " . $e->getMessage());
    $upcoming_events_list = [];
}

// Don't render the section if there are no upcoming events
if (empty($upcoming_events_list)) return;

$ue_currency = getSetting('currency_symbol', 'MWK');
?>

<!-- Upcoming Events Section -->
<section class="upcoming-events-section" id="upcoming-events">
    <div class="container">
        <?php renderSectionHeader('upcoming_events', 'index', [
            'label' => "What's Happening",
            'title' => 'Upcoming Events',
            'description' => "Don't miss out on our carefully curated experiences and celebrations"
        ]); ?>

        <div class="ue-timeline">
            <?php foreach ($upcoming_events_list as $ue_index => $ue_event): 
                $ue_date = new DateTime($ue_event['event_date']);
                $ue_day = $ue_date->format('d');
                $ue_month = $ue_date->format('M');
                $ue_year = $ue_date->format('Y');
                $ue_weekday = $ue_date->format('l');
                $ue_start = $ue_event['start_time'] ? date('g:i A', strtotime($ue_event['start_time'])) : '';
                $ue_end = $ue_event['end_time'] ? date('g:i A', strtotime($ue_event['end_time'])) : '';
                $ue_time_str = $ue_start;
                if ($ue_start && $ue_end) $ue_time_str = $ue_start . ' – ' . $ue_end;
                $ue_raw_desc = strip_tags($ue_event['description'] ?? '');
                $ue_desc = htmlspecialchars(strlen($ue_raw_desc) > 140 ? substr($ue_raw_desc, 0, 137) . '...' : $ue_raw_desc);
                $ue_has_image = !empty($ue_event['image_path']);
                $ue_price = floatval($ue_event['ticket_price']);
                $ue_is_reverse = ($ue_index % 2 === 1);
            ?>
            <div class="ue-item <?php echo $ue_is_reverse ? 'ue-item--reverse' : ''; ?>">
                <!-- Date Pill -->
                <div class="ue-date-pill">
                    <span class="ue-date-day"><?php echo $ue_day; ?></span>
                    <span class="ue-date-month"><?php echo strtoupper($ue_month); ?></span>
                </div>

                <!-- Content Card -->
                <div class="ue-card">
                    <?php if ($ue_has_image): ?>
                    <div class="ue-card-image">
                        <img src="<?php echo htmlspecialchars($ue_event['image_path']); ?>" 
                             alt="<?php echo htmlspecialchars($ue_event['title']); ?>"
                             loading="lazy" width="600" height="400">
                        <?php if ($ue_price > 0): ?>
                        <span class="ue-card-price"><?php echo $ue_currency . ' ' . number_format($ue_price, 0); ?></span>
                        <?php else: ?>
                        <span class="ue-card-price ue-card-price--free">Free Entry</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="ue-card-body">
                        <h3 class="ue-card-title"><?php echo htmlspecialchars($ue_event['title']); ?></h3>
                        
                        <div class="ue-card-meta">
                            <span class="ue-meta-item">
                                <i class="fas fa-calendar-day"></i>
                                <?php echo $ue_weekday; ?>, <?php echo $ue_date->format('M j, Y'); ?>
                            </span>
                            <?php if ($ue_time_str): ?>
                            <span class="ue-meta-item">
                                <i class="fas fa-clock"></i>
                                <?php echo $ue_time_str; ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($ue_event['location'])): ?>
                            <span class="ue-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($ue_event['location']); ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($ue_desc): ?>
                        <p class="ue-card-desc"><?php echo $ue_desc; ?></p>
                        <?php endif; ?>
                        
                        <a href="events.php" class="ue-card-link">
                            Learn More <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ue-footer">
            <a href="events.php" class="btn btn-outline">
                View All Events <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>
<?php
} catch (\Throwable $e) {
    // Silently fail — never break the parent page
    error_log("Upcoming events section error: " . $e->getMessage());
}
?>
