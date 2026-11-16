<?php
declare(strict_types=1);

/** Strict Iranian-mobile normalization. Invalid characters are rejected, never stripped blindly. */
final class PhoneValidator
{
    private const DIGIT_MAP = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function normalize(string $input): string
    {
        $canonical = self::canonicalOrNull($input);
        if ($canonical === null) {
            throw new ApiProblemException(422, 'PHONE_INVALID', 'Unprocessable content', 'شماره تلفن همراه معتبر نیست. شماره را با قالب ایران وارد کنید.');
        }
        return $canonical;
    }

    public static function canonicalOrNull(string $input): ?string
    {
        if (strlen($input) > 80 || preg_match('//u', $input) !== 1) {
            return null;
        }
        $value = strtr(trim($input), self::DIGIT_MAP);
        // Permit only common display separators. Alphabetic or other punctuation survives and fails below.
        $compacted = preg_replace('/[\s\p{Z}().\-]+/u', '', $value);
        if (!is_string($compacted) || $compacted === '' || preg_match('/^\+?[0-9]+$/D', $compacted) !== 1) {
            return null;
        }

        if (preg_match('/^09[0-9]{9}$/D', $compacted) === 1) {
            $compacted = '+98' . substr($compacted, 1);
        } elseif (preg_match('/^9[0-9]{9}$/D', $compacted) === 1) {
            $compacted = '+98' . $compacted;
        } elseif (preg_match('/^989[0-9]{9}$/D', $compacted) === 1) {
            $compacted = '+' . $compacted;
        } elseif (preg_match('/^00989[0-9]{9}$/D', $compacted) === 1) {
            $compacted = '+' . substr($compacted, 2);
        }

        return preg_match('/^\+989[0-9]{9}$/D', $compacted) === 1 ? $compacted : null;
    }
}
