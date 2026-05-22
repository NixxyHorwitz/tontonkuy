<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('users');
csrf_enforce();

$flash = $flashType = '';

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

    if ($action === 'edit_user' && $uid) {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $whatsapp  = trim($_POST['whatsapp'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $account_name   = trim($_POST['account_name'] ?? '');
        $mem_id    = $_POST['membership_id'] === '' ? null : (int)$_POST['membership_id'];
        $mem_exp   = trim($_POST['membership_expires_at'] ?? '');
        $bal_wd    = (float)$_POST['balance_wd'];
        $bal_dep   = (float)$_POST['balance_dep'];
        $total_e   = (float)$_POST['total_earned'];
        $is_active = (int)($_POST['is_active'] ?? 0);
        $can_wd    = (int)($_POST['can_withdraw'] ?? 1);
        $can_chat  = (int)($_POST['can_chat'] ?? 1);
        $new_pass  = trim($_POST['new_password'] ?? '');

        $errors = [];
        if (strlen($username) < 3) $errors[] = 'Username minimal 3 karakter.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';

        // Check username/email uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $chk->execute([$username, $uid]);
        if ($chk->fetch()) $errors[] = 'Username sudah digunakan.';

        $chk2 = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
        $chk2->execute([$email, $uid]);
        if ($chk2->fetch()) $errors[] = 'Email sudah digunakan.';

        if ($errors) {
            $flash = implode(' ', $errors); $flashType = 'error';
        } else {
            $mem_exp_val = ($mem_exp && $mem_id) ? $mem_exp : null;
            $sql = "UPDATE users SET username=?, email=?, whatsapp=?, membership_id=?, membership_expires_at=?,
                    balance_wd=?, balance_dep=?, total_earned=?, is_active=?, can_withdraw=?, can_chat=?,
                    bank_name=?, account_number=?, account_name=? WHERE id=?";
            $pdo->prepare($sql)->execute([
                $username, $email, $whatsapp, $mem_id, $mem_exp_val,
                $bal_wd, $bal_dep, $total_e, $is_active, $can_wd, $can_chat,
                $bank_name, $account_number, $account_name, $uid
            ]);
            if ($new_pass !== '') {
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                    ->execute([password_hash($new_pass, PASSWORD_BCRYPT), $uid]);
            }
            $flash = "User '{$username}' berhasil diperbarui.";
        }
    }
    if ($action === 'refund_level' && $uid) {
        $cut = isset($_POST['cut']) ? (int)$_POST['cut'] : 0;
        
        $s = $pdo->prepare("SELECT u.membership_id, m.price, m.name FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
        $s->execute([$uid]);
        $uInfo = $s->fetch();
        
        if (!$uInfo || !$uInfo['membership_id']) {
            $flash = 'User tidak memiliki paket aktif.'; $flashType = 'error';
        } else {
            $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
            $oStmt->execute([$uid, $uInfo['membership_id']]);
            $basePrice = (float)$oStmt->fetchColumn();
            
            if (!$basePrice) $basePrice = (float)$uInfo['price'];
            
            $refundAmt = $cut === 15 ? ($basePrice * 0.85) : $basePrice;
            
            $pdo->prepare("UPDATE users SET balance_dep = balance_dep + ?, membership_id = NULL, membership_expires_at = NULL WHERE id = ?")
                ->execute([$refundAmt, $uid]);
                
            $flash = "Refund sukses untuk paket {$uInfo['name']}. Saldo dikembalikan: " . format_rp($refundAmt);
        }
    }
}

// Load memberships for dropdown
$memberships = $pdo->query("SELECT id, name FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();

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

<style>
/* Responsive Table for Mobile */
@media (max-width: 768px) {
  .c-table thead { display: none; }
  .c-table, .c-table tbody, .c-table tr, .c-table td { display: block; width: 100%; border: none; }
  .c-table tr { margin-bottom: 16px; border: 2px solid var(--ink); border-radius: 12px; padding: 12px; background: #fff; box-shadow: 4px 4px 0 var(--ink); }
  .c-table td { text-align: right; padding-left: 45%; position: relative; border-bottom: 1.5px solid #f0f0f0; min-height: 40px; display: flex; justify-content: flex-end; align-items: center; }
  .c-table td:last-child { border-bottom: 0; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 6px; padding-left: 0; margin-top: 10px; }
  .c-table td::before { content: attr(data-label); position: absolute; left: 0; width: 45%; text-align: left; font-weight: 800; color: #777; font-size: 11px; }
  .c-table td .btn { flex: 1; min-width: 30%; }
}
</style>

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
          <td data-label="Username"><strong style="font-size:13px"><?= htmlspecialchars($u['username']) ?></strong><div style="font-size:11px;color:#555"><?= date('d M Y', strtotime($u['created_at'])) ?></div></td>
          <td data-label="Kontak"><div style="font-size:12px"><?= htmlspecialchars($u['email']) ?></div><div style="font-size:11px;color:#666"><?= htmlspecialchars($u['whatsapp']) ?></div></td>
          <td data-label="Saldo"><div style="color:#4CAF82;font-weight:700;font-size:12px">WD: <?= format_rp((float)$u['balance_wd']) ?></div><div style="color:#4E9BFF;font-size:11px">Dep: <?= format_rp((float)$u['balance_dep']) ?></div></td>
          <td data-label="Total Earned" style="color:#888;font-size:12px"><?= format_rp((float)$u['total_earned']) ?></td>
          <td data-label="Paket">
            <?php if ($u['membership_name'] && $u['membership_expires_at'] && strtotime($u['membership_expires_at'])>time()): ?>
            <span class="badge b-success" style="border-radius:6px;font-size:11px"><?= htmlspecialchars($u['membership_name']) ?></span>
            <?php else: ?><span class="badge b-neutral" style="border-radius:6px;font-size:11px">Free</span><?php endif; ?>
          </td>
          <td data-label="Referral" style="font-size:12px;letter-spacing:1px;color:#888"><?= $u['referral_code'] ?></td>
          <td data-label="Status">
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="badge border-0 <?= $u['is_active']?'b-success':'b-danger' ?>" style="cursor:pointer;border-radius:6px;padding:4px 8px">
                <?= $u['is_active']?'Aktif':'Nonaktif' ?>
              </button>
            </form>
          </td>
          <td data-label="Aksi">
            <button class="btn btn-sm b-neutral" style="border-radius:8px;font-size:11px;border:none;margin-right:4px;background:#f0f0f0;color:var(--ink)"
              onclick="editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">✏️ Edit</button>
            <button class="btn btn-sm b-neutral" style="border-radius:8px;font-size:11px;border:none;margin-right:4px;background:#e3f2fd;color:#0d47a1"
              onclick="adjustBalance(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">💰 Saldo</button>
            <?php if ($u['membership_id'] && $u['membership_name']): ?>
            <button class="btn btn-sm b-neutral" style="border-radius:8px;font-size:11px;border:none;background:#ffebee;color:#c62828"
              onclick="refundLevel(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>', '<?= htmlspecialchars($u['membership_name']) ?>')">⏪ Refund</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($users)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada pengguna ditemukan.</div><?php endif; ?>
  </div>
</div>

<!-- ── Edit User Modal ───────────────────────────────── -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" id="edit-user-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" id="eu-uid">
    <div class="modal-header border-0">
      <h6 class="modal-title fw-bold" id="eu-title">✏️ Edit Pengguna</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="row g-3">
        <!-- Col 1 -->
        <div class="col-md-6">
          <div class="c-form-group mb-3">
            <label class="c-label">Username</label>
            <input type="text" name="username" id="eu-username" class="c-form-control" required minlength="3">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Email</label>
            <input type="email" name="email" id="eu-email" class="c-form-control" required>
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">WhatsApp</label>
            <input type="text" name="whatsapp" id="eu-whatsapp" class="c-form-control">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Password Baru <small style="color:#666">(kosongkan jika tidak diubah)</small></label>
            <input type="text" name="new_password" class="c-form-control" placeholder="Biarkan kosong jika tidak diubah">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Status Akun</label>
            <select name="is_active" id="eu-is-active" class="c-form-control">
              <option value="1">Aktif</option>
              <option value="0">Nonaktif</option>
            </select>
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Akses Withdraw</label>
            <select name="can_withdraw" id="eu-can-wd" class="c-form-control">
              <option value="1">Diizinkan</option>
              <option value="0">Dibatasi (Blocked)</option>
            </select>
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Akses LiveChat</label>
            <select name="can_chat" id="eu-can-chat" class="c-form-control">
              <option value="1">Diizinkan</option>
              <option value="0">Dibatasi (Blocked)</option>
            </select>
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Nama Bank / E-Wallet</label>
            <input type="text" name="bank_name" id="eu-bank-name" class="c-form-control">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Nomor Rekening</label>
            <input type="text" name="account_number" id="eu-acc-num" class="c-form-control">
          </div>
          <div class="c-form-group">
            <label class="c-label">Nama Pemilik Rekening</label>
            <input type="text" name="account_name" id="eu-acc-name" class="c-form-control">
          </div>
        </div>
        <!-- Col 2 -->
        <div class="col-md-6">
          <div class="c-form-group mb-3">
            <label class="c-label">Saldo WD (Rp)</label>
            <input type="number" name="balance_wd" id="eu-bal-wd" class="c-form-control" step="0.01" min="0">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Saldo Deposit (Rp)</label>
            <input type="number" name="balance_dep" id="eu-bal-dep" class="c-form-control" step="0.01" min="0">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Total Earned (Rp)</label>
            <input type="number" name="total_earned" id="eu-total-earned" class="c-form-control" step="0.01" min="0">
          </div>
          <div class="c-form-group mb-3">
            <label class="c-label">Paket Membership</label>
            <select name="membership_id" id="eu-mem-id" class="c-form-control">
              <option value="">Free (Tidak ada)</option>
              <?php foreach ($memberships as $m): ?>
              <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="c-form-group">
            <label class="c-label">Expires At Membership</label>
            <input type="datetime-local" name="membership_expires_at" id="eu-mem-exp" class="c-form-control">
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">💾 Simpan Perubahan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- ── Adjust Balance Modal ───────────────────────────── -->
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

<!-- ── Refund Level Modal ───────────────────────────── -->
<div class="modal fade" id="refundModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <div class="modal-header border-0">
      <h6 class="modal-title fw-bold" id="ref-title">Refund Level</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body text-center">
      <p style="font-size:13px;color:#ccc;margin-bottom:16px;">Pilih jenis refund untuk mengembalikan level ke Free dan saldo dikembalikan ke Deposit.</p>
      <form method="POST" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="refund_level"><input type="hidden" name="user_id" id="ref-uid-1"><input type="hidden" name="cut" value="0">
        <button type="submit" class="btn w-100 mb-2" style="background:var(--success);color:#fff;font-weight:700;font-size:13px;">✅ Refund 100% (Utuh)</button>
      </form>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="refund_level"><input type="hidden" name="user_id" id="ref-uid-2"><input type="hidden" name="cut" value="15">
        <button type="submit" class="btn w-100" style="background:var(--danger);color:#fff;font-weight:700;font-size:13px;">✂️ Refund (Potong 15%)</button>
      </form>
    </div>
  </div></div>
</div>

<script>
function refundLevel(id, name, level) {
  document.getElementById('ref-uid-1').value = id;
  document.getElementById('ref-uid-2').value = id;
  document.getElementById('ref-title').textContent = 'Refund: ' + level + ' (' + name + ')';
  new bootstrap.Modal(document.getElementById('refundModal')).show();
}

function adjustBalance(id, name) {
  document.getElementById('bal-uid').value = id;
  document.getElementById('bal-title').textContent = 'Atur Saldo: ' + name;
  new bootstrap.Modal(document.getElementById('balanceModal')).show();
}

function editUser(u) {
  document.getElementById('eu-uid').value        = u.id;
  document.getElementById('eu-title').textContent = '✏️ Edit: ' + u.username;
  document.getElementById('eu-username').value    = u.username;
  document.getElementById('eu-email').value       = u.email;
  document.getElementById('eu-whatsapp').value    = u.whatsapp || '';
  document.getElementById('eu-bal-wd').value      = u.balance_wd;
  document.getElementById('eu-bal-dep').value     = u.balance_dep;
  document.getElementById('eu-total-earned').value= u.total_earned;
  document.getElementById('eu-is-active').value   = u.is_active;
  document.getElementById('eu-can-wd').value      = u.can_withdraw !== undefined ? u.can_withdraw : 1;
  document.getElementById('eu-can-chat').value    = u.can_chat !== undefined ? u.can_chat : 1;
  document.getElementById('eu-mem-id').value      = u.membership_id || '';
  document.getElementById('eu-bank-name').value   = u.bank_name || '';
  document.getElementById('eu-acc-num').value     = u.account_number || '';
  document.getElementById('eu-acc-name').value    = u.account_name || '';

  // Format datetime-local: "2026-05-06 15:00:00" → "2026-05-06T15:00"
  const exp = u.membership_expires_at;
  document.getElementById('eu-mem-exp').value = exp ? exp.replace(' ', 'T').slice(0, 16) : '';

  new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
