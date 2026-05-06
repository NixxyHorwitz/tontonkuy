-- ============================================================
-- TONTON - YouTube Watch & Earn Platform
-- Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS `tonton` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tonton`;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`       VARCHAR(50) NOT NULL UNIQUE,
  `email`          VARCHAR(120) NOT NULL UNIQUE,
  `whatsapp`       VARCHAR(20) NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `referral_code`  VARCHAR(12) NOT NULL UNIQUE,
  `referred_by`    VARCHAR(12) DEFAULT NULL,
  `balance`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_earned`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `membership_id`  INT UNSIGNED DEFAULT NULL,
  `membership_expires_at` DATETIME DEFAULT NULL,
  `watch_count_today` INT NOT NULL DEFAULT 0,
  `watch_reset_date`  DATE DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (`email`),
  INDEX idx_username (`username`),
  INDEX idx_referral (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MEMBERSHIPS / UPGRADE PLANS
-- ============================================================
CREATE TABLE IF NOT EXISTS `memberships` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(100) NOT NULL,
  `price`           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `watch_limit`     INT NOT NULL DEFAULT 10 COMMENT 'Jumlah video per hari',
  `duration_days`   INT NOT NULL DEFAULT 30,
  `description`     TEXT DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default memberships
INSERT INTO `memberships` (`name`, `price`, `watch_limit`, `duration_days`, `description`, `sort_order`) VALUES
('Free', 0.00, 5, 0, 'Akun gratis dengan limit tonton terbatas', 0),
('Silver', 50000.00, 20, 30, 'Tonton lebih banyak video setiap hari', 1),
('Gold', 100000.00, 50, 30, 'Limit tonton 5x lebih banyak dari Silver', 2),
('Platinum', 200000.00, 100, 30, 'Tonton tanpa batas maksimal setiap hari', 3);

-- ============================================================
-- VIDEOS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `videos` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`           VARCHAR(255) NOT NULL,
  `youtube_id`      VARCHAR(20) NOT NULL,
  `thumbnail`       VARCHAR(255) DEFAULT NULL,
  `reward_amount`   DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `watch_duration`  INT NOT NULL DEFAULT 30 COMMENT 'Durasi minimum tonton (detik)',
  `total_watches`   INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active (`is_active`),
  INDEX idx_youtube (`youtube_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- WATCH HISTORY
-- ============================================================
CREATE TABLE IF NOT EXISTS `watch_history` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `video_id`    INT UNSIGNED NOT NULL,
  `reward_given` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `watched_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (`user_id`),
  INDEX idx_video (`video_id`),
  INDEX idx_user_video_date (`user_id`, `video_id`, `watched_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DEPOSITS
-- ============================================================
CREATE TABLE IF NOT EXISTS `deposits` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `amount`          DECIMAL(15,2) NOT NULL,
  `method`          VARCHAR(50) NOT NULL DEFAULT 'transfer',
  `proof_image`     VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `admin_note`      TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at`    DATETIME DEFAULT NULL,
  INDEX idx_user (`user_id`),
  INDEX idx_status (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- WITHDRAWALS
-- ============================================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `amount`          DECIMAL(15,2) NOT NULL,
  `bank_name`       VARCHAR(100) NOT NULL,
  `account_number`  VARCHAR(50) NOT NULL,
  `account_name`    VARCHAR(100) NOT NULL,
  `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note`      TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`    DATETIME DEFAULT NULL,
  INDEX idx_user (`user_id`),
  INDEX idx_status (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- UPGRADE PURCHASES (user memberships)
-- ============================================================
CREATE TABLE IF NOT EXISTS `upgrade_orders` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `membership_id`   INT UNSIGNED NOT NULL,
  `amount`          DECIMAL(15,2) NOT NULL,
  `proof_image`     VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `admin_note`      TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at`    DATETIME DEFAULT NULL,
  INDEX idx_user (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`membership_id`) REFERENCES `memberships`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SETTINGS / CONFIG
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key`, `value`) VALUES
('site_name', 'TontonKuy'),
('site_tagline', 'Tonton Video, Dapatkan Reward!'),
('min_withdraw', '50000'),
('free_watch_limit', '5'),
('bank_name', 'BCA'),
('bank_account', '1234567890'),
('bank_holder', 'Admin TontonKuy'),
('console_password_hash', ''),
('withdraw_fee', '0'),
('referral_bonus', '1000'),
('setup_complete', '0');

-- ============================================================
-- ADMIN TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin: username=admin, password=admin123 (will be changed on first login)
INSERT INTO `admins` (`username`, `password_hash`) VALUES
('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
