<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limit
$ip_key   = 'login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_att'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait  = ceil(($lock_until - time()) / 60);
        $error = "Akun terkunci. Coba lagi dalam {$wait} menit.";
        goto end_login;
    }

    $login = trim($_POST['login'] ?? '');
    $pwd   = $_POST['password'] ?? '';
    $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    $s     = $pdo->prepare("SELECT * FROM users WHERE {$field}=? AND is_active=1");
    $s->execute([$login]);
    $user  = $s->fetch();

    if ($user && password_verify($pwd, $user['password_hash'])) {
        unset($_SESSION[$ip_key . '_att'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        redirect('/home');
    }

    $new_att = $attempts + 1;
    $_SESSION[$ip_key . '_att'] = $new_att;
    if ($new_att >= 5) {
        $_SESSION[$ip_key . '_lock'] = time() + 600;
        $error = 'Terlalu banyak percobaan. Akun dikunci 10 menit.';
    } else {
        $left  = 5 - $new_att;
        $error = "Username/email atau password salah. Sisa percobaan: {$left}";
    }
}
end_login:
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Masuk — TontonKuy</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.login-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--mint);
  border: var(--border);
  box-shadow: var(--shadow-sm);
  border-radius: 20px;
  padding: 5px 14px;
  font-size: 12px; font-weight: 800;
  margin-bottom: 16px;
}
.deco-strip {
  height: 8px;
  background: repeating-linear-gradient(90deg,
    var(--yellow) 0,var(--yellow) 40px,
    var(--mint)   40px,var(--mint)   80px,
    var(--lavender) 80px,var(--lavender) 120px,
    var(--salmon) 120px,var(--salmon) 160px
  );
  border-top: var(--border);
  border-bottom: var(--border);
  margin: -30px -24px 24px;
}
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <!-- Decorative stripe -->
    <div class="deco-strip"></div>

    <div class="auth-logo">
      <div class="auth-logo__icon">🎬</div>
      <div class="auth-logo__title">TontonKuy</div>
      <div class="login-pill">🎁 Tonton video &amp; kumpulkan reward!</div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert--error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label">Username / Email</label>
        <div class="input-wrap">
          <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input class="form-control" type="text" name="login"
            value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
            placeholder="username atau email" autofocus autocomplete="username">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <div class="input-wrap">
          <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          <input class="form-control" type="password" id="pwd" name="password"
            placeholder="Password kamu" autocomplete="current-password">
          <button type="button" class="input-toggle" onclick="document.getElementById('pwd').type=document.getElementById('pwd').type==='password'?'text':'password'">👁</button>
        </div>
      </div>

      <button type="submit" class="btn btn--yellow btn--full btn--lg" style="margin-top:4px">
        🚀 Masuk Sekarang
      </button>
    </form>

    <!-- Visual divider -->
    <div style="display:flex;align-items:center;gap:10px;margin:18px 0">
      <div style="flex:1;height:2px;background:var(--ink);border-radius:2px"></div>
      <span style="font-size:12px;font-weight:800;color:#aaa">ATAU</span>
      <div style="flex:1;height:2px;background:var(--ink);border-radius:2px"></div>
    </div>

    <!-- Feature highlights -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px">
      <?php
      $feats = [['🎬','Tonton\nVideo'],['💰','Dapat\nReward'],['🏆','Withdraw\nSaldo']];
      $cols  = ['var(--mint)','var(--yellow)','var(--lavender)'];
      foreach ($feats as $i => [$ic,$lbl]): ?>
      <div style="background:<?= $cols[$i] ?>;border:var(--border);box-shadow:var(--shadow-sm);border-radius:10px;padding:10px 6px;text-align:center">
        <div style="font-size:20px"><?= $ic ?></div>
        <div style="font-size:10px;font-weight:800;margin-top:4px;line-height:1.3;white-space:pre-line"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="auth-switch">Belum punya akun? <a href="/register">Daftar gratis →</a></div>
  </div>
</div>
</body>
</html>
