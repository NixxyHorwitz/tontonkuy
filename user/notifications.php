<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// ── Fetch notifications for this user ────────────────────────────────────────
$notifications = $pdo->prepare(
    "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
     FROM notifications n
     LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
     WHERE (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, CAST(? AS JSON))))
       AND (n.expires_at IS NULL OR n.expires_at > NOW())
     ORDER BY n.created_at DESC
     LIMIT 50"
);
$notifications->execute([$user['id'], $user['id']]);
$notifications = $notifications->fetchAll();

$unread_count = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unread_count++;
}

$pageTitle  = 'Notifikasi — TontonKuy';
$activePage = 'notifications';
require dirname(__DIR__) . '/partials/header.php';

// Type config
$type_cfg = [
    'info'     => ['bg' => 'var(--sky)',     'icon' => 'ℹ️',  'label' => 'Info'],
    'success'  => ['bg' => 'var(--lime)',    'icon' => '✅',  'label' => 'Sukses'],
    'warning'  => ['bg' => 'var(--peach)',   'icon' => '⚠️',  'label' => 'Peringatan'],
    'alert'    => ['bg' => 'var(--salmon)',  'icon' => '🚨',  'label' => 'Alert'],
    'congrats' => ['bg' => 'var(--yellow)',  'icon' => '🎉',  'label' => 'Selamat'],
];
?>

<div class="page-title-bar" style="display:flex;align-items:center;justify-content:space-between">
  <div>
    <h1 style="font-size:20px">🔔 Notifikasi</h1>
    <?php if ($unread_count > 0): ?>
    <p><?= $unread_count ?> belum dibaca</p>
    <?php else: ?>
    <p style="color:#22c55e">Semua sudah dibaca ✓</p>
    <?php endif; ?>
  </div>
  <?php if ($unread_count > 0): ?>
  <button id="btn-mark-all" class="btn btn--ghost btn--sm" onclick="markAllRead()">
    Tandai semua dibaca
  </button>
  <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="card" style="text-align:center;padding:50px 20px">
  <div style="font-size:48px;margin-bottom:12px">📭</div>
  <div style="font-weight:800;font-size:16px;margin-bottom:4px">Belum ada notifikasi</div>
  <div style="font-size:13px;color:#888">Notifikasi dari admin akan muncul di sini</div>
</div>

<?php else: ?>
<div id="notif-list">
<?php foreach ($notifications as $n):
  $cfg = $type_cfg[$n['type']] ?? $type_cfg['info'];
  $icon = $n['icon'] ?: $cfg['icon'];
  $is_read = (bool)$n['is_read'];
?>
<div class="notif-item <?= $is_read ? 'notif-item--read' : '' ?>" data-id="<?= $n['id'] ?>"
     style="background:<?= $is_read ? 'var(--white)' : $cfg['bg'] ?>">
  <div class="notif-item__icon"><?= $icon ?></div>
  <div class="notif-item__body">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
      <?php if (!$is_read): ?>
      <span style="width:8px;height:8px;background:var(--brand);border-radius:50%;flex-shrink:0;border:1.5px solid var(--ink)"></span>
      <?php endif; ?>
      <div class="notif-item__title"><?= htmlspecialchars($n['title']) ?></div>
    </div>
    <div class="notif-item__msg"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
    <?php if ($n['action_url'] && $n['action_text']): ?>
    <a href="<?= htmlspecialchars($n['action_url']) ?>" class="notif-item__cta">
      <?= htmlspecialchars($n['action_text']) ?> →
    </a>
    <?php endif; ?>
    <div class="notif-item__time">
      <span class="badge badge--neutral" style="font-size:9px;padding:2px 6px"><?= $cfg['label'] ?></span>
      <?= date('d M Y, H:i', strtotime($n['created_at'])) ?>
    </div>
  </div>
  <?php if (!$is_read): ?>
  <button class="notif-item__read-btn" onclick="markRead(<?= $n['id'] ?>, this)" title="Tandai dibaca">
    ✓
  </button>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.notif-item {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 14px 16px;
  border: 2.5px solid var(--ink);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  margin-bottom: 10px;
  position: relative;
  transition: opacity .3s, transform .2s;
}
.notif-item--read {
  opacity: .72;
  box-shadow: 2px 2px 0 var(--ink);
}
.notif-item__icon {
  font-size: 26px;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  background: var(--white);
  border: 2px solid var(--ink);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 2px 2px 0 var(--ink);
}
.notif-item__body { flex: 1; min-width: 0; }
.notif-item__title { font-weight: 900; font-size: 14px; line-height: 1.3; }
.notif-item__msg { font-size: 13px; color: #444; margin-top: 4px; font-weight: 600; line-height: 1.5; }
.notif-item__cta {
  display: inline-block;
  margin-top: 8px;
  font-size: 12px;
  font-weight: 900;
  color: var(--brand);
  text-decoration: none;
  border: 1.5px solid var(--brand);
  border-radius: 6px;
  padding: 3px 10px;
  box-shadow: 2px 2px 0 var(--ink);
}
.notif-item__time {
  font-size: 11px;
  color: #666;
  margin-top: 6px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;
}
.notif-item__read-btn {
  position: absolute;
  top: 10px; right: 10px;
  background: var(--white);
  border: 2px solid var(--ink);
  border-radius: 6px;
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 900; cursor: pointer;
  box-shadow: 2px 2px 0 var(--ink);
  transition: transform .1s, box-shadow .1s;
}
.notif-item__read-btn:hover { transform: translate(-1px,-1px); box-shadow: 3px 3px 0 var(--ink); }
.notif-item__read-btn:active { transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--ink); }
</style>

<script>
const CSRF = '<?= csrf_token() ?>';

async function markRead(id, btn) {
  const item = btn.closest('.notif-item');
  const fd = new FormData();
  fd.append('action', 'mark_read');
  fd.append('id', id);
  fd.append('_csrf', CSRF);
  const res = await fetch('/notif_action', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    item.classList.add('notif-item--read');
    item.style.background = 'var(--white)';
    // Remove the unread dot
    const dot = item.querySelector('[style*="width:8px"]');
    if (dot) dot.remove();
    btn.remove();
    // Update count badge in header if present
    updateBadge(data.count);
    // Update subtitle
    const sub = document.querySelector('.page-title-bar p');
    if (sub && data.count > 0) sub.textContent = data.count + ' belum dibaca';
    else if (sub) { sub.textContent = 'Semua sudah dibaca ✓'; sub.style.color = '#22c55e'; }
  }
}

async function markAllRead() {
  const btn = document.getElementById('btn-mark-all');
  if (btn) btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'mark_all');
  fd.append('_csrf', CSRF);
  await fetch('/notif_action', { method: 'POST', body: fd });
  // Refresh page
  location.reload();
}

function updateBadge(count) {
  const badge = document.getElementById('notif-badge');
  if (!badge) return;
  if (count > 0) { badge.textContent = count > 9 ? '9+' : count; badge.style.display = ''; }
  else badge.style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
