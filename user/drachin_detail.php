<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$bookId = $_GET['id'] ?? '';
if (empty($bookId)) {
    redirect('/videos');
}

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Fetch Episodes
$episodes = [];
$api_url = "https://api.sansekai.my.id/api/dramabox/allepisode?bookId=" . urlencode($bookId);
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$res = curl_exec($ch);
curl_close($ch);
if ($res) {
    $decoded = json_decode($res, true);
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $episodes = $decoded['data'];
    } elseif (is_array($decoded)) {
        $episodes = $decoded;
    }
}

// Find which episodes the user has watched today
$watched_eps = [];
$stmt = $pdo->prepare("
    SELECT v.youtube_id 
    FROM watch_history wh
    JOIN videos v ON wh.video_id = v.id
    WHERE wh.user_id = ? AND DATE(wh.watched_at) = CURDATE()
    AND v.youtube_id LIKE ?
");
$stmt->execute([$user['id'], "db:{$bookId}:%"]);
while ($row = $stmt->fetch()) {
    $parts = explode(':', $row['youtube_id']);
    if (count($parts) === 3) {
        $watched_eps[] = (int)$parts[2];
    }
}

$pageTitle  = 'Pilih Episode — TontonKuy';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar" style="display:flex;align-items:center;gap:12px">
  <a href="/videos" style="color:var(--text1);text-decoration:none">
    <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
  </a>
  <div>
    <h1>Pilih Episode</h1>
    <p>Pilih episode yang ingin ditonton</p>
  </div>
</div>

<div class="card" style="margin-bottom:12px">
  <div class="card__body" style="padding:10px 14px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <span style="font-size:12px;font-weight:700">Progres Tonton Hari Ini</span>
      <span style="font-size:12px;font-weight:800;color:var(--brand)"><?= $watch_today ?>/<?= $watch_limit ?></span>
    </div>
    <div style="background:var(--bg);border-radius:20px;height:5px;overflow:hidden">
      <div style="background:linear-gradient(90deg,var(--brand),var(--brand2));height:100%;width:<?= min(100, round($watch_today/$watch_limit*100)) ?>%;border-radius:20px;transition:width .5s"></div>
    </div>
  </div>
</div>

<?php if (empty($episodes)): ?>
<div class="card"><div class="empty-state">
  <p>Episode tidak ditemukan atau server sibuk.</p>
</div></div>
<?php else: ?>

<style>
.ep-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ep-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  padding: 12px 16px;
  border-radius: 8px;
  border: 1px solid var(--border);
  text-decoration: none;
  color: var(--text1);
  font-weight: 700;
  font-size: 14px;
}
.ep-item:active {
  background: var(--bg);
}
.ep-item--done {
  opacity: 0.6;
}
.ep-play {
  width: 32px;
  height: 32px;
  background: rgba(99,102,241,.1);
  color: var(--brand);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ep-item--done .ep-play {
  background: rgba(34,197,94,.1);
  color: #22c55e;
}
</style>

<div class="ep-list">
<?php 
// Episodes might be an array of objects
foreach ($episodes as $ep):
  $idx = $ep['chapterIndex'] ?? 0;
  $name = $ep['chapterName'] ?? "EP {$idx}";
  $done = in_array((int)$idx, $watched_eps);
  $blocked = !$done && ($watch_today >= $watch_limit);
  $href = ($done || $blocked) ? 'javascript:void(0)' : '/watch?bookId='.urlencode($bookId).'&ep='.urlencode((string)$idx);
?>
  <a href="<?= $href ?>" class="ep-item <?= $done ? 'ep-item--done' : '' ?>" <?= ($done || $blocked) ? 'style="pointer-events:none"' : '' ?>>
    <div>
      <?= htmlspecialchars($name) ?>
      <?php if ($done): ?>
        <span style="font-size:10px;color:#22c55e;margin-left:8px">✓ Ditonton</span>
      <?php endif; ?>
    </div>
    <div class="ep-play">
      <?php if ($done): ?>
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      <?php else: ?>
        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      <?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
