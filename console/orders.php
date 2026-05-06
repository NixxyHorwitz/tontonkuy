<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
csrf_enforce();

use App\Order;

$order     = new Order($pdo);
$statusFilter = $_GET['status'] ?? 'all';
$search    = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;
$flash     = '';
$flashType = 'success';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $code   = trim($_POST['order_code'] ?? '');

    if ($action === 'confirm' && $code) {
        $ok = $order->confirm($code);
        $flash = $ok ? "Order {$code} berhasil dikonfirmasi!" : "Gagal konfirmasi order.";
        if (!$ok) $flashType = 'error';
    }
    if ($action === 'reject' && $code) {
        $reason = trim($_POST['reason'] ?? 'Ditolak oleh admin');
        $ok = $order->reject($code, $reason);
        $flash = $ok ? "Order {$code} berhasil ditolak." : "Gagal menolak order.";
        if (!$ok) $flashType = 'error';
    }
    if ($action === 'delete' && $code) {
        $stmt = $pdo->prepare("DELETE FROM orders WHERE order_code = ?");
        $ok   = $stmt->execute([$code]) && $stmt->rowCount() > 0;
        $flash = $ok ? "Order {$code} berhasil dihapus." : "Gagal hapus order.";
        if (!$ok) $flashType = 'error';
    }
    if ($action === 'delete_rejected') {
        $cnt = $pdo->exec("DELETE FROM orders WHERE status IN ('rejected','expired')");
        $flash = "Berhasil hapus {$cnt} order rejected/expired.";
    }
    if ($action === 'expire_all') {
        $cnt = $order->expire();
        $flash = "Expired {$cnt} order yang telah melewati batas waktu.";
    }
}

// Build query
$where  = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[]  = "status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[]  = "(order_code LIKE ? OR email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $totalRows = (int) $pdo->prepare("SELECT COUNT(*) FROM orders {$whereSQL}")->execute($params) + 0;
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders {$whereSQL}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $listStmt = $pdo->prepare("SELECT * FROM orders {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    $listStmt->execute($params);
    $orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Status counts for filter tabs
    $statusCounts = [];
    $scStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
    foreach ($scStmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
        $statusCounts[$sc['status']] = $sc['cnt'];
    }

} catch(\Exception $e) {
    $orders = [];
    $totalRows = 0;
    $statusCounts = [];
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$pageTitle  = 'Orders';
$activePage = 'orders';
require __DIR__ . '/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Manajemen Orders</h1>
    <p class="page-sub">Total <?= number_format($totalRows) ?> order ditemukan</p>
  </div>
  <div class="page-header__actions">
    <form method="POST" style="display:flex;gap:8px" onsubmit="return confirm('Hapus semua order rejected & expired?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_rejected">
      <button type="submit" class="btn btn--ghost btn--sm" style="color:var(--c-red)">Hapus Rejected/Expired</button>
    </form>
    <form method="POST" onsubmit="return confirm('Expire semua order kadaluarsa?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="expire_all">
      <button type="submit" class="btn btn--ghost btn--sm">Expire Kadaluarsa</button>
    </form>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:20px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="tabs">
  <?php
  $tabs = ['all'=>'Semua', 'pending'=>'Pending', 'confirmed'=>'Confirmed', 'rejected'=>'Rejected', 'expired'=>'Expired'];
  foreach ($tabs as $s => $label):
    $cnt = $s === 'all' ? array_sum($statusCounts) : ($statusCounts[$s] ?? 0);
    $url = "/console/orders.php?status={$s}" . ($search ? "&q=".urlencode($search) : '');
  ?>
  <a href="<?= $url ?>" class="tab-item <?= $statusFilter === $s ? 'active' : '' ?>">
    <?= $label ?>
    <?php if ($cnt > 0): ?><span class="tab-badge"><?= $cnt ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Search -->
<div class="search-bar">
  <form method="GET" style="display:flex;gap:8px;flex:1">
    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
    <div class="input-wrap" style="flex:1">
      <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" class="form-control" placeholder="Cari kode order atau email..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <button type="submit" class="btn btn--primary btn--sm">Cari</button>
    <?php if ($search): ?>
    <a href="/console/orders.php?status=<?= $statusFilter ?>" class="btn btn--ghost btn--sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<!-- Orders table -->
<div class="card" style="margin-top:16px">
  <div class="card__body" style="padding:0">
    <?php if (empty($orders)): ?>
      <div style="padding:40px;text-align:center;color:var(--c-text-hint)">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        Tidak ada order ditemukan
      </div>
    <?php else: ?>
    <table class="table table--hover">
      <thead>
        <tr>
          <th>Kode Order</th>
          <th>Email</th>
          <th>Metode</th>
          <th>Nominal</th>
          <th>Status</th>
          <th>Waktu</th>
          <th style="text-align:right">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td><code style="font-size:13px;font-weight:600"><?= htmlspecialchars($o['order_code']) ?></code></td>
          <td>
            <div><?= htmlspecialchars($o['email']) ?></div>
            <?php if ($o['ip_address']): ?>
            <div style="font-size:11px;color:var(--c-text-hint)"><?= htmlspecialchars($o['ip_address']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge badge--neutral"><?= strtoupper($o['method']) ?></span></td>
          <td style="font-weight:600"><?= Order::formatRp((int)$o['amount']) ?></td>
          <td>
            <span class="badge badge--<?= match($o['status']) { 'confirmed'=>'success', 'pending'=>'warn', 'rejected'=>'error', default=>'neutral' } ?>">
              <?= ucfirst($o['status']) ?>
            </span>
          </td>
          <td>
            <div style="font-size:12px"><?= date('d M Y', strtotime($o['created_at'])) ?></div>
            <div style="font-size:11px;color:var(--c-text-hint)"><?= date('H:i', strtotime($o['created_at'])) ?></div>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:flex-end;flex-wrap:wrap">
            <?php if ($o['status'] === 'pending'): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Konfirmasi order <?= $o['order_code'] ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="order_code" value="<?= $o['order_code'] ?>">
                <button type="submit" class="btn btn--success btn--xs">✓</button>
              </form>
              <button type="button" class="btn btn--danger btn--xs" onclick="openRejectModal('<?= $o['order_code'] ?>')">✗</button>
            <?php endif; ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Hapus order <?= $o['order_code'] ?> secara permanen?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="order_code" value="<?= $o['order_code'] ?>">
                <button type="submit" class="btn btn--ghost btn--xs" style="color:var(--c-red)" title="Hapus permanen">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <a href="?status=<?= $statusFilter ?>&q=<?= urlencode($search) ?>&page=<?= $p ?>" 
     class="page-link <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Reject Modal -->
<div class="modal-overlay" id="reject-modal" style="display:none">
  <div class="modal-box">
    <div class="modal-title">Tolak Order</div>
    <p style="font-size:14px;color:var(--c-text-sec);margin-bottom:16px">Masukkan alasan penolakan (opsional):</p>
    <form method="POST" id="reject-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="order_code" id="reject-code">
      <textarea name="reason" class="form-control" rows="3" placeholder="Contoh: Bukti bayar tidak valid"></textarea>
      <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
        <button type="button" class="btn btn--ghost" onclick="closeRejectModal()">Batal</button>
        <button type="submit" class="btn btn--danger">Tolak Order</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRejectModal(code) {
  document.getElementById('reject-code').value = code;
  document.getElementById('reject-modal').style.display = 'flex';
}
function closeRejectModal() {
  document.getElementById('reject-modal').style.display = 'none';
}
document.getElementById('reject-modal').addEventListener('click', function(e) {
  if (e.target === this) closeRejectModal();
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
