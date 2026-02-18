<?php
/**
 * Admin - Visitor Analytics
 * View website visitor sessions: who visited, from where, which device, etc.
 */

require_once 'admin-init.php';

$site_name = getSetting('site_name');
$filter_device = $_GET['device'] ?? '';
$filter_range = $_GET['range'] ?? 'today';

// Build date range
switch ($filter_range) {
    case 'today':
        $date_start = date('Y-m-d 00:00:00');
        $date_end = date('Y-m-d 23:59:59');
        break;
    case '7days':
        $date_start = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $date_end = date('Y-m-d 23:59:59');
        break;
    case '30days':
        $date_start = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $date_end = date('Y-m-d 23:59:59');
        break;
    case 'custom':
        $date_start = ($_GET['date_start'] ?? date('Y-m-d')) . ' 00:00:00';
        $date_end = ($_GET['date_end'] ?? date('Y-m-d')) . ' 23:59:59';
        break;
    default:
        $date_start = date('Y-m-d 00:00:00');
        $date_end = date('Y-m-d 23:59:59');
}

$table_exists = false;
$stats = ['total_views' => 0, 'unique_sessions' => 0, 'unique_ips' => 0, 'new_visitors' => 0];
$devices = [];
$browsers = [];
$operating_systems = [];
$top_pages = [];
$referrers = [];
$visitors = [];
$hourly_data = array_fill(0, 24, 0);

try {
    // Check if table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'site_visitors'");
    $table_exists = $table_check->rowCount() > 0;

    if ($table_exists) {
        // Summary stats
        $stats_sql = "SELECT
            COUNT(*) as total_views,
            COUNT(DISTINCT session_id) as unique_sessions,
            COUNT(DISTINCT ip_address) as unique_ips,
            SUM(is_first_visit) as new_visitors
            FROM site_visitors WHERE created_at BETWEEN ? AND ?";
        $stats_params = [$date_start, $date_end];

        if ($filter_device && $filter_device !== 'all') {
            $stats_sql .= " AND device_type = ?";
            $stats_params[] = $filter_device;
        }

        $stats_stmt = $pdo->prepare($stats_sql);
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Device breakdown
        $device_stmt = $pdo->prepare("
            SELECT device_type, COUNT(*) as count, COUNT(DISTINCT session_id) as sessions
            FROM site_visitors WHERE created_at BETWEEN ? AND ?
            GROUP BY device_type ORDER BY count DESC
        ");
        $device_stmt->execute([$date_start, $date_end]);
        $devices = $device_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Browser breakdown
        $browser_stmt = $pdo->prepare("
            SELECT browser, COUNT(*) as count
            FROM site_visitors WHERE created_at BETWEEN ? AND ?
            GROUP BY browser ORDER BY count DESC LIMIT 10
        ");
        $browser_stmt->execute([$date_start, $date_end]);
        $browsers = $browser_stmt->fetchAll(PDO::FETCH_ASSOC);

        // OS breakdown
        $os_stmt = $pdo->prepare("
            SELECT os, COUNT(*) as count
            FROM site_visitors WHERE created_at BETWEEN ? AND ?
            GROUP BY os ORDER BY count DESC LIMIT 10
        ");
        $os_stmt->execute([$date_start, $date_end]);
        $operating_systems = $os_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top pages
        $pages_stmt = $pdo->prepare("
            SELECT page_url, COUNT(*) as views, COUNT(DISTINCT session_id) as unique_views
            FROM site_visitors WHERE created_at BETWEEN ? AND ?
            GROUP BY page_url ORDER BY views DESC LIMIT 15
        ");
        $pages_stmt->execute([$date_start, $date_end]);
        $top_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top referrers
        $ref_stmt = $pdo->prepare("
            SELECT referrer_domain, COUNT(*) as count
            FROM site_visitors WHERE created_at BETWEEN ? AND ? AND referrer_domain != '' AND referrer_domain IS NOT NULL
            GROUP BY referrer_domain ORDER BY count DESC LIMIT 10
        ");
        $ref_stmt->execute([$date_start, $date_end]);
        $referrers = $ref_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent visitors (paginated)
        $page_num = max(1, intval($_GET['page'] ?? 1));
        $per_page = 50;
        $offset = ($page_num - 1) * $per_page;

        $visitors_sql = "SELECT * FROM site_visitors WHERE created_at BETWEEN ? AND ?";
        $visitors_params = [$date_start, $date_end];

        if ($filter_device && $filter_device !== 'all') {
            $visitors_sql .= " AND device_type = ?";
            $visitors_params[] = $filter_device;
        }

        $visitors_sql .= " ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
        $visitors_stmt = $pdo->prepare($visitors_sql);
        $visitors_stmt->execute($visitors_params);
        $visitors = $visitors_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hourly distribution
        $hourly_stmt = $pdo->prepare("
            SELECT HOUR(created_at) as hour, COUNT(*) as count
            FROM site_visitors WHERE created_at BETWEEN ? AND ?
            GROUP BY HOUR(created_at) ORDER BY hour
        ");
        $hourly_stmt->execute([$date_start, $date_end]);
        $hourly = $hourly_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hourly as $h) {
            $hourly_data[$h['hour']] = (int)$h['count'];
        }
    }
} catch (PDOException $e) {
    $table_exists = false;
    error_log('Visitor analytics error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Analytics - <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .analytics-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .page-title { font-family: 'Playfair Display', serif; font-size: 28px; color: var(--navy); }
        .filter-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 24px; padding: 16px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .filter-bar select, .filter-bar input[type="date"] { padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: 'Poppins', sans-serif; font-size: 13px; }
        .filter-bar select:focus, .filter-bar input:focus { outline: none; border-color: var(--gold); }
        .filter-bar .btn-filter { padding: 8px 20px; background: var(--gold); color: var(--deep-navy); border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
        .stat-card .stat-icon { font-size: 24px; color: var(--gold); margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; color: var(--navy); }
        .stat-card .stat-label { font-size: 13px; color: #888; margin-top: 4px; }

        .analytics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .analytics-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .analytics-card h3 { color: var(--navy); margin: 0 0 16px 0; font-size: 16px; padding-bottom: 10px; border-bottom: 2px solid var(--gold); }
        .analytics-card h3 i { color: var(--gold); margin-right: 8px; }

        .breakdown-list { list-style: none; padding: 0; margin: 0; }
        .breakdown-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .breakdown-list li:last-child { border-bottom: none; }
        .breakdown-bar { display: flex; align-items: center; gap: 10px; flex: 1; }
        .breakdown-fill { height: 8px; background: linear-gradient(90deg, var(--gold), #f0c040); border-radius: 4px; min-width: 4px; }
        .breakdown-count { font-weight: 600; color: var(--navy); min-width: 40px; text-align: right; }

        .visitors-table { width: 100%; border-collapse: collapse; }
        .visitors-table th { background: var(--navy); color: white; padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; }
        .visitors-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .visitors-table tr:hover { background: rgba(212, 175, 55, 0.05); }
        .device-badge { padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .device-desktop { background: #e3f2fd; color: #1565c0; }
        .device-mobile { background: #f3e5f5; color: #7b1fa2; }
        .device-tablet { background: #e8f5e9; color: #2e7d32; }
        .device-bot { background: #fce4ec; color: #c62828; }
        .device-unknown { background: #f5f5f5; color: #616161; }

        .hourly-chart { display: flex; align-items: flex-end; justify-content: space-between; height: 120px; gap: 2px; padding: 10px 0; }
        .hourly-bar { flex: 1; background: linear-gradient(to top, var(--gold), #f0c040); border-radius: 3px 3px 0 0; min-width: 8px; position: relative; cursor: pointer; transition: opacity 0.2s; }
        .hourly-bar:hover { opacity: 0.8; }
        .hourly-bar .tooltip { display: none; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: var(--navy); color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; white-space: nowrap; z-index: 10; }
        .hourly-bar:hover .tooltip { display: block; }
        .hourly-labels { display: flex; justify-content: space-between; font-size: 10px; color: #999; }

        .table-wrapper { overflow-x: auto; max-height: 600px; overflow-y: auto; }
        .no-data { text-align: center; padding: 60px 20px; color: #999; }
        .no-data i { font-size: 48px; color: #ddd; margin-bottom: 16px; display: block; }

        .pagination { display: flex; gap: 8px; justify-content: center; margin-top: 16px; }
        .pagination a { padding: 6px 14px; border-radius: 6px; text-decoration: none; border: 1px solid #ddd; color: var(--navy); font-size: 13px; }
        .pagination a.active { background: var(--gold); color: var(--deep-navy); border-color: var(--gold); font-weight: 600; }

        @media (max-width: 768px) {
            .analytics-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="analytics-container">
            <div class="page-header">
                <div>
                    <h1 class="page-title"><i class="fas fa-chart-line" style="color: var(--gold);"></i> Visitor Analytics</h1>
                    <p style="color: #888; margin-top: 4px;">Monitor your website traffic and visitor behavior</p>
                </div>
            </div>

            <?php if (!$table_exists): ?>
                <div class="no-data">
                    <i class="fas fa-database"></i>
                    <h3>Visitor tracking not yet initialized</h3>
                    <p>The tracking table will be created automatically when the first visitor accesses your website.</p>
                    <p style="margin-top: 10px;">Or run the migration: <code>Database/migrations/002_create_site_visitors.sql</code></p>
                </div>
            <?php else: ?>

            <!-- Filters -->
            <form class="filter-bar" method="GET">
                <label style="font-weight: 600; color: var(--navy); font-size: 13px;"><i class="fas fa-filter"></i> Period:</label>
                <select name="range" onchange="toggleCustomDates(this.value)">
                    <option value="today" <?php echo $filter_range === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="7days" <?php echo $filter_range === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="30days" <?php echo $filter_range === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                    <option value="custom" <?php echo $filter_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
                <div id="customDates" style="display: <?php echo $filter_range === 'custom' ? 'flex' : 'none'; ?>; gap: 8px; align-items: center;">
                    <input type="date" name="date_start" value="<?php echo htmlspecialchars($_GET['date_start'] ?? date('Y-m-d')); ?>">
                    <span>to</span>
                    <input type="date" name="date_end" value="<?php echo htmlspecialchars($_GET['date_end'] ?? date('Y-m-d')); ?>">
                </div>
                <label style="font-weight: 600; color: var(--navy); font-size: 13px;">Device:</label>
                <select name="device">
                    <option value="all" <?php echo $filter_device === 'all' || empty($filter_device) ? 'selected' : ''; ?>>All Devices</option>
                    <option value="desktop" <?php echo $filter_device === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                    <option value="mobile" <?php echo $filter_device === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                    <option value="tablet" <?php echo $filter_device === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                    <option value="bot" <?php echo $filter_device === 'bot' ? 'selected' : ''; ?>>Bots</option>
                </select>
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Apply</button>
            </form>

            <!-- Summary Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-eye"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['total_views'] ?? 0); ?></div>
                    <div class="stat-label">Page Views</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['unique_sessions'] ?? 0); ?></div>
                    <div class="stat-label">Unique Sessions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-globe"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['unique_ips'] ?? 0); ?></div>
                    <div class="stat-label">Unique IPs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="stat-value"><?php echo number_format($stats['new_visitors'] ?? 0); ?></div>
                    <div class="stat-label">New Visitors</div>
                </div>
            </div>

            <!-- Hourly Traffic -->
            <div class="analytics-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-clock"></i> Hourly Traffic Distribution</h3>
                <?php $max_hourly = max(1, max($hourly_data)); ?>
                <div class="hourly-chart">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <div class="hourly-bar" style="height: <?php echo max(2, ($hourly_data[$h] / $max_hourly) * 100); ?>%;">
                            <span class="tooltip"><?php echo sprintf('%02d:00', $h); ?> - <?php echo $hourly_data[$h]; ?> visits</span>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="hourly-labels">
                    <span>12am</span><span>3am</span><span>6am</span><span>9am</span>
                    <span>12pm</span><span>3pm</span><span>6pm</span><span>9pm</span>
                </div>
            </div>

            <!-- Breakdowns -->
            <div class="analytics-grid">
                <!-- Device Breakdown -->
                <div class="analytics-card">
                    <h3><i class="fas fa-mobile-alt"></i> Devices</h3>
                    <?php if (empty($devices)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">No data yet</p>
                    <?php else: ?>
                    <ul class="breakdown-list">
                        <?php
                        $total_device = max(1, array_sum(array_column($devices, 'count')));
                        foreach ($devices as $d):
                            $pct = round(($d['count'] / $total_device) * 100);
                            $icons = ['desktop' => 'fa-desktop', 'mobile' => 'fa-mobile-alt', 'tablet' => 'fa-tablet-alt', 'bot' => 'fa-robot', 'unknown' => 'fa-question-circle'];
                            $icon = $icons[$d['device_type']] ?? 'fa-question-circle';
                        ?>
                        <li>
                            <div class="breakdown-bar">
                                <i class="fas <?php echo $icon; ?>" style="color: var(--gold); width: 20px;"></i>
                                <span><?php echo ucfirst($d['device_type']); ?></span>
                                <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                            <span class="breakdown-count"><?php echo $d['count']; ?> (<?php echo $pct; ?>%)</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <!-- Browser Breakdown -->
                <div class="analytics-card">
                    <h3><i class="fas fa-globe"></i> Browsers</h3>
                    <?php if (empty($browsers)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">No data yet</p>
                    <?php else: ?>
                    <ul class="breakdown-list">
                        <?php
                        $total_browser = max(1, array_sum(array_column($browsers, 'count')));
                        foreach ($browsers as $b):
                            $pct = round(($b['count'] / $total_browser) * 100);
                        ?>
                        <li>
                            <div class="breakdown-bar">
                                <span><?php echo htmlspecialchars($b['browser']); ?></span>
                                <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                            <span class="breakdown-count"><?php echo $b['count']; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <!-- OS Breakdown -->
                <div class="analytics-card">
                    <h3><i class="fas fa-laptop"></i> Operating Systems</h3>
                    <?php if (empty($operating_systems)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">No data yet</p>
                    <?php else: ?>
                    <ul class="breakdown-list">
                        <?php
                        $total_os = max(1, array_sum(array_column($operating_systems, 'count')));
                        foreach ($operating_systems as $o):
                            $pct = round(($o['count'] / $total_os) * 100);
                        ?>
                        <li>
                            <div class="breakdown-bar">
                                <span><?php echo htmlspecialchars($o['os']); ?></span>
                                <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                            <span class="breakdown-count"><?php echo $o['count']; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <!-- Top Referrers -->
                <div class="analytics-card">
                    <h3><i class="fas fa-link"></i> Top Referrers</h3>
                    <?php if (empty($referrers)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">No referrer data yet</p>
                    <?php else: ?>
                    <ul class="breakdown-list">
                        <?php
                        $total_ref = max(1, array_sum(array_column($referrers, 'count')));
                        foreach ($referrers as $r):
                            $pct = round(($r['count'] / $total_ref) * 100);
                        ?>
                        <li>
                            <div class="breakdown-bar">
                                <span><?php echo htmlspecialchars($r['referrer_domain']); ?></span>
                                <div class="breakdown-fill" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                            <span class="breakdown-count"><?php echo $r['count']; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Pages -->
            <div class="analytics-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-file-alt"></i> Top Pages</h3>
                <div class="table-wrapper">
                    <table class="visitors-table">
                        <thead>
                            <tr>
                                <th>Page</th>
                                <th>Views</th>
                                <th>Unique</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_pages)): ?>
                            <tr><td colspan="3" style="text-align: center; color: #999; padding: 40px;">No page data yet</td></tr>
                            <?php else: ?>
                            <?php foreach ($top_pages as $pg): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pg['page_url']); ?></td>
                                <td><strong><?php echo number_format($pg['views']); ?></strong></td>
                                <td><?php echo number_format($pg['unique_views']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Visitors Log -->
            <div class="analytics-card">
                <h3><i class="fas fa-list"></i> Recent Visitor Log</h3>
                <div class="table-wrapper">
                    <table class="visitors-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>IP Address</th>
                                <th>Page</th>
                                <th>Device</th>
                                <th>Browser</th>
                                <th>OS</th>
                                <th>Referrer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visitors)): ?>
                            <tr><td colspan="7" style="text-align: center; color: #999; padding: 40px;">No visitor data for this period</td></tr>
                            <?php else: ?>
                            <?php foreach ($visitors as $v): ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo date('H:i:s', strtotime($v['created_at'])); ?><br><small style="color:#999;"><?php echo date('M j', strtotime($v['created_at'])); ?></small></td>
                                <td><code style="font-size: 12px;"><?php echo htmlspecialchars($v['ip_address']); ?></code></td>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($v['page_url']); ?></td>
                                <td><span class="device-badge device-<?php echo $v['device_type']; ?>"><?php echo ucfirst($v['device_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($v['browser']); ?></td>
                                <td><?php echo htmlspecialchars($v['os']); ?></td>
                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($v['referrer_domain'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if (!empty($visitors) && count($visitors) >= $per_page): ?>
                <div class="pagination">
                    <?php if ($page_num > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page_num - 1])); ?>">&laquo; Prev</a>
                    <?php endif; ?>
                    <a class="active" href="#">Page <?php echo $page_num; ?></a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page_num + 1])); ?>">Next &raquo;</a>
                </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleCustomDates(val) {
            document.getElementById('customDates').style.display = val === 'custom' ? 'flex' : 'none';
        }
    </script>
    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>
</body>
</html>
