<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan — TontonKuy';
$activePage = 'panduan';
require dirname(__DIR__) . '/partials/header.php';

// Reward per video from free plan
$free_reward = (float) setting($pdo, 'free_reward_amount', '200');
$free_limit  = (int)   setting($pdo, 'free_watch_limit', '5');
$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');

// Panduan customizable content from DB
$panduan_intro  = setting($pdo, 'panduan_intro', 'Cara kerja platform reward video ini');
$panduan_step1  = setting($pdo, 'panduan_step1', 'Buat akun gratis, tidak perlu verifikasi ribet. Langsung bisa mulai tonton.');
$panduan_step2  = setting($pdo, 'panduan_step2', 'Setiap video yang ditonton hingga selesai akan otomatis memberikan reward ke Saldo Penarikan kamu.');
$panduan_step3  = setting($pdo, 'panduan_step3', 'Reward terkumpul di Saldo Penarikan. Cek progresmu di halaman Beranda kapan saja.');
$panduan_step4  = setting($pdo, 'panduan_step4', 'Minimal withdraw ' . format_rp($min_wd) . '. Proses 1–3 hari kerja ke rekening/e-wallet pilihanmu.');
$panduan_faq    = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text', '🎬 Mulai Tonton Sekarang →');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url', '/videos');
?>

<style>
.guide-step{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.guide-step__num{width:34px;height:34px;flex-shrink:0;border-radius:50%;background:var(--yellow);border:2px solid var(--ink);box-shadow:2px 2px 0 var(--ink);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px}
.guide-step__body{flex:1}
.guide-step__title{font-weight:900;font-size:14px;margin-bottom:2px}
.guide-step__desc{font-size:12px;color:#555;line-height:1.5}
.guide-card{border:2px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);background:var(--white);margin-bottom:12px;overflow:hidden}
.guide-card__hd{padding:12px 14px;border-bottom:2px solid var(--ink);font-weight:900;font-size:14px;background:var(--yellow)}
.guide-card__bd{padding:14px}
.reward-table{width:100%;border-collapse:collapse;font-size:12px}
.reward-table th,.reward-table td{padding:7px 10px;border:1.5px solid var(--ink);text-align:left}
.reward-table th{background:var(--mint);font-weight:800}
.reward-table tr:nth-child(even) td{background:#fafafa}
.tip-box{background:var(--lime);border:2px solid var(--ink);border-radius:10px;padding:10px 12px;font-size:12px;margin-bottom:8px;display:flex;gap:8px;align-items:flex-start}
.tip-box__icon{font-size:16px;flex-shrink:0;margin-top:1px}
</style>

<!-- Header -->
<div style="text-align:center;margin-bottom:16px">
  <div style="font-size:28px;margin-bottom:4px">📖</div>
  <div style="font-weight:900;font-size:18px">Panduan TontonKuy</div>
  <div style="font-size:12px;color:#666;margin-top:2px"><?= htmlspecialchars($panduan_intro) ?></div>
</div>

<!-- Cara Kerja -->
<div class="guide-card">
  <div class="guide-card__hd">🎯 Cara Kerja</div>
  <div class="guide-card__bd">
    <div class="guide-step">
      <div class="guide-step__num">1</div>
      <div class="guide-step__body">
        <div class="guide-step__title">Daftar & Login</div>
        <div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step1)) ?></div>
      </div>
    </div>
    <div class="guide-step">
      <div class="guide-step__num">2</div>
      <div class="guide-step__body">
        <div class="guide-step__title">Tonton Video</div>
        <div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step2)) ?></div>
      </div>
    </div>
    <div class="guide-step">
      <div class="guide-step__num">3</div>
      <div class="guide-step__body">
        <div class="guide-step__title">Kumpulkan Reward</div>
        <div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step3)) ?></div>
      </div>
    </div>
    <div class="guide-step">
      <div class="guide-step__num">4</div>
      <div class="guide-step__body">
        <div class="guide-step__title">Tarik Saldo (Withdraw)</div>
        <div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step4)) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Saldo -->
<div class="guide-card">
  <div class="guide-card__hd">💰 Jenis Saldo</div>
  <div class="guide-card__bd">
    <div class="tip-box">
      <div class="tip-box__icon">💸</div>
      <div><strong>Saldo Penarikan (WD)</strong> — Didapat dari reward tonton video & bonus referral. Bisa ditarik ke rekening.</div>
    </div>
    <div class="tip-box" style="background:var(--sky)">
      <div class="tip-box__icon">💳</div>
      <div><strong>Saldo Deposit</strong> — Diisi manual lewat transfer/QRIS. Digunakan untuk upgrade paket membership agar bisa tonton lebih banyak video dan dapat reward lebih besar.</div>
    </div>
  </div>
</div>

<!-- Reward -->
<div class="guide-card">
  <div class="guide-card__hd">⭐ Reward per Video</div>
  <div class="guide-card__bd">
    <div style="font-size:12px;color:#555;margin-bottom:10px">Reward ditentukan per video oleh admin. Upgrade membership untuk akses video bereward lebih tinggi.</div>
    <table class="reward-table">
      <tr><th>Paket</th><th>Limit/Hari</th><th>Akses</th></tr>
      <tr><td>🆓 Free</td><td><?= $free_limit ?>× video</td><td>Video dasar</td></tr>
      <tr><td>👑 Premium</td><td>Lebih banyak</td><td>Video reward lebih besar</td></tr>
    </table>
    <div style="font-size:11px;color:#888;margin-top:8px">* Detail paket membership bisa dilihat di halaman <a href="/upgrade">Upgrade →</a></div>
  </div>
</div>

<!-- Referral -->
<div class="guide-card">
  <div class="guide-card__hd">🤝 Program Referral</div>
  <div class="guide-card__bd">
    <div class="tip-box" style="background:var(--lavender)">
      <div class="tip-box__icon">🎁</div>
      <div>Ajak temanmu daftar pakai <strong>kode referral</strong> kamu. Kamu dapat bonus <strong><?= format_rp($ref_bonus) ?></strong> langsung ke Saldo Penarikan untuk setiap teman yang berhasil daftar!</div>
    </div>
    <div style="font-size:12px;color:#555;margin-top:8px">Kode referralmu bisa dilihat dan disebarkan di halaman <a href="/referral">Referral →</a></div>
  </div>
</div>

<!-- Check-in -->
<div class="guide-card">
  <div class="guide-card__hd">📅 Daily Check-in</div>
  <div class="guide-card__bd">
    <div class="tip-box" style="background:var(--salmon)">
      <div class="tip-box__icon">🔥</div>
      <div>Login setiap hari dan klik <strong>Check-in</strong> untuk mendapatkan bonus reward harian. Jangan sampai putus streak-nya!</div>
    </div>
    <div style="font-size:12px;color:#555;margin-top:8px">Check-in bisa dilakukan di halaman <a href="/checkin">Check-in →</a></div>
  </div>
</div>

<!-- FAQ -->
<div class="guide-card">
  <div class="guide-card__hd">❓ FAQ</div>
  <div class="guide-card__bd" style="display:flex;flex-direction:column;gap:10px">
    <div>
      <div style="font-weight:800;font-size:13px">Kapan reward masuk ke saldo?</div>
      <div style="font-size:12px;color:#555;margin-top:2px">Reward otomatis masuk setelah video selesai ditonton sesuai durasi minimum yang ditentukan.</div>
    </div>
    <div style="border-top:1.5px solid #eee;padding-top:10px">
      <div style="font-weight:800;font-size:13px">Apakah bisa skip video?</div>
      <div style="font-size:12px;color:#555;margin-top:2px">Tidak bisa. Reward hanya diberikan jika kamu menonton sampai waktu minimum tercapai.</div>
    </div>
    <div style="border-top:1.5px solid #eee;padding-top:10px">
      <div style="font-weight:800;font-size:13px">Apakah gratis?</div>
      <div style="font-size:12px;color:#555;margin-top:2px">Ya! Daftar dan tonton video sepenuhnya gratis. Deposit hanya diperlukan jika ingin upgrade ke paket premium.</div>
    </div>
    <div style="border-top:1.5px solid #eee;padding-top:10px">
      <div style="font-weight:800;font-size:13px">Withdraw ke mana saja?</div>
      <div style="font-size:12px;color:#555;margin-top:2px">Semua bank dan e-wallet Indonesia (BCA, BNI, BRI, Mandiri, GoPay, OVO, Dana, dll).</div>
    </div>
    <?php if ($panduan_faq): ?>
    <div style="border-top:1.5px solid #eee;padding-top:10px">
      <div style="font-size:12px;color:#555"><?= nl2br(htmlspecialchars($panduan_faq)) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- CTA -->
<div style="text-align:center;margin-top:4px;margin-bottom:8px">
  <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="btn btn--primary btn--full" style="font-size:14px;font-weight:900"><?= htmlspecialchars($panduan_cta_text) ?></a>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
