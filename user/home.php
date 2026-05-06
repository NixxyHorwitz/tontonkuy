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
     ORDER BY wh.watched_at DESC LIMIT 4"
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

<!-- Hero balance (compact) -->
<div class="hero-card" style="padding:14px 16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
    <div class="hero-card__label" style="font-size:13px">👋 <?= htmlspecialchars($user['username']) ?></div>
    <span class="badge badge--neutral" style="font-size:11px">⭐ <?= $membership_name ?></span>
  </div>
  <div class="dual-balance" style="gap:8px">
    <div class="dual-balance__item dual-balance__item--wd">
      <div class="dual-balance__label" style="font-size:10px">💸 WD</div>
      <div class="dual-balance__val" style="font-size:18px"><?= format_rp((float)$user['balance_wd']) ?></div>
    </div>
    <div class="dual-balance__item dual-balance__item--dep">
      <div class="dual-balance__label" style="font-size:10px">💳 Deposit</div>
      <div class="dual-balance__val" style="font-size:18px"><?= format_rp((float)$user['balance_dep']) ?></div>
    </div>
  </div>
  <div class="hero-card__actions" style="margin-top:10px;gap:6px">
    <a href="/deposit"  class="hero-card__btn">⬆️ Deposit</a>
    <a href="/withdraw" class="hero-card__btn">⬇️ WD</a>
    <a href="/upgrade"  class="hero-card__btn">👑 Upgrade</a>
    <a href="/checkin"  class="hero-card__btn">📅 Check-in</a>
  </div>
</div>

<!-- Stats row (compact) -->
<div class="stat-row" style="margin-top:10px">
  <div class="stat-mini">
    <div class="stat-mini__val"><?= $watch_today ?><span style="font-size:11px;color:var(--text3)">/<?= $watch_limit ?></span></div>
    <div class="stat-mini__lbl">Tonton</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:14px"><?= count($videos) ?></div>
    <div class="stat-mini__lbl">Tersedia</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini__val" style="font-size:12px;letter-spacing:2px"><?= $user['referral_code'] ?></div>
    <div class="stat-mini__lbl">Referral</div>
  </div>

</div>

<?php if ($watch_today >= $watch_limit): ?>
<div class="alert alert--warn" style="margin-top:10px;font-size:12px;padding:8px 12px">
  ⚠️ Limit hari ini habis (<?= $watch_limit ?>×). <a href="/upgrade" style="color:inherit;font-weight:800">Upgrade →</a>
</div>
<?php endif; ?>

<!-- Available videos -->
<div class="section-header" style="margin-top:16px">
  <div class="section-title">🎬 Video Tersedia</div>
  <a href="/videos" class="section-link">Lihat semua →</a>
</div>

<?php if (empty($videos)): ?>
<div class="card">
  <div class="empty-state">
    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
    <p style="font-size:13px">Semua video sudah ditonton hari ini! 🎉</p>
  </div>
</div>
<?php else: ?>
<?php foreach ($videos as $v): ?>
<a href="/watch?id=<?= $v['id'] ?>" class="video-card" style="margin-bottom:8px">
  <div class="video-card__thumb-wrap">
    <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>"
         loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
    <div class="video-card__play"><div class="video-card__play-btn">
      <svg width="16" height="16" fill="#fff" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
    </div></div>
    <div class="video-card__badge">+<?= format_rp((float)$v['reward_amount']) ?></div>
  </div>
  <div class="video-card__body">
    <div class="video-card__title" style="font-size:13px"><?= htmlspecialchars($v['title']) ?></div>
    <div class="video-card__meta">
      <div class="video-card__reward">
        <svg width="10" height="10" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
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
<div class="section-header" style="margin-top:14px">
  <div class="section-title">📋 Aktivitas Terbaru</div>
  <a href="/history" class="section-link">Lihat semua →</a>
</div>
<div class="card">
  <div class="card__body" style="padding:4px 0">
    <?php foreach ($history as $h): ?>
    <div class="list-item" style="padding:8px 14px">
      <div class="list-item__icon" style="background:var(--lime);width:30px;height:30px;font-size:14px">🎬</div>
      <div class="list-item__body">
        <div class="list-item__title" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px"><?= htmlspecialchars($h['title']) ?></div>
        <div class="list-item__sub" style="font-size:10px"><?= date('d M H:i', strtotime($h['watched_at'])) ?></div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount list-item__amount--green" style="font-size:12px">+<?= format_rp((float)$h['reward_given']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Popup settings from DB
$popup_enabled      = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title        = setting($pdo, 'popup_title',   '📖 Hei, sudah baca panduan?');
$popup_body         = setting($pdo, 'popup_body',    'Biar makin lancar dapat reward, yuk baca dulu cara kerja TontonKuy! Dari cara tonton, jenis saldo, sampai tips withdraw.');
$popup_cta_text     = setting($pdo, 'popup_cta_text', '📖 Baca Panduan →');
$popup_cta_url      = setting($pdo, 'popup_cta_url',  '/panduan');
$popup_delay        = max(0, (int) setting($pdo, 'popup_delay', '1500'));
$popup_reset_hours  = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));
?>
<?php if ($popup_enabled): ?>
<!-- Popup Panduan -->
<div id="guide-popup" style="
  display:none;
  position:fixed;inset:0;
  background:rgba(0,0,0,.55);
  z-index:9999;
  align-items:flex-end;
  justify-content:center;
  padding-bottom:0;
">
  <div style="
    background:var(--white);
    border:2.5px solid var(--ink);
    border-bottom:none;
    border-radius:20px 20px 0 0;
    box-shadow:0 -6px 0 var(--ink);
    padding:20px 20px 28px;
    max-width:480px;
    width:100%;
    animation:slideUp .3s cubic-bezier(.22,.68,0,1.2);
  ">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-weight:900;font-size:16px"><?= htmlspecialchars($popup_title) ?></div>
      <button onclick="closeGuidePopup()" style="background:none;border:none;font-size:20px;cursor:pointer;line-height:1;color:#999">✕</button>
    </div>
    <div style="font-size:13px;color:#555;margin-bottom:16px;line-height:1.6">
      <?= nl2br(htmlspecialchars($popup_body)) ?>
    </div>
    <div style="display:flex;gap:8px">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="btn btn--primary btn--full" style="font-weight:900;font-size:13px"><?= htmlspecialchars($popup_cta_text) ?></a>
      <button onclick="closeGuidePopup()" class="btn btn--secondary" style="flex-shrink:0;font-size:12px;padding:0 14px">Nanti</button>
    </div>
  </div>
</div>

<style>
@keyframes slideUp {
  from { transform: translateY(100%); opacity:0; }
  to   { transform: translateY(0);    opacity:1; }
}
</style>

<script>
(function(){
  const KEY   = 'tk_guide_seen';
  const RESET = <?= $popup_reset_hours ?>;
  const DELAY = <?= $popup_delay ?>;
  const stored = localStorage.getItem(KEY);
  let show = !stored;
  if (stored && RESET > 0) {
    const seenAt = parseInt(stored, 10);
    if (Date.now() - seenAt > RESET * 3600 * 1000) show = true;
  }
  if (show) {
    setTimeout(function(){
      const el = document.getElementById('guide-popup');
      if (el) el.style.display = 'flex';
    }, DELAY);
  }
})();
function closeGuidePopup() {
  const el = document.getElementById('guide-popup');
  if (el) el.style.display = 'none';
  localStorage.setItem('tk_guide_seen', Date.now().toString());
}
const popup = document.getElementById('guide-popup');
if (popup) popup.addEventListener('click', function(e){ if (e.target===this) closeGuidePopup(); });
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
