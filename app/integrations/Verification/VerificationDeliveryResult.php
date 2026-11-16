<?php
declare(strict_types=1);

/** Typed, allowlisted result from an implementation of VerificationTransportInterface. */
final class VerificationDeliveryResult
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';
    public const NOT_CONFIGURED = 'not_configured';

    private function __construct(
        public readonly string $status,
        public readonly string $safeCode,
        public readonly ?int $retryAfterSeconds = null
    ) {
        if (!in_array($status, [self::ACCEPTED, self::REJECTED, self::UNKNOWN, self::NOT_CONFIGURED], true)) {
            throw new InvalidArgumentException('Verification transport status is unsupported.');
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $safeCode)) {
            throw new InvalidArgumentException('Verification transport safe code is invalid.');
        }
        if ($retryAfterSeconds !== null && ($status !== self::REJECTED || $retryAfterSeconds < 1 || $retryAfterSeconds > 3600)) {
            throw new InvalidArgumentException('Verification retry-after interval is invalid.');
        }
    }

    public static function accepted(): self
    {
        return new self(self::ACCEPTED, 'PROVIDER_ACCEPTED');
    }

    public static function rejected(string $safeCode = 'PROVIDER_REJECTED', ?int $retryAfterSeconds = null): self
    {
        if ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 3600)) {
            $retryAfterSeconds = null;
        }
        return new self(self::REJECTED, self::sanitizeCode($safeCode, 'PROVIDER_REJECTED'), $retryAfterSeconds);
    }

    public static function unknown(string $safeCode = 'PROVIDER_RESULT_UNKNOWN'): self
    {
        return new self(self::UNKNOWN, self::sanitizeCode($safeCode, 'PROVIDER_RESULT_UNKNOWN'));
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'PROVIDER_NOT_CONFIGURED');
    }

    private static function sanitizeCode(string $value, string $fallback): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $value) ? $value : $fallback;
    }
}
