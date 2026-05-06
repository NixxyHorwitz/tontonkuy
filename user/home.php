<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Available videos (not yet watched today)
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
     ORDER BY wh.watched_at DESC LIMIT 5"
);
$history->execute([$user['id']]);
$history = $history->fetchAll();

// Membership name
$membership_name = 'Free';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn() ?: 'Free';
}

$pageTitle  = 'Beranda — TontonKuy';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<!-- Hero balance -->
<div class="hero-card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
    <div class="hero-card__label">👋 Hai, <?= htmlspecialchars($user['username']) ?>!</div>
    <span class="badge badge--neutral">⭐ <?= $membership_name ?></span>
  </div>
  <div class="dual-balance">
    <div class="dual-balance__item dual-balance__item--wd">
      <div class="dual-balance__label">💸 Saldo Penarikan</div>
      <div class="dual-balance__val"><?= format_rp((float)$user['balance_wd']) ?></div>
      <div class="dual-balance__hint">Bisa ditarik</div>
    </div>
    <div class="dual-balance__item dual-balance__item--dep">
      <div class="dual-balance__label">💳 Saldo Deposit</div>
      <div class="dual-balance__val"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div class="dual-balance__hint">Untuk upgrade</div>
    </div>
  </div>
  <div class="hero-card__sub" style="margin-top:8px">Total reward: <?= format_rp((float)$user['total_earned']) ?></div>
  <div class="hero-card__actions">
    <a href="/deposit" class="hero-card__btn">⬆️ Deposit</a>
    <a href="/withdraw" class="hero-card__btn">⬇️ WD</a>
    <a href="/upgrade" class="hero-card__btn">👑 Upgrade</a>
    <a href="/checkin" class="hero-card__btn">📅 Check-in</a>
  </div>
</div>

<!-- Stats row -->
<div class="stat-row">
  <div class="stat-mini">
    <div class="stat-mini__val"><?= $watch_today ?><span style="font-size:12px;color:var(--text3)">/<?= $watch_limit ?></span></div>
    <div class="stat-mini__lbl">Tonton Hari Ini</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:14px"><?= count($videos) ?></div>
    <div class="stat-mini__lbl">Video Tersedia</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px;letter-spacing:2px"><?= $user['referral_code'] ?></div>
    <div class="stat-mini__lbl">Kode Referral</div>
  </div>
</div>

<?php if ($watch_today >= $watch_limit): ?>
<div class="alert alert--warn" style="margin-top:16px">
  ⚠️ Limit tonton hari ini sudah habis (<?= $watch_limit ?>×). Reset besok atau
  <a href="/upgrade" style="color:inherit;font-weight:800">upgrade paket →</a>
</div>
<?php endif; ?>

<!-- Available videos -->
<div class="section-header" style="margin-top:20px">
  <div class="section-title">🎬 Video Tersedia</div>
  <a href="/videos" class="section-link">Lihat semua →</a>
</div>

<?php if (empty($videos)): ?>
<div class="card">
  <div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
    <p>Semua video sudah kamu tonton hari ini! 🎉</p>
  </div>
</div>
<?php else: ?>
<?php foreach ($videos as $v): ?>
<a href="/watch?id=<?= $v['id'] ?>" class="video-card" style="margin-bottom:12px">
  <div class="video-card__thumb-wrap">
    <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>"
         loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
    <div class="video-card__play"><div class="video-card__play-btn">
      <svg width="18" height="18" fill="#fff" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
    </div></div>
    <div class="video-card__badge">+<?= format_rp((float)$v['reward_amount']) ?></div>
  </div>
  <div class="video-card__body">
    <div class="video-card__title"><?= htmlspecialchars($v['title']) ?></div>
    <div class="video-card__meta">
      <div class="video-card__reward">
        <svg width="11" height="11" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
        <?= format_rp((float)$v['reward_amount']) ?>
      </div>
      <div class="video-card__duration">⏱ <?= $v['watch_duration'] ?>s</div>
    </div>
  </div>
</a>
<?php endforeach; ?>
<?php endif; ?>

<!-- Recent activity -->
<?php if (!empty($history)): ?>
<div class="section-header" style="margin-top:20px">
  <div class="section-title">📋 Aktivitas Terbaru</div>
</div>
<div class="card">
  <div class="card__body">
    <?php foreach ($history as $h): ?>
    <div class="list-item">
      <div class="list-item__icon" style="background:var(--lime)">🎬</div>
      <div class="list-item__body">
        <div class="list-item__title"><?= htmlspecialchars($h['title']) ?></div>
        <div class="list-item__sub"><?= date('d M Y H:i', strtotime($h['watched_at'])) ?></div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount list-item__amount--green">+<?= format_rp((float)$h['reward_given']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
