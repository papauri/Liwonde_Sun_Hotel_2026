- Migration: Create restaurant_inquiries table
-- Date: 2026-04-02
-- Description: Table to store restaurant reservation inquiries

CREATE TABLE IF NOT EXISTS `restaurant_inquiries` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `reference_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` time NOT NULL,
  `guests` int NOT NULL DEFAULT '1',
  `occasion` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g., Anniversary, Birthday, Business Dinner',
  `special_requests` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new' COMMENT 'new, confirmed, cancelled, completed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_reference_number` (`reference_number`),
  INDEX `idx_email` (`email`),
  INDEX `idx_status` (`status`),
  INDEX `idx_preferred_date` (`preferred_date`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
