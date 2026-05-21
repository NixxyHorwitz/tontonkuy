<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

try {
    // 1. Tambahkan kolom reward_coins ke tabel plinko_history jika belum ada
    $checkCoins = $pdo->query("SHOW COLUMNS FROM `plinko_history` LIKE 'reward_coins'")->fetch();
    if (!$checkCoins) {
        $pdo->exec("ALTER TABLE `plinko_history` ADD COLUMN `reward_coins` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `multiplier`");
        echo "✅ Kolom `reward_coins` berhasil ditambahkan ke tabel `plinko_history`.\n";
    } else {
        echo "ℹ️ Kolom `reward_coins` sudah ada di tabel `plinko_history`.\n";
    }

    // 2. Tambahkan setting default plinko_enabled, plinko_buy_rate, dan plinko_sell_rate ke tabel settings
    $settings = [
        'plinko_enabled' => '1',
        'plinko_buy_rate' => '100',
        'plinko_sell_rate' => '100'
    ];

    foreach ($settings as $key => $val) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `settings` WHERE `key` = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)")->execute([$key, $val]);
            echo "✅ Setting default `{$key}` = `{$val}` berhasil ditambahkan.\n";
        } else {
            echo "ℹ️ Setting `{$key}` sudah ada.\n";
        }
    }

    echo "\n🎉 MIGRASI PLINKO V2 BERHASIL DIJALANKAN!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
