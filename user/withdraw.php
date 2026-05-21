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
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
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

// Edit rekening logic
$can_edit_bank    = (bool)($user_mem['allow_edit_bank'] ?? 0);
$edit_bank_min_dep = (int)($user['edit_bank_deposit_min'] ?? 50000);
$dep_ok_for_edit  = (float)$user['balance_dep'] >= $edit_bank_min_dep;
$bank_editable    = $has_bank && $can_edit_bank && $dep_ok_for_edit;

// Handle edit rekening POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_bank') {
    if (!$bank_editable) {
        $flash = '❌ Tidak diizinkan untuk mengubah rekening.'; $flashType = 'error';
    } else {
        $new_bank    = trim($_POST['bank_name'] ?? '');
        $new_accnum  = trim($_POST['account_number'] ?? '');
        $new_accname = trim($_POST['account_name'] ?? '');
        if (!$new_bank || !$new_accnum || !$new_accname) {
            $flash = 'Semua field rekening wajib diisi.'; $flashType = 'error';
        } else {
            $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")
                ->execute([$new_bank, $new_accnum, $new_accname, $user['id']]);
            $flash = '✅ Rekening berhasil diperbarui!';
            // Refresh user
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            $has_bank = true;
        }
    }
}


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
            $wd_status = (isset($user_mem['wd_hold']) && $user_mem['wd_hold'] == 1) ? 'hold' : 'pending';
            
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name,status) VALUES (?,?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname, $wd_status]);
            $wd_id = $pdo->lastInsertId();
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            
            $levelInfo = $user_mem ? ($user_mem['name'] ?? 'Free') : 'Free';
            $wdHoldNote = ($user_mem['wd_hold'] ?? 0) ? ' ⏸ (Auto Hold)' : '';
            $msg = "<b>💸 WITHDRAW BARU</b>\n👤 User: {$user['username']}\n🏅 Level: {$levelInfo}{$wdHoldNote}\n💰 Amount: " . format_rp((float)$amount) . "\n🏦 Bank: {$bank} - {$accnum}\n👨‍💼 a/n: {$accname}\n📋 Status: " . ucfirst($wd_status);
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'wd_approve_'.$wd_id], ['text'=>'❌ Reject', 'callback_data'=>'wd_reject_'.$wd_id]],
                [['text'=>'⏸ Hold (Selesai non-refund)', 'callback_data'=>'wd_hold_'.$wd_id]],
                [['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_wd_'.$wd_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb);
            
            $flash = '✅ Permintaan withdraw dikirim! Proses 1-10 menit.';
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
.qty-pills{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
.qty-pills button{font-size:11px;padding:4px 10px}
</style>

<!-- Balance -->
<div class="wd-bal">
  <div class="wd-bal__lbl">💸 Saldo Penarikan</div>
  <div class="wd-bal__val"><?= format_rp((float)$user['balance_wd']) ?></div>
</div>

<div class="alert" style="margin-bottom:12px;font-size:12px;background:rgba(88,86,214,0.1);border:1px solid rgba(88,86,214,0.3);color:var(--ink)">
  ℹ️ <strong>Batas Penarikan:</strong> Minimal <?= format_rp($min_withdraw) ?><?= $max_withdraw > 0 ? ' & Maksimal ' . format_rp($max_withdraw) : '' ?>
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
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <div style="font-size:12px;font-weight:800;color:#555">🏦 Bank Tujuan</div>
            <?php if ($can_edit_bank): ?>
              <?php if ($dep_ok_for_edit): ?>
              <button type="button" onclick="toggleEditBank()" class="btn btn--ghost btn--sm" style="font-size:10px;padding:3px 8px">✏️ Edit Rekening</button>
              <?php else: ?>
              <span title="Butuh saldo deposit minimal <?= format_rp($edit_bank_min_dep) ?>" style="font-size:10px;color:#f59e0b;font-weight:700;cursor:help">🔒 Min. Depo <?= format_rp($edit_bank_min_dep) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <!-- Tampilan normal -->
          <div id="bank-display">
            <div style="font-size:13px;font-weight:700">
              <?= htmlspecialchars($user['bank_name']) ?><br>
              <?= htmlspecialchars($user['account_number']) ?><br>
              <?= htmlspecialchars($user['account_name']) ?>
            </div>
          </div>
          <?php if ($bank_editable): ?>
          <!-- Form edit rekening -->
          <form id="bank-edit-form" method="POST" style="display:none;margin-top:10px;padding-top:10px;border-top:1.5px dashed #aaa">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_bank">
            <div class="form-group" style="margin-bottom:6px">
              <label class="form-label" style="font-size:11px">Nama Bank</label>
              <input class="form-control" type="text" name="bank_name" value="<?= htmlspecialchars($user['bank_name']) ?>" required style="font-size:12px">
            </div>
            <div class="form-group" style="margin-bottom:6px">
              <label class="form-label" style="font-size:11px">Nomor Rekening</label>
              <input class="form-control" type="text" name="account_number" value="<?= htmlspecialchars($user['account_number']) ?>" required style="font-size:12px">
            </div>
            <div class="form-group" style="margin-bottom:8px">
              <label class="form-label" style="font-size:11px">Nama Pemilik</label>
              <input class="form-control" type="text" name="account_name" value="<?= htmlspecialchars($user['account_name']) ?>" required style="font-size:12px">
            </div>
            <div style="font-size:10px;color:#e67e22;font-weight:700;margin-bottom:8px">⚠️ Pastikan rekening baru sudah benar sebelum disimpan!</div>
            <div style="display:flex;gap:8px">
              <button type="button" onclick="toggleEditBank()" class="btn btn--ghost btn--sm" style="flex:1;font-size:12px">Batal</button>
              <button type="submit" class="btn btn--primary btn--sm" style="flex:2;font-size:12px">💾 Simpan Rekening</button>
            </div>
          </form>
          <?php endif; ?>
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
    const maxWd = <?= (float)$max_available ?>;

    if (amt < minWd) {
      e.preventDefault();
      alert('Minimal withdraw Rp ' + minWd.toLocaleString('id-ID'));
      return;
    }
    if (amt > maxWd) {
      e.preventDefault();
      alert('Maksimal withdraw Rp ' + maxWd.toLocaleString('id-ID'));
      return;
    }

    // Show level-blocked notice only when user has enough balance and tries to submit
    if (levelBlocked && balWd >= minWd) {
      e.preventDefault();
      if (notice) { notice.style.display = 'flex'; notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
      return;
    }

    // Modal confirmation
    if (!form.dataset.confirmed) {
      e.preventDefault();
      document.getElementById('brutal-confirm-amt').innerText = 'Rp ' + amt.toLocaleString('id-ID');
      document.getElementById('brutal-confirm').style.display = 'flex';
    }
  });

  window.confirmBrutalWd = function() {
    form.dataset.confirmed = '1';
    form.submit();
  };
})();

function toggleEditBank() {
  const display  = document.getElementById('bank-display');
  const editForm = document.getElementById('bank-edit-form');
  if (!editForm) return;
  const isHidden = editForm.style.display === 'none';
  editForm.style.display  = isHidden ? 'block' : 'none';
  if (display) display.style.display = isHidden ? 'none' : 'block';
}
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

<!-- Neobrutalism Modal Confirm -->
<div id="brutal-confirm" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);">
  <div class="card card--mint" style="width:100%;max-width:340px;box-shadow:6px 6px 0 var(--ink);border:3px solid var(--ink);border-radius:12px;animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
    <div class="card__header" style="background:var(--brand);border-bottom:3px solid var(--ink);border-radius:9px 9px 0 0;padding:12px 16px;">
      <div class="card__title" style="color:var(--ink);font-weight:900;font-size:16px;">💸 Konfirmasi Withdraw</div>
    </div>
    <div class="card__body" style="padding:16px;background:#fff;border-radius:0 0 9px 9px;">
      <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:#333;text-align:center;">Kamu akan melakukan penarikan sebesar:</div>
      <div id="brutal-confirm-amt" style="font-size:26px;font-weight:900;color:var(--brand);margin-bottom:12px;text-align:center;letter-spacing:-0.5px;"></div>
      <div style="font-size:12px;color:#666;margin-bottom:20px;font-weight:600;text-align:center;">Pastikan data rekening bank tujuan sudah benar.<br>Apakah kamu ingin melanjutkan?</div>
      <div style="display:flex;gap:12px;">
        <button type="button" onclick="document.getElementById('brutal-confirm').style.display='none'" class="btn" style="flex:1;background:#eee;color:var(--ink);border:2.5px solid var(--ink);font-weight:800;border-radius:8px;">Batal</button>
        <button type="button" onclick="confirmBrutalWd()" class="btn btn--primary" style="flex:1.5;background:var(--brand);color:var(--ink);border:2.5px solid var(--ink);font-weight:900;border-radius:8px;box-shadow:2px 2px 0 var(--ink);">Ya, Tarik Dana</button>
      </div>
    </div>
  </div>
</div>
<style>
@keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
</style>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
