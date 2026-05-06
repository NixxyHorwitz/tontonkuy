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

$deps = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$deps->execute([$user['id']]); $deps = $deps->fetchAll();

$pageTitle  = 'Deposit — TontonKuy';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
.dep-bal-strip{display:flex;gap:8px;margin-bottom:12px}
.dep-bal-strip__item{flex:1;border:2px solid var(--ink);border-radius:10px;padding:10px 12px;background:var(--white);box-shadow:3px 3px 0 var(--ink)}
.dep-bal-strip__lbl{font-size:10px;font-weight:700;color:#666;margin-bottom:2px}
.dep-bal-strip__val{font-size:15px;font-weight:900}

.dep-method{border:2.5px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);background:var(--white);overflow:hidden;margin-bottom:8px}
.dep-method__hd{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;user-select:none}
.dep-method__hd:active{background:#f5f5f5}
.dep-method__ico{width:38px;height:38px;flex-shrink:0;border-radius:10px;border:2px solid var(--ink);display:flex;align-items:center;justify-content:center;font-size:18px}
.dep-method__info{flex:1;min-width:0}
.dep-method__name{font-weight:900;font-size:14px}
.dep-method__sub{font-size:10px;color:#666;font-weight:600;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dep-method__chev{font-size:11px;color:#aaa;transition:transform .2s;flex-shrink:0}
.dep-method__bd{padding:0 14px 14px;border-top:2px solid var(--ink);display:none}

.dep-rek{background:var(--bg);border:2px solid var(--ink);border-radius:10px;padding:12px;margin:12px 0;text-align:center}
.dep-rek__lbl{font-size:10px;color:#888;font-weight:700;margin-bottom:4px}
.dep-rek__bank{font-size:13px;font-weight:800}
.dep-rek__num{font-size:20px;font-weight:900;letter-spacing:3px;margin:3px 0}
.dep-rek__name{font-size:11px;color:#666}

.qty-pills{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}
.qty-pills button{font-size:11px;padding:4px 10px}
</style>

<!-- Balance strip -->
<div class="dep-bal-strip">
  <div class="dep-bal-strip__item">
    <div class="dep-bal-strip__lbl">💳 Saldo Deposit</div>
    <div class="dep-bal-strip__val"><?= format_rp((float)$user['balance_dep']) ?></div>
  </div>
  <div class="dep-bal-strip__item">
    <div class="dep-bal-strip__lbl">💸 Saldo WD</div>
    <div class="dep-bal-strip__val"><?= format_rp((float)$user['balance_wd']) ?></div>
  </div>
</div>

<div style="font-size:11px;color:#888;margin-bottom:10px">Min. deposit <strong><?= format_rp($min_deposit) ?></strong> · Masuk ke Saldo Deposit</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if ($bank_enabled): ?>
<!-- Bank Transfer -->
<div class="dep-method" id="card-bank">
  <div class="dep-method__hd" onclick="toggleCard('bank')">
    <div class="dep-method__ico" style="background:var(--sky)">🏦</div>
    <div class="dep-method__info">
      <div class="dep-method__name">Transfer Bank</div>
      <div class="dep-method__sub">BCA · Mandiri · BNI · BRI dll</div>
    </div>
    <div class="dep-method__chev" id="chev-bank">▼</div>
  </div>
  <div class="dep-method__bd" id="body-bank">
    <div class="dep-rek">
      <div class="dep-rek__lbl">Rekening Tujuan</div>
      <div class="dep-rek__bank">Bank <?= htmlspecialchars($bankName) ?></div>
      <div class="dep-rek__num" id="rek-num"><?= htmlspecialchars($bankAccount) ?></div>
      <div class="dep-rek__name">a.n. <?= htmlspecialchars($bankHolder) ?></div>
      <button type="button" class="btn btn--secondary btn--sm" style="margin-top:8px;width:100%;font-size:12px" onclick="copyRek()">📋 Salin Nomor</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_bank">
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Jumlah Transfer (Rp)</label>
        <input class="form-control" type="number" name="amount" min="<?= $min_deposit ?>"
               step="any" placeholder="Min. <?= number_format($min_deposit,0,'','') ?>" required>
      </div>
      <div class="qty-pills">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="setAmt('bank',<?= $q ?>)"><?= format_rp($q) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="form-group" style="margin-bottom:10px">
        <label class="form-label" style="font-size:12px">Bukti Transfer <span style="font-weight:400;color:#888">(JPG/PNG)</span></label>
        <input class="form-control" type="file" name="proof" accept="image/*" style="padding:8px;font-size:12px">
      </div>
      <button type="submit" class="btn btn--primary btn--full" style="font-size:13px">📤 Kirim Bukti Transfer</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($qris_enabled && !empty($qris_raw)): ?>
<!-- QRIS -->
<div class="dep-method" id="card-qris">
  <div class="dep-method__hd" onclick="toggleCard('qris')">
    <div class="dep-method__ico" style="background:var(--mint)">📱</div>
    <div class="dep-method__info">
      <div class="dep-method__name">QRIS</div>
      <div class="dep-method__sub">GoPay · OVO · Dana · ShopeePay</div>
    </div>
    <span class="badge badge--success" style="font-size:10px;flex-shrink:0">Instan</span>
    <div class="dep-method__chev" id="chev-qris" style="margin-left:4px">▼</div>
  </div>
  <div class="dep-method__bd" id="body-qris">
    <form method="POST" id="qris-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_qris">
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Jumlah Deposit (Rp)</label>
        <input class="form-control" id="qris-amount" type="number" name="amount"
               min="<?= $min_deposit ?>" step="any"
               placeholder="Min. <?= number_format($min_deposit,0,'','') ?>" required>
      </div>
      <div class="qty-pills">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <button type="button" class="btn btn--secondary btn--sm" onclick="setAmt('qris',<?= $q ?>)"><?= format_rp($q) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="alert alert--info" style="margin-bottom:10px;font-size:11px;padding:8px 10px">
        📲 Kamu akan diarahkan ke halaman scan QR setelah klik Bayar.
      </div>
      <button type="submit" class="btn btn--primary btn--full" style="font-size:13px">📲 Bayar via QRIS →</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
<div class="alert alert--warn" style="font-size:13px">⚠️ Tidak ada metode deposit aktif. Hubungi admin.</div>
<?php endif; ?>

<!-- Riwayat -->
<?php if (!empty($deps)): ?>
<div class="section-header" style="margin-top:14px">
  <div class="section-title" style="font-size:13px">📜 Riwayat Deposit</div>
  <a href="/history" class="section-link">Lihat semua →</a>
</div>
<div class="card"><div class="card__body" style="padding:4px 0">
  <?php foreach ($deps as $d): ?>
  <div class="list-item" style="padding:8px 14px">
    <div class="list-item__icon" style="background:<?= $d['method']==='qris'?'var(--mint)':'var(--sky)' ?>;width:30px;height:30px;font-size:14px">
      <?= $d['method']==='qris' ? '📱' : '🏦' ?>
    </div>
    <div class="list-item__body">
      <div class="list-item__title" style="font-size:13px"><?= format_rp((float)$d['amount']) ?></div>
      <div class="list-item__sub" style="font-size:10px"><?= strtoupper($d['method']) ?> · <?= date('d M H:i', strtotime($d['created_at'])) ?></div>
    </div>
    <div class="list-item__right" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
      <span class="badge badge--<?= match($d['status']){'confirmed'=>'success','pending'=>'warn','rejected'=>'error'} ?>" style="font-size:10px">
        <?= ucfirst($d['status']) ?>
      </span>
      <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
      <a href="/pay?id=<?= $d['id'] ?>" class="btn btn--yellow btn--sm" style="padding:3px 8px;font-size:10px">▶ Bayar</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<script>
function toggleCard(id) {
  ['bank','qris'].forEach(k => {
    const b = document.getElementById('body-' + k);
    const c = document.getElementById('chev-' + k);
    if (b) b.style.display = 'none';
    if (c) c.style.transform = '';
  });
  const body = document.getElementById('body-' + id);
  const chev = document.getElementById('chev-' + id);
  if (body && body.style.display === 'none') {
    body.style.display = 'block';
    if (chev) chev.style.transform = 'rotate(180deg)';
  }
}
function setAmt(type, v) {
  if (type === 'bank') document.querySelector('#body-bank input[name="amount"]').value = v;
  if (type === 'qris') document.getElementById('qris-amount').value = v;
}
function copyRek() {
  const t = document.getElementById('rek-num').textContent.trim();
  nToast.copy ? nToast.copy(t, 'Nomor rekening') : navigator.clipboard.writeText(t);
}
document.addEventListener('DOMContentLoaded', () => {
  const cards = ['bank','qris'].filter(k => document.getElementById('card-' + k));
  if (cards.length === 1) toggleCard(cards[0]);
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
