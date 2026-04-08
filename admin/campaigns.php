<?php
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once '../includes/campaign-attribution.php';

ensureMarketingCampaignInfrastructure($pdo);

$message = '';
$error = '';

$baseSiteUrl = rtrim((string)getSetting('site_url', ''), '/');
if ($baseSiteUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseSiteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function campaignSlugify($text)
{
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim($text, '_');
    return $text !== '' ? substr($text, 0, 140) : 'campaign_' . date('YmdHis');
}

function buildCampaignTrackingUrl($baseSiteUrl, $row)
{
    $path = campaignAttributionNormalizePath($row['destination_path'] ?? '/booking.php');
    $query = [
        'utm_source' => $row['utm_source'] ?? '',
        'utm_medium' => $row['utm_medium'] ?? 'paid_social',
        'utm_campaign' => $row['utm_campaign'] ?? '',
    ];

    if (!empty($row['utm_content'])) {
        $query['utm_content'] = $row['utm_content'];
    }
    if (!empty($row['utm_term'])) {
        $query['utm_term'] = $row['utm_term'];
    }

    return rtrim($baseSiteUrl, '/') . $path . '?' . http_build_query($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfValidation();

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_campaign') {
            $campaignName = trim($_POST['campaign_name'] ?? '');
            $platform = trim($_POST['platform'] ?? 'facebook');
            $objective = trim($_POST['objective'] ?? 'bookings');
            $destinationPath = campaignAttributionNormalizePath($_POST['destination_path'] ?? '/booking.php');
            $utmSource = trim($_POST['utm_source'] ?? ($platform === 'instagram' ? 'instagram' : 'facebook'));
            $utmMedium = trim($_POST['utm_medium'] ?? 'paid_social');
            $utmCampaign = trim($_POST['utm_campaign'] ?? '');
            $utmContent = trim($_POST['utm_content'] ?? '');
            $utmTerm = trim($_POST['utm_term'] ?? '');
            $status = trim($_POST['status'] ?? 'draft');
            $budgetAmount = $_POST['budget_amount'] !== '' ? (float)$_POST['budget_amount'] : null;
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($campaignName === '') {
                throw new Exception('Campaign name is required.');
            }
            if (!in_array($platform, ['facebook', 'instagram', 'mixed'], true)) {
                throw new Exception('Invalid platform selected.');
            }
            if (!in_array($objective, ['bookings', 'events', 'traffic', 'awareness'], true)) {
                throw new Exception('Invalid objective selected.');
            }
            if (!in_array($status, ['draft', 'active', 'paused', 'ended'], true)) {
                throw new Exception('Invalid status selected.');
            }

            if ($utmCampaign === '') {
                $utmCampaign = campaignSlugify($campaignName);
            }

            $insert = $pdo->prepare("INSERT INTO marketing_campaigns (
                    campaign_name, platform, objective, destination_path,
                    utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                    status, budget_amount, start_date, end_date, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $insert->execute([
                $campaignName,
                $platform,
                $objective,
                $destinationPath,
                campaignAttributionSanitize($utmSource, 100),
                campaignAttributionSanitize($utmMedium, 100),
                campaignAttributionSanitize($utmCampaign, 150),
                campaignAttributionSanitize($utmContent, 150),
                campaignAttributionSanitize($utmTerm, 150),
                $status,
                $budgetAmount,
                $startDate !== '' ? $startDate : null,
                $endDate !== '' ? $endDate : null,
                $notes !== '' ? $notes : null,
                $user['id']
            ]);

            $message = 'Campaign created successfully.';
        }

        if ($action === 'update_status') {
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            $status = trim($_POST['status'] ?? 'draft');
            if ($campaignId <= 0 || !in_array($status, ['draft', 'active', 'paused', 'ended'], true)) {
                throw new Exception('Invalid campaign status update request.');
            }

            $update = $pdo->prepare('UPDATE marketing_campaigns SET status = ? WHERE id = ?');
            $update->execute([$status, $campaignId]);
            $message = 'Campaign status updated.';
        }

        if ($action === 'archive_campaign') {
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                throw new Exception('Invalid campaign selected.');
            }
            $update = $pdo->prepare("UPDATE marketing_campaigns SET status = 'ended' WHERE id = ?");
            $update->execute([$campaignId]);
            $message = 'Campaign archived.';
        }

    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$filterStatus = trim($_GET['status'] ?? 'all');
$allowedStatus = ['all', 'draft', 'active', 'paused', 'ended'];
if (!in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = 'all';
}

$campaigns = [];
try {
    $where = '';
    $params = [];
    if ($filterStatus !== 'all') {
        $where = 'WHERE c.status = ?';
        $params[] = $filterStatus;
    }

    $sql = "SELECT
            c.*,
            COALESCE(clicks.total_clicks, 0) AS total_clicks,
            COALESCE(bookings.total_bookings, 0) AS total_bookings,
            COALESCE(bookings.total_booking_revenue, 0) AS total_booking_revenue,
            COALESCE(events.total_event_leads, 0) AS total_event_leads,
            COALESCE(events.total_event_revenue, 0) AS total_event_revenue
        FROM marketing_campaigns c
        LEFT JOIN (
            SELECT campaign_id, SUM(click_count) AS total_clicks
            FROM marketing_campaign_clicks
            GROUP BY campaign_id
        ) clicks ON clicks.campaign_id = c.id
        LEFT JOIN (
            SELECT campaign_id, COUNT(*) AS total_bookings, COALESCE(SUM(total_amount), 0) AS total_booking_revenue
            FROM bookings
            WHERE campaign_id IS NOT NULL
            GROUP BY campaign_id
        ) bookings ON bookings.campaign_id = c.id
        LEFT JOIN (
            SELECT campaign_id, COUNT(*) AS total_event_leads, COALESCE(SUM(total_amount), 0) AS total_event_revenue
            FROM conference_inquiries
            WHERE campaign_id IS NOT NULL
            GROUP BY campaign_id
        ) events ON events.campaign_id = c.id
        {$where}
        ORDER BY c.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Failed to load campaigns: ' . $e->getMessage();
}

$summary = [
    'campaigns' => 0,
    'clicks' => 0,
    'bookings' => 0,
    'booking_revenue' => 0,
    'event_leads' => 0,
    'event_revenue' => 0,
];

foreach ($campaigns as $campaign) {
    $summary['campaigns']++;
    $summary['clicks'] += (int)$campaign['total_clicks'];
    $summary['bookings'] += (int)$campaign['total_bookings'];
    $summary['booking_revenue'] += (float)$campaign['total_booking_revenue'];
    $summary['event_leads'] += (int)$campaign['total_event_leads'];
    $summary['event_revenue'] += (float)$campaign['total_event_revenue'];
}

$currencySymbol = getSetting('currency_symbol', 'MWK');
$siteName = getSetting('site_name', 'Hotel');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">

    <style>
        .campaign-layout {
            display: grid;
            grid-template-columns: 1.2fr 1.8fr;
            gap: 24px;
            margin-top: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-top: 18px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }
        .stat-card .label {
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-top: 6px;
        }
        .panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            padding: 18px;
        }
        .panel h3 {
            margin: 0 0 12px;
            font-size: 18px;
            color: #1f2937;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .form-grid .full {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            display: block;
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
        }
        .campaign-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .campaign-table th,
        .campaign-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-draft { background: #eef2ff; color: #3730a3; }
        .status-active { background: #ecfdf5; color: #065f46; }
        .status-paused { background: #fef3c7; color: #92400e; }
        .status-ended { background: #f3f4f6; color: #374151; }
        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .btn-inline {
            border: none;
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }
        .btn-start { background: #10b981; color: #fff; }
        .btn-pause { background: #f59e0b; color: #fff; }
        .btn-end { background: #6b7280; color: #fff; }
        .tracking-url {
            max-width: 280px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            color: #2563eb;
        }
        .url-cell {
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        .btn-copy-url {
            flex-shrink: 0;
            border: none;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 6px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .btn-copy-url:hover { background: #bae6fd; }
        .btn-copy-url.copied { background: #d1fae5; color: #065f46; }
        .filter-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }
        @media (max-width: 1100px) {
            .campaign-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once 'includes/admin-header.php'; ?>

        <main class="admin-main">
            <div class="container">
                <div class="page-header">
                    <h2><i class="fas fa-bullhorn"></i> Facebook/Instagram Campaigns</h2>
                    <p>Create tracked links, monitor bookings, and include event campaign performance.</p>
                </div>

                <?php if ($message): ?>
                    <?php showAlert($message, 'success'); ?>
                <?php endif; ?>
                <?php if ($error): ?>
                    <?php showAlert($error, 'error'); ?>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card"><div class="label">Campaigns</div><div class="value"><?php echo (int)$summary['campaigns']; ?></div></div>
                    <div class="stat-card"><div class="label">Tracked Clicks</div><div class="value"><?php echo (int)$summary['clicks']; ?></div></div>
                    <div class="stat-card"><div class="label">Room Bookings</div><div class="value"><?php echo (int)$summary['bookings']; ?></div></div>
                    <div class="stat-card"><div class="label">Booking Revenue</div><div class="value"><?php echo htmlspecialchars($currencySymbol) . ' ' . number_format($summary['booking_revenue'], 0); ?></div></div>
                    <div class="stat-card"><div class="label">Event Leads</div><div class="value"><?php echo (int)$summary['event_leads']; ?></div></div>
                    <div class="stat-card"><div class="label">Event Revenue</div><div class="value"><?php echo htmlspecialchars($currencySymbol) . ' ' . number_format($summary['event_revenue'], 0); ?></div></div>
                </div>

                <div class="campaign-layout">
                    <section class="panel">
                        <h3>Create Campaign</h3>
                        <form method="POST">
                            <?php echo getCsrfField(); ?>
                            <input type="hidden" name="action" value="create_campaign">

                            <div class="form-grid">
                                <div class="form-group full">
                                    <label>Campaign Name</label>
                                    <input type="text" name="campaign_name" required placeholder="April Weekend Escape">
                                </div>

                                <div class="form-group">
                                    <label>Platform</label>
                                    <select name="platform" required>
                                        <option value="facebook">Facebook</option>
                                        <option value="instagram">Instagram</option>
                                        <option value="mixed">Mixed</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Objective</label>
                                    <select name="objective" required>
                                        <option value="bookings">Room Bookings</option>
                                        <option value="events">Hotel Events / Conference</option>
                                        <option value="traffic">Traffic</option>
                                        <option value="awareness">Awareness</option>
                                    </select>
                                </div>

                                <div class="form-group full">
                                    <label>Destination Page</label>
                                    <select name="destination_path" required>
                                        <option value="/booking.php">Booking Page</option>
                                        <option value="/events.php">Events Page</option>
                                        <option value="/conference.php">Conference Page</option>
                                        <option value="/rooms-gallery.php">Rooms Gallery</option>
                                        <option value="/restaurant.php">Restaurant</option>
                                        <option value="/gym.php">Gym</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>UTM Source</label>
                                    <input type="text" name="utm_source" placeholder="facebook">
                                </div>

                                <div class="form-group">
                                    <label>UTM Medium</label>
                                    <input type="text" name="utm_medium" value="paid_social">
                                </div>

                                <div class="form-group full">
                                    <label>UTM Campaign (optional, auto-generated if blank)</label>
                                    <input type="text" name="utm_campaign" placeholder="april_weekend_escape">
                                </div>

                                <div class="form-group">
                                    <label>UTM Content (optional)</label>
                                    <input type="text" name="utm_content" placeholder="video_a">
                                </div>

                                <div class="form-group">
                                    <label>UTM Term (optional)</label>
                                    <input type="text" name="utm_term" placeholder="luxury_hotel_malawi">
                                </div>

                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="draft">Draft</option>
                                        <option value="active">Active</option>
                                        <option value="paused">Paused</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Budget</label>
                                    <input type="number" step="0.01" min="0" name="budget_amount" placeholder="0.00">
                                </div>

                                <div class="form-group">
                                    <label>Start Date</label>
                                    <input type="date" name="start_date">
                                </div>

                                <div class="form-group">
                                    <label>End Date</label>
                                    <input type="date" name="end_date">
                                </div>

                                <div class="form-group full">
                                    <label>Notes</label>
                                    <textarea name="notes" rows="3" placeholder="Promo details, audience notes, creative notes"></textarea>
                                </div>

                                <div class="form-group full">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-plus-circle"></i> Create Campaign
                                    </button>
                                </div>
                            </div>
                        </form>
                    </section>

                    <section class="panel">
                        <h3>Campaign Performance</h3>

                        <div class="filter-row">
                            <label for="statusFilter">Filter:</label>
                            <select id="statusFilter" onchange="window.location='campaigns.php?status=' + encodeURIComponent(this.value)">
                                <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="paused" <?php echo $filterStatus === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                <option value="ended" <?php echo $filterStatus === 'ended' ? 'selected' : ''; ?>>Ended</option>
                            </select>
                        </div>

                        <div class="table-container">
                            <table class="campaign-table">
                                <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th>Tracking Link</th>
                                    <th>Clicks</th>
                                    <th>Room Bookings</th>
                                    <th>Event Leads</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($campaigns)): ?>
                                <tr>
                                    <td colspan="7">No campaigns found.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($campaigns as $campaign): ?>
                                        <?php
                                            $trackingUrl = buildCampaignTrackingUrl($baseSiteUrl, $campaign);
                                            $clicks = (int)$campaign['total_clicks'];
                                            $bookings = (int)$campaign['total_bookings'];
                                            $eventLeads = (int)$campaign['total_event_leads'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($campaign['campaign_name']); ?></strong><br>
                                                <small><?php echo htmlspecialchars(strtoupper($campaign['platform'])); ?> · <?php echo htmlspecialchars($campaign['objective']); ?></small>
                                            </td>
                                            <td>
                                                <div class="url-cell">
                                                    <a class="tracking-url" href="<?php echo htmlspecialchars($trackingUrl); ?>" target="_blank" title="<?php echo htmlspecialchars($trackingUrl); ?>">
                                                        <?php echo htmlspecialchars($trackingUrl); ?>
                                                    </a>
                                                    <button type="button" class="btn-copy-url" data-url="<?php echo htmlspecialchars($trackingUrl); ?>" title="Copy tracking URL">
                                                        <i class="fas fa-copy"></i> Copy
                                                    </button>
                                                </div>
                                                <small><?php echo htmlspecialchars($campaign['utm_campaign']); ?></small>
                                            </td>
                                            <td><?php echo $clicks; ?></td>
                                            <td>
                                                <?php echo $bookings; ?><br>
                                                <small><?php echo htmlspecialchars($currencySymbol) . ' ' . number_format((float)$campaign['total_booking_revenue'], 0); ?></small>
                                            </td>
                                            <td>
                                                <?php echo $eventLeads; ?><br>
                                                <small><?php echo htmlspecialchars($currencySymbol) . ' ' . number_format((float)$campaign['total_event_revenue'], 0); ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo htmlspecialchars($campaign['status']); ?>">
                                                    <?php echo ucfirst(htmlspecialchars($campaign['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="actions">
                                                    <?php if ($campaign['status'] !== 'active'): ?>
                                                        <form method="POST">
                                                            <?php echo getCsrfField(); ?>
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="campaign_id" value="<?php echo (int)$campaign['id']; ?>">
                                                            <input type="hidden" name="status" value="active">
                                                            <button type="submit" class="btn-inline btn-start">Start</button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if ($campaign['status'] === 'active'): ?>
                                                        <form method="POST">
                                                            <?php echo getCsrfField(); ?>
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="campaign_id" value="<?php echo (int)$campaign['id']; ?>">
                                                            <input type="hidden" name="status" value="paused">
                                                            <button type="submit" class="btn-inline btn-pause">Pause</button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if ($campaign['status'] !== 'ended'): ?>
                                                        <form method="POST">
                                                            <?php echo getCsrfField(); ?>
                                                            <input type="hidden" name="action" value="archive_campaign">
                                                            <input type="hidden" name="campaign_id" value="<?php echo (int)$campaign['id']; ?>">
                                                            <button type="submit" class="btn-inline btn-end">End</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
<script>
document.querySelectorAll('.btn-copy-url').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = this.getAttribute('data-url');
        var self = this;
        navigator.clipboard.writeText(url).then(function() {
            self.innerHTML = '<i class="fas fa-check"></i> Copied!';
            self.classList.add('copied');
            setTimeout(function() {
                self.innerHTML = '<i class="fas fa-copy"></i> Copy';
                self.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            var el = document.createElement('textarea');
            el.value = url;
            el.style.position = 'fixed';
            el.style.opacity = '0';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            self.innerHTML = '<i class="fas fa-check"></i> Copied!';
            self.classList.add('copied');
            setTimeout(function() {
                self.innerHTML = '<i class="fas fa-copy"></i> Copy';
                self.classList.remove('copied');
            }, 2000);
        });
    });
});
</script>
</body>
</html>
