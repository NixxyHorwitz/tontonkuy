<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// Ensure this is run in a secure CLI context or as admin
if (php_sapi_name() !== 'cli' && !auth_admin()) {
    die("Akses ditolak. Silakan jalankan via CLI atau masuk sebagai admin.");
}

echo "Memulai migrasi promotor...\n";

// Helper to run query and catch duplicate column errors
function runQuery(PDO $pdo, string $sql, string $successMsg): void {
    try {
        $pdo->exec($sql);
        echo "✅ {$successMsg}\n";
    } catch (PDOException $e) {
        // If it's a duplicate column error (1060), ignore it
        if ($e->getCode() === '42S21' || str_contains($e->getMessage(), 'Duplicate column')) {
            echo "ℹ️ Kolom sudah ada (diabaikan).\n";
        } else {
            echo "❌ Gagal: " . $e->getMessage() . "\n";
        }
    }
}

// 1. Add columns to users table
runQuery($pdo, "ALTER TABLE users ADD COLUMN is_promotor TINYINT NOT NULL DEFAULT 0", "Menambahkan kolom is_promotor ke tabel users");
runQuery($pdo, "ALTER TABLE users ADD COLUMN promotor_target_deposits DECIMAL(16,2) NOT NULL DEFAULT 0.00", "Menambahkan kolom promotor_target_deposits");
runQuery($pdo, "ALTER TABLE users ADD COLUMN promotor_target_regs INT NOT NULL DEFAULT 0", "Menambahkan kolom promotor_target_regs");
runQuery($pdo, "ALTER TABLE users ADD COLUMN promotor_salary_rate DECIMAL(16,2) NOT NULL DEFAULT 0.00", "Menambahkan kolom promotor_salary_rate");

// 2. Create referral_clicks table
$sql_clicks = "
CREATE TABLE IF NOT EXISTS referral_clicks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  promotor_id INT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(300),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_promotor_date (promotor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
runQuery($pdo, $sql_clicks, "Membuat tabel referral_clicks");

// 3. Create promotor_daily_targets table
$sql_targets = "
CREATE TABLE IF NOT EXISTS promotor_daily_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  target_deposits DECIMAL(16,2) NOT NULL,
  actual_deposits DECIMAL(16,2) NOT NULL,
  target_regs INT NOT NULL,
  actual_regs INT NOT NULL,
  percentage DECIMAL(5,2) NOT NULL,
  salary_rate DECIMAL(16,2) NOT NULL,
  is_paid TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_date (user_id, date),
  INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
runQuery($pdo, $sql_targets, "Membuat tabel promotor_daily_targets");

echo "Migrasi promotor selesai!\n";
