<?php
require 'bootstrap.php';
echo "--- DESCRIBE user_investments ---\n";
foreach ($pdo->query("DESCRIBE user_investments")->fetchAll() as $col) {
    echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']} - Default: {$col['Default']}\n";
}
echo "\n--- DESCRIBE investment_profit_logs ---\n";
foreach ($pdo->query("DESCRIBE investment_profit_logs")->fetchAll() as $col) {
    echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']} - Default: {$col['Default']}\n";
}
