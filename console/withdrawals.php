<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
csrf_enforce();

$flash = $flashType = '';
$filter = $_GET['status'] ?? 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    if ($action === 'approve' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $pdo->prepare("UPDATE withdrawals SET status='approved',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note, $id]);
            $flash = "Withdraw #{$id} disetujui.";
        }
    }
    if ($action === 'reject' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            // Refund balance
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
            $pdo->prepare("UPDATE withdrawals SET status='rejected',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note ?: 'Ditolak admin', $id]);
            $flash = "Withdraw #{$id} ditolak dan saldo dikembalikan.";
        }
    }
}

$where = $filter !== 'all' ? "WHERE status=?" : "";
$params = $filter !== 'all' ? [$filter] : [];
$rows = $pdo->prepare("SELECT w.*, u.username, u.email FROM withdrawals w JOIN users u ON u.id=w.user_id $where ORDER BY w.created_at DESC LIMIT 50");
$rows->execute($params); $rows = $rows->fetchAll();

// Counts
$counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM withdrawals GROUP BY status")->fetchAll();
$countMap = array_column($counts, 'cnt', 'status');

$pageTitle  = 'Manajemen Withdraw';
$activePage = 'withdrawals';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">⬇️ Manajemen Withdraw</h5></div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['all'=>'Semua','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $s=>$lbl): ?>
  <a href="?status=<?= $s ?>" class="btn btn-sm <?= $filter===$s?'text-white':'btn-secondary' ?>" style="<?= $filter===$s?'background:var(--brand)':'' ?>">
    <?= $lbl ?> <?php $cnt=$s==='all'?array_sum($countMap):($countMap[$s]??0); if($cnt>0): ?><span class="badge bg-dark ms-1"><?= $cnt ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>User</th><th>Jumlah</th><th>Bank/Akun</th><th>Tanggal</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $w): ?>
        <tr>
          <td><strong style="font-size:13px"><?= htmlspecialchars($w['username']) ?></strong><div style="font-size:11px;color:#666"><?= htmlspecialchars($w['email']) ?></div></td>
          <td style="color:#FF6B35;font-weight:700;font-size:15px"><?= format_rp((float)$w['amount']) ?></td>
          <td>
            <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($w['bank_name']) ?></div>
            <div style="font-size:12px;color:#888"><?= htmlspecialchars($w['account_number']) ?></div>
            <div style="font-size:11px;color:#666">a.n. <?= htmlspecialchars($w['account_name']) ?></div>
          </td>
          <td style="font-size:12px;color:#666"><?= date('d M Y H:i', strtotime($w['created_at'])) ?></td>
          <td>
            <span class="badge <?= match($w['status']){'approved'=>'b-success','pending'=>'b-warn','rejected'=>'b-danger'} ?>" style="border-radius:6px;padding:4px 8px">
              <?= ucfirst($w['status']) ?>
            </span>
            <?php if ($w['admin_note']): ?><div style="font-size:11px;color:#666;margin-top:3px"><?= htmlspecialchars($w['admin_note']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($w['status'] === 'pending'): ?>
            <button class="btn btn-sm b-success" style="border:none;border-radius:8px;font-size:11px" onclick="processWd(<?= $w['id'] ?>,'approve')">✓ Approve</button>
            <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px" onclick="processWd(<?= $w['id'] ?>,'reject')">✗ Reject</button>
            <?php else: ?>
            <span style="font-size:11px;color:#555">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($rows)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada data.</div><?php endif; ?>
  </div>
</div>

<!-- Process modal -->
<div class="modal fade" id="wdModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="wd-action">
    <input type="hidden" name="id" id="wd-id">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="wd-title">Proses Withdraw</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group"><label class="c-label">Catatan Admin (opsional)</label>
        <textarea name="note" class="c-form-control" rows="2" placeholder="Catatan untuk user..."></textarea></div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" id="wd-submit" class="btn btn-sm text-white" style="background:var(--brand)">Konfirmasi</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function processWd(id, action) {
  document.getElementById('wd-id').value = id;
  document.getElementById('wd-action').value = action;
  document.getElementById('wd-title').textContent = action==='approve'?'✅ Setujui Withdraw':'❌ Tolak Withdraw';
  document.getElementById('wd-submit').style.background = action==='approve'?'#4CAF82':'#F44E3B';
  new bootstrap.Modal(document.getElementById('wdModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
