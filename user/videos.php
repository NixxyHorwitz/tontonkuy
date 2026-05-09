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
<div class="card" style="margin-bottom:12px">
  <div class="card__body" style="padding:10px 14px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <span style="font-size:12px;font-weight:700">Progres Hari Ini</span>
      <span style="font-size:12px;font-weight:800;color:var(--brand)"><?= $watch_today ?>/<?= $watch_limit ?></span>
    </div>
    <div style="background:var(--bg);border-radius:20px;height:5px;overflow:hidden">
      <div style="background:linear-gradient(90deg,var(--brand),var(--brand2));height:100%;width:<?= min(100, round($watch_today/$watch_limit*100)) ?>%;border-radius:20px;transition:width .5s"></div>
    </div>
    <?php if ($watch_today >= $watch_limit): ?>
    <div style="font-size:11px;color:var(--text3);margin-top:5px">
      Limit tercapai! <a href="/upgrade" style="color:var(--brand);font-weight:700">Upgrade →</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($videos)): ?>
<div class="card"><div class="empty-state">
  <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
  <p>Belum ada video tersedia.</p>
</div></div>
<?php else: ?>

<style>
.vgrid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.vcard{border-radius:10px;overflow:hidden;background:var(--card);text-decoration:none;display:block;transition:transform .15s;border:1px solid var(--border,rgba(255,255,255,.06))}
.vcard:active{transform:scale(.97)}
.vcard--done{opacity:.6}
.vcard__thumb{position:relative;aspect-ratio:16/9;overflow:hidden}
.vcard__thumb img{width:100%;height:100%;object-fit:cover;display:block}
.vcard__badge{position:absolute;bottom:4px;right:4px;font-size:10px;font-weight:700;padding:2px 6px;border-radius:5px;background:var(--brand);color:#fff;line-height:1.4;letter-spacing:.2px}
.vcard__badge--done{background:var(--green,#22c55e)}
.vcard__play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.vcard__play-icon{width:28px;height:28px;border-radius:50%;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center}
.vcard__body{padding:6px 8px 8px}
.vcard__title{font-size:11px;font-weight:700;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:4px;color:var(--text1)}
.vcard__meta{display:flex;align-items:center;justify-content:space-between;gap:4px}
.vcard__reward{font-size:10px;font-weight:700;color:var(--brand);background:rgba(99,102,241,.1);padding:2px 6px;border-radius:4px;white-space:nowrap}
.vcard__reward--done{color:var(--green,#22c55e);background:rgba(34,197,94,.1)}
.vcard__dur{font-size:10px;color:var(--text3);white-space:nowrap}
</style>

<div class="vgrid">
<?php foreach ($videos as $v):
  $done    = (bool)$v['watched_today'];
  $blocked = !$done && ($watch_today >= $watch_limit);
  $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id='.$v['id'];
?>
<a href="<?= $href ?>" class="vcard <?= $done ? 'vcard--done' : '' ?>" <?= ($done||$blocked) ? 'style="pointer-events:none"' : '' ?>>
  <div class="vcard__thumb">
    <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>"
         loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
    <div class="vcard__play">
      <div class="vcard__play-icon">
        <?php if ($done): ?>
          <svg width="13" height="13" fill="#fff" viewBox="0 0 24 24" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?php else: ?>
          <svg width="13" height="13" fill="#fff" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        <?php endif; ?>
      </div>
    </div>
    <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
      <?= $done ? '✓ Done' : '+'.format_rp((float)$v['reward_amount']) ?>
    </div>
  </div>
  <div class="vcard__body">
    <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
    <div class="vcard__meta">
      <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>"><?= $done ? '✓ Claimed' : format_rp((float)$v['reward_amount']) ?></span>
      <span class="vcard__dur">⏱ <?= $v['watch_duration'] ?>s</span>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
