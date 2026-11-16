<?php
declare(strict_types=1);

/**
 * Centralized baseline security headers and an opt-in CSP Report-Only policy.
 *
 * The report-only policy is intentionally disabled by default and is emitted
 * only when MRNEON_ENV=staging and MRNEON_CSP_REPORT_ONLY=1. This class never
 * emits an enforcing Content-Security-Policy header.
 */
final class CspReportOnly
{
    public static function sendHtmlSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()', true);

        if (!self::isEnabledForStaging()) {
            return;
        }

        $reportUri = self::configuredReportUri();
        if ($reportUri === false) {
            error_log('MR NEON CSP Report-Only disabled: MRNEON_CSP_REPORT_URI must be an approved absolute HTTPS URL.');
            return;
        }

        $policy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self'",
            "script-src-elem 'self'",
            "script-src-attr 'none'",
            "style-src 'self'",
            "style-src-elem 'self'",
            "style-src-attr 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "manifest-src 'self'",
        ]);

        if (is_string($reportUri) && $reportUri !== '') {
            // Legacy report-uri is a compatibility fallback; Reporting-Endpoints
            // plus report-to enables the newer Reporting API where supported.
            header('Reporting-Endpoints: mrneon-csp="' . $reportUri . '"', true);
            $policy .= '; report-to mrneon-csp; report-uri ' . $reportUri;
        }

        header('Content-Security-Policy-Report-Only: ' . $policy, true);
    }

    private static function isEnabledForStaging(): bool
    {
        $environment = strtolower(trim((string) (getenv('MRNEON_ENV') ?: 'local')));
        $enabled = trim((string) (getenv('MRNEON_CSP_REPORT_ONLY') ?: ''));

        return $environment === 'staging' && $enabled === '1';
    }

    /**
     * @return string|false|null A validated HTTPS URL, false for an invalid value,
     *                           or null when no collector was configured.
     */
    private static function configuredReportUri(): string|false|null
    {
        $candidate = trim((string) (getenv('MRNEON_CSP_REPORT_URI') ?: ''));
        if ($candidate === '') {
            return null;
        }

        // Exclude control characters and header/directive delimiters before parsing.
        if (preg_match('/[\x00-\x20\x7f"\'<>;,\\\\]/', $candidate)) {
            return false;
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $host = trim((string) $parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            return false;
        }

        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            return false;
        }

        return $candidate;
    }
}
