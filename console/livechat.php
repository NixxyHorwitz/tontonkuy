<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';
$pageTitle  = 'Live Chat';
$activePage = 'livechat';

// ── Handle form saves ────────────────────────────────────────
$saved = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = $_POST['tab'] ?? 'settings';

    if ($tab === 'settings') {
        $keys = [
            'tg_bot_token','tg_chat_id','tg_group_is_forum',
            'openai_api_key','openai_model','ai_system_prompt',
            'chat_welcome_msg','chat_ai_enabled','chat_admin_enabled',
        ];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$k, $v, $v]);
        }
        $saved = true;
    }

    // Close a session
    if ($tab === 'close_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sid]);
            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi ditutup oleh Admin.')")->execute([$sid]);
        }
    }
}

// ── Load settings ─────────────────────────────────────────────
$cfg = [];
foreach ([
    'tg_bot_token','tg_chat_id','tg_group_is_forum',
    'openai_api_key','openai_model','ai_system_prompt',
    'chat_welcome_msg','chat_ai_enabled','chat_admin_enabled',
] as $k) { $cfg[$k] = setting($pdo, $k, ''); }

// ── Load sessions ─────────────────────────────────────────────
$sessions = $pdo->query(
    "SELECT s.*, 
        (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id=s.id) as msg_count,
        (SELECT message FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) as last_msg
     FROM chat_sessions s ORDER BY s.last_message_at DESC LIMIT 60"
)->fetchAll();

// ── Active session detail ──────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$viewMsgs = [];
$viewSess = null;
if ($viewId) {
    $vs = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $vs->execute([$viewId]);
    $viewSess = $vs->fetch() ?: null;
    if ($viewSess) {
        $vm = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id=? ORDER BY id ASC");
        $vm->execute([$viewId]);
        $viewMsgs = $vm->fetchAll();
    }
}

// ── Admin reply from console ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'reply') {
    $sid  = (int)($_POST['session_id'] ?? 0);
    $msg  = trim($_POST['reply_msg'] ?? '');
    if ($sid && $msg) {
        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'admin',?)")
            ->execute([$sid, '[Admin Console] ' . $msg]);
        // Kirim ke Telegram
        $sess = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
        $sess->execute([$sid]);
        $sessRow = $sess->fetch();
        $token = setting($pdo, 'tg_bot_token', '');
        $chatId = setting($pdo, 'tg_chat_id', '');
        if ($token && $chatId && $sessRow && $sessRow['tg_thread_id']) {
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'chat_id' => $chatId,
                    'message_thread_id' => (int)$sessRow['tg_thread_id'],
                    'text' => "🖥️ *Admin Console:* " . $msg,
                    'parse_mode' => 'Markdown',
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            curl_exec($ch); curl_close($ch);
        }
        header("Location: /console/livechat.php?view={$sid}&replied=1"); exit;
    }
}

require_once __DIR__ . '/partials/header.php';
?>

<style>
.lc-tabs { display:flex; gap:4px; border-bottom:1px solid #1f2235; margin-bottom:20px; }
.lc-tab  { padding:10px 18px; font-size:13px; font-weight:600; color:#666; cursor:pointer; border-bottom:2px solid transparent; text-decoration:none; }
.lc-tab.active { color:var(--brand); border-bottom-color:var(--brand); }

.sess-row { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid #1a1d27; }
.sess-row:last-child { border-bottom:none; }
.sess-avatar { width:36px;height:36px;border-radius:50%;background:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;flex-shrink:0; }
.sess-body { flex:1;min-width:0; }
.sess-name { font-size:13.5px;font-weight:700;color:#e0e0f0; }
.sess-last { font-size:12px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px; }
.sess-right { text-align:right;flex-shrink:0; }
.sess-badge { display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px; }
.sess-badge.open   { background:rgba(76,175,130,.2);color:#4CAF82; }
.sess-badge.closed { background:rgba(255,255,255,.07);color:#555; }
.sess-mode { font-size:10px;color:#555;margin-top:3px; }

.msg-bubble { max-width:75%;padding:9px 13px;border-radius:12px;font-size:13px;line-height:1.5;word-break:break-word; }
.msg-user  .msg-bubble { background:#2a2d3e;color:#ddd; border-radius:12px 12px 4px 12px; }
.msg-ai    .msg-bubble { background:rgba(196,181,253,.15);color:#c4b5fd; border-radius:12px 12px 12px 4px; }
.msg-admin .msg-bubble { background:rgba(168,240,220,.12);color:#a8f0dc; border-radius:12px 12px 12px 4px; }
.msg-system .msg-bubble { background:transparent;color:#555;font-size:11px;font-style:italic;text-align:center; }
.msg-row { display:flex;margin-bottom:8px; }
.msg-row.msg-user  { justify-content:flex-end; }
.msg-row.msg-system{ justify-content:center; }
.msg-time { font-size:10px;color:#444;margin-top:3px;text-align:right; }

.detail-panel { background:#131520;border:1px solid #1f2235;border-radius:12px;overflow:hidden; }
.detail-header { padding:14px 18px;border-bottom:1px solid #1f2235;display:flex;align-items:center;gap:10px; }
.detail-msgs { padding:14px;max-height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:2px; }
.detail-msgs::-webkit-scrollbar{width:4px} .detail-msgs::-webkit-scrollbar-thumb{background:#2a2d3e;border-radius:4px;}
.detail-reply { padding:14px;border-top:1px solid #1f2235; }

.webhook-url { background:#0f1117;border:1px solid #1f2235;border-radius:8px;padding:10px 14px;font-size:12px;font-family:monospace;color:#a8f0dc;word-break:break-all; }
</style>

<?php if ($saved): ?>
<div class="alert alert-success mb-3" style="background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.3);color:#4CAF82;padding:10px 16px;border-radius:8px;font-size:13px;">
  ✅ Pengaturan berhasil disimpan.
</div>
<?php endif; ?>
<?php if (!empty($_GET['replied'])): ?>
<div class="alert alert-success mb-3" style="background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.3);color:#4CAF82;padding:10px 16px;border-radius:8px;font-size:13px;">
  ✅ Balasan berhasil dikirim.
</div>
<?php endif; ?>

<div class="lc-tabs">
  <a href="/console/livechat.php" class="lc-tab <?= !$viewId && ($_GET['t']??'sessions')==='sessions' ? 'active':'' ?>">💬 Sesi Chat</a>
  <a href="/console/livechat.php?t=settings" class="lc-tab <?= ($_GET['t']??'')==='settings' ? 'active':'' ?>">⚙️ Pengaturan</a>
  <a href="/console/livechat.php?t=webhook" class="lc-tab <?= ($_GET['t']??'')==='webhook' ? 'active':'' ?>">🔗 Webhook Info</a>
</div>

<?php if (($viewId && $viewSess) || false): /* DETAIL VIEW */ ?>
<!-- handled below -->
<?php endif; ?>

<?php $activeTab = $_GET['t'] ?? ($viewId ? 'view' : 'sessions'); ?>

<!-- ═══ TAB: SESSIONS ══════════════════════════════════════ -->
<?php if ($activeTab === 'sessions' || $viewId): ?>
<div class="row g-3">
  <!-- Session list -->
  <div class="<?= $viewId ? 'col-lg-4' : 'col-12' ?>">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">Sesi Chat</span>
        <span style="font-size:12px;color:#555;"><?= count($sessions) ?> sesi</span>
      </div>
      <div class="c-card-body" style="padding:0 20px;">
        <?php if (empty($sessions)): ?>
          <p style="color:#555;font-size:13px;padding:20px 0;text-align:center;">Belum ada sesi chat.</p>
        <?php else: ?>
          <?php foreach ($sessions as $s): ?>
          <div class="sess-row">
            <div class="sess-avatar"><?= strtoupper(substr($s['user_name'],0,1)) ?></div>
            <div class="sess-body">
              <div class="sess-name"><?= htmlspecialchars($s['user_name']) ?>
                <?php if ($s['user_email']): ?><span style="color:#555;font-size:11px;font-weight:400;"> — <?= htmlspecialchars($s['user_email']) ?></span><?php endif; ?>
              </div>
              <div class="sess-last"><?= htmlspecialchars(mb_substr($s['last_msg']??'(kosong)',0,60)) ?></div>
            </div>
            <div class="sess-right">
              <span class="sess-badge <?= $s['status'] ?>"><?= $s['status'] ?></span>
              <div class="sess-mode">🤖 <?= $s['mode'] ?> · <?= $s['msg_count'] ?> pesan</div>
              <div style="margin-top:5px;display:flex;gap:4px;justify-content:flex-end;">
                <a href="/console/livechat.php?view=<?= $s['id'] ?>" class="btn btn-sm" style="background:#1f2235;border:1px solid #2a2d3e;color:#ccc;padding:3px 10px;font-size:11px;border-radius:6px;text-decoration:none;">Detail</a>
                <?php if ($s['status']==='open'): ?>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="tab" value="close_session">
                  <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                  <button type="submit" onclick="return confirm('Tutup sesi ini?')" style="background:rgba(244,78,59,.15);border:1px solid rgba(244,78,59,.3);color:#F44E3B;padding:3px 10px;font-size:11px;border-radius:6px;cursor:pointer;">Tutup</button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Detail panel -->
  <?php if ($viewId && $viewSess): ?>
  <div class="col-lg-8">
    <div class="detail-panel">
      <div class="detail-header">
        <div class="sess-avatar"><?= strtoupper(substr($viewSess['user_name'],0,1)) ?></div>
        <div style="flex:1;">
          <div style="font-weight:700;font-size:14px;color:#e0e0f0;"><?= htmlspecialchars($viewSess['user_name']) ?></div>
          <div style="font-size:11px;color:#555;">
            <?= htmlspecialchars($viewSess['user_email']??'-') ?> &nbsp;·&nbsp;
            Mode: <strong style="color:#a8f0dc"><?= $viewSess['mode'] ?></strong> &nbsp;·&nbsp;
            Status: <strong style="color:<?= $viewSess['status']==='open'?'#4CAF82':'#555' ?>"><?= $viewSess['status'] ?></strong>
          </div>
        </div>
        <a href="/console/livechat.php" style="color:#555;font-size:20px;text-decoration:none;line-height:1;">×</a>
      </div>

      <div class="detail-msgs" id="detail-msgs">
        <?php foreach ($viewMsgs as $m): ?>
        <div class="msg-row msg-<?= $m['sender'] ?>">
          <div>
            <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
            <div class="msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($viewSess['status']==='open'): ?>
      <div class="detail-reply">
        <form method="post">
          <input type="hidden" name="tab" value="reply">
          <input type="hidden" name="session_id" value="<?= $viewId ?>">
          <div style="display:flex;gap:8px;align-items:flex-end;">
            <textarea name="reply_msg" class="c-form-control" rows="2" placeholder="Ketik balasan..." style="flex:1;resize:none;" required></textarea>
            <button type="submit" style="background:var(--brand);border:none;color:#fff;padding:10px 18px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;height:fit-content;">Kirim</button>
          </div>
          <p style="font-size:11px;color:#444;margin-top:5px;">💡 Balasan juga akan dikirim ke thread Telegram.</p>
        </form>
      </div>
      <?php else: ?>
      <div style="padding:12px 18px;color:#555;font-size:12px;text-align:center;">🔒 Sesi sudah ditutup.</div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    const dm = document.getElementById('detail-msgs');
    if (dm) dm.scrollTop = dm.scrollHeight;
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>


<!-- ═══ TAB: SETTINGS ══════════════════════════════════════ -->
<?php if ($activeTab === 'settings'): ?>
<form method="post">
  <input type="hidden" name="tab" value="settings">
  <div class="row g-3">

    <!-- Telegram -->
    <div class="col-md-6">
      <div class="c-card h-100">
        <div class="c-card-header"><span class="c-card-title">🤖 Telegram Bot</span></div>
        <div class="c-card-body">
          <div class="c-form-group">
            <label class="c-label">Bot Token</label>
            <input type="text" name="tg_bot_token" class="c-form-control" value="<?= htmlspecialchars($cfg['tg_bot_token']) ?>" placeholder="1234567890:AAH...">
            <small style="color:#444;font-size:11px;">Dari @BotFather di Telegram.</small>
          </div>
          <div class="c-form-group">
            <label class="c-label">Group / Chat ID</label>
            <input type="text" name="tg_chat_id" class="c-form-control" value="<?= htmlspecialchars($cfg['tg_chat_id']) ?>" placeholder="-100123456789">
            <small style="color:#444;font-size:11px;">ID Supergroup forum tempat thread dibuat. Format: -100xxx</small>
          </div>
          <div class="c-form-group">
            <label class="c-label">Tipe Grup</label>
            <select name="tg_group_is_forum" class="c-form-control">
              <option value="1" <?= $cfg['tg_group_is_forum']==='1'?'selected':'' ?>>Forum / Supergroup (pakai Topics/Thread)</option>
              <option value="0" <?= $cfg['tg_group_is_forum']==='0'?'selected':'' ?>>Grup biasa (tanpa thread)</option>
            </select>
          </div>
          <div class="c-form-group">
            <label class="c-label">Pesan Sambutan</label>
            <textarea name="chat_welcome_msg" class="c-form-control" rows="2"><?= htmlspecialchars($cfg['chat_welcome_msg']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- OpenAI -->
    <div class="col-md-6">
      <div class="c-card h-100">
        <div class="c-card-header"><span class="c-card-title">✨ OpenAI (Mode AI)</span></div>
        <div class="c-card-body">
          <div class="c-form-group">
            <label class="c-label">OpenAI API Key</label>
            <input type="password" name="openai_api_key" class="c-form-control" value="<?= htmlspecialchars($cfg['openai_api_key']) ?>" placeholder="sk-...">
          </div>
          <div class="c-form-group">
            <label class="c-label">Model</label>
            <select name="openai_model" class="c-form-control">
              <?php foreach (['gpt-4o-mini','gpt-4o','gpt-3.5-turbo'] as $m): ?>
              <option value="<?= $m ?>" <?= $cfg['openai_model']===$m?'selected':'' ?>><?= $m ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="c-form-group">
            <label class="c-label">System Prompt AI</label>
            <textarea name="ai_system_prompt" class="c-form-control" rows="5"><?= htmlspecialchars($cfg['ai_system_prompt']) ?></textarea>
            <small style="color:#444;font-size:11px;">Instruksi untuk AI tentang cara menjawab.</small>
          </div>
          <div style="display:flex;gap:20px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#888;cursor:pointer;">
              <input type="checkbox" name="chat_ai_enabled" value="1" <?= $cfg['chat_ai_enabled']==='1'?'checked':'' ?>>
              Aktifkan Mode AI
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#888;cursor:pointer;">
              <input type="checkbox" name="chat_admin_enabled" value="1" <?= $cfg['chat_admin_enabled']==='1'?'checked':'' ?>>
              Aktifkan Mode Admin
            </label>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 text-end">
      <button type="submit" style="background:var(--brand);border:none;color:#fff;padding:10px 28px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
        💾 Simpan Pengaturan
      </button>
    </div>
  </div>
</form>
<?php endif; ?>


<!-- ═══ TAB: WEBHOOK ══════════════════════════════════════ -->
<?php if ($activeTab === 'webhook'): ?>
<?php
  $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
  $host       = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
  $webhookUrl = $scheme . '://' . $host . '/chat_action?action=tg_webhook';
  $botToken   = $cfg['tg_bot_token'];
  $setWebhookUrl = $botToken
    ? "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($webhookUrl)
    : '';
?>
<div class="row g-3">
  <div class="col-md-7">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">🔗 Setup Telegram Webhook</span></div>
      <div class="c-card-body">
        <p style="font-size:13px;color:#888;margin-bottom:16px;">
          Agar balasan admin dari Telegram masuk ke chat user, set webhook ini ke bot kamu.
        </p>

        <div class="c-form-group">
          <label class="c-label">URL Webhook kamu</label>
          <div class="webhook-url"><?= htmlspecialchars($webhookUrl) ?></div>
        </div>

        <?php if ($setWebhookUrl): ?>
        <div class="c-form-group">
          <label class="c-label">Set Webhook Otomatis (klik link ini di browser)</label>
          <div class="webhook-url">
            <a href="<?= htmlspecialchars($setWebhookUrl) ?>" target="_blank" style="color:#a8f0dc;word-break:break-all;">
              <?= htmlspecialchars($setWebhookUrl) ?>
            </a>
          </div>
        </div>
        <a href="<?= htmlspecialchars($setWebhookUrl) ?>" target="_blank"
           style="display:inline-block;background:var(--brand);color:#fff;padding:9px 20px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;margin-top:4px;">
          🚀 Set Webhook Sekarang
        </a>
        <?php else: ?>
        <div style="background:rgba(242,153,0,.1);border:1px solid rgba(242,153,0,.3);color:#F29900;padding:10px 14px;border-radius:8px;font-size:12px;">
          ⚠️ Isi Bot Token di tab Pengaturan dulu.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">📋 Cara Kerja</span></div>
      <div class="c-card-body">
        <ol style="font-size:13px;color:#888;line-height:2;padding-left:18px;margin:0;">
          <li>Buat bot via <strong style="color:#ccc;">@BotFather</strong></li>
          <li>Buat Supergroup &amp; aktifkan <strong style="color:#ccc;">Topics</strong></li>
          <li>Tambahkan bot ke grup sebagai admin</li>
          <li>Isi <strong style="color:#ccc;">Bot Token</strong> &amp; <strong style="color:#ccc;">Chat ID</strong></li>
          <li>Set webhook dengan tombol di kiri</li>
          <li>Setiap sesi chat baru = 1 Thread baru di grup</li>
          <li>Balas thread di Telegram → pesan masuk ke chat user</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
