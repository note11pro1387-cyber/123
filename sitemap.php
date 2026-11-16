<?php
declare(strict_types=1);

require_once __DIR__ . '/app/support/PublicBaseUrl.php';
$publicBaseUrl = mrneonNormalizePublicBaseUrl((string) (getenv('MRNEON_PUBLIC_BASE_URL') ?: ''));
if ($publicBaseUrl === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "Sitemap unavailable until the verified public base URL is configured.";
    exit;
}

$publicHomeUrl = $publicBaseUrl . '/';
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
$location = htmlspecialchars($publicHomeUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '  <url><loc>' . $location . '</loc></url>' . "\n";
echo '</urlset>' . "\n";
