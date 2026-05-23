<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$dep_id = (int)($_GET['id'] ?? 0);
if (!$dep_id) redirect('/deposit');

$dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND user_id=?");
$dep->execute([$dep_id, $user['id']]);
$dep = $dep->fetch();
if (!$dep || $dep['method'] !== 'qris') redirect('/deposit');

// ── AJAX: check_status HARUS di sini (sebelum redirect confirmed!) ──
// Jika check_status POST dikirim saat deposit sudah confirmed, tanpa ini
// PHP akan redirect ke /history dan mengirim HTML, menyebabkan JSON parse error di JS.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    header('Content-Type: application/json; charset=utf-8');
    // Re-fetch fresh status from DB
    $st = $pdo->prepare("SELECT status FROM deposits WHERE id=? AND user_id=?");
    $st->execute([$dep_id, $user['id']]);
    $row = $st->fetch();
    echo json_encode(['confirmed' => ($row && $row['status'] === 'confirmed')]);
    exit;
}

if ($dep['status'] === 'confirmed') redirect('/history');

$qris_raw     = setting($pdo, 'qris_raw', '');
$confirm_mode = setting($pdo, 'deposit_confirm_mode', 'manual');
$amount       = (float)$dep['amount'];
$qris_str     = !empty($qris_raw) ? qris_with_amount($qris_raw, (int)$amount) : '';
$_favicon     = setting($pdo, 'favicon_path', '');
$fav_url      = $_favicon ? '/' . ltrim($_favicon, '/') : '';

// Handle upload bukti
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Pilih file bukti pembayaran.'; $flashType = 'error';
    } else {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $flash = 'Format harus JPG/PNG/WEBP.'; $flashType = 'error';
        } else {
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $pdo->prepare("UPDATE deposits SET proof_image=? WHERE id=?")->execute(['deposits/' . $fname, $dep_id]);
            $flash = '✅ Bukti berhasil diupload! Admin akan memverifikasi segera.';
        }
    }
    $dep2 = $pdo->prepare("SELECT * FROM deposits WHERE id=?"); $dep2->execute([$dep_id]); $dep = $dep2->fetch();
}

// Handle cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_deposit') {
    if (time() - strtotime($dep['created_at']) >= 60) {
        $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Dibatalkan oleh Pengguna' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([$dep_id, $user['id']]);
        redirect('/deposit');
    } else {
        $flash = 'Harap tunggu 1 menit sejak deposit dibuat sebelum membatalkan.'; $flashType = 'error';
    }
}

$seconds_left = max(0, 60 - (time() - strtotime($dep['created_at'])));
$qr_url = !empty($qris_str)
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str)
    : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFE566">
<title>Bayar QRIS — TontonKuy</title>
<?php if ($fav_url): ?>
<link rel="icon" href="<?= htmlspecialchars($fav_url) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($fav_url) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
:root { --pay-radius: 12px; }
.pay-wrap { min-height:100dvh; display:flex; flex-direction:column; background:var(--bg); }

/* ── Topbar ── */
.pay-topbar {
  background:var(--white); border-bottom:2px solid var(--ink);
  padding:0 14px; height:48px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:100;
}
.pay-topbar__back {
  display:flex; align-items:center; gap:4px;
  color:var(--ink); text-decoration:none; font-weight:800; font-size:13px;
}
.pay-topbar__title { font-size:13px; font-weight:900; }
.pay-topbar__amt {
  font-size:13px; font-weight:900;
  background:var(--yellow); border:1.5px solid var(--ink);
  border-radius:6px; padding:3px 9px;
}

/* ── Body ── */
.pay-body { padding:14px; flex:1; display:flex; flex-direction:column; gap:12px; }

/* ── Amount Header ── */
.pay-amthdr {
  text-align:center; padding:14px 12px 10px;
  background:var(--mint); border:2px solid var(--ink);
  border-radius:var(--pay-radius); 
}
.pay-amthdr__label { font-size:11px; font-weight:700; color:#666; margin-bottom:2px; }
.pay-amthdr__val   { font-size:30px; font-weight:900; letter-spacing:-1px; line-height:1; }
.pay-amthdr__id    { font-size:10px; color:#888; margin-top:3px; }

/* ── Auto strip ── */
.auto-strip {
  display:flex; align-items:center; gap:8px;
  background:#f0fdf4; border:1.5px solid #4ade80;
  border-radius:8px; padding:8px 12px;
}
.auto-strip__dot {
  width:8px; height:8px; border-radius:50%;
  background:#22c55e; flex-shrink:0;
  animation:blink .9s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.15} }
.auto-strip__lbl { font-size:11px; font-weight:700; flex:1; color:#166534; }
.auto-strip__time { font-size:13px; font-weight:900; color:#166534; flex-shrink:0; }

/* ── QR Card ── */
.qr-card {
  background:#fff; border:2px solid var(--ink);
  border-radius:var(--pay-radius); padding:16px 12px 12px;
  text-align:center;
}
.qr-card img {
  width:170px; height:170px;
  border:2px solid var(--ink); border-radius:10px;
  display:block; margin:0 auto 10px;
  padding:8px; background:#fff;
}
.qr-card__amt  { font-size:18px; font-weight:900; margin-bottom:2px; }
.qr-card__note { font-size:11px; color:#777; font-weight:600; margin-bottom:10px; }
.qr-card__btns { display:flex; gap:8px; }
.qr-card__btns > * { flex:1; }

/* ── Steps ── */
.steps {
  background:var(--yellow); border:1.5px solid var(--ink);
  border-radius:var(--pay-radius); padding:12px;
  display:flex; flex-direction:column; gap:7px;
}
.steps__title { font-size:12px; font-weight:900; margin-bottom:2px; }
.step { display:flex; align-items:center; gap:8px; }
.step__n {
  width:22px; height:22px; flex-shrink:0;
  background:#fff; border:1.5px solid var(--ink); border-radius:50%;
  font-size:11px; font-weight:900;
  display:flex; align-items:center; justify-content:center;
}
.step__t { font-size:12px; font-weight:700; line-height:1.3; }

/* ── Upload card ── */
.upload-card {
  background:#fff; border:2px solid var(--ink);
  border-radius:var(--pay-radius); padding:14px;
}
.upload-card__title { font-size:13px; font-weight:900; margin-bottom:10px; }

/* ── Action buttons ── */
.pay-actions { display:flex; flex-direction:column; gap:8px; }

/* ── Confirmed ── */
.pay-confirmed {
  text-align:center; padding:28px 16px;
  background:var(--lime); border:2px solid var(--ink);
  border-radius:var(--pay-radius);
}
</style>
</head>
<body>
<div class="pay-wrap">

  <div class="pay-topbar">
    <a href="/deposit" class="pay-topbar__back">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Kembali
    </a>
    <div class="pay-topbar__title">📱 Bayar QRIS</div>
    <div class="pay-topbar__amt"><?= format_rp($amount) ?></div>
  </div>

  <div class="pay-body">
    <?php if ($flash): ?>
    <div class="alert alert--<?= $flashType==='error'?'error':'success' ?>" style="margin:0;font-size:12px"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="pay-confirmed">
      <div style="font-size:42px;margin-bottom:8px">✅</div>
      <div style="font-size:18px;font-weight:900;margin-bottom:6px">Pembayaran Dikonfirmasi!</div>
      <div style="font-size:12px;color:#555;margin-bottom:16px">Saldo deposit kamu sudah ditambahkan.</div>
      <a href="/home" class="btn btn--primary btn--full">🏠 Ke Beranda</a>
    </div>

    <?php elseif ($dep['proof_image']): ?>
    <div class="alert alert--info" style="margin:0;font-size:12px">⏳ Bukti sudah diupload, menunggu konfirmasi admin. Biasanya 1–24 jam.</div>
    <a href="/history" class="btn btn--ghost btn--full" style="font-size:13px">📜 Lihat Riwayat</a>

    <?php else: ?>

    <!-- Amount header -->
    <div class="pay-amthdr">
      <div class="pay-amthdr__label">Total Pembayaran</div>
      <div class="pay-amthdr__val"><?= format_rp($amount) ?></div>
      <div class="pay-amthdr__id">Deposit #<?= $dep_id ?></div>
    </div>

    <!-- Auto-confirm strip -->
    <?php if ($confirm_mode === 'auto'): ?>
    <div class="auto-strip">
      <div class="auto-strip__dot" id="strip-dot"></div>
      <div class="auto-strip__lbl" id="strip-label">⚡ Menunggu konfirmasi otomatis…</div>
      <div class="auto-strip__time" id="auto-timer">5:00</div>
    </div>
    <?php endif; ?>

    <!-- QR Code -->
    <?php if ($qr_url): ?>
    <div class="qr-card">
      <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS <?= format_rp($amount) ?>" crossorigin="anonymous">
      <div class="qr-card__amt"><?= format_rp($amount) ?></div>
      <div class="qr-card__note">Scan dengan aplikasi bank / dompet digital apapun</div>
      <div class="qr-card__btns">
        <button type="button" onclick="downloadQR()" class="btn btn--secondary btn--sm" style="font-size:12px;font-weight:800">
          ⬇️ Unduh QR
        </button>
        <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="btn btn--ghost btn--sm" style="font-size:12px;font-weight:800;text-align:center">
          🔗 Buka
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert--warn" style="margin:0;font-size:12px">QRIS belum dikonfigurasi. Hubungi admin.</div>
    <?php endif; ?>

    <!-- Steps -->
    <div class="steps">
      <div class="steps__title">📋 Cara Bayar</div>
      <div class="step"><div class="step__n">1</div><div class="step__t">Buka app bank atau e-wallet (GoPay, OVO, DANA, dll)</div></div>
      <div class="step"><div class="step__n">2</div><div class="step__t">Scan QR di atas — nominal sudah otomatis terisi</div></div>
      <div class="step"><div class="step__n">3</div><div class="step__t">Konfirmasi pembayaran di aplikasi kamu</div></div>
      <?php if ($confirm_mode === 'manual'): ?>
      <div class="step"><div class="step__n">4</div><div class="step__t">Screenshot bukti &amp; upload di bawah ini</div></div>
      <?php else: ?>
      <div class="step"><div class="step__n">4</div><div class="step__t">Tunggu — saldo otomatis masuk dalam hitungan menit ⚡</div></div>
      <?php endif; ?>
    </div>

    <!-- Upload bukti (manual mode only) -->
    <?php if ($confirm_mode !== 'auto'): ?>
    <div class="upload-card">
      <div class="upload-card__title">📸 Upload Bukti Pembayaran</div>
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <div class="form-group" style="margin-bottom:10px">
          <input class="form-control" type="file" name="proof" accept="image/*" style="padding:8px;font-size:12px" required>
          <div class="form-hint" style="font-size:11px">Screenshot notifikasi / struk pembayaran QRIS</div>
        </div>
        <button type="submit" class="btn btn--primary btn--full" style="font-size:13px">📤 Upload &amp; Konfirmasi</button>
      </form>
      <div style="text-align:center;margin-top:10px">
        <a href="/history" style="font-size:12px;color:#999;font-weight:700">Nanti saja → Lihat Riwayat</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Action buttons -->
    <div class="pay-actions">
      <button type="button" id="btn-check-status" onclick="manualCheckStatus()"
              class="btn btn--primary btn--full" style="font-size:13px;font-weight:800">
        🔄 Cek Status Pembayaran
      </button>
      <form method="POST" id="cancel-form" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_deposit">
        <button type="submit" id="btn-cancel-dep"
                class="btn btn--ghost btn--full"
                style="font-size:12px;font-weight:800;border-color:#ef4444;color:#ef4444;width:100%">
          ❌ Batalkan Deposit
        </button>
      </form>
    </div>

    <!-- Scripts -->
    <script>
    const DEP_ID   = <?= $dep_id ?>;
    const CSRF_TOK = '<?= csrf_token() ?>';
    let isChecking = false;

    // ── Download QR via blob (cross-origin safe) ──
    async function downloadQR() {
      try {
        const r   = await fetch('<?= htmlspecialchars($qr_url) ?>');
        const blo = await r.blob();
        const url = URL.createObjectURL(blo);
        const a   = document.createElement('a');
        a.href     = url;
        a.download = 'QRIS-TontonKuy-<?= format_rp($amount) ?>.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      } catch(e) {
        // Fallback: open in new tab so user can save manually
        window.open('<?= htmlspecialchars($qr_url) ?>', '_blank');
      }
    }

    // ── Polling (every 5s) ──
    const pollStatus = () => {
      if (isChecking) return;
      fetch('?id=' + DEP_ID, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf=' + CSRF_TOK + '&action=check_status'
      })
      .then(r => r.json())
      .then(d => {
        if (d.confirmed) confirmAndRedirect();
      })
      .catch(() => {});
    };
    const pollTimer = setInterval(pollStatus, 5000);

    function confirmAndRedirect() {
      clearInterval(pollTimer);
      if (typeof countdown !== 'undefined') clearInterval(countdown);
      const dot = document.getElementById('strip-dot');
      const lbl = document.getElementById('strip-label');
      const tmr = document.getElementById('auto-timer');
      if (dot) { dot.style.background='#22c55e'; dot.style.animation='none'; }
      if (lbl) lbl.textContent = '✅ Dikonfirmasi! Mengalihkan...';
      if (tmr) tmr.textContent = '✓';
      setTimeout(() => location.href = '/history?tab=deposit', 1500);
    }

    // ── Manual check ──
    const manualCheckStatus = () => {
      if (isChecking) return;
      isChecking = true;
      const btn = document.getElementById('btn-check-status');
      const orig = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '⏳ Mengecek...';
      fetch('?id=' + DEP_ID, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf=' + CSRF_TOK + '&action=check_status'
      })
      .then(r => r.json())
      .then(d => {
        isChecking = false;
        btn.disabled = false;
        btn.innerHTML = orig;
        if (d.confirmed) {
          confirmAndRedirect();
        } else {
          alert('❌ Pembayaran belum terdeteksi. Pastikan nominal sudah sesuai (termasuk kode unik jika ada).');
        }
      })
      .catch(() => {
        isChecking = false;
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('⚠️ Gagal menghubungi server. Coba lagi.');
      });
    };

    <?php if ($confirm_mode === 'auto'): ?>
    // ── Auto countdown ──
    let secs = 300;
    const countdown = setInterval(() => {
      secs--;
      const el = document.getElementById('auto-timer');
      if (el) el.textContent = Math.floor(secs/60) + ':' + String(secs%60).padStart(2,'0');
      if (secs <= 0) {
        clearInterval(countdown);
        const lbl = document.getElementById('strip-label');
        const dot = document.getElementById('strip-dot');
        if (lbl) lbl.textContent = '⚠️ Waktu habis — cek riwayat atau hubungi admin';
        if (dot) { dot.style.background='#f97316'; dot.style.animation='none'; }
      }
    }, 1000);
    <?php endif; ?>

    // ── Cancel cooldown ──
    let cancelSecs = <?= $seconds_left ?>;
    const cancelBtn = document.getElementById('btn-cancel-dep');
    if (cancelBtn && cancelSecs > 0) {
      cancelBtn.disabled = true;
      cancelBtn.style.opacity = '0.45';
      cancelBtn.style.cursor  = 'not-allowed';
      cancelBtn.textContent = '❌ Batalkan (Tunggu ' + cancelSecs + 's)';
      const ci = setInterval(() => {
        cancelSecs--;
        cancelBtn.textContent = cancelSecs > 0
          ? '❌ Batalkan (Tunggu ' + cancelSecs + 's)'
          : '❌ Batalkan Deposit';
        if (cancelSecs <= 0) {
          clearInterval(ci);
          cancelBtn.disabled     = false;
          cancelBtn.style.opacity = '1';
          cancelBtn.style.cursor  = 'pointer';
        }
      }, 1000);
    }
    </script>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
