<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$min_withdraw  = (float) setting($pdo, 'min_withdraw', '50000');
$wd_min_level  = (int)   setting($pdo, 'wd_min_level', '0');
$wd_lock_start = setting($pdo, 'wd_lock_start', '');
$wd_lock_end   = setting($pdo, 'wd_lock_end', '');
$wd_lock_notice= setting($pdo, 'wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');

$wd_locked    = is_wd_locked($pdo);
$user_level   = user_membership_level($pdo, $user);
$level_blocked= $wd_min_level > 0 && $user_level < $wd_min_level;

// Get min level membership name
$min_level_name = '';
if ($wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($wd_locked) {
        $flash = '⏰ ' . $wd_lock_notice; $flashType = 'error';
    } elseif ($level_blocked) {
        $flash = "Kamu perlu upgrade ke {$min_level_name} untuk bisa menarik saldo."; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        $bank    = trim($_POST['bank_name'] ?? '');
        $accnum  = trim($_POST['account_number'] ?? '');
        $accname = trim($_POST['account_name'] ?? '');

        if ($amount < $min_withdraw) {
            $flash = 'Minimal withdraw ' . format_rp($min_withdraw) . '.'; $flashType = 'error';
        } elseif ($amount > (float)$user['balance_wd']) {
            $flash = 'Saldo penarikan tidak mencukupi.'; $flashType = 'error';
        } elseif (!$bank || !$accnum || !$accname) {
            $flash = 'Lengkapi data rekening.'; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name) VALUES (?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname]);
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            $flash = 'Permintaan withdraw dikirim! Proses 1-3 hari kerja.';
        }
    }
}

// History
$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$pageTitle  = 'Withdraw — TontonKuy';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>⬇️ Tarik Saldo</h1>
  <p>Penarikan dari Saldo Penarikan saja</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Lock notice -->
<?php if ($wd_locked): ?>
<div class="alert alert--warn" style="margin-bottom:16px">
  🔒 <strong>Penarikan Ditutup</strong><br>
  <?= htmlspecialchars($wd_lock_notice) ?>
  <?php if ($wd_lock_start && $wd_lock_end): ?>
  <br><small>Jam lock: <?= htmlspecialchars($wd_lock_start) ?> – <?= htmlspecialchars($wd_lock_end) ?></small>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Level block notice -->
<?php if ($level_blocked): ?>
<div class="alert alert--warn" style="margin-bottom:16px">
  🔒 Penarikan memerlukan paket minimum <strong><?= htmlspecialchars($min_level_name) ?></strong>.
  <a href="/upgrade" style="font-weight:800;color:inherit"> Upgrade sekarang →</a>
</div>
<?php endif; ?>

<!-- Balance card -->
<div class="hero-card" style="background:var(--mint);margin-bottom:16px">
  <div class="hero-card__label">💸 Saldo Penarikan</div>
  <div class="hero-card__amount"><?= format_rp((float)$user['balance_wd']) ?></div>
  <div class="hero-card__sub">Min. withdraw: <?= format_rp($min_withdraw) ?></div>
</div>

<!-- Form -->
<?php if (!$wd_locked && !$level_blocked): ?>
<div class="card">
  <div class="card__header"><div class="card__title">🏦 Form Penarikan</div></div>
  <div class="card__body">
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label">Jumlah Withdraw (Rp)</label>
        <div class="input-wrap">
          <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          <input class="form-control" type="number" name="amount" min="<?= $min_withdraw ?>" max="<?= $user['balance_wd'] ?>" step="1000" placeholder="Min. <?= number_format($min_withdraw,0,'','') ?>" required>
        </div>
      </div>
      <!-- Quick amounts -->
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
        <?php foreach ([50000,100000,200000,500000] as $q): ?>
        <?php if ($q <= (float)$user['balance_wd']): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="document.querySelector('[name=amount]').value=<?= $q ?>"><?= format_rp($q) ?></button>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Nama Bank / E-Wallet</label>
        <input class="form-control" type="text" name="bank_name" value="<?= htmlspecialchars($_POST['bank_name'] ?? '') ?>" placeholder="Contoh: BCA, GoPay, OVO" required>
      </div>
      <div class="form-group">
        <label class="form-label">Nomor Rekening / Akun</label>
        <input class="form-control" type="text" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="08xxxxxxxxxx atau 1234567890" required>
      </div>
      <div class="form-group">
        <label class="form-label">Nama Pemilik Rekening</label>
        <input class="form-control" type="text" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="Nama sesuai rekening" required>
      </div>
      <?php if ((float)$user['balance_wd'] < $min_withdraw): ?>
        <div class="alert alert--warn" style="margin-bottom:12px">Saldo penarikan belum mencukupi minimum withdraw.</div>
        <button type="submit" class="btn btn--primary btn--full" disabled>Saldo Tidak Cukup</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="btn btn--primary btn--full">Ajukan Penarikan</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<script>
(function(){
  const form = document.querySelector('form[method="POST"]');
  const btn  = document.getElementById('wd-submit-btn');
  if (!form || !btn) return;
  form.addEventListener('submit', function(e) {
    if (!form.dataset.confirmed) {
      e.preventDefault();
      const amt = document.querySelector('[name=amount]');
      const display = amt && amt.value ? ' Rp ' + Number(amt.value).toLocaleString('id-ID') : '';
      nToast('Klik Ajukan lagi untuk konfirmasi penarikan' + display, 'warn', 3500);
      form.dataset.confirmed = '1';
      setTimeout(() => delete form.dataset.confirmed, 4000);
    }
  });
})();
</script>
<?php else: ?>
<div class="card">
  <div class="card__body" style="text-align:center;padding:32px 20px">
    <div style="font-size:48px;margin-bottom:12px">🔒</div>
    <div style="font-weight:800;margin-bottom:8px">Penarikan Tidak Tersedia</div>
    <div style="color:var(--text2);font-size:14px"><?= $wd_locked ? htmlspecialchars($wd_lock_notice) : 'Upgrade akun untuk mengaktifkan penarikan.' ?></div>
  </div>
</div>
<?php endif; ?>

<!-- History -->
<?php if (!empty($wds)): ?>
<div class="section-header" style="margin-top:20px"><div class="section-title">📜 Riwayat Withdraw</div></div>
<div class="card"><div class="card__body">
  <?php foreach ($wds as $w): ?>
  <div class="list-item">
    <div class="list-item__icon" style="background:var(--brand-soft,#fff5cc)">💸</div>
    <div class="list-item__body">
      <div class="list-item__title"><?= format_rp((float)$w['amount']) ?></div>
      <div class="list-item__sub"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M Y H:i', strtotime($w['created_at'])) ?></div>
      <?php if ($w['admin_note']): ?>
      <div class="list-item__sub" style="color:var(--red,#ef4444)">📝 <?= htmlspecialchars($w['admin_note']) ?></div>
      <?php endif; ?>
    </div>
    <div class="list-item__right">
      <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','rejected'=>'error'} ?>">
        <?= ucfirst($w['status']) ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
