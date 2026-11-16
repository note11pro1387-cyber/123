<?php
declare(strict_types=1);

/** Central reset-only authorization policy; OTP lifetime remains the owner-fixed 120 seconds. */
final class RecoveryPolicy
{
    public const DEFAULT_RESET_AUTHORIZATION_SECONDS = 600;
    public const GENERIC_START_MESSAGE = 'اگر اطلاعات واردشده با حسابی مطابقت داشته باشد و کانال تأییدشده‌ای برای آن در دسترس باشد، دستورالعمل ادامه ارسال می‌شود.';

    public static function resetAuthorizationSeconds(): int
    {
        $raw = getenv('MRNEON_RECOVERY_TOKEN_TTL_SECONDS');
        if ($raw === false || $raw === '') return self::DEFAULT_RESET_AUTHORIZATION_SECONDS;
        if (!is_string($raw) || preg_match('/^[0-9]{1,4}$/D', $raw) !== 1) {
            throw new ApiProblemException(503, 'RECOVERY_POLICY_INVALID', 'Service unavailable', 'سیاست بازیابی رمز روی سرور معتبر نیست.');
        }
        $value = (int) $raw;
        if ($value < 60 || $value > 900) {
            throw new ApiProblemException(503, 'RECOVERY_POLICY_INVALID', 'Service unavailable', 'سیاست بازیابی رمز خارج از محدودهٔ مجاز است.');
        }
        return $value;
    }

    public static function otpLifetimeSeconds(): int
    {
        return VerificationPolicy::OTP_TTL_SECONDS;
    }

    public static function maxDeliveryAttempts(): int
    {
        return VerificationPolicy::maxDeliveryAttempts();
    }

    public static function resendCooldownSeconds(): int
    {
        return VerificationPolicy::resendCooldownSeconds();
    }
}
