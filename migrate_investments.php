<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

try {
    // 1. Create investment_packages table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `investment_packages` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL,
          `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
          `roi_percent` DECIMAL(5,2) NOT NULL,
          `duration_days` INT UNSIGNED NOT NULL,
          `daily_profit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table `investment_packages` verified/created.\n";

    // 2. Create user_investments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_investments` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `package_id` INT UNSIGNED NOT NULL,
          `amount` DECIMAL(15,2) NOT NULL,
          `daily_profit` DECIMAL(15,2) NOT NULL,
          `roi_percent` DECIMAL(5,2) NOT NULL,
          `duration_days` INT UNSIGNED NOT NULL,
          `days_passed` INT UNSIGNED NOT NULL DEFAULT 0,
          `last_profit_claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `status` ENUM('active', 'completed') NOT NULL DEFAULT 'active',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          INDEX idx_user_status (`user_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table `user_investments` verified/created.\n";

    // 3. Create investment_profit_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `investment_profit_logs` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `user_investment_id` INT UNSIGNED NOT NULL,
          `amount` DECIMAL(15,2) NOT NULL,
          `days_claimed` INT UNSIGNED NOT NULL,
          `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`user_investment_id`) REFERENCES `user_investments`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table `investment_profit_logs` verified/created.\n";

    // 4. Seed default packages if empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `investment_packages`")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("
            INSERT INTO `investment_packages` (`name`, `price`, `roi_percent`, `duration_days`, `daily_profit`) VALUES
            ('Investasi Pemula 🚀', 50000.00, 120.00, 10, 6000.00),
            ('Investasi Menengah 💎', 150000.00, 135.00, 15, 13500.00),
            ('Investasi Sultan 👑', 500000.00, 150.00, 30, 25000.00)
        ");
        echo "Seed default investment packages inserted.\n";
    }

    // 5. Initialize setting
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('investment_enabled','1') ON DUPLICATE KEY UPDATE `key`=`key`")
        ->execute();
    echo "Setting `investment_enabled` initialized to '1'.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
