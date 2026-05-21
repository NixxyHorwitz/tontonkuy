<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

$flash = $flashType = '';

// Handle Delete
if (isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM redeem_codes WHERE id=?")->execute([$del]);
    $flash = "Kode redeem berhasil dihapus!";
    $flashType = "success";
}

// Handle Add
if (isset($_POST['add_code'])) {
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $reward = (float)preg_replace('/\D/', '', $_POST['reward'] ?? '0');
    $quota  = (int)($_POST['quota'] ?? 0);
    $expiry = trim($_POST['expiry'] ?? '');
    
    if (!$code || $reward <= 0) {
        $flash = "Kode dan reward tidak boleh kosong/nol!";
        $flashType = "danger";
    } else {
        $exp_date = null;
        if ($expiry) {
            $exp_date = date('Y-m-d H:i:s', strtotime($expiry));
        }
        
        try {
            $pdo->prepare("INSERT INTO redeem_codes (code, reward_amount, max_claims, expires_at) VALUES (?, ?, ?, ?)")
                ->execute([$code, $reward, $quota, $exp_date]);
            $flash = "Kode redeem berhasil ditambahkan!";
            $flashType = "success";
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                $flash = "Kode redeem '$code' sudah ada!";
            } else {
                $flash = "Terjadi kesalahan database.";
            }
            $flashType = "danger";
        }
    }
}

$codes = $pdo->query("SELECT * FROM redeem_codes ORDER BY created_at DESC")->fetchAll();

$pageTitle  = 'Kode Redeem';
$activePage = 'redeem';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0 text-white" style="font-weight:700">Manajemen Kode Redeem</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">+ Tambah Kode</button>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="c-card">
  <div class="c-card-body p-0">
    <div class="table-responsive">
      <table class="c-table">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Reward</th>
            <th>Terpakai / Kuota</th>
            <th>Kedaluwarsa</th>
            <th>Dibuat</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($codes as $c): ?>
          <?php 
            $is_expired = $c['expires_at'] && strtotime($c['expires_at']) < time();
            $is_depleted = $c['max_claims'] > 0 && $c['claims_count'] >= $c['max_claims'];
            $status = 'Aktif';
            $badge = 'success';
            if ($is_expired) { $status = 'Expired'; $badge = 'danger'; }
            elseif ($is_depleted) { $status = 'Habis'; $badge = 'warning text-dark'; }
          ?>
          <tr>
            <td>
              <strong style="font-family:monospace;font-size:14px;letter-spacing:1px;color:var(--brand)"><?= htmlspecialchars($c['code']) ?></strong><br>
              <span class="badge bg-<?= $badge ?>" style="font-size:10px"><?= $status ?></span>
            </td>
            <td style="color:#4CAF82;font-weight:700"><?= format_rp((float)$c['reward_amount']) ?></td>
            <td>
              <?= $c['claims_count'] ?> / <?= $c['max_claims'] > 0 ? $c['max_claims'] : '∞' ?>
            </td>
            <td style="color:#aaa;font-size:12px">
              <?= $c['expires_at'] ? date('d M Y H:i', strtotime($c['expires_at'])) : 'Tanpa batas' ?>
            </td>
            <td style="color:#888;font-size:12px"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Hapus kode ini?');" style="display:inline-block">
                <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger py-1 px-2" style="font-size:11px">Hapus</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($codes)): ?>
          <tr><td colspan="6" class="text-center py-4 text-muted">Belum ada kode redeem</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Add -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" style="background:#131520;border:1px solid #1f2235" method="POST">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title text-white fw-bold">Tambah Kode Redeem</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="add_code" value="1">
        
        <div class="mb-3">
          <label class="c-label">Kode <span class="text-danger">*</span></label>
          <input type="text" name="code" class="c-form-control" required style="text-transform:uppercase" placeholder="Contoh: PROMO10K">
        </div>
        
        <div class="mb-3">
          <label class="c-label">Nominal Reward (Rp) <span class="text-danger">*</span></label>
          <input type="number" name="reward" class="c-form-control" required min="100" step="100" placeholder="10000">
        </div>
        
        <div class="mb-3">
          <label class="c-label">Batas Kuota Klaim</label>
          <input type="number" name="quota" class="c-form-control" min="0" placeholder="0 = Tanpa batas kuota">
          <small class="text-muted" style="font-size:11px">Biarkan kosong atau isi 0 jika tidak ada batasan kuota.</small>
        </div>
        
        <div class="mb-3">
          <label class="c-label">Tanggal Kedaluwarsa</label>
          <input type="datetime-local" name="expiry" class="c-form-control">
          <small class="text-muted" style="font-size:11px">Biarkan kosong jika kode tidak memiliki batas waktu.</small>
        </div>
      </div>
      <div class="modal-footer border-top-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan Kode</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
