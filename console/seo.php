<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
csrf_enforce();

$flash = $flashType = '';
global $pdo;
$s = fn($k,$d='') => setting($pdo,$k,$d);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_seo';

    // ── Favicon upload (separate action) ──────────────────────
    if ($action === 'upload_favicon' && !empty($_FILES['favicon']['tmp_name'])) {
        $maxBytes = 5 * 1024 * 1024; // 5MB
        $tmpFile  = $_FILES['favicon']['tmp_name'];
        $origName = $_FILES['favicon']['name'];
        $fileSize = $_FILES['favicon']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($fileSize > $maxBytes) {
            $flash = '❌ File terlalu besar. Maksimal 5MB.'; $flashType = 'error';
        } elseif (!in_array($ext, ['png','jpg','jpeg','webp','gif','ico'])) {
            $flash = '❌ Format tidak didukung. Gunakan PNG/JPG/WEBP.'; $flashType = 'error';
        } elseif ($ext === 'ico') {
            $favDir  = dirname(__DIR__) . '/assets/';
            $favPath = $favDir . 'favicon.ico';
            if (move_uploaded_file($tmpFile, $favPath)) {
                setting_set($pdo, 'favicon_path', '/assets/favicon.ico');
                $flash = '✅ Favicon (.ico) berhasil diupload!';
            } else {
                $flash = '❌ Gagal menyimpan favicon ke /assets/. Cek permission folder.';
                $flashType = 'error';
            }
        } elseif (!function_exists('imagecreatefrompng')) {
            $flash = '❌ GD extension tidak tersedia di server.'; $flashType = 'error';
        } else {
            // Try to load image — first by getimagesize(), fallback by extension
            $src = null;
            $info = @getimagesize($tmpFile);
            if ($info) {
                $src = match((int)$info[2]) {
                    IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
                    IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
                    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : null,
                    IMAGETYPE_GIF  => @imagecreatefromgif($tmpFile),
                    default        => null,
                };
            }
            // Extension-based fallback (handles mis-detected types)
            if (!$src) {
                $src = match($ext) {
                    'jpg','jpeg' => @imagecreatefromjpeg($tmpFile),
                    'png'        => @imagecreatefrompng($tmpFile),
                    'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : null,
                    'gif'        => @imagecreatefromgif($tmpFile),
                    default      => null,
                };
            }

            if (!$src) {
                $flash = '❌ File gambar tidak dapat dibaca. Pastikan file valid dan tidak corrupt.';
                $flashType = 'error';
            } else {
                // Resize to 64×64 with transparency support
                $out = imagecreatetruecolor(64, 64);
                imagealphablending($out, false);
                imagesavealpha($out, true);
                $transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
                imagefill($out, 0, 0, $transparent);
                imagecopyresampled($out, $src, 0, 0, 0, 0, 64, 64, imagesx($src), imagesy($src));

                $favDir  = dirname(__DIR__) . '/assets/';
                $favPath = $favDir . 'favicon.png';
                if (@imagepng($out, $favPath, 7)) {
                    setting_set($pdo, 'favicon_path', '/assets/favicon.png');
                    $flash = '✅ Favicon berhasil diupload dan dikompres ke 64×64px!';
                } else {
                    $flash = '❌ Gagal menyimpan favicon ke /assets/. Cek permission folder.';
                    $flashType = 'error';
                }
            }
        }
    }

    // ── Save SEO meta settings ─────────────────────────────────
    if ($action === 'save_seo') {
        $keys = ['seo_title','seo_description','seo_og_image','seo_robots',
                 'seo_keywords','seo_author','seo_twitter_card'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) setting_set($pdo, $k, trim($_POST[$k]));
        }
        // Robots.txt write
        if (isset($_POST['robots_txt'])) {
            file_put_contents(dirname(__DIR__) . '/robots.txt', trim($_POST['robots_txt']));
        }
        if (!$flash) $flash = '✅ Pengaturan SEO disimpan!';
    }
}

$robots_txt_content = '';
$robots_path = dirname(__DIR__) . '/robots.txt';
if (file_exists($robots_path)) {
    $robots_txt_content = file_get_contents($robots_path);
} else {
    $robots_txt_content = "User-agent: *\nAllow: /\nDisallow: /console/\nDisallow: /auth/\nDisallow: /uploads/\n\nSitemap: " . base_url('sitemap.xml');
}

$pageTitle  = 'SEO Management';
$activePage = 'seo';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h5 class="mb-0 fw-bold">🔍 SEO Management</h5>
  <div style="font-size:12px;color:#666;margin-top:2px">Kelola meta tag, Open Graph, dan robots.txt</div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
<div class="row g-3">

  <!-- Meta Tags -->
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">📄 Meta Tags Global</span></div>
      <div class="c-card-body">
        <div class="c-form-group">
          <label class="c-label">Site Title (Title Tag)</label>
          <input type="text" name="seo_title" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_title','TontonKuy')) ?>"
                 placeholder="TontonKuy — Nonton Video, Dapat Reward!">
          <div style="font-size:11px;color:#666;margin-top:3px">Rekomendasi: 50–60 karakter</div>
        </div>
        <div class="c-form-group">
          <label class="c-label">Meta Description</label>
          <textarea name="seo_description" class="c-form-control" rows="3"
            placeholder="Deskripsi singkat website..."><?= htmlspecialchars($s('seo_description')) ?></textarea>
          <div style="font-size:11px;color:#666;margin-top:3px">Rekomendasi: 120–158 karakter</div>
        </div>
        <div class="c-form-group">
          <label class="c-label">Meta Keywords (opsional)</label>
          <input type="text" name="seo_keywords" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_keywords')) ?>"
                 placeholder="nonton video reward, tonton dapat uang, ...">
        </div>
        <div class="c-form-group">
          <label class="c-label">Meta Author</label>
          <input type="text" name="seo_author" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_author','TontonKuy')) ?>">
        </div>
        <div class="c-form-group">
          <label class="c-label">Robots</label>
          <select name="seo_robots" class="c-form-control">
            <?php foreach (['index,follow','noindex,nofollow','index,nofollow','noindex,follow'] as $r): ?>
            <option value="<?= $r ?>" <?= $s('seo_robots','index,follow')===$r?'selected':'' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- OG / Social -->
  <div class="col-md-6">
    <div class="c-card mb-3">
      <div class="c-card-header"><span class="c-card-title">📱 Open Graph & Social</span></div>
      <div class="c-card-body">
        <div class="c-form-group">
          <label class="c-label">OG Image URL</label>
          <input type="text" name="seo_og_image" class="c-form-control"
                 value="<?= htmlspecialchars($s('seo_og_image')) ?>"
                 placeholder="<?= htmlspecialchars(base_url('og-image.jpg')) ?>">
          <div style="font-size:11px;color:#666;margin-top:3px">Ukuran ideal: 1200×630px</div>
        </div>
        <?php $og = $s('seo_og_image'); if ($og): ?>
        <img src="<?= htmlspecialchars($og) ?>" alt="OG Preview" style="width:100%;border-radius:8px;border:1px solid #1f2235;margin-top:8px">
        <?php endif; ?>
        <div class="c-form-group" style="margin-top:12px">
          <label class="c-label">Twitter Card Type</label>
          <select name="seo_twitter_card" class="c-form-control">
            <option value="summary_large_image" <?= $s('seo_twitter_card')==='summary_large_image'?'selected':'' ?>>summary_large_image</option>
            <option value="summary" <?= $s('seo_twitter_card','summary_large_image')==='summary'?'selected':'' ?>>summary</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Preview -->
    <div class="c-card" style="border-color:#2563eb22">
      <div class="c-card-header"><span class="c-card-title">👁 SERP Preview</span></div>
      <div class="c-card-body">
        <div style="font-family:Arial,sans-serif;max-width:400px">
          <div style="font-size:12px;color:#666;margin-bottom:2px"><?= htmlspecialchars(rtrim(base_url(), '/')) ?></div>
          <div style="font-size:17px;color:#1a73e8;font-weight:500;margin-bottom:4px" id="preview-title"><?= htmlspecialchars($s('seo_title','TontonKuy')) ?></div>
          <div style="font-size:13px;color:#555;line-height:1.4" id="preview-desc"><?= htmlspecialchars($s('seo_description','')) ?></div>
        </div>
        <script>
        document.querySelector('[name="seo_title"]').addEventListener('input', function(){
          document.getElementById('preview-title').textContent = this.value || 'Title';
        });
        document.querySelector('[name="seo_description"]').addEventListener('input', function(){
          document.getElementById('preview-desc').textContent = this.value || 'Description';
        });
        </script>
      </div>
    </div>
  </div>

  <!-- Robots.txt -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">🤖 robots.txt</span>
        <span style="font-size:11px;color:#666">Edit langsung akan disimpan ke file robots.txt</span>
      </div>
      <div class="c-card-body">
        <textarea name="robots_txt" class="c-form-control" rows="8"
          style="font-family:monospace;font-size:12px"><?= htmlspecialchars($robots_txt_content) ?></textarea>
      </div>
    </div>
  </div>

  <!-- Favicon -->
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">🖼️ Favicon Website</span>
        <span style="font-size:11px;color:#666">Akan dikompres otomatis ke 64×64px · Maks 5MB</span>
      </div>
      <div class="c-card-body">
        <div class="row align-items-center g-3">
          <!-- Preview -->
          <div class="col-auto">
            <?php $fav = $s('favicon_path'); if ($fav): ?>
            <div style="width:64px;height:64px;border:1.5px solid #1f2235;border-radius:8px;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center">
              <img src="<?= htmlspecialchars($fav) ?>?v=<?= time() ?>" style="width:100%;height:100%;object-fit:contain" alt="Favicon">
            </div>
            <div style="font-size:10px;color:#666;text-align:center;margin-top:4px">Current</div>
            <?php else: ?>
            <div style="width:64px;height:64px;border:1.5px dashed #444;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#555;font-size:22px">
              🌐
            </div>
            <div style="font-size:10px;color:#666;text-align:center;margin-top:4px">Belum ada</div>
            <?php endif; ?>
          </div>
          <!-- Upload form (separate enctype) -->
          <div class="col">
            <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload_favicon">
              <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon"
                     class="c-form-control" style="flex:1;min-width:180px" required>
              <button type="submit" class="btn btn-sm text-white" style="background:var(--brand);white-space:nowrap">⬆️ Upload Favicon</button>
            </form>
            <div style="font-size:11px;color:#666;margin-top:6px">
              Format: PNG, JPG, WEBP, GIF · Sistem akan resize &amp; kompres otomatis ke 64×64px PNG
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12">
    <button type="submit" class="btn btn-sm text-white px-4" style="background:var(--brand)">💾 Simpan Semua SEO Settings</button>
  </div>
</div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
