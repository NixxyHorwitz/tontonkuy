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

$multipliers = [10.0, 3.0, 1.5, 0.8, 0.2, 0.8, 1.5, 3.0, 10.0]; // 8 rows -> 9 buckets

$flash = $flashType = '';

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
                // Get updated coin count
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
            $pdo->rollBack();
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
            
            // Fetch fresh balances
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

    // 3. SELL COINS FOR WD BALANCE [NEW!]
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
            
            // Fetch fresh balances
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

    // 4. PLAY PLINKO (DROP BALL)
    if ($action === 'play') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        
        $bet = (int)($_POST['bet'] ?? 10);
        if (!in_array($bet, [10, 25, 50, 100], true)) {
            echo json_encode(['error' => 'Jumlah taruhan koin tidak valid.']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Lock user row
            $stmt = $pdo->prepare("SELECT plinko_coins, balance_wd FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $usrData = $stmt->fetch();
            $current_coins = (int)$usrData['plinko_coins'];
            
            if ($current_coins < $bet) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Koin kamu tidak cukup. Silakan klaim koin harian gratis atau beli koin terlebih dahulu!']);
                exit;
            }
            
            // Generate Binomial Path (8 rows)
            $path = [];
            $bucket = 0; // Starts at 0, goes to 8
            for ($r = 0; $r < 8; $r++) {
                $step = random_int(0, 1); // 0 = Left, 1 = Right
                $path[] = $step;
                $bucket += $step;
            }
            
            $mult = $multipliers[$bucket];
            $reward_coins = (int)round($bet * $mult);
            
            // Update user row (deduct bet coins, add reward coins)
            $pdo->prepare("
                UPDATE users 
                SET plinko_coins = plinko_coins - ? + ?
                WHERE id = ?
            ")->execute([$bet, $reward_coins, $user['id']]);
            
            // Write to Plinko History Log (reward_wd is set to 0.00, reward_coins tracks actual coins gained)
            $pdo->prepare("
                INSERT INTO plinko_history (user_id, coins_bet, multiplier, reward_wd, reward_coins) 
                VALUES (?, ?, ?, 0.00, ?)
            ")->execute([$user['id'], $bet, $mult, $reward_coins]);
            
            $pdo->commit();
            
            // Fetch updated user balances
            $fresh = $pdo->query("SELECT plinko_coins, balance_wd FROM users WHERE id = {$user['id']}")->fetch();
            
            echo json_encode([
                'ok' => true,
                'path' => $path,
                'bucket' => $bucket,
                'multiplier' => $mult,
                'reward_coins' => $reward_coins,
                'new_coins' => (int)$fresh['plinko_coins'],
                'new_balance_wd' => format_rp((float)$fresh['balance_wd'])
            ]);
            
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Gagal memproses taruhan: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ── GET PLINKO LOGS HISTORY ──────────────────────────────────
$history = [];
try {
    $h_stmt = $pdo->prepare("
        SELECT * FROM plinko_history 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 5
    ");
    $h_stmt->execute([$user['id']]);
    $history = $h_stmt->fetchAll();
} catch (\Throwable $e) {}

$pageTitle  = 'Mini Game Plinko — TontonKuy';
$activePage = 'plinko';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>🎮 Plinko Arcade</h1>
  <p>Jatuhkan bola neon, kumpulkan multiplier dan raih ekstra saldo WD instan!</p>
</div>

<!-- Balance Indicator (Neo-Brutalist Compact Rows) -->
<div class="stat-row" style="margin-bottom:16px; display:flex; gap:8px;">
  <div class="stat-mini" style="flex:1; background:var(--yellow); border:2.5px solid var(--ink); box-shadow:3px 3px 0 var(--ink);">
    <div class="stat-mini__val" style="font-size:18px;" id="disp-coins">🪙 <?= number_format((int)$user['plinko_coins']) ?></div>
    <div class="stat-mini__lbl" style="color:var(--ink); font-weight:800;">Koin Plinko</div>
  </div>
  <div class="stat-mini" style="flex:1; background:var(--white); border:2.5px solid var(--ink); box-shadow:3px 3px 0 var(--ink);">
    <div class="stat-mini__val" style="font-size:14px;" id="disp-dep"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div class="stat-mini__lbl">Saldo Deposit</div>
  </div>
  <div class="stat-mini" style="flex:1; background:var(--mint); border:2.5px solid var(--ink); box-shadow:3px 3px 0 var(--ink);">
    <div class="stat-mini__val" style="font-size:14px; color:var(--ink);" id="disp-wd"><?= format_rp((float)$user['balance_wd']) ?></div>
    <div class="stat-mini__lbl" style="color:var(--ink); font-weight:800;">Saldo WD</div>
  </div>
</div>

<div class="card" style="margin-bottom:16px; overflow:hidden;">
  <div class="card__header" style="background:var(--brand); display:flex; justify-content:space-between; align-items:center;">
    <div class="card__title" style="color:#fff; font-weight:900;">🎯 Papan Permainan Plinko</div>
    <button onclick="toggleSound(this)" class="btn btn--secondary btn--sm" style="font-size:11px; padding:3px 8px; background:var(--yellow); color:var(--ink); border:1.5px solid var(--ink); box-shadow:1.5px 1.5px 0 var(--ink);">🔊 Suara: ON</button>
  </div>
  <div class="card__body" style="padding:12px; display:flex; flex-direction:column; align-items:center; background:#1A1A1A;">
    
    <!-- Canvas container with absolute responsive sizing -->
    <div style="width:100%; max-width:400px; background:#111; border:3px solid var(--ink); border-radius:12px; box-shadow:4px 4px 0 var(--ink); overflow:hidden; position:relative;">
      <canvas id="plinkoCanvas" width="400" height="380" style="display:block; width:100%; height:auto;"></canvas>
    </div>
    
    <!-- Bet sizing selector -->
    <div style="width:100%; max-width:400px; margin-top:14px; background:#fff; border:3px solid var(--ink); border-radius:12px; box-shadow:3px 3px 0 var(--ink); padding:10px 12px;">
      <div style="font-size:11px; font-weight:900; color:#555; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; text-align:center;">💵 Pilih Taruhan (Koin Plinko)</div>
      <div style="display:flex; gap:6px; justify-content:center;">
        <?php foreach ([10, 25, 50, 100] as $bSize): ?>
          <button type="button" class="btn btn-bet-selector <?= $bSize===10?'active':'' ?>" data-bet="<?= $bSize ?>" onclick="selectBet(this)" style="
            flex:1;
            padding:8px 4px;
            font-size:13px;
            font-weight:900;
            border:2px solid var(--ink);
            border-radius:8px;
            cursor:pointer;
            background:#f0f0f0;
            box-shadow:1.5px 1.5px 0 var(--ink);
            transition:transform .1s, background .1s;
          ">
            <?= $bSize ?>
          </button>
        <?php endforeach; ?>
      </div>
      
      <!-- CTA Play Button -->
      <button type="button" id="btn-drop" onclick="playPlinko()" class="btn btn--primary btn--full" style="
        margin-top:12px;
        font-size:16px;
        font-weight:900;
        background:var(--mint);
        color:var(--ink);
        border:3px solid var(--ink);
        box-shadow:3px 3px 0 var(--ink);
        padding:12px;
        border-radius:10px;
        text-transform:uppercase;
        letter-spacing:1px;
      ">
        🟢 JATUHKAN BOLA 🟢
      </button>
    </div>
    
  </div>
</div>

<!-- Daily free claim & coin store grid -->
<div style="display:grid; grid-template-columns:1fr; gap:16px; margin-bottom:16px;">
  
  <!-- Daily Claim Card -->
  <div class="card card--mint" style="box-shadow:4px 4px 0 var(--ink); border:2.5px solid var(--ink);">
    <div class="card__header" style="border-bottom:2.5px solid var(--ink); background:var(--yellow); padding:10px 14px;"><div class="card__title" style="color:var(--ink); font-weight:900; font-size:13px;">🎁 Koin Gratis Harian</div></div>
    <div class="card__body" style="padding:14px; background:#fff;">
      <div style="font-size:12px; color:#555; line-height:1.5; margin-bottom:12px;">
        Klaim <strong>50 Koin Plinko gratis</strong> setiap hari untuk bermain. Taruhan koin dapat menghasilkan multiplier saldo WD riil!
      </div>
      
      <?php
      $today = date('Y-m-d');
      $already_claimed = $user['last_plinko_claim'] === $today;
      ?>
      
      <form id="form-claim-daily" onsubmit="claimDaily(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim_daily">
        <button type="submit" id="btn-claim-daily" class="btn btn--primary btn--full" <?= $already_claimed ? 'disabled' : '' ?> style="
          font-weight:900;
          font-size:12px;
          border:2px solid var(--ink);
          box-shadow:2px 2px 0 var(--ink);
          background:<?= $already_claimed ? '#ddd' : 'var(--brand)' ?>;
          color:<?= $already_claimed ? '#888' : '#fff' ?>;
          cursor:<?= $already_claimed ? 'not-allowed' : 'pointer' ?>;
        ">
          <?= $already_claimed ? '✅ Sudah Diklaim Hari Ini' : '🎁 Klaim 50 Koin Gratis Sekarang' ?>
        </button>
      </form>
    </div>
  </div>

  <!-- Buy Coins Card -->
  <div class="card" style="box-shadow:4px 4px 0 var(--ink); border:2.5px solid var(--ink);">
    <div class="card__header" style="border-bottom:2.5px solid var(--ink); background:var(--brand); padding:10px 14px;"><div class="card__title" style="color:#fff; font-weight:900; font-size:13px;">🪙 Lapak Beli Koin (Beli Koin Plinko)</div></div>
    <div class="card__body" style="padding:14px; background:#fff;">
      <div style="font-size:12px; color:#555; line-height:1.5; margin-bottom:12px;">
        Konversi <strong>Saldo Deposit</strong> menjadi koin Plinko untuk bermain. Rate konversi: <strong>1 Koin = Rp <?= number_format($plinko_buy_rate, 0, ',', '.') ?></strong>.
      </div>
      
      <!-- Preset packages -->
      <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:8px; margin-bottom:12px;">
        <?php foreach ([50, 100, 250, 500] as $coinsQty): 
          $priceVal = $coinsQty * $plinko_buy_rate;
        ?>
          <button type="button" onclick="setBuyQty(<?= $coinsQty ?>)" class="btn btn--ghost" style="
            font-size:11px;
            font-weight:800;
            padding:6px;
            border:1.5px solid var(--ink);
            border-radius:6px;
            background:#fcfcfc;
            box-shadow:1.5px 1.5px 0 var(--ink);
          ">
            🪙 <?= $coinsQty ?> Koin (Rp <?= number_format($priceVal, 0, ',', '.') ?>)
          </button>
        <?php endforeach; ?>
      </div>
      
      <!-- Buy Input Form -->
      <form id="form-buy-coins" onsubmit="buyCoins(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="buy_coins">
        <div style="display:flex; gap:8px; margin-bottom:10px;">
          <input type="number" name="qty" id="buy-qty" class="form-control" placeholder="Min. 10 koin" min="10" step="10" required style="
            flex:1;
            padding:8px 10px;
            border:2px solid var(--ink);
            border-radius:8px;
            font-size:12px;
            font-weight:700;
          ">
          <button type="submit" id="btn-buy" class="btn btn--primary" style="
            background:var(--yellow);
            color:var(--ink);
            border:2px solid var(--ink);
            box-shadow:2px 2px 0 var(--ink);
            font-weight:900;
            font-size:12px;
            padding:0 16px;
            border-radius:8px;
          ">
            💳 Beli
          </button>
        </div>
        <div id="buy-summary" style="font-size:11px; font-weight:800; color:var(--brand); text-align:right;"></div>
      </form>
    </div>
  </div>

  <!-- Sell Coins Card [NEW!] -->
  <div class="card card--mint" style="box-shadow:4px 4px 0 var(--ink); border:2.5px solid var(--ink);">
    <div class="card__header" style="border-bottom:2.5px solid var(--ink); background:var(--mint); padding:10px 14px;"><div class="card__title" style="color:var(--ink); font-weight:900; font-size:13px;">💰 Lapak Jual Koin (Jual Koin Plinko)</div></div>
    <div class="card__body" style="padding:14px; background:#fff;">
      <div style="font-size:12px; color:#555; line-height:1.5; margin-bottom:12px;">
        Tukarkan kembali koin Plinko milikmu menjadi <strong>Saldo WD</strong> siap tarik. Rate konversi: <strong>1 Koin = Rp <?= number_format($plinko_sell_rate, 0, ',', '.') ?></strong>.
      </div>
      
      <!-- Preset packages -->
      <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:8px; margin-bottom:12px;">
        <?php foreach ([50, 100, 250, 500] as $coinsQty): 
          $earningsVal = $coinsQty * $plinko_sell_rate;
        ?>
          <button type="button" onclick="setSellQty(<?= $coinsQty ?>)" class="btn btn--ghost" style="
            font-size:11px;
            font-weight:800;
            padding:6px;
            border:1.5px solid var(--ink);
            border-radius:6px;
            background:#fcfcfc;
            box-shadow:1.5px 1.5px 0 var(--ink);
          ">
            🪙 <?= $coinsQty ?> Koin (Rp <?= number_format($earningsVal, 0, ',', '.') ?>)
          </button>
        <?php endforeach; ?>
      </div>
      
      <!-- Sell Input Form -->
      <form id="form-sell-coins" onsubmit="sellCoins(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sell_coins">
        <div style="display:flex; gap:8px; margin-bottom:10px;">
          <input type="number" name="qty" id="sell-qty" class="form-control" placeholder="Min. 1 koin" min="1" required style="
            flex:1;
            padding:8px 10px;
            border:2px solid var(--ink);
            border-radius:8px;
            font-size:12px;
            font-weight:700;
          ">
          <button type="submit" id="btn-sell" class="btn btn--primary" style="
            background:var(--mint);
            color:var(--ink);
            border:2px solid var(--ink);
            box-shadow:2px 2px 0 var(--ink);
            font-weight:900;
            font-size:12px;
            padding:0 16px;
            border-radius:8px;
          ">
            💰 Jual
          </button>
        </div>
        <div id="sell-summary" style="font-size:11px; font-weight:800; color:var(--green); text-align:right;"></div>
      </form>
    </div>
  </div>

<!-- History Log Card -->
<div class="card" style="margin-bottom:16px; box-shadow:4px 4px 0 var(--ink); border:2.5px solid var(--ink);">
  <div class="card__header" style="border-bottom:2.5px solid var(--ink); background:#eee; padding:10px 14px;"><div class="card__title" style="color:var(--ink); font-weight:900; font-size:13px;">📋 Riwayat Bermain Terakhir</div></div>
  <div class="card__body" style="padding:0; background:#fff;">
    <div id="history-container">
      <?php if (empty($history)): ?>
        <div style="padding:16px; text-align:center; font-size:12px; color:#aaa;" id="history-empty">Belum ada riwayat bermain. Mulai jatuhkan bola pertama kamu! 🟢</div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column;">
          <?php foreach ($history as $h): ?>
            <div class="list-item" style="padding:10px 14px; border-bottom:1px dashed #ddd; display:flex; justify-content:space-between; align-items:center; font-size:12px;">
              <div style="display:flex; align-items:center; gap:8px;">
                <div style="width:28px; height:28px; border-radius:6px; border:1.5px solid var(--ink); background:var(--yellow); display:flex; align-items:center; justify-content:center; font-size:12px;">🎮</div>
                <div>
                  <div style="font-weight:900;">Taruhan <?= (int)$h['coins_bet'] ?> Koin (<?= (float)$h['multiplier'] ?>x)</div>
                  <div style="font-size:10px; color:#888; margin-top:2px;"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></div>
                </div>
              </div>
              <div style="font-weight:900; color:var(--green); font-size:13px;">
                <?php if (isset($h['reward_coins']) && $h['reward_coins'] > 0): ?>
                  +<?= number_format((int)$h['reward_coins']) ?> Koin
                <?php else: ?>
                  +<?= format_rp((float)$h['reward_wd']) ?> WD
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* Bet button active states */
.btn-bet-selector.active {
  background: var(--yellow) !important;
  color: var(--ink) !important;
  transform: translate(1px, 1px);
  box-shadow: 0px 0px 0 var(--ink) !important;
}
.btn-bet-selector:active {
  transform: translate(1.5px, 1.5px);
  box-shadow: 0px 0px 0 var(--ink) !important;
}
</style>

<script>
// CSRF Helper
const _csrf = "<?= csrf_token() ?>";
const BUY_RATE = <?= (float)$plinko_buy_rate ?>;
const SELL_RATE = <?= (float)$plinko_sell_rate ?>;

// Web Audio API Synthesizer Context
let audioCtx = null;
let soundEnabled = true;

function initAudio() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
}

function playBip() {
  if (!soundEnabled) return;
  try {
    initAudio();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    
    // Retro arcade bip frequency
    const f = 600 + Math.random() * 300;
    osc.frequency.setValueAtTime(f, audioCtx.currentTime);
    gain.gain.setValueAtTime(0.04, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.08);
    
    osc.start();
    osc.stop(audioCtx.currentTime + 0.08);
  } catch(e) {}
}

function playWinChime(isHigh = false) {
  if (!soundEnabled) return;
  try {
    initAudio();
    const now = audioCtx.currentTime;
    const notes = isHigh ? [523.25, 659.25, 783.99, 1046.50] : [261.63, 329.63, 392.00, 523.25];
    
    notes.forEach((freq, idx) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      
      osc.frequency.setValueAtTime(freq, now + idx * 0.08);
      gain.gain.setValueAtTime(0.06, now + idx * 0.08);
      gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.08 + 0.35);
      
      osc.start(now + idx * 0.08);
      osc.stop(now + idx * 0.08 + 0.35);
    });
  } catch(e) {}
}

function toggleSound(btn) {
  soundEnabled = !soundEnabled;
  btn.innerText = soundEnabled ? '🔊 Suara: ON' : '🔇 Suara: OFF';
  btn.style.background = soundEnabled ? 'var(--yellow)' : '#ddd';
}

// Preset values updates (Buy)
function setBuyQty(qty) {
  const inp = document.getElementById('buy-qty');
  inp.value = qty;
  updateBuySummary(qty);
}

document.getElementById('buy-qty').addEventListener('input', function() {
  updateBuySummary(parseInt(this.value) || 0);
});

function updateBuySummary(qty) {
  const sum = document.getElementById('buy-summary');
  if (qty >= 10) {
    const cost = qty * BUY_RATE;
    sum.innerText = 'Total Biaya: Rp ' + cost.toLocaleString('id-ID');
  } else {
    sum.innerText = '';
  }
}

// Preset values updates (Sell) [NEW!]
function setSellQty(qty) {
  const inp = document.getElementById('sell-qty');
  inp.value = qty;
  updateSellSummary(qty);
}

document.addEventListener('DOMContentLoaded', () => {
  const sellQtyInp = document.getElementById('sell-qty');
  if (sellQtyInp) {
    sellQtyInp.addEventListener('input', function() {
      updateSellSummary(parseInt(this.value) || 0);
    });
  }
});

function updateSellSummary(qty) {
  const sum = document.getElementById('sell-summary');
  if (qty >= 1) {
    const earnings = qty * SELL_RATE;
    sum.innerText = 'Perkiraan Hasil: Rp ' + earnings.toLocaleString('id-ID');
  } else {
    sum.innerText = '';
  }
}

// Preset active bet choice
let activeBet = 10;
function selectBet(btn) {
  if (isPlaying) return;
  document.querySelectorAll('.btn-bet-selector').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  activeBet = parseInt(btn.dataset.bet) || 10;
  
  // Synthesize soft selector click
  playBip();
}

// ── CANVAS GAME RENDERING ENGINE ─────────────────────────────
const canvas = document.getElementById('plinkoCanvas');
const ctx = canvas.getContext('2d');

const BOARD_WIDTH = 400;
const BOARD_HEIGHT = 380;
const START_Y = 55;
const ROW_SPACING = 30;
const PEG_SPACING = 30;

// Draw board configurations
const bucketsMultipliers = <?= json_encode($multipliers) ?>;
const totalRows = 8;

// Keep track of active glowing pins
let glowingPins = [];

function addGlow(r, i) {
  glowingPins.push({
    row: r,
    idx: i,
    intensity: 1.0
  });
}

function updateGlows() {
  for (let i = glowingPins.length - 1; i >= 0; i--) {
    glowingPins[i].intensity -= 0.08;
    if (glowingPins[i].intensity <= 0) {
      glowingPins.splice(i, 1);
    }
  }
}

// Bucket positions & multipliers coloring
const bucketColors = [
  '#FF3366', // 10x
  '#FF9900', // 3x
  '#FFFF00', // 1.5x
  '#00FF66', // 0.8x
  '#555555', // 0.2x
  '#00FF66', // 0.8x
  '#FFFF00', // 1.5x
  '#FF9900', // 3x
  '#FF3366'  // 10x
];

function drawBoard() {
  // Clear canvas
  ctx.fillStyle = '#111';
  ctx.fillRect(0, 0, BOARD_WIDTH, BOARD_HEIGHT);
  
  // 1. Draw connecting grid lines for Neo-Brutalist design
  ctx.lineWidth = 1;
  ctx.strokeStyle = '#222';
  for (let r = 0; r < totalRows; r++) {
    const y = START_Y + r * ROW_SPACING;
    const pinsInRow = r + 3;
    const startX = BOARD_WIDTH / 2 - ((pinsInRow - 1) / 2) * PEG_SPACING;
    
    ctx.beginPath();
    ctx.moveTo(startX, y);
    ctx.lineTo(startX + (pinsInRow - 1) * PEG_SPACING, y);
    ctx.stroke();
  }
  
  // 2. Draw Buckets (bins) at the bottom
  const bucketY = 325;
  const bucketWidth = 32;
  const bucketHeight = 35;
  const startBucketX = BOARD_WIDTH / 2 - (9 / 2) * bucketWidth;
  
  for (let b = 0; b < 9; b++) {
    const bx = startBucketX + b * bucketWidth;
    
    // Draw Box
    ctx.fillStyle = '#000';
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 2.5;
    
    // Neo brutalist thick box
    ctx.beginPath();
    ctx.rect(bx + 2, bucketY, bucketWidth - 4, bucketHeight);
    ctx.fill();
    ctx.stroke();
    
    // Small color tab inside
    ctx.fillStyle = bucketColors[b];
    ctx.fillRect(bx + 4, bucketY + 2, bucketWidth - 8, 4);
    
    // Draw Text Multiplier
    ctx.fillStyle = bucketColors[b];
    ctx.font = '900 10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText(bucketsMultipliers[b] + 'x', bx + bucketWidth/2, bucketY + 22);
  }
  
  // 3. Draw Peg pins
  for (let r = 0; r < totalRows; r++) {
    const y = START_Y + r * ROW_SPACING;
    const pinsInRow = r + 3;
    const startX = BOARD_WIDTH / 2 - ((pinsInRow - 1) / 2) * PEG_SPACING;
    
    for (let i = 0; i < pinsInRow; i++) {
      const px = startX + i * PEG_SPACING;
      
      // Check if this peg is currently glowing
      const glow = glowingPins.find(g => g.row === r && g.idx === i);
      
      ctx.beginPath();
      ctx.arc(px, y, 4.5, 0, Math.PI * 2);
      
      if (glow) {
        // Neon color highlight
        ctx.fillStyle = 'rgba(0, 240, 255, ' + glow.intensity + ')';
        ctx.strokeStyle = '#00f0ff';
        ctx.lineWidth = 2;
        ctx.shadowBlur = 10 * glow.intensity;
        ctx.shadowColor = '#00f0ff';
      } else {
        ctx.fillStyle = '#fff';
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.shadowBlur = 0;
      }
      
      ctx.fill();
      ctx.stroke();
      ctx.shadowBlur = 0; // Reset shadow
    }
  }
}

// ── PLAY ANIMATION ENGINE ────────────────────────────────────
let isPlaying = false;
let ball = {
  x: 0,
  y: 0,
  targetFrames: [],
  currentFrameIdx: 0,
  color: 'var(--yellow)'
};

function generateFramesForPath(path, targetBucket) {
  let list = [];
  let currentX = BOARD_WIDTH / 2;
  let currentY = 15;
  
  // Initial drop down to top pin of row 0
  const steps = 14;
  const r0_pins = 3;
  const r0_startX = BOARD_WIDTH / 2 - ((r0_pins - 1) / 2) * PEG_SPACING;
  const pin0X = r0_startX + 1 * PEG_SPACING; // middle pin is index 1
  const pin0Y = START_Y;
  
  for (let f = 0; f <= steps; f++) {
    const t = f / steps;
    // quadratic fall
    list.push({
      x: currentX + (pin0X - currentX) * t,
      y: currentY + (pin0Y - currentY) * t * t,
      hit: (f === steps) ? { row: 0, idx: 1 } : null
    });
  }
  
  currentX = pin0X;
  currentY = pin0Y;
  
  // Start from peg index 1
  let index = 1;
  
  for (let r = 0; r < 8; r++) {
    const d = path[r]; // 0 = left, 1 = right
    const nextIndex = index + d;
    
    // Target peg on row r+1 (if r < 7)
    // If r === 7, we land in bucket
    let targetX, targetY;
    
    if (r < 7) {
      const nextPinsCount = r + 1 + 3;
      const nextStartX = BOARD_WIDTH / 2 - ((nextPinsCount - 1) / 2) * PEG_SPACING;
      targetX = nextStartX + nextIndex * PEG_SPACING;
      targetY = START_Y + (r + 1) * ROW_SPACING;
    } else {
      // Land in bucket Y = 325
      const bucketWidth = 32;
      const startBucketX = BOARD_WIDTH / 2 - (9 / 2) * bucketWidth;
      targetX = startBucketX + targetBucket * bucketWidth + bucketWidth / 2;
      targetY = 325;
    }
    
    // Generate bounce animation frames using curves
    // Bounces slightly out to left/right of current peg, then falls down
    const bounceX = currentX + (d === 1 ? 11 : -11);
    const bounceY = currentY + 5;
    
    const midX = (bounceX + targetX) / 2;
    const midY = (bounceY + targetY) / 2 - 8; // gravity high arc
    
    const animSteps = 16;
    for (let f = 1; f <= animSteps; f++) {
      const t = f / animSteps;
      // Bezier curve quadratic interpolation
      const x = (1 - t) * (1 - t) * bounceX + 2 * (1 - t) * t * midX + t * t * targetX;
      const y = (1 - t) * (1 - t) * bounceY + 2 * (1 - t) * t * midY + t * t * targetY;
      
      let hit = null;
      if (f === animSteps && r < 7) {
        hit = { row: r + 1, idx: nextIndex };
      }
      
      list.push({ x, y, hit });
    }
    
    currentX = targetX;
    currentY = targetY;
    index = nextIndex;
  }
  
  return list;
}

function animateGameLoop() {
  if (!isPlaying) return;
  
  // Draw basic board pins and buckets
  drawBoard();
  updateGlows();
  
  // Render Ball
  const f = ball.targetFrames[ball.currentFrameIdx];
  if (f) {
    ball.x = f.x;
    ball.y = f.y;
    
    // Draw ball shadows for neo brutalist glowing effect
    ctx.beginPath();
    ctx.arc(ball.x, ball.y, 8, 0, Math.PI * 2);
    ctx.fillStyle = '#00FF66';
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2.5;
    ctx.shadowBlur = 12;
    ctx.shadowColor = '#00FF66';
    ctx.fill();
    ctx.stroke();
    ctx.shadowBlur = 0; // Reset
    
    // Handle collision trigger (sound & lights glow)
    if (f.hit) {
      addGlow(f.hit.row, f.hit.idx);
      playBip();
    }
    
    ball.currentFrameIdx++;
    requestAnimationFrame(animateGameLoop);
  } else {
    // Ball mended / landed!
    isPlaying = false;
    document.getElementById('btn-drop').disabled = false;
    document.getElementById('btn-drop').innerText = '🟢 JATUHKAN BOLA 🟢';
    
    // Play sound chimes based on multiplier intensity
    if (finalWinData) {
      const mult = finalWinData.multiplier;
      playWinChime(mult >= 1.5);
      
      // Fire visual success toast
      if (typeof nToast !== 'undefined') {
        nToast('🎯 Bola Mendarat! Multiplier ' + mult + 'x · Menang ' + finalWinData.reward_coins + ' Koin Plinko!', 'success', 5000);
      }
      
      // Update stats text balances immediately
      updateBalances(finalWinData);
      
      // Prepend to list view
      prependHistoryRow(finalWinData);
    }
  }
}

function updateBalances(data) {
  document.getElementById('disp-coins').innerText = '🪙 ' + data.new_coins.toLocaleString('id-ID');
  document.getElementById('disp-wd').innerText = data.new_balance_wd;
  
  // Keep headers count synced
  const topCoins = document.getElementById('user-coins');
  if (topCoins) topCoins.innerText = data.new_coins;
}

function prependHistoryRow(data) {
  const container = document.getElementById('history-container');
  const empty = document.getElementById('history-empty');
  if (empty) empty.style.display = 'none';
  
  const now = new Date();
  const timeStr = now.toLocaleDateString('id-ID', {day: 'numeric', month: 'short', year: 'numeric'}) + ' ' + 
                  String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
  
  const row = document.createElement('div');
  row.className = 'list-item';
  row.style.cssText = 'padding:10px 14px; border-bottom:1px dashed #ddd; display:flex; justify-content:space-between; align-items:center; font-size:12px; animation: popIn 0.3s ease-out;';
  row.innerHTML = `
    <div style="display:flex; align-items:center; gap:8px;">
      <div style="width:28px; height:28px; border-radius:6px; border:1.5px solid var(--ink); background:var(--yellow); display:flex; align-items:center; justify-content:center; font-size:12px;">🎮</div>
      <div>
        <div style="font-weight:900;">Taruhan ${activeBet} Koin (${data.multiplier}x)</div>
        <div style="font-size:10px; color:#888; margin-top:2px;">${timeStr}</div>
      </div>
    </div>
    <div style="font-weight:900; color:var(--green); font-size:13px;">+${data.reward_coins.toLocaleString('id-ID')} Koin</div>
  `;
  
  if (container.firstChild && container.firstChild.className === 'list-item') {
    container.insertBefore(row, container.firstChild);
  } else {
    // If container holds the flex wrapper
    const wrapper = container.querySelector('div[style*="flex-direction:column"]');
    if (wrapper) {
      wrapper.insertBefore(row, wrapper.firstChild);
    } else {
      const wrap = document.createElement('div');
      wrap.style.cssText = 'display:flex; flex-direction:column;';
      wrap.appendChild(row);
      container.innerHTML = '';
      container.appendChild(wrap);
    }
  }
}

// ── PLAY PLINKO AJAX ACTION TRIGGER ──────────────────────────
let finalWinData = null;

function playPlinko() {
  if (isPlaying) return;
  
  const btn = document.getElementById('btn-drop');
  btn.disabled = true;
  btn.innerText = 'MEMANCAR BOLA...';
  
  // Initialize synthesizer
  initAudio();
  
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=play&bet=' + activeBet + '&_csrf=' + encodeURIComponent(_csrf)
  })
  .then(r => r.json())
  .then(res => {
    if (res.error) {
      btn.disabled = false;
      btn.innerText = '🟢 JATUHKAN BOLA 🟢';
      if (typeof nToast !== 'undefined') {
        nToast(res.error, 'error');
      } else {
        alert(res.error);
      }
    } else {
      // Trigger canvas loop
      finalWinData = res;
      ball.targetFrames = generateFramesForPath(res.path, res.bucket);
      ball.currentFrameIdx = 0;
      isPlaying = true;
      animateGameLoop();
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerText = '🟢 JATUHKAN BOLA 🟢';
    if (typeof nToast !== 'undefined') {
      nToast('Koneksi terputus. Coba lagi.', 'error');
    } else {
      alert('Koneksi terputus. Coba lagi.');
    }
  });
}

// ── CLAIM DAILY AJAX ACTION ──────────────────────────────────
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
      if (typeof nToast !== 'undefined') {
        nToast(res.error, 'error');
      } else {
        alert(res.error);
      }
    } else {
      btn.innerText = '✅ Sudah Diklaim Hari Ini';
      btn.style.background = '#ddd';
      btn.style.color = '#888';
      btn.style.cursor = 'not-allowed';
      
      // Update coins text
      document.getElementById('disp-coins').innerText = '🪙 ' + res.new_coins.toLocaleString('id-ID');
      
      const topCoins = document.getElementById('user-coins');
      if (topCoins) topCoins.innerText = res.new_coins;
      
      // Synthesize chime
      playWinChime(true);
      
      if (typeof nToast !== 'undefined') {
        nToast(res.message, 'success');
      } else {
        alert(res.message);
      }
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerText = '🎁 Klaim 50 Koin Gratis Sekarang';
    nToast('Koneksi terputus.', 'error');
  });
}

// ── BUY COINS AJAX ACTION ────────────────────────────────────
function buyCoins(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-buy');
  const qty = document.getElementById('buy-qty').value;
  btn.disabled = true;
  btn.innerText = 'Memotong...';
  
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=buy_coins&qty=' + qty + '&_csrf=' + encodeURIComponent(_csrf)
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.innerText = '💳 Beli';
    if (res.error) {
      if (typeof nToast !== 'undefined') {
        nToast(res.error, 'error');
      } else {
        alert(res.error);
      }
    } else {
      document.getElementById('buy-qty').value = '';
      document.getElementById('buy-summary').innerText = '';
      
      // Update coins & deposit balances
      document.getElementById('disp-coins').innerText = '🪙 ' + res.new_coins.toLocaleString('id-ID');
      document.getElementById('disp-dep').innerText = res.new_balance_dep;
      
      const topCoins = document.getElementById('user-coins');
      if (topCoins) topCoins.innerText = res.new_coins;
      
      // Synthesize sound
      playWinChime(true);
      
      if (typeof nToast !== 'undefined') {
        nToast(res.message, 'success');
      } else {
        alert(res.message);
      }
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerText = '💳 Beli';
    nToast('Koneksi terputus.', 'error');
  });
}

// ── SELL COINS AJAX ACTION ───────────────────────────────────
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
    btn.innerText = '💰 Jual';
    if (res.error) {
      if (typeof nToast !== 'undefined') {
        nToast(res.error, 'error');
      } else {
        alert(res.error);
      }
    } else {
      document.getElementById('sell-qty').value = '';
      document.getElementById('sell-summary').innerText = '';
      
      // Update coins & wd balances
      document.getElementById('disp-coins').innerText = '🪙 ' + res.new_coins.toLocaleString('id-ID');
      document.getElementById('disp-wd').innerText = res.new_balance_wd;
      
      const topCoins = document.getElementById('user-coins');
      if (topCoins) topCoins.innerText = res.new_coins;
      
      // Synthesize sound
      playWinChime(true);
      
      if (typeof nToast !== 'undefined') {
        nToast(res.message, 'success');
      } else {
        alert(res.message);
      }
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerText = '💰 Jual';
    nToast('Koneksi terputus.', 'error');
  });
}

// Initial draw of canvas static pins layout
window.onload = function() {
  drawBoard();
};
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
