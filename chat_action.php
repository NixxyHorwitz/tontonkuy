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

// ─── Helper: Telegram API call ───────────────────────────────
function tg_api(PDO $pdo, string $method, array $params): array {
    $token = setting($pdo, 'tg_bot_token', '');
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

// ─── Helper: Format Telegram escaping (MarkdownV2) ────────────
function tg_escape(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')','{','}','~','`','>','#','+','-','=','|','.',',','!','\\'],
        ['\_','\*','\[','\]','\(','\)','\{','\}','\~','\`','\>','\#','\+','\-','\=','\|','\.','\,','\!','\\\\'],
        $text
    );
}

// ═══════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════

switch ($action) {

    // ── Start / get session ─────────────────────────────────────
    case 'start':
        $user      = auth_user($pdo);
        $sessionKey = $_COOKIE['chat_session'] ?? '';

        // Cek sesi existing
        if ($sessionKey) {
            $sess = get_chat_session($pdo, $sessionKey);
            if ($sess) {
                // Load existing messages — sertakan id agar JS bisa track lastMsgId
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
        $newKey   = bin2hex(random_bytes(16));
        $userName = $user ? $user['username'] : (trim($_POST['name'] ?? '') ?: 'Guest');
        $userEmail= $user ? $user['email'] : (trim($_POST['email'] ?? '') ?: null);
        $userId   = $user ? (int)$user['id'] : null;

        $pdo->prepare(
            "INSERT INTO chat_sessions (session_key,user_id,user_name,user_email,mode) VALUES (?,?,?,?,'ai')"
        )->execute([$newKey, $userId, $userName, $userEmail]);
        $sessId = (int)$pdo->lastInsertId();

        // Welcome message in DB
        $welcome = setting($pdo, 'chat_welcome_msg', 'Halo! 👋 Ada yang bisa kami bantu?');
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sessId, $welcome]);
        $welcomeMsgId = (int)$pdo->lastInsertId();

        // Set cookie
        setcookie('chat_session', $newKey, time() + 86400 * 7, '/', '', false, true);

        // Buat thread di Telegram Forum group
        $chatId     = setting($pdo, 'tg_chat_id', '');
        $isForum    = setting($pdo, 'tg_group_is_forum', '1') === '1';
        $tgThreadId = null;
        $tgDebug    = null;
        if ($chatId) {
            $threadTitle = "💬 {$userName}" . ($userEmail ? " ({$userEmail})" : '') . " #S{$sessId}";
            $intro = "🆕 Sesi Baru\n"
                   . "👤 User: {$userName}"
                   . ($userEmail ? "\n📧 Email: {$userEmail}" : '')
                   . "\n🆔 Session: #{$sessId}\n🤖 Mode: AI";

            if ($isForum) {
                // Coba buat Forum Topic (Supergroup dengan Topics aktif)
                $tgRes = tg_api($pdo, 'createForumTopic', [
                    'chat_id'    => $chatId,
                    'name'       => mb_substr($threadTitle, 0, 128),
                    'icon_color' => 7322096, // biru muda
                ]);
                $tgDebug = $tgRes; // simpan untuk debug

                if (!empty($tgRes['ok'])) {
                    $tgThreadId = $tgRes['result']['message_thread_id'] ?? null;
                    $pdo->prepare("UPDATE chat_sessions SET tg_thread_id=? WHERE id=?")
                        ->execute([$tgThreadId, $sessId]);
                    // Kirim info sesi ke thread
                    tg_api($pdo, 'sendMessage', [
                        'chat_id'           => $chatId,
                        'message_thread_id' => $tgThreadId,
                        'text'              => $intro,
                    ]);
                } else {
                    // Forum topic gagal — fallback kirim notif biasa ke group
                    $errDesc = $tgRes['description'] ?? 'unknown error';
                    tg_api($pdo, 'sendMessage', [
                        'chat_id' => $chatId,
                        'text'    => "⚠️ Gagal buat thread: {$errDesc}\n\n" . $intro,
                    ]);
                }
            } else {
                // Mode grup biasa (tanpa forum/topic)
                $tgRes = tg_api($pdo, 'sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => $intro,
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
            'tg_debug'    => $tgDebug, // bantu troubleshoot
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

        // Simpan pesan user
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'user',?)"
        )->execute([$sessId, $text]);
        $userMsgId = (int)$pdo->lastInsertId();

        // Kirim ke Telegram thread
        $chatId     = setting($pdo, 'tg_chat_id', '');
        $tgMsgId    = null;
        if ($chatId && $sess['tg_thread_id']) {
            $tgRes = tg_api($pdo, 'sendMessage', [
                'chat_id'           => $chatId,
                'message_thread_id' => (int)$sess['tg_thread_id'],
                'text'              => "👤 *" . tg_escape($sess['user_name']) . "*\n" . tg_escape($text),
                'parse_mode'        => 'MarkdownV2',
            ]);
            $tgMsgId = $tgRes['result']['message_id'] ?? null;
            $pdo->prepare("UPDATE chat_messages SET tg_msg_id=? WHERE id=?")
                ->execute([$tgMsgId, $userMsgId]);
        }

        $replyMsg = null;

        // Mode AI → auto reply dari OpenAI
        if ($sess['mode'] === 'ai' && setting($pdo, 'chat_ai_enabled', '1') === '1') {
            // Build message history for context
            $histStmt = $pdo->prepare(
                "SELECT sender,message FROM chat_messages 
                 WHERE session_id=? AND sender IN ('user','ai') 
                 ORDER BY id DESC LIMIT 20"
            );
            $histStmt->execute([$sessId]);
            $history = array_reverse($histStmt->fetchAll());

            $sysPrompt = setting($pdo, 'ai_system_prompt',
                'Kamu adalah customer service TontonKuy. Jawab singkat dan ramah dalam bahasa Indonesia.');
            $oaiMsgs   = [['role' => 'system', 'content' => $sysPrompt]];
            foreach ($history as $h) {
                $oaiMsgs[] = [
                    'role'    => $h['sender'] === 'user' ? 'user' : 'assistant',
                    'content' => $h['message'],
                ];
            }

            $aiReply = openai_chat($pdo, $oaiMsgs);

            // Simpan AI reply
            $pdo->prepare(
                "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'ai',?)"
            )->execute([$sessId, $aiReply]);
            $aiMsgId = (int)$pdo->lastInsertId();

            // Kirim AI reply ke Telegram juga (info)
            if ($chatId && $sess['tg_thread_id']) {
                $tgAi = tg_api($pdo, 'sendMessage', [
                    'chat_id'           => $chatId,
                    'message_thread_id' => (int)$sess['tg_thread_id'],
                    'text'              => "🤖 *AI:* " . tg_escape($aiReply),
                    'parse_mode'        => 'MarkdownV2',
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


    // ── Poll new messages (for admin reply via Telegram webhook) ─
    case 'poll':
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


    // ── Switch mode (AI ↔ Admin) — user request ────────────────
    case 'switch_mode':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        $newMode    = $_POST['mode'] ?? '';
        if (!in_array($newMode, ['ai', 'admin'], true)) json_err('Mode tidak valid.');
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $pdo->prepare("UPDATE chat_sessions SET mode=? WHERE id=?")->execute([$newMode, $sess['id']]);

        // Simpan marker mode switch ke DB
        $switchMsg = $newMode === 'admin'
            ? '🔄 Beralih ke Mode Admin — tim kami akan segera membalas.'
            : '🔄 Beralih ke Mode AI — Asisten AI siap membantu.';
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sess['id'], $switchMsg]);
        $switchMsgId = (int)$pdo->lastInsertId();

        // Kirim notif ke Telegram jika switch ke admin
        $chatId = setting($pdo, 'tg_chat_id', '');
        if ($newMode === 'admin' && $chatId) {
            $tgParams = [
                'chat_id' => $chatId,
                'text'    => "🔔 [{$sess['user_name']}] beralih ke Mode Admin. Sesi #{$sess['id']}",
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


    // ── Close session ───────────────────────────────────────────
    case 'close':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sess['id']]);
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi chat telah ditutup.')"
        )->execute([$sess['id']]);

        $chatId = setting($pdo, 'tg_chat_id', '');
        if ($chatId && $sess['tg_thread_id']) {
            tg_api($pdo, 'closeForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => (int)$sess['tg_thread_id'],
            ]);
        }

        setcookie('chat_session', '', time() - 3600, '/');
        json_ok(['closed' => true]);


    // ── Webhook dari Telegram (admin reply → disimpan ke DB) ────
    case 'tg_webhook':
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['message'])) { echo '{}'; exit; }

        $msg       = $input['message'];
        $threadId  = $msg['message_thread_id'] ?? null;
        $text      = $msg['text'] ?? '';
        $fromUser  = $msg['from'] ?? [];

        // Cek apakah pesan dari bot sendiri (skip)
        if (!empty($fromUser['is_bot'])) { echo '{}'; exit; }
        if (!$threadId || !$text) { echo '{}'; exit; }

        // Cari sesi berdasarkan tg_thread_id
        $s = $pdo->prepare("SELECT * FROM chat_sessions WHERE tg_thread_id=? AND status='open' LIMIT 1");
        $s->execute([$threadId]);
        $sess = $s->fetch();
        if (!$sess) { echo '{}'; exit; }

        $adminName = trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? '')) ?: 'Admin';
        $fullText  = "[{$adminName}] {$text}";

        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message,tg_msg_id) VALUES (?,'admin',?,?)"
        )->execute([$sess['id'], $fullText, $msg['message_id']]);

        // Jika mode AI, switch otomatis ke admin
        if ($sess['mode'] === 'ai') {
            $pdo->prepare("UPDATE chat_sessions SET mode='admin' WHERE id=?")->execute([$sess['id']]);
        }

        echo '{}';
        exit;


    default:
        json_err('Action tidak dikenal.', 404);
}
