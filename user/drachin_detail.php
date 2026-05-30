<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$bookId = $_GET['id'] ?? '';
$provider = $_GET['provider'] ?? 'dramabox';
if (empty($bookId)) {
    redirect('/drachin');
}

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Fetch Episodes
$episodes = [];
// API Configuration Map
$api_config = [
    'dramabox'  => ['ep' => 'allepisode', 'param' => 'bookId'],
    'pinedrama' => ['ep' => 'detail', 'param' => 'collection_id'],
    'reelshort' => ['ep' => 'allepisode', 'param' => 'bookId'],
    'shortmax'  => ['ep' => 'allepisode', 'param' => 'shortPlayId'], // or detail
    'goodshort' => ['ep' => 'allepisode', 'param' => 'bookId'],
    'freereels' => ['ep' => 'detailAndAllEpisode', 'param' => 'key'],
    'dramanova' => ['ep' => 'detail', 'param' => 'dramaId'],
    'anime'     => ['ep' => 'detail', 'param' => 'urlId'],
    'komik'     => ['ep' => 'chapterlist', 'param' => 'manga_id'],
    'moviebox'  => ['ep' => 'detail', 'param' => 'subjectId']
];

$conf = $api_config[$provider] ?? ['ep' => 'allepisode', 'param' => 'bookId'];
$api_url = "https://api.sansekai.my.id/api/{$provider}/{$conf['ep']}?{$conf['param']}=" . urlencode($bookId);

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$res = curl_exec($ch);
curl_close($ch);
if ($res) {
    $decoded = json_decode($res, true);
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        if (isset($decoded['data']['items']) && is_array($decoded['data']['items'])) {
            $episodes = $decoded['data']['items'];
        } else {
            $episodes = $decoded['data'];
        }
    } elseif (is_array($decoded) && !isset($decoded['error'])) {
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
  <a href="/drachin" style="color:var(--text1);text-decoration:none">
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
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
}
.ep-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #fff;
  padding: 12px 4px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  text-decoration: none;
  color: var(--text1);
  font-weight: 800;
  font-size: 14px;
  transition: transform 0.1s, background 0.2s;
  position: relative;
}
.ep-item:active {
  transform: scale(0.95);
  background: var(--bg);
}
.ep-item--done {
  background: rgba(34,197,94,.08);
  border-color: rgba(34,197,94,.4);
  color: #16a34a;
}
.ep-item--blocked {
  opacity: 0.5;
  pointer-events: none;
}
.ep-num {
  font-size: 16px;
  line-height: 1;
}
.ep-icon {
  position: absolute;
  top: -4px;
  right: -4px;
  width: 18px;
  height: 18px;
  background: #22c55e;
  color: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<div class="ep-list">
<?php 
// Episodes might be an array of objects
foreach ($episodes as $ep):
  $idx = $ep['chapterIndex'] ?? $ep['index'] ?? $ep['episode'] ?? 0;
  $done = in_array((int)$idx, $watched_eps);
  $blocked = !$done && ($watch_today >= $watch_limit);
  $href = ($done || $blocked) ? 'javascript:void(0)' : '/watch?provider='.urlencode($provider).'&bookId='.urlencode($bookId).'&ep='.urlencode((string)$idx);
  
  $classes = ['ep-item'];
  if ($done) $classes[] = 'ep-item--done';
  if ($blocked) $classes[] = 'ep-item--blocked';
?>
  <a href="<?= $href ?>" class="<?= implode(' ', $classes) ?>">
    <div class="ep-num"><?= $idx ?></div>
    <?php if ($done): ?>
      <div class="ep-icon">
        <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
    <?php endif; ?>
  </a>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
