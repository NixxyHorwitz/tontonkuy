<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// All active videos with watch status for today
$videos = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh
        WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today
     FROM videos v
     WHERE v.is_active=1
     ORDER BY v.sort_order ASC, v.id DESC"
);
$videos->execute([$user['id']]);
$videos = $videos->fetchAll();

$pageTitle  = 'Tonton Video — TontonKuy';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>🎬 Semua Video</h1>
  <p>Tonton video untuk kumpulkan reward</p>
</div>

<!-- Progress bar -->
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <span style="font-size:13px;font-weight:700">Progres Tonton Hari Ini</span>
      <span style="font-size:13px;font-weight:800;color:var(--brand)"><?= $watch_today ?>/<?= $watch_limit ?></span>
    </div>
    <div style="background:var(--bg);border-radius:20px;height:8px;overflow:hidden">
      <div style="background:linear-gradient(90deg,var(--brand),var(--brand2));height:100%;width:<?= min(100, round($watch_today/$watch_limit*100)) ?>%;border-radius:20px;transition:width .5s"></div>
    </div>
    <?php if ($watch_today >= $watch_limit): ?>
    <div style="font-size:12px;color:var(--text3);margin-top:6px">
      Limit tercapai! <a href="/upgrade" style="color:var(--brand);font-weight:700">Upgrade untuk lebih banyak →</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($videos)): ?>
<div class="card"><div class="empty-state">
  <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
  <p>Belum ada video tersedia.</p>
</div></div>
<?php else: ?>
<?php foreach ($videos as $v):
  $done    = (bool)$v['watched_today'];
  $blocked = !$done && ($watch_today >= $watch_limit);
?>
<div style="position:relative;margin-bottom:12px">
  <a href="<?= ($done||$blocked)?'#':'/watch?id='.$v['id'] ?>"
     class="video-card <?= ($done||$blocked)?'video-card--disabled':'' ?>"
     style="<?= ($done||$blocked)?'opacity:.7;pointer-events:none;text-decoration:none':'' ?>">
    <div class="video-card__thumb-wrap">
      <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>"
           loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
      <div class="video-card__play"><div class="video-card__play-btn" style="<?= $done?'background:var(--green)':'' ?>">
        <?php if ($done): ?>
          <svg width="18" height="18" fill="#fff" viewBox="0 0 24 24" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?php else: ?>
          <svg width="18" height="18" fill="#1A1A1A" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        <?php endif; ?>
      </div></div>
      <div class="video-card__badge" style="<?= $done?'background:var(--green);color:#fff':'' ?>">
        <?= $done ? '✅ Selesai' : '+'.format_rp((float)$v['reward_amount']) ?>
      </div>
    </div>
    <div class="video-card__body">
      <div class="video-card__title"><?= htmlspecialchars($v['title']) ?></div>
      <div class="video-card__meta">
        <div class="video-card__reward" style="<?= $done?'background:var(--green-soft);color:var(--green)':'' ?>">
          <?= $done ? '✓ Reward diterima' : format_rp((float)$v['reward_amount']) ?>
        </div>
        <div class="video-card__duration">⏱ <?= $v['watch_duration'] ?>s · 👁 <?= number_format((int)$v['total_watches']) ?>x</div>
      </div>
    </div>
  </a>
  <?php if (!$done && !$blocked): ?>
  <a href="/watch?id=<?= $v['id'] ?>" style="position:absolute;inset:0;z-index:2"></a>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
