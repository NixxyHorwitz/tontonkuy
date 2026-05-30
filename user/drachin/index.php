<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/auth/guard.php';

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Valid providers
$valid_providers = [
    'dramabox', 'pinedrama', 'reelshort', 'shortmax', 'goodshort',
    'freereels', 'dramanova', 'anime', 'komik', 'moviebox'
];
$provider = $_GET['provider'] ?? 'dramabox';
if (!in_array($provider, $valid_providers)) {
    $provider = 'dramabox';
}

$q = trim($_GET['q'] ?? '');

// Fetch Drachin from Sansekai API
$dramas = [];
if ($q !== '') {
    $api_url = "https://api.sansekai.my.id/api/{$provider}/search?query=" . urlencode($q);
} else {
    $api_url = "https://api.sansekai.my.id/api/{$provider}/foryou";
}

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$res = curl_exec($ch);
curl_close($ch);

if ($res) {
    $decoded = json_decode($res, true);
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        if (isset($decoded['data']['items']) && is_array($decoded['data']['items'])) {
            $dramas = $decoded['data']['items'];
        } else {
            $dramas = $decoded['data'];
        }
    } elseif (is_array($decoded) && !isset($decoded['error'])) {
        $dramas = $decoded;
    }
}

$pageTitle  = 'Nonton Drachin — TontonKuy';
$activePage = 'drachin';
require dirname(__DIR__, 2) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>🎬 Nonton Drachin</h1>
  <p>Pilih provider, cari judul, dan mulai kumpulkan reward!</p>
</div>

<!-- Progress bar -->
<div class="card" style="margin-bottom:12px">
  <div class="card__body" style="padding:10px 14px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <span style="font-size:12px;font-weight:700">Progres Tonton Hari Ini</span>
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

<!-- Search & Filter -->
<div class="card" style="margin-bottom:12px">
  <div class="card__body" style="padding:12px">
    <form method="GET" action="/drachin" style="display:flex;flex-direction:column;gap:8px">
      <select name="provider" class="form-control" style="font-size:14px;padding:8px;text-transform:capitalize;" onchange="this.form.submit()">
        <?php 
        $labels = [
            'dramabox' => 'DramaBox', 'pinedrama' => 'PineDrama', 'reelshort' => 'ReelShort',
            'shortmax' => 'ShortMax', 'goodshort' => 'GoodShort', 'freereels' => 'FreeReels',
            'dramanova' => 'DramaNova', 'anime' => 'Anime', 'komik' => 'Komik', 'moviebox' => 'MovieBox'
        ];
        foreach ($valid_providers as $vp): 
        ?>
          <option value="<?= $vp ?>" <?= $provider === $vp ? 'selected' : '' ?>><?= $labels[$vp] ?? ucfirst($vp) ?></option>
        <?php endforeach; ?>
      </select>
      
      <div style="display:flex;gap:8px">
        <input type="text" name="q" class="form-control" placeholder="Cari judul drama..." value="<?= htmlspecialchars($q) ?>" style="font-size:14px;padding:8px">
        <button type="submit" class="btn btn--brand btn--sm">Cari</button>
      </div>
    </form>
  </div>
</div>

<?php if (empty($dramas)): ?>
<div class="card"><div class="empty-state">
  <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
  <p>Belum ada drama tersedia atau hasil pencarian kosong.</p>
</div></div>
<?php else: ?>

<style>
.vgrid{display:grid;grid-template-columns:repeat(3, 1fr);gap:8px}
.vcard{border-radius:8px;overflow:hidden;background:var(--card);text-decoration:none;display:block;transition:transform .15s;border:1px solid var(--border,rgba(255,255,255,.06))}
.vcard:active{transform:scale(.97)}
.vcard__thumb{position:relative;aspect-ratio:3/4;overflow:hidden}
.vcard__thumb img{width:100%;height:100%;object-fit:cover;object-position:top;display:block}
.vcard__badge{position:absolute;bottom:4px;right:4px;font-size:9px;font-weight:800;padding:2px 4px;border-radius:4px;background:var(--brand);color:#fff;}
.vcard__play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.vcard__play-icon{width:28px;height:28px;border-radius:50%;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center}
.vcard__body{padding:6px}
.vcard__title{font-size:10px;font-weight:800;line-height:1.3;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;color:var(--text1);height:39px}
</style>

<div class="vgrid">
<?php foreach ($dramas as $v):
  $blocked = ($watch_today >= $watch_limit);
  $bId = $v['bookId'] ?? $v['key'] ?? $v['id'] ?? '';
  // cover is coverWap for foryou, or cover for search
  $cover = $v['coverWap'] ?? $v['cover'] ?? $v['thumbnail'] ?? $v['image'] ?? '';
  $title = $v['bookName'] ?? $v['title'] ?? $v['name'] ?? '';
  $eps = $v['chapterCount'] ?? $v['episode_count'] ?? $v['episodes'] ?? 0;
  
  $href = $blocked ? 'javascript:void(0)' : '/drachin/detail?provider='.urlencode($provider).'&id='.urlencode((string)$bId);
?>
<a href="<?= $href ?>" class="vcard" <?= $blocked ? 'style="pointer-events:none;opacity:.6"' : '' ?>>
  <div class="vcard__thumb">
    <img src="<?= htmlspecialchars($cover) ?>" alt="" loading="lazy">
    <div class="vcard__play">
      <div class="vcard__play-icon">
        <svg width="12" height="12" fill="#fff" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
      </div>
    </div>
    <?php if ($eps > 0): ?>
    <div class="vcard__badge">
      <?= $eps ?> EP
    </div>
    <?php endif; ?>
  </div>
  <div class="vcard__body">
    <div class="vcard__title"><?= htmlspecialchars($title) ?></div>
  </div>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
