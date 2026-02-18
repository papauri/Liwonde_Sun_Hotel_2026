<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking Received - <?php echo $site_name; ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #D4AF37 0%, #c19b2e 100%);
            padding: 30px 40px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            color: #0A1929;
            font-size: 24px;
        }
        .alert-banner {
            background-color: #17a2b8;
            color: white;
            padding: 15px;
            text-align: center;
            font-size: 18px;
            font-weight: 600;
        }
        .content {
            padding: 40px;
        }
        h2 {
            color: #0A1929;
            font-size: 20px;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #D4AF37;
        }
        .booking-info {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .booking-reference {
            font-size: 24px;
            font-weight: bold;
            color: #D4AF37;
            text-align: center;
            margin: 0 0 15px 0;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 15px 0;
        }
        .info-item {
            padding: 10px;
            background-color: white;
            border-radius: 5px;
        }
        .info-label {
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .info-value {
            color: #0A1929;
            font-weight: 600;
        }
        .action-buttons {
            margin: 30px 0;
            text-align: center;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 0 10px 10px 0;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #D4AF37 0%, #c19b2e 100%);
            color: #0A1929;
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(212, 175, 55, 0.3);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .footer {
            background-color: #0A1929;
            color: #fff;
            padding: 20px;
            text-align: center;
            font-size: 12px;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px;
            }
            .header, .content {
                padding: 20px;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>🔔 New Booking Received</h1>
        </div>
        
        <div class="alert-banner">
            A new room booking has been submitted through the website
        </div>
        
        <div class="content">
            <h2>Booking Information</h2>
            
            <div class="booking-info">
                <div class="booking-reference">
                    <?php echo $booking_reference; ?>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Guest Name</div>
                        <div class="info-value"><?php echo $guest_name; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo $guest_email; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo $guest_phone; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Check-in</div>
                        <div class="info-value"><?php echo $check_in_date; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Check-out</div>
                        <div class="info-value"><?php echo $check_out_date; ?></div>
                    </div>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="info-label">Total Amount</div>
                        <div class="info-value" style="font-size: 20px; color: #D4AF37;"><?php echo $total_amount; ?></div>
                    </div>
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="<?php echo $admin_url; ?>" class="btn btn-primary" target="_blank">
                    📋 View Booking Details
                </a>
            </div>
            
            <p style="margin-top: 30px; font-size: 14px; color: #666;">
                This is an automated notification. Please review the booking details and contact the guest if needed.
            </p>
        </div>
        
        <div class="footer">
            <p style="margin: 0;">&copy; 2026 <?php echo $site_name; ?>. All rights reserved.</p>
            <p style="margin: 5px 0 0 0; font-size: 11px; opacity: 0.8;">
                Generated on <?php echo date('F j, Y, g:i A'); ?>
            </p>
        </div>
    </div>
</body>
</html>