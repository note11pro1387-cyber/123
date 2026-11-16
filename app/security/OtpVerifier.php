<?php
declare(strict_types=1);

/** Context-bound OTP HMAC. The secret is distinct from rate-limit and session keys. */
final class OtpVerifier
{
    private const KEY_ENV = 'MRNEON_OTP_HMAC_KEY';
    private const CONTEXT_VERSION = 'mr-neon-otp:v1';

    public function __construct(private readonly ?string $keyOverride = null)
    {
    }

    public function mac(string $challengeId, string $purpose, string $channel, string $targetCanonical, string $code): string
    {
        self::assertContext($challengeId, $purpose, $channel, $targetCanonical, $code);
        return hash_hmac('sha256', self::message($challengeId, $purpose, $channel, $targetCanonical, $code), $this->key());
    }

    public function matches(string $storedMac, string $challengeId, string $purpose, string $channel, string $targetCanonical, string $code): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $storedMac) || !OtpGenerator::isValidFormat($code)) {
            // Keep malformed attempts out of hash_equals to avoid accidental format coercion.
            return false;
        }
        $expected = $this->mac($challengeId, $purpose, $channel, $targetCanonical, $code);
        return hash_equals($storedMac, $expected);
    }

    /**
     * Stable pseudonymous client reference for a Recovery context. This is deliberately NOT a
     * database Challenge ID and is never an authorization token; verification still resolves the
     * account, verified channel, active Challenge, and OTP independently on the server.
     */
    public function recoveryReference(string $method, string $normalizedIdentifier): string
    {
        if (!in_array($method, ['email', 'telegram'], true)
            || $normalizedIdentifier === '' || strlen($normalizedIdentifier) > 254) {
            throw new InvalidArgumentException('Recovery reference context is invalid.');
        }
        $derivedKey = hash_hkdf('sha256', $this->key(), 32, 'mr-neon:recovery-reference:v1');
        return substr(hash_hmac('sha256', implode("\0", ['mr-neon:recovery-reference:v1', $method, $normalizedIdentifier]), $derivedKey), 0, 32);
    }

    /** A keyed destination fingerprint for delivery-attempt audit, not a recoverable identifier. */
    public function destinationFingerprint(string $purpose, string $channel, string $targetCanonical): string
    {
        if (!in_array($purpose, ['registration', 'password_reset'], true)
            || !in_array($channel, ['email', 'telegram'], true)
            || $targetCanonical === '' || strlen($targetCanonical) > 255) {
            throw new InvalidArgumentException('OTP destination context is invalid.');
        }
        return hash_hmac('sha256', self::CONTEXT_VERSION . "\0destination\0" . $purpose . "\0" . $channel . "\0" . $targetCanonical, $this->key());
    }

    public function assertConfigured(): void
    {
        $this->key();
    }

    private function key(): string
    {
        $key = $this->keyOverride;
        if ($key === null) {
            $value = getenv(self::KEY_ENV);
            $key = is_string($value) ? $value : '';
        }
        if (strlen($key) < 32) {
            throw new ApiProblemException(503, 'OTP_SECURITY_NOT_CONFIGURED', 'Service unavailable', 'تنظیم امن کد تأیید روی سرور کامل نیست.');
        }
        return $key;
    }

    private static function message(string $challengeId, string $purpose, string $channel, string $targetCanonical, string $code): string
    {
        return implode("\0", [self::CONTEXT_VERSION, $challengeId, $purpose, $channel, $targetCanonical, $code]);
    }

    private static function assertContext(string $challengeId, string $purpose, string $channel, string $targetCanonical, string $code): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $challengeId)
            || !in_array($purpose, ['registration', 'password_reset'], true)
            || !in_array($channel, ['email', 'telegram'], true)
            || $targetCanonical === '' || strlen($targetCanonical) > 255
            || !OtpGenerator::isValidFormat($code)) {
            throw new InvalidArgumentException('OTP verification context is invalid.');
        }
    }
}
