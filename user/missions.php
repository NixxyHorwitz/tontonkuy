<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// ── Mission definitions (hardcoded) ───────────────────────────
$ALL_MISSIONS = [
    // HARIAN
    ['slug'=>'daily_watch_3',       'category'=>'daily',    'title'=>'Tonton 3 Video',             'desc'=>'Tonton minimal 3 video hari ini.',           'target'=>3,   'reward'=>200,   'icon'=>'ph-film-slate'],
    ['slug'=>'daily_watch_5',       'category'=>'daily',    'title'=>'Tonton 5 Video',             'desc'=>'Tonton minimal 5 video hari ini.',           'target'=>5,   'reward'=>500,   'icon'=>'ph-film-reel'],
    ['slug'=>'daily_checkin',       'category'=>'daily',    'title'=>'Check-in Hari Ini',          'desc'=>'Lakukan check-in harian.',                   'target'=>1,   'reward'=>100,   'icon'=>'ph-calendar-check'],
    // MINGGUAN
    ['slug'=>'weekly_streak_7',     'category'=>'weekly',   'title'=>'Streak 7 Hari',              'desc'=>'Check-in setiap hari selama 7 hari penuh.',  'target'=>7,   'reward'=>2000,  'icon'=>'ph-fire'],
    ['slug'=>'weekly_watch_20',     'category'=>'weekly',   'title'=>'Tonton 20 Video Minggu Ini', 'desc'=>'Tonton total 20 video minggu ini.',          'target'=>20,  'reward'=>1500,  'icon'=>'ph-television'],
    ['slug'=>'weekly_watch_7days',  'category'=>'weekly',   'title'=>'Aktif 7 Hari (Nonton)',      'desc'=>'Tonton video di 7 hari berbeda minggu ini.', 'target'=>7,   'reward'=>2500,  'icon'=>'ph-star'],
    // LIFETIME
    ['slug'=>'lifetime_first_ref',  'category'=>'lifetime', 'title'=>'Daftarkan 1 Referral',       'desc'=>'Ajak 1 teman bergabung via kode referralmu.','target'=>1,   'reward'=>5000,  'icon'=>'ph-user-plus'],
    ['slug'=>'lifetime_5_refs',     'category'=>'lifetime', 'title'=>'Agen Rekruter',               'desc'=>'Ajak 5 teman bergabung via kode referralmu.','target'=>5,   'reward'=>15000, 'icon'=>'ph-users-three'],
    ['slug'=>'lifetime_first_wd',   'category'=>'lifetime', 'title'=>'Penarikan Pertama',           'desc'=>'Lakukan penarikan saldo pertama kalinya.',   'target'=>1,   'reward'=>3000,  'icon'=>'ph-money'],
    ['slug'=>'lifetime_100_videos', 'category'=>'lifetime', 'title'=>'Penonton Sejati',             'desc'=>'Tonton total 100 video di TontonKuy.',       'target'=>100, 'reward'=>10000, 'icon'=>'ph-popcorn'],
    ['slug'=>'lifetime_upgrade',    'category'=>'lifetime', 'title'=>'Member Premium',              'desc'=>'Upgrade ke paket membership berbayar.',      'target'=>1,   'reward'=>8000,  'icon'=>'ph-crown'],
];

$today    = date('Y-m-d');
$weekKey  = date('Y-\WW'); // e.g. 2026-W23

// ── Helper: get real-time progress ───────────────────────────
function get_progress(PDO $pdo, array $user, array $mission): int {
    $uid = $user['id'];
    switch ($mission['slug']) {
        case 'daily_watch_3':
        case 'daily_watch_5':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'daily_checkin':
            return ($user['last_checkin'] === date('Y-m-d')) ? 1 : 0;
        case 'weekly_streak_7':
            // Count distinct days this week with check-in (approximate via watch_history)
            $s = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return min(7, (int)$s->fetchColumn());
        case 'weekly_watch_20':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'weekly_watch_7days':
            $s = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_first_ref':
        case 'lifetime_5_refs':
            $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
            $s->execute([$user['referral_code']]);
            return (int)$s->fetchColumn();
        case 'lifetime_first_wd':
            $s = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=?");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_100_videos':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_upgrade':
            return ($user['membership_id'] ? 1 : 0);
    }
    return 0;
}

// ── Helper: get period key ────────────────────────────────────
function get_period_key(string $category): ?string {
    if ($category === 'daily')   return date('Y-m-d');
    if ($category === 'weekly')  return date('Y-\WW');
    return null; // lifetime
}

// ── Helper: check if already claimed ─────────────────────────
function is_claimed(PDO $pdo, int $user_id, string $slug, ?string $period_key): bool {
    $s = $pdo->prepare("SELECT claimed_at FROM user_missions WHERE user_id=? AND mission_slug=? AND period_key<=>?");
    $s->execute([$user_id, $slug, $period_key]);
    $row = $s->fetch();
    return $row && $row['claimed_at'] !== null;
}

// ── Handle AJAX claim ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_mission') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'CSRF tidak valid.']); exit; }

    $slug = trim($_POST['slug'] ?? '');
    $mission = null;
    foreach ($ALL_MISSIONS as $m) {
        if ($m['slug'] === $slug) { $mission = $m; break; }
    }
    if (!$mission) { echo json_encode(['ok'=>false,'msg'=>'Misi tidak ditemukan.']); exit; }

    $period = get_period_key($mission['category']);
    if (is_claimed($pdo, $user['id'], $slug, $period)) {
        echo json_encode(['ok'=>false,'msg'=>'Misi ini sudah pernah diklaim!']); exit;
    }

    $progress = get_progress($pdo, $user, $mission);
    if ($progress < $mission['target']) {
        echo json_encode(['ok'=>false,'msg'=>"Progress belum cukup. ({$progress}/{$mission['target']})"]); exit;
    }

    try {
        $pdo->beginTransaction();
        // Upsert record
        $pdo->prepare("INSERT INTO user_missions (user_id, mission_slug, progress, completed_at, claimed_at, period_key)
            VALUES (?, ?, ?, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE progress=VALUES(progress), completed_at=COALESCE(completed_at,NOW()), claimed_at=NOW()")
            ->execute([$user['id'], $slug, $progress, $period]);
        // Give reward
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id=?")
            ->execute([$mission['reward'], $user['id']]);
        $pdo->commit();
        echo json_encode(['ok'=>true,'msg'=>'🎉 Reward diklaim! +'.number_format($mission['reward'],0,',','.').' ke Saldo Tarik.','reward'=>$mission['reward']]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kesalahan: '.$e->getMessage()]);
    }
    exit;
}

// ── Build mission data with progress ─────────────────────────
$missions_data = [];
foreach ($ALL_MISSIONS as $m) {
    $period   = get_period_key($m['category']);
    $progress = get_progress($pdo, $user, $m);
    $claimed  = is_claimed($pdo, $user['id'], $m['slug'], $period);
    $done     = $progress >= $m['target'];

    $missions_data[] = array_merge($m, [
        'progress' => min($progress, $m['target']),
        'claimed'  => $claimed,
        'done'     => $done,
        'period'   => $period,
    ]);
}

$daily    = array_filter($missions_data, fn($m) => $m['category'] === 'daily');
$weekly   = array_filter($missions_data, fn($m) => $m['category'] === 'weekly');
$lifetime = array_filter($missions_data, fn($m) => $m['category'] === 'lifetime');

$claimed_today = count(array_filter($missions_data, fn($m) => $m['claimed']));

$pageTitle  = 'Misi — TontonKuy';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ── Mission Page – Neo-Brutalism ─────────── */
.mission-header {
  background: var(--ink);
  color: #fff;
  padding: 20px 16px 16px;
  margin: -16px -16px 20px;
  border-bottom: 4px solid var(--ink);
  position: relative;
  overflow: hidden;
}
.mission-header::before {
  content: '';
  position: absolute; inset: 0;
  background: repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(255,255,255,0.04) 8px, rgba(255,255,255,0.04) 16px);
}
.mission-header__title {
  font-size: 28px; font-weight: 900; text-transform: uppercase;
  letter-spacing: -1px; position: relative;
}
.mission-header__sub {
  font-size: 13px; opacity: 0.7; font-weight: 700; position: relative; margin-top: 2px;
}
.mission-header__badge {
  position: absolute; top: 16px; right: 16px;
  background: var(--yellow); color: var(--ink);
  border: 3px solid #fff; border-radius: 50%;
  width: 52px; height: 52px;
  display: flex; align-items: center; justify-content: center;
  flex-direction: column; font-weight: 900; font-size: 18px; line-height: 1;
}
.mission-header__badge small { font-size: 9px; font-weight: 800; text-transform: uppercase; }

/* ── Tabs ─────── */
.mission-tabs {
  display: grid; grid-template-columns: repeat(3, 1fr);
  border: 3px solid var(--ink); border-radius: 10px;
  overflow: hidden; box-shadow: 3px 3px 0 var(--ink);
  margin-bottom: 20px;
}
.mission-tab {
  padding: 10px 4px; text-align: center;
  font-size: 11px; font-weight: 900; text-transform: uppercase;
  background: #fff; color: var(--ink); border: none;
  cursor: pointer; letter-spacing: 0.3px;
  border-right: 3px solid var(--ink);
  transition: background 0.15s;
}
.mission-tab:last-child { border-right: none; }
.mission-tab.active { background: var(--yellow); }
.mission-tab__icon { display: block; font-size: 20px; margin-bottom: 2px; }

/* ── Mission Card ─────── */
.mission-card {
  background: #fff;
  border: 3px solid var(--ink);
  border-radius: 12px;
  box-shadow: 4px 4px 0 var(--ink);
  margin-bottom: 14px;
  overflow: hidden;
  transition: transform 0.1s;
}
.mission-card--done { background: #f0fdf4; }
.mission-card--claimed { background: #f8f8f8; opacity: 0.7; }
.mission-card__head {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 14px 10px;
}
.mission-card__icon-wrap {
  width: 48px; height: 48px; border-radius: 10px;
  border: 3px solid var(--ink);
  box-shadow: 2px 2px 0 var(--ink);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: 22px;
  background: var(--yellow);
}
.mission-card--done .mission-card__icon-wrap { background: #bbf7d0; }
.mission-card--claimed .mission-card__icon-wrap { background: #e5e7eb; }
.mission-card__info { flex: 1; min-width: 0; }
.mission-card__title {
  font-size: 14px; font-weight: 900; color: var(--ink); line-height: 1.2;
}
.mission-card__desc {
  font-size: 11px; color: #666; font-weight: 700; margin-top: 2px;
}
.mission-card__reward {
  font-size: 13px; font-weight: 900; color: var(--ink);
  background: var(--yellow); border: 2px solid var(--ink);
  padding: 3px 8px; border-radius: 6px; box-shadow: 2px 2px 0 var(--ink);
  white-space: nowrap; flex-shrink: 0;
}
.mission-card--claimed .mission-card__reward { background: #e5e7eb; box-shadow: none; border-color: #aaa; color: #888; }

/* ── Progress Bar ─────── */
.mission-progress { padding: 0 14px 14px; }
.mission-progress__bar-wrap {
  background: #e5e7eb; border: 2px solid var(--ink);
  border-radius: 6px; height: 14px; overflow: hidden;
  box-shadow: 2px 2px 0 var(--ink);
}
.mission-progress__bar {
  height: 100%; background: var(--yellow);
  border-right: 2px solid var(--ink);
  transition: width 0.5s ease;
}
.mission-progress__bar--done { background: #22c55e; border-right-color: transparent; }
.mission-progress__meta {
  display: flex; justify-content: space-between;
  font-size: 10px; font-weight: 900; margin-top: 5px; color: #555;
}

/* ── Claim Button ─────── */
.mission-claim-btn {
  width: 100%; margin-top: 6px;
  padding: 10px; font-size: 13px; font-weight: 900;
  text-transform: uppercase; letter-spacing: 0.5px;
  border: 3px solid var(--ink); border-radius: 8px;
  box-shadow: 3px 3px 0 var(--ink);
  cursor: pointer; transition: transform 0.1s, box-shadow 0.1s;
  background: #00E5FF; color: var(--ink);
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.mission-claim-btn:active { transform: translate(3px,3px); box-shadow: 0 0 0 var(--ink); }
.mission-claim-btn:disabled {
  background: #e5e7eb; color: #9ca3af; cursor: not-allowed;
  box-shadow: 2px 2px 0 #9ca3af; border-color: #9ca3af;
}
.mission-claim-btn--claimed {
  background: #d1fae5; color: #166534;
  border-color: #166534; box-shadow: 2px 2px 0 #166534;
  cursor: default;
}

/* ── Section Header ─────── */
.mission-section-hdr {
  font-size: 12px; font-weight: 900; text-transform: uppercase;
  letter-spacing: 0.5px; color: #555;
  display: flex; align-items: center; gap: 6px;
  margin-bottom: 12px;
  padding-bottom: 6px; border-bottom: 3px solid var(--ink);
}

/* ── Tab panels ─────── */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

@keyframes missionIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: none; }
}
.mission-card { animation: missionIn 0.2s ease both; }
</style>

<!-- Page Header -->
<div class="mission-header">
  <div class="mission-header__title">🎯 Misi</div>
  <div class="mission-header__sub">Selesaikan misi &amp; klaim saldo tarik!</div>
  <div class="mission-header__badge">
    <?= $claimed_today ?>
    <small>Klaim</small>
  </div>
</div>

<!-- Tabs -->
<div class="mission-tabs" role="tablist">
  <button class="mission-tab active" id="tab-daily" onclick="switchTab('daily')" role="tab">
    <i class="ph-fill ph-sun mission-tab__icon"></i>
    Harian
  </button>
  <button class="mission-tab" id="tab-weekly" onclick="switchTab('weekly')" role="tab">
    <i class="ph-fill ph-calendar mission-tab__icon"></i>
    Mingguan
  </button>
  <button class="mission-tab" id="tab-lifetime" onclick="switchTab('lifetime')" role="tab">
    <i class="ph-fill ph-trophy mission-tab__icon"></i>
    Pencapaian
  </button>
</div>

<!-- ── Daily Panel ──────────────────────────────────────────── -->
<div class="tab-panel active" id="panel-daily">
  <div class="mission-section-hdr">
    <i class="ph-fill ph-sun" style="color:var(--yellow);font-size:16px"></i>
    Misi Harian — Reset setiap hari
  </div>
  <?php foreach ($daily as $m): ?>
  <?php
    $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
    $cardClass = $m['claimed'] ? 'mission-card--claimed' : ($m['done'] ? 'mission-card--done' : '');
  ?>
  <div class="mission-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
    <div class="mission-card__head">
      <div class="mission-card__icon-wrap">
        <i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i>
      </div>
      <div class="mission-card__info">
        <div class="mission-card__title"><?= htmlspecialchars($m['title']) ?></div>
        <div class="mission-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
      </div>
      <div class="mission-card__reward">+Rp <?= number_format($m['reward'],0,',','.') ?></div>
    </div>
    <div class="mission-progress">
      <div class="mission-progress__bar-wrap">
        <div class="mission-progress__bar <?= $m['done'] ? 'mission-progress__bar--done' : '' ?>"
             style="width:<?= $pct ?>%"></div>
      </div>
      <div class="mission-progress__meta">
        <span><?= $m['progress'] ?> / <?= $m['target'] ?></span>
        <span><?= $pct ?>%</span>
      </div>
      <?php if ($m['claimed']): ?>
        <button class="mission-claim-btn mission-claim-btn--claimed" disabled>
          <i class="ph-bold ph-check-circle"></i> Sudah Diklaim Hari Ini
        </button>
      <?php elseif ($m['done']): ?>
        <button class="mission-claim-btn" onclick="claimMission('<?= $m['slug'] ?>', this)">
          <i class="ph-bold ph-gift"></i> Klaim Reward!
        </button>
      <?php else: ?>
        <button class="mission-claim-btn" disabled>
          <i class="ph-bold ph-lock"></i> Belum Selesai
        </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Weekly Panel ──────────────────────────────────────────── -->
<div class="tab-panel" id="panel-weekly">
  <div class="mission-section-hdr">
    <i class="ph-fill ph-calendar" style="color:#6366f1;font-size:16px"></i>
    Misi Mingguan — Reset tiap Senin
  </div>
  <?php foreach ($weekly as $m): ?>
  <?php
    $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
    $cardClass = $m['claimed'] ? 'mission-card--claimed' : ($m['done'] ? 'mission-card--done' : '');
  ?>
  <div class="mission-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
    <div class="mission-card__head">
      <div class="mission-card__icon-wrap">
        <i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i>
      </div>
      <div class="mission-card__info">
        <div class="mission-card__title"><?= htmlspecialchars($m['title']) ?></div>
        <div class="mission-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
      </div>
      <div class="mission-card__reward">+Rp <?= number_format($m['reward'],0,',','.') ?></div>
    </div>
    <div class="mission-progress">
      <div class="mission-progress__bar-wrap">
        <div class="mission-progress__bar <?= $m['done'] ? 'mission-progress__bar--done' : '' ?>"
             style="width:<?= $pct ?>%"></div>
      </div>
      <div class="mission-progress__meta">
        <span><?= $m['progress'] ?> / <?= $m['target'] ?></span>
        <span><?= $pct ?>%</span>
      </div>
      <?php if ($m['claimed']): ?>
        <button class="mission-claim-btn mission-claim-btn--claimed" disabled>
          <i class="ph-bold ph-check-circle"></i> Sudah Diklaim Minggu Ini
        </button>
      <?php elseif ($m['done']): ?>
        <button class="mission-claim-btn" onclick="claimMission('<?= $m['slug'] ?>', this)">
          <i class="ph-bold ph-gift"></i> Klaim Reward!
        </button>
      <?php else: ?>
        <button class="mission-claim-btn" disabled>
          <i class="ph-bold ph-lock"></i> Belum Selesai
        </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Lifetime Panel ──────────────────────────────────────────── -->
<div class="tab-panel" id="panel-lifetime">
  <div class="mission-section-hdr">
    <i class="ph-fill ph-trophy" style="color:#d97706;font-size:16px"></i>
    Pencapaian — Hanya bisa diklaim sekali
  </div>
  <?php foreach ($lifetime as $m): ?>
  <?php
    $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
    $cardClass = $m['claimed'] ? 'mission-card--claimed' : ($m['done'] ? 'mission-card--done' : '');
  ?>
  <div class="mission-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
    <div class="mission-card__head">
      <div class="mission-card__icon-wrap">
        <i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i>
      </div>
      <div class="mission-card__info">
        <div class="mission-card__title"><?= htmlspecialchars($m['title']) ?></div>
        <div class="mission-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
      </div>
      <div class="mission-card__reward">+Rp <?= number_format($m['reward'],0,',','.') ?></div>
    </div>
    <div class="mission-progress">
      <div class="mission-progress__bar-wrap">
        <div class="mission-progress__bar <?= $m['done'] ? 'mission-progress__bar--done' : '' ?>"
             style="width:<?= $pct ?>%"></div>
      </div>
      <div class="mission-progress__meta">
        <span><?= $m['progress'] ?> / <?= $m['target'] ?></span>
        <span><?= $pct ?>%</span>
      </div>
      <?php if ($m['claimed']): ?>
        <button class="mission-claim-btn mission-claim-btn--claimed" disabled>
          <i class="ph-bold ph-check-circle"></i> Sudah Diklaim
        </button>
      <?php elseif ($m['done']): ?>
        <button class="mission-claim-btn" onclick="claimMission('<?= $m['slug'] ?>', this)">
          <i class="ph-bold ph-gift"></i> Klaim Reward!
        </button>
      <?php else: ?>
        <button class="mission-claim-btn" disabled>
          <i class="ph-bold ph-lock"></i> Belum Selesai
        </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const _csrf = '<?= csrf_token() ?>';

function switchTab(cat) {
  document.querySelectorAll('.mission-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + cat).classList.add('active');
  document.getElementById('panel-' + cat).classList.add('active');
}

function claimMission(slug, btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:spin 0.8s linear infinite"></i> Mengklaim...';

  const fd = new FormData();
  fd.append('action', 'claim_mission');
  fd.append('slug', slug);
  fd.append('_csrf', _csrf);

  fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        const card = document.getElementById('mc-' + slug);
        if (card) {
          card.classList.remove('mission-card--done');
          card.classList.add('mission-card--claimed');
        }
        btn.className = 'mission-claim-btn mission-claim-btn--claimed';
        btn.innerHTML = '<i class="ph-bold ph-check-circle"></i> Sudah Diklaim';
        btn.disabled = true;
        // Toast
        if (window.showToast) showToast(data.msg, 'success');
        else alert(data.msg);
      } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-gift"></i> Klaim Reward!';
        if (window.showToast) showToast(data.msg, 'error');
        else alert(data.msg);
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="ph-bold ph-gift"></i> Klaim Reward!';
    });
}
</script>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
