<?php
declare(strict_types=1);

/** Generates a fixed-width six-digit OTP using PHP's cryptographically secure RNG. */
final class OtpGenerator
{
    public const DIGITS = 6;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function isValidFormat(string $code): bool
    {
        return preg_match('/^[0-9]{6}$/D', $code) === 1;
    }
}
