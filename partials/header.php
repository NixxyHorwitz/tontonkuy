<?php
/** partials/header.php — requires: $pageTitle, $activePage, $user */
// SEO settings (read once)
$_seo_title  = setting($pdo, 'seo_title', 'TontonKuy');
$_seo_desc   = setting($pdo, 'seo_description', '');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og     = setting($pdo, 'seo_og_image', '');
$_seo_twcard = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_author = setting($pdo, 'seo_author', 'TontonKuy');
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
<?php if ($_seo_og):   ?><meta property="og:image" content="<?= htmlspecialchars($_seo_og) ?>"><?php endif; ?>
<meta property="og:title" content="<?= htmlspecialchars($pageTitle ?? $_seo_title) ?>">
<?php if ($_seo_desc): ?><meta property="og:description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<?php if ($_favicon): ?><link rel="icon" type="image/png" href="<?= htmlspecialchars($_favicon) ?>?v=<?= @filemtime(dirname(__DIR__) . $_favicon) ?: time() ?>"><?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css">
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
      <div class="topbar__balances">
        <div class="topbar__bal-item topbar__bal-item--wd" title="Saldo Penarikan: <?= format_rp((float)$user['balance_wd']) ?>">
          <span class="topbar__bal-label">WD</span>
          <span class="topbar__bal-val"><?= fmt_short((float)$user['balance_wd']) ?></span>
        </div>
        <div class="topbar__bal-item topbar__bal-item--dep" title="Saldo Deposit: <?= format_rp((float)$user['balance_dep']) ?>">
          <span class="topbar__bal-label">DEP</span>
          <span class="topbar__bal-val"><?= fmt_short((float)$user['balance_dep']) ?></span>
        </div>
      </div>
      <a href="/history" class="topbar__avatar" title="Riwayat"
         style="background:var(--mint);font-size:16px;text-decoration:none">📊</a>
      <a href="/profile" class="topbar__avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></a>
      <?php endif; ?>
    </div>
  </header>
  <main class="page-content">
