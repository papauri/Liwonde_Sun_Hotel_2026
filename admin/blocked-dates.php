<?php
/**
 * Blocked Dates Management Page
 * Hotel Website - Admin Panel
 *
 * Allows administrators to block/unblock room dates
 * for maintenance, events, or other reasons
 */

// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/modal.php';
require_once '../includes/alert.php';

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'block_date') {
        $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
        $block_date = $_POST['block_date'] ?? '';
        $block_type = $_POST['block_type'] ?? 'manual';
        $reason = $_POST['reason'] ?? null;
        $created_by = $user['id'] ?? null;
        
        if (empty($block_date)) {
            $message = 'Please select a date to block';
            $messageType = 'error';
        } else {
            $result = blockRoomDate($room_id, $block_date, $block_type, $reason, $created_by);
            
            if ($result) {
                $message = 'Date blocked successfully';
                $messageType = 'success';
            } else {
                $message = 'Failed to block date';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'unblock_date') {
        $id = (int)$_POST['id'] ?? 0;
        
        if ($id > 0) {
            // Get blocked date details
            $blocked_dates = getBlockedDates(null, null, null);
            $target_date = null;
            
            foreach ($blocked_dates as $bd) {
                if ($bd['id'] == $id) {
                    $target_date = $bd;
                    break;
                }
            }
            
            if ($target_date) {
                $result = unblockRoomDate($target_date['room_id'], $target_date['block_date']);
                
                if ($result) {
                    $message = 'Date unblocked successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to unblock date';
                    $messageType = 'error';
                }
            } else {
                $message = 'Blocked date not found';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'block_multiple') {
        $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
        $dates_json = $_POST['dates'] ?? '';
        $block_type = $_POST['block_type'] ?? 'manual';
        $reason = $_POST['reason'] ?? null;
        $created_by = $user['id'] ?? null;
        
        // Decode JSON dates array
        $dates = !empty($dates_json) ? json_decode($dates_json, true) : [];
        
        if (empty($dates) || !is_array($dates)) {
            $message = 'Please select at least one date to block';
            $messageType = 'error';
        } else {
            $blocked_count = blockRoomDates($room_id, $dates, $block_type, $reason, $created_by);
            
            if ($blocked_count > 0) {
                $message = "Successfully blocked {$blocked_count} date(s)";
                $messageType = 'success';
            } else {
                $message = 'Failed to block dates';
                $messageType = 'error';
            }
        }
    }
}

// Get filter parameters
$filter_room_id = isset($_GET['room_id']) ? ($_GET['room_id'] === 'all' ? null : (int)$_GET['room_id']) : null;
$filter_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$filter_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t'); // Last day of current month

// Get blocked dates
$blocked_dates = getBlockedDates($filter_room_id, $filter_start_date, $filter_end_date);

// Get all rooms for dropdown
$rooms = getCachedRooms();

// Get blocked dates for calendar display
$calendar_start = date('Y-m-d', strtotime('-3 months'));
$calendar_end = date('Y-m-d', strtotime('+6 months'));
$calendar_blocked_dates = getBlockedDates(null, $calendar_start, $calendar_end);

// Format blocked dates for calendar - simple array of dates
$blocked_dates_array = [];
foreach ($calendar_blocked_dates as $bd) {
    $blocked_dates_array[] = $bd['block_date'];
}

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blocked Dates | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        .page-actions {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .page-actions .btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .page-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .info-box {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 4px solid #2196f3;
            padding: 20px;
            margin-bottom: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(33, 150, 243, 0.1);
        }
        
        .info-box h4 {
            margin: 0 0 12px 0;
            color: #1565c0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .info-box p {
            margin: 0 0 8px 0;
            color: #424242;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .info-box ul {
            margin: 12px 0 0 0;
            padding-left: 20px;
            color: #424242;
            font-size: 14px;
        }
        
        .info-box li {
            margin-bottom: 6px;
            line-height: 1.4;
        }
        
        .card-header {
            background: linear-gradient(135deg, #f5f5f5 0%, #eeeeee 100%);
            padding: 16px 20px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header i {
            color: #1976d2;
            margin-right: 8px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .badge-maintenance {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            color: #856404;
            border: 1px solid #ffeeba;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .badge-event {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border: 1px solid #abdde5;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .badge-manual {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f1b0b7;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .badge-full {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #b8dacc;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #495057;
            font-weight: 600;
            padding: 14px 16px;
            border-bottom: 2px solid #dee2e6;
            text-align: left;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .flatpickr-day.disabled {
            background: #ffebee !important;
            color: #c62828 !important;
        }
        
        .flatpickr-day.selected {
            background: #2196f3 !important;
            border-color: #2196f3 !important;
        }
        
        .modal-title {
            font-weight: 600;
            color: #333;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
            border: none;
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            border: none;
            color: white;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <h2 class="section-title">Blocked Dates Management</h2>
        
        <!-- Info Box -->
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> How to Block Dates</h4>
            <p>Use this page to block specific dates from being booked. Blocked dates will appear unavailable to guests on the booking form.</p>
            <ul>
                <li><strong>Block Single Date:</strong> Click "Block Single Date" button, select room, date, and reason</li>
                <li><strong>Block Date Range:</strong> Click "Block Date Range" button, select room, start/end dates, and reason</li>
                <li><strong>Unblock:</strong> Click the "Unblock" button next to any blocked date in the list below</li>
            </ul>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#blockSingleDateModal">
                <i class="fas fa-calendar-day"></i> Block Single Date
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#blockDateRangeModal">
                <i class="fas fa-calendar-week"></i> Block Date Range
            </button>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filter Blocked Dates
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Room</label>
                        <select name="room_id" class="form-select">
                            <option value="all">All Rooms</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>" <?php echo $filter_room_id === $room['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Blocked Dates List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Blocked Dates 
                <span class="badge bg-secondary"><?php echo count($blocked_dates); ?> dates</span>
            </div>
            <div class="card-body">
                <?php if (empty($blocked_dates)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No blocked dates found</h5>
                        <p class="text-muted">Use the buttons above to block dates</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Room</th>
                                    <th>Type</th>
                                    <th>Reason</th>
                                    <th>Created By</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocked_dates as $bd): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-calendar-day text-muted"></i>
                                            <?php echo date('M j, Y', strtotime($bd['block_date'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($bd['room_id']): ?>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($bd['room_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-dark">All Rooms</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $bd['block_type']; ?>">
                                                <?php echo ucfirst($bd['block_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($bd['reason']): ?>
                                                <?php echo htmlspecialchars($bd['reason']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($bd['created_by_name'] ?? 'System'); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y g:i A', strtotime($bd['created_at'])); ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="unblock_date">
                                                <input type="hidden" name="id" value="<?php echo $bd['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to unblock this date?');">
                                                    <i class="fas fa-unlock"></i> Unblock
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Block Single Date Modal -->
    <div class="modal fade" id="blockSingleDateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-day"></i> Block Single Date
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="block_date">
                        
                        <div class="mb-3">
                            <label class="form-label">Room</label>
                            <select name="room_id" class="form-select" required>
                                <option value="">All Rooms</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>">
                                        <?php echo htmlspecialchars($room['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select "All Rooms" to block all rooms for this date</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date to Block</label>
                            <input type="date" name="block_date" id="singleDateInput" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Block Type</label>
                            <select name="block_type" class="form-select" required>
                                <option value="manual">Manual Block</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="event">Event</option>
                                <option value="full">Fully Booked</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason (Optional)</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for blocking this date..."></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-ban"></i> Block Date
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Block Date Range Modal -->
    <div class="modal fade" id="blockDateRangeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-week"></i> Block Date Range
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="blockRangeForm">
                        <input type="hidden" name="action" value="block_multiple">
                        <input type="hidden" name="dates" id="selectedDatesArray">
                        
                        <div class="mb-3">
                            <label class="form-label">Room</label>
                            <select name="room_id" class="form-select" required>
                                <option value="">All Rooms</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>">
                                        <?php echo htmlspecialchars($room['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select "All Rooms" to block all rooms for these dates</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date Range</label>
                            <input type="text" id="dateRangeInput" class="form-control" placeholder="Select start and end dates">
                            <small class="text-muted">All dates in the range will be blocked</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Selected Dates</label>
                            <div id="selectedDatesDisplay" class="alert alert-info" style="font-size: 13px;">
                                <span class="text-muted">No dates selected</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Block Type</label>
                            <select name="block_type" class="form-select" required>
                                <option value="manual">Manual Block</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="event">Event</option>
                                <option value="full">Fully Booked</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason (Optional)</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for blocking these dates..."></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-ban"></i> Block Dates
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    // Blocked dates array for disabling in calendar
    const blockedDates = <?php echo json_encode($blocked_dates_array); ?>;
    
    // Initialize single date picker
    flatpickr('#singleDateInput', {
        minDate: 'today',
        dateFormat: 'Y-m-d',
        disable: blockedDates
    });
    
    // Initialize date range picker
    const rangePicker = flatpickr('#dateRangeInput', {
        mode: 'range',
        minDate: 'today',
        dateFormat: 'Y-m-d',
        disable: blockedDates,
        onChange: function(selectedDates, dateStr, instance) {
            const display = document.getElementById('selectedDatesDisplay');
            const input = document.getElementById('selectedDatesArray');
            
            if (selectedDates.length === 2) {
                const startDate = new Date(selectedDates[0]);
                const endDate = new Date(selectedDates[1]);
                const dates = [];
                
                // Generate all dates in range
                const currentDate = new Date(startDate);
                while (currentDate <= endDate) {
                    dates.push(instance.formatDate(currentDate, 'Y-m-d'));
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                
                input.value = JSON.stringify(dates);
                
                if (dates.length <= 10) {
                    display.innerHTML = '<strong>' + dates.length + ' dates:</strong> ' + dates.join(', ');
                } else {
                    display.innerHTML = '<strong>' + dates.length + ' dates:</strong> ' + dates.slice(0, 5).join(', ') + ' ... ' + dates.slice(-2).join(', ');
                }
            } else {
                input.value = '';
                display.innerHTML = '<span class="text-muted">Select start and end dates</span>';
            }
        }
    });
    </script>
    <script src="js/admin-components.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>
