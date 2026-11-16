<?php
declare(strict_types=1);

require_once __DIR__ . '/app/support/PublicBaseUrl.php';

$publicBaseUrl = mrneonNormalizePublicBaseUrl((string) (getenv('MRNEON_PUBLIC_BASE_URL') ?: ''));
$basePath = $publicBaseUrl !== '' ? (string) (parse_url($publicBaseUrl, PHP_URL_PATH) ?: '') : '';

// Keep robots rules scoped to this deployment path. Unsupported path characters
// fail closed for the Sitemap directive rather than emitting a malformed URL.
$pathIsRobotsSafe = $basePath === '' || preg_match(
    "~^/(?:[A-Za-z0-9._!$&'()*+,;=:@/-]|%[A-Fa-f0-9]{2})*$~D",
    $basePath
) === 1;
if (!$pathIsRobotsSafe) {
    $publicBaseUrl = '';
    $basePath = '';
}

$basePath = rtrim($basePath, '/');
$pathPrefix = $basePath;
$allowedPath = $pathPrefix !== '' ? $pathPrefix . '/' : '/';

$disallowedPaths = [
    '/api.php',
    '/bin/',
    '/core/',
    '/data/',
    '/database/',
    '/docs/',
    '/reports/',
    '/tests/',
    '/tools/',
];

$lines = [
    '# MR NEON crawler policy. This file is NOT an access-control mechanism.',
    'User-agent: *',
    'Allow: ' . $allowedPath,
];
foreach ($disallowedPaths as $path) {
    $lines[] = 'Disallow: ' . $pathPrefix . $path;
}
if ($publicBaseUrl !== '') {
    $lines[] = '';
    $lines[] = 'Sitemap: ' . $publicBaseUrl . '/sitemap.php';
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo implode("\n", $lines) . "\n";
