<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// List downlines
$downlines = $pdo->prepare(
    "SELECT username, email, created_at FROM users WHERE referred_by=? ORDER BY created_at DESC"
);
$downlines->execute([$user['referral_code']]);
$downlines = $downlines->fetchAll();

// Referral commission history
$commissions = $pdo->prepare(
    "SELECT rc.amount, rc.created_at, u.username as from_username, d.amount as dep_amount
     FROM referral_commissions rc
     JOIN users u ON u.id=rc.from_user_id
     JOIN deposits d ON d.id=rc.deposit_id
     WHERE rc.user_id=? ORDER BY rc.created_at DESC LIMIT 20"
);
$commissions->execute([$user['id']]);
$commissions = $commissions->fetchAll();

// Total commission earned
$total_commission = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE user_id=?");
$total_commission->execute([$user['id']]);
$total_commission = (float)$total_commission->fetchColumn();

$referral_pct = setting($pdo, 'referral_commission_percent', '5');
$ref_link     = base_url('register?ref=' . $user['referral_code']);

$pageTitle  = 'Referral — TontonKuy';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>🤝 Program Referral</h1>
  <p>Ajak teman, dapatkan komisi <?= $referral_pct ?>% dari setiap deposit mereka!</p>
</div>

<!-- Referral code card -->
<div class="hero-card" style="background:var(--mint)">
  <div class="hero-card__label">🔗 Kode Referral Kamu</div>
  <div class="hero-card__amount" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></div>
  <div class="hero-card__sub">Komisi <?= $referral_pct ?>% dari deposit downline → Saldo Penarikan</div>
  <div class="hero-card__actions">
    <button onclick="copyCode()" class="hero-card__btn">📋 Salin Kode</button>
    <button onclick="copyLink()" class="hero-card__btn">🔗 Salin Link</button>
    <button onclick="shareLink()" class="hero-card__btn">📤 Share</button>
  </div>
</div>

<!-- Stats -->
<div class="stat-row" style="margin-top:16px">
  <div class="stat-mini">
    <div class="stat-mini__val"><?= count($downlines) ?></div>
    <div class="stat-mini__lbl">👥 Downline</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px"><?= format_rp($total_commission) ?></div>
    <div class="stat-mini__lbl">💰 Total Komisi</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val"><?= $referral_pct ?>%</div>
    <div class="stat-mini__lbl">📊 Rate</div>
  </div>
</div>

<!-- Cara kerja -->
<div class="card" style="margin-top:16px">
  <div class="card__header"><div class="card__title">💡 Cara Kerja</div></div>
  <div class="card__body">
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--yellow)">1️⃣</div>
      <div class="list-item__body"><div class="list-item__title">Bagikan kode referralmu</div><div class="list-item__sub">Kirim ke teman via WhatsApp, Telegram, dll.</div></div>
    </div>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--mint)">2️⃣</div>
      <div class="list-item__body"><div class="list-item__title">Teman daftar & deposit</div><div class="list-item__sub">Teman mendaftar menggunakan kode referral kamu</div></div>
    </div>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--pink)">3️⃣</div>
      <div class="list-item__body"><div class="list-item__title">Komisi otomatis masuk</div><div class="list-item__sub"><?= $referral_pct ?>% dari setiap deposit yang disetujui masuk ke Saldo Penarikan kamu</div></div>
    </div>
  </div>
</div>

<!-- Downlines -->
<?php if (!empty($downlines)): ?>
<div class="section-header" style="margin-top:20px">
  <div class="section-title">👥 Daftar Downline (<?= count($downlines) ?>)</div>
</div>
<div class="card"><div class="card__body">
  <?php foreach ($downlines as $dl): ?>
  <div class="list-item">
    <div class="list-item__icon" style="background:var(--purple-soft,#e8d5ff)"><?= strtoupper(substr($dl['username'],0,1)) ?></div>
    <div class="list-item__body">
      <div class="list-item__title"><?= htmlspecialchars($dl['username']) ?></div>
      <div class="list-item__sub"><?= date('d M Y', strtotime($dl['created_at'])) ?></div>
    </div>
    <div class="list-item__right"><span class="badge badge--neutral">Downline</span></div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<!-- Commission history -->
<?php if (!empty($commissions)): ?>
<div class="section-header" style="margin-top:20px">
  <div class="section-title">💸 Riwayat Komisi</div>
</div>
<div class="card"><div class="card__body">
  <?php foreach ($commissions as $c): ?>
  <div class="list-item">
    <div class="list-item__icon" style="background:var(--lime,#d4f5a2)">💰</div>
    <div class="list-item__body">
      <div class="list-item__title">Komisi dari <?= htmlspecialchars($c['from_username']) ?></div>
      <div class="list-item__sub">Deposit <?= format_rp((float)$c['dep_amount']) ?> · <?= date('d M Y H:i', strtotime($c['created_at'])) ?></div>
    </div>
    <div class="list-item__right">
      <div class="list-item__amount list-item__amount--green">+<?= format_rp((float)$c['amount']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php elseif (empty($downlines)): ?>
<div class="card" style="margin-top:16px">
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
    <p>Belum ada downline. Mulai bagikan kode referralmu!</p>
  </div>
</div>
<?php endif; ?>

<script>
const REF_CODE = '<?= $user['referral_code'] ?>';
const REF_LINK = '<?= htmlspecialchars($ref_link) ?>';

function copyText(text, label) {
  nToast.copy(text, label || text);
}

function copyCode() { nToast.copy(REF_CODE, 'Kode referral'); }
function copyLink() { nToast.copy(REF_LINK, 'Link referral'); }
function shareLink() {
  if (navigator.share) {
    navigator.share({
      title: 'Daftar TontonKuy & Dapatkan Reward!',
      text: 'Tonton video YouTube dan dapatkan uang! Gunakan kode referralku: ' + REF_CODE,
      url: REF_LINK
    }).catch(() => copyLink());
  } else {
    copyLink();
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
