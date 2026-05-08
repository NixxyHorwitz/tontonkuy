<?php
declare(strict_types=1);

if (!isset($pdo)) {
    return;
}

try {
    $stmt = $pdo->prepare("UPDATE deposits SET status = 'cancelled' WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute();
} catch (\Throwable $th) {
    // Silently fail to not interrupt the request
}
