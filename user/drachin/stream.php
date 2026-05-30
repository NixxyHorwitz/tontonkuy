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
    'cdn.pinedrama.com',
    'reelshort.com',
    'shortmax.com',
    'goodshort.com',
    'freereels.com',
    'dramnova.com',
    'cdn.crunchyroll.com',
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
    die("Domain $host tidak diizinkan untuk di-proxy");
}

// Stream via cURL — support Range requests for seekable video
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
curl_setopt($ch, CURLOPT_BUFFERSIZE, 128 * 1024);

// Forward Range header if present (for seek)
if (!empty($_SERVER['HTTP_RANGE'])) {
    curl_setopt($ch, CURLOPT_RANGE, str_replace('bytes=', '', $_SERVER['HTTP_RANGE']));
}

// Write response headers from upstream to client
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) {
    $lower = strtolower($header);
    // Forward relevant headers
    if (
        str_starts_with($lower, 'content-type') ||
        str_starts_with($lower, 'content-length') ||
        str_starts_with($lower, 'content-range') ||
        str_starts_with($lower, 'accept-ranges')
    ) {
        header(trim($header));
    }
    return strlen($header);
});

// Allow CORS for our own video player
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD');

curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 400) {
    http_response_code($httpCode);
}
