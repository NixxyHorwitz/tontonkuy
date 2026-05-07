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

<?php
// Load contact buttons for profile section
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }
$_psvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>
<?php if (!empty($_contact_btns)): ?>
<!-- Community / Contact section -->
<div class="card" style="margin-bottom:12px">
  <div class="card__header"><div class="card__title">💬 Hubungi Kami &amp; Komunitas</div></div>
  <div class="card__body" style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($_contact_btns as $_cb): ?>
    <a href="<?= htmlspecialchars($_cb['url']) ?>" target="_blank" rel="noopener"
       style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:2px solid var(--ink);border-radius:10px;box-shadow:2px 2px 0 var(--ink);text-decoration:none;color:var(--ink);background:var(--white);transition:transform .1s,box-shadow .1s"
       onmousedown="this.style.transform='translate(1px,1px)';this.style.boxShadow='1px 1px 0 var(--ink)'"
       onmouseup="this.style.transform='';this.style.boxShadow='2px 2px 0 var(--ink)'"
       ontouchstart="this.style.transform='translate(1px,1px)'" ontouchend="this.style.transform=''">
      <div style="width:38px;height:38px;flex-shrink:0;border-radius:10px;background:<?= htmlspecialchars($_cb['bg_color']) ?>;border:1.5px solid var(--ink);display:flex;align-items:center;justify-content:center;overflow:hidden">
        <?php if ($_cb['icon_type'] === 'custom'): ?>
          <img src="<?= htmlspecialchars($_cb['icon_value']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
        <?php else: ?>
          <span style="color:#fff;display:flex"><?= $_psvg[$_cb['icon_value']] ?? $_psvg['cs'] ?></span>
        <?php endif; ?>
      </div>
      <div style="flex:1">
        <div style="font-weight:800;font-size:13px"><?= htmlspecialchars($_cb['label']) ?></div>
        <div style="font-size:10px;color:#888;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($_cb['url']) ?></div>
      </div>
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;opacity:.4"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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
