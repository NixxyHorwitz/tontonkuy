<?php
require __DIR__ . '/bootstrap.php';
$stmt = $pdo->query("SHOW COLUMNS FROM chat_messages");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
