<?php
declare(strict_types=1);

// ============================================================
// BOOTSTRAP — TontonKuy Platform
// ============================================================

// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
        putenv(trim($k) . '=' . trim($v));
    }
}

// Timezone — WIB (UTC+7)
date_default_timezone_set('Asia/Jakarta');

// Session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// PDO connection
function createPdo(): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_DATABASE'] ?? 'tonton'
    );
    return new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

try {
    $pdo = createPdo();
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    http_response_code(503);
    die('<h1 style="font-family:sans-serif">⚠️ Database Error</h1><p>Please start MySQL and check .env config</p><pre style="background:#f5f5f5;padding:12px;border-radius:6px">' . htmlspecialchars($e->getMessage()) . '</pre>');
}

// ============================================================
// HELPERS
// ============================================================

/** Read setting from DB with static cache */
function setting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $cache[$key] = ($v !== false ? (string)$v : $default);
    } catch (\Throwable) { return $cache[$key] = $default; }
}

/** Upsert setting */
function setting_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
        ->execute([$key, $value, $value]);
}

/** Format currency IDR */
function format_rp(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/** Generate CSRF token */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Render CSRF hidden input */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Verify CSRF token */
function csrf_verify(): bool {
    $tok = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals(csrf_token(), (string)$tok);
}

/** Enforce CSRF on POST — aborts with 403 if invalid */
function csrf_enforce(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        http_response_code(403);
        die('<h1>403 Invalid CSRF Token</h1>');
    }
}

/** HTTP redirect (clean URL) */
function redirect(string $url): never {
    header("Location: {$url}");
    exit;
}

/** Generate unique referral code */
function generate_referral_code(PDO $pdo): string {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE referral_code=?");
        $exists->execute([$code]);
    } while ($exists->fetchColumn());
    return $code;
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $scheme = 'https';
        }
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $root   = rtrim($scheme . '://' . $host . '/', '/') . '/';
        return $root . ltrim($path, '/');
    }
}

/** Extract YouTube video ID from various URL formats */
function extract_youtube_id(string $url): string {
    $patterns = [
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
        '/^([a-zA-Z0-9_-]{11})$/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) return $m[1];
    }
    return '';
}

/** Get YouTube thumbnail URL */
function yt_thumb(string $youtube_id): string {
    return "https://img.youtube.com/vi/{$youtube_id}/maxresdefault.jpg";
}

/** Get logged-in user or null */
function auth_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user !== null) return $user;
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND is_active=1");
    $s->execute([$_SESSION['user_id']]);
    return $user = ($s->fetch() ?: null);
}

/** Require user auth — redirects to /login if not logged in */
function require_auth(PDO $pdo): array {
    $u = auth_user($pdo);
    if (!$u) redirect('/login');
    return $u;
}

/** Get user's daily watch limit based on active membership */
function user_watch_limit(PDO $pdo, array $user): int {
    if ($user['membership_id'] && $user['membership_expires_at']
        && strtotime((string)$user['membership_expires_at']) > time()) {
        $s = $pdo->prepare("SELECT watch_limit FROM memberships WHERE id=? AND is_active=1");
        $s->execute([$user['membership_id']]);
        $v = $s->fetchColumn();
        if ($v !== false) return (int)$v;
    }
    return (int) setting($pdo, 'free_watch_limit', '5');
}

/** Count videos user watched today */
function user_watch_today(PDO $pdo, array $user): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
    $s->execute([$user['id']]);
    return (int)$s->fetchColumn();
}

/** Get user membership sort_order level (0 = Free) */
function user_membership_level(PDO $pdo, array $user): int {
    if ($user['membership_id'] && $user['membership_expires_at']
        && strtotime((string)$user['membership_expires_at']) > time()) {
        $s = $pdo->prepare("SELECT sort_order FROM memberships WHERE id=?");
        $s->execute([$user['membership_id']]);
        $v = $s->fetchColumn();
        if ($v !== false) return (int)$v;
    }
    return 0;
}

/** Check if site is in maintenance mode */
function is_maintenance(PDO $pdo): bool {
    return setting($pdo, 'maintenance_mode', '0') === '1';
}

/** Check if withdrawals are currently locked by time window */
function is_wd_locked(PDO $pdo): bool {
    $start = setting($pdo, 'wd_lock_start', '');
    $end   = setting($pdo, 'wd_lock_end', '');
    if ($start === '' || $end === '') return false;
    $now   = (int)date('Hi'); // e.g. 2230
    $s     = (int)str_replace(':', '', $start);
    $e     = (int)str_replace(':', '', $end);
    if ($s <= $e) return $now >= $s && $now < $e;
    // crosses midnight: e.g. 22:00 → 06:00
    return $now >= $s || $now < $e;
}

/**
 * Generate dynamic QRIS by modifying the raw QRIS string amount field (Tag 54).
 * Returns base64-encoded QR PNG using a free QR API, or empty string on failure.
 */
function qris_with_amount(string $qris_raw, int $amount): string {
    if (empty($qris_raw)) return '';
    // Remove existing CRC (last 4 hex chars after 6304)
    $pos = strpos($qris_raw, '6304');
    if ($pos !== false) {
        $qris_raw = substr($qris_raw, 0, $pos);
    }
    // Remove existing Tag 54 (Transaction Amount) if present
    $qris_raw = preg_replace('/5402\d{2}[\d.]+/', '', $qris_raw);
    // Build Tag 54 with amount
    $amt_str  = (string)$amount;
    $tag54    = '54' . str_pad((string)strlen($amt_str), 2, '0', STR_PAD_LEFT) . $amt_str;
    // Insert before tag 58 (Country Code)
    $qris_raw = preg_replace('/(5802)/', $tag54 . '$1', $qris_raw);
    // Recalculate CRC-16/CCITT-FALSE
    $qris_raw .= '6304';
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($qris_raw); $i++) {
        $crc ^= (ord($qris_raw[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
        }
        $crc &= 0xFFFF;
    }
    $qris_final = $qris_raw . strtoupper(sprintf('%04X', $crc));
    return $qris_final;
}

/** Get logged-in admin or null */
function auth_admin(): ?array {
    return $_SESSION['admin'] ?? null;
}

/** Require admin auth — redirects to /console/login */
function require_admin(): array {
    $a = auth_admin();
    if (!$a) redirect('/console/login');
    return $a;
}


/** Send message to Telegram Admin Group/Channel */
function send_telegram_notif(PDO $pdo, string $message, array $inline_keyboard = []): void {
    $token = setting($pdo, 'tg_bot_token', '');
    $chat_id = setting($pdo, 'tg_chat_id', '');
    if (!$token || !$chat_id) return;
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    if (!empty($inline_keyboard)) {
        $post['reply_markup'] = json_encode(['inline_keyboard' => $inline_keyboard]);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_exec($ch);
    curl_close($ch);
}

/** Track a page view (fire-and-forget, safe to fail) */
function track_pageview(PDO $pdo, string $path): void {
    try {
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip_hash = hash('sha256', $ip . date('Y-m-d')); // rotates daily for privacy
        $ref     = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
        // Skip bots
        if (preg_match('/bot|crawl|spider|slurp|baidu|bing|google/i', $ua)) return;
        $pdo->prepare("INSERT INTO page_views (path,ip_hash,referrer,user_agent) VALUES (?,?,?,?)")
            ->execute([$path, $ip_hash, $ref, $ua]);
    } catch (\Throwable) {
        // Silently fail — never break user experience for analytics
    }
}

require_once __DIR__ . '/depo_canceller.php';
