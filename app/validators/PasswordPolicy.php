<?php
declare(strict_types=1);

/** Single server-owned password policy for newly created accounts. Login compatibility is unchanged. */
final class PasswordPolicy
{
    public const MIN_CHARACTERS = 15;
    public const MAX_BYTES = 1024;
    public const BCRYPT_MAX_BYTES = 72;

    public static function validate(string $password): void
    {
        if ($password === '' || strlen($password) > self::MAX_BYTES || preg_match('//u', $password) !== 1) {
            throw self::invalidPassword();
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw self::invalidPassword();
        }
        $characters = preg_match_all('/./us', $password);
        if (!is_int($characters) || $characters < self::MIN_CHARACTERS) {
            throw self::invalidPassword();
        }

        if (!self::argon2idAvailable() && strlen($password) > self::BCRYPT_MAX_BYTES) {
            // Never allow bcrypt's historical 72-byte truncation semantics to silently collapse passwords.
            throw new ApiProblemException(422, 'PASSWORD_TOO_LONG_FOR_RUNTIME', 'Unprocessable content', 'رمز عبور برای الگوریتم امن موجود در این سرور بیش از حد طولانی است.');
        }
    }

    public static function hash(string $password): string
    {
        self::validate($password);
        $algorithm = self::argon2idAvailable() ? constant('PASSWORD_ARGON2ID') : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash) || $hash === '' || password_get_info($hash)['algoName'] === 'unknown') {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    private static function argon2idAvailable(): bool
    {
        return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
    }

    private static function invalidPassword(): ApiProblemException
    {
        return new ApiProblemException(422, 'PASSWORD_POLICY_VIOLATION', 'Unprocessable content', 'رمز عبور باید دست‌کم ۱۵ کاراکتر داشته باشد و از محدودیت طول مجاز بیشتر نباشد.');
    }
}
