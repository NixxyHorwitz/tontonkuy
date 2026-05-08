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

<!-- How it works -->
<div class="card" style="margin-bottom:14px;border-left:4px solid var(--yellow)">
  <div class="card__body" style="padding:14px 16px">
    <div style="font-size:13px;font-weight:800;margin-bottom:10px">📖 Cara Kerja Upgrade</div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:12px;color:#555">
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span style="background:var(--yellow);border:1.5px solid var(--ink);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">1</span>
        <span><strong>Deposit saldo</strong> terlebih dahulu melalui menu Deposit (transfer bank atau QRIS). Saldo Deposit dipakai khusus untuk pembelian paket.</span>
      </div>
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span style="background:var(--yellow);border:1.5px solid var(--ink);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">2</span>
        <span><strong>Pilih paket</strong> membership yang sesuai budget & kebutuhanmu di bawah ini.</span>
      </div>
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span style="background:var(--yellow);border:1.5px solid var(--ink);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">3</span>
        <span><strong>Konfirmasi upgrade</strong> — harga paket langsung dipotong dari Saldo Deposit, membership aktif seketika!</span>
      </div>
    </div>
  </div>
</div>

<!-- Benefits info -->
<div class="card" style="margin-bottom:14px;border-left:4px solid var(--mint)">
  <div class="card__body" style="padding:14px 16px">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">✅ Keuntungan Paket Berbayar</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;font-size:12px;color:#555">
      <div>📹 Limit tonton lebih banyak/hari</div>
      <div>💸 Min. WD lebih rendah</div>
      <div>📈 Potensi earn lebih besar</div>
      <div>💰 Max. WD lebih tinggi</div>
      <div>⚡ Akses fitur eksklusif</div>
      <div>🎯 Prioritas dukungan admin</div>
    </div>
  </div>
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
        <div class="membership-card__feature">📹 <?= $m['watch_limit'] ?>× video per hari</div>
        <div class="membership-card__feature">⏳ Berlaku <?= $m['duration_days'] ?> hari</div>
        <?php if ((float)$m['min_wd'] > 0): ?>
        <div class="membership-card__feature">💸 Min. WD: <?= format_rp((float)$m['min_wd']) ?></div>
        <?php endif; ?>
        <div class="membership-card__feature">📤 Max. WD: <?= (float)$m['max_wd'] > 0 ? format_rp((float)$m['max_wd']) : '<span style="color:#4CAF82">Tanpa batas</span>' ?></div>
        <?php if ($m['description']): ?><div class="membership-card__feature">ℹ️ <?= htmlspecialchars($m['description']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <button type="submit" id="upgrade-btn" class="btn btn--primary btn--full btn--lg" disabled>
    Pilih Paket Dulu ↑
  </button>
</form>

<!-- FAQ / Notes -->
<div class="card" style="margin-top:14px;margin-bottom:8px">
  <div class="card__body" style="padding:14px 16px">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">❓ Yang Perlu Kamu Tahu</div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:12px;color:#555">
      <div>🔄 <strong>Upgrade saat masih aktif</strong> akan <em>mengganti</em> paket yang berjalan. Durasi dimulai ulang dari sekarang.</div>
      <div>💳 <strong>Saldo Deposit ≠ Saldo WD.</strong> Saldo Deposit hanya bisa dipakai beli paket, bukan ditarik langsung.</div>
      <div>💸 <strong>Limit Withdraw</strong> (Min & Max) mengikuti paket aktifmu. Upgrade untuk memperbesar limit withdraw.</div>
      <div>⚡ <strong>Aktivasi instan</strong> — tidak perlu menunggu konfirmasi admin. Paket langsung aktif begitu kamu klik upgrade.</div>
      <div>📅 <strong>Paket expired</strong> berarti kamu kembali ke limit free. Pastikan selalu perpanjang sebelum habis!</div>
    </div>
  </div>
</div>

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
