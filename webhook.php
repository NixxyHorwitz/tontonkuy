<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$json   = file_get_contents('php://input');
$update = json_decode($json, true);

if (!$update) { http_response_code(200); exit; }

$token = setting($pdo, 'tg_bot_token', '');
if (!$token) { http_response_code(200); exit; }

$admin_chat_id = setting($pdo, 'tg_chat_id', '');

// ── Helpers ─────────────────────────────────────────────────────────────────

function tg_api(string $token, string $method, array $post): ?array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
    ]);
    $res = curl_exec($ch);
    return json_decode($res ?: '{}', true);
}

function tg_api_json(string $token, string $method, array $body): void {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    curl_exec($ch);
}

function answer_cb(string $token, string $cb_id, string $text): void {
    tg_api($token, 'answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => $text, 'show_alert' => false]);
}

function edit_msg(string $token, $chat_id, $msg_id, string $text, ?array $kb = null): void {
    $body = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($kb !== null) $body['reply_markup'] = ['inline_keyboard' => $kb];
    tg_api_json($token, 'editMessageText', $body);
}

function send_msg(string $token, $chat_id, string $text, ?array $kb = null, $thread_id = null): ?int {
    $body = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($kb !== null) $body['reply_markup'] = ['inline_keyboard' => $kb];
    if ($thread_id) $body['message_thread_id'] = (int)$thread_id;
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $res = curl_exec($ch);
    $data = json_decode($res ?: '{}', true);
    return $data['result']['message_id'] ?? null;
}

/** Save pending reject state to settings table */
function set_tg_state(PDO $pdo, $chat_id, string $state): void {
    $key = 'tg_state_' . $chat_id;
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
        ->execute([$key, $state, $state]);
}

/** Get and clear pending state */
function get_tg_state(PDO $pdo, $chat_id): string {
    $key = 'tg_state_' . $chat_id;
    $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
    $s->execute([$key]);
    return (string)($s->fetchColumn() ?: '');
}

function clear_tg_state(PDO $pdo, $chat_id): void {
    $pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(['tg_state_' . $chat_id]);
}

/** Do the actual deposit reject */
function do_depo_reject(PDO $pdo, int $id, string $reason): string {
    $pdo->beginTransaction();
    $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? FOR UPDATE");
    $dep->execute([$id]);
    $dep = $dep->fetch();
    if (!$dep || $dep['status'] !== 'pending') {
        $pdo->rollBack();
        return 'Deposit tidak ditemukan atau bukan pending.';
    }
    $note = $reason ?: 'Rejected via Bot';
    $pdo->prepare("UPDATE deposits SET status='rejected', admin_note=? WHERE id=?")->execute([$note, $id]);
    $pdo->commit();
    return 'ok';
}

/** Do the actual withdraw reject */
function do_wd_reject(PDO $pdo, int $id, string $reason): string {
    $pdo->beginTransaction();
    $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
    $wd->execute([$id]);
    $wd = $wd->fetch();
    if (!$wd || $wd['status'] !== 'pending') {
        $pdo->rollBack();
        return 'WD tidak ditemukan atau bukan pending.';
    }
    $note = $reason ?: 'Rejected via Bot';
    $pdo->prepare("UPDATE withdrawals SET status='rejected', admin_note=? WHERE id=?")->execute([$note, $id]);
    $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
    $pdo->commit();
    return 'ok';
}

/** Do the actual withdraw hold (no refund) */
function do_wd_hold(PDO $pdo, int $id, string $reason): string {
    $pdo->beginTransaction();
    $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
    $wd->execute([$id]);
    $wd = $wd->fetch();
    if (!$wd || $wd['status'] !== 'pending') {
        $pdo->rollBack();
        return 'WD tidak ditemukan atau bukan pending.';
    }
    $note = $reason ?: 'Hold via Bot (Selesai non-refund)';
    $pdo->prepare("UPDATE withdrawals SET status='hold', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$note, $id]);
    $pdo->commit();
    return 'ok';
}

// ── Callback Query Handler ───────────────────────────────────────────────────

if (isset($update['callback_query'])) {
    $cb      = $update['callback_query'];
    $data    = $cb['data'] ?? '';
    $chat_id = $cb['message']['chat']['id'] ?? '';
    $msg_id  = $cb['message']['message_id'] ?? '';
    $cb_id   = $cb['id'] ?? '';
    $orig    = $cb['message']['text'] ?? '';

    if ((string)$chat_id !== (string)$admin_chat_id) {
        http_response_code(200); exit;
    }

    // ── REFRESH ──────────────────────────────────────────────────────────────
    if (preg_match('/^refresh_(depo|wd)_(\d+)$/', $data, $m)) {
        $type = $m[1];
        $id   = (int)$m[2];
        answer_cb($token, $cb_id, '🔄 Mengecek status...');

        if ($type === 'depo') {
            $row = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.id=?");
            $row->execute([$id]); $row = $row->fetch();
            if (!$row) { send_msg($token, $chat_id, "Deposit #{$id} tidak ditemukan."); }
            elseif ($row['status'] !== 'pending') {
                $icon = $row['status'] === 'confirmed' ? '✅' : '❌';
                edit_msg($token, $chat_id, $msg_id,
                    str_replace('Status: Pending', "Status: {$icon} " . ucfirst($row['status']), $orig));
                send_msg($token, $chat_id, "ℹ️ Deposit #{$id} sudah di-handle: <b>{$row['status']}</b>");
            } else {
                send_msg($token, $chat_id, "⏳ Deposit #{$id} masih <b>pending</b>, belum diproses.");
            }
        } else {
            $row = $pdo->prepare("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE w.id=?");
            $row->execute([$id]); $row = $row->fetch();
            if (!$row) { send_msg($token, $chat_id, "WD #{$id} tidak ditemukan."); }
            elseif ($row['status'] !== 'pending') {
                $icon = $row['status'] === 'approved' ? '✅' : '❌';
                edit_msg($token, $chat_id, $msg_id,
                    str_replace('Status: Pending', "Status: {$icon} " . ucfirst($row['status']), $orig));
                send_msg($token, $chat_id, "ℹ️ WD #{$id} sudah di-handle: <b>{$row['status']}</b>");
            } else {
                send_msg($token, $chat_id, "⏳ WD #{$id} masih <b>pending</b>, belum diproses.");
            }
        }
        http_response_code(200); exit;
    }

    // ── APPROVE ──────────────────────────────────────────────────────────────
    if (preg_match('/^depo_approve_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        $pdo->beginTransaction();
        $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? FOR UPDATE");
        $dep->execute([$id]); $dep = $dep->fetch();
        if ($dep && $dep['status'] === 'pending') {
            $pdo->prepare("UPDATE deposits SET status='confirmed', confirmed_at=NOW() WHERE id=?")->execute([$id]);
            $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")->execute([$dep['amount'], $dep['user_id']]);
            $pdo->commit();
            answer_cb($token, $cb_id, '✅ Deposit Approved!');
            edit_msg($token, $chat_id, $msg_id, str_replace('Status: Pending', 'Status: ✅ Approved', $orig));
        } else {
            $pdo->rollBack();
            answer_cb($token, $cb_id, '⚠️ Sudah diproses atau tidak ditemukan.');
        }
        http_response_code(200); exit;
    }

    if (preg_match('/^wd_approve_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        $pdo->beginTransaction();
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd && $wd['status'] === 'pending') {
            $pdo->prepare("UPDATE withdrawals SET status='approved', processed_at=NOW() WHERE id=?")->execute([$id]);
            $pdo->commit();
            answer_cb($token, $cb_id, '✅ Withdraw Approved!');
            edit_msg($token, $chat_id, $msg_id, str_replace('Status: Pending', 'Status: ✅ Approved', $orig));
        } else {
            $pdo->rollBack();
            answer_cb($token, $cb_id, '⚠️ Sudah diproses atau tidak ditemukan.');
        }
        http_response_code(200); exit;
    }

    // ── REJECT / HOLD (ask for reason) ─────────────────────────────────────────
    if (preg_match('/^(depo|wd)_(reject|hold)_(\d+)$/', $data, $m)) {
        $type   = $m[1];
        $action = $m[2];
        $id     = (int)$m[3];
        $act_id = "{$type}_{$action}";
        $label  = $action === 'hold' ? 'Hold' : 'penolakan';
        
        answer_cb($token, $cb_id, "📝 Ketik alasan {$label}...");
        // Send prompt and capture its message_id
        $prompt_msg_id = send_msg($token, $chat_id,
            "📝 <b>Ketik alasan {$label}</b> untuk {$type} #{$id} dan kirim sebagai pesan.\n\nAtau tekan tombol di bawah untuk langsung memproses tanpa alasan.",
            [[['text' => '⏭ Skip (Tanpa Alasan)', 'callback_data' => "{$act_id}_skip_{$id}"]]]
        );
        // Save state: awaiting_reason|type|action|id|orig_msg_id|orig_b64|prompt_msg_id
        $state = implode('|', ['awaiting_reason', $type, $action, $id, $msg_id, base64_encode($orig), (int)$prompt_msg_id]);
        set_tg_state($pdo, $chat_id, $state);
        http_response_code(200); exit;
    }

    // ── REJECT / HOLD SKIP (no reason) ─────────────────────────────────────────
    if (preg_match('/^(depo|wd)_(reject|hold)_skip_(\d+)$/', $data, $m)) {
        $type   = $m[1];
        $action = $m[2];
        $id     = (int)$m[3];
        clear_tg_state($pdo, $chat_id);

        if ($type === 'depo') {
            $res = do_depo_reject($pdo, $id, '');
            $icon = '❌'; $status = 'Rejected';
        } else {
            if ($action === 'hold') {
                $res = do_wd_hold($pdo, $id, '');
                $icon = '⏸'; $status = 'Hold';
            } else {
                $res = do_wd_reject($pdo, $id, '');
                $icon = '❌'; $status = 'Rejected';
            }
        }

        if ($res === 'ok') {
            answer_cb($token, $cb_id, "{$icon} " . ucfirst($action) . " tanpa alasan.");
            edit_msg($token, $chat_id, $msg_id, str_replace('Status: Pending', "Status: {$icon} {$status}", $orig));
        } else {
            answer_cb($token, $cb_id, '⚠️ ' . $res);
        }
        http_response_code(200); exit;
    }

    http_response_code(200); exit;
}

// ── Message Handler (for reject reason text) ─────────────────────────────────

if (isset($update['message'])) {
    $msg     = $update['message'];
    $chat_id = $msg['chat']['id'] ?? '';
    $text    = trim($msg['text'] ?? '');

    if (empty($text)) {
        http_response_code(200); exit;
    }



    if ((string)$chat_id !== (string)$admin_chat_id) {
        http_response_code(200); exit;
    }

    $state = get_tg_state($pdo, $chat_id);
    if (!$state) { http_response_code(200); exit; }

    $parts = explode('|', $state, 7);
    if ($parts[0] !== 'awaiting_reason') { http_response_code(200); exit; }

    [, $type, $action, $id, $orig_msg_id, $orig_b64, $prompt_msg_id] = array_pad($parts, 7, 0);
    $id            = (int)$id;
    $orig_msg_id   = (int)$orig_msg_id;
    $prompt_msg_id = (int)$prompt_msg_id;
    $orig_text     = base64_decode($orig_b64);
    $reason        = $text;

    clear_tg_state($pdo, $chat_id);

    if ($type === 'depo') {
        $res = do_depo_reject($pdo, $id, $reason);
        $icon = '❌'; $status = 'Rejected';
    } else {
        if ($action === 'hold') {
            $res = do_wd_hold($pdo, $id, $reason);
            $icon = '⏸'; $status = 'Hold';
        } else {
            $res = do_wd_reject($pdo, $id, $reason);
            $icon = '❌'; $status = 'Rejected';
        }
    }

    if ($res === 'ok') {
        $new_text = str_replace('Status: Pending', "Status: {$icon} {$status}\nAlasan: {$reason}", $orig_text);
        edit_msg($token, $chat_id, $orig_msg_id, $new_text);
        // Edit the prompt message to confirm the reason was received
        if ($prompt_msg_id) {
            edit_msg($token, $chat_id, $prompt_msg_id,
                "{$icon} <b>" . strtoupper($type) . " #{$id} " . ($action==='hold'?'di-hold':'ditolak') . "</b>\n📝 Alasan: <i>" . htmlspecialchars($reason) . "</i>");
        }
    } else {
        send_msg($token, $chat_id, "⚠️ Gagal: {$res}");
    }

    http_response_code(200); exit;
}

http_response_code(200);
