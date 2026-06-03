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
    $diff = (int)((strtotime($today) - strtotime($last_checkin)) / 86400);
    if ($diff <= 1) {
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
                $streak++; // Optimistically update streak for UI
                $flash = '🎉 Check-in berhasil! +' . format_rp($checkin_reward) . ' masuk ke Saldo Beli.';
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

$completed_days = $already ? ($streak % 7 ?: 7) : ($streak % 7);
if ($streak == 0 && $already) { $completed_days = 1; } // fallback
?>

<style>
.neo-wrapper {
  padding: 10px 0;
}
.neo-title {
  font-size: 32px;
  font-weight: 900;
  text-transform: uppercase;
  color: var(--ink, #000);
  margin-bottom: 2px;
  letter-spacing: -1px;
  text-shadow: 2px 2px 0px #fff, 4px 4px 0px var(--ink, #000);
}
.neo-subtitle {
  font-size: 14px;
  color: #444;
  font-weight: 800;
  margin-bottom: 24px;
  background: #fff;
  display: inline-block;
  padding: 4px 8px;
  border: 2px solid var(--ink);
  box-shadow: 2px 2px 0 var(--ink);
  transform: rotate(-1deg);
}
.neo-card {
  background: #fff;
  border: 4px solid var(--ink, #000);
  box-shadow: 6px 6px 0px var(--ink, #000);
  border-radius: 12px 4px 20px 4px;
  padding: 24px;
  margin-bottom: 24px;
  text-align: center;
}
.neo-card--trusted { background: var(--yellow, #eab308); color: var(--ink); }
.neo-card--trusted .neo-card__subtitle { color: var(--ink); font-weight: 900; background: #fff; padding: 4px 10px; border: 2px solid var(--ink); display: inline-block; border-radius: 4px; box-shadow: 2px 2px 0 var(--ink); transform: rotate(-2deg); margin-bottom: 12px; text-transform: uppercase; font-size: 14px;}
.neo-card--trusted .neo-card__amount { color: var(--ink); text-shadow: 2px 2px 0 #fff; font-size: 36px; font-weight: 900; margin-bottom: 20px; letter-spacing: -1px; }

.neo-btn {
  background: #00E5FF;
  border: 3px solid var(--ink, #000);
  box-shadow: 4px 4px 0px var(--ink, #000);
  border-radius: 4px 12px 4px 12px;
  padding: 14px 20px;
  font-weight: 900;
  font-size: 16px;
  color: var(--ink);
  cursor: pointer;
  width: 100%;
  text-transform: uppercase;
  transition: transform 0.1s, box-shadow 0.1s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  letter-spacing: 0.5px;
}
.neo-btn:active:not(:disabled) {
  transform: translate(4px, 4px);
  box-shadow: 0px 0px 0px var(--ink, #000);
}
.neo-btn:disabled, .neo-btn.disabled {
  background: #d4d4d8;
  color: #71717a;
  border-color: var(--ink);
  box-shadow: 4px 4px 0px var(--ink);
  cursor: not-allowed;
}

/* Weekly Stepper */
.weekly-stepper {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  position: relative;
  margin: 30px 0 40px 0;
  padding: 0 5px;
}
.weekly-stepper::before {
  content: '';
  position: absolute;
  top: 18px; 
  left: 20px;
  right: 20px;
  height: 6px;
  background: var(--ink, #000);
  z-index: 0;
  border-bottom: 2px dashed #fff;
}
.step-item {
  position: relative;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}
.step-item.active {
  transform: scale(1.15) translateY(-4px);
  transition: transform 0.2s;
}
.step-lbl {
  font-size: 10px;
  font-weight: 900;
  text-transform: uppercase;
  color: var(--ink);
  background: #fff;
  padding: 2px 6px;
  border: 2px solid var(--ink);
  box-shadow: 2px 2px 0 var(--ink);
  transform: rotate(2deg);
}
.step-item:nth-child(even) .step-lbl { transform: rotate(-2deg); }

/* Neo Alert */
.neo-alert {
  border: 4px solid var(--ink, #000);
  border-radius: 4px 12px 4px 12px;
  padding: 12px 16px;
  font-weight: 800;
  font-size: 13px;
  margin-bottom: 24px;
  box-shadow: 4px 4px 0 var(--ink, #000);
}
.neo-alert--success { background: #00FF66; color: var(--ink, #000); }
.neo-alert--warn { background: #00E5FF; color: var(--ink, #000); }
.neo-alert--error { background: #FF00E6; color: #fff; }

.stat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 24px;
}
.neo-stat {
  border: 4px solid var(--ink, #000);
  box-shadow: 5px 5px 0 var(--ink, #000);
  border-radius: 4px;
  padding: 16px;
  text-align: center;
  position: relative;
  overflow: hidden;
}
.neo-stat:nth-child(1) { background: #FF00E6; color: #fff; transform: rotate(-1deg); }
.neo-stat:nth-child(2) { background: #00FF66; color: var(--ink); transform: rotate(1deg); }

.neo-stat__val {
  font-size: 26px;
  font-weight: 900;
  letter-spacing: -1px;
  margin-bottom: 2px;
  text-shadow: 2px 2px 0 rgba(0,0,0,0.2);
}
.neo-stat__lbl {
  font-size: 11px;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-8px); }
}
</style>

<div class="neo-wrapper">
  <div class="neo-title">Check-in Harian</div>
  <div class="neo-subtitle">Kumpulkan streak & dapatkan saldo beli!</div>

  <?php if ($flash): ?>
  <div class="neo-alert neo-alert--<?= $flashType === 'error' ? 'error' : ($flashType === 'warn' ? 'warn' : 'success') ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <div class="neo-card neo-card--trusted">
    <div style="margin-bottom: 16px; animation: float 3s ease-in-out infinite;">
      <img src="/assets/chest.png" alt="Reward Chest" style="width: 90px; height: 90px; object-fit: contain; filter: drop-shadow(0px 8px 8px rgba(0,0,0,0.15));">
    </div>
    <div class="neo-card__subtitle">Reward Hari Ini</div>
    <div class="neo-card__amount">
      <?= format_rp($checkin_reward) ?>
    </div>

    <?php if (!$already): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="checkin">
      <button type="submit" class="neo-btn">
        <i class="ph-bold ph-target"></i> Klaim Saldo Harian
      </button>
    </form>
    <?php else: ?>
    <button class="neo-btn" disabled>
      <i class="ph-bold ph-check-circle"></i> Sudah Diklaim
    </button>
    <?php endif; ?>
  </div>

  <div class="neo-title" style="font-size: 18px; margin-top: 32px;">Minggu Ini</div>
  <div class="neo-subtitle" style="margin-bottom: 16px;">Progres Check-in 7 Hari</div>
  
  <div class="weekly-stepper">
    <?php for ($i = 1; $i <= 7; $i++): 
      $is_done = $i <= $completed_days;
      $is_active = (!$already && $i == $completed_days + 1);
      $class = $is_done ? 'done' : ($is_active ? 'active' : '');
      $img = ($i === 7) ? '/assets/chest.png' : '/assets/coins.png';
      $img_filter = $is_done || $is_active ? 'filter: drop-shadow(2px 2px 0px rgba(0,0,0,0.2));' : 'filter: grayscale(1) opacity(0.3);';
      $node_style = 'width:42px;height:42px;border:none;background:transparent;box-shadow:none;position:relative;margin:0 auto;';
    ?>
    <div class="step-item <?= $class ?>">
      <div class="step-node" style="<?= $node_style ?>">
        <img src="<?= $img ?>" style="width:100%;height:100%;object-fit:contain; <?= $img_filter ?>">
        <?php if ($is_done): ?>
          <div style="position:absolute;bottom:-2px;right:-4px;background:var(--green);color:#fff;border-radius:50%;width:18px;height:18px;font-size:11px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:1.5px 1.5px 0 var(--ink)"><i class="ph-bold ph-check"></i></div>
        <?php endif; ?>
      </div>
      <div class="step-lbl" style="margin-top:8px;<?= $is_active ? 'color:var(--yellow);font-size:11px;' : '' ?>">Hari <?= $i ?></div>
    </div>
    <?php endfor; ?>
  </div>

  <div class="stat-grid" style="margin-top: 24px;">
    <div class="neo-stat">
      <div class="neo-stat__val"><?= $streak ?></div>
      <div class="neo-stat__lbl"><i class="ph-fill ph-fire"></i> Hari Aktif</div>
    </div>
    <div class="neo-stat">
      <div class="neo-stat__val" style="font-size: 20px; margin-top: 6px;"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div class="neo-stat__lbl">Saldo Beli</div>
    </div>
  </div>

</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
