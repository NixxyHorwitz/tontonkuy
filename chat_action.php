<?php
/**
 * chat_action.php — AJAX handler untuk LiveChat (Telegram + OpenAI)
 * Endpoint: /chat_action?action=...
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── Helper: JSON response ────────────────────────────────────
function json_ok(array $data = []): never {
    echo json_encode(['ok' => true, ...$data]);
    exit;
}
function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ─── Helper: Get/create session ──────────────────────────────
function get_chat_session(PDO $pdo, string $key): ?array {
    $s = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_key=?");
    $s->execute([$key]);
    return $s->fetch() ?: null;
}

// ─── Helper: Telegram API call (pakai lc_tg_token — BUKAN bot depo/WD) ──
function tg_api(PDO $pdo, string $method, array $params): array {
    $token = setting($pdo, 'lc_tg_token', '');
    if (!$token) return ['ok' => false];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?: [];
}

// ─── Helper: OpenAI chat completion ──────────────────────────
function openai_chat(PDO $pdo, array $messages): string {
    $apiKey = setting($pdo, 'openai_api_key', '');
    $model  = setting($pdo, 'openai_model', 'gpt-4o-mini');
    if (!$apiKey) return 'Maaf, layanan AI sedang tidak tersedia.';

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 600,
            'temperature' => 0.7,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return 'Maaf, gagal menghubungi AI: ' . $err;
    $data = json_decode($res ?: '{}', true);
    return trim($data['choices'][0]['message']['content'] ?? 'Maaf, AI tidak merespons.');
}

// ─── Helper: escape plain text for Telegram ──────────────────
function tg_escape(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')','{','}','~','`','>','#','+','-','=','|','.',',','!','\\'],
        ['\_','\*','\[','\]','\(','\)','\{','\}','\~','\`','\>','\#','\+','\-','\=','\|','\.','\,','\!','\\\\'],
        $text
    );
}

// ─── Helper: Cleanup Inactive Sessions ────────────────────────
function cleanup_inactive_sessions(PDO $pdo): void {
    try {
        $stale = $pdo->query("SELECT id, tg_thread_id FROM chat_sessions WHERE status='open' AND last_message_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchAll();
        if (!$stale) return;
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        foreach ($stale as $st) {
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$st['id']]);
            if ($st['tg_thread_id'] && $chatId) {
                tg_api($pdo, 'deleteForumTopic', [
                    'chat_id'           => $chatId,
                    'message_thread_id' => (int)$st['tg_thread_id'],
                ]);
            }
        }
    } catch (\Throwable $th) {}
}

// ═══════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════

switch ($action) {

    // ── Start / get session ─────────────────────────────────────
    case 'start':
        $user       = auth_user($pdo);
        $sessionKey = $_COOKIE['chat_session'] ?? '';

        // Cek sesi existing
        if ($sessionKey) {
            $sess = get_chat_session($pdo, $sessionKey);
            if ($sess) {
                $msgs = $pdo->prepare(
                    "SELECT id,sender,message,created_at FROM chat_messages
                     WHERE session_id=? ORDER BY id ASC LIMIT 100"
                );
                $msgs->execute([$sess['id']]);
                $rows = $msgs->fetchAll();
                json_ok([
                    'session_key' => $sess['session_key'],
                    'mode'        => $sess['mode'],
                    'status'      => $sess['status'],
                    'messages'    => $rows,
                    'last_msg_id' => !empty($rows) ? (int)end($rows)['id'] : 0,
                    'welcome'     => setting($pdo, 'chat_welcome_msg', 'Halo! Ada yang bisa dibantu?'),
                ]);
            }
        }

        // Buat sesi baru
        $newKey    = bin2hex(random_bytes(16));
        $userName  = $user ? $user['username'] : (trim($_POST['name'] ?? '') ?: 'Guest');
        $userEmail = $user ? $user['email'] : (trim($_POST['email'] ?? '') ?: null);
        $userId    = $user ? (int)$user['id'] : null;

        $pdo->prepare(
            "INSERT INTO chat_sessions (session_key,user_id,user_name,user_email,mode) VALUES (?,?,?,?,'ai')"
        )->execute([$newKey, $userId, $userName, $userEmail]);
        $sessId = (int)$pdo->lastInsertId();

        // Welcome message
        $welcome = setting($pdo, 'chat_welcome_msg', 'Halo! Ada yang bisa kami bantu?');
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sessId, $welcome]);
        $welcomeMsgId = (int)$pdo->lastInsertId();

        setcookie('chat_session', $newKey, time() + 86400 * 7, '/', '', false, true);

        // ── Telegram: buat thread + inline keyboard ──
        $chatId  = setting($pdo, 'lc_tg_chat_id', '');
        $isForum = setting($pdo, 'lc_tg_forum', '1') === '1';
        $siteUrl = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        $tgThreadId = null;
        $tgDebug    = null;

        // Inline keyboard untuk admin
        $consoleLink = $siteUrl ? "{$siteUrl}/console/livechat.php?view={$sessId}" : null;
        
        $inlineKbd = ['inline_keyboard' => []];
        if ($consoleLink) {
            $inlineKbd['inline_keyboard'][] = [['text' => "🖥️ Buka Console", 'url' => $consoleLink]];
        }
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "🔒 Tutup", 'callback_data' => "close_sess:{$sessId}"],
            ['text' => "🗑️ Hapus Sesi", 'callback_data' => "del_thread:{$sessId}"]
        ];
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "🤖 Mode AI", 'callback_data' => "mode_ai:{$sessId}"],
            ['text' => "👨‍💼 Mode Admin", 'callback_data' => "mode_admin:{$sessId}"]
        ];
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "🗑️ Hapus Pesan Ini", 'callback_data' => "del_msg:{$sessId}"]
        ];

        if ($chatId) {
            $threadTitle = "{$userName}" . ($userEmail ? " ({$userEmail})" : '') . " #S{$sessId}";
            $intro = "Sesi Baru\nUser: {$userName}"
                   . ($userEmail ? "\nEmail: {$userEmail}" : '')
                   . "\nSession: #{$sessId}\nMode: AI";

            if ($isForum) {
                $tgRes   = tg_api($pdo, 'createForumTopic', [
                    'chat_id'    => $chatId,
                    'name'       => mb_substr($threadTitle, 0, 128),
                    'icon_color' => 7322096,
                ]);
                $tgDebug = $tgRes;

                if (!empty($tgRes['ok'])) {
                    $tgThreadId = $tgRes['result']['message_thread_id'] ?? null;
                    $pdo->prepare("UPDATE chat_sessions SET tg_thread_id=? WHERE id=?")
                        ->execute([$tgThreadId, $sessId]);
                    tg_api($pdo, 'sendMessage', [
                        'chat_id'           => $chatId,
                        'message_thread_id' => $tgThreadId,
                        'text'              => $intro,
                        'reply_markup'      => $inlineKbd,
                    ]);
                } else {
                    $errDesc = $tgRes['description'] ?? 'unknown';
                    tg_api($pdo, 'sendMessage', [
                        'chat_id'      => $chatId,
                        'text'         => "Gagal buat thread: {$errDesc}\n\n" . $intro,
                        'reply_markup' => $inlineKbd,
                    ]);
                }
            } else {
                $tgRes   = tg_api($pdo, 'sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => $intro,
                    'reply_markup' => $inlineKbd,
                ]);
                $tgDebug = $tgRes;
            }
        }

        json_ok([
            'session_key' => $newKey,
            'mode'        => 'ai',
            'status'      => 'open',
            'messages'    => [
                ['id' => $welcomeMsgId, 'sender' => 'system', 'message' => $welcome, 'created_at' => date('Y-m-d H:i:s')],
            ],
            'last_msg_id' => $welcomeMsgId,
            'welcome'     => $welcome,
            'tg_debug'    => $tgDebug,
        ]);


    // ── Send message ────────────────────────────────────────────
    case 'send':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        $text       = trim($_POST['message'] ?? '');
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');
        if (!$text || mb_strlen($text) > 2000) json_err('Pesan tidak valid.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');
        if ($sess['status'] === 'closed') json_err('Sesi ini sudah ditutup.');

        $sessId = (int)$sess['id'];

        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'user',?)"
        )->execute([$sessId, $text]);
        $userMsgId = (int)$pdo->lastInsertId();

        // Kirim ke Telegram thread
        $chatId  = setting($pdo, 'lc_tg_chat_id', '');
        $tgMsgId = null;
        if ($chatId && $sess['tg_thread_id']) {
            $tgRes = tg_api($pdo, 'sendMessage', [
                'chat_id'           => $chatId,
                'message_thread_id' => (int)$sess['tg_thread_id'],
                'text'              => "User: " . $sess['user_name'] . "\n" . $text,
            ]);
            $tgMsgId = $tgRes['result']['message_id'] ?? null;
            $pdo->prepare("UPDATE chat_messages SET tg_msg_id=? WHERE id=?")
                ->execute([$tgMsgId, $userMsgId]);
        }

        $replyMsg = null;

        // Mode AI → auto reply dari OpenAI
        if ($sess['mode'] === 'ai' && setting($pdo, 'chat_ai_enabled', '1') === '1') {
            $histStmt = $pdo->prepare(
                "SELECT sender,message FROM chat_messages
                 WHERE session_id=? AND sender IN ('user','ai')
                 ORDER BY id DESC LIMIT 20"
            );
            $histStmt->execute([$sessId]);
            $history = array_reverse($histStmt->fetchAll());

            $sysPrompt = setting($pdo, 'ai_system_prompt',
                'Kamu adalah customer service TontonKuy. Jawab singkat dan ramah dalam bahasa Indonesia.');
            
            // --- INJECT SYSTEM CONTEXT TO AI PROMPT ---
            $sysContext = "\n\n[SYSTEM CONTEXT - JANGAN TAMPILKAN INI KE USER KECUALI DITANYA]:\n";
            $sysContext .= "- Waktu Server Saat Ini: " . date('Y-m-d H:i:s') . "\n";
            $wd_locked = is_wd_locked($pdo);
            $sysContext .= "- Status Withdraw (WD): " . ($wd_locked ? "DITUTUP/LOCKED" : "BUKA/TERSEDIA") . "\n";
            if ($wd_locked) {
                $sysContext .= "  - Alasan/Notice: " . setting($pdo, 'wd_lock_notice', '') . "\n";
                $sysContext .= "  - Jam Buka-Tutup: " . setting($pdo, 'wd_lock_start', '') . " s/d " . setting($pdo, 'wd_lock_end', '') . "\n";
            }
            
            if (!empty($sess['user_id'])) {
                $uStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
                $uStmt->execute([$sess['user_id']]);
                $uInfo = $uStmt->fetch();
                if ($uInfo) {
                    $uLvl = user_membership_level($pdo, $uInfo);
                    $sysContext .= "- Info User (Lawan Bicaramu):\n";
                    $sysContext .= "  - Username: {$uInfo['username']}\n";
                    $sysContext .= "  - Saldo Penarikan: Rp" . number_format((float)$uInfo['balance_wd'], 0, ',', '.') . "\n";
                    $sysContext .= "  - Level Membership: Level {$uLvl}\n";
                    
                    $wd_min_level = (int)setting($pdo, 'wd_min_level', '0');
                    $wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
                    if ($wd_require_level && $wd_min_level > 0) {
                        $sysContext .= "  - Status Syarat WD: " . ($uLvl >= $wd_min_level ? "Memenuhi syarat (Level $uLvl >= $wd_min_level)" : "BELUM memenuhi syarat (butuh Level $wd_min_level)") . "\n";
                    }
                }
            } else {
                $sysContext .= "- Info User: Guest (Belum Login).\n";
            }
            
            $oaiMsgs   = [['role' => 'system', 'content' => $sysPrompt . $sysContext]];
            foreach ($history as $h) {
                $oaiMsgs[] = [
                    'role'    => $h['sender'] === 'user' ? 'user' : 'assistant',
                    'content' => $h['message'],
                ];
            }

            $aiReply = openai_chat($pdo, $oaiMsgs);

            $pdo->prepare(
                "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'ai',?)"
            )->execute([$sessId, $aiReply]);
            $aiMsgId = (int)$pdo->lastInsertId();

            if ($chatId && $sess['tg_thread_id']) {
                $tgAi = tg_api($pdo, 'sendMessage', [
                    'chat_id'           => $chatId,
                    'message_thread_id' => (int)$sess['tg_thread_id'],
                    'text'              => "AI: " . $aiReply,
                ]);
                $pdo->prepare("UPDATE chat_messages SET tg_msg_id=? WHERE id=?")
                    ->execute([$tgAi['result']['message_id'] ?? null, $aiMsgId]);
            }

            $replyMsg = ['id' => $aiMsgId, 'sender' => 'ai', 'message' => $aiReply, 'created_at' => date('Y-m-d H:i:s')];
        }

        json_ok([
            'user_message' => ['id' => $userMsgId, 'sender' => 'user', 'message' => $text, 'created_at' => date('Y-m-d H:i:s')],
            'last_msg_id'  => $replyMsg ? (int)$replyMsg['id'] : $userMsgId,
            'reply'        => $replyMsg,
        ]);


    // ── Poll new messages ────────────────────────────────────────
    case 'poll':
        if (rand(1, 10) === 1) cleanup_inactive_sessions($pdo); // Auto-close inactive
        $sessionKey = $_COOKIE['chat_session'] ?? $_GET['session_key'] ?? '';
        $afterId    = (int)($_GET['after_id'] ?? 0);
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $msgs = $pdo->prepare(
            "SELECT id,sender,message,created_at FROM chat_messages
             WHERE session_id=? AND id>? ORDER BY id ASC LIMIT 50"
        );
        $msgs->execute([$sess['id'], $afterId]);
        $rows = $msgs->fetchAll();

        json_ok([
            'messages' => $rows,
            'status'   => $sess['status'],
            'mode'     => $sess['mode'],
        ]);


    // ── Switch mode (AI ↔ Admin) ─────────────────────────────────
    case 'switch_mode':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        $newMode    = $_POST['mode'] ?? '';
        if (!in_array($newMode, ['ai', 'admin'], true)) json_err('Mode tidak valid.');
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $pdo->prepare("UPDATE chat_sessions SET mode=? WHERE id=?")->execute([$newMode, $sess['id']]);

        $switchMsg = $newMode === 'admin'
            ? 'Beralih ke Mode Admin — tim kami akan segera membalas.'
            : 'Beralih ke Mode AI — Asisten AI siap membantu.';
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sess['id'], $switchMsg]);
        $switchMsgId = (int)$pdo->lastInsertId();

        // Notif ke Telegram untuk semua mode switch
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        if ($chatId) {
            $modeLabel = $newMode === 'admin' ? 'Admin' : 'AI';
            $tgParams  = [
                'chat_id' => $chatId,
                'text'    => "[{$sess['user_name']}] beralih ke Mode {$modeLabel}. Sesi #{$sess['id']}",
            ];
            if ($sess['tg_thread_id']) {
                $tgParams['message_thread_id'] = (int)$sess['tg_thread_id'];
            }
            tg_api($pdo, 'sendMessage', $tgParams);
        }

        json_ok([
            'mode'           => $newMode,
            'switch_msg_id'  => $switchMsgId,
            'switch_message' => $switchMsg,
        ]);


    // ── Close session ────────────────────────────────────────────
    case 'close':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sess['id']]);
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi chat telah ditutup.')"
        )->execute([$sess['id']]);

        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        if ($chatId && $sess['tg_thread_id']) {
            tg_api($pdo, 'closeForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => (int)$sess['tg_thread_id'],
            ]);
        }

        setcookie('chat_session', '', time() - 3600, '/');
        json_ok(['closed' => true]);


    // ── Webhook dari Telegram ─────────────────────────────────────
    case 'tg_webhook':
        $input = json_decode(file_get_contents('php://input'), true);

        // Handle callback_query (inline button dari Telegram)
        if (!empty($input['callback_query'])) {
            $cb     = $input['callback_query'];
            $cbId   = $cb['id'];
            $cbData = $cb['data'] ?? '';

            [$cbAction, $cbSessId] = array_pad(explode(':', $cbData, 2), 2, '');
            $cbSessId = (int)$cbSessId;
            $ackText  = 'Done';

            if ($cbSessId) {
                $csStmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
                $csStmt->execute([$cbSessId]);
                $csRow = $csStmt->fetch();

                if ($csRow) {
                    $tgChatId = setting($pdo, 'lc_tg_chat_id', '');
                    if ($cbAction === 'close_sess') {
                        if ($csRow['status'] === 'open') {
                            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$cbSessId]);
                            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi ditutup oleh Admin via Telegram.')")->execute([$cbSessId]);
                            if ($csRow['tg_thread_id'] && $tgChatId) {
                                tg_api($pdo, 'closeForumTopic', [
                                    'chat_id'           => $tgChatId,
                                    'message_thread_id' => (int)$csRow['tg_thread_id'],
                                ]);
                            }
                            $ackText = 'Sesi ditutup!';
                        } else {
                            $ackText = 'Sesi sudah ditutup.';
                        }
                    } elseif ($cbAction === 'mode_ai') {
                        $pdo->prepare("UPDATE chat_sessions SET mode='ai' WHERE id=?")->execute([$cbSessId]);
                        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Mode beralih ke Asisten AI oleh Admin.')")->execute([$cbSessId]);
                        $ackText = 'Mode AI aktif';
                    } elseif ($cbAction === 'mode_admin') {
                        $pdo->prepare("UPDATE chat_sessions SET mode='admin' WHERE id=?")->execute([$cbSessId]);
                        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Mode beralih ke Admin.')")->execute([$cbSessId]);
                        $ackText = 'Mode Admin aktif';
                    } elseif ($cbAction === 'del_thread') {
                        $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$cbSessId]);
                        if ($csRow['tg_thread_id'] && $tgChatId) {
                            tg_api($pdo, 'deleteForumTopic', [
                                'chat_id'           => $tgChatId,
                                'message_thread_id' => (int)$csRow['tg_thread_id'],
                            ]);
                        }
                        $ackText = 'Sesi dihapus!';
                    } elseif ($cbAction === 'del_msg') {
                        tg_api($pdo, 'deleteMessage', [
                            'chat_id'    => $cb['message']['chat']['id'],
                            'message_id' => $cb['message']['message_id']
                        ]);
                        $ackText = 'Pesan dihapus!';
                    }
                }
            }

            tg_api($pdo, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => $ackText,
                'show_alert'        => false,
            ]);
            echo '{}'; exit;
        }

        // Handle regular message (admin reply via Telegram)
        if (empty($input['message'])) { echo '{}'; exit; }

        $msg      = $input['message'];
        $threadId = $msg['message_thread_id'] ?? null;
        $text     = $msg['text'] ?? '';
        $fromUser = $msg['from'] ?? [];

        if (!empty($fromUser['is_bot'])) { echo '{}'; exit; }
        if (!$threadId || !$text) { echo '{}'; exit; }

        $s = $pdo->prepare("SELECT * FROM chat_sessions WHERE tg_thread_id=? AND status='open' LIMIT 1");
        $s->execute([$threadId]);
        $sess = $s->fetch();
        if (!$sess) { echo '{}'; exit; }

        $adminName = trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? '')) ?: 'Admin';
        $fullText  = "[{$adminName}] {$text}";

        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message,tg_msg_id) VALUES (?,'admin',?,?)"
        )->execute([$sess['id'], $fullText, $msg['message_id']]);

        if ($sess['mode'] === 'ai') {
            $pdo->prepare("UPDATE chat_sessions SET mode='admin' WHERE id=?")->execute([$sess['id']]);
        }

        echo '{}';
        exit;


    default:
        json_err('Action tidak dikenal.', 404);
}
