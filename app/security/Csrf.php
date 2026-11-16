<?php
declare(strict_types=1);

final class Csrf
{
    public const HEADER_NAME = 'X-CSRF-Token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be started before CSRF token access.');
        }
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || !preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function rotate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be started before CSRF token rotation.');
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    public static function assertValid(): void
    {
        self::assertSameOriginSignals();
        $expected = $_SESSION['csrf_token'] ?? null;
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !is_string($provided) || !preg_match('/^[a-f0-9]{64}$/D', $provided) || !hash_equals($expected, $provided)) {
            throw new ApiProblemException(403, 'CSRF_VALIDATION_FAILED', 'Forbidden', 'اعتبار درخواست تأیید نشد؛ صفحه را تازه‌سازی و دوباره تلاش کنید.');
        }
    }

    private static function assertSameOriginSignals(): void
    {
        $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($fetchSite === 'cross-site') {
            throw new ApiProblemException(403, 'CROSS_SITE_REQUEST_REJECTED', 'Forbidden', 'درخواست بین‌مبدأ پذیرفته نمی‌شود.');
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_string($origin) || $origin === '') {
            return;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiProblemException(403, 'ORIGIN_REJECTED', 'Forbidden', 'مبدأ درخواست پذیرفته نمی‌شود.');
        }
        $originScheme = strtolower((string) $parts['scheme']);
        $originHost = strtolower((string) $parts['host']);
        $originPort = isset($parts['port']) ? (int) $parts['port'] : (($originScheme === 'https') ? 443 : 80);
        $configured = defined('PUBLIC_BASE_URL') ? (string) PUBLIC_BASE_URL : '';
        if ($configured !== '') {
            $base = parse_url($configured);
            $expectedScheme = strtolower((string) ($base['scheme'] ?? ''));
            $expectedHost = strtolower((string) ($base['host'] ?? ''));
            $expectedPort = isset($base['port']) ? (int) $base['port'] : (($expectedScheme === 'https') ? 443 : 80);
        } else {
            $expectedScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
            $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($hostHeader === '' || preg_match('/[\r\n\s]/', $hostHeader)) {
                throw new ApiProblemException(403, 'ORIGIN_REJECTED', 'Forbidden', 'مبدأ درخواست پذیرفته نمی‌شود.');
            }
            $hostParts = parse_url($expectedScheme . '://' . $hostHeader);
            $expectedHost = strtolower((string) ($hostParts['host'] ?? ''));
            $expectedPort = isset($hostParts['port']) ? (int) $hostParts['port'] : (($expectedScheme === 'https') ? 443 : 80);
        }
        if ($originScheme !== $expectedScheme || $originHost !== $expectedHost || $originPort !== $expectedPort) {
            throw new ApiProblemException(403, 'ORIGIN_REJECTED', 'Forbidden', 'مبدأ درخواست پذیرفته نمی‌شود.');
        }
    }
}
