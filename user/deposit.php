<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$min_deposit  = (float) setting($pdo, 'min_deposit', '10000');
$bank_enabled = setting($pdo, 'bank_enabled', '1') === '1';
$qris_enabled = setting($pdo, 'qris_enabled', '1') === '1';
$bankName     = setting($pdo, 'bank_name', 'BCA');
$bankAccount  = setting($pdo, 'bank_account', '-');
$bankHolder   = setting($pdo, 'bank_holder', 'Admin');
$qris_raw     = setting($pdo, 'qris_raw', '');

// Handle bank transfer submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_bank') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . '.'; $flashType = 'error';
    } elseif (!$bank_enabled) {
        $flash = 'Transfer bank sedang tidak tersedia.'; $flashType = 'error';
    } else {
        $proof = null;
        if (!empty($_FILES['proof']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $flash = 'Format bukti harus JPG/PNG/WEBP.'; $flashType = 'error';
                goto end_dep;
            }
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $proof = 'deposits/' . $fname;
        }
        $pdo->prepare("INSERT INTO deposits (user_id,amount,method,proof_image) VALUES (?,?,?,?)")
            ->execute([$user['id'], $amount, 'transfer', $proof]);
        $flash = '✅ Bukti transfer dikirim! Admin akan memproses dalam 1×24 jam.';
    }
}

// Handle QRIS: create pending deposit → redirect to /pay
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_qris') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . '.'; $flashType = 'error';
    } elseif (!$qris_enabled || empty($qris_raw)) {
        $flash = 'QRIS belum tersedia.'; $flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO deposits (user_id,amount,method,status) VALUES (?,?,'qris','pending')")
            ->execute([$user['id'], $amount]);
        $dep_id = $pdo->lastInsertId();
        redirect('/pay?id=' . $dep_id);
    }
}
end_dep:

// History (last 8)
$deps = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 8");
$deps->execute([$user['id']]); $deps = $deps->fetchAll();

$pageTitle  = 'Deposit — TontonKuy';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>⬆️ Top Up Saldo</h1>
  <p>Min. deposit <?= format_rp($min_deposit) ?> • Masuk ke Saldo Deposit</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Saldo info -->
<div class="dual-balance" style="margin-bottom:16px">
  <div class="dual-balance__item dual-balance__item--dep">
    <div class="dual-balance__label">💳 Saldo Deposit</div>
    <div class="dual-balance__val"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div class="dual-balance__hint">untuk upgrade paket</div>
  </div>
  <div class="dual-balance__item dual-balance__item--wd">
    <div class="dual-balance__label">💸 Saldo WD</div>
    <div class="dual-balance__val"><?= format_rp((float)$user['balance_wd']) ?></div>
    <div class="dual-balance__hint">dari nonton & referral</div>
  </div>
</div>

<!-- Metode Deposit -->
<div class="section-title" style="margin-bottom:12px">Pilih Metode Deposit</div>
<div style="display:flex;flex-direction:column;gap:12px">

<?php if ($bank_enabled): ?>
<!-- Bank Transfer Card -->
<div class="dep-method-card" id="card-bank">
  <div class="dep-method-card__header" onclick="toggleCard('bank')">
    <div class="dep-method-card__icon" style="background:var(--sky)">🏦</div>
    <div class="dep-method-card__info">
      <div class="dep-method-card__name">Transfer Bank</div>
      <div class="dep-method-card__sub">BCA · Mandiri · BNI · BRI dll</div>
    </div>
    <div class="dep-method-card__chevron" id="chev-bank">▼</div>
  </div>
  <div class="dep-method-card__body" id="body-bank" style="display:none">
    <!-- Rekening tujuan -->
    <div class="dep-rekening">
      <div class="dep-rekening__label">Rekening Tujuan</div>
      <div class="dep-rekening__bank">Bank <?= htmlspecialchars($bankName) ?></div>
      <div class="dep-rekening__num" id="rek-num"><?= htmlspecialchars($bankAccount) ?></div>
      <div class="dep-rekening__name">a.n. <?= htmlspecialchars($bankHolder) ?></div>
      <button type="button" class="btn btn--secondary btn--sm" style="margin-top:8px;width:100%" onclick="copyRek()">📋 Salin Nomor</button>
    </div>
    <!-- Form -->
    <form method="POST" enctype="multipart/form-data" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_bank">
      <div class="form-group">
        <label class="form-label">Jumlah Transfer (Rp)</label>
        <input class="form-control" type="number" name="amount" min="<?= $min_deposit ?>"
               step="any" placeholder="Min. <?= number_format($min_deposit,0,'','') ?>" required>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="setAmt('bank',<?= $q ?>)"><?= format_rp($q) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Bukti Transfer <span style="font-weight:400;color:#888">(JPG/PNG)</span></label>
        <input class="form-control" type="file" name="proof" accept="image/*" style="padding:10px">
      </div>
      <button type="submit" class="btn btn--primary btn--full">📤 Kirim Bukti Transfer</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($qris_enabled && !empty($qris_raw)): ?>
<!-- QRIS Card -->
<div class="dep-method-card" id="card-qris">
  <div class="dep-method-card__header" onclick="toggleCard('qris')">
    <div class="dep-method-card__icon" style="background:var(--mint)">📱</div>
    <div class="dep-method-card__info">
      <div class="dep-method-card__name">QRIS</div>
      <div class="dep-method-card__sub">GoPay · OVO · Dana · ShopeePay dll</div>
    </div>
    <span class="badge badge--success" style="font-size:10px;margin-right:4px">Instan</span>
    <div class="dep-method-card__chevron" id="chev-qris">▼</div>
  </div>
  <div class="dep-method-card__body" id="body-qris" style="display:none">
    <form method="POST" id="qris-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_qris">
      <div class="form-group">
        <label class="form-label">Jumlah Deposit (Rp)</label>
        <input class="form-control" id="qris-amount" type="number" name="amount"
               min="<?= $min_deposit ?>" step="any"
               placeholder="Min. <?= number_format($min_deposit,0,'','') ?>" required>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="setAmt('qris',<?= $q ?>)"><?= format_rp($q) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="alert alert--info" style="margin-bottom:12px;font-size:12px">
        📲 Setelah klik Bayar, kamu akan diarahkan ke halaman scan QR dengan nominal yang sudah tertanam.
      </div>
      <button type="submit" class="btn btn--primary btn--full btn--lg">📲 Bayar via QRIS →</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
<div class="alert alert--warn">⚠️ Tidak ada metode deposit yang aktif saat ini. Hubungi admin.</div>
<?php endif; ?>
</div>

<!-- Riwayat deposit -->
<?php if (!empty($deps)): ?>
<div class="section-header" style="margin-top:20px">
  <div class="section-title">📜 Riwayat Deposit</div>
  <a href="/history" class="section-link">Lihat semua →</a>
</div>
<div class="card"><div class="card__body">
  <?php foreach ($deps as $d): ?>
  <div class="list-item">
    <div class="list-item__icon" style="background:<?= $d['method']==='qris'?'var(--mint)':'var(--sky)' ?>">
      <?= $d['method']==='qris' ? '📱' : '🏦' ?>
    </div>
    <div class="list-item__body">
      <div class="list-item__title"><?= format_rp((float)$d['amount']) ?></div>
      <div class="list-item__sub"><?= strtoupper($d['method']) ?> · <?= date('d M Y H:i', strtotime($d['created_at'])) ?></div>
    </div>
    <div class="list-item__right" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
      <span class="badge badge--<?= match($d['status']){'confirmed'=>'success','pending'=>'warn','rejected'=>'error'} ?>">
        <?= ucfirst($d['status']) ?>
      </span>
      <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
      <a href="/pay?id=<?= $d['id'] ?>" class="btn btn--yellow btn--sm" style="padding:4px 10px;font-size:11px">▶ Lanjut Bayar</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<style>
.dep-method-card {
  border: 2.5px solid var(--ink);
  border-radius: var(--radius);
  box-shadow: 4px 4px 0 var(--ink);
  background: var(--white);
  overflow: hidden;
}
.dep-method-card__header {
  display: flex; align-items: center; gap: 12px;
  padding: 16px;
  cursor: pointer;
  user-select: none;
  transition: background .12s;
}
.dep-method-card__header:hover { background: #fafafa; }
.dep-method-card__icon {
  width: 44px; height: 44px; flex-shrink: 0;
  border-radius: 12px; border: 2px solid var(--ink);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
}
.dep-method-card__info { flex: 1; }
.dep-method-card__name { font-weight: 900; font-size: 15px; }
.dep-method-card__sub  { font-size: 11px; color: #666; font-weight: 600; margin-top: 2px; }
.dep-method-card__chevron { font-size: 12px; color: #aaa; transition: transform .2s; }
.dep-method-card__body { padding: 0 16px 16px; border-top: 2px solid var(--ink); }
.dep-rekening {
  background: var(--bg);
  border: 2px solid var(--ink);
  border-radius: 10px;
  padding: 14px;
  margin-top: 14px;
  text-align: center;
}
.dep-rekening__label { font-size: 11px; color: #888; font-weight: 700; margin-bottom: 6px; }
.dep-rekening__bank  { font-size: 14px; font-weight: 800; }
.dep-rekening__num   { font-size: 22px; font-weight: 900; letter-spacing: 3px; margin: 4px 0; }
.dep-rekening__name  { font-size: 12px; color: #666; }
</style>

<script>
function toggleCard(id) {
  const body = document.getElementById('body-' + id);
  const chev = document.getElementById('chev-' + id);
  const open = body.style.display !== 'none';
  // Close all
  ['bank','qris'].forEach(k => {
    const b = document.getElementById('body-' + k);
    const c = document.getElementById('chev-' + k);
    if (b) { b.style.display = 'none'; }
    if (c) { c.style.transform = ''; }
  });
  if (!open) {
    body.style.display = 'block';
    chev.style.transform = 'rotate(180deg)';
  }
}

function setAmt(type, v) {
  if (type === 'bank') document.querySelector('#body-bank input[name="amount"]').value = v;
  if (type === 'qris') document.getElementById('qris-amount').value = v;
}

function copyRek() {
  const t = document.getElementById('rek-num').textContent.trim();
  nToast.copy(t, 'Nomor rekening');
}

// Auto-open jika hanya 1 metode tersedia
document.addEventListener('DOMContentLoaded', () => {
  const cards = ['bank','qris'].filter(k => document.getElementById('card-' + k));
  if (cards.length === 1) toggleCard(cards[0]);
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
