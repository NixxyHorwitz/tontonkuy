<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
$user = require_auth($pdo);
$_favicon = setting($pdo, 'favicon_path', '');
$_seo_title = setting($pdo, 'seo_title', 'TontonKuy');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFE566">
<title>Live Chat — <?= htmlspecialchars($_seo_title) ?></title>
<?php
$_abs_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : base_url(ltrim($_favicon, '/'))) : '';
if ($_abs_fav): ?>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($_abs_fav) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body style="display:flex;flex-direction:column;height:100vh;overflow:hidden;align-items:normal;">
<!-- ── Custom Topbar (no navbar) ── -->
<header class="chat-topbar" id="chat-topbar">
  <a href="/home" class="chat-back-btn" title="Kembali ke Beranda">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
  </a>
  <div class="chat-topbar__info">
    <span class="chat-topbar__title">💬 Live Support</span>
    <span class="chat-topbar__sub" id="topbar-username"><?= htmlspecialchars($user['username']) ?></span>
  </div>
  <div class="chat-topbar__actions">
    <div class="chat-status-badge online" id="chat-status-badge" style="border:1.5px solid var(--ink);">Online</div>
  </div>
</header>

<style>
/* ═══════════════════════════════════════
   LIVECHAT — Neobrutalism
═══════════════════════════════════════ */
/* ── Custom topbar ──────────────────── */
.chat-topbar {
  height: 54px;
  background: var(--white);
  border-bottom: var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 12px;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 100;
}
.chat-back-btn {
  width: 36px; height: 36px;
  border: var(--border);
  border-radius: 10px;
  box-shadow: var(--shadow-sm);
  background: var(--white);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--ink);
  text-decoration: none;
  transition: transform .12s, box-shadow .12s;
}
.chat-back-btn:hover { transform: translate(-2px,-2px); box-shadow: 4px 4px 0 var(--ink); }
.chat-back-btn:active { transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--ink); }
.chat-topbar__info { flex: 1; min-width: 0; }
.chat-topbar__title { font-size: 14px; font-weight: 900; display: block; line-height: 1.2; }
.chat-topbar__sub   { font-size: 11px; color: #888; font-weight: 600; }
.chat-topbar__actions { flex-shrink: 0; }

.chat-page {
  display: flex;
  flex-direction: column;
  flex: 1;
  padding: 0;
  overflow: hidden;
  min-height: 0;
}

/* ── Mode switch bar ─────────────────── */
.chat-modebar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: var(--white);
  border-bottom: var(--border);
  flex-shrink: 0;
}
.chat-modebar__label {
  font-size: 11px;
  font-weight: 900;
  color: #888;
  text-transform: uppercase;
  letter-spacing: .5px;
  flex-shrink: 0;
}
.mode-pill {
  display: flex;
  background: #f0f0f0;
  border: var(--border);
  border-radius: 10px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  flex: 1;
}
.mode-btn {
  flex: 1;
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 12px;
  font-weight: 800;
  padding: 8px 6px;
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  color: #888;
  border-radius: 8px;
}
.mode-btn.active {
  background: var(--ink);
  color: #fff;
  box-shadow: 2px 2px 0 #555;
}
.mode-btn.active.mode-ai  { background: var(--lavender); color: var(--ink); }
.mode-btn.active.mode-adm { background: var(--mint);     color: var(--ink); }

/* Status badge */
.chat-status-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 900;
  padding: 4px 10px;
  border: 1.5px solid var(--ink);
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.chat-status-badge.online { background: var(--lime); }
.chat-status-badge.busy   { background: var(--peach); }
.chat-status-badge::before {
  content: '';
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--green);
  display: inline-block;
}
.chat-status-badge.busy::before { background: var(--orange); }

/* ── Messages area ───────────────────── */
.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: var(--bg);
  scroll-behavior: smooth;
}
.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

/* ── Bubble ──────────────────────────── */
.bubble-wrap {
  display: flex;
  flex-direction: column;
  max-width: 82%;
  animation: bubbleIn .2s ease;
}
@keyframes bubbleIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.bubble-wrap.user  { align-self: flex-end; align-items: flex-end; }
.bubble-wrap.other { align-self: flex-start; align-items: flex-start; }
.bubble-wrap.system{ align-self: center; align-items: center; }

.bubble {
  padding: 10px 14px;
  border-radius: 14px;
  border: 2px solid var(--ink);
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.55;
  word-break: break-word;
}
.bubble-wrap.user  .bubble { background: var(--yellow); box-shadow: 3px 3px 0 var(--ink); border-radius: 14px 14px 4px 14px; }
.bubble-wrap.ai    .bubble { background: var(--lavender); box-shadow: 3px 3px 0 var(--ink); border-radius: 14px 14px 14px 4px; }
.bubble-wrap.admin .bubble { background: var(--mint); box-shadow: 3px 3px 0 var(--ink); border-radius: 14px 14px 14px 4px; }
.bubble-wrap.system .bubble {
  background: #f5f5f5; color: #777;
  font-size: 11px; border-style: dashed;
  box-shadow: none; padding: 6px 14px;
  border-radius: 20px;
}
.bubble-meta {
  font-size: 10px;
  color: #aaa;
  font-weight: 700;
  margin-top: 3px;
  padding: 0 4px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.bubble-sender-tag {
  font-size: 10px;
  font-weight: 900;
  padding: 2px 8px;
  border-radius: 10px;
  border: 1.5px solid var(--ink);
  margin-bottom: 4px;
  display: inline-block;
}
.sender-ai    { background: var(--lavender); }
.sender-admin { background: var(--mint); }
.sender-user  { background: var(--yellow); }

/* ── Typing indicator ────────────────── */
.typing-bubble {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 12px 16px;
  background: var(--lavender);
  border: 2px solid var(--ink);
  border-radius: 14px 14px 14px 4px;
  box-shadow: 3px 3px 0 var(--ink);
  max-width: 80px;
}
.typing-dot {
  width: 8px; height: 8px;
  background: var(--ink);
  border-radius: 50%;
  animation: typingBounce 1.2s infinite;
}
.typing-dot:nth-child(2) { animation-delay: .2s; }
.typing-dot:nth-child(3) { animation-delay: .4s; }
@keyframes typingBounce {
  0%,60%,100% { transform: translateY(0); }
  30%          { transform: translateY(-6px); }
}

/* ── Input area ──────────────────────── */
.chat-inputbar {
  padding: 10px 12px;
  background: var(--white);
  border-top: var(--border);
  display: flex;
  gap: 8px;
  align-items: flex-end;
  flex-shrink: 0;
}
.chat-textarea {
  flex: 1;
  min-height: 42px;
  max-height: 120px;
  resize: none;
  border: var(--border);
  border-radius: 12px;
  box-shadow: 3px 3px 0 var(--ink);
  padding: 10px 13px;
  font-size: 13.5px;
  font-family: inherit;
  font-weight: 600;
  color: var(--ink);
  background: var(--white);
  outline: none;
  transition: box-shadow .15s;
  overflow-y: auto;
  line-height: 1.45;
}
.chat-textarea:focus { box-shadow: 5px 5px 0 var(--ink); }
.chat-textarea::placeholder { color: #bbb; }
.chat-send-btn {
  width: 44px; height: 44px;
  flex-shrink: 0;
  background: var(--brand);
  border: var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .12s, box-shadow .12s;
  color: #fff;
}
.chat-send-btn:hover { transform: translate(-2px,-2px); box-shadow: 5px 5px 0 var(--ink); }
.chat-send-btn:active { transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--ink); }
.chat-send-btn:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: var(--shadow-sm); }

/* ── Mode info banner ────────────────── */
.mode-info-banner {
  margin: 0 14px;
  padding: 9px 13px;
  border-radius: 10px;
  border: 2px dashed var(--ink);
  font-size: 11.5px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 7px;
  flex-shrink: 0;
}
.mode-info-banner.ai-mode  { background: var(--lavender); }
.mode-info-banner.adm-mode { background: var(--mint); }

/* ── Session start overlay ───────────── */
.chat-start-overlay {
  position: absolute;
  inset: 0;
  background: var(--bg);
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  overflow-y: auto;
}
.chat-start-card {
  width: 100%;
  background: var(--white);
  border: var(--border);
  border-radius: 20px;
  box-shadow: var(--shadow-lg);
  padding: 28px 22px;
  text-align: center;
}
.chat-start-icon {
  width: 70px; height: 70px;
  margin: 0 auto 14px;
  background: var(--yellow);
  border: var(--border);
  box-shadow: var(--shadow);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
}
.chat-start-title { font-size: 20px; font-weight: 900; margin-bottom: 6px; }
.chat-start-sub   { font-size: 13px; color: #666; font-weight: 600; margin-bottom: 20px; }

/* ── Closed overlay ──────────────────── */
.chat-closed-bar {
  padding: 12px 14px;
  background: var(--salmon);
  border-top: var(--border);
  text-align: center;
  font-size: 13px;
  font-weight: 800;
  flex-shrink: 0;
}
</style>

<div style="flex:1;position:relative;overflow:hidden;display:flex;flex-direction:column;min-height:0;">

  <!-- Start Overlay (shown until session created) -->
  <div class="chat-start-overlay" id="chat-start-overlay">
    <div class="chat-start-card">
      <div class="chat-start-icon">💬</div>
      <div class="chat-start-title">Live Support</div>
      <div class="chat-start-sub">Pilih mode dan mulai percakapan dengan kami sekarang.</div>

      <!-- Mode selector in start screen -->
      <div style="margin-bottom:18px;">
        <p style="font-size:12px;font-weight:800;color:#888;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Pilih Mode Chat</p>
        <div class="mode-pill" style="max-width:100%;box-shadow:var(--shadow);">
          <button class="mode-btn mode-ai active" id="start-mode-ai" onclick="selectStartMode('ai')">
            🤖 Asisten AI
          </button>
          <button class="mode-btn mode-adm" id="start-mode-admin" onclick="selectStartMode('admin')">
            👨‍💼 Admin
          </button>
        </div>
        <p id="start-mode-desc" style="font-size:11px;color:#888;margin-top:8px;font-weight:600;">
          AI akan menjawab pertanyaan Anda secara otomatis & instan.
        </p>
      </div>

      <button class="btn btn--primary btn--full btn--lg" id="btn-start-chat" onclick="startChat()">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        Mulai Chat
      </button>
    </div>
  </div>

  <!-- Chat UI (hidden until session created) -->
  <div class="chat-page" id="chat-ui" style="display:none;">

    <!-- Mode Bar -->
    <div class="chat-modebar">
      <span class="chat-modebar__label">Mode:</span>
      <div class="mode-pill">
        <button class="mode-btn mode-ai" id="modebtn-ai" onclick="switchMode('ai')">
          🤖 AI
        </button>
        <button class="mode-btn mode-adm" id="modebtn-admin" onclick="switchMode('admin')">
          👨‍💼 Admin
        </button>
      </div>
      <div class="chat-status-badge online" id="chat-status-badge">Online</div>
    </div>

    <!-- Mode info banner -->
    <div class="mode-info-banner ai-mode" id="mode-info-banner" style="margin-top:10px;">
      <span>🤖</span>
      <span id="mode-info-text">Mode AI aktif — Dijawab otomatis oleh Asisten AI.</span>
    </div>

    <!-- Messages -->
    <div class="chat-messages" id="chat-messages"></div>

    <!-- Typing indicator (hidden) -->
    <div id="typing-wrap" style="padding:0 14px 6px;display:none;">
      <div class="typing-bubble">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>
    </div>

    <!-- Input bar -->
    <div class="chat-inputbar" id="chat-inputbar">
      <textarea class="chat-textarea" id="chat-input"
        placeholder="Ketik pesan..." rows="1"
        onkeydown="handleKey(event)"
        oninput="autoResize(this)"
      ></textarea>
      <button class="chat-send-btn" id="chat-send-btn" onclick="sendMessage()">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>

    <!-- Closed bar (hidden until closed) -->
    <div class="chat-closed-bar" id="chat-closed-bar" style="display:none;">
      🔒 Sesi chat telah ditutup.
      <button onclick="resetChat()" style="background:var(--ink);color:#fff;border:none;border-radius:8px;padding:5px 14px;font-weight:800;font-size:12px;font-family:inherit;cursor:pointer;margin-left:10px;">Chat Baru</button>
    </div>
  </div>

</div>

<script>
/* ═══════════════════════════════════════
   LIVECHAT CLIENT
═══════════════════════════════════════ */
let sessionKey   = null;
let currentMode  = 'ai';
let startMode    = 'ai';
let lastMsgId    = 0;
let pollTimer    = null;
let sessionStatus = 'open';

// ── Mode selector on start screen ────────────────────────
function selectStartMode(mode) {
  startMode = mode;
  document.getElementById('start-mode-ai').classList.toggle('active', mode === 'ai');
  document.getElementById('start-mode-admin').classList.toggle('active', mode === 'admin');
  const desc = document.getElementById('start-mode-desc');
  if (mode === 'ai') {
    desc.textContent = 'AI akan menjawab pertanyaan Anda secara otomatis & instan.';
  } else {
    desc.textContent = 'Admin akan membalas pesan Anda langsung dari Telegram.';
  }
}

// ── Start session ─────────────────────────────────────────
async function startChat() {
  const btn = document.getElementById('btn-start-chat');
  btn.disabled = true;
  btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/></svg> Menghubungkan...';

  try {
    const fd = new FormData();
    const res = await fetch('/chat_action?action=start', { method: 'POST', body: fd, credentials: 'include' });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Gagal memulai sesi.');

    sessionKey    = data.session_key;
    currentMode   = data.mode;
    sessionStatus = data.status;

    // Show chat UI
    document.getElementById('chat-start-overlay').style.display = 'none';
    document.getElementById('chat-ui').style.display = 'flex';

    // Render existing messages
    const msgs = document.getElementById('chat-messages');
    msgs.innerHTML = '';
    (data.messages || []).forEach(m => appendBubble(m.sender, m.message, m.created_at, false));

    // If start mode differs from current, switch
    if (startMode !== currentMode) {
      await switchMode(startMode);
    } else {
      updateModeUI(currentMode);
    }

    // Track lastMsgId dari DB id yg dikembalikan server (cegah double render saat poll)
    if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);

    scrollBottom();
    startPolling();
    document.getElementById('chat-input').focus();

  } catch(e) {
    btn.disabled = false;
    btn.innerHTML = '💬 Mulai Chat';
    alert('❌ ' + e.message);
  }
}

// ── Append bubble ─────────────────────────────────────────
let _msgIdCounter = 0;
function appendBubble(sender, text, time, animate = true) {
  const msgs = document.getElementById('chat-messages');
  const wrap = document.createElement('div');
  const id   = ++_msgIdCounter;
  wrap.className   = `bubble-wrap ${sender === 'user' ? 'user' : sender === 'system' ? 'system' : sender}`;
  wrap.dataset.msgId = id;
  if (!animate) wrap.style.animation = 'none';

  const senderLabels = { ai: '🤖 AI', admin: '👨‍💼 Admin', user: '🙋 Kamu', system: '' };
  const senderClass  = { ai: 'sender-ai', admin: 'sender-admin', user: 'sender-user', system: '' };

  const timeStr = time ? new Date(time).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'}) : '';
  const label   = sender !== 'system' && sender !== 'user' ? `<div class="bubble-sender-tag ${senderClass[sender]||''}">${senderLabels[sender]||sender}</div>` : '';
  // AI dan admin pakai markdown, user dan system pakai plain text
  const bodyHtml = (sender === 'ai' || sender === 'admin') ? renderMarkdown(text) : (sender === 'system' ? escHtml(text) : escHtml(text) + '');

  wrap.innerHTML = `
    ${label}
    <div class="bubble">${bodyHtml}</div>
    ${sender !== 'system' ? `<div class="bubble-meta">${timeStr}</div>` : ''}
  `;
  msgs.appendChild(wrap);
  scrollBottom();
  return wrap;
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Markdown renderer (untuk bubble AI & Admin) ───────────
function renderMarkdown(text) {
  let s = escHtml(text);
  // Headings: # Title
  s = s.replace(/^###\s+(.+)$/gm, '<strong style="font-size:13px;">$1</strong>');
  s = s.replace(/^##\s+(.+)$/gm,  '<strong style="font-size:14px;">$1</strong>');
  s = s.replace(/^#\s+(.+)$/gm,   '<strong style="font-size:15px;">$1</strong>');
  // Bold: **text** or __text__
  s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/__(.+?)__/g,     '<strong>$1</strong>');
  // Italic: *text* or _text_
  s = s.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');
  s = s.replace(/_([^_\n]+?)_/g,   '<em>$1</em>');
  // Inline code
  s = s.replace(/`([^`]+?)`/g, '<code style="background:rgba(0,0,0,.1);padding:1px 5px;border-radius:4px;font-size:12px;font-family:monospace;">$1</code>');
  // Numbered list: 1. item
  s = s.replace(/^(\d+)\.\s+(.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0;"><span style="font-weight:800;min-width:16px;">$1.</span><span>$2</span></div>');
  // Bullet list: - item or * item
  s = s.replace(/^[-*]\s+(.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0;"><span style="font-weight:900;">•</span><span>$1</span></div>');
  // Newlines
  s = s.replace(/\n/g, '<br>');
  return s;
}

function scrollBottom() {
  const msgs = document.getElementById('chat-messages');
  msgs.scrollTop = msgs.scrollHeight;
}

// ── Send message ──────────────────────────────────────────
async function sendMessage() {
  if (sessionStatus === 'closed') return;
  const input = document.getElementById('chat-input');
  const text  = input.value.trim();
  if (!text) return;

  const sendBtn = document.getElementById('chat-send-btn');
  input.value = '';
  input.style.height = '';
  sendBtn.disabled = true;

  appendBubble('user', text, new Date().toISOString());

  // Show typing if AI mode
  if (currentMode === 'ai') showTyping(true);

  try {
    const fd = new FormData();
    fd.append('message', text);
    fd.append('session_key', sessionKey);
    const res  = await fetch('/chat_action?action=send', { method:'POST', body:fd, credentials:'include' });
    const data = await res.json();
    showTyping(false);
    if (!data.ok) throw new Error(data.error || 'Gagal mengirim.');
    // Update lastMsgId dari DB agar poll tidak re-render pesan yg sudah tampil
    if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);
    if (data.reply) {
      appendBubble(data.reply.sender, data.reply.message, data.reply.created_at);
    }
  } catch(e) {
    showTyping(false);
    appendBubble('system', '⚠️ ' + e.message, new Date().toISOString());
  }

  sendBtn.disabled = false;
  input.focus();
}

function showTyping(show) {
  document.getElementById('typing-wrap').style.display = show ? 'block' : 'none';
  scrollBottom();
}

// ── Switch mode ───────────────────────────────────────────
async function switchMode(mode) {
  if (mode === currentMode) return;
  try {
    const fd = new FormData();
    fd.append('mode', mode);
    fd.append('session_key', sessionKey);
    const res  = await fetch('/chat_action?action=switch_mode', { method:'POST', body:fd, credentials:'include' });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    currentMode = data.mode;
    updateModeUI(currentMode);
    // Update lastMsgId agar poll tidak re-render divider switch
    if (data.switch_msg_id) lastMsgId = parseInt(data.switch_msg_id);
    // Tampilkan divider visual mode switch
    if (data.switch_message) appendModeDivider(data.mode, data.switch_message);
  } catch(e) {
    alert('Gagal beralih mode: ' + e.message);
  }
}

// ── Mode divider (pemisah visual antar section) ────────
function appendModeDivider(mode, label) {
  const msgs  = document.getElementById('chat-messages');
  const el    = document.createElement('div');
  const color = mode === 'ai' ? 'var(--lavender)' : 'var(--mint)';
  el.style.cssText = 'display:flex;align-items:center;gap:8px;margin:10px 0;';
  el.innerHTML = `
    <div style="flex:1;height:2px;border-radius:2px;background:${color};border:1px solid var(--ink);"></div>
    <span style="font-size:10px;font-weight:900;background:${color};border:1.5px solid var(--ink);padding:3px 10px;border-radius:20px;white-space:nowrap;box-shadow:2px 2px 0 var(--ink);">${escHtml(label)}</span>
    <div style="flex:1;height:2px;border-radius:2px;background:${color};border:1px solid var(--ink);"></div>
  `;
  msgs.appendChild(el);
  scrollBottom();
}

function updateModeUI(mode) {
  const aiBtn  = document.getElementById('modebtn-ai');
  const admBtn = document.getElementById('modebtn-admin');
  aiBtn.classList.toggle('active', mode === 'ai');
  admBtn.classList.toggle('active', mode === 'admin');

  const banner = document.getElementById('mode-info-banner');
  const bannerText = document.getElementById('mode-info-text');
  if (mode === 'ai') {
    banner.className = 'mode-info-banner ai-mode';
    bannerText.innerHTML = '🤖 Mode AI aktif &mdash; Dijawab otomatis oleh Asisten AI.';
  } else {
    banner.className = 'mode-info-banner adm-mode';
    bannerText.innerHTML = '👨‍💼 Mode Admin aktif &mdash; Menunggu balasan dari tim kami via Telegram.';
  }
}

// ── Polling (near-realtime, 2s interval) ─────────────────
function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(pollMessages, 2000);
}

// Pause saat tab tidak aktif, resume saat aktif (hemat request)
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    clearInterval(pollTimer);
  } else if (sessionKey && sessionStatus !== 'closed') {
    pollMessages();      // langsung poll saat balik ke tab
    startPolling();
  }
});

async function pollMessages() {
  if (!sessionKey || sessionStatus === 'closed') return;
  try {
    const res  = await fetch(`/chat_action?action=poll&session_key=${sessionKey}&after_id=${lastMsgId}`, { credentials:'include' });
    const data = await res.json();
    if (!data.ok) return;

    (data.messages || []).forEach(m => {
      if (parseInt(m.id) > lastMsgId) {
        lastMsgId = parseInt(m.id);
        appendBubble(m.sender, m.message, m.created_at);
      }
    });

    if (data.status === 'closed' && sessionStatus !== 'closed') {
      sessionStatus = 'closed';
      onSessionClosed();
    }
    if (data.mode && data.mode !== currentMode) {
      currentMode = data.mode;
      updateModeUI(currentMode);
    }
  } catch {}
}

function onSessionClosed() {
  clearInterval(pollTimer);
  document.getElementById('chat-inputbar').style.display = 'none';
  document.getElementById('chat-closed-bar').style.display = 'block';
  document.getElementById('chat-status-badge').className = 'chat-status-badge busy';
  document.getElementById('chat-status-badge').textContent = 'Ditutup';
}

// ── Reset session ─────────────────────────────────────────
async function resetChat() {
  await fetch('/chat_action?action=close', { method:'POST', credentials:'include' });
  document.cookie = 'chat_session=; max-age=0; path=/';
  location.reload();
}

// ── Auto-resize textarea ──────────────────────────────────
function autoResize(el) {
  el.style.height = '';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

// ── Init: check for existing session cookie ───────────────
(function() {
  const hasCookie = document.cookie.split(';').some(c => c.trim().startsWith('chat_session='));
  if (hasCookie) {
    // Auto-load existing session
    startChat();
  }
})();
</script>

<style>
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>

<script src="/assets/js/toast.js"></script>
</body>
</html>
