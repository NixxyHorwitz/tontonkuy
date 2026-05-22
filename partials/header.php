<?php
/** partials/header.php — requires: $pageTitle, $activePage, $user */
// SEO settings (read once)
$_seo_title  = setting($pdo, 'seo_title', 'TontonKuy');
$_seo_desc   = setting($pdo, 'seo_description', '');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og       = setting($pdo, 'seo_og_image', '');
$_seo_twcard   = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_author   = setting($pdo, 'seo_author', 'TontonKuy');
$_seo_og_title = setting($pdo, 'seo_og_title', '');
$_seo_og_desc  = setting($pdo, 'seo_og_description', '');
$_seo_og_type  = setting($pdo, 'seo_og_type', 'website');
$_favicon    = setting($pdo, 'favicon_path', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFE566">
<title><?= htmlspecialchars(($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author):?><meta name="author"     content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<?php
$absolute_og = $_seo_og ? (preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'))) : '';
$fav_url = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_desc = $_seo_og_desc ?: $_seo_desc;
?>
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_seo_title) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= htmlspecialchars($_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title)) ?>">
<?php if ($final_og_desc): ?>
<meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<?php if ($fav_url): ?>
<link rel="icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <a href="/home" class="topbar__logo">
      <?php if ($_favicon): ?>
        <img src="<?= htmlspecialchars($_favicon) ?>" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:6px;border:1.5px solid #1A1A1A;flex-shrink:0">
      <?php else: ?>
        <span style="font-size:20px;line-height:1">🎬</span>
      <?php endif; ?>
      <span style="white-space:nowrap">Tonton<strong>Kuy</strong></span>
    </a>
    <div class="topbar__right">
      <?php if (!empty($user)): ?>
      <?php
      // Compact number for topbar: 1.234.567 → 1,2jt | 50.000 → 50rb
      function fmt_short(float $n): string {
        if ($n >= 1_000_000) return number_format($n/1_000_000, 1, '.', '') . 'jt';
        if ($n >= 1_000)     return number_format($n/1_000, 1, '.', '') . 'rb';
        return (string)(int)$n;
      }
      ?>
      <!-- Balance Dropdown -->
      <div class="bal-dropdown" id="bal-dropdown">
        <button type="button" class="bal-dropdown__trigger" onclick="toggleBalDropdown()" aria-label="Lihat saldo">
          <span class="bal-dropdown__icon">💰</span>
          <span class="bal-dropdown__text"><?= fmt_short((float)$user['balance_wd']) ?></span>
          <span class="bal-dropdown__caret">▾</span>
        </button>
        <div class="bal-dropdown__panel" id="bal-panel">
          <div class="bal-dropdown__row bal-dropdown__row--wd">
            <span class="bal-dropdown__lbl">💸 Saldo WD</span>
            <span class="bal-dropdown__val"><?= format_rp((float)$user['balance_wd']) ?></span>
          </div>
          <div class="bal-dropdown__row bal-dropdown__row--dep">
            <span class="bal-dropdown__lbl">🏦 Saldo Deposit</span>
            <span class="bal-dropdown__val"><?= format_rp((float)$user['balance_dep']) ?></span>
          </div>
          <?php if (setting($pdo, 'plinko_enabled', '1') === '1'): ?>
          <div class="bal-dropdown__row bal-dropdown__row--coin">
            <span class="bal-dropdown__lbl">🪙 Koin Plinko</span>
            <span class="bal-dropdown__val" id="user-coins"><?= number_format((int)$user['plinko_coins']) ?></span>
          </div>
          <?php endif; ?>
          <a href="/plinko-shop" class="bal-dropdown__link">🛒 Lapak Koin →</a>
        </div>
      </div>
      <style>
      .bal-dropdown{position:relative;z-index:9999;}
      .bal-dropdown__trigger{display:flex;align-items:center;gap:4px;background:var(--yellow);border:2px solid var(--ink);border-radius:8px;box-shadow:2px 2px 0 var(--ink);padding:5px 10px;font-weight:900;font-size:12px;color:var(--ink);cursor:pointer;transition:transform .1s,box-shadow .1s;}
      .bal-dropdown__trigger:hover{transform:translate(-1px,-1px);box-shadow:3px 3px 0 var(--ink);}
      .bal-dropdown__caret{font-size:9px;transition:transform .2s;}
      .bal-dropdown__panel{display:none;position:fixed;right:12px;top:56px;background:#fff;border:2.5px solid var(--ink);border-radius:10px;box-shadow:4px 4px 0 var(--ink);min-width:210px;z-index:9999;overflow:hidden;}
      .bal-dropdown__panel.open{display:block;animation:bdFadeIn .15s ease;}
      .bal-dropdown__caret.open{transform:rotate(180deg);}
      @keyframes bdFadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
      .bal-dropdown__row{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;border-bottom:1.5px solid #eee;font-size:12px;}
      .bal-dropdown__row--wd{background:var(--mint);}
      .bal-dropdown__row--dep{background:#f5f8ff;}
      .bal-dropdown__row--coin{background:var(--yellow);}
      .bal-dropdown__lbl{font-weight:700;color:#444;}
      .bal-dropdown__val{font-weight:900;color:var(--ink);}
      .bal-dropdown__link{display:block;padding:9px 14px;font-size:11px;font-weight:900;color:var(--brand);text-decoration:none;text-align:center;background:#fafafa;}
      .bal-dropdown__link:hover{background:var(--yellow);}
      </style>
      <script>
      (function(){
        function toggleBalDropdown(e){
          e.stopPropagation();
          const panel=document.getElementById('bal-panel');
          const caret=document.querySelector('.bal-dropdown__caret');
          const isOpen=panel.classList.toggle('open');
          caret.classList.toggle('open',isOpen);
        }
        // expose globally for onclick
        window.toggleBalDropdown=toggleBalDropdown;
        document.addEventListener('click',function(e){
          const d=document.getElementById('bal-dropdown');
          if(d&&!d.contains(e.target)){
            document.getElementById('bal-panel').classList.remove('open');
            const c=document.querySelector('.bal-dropdown__caret');
            if(c)c.classList.remove('open');
          }
        });
      })();
      </script>
      <a href="/notifications" class="topbar__avatar" title="Notifikasi"
         id="notif-bell-btn"
         style="background:var(--lavender);font-size:16px;text-decoration:none;position:relative">
        🔔
        <span id="notif-badge" style="
          display:none;position:absolute;top:-4px;right:-4px;
          background:var(--brand);color:#fff;
          font-size:9px;font-weight:900;min-width:16px;height:16px;
          border-radius:10px;padding:0 3px;
          border:1.5px solid var(--ink);
          display:inline-flex;align-items:center;justify-content:center;
          line-height:1
        "></span>
      </a>
      <a href="/profile" class="topbar__avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></a>
      <?php endif; ?>
    </div>
  </header>
  <main class="page-content">
<?php if (!empty($user)): ?>
<script>
(function() {
  function fetchNotifCount() {
    fetch('/notif_action?action=count')
      .then(r => r.json())
      .then(data => {
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (data.count > 0) {
          badge.textContent = data.count > 9 ? '9+' : data.count;
          badge.style.display = 'inline-flex';
        } else {
          badge.style.display = 'none';
        }
      })
      .catch(() => {});
  }
  // Run on load + every 60s
  fetchNotifCount();
  setInterval(fetchNotifCount, 60000);
})();
</script>
<?php endif; ?>
