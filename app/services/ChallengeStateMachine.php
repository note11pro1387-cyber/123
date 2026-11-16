<?php
declare(strict_types=1);

/** Pure lifecycle rules shared by services and unit tests. Persisted values follow Wave 2 schema. */
final class ChallengeStateMachine
{
    public const CHALLENGE_PENDING_DELIVERY = 'pending_delivery';
    public const DELIVERY_PREPARED = 'prepared';
    public const DELIVERY_ACCEPTED = 'accepted';
    public const DELIVERY_REJECTED = 'rejected';
    public const DELIVERY_UNKNOWN = 'unknown';
    public const DELIVERY_NOT_CONFIGURED = 'not_configured';

    private const DELIVERY_TERMINAL = ['accepted', 'rejected', 'unknown', 'not_configured'];

    public static function isConsumable(array $challenge, DateTimeImmutable $now): bool
    {
        if (($challenge['purpose'] ?? null) !== 'registration'
            || ($challenge['subject_type'] ?? null) !== 'registration_attempt'
            || ($challenge['state'] ?? null) !== 'active'
            || ($challenge['used_at'] ?? null) !== null) {
            return false;
        }
        $expiry = self::parseUtc($challenge['expires_at'] ?? null);
        if (!$expiry instanceof DateTimeImmutable || $now >= $expiry) {
            return false;
        }
        return (int) ($challenge['attempts'] ?? 0) < (int) ($challenge['max_attempts'] ?? 0);
    }

    public static function isPasswordResetConsumable(array $challenge, DateTimeImmutable $now): bool
    {
        if (($challenge['purpose'] ?? null) !== 'password_reset'
            || ($challenge['subject_type'] ?? null) !== 'user'
            || ($challenge['state'] ?? null) !== 'active'
            || ($challenge['used_at'] ?? null) !== null) {
            return false;
        }
        $expiry = self::parseUtc($challenge['expires_at'] ?? null);
        if (!$expiry instanceof DateTimeImmutable || $now >= $expiry) return false;
        return (int) ($challenge['attempts'] ?? 0) < (int) ($challenge['max_attempts'] ?? 0);
    }

    public static function deliveryTransitionAllowed(string $from, string $to): bool
    {
        return $from === self::DELIVERY_PREPARED && in_array($to, self::DELIVERY_TERMINAL, true);
    }

    public static function canPrepareDelivery(string $purpose): bool
    {
        return in_array($purpose, ['registration', 'password_reset'], true);
    }

    public static function challengeTransitionAllowed(string $purpose, string $from, string $to, bool $providerAccepted = false): bool
    {
        if (!self::canPrepareDelivery($purpose)) return false;
        if ($from === self::CHALLENGE_PENDING_DELIVERY) {
            if ($to === 'revoked') return true;
            return $to === 'active' && $providerAccepted;
        }
        // A reset challenge has an intermediate reset-only capability state; a
        // registration challenge must never enter that state.
        if ($purpose === 'registration') {
            return $from === 'active' && in_array($to, ['used', 'revoked', 'exhausted'], true);
        }
        if ($purpose === 'password_reset') {
            if ($from === 'active') {
                return in_array($to, ['verified_reset', 'revoked', 'exhausted'], true);
            }
            if ($from === 'verified_reset') {
                return in_array($to, ['used', 'revoked'], true);
            }
        }
        return false;
    }

    private static function parseUtc(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
