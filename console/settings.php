<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
csrf_enforce();

$flash = $flashType = '';
global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $keys = ['site_name','site_tagline','free_watch_limit','referral_bonus',
                 'referral_commission_percent','checkin_reward','min_deposit','wd_min_level'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) setting_set($pdo, $k, trim($_POST[$k]));
        }
        // Toggle checkbox
        setting_set($pdo, 'wd_require_level', isset($_POST['wd_require_level']) ? '1' : '0');
        $flash = 'Pengaturan umum berhasil disimpan!';
    }

    if ($action === 'save_bank') {
        foreach (['bank_name','bank_account','bank_holder'] as $k) {
            if (isset($_POST[$k])) setting_set($pdo, $k, trim($_POST[$k]));
        }
        // QRIS raw
        if (isset($_POST['qris_raw'])) setting_set($pdo, 'qris_raw', trim($_POST['qris_raw']));
        $flash = 'Info rekening & QRIS berhasil disimpan!';
    }

    if ($action === 'save_maintenance') {
        setting_set($pdo, 'maintenance_mode', isset($_POST['maintenance_mode']) && $_POST['maintenance_mode'] === '1' ? '1' : '0');
        setting_set($pdo, 'maintenance_message', trim($_POST['maintenance_message'] ?? 'Sistem sedang dalam perbaikan.'));
        $flash = 'Pengaturan maintenance disimpan!';
    }

    if ($action === 'save_wd_lock') {
        setting_set($pdo, 'wd_lock_start', trim($_POST['wd_lock_start'] ?? ''));
        setting_set($pdo, 'wd_lock_end',   trim($_POST['wd_lock_end'] ?? ''));
        setting_set($pdo, 'wd_lock_notice', trim($_POST['wd_lock_notice'] ?? ''));
        $flash = 'Pengaturan jam lock WD disimpan!';
    }

    if ($action === 'save_telegram') {
        setting_set($pdo, 'tg_bot_token', trim($_POST['tg_bot_token'] ?? ''));
        setting_set($pdo, 'tg_chat_id',   trim($_POST['tg_chat_id'] ?? ''));
        $flash = 'Pengaturan Telegram Bot disimpan!';
    }

    if ($action === 'sync_tg_webhook') {
        $token = setting($pdo, 'tg_bot_token', '');
        if (!$token) {
            $flash = 'Isi Token Bot terlebih dahulu!'; $flashType = 'error';
        } else {
            $webhook_url = base_url('webhook.php');
            $url = "https://api.telegram.org/bot{$token}/setWebhook?url=" . urlencode($webhook_url);
            $response = @file_get_contents($url);
            if ($response) {
                $res = json_decode($response, true);
                if (isset($res['ok']) && $res['ok']) {
                    $flash = 'Webhook berhasil di-sync ke: ' . $webhook_url;
                } else {
                    $flash = 'Gagal sync webhook: ' . ($res['description'] ?? 'Unknown error'); $flashType = 'error';
                }
            } else {
                $flash = 'Gagal memanggil API Telegram.'; $flashType = 'error';
            }
        }
    }

    if ($action === 'change_password') {
        $admin = $_SESSION['admin'];
        $cur   = $pdo->prepare("SELECT password_hash FROM admins WHERE id=?"); $cur->execute([$admin['id']]); $cur = $cur->fetchColumn();
        $old   = $_POST['old_password'] ?? '';
        $new   = $_POST['new_password'] ?? '';
        if (!password_verify($old, $cur)) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE admins SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $admin['id']]);
            $flash = 'Password admin berhasil diubah.';
        }
    }
}

$s = fn($k, $d='') => setting($pdo, $k, $d);
$maintenance_on = $s('maintenance_mode','0') === '1';

$pageTitle  = 'Pengaturan';
$activePage = 'settings';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4"><h5 class="mb-0 fw-bold">⚙️ Pengaturan Sistem</h5></div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php
// WD Lock Estimation Logic
$wd_locked = is_wd_locked($pdo);
$start_lock = $s('wd_lock_start');
$end_lock   = $s('wd_lock_end');
$wd_estimation = '';

if ($start_lock && $end_lock) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $start_lock);
    $e_ts = strtotime(date('Y-m-d ') . $end_lock);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "<div class='alert alert-warning py-2 mb-3' style='border-radius:8px;font-size:13px'>⏳ Saat ini WD <strong>DITUTUP</strong>. Akan dibuka kembali dalam <strong>{$h} jam {$m} menit</strong>.</div>";
    } else {
        if ($s_ts <= $now_ts) $s_ts += 86400;
        $diff = $s_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "<div class='alert alert-success py-2 mb-3' style='border-radius:8px;font-size:13px'>✅ Saat ini WD <strong>DIBUKA</strong>. Akan ditutup dalam <strong>{$h} jam {$m} menit</strong>.</div>";
    }
}
?>

<ul class="nav nav-pills mb-4" id="settingsTabs" role="tablist" style="gap:8px;background:rgba(255,255,255,0.03);padding:8px;border-radius:12px">
  <li class="nav-item" role="presentation">
    <button class="nav-link active text-white" data-bs-toggle="pill" data-bs-target="#tab-general" type="button" role="tab" style="border-radius:8px">🌐 Umum</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link text-white" data-bs-toggle="pill" data-bs-target="#tab-bank" type="button" role="tab" style="border-radius:8px">🏦 Rekening</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link text-white" data-bs-toggle="pill" data-bs-target="#tab-wd" type="button" role="tab" style="border-radius:8px">🔒 Jam Lock WD</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link text-white" data-bs-toggle="pill" data-bs-target="#tab-system" type="button" role="tab" style="border-radius:8px">🔧 Sistem & Telegram</button>
  </li>
</ul>

<div class="tab-content">
  <!-- TAB GENERAL -->
  <div class="tab-pane fade show active" id="tab-general" role="tabpanel">
    <div class="row g-3"><div class="col-md-8">
      <div class="c-card mb-3">
        <div class="c-card-header"><span class="c-card-title">🌐 Pengaturan Umum</span></div>
        <div class="c-card-body">
          <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Nama Website</label>
                <input type="text" name="site_name" class="c-form-control" value="<?= htmlspecialchars($s('site_name','TontonKuy')) ?>"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Tagline</label>
                <input type="text" name="site_tagline" class="c-form-control" value="<?= htmlspecialchars($s('site_tagline')) ?>"></div></div>
            </div>
            
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Limit Tonton Free (video/hari)</label>
                <input type="number" name="free_watch_limit" class="c-form-control" value="<?= $s('free_watch_limit','5') ?>" min="1"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Reward Check-in Harian (Rp)</label>
                <input type="number" name="checkin_reward" class="c-form-control" value="<?= $s('checkin_reward','500') ?>" min="0"></div></div>
            </div>

            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Minimum Deposit (Rp)</label>
                <input type="number" name="min_deposit" class="c-form-control" value="<?= $s('min_deposit','10000') ?>" min="0"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Minimum Withdraw (Rp)</label>
                <input type="number" name="min_withdraw" class="c-form-control" value="<?= $s('min_withdraw','50000') ?>" min="0"></div></div>
            </div>
            
            <div class="row g-2">
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">Level Minimum WD <small style="color:#888">(0=semua, 1=Silver+)</small></label>
                <input type="number" name="wd_min_level" class="c-form-control" value="<?= $s('wd_min_level','0') ?>" min="0" max="10"></div></div>
              <div class="col-md-6"><div class="c-form-group"><label class="c-label">% Komisi Referral</label>
                <input type="number" name="referral_commission_percent" class="c-form-control" value="<?= $s('referral_commission_percent','5') ?>" min="0" max="100" step="0.1"></div></div>
            </div>
            
            <div class="c-form-group">
              <label class="c-label">Paksa Level Minimum untuk WD</label>
              <div class="form-check ms-1">
                <input class="form-check-input" type="checkbox" name="wd_require_level" id="wd_require_level_chk" value="1" <?= $s('wd_require_level','0')==='1'?'checked':'' ?>>
                <label class="form-check-label text-secondary" for="wd_require_level_chk" style="font-size:13px">
                  Aktifkan syarat level minimum sebelum bisa WD
                </label>
              </div>
              <small style="color:#888;font-size:11px">Jika dimatikan, semua user bisa WD tanpa syarat level.</small>
            </div>
            
            <div class="c-form-group"><label class="c-label">Bonus Referral Registrasi (Rp) <small style="color:#888">(opsional)</small></label>
              <input type="number" name="referral_bonus" class="c-form-control" value="<?= $s('referral_bonus','1000') ?>" min="0"></div>
            
            <button type="submit" class="btn btn-sm text-white mt-2" style="background:var(--brand)">Simpan Pengaturan</button>
          </form>
        </div>
      </div>
    </div></div>
  </div>

  <!-- TAB BANK -->
  <div class="tab-pane fade" id="tab-bank" role="tabpanel">
    <div class="row g-3"><div class="col-md-6">
      <div class="c-card mb-3">
        <div class="c-card-header"><span class="c-card-title">🏦 Info Rekening & QRIS</span></div>
        <div class="c-card-body">
          <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_bank">
            <div class="c-form-group"><label class="c-label">Nama Bank</label>
              <input type="text" name="bank_name" class="c-form-control" value="<?= htmlspecialchars($s('bank_name','BCA')) ?>"></div>
            <div class="c-form-group"><label class="c-label">Nomor Rekening</label>
              <input type="text" name="bank_account" class="c-form-control" value="<?= htmlspecialchars($s('bank_account')) ?>"></div>
            <div class="c-form-group"><label class="c-label">Nama Pemilik</label>
              <input type="text" name="bank_holder" class="c-form-control" value="<?= htmlspecialchars($s('bank_holder')) ?>"></div>
            <div class="c-form-group"><label class="c-label">QRIS Raw String <small style="color:#888">(paste string QRIS statis tanpa CRC)</small></label>
              <textarea name="qris_raw" class="c-form-control" rows="4" placeholder="00020101021226..."><?= htmlspecialchars($s('qris_raw')) ?></textarea>
              <small style="color:#888">Kosongkan jika tidak menggunakan QRIS</small></div>
            <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Rekening & QRIS</button>
          </form>
        </div>
      </div>
    </div></div>
  </div>

  <!-- TAB WD LOCK -->
  <div class="tab-pane fade" id="tab-wd" role="tabpanel">
    <div class="row g-3"><div class="col-md-6">
      <div class="c-card mb-3">
        <div class="c-card-header"><span class="c-card-title">🔒 Jam Lock Penarikan (WD)</span></div>
        <div class="c-card-body">
          
          <?= $wd_estimation ?>
          
          <form method="POST" id="form_wd_lock">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_wd_lock">
            <!-- Hidden inputs for backend -->
            <input type="hidden" name="wd_lock_start" id="wd_lock_start" value="<?= htmlspecialchars($s('wd_lock_start')) ?>">
            <input type="hidden" name="wd_lock_end" id="wd_lock_end" value="<?= htmlspecialchars($s('wd_lock_end')) ?>">
            
            <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap">
              <div class="c-form-group" style="flex:1;min-width:200px"><label class="c-label">Mulai Lock</label>
                <div style="display:flex;gap:4px">
                  <select id="start_h" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=1;$i<=12;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="start_m" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=0;$i<60;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="start_p" class="c-form-control" style="padding:4px"><option value="">--</option><option value="AM">AM</option><option value="PM">PM</option></select>
                </div>
                <small style="color:#888">Kosongkan semua untuk hapus lock</small>
              </div>
              <div class="c-form-group" style="flex:1;min-width:200px"><label class="c-label">Selesai Lock</label>
                <div style="display:flex;gap:4px">
                  <select id="end_h" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=1;$i<=12;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="end_m" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=0;$i<60;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="end_p" class="c-form-control" style="padding:4px"><option value="">--</option><option value="AM">AM</option><option value="PM">PM</option></select>
                </div>
              </div>
            </div>
            <div class="c-form-group"><label class="c-label">Pesan saat WD dikunci</label>
              <input type="text" name="wd_lock_notice" class="c-form-control" value="<?= htmlspecialchars($s('wd_lock_notice','Penarikan hanya bisa dilakukan pada jam tertentu.')) ?>"></div>
            <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Jam Lock WD</button>
          </form>
        </div>
      </div>
    </div></div>
  </div>

  <!-- TAB SYSTEM & TELEGRAM -->
  <div class="tab-pane fade" id="tab-system" role="tabpanel">
    <div class="row g-3">
      <div class="col-md-6">
        <!-- Maintenance mode -->
        <div class="c-card mb-3">
          <div class="c-card-header"><span class="c-card-title">🔧 Mode Maintenance</span>
            <span class="badge <?= $maintenance_on ? 'b-danger' : 'b-success' ?>" style="float:right;border-radius:6px"><?= $maintenance_on ? '🔴 Aktif' : '🟢 Normal' ?></span>
          </div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_maintenance">
              <div class="c-form-group">
                <label class="c-label">Status Maintenance</label>
                <select name="maintenance_mode" class="c-form-control">
                  <option value="0" <?= !$maintenance_on?'selected':'' ?>>🟢 Normal (User bisa akses)</option>
                  <option value="1" <?= $maintenance_on?'selected':'' ?>>🔴 Maintenance (User diblokir)</option>
                </select>
              </div>
              <div class="c-form-group"><label class="c-label">Pesan Maintenance</label>
                <textarea name="maintenance_message" class="c-form-control" rows="2"><?= htmlspecialchars($s('maintenance_message','Sistem sedang dalam perbaikan.')) ?></textarea></div>
              <button type="submit" class="btn btn-sm <?= $maintenance_on ? 'btn-success' : 'btn-warning' ?>" style="color:#000">
                <?= $maintenance_on ? '✅ Matikan Maintenance' : '🔧 Simpan Maintenance' ?>
              </button>
            </form>
          </div>
        </div>

        <!-- Telegram Bot Settings -->
        <div class="c-card mb-3">
          <div class="c-card-header"><span class="c-card-title">🤖 Telegram Bot Notifikasi</span></div>
          <div class="c-card-body">
            <form method="POST" class="mb-3">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_telegram">
              <div class="c-form-group"><label class="c-label">Bot Token <small style="color:#888">(dari @BotFather)</small></label>
                <input type="text" name="tg_bot_token" class="c-form-control" value="<?= htmlspecialchars($s('tg_bot_token')) ?>" placeholder="123456789:ABCdefGHI..."></div>
              <div class="c-form-group"><label class="c-label">Chat ID Admin <small style="color:#888">(ID grup atau ID admin)</small></label>
                <input type="text" name="tg_chat_id" class="c-form-control" value="<?= htmlspecialchars($s('tg_chat_id')) ?>" placeholder="-100123456789"></div>
              <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Telegram</button>
            </form>
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="sync_tg_webhook">
              <button type="submit" class="btn btn-sm btn-info text-white">🔄 Sync Webhook</button>
            </form>
          </div>
        </div>
      </div>
      
      <div class="col-md-6">
        <!-- Change password -->
        <div class="c-card">
          <div class="c-card-header"><span class="c-card-title">🔐 Ganti Password Admin</span></div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
              <div class="c-form-group"><label class="c-label">Password Lama</label>
                <input type="password" name="old_password" class="c-form-control" required></div>
              <div class="c-form-group"><label class="c-label">Password Baru</label>
                <input type="password" name="new_password" class="c-form-control" required></div>
              <button type="submit" class="btn btn-sm btn-secondary">Ganti Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- System info -->
<div class="c-card mt-3">
  <div class="c-card-header"><span class="c-card-title">ℹ️ Info Sistem</span></div>
  <div class="c-card-body">
    <div class="row g-2" style="font-size:13px">
      <div class="col-6 col-md-3"><span style="color:#666">PHP Version</span><div style="font-weight:700"><?= PHP_VERSION ?></div></div>
      <div class="col-6 col-md-3"><span style="color:#666">Database</span><div style="font-weight:700"><?= $_ENV['DB_DATABASE'] ?? 'tonton' ?></div></div>
      <div class="col-6 col-md-3"><span style="color:#666">Server Time</span><div style="font-weight:700"><?= date('d M Y H:i') ?></div></div>
      <?php try {
        $sz = (int)$pdo->query("SELECT COUNT(*) FROM watch_history")->fetchColumn();
        echo "<div class='col-6 col-md-3'><span style='color:#666'>Watch History</span><div style='font-weight:700'>".number_format($sz)." baris</div></div>";
      } catch(\Throwable) {} ?>
      <?php try {
        $ref_cnt = (int)$pdo->query("SELECT COUNT(*) FROM referral_commissions")->fetchColumn();
        echo "<div class='col-6 col-md-3'><span style='color:#666'>Komisi Referral</span><div style='font-weight:700'>".number_format($ref_cnt)." transaksi</div></div>";
      } catch(\Throwable) {} ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
<script>
// Logic to populate and sync AM/PM select clock for WD lock
function parseTime(val) {
  if(!val) return {h:'', m:'', p:''};
  let parts = val.split(':');
  if(parts.length < 2) return {h:'', m:'', p:''};
  let h = parseInt(parts[0], 10);
  let m = parts[1];
  let p = h >= 12 ? 'PM' : 'AM';
  h = h % 12;
  if(h === 0) h = 12;
  return { h: h.toString().padStart(2, '0'), m: m.padStart(2, '0'), p: p };
}

function initTimeSelects(id) {
  let val = document.getElementById(id).value;
  let parsed = parseTime(val);
  let pfx = id === 'wd_lock_start' ? 'start_' : 'end_';
  document.getElementById(pfx+'h').value = parsed.h;
  document.getElementById(pfx+'m').value = parsed.m;
  document.getElementById(pfx+'p').value = parsed.p;
}

function syncTime(id) {
  let pfx = id === 'wd_lock_start' ? 'start_' : 'end_';
  let h = document.getElementById(pfx+'h').value;
  let m = document.getElementById(pfx+'m').value;
  let p = document.getElementById(pfx+'p').value;
  
  if(!h || !m || !p) {
    document.getElementById(id).value = '';
    return;
  }
  
  h = parseInt(h, 10);
  if(p === 'PM' && h < 12) h += 12;
  if(p === 'AM' && h === 12) h = 0;
  
  document.getElementById(id).value = h.toString().padStart(2, '0') + ':' + m;
}

initTimeSelects('wd_lock_start');
initTimeSelects('wd_lock_end');

['start_h','start_m','start_p'].forEach(x => document.getElementById(x).addEventListener('change', () => syncTime('wd_lock_start')));
['end_h','end_m','end_p'].forEach(x => document.getElementById(x).addEventListener('change', () => syncTime('wd_lock_end')));
</script>
