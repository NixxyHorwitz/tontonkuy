<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

try {
    // 1. Add target_users column to redeem_codes if not exists
    $stmt = $pdo->query("SHOW COLUMNS FROM `redeem_codes` LIKE 'target_users'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `redeem_codes` ADD COLUMN `target_users` TEXT DEFAULT NULL AFTER `expires_at`");
        echo "Column `target_users` added to `redeem_codes` table.\n";
    } else {
        echo "Column `target_users` already exists in `redeem_codes`.\n";
    }

    // 2. Create discount_vouchers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `discount_vouchers` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `code` VARCHAR(50) NOT NULL UNIQUE,
          `discounts` TEXT NOT NULL,
          `max_claims` INT NOT NULL DEFAULT 0,
          `claims_count` INT NOT NULL DEFAULT 0,
          `expires_at` DATETIME DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table `discount_vouchers` created successfully.\n";

    // 3. Create user_discount_claims table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_discount_claims` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `voucher_id` INT UNSIGNED NOT NULL,
          `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE INDEX `uidx_user_voucher` (`user_id`, `voucher_id`),
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`voucher_id`) REFERENCES `discount_vouchers`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table `user_discount_claims` created successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
