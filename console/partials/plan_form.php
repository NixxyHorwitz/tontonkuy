<div class="c-form-group mb-3">
  <label class="c-label">Nama Paket</label>
  <input type="text" name="name" class="c-form-control" placeholder="Contoh: Gold" required>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Harga (Rp)</label>
    <input type="number" name="price" class="c-form-control" value="0" min="0" step="1000">
  </div>
  <div class="col-6">
    <label class="c-label">Limit Tonton/Hari</label>
    <input type="number" name="watch_limit" class="c-form-control" value="10" min="1">
  </div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Durasi (hari)</label>
    <input type="number" name="duration_days" class="c-form-control" value="30" min="1">
  </div>
  <div class="col-6">
    <label class="c-label">Urutan Tampil</label>
    <input type="number" name="sort_order" class="c-form-control" value="0">
  </div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="c-label">Min. Withdraw (Rp)</label>
    <input type="number" name="min_wd" class="c-form-control" value="50000" min="0" step="1000">
  </div>
  <div class="col-6">
    <label class="c-label">Max. Withdraw (Rp)</label>
    <input type="number" name="max_wd" class="c-form-control" value="0" min="0" step="1000">
    <small style="font-size:10px;color:#888">0 = Tanpa batas max</small>
  </div>
</div>
<div class="c-form-group mb-3">
  <label class="c-label">Deskripsi Singkat</label>
  <input type="text" name="description" class="c-form-control" placeholder="Opsional">
</div>
<div class="form-check ms-1">
  <input class="form-check-input" type="checkbox" name="is_active" id="plan_active_add" checked>
  <label class="form-check-label text-secondary" for="plan_active_add" style="font-size:13px">Aktif</label>
</div>
