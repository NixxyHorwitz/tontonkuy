<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$json = file_get_contents('php://input');
$update = json_decode($json, true);

if (!$update) {
    http_response_code(200);
    exit;
}

$token = setting($pdo, 'tg_bot_token', '');
if (!$token) {
    http_response_code(200);
    exit;
}

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'] ?? '';
    $chat_id = $cb['message']['chat']['id'] ?? '';
    $msg_id = $cb['message']['message_id'] ?? '';
    $cb_id = $cb['id'] ?? '';

    // Only allow configured admin chat id
    $admin_chat_id = setting($pdo, 'tg_chat_id', '');
    if ((string)$chat_id !== (string)$admin_chat_id) {
        http_response_code(200);
        exit;
    }

    $answerText = 'Action processed';
    $newMsgText = $cb['message']['text'] ?? '';

    if (preg_match('/^depo_(approve|reject)_(\d+)$/', $data, $m)) {
        $action = $m[1];
        $id = (int)$m[2];
        
        $pdo->beginTransaction();
        $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? FOR UPDATE");
        $dep->execute([$id]);
        $dep = $dep->fetch();

        if ($dep && $dep['status'] === 'pending') {
            if ($action === 'approve') {
                $pdo->prepare("UPDATE deposits SET status='confirmed', confirmed_at=NOW() WHERE id=?")->execute([$id]);
                $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")->execute([$dep['amount'], $dep['user_id']]);
                $answerText = "Deposit Approved!";
                $newMsgText = str_replace('Status: Pending', 'Status: ✅ Approved', $newMsgText);
            } else {
                $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Rejected via Bot' WHERE id=?")->execute([$id]);
                $answerText = "Deposit Rejected!";
                $newMsgText = str_replace('Status: Pending', 'Status: ❌ Rejected', $newMsgText);
            }
        } else {
            $answerText = "Deposit is not pending or not found.";
        }
        $pdo->commit();
    } elseif (preg_match('/^wd_(approve|reject)_(\d+)$/', $data, $m)) {
        $action = $m[1];
        $id = (int)$m[2];

        $pdo->beginTransaction();
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
        $wd->execute([$id]);
        $wd = $wd->fetch();

        if ($wd && $wd['status'] === 'pending') {
            if ($action === 'approve') {
                $pdo->prepare("UPDATE withdrawals SET status='approved', processed_at=NOW() WHERE id=?")->execute([$id]);
                $answerText = "Withdraw Approved!";
                $newMsgText = str_replace('Status: Pending', 'Status: ✅ Approved', $newMsgText);
            } else {
                // refund
                $pdo->prepare("UPDATE withdrawals SET status='rejected', admin_note='Rejected via Bot' WHERE id=?")->execute([$id]);
                $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
                $answerText = "Withdraw Rejected & Refunded!";
                $newMsgText = str_replace('Status: Pending', 'Status: ❌ Rejected', $newMsgText);
            }
        } else {
            $answerText = "Withdraw is not pending or not found.";
        }
        $pdo->commit();
    }

    // Answer callback
    $url = "https://api.telegram.org/bot{$token}/answerCallbackQuery";
    $post = ['callback_query_id' => $cb_id, 'text' => $answerText];
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); curl_exec($ch); curl_close($ch);

    // Edit message to remove inline keyboard and update text
    $url = "https://api.telegram.org/bot{$token}/editMessageText";
    $post = [
        'chat_id' => $chat_id,
        'message_id' => $msg_id,
        'text' => $newMsgText
    ];
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); curl_exec($ch); curl_close($ch);
}

http_response_code(200);
