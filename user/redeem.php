<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'claim';
    
    // -- AJAX Check Endpoint --
    if ($action === 'check') {
        header('Content-Type: application/json');
        $code_input = strtoupper(trim($_POST['code'] ?? ''));
        if (!$code_input) { echo json_encode(['error' => 'Masukkan kode redeem.']); exit; }
        
        $stmt = $pdo->prepare("SELECT * FROM redeem_codes WHERE code = ?");
        $stmt->execute([$code_input]);
        $codeData = $stmt->fetch();
        
        if (!$codeData) { echo json_encode(['error' => 'Kode redeem tidak ditemukan atau tidak valid.']); exit; }
        if ($codeData['expires_at'] && strtotime($codeData['expires_at']) < time()) { echo json_encode(['error' => 'Kode redeem ini sudah kedaluwarsa.']); exit; }
        if ($codeData['max_claims'] > 0 && $codeData['claims_count'] >= $codeData['max_claims']) { echo json_encode(['error' => 'Kode redeem ini sudah mencapai batas kuota klaim.']); exit; }
        
        $chk = $pdo->prepare("SELECT id FROM user_redeems WHERE user_id = ? AND code_id = ?");
        $chk->execute([$user['id'], $codeData['id']]);
        if ($chk->fetch()) { echo json_encode(['error' => 'Kamu sudah mengklaim kode ini sebelumnya.']); exit; }
        
        $msg_parts = [];
        if ($codeData['reward_wd'] > 0) $msg_parts[] = 'Saldo WD: ' . format_rp((float)$codeData['reward_wd']);
        if ($codeData['reward_dep'] > 0) $msg_parts[] = 'Saldo Deposit: ' . format_rp((float)$codeData['reward_dep']);
        if ($codeData['reward_level_id']) {
            $ls = $pdo->prepare("SELECT name FROM memberships WHERE id = ?");
            $ls->execute([$codeData['reward_level_id']]);
            if ($lname = $ls->fetchColumn()) $msg_parts[] = 'Level: ' . $lname;
        }
        
        echo json_encode(['ok' => true, 'details' => implode("\\n- ", $msg_parts)]);
        exit;
    }
    // -- End AJAX Check --

    $code_input = strtoupper(trim($_POST['code'] ?? ''));
    if (!$code_input) {
        $flash = 'Masukkan kode redeem.';
        $flashType = 'error';
    } else {
        $pdo->beginTransaction();
        
        // Cek kode exist dan valid
        $stmt = $pdo->prepare("SELECT * FROM redeem_codes WHERE code = ? FOR UPDATE");
        $stmt->execute([$code_input]);
        $codeData = $stmt->fetch();
        
        if (!$codeData) {
            $flash = 'Kode redeem tidak ditemukan atau tidak valid.';
            $flashType = 'error';
        } else {
            if ($codeData['expires_at'] && strtotime($codeData['expires_at']) < time()) {
                $flash = 'Kode redeem ini sudah kedaluwarsa.';
                $flashType = 'error';
            } elseif ($codeData['max_claims'] > 0 && $codeData['claims_count'] >= $codeData['max_claims']) {
                $flash = 'Kode redeem ini sudah mencapai batas kuota klaim.';
                $flashType = 'error';
            } else {
                // Cek apakah user sudah pernah klaim
                $chk = $pdo->prepare("SELECT id FROM user_redeems WHERE user_id = ? AND code_id = ?");
                $chk->execute([$user['id'], $codeData['id']]);
                if ($chk->fetch()) {
                    $flash = 'Kamu sudah mengklaim kode ini sebelumnya.';
                    $flashType = 'error';
                } else {
                    // Record klaim
                    $pdo->prepare("INSERT INTO user_redeems (user_id, code_id) VALUES (?, ?)")
                        ->execute([$user['id'], $codeData['id']]);
                        
                    $pdo->prepare("UPDATE redeem_codes SET claims_count = claims_count + 1 WHERE id = ?")
                        ->execute([$codeData['id']]);
                        
                    // Berikan reward ke user
                    $r_wd  = (float)$codeData['reward_wd'];
                    $r_dep = (float)$codeData['reward_dep'];
                    $r_lvl = $codeData['reward_level_id'];
                    
                    $updateSql = "UPDATE users SET balance_wd = balance_wd + ?, balance_dep = balance_dep + ?, total_earned = total_earned + ?";
                    $updateParams = [$r_wd, $r_dep, $r_wd]; // hanya WD yang dihitung total earned (opsional, tergantung logic bisnis)

                    $level_name = '';
                    if ($r_lvl) {
                        // Get level duration
                        $ls = $pdo->prepare("SELECT name, duration_days FROM memberships WHERE id = ?");
                        $ls->execute([$r_lvl]);
                        $levelData = $ls->fetch();
                        if ($levelData) {
                            $level_name = $levelData['name'];
                            $days = (int)$levelData['duration_days'];
                            $updateSql .= ", membership_id = ?, membership_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)";
                            $updateParams[] = $r_lvl;
                            $updateParams[] = $days;
                        }
                    }

                    $updateSql .= " WHERE id = ?";
                    $updateParams[] = $user['id'];
                    
                    $pdo->prepare($updateSql)->execute($updateParams);
                        
                    // Re-fetch user to reflect changes
                    $usrStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $usrStmt->execute([$user['id']]);
                    $user = $usrStmt->fetch();
                    
                    $pdo->commit();
                    
                    $msg_parts = [];
                    if ($r_wd > 0) $msg_parts[] = 'Saldo WD ' . format_rp($r_wd);
                    if ($r_dep > 0) $msg_parts[] = 'Saldo Deposit ' . format_rp($r_dep);
                    if ($level_name) $msg_parts[] = 'Level ' . $level_name;
                    
                    $flash = '✅ Selamat! Kamu mendapatkan ' . implode(', ', $msg_parts) . '.';
                    $flashType = 'success';
                    goto done_redeem;
                }
            }
        }
        $pdo->rollBack();
    }
}
done_redeem:

$pageTitle  = 'Redeem Code — TontonKuy';
$activePage = 'redeem';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>🎁 Kode Redeem</h1>
  <p>Tukarkan kodemu dan dapatkan reward melimpah!</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType ?>" style="margin-bottom:16px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="card card--mint" style="margin-bottom:16px">
  <div class="card__body">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">🎟️ Masukkan Kode Redeem</div>
    <form id="form-claim" method="POST" onsubmit="checkRedeem(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="claim">
      <div class="form-group" style="margin-bottom:16px">
        <input class="form-control" type="text" name="code" 
               placeholder="Contoh: TONTONVIP" style="text-transform:uppercase;letter-spacing:2px;font-weight:700" required>
      </div>
      <button type="submit" id="btn-check" class="btn btn--primary btn--full">Cek & Klaim</button>
    </form>
  </div>
</div>

<!-- Cara Kerja -->
<div class="card" style="margin-bottom:16px">
  <div class="card__header"><div class="card__title">💡 Informasi Kode Redeem</div></div>
  <div class="card__body" style="font-size:12px;color:#555">
    <ul style="padding-left:16px;margin:0">
      <li style="margin-bottom:4px">Pastikan kode yang dimasukkan sudah benar (huruf besar/kecil otomatis disesuaikan).</li>
      <li style="margin-bottom:4px">Satu akun hanya dapat mengklaim satu kode maksimal 1 (satu) kali.</li>
      <li>Kode redeem dapat memberikan kombinasi reward berupa <strong>Saldo WD, Saldo Deposit, maupun Level (Membership)</strong>.</li>
    </ul>
  </div>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>

<script>
function checkRedeem(e) {
    e.preventDefault();
    const form = document.getElementById('form-claim');
    const code = form.querySelector('input[name="code"]').value;
    const btn = document.getElementById('btn-check');
    
    btn.disabled = true;
    btn.innerText = 'Mengecek...';
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check&code=' + encodeURIComponent(code) + '&csrf_token=' + encodeURIComponent(form.querySelector('input[name="csrf_token"]')?.value || '')
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerText = 'Cek & Klaim';
        if (res.error) {
            alert(res.error);
        } else {
            if (confirm("🎁 Kode ini berisi reward berikut:\n\n- " + res.details + "\n\nApakah kamu yakin ingin mengklaimnya sekarang?")) {
                form.onsubmit = null;
                form.submit();
            }
        }
    })
    .catch(e => {
        btn.disabled = false;
        btn.innerText = 'Cek & Klaim';
        alert('Terjadi kesalahan jaringan.');
    });
}
</script>
