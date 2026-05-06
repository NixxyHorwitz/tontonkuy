-- Migration: Dual balance, QRIS, Maintenance, Check-in, Referral Commission

USE tonton;

-- ============================================================
-- Tabel komisi referral
-- ============================================================
CREATE TABLE IF NOT EXISTS `referral_commissions` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL COMMENT 'Referrer penerima komisi',
  `from_user_id` INT UNSIGNED NOT NULL COMMENT 'Downline yang deposit',
  `deposit_id`   INT UNSIGNED NOT NULL,
  `amount`       DECIMAL(15,2) NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`deposit_id`) REFERENCES `deposits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Settings baru
-- ============================================================
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('maintenance_mode', '0'),
('maintenance_message', 'Sistem sedang dalam perbaikan. Harap tunggu sebentar.'),
('wd_lock_start', ''),
('wd_lock_end', ''),
('min_deposit', '10000'),
('wd_min_level', '0'),
('qris_raw', ''),
('referral_commission_percent', '5'),
('checkin_reward', '500'),
('wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');
