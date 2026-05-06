<?php
$pageTitle  = $pageTitle  ?? 'Console';
$activePage = $activePage ?? '';
$admin      = $_SESSION['admin'] ?? ['username'=>'Admin'];

// Pending counts for badges
try {
    $pending_wd  = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $pending_dep = (int)$pdo->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
    $pending_upg = (int)$pdo->query("SELECT COUNT(*) FROM upgrade_orders WHERE status='pending'")->fetchColumn();
} catch(\Throwable) { $pending_wd = $pending_dep = $pending_upg = 0; }
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — TontonKuy Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --brand: #FF6B35;
  --sidebar-w: 240px;
  --sidebar-bg: #131520;
  --topbar-h: 58px;
}
*, *::before, *::after { font-family: 'Inter', sans-serif; box-sizing: border-box; }
body { background: #0f1117; color: #e0e0f0; min-height: 100vh; }

/* ── Sidebar ── */
.c-sidebar {
  position: fixed; top: 0; left: 0;
  width: var(--sidebar-w); height: 100vh;
  background: var(--sidebar-bg);
  border-right: 1px solid #1f2235;
  display: flex; flex-direction: column;
  z-index: 1050;
  transition: transform .25s;
}
.c-sidebar__logo {
  display: flex; align-items: center; gap: 10px;
  padding: 18px 20px;
  border-bottom: 1px solid #1f2235;
}
.c-sidebar__icon {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, #FF6B35, #FF8C42);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.c-sidebar__brand { font-size: 15px; font-weight: 800; color: #fff; line-height: 1.2; }
.c-sidebar__sub { font-size: 10px; color: #666; }
.c-sidebar__nav { flex: 1; overflow-y: auto; padding: 12px 10px; }
.c-sidebar__label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #444; padding: 10px 10px 4px; }
.c-nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px;
  border-radius: 8px;
  color: #888;
  font-size: 13.5px;
  font-weight: 500;
  text-decoration: none;
  margin-bottom: 2px;
  transition: all .18s;
  position: relative;
}
.c-nav-link:hover { background: #1f2235; color: #ccc; }
.c-nav-link.active { background: rgba(255,107,53,.15); color: var(--brand); font-weight: 700; }
.c-nav-link .badge-dot {
  margin-left: auto;
  background: var(--brand);
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 6px;
  border-radius: 10px;
  min-width: 20px;
  text-align: center;
}
.c-sidebar__footer { padding: 10px; border-top: 1px solid #1f2235; }

/* ── Topbar ── */
.c-topbar {
  position: fixed; top: 0; left: var(--sidebar-w); right: 0;
  height: var(--topbar-h);
  background: #131520;
  border-bottom: 1px solid #1f2235;
  display: flex; align-items: center;
  padding: 0 24px;
  gap: 12px;
  z-index: 1040;
  transition: left .25s;
}
.c-topbar__toggle { display: none; background: none; border: none; color: #888; cursor: pointer; padding: 4px; }
.c-topbar__title { font-weight: 700; font-size: 15px; flex: 1; }
.c-topbar__clock { font-size: 12px; color: #666; }
.c-topbar__avatar {
  width: 34px; height: 34px;
  background: var(--brand);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 14px; color: #fff;
}

/* ── Main content ── */
.c-main {
  margin-left: var(--sidebar-w);
  padding-top: var(--topbar-h);
  min-height: 100vh;
  transition: margin .25s;
}
.c-content { padding: 24px; }

/* ── Cards ── */
.c-card { background: #131520; border: 1px solid #1f2235; border-radius: 12px; overflow: hidden; }
.c-card-header { padding: 16px 20px; border-bottom: 1px solid #1f2235; display: flex; align-items: center; justify-content: space-between; }
.c-card-title { font-weight: 700; font-size: 14px; }
.c-card-body { padding: 20px; }

/* ── Stats ── */
.c-stat { background: #131520; border: 1px solid #1f2235; border-radius: 12px; padding: 18px 20px; }
.c-stat__val { font-size: 24px; font-weight: 800; }
.c-stat__lbl { font-size: 12px; color: #666; margin-top: 2px; }
.c-stat__icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }

/* ── Table ── */
.c-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.c-table th { background: #0f1117; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; padding: 10px 14px; border-bottom: 1px solid #1f2235; }
.c-table td { padding: 12px 14px; border-bottom: 1px solid #1a1d27; vertical-align: middle; }
.c-table tbody tr:hover { background: #15182a; }
.c-table tbody tr:last-child td { border-bottom: none; }

/* ── DataTables Overrides ── */
.dataTables_wrapper { font-size: 13px; color: #888; padding: 10px; }
.dataTables_wrapper .dataTables_length select,
.dataTables_wrapper .dataTables_filter input {
  background: #0f1117; border: 1.5px solid #1f2235; border-radius: 6px;
  color: #e0e0f0; padding: 4px 8px; outline: none;
}
.dataTables_wrapper .dataTables_length select:focus,
.dataTables_wrapper .dataTables_filter input:focus { border-color: var(--brand); }
.dataTables_wrapper .pagination .page-link {
  background: #0f1117; border-color: #1f2235; color: #888;
}
.dataTables_wrapper .pagination .page-item.active .page-link {
  background: var(--brand); border-color: var(--brand); color: #fff;
}

/* ── Badges ── */
.b-success { background: rgba(76,175,130,.15); color: #4CAF82; }
.b-warn    { background: rgba(242,153,0,.15); color: #F29900; }
.b-danger  { background: rgba(244,78,59,.15); color: #F44E3B; }
.b-neutral { background: rgba(255,255,255,.07); color: #888; }

/* ── Forms ── */
.c-form-control {
  background: #0f1117;
  border: 1.5px solid #1f2235;
  border-radius: 8px;
  color: #e0e0f0;
  padding: 9px 12px;
  font-size: 13px;
  width: 100%;
  outline: none;
  transition: border-color .18s;
  font-family: inherit;
}
.c-form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(255,107,53,.12); }
.c-form-control::placeholder { color: #444; }
.c-label { font-size: 12px; font-weight: 600; color: #888; margin-bottom: 5px; display: block; }
.c-form-group { margin-bottom: 14px; }

/* ── Backdrop (mobile) ── */
.c-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 1045; }

/* ── Mobile ── */
@media (max-width: 991px) {
  .c-sidebar { transform: translateX(-100%); }
  .c-sidebar.open { transform: translateX(0); }
  .c-topbar { left: 0; }
  .c-main { margin-left: 0; }
  .c-topbar__toggle { display: block; }
  .c-backdrop.active { display: block; }
}
</style>
</head>
<body>

<div class="c-backdrop" id="backdrop" onclick="closeSidebar()"></div>

<aside class="c-sidebar" id="sidebar">
  <div class="c-sidebar__logo">
    <div class="c-sidebar__icon">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2.5"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
    </div>
    <div>
      <div class="c-sidebar__brand">TontonKuy</div>
      <div class="c-sidebar__sub">Admin Console</div>
    </div>
  </div>

  <nav class="c-sidebar__nav">
    <div class="c-sidebar__label">Utama</div>
    <a href="/console/" class="c-nav-link <?= $activePage==='dashboard'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a href="/console/videos.php" class="c-nav-link <?= $activePage==='videos'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
      Manajemen Video
    </a>
    <a href="/console/users.php" class="c-nav-link <?= $activePage==='users'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Pengguna
    </a>

    <div class="c-sidebar__label" style="margin-top:6px">Keuangan</div>
    <a href="/console/deposits.php" class="c-nav-link <?= $activePage==='deposits'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Deposit
      <?php if ($pending_dep > 0): ?><span class="badge-dot"><?= $pending_dep ?></span><?php endif; ?>
    </a>
    <a href="/console/withdrawals.php" class="c-nav-link <?= $activePage==='withdrawals'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
      Withdraw
      <?php if ($pending_wd > 0): ?><span class="badge-dot"><?= $pending_wd ?></span><?php endif; ?>
    </a>
    <a href="/console/upgrades.php" class="c-nav-link <?= $activePage==='upgrades'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      Upgrade Orders
      <?php if ($pending_upg > 0): ?><span class="badge-dot"><?= $pending_upg ?></span><?php endif; ?>
    </a>

    <div class="c-sidebar__label" style="margin-top:6px">Pengaturan</div>
    <a href="/console/payment.php" class="c-nav-link <?= $activePage==='payment'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Rekening & QRIS
    </a>
    <a href="/console/memberships.php" class="c-nav-link <?= $activePage==='memberships'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
      Paket Membership
    </a>
    <a href="/console/seo.php" class="c-nav-link <?= $activePage==='seo'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      SEO Management
    </a>
    <a href="/console/analytics.php" class="c-nav-link <?= $activePage==='analytics'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Traffic Analytics
    </a>
    <a href="/console/settings.php" class="c-nav-link <?= $activePage==='settings'?'active':'' ?>">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
      Pengaturan Umum
    </a>
  </nav>

  <div class="c-sidebar__footer">
    <a href="/" target="_blank" class="c-nav-link">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      Lihat Website
    </a>
    <a href="/console/logout.php" class="c-nav-link" style="color:#F44E3B">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</aside>

<div class="c-main">
  <header class="c-topbar">
    <button class="c-topbar__toggle" onclick="toggleSidebar()">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="c-topbar__title"><?= htmlspecialchars($pageTitle) ?></div>
    <div class="c-topbar__clock" id="c-clock"></div>
    <div class="c-topbar__avatar"><?= strtoupper(substr($admin['username'],0,1)) ?></div>
  </header>
  <div class="c-content">
