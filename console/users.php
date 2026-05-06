<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
csrf_enforce();

$flash = $flashType = '';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);
    if ($action === 'toggle_active' && $uid) {
        $s = $pdo->prepare("SELECT is_active FROM users WHERE id=?"); $s->execute([$uid]);
        $cur = (int)$s->fetchColumn();
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$cur?0:1, $uid]);
        $flash = 'Status pengguna diperbarui.';
    }
    if ($action === 'adjust_balance' && $uid) {
        $amount = (float)$_POST['amount'];
        $type   = $_POST['type'] === 'add' ? 1 : -1;
        $field  = $_POST['bal_field'] === 'dep' ? 'balance_dep' : 'balance_wd';
        $pdo->prepare("UPDATE users SET {$field}=GREATEST(0,{$field}+?) WHERE id=?")->execute([$type*abs($amount), $uid]);
        $flash = 'Saldo pengguna diperbarui.';
    }
}

$total  = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stmt   = $pdo->query("SELECT u.*, m.name as membership_name FROM users u LEFT JOIN memberships m ON m.id=u.membership_id ORDER BY u.created_at DESC");
$users  = $stmt->fetchAll();

$pageTitle  = 'Pengguna';
$activePage = 'users';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">👥 Pengguna</h5><small class="text-secondary"><?= number_format($total) ?> pengguna terdaftar</small></div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>Username</th><th>Email / WA</th><th>Saldo (WD/Dep)</th><th>Total Earned</th><th>Paket</th><th>Referral</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><strong style="font-size:13px"><?= htmlspecialchars($u['username']) ?></strong><div style="font-size:11px;color:#555"><?= date('d M Y', strtotime($u['created_at'])) ?></div></td>
          <td><div style="font-size:12px"><?= htmlspecialchars($u['email']) ?></div><div style="font-size:11px;color:#666"><?= htmlspecialchars($u['whatsapp']) ?></div></td>
          <td style="font-size:12px"><div style="color:#4CAF82;font-weight:700">WD: <?= format_rp((float)$u['balance_wd']) ?></div><div style="color:#4E9BFF;font-size:11px">Dep: <?= format_rp((float)$u['balance_dep']) ?></div></td>
          <td style="color:#888;font-size:12px"><?= format_rp((float)$u['total_earned']) ?></td>
          <td>
            <?php if ($u['membership_name'] && $u['membership_expires_at'] && strtotime($u['membership_expires_at'])>time()): ?>
            <span class="badge b-success" style="border-radius:6px;font-size:11px"><?= htmlspecialchars($u['membership_name']) ?></span>
            <?php else: ?><span class="badge b-neutral" style="border-radius:6px;font-size:11px">Free</span><?php endif; ?>
          </td>
          <td style="font-size:12px;letter-spacing:1px;color:#888"><?= $u['referral_code'] ?></td>
          <td>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="badge border-0 <?= $u['is_active']?'b-success':'b-danger' ?>" style="cursor:pointer;border-radius:6px;padding:4px 8px">
                <?= $u['is_active']?'Aktif':'Nonaktif' ?>
              </button>
            </form>
          </td>
          <td>
            <button class="btn btn-sm b-neutral" style="border-radius:8px;font-size:11px;border:none"
              onclick="adjustBalance(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">💰 Saldo</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($users)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada pengguna ditemukan.</div><?php endif; ?>
  </div>
</div>

<!-- Adjust balance modal -->
<div class="modal fade" id="balanceModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="adjust_balance"><input type="hidden" name="user_id" id="bal-uid">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="bal-title">Atur Saldo</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group mb-3">
        <label class="c-label">Jenis Saldo</label>
        <select name="bal_field" class="c-form-control">
          <option value="wd">Saldo Penarikan (WD)</option>
          <option value="dep">Saldo Deposit</option>
        </select>
      </div>
      <div class="c-form-group mb-3">
        <label class="c-label">Tipe</label>
        <select name="type" class="c-form-control"><option value="add">Tambah saldo</option><option value="deduct">Kurangi saldo</option></select>
      </div>
      <div class="c-form-group">
        <label class="c-label">Jumlah (Rp)</label>
        <input type="number" name="amount" class="c-form-control" min="1" step="1000" required>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function adjustBalance(id, name) {
  document.getElementById('bal-uid').value = id;
  document.getElementById('bal-title').textContent = 'Atur Saldo: ' + name;
  new bootstrap.Modal(document.getElementById('balanceModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
