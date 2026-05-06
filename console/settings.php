<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
csrf_enforce();

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $keys = ['site_name','site_tagline','min_withdraw','free_watch_limit','referral_bonus',
                 'referral_commission_percent','checkin_reward','min_deposit','wd_min_level'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) setting_set($pdo, $k, trim($_POST[$k]));
        }
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

<div class="row g-3">
  <!-- General settings -->
  <div class="col-md-6">
    <div class="c-card mb-3">
      <div class="c-card-header"><span class="c-card-title">🌐 Pengaturan Umum</span></div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
          <div class="c-form-group"><label class="c-label">Nama Website</label>
            <input type="text" name="site_name" class="c-form-control" value="<?= htmlspecialchars($s('site_name','TontonKuy')) ?>"></div>
          <div class="c-form-group"><label class="c-label">Tagline</label>
            <input type="text" name="site_tagline" class="c-form-control" value="<?= htmlspecialchars($s('site_tagline')) ?>"></div>
          <div class="c-form-group"><label class="c-label">Limit Tonton Free (video/hari)</label>
            <input type="number" name="free_watch_limit" class="c-form-control" value="<?= $s('free_watch_limit','5') ?>" min="1"></div>
          <div class="c-form-group"><label class="c-label">Minimum Deposit (Rp)</label>
            <input type="number" name="min_deposit" class="c-form-control" value="<?= $s('min_deposit','10000') ?>" min="0"></div>
          <div class="c-form-group"><label class="c-label">Minimum Withdraw (Rp)</label>
            <input type="number" name="min_withdraw" class="c-form-control" value="<?= $s('min_withdraw','50000') ?>" min="0"></div>
          <div class="c-form-group"><label class="c-label">Level Minimum WD <small style="color:#888">(0=semua bisa, 1=Silver+, dll)</small></label>
            <input type="number" name="wd_min_level" class="c-form-control" value="<?= $s('wd_min_level','0') ?>" min="0" max="10"></div>
          <div class="c-form-group"><label class="c-label">% Komisi Referral</label>
            <input type="number" name="referral_commission_percent" class="c-form-control" value="<?= $s('referral_commission_percent','5') ?>" min="0" max="100" step="0.1"></div>
          <div class="c-form-group"><label class="c-label">Reward Check-in Harian (Rp)</label>
            <input type="number" name="checkin_reward" class="c-form-control" value="<?= $s('checkin_reward','500') ?>" min="0"></div>
          <div class="c-form-group"><label class="c-label">Bonus Referral Registrasi (Rp) <small style="color:#888">(opsional)</small></label>
            <input type="number" name="referral_bonus" class="c-form-control" value="<?= $s('referral_bonus','1000') ?>" min="0"></div>
          <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Pengaturan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <!-- Bank + QRIS -->
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

    <!-- WD Lock -->
    <div class="c-card mb-3">
      <div class="c-card-header"><span class="c-card-title">🔒 Jam Lock Penarikan (WD)</span></div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_wd_lock">
          <div style="display:flex;gap:12px">
            <div class="c-form-group" style="flex:1"><label class="c-label">Mulai Lock</label>
              <input type="time" name="wd_lock_start" class="c-form-control" value="<?= htmlspecialchars($s('wd_lock_start')) ?>">
              <small style="color:#888">Kosongkan = tidak ada lock</small></div>
            <div class="c-form-group" style="flex:1"><label class="c-label">Selesai Lock</label>
              <input type="time" name="wd_lock_end" class="c-form-control" value="<?= htmlspecialchars($s('wd_lock_end')) ?>"></div>
          </div>
          <div class="c-form-group"><label class="c-label">Pesan saat WD dikunci</label>
            <input type="text" name="wd_lock_notice" class="c-form-control" value="<?= htmlspecialchars($s('wd_lock_notice','Penarikan hanya bisa dilakukan pada jam tertentu.')) ?>"></div>
          <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Jam Lock WD</button>
        </form>
      </div>
    </div>

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

<!-- System info -->
<div class="c-card mt-3">
  <div class="c-card-header"><span class="c-card-title">ℹ️ Info Sistem</span></div>
  <div class="c-card-body">
    <div class="row g-2" style="font-size:13px">
      <div class="col-6 col-md-3"><span style="color:#666">PHP Version</span><div style="font-weight:700"><?= PHP_VERSION ?></div></div>
      <div class="col-6 col-md-3"><span style="color:#666">Database</span><div style="font-weight:700"><?= $_ENV['DB_DATABASE'] ?? 'tonton' ?></div></div>
      <div class="col-6 col-md-3"><span style="color:#666">Server Time</span><div style="font-weight:700"><?= date('d M Y H:i') ?></div></div>
      <?php try {
        $sz = $pdo->query("SELECT COUNT(*) FROM watch_history")->fetchColumn();
        echo "<div class='col-6 col-md-3'><span style='color:#666'>Watch History</span><div style='font-weight:700'>".number_format($sz)." baris</div></div>";
      } catch(\Throwable) {} ?>
      <?php try {
        $ref_cnt = $pdo->query("SELECT COUNT(*) FROM referral_commissions")->fetchColumn();
        echo "<div class='col-6 col-md-3'><span style='color:#666'>Komisi Referral</span><div style='font-weight:700'>".number_format($ref_cnt)." transaksi</div></div>";
      } catch(\Throwable) {} ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
