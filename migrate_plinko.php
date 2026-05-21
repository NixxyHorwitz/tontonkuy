<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

try {
    // 1. Tambahkan kolom plinko_coins ke tabel users jika belum ada
    $checkCoins = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'plinko_coins'")->fetch();
    if (!$checkCoins) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `plinko_coins` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_earned`");
        echo "✅ Kolom `plinko_coins` berhasil ditambahkan ke tabel `users`.\n";
    } else {
        echo "ℹ️ Kolom `plinko_coins` sudah ada.\n";
    }

    // 2. Tambahkan kolom last_plinko_claim ke tabel users jika belum ada
    $checkClaim = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'last_plinko_claim'")->fetch();
    if (!$checkClaim) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `last_plinko_claim` DATE DEFAULT NULL AFTER `plinko_coins`");
        echo "✅ Kolom `last_plinko_claim` berhasil ditambahkan ke tabel `users`.\n";
    } else {
        echo "ℹ️ Kolom `last_plinko_claim` sudah ada.\n";
    }

    // 3. Buat tabel plinko_history
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `plinko_history` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `coins_bet` INT UNSIGNED NOT NULL,
          `multiplier` DECIMAL(5,2) NOT NULL,
          `reward_wd` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✅ Tabel `plinko_history` berhasil dipastikan ada.\n";

    echo "\n🎉 SEMUA MIGRASI DATABASE PLINKO BERHASIL DIJALANKAN!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
