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

// Totals
$total_earned = (float)$user['total_earned'];
$total_dep    = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id=? AND status='confirmed'");
$total_dep->execute([$user['id']]); $total_dep = (float)$total_dep->fetchColumn();
$total_wd     = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='approved'");
$total_wd->execute([$user['id']]); $total_wd = (float)$total_wd->fetchColumn();

$pageTitle  = 'Riwayat — TontonKuy';
$activePage = 'history';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>📊 Riwayat Transaksi</h1>
  <p>Semua aktivitas akun kamu</p>
</div>

<!-- Summary stats -->
<div class="stat-row" style="margin-bottom:16px">
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px"><?= format_rp($total_earned) ?></div>
    <div class="stat-mini__lbl">🎁 Total Reward</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px"><?= format_rp($total_dep) ?></div>
    <div class="stat-mini__lbl">⬆️ Total Deposit</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px"><?= format_rp($total_wd) ?></div>
    <div class="stat-mini__lbl">💸 Total WD</div>
  </div>
</div>

<!-- Tabs -->
<div class="history-tabs">
  <a href="?tab=reward" class="history-tab <?= $tab==='reward'?'history-tab--active':'' ?>">🎁 Reward</a>
  <a href="?tab=deposit" class="history-tab <?= $tab==='deposit'?'history-tab--active':'' ?>">⬆️ Deposit</a>
  <a href="?tab=withdraw" class="history-tab <?= $tab==='withdraw'?'history-tab--active':'' ?>">💸 WD</a>
</div>

<!-- Reward Tab -->
<?php if ($tab === 'reward'): ?>
<div class="card">
  <?php if (empty($rewards)): ?>
  <div class="empty-state"><p>Belum ada riwayat nonton.</p></div>
  <?php else: ?>
  <div class="card__body">
    <?php foreach ($rewards as $r): ?>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--lime)">▶️</div>
      <div class="list-item__body">
        <div class="list-item__title"><?= htmlspecialchars($r['video_title'] ?? 'Video #'.$r['video_id']) ?></div>
        <div class="list-item__sub"><?= date('d M Y H:i', strtotime($r['watched_at'])) ?></div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount list-item__amount--green">+<?= format_rp((float)$r['reward_given']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Deposit Tab -->
<?php elseif ($tab === 'deposit'): ?>
<div class="card">
  <?php if (empty($deposits)): ?>
  <div class="empty-state"><p>Belum ada riwayat deposit.</p></div>
  <?php else: ?>
  <div class="card__body">
    <?php foreach ($deposits as $d): ?>
    <div class="list-item">
      <div class="list-item__icon" style="background:<?= $d['method']==='qris'?'var(--mint)':'var(--sky)' ?>">
        <?= $d['method']==='qris' ? '📱' : '🏦' ?>
      </div>
      <div class="list-item__body">
        <div class="list-item__title"><?= format_rp((float)$d['amount']) ?></div>
        <div class="list-item__sub"><?= strtoupper($d['method']) ?> · <?= date('d M Y H:i', strtotime($d['created_at'])) ?></div>
        <?php if ($d['admin_note']): ?>
        <div class="list-item__sub" style="color:var(--red)">📝 <?= htmlspecialchars($d['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <div class="list-item__right" style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
        <span class="badge badge--<?= match($d['status']){'confirmed'=>'success','pending'=>'warn','rejected'=>'error',default=>'error'} ?>">
          <?= ucfirst($d['status']) ?>
        </span>
        <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
        <a href="/pay?id=<?= $d['id'] ?>" class="btn btn--yellow btn--sm" style="padding:5px 12px;font-size:11px">▶ Lanjut Bayar</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Withdraw Tab -->
<?php elseif ($tab === 'withdraw'): ?>
<div class="card">
  <?php if (empty($wds)): ?>
  <div class="empty-state"><p>Belum ada riwayat penarikan.</p></div>
  <?php else: ?>
  <div class="card__body">
    <?php foreach ($wds as $w): ?>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--peach)">💸</div>
      <div class="list-item__body">
        <div class="list-item__title"><?= format_rp((float)$w['amount']) ?></div>
        <div class="list-item__sub"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M Y H:i', strtotime($w['created_at'])) ?></div>
        <?php if ($w['admin_note']): ?>
        <div class="list-item__sub" style="color:var(--red)">📝 <?= htmlspecialchars($w['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <div class="list-item__right">
        <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','rejected'=>'error',default=>'error'} ?>">
          <?= ucfirst($w['status']) ?>
        </span>
        <div class="list-item__amount list-item__amount--red" style="margin-top:4px">-<?= format_rp((float)$w['amount']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.history-tabs {
  display: flex; gap: 0;
  border: 2.5px solid var(--ink);
  border-radius: var(--radius-sm);
  overflow: hidden;
  box-shadow: 3px 3px 0 var(--ink);
  margin-bottom: 14px;
}
.history-tab {
  flex: 1; text-align: center;
  padding: 11px 6px;
  font-size: 13px; font-weight: 800;
  text-decoration: none;
  color: #888; background: var(--white);
  border-right: 2px solid var(--ink);
  transition: background .12s;
}
.history-tab:last-child { border-right: none; }
.history-tab--active { background: var(--yellow); color: var(--ink); }
.history-tab:hover:not(.history-tab--active) { background: #f5f5f5; }
</style>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
