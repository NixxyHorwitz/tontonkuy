<?php require ""bootstrap.php""; try { $stmt = $pdo->query(""SHOW COLUMNS FROM users""); print_r($stmt->fetchAll(PDO::FETCH_ASSOC)); } catch (Exception $e) { echo $e->getMessage(); } ?>
