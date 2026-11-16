<?php
declare(strict_types=1);

/** Central, bounded OTP and resend policy. The OTP lifetime itself is fixed by owner decision. */
final class VerificationPolicy
{
    public const OTP_TTL_SECONDS = 120;
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_RESEND_COOLDOWN_SECONDS = 60;
    public const DEFAULT_MAX_RESENDS = 3;
    public const DELIVERY_WINDOW_SECONDS = 3600;

    public static function maxAttempts(): int
    {
        return self::boundedInt('MRNEON_OTP_MAX_ATTEMPTS', self::DEFAULT_MAX_ATTEMPTS, 3, 10);
    }

    public static function resendCooldownSeconds(): int
    {
        return self::boundedInt('MRNEON_OTP_RESEND_COOLDOWN_SECONDS', self::DEFAULT_RESEND_COOLDOWN_SECONDS, 15, 300);
    }

    public static function maxResends(): int
    {
        return self::boundedInt('MRNEON_OTP_MAX_RESENDS', self::DEFAULT_MAX_RESENDS, 1, 10);
    }

    public static function maxDeliveryAttempts(): int
    {
        return self::maxResends() + 1;
    }

    public static function resendRetryAfter(?DateTimeImmutable $lastAcceptedAt, DateTimeImmutable $now): int
    {
        if (!$lastAcceptedAt instanceof DateTimeImmutable) {
            return 0;
        }
        $elapsedSeconds = $now->getTimestamp() - $lastAcceptedAt->getTimestamp();
        $elapsedMicros = ($elapsedSeconds * 1_000_000)
            + (int) $now->format('u')
            - (int) $lastAcceptedAt->format('u');
        $remainingMicros = (self::resendCooldownSeconds() * 1_000_000) - $elapsedMicros;
        // Round up so a resend never becomes available before the complete cooldown elapsed.
        return $remainingMicros > 0 ? (int) ceil($remainingMicros / 1_000_000) : 0;
    }

    public static function expiresAt(DateTimeImmutable $acceptedAt): DateTimeImmutable
    {
        return $acceptedAt->setTimezone(new DateTimeZone('UTC'))->modify('+' . self::OTP_TTL_SECONDS . ' seconds');
    }

    private static function boundedInt(string $name, int $default, int $minimum, int $maximum): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }
        if (!is_string($raw) || preg_match('/^[0-9]{1,4}$/D', $raw) !== 1) {
            throw new ApiProblemException(503, 'VERIFICATION_POLICY_INVALID', 'Service unavailable', 'سیاست تأیید روی سرور معتبر نیست.');
        }
        $value = (int) $raw;
        if ($value < $minimum || $value > $maximum) {
            throw new ApiProblemException(503, 'VERIFICATION_POLICY_INVALID', 'Service unavailable', 'سیاست تأیید روی سرور معتبر نیست.');
        }
        return $value;
    }
}
