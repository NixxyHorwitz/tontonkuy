<?php
/**
 * chat_action.php â€” AJAX handler untuk LiveChat (Telegram + OpenAI)
 * Endpoint: /chat_action?action=...
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// â”€â”€â”€ Helper: JSON response â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function json_ok(array $data = []): never {
    echo json_encode(['ok' => true, ...$data]);
    exit;
}
function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// â”€â”€â”€ Helper: Get/create session â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function get_chat_session(PDO $pdo, string $key): ?array {
    $s = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_key=?");
    $s->execute([$key]);
    return $s->fetch() ?: null;
}

// â”€â”€â”€ Helper: Telegram API call â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€â”€ Helper: OpenAI chat completion â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€â”€ Helper: Format Telegram escaping (MarkdownV2) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function tg_escape(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')','{','}','~','`','>','#','+','-','=','|','.',',','!','\\'],
        ['\_','\*','\[','\]','\(','\)','\{','\}','\~','\`','\>','\#','\+','\-','\=','\|','\.','\,','\!','\\\\'],
        $text
    );
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// ACTIONS
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

switch ($action) {

    // â”€â”€ Start / get session â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    case 'start':
        $user      = auth_user($pdo);
        $sessionKey = $_COOKIE['chat_session'] ?? '';

        // Cek sesi existing
        if ($sessionKey) {
            $sess = get_chat_session($pdo, $sessionKey);
            if ($sess) {
                // Load existing messages â€” sertakan id agar JS bisa track lastMsgId
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
        $welcome = setting($pdo, 'chat_welcome_msg', 'Halo! ðŸ‘‹ Ada yang bisa kami bantu?');
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sessId, $welcome]);
        $welcomeMsgId = (int)$pdo->lastInsertId();

        // Set cookie
        setcookie('chat_session', $newKey, time() + 86400 * 7, '/', '', false, true);

        // Buat thread di Telegram Forum group
        $chatId     = setting($pdo, 'lc_tg_chat_id', '');
        $isForum    = setting($pdo, 'lc_tg_forum', '1') === '1';
        $siteUrl    = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        $tgThreadId = null;
        $tgDebug    = null;

        // Inline keyboard untuk manajemen sesi
        $consoleLink = $siteUrl ? $siteUrl . "/console/livechat.php?view={$sessId}" : null;
        $inlineKbd = ['inline_keyboard' => [
            array_filter([
                $consoleLink ? ['text' => 'ðŸ–¥ï¸ Buka Console', 'url' => $consoleLink] : null,
                ['text' => 'ðŸ”’ Tutup Sesi', 'callback_data' => "close_sess:{$sessId}"],
                ['text' => 'ðŸ¤– Ganti ke AI', 'callback_data' => "mode_ai:{$sessId}"],
                ['text' => 'ðŸ‘¨â€ðŸ’¼ Ganti ke Admin', 'callback_data' => "mode_admin:{$sessId}"],
            ])
        ]];

        if ($chatId) {
            $threadTitle = "ðŸ’¬ {$userName}" . ($userEmail ? " ({$userEmail})" : '') . " #S{$sessId}";
            $intro = "ðŸ†• Sesi Baru\n"
                   . "ðŸ‘¤ User: {$userName}"
                   . ($userEmail ? "\nðŸ“§ Email: {$userEmail}" : '')
                   . "\nðŸ†” Session: #{$sessId}\nðŸ¤– Mode: AI";

            if ($isForum) {
                $tgRes = tg_api($pdo, 'createForumTopic', [
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
                    $errDesc = $tgRes['description'] ?? 'unknown error';
                    tg_api($pdo, 'sendMessage', [
                        'chat_id'      => $chatId,
                        'text'         => "âš ï¸ Gagal buat thread: {$errDesc}\n\n" . $intro,
                        'reply_markup' => $inlineKbd,
                    ]);
                }
            } else {
                $tgRes = tg_api($pdo, 'sendMessage', [
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
            'tg_debug'    => $tgDebug, // bantu troubleshoot
        ]);


    // â”€â”€ Send message â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
        $chatId     = setting($pdo, 'lc_tg_chat_id', '');
        $tgMsgId    = null;
        if ($chatId && $sess['tg_thread_id']) {
            $tgRes = tg_api($pdo, 'sendMessage', [
                'chat_id'           => $chatId,
                'message_thread_id' => (int)$sess['tg_thread_id'],
                'text'              => "ðŸ‘¤ *" . tg_escape($sess['user_name']) . "*\n" . tg_escape($text),
                'parse_mode'        => 'MarkdownV2',
            ]);
            $tgMsgId = $tgRes['result']['message_id'] ?? null;
            $pdo->prepare("UPDATE chat_messages SET tg_msg_id=? WHERE id=?")
                ->execute([$tgMsgId, $userMsgId]);
        }

        $replyMsg = null;

        // Mode AI â†’ auto reply dari OpenAI
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
                    'text'              => "ðŸ¤– *AI:* " . tg_escape($aiReply),
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


    // â”€â”€ Poll new messages (for admin reply via Telegram webhook) â”€
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


    // â”€â”€ Switch mode (AI â†” Admin) â€” user request â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
            ? 'ðŸ”„ Beralih ke Mode Admin â€” tim kami akan segera membalas.'
            : 'ðŸ”„ Beralih ke Mode AI â€” Asisten AI siap membantu.';
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sess['id'], $switchMsg]);
        $switchMsgId = (int)$pdo->lastInsertId();

        // Kirim notif ke Telegram untuk semua mode switch
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        if ($chatId) {
            $modeEmoji = $newMode === 'admin' ? 'ðŸ‘¨â€ðŸ’¼' : 'ðŸ¤–';
            $modeLabel = $newMode === 'admin' ? 'Admin' : 'AI';
            $tgParams  = [
                'chat_id' => $chatId,
                'text'    => "{$modeEmoji} [{$sess['user_name']}] beralih ke Mode {$modeLabel}. Sesi #{$sess['id']}",
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


    // â”€â”€ Close session â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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


    // â”€â”€ Webhook dari Telegram â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    case 'tg_webhook':
        $input = json_decode(file_get_contents('php://input'), true);

        // â”€â”€ Handle callback_query (inline button) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if (!empty($input['callback_query'])) {
            $cb       = $input['callback_query'];
            $cbId     = $cb['id'];
            $cbData   = $cb['data'] ?? '';
            $cbFrom   = $cb['from'] ?? [];
            $cbName   = trim(($cbFrom['first_name']??'')." ".($cbFrom['last_name']??'')) ?: 'Admin';

            [$cbAction, $cbSessId] = array_pad(explode(':', $cbData, 2), 2, '');
            $cbSessId = (int)$cbSessId;

            $ackText = 'âœ… Done';

            if ($cbSessId) {
                $csRow = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
                $csRow->execute([$cbSessId]); $csRow = $csRow->fetch();

                if ($csRow) {
                    if ($cbAction === 'close_sess') {
                        if ($csRow['status'] === 'open') {
                            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$cbSessId]);
                            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi ditutup oleh Admin via Telegram.')")->execute([$cbSessId]);
                            if ($csRow['tg_thread_id']) {
                                tg_api($pdo, 'closeForumTopic', ['chat_id' => setting($pdo,'lc_tg_chat_id',''), 'message_thread_id' => (int)$csRow['tg_thread_id']]);
                            }
                            $ackText = 'ðŸ”’ Sesi ditutup!';
                        } else {
                            $ackText = 'âš ï¸ Sesi sudah ditutup.';
                        }
                    } elseif ($cbAction === 'mode_ai') {
                        $pdo->prepare("UPDATE chat_sessions SET mode='ai' WHERE id=?")->execute([$cbSessId]);
                        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','ðŸ”„ Mode beralih ke Asisten AI oleh Admin.')")->execute([$cbSessId]);
                        $ackText = 'ðŸ¤– Mode AI aktif';
                    } elseif ($cbAction === 'mode_admin') {
                        $pdo->prepare("UPDATE chat_sessions SET mode='admin' WHERE id=?")->execute([$cbSessId]);
                        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','ðŸ”„ Mode beralih ke Admin.')")->execute([$cbSessId]);
                        $ackText = 'ðŸ‘¨â€ðŸ’¼ Mode Admin aktif';
                    }
                }
            }

            // Jawab callback agar tombol tidak loading
            tg_api($pdo, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => $ackText,
                'show_alert'        => false,
            ]);
            echo '{}'; exit;
        }

        // â”€â”€ Handle regular message (admin reply) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if (empty($input['message'])) { echo '{}'; exit; }

        $msg       = $input['message'];
        $threadId  = $msg['message_thread_id'] ?? null;
        $text      = $msg['text'] ?? '';
        $fromUser  = $msg['from'] ?? [];

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

