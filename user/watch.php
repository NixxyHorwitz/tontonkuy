<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$vid_id = (int)($_GET['id'] ?? 0);
if (!$vid_id) redirect('/videos');

$vs = $pdo->prepare("SELECT * FROM videos WHERE id=? AND is_active=1");
$vs->execute([$vid_id]);
$video = $vs->fetch();
if (!$video) redirect('/videos');

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

$chk = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
$chk->execute([$user['id'], $vid_id]);
$already_watched = (bool)$chk->fetch();
$canWatch = !$already_watched && $watch_today < $watch_limit;

// ─────────────────────────────────────────────────────────────
// AJAX: start_watch — server issues a signed token with timestamp
// Client harus call ini dulu sebelum bisa claim.
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_watch') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF']); exit; }
    if (!$canWatch)     { echo json_encode(['ok'=>false,'msg'=>'Tidak bisa menonton.']); exit; }

    $ts    = time();
    $secret = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';
    $sig   = hash_hmac('sha256', $user['id'] . '|' . $vid_id . '|' . $ts, $secret);
    $token = base64_encode($user['id'] . '|' . $vid_id . '|' . $ts . '|' . $sig);

    echo json_encode(['ok'=>true,'watch_token'=>$token]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: claim reward — wajib sertakan watch_token
// Server validasi: signature benar + waktu sudah cukup
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF']); exit; }

    // Reload state langsung dari DB (tidak percaya client-side state)
    $chk2 = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
    $chk2->execute([$user['id'], $vid_id]);
    if ($chk2->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Sudah ditonton hari ini.']); exit; }

    $wt = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
    $wt->execute([$user['id']]);
    if ((int)$wt->fetchColumn() >= $watch_limit) {
        echo json_encode(['ok'=>false,'msg'=>'Limit tonton habis!']); exit;
    }

    // Verifikasi watch_token
    $raw_token = $_POST['watch_token'] ?? '';
    if (empty($raw_token)) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak valid. Tonton video dari awal.']); exit;
    }

    $decoded = base64_decode($raw_token, true);
    if ($decoded === false || substr_count($decoded, '|') < 3) {
        echo json_encode(['ok'=>false,'msg'=>'Token rusak.']); exit;
    }

    [$tok_uid, $tok_vid, $tok_ts, $tok_sig] = explode('|', $decoded, 4);
    $secret  = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';
    $expected= hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts, $secret);

    // Validasi identitas
    if ((int)$tok_uid !== (int)$user['id'] || (int)$tok_vid !== $vid_id) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak cocok.']); exit;
    }
    // Validasi signature
    if (!hash_equals($expected, $tok_sig)) {
        echo json_encode(['ok'=>false,'msg'=>'Signature tidak valid.']); exit;
    }
    // Validasi waktu — harus sudah tonton minimal watch_duration detik
    $elapsed = time() - (int)$tok_ts;
    $required = (int)$video['watch_duration'];
    if ($elapsed < $required) {
        $kurang = $required - $elapsed;
        echo json_encode(['ok'=>false,'msg'=>"Belum cukup waktu. Tunggu {$kurang} detik lagi."]); exit;
    }
    // Token tidak boleh terlalu lama (maks 3× durasi, untuk toleransi pause)
    if ($elapsed > $required * 4 + 300) {
        echo json_encode(['ok'=>false,'msg'=>'Token sudah kedaluwarsa. Refresh dan coba lagi.']); exit;
    }

    $reward = (float)$video['reward_amount'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO watch_history (user_id,video_id,reward_given) VALUES (?,?,?)")
            ->execute([$user['id'], $vid_id, $reward]);
        $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE id=?")
            ->execute([$reward, $reward, $user['id']]);
        $pdo->prepare("UPDATE videos SET total_watches=total_watches+1 WHERE id=?")
            ->execute([$vid_id]);
        $pdo->commit();
        echo json_encode(['ok'=>true,'reward'=>format_rp($reward),'msg'=>'+'.format_rp($reward).' berhasil!']);
    } catch (\Throwable) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kesalahan server.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($video['title']) ?> — TontonKuy</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* ── Watch page overrides ── */
.watch-topbar {
  position:sticky;top:0;z-index:100;
  background:#fff;border-bottom:2.5px solid #1A1A1A;
  padding:0 16px;height:52px;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.back-btn {
  display:flex;align-items:center;gap:6px;
  color:#1A1A1A;text-decoration:none;font-weight:800;font-size:14px;
}
/* Clean video wrapper — NO overlays */
.yt-wrapper {
  position:relative;
  background:#000;
  aspect-ratio:16/9;
  width:100%;
}
.yt-wrapper iframe {
  position:absolute;inset:0;
  width:100%;height:100%;
  border:none;
}
/* Timer bar — below the video, not ON it */
.watch-progress-bar {
  height:6px;
  background:#e0e0e0;
  border-bottom:2px solid #1A1A1A;
  overflow:hidden;
}
.watch-progress-fill {
  height:100%;width:0%;
  background:var(--mint);
  transition:width 1s linear;
}
.watch-progress-fill.done { background:#22C55E; }

.watch-status {
  background:#fff;
  border-bottom:2.5px solid #1A1A1A;
  padding:10px 16px;
  display:flex;align-items:center;justify-content:space-between;gap:8px;
  font-size:13px;font-weight:800;
}
.watch-status__timer {
  display:flex;align-items:center;gap:8px;
}
.timer-badge {
  width:38px;height:38px;
  border-radius:50%;
  border:2.5px solid #1A1A1A;
  box-shadow:2px 2px 0 #1A1A1A;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:900;
  background:var(--yellow);
  flex-shrink:0;
}
.watch-status__hint { color:#666;font-size:12px; }
</style>
</head>
<body>
<div class="app-shell">

  <!-- Topbar -->
  <div class="watch-topbar">
    <a href="/videos" class="back-btn">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Kembali
    </a>
    <div class="topbar__balance" title="Saldo WD"><?= format_rp((float)$user['balance_wd']) ?></div>
  </div>

  <!-- YouTube player — BERSIH, tanpa overlay apapun -->
  <div class="yt-wrapper">
    <div id="yt-player"></div>
  </div>

  <!-- Progress bar di bawah video -->
  <div class="watch-progress-bar">
    <div class="watch-progress-fill" id="prog-fill"></div>
  </div>

  <!-- Status bar -->
  <div class="watch-status" id="status-bar">
    <div class="watch-status__timer">
      <div class="timer-badge" id="timer-badge">
        <?php if ($canWatch): ?>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?php else: ?>–<?php endif; ?>
      </div>
      <div>
        <div id="status-text">
          <?php if ($already_watched): ?>✅ Sudah ditonton hari ini
          <?php elseif ($watch_today >= $watch_limit): ?>⚠️ Limit tonton habis
          <?php else: ?>▶️ Putar video untuk mulai hitung waktu<?php endif; ?>
        </div>
        <div class="watch-status__hint" id="status-hint">
          <?php if ($canWatch): ?>Reward: <?= format_rp((float)$video['reward_amount']) ?> setelah <?= $video['watch_duration'] ?>s<?php endif; ?>
        </div>
      </div>
    </div>
    <?php if (!$already_watched && $watch_today < $watch_limit): ?>
    <div id="claim-wrap" style="display:none">
      <button id="claim-btn" class="btn btn--green btn--sm" onclick="claimReward()">
        🎁 Klaim
      </button>
    </div>
    <?php elseif ($watch_today >= $watch_limit): ?>
    <a href="/upgrade" class="btn btn--primary btn--sm">👑 Upgrade</a>
    <?php endif; ?>
  </div>

  <!-- Video info -->
  <div style="padding:16px">
    <h1 style="font-size:16px;font-weight:800;line-height:1.45;margin-bottom:10px"><?= htmlspecialchars($video['title']) ?></h1>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <span class="badge badge--brand">🎁 <?= format_rp((float)$video['reward_amount']) ?></span>
      <span class="badge badge--neutral">⏱ <?= $video['watch_duration'] ?>s minimum</span>
      <span class="badge badge--neutral">👁 <?= number_format($video['total_watches']) ?>× ditonton</span>
    </div>
    <?php if ($already_watched): ?>
    <div class="alert alert--success" style="margin-top:12px">✅ Kamu sudah menonton dan menerima reward hari ini!</div>
    <a href="/videos" class="btn btn--ghost btn--full" style="margin-top:8px">← Lihat Video Lain</a>
    <?php endif; ?>
  </div>
</div>

<!-- Reward popup -->
<div class="reward-popup" id="reward-popup"></div>

<script>
// ── Konstanta dari server ────────────────────────────
const DURATION  = <?= (int)$video['watch_duration'] ?>;
const CAN_WATCH = <?= $canWatch ? 'true' : 'false' ?>;
const CSRF      = '<?= csrf_token() ?>';
const WATCH_URL = '';   // POST ke halaman ini sendiri

// ── State ────────────────────────────────────────────
let watchToken  = null;   // diisi saat server OK start_watch
let timerLeft   = DURATION;
let timerHandle = null;
let watchStarted= false;
let claimReady  = false;
let playerReady = false;
let ytPlayer    = null;

// ── YouTube IFrame API ───────────────────────────────
window.onYouTubeIframeAPIReady = function() {
  console.log('[DEBUG] onYouTubeIframeAPIReady fired. Init player with videoId: <?= htmlspecialchars($video['youtube_id']) ?>');
  ytPlayer = new YT.Player('yt-player', {
    videoId: '<?= htmlspecialchars($video['youtube_id']) ?>',
    playerVars: {
      rel: 0,
      modestbranding: 1,
      playsinline: 1,
      autoplay: 1,
      origin: window.location.origin
    },
    events: {
      onReady: function(e) {
          console.log('[DEBUG] Player onReady');
          onPlayerReady(e);
      },
      onStateChange: function(e) {
          console.log('[DEBUG] Player onStateChange, state:', e.data);
          onPlayerStateChange(e);
      },
      onError: function(e) {
          console.log('[DEBUG] Player onError, error code:', e.data);
          setStatus('⚠️ YouTube Error: ' + e.data, 'Video tidak dapat diputar. ID: <?= htmlspecialchars($video['youtube_id']) ?>');
      }
    }
  });
};

// Load API script
console.log('[DEBUG] Injecting YouTube iframe_api script');
const tag = document.createElement('script');
tag.src = 'https://www.youtube.com/iframe_api';
document.head.appendChild(tag);

function onPlayerReady(e) {
  playerReady = true;
  console.log('[DEBUG] Player is actually ready now.');
}

function onPlayerStateChange(e) {
  console.log('[DEBUG] onPlayerStateChange logic triggered. State=', e.data, 'CAN_WATCH=', CAN_WATCH);
  if (!CAN_WATCH) {
      console.log('[DEBUG] CAN_WATCH is false, ignoring state change.');
      return;
  }

  if (e.data === YT.PlayerState.PLAYING) {
    console.log('[DEBUG] Video is PLAYING.');
    if (!watchStarted) {
      console.log('[DEBUG] First time playing, calling startWatchSession().');
      startWatchSession();
    } else if (timerHandle === null && !claimReady) {
      console.log('[DEBUG] Resuming countdown.');
      resumeCountdown();
    }
  } else if (e.data === YT.PlayerState.PAUSED ||
             e.data === YT.PlayerState.BUFFERING) {
    console.log('[DEBUG] Video paused or buffering. Calling pauseCountdown().');
    pauseCountdown();
  } else if (e.data === YT.PlayerState.ENDED) {
    console.log('[DEBUG] Video ended.');
  }
}

// ── Server: minta watch token ────────────────────────
async function startWatchSession() {
  const fd = new FormData();
  fd.append('action', 'start_watch');
  fd.append('_csrf', CSRF);
  const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
  const data = await res.json();
  if (!data.ok) {
    setStatus('⚠️ ' + data.msg, '');
    return;
  }
  watchToken   = data.watch_token;
  watchStarted = true;
  timerLeft    = DURATION;
  startCountdown();
}

// ── Countdown ────────────────────────────────────────
function startCountdown() {
  updateTimerUI();
  timerHandle = setInterval(() => {
    timerLeft--;
    updateTimerUI();
    if (timerLeft <= 0) {
      clearInterval(timerHandle);
      timerHandle = null;
      claimReady  = true;
      showClaimButton();
    }
  }, 1000);
}

function pauseCountdown() {
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  if (!claimReady) setStatus('⏸ Video dijeda — lanjutkan untuk hitung waktu', '');
}

function resumeCountdown() {
  startCountdown();
}

function updateTimerUI() {
  const badge = document.getElementById('timer-badge');
  const fill  = document.getElementById('prog-fill');
  const pct   = Math.min(100, ((DURATION - timerLeft) / DURATION) * 100);

  badge.textContent   = timerLeft > 0 ? timerLeft : '✓';
  fill.style.width    = pct + '%';
  fill.style.transition = 'width 1s linear';
  if (timerLeft <= 0) fill.classList.add('done');

  setStatus(
    timerLeft > 0 ? `⏱ ${timerLeft}s lagi untuk klaim reward` : '🎉 Reward siap diklaim!',
    timerLeft > 0 ? `Jangan pause video` : ''
  );
}

function showClaimButton() {
  const w = document.getElementById('claim-wrap');
  if (w) { w.style.display = 'block'; }
  document.getElementById('prog-fill').classList.add('done');
}

function setStatus(text, hint) {
  const el = document.getElementById('status-text');
  const eh = document.getElementById('status-hint');
  if (el) el.textContent = text;
  if (eh) eh.textContent = hint;
}

// ── Claim reward ─────────────────────────────────────
async function claimReward() {
  if (!watchToken) {
    nToast('Token tidak ditemukan. Putar video dari awal.', 'error');
    return;
  }
  const btn = document.getElementById('claim-btn');
  btn.disabled = true;
  btn.textContent = '⏳...';

  const fd = new FormData();
  fd.append('action', 'claim');
  fd.append('_csrf', CSRF);
  fd.append('watch_token', watchToken);

  const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
  const data = await res.json();

  if (data.ok) {
    showPop('🎉 ' + data.msg);
    btn.textContent = '✅ Reward Diterima!';
    document.getElementById('prog-fill').classList.add('done');
    setStatus('✅ Reward berhasil! Mengalihkan...', '');
    setTimeout(() => location.href = '/home', 2500);
  } else {
    nToast(data.msg, 'error');
    btn.disabled    = false;
    btn.textContent = '🎁 Klaim';
  }
}

// ── Popup ────────────────────────────────────────────
function showPop(msg) {
  const el = document.getElementById('reward-popup');
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 3500);
}
</script>
<script src="/assets/js/toast.js"></script>
</body>
</html>
