<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$checkin_reward = (float) setting($pdo, 'checkin_reward', '500');
$today          = date('Y-m-d');
$last_checkin   = $user['last_checkin'] ?? null;
$already        = $last_checkin === $today;

// Hitung streak (hari berturut-turut)
$streak = 0;
if ($last_checkin) {
    // Sederhana: jika kemarin check-in, streak lanjut; jika 2 hari+ lalu, reset
    $diff = (int)((strtotime($today) - strtotime($last_checkin)) / 86400);
    if ($diff <= 1) {
        // Count consecutive days from DB
        $streak_q = $pdo->prepare(
            "SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        $streak_q->execute([$user['id']]);
        $streak = max(1, (int)$streak_q->fetchColumn());
    }
}

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    if ($already) {
        $flash = 'Kamu sudah check-in hari ini. Kembali besok!';
        $flashType = 'warn';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE users SET balance_dep=balance_dep+?, last_checkin=CURDATE() WHERE id=? AND (last_checkin IS NULL OR last_checkin < CURDATE())");
            $stmt->execute([$checkin_reward, $user['id']]);
            
            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
                $already = true;
                $flash = '🎉 Check-in berhasil! +' . format_rp($checkin_reward) . ' masuk ke Saldo Deposit.';
                $flashType = 'success';
            } else {
                $pdo->rollBack();
                $flash = 'Kamu sudah check-in hari ini. Kembali besok!';
                $flashType = 'warn';
                $already = true;
            }
        } catch (\Throwable) {
            $pdo->rollBack();
            $flash = 'Terjadi kesalahan.'; $flashType = 'error';
        }
    }
}

$pageTitle  = 'Check-in Harian — TontonKuy';
$activePage = 'checkin';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>📅 Check-in Harian</h1>
  <p>Check-in setiap hari untuk mendapatkan bonus saldo deposit!</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : ($flashType === 'warn' ? 'warn' : 'success') ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Main checkin card -->
<div class="checkin-card">
  <div class="checkin-card__orb <?= $already ? 'checkin-card__orb--done' : 'checkin-card__orb--ready' ?>" id="orb">
    <?php if ($already): ?>
      <span class="checkin-orb-icon">✅</span>
      <span class="checkin-orb-label">Sudah Check-in</span>
    <?php else: ?>
      <span class="checkin-orb-icon">🎁</span>
      <span class="checkin-orb-label">Klik untuk Klaim!</span>
    <?php endif; ?>
  </div>

  <div class="checkin-card__reward">
    <div class="checkin-reward-amount"><?= format_rp($checkin_reward) ?></div>
    <div class="checkin-reward-label">Reward check-in hari ini</div>
  </div>

  <?php if (!$already): ?>
  <form method="POST" id="checkin-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="checkin">
    <button type="submit" class="btn btn--primary btn--full btn--lg" id="checkin-btn" onclick="animateOrb()">
      🎯 Klaim Check-in Sekarang
    </button>
  </form>
  <?php else: ?>
  <div class="alert alert--success" style="text-align:center">
    ✅ Sudah check-in hari ini!<br>
    <small>Kembali besok untuk reward berikutnya</small>
  </div>
  <?php endif; ?>
</div>

<!-- Stats row -->
<div class="stat-row" style="margin-top:16px">
  <div class="stat-mini">
    <div class="stat-mini__val"><?= $streak ?></div>
    <div class="stat-mini__lbl">🔥 Hari Aktif</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px"><?= $already ? 'Sudah ✓' : 'Belum' ?></div>
    <div class="stat-mini__lbl">Hari Ini</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div class="stat-mini__lbl">Saldo Deposit</div>
  </div>
</div>

<!-- Info card -->
<div class="card" style="margin-top:16px">
  <div class="card__header"><div class="card__title">📋 Cara Kerja Check-in</div></div>
  <div class="card__body">
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--yellow)">1️⃣</div>
      <div class="list-item__body">
        <div class="list-item__title">Check-in setiap hari</div>
        <div class="list-item__sub">Kamu bisa check-in 1× per hari, reset tiap tengah malam</div>
      </div>
    </div>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--mint)">2️⃣</div>
      <div class="list-item__body">
        <div class="list-item__title">Reward masuk ke Saldo Deposit</div>
        <div class="list-item__sub">Reward digunakan untuk upgrade paket membership</div>
      </div>
    </div>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--pink)">3️⃣</div>
      <div class="list-item__body">
        <div class="list-item__title">Konsisten = lebih banyak reward</div>
        <div class="list-item__sub">Jangan sampai terlewat agar streak kamu terus naik!</div>
      </div>
    </div>
  </div>
</div>

<style>
.checkin-card {
  background: var(--yellow);
  border: 3px solid var(--border);
  box-shadow: 5px 5px 0 var(--border);
  border-radius: 16px;
  padding: 28px 20px;
  text-align: center;
  margin-bottom: 8px;
}
.checkin-card__orb {
  width: 130px; height: 130px;
  border-radius: 50%;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  margin: 0 auto 20px;
  border: 3px solid var(--border);
  cursor: pointer;
  transition: transform .15s, box-shadow .15s;
}
.checkin-card__orb--ready {
  background: var(--mint);
  box-shadow: 4px 4px 0 var(--border);
  animation: orb-pulse 2s ease-in-out infinite;
}
.checkin-card__orb--done {
  background: #e0e0e0;
  box-shadow: 2px 2px 0 var(--border);
}
@keyframes orb-pulse {
  0%,100%{box-shadow:4px 4px 0 var(--border), 0 0 0 0 rgba(100,220,150,.4)}
  50%{box-shadow:4px 4px 0 var(--border), 0 0 0 16px rgba(100,220,150,0)}
}
.checkin-orb-icon { font-size: 40px; }
.checkin-orb-label { font-size: 11px; font-weight: 700; margin-top: 4px; color: var(--text2); }
.checkin-card__reward { margin-bottom: 20px; }
.checkin-reward-amount { font-size: 28px; font-weight: 900; color: var(--text); }
.checkin-reward-label { font-size: 13px; color: var(--text2); margin-top: 2px; }
</style>

<script>
function animateOrb() {
  const orb = document.getElementById('orb');
  orb.style.transform = 'scale(0.9)';
  setTimeout(() => orb.style.transform = 'scale(1)', 150);
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
