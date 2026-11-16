<?php
declare(strict_types=1);

/** Generates and fingerprints a high-entropy, reset-only capability. Raw values must stay in process memory. */
final class ResetTokenGenerator
{
    public const TOKEN_BYTES = 32;
    public const TOKEN_HEX_LENGTH = 64;

    public static function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public static function isValid(string $token): bool
    {
        return strlen($token) === self::TOKEN_HEX_LENGTH && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    public static function hash(string $token): string
    {
        if (!self::isValid($token)) {
            throw new InvalidArgumentException('Reset-only authorization token format is invalid.');
        }
        return hash('sha256', $token);
    }
}
