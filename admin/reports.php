<?php
/**
 * Comprehensive Hotel Reports & Analytics
 * Provides multi-tab reporting: Overview, Revenue, Bookings, Occupancy, Guests, Conference
 * With error handling, CSV export, and date filtering
 */

// Include admin initialization (PHP-only, no HTML output)
require_once __DIR__ . '/admin-init.php';

// Get date range from query parameters or default to current month
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$active_tab = $_GET['tab'] ?? 'overview';

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

// Sanitize tab
$valid_tabs = ['overview', 'revenue', 'bookings', 'occupancy', 'guests', 'conference'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'overview';
}

// Get currency symbol and VAT settings
$currency_symbol = getSetting('currency_symbol', 'MK');
$vatEnabled = in_array(getSetting('vat_enabled'), ['1', 'true', 'on']);
$vatRate = (float)getSetting('vat_rate', 0);

// Initialize all data arrays with defaults
$statusData = [];
$revenueByType = [];
$paymentMethods = [];
$outstandingPayments = [];
$dailyRevenue = [];
$monthlyRevenue = [];
$vatCollected = [];
$topClients = [];
$bookingStatusData = [];
$roomBookingStats = [];
$occupancyData = [];
$guestCountryData = [];
$repeatGuests = [];
$conferenceStats = [];
$conferenceRoomStats = [];
$recentBookings = [];
$reviewStats = [];
$gymInquiryStats = [];

$totalRevenue = 0;
$totalVatCollected = 0;
$totalTransactions = 0;
$totalOutstanding = 0;
$totalBookings = 0;
$avgStayLength = 0;
$avgRevenuePerBooking = 0;
$cancellationRate = 0;
$totalStatusCount = 0;
$error = null;

$adr = 0;
$revpar = 0;
$noShowRate = 0;
$adrData = ['total_room_revenue' => 0, 'total_nights_sold' => 0];
$noShowData = ['total_confirmed' => 0, 'no_shows' => 0];
$forecastData = ['upcoming_bookings' => 0, 'forecast_revenue' => 0, 'upcoming_nights' => 0];
$cancelData = ['total' => 0, 'cancelled' => 0];
$monthlyAdr = [];
$totalRoomNightsAvailable = 0;
$totalRoomInventory = 0;

$statusLabels = [
    'pending' => 'Pending',
    'partial' => 'Partial Payment',
    'paid' => 'Paid',
    'completed' => 'Completed',
    'refunded' => 'Refunded',
    'cancelled' => 'Cancelled'
];

try {
    // ============================================
    // OVERVIEW TAB QUERIES
    // ============================================

    // 1. Payment Status Overview (all time)
    $statusStmt = $pdo->query("
        SELECT payment_status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount
        FROM payments WHERE deleted_at IS NULL
        GROUP BY payment_status
    ");
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $statusData[$row['payment_status']] = $row;
        $totalStatusCount += $row['count'];
    }

    // 2. Revenue by Booking Type (date filtered)
    $revenueByTypeStmt = $pdo->prepare("
        SELECT booking_type, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_revenue,
               COALESCE(SUM(vat_amount), 0) as total_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY booking_type
    ");
    $revenueByTypeStmt->execute([$start_date, $end_date]);
    $revenueByType = $revenueByTypeStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($revenueByType as $revenue) {
        $totalRevenue += $revenue['total_revenue'];
        $totalVatCollected += $revenue['total_vat'];
        $totalTransactions += $revenue['count'];
    }

    // 3. Outstanding Payments
    $outstandingStmt = $pdo->query("
        SELECT p.*, 
            CASE WHEN p.booking_type = 'room' THEN b.booking_reference
                 WHEN p.booking_type = 'conference' THEN ci.inquiry_reference
            END as ref_number,
            CASE WHEN p.booking_type = 'room' THEN b.guest_name
                 WHEN p.booking_type = 'conference' THEN ci.company_name
            END as client_name,
            DATEDIFF(CURDATE(), p.payment_date) as days_overdue
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.payment_status IN ('pending', 'partial') AND p.deleted_at IS NULL
        ORDER BY p.payment_date ASC
    ");
    $outstandingPayments = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($outstandingPayments as $payment) {
        $totalOutstanding += $payment['total_amount'];
    }

    // ============================================
    // REVENUE TAB QUERIES
    // ============================================

    // 4. Payment Method Breakdown
    $paymentMethodsStmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY payment_method ORDER BY total_amount DESC
    ");
    $paymentMethodsStmt->execute([$start_date, $end_date]);
    $paymentMethods = $paymentMethodsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Daily Revenue Trend
    $dailyRevenueStmt = $pdo->prepare("
        SELECT DATE(payment_date) as date, COUNT(*) as transaction_count,
               COALESCE(SUM(total_amount), 0) as daily_revenue,
               COALESCE(SUM(vat_amount), 0) as daily_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY DATE(payment_date) ORDER BY date ASC
    ");
    $dailyRevenueStmt->execute([$start_date, $end_date]);
    $dailyRevenue = $dailyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Monthly Revenue Trend
    $monthlyRevenueStmt = $pdo->prepare("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
               DATE_FORMAT(payment_date, '%b %Y') as month_label,
               COUNT(*) as transaction_count,
               COALESCE(SUM(total_amount), 0) as monthly_revenue,
               COALESCE(SUM(vat_amount), 0) as monthly_vat
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY month, month_label ORDER BY month ASC
    ");
    $monthlyRevenueStmt->execute([$start_date, $end_date]);
    $monthlyRevenue = $monthlyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. VAT Collected
    $vatCollectedStmt = $pdo->prepare("
        SELECT DATE(payment_date) as date, COUNT(*) as transaction_count,
               COALESCE(SUM(vat_amount), 0) as vat_collected,
               COALESCE(SUM(total_amount), 0) as total_revenue
        FROM payments
        WHERE payment_status IN ('completed', 'paid') AND deleted_at IS NULL
        AND payment_date >= ? AND payment_date <= ?
        GROUP BY DATE(payment_date) ORDER BY date ASC
    ");
    $vatCollectedStmt->execute([$start_date, $end_date]);
    $vatCollected = $vatCollectedStmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Top Clients by Revenue
    $topClientsStmt = $pdo->prepare("
        SELECT
            CASE WHEN p.booking_type = 'room' THEN b.guest_name
                 WHEN p.booking_type = 'conference' THEN ci.company_name
            END as client_name,
            CASE WHEN p.booking_type = 'room' THEN b.guest_email
                 WHEN p.booking_type = 'conference' THEN ci.email
            END as client_email,
            p.booking_type, COUNT(*) as transaction_count,
            COALESCE(SUM(p.total_amount), 0) as total_spent
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.payment_status IN ('completed', 'paid') AND p.deleted_at IS NULL
        AND p.payment_date >= ? AND p.payment_date <= ?
        GROUP BY client_name, client_email, p.booking_type
        ORDER BY total_spent DESC LIMIT 10
    ");
    $topClientsStmt->execute([$start_date, $end_date]);
    $topClients = $topClientsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // BOOKINGS TAB QUERIES
    // ============================================

    // 9. Booking Status Breakdown
    $bookingStatusStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_value
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status ORDER BY count DESC
    ");
    $bookingStatusStmt->execute([$start_date, $end_date]);
    $bookingStatusData = $bookingStatusStmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. Room-level Booking Stats
    $roomBookingStmt = $pdo->prepare("
        SELECT r.name as room_name, r.price_per_night,
               COUNT(b.id) as booking_count,
               COALESCE(SUM(b.number_of_nights), 0) as total_nights,
               COALESCE(SUM(b.total_amount), 0) as total_revenue,
               COALESCE(AVG(b.number_of_nights), 0) as avg_stay
        FROM rooms r
        LEFT JOIN bookings b ON r.id = b.room_id
            AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
            AND b.status NOT IN ('cancelled')
        WHERE r.is_active = 1
        GROUP BY r.id, r.name, r.price_per_night
        ORDER BY booking_count DESC
    ");
    $roomBookingStmt->execute([$start_date, $end_date]);
    $roomBookingStats = $roomBookingStmt->fetchAll(PDO::FETCH_ASSOC);

    // 11. Booking Summary Metrics
    $bookingSummaryStmt = $pdo->prepare("
        SELECT COUNT(*) as total_bookings,
               COALESCE(AVG(number_of_nights), 0) as avg_stay_length,
               COALESCE(SUM(total_amount), 0) as total_booking_value,
               COALESCE(AVG(total_amount), 0) as avg_booking_value,
               COALESCE(SUM(number_of_guests), 0) as total_guests
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('cancelled')
    ");
    $bookingSummaryStmt->execute([$start_date, $end_date]);
    $bookingSummary = $bookingSummaryStmt->fetch(PDO::FETCH_ASSOC);
    $totalBookings = $bookingSummary['total_bookings'];
    $avgStayLength = round($bookingSummary['avg_stay_length'], 1);
    $avgRevenuePerBooking = $bookingSummary['avg_booking_value'];

    // 12. Cancellation Rate
    $cancelStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $cancelStmt->execute([$start_date, $end_date]);
    $cancelData = $cancelStmt->fetch(PDO::FETCH_ASSOC);
    $cancellationRate = $cancelData['total'] > 0 ? round(($cancelData['cancelled'] / $cancelData['total']) * 100, 1) : 0;

    // 13. Recent Bookings
    $recentBookingsStmt = $pdo->prepare("
        SELECT b.*, r.name as room_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        ORDER BY b.created_at DESC LIMIT 15
    ");
    $recentBookingsStmt->execute([$start_date, $end_date]);
    $recentBookings = $recentBookingsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // OCCUPANCY TAB QUERIES
    // ============================================

    // 14. Occupancy Data by Room Type
    $occupancyStmt = $pdo->prepare("
        SELECT r.name as room_name, r.total_rooms,
               COUNT(DISTINCT b.id) as bookings,
               COALESCE(SUM(b.number_of_nights), 0) as nights_booked,
               COALESCE(SUM(b.number_of_guests), 0) as total_guests
        FROM rooms r
        LEFT JOIN bookings b ON r.id = b.room_id
            AND b.check_in_date <= ? AND b.check_out_date >= ?
            AND b.status IN ('confirmed', 'checked-in', 'checked-out')
        WHERE r.is_active = 1
        GROUP BY r.id, r.name, r.total_rooms
        ORDER BY nights_booked DESC
    ");
    $occupancyStmt->execute([$end_date, $start_date]);
    $occupancyData = $occupancyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate days in period for occupancy rate
    $daysInPeriod = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400 + 1);

    // 15. Overall occupancy metrics
    $overallOccupancyStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT b.id) as total_bookings,
            COALESCE(SUM(b.number_of_nights), 0) as total_nights_booked,
            COALESCE(SUM(b.number_of_guests), 0) as total_guests,
            COALESCE(AVG(b.number_of_guests), 0) as avg_guests_per_booking
        FROM bookings b
        WHERE b.check_in_date <= ? AND b.check_out_date >= ?
        AND b.status IN ('confirmed', 'checked-in', 'checked-out')
    ");
    $overallOccupancyStmt->execute([$end_date, $start_date]);
    $overallOccupancy = $overallOccupancyStmt->fetch(PDO::FETCH_ASSOC);

    // Total room inventory
    $totalRoomsStmt = $pdo->query("SELECT COALESCE(SUM(total_rooms), 0) as total FROM rooms WHERE is_active = 1");
    $totalRoomInventory = $totalRoomsStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalRoomNightsAvailable = $totalRoomInventory * $daysInPeriod;
    $overallOccupancyRate = $totalRoomNightsAvailable > 0 
        ? round(($overallOccupancy['total_nights_booked'] / $totalRoomNightsAvailable) * 100, 1) 
        : 0;

    // ============================================
    // GUESTS TAB QUERIES
    // ============================================

    // 16. Guest Country Distribution
    $guestCountryStmt = $pdo->prepare("
        SELECT COALESCE(guest_country, 'Not Specified') as country,
               COUNT(*) as booking_count,
               COALESCE(SUM(total_amount), 0) as total_spent,
               COALESCE(SUM(number_of_guests), 0) as total_guests
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('cancelled')
        GROUP BY country ORDER BY booking_count DESC LIMIT 15
    ");
    $guestCountryStmt->execute([$start_date, $end_date]);
    $guestCountryData = $guestCountryStmt->fetchAll(PDO::FETCH_ASSOC);

    // 17. Repeat Guests
    $repeatGuestsStmt = $pdo->prepare("
        SELECT guest_name, guest_email, guest_country,
               COUNT(*) as booking_count,
               COALESCE(SUM(total_amount), 0) as total_spent,
               MIN(check_in_date) as first_visit,
               MAX(check_in_date) as last_visit
        FROM bookings
        WHERE status NOT IN ('cancelled')
        AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY guest_name, guest_email, guest_country
        HAVING booking_count > 1
        ORDER BY booking_count DESC LIMIT 15
    ");
    $repeatGuestsStmt->execute([$start_date, $end_date]);
    $repeatGuests = $repeatGuestsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 18. Guest Metrics
    $guestMetricsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT guest_email) as unique_guests,
               COUNT(*) as total_bookings,
               COALESCE(AVG(total_amount), 0) as avg_spend
        FROM bookings
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        AND status NOT IN ('cancelled')
    ");
    $guestMetricsStmt->execute([$start_date, $end_date]);
    $guestMetrics = $guestMetricsStmt->fetch(PDO::FETCH_ASSOC);

    // 19. Review Stats
    $reviewStatsStmt = $pdo->prepare("
        SELECT COUNT(*) as total_reviews,
               COALESCE(AVG(rating), 0) as avg_rating,
               SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive_reviews,
               SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative_reviews
        FROM reviews
        WHERE status = 'approved'
        AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $reviewStatsStmt->execute([$start_date, $end_date]);
    $reviewStats = $reviewStatsStmt->fetch(PDO::FETCH_ASSOC);

    // ============================================
    // CONFERENCE TAB QUERIES
    // ============================================

    // 20. Conference Inquiry Stats
    $conferenceStatsStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count,
               COALESCE(SUM(total_amount), 0) as total_value,
               COALESCE(SUM(amount_paid), 0) as total_paid,
               COALESCE(AVG(number_of_attendees), 0) as avg_attendees
        FROM conference_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status
    ");
    $conferenceStatsStmt->execute([$start_date, $end_date]);
    $conferenceStats = $conferenceStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 21. Conference Room Utilization
    $conferenceRoomStmt = $pdo->prepare("
        SELECT cr.name as room_name, cr.capacity,
               COUNT(ci.id) as total_events,
               COALESCE(SUM(ci.total_amount), 0) as total_revenue,
               COALESCE(AVG(ci.number_of_attendees), 0) as avg_attendees
        FROM conference_rooms cr
        LEFT JOIN conference_inquiries ci ON cr.id = ci.conference_room_id
            AND ci.created_at >= ? AND ci.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
            AND ci.status NOT IN ('cancelled')
        WHERE cr.is_active = 1
        GROUP BY cr.id, cr.name, cr.capacity
        ORDER BY total_events DESC
    ");
    $conferenceRoomStmt->execute([$start_date, $end_date]);
    $conferenceRoomStats = $conferenceRoomStmt->fetchAll(PDO::FETCH_ASSOC);

    // 22. Gym Inquiry Stats
    $gymStatsStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM gym_inquiries
        WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY status
    ");
    $gymStatsStmt->execute([$start_date, $end_date]);
    $gymInquiryStats = $gymStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // ADVANCED HOTEL KPI METRICS
    // ============================================

    // 23. ADR (Average Daily Rate) = Total Room Revenue / Number of Room Nights Sold
    $adrStmt = $pdo->prepare("
        SELECT COALESCE(SUM(b.total_amount), 0) as total_room_revenue,
               COALESCE(SUM(b.number_of_nights), 0) as total_nights_sold
        FROM bookings b
        WHERE b.status IN ('confirmed', 'checked-in', 'checked-out')
        AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $adrStmt->execute([$start_date, $end_date]);
    $adrData = $adrStmt->fetch(PDO::FETCH_ASSOC);
    $adr = $adrData['total_nights_sold'] > 0 
        ? round($adrData['total_room_revenue'] / $adrData['total_nights_sold'], 0) 
        : 0;

    // 24. RevPAR (Revenue Per Available Room) = Total Room Revenue / Total Available Room Nights
    $revpar = $totalRoomNightsAvailable > 0 
        ? round($adrData['total_room_revenue'] / $totalRoomNightsAvailable, 0) 
        : 0;

    // 25. No-Show Rate
    $noShowStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_confirmed,
            SUM(CASE WHEN status = 'no-show' THEN 1 ELSE 0 END) as no_shows
        FROM bookings
        WHERE status IN ('confirmed', 'checked-in', 'checked-out', 'no-show')
        AND created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $noShowStmt->execute([$start_date, $end_date]);
    $noShowData = $noShowStmt->fetch(PDO::FETCH_ASSOC);
    $noShowRate = $noShowData['total_confirmed'] > 0 
        ? round(($noShowData['no_shows'] / $noShowData['total_confirmed']) * 100, 1) 
        : 0;

    // 26. Revenue Forecast (from future confirmed bookings)
    $forecastStmt = $pdo->query("
        SELECT COUNT(*) as upcoming_bookings,
               COALESCE(SUM(total_amount), 0) as forecast_revenue,
               COALESCE(SUM(number_of_nights), 0) as upcoming_nights
        FROM bookings
        WHERE status IN ('confirmed', 'tentative')
        AND check_in_date > CURDATE()
    ");
    $forecastData = $forecastStmt->fetch(PDO::FETCH_ASSOC);

    // 27. Monthly ADR trend
    $monthlyAdrStmt = $pdo->prepare("
        SELECT DATE_FORMAT(b.created_at, '%Y-%m') as month,
               DATE_FORMAT(b.created_at, '%b %Y') as month_label,
               COALESCE(SUM(b.total_amount), 0) as revenue,
               COALESCE(SUM(b.number_of_nights), 0) as nights_sold,
               CASE WHEN SUM(b.number_of_nights) > 0 
                    THEN ROUND(SUM(b.total_amount) / SUM(b.number_of_nights), 0) 
                    ELSE 0 END as adr
        FROM bookings b
        WHERE b.status IN ('confirmed', 'checked-in', 'checked-out')
        AND b.created_at >= ? AND b.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY month, month_label
        ORDER BY month ASC
    ");
    $monthlyAdrStmt->execute([$start_date, $end_date]);
    $monthlyAdr = $monthlyAdrStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Unable to load report data. Please try again.";
    error_log("Reports error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .reports-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .reports-header {
            background: linear-gradient(135deg, #0A1929 0%, #1a3a5c 100%);
            color: white; padding: 30px; border-radius: 10px; margin-bottom: 24px;
        }
        .reports-header h1 { color: #D4AF37; margin-bottom: 8px; font-size: 24px; }
        .reports-header p { color: #ccc; margin: 0; }

        .report-tabs {
            display: flex; gap: 0; margin-bottom: 24px; background: white;
            border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            flex-wrap: wrap;
        }
        .report-tab {
            padding: 14px 20px; text-decoration: none; color: #555; font-weight: 600;
            font-size: 14px; border-bottom: 3px solid transparent; transition: all 0.2s;
            display: flex; align-items: center; gap: 8px; white-space: nowrap;
        }
        .report-tab:hover { background: #f8f9fa; color: #0A1929; }
        .report-tab.active { color: #0A1929; border-bottom-color: #D4AF37; background: #fdf9ef; }
        .report-tab i { font-size: 16px; }

        .date-filter {
            background: white; padding: 20px; border-radius: 10px;
            margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .date-filter form { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
        .date-filter .form-group { display: flex; flex-direction: column; }
        .date-filter label { font-weight: 600; margin-bottom: 4px; color: #333; font-size: 13px; }
        .date-filter input, .date-filter select {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;
        }
        .quick-filters { margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .quick-filters span { font-weight: 600; font-size: 13px; color: #555; margin-right: 4px; }

        .btn-filter {
            padding: 8px 18px; border: none; border-radius: 6px; cursor: pointer;
            font-weight: 600; font-size: 14px; transition: all 0.2s;
        }
        .btn-filter-primary { background: #D4AF37; color: #0A1929; }
        .btn-filter-primary:hover { background: #c49f2f; }
        .btn-filter-secondary { background: #6c757d; color: white; }
        .btn-filter-secondary:hover { background: #5a6268; }
        .btn-filter-outline {
            background: transparent; color: #555; border: 1px solid #ddd;
            padding: 6px 12px; font-size: 12px;
        }
        .btn-filter-outline:hover { background: #f8f9fa; border-color: #D4AF37; color: #0A1929; }

        .summary-cards {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .summary-card {
            background: white; padding: 20px; border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08); border-left: 4px solid #D4AF37;
        }
        .summary-card h3 { color: #888; font-size: 12px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-card .value { font-size: 24px; font-weight: 700; color: #0A1929; }
        .summary-card .subtitle { font-size: 12px; color: #999; margin-top: 4px; }
        .summary-card.green { border-left-color: #28a745; }
        .summary-card.red { border-left-color: #dc3545; }
        .summary-card.blue { border-left-color: #007bff; }
        .summary-card.purple { border-left-color: #6f42c1; }
        .summary-card.orange { border-left-color: #fd7e14; }
        .summary-card.teal { border-left-color: #20c997; }

        .report-section {
            background: white; padding: 24px; border-radius: 10px;
            margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .report-section h2 {
            color: #0A1929; border-bottom: 2px solid #D4AF37;
            padding-bottom: 10px; margin-bottom: 20px; font-size: 18px;
        }
        .report-section h2 i { margin-right: 8px; color: #D4AF37; }

        .report-table { width: 100%; border-collapse: collapse; }
        .report-table th, .report-table td {
            padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px;
        }
        .report-table th { background: #f8f9fa; font-weight: 600; color: #0A1929; font-size: 13px; }
        .report-table tr:hover { background: #fafbfc; }
        .report-table tfoot td { font-weight: 700; background: #f8f9fa; }

        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
        .status-card { padding: 16px; border-radius: 8px; text-align: center; }
        .status-card .count { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
        .status-card .label { font-size: 13px; color: #666; }
        .status-card .amount { font-size: 14px; font-weight: 600; margin-top: 4px; }
        .status-card.s-pending { background: #fff3cd; border-left: 4px solid #ffc107; }
        .status-card.s-partial { background: #e2e3e5; border-left: 4px solid #6c757d; }
        .status-card.s-paid { background: #d4edda; border-left: 4px solid #28a745; }
        .status-card.s-completed { background: #d4edda; border-left: 4px solid #28a745; }
        .status-card.s-refunded { background: #f8d7da; border-left: 4px solid #dc3545; }
        .status-card.s-cancelled { background: #e2e3e5; border-left: 4px solid #6c757d; }

        .progress-bar-custom { width: 100%; height: 28px; background: #e9ecef; border-radius: 14px; overflow: hidden; display: flex; }
        .progress-segment { height: 100%; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 11px; min-width: 0; }
        .progress-segment.p-pending { background: #ffc107; color: #333; }
        .progress-segment.p-partial { background: #6c757d; }
        .progress-segment.p-paid { background: #28a745; }
        .progress-segment.p-completed { background: #28a745; }
        .progress-segment.p-refunded { background: #dc3545; }
        .progress-segment.p-cancelled { background: #adb5bd; }

        .badge-sm { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-room { background: #e7f3ff; color: #0d6efd; }
        .badge-conference { background: #fff3e0; color: #ff9800; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-confirmed { background: #d4edda; color: #155724; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }
        .badge-checked-in { background: #cce5ff; color: #004085; }
        .badge-checked-out { background: #e2e3e5; color: #383d41; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-tentative { background: #e2d5f1; color: #6f42c1; }
        .badge-new { background: #cce5ff; color: #004085; }
        .badge-closed { background: #d4edda; color: #155724; }

        .empty-state { text-align: center; padding: 40px 20px; color: #999; }
        .empty-state i { font-size: 40px; color: #ddd; margin-bottom: 12px; }
        .empty-state p { font-size: 14px; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

        .metric-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .metric-row:last-child { border-bottom: none; }
        .metric-label { color: #666; font-size: 14px; }
        .metric-value { font-weight: 600; color: #0A1929; }

        .occupancy-bar { width: 100%; height: 20px; background: #e9ecef; border-radius: 10px; overflow: hidden; }
        .occupancy-fill { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); border-radius: 10px;
            display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; font-weight: 600; }

        .star-rating { color: #D4AF37; }

        @media (max-width: 768px) {
            .summary-cards { grid-template-columns: 1fr; }
            .two-col { grid-template-columns: 1fr; }
            .report-tabs { flex-direction: column; }
            .report-tab { border-bottom: none; border-left: 3px solid transparent; }
            .report-tab.active { border-left-color: #D4AF37; border-bottom: none; }
            .report-table { font-size: 12px; }
            .date-filter form { flex-direction: column; }
            .quick-filters { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="reports-container">
        <div class="reports-header">
            <h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1>
            <p>Comprehensive hotel performance reporting and financial analytics</p>
        </div>

        <!-- Report Tabs -->
        <div class="report-tabs">
            <?php
            $tabs = [
                'overview' => ['icon' => 'fa-tachometer-alt', 'label' => 'Overview'],
                'revenue' => ['icon' => 'fa-dollar-sign', 'label' => 'Revenue'],
                'bookings' => ['icon' => 'fa-calendar-check', 'label' => 'Bookings'],
                'occupancy' => ['icon' => 'fa-bed', 'label' => 'Occupancy'],
                'guests' => ['icon' => 'fa-users', 'label' => 'Guests'],
                'conference' => ['icon' => 'fa-briefcase', 'label' => 'Conference'],
            ];
            foreach ($tabs as $tab_key => $tab_info): ?>
                <a href="?tab=<?php echo $tab_key; ?>&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>" 
                   class="report-tab <?php echo $active_tab === $tab_key ? 'active' : ''; ?>">
                    <i class="fas <?php echo $tab_info['icon']; ?>"></i> <?php echo $tab_info['label']; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Date Filter -->
        <div class="date-filter">
            <form method="GET" action="">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <button type="submit" class="btn-filter btn-filter-primary">
                    <i class="fas fa-filter"></i> Apply
                </button>
                <button type="button" class="btn-filter btn-filter-secondary" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </form>
            <div class="quick-filters">
                <span>Quick:</span>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('today')">Today</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('week')">This Week</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('month')">This Month</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('quarter')">This Quarter</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('year')">This Year</button>
                <button type="button" class="btn-filter btn-filter-outline" onclick="setDateRange('all')">All Time</button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="report-section">
                <p style="color: #dc3545; text-align: center; padding: 20px;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!$error): ?>

        <!-- ============================================ -->
        <!-- OVERVIEW TAB -->
        <!-- ============================================ -->
        <div class="tab-content <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" id="tab-overview">
            
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card green">
                    <h3><i class="fas fa-dollar-sign"></i> Total Revenue</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></div>
                    <div class="subtitle">Completed payments in period</div>
                </div>
                <div class="summary-card red">
                    <h3><i class="fas fa-exclamation-triangle"></i> Outstanding</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalOutstanding, 2); ?></div>
                    <div class="subtitle"><?php echo count($outstandingPayments); ?> pending payment(s)</div>
                </div>
                <div class="summary-card blue">
                    <h3><i class="fas fa-exchange-alt"></i> Transactions</h3>
                    <div class="value"><?php echo number_format($totalTransactions); ?></div>
                    <div class="subtitle">Completed in period</div>
                </div>
                <div class="summary-card">
                    <h3><i class="fas fa-calendar-check"></i> Bookings</h3>
                    <div class="value"><?php echo number_format($totalBookings); ?></div>
                    <div class="subtitle">Avg stay: <?php echo $avgStayLength; ?> nights</div>
                </div>
                <?php if ($vatEnabled): ?>
                <div class="summary-card purple">
                    <h3><i class="fas fa-percent"></i> VAT Collected</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></div>
                    <div class="subtitle">At <?php echo $vatRate; ?>% rate</div>
                </div>
                <?php endif; ?>
                <div class="summary-card orange">
                    <h3><i class="fas fa-chart-line"></i> Occupancy Rate</h3>
                    <div class="value"><?php echo $overallOccupancyRate; ?>%</div>
                    <div class="subtitle"><?php echo $overallOccupancy['total_nights_booked']; ?> of <?php echo $totalRoomNightsAvailable; ?> room-nights</div>
                </div>
            </div>

            <!-- Hotel KPIs Row -->
            <div class="summary-cards" style="margin-top: 0;">
                <div class="summary-card" style="border-left: 4px solid #007bff;">
                    <h3><i class="fas fa-bed"></i> ADR</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($adr); ?></div>
                    <div class="subtitle">Average Daily Rate (<?php echo number_format($adrData['total_nights_sold']); ?> nights sold)</div>
                </div>
                <div class="summary-card" style="border-left: 4px solid #28a745;">
                    <h3><i class="fas fa-chart-bar"></i> RevPAR</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($revpar); ?></div>
                    <div class="subtitle">Revenue Per Available Room</div>
                </div>
                <div class="summary-card" style="border-left: 4px solid #dc3545;">
                    <h3><i class="fas fa-ban"></i> Cancellation Rate</h3>
                    <div class="value"><?php echo $cancellationRate; ?>%</div>
                    <div class="subtitle"><?php echo $cancelData['cancelled']; ?> of <?php echo $cancelData['total']; ?> bookings</div>
                </div>
                <div class="summary-card" style="border-left: 4px solid #795548;">
                    <h3><i class="fas fa-user-slash"></i> No-Show Rate</h3>
                    <div class="value"><?php echo $noShowRate; ?>%</div>
                    <div class="subtitle"><?php echo $noShowData['no_shows']; ?> no-show(s) in period</div>
                </div>
                <div class="summary-card" style="border-left: 4px solid #6f42c1;">
                    <h3><i class="fas fa-forward"></i> Revenue Forecast</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($forecastData['forecast_revenue']); ?></div>
                    <div class="subtitle"><?php echo $forecastData['upcoming_bookings']; ?> upcoming bookings (<?php echo $forecastData['upcoming_nights']; ?> nights)</div>
                </div>
            </div>

            <!-- Payment Status Overview -->
            <div class="report-section">
                <h2><i class="fas fa-tasks"></i> Payment Status Overview</h2>
                <?php if (empty($statusData)): ?>
                    <div class="empty-state"><i class="fas fa-inbox"></i><p>No payment data available</p></div>
                <?php else: ?>
                    <div class="status-grid">
                        <?php foreach ($statusLabels as $status => $label): ?>
                            <?php if (isset($statusData[$status])): ?>
                                <div class="status-card s-<?php echo htmlspecialchars($status); ?>">
                                    <div class="count"><?php echo number_format($statusData[$status]['count']); ?></div>
                                    <div class="label"><?php echo htmlspecialchars($label); ?></div>
                                    <div class="amount"><?php echo $currency_symbol . ' ' . number_format($statusData[$status]['total_amount'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalStatusCount > 0): ?>
                    <div style="margin-top: 20px;">
                        <h3 style="font-size: 14px; margin-bottom: 8px; color: #555;">Payment Distribution</h3>
                        <div class="progress-bar-custom">
                            <?php foreach ($statusLabels as $status => $label): ?>
                                <?php if (isset($statusData[$status])): ?>
                                    <?php $pct = ($statusData[$status]['count'] / $totalStatusCount) * 100; ?>
                                    <?php if ($pct > 0): ?>
                                    <div class="progress-segment p-<?php echo htmlspecialchars($status); ?>" 
                                         style="width: <?php echo $pct; ?>%;" 
                                         title="<?php echo htmlspecialchars($label); ?>: <?php echo number_format($pct, 1); ?>%">
                                        <?php echo $pct >= 8 ? number_format($pct, 0) . '%' : ''; ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Revenue by Type & Outstanding side by side -->
            <div class="two-col">
                <div class="report-section">
                    <h2><i class="fas fa-chart-pie"></i> Revenue by Booking Type</h2>
                    <?php if (empty($revenueByType)): ?>
                        <div class="empty-state"><i class="fas fa-chart-pie"></i><p>No revenue data for this period</p></div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead><tr><th>Type</th><th>Count</th><th>Revenue</th>
                            <?php if ($vatEnabled): ?><th>VAT</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($revenueByType as $rev): ?>
                                <tr>
                                    <td><span class="badge-sm badge-<?php echo htmlspecialchars($rev['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($rev['booking_type'])); ?></span></td>
                                    <td><?php echo number_format($rev['count']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($rev['total_revenue'], 2); ?></td>
                                    <?php if ($vatEnabled): ?><td><?php echo $currency_symbol . ' ' . number_format($rev['total_vat'], 2); ?></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="report-section">
                    <h2><i class="fas fa-exclamation-triangle"></i> Outstanding Payments</h2>
                    <?php if (empty($outstandingPayments)): ?>
                        <p style="color: #28a745; text-align: center; padding: 20px; font-weight: 600;">
                            <i class="fas fa-check-circle"></i> No outstanding payments!
                        </p>
                    <?php else: ?>
                        <table class="report-table">
                            <thead><tr><th>Reference</th><th>Client</th><th>Amount</th><th>Days</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($outstandingPayments, 0, 8) as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['payment_reference']); ?></td>
                                    <td><?php echo htmlspecialchars($p['client_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($p['total_amount'], 2); ?></td>
                                    <td><?php echo max(0, $p['days_overdue']); ?>d</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($outstandingPayments) > 8): ?>
                            <p style="text-align: center; margin-top: 10px; font-size: 13px; color: #999;">
                                + <?php echo count($outstandingPayments) - 8; ?> more outstanding payments
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- REVENUE TAB -->
        <!-- ============================================ -->
        <div class="tab-content <?php echo $active_tab === 'revenue' ? 'active' : ''; ?>" id="tab-revenue">

            <div class="summary-cards">
                <div class="summary-card green">
                    <h3>Total Revenue</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></div>
                </div>
                <div class="summary-card blue">
                    <h3>Transactions</h3>
                    <div class="value"><?php echo number_format($totalTransactions); ?></div>
                </div>
                <div class="summary-card">
                    <h3>Avg per Transaction</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . ($totalTransactions > 0 ? number_format($totalRevenue / $totalTransactions, 2) : '0.00'); ?></div>
                </div>
                <?php if ($vatEnabled): ?>
                <div class="summary-card purple">
                    <h3>VAT Collected</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></div>
                </div>
                <?php endif; ?>
                <div class="summary-card" style="border-left: 4px solid #007bff;">
                    <h3>ADR</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($adr); ?></div>
                    <div class="subtitle">Avg Daily Rate</div>
                </div>
                <div class="summary-card" style="border-left: 4px solid #28a745;">
                    <h3>RevPAR</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($revpar); ?></div>
                    <div class="subtitle">Rev Per Available Room</div>
                </div>
            </div>

            <!-- Payment Method Breakdown -->
            <div class="report-section">
                <h2><i class="fas fa-credit-card"></i> Payment Method Breakdown</h2>
                <?php if (empty($paymentMethods)): ?>
                    <div class="empty-state"><i class="fas fa-credit-card"></i><p>No payment data for this period</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Payment Method</th><th>Transactions</th><th>Total Amount</th><th>Share</th></tr></thead>
                        <tbody>
                        <?php foreach ($paymentMethods as $method): ?>
                            <tr>
                                <td><i class="fas fa-<?php echo $method['payment_method'] === 'cash' ? 'money-bill' : ($method['payment_method'] === 'bank_transfer' ? 'university' : ($method['payment_method'] === 'mobile_money' ? 'mobile-alt' : ($method['payment_method'] === 'credit_card' ? 'credit-card' : 'wallet'))); ?>"></i> <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($method['payment_method']))); ?></td>
                                <td><?php echo number_format($method['count']); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($method['total_amount'], 2); ?></td>
                                <td><?php echo $totalRevenue > 0 ? number_format(($method['total_amount'] / $totalRevenue) * 100, 1) : '0.0'; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="two-col">
                <!-- Daily Revenue -->
                <div class="report-section">
                    <h2><i class="fas fa-chart-line"></i> Daily Revenue Trend</h2>
                    <?php if (empty($dailyRevenue)): ?>
                        <div class="empty-state"><i class="fas fa-chart-line"></i><p>No daily data</p></div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead><tr><th>Date</th><th>Txns</th><th>Revenue</th></tr></thead>
                            <tbody>
                            <?php foreach ($dailyRevenue as $day): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($day['date'])); ?></td>
                                    <td><?php echo number_format($day['transaction_count']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($day['daily_revenue'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Monthly Revenue -->
                <div class="report-section">
                    <h2><i class="fas fa-calendar-alt"></i> Monthly Revenue</h2>
                    <?php if (empty($monthlyRevenue)): ?>
                        <div class="empty-state"><i class="fas fa-calendar-alt"></i><p>No monthly data</p></div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead><tr><th>Month</th><th>Txns</th><th>Revenue</th><?php if ($vatEnabled): ?><th>VAT</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($monthlyRevenue as $m): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($m['month_label']); ?></td>
                                    <td><?php echo number_format($m['transaction_count']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($m['monthly_revenue'], 2); ?></td>
                                    <?php if ($vatEnabled): ?><td><?php echo $currency_symbol . ' ' . number_format($m['monthly_vat'], 2); ?></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Clients -->
            <div class="report-section">
                <h2><i class="fas fa-trophy"></i> Top Clients by Revenue</h2>
                <?php if (empty($topClients)): ?>
                    <div class="empty-state"><i class="fas fa-trophy"></i><p>No client data for this period</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>#</th><th>Client</th><th>Email</th><th>Type</th><th>Transactions</th><th>Total Spent</th></tr></thead>
                        <tbody>
                        <?php foreach ($topClients as $i => $client): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($client['client_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($client['client_email'] ?? ''); ?></td>
                                <td><span class="badge-sm badge-<?php echo htmlspecialchars($client['booking_type']); ?>"><?php echo ucfirst(htmlspecialchars($client['booking_type'])); ?></span></td>
                                <td><?php echo number_format($client['transaction_count']); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($client['total_spent'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- VAT Report -->
            <?php if ($vatEnabled): ?>
            <div class="report-section">
                <h2><i class="fas fa-percent"></i> VAT Collection Report</h2>
                <?php if (empty($vatCollected)): ?>
                    <div class="empty-state"><i class="fas fa-percent"></i><p>No VAT data for this period</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Date</th><th>Transactions</th><th>VAT Collected</th><th>Total Revenue</th></tr></thead>
                        <tbody>
                        <?php foreach ($vatCollected as $vat): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($vat['date'])); ?></td>
                                <td><?php echo number_format($vat['transaction_count']); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($vat['vat_collected'], 2); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($vat['total_revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr>
                            <td>Total</td>
                            <td><?php echo number_format($totalTransactions); ?></td>
                            <td><?php echo $currency_symbol . ' ' . number_format($totalVatCollected, 2); ?></td>
                            <td><?php echo $currency_symbol . ' ' . number_format($totalRevenue, 2); ?></td>
                        </tr></tfoot>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Monthly ADR Trend -->
            <div class="report-section">
                <h2><i class="fas fa-chart-line"></i> Monthly ADR Trend</h2>
                <?php if (empty($monthlyAdr)): ?>
                    <div class="empty-state"><i class="fas fa-chart-line"></i><p>No ADR data for this period</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Month</th><th>Revenue</th><th>Nights Sold</th><th>ADR</th></tr></thead>
                        <tbody>
                        <?php foreach ($monthlyAdr as $ma): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ma['month_label']); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($ma['revenue'], 2); ?></td>
                                <td><?php echo number_format($ma['nights_sold']); ?></td>
                                <td><strong><?php echo $currency_symbol . ' ' . number_format($ma['adr']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- BOOKINGS TAB -->
        <!-- ============================================ -->
        <div class="tab-content <?php echo $active_tab === 'bookings' ? 'active' : ''; ?>" id="tab-bookings">

            <div class="summary-cards">
                <div class="summary-card blue">
                    <h3>Total Bookings</h3>
                    <div class="value"><?php echo number_format($totalBookings); ?></div>
                    <div class="subtitle">Non-cancelled bookings</div>
                </div>
                <div class="summary-card">
                    <h3>Avg Stay Length</h3>
                    <div class="value"><?php echo $avgStayLength; ?> nights</div>
                </div>
                <div class="summary-card green">
                    <h3>Avg Booking Value</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($avgRevenuePerBooking, 2); ?></div>
                </div>
                <div class="summary-card red">
                    <h3>Cancellation Rate</h3>
                    <div class="value"><?php echo $cancellationRate; ?>%</div>
                    <div class="subtitle"><?php echo $cancelData['cancelled'] ?? 0; ?> of <?php echo $cancelData['total'] ?? 0; ?> bookings</div>
                </div>
                <div class="summary-card teal">
                    <h3>Total Guests</h3>
                    <div class="value"><?php echo number_format($bookingSummary['total_guests'] ?? 0); ?></div>
                </div>
            </div>

            <!-- Booking Status Breakdown -->
            <div class="report-section">
                <h2><i class="fas fa-chart-bar"></i> Booking Status Breakdown</h2>
                <?php if (empty($bookingStatusData)): ?>
                    <div class="empty-state"><i class="fas fa-calendar"></i><p>No booking data for this period</p></div>
                <?php else: ?>
                    <div class="status-grid">
                        <?php 
                        $bookingStatusColors = [
                            'pending' => 's-pending', 'tentative' => 's-partial',
                            'confirmed' => 's-completed', 'checked-in' => 's-paid',
                            'checked-out' => 's-cancelled', 'cancelled' => 's-refunded'
                        ];
                        foreach ($bookingStatusData as $bs): ?>
                            <div class="status-card <?php echo $bookingStatusColors[$bs['status']] ?? 's-pending'; ?>">
                                <div class="count"><?php echo number_format($bs['count']); ?></div>
                                <div class="label"><?php echo ucfirst(str_replace('-', ' ', htmlspecialchars($bs['status']))); ?></div>
                                <div class="amount"><?php echo $currency_symbol . ' ' . number_format($bs['total_value'], 2); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Room-level Stats -->
            <div class="report-section">
                <h2><i class="fas fa-bed"></i> Bookings by Room Type</h2>
                <?php if (empty($roomBookingStats)): ?>
                    <div class="empty-state"><i class="fas fa-bed"></i><p>No room data available</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Room Type</th><th>Price/Night</th><th>Bookings</th><th>Total Nights</th><th>Avg Stay</th><th>Revenue</th></tr></thead>
                        <tbody>
                        <?php $totalRoomRevenue = 0; foreach ($roomBookingStats as $room): $totalRoomRevenue += $room['total_revenue']; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($room['room_name']); ?></strong></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($room['price_per_night'], 2); ?></td>
                                <td><?php echo number_format($room['booking_count']); ?></td>
                                <td><?php echo number_format($room['total_nights']); ?></td>
                                <td><?php echo number_format($room['avg_stay'], 1); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($room['total_revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr>
                            <td colspan="5"><strong>Total</strong></td>
                            <td><strong><?php echo $currency_symbol . ' ' . number_format($totalRoomRevenue, 2); ?></strong></td>
                        </tr></tfoot>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Recent Bookings -->
            <div class="report-section">
                <h2><i class="fas fa-clock"></i> Recent Bookings</h2>
                <?php if (empty($recentBookings)): ?>
                    <div class="empty-state"><i class="fas fa-calendar"></i><p>No recent bookings</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Reference</th><th>Guest</th><th>Room</th><th>Check-in</th><th>Nights</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentBookings as $bk): ?>
                            <tr>
                                <td><a href="booking-details.php?id=<?php echo (int)$bk['id']; ?>"><?php echo htmlspecialchars($bk['booking_reference']); ?></a></td>
                                <td><?php echo htmlspecialchars($bk['guest_name']); ?></td>
                                <td><?php echo htmlspecialchars($bk['room_name'] ?? 'N/A'); ?></td>
                                <td><?php echo date('M j', strtotime($bk['check_in_date'])); ?></td>
                                <td><?php echo $bk['number_of_nights']; ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($bk['total_amount'], 2); ?></td>
                                <td><span class="badge-sm badge-<?php echo htmlspecialchars($bk['status']); ?>"><?php echo ucfirst(str_replace('-', ' ', htmlspecialchars($bk['status']))); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- OCCUPANCY TAB -->
        <!-- ============================================ -->
        <div class="tab-content <?php echo $active_tab === 'occupancy' ? 'active' : ''; ?>" id="tab-occupancy">

            <div class="summary-cards">
                <div class="summary-card green">
                    <h3>Overall Occupancy</h3>
                    <div class="value"><?php echo $overallOccupancyRate; ?>%</div>
                    <div class="subtitle"><?php echo round($daysInPeriod); ?>-day period</div>
                </div>
                <div class="summary-card blue">
                    <h3>Room-Nights Booked</h3>
                    <div class="value"><?php echo number_format($overallOccupancy['total_nights_booked']); ?></div>
                    <div class="subtitle">of <?php echo number_format($totalRoomNightsAvailable); ?> available</div>
                </div>
                <div class="summary-card">
                    <h3>Total Room Inventory</h3>
                    <div class="value"><?php echo number_format($totalRoomInventory); ?> rooms</div>
                </div>
                <div class="summary-card teal">
                    <h3>Total Guests Served</h3>
                    <div class="value"><?php echo number_format($overallOccupancy['total_guests']); ?></div>
                    <div class="subtitle">Avg <?php echo number_format($overallOccupancy['avg_guests_per_booking'], 1); ?> per booking</div>
                </div>
            </div>

            <!-- Overall Occupancy Bar -->
            <div class="report-section">
                <h2><i class="fas fa-hotel"></i> Overall Occupancy Rate</h2>
                <div style="margin-bottom: 8px; font-size: 13px; color: #666;">
                    <?php echo $overallOccupancy['total_nights_booked']; ?> room-nights booked out of <?php echo $totalRoomNightsAvailable; ?> available
                </div>
                <div class="occupancy-bar">
                    <div class="occupancy-fill" style="width: <?php echo min(100, $overallOccupancyRate); ?>%;">
                        <?php echo $overallOccupancyRate >= 10 ? $overallOccupancyRate . '%' : ''; ?>
                    </div>
                </div>
            </div>

            <!-- Occupancy by Room Type -->
            <div class="report-section">
                <h2><i class="fas fa-bed"></i> Occupancy by Room Type</h2>
                <?php if (empty($occupancyData)): ?>
                    <div class="empty-state"><i class="fas fa-bed"></i><p>No occupancy data</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Room Type</th><th>Total Rooms</th><th>Bookings</th><th>Nights Booked</th><th>Guests</th><th>Occupancy Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($occupancyData as $occ): 
                            $roomAvail = $occ['total_rooms'] * $daysInPeriod;
                            $roomOccRate = $roomAvail > 0 ? round(($occ['nights_booked'] / $roomAvail) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($occ['room_name']); ?></strong></td>
                                <td><?php echo $occ['total_rooms']; ?></td>
                                <td><?php echo number_format($occ['bookings']); ?></td>
                                <td><?php echo number_format($occ['nights_booked']); ?></td>
                                <td><?php echo number_format($occ['total_guests']); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div class="occupancy-bar" style="width: 120px;">
                                            <div class="occupancy-fill" style="width: <?php echo min(100, $roomOccRate); ?>%;"></div>
                                        </div>
                                        <span style="font-weight: 600;"><?php echo $roomOccRate; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- GUESTS TAB -->
        <!-- ============================================ -->
        <div class="tab-content <?php echo $active_tab === 'guests' ? 'active' : ''; ?>" id="tab-guests">

            <div class="summary-cards">
                <div class="summary-card blue">
                    <h3>Unique Guests</h3>
                    <div class="value"><?php echo number_format($guestMetrics['unique_guests'] ?? 0); ?></div>
                </div>
                <div class="summary-card green">
                    <h3>Avg Spend per Guest</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($guestMetrics['avg_spend'] ?? 0, 2); ?></div>
                </div>
                <div class="summary-card purple">
                    <h3>Repeat Guests</h3>
                    <div class="value"><?php echo count($repeatGuests); ?></div>
                </div>
                <div class="summary-card orange">
                    <h3>Avg Rating</h3>
                    <div class="value">
                        <?php echo number_format($reviewStats['avg_rating'] ?? 0, 1); ?> 
                        <span class="star-rating"><i class="fas fa-star"></i></span>
                    </div>
                    <div class="subtitle"><?php echo ($reviewStats['total_reviews'] ?? 0); ?> review(s)</div>
                </div>
            </div>

            <div class="two-col">
                <!-- Country Distribution -->
                <div class="report-section">
                    <h2><i class="fas fa-globe-africa"></i> Guest Origin Countries</h2>
                    <?php if (empty($guestCountryData)): ?>
                        <div class="empty-state"><i class="fas fa-globe"></i><p>No guest data for this period</p></div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead><tr><th>Country</th><th>Bookings</th><th>Guests</th><th>Revenue</th></tr></thead>
                            <tbody>
                            <?php foreach ($guestCountryData as $gc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($gc['country']); ?></td>
                                    <td><?php echo number_format($gc['booking_count']); ?></td>
                                    <td><?php echo number_format($gc['total_guests']); ?></td>
                                    <td><?php echo $currency_symbol . ' ' . number_format($gc['total_spent'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Review Summary -->
                <div class="report-section">
                    <h2><i class="fas fa-star"></i> Guest Reviews Summary</h2>
                    <?php if (($reviewStats['total_reviews'] ?? 0) == 0): ?>
                        <div class="empty-state"><i class="fas fa-star"></i><p>No approved reviews for this period</p></div>
                    <?php else: ?>
                        <div class="metric-row">
                            <span class="metric-label">Total Reviews</span>
                            <span class="metric-value"><?php echo $reviewStats['total_reviews']; ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Average Rating</span>
                            <span class="metric-value">
                                <?php echo number_format($reviewStats['avg_rating'], 1); ?>/5
                                <span class="star-rating">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <?php if ($s <= floor($reviewStats['avg_rating'])): ?>
                                            <i class="fas fa-star" style="color: #D4AF37;"></i>
                                        <?php elseif ($s == ceil($reviewStats['avg_rating']) && $reviewStats['avg_rating'] != floor($reviewStats['avg_rating'])): ?>
                                            <i class="fas fa-star-half-alt" style="color: #D4AF37;"></i>
                                        <?php else: ?>
                                            <i class="far fa-star" style="color: #ddd;"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </span>
                            </span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Positive (4-5 stars)</span>
                            <span class="metric-value" style="color: #28a745;"><?php echo $reviewStats['positive_reviews']; ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Negative (1-2 stars)</span>
                            <span class="metric-value" style="color: #dc3545;"><?php echo $reviewStats['negative_reviews']; ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Satisfaction Rate</span>
                            <span class="metric-value">
                                <?php echo $reviewStats['total_reviews'] > 0 ? number_format(($reviewStats['positive_reviews'] / $reviewStats['total_reviews']) * 100, 0) : 0; ?>%
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Repeat Guests -->
            <div class="report-section">
                <h2><i class="fas fa-redo"></i> Repeat Guests</h2>
                <?php if (empty($repeatGuests)): ?>
                    <div class="empty-state"><i class="fas fa-users"></i><p>No repeat guests found in this period</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Guest</th><th>Email</th><th>Country</th><th>Bookings</th><th>Total Spent</th><th>First Visit</th><th>Last Visit</th></tr></thead>
                        <tbody>
                        <?php foreach ($repeatGuests as $rg): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($rg['guest_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($rg['guest_email']); ?></td>
                                <td><?php echo htmlspecialchars($rg['guest_country'] ?? 'N/A'); ?></td>
                                <td><strong><?php echo $rg['booking_count']; ?></strong></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($rg['total_spent'], 2); ?></td>
                                <td><?php echo date('M j, Y', strtotime($rg['first_visit'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($rg['last_visit'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- CONFERENCE TAB -->
        <!-- ============================================ -->
        <div class="tab-content <?php echo $active_tab === 'conference' ? 'active' : ''; ?>" id="tab-conference">

            <?php
            $totalConfEvents = 0; $totalConfRevenue = 0; $totalConfPaid = 0;
            foreach ($conferenceStats as $cs) {
                $totalConfEvents += $cs['count'];
                $totalConfRevenue += $cs['total_value'];
                $totalConfPaid += $cs['total_paid'];
            }
            $totalGymInquiries = 0;
            foreach ($gymInquiryStats as $gi) { $totalGymInquiries += $gi['count']; }
            ?>

            <div class="summary-cards">
                <div class="summary-card blue">
                    <h3>Conference Inquiries</h3>
                    <div class="value"><?php echo number_format($totalConfEvents); ?></div>
                </div>
                <div class="summary-card green">
                    <h3>Conference Revenue</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalConfRevenue, 2); ?></div>
                </div>
                <div class="summary-card">
                    <h3>Amount Collected</h3>
                    <div class="value"><?php echo $currency_symbol . ' ' . number_format($totalConfPaid, 2); ?></div>
                    <div class="subtitle"><?php echo $totalConfRevenue > 0 ? number_format(($totalConfPaid / $totalConfRevenue) * 100, 0) : 0; ?>% collected</div>
                </div>
                <div class="summary-card purple">
                    <h3>Gym Inquiries</h3>
                    <div class="value"><?php echo number_format($totalGymInquiries); ?></div>
                </div>
            </div>

            <div class="two-col">
                <!-- Conference Status -->
                <div class="report-section">
                    <h2><i class="fas fa-briefcase"></i> Conference Inquiry Status</h2>
                    <?php if (empty($conferenceStats)): ?>
                        <div class="empty-state"><i class="fas fa-briefcase"></i><p>No conference inquiries for this period</p></div>
                    <?php else: ?>
                        <div class="status-grid">
                            <?php foreach ($conferenceStats as $cs): ?>
                                <div class="status-card s-<?php echo htmlspecialchars($cs['status']); ?>">
                                    <div class="count"><?php echo number_format($cs['count']); ?></div>
                                    <div class="label"><?php echo ucfirst(htmlspecialchars($cs['status'])); ?></div>
                                    <div class="amount"><?php echo $currency_symbol . ' ' . number_format($cs['total_value'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Gym Inquiry Status -->
                <div class="report-section">
                    <h2><i class="fas fa-dumbbell"></i> Gym Inquiry Status</h2>
                    <?php if (empty($gymInquiryStats)): ?>
                        <div class="empty-state"><i class="fas fa-dumbbell"></i><p>No gym inquiries for this period</p></div>
                    <?php else: ?>
                        <div class="status-grid">
                            <?php foreach ($gymInquiryStats as $gi): ?>
                                <div class="status-card s-<?php echo htmlspecialchars($gi['status'] === 'new' ? 'pending' : ($gi['status'] === 'closed' ? 'completed' : $gi['status'])); ?>">
                                    <div class="count"><?php echo number_format($gi['count']); ?></div>
                                    <div class="label"><?php echo ucfirst(htmlspecialchars($gi['status'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Conference Room Utilization -->
            <div class="report-section">
                <h2><i class="fas fa-building"></i> Conference Room Utilization</h2>
                <?php if (empty($conferenceRoomStats)): ?>
                    <div class="empty-state"><i class="fas fa-building"></i><p>No conference room data</p></div>
                <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Room</th><th>Capacity</th><th>Events</th><th>Avg Attendees</th><th>Revenue</th><th>Utilization</th></tr></thead>
                        <tbody>
                        <?php foreach ($conferenceRoomStats as $cr): 
                            $utilizationPct = $cr['capacity'] > 0 && $cr['avg_attendees'] > 0 
                                ? round(($cr['avg_attendees'] / $cr['capacity']) * 100, 0) : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cr['room_name']); ?></strong></td>
                                <td><?php echo number_format($cr['capacity']); ?></td>
                                <td><?php echo number_format($cr['total_events']); ?></td>
                                <td><?php echo number_format($cr['avg_attendees'], 0); ?></td>
                                <td><?php echo $currency_symbol . ' ' . number_format($cr['total_revenue'], 2); ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div class="occupancy-bar" style="width: 100px;">
                                            <div class="occupancy-fill" style="width: <?php echo min(100, $utilizationPct); ?>%;"></div>
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px;"><?php echo $utilizationPct; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        function exportToCSV() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const tab = '<?php echo htmlspecialchars($active_tab); ?>';
            const url = '../api/reports-export.php?start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate) + '&report_type=' + encodeURIComponent(tab);
            window.open(url, '_blank');
        }

        function setDateRange(range) {
            const today = new Date();
            let startDate, endDate;

            switch(range) {
                case 'today':
                    startDate = endDate = today.toISOString().split('T')[0];
                    break;
                case 'week':
                    const weekStart = new Date(today);
                    weekStart.setDate(today.getDate() - today.getDay());
                    startDate = weekStart.toISOString().split('T')[0];
                    endDate = today.toISOString().split('T')[0];
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
                case 'quarter':
                    const qStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
                    const qEnd = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 + 3, 0);
                    startDate = qStart.toISOString().split('T')[0];
                    endDate = qEnd.toISOString().split('T')[0];
                    break;
                case 'year':
                    startDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                    endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
                    break;
                case 'all':
                    startDate = new Date(today.getFullYear() - 5, 0, 1).toISOString().split('T')[0];
                    endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
                    break;
            }

            document.getElementById('start_date').value = startDate;
            document.getElementById('end_date').value = endDate;
            const form = document.querySelector('.date-filter form');
            form.submit();
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
