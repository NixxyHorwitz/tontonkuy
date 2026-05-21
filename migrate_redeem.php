<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `redeem_codes` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `code` VARCHAR(50) NOT NULL UNIQUE,
          `reward_amount` DECIMAL(15,2) NOT NULL,
          `max_claims` INT NOT NULL DEFAULT 0,
          `claims_count` INT NOT NULL DEFAULT 0,
          `expires_at` DATETIME DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "redeem_codes table created\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_redeems` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `code_id` INT UNSIGNED NOT NULL,
          `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE INDEX `uidx_user_code` (`user_id`, `code_id`),
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`code_id`) REFERENCES `redeem_codes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "user_redeems table created\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
