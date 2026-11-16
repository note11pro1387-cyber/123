<?php
declare(strict_types=1);

/** Email validation and canonicalization aligned with UserChannelRepository::create(). */
final class EmailValidator
{
    public static function normalizeOptional(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }
        if (!is_string($input)) {
            throw new ApiProblemException(422, 'EMAIL_INVALID', 'Unprocessable content', 'قالب ایمیل معتبر نیست.');
        }
        $value = trim($input);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 254 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x20\x7F]/u', $value) === 1) {
            throw new ApiProblemException(422, 'EMAIL_INVALID', 'Unprocessable content', 'قالب ایمیل معتبر نیست.');
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiProblemException(422, 'EMAIL_INVALID', 'Unprocessable content', 'قالب ایمیل معتبر نیست.');
        }
        // Wave 2 stores normalized email destinations in lowercase; no provider-specific rewriting.
        return strtolower($value);
    }

    public static function requireValid(string $input): string
    {
        $value = self::normalizeOptional($input);
        if ($value === null) {
            throw new ApiProblemException(422, 'EMAIL_REQUIRED_FOR_CHANNEL', 'Unprocessable content', 'برای انتخاب کانال ایمیل، آدرس ایمیل معتبر الزامی است.');
        }
        return $value;
    }
}
