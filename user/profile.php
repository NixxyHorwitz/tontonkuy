<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        if (strlen($username) < 3) { $flash = 'Username minimal 3 karakter.'; $flashType = 'error'; }
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { $flash = 'Username hanya boleh huruf, angka, underscore.'; $flashType = 'error'; }
        else {
            // Check uniqueness
            $ex = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $ex->execute([$username, $user['id']]);
            if ($ex->fetch()) { $flash = 'Username sudah digunakan.'; $flashType = 'error'; }
            else {
                $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $user['id']]);
                $flash = '✅ Username berhasil diperbarui.';
            }
        }
    }
    if ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($old, $user['password_hash'])) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $flash = 'Password berhasil diubah.';
        }
    }
    // Reload user
    $ru = $pdo->prepare("SELECT * FROM users WHERE id=?"); $ru->execute([$user['id']]); $user = $ru->fetch();
}

// Stats
$total_watches = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?");
$total_watches->execute([$user['id']]); $total_watches = $total_watches->fetchColumn();

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Referral count
$refs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$refs->execute([$user['referral_code']]); $refs = $refs->fetchColumn();

$membership_name = 'Free';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?"); $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn() ?: 'Free';
}

$pageTitle  = 'Profil — TontonKuy';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';
?>

<!-- Profile hero card -->
<div class="profile-hero">
  <div class="profile-hero__avatar">
    <?= strtoupper(substr($user['username'], 0, 1)) ?>
  </div>
  <div class="profile-hero__info">
    <div class="profile-hero__name"><?= htmlspecialchars($user['username']) ?></div>
    <div class="profile-hero__email"><?= htmlspecialchars($user['email']) ?></div>
    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
      <span class="badge badge--brand">⭐ <?= $membership_name ?></span>
      <?php if ($user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()): ?>
      <span class="badge badge--neutral" style="font-size:10px">s/d <?= date('d M Y', strtotime($user['membership_expires_at'])) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.profile-hero {
  display: flex;
  align-items: center;
  gap: 16px;
  background: var(--yellow);
  border: 2.5px solid var(--ink);
  border-radius: 16px;
  box-shadow: 5px 5px 0 var(--ink);
  padding: 20px 18px;
  margin-bottom: 16px;
}
.profile-hero__avatar {
  width: 68px; height: 68px;
  flex-shrink: 0;
  background: var(--brand);
  color: #fff;
  border: 3px solid var(--ink);
  box-shadow: 4px 4px 0 var(--ink);
  border-radius: 18px;
  display: flex; align-items: center; justify-content: center;
  font-size: 30px; font-weight: 900;
  font-family: 'Nunito', sans-serif;
}
.profile-hero__name  { font-size: 20px; font-weight: 900; line-height: 1.2; }
.profile-hero__email { font-size: 12px; color: #666; font-weight: 600; margin-top: 2px; word-break: break-all; }
</style>


<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px">
  <div class="stat-mini"><div class="stat-mini__val"><?= format_rp((float)$user['total_earned']) ?></div><div class="stat-mini__lbl" style="font-size:9.5px">Total Earned</div></div>
  <div class="stat-mini"><div class="stat-mini__val" style="font-size:17px"><?= number_format((int)$total_watches) ?></div><div class="stat-mini__lbl">Video Ditonton</div></div>
  <div class="stat-mini"><div class="stat-mini__val" style="font-size:17px"><?= (int)$refs ?></div><div class="stat-mini__lbl">Referral</div></div>
</div>

<!-- Referral code -->
<div class="card" style="margin-bottom:12px">
  <div class="card__body">
    <div style="font-size:13px;font-weight:700;color:var(--text2);margin-bottom:6px">🔗 Kode Referral Kamu</div>
    <div style="display:flex;align-items:center;gap:10px">
      <div style="flex:1;background:var(--bg);border-radius:8px;padding:12px;font-size:18px;font-weight:800;letter-spacing:3px;text-align:center" id="ref-code"><?= $user['referral_code'] ?></div>
      <button onclick="copyRef()" class="btn btn--secondary btn--sm">Salin</button>
    </div>
    <div class="form-hint" style="margin-top:6px">Ajak teman pakai kode ini, kamu dapat bonus reward!</div>
  </div>
</div>

<!-- Update profile -->
<div class="card" style="margin-bottom:12px">
  <div class="card__header"><div class="card__title">✏️ Edit Profil</div></div>
  <div class="card__body">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">
      <!-- Username (editable) -->
      <div class="form-group">
        <label class="form-label">Username</label>
        <input class="form-control" type="text" name="username"
               value="<?= htmlspecialchars($user['username']) ?>"
               pattern="[a-zA-Z0-9_]+" minlength="3" required>
        <div class="form-hint">Hanya huruf, angka, underscore. Min. 3 karakter.</div>
      </div>
      <!-- WhatsApp (read-only) -->
      <div class="form-group">
        <label class="form-label">Nomor WhatsApp <span style="font-weight:600;color:#888;font-size:11px">🔒 tidak dapat diubah</span></label>
        <div style="position:relative">
          <input class="form-control" type="tel"
                 value="<?= htmlspecialchars($user['whatsapp']) ?>"
                 disabled readonly
                 style="background:var(--bg);color:#888;cursor:not-allowed;padding-right:40px">
          <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:16px;pointer-events:none">🔒</span>
        </div>
        <div class="form-hint">Hubungi admin jika nomor perlu diganti.</div>
      </div>
      <button type="submit" class="btn btn--primary btn--full">💾 Simpan Username</button>
    </form>
  </div>
</div>

<!-- Change password -->
<div class="card" style="margin-bottom:12px">
  <div class="card__header"><div class="card__title">🔐 Ganti Password</div></div>
  <div class="card__body">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label class="form-label">Password Lama</label>
        <input class="form-control" type="password" name="old_password" required>
      </div>
      <div class="form-group">
        <label class="form-label">Password Baru</label>
        <input class="form-control" type="password" name="new_password" required>
      </div>
      <button type="submit" class="btn btn--ghost btn--full">Ganti Password</button>
    </form>
  </div>
</div>

<!-- Logout -->
<div style="margin-bottom:8px">
  <a href="/logout" id="logout-btn"
     style="display:flex;align-items:center;justify-content:center;gap:10px;
            background:#FF4D4D;color:#fff;
            border:2.5px solid var(--ink);
            border-radius:var(--radius);
            box-shadow:4px 4px 0 var(--ink);
            padding:14px 20px;
            font-weight:900;font-size:15px;
            text-decoration:none;
            transition:transform .1s, box-shadow .1s;
            cursor:pointer">
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    Keluar dari Akun
  </a>
</div>
<script>
document.getElementById('logout-btn').addEventListener('click', function(e) {
  e.preventDefault();
  const url = this.href;
  if (!this.dataset.confirmed) {
    nToast('Klik Keluar lagi untuk konfirmasi', 'warn', 3000);
    this.dataset.confirmed = '1';
    setTimeout(() => delete this.dataset.confirmed, 3500);
    return;
  }
  window.location.href = url;
});
document.getElementById('logout-btn').addEventListener('mousedown', function() {
  this.style.transform = 'translate(2px,2px)';
  this.style.boxShadow = '2px 2px 0 var(--ink)';
});
document.addEventListener('mouseup', function() {
  const b = document.getElementById('logout-btn');
  if(b){ b.style.transform=''; b.style.boxShadow='4px 4px 0 var(--ink)'; }
});
</script>

<script>
function copyRef(){
  const c = document.getElementById('ref-code').textContent.trim();
  nToast.copy(c, 'Kode referral');
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
