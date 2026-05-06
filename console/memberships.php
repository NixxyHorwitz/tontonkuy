<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
csrf_enforce();

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        $name     = trim($_POST['name'] ?? '');
        $price    = (float)preg_replace('/[^\d.]/', '', $_POST['price'] ?? '0');
        $limit    = (int)($_POST['watch_limit'] ?? 10);
        $days     = (int)($_POST['duration_days'] ?? 30);
        $desc     = trim($_POST['description'] ?? '');
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);

        if (!$name) { $flash = 'Nama paket wajib diisi.'; $flashType = 'error'; }
        else {
            if ($action === 'add') {
                $pdo->prepare("INSERT INTO memberships (name,price,watch_limit,duration_days,description,is_active,sort_order) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name, $price, $limit, $days, $desc, $active, $sort]);
                $flash = "Paket {$name} ditambahkan.";
            } else {
                $pdo->prepare("UPDATE memberships SET name=?,price=?,watch_limit=?,duration_days=?,description=?,is_active=?,sort_order=? WHERE id=?")
                    ->execute([$name, $price, $limit, $days, $desc, $active, $sort, $id]);
                $flash = "Paket berhasil diperbarui.";
            }
        }
    }
    if ($action === 'delete' && $id) {
        $pdo->prepare("DELETE FROM memberships WHERE id=? AND price>0")->execute([$id]);
        $flash = 'Paket dihapus.';
    }
}

$plans = $pdo->query("SELECT * FROM memberships ORDER BY sort_order ASC, price ASC")->fetchAll();

$pageTitle  = 'Paket Membership';
$activePage = 'memberships';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">⭐ Paket Membership</h5></div>
  <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addPlanModal">+ Tambah Paket</button>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($plans as $p):
    $colors = ['#888','#4E9BFF','#FFC107','#4CAF82'];
    $icons  = ['🆓','🥈','🥇','💎'];
    $color  = $colors[min($p['sort_order'], 3)];
    $icon   = $icons[min($p['sort_order'], 3)];
    $activeUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE membership_id=? AND membership_expires_at>NOW()");
    $activeUsers->execute([$p['id']]); $activeUsers = $activeUsers->fetchColumn();
  ?>
  <div class="col-md-6 col-xl-3">
    <div class="c-card h-100">
      <div class="c-card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div style="font-size:28px"><?= $icon ?></div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm b-neutral" style="border:none;border-radius:8px;font-size:11px" onclick='editPlan(<?= json_encode($p) ?>)'>✏️</button>
            <?php if ((float)$p['price'] > 0): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus paket ini?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px">🗑</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <div style="font-size:16px;font-weight:800;color:<?= $color ?>"><?= htmlspecialchars($p['name']) ?></div>
        <div style="font-size:22px;font-weight:800;margin:4px 0"><?= (float)$p['price']===0.0?'Gratis':format_rp((float)$p['price']) ?></div>
        <div style="font-size:12px;color:#666;margin-bottom:12px"><?= (float)$p['price']>0?'/ '.$p['duration_days'].' hari':'' ?></div>
        <div style="font-size:13px;color:#888;display:flex;flex-direction:column;gap:4px">
          <div>📹 <?= $p['watch_limit'] ?>× video/hari</div>
          <?php if ($p['description']): ?><div>ℹ️ <?= htmlspecialchars($p['description']) ?></div><?php endif; ?>
        </div>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid #1f2235;font-size:12px;color:#666">
          👥 <strong style="color:#e0e0f0"><?= $activeUsers ?></strong> user aktif
          · <?= $p['is_active']?'<span style="color:#4CAF82">Aktif</span>':'<span style="color:#F44E3B">Nonaktif</span>' ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addPlanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah Paket</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="add-plan-body">
      <?php include __DIR__ . '/partials/plan_form.php'; ?>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editPlanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="ep-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit Paket</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="ep-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function editPlan(p) {
  document.getElementById('ep-id').value = p.id;
  document.getElementById('ep-body').innerHTML = `
    <div class="c-form-group mb-3"><label class="c-label">Nama Paket</label>
      <input type="text" name="name" class="c-form-control" value="${escH(p.name)}" required></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Harga (Rp)</label>
        <input type="number" name="price" class="c-form-control" value="${p.price}" min="0" step="1000"></div>
      <div class="col-6"><label class="c-label">Limit Tonton/Hari</label>
        <input type="number" name="watch_limit" class="c-form-control" value="${p.watch_limit}" min="1"></div>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Durasi (hari)</label>
        <input type="number" name="duration_days" class="c-form-control" value="${p.duration_days}" min="1"></div>
      <div class="col-6"><label class="c-label">Urutan</label>
        <input type="number" name="sort_order" class="c-form-control" value="${p.sort_order}"></div>
    </div>
    <div class="c-form-group mb-3"><label class="c-label">Deskripsi</label>
      <input type="text" name="description" class="c-form-control" value="${escH(p.description||'')}"></div>
    <div class="form-check ms-1"><input class="form-check-input" type="checkbox" name="is_active" id="ep-active" ${p.is_active==1?'checked':''}>
      <label class="form-check-label text-secondary" for="ep-active" style="font-size:13px">Aktif</label></div>`;
  new bootstrap.Modal(document.getElementById('editPlanModal')).show();
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
