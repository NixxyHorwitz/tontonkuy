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
        $flash = "Upgrade ke {$min_level_name} untuk bisa menarik saldo."; $flashType = 'error';
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
            $flash = '✅ Permintaan withdraw dikirim! Proses 1-3 hari kerja.';
        }
    }
}

$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$pageTitle  = 'Withdraw — TontonKuy';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
.wd-bal{border:2.5px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);background:var(--mint);padding:14px 16px;margin-bottom:12px}
.wd-bal__lbl{font-size:11px;font-weight:700;color:#444;margin-bottom:2px}
.wd-bal__val{font-size:22px;font-weight:900}
.wd-bal__min{font-size:11px;color:#555;margin-top:2px}
.qty-pills{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
.qty-pills button{font-size:11px;padding:4px 10px}
</style>

<!-- Balance -->
<div class="wd-bal">
  <div class="wd-bal__lbl">💸 Saldo Penarikan</div>
  <div class="wd-bal__val"><?= format_rp((float)$user['balance_wd']) ?></div>
  <div class="wd-bal__min">Min. withdraw: <?= format_rp($min_withdraw) ?></div>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Lock notice -->
<?php if ($wd_locked): ?>
<div class="alert alert--warn" style="margin-bottom:10px;font-size:12px">
  🔒 <strong>Penarikan Ditutup</strong> — <?= htmlspecialchars($wd_lock_notice) ?>
  <?php if ($wd_lock_start && $wd_lock_end): ?>
  <br><small>Jam lock: <?= htmlspecialchars($wd_lock_start) ?> – <?= htmlspecialchars($wd_lock_end) ?></small>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Level block notice — hidden, revealed by JS on submit attempt only when balance is sufficient -->
<?php if ($level_blocked): ?>
<div id="level-blocked-notice" class="alert alert--warn" style="display:none;margin-bottom:10px;font-size:12px;align-items:center;justify-content:space-between;gap:8px;flex-wrap:nowrap">
  <span>🔒 Kamu perlu upgrade ke <strong><?= htmlspecialchars($min_level_name) ?></strong> untuk bisa menarik saldo.</span>
  <a href="/upgrade" class="btn btn--yellow btn--sm" style="white-space:nowrap;font-size:11px;padding:4px 10px;flex-shrink:0">Upgrade →</a>
</div>
<?php endif; ?>

<!-- Form -->
<div class="card">
  <div class="card__header"><div class="card__title" style="font-size:14px">🏦 Form Penarikan</div></div>
  <div class="card__body">
    <form method="POST" id="wd-form">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Jumlah Withdraw (Rp)</label>
        <input class="form-control" type="number" name="amount"
               min="<?= $min_withdraw ?>" max="<?= $user['balance_wd'] ?>"
               step="1000" placeholder="Min. <?= number_format($min_withdraw,0,'','') ?>" required>
      </div>
      <div class="qty-pills">
        <?php foreach ([50000,100000,200000,500000] as $q): ?>
        <?php if ($q <= (float)$user['balance_wd']): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="document.querySelector('[name=amount]').value=<?= $q ?>"><?= format_rp($q) ?></button>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Nama Bank / E-Wallet</label>
        <input class="form-control" type="text" name="bank_name"
               value="<?= htmlspecialchars($_POST['bank_name'] ?? '') ?>"
               placeholder="BCA, GoPay, OVO, Dana..." required>
      </div>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Nomor Rekening / Akun</label>
        <input class="form-control" type="text" name="account_number"
               value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>"
               placeholder="08xxxxxxxxxx atau nomor rekening" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label class="form-label" style="font-size:12px">Nama Pemilik Rekening</label>
        <input class="form-control" type="text" name="account_name"
               value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>"
               placeholder="Nama sesuai rekening" required>
      </div>

      <?php if ((float)$user['balance_wd'] < $min_withdraw): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px">💸 Saldo Belum Cukup</button>
      <?php elseif ($wd_locked): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px">⏰ Sedang Ditutup</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="btn btn--primary btn--full" style="font-size:13px">💸 Ajukan Penarikan</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<script>
(function(){
  const form    = document.getElementById('wd-form');
  const btn     = document.getElementById('wd-submit-btn');
  const notice  = document.getElementById('level-blocked-notice');
  const minWd   = <?= (int)$min_withdraw ?>;
  const balWd   = <?= (float)$user['balance_wd'] ?>;
  const levelBlocked = <?= $level_blocked ? 'true' : 'false' ?>;

  if (!form) return;

  form.addEventListener('submit', function(e) {
    const amtInput = document.querySelector('[name=amount]');
    const amt = amtInput ? parseFloat(amtInput.value) : 0;

    // Show level-blocked notice only when user has enough balance and tries to submit
    if (levelBlocked && balWd >= minWd) {
      e.preventDefault();
      if (notice) { notice.style.display = 'flex'; notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
      return;
    }

    // Standard double-click confirmation
    if (btn && !form.dataset.confirmed) {
      e.preventDefault();
      const display = amt ? ' Rp ' + amt.toLocaleString('id-ID') : '';
      nToast('Klik Ajukan lagi untuk konfirmasi penarikan' + display, 'warn', 3500);
      form.dataset.confirmed = '1';
      setTimeout(() => delete form.dataset.confirmed, 4000);
    }
  });
})();
</script>

<!-- History -->
<?php if (!empty($wds)): ?>
<div class="section-header" style="margin-top:14px">
  <div class="section-title" style="font-size:13px">📜 Riwayat Withdraw</div>
  <a href="/history" class="section-link">Lihat semua →</a>
</div>
<div class="card"><div class="card__body" style="padding:4px 0">
  <?php foreach ($wds as $w): ?>
  <div class="list-item" style="padding:8px 14px">
    <div class="list-item__icon" style="background:var(--brand-soft,#fff5cc);width:30px;height:30px;font-size:14px">💸</div>
    <div class="list-item__body">
      <div class="list-item__title" style="font-size:13px"><?= format_rp((float)$w['amount']) ?></div>
      <div class="list-item__sub" style="font-size:10px"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M H:i', strtotime($w['created_at'])) ?></div>
      <?php if ($w['admin_note']): ?>
      <div class="list-item__sub" style="color:var(--red,#ef4444);font-size:10px">📝 <?= htmlspecialchars($w['admin_note']) ?></div>
      <?php endif; ?>
    </div>
    <div class="list-item__right">
      <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','rejected'=>'error'} ?>" style="font-size:10px">
        <?= ucfirst($w['status']) ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
