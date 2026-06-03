<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$tab = $_GET['tab'] ?? 'reward';

// Reward / watch history
$rewards = $pdo->prepare(
    "SELECT wh.*, v.title as video_title FROM watch_history wh
     LEFT JOIN videos v ON v.id=wh.video_id
     WHERE wh.user_id=? ORDER BY wh.watched_at DESC LIMIT 30"
);
$rewards->execute([$user['id']]); $rewards = $rewards->fetchAll();

// Deposits
$deposits = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
$deposits->execute([$user['id']]); $deposits = $deposits->fetchAll();

// Withdrawals
$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
$wds->execute([$user['id']]); $wds = $wds->fetchAll();

// Payment Channels Logos
$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

// Totals
$total_earned = (float)$user['total_earned'];
$total_dep    = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id=? AND status='confirmed'");
$total_dep->execute([$user['id']]); $total_dep = (float)$total_dep->fetchColumn();
$total_wd     = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='approved'");
$total_wd->execute([$user['id']]); $total_wd = (float)$total_wd->fetchColumn();

$pageTitle  = 'Riwayat — NontonKuy';
$activePage = 'history';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar" style="margin-bottom:16px">
  <h1 style="display:flex;align-items:center;gap:6px"><i class="ph-bold ph-list-dashes" style="color:var(--brand)"></i> Riwayat Transaksi</h1>
  <p>Semua aktivitas akun kamu</p>
</div>

<!-- Summary stats -->
<div class="stat-row" style="margin-bottom:20px;display:flex;gap:12px">
  <div style="flex:1;background:#fde047;border:3px solid var(--ink);border-radius:12px;padding:12px;box-shadow:4px 4px 0 var(--ink);color:var(--ink);text-align:center">
    <div style="font-size:10px;font-weight:900;margin-bottom:6px;text-transform:uppercase"><i class="ph-bold ph-gift" style="font-size:14px;vertical-align:middle;color:#b45309"></i> Reward</div>
    <div style="font-size:16px;font-weight:900;letter-spacing:-0.5px"><?= format_rp($total_earned) ?></div>
  </div>
  <div style="flex:1;background:#facc15;border:3px solid var(--ink);border-radius:12px;padding:12px;box-shadow:4px 4px 0 var(--ink);color:var(--ink);text-align:center">
    <div style="font-size:10px;font-weight:900;margin-bottom:6px;text-transform:uppercase"><i class="ph-bold ph-wallet" style="font-size:14px;vertical-align:middle;color:#a16207"></i> Top Up</div>
    <div style="font-size:16px;font-weight:900;letter-spacing:-0.5px"><?= format_rp($total_dep) ?></div>
  </div>
  <div style="flex:1;background:#eab308;border:3px solid var(--ink);border-radius:12px;padding:12px;box-shadow:4px 4px 0 var(--ink);color:var(--ink);text-align:center">
    <div style="font-size:10px;font-weight:900;margin-bottom:6px;text-transform:uppercase"><i class="ph-bold ph-paper-plane-right" style="font-size:14px;vertical-align:middle;color:#854d0e"></i> Tarik</div>
    <div style="font-size:16px;font-weight:900;letter-spacing:-0.5px"><?= format_rp($total_wd) ?></div>
  </div>
</div>

<!-- Tabs -->
<div class="history-tabs">
  <a href="?tab=reward" class="history-tab <?= $tab==='reward'?'history-tab--active':'' ?>"><i class="ph-bold ph-gift" style="font-size:14px;vertical-align:middle"></i> Reward</a>
  <a href="?tab=deposit" class="history-tab <?= $tab==='deposit'?'history-tab--active':'' ?>"><i class="ph-bold ph-wallet" style="font-size:14px;vertical-align:middle"></i> Top Up</a>
  <a href="?tab=withdraw" class="history-tab <?= $tab==='withdraw'?'history-tab--active':'' ?>"><i class="ph-bold ph-paper-plane-right" style="font-size:14px;vertical-align:middle"></i> Penarikan</a>
</div>

<!-- Reward Tab -->
<?php if ($tab === 'reward'): ?>
<div class="card-trusted">
  <?php if (empty($rewards)): ?>
  <div class="empty-state"><p>Belum ada riwayat nonton.</p></div>
  <?php else: ?>
  <div class="card__body" style="padding:0">
    <?php foreach ($rewards as $r): ?>
    <div class="list-item" style="padding:14px 16px;border-bottom:2px solid var(--ink)">
      <div class="list-item__icon" style="background:#ecfdf5;color:var(--green);border:2px solid var(--ink);width:36px;height:36px;font-size:18px;display:flex;align-items:center;justify-content:center;border-radius:8px">
        <i class="ph-fill ph-play-circle"></i>
      </div>
      <div class="list-item__body">
        <div class="list-item__title" style="font-weight:900;color:var(--ink)"><?= htmlspecialchars($r['video_title'] ?? 'Video #'.$r['video_id']) ?></div>
        <div class="list-item__sub" style="font-weight:700"><?= date('d M Y H:i', strtotime($r['watched_at'])) ?></div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount" style="color:var(--green);font-weight:900;font-size:14px">+<?= format_rp((float)$r['reward_given']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Deposit Tab -->
<?php elseif ($tab === 'deposit'): ?>
<div class="card-trusted">
  <?php if (empty($deposits)): ?>
  <div class="empty-state"><p>Belum ada riwayat top up.</p></div>
  <?php else: ?>
  <div class="card__body" style="padding:0">
    <?php foreach ($deposits as $d): ?>
    <?php $dl = $channel_logos[strtolower($d['method'])] ?? null; ?>
    <div class="list-item" style="padding:14px 16px;border-bottom:2px solid var(--ink)">
      <?php if ($dl): ?>
      <div class="list-item__icon" style="background:transparent;padding:0;border:2px solid var(--ink);border-radius:8px;width:36px;height:36px;overflow:hidden">
        <img src="/assets/banks/<?= htmlspecialchars($dl) ?>" style="width:100%;height:100%;object-fit:contain;background:#fff">
      </div>
      <?php else: ?>
        <div class="list-item__icon" style="background:#fef08a;color:#d97706;border:2px solid var(--ink);width:36px;height:36px;font-size:18px;display:flex;align-items:center;justify-content:center;border-radius:8px">
          <i class="<?= $d['method']==='qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
        </div>
      <?php endif; ?>
      <div class="list-item__body">
        <div class="list-item__title" style="font-weight:900;color:var(--ink)"><?= format_rp((float)$d['amount']) ?></div>
        <div class="list-item__sub" style="font-weight:700"><?= strtoupper($d['method']) ?> · <?= date('d M Y H:i', strtotime($d['created_at'])) ?></div>
        <?php if ($d['admin_note']): ?>
        <div class="list-item__sub" style="color:var(--red);font-weight:800;display:flex;align-items:center;gap:2px"><i class="ph-bold ph-note"></i> <?= htmlspecialchars($d['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <div class="list-item__right" style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
        <span class="badge badge--<?= match($d['status']){'confirmed'=>'success','pending'=>'warn','rejected'=>'error',default=>'error'} ?>" style="font-size:10px;font-weight:800">
          <?= ucfirst($d['status']) ?>
        </span>
        <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
        <a href="/pay?id=<?= $d['id'] ?>" class="btn btn--yellow btn--sm" style="padding:4px 10px;font-size:10px;display:flex;align-items:center;gap:4px"><i class="ph-bold ph-arrow-right"></i> Bayar</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Withdraw Tab -->
<?php elseif ($tab === 'withdraw'): ?>
<div class="card-trusted">
  <?php if (empty($wds)): ?>
  <div class="empty-state"><p>Belum ada riwayat penarikan.</p></div>
  <?php else: ?>
  <div class="card__body" style="padding:0">
    <?php foreach ($wds as $w): ?>
    <?php $wl = $channel_logos[strtolower($w['bank_name'])] ?? null; ?>
    <div class="list-item" style="padding:14px 16px;border-bottom:2px solid var(--ink)">
      <?php if ($wl): ?>
      <div class="list-item__icon" style="background:transparent;padding:0;border:2px solid var(--ink);border-radius:8px;width:36px;height:36px;overflow:hidden">
        <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="width:100%;height:100%;object-fit:contain;background:#fff">
      </div>
      <?php else: ?>
      <div class="list-item__icon" style="background:#fef08a;color:#d97706;border:2px solid var(--ink);width:36px;height:36px;font-size:18px;display:flex;align-items:center;justify-content:center;border-radius:8px">
        <i class="ph-bold ph-bank"></i>
      </div>
      <?php endif; ?>
      <div class="list-item__body">
        <div class="list-item__title" style="font-weight:900;color:var(--ink)"><?= format_rp((float)$w['amount']) ?></div>
        <div class="list-item__sub" style="font-weight:700"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M Y H:i', strtotime($w['created_at'])) ?></div>
        <?php if ($w['admin_note']): ?>
        <div class="list-item__sub" style="color:var(--red);font-weight:800;display:flex;align-items:center;gap:2px"><i class="ph-bold ph-note"></i> <?= htmlspecialchars($w['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <div class="list-item__right">
        <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','rejected'=>'error','refunded'=>'info',default=>'error'} ?>" style="font-size:10px;font-weight:800">
          <?= match($w['status']){'approved'=>'Sukses', 'pending'=>'Menunggu', 'hold'=>'Ditahan', 'rejected'=>'Ditolak', 'refunded'=>'Dikembalikan', default=>ucfirst($w['status'])} ?>
        </span>
        <div class="list-item__amount" style="margin-top:6px;color:var(--red);font-weight:900;font-size:13px;text-align:right">-<?= format_rp((float)$w['amount']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.history-tabs {
  display: flex; gap: 8px;
  margin-bottom: 20px;
}
.history-tab {
  flex: 1; text-align: center;
  padding: 10px 6px;
  font-size: 12px; font-weight: 800;
  text-decoration: none;
  color: var(--ink); background: #f8fafc;
  border: 3px solid var(--ink);
  border-radius: 12px;
  box-shadow: 2px 2px 0 var(--ink);
  transition: transform .12s, box-shadow .12s;
  display: flex; align-items: center; justify-content: center; gap: 4px;
}
.history-tab:active { transform: translate(2px, 2px); box-shadow: 0 0 0 var(--ink); }
.history-tab--active { background: #fde047; border-width:3px; box-shadow: 3px 3px 0 var(--ink); }

.card-trusted { background: #fff; border: 3px solid var(--ink); border-radius: 12px; box-shadow: 4px 4px 0 var(--ink); overflow: hidden; margin-bottom: 16px; }
.card-trusted .list-item:last-child { border-bottom: none !important; }
</style>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
