<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Referral stats
$ref_count = (int)$pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?")->execute([$user['referral_code']]) ?: 0;
$s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$s->execute([$user['referral_code']]);
$ref_count = (int)$s->fetchColumn();

$e = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE user_id=?");
$e->execute([$user['id']]);
$ref_earned = (float)$e->fetchColumn();

// Referral history
$hist = $pdo->prepare(
  "SELECT rc.amount, rc.created_at, u.username
   FROM referral_commissions rc
   JOIN users u ON u.id = rc.from_user_id
   WHERE rc.user_id = ?
   ORDER BY rc.created_at DESC LIMIT 20"
);
$hist->execute([$user['id']]);
$history = $hist->fetchAll();

// Referred users list
$refs = $pdo->prepare(
  "SELECT username, created_at FROM users WHERE referred_by=? ORDER BY created_at DESC LIMIT 10"
);
$refs->execute([$user['referral_code']]);
$referreds = $refs->fetchAll();

$ref_url = base_url('register?ref=' . $user['referral_code']);

$pageTitle  = 'Referral — TontonKuy';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>👥 Program Referral</h1>
  <p>Ajak teman, dapatkan komisi otomatis</p>
</div>

<!-- Stats -->
<div class="stat-row" style="margin-bottom:16px">
  <div class="stat-mini">
    <div class="stat-mini__val"><?= $ref_count ?></div>
    <div class="stat-mini__lbl">Teman Diajak</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val"><?= format_rp($ref_earned) ?></div>
    <div class="stat-mini__lbl">Total Komisi</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val"><?= $user['referral_code'] ?></div>
    <div class="stat-mini__lbl">Kode Kamu</div>
  </div>
</div>

<!-- Share card -->
<div class="card card--yellow" style="margin-bottom:16px">
  <div class="card__body">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">🔗 Link Referral Kamu</div>
    <div style="display:flex;gap:8px;align-items:center">
      <input id="ref-link-input" type="text" value="<?= htmlspecialchars($ref_url) ?>"
             class="form-control" readonly style="font-size:12px;padding:8px 10px;background:#fffdf5">
      <button onclick="copyRef()" class="btn btn--ghost btn--sm" id="copy-btn" style="white-space:nowrap;flex-shrink:0">
        📋 Salin
      </button>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <a href="https://wa.me/?text=<?= urlencode('Yuk gabung TontonKuy, tonton video & dapat reward! Daftar pakai link ku: ' . $ref_url) ?>"
         target="_blank" class="btn btn--green btn--sm btn--full">
        💬 Share ke WhatsApp
      </a>
      <a href="https://t.me/share/url?url=<?= urlencode($ref_url) ?>&text=<?= urlencode('Gabung TontonKuy, dapat reward tiap nonton video!') ?>"
         target="_blank" class="btn btn--ghost btn--sm btn--full">
        ✈️ Telegram
      </a>
    </div>
  </div>
</div>

<!-- How it works -->
<div class="card" style="margin-bottom:16px">
  <div class="card__header"><div class="card__title">💡 Cara Kerja Referral</div></div>
  <div class="card__body">
    <div style="display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;align-items:flex-start;gap:10px">
        <div style="width:28px;height:28px;background:var(--yellow);border:2px solid #1A1A1A;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0">1</div>
        <div style="font-size:13px;font-weight:700;padding-top:4px">Bagikan link referral kamu ke teman-teman</div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:10px">
        <div style="width:28px;height:28px;background:var(--mint);border:2px solid #1A1A1A;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0">2</div>
        <div style="font-size:13px;font-weight:700;padding-top:4px">Teman daftar menggunakan link kamu</div>
      </div>
      <div style="display:flex;align-items:flex-start;gap:10px">
        <div style="width:28px;height:28px;background:var(--lavender);border:2px solid #1A1A1A;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0">3</div>
        <div style="font-size:13px;font-weight:700;padding-top:4px">Kamu otomatis dapat komisi dari setiap deposit teman</div>
      </div>
    </div>
  </div>
</div>

<!-- Referred users -->
<?php if (!empty($referreds)): ?>
<div class="section-header"><div class="section-title">🧑‍🤝‍🧑 Teman yang Bergabung</div></div>
<div class="card" style="margin-bottom:16px">
  <div class="card__body">
    <?php foreach ($referreds as $r): ?>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--sky)">👤</div>
      <div class="list-item__body">
        <div class="list-item__title"><?= htmlspecialchars($r['username']) ?></div>
        <div class="list-item__sub"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="text-align:center;padding:28px 20px;color:#aaa">
    <div style="font-size:36px;margin-bottom:8px">👥</div>
    <div style="font-size:13px;font-weight:700">Belum ada teman yang bergabung.<br>Mulai bagikan link referral kamu!</div>
  </div>
</div>
<?php endif; ?>

<!-- Commission history -->
<?php if (!empty($history)): ?>
<div class="section-header"><div class="section-title">💰 Riwayat Komisi</div></div>
<div class="card">
  <div class="card__body">
    <?php foreach ($history as $h): ?>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--lime)">🎁</div>
      <div class="list-item__body">
        <div class="list-item__title">Komisi dari <?= htmlspecialchars($h['username']) ?></div>
        <div class="list-item__sub"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount list-item__amount--green">+<?= format_rp((float)$h['amount']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
function copyRef() {
  const input = document.getElementById('ref-link-input');
  input.select();
  input.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(input.value).then(() => {
    const btn = document.getElementById('copy-btn');
    btn.textContent = '✅ Tersalin!';
    setTimeout(() => btn.textContent = '📋 Salin', 2000);
  }).catch(() => {
    document.execCommand('copy');
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
