<?php
declare(strict_types=1);

/** Server-owned registration policies. Values are centralized and bounded. */
final class RegistrationPolicy
{
    public const DEFAULT_ATTEMPT_TTL_SECONDS = 1800; // Provisional 30-minute attempt expiry; distinct from the 120-second OTP TTL.
    public const MIN_ATTEMPT_TTL_SECONDS = 300;
    public const MAX_ATTEMPT_TTL_SECONDS = 86400;

    public static function termsVersion(): string
    {
        $value = getenv('MRNEON_TERMS_VERSION');
        $version = is_string($value) ? trim($value) : '';
        if ($version === '' || strlen($version) > 64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $version) !== 1) {
            throw new ApiProblemException(503, 'TERMS_POLICY_UNAVAILABLE', 'Service unavailable', 'نسخهٔ تأییدشدهٔ قوانین و حریم خصوصی روی سرور پیکربندی نشده است.');
        }
        return $version;
    }

    public static function attemptTtlSeconds(): int
    {
        $raw = getenv('MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_ATTEMPT_TTL_SECONDS;
        }
        if (!is_string($raw) || preg_match('/^[0-9]{1,6}$/D', $raw) !== 1) {
            throw new RuntimeException('Registration attempt TTL configuration is invalid.');
        }
        $ttl = (int) $raw;
        if ($ttl < self::MIN_ATTEMPT_TTL_SECONDS || $ttl > self::MAX_ATTEMPT_TTL_SECONDS) {
            throw new RuntimeException('Registration attempt TTL configuration is outside permitted bounds.');
        }
        return $ttl;
    }
}
