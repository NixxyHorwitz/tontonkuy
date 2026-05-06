<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$dep_id  = (int)($_GET['id'] ?? 0);
if (!$dep_id) redirect('/deposit');

$dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND user_id=?");
$dep->execute([$dep_id, $user['id']]);
$dep = $dep->fetch();
if (!$dep)                          redirect('/deposit');
if ($dep['method'] !== 'qris')      redirect('/deposit');
if ($dep['status'] === 'confirmed') redirect('/history');

$qris_raw     = setting($pdo, 'qris_raw', '');
$confirm_mode = setting($pdo, 'deposit_confirm_mode', 'manual');
$amount       = (float)$dep['amount'];
$qris_str     = !empty($qris_raw) ? qris_with_amount($qris_raw, (int)$amount) : '';

// Handle upload bukti setelah scan
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Pilih file bukti pembayaran.'; $flashType = 'error';
    } else {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $flash = 'Format harus JPG/PNG/WEBP.'; $flashType = 'error';
        } else {
            $dir   = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $pdo->prepare("UPDATE deposits SET proof_image=? WHERE id=?")
                ->execute(['deposits/' . $fname, $dep_id]);
            $flash = '✅ Bukti berhasil diupload! Admin akan memverifikasi segera.';
        }
    }
    $dep2 = $pdo->prepare("SELECT * FROM deposits WHERE id=?"); $dep2->execute([$dep_id]); $dep = $dep2->fetch();
}

// AJAX: poll deposit status (for auto-confirm mode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    header('Content-Type: application/json');
    $st = $pdo->prepare("SELECT status FROM deposits WHERE id=? AND user_id=?");
    $st->execute([$dep_id, $user['id']]);
    $row = $st->fetch();
    echo json_encode(['confirmed' => ($row && $row['status'] === 'confirmed')]);
    exit;
}

$qr_url = !empty($qris_str)
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qris_str)
    : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bayar QRIS — TontonKuy</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.pay-page { min-height: 100vh; display:flex; flex-direction:column; background: var(--bg); }
.pay-topbar {
  background: var(--white);
  border-bottom: 2.5px solid var(--ink);
  padding: 0 16px; height: 54px;
  display: flex; align-items: center; justify-content: space-between;
}
.pay-hero {
  background: var(--mint);
  border-bottom: 2.5px solid var(--ink);
  padding: 20px 16px;
  text-align: center;
}
.pay-hero__label { font-size: 13px; font-weight: 800; color: #555; margin-bottom: 4px; }
.pay-hero__amount { font-size: 36px; font-weight: 900; letter-spacing: -1px; }
.pay-hero__id { font-size: 11px; color: #666; margin-top: 3px; }
.pay-body { padding: 20px 16px; flex: 1; }
.qr-box {
  background: var(--white);
  border: 3px solid var(--ink);
  border-radius: 20px;
  box-shadow: 6px 6px 0 var(--ink);
  padding: 24px 20px;
  text-align: center;
  margin-bottom: 16px;
}
.qr-box img {
  width: 200px; height: 200px;
  border: 3px solid var(--ink);
  border-radius: 14px;
  display: block; margin: 0 auto 14px;
  padding: 12px;
  background: #fff;
}
.qr-box__note { font-size: 12px; color: #666; font-weight: 600; }
.steps-card {
  background: var(--yellow);
  border: 2.5px solid var(--ink);
  border-radius: 14px;
  box-shadow: 4px 4px 0 var(--ink);
  padding: 16px;
  margin-bottom: 16px;
}
.step-item { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.step-item:last-child { margin-bottom: 0; }
.step-num {
  width: 28px; height: 28px; flex-shrink: 0;
  background: var(--white); border: 2px solid var(--ink);
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 900;
}
.step-text { font-size: 13px; font-weight: 700; line-height: 1.4; padding-top: 4px; }
.upload-card {
  background: var(--white);
  border: 2.5px solid var(--ink);
  border-radius: 14px;
  box-shadow: 4px 4px 0 var(--ink);
  padding: 18px;
}
.upload-card__title { font-weight: 900; font-size: 15px; margin-bottom: 14px; }
.status-confirmed {
  background: var(--lime);
  border: 2.5px solid var(--ink);
  border-radius: 14px;
  box-shadow: 4px 4px 0 var(--ink);
  padding: 24px;
  text-align: center;
}
/* Auto-confirm timer */
/* Auto-confirm timer — compact strip */
.auto-timer-strip {
  display: flex; align-items: center; justify-content: space-between;
  background: var(--lime);
  border: 2px solid var(--ink);
  border-radius: 10px;
  padding: 10px 14px;
  margin-bottom: 12px;
  gap: 10px;
}
.auto-timer-strip__dot {
  width: 9px; height: 9px; flex-shrink: 0;
  border-radius: 50%;
  background: #22c55e;
  animation: blink-dot .9s ease-in-out infinite;
}
@keyframes blink-dot {
  0%,100% { opacity:1; } 50% { opacity:.2; }
}
.auto-timer-strip__label { font-size: 12px; font-weight: 700; flex: 1; }
.auto-timer-strip__time  { font-size: 14px; font-weight: 900; letter-spacing: -.5px; flex-shrink: 0; }
</style>
</head>
<body>
<div class="pay-page app-shell">

  <div class="pay-topbar">
    <a href="/deposit" style="display:flex;align-items:center;gap:6px;color:var(--ink);text-decoration:none;font-weight:800;font-size:14px">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Kembali
    </a>
    <div class="topbar__balance"><?= format_rp($amount) ?></div>
  </div>

  <div class="pay-hero">
    <div class="pay-hero__label">📱 Bayar via QRIS</div>
    <div class="pay-hero__amount"><?= format_rp($amount) ?></div>
    <div class="pay-hero__id">Deposit #<?= $dep_id ?></div>
  </div>

  <div class="pay-body">
    <?php if ($flash): ?>
    <div class="alert alert--<?= $flashType==='error'?'error':'success' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="status-confirmed">
      <div style="font-size:48px;margin-bottom:12px">✅</div>
      <div style="font-size:20px;font-weight:900;margin-bottom:8px">Pembayaran Dikonfirmasi!</div>
      <div style="font-size:13px;color:#555">Saldo deposit kamu sudah ditambahkan.</div>
      <a href="/home" class="btn btn--primary btn--full" style="margin-top:16px">🏠 Ke Beranda</a>
    </div>

    <?php elseif ($dep['proof_image']): ?>
    <div class="alert alert--info">
      ⏳ Bukti sudah diupload, menunggu konfirmasi admin. Biasanya 1–24 jam.
    </div>
    <a href="/history" class="btn btn--ghost btn--full" style="margin-top:8px">📜 Lihat Riwayat</a>

    <?php else: ?>
    <!-- QR Code -->
    <?php if ($confirm_mode === 'auto'): ?>
    <div class="auto-timer-strip">
      <div class="auto-timer-strip__dot" id="strip-dot"></div>
      <div class="auto-timer-strip__label" id="strip-label">⚡ Menunggu konfirmasi otomatis…</div>
      <div class="auto-timer-strip__time" id="auto-timer">5:00</div>
    </div>
    <?php endif; ?>
    <?php if ($qr_url): ?>
    <div class="qr-box">
      <img src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS <?= format_rp($amount) ?>">
      <div style="font-weight:900;font-size:16px;margin-bottom:4px"><?= format_rp($amount) ?></div>
      <div class="qr-box__note">Scan QR ini dengan aplikasi bank/dompet digital apapun</div>
    </div>
    <?php else: ?>
    <div class="alert alert--warn">QRIS belum dikonfigurasi admin. Hubungi support.</div>
    <?php endif; ?>

    <!-- Langkah -->
    <div class="steps-card">
      <div style="font-weight:900;margin-bottom:12px">📋 Langkah Pembayaran</div>
      <div class="step-item"><div class="step-num">1</div><div class="step-text">Buka aplikasi bank atau e-wallet kamu (GoPay, OVO, DANA, dll)</div></div>
      <div class="step-item"><div class="step-num">2</div><div class="step-text">Scan QR Code di atas — nominal sudah otomatis terisi</div></div>
      <div class="step-item"><div class="step-num">3</div><div class="step-text">Konfirmasi pembayaran di aplikasi kamu</div></div>
      <?php if ($confirm_mode === 'manual'): ?>
      <div class="step-item"><div class="step-num">4</div><div class="step-text">Screenshot bukti & upload di bawah ini</div></div>
      <?php else: ?>
      <div class="step-item"><div class="step-num">4</div><div class="step-text">Tunggu — saldo otomatis masuk dalam hitungan menit ⚡</div></div>
      <?php endif; ?>
    </div>

    <?php if ($confirm_mode === 'auto'): ?>
    <script>
    let secs = 300;
    const timerEl  = document.getElementById('auto-timer');
    const labelEl  = document.getElementById('strip-label');
    const dotEl    = document.getElementById('strip-dot');
    const countdown = setInterval(() => {
      secs--;
      const m = Math.floor(secs / 60), s = secs % 60;
      if (timerEl) timerEl.textContent = m + ':' + String(s).padStart(2,'0');
      if (secs <= 0) {
        clearInterval(countdown);
        if (timerEl) timerEl.textContent = '0:00';
        if (labelEl) labelEl.textContent = '⚠️ Waktu habis — cek riwayat atau hubungi admin';
        if (dotEl)   { dotEl.style.background = '#f97316'; dotEl.style.animation = 'none'; }
      }
    }, 1000);
    const pollStatus = () => {
      fetch('', { method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=<?= csrf_token() ?>&action=check_status'
      }).then(r=>r.json()).then(d=>{
        if (d.confirmed) {
          clearInterval(countdown); clearInterval(pollTimer);
          if (timerEl) timerEl.textContent = '✓';
          if (labelEl) labelEl.textContent = '✅ Dikonfirmasi! Mengalihkan...';
          if (dotEl)   { dotEl.style.background='#22c55e'; dotEl.style.animation='none'; }
          setTimeout(()=>location.href='/history?tab=deposit', 1800);
        }
      }).catch(()=>{});
    };
    const pollTimer = setInterval(pollStatus, 10000);
    </script>
    <?php else: ?>
    <!-- MANUAL: Upload bukti -->
    <div class="upload-card">
      <div class="upload-card__title">📸 Upload Bukti Pembayaran</div>
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <div class="form-group">
          <input class="form-control" type="file" name="proof" accept="image/*"
                 style="padding:10px" required>
          <div class="form-hint">Screenshot notifikasi/struk pembayaran QRIS</div>
        </div>
        <button type="submit" class="btn btn--primary btn--full">📤 Upload & Konfirmasi</button>
      </form>
      <div style="text-align:center;margin-top:12px">
        <a href="/history" style="font-size:13px;color:#888;font-weight:700">Nanti saja → Lihat di Riwayat</a>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
