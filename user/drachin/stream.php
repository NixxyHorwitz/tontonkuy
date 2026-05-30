<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';

// Must be logged in
require_auth($pdo);

$url = $_GET['url'] ?? '';
if (!$url) {
    http_response_code(400);
    die('Missing url parameter');
}

// NOTE: $_GET already URL-decodes once. Do NOT urldecode() again — it would break the inner encoded URL params.

// Security: only allow proxying from known trusted domains
$allowed = [
    'api.sansekai.my.id',
    'hwztvideo.dramaboxdb.com',
    'cdn.dramabox.com',
    'pinedrama.com',
    'cdn.pinedrama.com',
    'reelshort.com',
    'cdn.reelshort.com',
    'shortmax.com',
    'goodshort.com',
    'freereels.com',
    'dramnova.com',
    'dramanova.com',
];

$parsed = parse_url($url);
$host = $parsed['host'] ?? '';

$allowed_host = false;
foreach ($allowed as $domain) {
    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        $allowed_host = true;
        break;
    }
}

if (!$allowed_host) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => "Domain $host tidak diizinkan untuk di-proxy"]));
}

// ── Handle Range request (untuk seeking video) ──
$range = $_SERVER['HTTP_RANGE'] ?? '';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,    // stream langsung ke output
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_BUFFERSIZE     => 128 * 1024,
    CURLOPT_TIMEOUT        => 0, // no timeout for streaming
    CURLOPT_CONNECTTIMEOUT => 15,
]);

// Forward Range header jika ada (untuk seeking)
if ($range) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Range: $range"]);
}

// Forward upstream headers ke browser
$responseCode = 200;
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseCode) {
    $trimmed = trim($header);
    $lower = strtolower($trimmed);

    // Tangkap HTTP status code
    if (str_starts_with($lower, 'http/')) {
        preg_match('/\d{3}/', $trimmed, $m);
        if (!empty($m[0])) $responseCode = (int)$m[0];
        return strlen($header);
    }

    // Forward header yang relevan
    $allowed_headers = [
        'content-type',
        'content-length',
        'content-range',
        'accept-ranges',
        'cache-control',
        'last-modified',
        'etag',
    ];

    foreach ($allowed_headers as $h) {
        if (str_starts_with($lower, $h)) {
            if (!headers_sent()) header($trimmed);
            return strlen($header);
        }
    }

    return strlen($header);
});

// Set CORS agar browser bisa load video dari domain kita
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Range');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
}

// Jalankan dan langsung pipe ke output browser
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Set proper status code
if ($httpCode >= 400 && !headers_sent()) {
    http_response_code($httpCode);
}
