<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo->exec("
        ALTER TABLE `redeem_codes`
          DROP COLUMN `reward_amount`,
          ADD COLUMN `reward_wd` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `code`,
          ADD COLUMN `reward_dep` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `reward_wd`,
          ADD COLUMN `reward_level_id` INT UNSIGNED DEFAULT NULL AFTER `reward_dep`;
    ");
    echo "redeem_codes table altered successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
