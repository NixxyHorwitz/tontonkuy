<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

$uid = (int)($_GET['id'] ?? 0);
if (!$uid) {
    echo "ID User tidak valid.";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$uid]);
$u = $stmt->fetch();
if (!$u) {
    echo "User tidak ditemukan.";
    exit;
}

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wd  = (float)preg_replace('/\D/', '', $_POST['balance_wd'] ?? '0');
    $dep = (float)preg_replace('/\D/', '', $_POST['balance_dep'] ?? '0');
    $ebdm = (int)preg_replace('/\D/', '', $_POST['edit_bank_deposit_min'] ?? '50000');
    
    $pdo->prepare("UPDATE users SET balance_wd=?, balance_dep=?, edit_bank_deposit_min=? WHERE id=?")->execute([$wd, $dep, $ebdm, $uid]);
    
    $flash = "Saldo berhasil diupdate!";
    $flashType = "success";
    
    // Refresh data
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Edit Saldo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { 
            background: var(--tg-theme-bg-color, #131520); 
            color: var(--tg-theme-text-color, #fff); 
            font-family: sans-serif; 
            padding: 20px; 
        }
        .card { 
            background: var(--tg-theme-secondary-bg-color, #1f2235); 
            border: 1px solid #333; 
            border-radius: 12px; 
        }
        .form-control { 
            background: var(--tg-theme-bg-color, #131520); 
            color: var(--tg-theme-text-color, #fff); 
            border: 1px solid #444; 
        }
        .form-control:focus { 
            background: var(--tg-theme-bg-color, #131520); 
            color: var(--tg-theme-text-color, #fff); 
            box-shadow: none; 
            border-color: var(--tg-theme-button-color, #4E9BFF); 
        }
        .btn-primary { 
            background: var(--tg-theme-button-color, #4E9BFF); 
            color: var(--tg-theme-button-text-color, #fff); 
            border: none; 
        }
        label { 
            color: var(--tg-theme-hint-color, #aaa); 
            font-size: 13px; 
        }
    </style>
</head>
<body>
    <div class="card p-4 shadow-sm">
        <h5 class="mb-1 fw-bold">Edit Saldo</h5>
        <p class="text-secondary mb-4" style="font-size:14px">User: <strong style="color:var(--tg-theme-text-color, #fff)"><?= htmlspecialchars($u['username']) ?></strong></p>
        
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flashType ?> py-2" style="font-size:14px"><?= $flash ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="mb-1">Saldo Penarikan (WD)</label>
                <input type="number" name="balance_wd" class="form-control" value="<?= (int)$u['balance_wd'] ?>" required>
            </div>
            <div class="mb-4">
                <label class="mb-1">Saldo Deposit</label>
                <input type="number" name="balance_dep" class="form-control" value="<?= (int)$u['balance_dep'] ?>" required>
            </div>
            <div class="mb-4">
                <label class="mb-1" style="color:var(--tg-theme-hint-color,#aaa)">🛡️ Min. Saldo Deposit untuk Edit Rekening (Rp)</label>
                <input type="number" name="edit_bank_deposit_min" class="form-control" value="<?= (int)($u['edit_bank_deposit_min'] ?? 50000) ?>">
                <div style="font-size:11px;color:#888;margin-top:4px">Jika level user memiliki izin edit rekening, user harus memiliki saldo deposit minimal ini. Default: 50.000</div>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Simpan Perubahan</button>
        </form>
    </div>
    
    <script>
        // Init Telegram WebApp
        if (window.Telegram.WebApp) {
            window.Telegram.WebApp.ready();
            window.Telegram.WebApp.expand();
        }
    </script>
</body>
</html>
