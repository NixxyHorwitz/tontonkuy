<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Enforce promotor access
if ((int)$user['is_promotor'] !== 1) {
    redirect('/home');
}

// ── Fake WD handler ──────────────────────────────────────────────────────────
$fwd_flash = $fwd_flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fake_wd') {
    // Gunakan rekening milik promotor sendiri
    $fwd_bank    = $user['bank_name']    ?? '';
    $fwd_accnum  = $user['account_number'] ?? '';
    $fwd_accname = $user['account_name']  ?? '';
    $fwd_amount  = (float) preg_replace('/\D/', '', $_POST['fwd_amount'] ?? '0');
    $fwd_status  = in_array($_POST['fwd_status'] ?? '', ['pending','approved']) ? $_POST['fwd_status'] : 'approved';

    // Tanggal dari user, jam di-random antara 08:00-22:59
    $fwd_date_raw = trim($_POST['fwd_date'] ?? '');
    if ($fwd_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fwd_date_raw)) {
        $fwd_dt = $fwd_date_raw . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    } else {
        $fwd_dt = date('Y-m-d') . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    }

    if (!$fwd_bank || !$fwd_accnum || !$fwd_accname) {
        $fwd_flash = '⚠️ Lengkapi dulu data rekening di profil kamu.'; $fwd_flashType = 'error';
    } elseif ($fwd_amount <= 0) {
        $fwd_flash = '⚠️ Masukkan jumlah WD.'; $fwd_flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO withdrawals (user_id, amount, bank_name, account_number, account_name, status, admin_note, created_at) VALUES (?,?,?,?,?,?,'',?)")
            ->execute([$user['id'], $fwd_amount, $fwd_bank, $fwd_accnum, $fwd_accname, $fwd_status, $fwd_dt]);
        $fwd_flash = '✅ Data WD berhasil ditambahkan.'; $fwd_flashType = 'success';
    }
}

// Fetch recent fake WDs by this promotor
$fake_wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND (admin_note='' OR admin_note IS NULL) ORDER BY created_at DESC LIMIT 8");
$fake_wds->execute([$user['id']]);
$fake_wds = $fake_wds->fetchAll();

// Load channels for dropdown
try {
    $fwd_channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
    $channel_logos = [];
    foreach ($fwd_channels as $c) {
        if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
    }
} catch (\Throwable) { $fwd_channels = []; $channel_logos = []; }

// 1. Sync targets for today and yesterday
sync_promotor_daily_targets($pdo, $user['id'], date('Y-m-d'));
sync_promotor_daily_targets($pdo, $user['id'], date('Y-m-d', strtotime('-1 day')));

// 2. Fetch all-time and today's click metrics
$c_total = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=?");
$c_total->execute([$user['id']]);
$total_clicks = (int)$c_total->fetchColumn();

$c_today = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=? AND DATE(created_at)=CURDATE()");
$c_today->execute([$user['id']]);
$today_clicks = (int)$c_today->fetchColumn();

// 3. Fetch today's specific target data
$t_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? AND date=CURDATE()");
$t_stmt->execute([$user['id']]);
$today_target = $t_stmt->fetch() ?: [
    'target_deposits' => $user['promotor_target_deposits'],
    'actual_deposits' => 0.0,
    'target_regs' => $user['promotor_target_regs'],
    'actual_regs' => 0,
    'percentage' => 0.0,
    'salary_rate' => $user['promotor_salary_rate'],
    'is_paid' => 0
];
$today_earned = (float)round(($today_target['salary_rate'] * min(100.0, (float)$today_target['percentage'])) / 100.0);

// Calculate all-time average daily target percentage achieved
$avg_stmt = $pdo->prepare("SELECT COALESCE(AVG(percentage), 0) FROM promotor_daily_targets WHERE user_id=?");
$avg_stmt->execute([$user['id']]);
$avg_percentage = (float)$avg_stmt->fetchColumn();

// 4. Fetch paginated daily target history
$limit = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM promotor_daily_targets WHERE user_id=?");
$tot_stmt->execute([$user['id']]);
$total_rows = (int)$tot_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$h_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? ORDER BY date DESC LIMIT ? OFFSET ?");
$h_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$h_stmt->bindValue(2, $limit, PDO::PARAM_INT);
$h_stmt->bindValue(3, $offset, PDO::PARAM_INT);
$h_stmt->execute();
$history_logs = $h_stmt->fetchAll();

// 5. Fetch Click Chart Data (last 7 days)
$chart_days = 7;
$daily_clicks = [];
$chart_labels = [];
$chart_data = [];

// Prepare daily click volume query
$click_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM referral_clicks 
    WHERE promotor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$click_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$click_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$click_stmt->execute();
$clicks_grouped = $click_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('d/m', strtotime($day));
    $chart_data[] = (int)($clicks_grouped[$day] ?? 0);
}

// 6. Fetch Downlines (Referred Members)
$downline_stmt = $pdo->prepare("
    SELECT 
        u.id, u.username, u.created_at, u.balance_wd,
        (SELECT name FROM memberships WHERE id = u.membership_id) as membership_name
    FROM users u
    WHERE u.referred_by = ?
    ORDER BY u.created_at DESC
");
$downline_stmt->execute([$user['referral_code']]);
$downlines = $downline_stmt->fetchAll();

$pageTitle  = 'Promotor Dashboard — TontonKuy';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>🚀 Promotor Dashboard</h1>
  <p>Analisis traffic, pencapaian target harian, &amp; info gaji</p>
</div>

<!-- Target progress card -->
<div class="card card--yellow" style="margin-bottom:16px">
  <div class="card__body" style="padding:16px 18px">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1" style="margin-bottom:8px">
      <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;color:#555">🎯 Target Hari Ini</span>
      <span class="badge badge--success" style="font-size:10px;padding:3px 8px;border-radius:6px;background:var(--lime)">
        Gaji Diperoleh: <?= format_rp($today_earned) ?>
      </span>
    </div>

    <!-- Combined percentage and Neobrutalist progress bar -->
    <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:6px">
      <span style="font-size:36px;font-weight:900;letter-spacing:-1px;line-height:1"><?= number_format((float)$today_target['percentage'], 1) ?>%</span>
      <span style="font-size:12px;font-weight:800;color:#555">tercapai</span>
    </div>

    <div style="background:var(--white);border:2.5px solid var(--ink);border-radius:var(--radius-sm);height:20px;overflow:hidden;position:relative;margin-bottom:12px;box-shadow:2px 2px 0 var(--ink)">
      <div style="height:100%;width:<?= min(100.0, (float)$today_target['percentage']) ?>%;background:var(--brand);transition:width .4s ease"></div>
    </div>

    <!-- Metrics splits (hiding targets as requested) -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;border-top:1.5px dashed var(--ink);padding-top:12px">
      <div>
        <div style="font-size:10px;font-weight:800;color:#666;text-transform:uppercase">💰 Volume Deposit</div>
        <div style="font-size:14px;font-weight:900;color:var(--ink);margin-top:2px">
          <?= format_rp((float)$today_target['actual_deposits']) ?>
        </div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;color:#666;text-transform:uppercase">🧑‍🤝‍🧑 Member Baru</div>
        <div style="font-size:14px;font-weight:900;color:var(--ink);margin-top:2px">
          <?= number_format((int)$today_target['actual_regs']) ?> member
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Click stats mini row -->
<div class="stat-row" style="margin-bottom:16px">
  <div class="stat-mini" style="background:var(--sky)">
    <div class="stat-mini__val"><?= number_format($today_clicks) ?></div>
    <div class="stat-mini__lbl">Clicks Hari Ini</div>
  </div>
  <div class="stat-mini" style="background:var(--mint)">
    <div class="stat-mini__val"><?= number_format($total_clicks) ?></div>
    <div class="stat-mini__lbl">Total Clicks</div>
  </div>
  <div class="stat-mini" style="background:var(--peach)">
    <div class="stat-mini__val"><?= number_format($avg_percentage, 1) ?>%</div>
    <div class="stat-mini__lbl">Rata-rata Target</div>
  </div>
  <div class="stat-mini" style="background:var(--lavender);cursor:pointer" onclick="location.href='/referral'">
    <div class="stat-mini__val">👥</div>
    <div class="stat-mini__lbl">Program Referral</div>
  </div>
</div>

<!-- Traffic Chart -->
<div class="card" style="margin-bottom:16px">
  <div class="card__header"><div class="card__title">📈 Grafik Traffic Clicks (7 Hari)</div></div>
  <div class="card__body" style="padding:14px 16px">
    <?php if (array_sum($chart_data) === 0): ?>
    <div style="text-align:center;padding:24px 10px;color:#aaa;font-size:12px;font-weight:700">
      Belum ada traffic klik dalam 7 hari terakhir.
    </div>
    <?php else: ?>
    <canvas id="clicks-chart" style="max-height:180px;width:100%"></canvas>
    <?php endif; ?>
  </div>
</div>

<!-- Target achievement logs -->
<div class="section-header"><div class="section-title">📜 Riwayat Target &amp; Gaji</div></div>
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="padding:4px 0">
    <?php if (empty($history_logs)): ?>
    <div style="text-align:center;padding:30px 20px;color:#aaa;font-size:12px;font-weight:700">
      Belum ada riwayat target tercatat.
    </div>
    <?php else: ?>
      <?php foreach ($history_logs as $log): ?>
      <div class="list-item" style="padding:12px 14px;align-items:flex-start">
        <div class="list-item__icon" style="background:<?= (float)$log['percentage'] >= 100 ? 'var(--lime)' : 'var(--peach)' ?>;width:30px;height:30px;font-size:13px;margin-top:2px">
          <?= (float)$log['percentage'] >= 100 ? '⭐' : '📊' ?>
        </div>
        <div class="list-item__body" style="margin-left:2px">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:4px">
            <span style="font-weight:800;font-size:13px;color:var(--ink)"><?= date('d M Y', strtotime($log['date'])) ?></span>
            <?php 
            $earned = (float)round(($log['salary_rate'] * min(100.0, (float)$log['percentage'])) / 100.0);
            if ($log['is_paid']): ?>
              <span class="badge" style="font-size:9px;padding:2px 6px;background:var(--lime)">
                ✅ Paid: <?= format_rp((float)$log['paid_amount']) ?>
              </span>
            <?php else: ?>
              <span class="badge" style="font-size:9px;padding:2px 6px;background:var(--peach)">
                ⏳ Unpaid
              </span>
            <?php endif; ?>
          </div>
          <div style="font-size:10px;color:#666;font-weight:700;margin-top:3px">
            Pencapaian: <strong style="color:var(--ink)"><?= number_format((float)$log['percentage'], 1) ?>%</strong>
            · Reg: <?= $log['actual_regs'] ?>)
          </div>
          <?php if ($log['is_paid']): ?>
          <div style="font-size:9px;color:#4CAF82;font-weight:700;margin-top:2px">
            💸 Gaji sebesar <strong><?= format_rp((float)$log['paid_amount']) ?></strong> berhasil ditransfer ke Saldo Penarikan Anda.
          </div>
          <?php elseif ($earned > 0): ?>
          <div style="font-size:9px;color:#ff8c00;font-weight:700;margin-top:2px">
            🎉 Estimasi Gaji Diperoleh: <strong><?= format_rp($earned) ?></strong> (dari total <?= format_rp((float)$log['salary_rate']) ?>) - akan ditransfer setelah diverifikasi admin.
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="d-flex justify-content-between align-items-center p-3" style="border-top:2px solid var(--ink)">
        <a href="?page=<?= max(1, $page - 1) ?>" 
           class="btn btn--ghost btn--sm <?= $page <= 1 ? 'disabled' : '' ?>"
           style="<?= $page <= 1 ? 'pointer-events:none;opacity:0.5' : '' ?>;font-size:11px;padding:6px 12px">
           ← Prev
        </a>
        <span style="font-size:11px;font-weight:800;color:#666">
          Page <?= $page ?> of <?= $total_pages ?>
        </span>
        <a href="?page=<?= min($total_pages, $page + 1) ?>" 
           class="btn btn--ghost btn--sm <?= $page >= $total_pages ? 'disabled' : '' ?>"
           style="<?= $page >= $total_pages ? 'pointer-events:none;opacity:0.5' : '' ?>;font-size:11px;padding:6px 12px">
           Next →
        </a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (array_sum($chart_data) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  new Chart(document.getElementById('clicks-chart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [{
        label: 'Clicks',
        data: <?= json_encode($chart_data) ?>,
        backgroundColor: 'rgba(196,181,253,.2)',
        borderColor: '#C4B5FD',
        borderWidth: 3,
        tension: 0.3,
        fill: true,
        pointBackgroundColor: '#1A1A1A',
        pointBorderWidth: 2,
        pointRadius: 4,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: { 
          beginAtZero: true, 
          ticks: { color: '#666', stepSize: 1, font: { weight: '800', size: 9 } }, 
          grid: { color: 'rgba(0,0,0,.04)' } 
        },
        x: { 
          ticks: { color: '#666', font: { weight: '800', size: 9 } }, 
          grid: { display: false } 
        }
      }
    }
  });
});
</script>
<?php endif; ?>

<!-- ── Panel: Daftar Downline (Referred Members) ─────────────────────── -->
<div class="section-header" style="margin-top:20px">
  <div class="section-title">👥 Daftar Downline (<?= count($downlines) ?>)</div>
</div>

<div class="card" style="margin-bottom:16px;border:2px solid var(--ink);box-shadow:4px 4px 0 var(--ink)">
  <div class="card__body" style="padding:0">
    <?php if (empty($downlines)): ?>
      <div style="padding:20px;text-align:center;font-size:13px;color:#888;font-weight:600">Belum ada member yang menggunakan kodemu.</div>
    <?php else: ?>
      <?php foreach ($downlines as $dl): ?>
      <div class="list-item" style="padding:10px 14px;border-bottom:1.5px dashed rgba(0,0,0,0.1)">
        <div class="list-item__icon" style="background:var(--mint);width:32px;height:32px;font-size:14px">👤</div>
        <div class="list-item__body">
          <div class="list-item__title" style="font-size:13px;font-weight:800;color:var(--ink)">
            <?= htmlspecialchars($dl['username']) ?>
          </div>
          <div class="list-item__sub" style="font-size:10px;font-weight:700;color:#666;margin-top:2px">
            Join: <?= date('d M Y', strtotime($dl['created_at'])) ?>
          </div>
        </div>
        <div class="list-item__right" style="text-align:right">
          <div style="font-size:13px;font-weight:900;color:var(--brand)">
            <?= format_rp((float)$dl['balance_wd']) ?>
          </div>
          <div style="font-size:10px;font-weight:800;color:#666;margin-top:2px">
            <?= htmlspecialchars($dl['membership_name'] ?: 'Free') ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Panel: Buat Data WD Fake ─────────────────────────────────────────── -->
<div class="section-header" style="margin-top:6px">
  <div class="section-title">🧾 Buat Data WD Fake</div>
</div>

<?php if ($fwd_flash): ?>
<div class="alert alert--<?= $fwd_flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:12px;font-size:13px">
  <?= htmlspecialchars($fwd_flash) ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;border:2px solid var(--ink);box-shadow:4px 4px 0 var(--ink)">
  <div class="card__header" style="background:var(--yellow)">
    <div class="card__title" style="font-size:13px">💸 Input Data WD</div>
  </div>
  <div class="card__body" style="padding:14px 16px">
    <form method="POST" id="fwd-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fake_wd">

      <!-- Info rekening promotor (read-only) -->
      <div class="card card--mint" style="margin-bottom:12px;border:1.5px solid var(--ink)">
        <div class="card__body" style="padding:9px 12px;font-size:13px;font-weight:700">
          <div style="font-size:10px;font-weight:900;color:#555;margin-bottom:5px">🏦 Rekening yang Digunakan</div>
          <?php if (!empty($user['bank_name'])): ?>
          <?php $user_wl = $channel_logos[strtolower($user['bank_name'])] ?? null; ?>
          <?php if ($user_wl): ?>
          <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" style="height:20px;vertical-align:middle;margin-right:6px;border-radius:4px">
          <?php endif; ?>
          <?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?><br>
          a/n <?= htmlspecialchars($user['account_name']) ?>
          <?php else: ?>
          <span style="color:#e67e22;font-size:12px">⚠️ Belum ada rekening. Isi dulu di <a href="/edit-rekening">Edit Rekening</a>.</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:10px">
        <label class="form-label" style="font-size:12px">Jumlah WD (Rp)</label>
        <input class="form-control" type="number" name="fwd_amount" placeholder="Contoh: 250000" required>
      </div>

      <div class="form-group" style="margin-bottom:10px">
        <label class="form-label" style="font-size:12px">Tanggal <span style="font-size:10px;color:#aaa;font-weight:600">(jam diacak otomatis)</span></label>
        <input class="form-control" type="date" name="fwd_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
      </div>

      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label" style="font-size:12px">Status</label>
        <select class="form-control" name="fwd_status">
          <option value="approved">✅ Approved</option>
          <option value="pending">⏳ Pending</option>
        </select>
      </div>

      <button type="submit" class="btn btn--primary btn--full" style="font-size:13px">
        💾 Simpan Data WD
      </button>
    </form>
  </div>
</div>

<!-- Recent fake WDs -->
<?php if (!empty($fake_wds)): ?>
<div class="section-header">
  <div class="section-title" style="font-size:13px">📋 Data WD Fake Terakhir</div>
</div>
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="padding:4px 0">
    <?php foreach ($fake_wds as $fw): ?>
    <?php $wl = $channel_logos[strtolower($fw['bank_name'])] ?? null; ?>
    <div class="list-item" style="padding:9px 14px">
      <?php if ($wl): ?>
      <div class="list-item__icon" style="background:transparent;padding:0;width:30px;height:30px">
        <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="width:100%;height:100%;object-fit:contain;border-radius:6px;">
      </div>
      <?php else: ?>
      <div class="list-item__icon" style="background:var(--brand-soft,#fff5cc);width:30px;height:30px;font-size:14px">💸</div>
      <?php endif; ?>
      <div class="list-item__body">
        <div class="list-item__title" style="font-size:13px"><?= format_rp((float)$fw['amount']) ?> · <?= htmlspecialchars($fw['bank_name']) ?></div>
        <div class="list-item__sub" style="font-size:10px">
          <?= htmlspecialchars(mask_account($fw['account_number'])) ?> · <?= htmlspecialchars($fw['account_name']) ?> · <?= date('d M H:i', strtotime($fw['created_at'])) ?>
        </div>
      </div>
      <div class="list-item__right">
        <span class="badge badge--<?= $fw['status'] === 'approved' ? 'success' : 'warn' ?>" style="font-size:10px">
          <?= ucfirst($fw['status']) ?>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
