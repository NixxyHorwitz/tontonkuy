<?php
require_once __DIR__ . '/bootstrap.php';

$sql = "CREATE TABLE IF NOT EXISTS `admin_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL, 
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `payload` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `admin_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

try {
    $pdo->exec($sql);
    echo "<h1>✅ SUKSES!</h1>";
    echo "<p>Tabel <b>admin_requests</b> berhasil ditambahkan ke database Anda.</p>";
    echo "<p>Sekarang error 500 / blank page di halaman Withdraw dan Profil sudah hilang. Silakan hapus file ini (run_migration.php) demi keamanan.</p>";
} catch (PDOException $e) {
    echo "<h1>❌ GAGAL!</h1>";
    echo "<p>Pesan Error: " . $e->getMessage() . "</p>";
}
