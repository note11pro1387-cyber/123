<?php
declare(strict_types=1);

/**
 * Normalize an explicitly configured public base URL for canonical/absolute links.
 * Returns an empty string unless HTTPS is used (HTTP is allowed only for local development).
 * Never derives a URL from an inbound Host header.
 */
function mrneonNormalizePublicBaseUrl(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '' || preg_match('/[\r\n]/', $candidate)) {
        return '';
    }

    $parts = parse_url($candidate);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower(trim((string) $parts['host'], '[]'));
    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $isLocalHost)) {
        return '';
    }

    if (!$isLocalHost && filter_var($host, FILTER_VALIDATE_IP) === false &&
        filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return '';
    }

    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        return '';
    }

    $authorityHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $base = $scheme . '://' . $authorityHost;
    if (isset($parts['port'])) {
        $base .= ':' . (int) $parts['port'];
    }
    $path = isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '';
    return rtrim($base . $path, '/');
}
