<?php
declare(strict_types=1);

final class Totp
{
    public static function verify(string $base32Secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        return self::matchingCounter($base32Secret, $code, $timestamp, $window) !== null;
    }

    /** Returns the matching 30-second counter so the caller can prevent TOTP replay. */
    public static function matchingCounter(string $base32Secret, string $code, ?int $timestamp = null, int $window = 1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code) || $window < 0 || $window > 2) {
            return null;
        }
        $key = self::decodeBase32($base32Secret);
        if ($key === '' || strlen($key) < 10) {
            return null;
        }
        $step = intdiv($timestamp ?? time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $counter = $step + $offset;
            if ($counter < 0) continue;
            $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
            $hash = hash_hmac('sha1', $binaryCounter, $key, true);
            $index = ord($hash[19]) & 0x0f;
            $number = ((ord($hash[$index]) & 0x7f) << 24)
                | ((ord($hash[$index + 1]) & 0xff) << 16)
                | ((ord($hash[$index + 2]) & 0xff) << 8)
                | (ord($hash[$index + 3]) & 0xff);
            $candidate = str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($candidate, $code)) return $counter;
        }
        return null;
    }

    private static function decodeBase32(string $input): string
    {
        $input = strtoupper(rtrim(trim($input), '='));
        if ($input === '' || !preg_match('/^[A-Z2-7]+$/D', $input)) return '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (str_split($input) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) return '';
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $output;
    }
}
