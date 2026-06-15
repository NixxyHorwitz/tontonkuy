<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0,
        'username' => 'Tamu',
        'balance_wd' => 0,
        'balance_dep' => 0,
        'membership_id' => null,
        'membership_expires_at' => null,
        'referral_code' => '-',
        'is_promotor' => 0,
        'plinko_coins' => 0,
    ];
}

// Maintenance mode check — block users but not admins
if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

// Track pageview (analytics)
track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$watch_limit = $is_guest ? 0 : user_watch_limit($pdo, $user);
$watch_today = $is_guest ? 0 : user_watch_today($pdo, $user);

// Available videos
if ($is_guest) {
    $videos = $pdo->query("SELECT v.* FROM videos v WHERE v.is_active=1 ORDER BY v.sort_order ASC, v.id DESC LIMIT 6")->fetchAll();
    $history = [];
    $notif_preview = [];
    $notif_unread = 0;
} else {
    $videos = $pdo->prepare(
        "SELECT v.* FROM videos v
         WHERE v.is_active=1
           AND v.id NOT IN (
               SELECT video_id FROM watch_history
               WHERE user_id=? AND DATE(watched_at)=CURDATE()
           )
         ORDER BY v.sort_order ASC, v.id DESC LIMIT 6"
    );
    $videos->execute([$user['id']]);
    $videos = $videos->fetchAll();
    
    // Recent activity
    $history = $pdo->prepare(
        "SELECT wh.reward_given, wh.watched_at, v.title
         FROM watch_history wh
         JOIN videos v ON v.id=wh.video_id
         WHERE wh.user_id=?
         ORDER BY wh.watched_at DESC LIMIT 4"
    );
    $history->execute([$user['id']]);
    $history = $history->fetchAll();
    
    // Unread notifications preview (max 3)
    $notif_preview = [];
    $notif_unread  = 0;
    try {
        $uid = $user['id'];
        $np = $pdo->prepare(
            "SELECT n.* FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL
               AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW())
             ORDER BY n.created_at DESC LIMIT 3"
        );
        $np->execute([$uid, (string)$uid]);
        $notif_preview = $np->fetchAll();
        // Total unread count
        $nc = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL
               AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $nc->execute([$uid, (string)$uid]);
        $notif_unread = (int)$nc->fetchColumn();
    } catch (\Throwable) {}
}

// Membership name
$membership_name = 'Free';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn() ?: 'Free';
}

$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;
$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

$pageTitle  = 'Beranda — NontonKuy';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<?php if (!empty($_SESSION['flash_home_err'])): ?>
<div class="alert alert--error" style="margin-bottom:12px;font-size:13px">
  <?= htmlspecialchars($_SESSION['flash_home_err']) ?>
</div>
<?php unset($_SESSION['flash_home_err']); endif; ?>

<!-- Header Profile & Balance -->
<style>
@keyframes float {
  0% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
  100% { transform: translateY(0); }
}
@keyframes pulse-glow {
  0% { box-shadow: 0 0 0 0 rgba(255, 107, 53, 0.7); }
  70% { box-shadow: 0 0 0 12px rgba(255, 107, 53, 0); }
  100% { box-shadow: 0 0 0 0 rgba(255, 107, 53, 0); }
}
.upgrade-btn {
  background: linear-gradient(135deg, #FF6B35, #FF4500);
  color: #fff;
  text-decoration: none;
  padding: 8px 16px;
  border-radius: 50px;
  font-weight: 900;
  border: 2px solid var(--ink);
  box-shadow: 3px 3px 0 var(--ink);
  font-size: 13px;
  letter-spacing: 0.5px;
  display: flex;
  align-items: center;
  gap: 6px;
  animation: pulse-glow 2s infinite;
  transition: all 0.2s ease;
}
.upgrade-btn:hover {
  transform: translate(-2px, -2px) scale(1.02);
  box-shadow: 5px 5px 0 var(--ink);
}
.upgrade-btn i { animation: float 2s ease-in-out infinite; }
</style>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
  <div>
    <h2 style="font-size:18px;font-weight:900;margin:0;line-height:1.2;color:var(--ink)">Halo, <?= htmlspecialchars($user['username']) ?>!</h2>
    <span class="badge badge--neutral" style="font-size:10px;margin-top:4px;background:var(--peach);color:#fff;border-color:var(--ink)"><i class="ph-fill ph-star"></i> <?= $membership_name ?></span>
  </div>
  <a href="<?= $is_guest ? '/login' : '/upgrade' ?>" class="upgrade-btn">
    <i class="ph-bold <?= $is_guest ? 'ph-sign-in' : 'ph-rocket-launch' ?>"></i> <?= $is_guest ? 'LOGIN / DAFTAR' : 'UPGRADE' ?>
  </a>
</div>

<?php 
$is_newcomer = !$is_guest && (empty($history) || (isset($user['created_at']) && strtotime($user['created_at']) > time() - 3 * 86400) || ($user['balance_wd'] == 0 && $user['balance_dep'] == 0));
if ($is_newcomer): 
?>
<div style="background:#fef08a;border:2.5px solid var(--ink);border-radius:12px;padding:12px;margin-bottom:16px;box-shadow:3px 3px 0 var(--ink);display:flex;align-items:center;gap:12px;animation:float 3s ease-in-out infinite">
  <div style="background:#fff;border:2px solid var(--ink);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:2px 2px 0 var(--ink)">
    <i class="ph-bold ph-book-open-text" style="font-size:20px;color:#d97706"></i>
  </div>
  <div style="flex:1">
    <div style="font-size:12px;font-weight:900;color:var(--ink);margin-bottom:2px">Baru gabung di NontonKuy?</div>
    <div style="font-size:10px;font-weight:700;color:#666">Yuk baca panduan dulu supaya paham cara dapetin duitnya!</div>
  </div>
  <a href="/panduan" class="btn btn--primary btn--sm" style="font-size:10px;padding:6px 10px;white-space:nowrap;border:2px solid var(--ink);box-shadow:2px 2px 0 var(--ink)">Baca Panduan</a>
</div>
<?php endif; ?>

<?php if ($level_blocked): ?>
<div style="background:#fefce8;border:2.5px solid #ca8a04;border-radius:12px;padding:12px;margin-bottom:16px;box-shadow:3px 3px 0 #ca8a04;display:flex;align-items:center;gap:12px">
  <div style="background:#fff;border:2px solid #ca8a04;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:2px 2px 0 #ca8a04">
    <i class="ph-bold ph-lock-key" style="font-size:20px;color:#ca8a04"></i>
  </div>
  <div style="flex:1">
    <div style="font-size:12px;font-weight:900;color:var(--ink);margin-bottom:2px">Akses Withdraw Terkunci</div>
    <div style="font-size:10px;font-weight:700;color:#666">Kamu harus upgrade ke minimal <strong><?= htmlspecialchars($min_level_name) ?></strong> agar bisa withdraw.</div>
  </div>
  <a href="/upgrade" class="btn" style="background:#ca8a04;color:#fff;font-size:10px;padding:6px 10px;white-space:nowrap;border:2px solid #854d0e;border-radius:8px;font-weight:800;box-shadow:2px 2px 0 #854d0e">Upgrade →</a>
</div>
<?php endif; ?>

<!-- Dual Balance Cards -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
  <!-- Saldo Penarikan -->
  <a href="/withdraw" style="background:linear-gradient(135deg, var(--lavender), #e0d4ff);border:2.5px solid var(--ink);border-radius:12px;padding:12px;box-shadow:3px 3px 0 var(--ink);text-decoration:none;color:var(--ink);display:flex;flex-direction:column;gap:6px;transition:transform 0.2s;position:relative;overflow:hidden">
    <div style="position:absolute;right:-15px;bottom:-15px;opacity:0.1;transform:rotate(-15deg)"><i class="ph-fill ph-wallet" style="font-size:60px"></i></div>
    <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:800;opacity:0.9;position:relative">
      <i class="ph-bold ph-wallet" style="font-size:16px"></i> Saldo Penarikan
    </div>
    <div style="font-size:16px;font-weight:900;word-break:break-word;position:relative"><?= format_rp((float)$user['balance_wd']) ?></div>
  </a>
  <!-- Saldo Deposit -->
  <a href="/deposit" style="background:linear-gradient(135deg, var(--mint), #baffdb);border:2.5px solid var(--ink);border-radius:12px;padding:12px;box-shadow:3px 3px 0 var(--ink);text-decoration:none;color:var(--ink);display:flex;flex-direction:column;gap:6px;transition:transform 0.2s;position:relative;overflow:hidden">
    <div style="position:absolute;right:-10px;bottom:-10px;opacity:0.1;transform:rotate(10deg)"><i class="ph-fill ph-bank" style="font-size:60px"></i></div>
    <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:800;opacity:0.9;position:relative">
      <i class="ph-bold ph-bank" style="font-size:16px"></i> Saldo Beli
    </div>
    <div style="font-size:16px;font-weight:900;word-break:break-word;position:relative"><?= format_rp((float)$user['balance_dep']) ?></div>
  </a>
</div>

<!-- Action Grid -->
<style>
.qact-wrap { position: relative; margin-bottom: 14px; }
.qact-row {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 2px 0 8px;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.qact-row::-webkit-scrollbar { display: none; }
.qact-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  flex-shrink: 0;
  text-decoration: none;
  color: var(--ink);
  width: 60px;
}
.qact-item__icon {
  width: 48px; height: 48px;
  border-radius: 14px;
  border: 2.5px solid var(--ink);
  box-shadow: 2.5px 2.5px 0 var(--ink);
  display: flex; align-items: center; justify-content: center;
  font-size: 21px;
  color: #fff;
  transition: transform 0.12s, box-shadow 0.12s;
}
.qact-item:active .qact-item__icon {
  transform: translate(2px, 2px);
  box-shadow: 0 0 0 var(--ink);
}
.qact-item__label {
  font-size: 10px; font-weight: 800;
  text-align: center; line-height: 1.2;
  color: var(--ink);
}
/* Scroll indicator */
.qact-dots {
  display: flex;
  justify-content: center;
  gap: 4px;
  margin-top: 4px;
}
.qact-dot {
  width: 18px; height: 4px;
  border-radius: 2px;
  background: #ddd;
  border: 1px solid #ccc;
  transition: background .2s, width .2s;
}
.qact-dot.active {
  background: var(--ink);
  width: 28px;
}
</style>
<div class="qact-wrap">
<div class="qact-row" id="qact-row">
  <a href="/deposit" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#38bdf8,#0ea5e9)"><i class="ph-bold ph-download-simple"></i></div>
    <span class="qact-item__label">Topup</span>
  </a>
  <a href="/withdraw" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#fb923c,#f97316)"><i class="ph-bold ph-upload-simple"></i></div>
    <span class="qact-item__label">Tarik</span>
  </a>
  <a href="/history" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#a78bfa,#7c3aed)"><i class="ph-bold ph-receipt"></i></div>
    <span class="qact-item__label">Riwayat</span>
  </a>
  <a href="/missions" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"><i class="ph-bold ph-target"></i></div>
    <span class="qact-item__label">Misi</span>
  </a>
  <a href="/checkin" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#34d399,#059669)"><i class="ph-bold ph-calendar-check"></i></div>
    <span class="qact-item__label">Absen</span>
  </a>
  <a href="/redeem" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#f472b6,#db2777)"><i class="ph-bold ph-gift"></i></div>
    <span class="qact-item__label">Redeem</span>
  </a>
  <a href="/referral" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#c084fc,#9333ea)"><i class="ph-bold ph-users"></i></div>
    <span class="qact-item__label">Referral</span>
  </a>
  <a href="/panduan" class="qact-item">
    <div class="qact-item__icon" style="background:linear-gradient(135deg,#fbbf24,#d97706)"><i class="ph-bold ph-book-open"></i></div>
    <span class="qact-item__label">Panduan</span>
  </a>
</div>
<div class="qact-dots" id="qact-dots"></div>
</div>
<script>
(function(){
  const row  = document.getElementById('qact-row');
  const wrap = document.getElementById('qact-dots');
  // Show 3 dots representing scroll position thirds
  const DOTS = 3;
  for(let i=0;i<DOTS;i++){
    const d = document.createElement('div');
    d.className = 'qact-dot' + (i===0?' active':'');
    wrap.appendChild(d);
  }
  row.addEventListener('scroll', () => {
    const pct = row.scrollLeft / (row.scrollWidth - row.clientWidth);
    const idx = Math.min(DOTS-1, Math.round(pct * (DOTS-1)));
    wrap.querySelectorAll('.qact-dot').forEach((d,i) => d.classList.toggle('active', i===idx));
  });
})();
</script>

<!-- Dashboard Stats -->
<div style="background:var(--white);border:2.5px solid var(--ink);border-radius:14px;box-shadow:4px 4px 0 var(--ink);padding:14px;margin-bottom:16px;transition:transform 0.2s">
  <div style="font-size:14px;font-weight:900;margin-bottom:12px;display:flex;align-items:center;gap:6px">
    <i class="ph-fill ph-chart-pie-slice" style="font-size:18px;color:var(--brand)"></i> Statistik Kamu
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div style="display:flex;flex-direction:column;gap:4px">
      <div style="font-size:10px;font-weight:800;color:#666">Tontonan Hari Ini</div>
      <div style="font-size:14px;font-weight:900"><?= $watch_today ?> <span style="font-size:11px;color:#888">/ <?= $watch_limit ?></span></div>
      <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
      <div style="width:100%;height:6px;background:#eee;border-radius:4px;border:1.5px solid var(--ink);overflow:hidden;margin-top:2px">
        <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? 'var(--salmon)' : 'var(--green)' ?>;transition:width .3s"></div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:4px">
      <div style="font-size:10px;font-weight:800;color:#666">Kode Referralmu</div>
      <div style="display:flex;align-items:center;gap:6px">
        <div style="font-size:12px;font-weight:900;letter-spacing:1px;background:#f4f4f4;padding:4px 8px;border-radius:6px;border:1.5px solid #ccc;flex:1;text-align:center"><?= $user['referral_code'] ?></div>
        <button type="button" onclick="copyRef('<?= htmlspecialchars($user['referral_code']) ?>')" style="background:var(--ink);color:var(--white);border:none;border-radius:6px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer">
          <i class="ph-bold ph-copy"></i>
        </button>
      </div>
    </div>
  </div>
</div>
<div id="ref-toast" style="display:none;text-align:center;font-size:11px;font-weight:800;color:var(--green);margin-bottom:12px">✓ Kode berhasil disalin! Siap dibagikan!</div>

<?php if (setting($pdo, 'investment_enabled', '1') === '1'): ?>
<!-- Invest Banner -->
<div style="background:var(--ink);border-radius:14px;padding:16px;color:var(--white);position:relative;overflow:hidden;margin-bottom:16px;box-shadow:4px 4px 0 var(--peach);transition:transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
  <i class="ph-fill ph-trend-up" style="position:absolute;right:-10px;bottom:-10px;font-size:100px;opacity:0.1;animation:float 4s ease-in-out infinite"></i>
  <div style="display:flex;justify-content:space-between;align-items:center;position:relative;z-index:2">
    <div>
      <div style="font-size:10px;font-weight:900;color:var(--lime);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Profit Pasif</div>
      <h3 style="font-size:16px;font-weight:900;margin:0">Portal Investasi</h3>
    </div>
    <a href="/invest" class="btn btn--sm" style="background:var(--lime);color:var(--ink);border:2px solid var(--white);font-size:11px;font-weight:900">
      <i class="ph-bold ph-rocket"></i> Mulai
    </a>
  </div>
</div>
<?php endif; ?>

<?php if (setting($pdo, 'plinko_enabled', '1') === '1'): ?>
<!-- Plinko Banner -->
<div style="background:var(--yellow);border:2.5px solid var(--ink);border-radius:14px;padding:16px;color:var(--ink);position:relative;overflow:hidden;margin-bottom:16px;box-shadow:4px 4px 0 var(--ink);transition:transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
  <i class="ph-fill ph-game-controller" style="position:absolute;right:-10px;bottom:-10px;font-size:90px;opacity:0.15;animation:float 3s ease-in-out infinite reverse"></i>
  <div style="display:flex;justify-content:space-between;align-items:center;position:relative;z-index:2">
    <div>
      <div style="font-size:10px;font-weight:900;background:var(--brand);color:var(--white);padding:2px 6px;border-radius:4px;display:inline-block;margin-bottom:6px;border:1px solid var(--ink)">EVENT</div>
      <h3 style="font-size:16px;font-weight:900;margin:0">Plinko Arcade</h3>
    </div>
    <a href="/events" class="btn btn--sm" style="background:var(--brand);color:var(--white);border:2px solid var(--ink);font-size:11px;font-weight:900">
      <i class="ph-bold ph-play"></i> Main
    </a>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($notif_preview)): ?>
<!-- Notifications -->
<div style="margin-bottom:16px">
  <div class="section-header" style="margin-bottom:8px">
    <div class="section-title" style="font-size:14px;display:flex;align-items:center;gap:6px">
      <i class="ph-fill ph-bell-ringing" style="color:var(--brand)"></i> Notifikasi
      <?php if ($notif_unread > 0): ?>
      <span style="background:var(--brand);color:#fff;font-size:10px;font-weight:900;border-radius:10px;padding:2px 6px;border:1px solid var(--ink)"><?= $notif_unread > 9 ? '9+' : $notif_unread ?></span>
      <?php endif; ?>
    </div>
    <a href="/notifications" class="section-link">Lihat Semua →</a>
  </div>
  <?php
  $notif_colors = [
    'info'     => ['bg' => 'var(--sky)',     'icon' => 'ph-info'],
    'success'  => ['bg' => 'var(--lime)',    'icon' => 'ph-check-circle'],
    'warning'  => ['bg' => 'var(--peach)',   'icon' => 'ph-warning'],
    'alert'    => ['bg' => 'var(--salmon)',  'icon' => 'ph-warning-octagon'],
    'congrats' => ['bg' => 'var(--yellow)',  'icon' => 'ph-confetti'],
  ];
  foreach ($notif_preview as $nf):
    $nc = $notif_colors[$nf['type']] ?? $notif_colors['info'];
    // Extract icon if it exists, else default. The original DB might have emojis, let's just use the neo icon if DB has an emoji.
    $ni = $nc['icon'];
  ?>
  <div style="display:flex;align-items:center;gap:10px;background:<?= $nc['bg'] ?>;border:2.5px solid var(--ink);box-shadow:4px 4px 0 var(--ink);border-radius:12px;padding:10px;margin-bottom:12px">
    <div style="width:36px;height:36px;border-radius:10px;background:#fff;border:2px solid var(--ink);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:2px 2px 0 var(--ink)">
      <i class="ph-fill <?= $ni ?>" style="font-size:20px;color:var(--ink)"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:900;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px"><?= htmlspecialchars($nf['title']) ?></div>
      <div style="font-size:11px;color:#444;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700"><?= htmlspecialchars($nf['message']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($watch_today >= $watch_limit): ?>
<div class="alert alert--warn" style="margin-bottom:16px;font-size:12px;padding:10px;border-radius:10px;animation:pulse-glow 2s infinite">
  <i class="ph-bold ph-warning-circle" style="font-size:16px"></i> Limit tonton hari ini udah habis ya (<?= $watch_limit ?>). <a href="/upgrade" style="color:inherit;font-weight:800;text-decoration:underline">Yuk upgrade sekarang!</a>
</div>
<?php endif; ?>

<!-- Horizontal Video Scroll -->
<style>
.video-scroll { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px; margin: 0 -14px; padding-left: 14px; padding-right: 14px; scroll-snap-type: x mandatory; scrollbar-width: none; }
.video-scroll::-webkit-scrollbar { display: none; }
.v-card { flex: 0 0 220px; scroll-snap-align: center; text-decoration: none; display: flex; flex-direction: column; background: var(--white); border: 2.5px solid var(--ink); border-radius: 12px; overflow: hidden; box-shadow: 3px 3px 0 var(--ink); }
.v-card__thumb { position: relative; aspect-ratio: 16/9; background: #000; border-bottom: 2px solid var(--ink); }
.v-card__thumb img { width: 100%; height: 100%; object-fit: cover; opacity: 0.9; transition: opacity 0.2s; }
.v-card:hover .v-card__thumb img { opacity: 1; }
.v-card__badge { position: absolute; top: 6px; right: 6px; background: var(--brand); color: #fff; font-size: 10px; font-weight: 900; padding: 2px 6px; border-radius: 6px; border: 1.5px solid var(--ink); }
.v-card__play { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
.v-card__play i { font-size: 36px; color: #fff; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5)); transition: transform 0.2s; }
.v-card:hover .v-card__play i { transform: scale(1.1); }
.v-card__info { padding: 10px; display: flex; flex-direction: column; gap: 4px; }
.v-card__title { font-size: 12px; font-weight: 800; color: var(--ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; height: 31px; }
.v-card__meta { display: flex; align-items: center; justify-content: space-between; font-size: 10px; font-weight: 800; color: #555; }
</style>

<div class="section-header" style="margin-bottom:12px">
  <div class="section-title" style="display:flex;align-items:center;gap:6px">
    <i class="ph-fill ph-video-camera" style="color:var(--brand)"></i> Video Tersedia
  </div>
  <a href="/videos" class="section-link">Lihat Semua →</a>
</div>

<?php if (empty($videos)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="empty-state">
    <i class="ph-fill ph-check-circle" style="font-size:36px;color:var(--green)"></i>
    <p style="font-size:13px;font-weight:800;margin-top:6px;color:var(--ink)">Mantap! Semua video sudah ditonton hari ini.</p>
  </div>
</div>
<?php else: ?>
<div class="video-scroll" style="margin-bottom:16px">
  <?php foreach ($videos as $v): ?>
  <a href="/watch?id=<?= $v['id'] ?>" class="v-card">
    <div class="v-card__thumb">
      <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
      <div class="v-card__play"><i class="ph-fill ph-play-circle"></i></div>
      <div class="v-card__badge">+<?= format_rp((float)$v['reward_amount']) ?></div>
    </div>
    <div class="v-card__info">
      <div class="v-card__title"><?= htmlspecialchars($v['title']) ?></div>
      <div class="v-card__meta">
        <span style="color:var(--green);display:flex;align-items:center;gap:2px"><i class="ph-bold ph-coins"></i> <?= format_rp((float)$v['reward_amount']) ?></span>
        <span style="display:flex;align-items:center;gap:2px"><i class="ph-bold ph-clock"></i> <?= $v['watch_duration'] ?>s</span>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Recent Activity -->
<?php if (!empty($history)): ?>
<div class="section-header" style="margin-bottom:10px">
  <div class="section-title" style="display:flex;align-items:center;gap:6px">
    <i class="ph-fill ph-clock-counter-clockwise" style="color:var(--blue)"></i> Aktivitas Terbaru
  </div>
</div>
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="padding:4px 0">
    <?php foreach ($history as $h): ?>
    <div class="list-item" style="padding:8px 14px;border-bottom:1px solid #eee">
      <div class="list-item__icon" style="background:var(--lime);width:32px;height:32px;font-size:16px;border:2px solid var(--ink);border-radius:8px">
        <i class="ph-bold ph-monitor-play" style="color:var(--ink)"></i>
      </div>
      <div class="list-item__body">
        <div class="list-item__title" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px"><?= htmlspecialchars($h['title']) ?></div>
        <div class="list-item__sub" style="font-size:10px;display:flex;align-items:center;gap:4px">
          <i class="ph-bold ph-calendar-blank"></i> <?= date('d M H:i', strtotime($h['watched_at'])) ?>
        </div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount" style="font-size:12px;color:var(--green);font-weight:900;display:flex;align-items:center;gap:2px">
          +<?= format_rp((float)$h['reward_given']) ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Popup settings from DB
$popup_enabled      = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title        = setting($pdo, 'popup_title',   'Hei, sudah baca panduan?');
$popup_body         = setting($pdo, 'popup_body',    'Biar makin lancar dapat reward, yuk baca dulu cara kerja NontonKuy! Dari cara tonton, jenis saldo, sampai tips withdraw.');
$popup_cta_text     = setting($pdo, 'popup_cta_text', 'Baca Panduan');
$popup_cta_url      = setting($pdo, 'popup_cta_url',  '/panduan');
$popup_delay        = max(0, (int) setting($pdo, 'popup_delay', '1500'));
$popup_reset_hours  = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));
?>
<?php if ($popup_enabled): ?>
<!-- Popup Panduan -->
<div id="guide-popup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-end;justify-content:center;padding-bottom:0">
  <div style="background:var(--white);border:2.5px solid var(--ink);border-bottom:none;border-radius:24px 24px 0 0;box-shadow:0 -6px 0 var(--ink);padding:24px 20px 28px;max-width:480px;width:100%;transform:translateY(100%);transition:transform .3s cubic-bezier(0.175, 0.885, 0.32, 1.275);position:relative">
    <button onclick="closePopup()" style="position:absolute;top:16px;right:16px;background:var(--ink);color:#fff;border:none;width:30px;height:30px;border-radius:50%;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer"><i class="ph-bold ph-x"></i></button>
    
    <div style="width:60px;height:60px;background:var(--yellow);border:2.5px solid var(--ink);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 16px;box-shadow:3px 3px 0 var(--ink);transform:rotate(-5deg)">
      <i class="ph-fill ph-book-open" style="color:var(--ink)"></i>
    </div>
    
    <h3 style="font-size:18px;font-weight:900;text-align:center;margin:0 0 10px"><?= htmlspecialchars($popup_title) ?></h3>
    <p style="font-size:13px;line-height:1.5;color:#444;text-align:center;margin:0 0 20px;font-weight:700">
      <?= nl2br(htmlspecialchars($popup_body)) ?>
    </p>
    
    <div style="display:flex;flex-direction:column;gap:10px">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="btn btn--primary" style="font-size:14px;padding:14px;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:6px">
        <i class="ph-bold ph-book-bookmark"></i> <?= htmlspecialchars($popup_cta_text) ?>
      </a>
      <button type="button" onclick="closePopup()" class="btn btn--ghost" style="font-size:13px;font-weight:800;color:#666">Nanti Saja</button>
    </div>
  </div>
</div>

<script>
function closePopup() {
  const p = document.getElementById('guide-popup');
  const c = p.querySelector('div');
  c.style.transform = 'translateY(100%)';
  setTimeout(() => p.style.display = 'none', 300);
  // Simpan state
  try {
    const data = { ts: Date.now() };
    localStorage.setItem('tonton_popup_seen', JSON.stringify(data));
  } catch(e){}
}

document.addEventListener('DOMContentLoaded', () => {
  const p = document.getElementById('guide-popup');
  if(!p) return;
  const c = p.querySelector('div');
  const resetMs = <?= $popup_reset_hours ?> * 3600000;
  
  try {
    const raw = localStorage.getItem('tonton_popup_seen');
    if (raw) {
      const data = JSON.parse(raw);
      if (resetMs > 0 && (Date.now() - data.ts) > resetMs) {
        // expired, tunjukkan lagi
      } else {
        return; // jangan tampilkan
      }
    }
  } catch(e){}

  setTimeout(() => {
    p.style.display = 'flex';
    // Flush layout
    p.offsetHeight;
    c.style.transform = 'translateY(0)';
  }, <?= $popup_delay ?>);
});
</script>
<?php endif; ?>

<script>
function copyRef(code) {
  navigator.clipboard.writeText(code).then(()=>{
    const toast = document.getElementById('ref-toast');
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 2000);
  }).catch(()=>{
    alert("Gagal menyalin: " + code);
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
