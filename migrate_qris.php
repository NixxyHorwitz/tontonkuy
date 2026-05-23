<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// Ensure this is run in a secure CLI context or as admin
if (php_sapi_name() !== 'cli' && !auth_admin()) {
    die("Akses ditolak. Silakan jalankan via CLI atau masuk sebagai admin.");
}

echo "Memulai migrasi payment gateway logs...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_gateway_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payload TEXT NOT NULL,
            extracted_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            deposit_id INT UNSIGNED DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'unmatched',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_deposit (deposit_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "✅ Tabel payment_gateway_logs berhasil dibuat!\n";
} catch (PDOException $e) {
    echo "❌ Gagal membuat tabel: " . $e->getMessage() . "\n";
}

echo "Migrasi payment gateway selesai!\n";
