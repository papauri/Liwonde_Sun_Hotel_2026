#!/usr/bin/env php
<?php
/**
 * Cron Job: Check and Process Tentative Bookings
 * Run: Every hour (crontab: 0 * * * *)
 *
 * This script:
 * 1. Finds tentative bookings that have expired
 * 2. Finds pending bookings that have expired
 * 3. Marks them as expired
 * 4. Sends expiration emails to guests
 * 5. Sends admin notification emails
 * 6. Restores room availability
 * 7. Logs all actions
 * 8. Finds bookings expiring in 24 hours and sends reminder emails
 */

// Production error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/cron-errors.log');

// Load bootstrap
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

// Log output to file
$log_file = __DIR__ . '/../logs/tentative-bookings-cron.log';
$log_output = "========================================\n";
$log_output .= "Tentative Booking Check Cron\n";
$log_output .= "Started: " . date('Y-m-d H:i:s') . "\n";
$log_output .= "========================================\n\n";

try {
    $processed_expired = 0;
    $processed_pending = 0;
    $processed_reminders = 0;
    $errors = 0;
    
    // Get settings
    $grace_period_hours = (int)getSetting('tentative_grace_period_hours', 0);
    $pending_duration_hours = (int)getSetting('pending_duration_hours', 24);
    
    $log_output .= "Settings loaded:\n";
    $log_output .= "  - Grace period: {$grace_period_hours} hours\n";
    $log_output .= "  - Pending duration: {$pending_duration_hours} hours\n\n";
    
    // ============================================
    // PART 1: Process Expired Tentative Bookings
    // ============================================
    
    $log_output .= "PART 1: Checking for expired tentative bookings...\n";
    
    // Build query with grace period support
    $grace_period_sql = $grace_period_hours > 0
        ? "AND tentative_expires_at < DATE_SUB(NOW(), INTERVAL {$grace_period_hours} HOUR)"
        : "";
    
    $stmt = $pdo->prepare("
        SELECT * FROM bookings
        WHERE status = 'tentative'
        AND tentative_expires_at IS NOT NULL
        AND tentative_expires_at < NOW()
        {$grace_period_sql}
        AND expired_at IS NULL
    ");
    $stmt->execute();
    $expired_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $log_output .= "Found " . count($expired_bookings) . " expired tentative bookings\n\n";
    
    if (!empty($expired_bookings)) {
        foreach ($expired_bookings as $booking) {
            $log_output .= "Processing booking: {$booking['booking_reference']}\n";
            
            $pdo->beginTransaction();
            
            try {
                // Mark as expired
                $update = $pdo->prepare("
                    UPDATE bookings
                    SET status = 'expired',
                        expired_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([$booking['id']]);
                
                // Log expiration in tentative_booking_log
                $log = $pdo->prepare("
                    INSERT INTO tentative_booking_log
                    (booking_id, action, previous_expires_at, new_expires_at, action_reason, performed_by)
                    VALUES (?, 'expired', ?, NULL, 'Automatic expiration by cron', NULL)
                ");
                $log->execute([
                    $booking['id'],
                    $booking['tentative_expires_at']
                ]);
                
                // Restore room availability if configured
                $tentative_block_availability = getSetting('tentative_block_availability', '1');
                if ($tentative_block_availability == '1') {
                    $restore = $pdo->prepare("
                        UPDATE rooms
                        SET rooms_available = rooms_available + 1
                        WHERE id = ? AND rooms_available < total_rooms
                    ");
                    $restore->execute([$booking['room_id']]);
                    $log_output .= "  - Room availability restored\n";
                }
                
                // Send expiration email to guest
                $email_result = sendTentativeBookingExpiredEmail($booking);
                if ($email_result['success']) {
                    $log_output .= "  - Expiration email sent to guest\n";
                } else {
                    $log_output .= "  - Guest email failed: {$email_result['message']}\n";
                }
                
                // Send admin notification
                $admin_email_result = sendAdminBookingExpiredNotification($booking, 'tentative');
                if ($admin_email_result['success']) {
                    $log_output .= "  - Admin notification sent\n";
                } else {
                    $log_output .= "  - Admin notification failed: {$admin_email_result['message']}\n";
                }
                
                $pdo->commit();
                $log_output .= "  - Booking marked as expired\n\n";
                $processed_expired++;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $log_output .= "  - ERROR: {$e->getMessage()}\n\n";
                error_log("Failed to expire booking {$booking['booking_reference']}: {$e->getMessage()}");
                $errors++;
            }
        }
    }
    
    // ============================================
    // PART 2: Process Expired Pending Bookings
    // ============================================
    
    $log_output .= "PART 2: Checking for expired pending bookings...\n";
    
    $stmt = $pdo->prepare("
        SELECT * FROM bookings
        WHERE status = 'pending'
        AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
        AND expired_at IS NULL
    ");
    $stmt->execute([$pending_duration_hours]);
    $expired_pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $log_output .= "Found " . count($expired_pending) . " expired pending bookings\n\n";
    
    if (!empty($expired_pending)) {
        foreach ($expired_pending as $booking) {
            $log_output .= "Processing pending booking: {$booking['booking_reference']}\n";
            
            $pdo->beginTransaction();
            
            try {
                // Get room details for email
                $room_stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
                $room_stmt->execute([$booking['room_id']]);
                $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Mark as expired
                $update = $pdo->prepare("
                    UPDATE bookings
                    SET status = 'expired',
                        expired_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([$booking['id']]);
                
                // Log expiration in tentative_booking_log
                $log = $pdo->prepare("
                    INSERT INTO tentative_booking_log
                    (booking_id, action, previous_expires_at, new_expires_at, action_reason, performed_by)
                    VALUES (?, 'expired', ?, NULL, 'Pending booking auto-expired by cron', NULL)
                ");
                $log->execute([
                    $booking['id'],
                    $booking['created_at']
                ]);
                
                // Restore room availability if configured
                $pending_block_availability = getSetting('pending_block_availability', '1');
                if ($pending_block_availability == '1') {
                    $restore = $pdo->prepare("
                        UPDATE rooms
                        SET rooms_available = rooms_available + 1
                        WHERE id = ? AND rooms_available < total_rooms
                    ");
                    $restore->execute([$booking['room_id']]);
                    $log_output .= "  - Room availability restored\n";
                }
                
                // Send expiration email to guest
                $email_result = sendPendingBookingExpiredEmail($booking, $room);
                if ($email_result['success']) {
                    $log_output .= "  - Expiration email sent to guest\n";
                } else {
                    $log_output .= "  - Guest email failed: {$email_result['message']}\n";
                }
                
                // Send admin notification
                $admin_email_result = sendAdminBookingExpiredNotification($booking, 'pending');
                if ($admin_email_result['success']) {
                    $log_output .= "  - Admin notification sent\n";
                } else {
                    $log_output .= "  - Admin notification failed: {$admin_email_result['message']}\n";
                }
                
                $pdo->commit();
                $log_output .= "  - Pending booking marked as expired\n\n";
                $processed_pending++;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $log_output .= "  - ERROR: {$e->getMessage()}\n\n";
                error_log("Failed to expire pending booking {$booking['booking_reference']}: {$e->getMessage()}");
                $errors++;
            }
        }
    }
    
    // ============================================
    // PART 3: Send Reminder Emails (24h before expiry)
    // ============================================
    
    $log_output .= "PART 3: Checking for bookings expiring in 24 hours...\n";
    
    $reminder_hours = (int)getSetting('tentative_reminder_hours', 24);
    
    $stmt = $pdo->prepare("
        SELECT * FROM bookings
        WHERE status = 'tentative'
        AND tentative_expires_at IS NOT NULL
        AND tentative_expires_at > NOW()
        AND tentative_expires_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
        AND reminder_sent = 0
    ");
    $stmt->execute([$reminder_hours]);
    $reminder_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $log_output .= "Found " . count($reminder_bookings) . " bookings needing reminders\n\n";
    
    if (!empty($reminder_bookings)) {
        foreach ($reminder_bookings as $booking) {
            $log_output .= "Processing reminder for booking: {$booking['booking_reference']}\n";
            
            $pdo->beginTransaction();
            
            try {
                // Send reminder email
                $email_result = sendTentativeBookingReminderEmail($booking);
                
                if ($email_result['success']) {
                    // Mark reminder as sent
                    $update = $pdo->prepare("
                        UPDATE bookings
                        SET reminder_sent = 1,
                            reminder_sent_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update->execute([$booking['id']]);
                    
                    // Log reminder in tentative_booking_log
                    $log = $pdo->prepare("
                        INSERT INTO tentative_booking_log
                        (booking_id, action, previous_expires_at, new_expires_at, action_reason, performed_by)
                        VALUES (?, 'reminder_sent', ?, ?, 'Reminder sent by cron', NULL)
                    ");
                    $log->execute([
                        $booking['id'],
                        $booking['tentative_expires_at'],
                        $booking['tentative_expires_at']
                    ]);
                    
                    $log_output .= "  - Reminder email sent\n";
                    $processed_reminders++;
                } else {
                    $log_output .= "  - Email failed: {$email_result['message']}\n";
                    $errors++;
                }
                
                $pdo->commit();
                $log_output .= "  - Reminder processed\n\n";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $log_output .= "  - ERROR: {$e->getMessage()}\n\n";
                error_log("Failed to send reminder for booking {$booking['booking_reference']}: {$e->getMessage()}");
                $errors++;
            }
        }
    }
    
    // ============================================
    // PART 4: Auto Check-out Overdue Guests
    // ============================================
    
    $log_output .= "\n========================================\n";
    $log_output .= "PART 4: Auto Check-out Overdue Guests\n";
    $log_output .= "========================================\n";
    
    $processed_checkouts = 0;
    
    $overdue_stmt = $pdo->prepare("
        SELECT b.*, r.room_name 
        FROM bookings b 
        LEFT JOIN rooms r ON b.room_id = r.id 
        WHERE b.status = 'checked-in' AND b.check_out_date < CURDATE()
    ");
    $overdue_stmt->execute();
    $overdue_bookings = $overdue_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $log_output .= "Found " . count($overdue_bookings) . " overdue checked-in bookings\n\n";
    
    foreach ($overdue_bookings as $booking) {
        try {
            $pdo->beginTransaction();
            
            $log_output .= "Processing: {$booking['booking_reference']} - {$booking['guest_name']}\n";
            $log_output .= "  - Room: {$booking['room_name']}, Check-out was: {$booking['check_out_date']}\n";
            
            // Update booking status to checked-out
            $update = $pdo->prepare("UPDATE bookings SET status = 'checked-out', updated_at = NOW() WHERE id = ?");
            $update->execute([$booking['id']]);
            
            // Restore room availability
            $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ?");
            $restore->execute([$booking['room_id']]);
            
            $pdo->commit();
            $log_output .= "  - Auto checked-out and room restored\n\n";
            $processed_checkouts++;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $log_output .= "  - ERROR: {$e->getMessage()}\n\n";
            $errors++;
        }
    }
    
    // ============================================
    // PART 5: Summary Statistics
    // ============================================
    
    $log_output .= "========================================\n";
    $log_output .= "SUMMARY\n";
    $log_output .= "========================================\n";
    $log_output .= "Tentative bookings expired: {$processed_expired}\n";
    $log_output .= "Pending bookings expired: {$processed_pending}\n";
    $log_output .= "Reminder emails sent: {$processed_reminders}\n";
    $log_output .= "Auto check-outs: {$processed_checkouts}\n";
    $log_output .= "Errors encountered: {$errors}\n";
    $log_output .= "Completed: " . date('Y-m-d H:i:s') . "\n";
    $log_output .= "========================================\n";
    
    // Write full log to file
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/tentative-bookings-cron.log';
    $summaryFile = $logDir . '/tentative-bookings-summary.log';
    $logEntry = "[" . date('Y-m-d H:i:s') . "] Tentative Expired: {$processed_expired}, Pending Expired: {$processed_pending}, Reminders: {$processed_reminders}, Auto Check-outs: {$processed_checkouts}, Errors: {$errors}\n";
    file_put_contents($logFile, $log_output, FILE_APPEND);
    file_put_contents($summaryFile, $logEntry, FILE_APPEND);
    
    // Log to error_log if there were errors
    if ($errors > 0) {
        error_log("Tentative booking cron completed with {$errors} errors");
    }
    
    exit(0);
    
} catch (Exception $e) {
    $error_msg = "CRITICAL ERROR in tentative booking cron: {$e->getMessage()}\n";
    error_log($error_msg);
    
    // Write error to log file
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/tentative-bookings-cron.log', "[" . date('Y-m-d H:i:s') . "] " . $error_msg, FILE_APPEND);
    
    exit(1);
}
