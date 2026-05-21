<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    // Berikan reward ke user (WD Balance)
                    $reward = (float)$codeData['reward_amount'];
                    
                    $pdo->prepare("INSERT INTO user_redeems (user_id, code_id) VALUES (?, ?)")
                        ->execute([$user['id'], $codeData['id']]);
                        
                    $pdo->prepare("UPDATE redeem_codes SET claims_count = claims_count + 1 WHERE id = ?")
                        ->execute([$codeData['id']]);
                        
                    $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")
                        ->execute([$reward, $reward, $user['id']]);
                        
                    // Re-fetch user to reflect changes
                    $usrStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $usrStmt->execute([$user['id']]);
                    $user = $usrStmt->fetch();
                    
                    $pdo->commit();
                    $flash = '✅ Selamat! Kamu mendapatkan ' . format_rp($reward) . ' dari kode redeem.';
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
  <p>Tukarkan kodemu dan dapatkan reward tambahan!</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType ?>" style="margin-bottom:16px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="card card--mint" style="margin-bottom:16px">
  <div class="card__body">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">🎟️ Masukkan Kode Redeem</div>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:16px">
        <input class="form-control" type="text" name="code" 
               placeholder="Contoh: TONTON10K" style="text-transform:uppercase;letter-spacing:2px;font-weight:700" required>
      </div>
      <button type="submit" class="btn btn--primary btn--full">Klaim Reward</button>
    </form>
  </div>
</div>

<!-- Cara Kerja -->
<div class="card" style="margin-bottom:16px">
  <div class="card__header"><div class="card__title">💡 Informasi Kode Redeem</div></div>
  <div class="card__body" style="font-size:12px;color:#555">
    <ul style="padding-left:16px;margin:0">
      <li style="margin-bottom:4px">Pastikan kode yang dimasukkan sudah benar (huruf besar/kecil otomatis disesuaikan).</li>
      <li style="margin-bottom:4px">Setiap kode redeem memiliki batas waktu (kedaluwarsa) dan batas kuota klaim.</li>
      <li style="margin-bottom:4px">Satu akun hanya dapat mengklaim satu kode yang sama maksimal 1 (satu) kali.</li>
      <li>Reward akan otomatis masuk ke <strong>Saldo Penarikan (WD)</strong> kamu setelah berhasil diklaim.</li>
    </ul>
  </div>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
