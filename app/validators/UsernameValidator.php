<?php
declare(strict_types=1);

/** Canonical username rules shared by all new-registration consumers. */
final class UsernameValidator
{
    public static function display(string $input): string
    {
        $value = trim($input);
        if (strlen($value) > 64 || !preg_match('/^[A-Za-z0-9_]{4,20}$/D', $value)) {
            throw new ApiProblemException(422, 'USERNAME_INVALID', 'Unprocessable content', 'نام کاربری باید ۴ تا ۲۰ کاراکتر انگلیسی، عدد یا زیرخط باشد.');
        }
        return $value;
    }

    public static function normalize(string $input): string
    {
        return strtolower(self::display($input));
    }
}
