<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limiting — max 5 attempts per IP per 15 min
$ip_key  = 'reg_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit check
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan. Coba lagi dalam {$wait} menit.";
        goto end_reg;
    }

    // Slider CAPTCHA validation
    $captcha_ok   = $_POST['captcha_done'] ?? '0';
    $captcha_tok  = $_POST['captcha_tok']  ?? '';
    $captcha_ts   = (int)($_POST['captcha_ts'] ?? 0);
    $expected_tok = hash_hmac('sha256', (string)$captcha_ts, 'TONTON_CAP_' . session_id());

    if ($captcha_ok !== '1' || !hash_equals($expected_tok, $captcha_tok) || (time() - $captcha_ts) > 600) {
        $error = 'Verifikasi slider gagal. Geser slider sampai akhir!';
        goto end_reg;
    }

    // Input validation
    $username  = trim($_POST['username']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $whatsapp  = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $password  = $_POST['password']  ?? '';
    $ref_input = strtoupper(trim($_POST['referral'] ?? ''));

    if (!$username || !$email || !$whatsapp || !$password) {
        $error = 'Semua field wajib diisi.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username 3–30 karakter, hanya huruf/angka/underscore.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 15) {
        $error = 'Nomor WhatsApp tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        // Duplicate check
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'Username atau email sudah terdaftar.';
            // Count failed attempt
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
        } else {
            // Referral check
            $ref_by = null;
            if ($ref_input) {
                $rs = $pdo->prepare("SELECT referral_code FROM users WHERE referral_code=?");
                $rs->execute([$ref_input]);
                if (!$rs->fetchColumn()) { $error = 'Kode referral tidak valid.'; goto end_reg; }
                $ref_by = $ref_input;
            }
            // Create user
            $code = generate_referral_code($pdo);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by) VALUES (?,?,?,?,?,?)")
                ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by]);
            $new_id = (int)$pdo->lastInsertId();

            // Referral bonus
            if ($ref_by) {
                $bonus = (float) setting($pdo, 'referral_bonus', '1000');
                $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE referral_code=?")
                    ->execute([$bonus, $bonus, $ref_by]);
            }
            // Reset rate limit
            unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $new_id;
            redirect('/home');
        }
    }
}
end_reg:

// Generate CAPTCHA token for this page load
$cap_ts  = time();
$cap_tok = hash_hmac('sha256', (string)$cap_ts, 'TONTON_CAP_' . session_id());
?>
<?php
$_seo_title  = setting($pdo, 'seo_title', 'TontonKuy');
$_seo_desc   = setting($pdo, 'seo_description', 'Daftar gratis dan mulai tonton video untuk dapat reward!');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_og     = setting($pdo, 'seo_og_image', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og_type = setting($pdo, 'seo_og_type', 'website');
$_favicon    = setting($pdo, 'favicon_path', '');
$_page_title = 'Daftar — ' . $_seo_title;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFE566">
<title><?= htmlspecialchars($_page_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<?php
$absolute_og = $_seo_og ? (preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'))) : '';
$absolute_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : base_url(ltrim($_favicon, '/'))) : '';
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_desc = $_seo_desc;
?>
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_page_title) ?>">
<?php if ($final_og_desc): ?><meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>"><?php endif; ?>
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_seo_title) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?php if ($absolute_fav): ?>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__).$_favicon)?:time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__).$_favicon)?:time() ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* Extra neobrutalism decoration */
.reg-deco {
  position: absolute;
  pointer-events: none;
  font-size: 40px;
  opacity: .15;
  user-select: none;
}
.auth-page { position: relative; overflow: hidden; }
.step-tabs { display: flex; gap: 6px; margin-bottom: 20px; }
.step-tab {
  flex: 1; height: 6px; border-radius: 3px;
  background: #e0e0e0;
  border: 1.5px solid var(--ink);
  transition: background .3s;
}
.step-tab.done { background: var(--lime); }
.step-tab.active { background: var(--yellow); }
.form-step { display: none; }
.form-step.active { display: block; }
</style>
</head>
<body>
<div class="auth-page">
  <!-- Decorative elements -->
  <span class="reg-deco" style="top:5%;left:3%">🎬</span>
  <span class="reg-deco" style="top:15%;right:4%">⭐</span>
  <span class="reg-deco" style="bottom:20%;left:5%">💰</span>
  <span class="reg-deco" style="bottom:10%;right:3%">🎁</span>

    <div class="auth-card">
    <div class="auth-logo">
      <?php if ($_favicon): ?>
      <div class="auth-logo__icon" style="background:none;border:none;box-shadow:none;padding:0">
        <img src="<?= htmlspecialchars($_favicon) ?>" alt="" style="width:52px;height:52px;object-fit:contain;border-radius:12px;border:2px solid var(--ink)">
      </div>
      <?php else: ?>
      <div class="auth-logo__icon">🎬</div>
      <?php endif; ?>
      <div class="auth-logo__title"><?= htmlspecialchars($_seo_title) ?></div>
      <div class="auth-logo__sub">Daftar gratis &amp; langsung tonton!</div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert--error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Step indicator -->
    <div class="step-tabs">
      <div class="step-tab active" id="tab1"></div>
      <div class="step-tab" id="tab2"></div>
      <div class="step-tab" id="tab3"></div>
    </div>

    <form method="POST" id="reg-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="captcha_done" id="captcha_done" value="0">
      <input type="hidden" name="captcha_tok"  value="<?= $cap_tok ?>">
      <input type="hidden" name="captcha_ts"   value="<?= $cap_ts ?>">

      <!-- STEP 1: Identity -->
      <div class="form-step <?= !$error ? 'active' : '' ?>" id="step1">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 1 / 3 — Data Akun</div>
        <div class="form-group">
          <label class="form-label">Username</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input class="form-control" type="text" id="f_username" name="username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              placeholder="username_kamu" autocomplete="username">
          </div>
          <div class="form-hint">3–30 karakter, huruf/angka/underscore</div>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input class="form-control" type="email" id="f_email" name="email"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              placeholder="email@kamu.com" autocomplete="email">
          </div>
        </div>
        <button type="button" class="btn btn--yellow btn--full btn--lg" onclick="goStep2()">Lanjut →</button>
      </div>

      <!-- STEP 2: Contact & Password -->
      <div class="form-step" id="step2">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 2 / 3 — Kontak &amp; Password</div>
        <div class="form-group">
          <label class="form-label">Nomor WhatsApp</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8 19.79 19.79 0 01.01 1.18 2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16z"/></svg>
            <input class="form-control" type="tel" id="f_wa" name="whatsapp"
              value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>"
              placeholder="08xxxxxxxxxx" autocomplete="tel">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input class="form-control" type="password" id="f_pwd" name="password"
              placeholder="Min. 6 karakter" autocomplete="new-password">
            <button type="button" class="input-toggle" onclick="togglePwd('f_pwd')">👁</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Kode Referral <span style="color:#aaa;font-weight:600">(opsional)</span></label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
            <input class="form-control" type="text" name="referral"
              value="<?= htmlspecialchars($_POST['referral'] ?? '') ?>"
              placeholder="XXXXXXXX" style="text-transform:uppercase;letter-spacing:2px">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn btn--ghost" onclick="goStep(1)" style="flex:0 0 auto">← Kembali</button>
          <button type="button" class="btn btn--yellow btn--full" onclick="goStep3()">Lanjut →</button>
        </div>
      </div>

      <!-- STEP 3: CAPTCHA -->
      <div class="form-step <?= $error ? 'active' : '' ?>" id="step3">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 3 / 3 — Verifikasi Ulang</div>

        <!-- Slider CAPTCHA -->
        <div class="slider-captcha">
          <div class="slider-captcha-label">🤖 Bukan robot? Geser slider ini!</div>
          <div class="slider-track" id="sliderTrack">
            <div class="slider-fill"  id="sliderFill"></div>
            <div class="slider-thumb" id="sliderThumb" title="Geser ke kanan">
              <span id="sliderIcon">→</span>
            </div>
            <div class="slider-hint"  id="sliderHint">Geser ke kanan untuk verifikasi</div>
          </div>
        </div>

        <!-- Summary card -->
        <div class="card card--mint" style="margin-bottom:14px">
          <div class="card__body" style="padding:12px 14px">
            <div style="font-size:12px;font-weight:800;color:#555;margin-bottom:6px">📋 Ringkasan Pendaftaran</div>
            <div style="font-size:13px;font-weight:700;display:flex;flex-direction:column;gap:3px">
              <div>👤 <span id="sum_username">—</span></div>
              <div>📧 <span id="sum_email">—</span></div>
              <div>📱 <span id="sum_wa">—</span></div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:8px">
          <button type="button" class="btn btn--ghost" onclick="goStep(2)" style="flex:0 0 auto">← Kembali</button>
          <button type="submit" id="submit-btn" class="btn btn--primary btn--full" disabled
            style="opacity:.5;cursor:not-allowed">
            🎉 Daftar Sekarang
          </button>
        </div>
      </div>
    </form>

    <div class="auth-switch">Sudah punya akun? <a href="/login">Masuk di sini</a></div>
  </div>
</div>

<script>
// ── Step Navigation ─────────────────────
let currentStep = <?= ($error ? 3 : 1) ?>;

function updateTabs(step) {
  for (let i = 1; i <= 3; i++) {
    const tab = document.getElementById('tab' + i);
    tab.className = 'step-tab' + (i < step ? ' done' : i === step ? ' active' : '');
  }
}

function goStep(n) {
  document.getElementById('step' + currentStep).classList.remove('active');
  document.getElementById('step' + n).classList.add('active');
  currentStep = n;
  updateTabs(n);
  if (n === 3) updateSummary();
}

function validateStep1() {
  const u = document.getElementById('f_username').value.trim();
  const e = document.getElementById('f_email').value.trim();
  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) {
    alert('Username minimal 3 karakter, hanya huruf/angka/underscore!'); return false;
  }
  if (!e || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) {
    alert('Email tidak valid!'); return false;
  }
  return true;
}

function validateStep2() {
  const wa  = document.getElementById('f_wa').value.replace(/\D/g,'');
  const pwd = document.getElementById('f_pwd').value;
  if (wa.length < 9) { alert('Nomor WhatsApp tidak valid!'); return false; }
  if (pwd.length < 6) { alert('Password minimal 6 karakter!'); return false; }
  return true;
}

function goStep2() { if (validateStep1()) goStep(2); }
function goStep3() { if (validateStep2()) goStep(3); }

function updateSummary() {
  document.getElementById('sum_username').textContent = document.getElementById('f_username').value || '—';
  document.getElementById('sum_email').textContent    = document.getElementById('f_email').value    || '—';
  document.getElementById('sum_wa').textContent       = document.getElementById('f_wa').value       || '—';
}

// ── Slider CAPTCHA ─────────────────────
const track  = document.getElementById('sliderTrack');
const thumb  = document.getElementById('sliderThumb');
const fill   = document.getElementById('sliderFill');
const hint   = document.getElementById('sliderHint');
const icon   = document.getElementById('sliderIcon');
const capInp = document.getElementById('captcha_done');
const subBtn = document.getElementById('submit-btn');

let isDragging = false, startX = 0, startLeft = 0, verified = false;
const THUMB_W = 40, PAD = 5;

function getTrackWidth() { return track.getBoundingClientRect().width; }
function getMaxLeft()    { return getTrackWidth() - THUMB_W - PAD * 2; }

function onStart(e) {
  if (verified) return;
  isDragging = true;
  const cx = e.touches ? e.touches[0].clientX : e.clientX;
  startX    = cx;
  startLeft = parseInt(thumb.style.left || '5', 10);
  thumb.style.cursor = 'grabbing';
  e.preventDefault();
}

function onMove(e) {
  if (!isDragging || verified) return;
  const cx   = e.touches ? e.touches[0].clientX : e.clientX;
  const dx   = cx - startX;
  const max  = getMaxLeft();
  const newL = Math.min(max, Math.max(PAD, startLeft + dx));
  thumb.style.left = newL + 'px';
  const pct = ((newL - PAD) / (max - PAD)) * 100;
  fill.style.width = (newL + THUMB_W / 2) + 'px';
}

function onEnd() {
  if (!isDragging) return;
  isDragging = false;
  thumb.style.cursor = 'grab';
  const max  = getMaxLeft();
  const curL = parseInt(thumb.style.left || '5', 10);
  const pct  = ((curL - PAD) / (max - PAD)) * 100;
  if (pct >= 90) {
    // Verified!
    verified = true;
    thumb.style.left = max + 'px';
    fill.style.width = '100%';
    fill.classList.add('done');
    thumb.classList.add('done');
    icon.textContent = '✓';
    hint.textContent = '✅ Verifikasi berhasil!';
    hint.classList.add('done');
    capInp.value = '1';
    subBtn.disabled = false;
    subBtn.style.opacity = '1';
    subBtn.style.cursor  = 'pointer';
  } else {
    // Reset
    thumb.style.left = PAD + 'px';
    fill.style.width = '0%';
  }
}

thumb.addEventListener('mousedown',  onStart);
thumb.addEventListener('touchstart', onStart, { passive: false });
document.addEventListener('mousemove',  onMove);
document.addEventListener('touchmove',  onMove, { passive: false });
document.addEventListener('mouseup',  onEnd);
document.addEventListener('touchend', onEnd);

// ── Helpers ────────────────────────────
function togglePwd(id) {
  const i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
}

// Re-run step 3 if error
<?php if ($error): ?>updateTabs(3); updateSummary();<?php endif; ?>
</script>
</body>
</html>
