<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$wd_lock_notice = setting($pdo, 'wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');
$wd_lock_start  = setting($pdo, 'wd_lock_start', '');
$wd_lock_end    = setting($pdo, 'wd_lock_end', '');

// Fetch membership min/max WD — only if membership is still active (not expired)
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT min_wd, max_wd FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT min_wd, max_wd FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$min_withdraw  = $user_mem ? (float)$user_mem['min_wd'] : 0;
$max_withdraw  = $user_mem ? (float)$user_mem['max_wd'] : 0;
$max_available = min((float)$user['balance_wd'], $max_withdraw > 0 ? $max_withdraw : (float)$user['balance_wd']);

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

$wd_locked = is_wd_locked($pdo);

// Level block — only enforced if admin enables the toggle
$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

// Cek apakah ada WD pending
$pending_wd = $pdo->prepare("SELECT id FROM withdrawals WHERE user_id=? AND status='pending' LIMIT 1");
$pending_wd->execute([$user['id']]);
$has_pending_wd = (bool)$pending_wd->fetchColumn();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user['can_withdraw']) {
        $flash = '❌ Akses Withdraw dibatasi. Hubungi admin untuk informasi lebih lanjut.'; $flashType = 'error';
    } elseif ($wd_locked) {
        $flash = '⏰ ' . $wd_lock_notice; $flashType = 'error';
    } elseif ($has_pending_wd) {
        $flash = '⏳ Kamu masih memiliki WD yang sedang diproses. Tunggu hingga selesai sebelum mengajukan yang baru.'; $flashType = 'error';
    } elseif ($level_blocked) {
        $flash = "Upgrade ke {$min_level_name} untuk bisa menarik saldo."; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        
        $bank    = $has_bank ? $user['bank_name'] : trim($_POST['bank_name'] ?? '');
        $accnum  = $has_bank ? $user['account_number'] : trim($_POST['account_number'] ?? '');
        $accname = $has_bank ? $user['account_name'] : trim($_POST['account_name'] ?? '');

        if ($amount < $min_withdraw) {
            $flash = 'Minimal withdraw ' . format_rp($min_withdraw) . '.'; $flashType = 'error';
        } elseif ($max_withdraw > 0 && $amount > $max_withdraw) {
            $flash = 'Maksimal withdraw ' . format_rp($max_withdraw) . '.'; $flashType = 'error';
        } elseif ($amount > (float)$user['balance_wd']) {
            $flash = 'Saldo penarikan tidak mencukupi.'; $flashType = 'error';
        } elseif (!$bank || !$accnum || !$accname) {
            $flash = 'Lengkapi data rekening.'; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            if (!$has_bank) {
                $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")->execute([$bank, $accnum, $accname, $user['id']]);
                $has_bank = true;
                $user['bank_name'] = $bank;
                $user['account_number'] = $accnum;
                $user['account_name'] = $accname;
            }
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name) VALUES (?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname]);
            $wd_id = $pdo->lastInsertId();
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            
            $msg = "<b>💸 WITHDRAW BARU</b>\nUser: {$user['username']}\nAmount: " . format_rp((float)$amount) . "\nBank: {$bank} - {$accnum}\na/n: {$accname}\nStatus: Pending";
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'wd_approve_'.$wd_id], ['text'=>'❌ Reject', 'callback_data'=>'wd_reject_'.$wd_id]],
                [['text'=>'⏸ Hold (Selesai non-refund)', 'callback_data'=>'wd_hold_'.$wd_id]],
                [['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_wd_'.$wd_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb);
            
            $flash = '✅ Permintaan withdraw dikirim! Proses 1-3 hari kerja.';
        }
    }
}

$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$wd_estimation = '';
if ($wd_lock_start && $wd_lock_end) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $wd_lock_start);
    $e_ts = strtotime(date('Y-m-d ') . $wd_lock_end);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "⏳ Penarikan saat ini <strong>DITUTUP</strong>. Akan dibuka dalam <strong>{$h} jam {$m} menit</strong>.";
    } else {
        if ($s_ts <= $now_ts) $s_ts += 86400;
        $diff = $s_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "✅ Penarikan saat ini <strong>DIBUKA</strong>. Akan ditutup dalam <strong>{$h} jam {$m} menit</strong>.";
    }
}

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
  <div class="wd-bal__min">Min: <?= format_rp($min_withdraw) ?><?= $max_withdraw > 0 ? ' | Max: '.format_rp($max_withdraw) : '' ?></div>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Lock notice / Estimation -->
<?php if ($wd_estimation): ?>
<div class="alert <?= $wd_locked ? 'alert--error' : 'alert--success' ?>" style="margin-bottom:10px;font-size:12px;background:<?= $wd_locked ? 'rgba(255,59,48,0.1)' : 'rgba(52,199,89,0.1)' ?>;border:1px solid <?= $wd_locked ? 'rgba(255,59,48,0.3)' : 'rgba(52,199,89,0.3)' ?>">
  <div style="margin-bottom:4px"><?= $wd_estimation ?></div>
  <?php if ($wd_locked && $wd_lock_notice): ?>
  <div style="margin-bottom:4px"><em>"<?= htmlspecialchars($wd_lock_notice) ?>"</em></div>
  <?php endif; ?>
  <div style="font-size:11px;opacity:0.8">Jam operasional: <?= date('h:i A', strtotime($wd_lock_end)) ?> – <?= date('h:i A', strtotime($wd_lock_start)) ?></div>
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
<?php if (!$user['can_withdraw']): ?>
<div class="card card--danger" style="margin-bottom:15px;background:rgba(255,59,48,0.1);border:1px solid rgba(255,59,48,0.3)">
  <div class="card__body" style="text-align:center;padding:20px 15px">
    <div style="font-size:24px;margin-bottom:10px">🚫</div>
    <h6 style="color:#F44E3B;margin-bottom:5px;font-weight:700">Akses Withdraw Dibatasi</h6>
    <div style="font-size:12px;color:#aaa">Akun kamu saat ini tidak diizinkan untuk melakukan penarikan dana. Silakan hubungi admin untuk informasi lebih lanjut.</div>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card__header"><div class="card__title" style="font-size:14px">🏦 Form Penarikan</div></div>
  <div class="card__body">
    <form method="POST" id="wd-form">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Jumlah Withdraw (Rp)</label>
        <input class="form-control" type="number" name="amount"
               min="<?= $min_withdraw ?>" max="<?= $max_available ?>"
               step="1000" placeholder="Min. <?= number_format($min_withdraw,0,'','') ?>" required>
      </div>
      <div class="qty-pills">
        <?php foreach ([50000,100000,200000,500000] as $q): ?>
        <?php if ($q <= $max_available): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="document.querySelector('[name=amount]').value=<?= $q ?>"><?= format_rp($q) ?></button>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($has_bank): ?>
      <div class="card card--mint" style="margin-bottom:12px">
        <div class="card__body" style="padding:10px 12px">
          <div style="font-size:12px;font-weight:800;color:#555;margin-bottom:4px">🏦 Bank Tujuan</div>
          <div style="font-size:13px;font-weight:700">
            <?= htmlspecialchars($user['bank_name']) ?><br>
            <?= htmlspecialchars($user['account_number']) ?><br>
            <?= htmlspecialchars($user['account_name']) ?>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="alert alert--warn" style="margin-bottom:12px;font-size:12px">⚠️ Harap isi data rekening bank. Data ini tidak dapat diubah setelah diisi!</div>
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
      <?php endif; ?>

      <?php if ($has_pending_wd): ?>
        <div class="alert alert--warn" style="margin-bottom:10px;font-size:12px">
          ⏳ <strong>Ada WD pending.</strong> Kamu masih memiliki penarikan yang sedang diproses. Tunggu hingga selesai sebelum mengajukan yang baru.
        </div>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px">⏳ WD Pending — Tunggu Dulu</button>
      <?php elseif ((float)$user['balance_wd'] < $min_withdraw): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px">💸 Saldo Belum Cukup</button>
      <?php elseif ($wd_locked): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px">⏰ Sedang Ditutup</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="btn btn--primary btn--full" style="font-size:13px">💸 Ajukan Penarikan</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

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
      <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','hold'=>'warn','rejected'=>'error',default=>'error'} ?>" style="font-size:10px">
        <?= ucfirst($w['status']) ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
