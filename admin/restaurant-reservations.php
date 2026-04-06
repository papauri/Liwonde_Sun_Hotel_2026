<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../config/email.php';
require_once '../includes/alert.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';

// Handle status updates and deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_action'])) {
    try {
        $reservation_id = $_POST['reservation_id'] ?? 0;
        $action = $_POST['reservation_action'];
        
        if ($action === 'update_status') {
            $new_status = $_POST['new_status'] ?? 'new';
            $stmt = $pdo->prepare("UPDATE restaurant_inquiries SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $reservation_id]);
            $message = 'Reservation status updated successfully!';
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM restaurant_inquiries WHERE id = ?");
            $stmt->execute([$reservation_id]);
            $message = 'Restaurant reservation deleted successfully!';
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch restaurant reservations with search/filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $sql = "SELECT * FROM restaurant_inquiries WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR reference_number LIKE ?)";
        $search_term = '%' . $search . '%';
        $params = array_fill(0, 4, $search_term);
    }
    
    if (!empty($status_filter)) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    $sql .= " ORDER BY preferred_date ASC, preferred_time ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reservations = [];
    $error = 'Error fetching reservations: ' . $e->getMessage();
}

// Get status counts for filter tabs
try {
    $status_counts = [];
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM restaurant_inquiries GROUP BY status");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_counts[$row['status']] = $row['count'];
    }
} catch (PDOException $e) {
    $status_counts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Reservations - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Filter tabs */
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .filter-tab {
            padding: 8px 16px;
            border-radius: 20px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
            color: var(--navy);
        }
        
        .filter-tab:hover {
            background: #e9ecef;
        }
        
        .filter-tab.active {
            background: var(--gold);
            color: var(--deep-navy);
            border-color: var(--gold);
        }
        
        .filter-tab .count {
            background: rgba(0,0,0,0.1);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            margin-left: 6px;
        }
        
        /* Search bar */
        .search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .search-bar input {
            flex: 1;
            min-width: 250px;
        }
        
        /* Status badges */
        .badge-new {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }
        
        .badge-confirmed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .badge-cancelled {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .badge-completed {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }
        
        /* Status select dropdown */
        .status-select {
            padding: 6px 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        
        .status-select:focus {
            outline: none;
            border-color: var(--gold);
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* Details grid */
        .reservation-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            font-size: 13px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .detail-item label {
            font-weight: 600;
            color: #666;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-item span {
            color: var(--navy);
        }
        
        @media (max-width: 768px) {
            .filter-tabs {
                width: 100%;
                overflow-x: auto;
            }
            
            .search-bar {
                flex-direction: column;
            }

            .search-bar input {
                min-width: 0;
            }

            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table-container .table {
                min-width: 940px;
            }

            .action-buttons .btn,
            .action-buttons button {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .table-container .table {
                min-width: 820px;
            }

            .table-container .table th,
            .table-container .table td {
                white-space: nowrap;
                padding: 6px 8px;
            }

            .table-container .table td:last-child,
            .table-container .table th:last-child {
                position: sticky;
                right: 0;
                background: #fff;
                z-index: 2;
                box-shadow: -8px 0 10px -8px rgba(15, 23, 42, 0.35);
            }

            .table-container .table thead th:last-child {
                background: #f8f9fa;
                z-index: 3;
            }
        }

        @media (max-width: 360px) {
            .table-container .table {
                min-width: 760px;
            }
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div class="page-header">
            <h2 class="section-title"><i class="fas fa-utensils"></i> Restaurant Reservations</h2>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="restaurant-reservations.php" class="filter-tab <?php echo empty($status_filter) ? 'active' : ''; ?>">
                All <?php if (!empty($status_counts)) { ?><span class="count"><?php echo array_sum($status_counts); ?></span><?php } ?>
            </a>
            <a href="restaurant-reservations.php?status=new" class="filter-tab <?php echo $status_filter === 'new' ? 'active' : ''; ?>">
                New <?php if (isset($status_counts['new'])) { ?><span class="count"><?php echo $status_counts['new']; ?></span><?php } ?>
            </a>
            <a href="restaurant-reservations.php?status=confirmed" class="filter-tab <?php echo $status_filter === 'confirmed' ? 'active' : ''; ?>">
                Confirmed <?php if (isset($status_counts['confirmed'])) { ?><span class="count"><?php echo $status_counts['confirmed']; ?></span><?php } ?>
            </a>
            <a href="restaurant-reservations.php?status=completed" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                Completed <?php if (isset($status_counts['completed'])) { ?><span class="count"><?php echo $status_counts['completed']; ?></span><?php } ?>
            </a>
            <a href="restaurant-reservations.php?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                Cancelled <?php if (isset($status_counts['cancelled'])) { ?><span class="count"><?php echo $status_counts['cancelled']; ?></span><?php } ?>
            </a>
        </div>

        <!-- Search Bar -->
        <form method="GET" class="search-bar">
            <input type="text" name="search" placeholder="Search by name, email, phone, or reference..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
            <?php if (!empty($status_filter)): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <?php if (!empty($search) || !empty($status_filter)): ?>
                <a href="restaurant-reservations.php" class="btn btn-danger"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>

        <!-- Reservations Table -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Date & Time</th>
                        <th>Guests</th>
                        <th>Occasion</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No reservations found</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($reservations as $reservation): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($reservation['reference_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($reservation['name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($reservation['email']); ?><br>
                            <small><?php echo htmlspecialchars($reservation['phone']); ?></small>
                        </td>
                        <td>
                            <?php echo date('M j, Y', strtotime($reservation['preferred_date'])); ?><br>
                            <small><?php echo date('H:i', strtotime($reservation['preferred_time'])); ?></small>
                        </td>
                        <td><?php echo (int)$reservation['guests']; ?> <?php echo (int)$reservation['guests'] === 1 ? 'guest' : 'guests'; ?></td>
                        <td><?php echo htmlspecialchars($reservation['occasion'] ?? '—'); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="reservation_action" value="update_status">
                                <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                <select name="new_status" class="status-select" onchange="this.form.submit();">
                                    <option value="new" <?php echo $reservation['status'] === 'new' ? 'selected' : ''; ?>>New</option>
                                    <option value="confirmed" <?php echo $reservation['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $reservation['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $reservation['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <small><?php echo date('M j, Y', strtotime($reservation['created_at'])); ?></small><br>
                            <small style="color:#999;"><?php echo date('H:i', strtotime($reservation['created_at'])); ?></small>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button type="button" class="btn btn-primary btn-sm" onclick="showReservationDetails(<?php echo htmlspecialchars(json_encode($reservation)); ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="reservation_action" value="delete">
                                    <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this reservation?');">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reservation Details Modal -->
    <div id="reservationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reservation Details</h3>
                <span class="close" onclick="closeReservationModal()">&times;</span>
            </div>
            <div class="modal-body" id="reservationModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <script>
        function showReservationDetails(reservation) {
            const modal = document.getElementById('reservationModal');
            const body = document.getElementById('reservationModalBody');
            
            const statusColors = {
                'new': '#17a2b8',
                'confirmed': '#28a745',
                'completed': '#6c757d',
                'cancelled': '#dc3545'
            };

            const detailsHTML = `
                <div class="reservation-details">
                    <div class="detail-item">
                        <label>Reference Number</label>
                        <span>${escapeHtml(reservation.reference_number)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Name</label>
                        <span>${escapeHtml(reservation.name)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Email</label>
                        <span>${escapeHtml(reservation.email)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Phone</label>
                        <span>${escapeHtml(reservation.phone)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Preferred Date</label>
                        <span>${new Date(reservation.preferred_date).toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Preferred Time</label>
                        <span>${reservation.preferred_time}</span>
                    </div>
                    <div class="detail-item">
                        <label>Number of Guests</label>
                        <span>${reservation.guests}</span>
                    </div>
                    <div class="detail-item">
                        <label>Occasion</label>
                        <span>${reservation.occasion ? escapeHtml(reservation.occasion) : '—'}</span>
                    </div>
                    <div class="detail-item">
                        <label>Status</label>
                        <span style="background: ${statusColors[reservation.status] || '#999'}; color: white; padding: 4px 12px; border-radius: 4px; display: inline-block; font-size: 12px;">
                            ${reservation.status.charAt(0).toUpperCase() + reservation.status.slice(1)}
                        </span>
                    </div>
                    <div class="detail-item">
                        <label>Created</label>
                        <span>${new Date(reservation.created_at).toLocaleString()}</span>
                    </div>
                </div>
                ${reservation.special_requests ? `
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                    <strong>Special Requests</strong>
                    <p style="margin: 8px 0 0; color: var(--navy);">${escapeHtml(reservation.special_requests)}</p>
                </div>
                ` : ''}
            `;
            
            body.innerHTML = detailsHTML;
            modal.style.display = 'block';
        }

        function closeReservationModal() {
            const modal = document.getElementById('reservationModal');
            modal.style.display = 'none';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('reservationModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>

</body>
</html>
