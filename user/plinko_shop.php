<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Guard: Check if Plinko feature is enabled globally
$plinko_enabled = setting($pdo, 'plinko_enabled', '1') === '1';
if (!$plinko_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Mini Game Plinko sedang dinonaktifkan oleh Administrator.';
    redirect('/home');
}

// Rates: Read dynamically from settings
$plinko_buy_rate  = (float)setting($pdo, 'plinko_buy_rate', '100.0');
$plinko_sell_rate = (float)setting($pdo, 'plinko_sell_rate', '100.0');

// ── BACKEND AJAX ENDPOINTS ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. CLAIM DAILY FREE COINS
    if ($action === 'claim_daily') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        
        $today = date('Y-m-d');
        if ($user['last_plinko_claim'] === $today) {
            echo json_encode(['error' => 'Kamu sudah mengklaim koin gratis hari ini. Kembali lagi besok!']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE users 
                SET plinko_coins = plinko_coins + 50, last_plinko_claim = CURDATE() 
                WHERE id = ? AND (last_plinko_claim IS NULL OR last_plinko_claim < CURDATE())
            ");
            $stmt->execute([$user['id']]);
            
            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                $new_coins = (int)$pdo->query("SELECT plinko_coins FROM users WHERE id = {$user['id']}")->fetchColumn();
                echo json_encode([
                    'ok' => true,
                    'new_coins' => $new_coins,
                    'message' => '🎉 Sukses mengklaim 50 Koin Plinko gratis! Selamat bermain!'
                ]);
            } else {
                $pdo->rollBack();
                echo json_encode(['error' => 'Gagal mengklaim. Kamu sudah mengklaim koin gratis hari ini.']);
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. BUY COINS WITH DEPOSIT BALANCE
    if ($action === 'buy_coins') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        
        $qty = (int)($_POST['qty'] ?? 0);
        if ($qty < 10) {
            echo json_encode(['error' => 'Minimal pembelian adalah 10 koin.']);
            exit;
        }
        
        $cost = $qty * $plinko_buy_rate;
        
        try {
            $pdo->beginTransaction();
            
            // Lock user row
            $stmt = $pdo->prepare("SELECT balance_dep FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $current_dep = (float)$stmt->fetchColumn();
            
            if ($current_dep < $cost) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Saldo deposit tidak mencukupi. Butuh ' . format_rp($cost) . ' untuk membeli ' . $qty . ' koin.']);
                exit;
            }
            
            // Deduct deposit balance and add coins
            $pdo->prepare("UPDATE users SET balance_dep = balance_dep - ?, plinko_coins = plinko_coins + ? WHERE id = ?")
                ->execute([$cost, $qty, $user['id']]);
                
            $pdo->commit();
            
            $fresh = $pdo->query("SELECT plinko_coins, balance_dep FROM users WHERE id = {$user['id']}")->fetch();
            echo json_encode([
                'ok' => true,
                'new_coins' => (int)$fresh['plinko_coins'],
                'new_balance_dep' => format_rp((float)$fresh['balance_dep']),
                'message' => '✓ Sukses membeli ' . $qty . ' Koin seharga ' . format_rp($cost) . ' dari Saldo Deposit!'
            ]);
            
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Gagal memproses pembelian: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. SELL COINS FOR WD BALANCE
    if ($action === 'sell_coins') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        
        $qty = (int)($_POST['qty'] ?? 0);
        if ($qty < 1) {
            echo json_encode(['error' => 'Kuantitas koin yang dijual tidak valid.']);
            exit;
        }
        
        $earnings = $qty * $plinko_sell_rate;
        
        try {
            $pdo->beginTransaction();
            
            // Lock user row
            $stmt = $pdo->prepare("SELECT plinko_coins FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $current_coins = (int)$stmt->fetchColumn();
            
            if ($current_coins < $qty) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Koin Plinko tidak mencukupi. Kamu hanya memiliki ' . $current_coins . ' koin.']);
                exit;
            }
            
            // Deduct coins and add to WD balance + total_earned
            $pdo->prepare("UPDATE users SET plinko_coins = plinko_coins - ?, balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")
                ->execute([$qty, $earnings, $earnings, $user['id']]);
                
            $pdo->commit();
            
            $fresh = $pdo->query("SELECT plinko_coins, balance_wd FROM users WHERE id = {$user['id']}")->fetch();
            echo json_encode([
                'ok' => true,
                'new_coins' => (int)$fresh['plinko_coins'],
                'new_balance_wd' => format_rp((float)$fresh['balance_wd']),
                'message' => '✓ Sukses menjual ' . $qty . ' Koin seharga ' . format_rp($earnings) . ' ke Saldo WD!'
            ]);
            
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Gagal memproses penjualan koin: ' . $e->getMessage()]);
        }
        exit;
    }
}

$pageTitle  = 'Lapak Koin Plinko — TontonKuy';
$activePage = 'plinko-shop';
require dirname(__DIR__) . '/partials/header.php';
?>

<!-- Premium Header (Neo-Brutalist Neon Layout) -->
<div class="page-title-bar" style="
  background: var(--brand);
  border: 3px solid var(--ink);
  border-radius: 14px;
  box-shadow: 5px 5px 0 var(--ink);
  padding: 18px 16px;
  margin-bottom: 20px;
  color: #fff;
  position: relative;
  overflow: hidden;
">
  <div style="font-size: 24px; position: absolute; right: 12px; top: 12px; opacity: 0.25;">🛒</div>
  <h1 style="color:#fff; font-weight:900; font-size:22px; text-shadow:2px 2px 0 var(--ink); display:flex; align-items:center; gap:8px;">🛒 Lapak Jual-Beli Koin</h1>
  <p style="color:#FFF0E0; font-weight:700; margin-top:4px; font-size:12px;">Kelola Koin Plinko Anda. Beli koin untuk bermain, atau jual hasil menang Anda langsung ke Saldo WD!</p>
</div>

<!-- Navigation Back to Game (Massive Neo-Brutalist Banner) -->
<a href="/plinko" class="card card--yellow" style="
  display: block; 
  text-decoration: none; 
  color: var(--ink); 
  margin-bottom: 16px; 
  box-shadow: var(--shadow); 
  border: 3px solid var(--ink);
  transition: transform 0.15s, box-shadow 0.15s;
" onmouseover="this.style.transform='translate(-2px, -2px)'; this.style.boxShadow='6px 6px 0 var(--ink)';" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow)';">
  <div class="card__body" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px;">
    <div>
      <div style="font-weight: 900; font-size: 15px; display: flex; align-items: center; gap: 6px;">🎮 Kembali Bermain Plinko Arcade ➔</div>
      <div style="font-size: 10px; font-weight: 700; color: #555; margin-top: 3px;">Koin Plinko siap ditumpahkan di papan neon permainan!</div>
    </div>
    <div style="font-size: 22px; background: #fff; border: 2px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); border-radius: 10px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">🟢</div>
  </div>
</a>

<!-- Real-time Balance Indicators (Neo-Brutalist Compact Rows) -->
<div class="stat-row" style="margin-bottom: 18px; display: flex; gap: 8px;">
  <div class="stat-mini" style="flex: 1; background: var(--yellow); border: 2.5px solid var(--ink); box-shadow: 3.5px 3.5px 0 var(--ink);" title="Total Koin Anda">
    <div class="stat-mini__val" style="font-size: 16px;" id="disp-coins">🪙 <?= number_format((int)$user['plinko_coins']) ?></div>
    <div class="stat-mini__lbl" style="color: var(--ink); font-weight: 800; font-size: 9px;">Koin Anda</div>
  </div>
  <div class="stat-mini" style="flex: 1; background: var(--white); border: 2.5px solid var(--ink); box-shadow: 3.5px 3.5px 0 var(--ink);" title="Saldo Deposit">
    <div class="stat-mini__val" style="font-size: 13px;" id="disp-dep"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div class="stat-mini__lbl" style="font-size: 9px;">Saldo DEP</div>
  </div>
  <div class="stat-mini" style="flex: 1; background: var(--mint); border: 2.5px solid var(--ink); box-shadow: 3.5px 3.5px 0 var(--ink);" title="Saldo WD">
    <div class="stat-mini__val" style="font-size: 13px; color: var(--ink);" id="disp-wd"><?= format_rp((float)$user['balance_wd']) ?></div>
    <div class="stat-mini__lbl" style="color: var(--ink); font-weight: 800; font-size: 9px;">Saldo WD</div>
  </div>
</div>

<!-- Faucet & Shops Panel Grid -->
<div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
  
  <!-- 1. Faucet (Daily Claim) -->
  <div class="card card--lavender" style="box-shadow: 5px 5px 0 var(--ink); border: 3px solid var(--ink);">
    <div class="card__header" style="border-bottom: 3px solid var(--ink); background: var(--lavender); padding: 10px 14px;">
      <div class="card__title" style="color: var(--ink); font-weight: 900; font-size: 14px; display: flex; align-items: center; gap: 6px;">🎁 Koin Gratis Harian</div>
    </div>
    <div class="card__body" style="padding: 14px; background: #fff;">
      <div style="font-size: 12px; color: #444; line-height: 1.5; margin-bottom: 12px;">
        Klaim **50 Koin Plinko secara gratis** setiap hari. Anda dapat menggunakan koin ini untuk memicu taruhan di arena arcade!
      </div>
      
      <?php
      $today = date('Y-m-d');
      $already_claimed = $user['last_plinko_claim'] === $today;
      ?>
      
      <form id="form-claim-daily" onsubmit="claimDaily(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim_daily">
        <button type="submit" id="btn-claim-daily" class="btn btn--primary btn--full" <?= $already_claimed ? 'disabled' : '' ?> style="
          font-weight: 900;
          font-size: 12px;
          border: 2.5px solid var(--ink);
          box-shadow: 3px 3px 0 var(--ink);
          background: <?= $already_claimed ? '#eee' : 'var(--brand)' ?>;
          color: <?= $already_claimed ? '#aaa' : '#fff' ?>;
          cursor: <?= $already_claimed ? 'not-allowed' : 'pointer' ?>;
          height: 42px;
        ">
          <?= $already_claimed ? '✅ Sudah Diklaim Hari Ini' : '🎁 Klaim 50 Koin Gratis Sekarang' ?>
        </button>
      </form>
    </div>
  </div>

  <!-- 2 & 3. Gabungan Lapak Beli & Jual Koin -->
  <div class="card" style="box-shadow: 5px 5px 0 var(--ink); border: 3px solid var(--ink); overflow: hidden;">
    <div class="card__header" style="border-bottom: 3px solid var(--ink); padding: 0;">
      <!-- Tab Toggle Header -->
      <div style="display: flex;">
        <button type="button" id="tab-beli-btn" onclick="switchLapakTab('beli')" style="
          flex: 1;
          padding: 12px 8px;
          font-size: 13px;
          font-weight: 900;
          border: none;
          border-right: 2px solid var(--ink);
          background: var(--sky);
          color: var(--ink);
          cursor: pointer;
          transition: background .15s;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 5px;
        ">🪙 BELI KOIN</button>
        <button type="button" id="tab-jual-btn" onclick="switchLapakTab('jual')" style="
          flex: 1;
          padding: 12px 8px;
          font-size: 13px;
          font-weight: 900;
          border: none;
          background: #eee;
          color: #888;
          cursor: pointer;
          transition: background .15s;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 5px;
        ">💰 JUAL KOIN</button>
      </div>
    </div>

    <!-- PANEL BELI -->
    <div id="panel-beli" class="card__body" style="padding: 14px; background: #fff;">
      <div style="font-size: 12px; color: #444; line-height: 1.5; margin-bottom: 12px;">
        Konversi <strong>Saldo Deposit</strong> menjadi koin permainan Plinko.<br>
        Rate Beli: <strong style="color: var(--brand);">1 Koin = Rp <?= number_format($plinko_buy_rate, 0, ',', '.') ?></strong>
      </div>
      <!-- Preset Tiles -->
      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 14px;">
        <?php foreach ([50, 100, 250, 500] as $coinsQty):
          $priceVal = $coinsQty * $plinko_buy_rate;
        ?>
          <button type="button" onclick="setBuyQty(<?= $coinsQty ?>)" class="btn btn--ghost" style="
            font-size: 11px;
            font-weight: 800;
            padding: 8px 6px;
            border: 2px solid var(--ink);
            border-radius: 8px;
            background: #fafafa;
            box-shadow: 2px 2px 0 var(--ink);
          ">
            🪙 <?= $coinsQty ?> Koin<br>
            <span style="font-size:10px; color:#555;">Rp <?= number_format($priceVal, 0, ',', '.') ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <!-- Buy Form -->
      <form id="form-buy-coins" onsubmit="buyCoins(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="buy_coins">
        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
          <input type="number" name="qty" id="buy-qty" class="form-control" placeholder="Jumlah koin..." min="10" step="10" required style="
            flex: 1;
            padding: 10px 12px;
            border: 2.5px solid var(--ink);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 2px 2px 0 var(--ink);
          ">
        </div>
        <!-- Total Biaya Besar -->
        <div id="buy-summary-box" style="
          display: none;
          background: var(--yellow);
          border: 2.5px solid var(--ink);
          border-radius: 10px;
          box-shadow: 3px 3px 0 var(--ink);
          padding: 12px 16px;
          margin-bottom: 12px;
          text-align: center;
        ">
          <div style="font-size: 10px; font-weight: 800; color: #555; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">💳 Total yang Dibayar</div>
          <div id="buy-summary-val" style="font-size: 24px; font-weight: 900; color: var(--ink); line-height: 1;"></div>
          <div id="buy-summary-sub" style="font-size: 11px; font-weight: 700; color: #555; margin-top: 4px;"></div>
        </div>
        <button type="submit" id="btn-buy" class="btn btn--primary btn--full" style="
          background: var(--brand);
          color: #fff;
          border: 2.5px solid var(--ink);
          box-shadow: 3px 3px 0 var(--ink);
          font-weight: 900;
          font-size: 14px;
          padding: 12px;
          border-radius: 10px;
          text-transform: uppercase;
          letter-spacing: 1px;
        ">💳 Beli Koin Sekarang</button>
      </form>
    </div>

    <!-- PANEL JUAL -->
    <div id="panel-jual" class="card__body" style="display: none; padding: 14px; background: #fff;">
      <div style="font-size: 12px; color: #444; line-height: 1.5; margin-bottom: 12px;">
        Jual kembali koin Plinko menjadi <strong>Saldo WD</strong> yang bisa langsung ditarik.<br>
        Rate Jual: <strong style="color: var(--green);">1 Koin = Rp <?= number_format($plinko_sell_rate, 0, ',', '.') ?></strong>
      </div>
      <!-- Preset Tiles -->
      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 14px;">
        <?php foreach ([50, 100, 250, 500] as $coinsQty):
          $earningsVal = $coinsQty * $plinko_sell_rate;
        ?>
          <button type="button" onclick="setSellQty(<?= $coinsQty ?>)" class="btn btn--ghost" style="
            font-size: 11px;
            font-weight: 800;
            padding: 8px 6px;
            border: 2px solid var(--ink);
            border-radius: 8px;
            background: #fafafa;
            box-shadow: 2px 2px 0 var(--ink);
          ">
            🪙 <?= $coinsQty ?> Koin<br>
            <span style="font-size:10px; color:#555;">→ Rp <?= number_format($earningsVal, 0, ',', '.') ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <!-- Sell Form -->
      <form id="form-sell-coins" onsubmit="sellCoins(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sell_coins">
        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
          <input type="number" name="qty" id="sell-qty" class="form-control" placeholder="Jumlah koin dijual..." min="1" required style="
            flex: 1;
            padding: 10px 12px;
            border: 2.5px solid var(--ink);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 2px 2px 0 var(--ink);
          ">
        </div>
        <!-- Total Hasil Besar -->
        <div id="sell-summary-box" style="
          display: none;
          background: var(--mint);
          border: 2.5px solid var(--ink);
          border-radius: 10px;
          box-shadow: 3px 3px 0 var(--ink);
          padding: 12px 16px;
          margin-bottom: 12px;
          text-align: center;
        ">
          <div style="font-size: 10px; font-weight: 800; color: #3a7a5f; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">💰 Total Hasil Penjualan</div>
          <div id="sell-summary-val" style="font-size: 24px; font-weight: 900; color: var(--ink); line-height: 1;"></div>
          <div id="sell-summary-sub" style="font-size: 11px; font-weight: 700; color: #3a7a5f; margin-top: 4px;"></div>
        </div>
        <button type="submit" id="btn-sell" class="btn btn--primary btn--full" style="
          background: var(--mint);
          color: var(--ink);
          border: 2.5px solid var(--ink);
          box-shadow: 3px 3px 0 var(--ink);
          font-weight: 900;
          font-size: 14px;
          padding: 12px;
          border-radius: 10px;
          text-transform: uppercase;
          letter-spacing: 1px;
        ">💰 Jual Koin Sekarang</button>
      </form>
    </div>
  </div>

</div>

<style>
/* Preset tile card hover transitions */
.btn--ghost {
  transition: transform 0.1s, box-shadow 0.1s, background-color 0.1s;
  cursor: pointer;
}
.btn--ghost:hover {
  transform: translate(-2px, -2px);
  box-shadow: 4px 4px 0 var(--ink) !important;
  background: var(--yellow) !important;
}
.btn--ghost:active {
  transform: translate(1px, 1px);
  box-shadow: 1px 1px 0 var(--ink) !important;
}

/* Form control inputs focus */
.form-control {
  transition: border-color 0.1s, box-shadow 0.1s;
}
.form-control:focus {
  outline: none;
  border-color: var(--brand) !important;
  box-shadow: 4px 4px 0 var(--ink) !important;
}

/* Action button micro-animations */
#btn-claim-daily, #btn-buy, #btn-sell {
  transition: transform 0.1s, box-shadow 0.1s;
}
#btn-claim-daily:hover:not(:disabled), #btn-buy:hover:not(:disabled), #btn-sell:hover:not(:disabled) {
  transform: translate(-2px, -2px);
  box-shadow: 4px 4px 0 var(--ink) !important;
}
#btn-claim-daily:active:not(:disabled), #btn-buy:active:not(:disabled), #btn-sell:active:not(:disabled) {
  transform: translate(1px, 1px);
  box-shadow: 1px 1px 0 var(--ink) !important;
}

/* Lapak Tab active indicator */
.lapak-tab-active {
  position: relative;
}
.lapak-tab-active::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--ink);
}
</style>

  <div class="card" style="box-shadow: 5px 5px 0 var(--ink); border: 3px solid var(--ink);">
    <div class="card__header" style="border-bottom: 3px solid var(--ink); background: var(--sky); padding: 10px 14px;">
      <div class="card__title" style="color: var(--ink); font-weight: 900; font-size: 14px; display: flex; align-items: center; gap: 6px;">🪙 Lapak Beli Koin</div>
    </div>
    <div class="card__body" style="padding: 14px; background: #fff;">
      <div style="font-size: 12px; color: #444; line-height: 1.5; margin-bottom: 12px;">
        Konversi **Saldo Deposit** menjadi koin permainan Plinko.
        <br>Rate Beli: <strong style="color: var(--brand);">1 Koin = Rp <?= number_format($plinko_buy_rate, 0, ',', '.') ?></strong>.
      </div>
      
      <!-- Preset options -->
      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 12px;">
        <?php foreach ([50, 100, 250, 500] as $coinsQty): 
          $priceVal = $coinsQty * $plinko_buy_rate;
        ?>
          <button type="button" onclick="setBuyQty(<?= $coinsQty ?>)" class="btn btn--ghost" style="
            font-size: 11px;
            font-weight: 800;
            padding: 7px;
            border: 2px solid var(--ink);
            border-radius: 8px;
            background: #fafafa;
            box-shadow: 2px 2px 0 var(--ink);
          ">
            🪙 <?= $coinsQty ?> Koin (Rp <?= number_format($priceVal, 0, ',', '.') ?>)
          </button>
        <?php endforeach; ?>
      </div>
      
      <!-- Buy Input Form -->
      <form id="form-buy-coins" onsubmit="buyCoins(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="buy_coins">
        <div style="display: flex; gap: 8px; margin-bottom: 8px;">
          <input type="number" name="qty" id="buy-qty" class="form-control" placeholder="Min. 10 koin" min="10" step="10" required style="
            flex: 1;
            padding: 10px;
            border: 2.5px solid var(--ink);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 2px 2px 0 var(--ink);
          ">
          <button type="submit" id="btn-buy" class="btn btn--primary" style="
            background: var(--yellow);
            color: var(--ink);
            border: 2.5px solid var(--ink);
            box-shadow: 2px 2px 0 var(--ink);
            font-weight: 900;
            font-size: 13px;
            padding: 0 18px;
            border-radius: 8px;
          ">
            💳 Beli Koin
          </button>
        </div>
        <div id="buy-summary" style="font-size: 11px; font-weight: 800; color: var(--brand); text-align: right; min-height: 15px;"></div>
      </form>
    </div>
  </div>

  <!-- 3. Sell Coins Card -->
  <div class="card card--mint" style="box-shadow: 5px 5px 0 var(--ink); border: 3px solid var(--ink);">
    <div class="card__header" style="border-bottom: 3px solid var(--ink); background: var(--mint); padding: 10px 14px;">
      <div class="card__title" style="color: var(--ink); font-weight: 900; font-size: 14px; display: flex; align-items: center; gap: 6px;">💰 Lapak Jual Koin</div>
    </div>
    <div class="card__body" style="padding: 14px; background: #fff;">
      <div style="font-size: 12px; color: #444; line-height: 1.5; margin-bottom: 12px;">
        Jual kembali koin Plinko Anda menjadi **Saldo WD** yang dapat ditarik langsung ke rekening.
        <br>Rate Jual: <strong style="color: var(--green);">1 Koin = Rp <?= number_format($plinko_sell_rate, 0, ',', '.') ?></strong>.
      </div>
      
      <!-- Preset options -->
      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 12px;">
        <?php foreach ([50, 100, 250, 500] as $coinsQty): 
          $earningsVal = $coinsQty * $plinko_sell_rate;
        ?>
          <button type="button" onclick="setSellQty(<?= $coinsQty ?>)" class="btn btn--ghost" style="
            font-size: 11px;
            font-weight: 800;
            padding: 7px;
            border: 2px solid var(--ink);
            border-radius: 8px;
            background: #fafafa;
            box-shadow: 2px 2px 0 var(--ink);
          ">
            🪙 <?= $coinsQty ?> Koin (Rp <?= number_format($earningsVal, 0, ',', '.') ?>)
          </button>
        <?php endforeach; ?>
      </div>
      
      <!-- Sell Input Form -->
      <form id="form-sell-coins" onsubmit="sellCoins(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sell_coins">
        <div style="display: flex; gap: 8px; margin-bottom: 8px;">
          <input type="number" name="qty" id="sell-qty" class="form-control" placeholder="Min. 1 koin" min="1" required style="
            flex: 1;
            padding: 10px;
            border: 2.5px solid var(--ink);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 2px 2px 0 var(--ink);
          ">
          <button type="submit" id="btn-sell" class="btn btn--primary" style="
            background: var(--mint);
            color: var(--ink);
            border: 2.5px solid var(--ink);
            box-shadow: 2px 2px 0 var(--ink);
            font-weight: 900;
            font-size: 13px;
            padding: 0 18px;
            border-radius: 8px;
          ">
            💰 Jual Koin
          </button>
        </div>
        <div id="sell-summary" style="font-size: 11px; font-weight: 800; color: var(--green); text-align: right; min-height: 15px;"></div>
      </form>
    </div>
  </div>

</div>

<style>
/* Preset tile card hover transitions */
.btn--ghost {
  transition: transform 0.1s, box-shadow 0.1s, background-color 0.1s;
  cursor: pointer;
}
.btn--ghost:hover {
  transform: translate(-2px, -2px);
  box-shadow: 4px 4px 0 var(--ink) !important;
  background: var(--yellow) !important;
}
.btn--ghost:active {
  transform: translate(1px, 1px);
  box-shadow: 1px 1px 0 var(--ink) !important;
}

/* Form control inputs focus */
.form-control {
  transition: border-color 0.1s, box-shadow 0.1s;
}
.form-control:focus {
  outline: none;
  border-color: var(--brand) !important;
  box-shadow: 4px 4px 0 var(--ink) !important;
}

/* Action button micro-animations */
#btn-claim-daily, #btn-buy, #btn-sell {
  transition: transform 0.1s, box-shadow 0.1s;
}
#btn-claim-daily:hover:not(:disabled), #btn-buy:hover:not(:disabled), #btn-sell:hover:not(:disabled) {
  transform: translate(-2px, -2px);
  box-shadow: 4px 4px 0 var(--ink) !important;
}
#btn-claim-daily:active:not(:disabled), #btn-buy:active:not(:disabled), #btn-sell:active:not(:disabled) {
  transform: translate(1px, 1px);
  box-shadow: 1px 1px 0 var(--ink) !important;
}
</style>

<script>
const _csrf = "<?= csrf_token() ?>";
const BUY_RATE = <?= (float)$plinko_buy_rate ?>;
const SELL_RATE = <?= (float)$plinko_sell_rate ?>;

// Tab toggle logic
function switchLapakTab(tab) {
  const panelBeli = document.getElementById('panel-beli');
  const panelJual = document.getElementById('panel-jual');
  const btnBeli   = document.getElementById('tab-beli-btn');
  const btnJual   = document.getElementById('tab-jual-btn');

  if (tab === 'beli') {
    panelBeli.style.display = '';
    panelJual.style.display = 'none';
    btnBeli.style.background = 'var(--sky)';
    btnBeli.style.color = 'var(--ink)';
    btnBeli.style.fontWeight = '900';
    btnJual.style.background = '#eee';
    btnJual.style.color = '#888';
  } else {
    panelBeli.style.display = 'none';
    panelJual.style.display = '';
    btnJual.style.background = 'var(--mint)';
    btnJual.style.color = 'var(--ink)';
    btnJual.style.fontWeight = '900';
    btnBeli.style.background = '#eee';
    btnBeli.style.color = '#888';
  }
}

// Web Audio API
let audioCtx = null;
function playWinChime() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const now = audioCtx.currentTime;
    [523.25, 659.25, 783.99, 1046.50].forEach((freq, idx) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.frequency.setValueAtTime(freq, now + idx * 0.07);
      gain.gain.setValueAtTime(0.05, now + idx * 0.07);
      gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.07 + 0.3);
      osc.start(now + idx * 0.07);
      osc.stop(now + idx * 0.07 + 0.3);
    });
  } catch(e) {}
}

// Buy qty preset + live update
function setBuyQty(qty) {
  document.getElementById('buy-qty').value = qty;
  updateBuySummary(qty);
}
document.getElementById('buy-qty').addEventListener('input', function() {
  updateBuySummary(parseInt(this.value) || 0);
});
function updateBuySummary(qty) {
  const box = document.getElementById('buy-summary-box');
  const val = document.getElementById('buy-summary-val');
  const sub = document.getElementById('buy-summary-sub');
  if (qty >= 10) {
    const cost = qty * BUY_RATE;
    box.style.display = '';
    val.innerText = 'Rp ' + cost.toLocaleString('id-ID');
    sub.innerText = qty.toLocaleString('id-ID') + ' Koin → dari Saldo Deposit';
  } else {
    box.style.display = 'none';
  }
}

// Sell qty preset + live update
function setSellQty(qty) {
  document.getElementById('sell-qty').value = qty;
  updateSellSummary(qty);
}
document.getElementById('sell-qty').addEventListener('input', function() {
  updateSellSummary(parseInt(this.value) || 0);
});
function updateSellSummary(qty) {
  const box = document.getElementById('sell-summary-box');
  const val = document.getElementById('sell-summary-val');
  const sub = document.getElementById('sell-summary-sub');
  if (qty >= 1) {
    const earnings = qty * SELL_RATE;
    box.style.display = '';
    val.innerText = 'Rp ' + earnings.toLocaleString('id-ID');
    sub.innerText = qty.toLocaleString('id-ID') + ' Koin → masuk Saldo WD';
  } else {
    box.style.display = 'none';
  }
}

// AJAX: Claim Daily
function claimDaily(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-claim-daily');
  btn.disabled = true;
  btn.innerText = 'Mengklaim...';
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=claim_daily&_csrf=' + encodeURIComponent(_csrf)
  })
  .then(r => r.json())
  .then(res => {
    if (res.error) {
      btn.disabled = false;
      btn.innerText = '🎁 Klaim 50 Koin Gratis Sekarang';
      nToast(res.error, 'error');
    } else {
      btn.innerText = '✅ Sudah Diklaim Hari Ini';
      btn.style.background = '#eee';
      btn.style.color = '#aaa';
      btn.style.cursor = 'not-allowed';
      document.getElementById('disp-coins').innerText = '🪙 ' + res.new_coins.toLocaleString('id-ID');
      const topCoins = document.getElementById('user-coins');
      if (topCoins) topCoins.innerText = res.new_coins;
      playWinChime();
      nToast(res.message, 'success');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerText = '🎁 Klaim 50 Koin Gratis Sekarang';
    nToast('Koneksi terputus.', 'error');
  });
}

// AJAX: Buy Coins
function buyCoins(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-buy');
  const qty = document.getElementById('buy-qty').value;
  btn.disabled = true;
  btn.innerText = 'Memproses...';
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=buy_coins&qty=' + qty + '&_csrf=' + encodeURIComponent(_csrf)
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.innerText = '💳 Beli Koin Sekarang';
    if (res.error) {
      nToast(res.error, 'error');
    } else {
      document.getElementById('buy-qty').value = '';
      document.getElementById('buy-summary-box').style.display = 'none';
      document.getElementById('disp-coins').innerText = '🪙 ' + res.new_coins.toLocaleString('id-ID');
      document.getElementById('disp-dep').innerText = res.new_balance_dep;
      const topCoins = document.getElementById('user-coins');
      if (topCoins) topCoins.innerText = res.new_coins;
      playWinChime();
      nToast(res.message, 'success');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerText = '💳 Beli Koin Sekarang';
    nToast('Koneksi terputus.', 'error');
  });
}

// AJAX: Sell Coins
function sellCoins(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-sell');
  const qty = document.getElementById('sell-qty').value;
  btn.disabled = true;
  btn.innerText = 'Memproses...';
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=sell_coins&qty=' + qty + '&_csrf=' + encodeURIComponent(_csrf)
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.innerText = '💰 Jual Koin Sekarang';
    if (res.error) {
      nToast(res.error, 'error');
    } else {
      document.getElementById('sell-qty').value = '';
      document.getElementById('sell-summary-box').style.display = 'none';
      document.getElementById('disp-coins').innerText = '🪙 ' + res.new_coins.toLocaleString('id-ID');
      document.getElementById('disp-wd').innerText = res.new_balance_wd;
      const topCoins = document.getElementById('user-coins');
      if (topCoins) topCoins.innerText = res.new_coins;
      playWinChime();
      nToast(res.message, 'success');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerText = '💰 Jual Koin Sekarang';
    nToast('Koneksi terputus.', 'error');
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
