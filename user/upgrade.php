<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
$flash = $flashType = '';

// Active membership info
$active_membership = null;
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $active_membership = $ms->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = (int)($_POST['membership_id'] ?? 0);
    $ms  = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen = $ms->fetch();

    if (!$chosen) {
        $flash = 'Paket tidak ditemukan.'; $flashType = 'error';
    } elseif ((float)$chosen['price'] == 0) {
        $flash = 'Paket Free tidak perlu upgrade.'; $flashType = 'error';
    } elseif ((float)$user['balance_dep'] < (float)$chosen['price']) {
        $flash = 'Saldo Deposit tidak mencukupi. Deposit terlebih dahulu.'; $flashType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            // Deduct from balance_dep (with atomic check to prevent race conditions)
            $stmt = $pdo->prepare("UPDATE users SET balance_dep=balance_dep-? WHERE id=? AND balance_dep >= ?");
            $stmt->execute([$chosen['price'], $user['id'], $chosen['price']]);
            
            if ($stmt->rowCount() > 0) {
                // Insert upgrade order (auto-confirmed via balance)
                $pdo->prepare("INSERT INTO upgrade_orders (user_id,membership_id,amount,status,confirmed_at) VALUES (?,?,?,'confirmed',NOW())")
                    ->execute([$user['id'], $mid, $chosen['price']]);
                // Activate membership
                $new_expires = date('Y-m-d H:i:s', strtotime("+{$chosen['duration_days']} days"));
                $pdo->prepare("UPDATE users SET membership_id=?, membership_expires_at=? WHERE id=?")
                    ->execute([$mid, $new_expires, $user['id']]);
                $pdo->commit();
                // Refresh user
                $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
                $flash = '🎉 Upgrade ke ' . htmlspecialchars($chosen['name']) . ' berhasil! Berlaku hingga ' . date('d M Y', strtotime($new_expires)) . '.';
                // Refresh active membership
                $active_membership = $chosen;
            } else {
                $pdo->rollBack();
                $flash = 'Saldo Deposit tidak mencukupi. Transaksi digagalkan.'; $flashType = 'error';
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = 'Terjadi kesalahan. Silakan coba lagi.'; $flashType = 'error';
        }
    }
}

$pageTitle  = 'Upgrade Paket — TontonKuy';
$activePage = 'upgrade';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>👑 Upgrade Paket</h1>
  <p>Tonton lebih banyak, earn lebih besar!</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Balance dep info -->
<div class="hero-card" style="background:var(--yellow);margin-bottom:16px">
  <div class="hero-card__label">💳 Saldo Deposit (untuk Upgrade)</div>
  <div class="hero-card__amount"><?= format_rp((float)$user['balance_dep']) ?></div>
  <div class="hero-card__sub">Upgrade langsung dipotong dari saldo deposit</div>
  <div class="hero-card__actions">
    <a href="/deposit" class="hero-card__btn">⬆️ Tambah Deposit</a>
    <a href="/checkin" class="hero-card__btn">📅 Check-in</a>
  </div>
</div>

<?php if ($active_membership): ?>
<div class="alert alert--info" style="margin-bottom:16px">
  ⭐ Paket aktif: <strong><?= htmlspecialchars($active_membership['name']) ?></strong>
  — Limit <?= $active_membership['watch_limit'] ?>× /hari,
  berlaku s/d <?= date('d M Y', strtotime($user['membership_expires_at'])) ?>
</div>
<?php endif; ?>

<!-- Packages -->
<form method="POST" id="upgrade-form">
  <?= csrf_field() ?>
  <input type="hidden" name="membership_id" id="chosen-id" value="">
  <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px">
    <?php $colors = ['#FF6B35','#4E9BFF','#9C6FFF','#4CAF82'];
    foreach ($memberships as $i => $m):
      if ((float)$m['price'] == 0) continue;
      $color = $colors[$i % count($colors)];
      $can_afford = (float)$user['balance_dep'] >= (float)$m['price'];
    ?>
    <div class="membership-card" id="card-<?= $m['id'] ?>"
         onclick="<?= $can_afford ? 'selectPlan('.$m['id'].','.$m['price'].')' : "nToast('Saldo deposit tidak cukup. Deposit dulu!','error')" ?>"
         style="cursor:pointer;transition:all .2s<?= !$can_afford ? ';opacity:.65' : '' ?>">
      <?php if ($i === 2): ?><div class="membership-card__badge">🔥 Populer</div><?php endif; ?>
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div>
          <div class="membership-card__name" style="color:<?= $color ?>"><?= htmlspecialchars($m['name']) ?></div>
          <div class="membership-card__price"><?= format_rp((float)$m['price']) ?><span>/<?= $m['duration_days'] ?> hari</span></div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
          <div style="width:44px;height:44px;background:<?= $color ?>22;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px">
            <?= ['⭐','🥈','🥇','💎'][$i] ?? '⭐' ?>
          </div>
          <span class="badge badge--<?= $can_afford ? 'success' : 'error' ?>" style="font-size:10px">
            <?= $can_afford ? '✓ Bisa' : '✗ Kurang' ?>
          </span>
        </div>
      </div>
      <div class="membership-card__features" style="margin-top:10px">
        <div class="membership-card__feature"><?= $m['watch_limit'] ?>× video per hari</div>
        <div class="membership-card__feature">Berlaku <?= $m['duration_days'] ?> hari</div>
        <?php if ($m['description']): ?><div class="membership-card__feature"><?= htmlspecialchars($m['description']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <button type="submit" id="upgrade-btn" class="btn btn--primary btn--full btn--lg" disabled>
    Pilih Paket Dulu ↑
  </button>
</form>

<script>
let selectedId = 0, selectedPrice = 0;
function selectPlan(id, price) {
  document.querySelectorAll('.membership-card').forEach(c => c.classList.remove('active'));
  document.getElementById('card-'+id).classList.add('active');
  document.getElementById('chosen-id').value = id;
  selectedId = id; selectedPrice = price;
  const btn = document.getElementById('upgrade-btn');
  btn.disabled = false;
  btn.textContent = '🚀 Upgrade — Rp ' + price.toLocaleString('id-ID');
}
document.getElementById('upgrade-form').onsubmit = function(e) {
  if (!selectedId) {
    e.preventDefault();
    nToast('Pilih paket terlebih dahulu!', 'warn');
    return;
  }
  // Ganti confirm() native dengan form data-confirm sederhana
  if (!this.dataset.confirmed) {
    e.preventDefault();
    nToast('Klik Upgrade lagi untuk konfirmasi — Rp ' + selectedPrice.toLocaleString('id-ID') + ' akan dipotong', 'warn', 3500);
    this.dataset.confirmed = '1';
    setTimeout(() => { delete this.dataset.confirmed; }, 4000);
    return;
  }
};
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
