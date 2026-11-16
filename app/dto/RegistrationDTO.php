<?php
declare(strict_types=1);

/** Validated registration input. The raw password is private and excluded from serialization. */
final class RegistrationDTO implements JsonSerializable
{
    private string $rawPassword;

    private function __construct(
        public readonly string $fullName,
        public readonly string $username,
        public readonly string $usernameNormalized,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly ?string $emailNormalized,
        public readonly string $selectedChannel,
        public readonly bool $termsAccepted,
        string $rawPassword
    ) {
        $this->rawPassword = $rawPassword;
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $allowed = ['fullName', 'username', 'phone', 'password', 'email', 'selectedChannel', 'termsAccepted'];
        $unknown = [];
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $unknown[] = is_string($key) ? $key : 'non_string_key';
            }
        }
        if ($unknown !== []) {
            throw new ApiProblemException(422, 'UNEXPECTED_REGISTRATION_FIELDS', 'Unprocessable content', 'درخواست ثبت‌نام شامل فیلدهای پشتیبانی‌نشده است.');
        }

        foreach (['fullName', 'username', 'phone', 'password', 'selectedChannel', 'termsAccepted'] as $required) {
            if (!array_key_exists($required, $input)) {
                throw new ApiProblemException(422, 'REGISTRATION_FIELD_REQUIRED', 'Unprocessable content', 'یکی از فیلدهای اجباری ثبت‌نام ارسال نشده است.');
            }
        }
        foreach (['fullName', 'username', 'phone', 'password', 'selectedChannel'] as $field) {
            if (!is_string($input[$field])) {
                throw new ApiProblemException(422, 'REGISTRATION_FIELD_TYPE_INVALID', 'Unprocessable content', 'نوع یکی از فیلدهای ثبت‌نام معتبر نیست.');
            }
        }
        if (!is_bool($input['termsAccepted']) || $input['termsAccepted'] !== true) {
            throw new ApiProblemException(422, 'TERMS_ACCEPTANCE_REQUIRED', 'Unprocessable content', 'پذیرش قوانین نمایش‌داده‌شده برای ادامه الزامی است.');
        }

        $name = trim($input['fullName']);
        if (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new ApiProblemException(422, 'FULL_NAME_INVALID', 'Unprocessable content', 'نام و نام خانوادگی معتبر نیست.');
        }
        $nameLength = preg_match_all('/./us', $name);
        if (!is_int($nameLength) || $nameLength < 3 || $nameLength > 160) {
            throw new ApiProblemException(422, 'FULL_NAME_INVALID', 'Unprocessable content', 'نام و نام خانوادگی باید بین ۳ تا ۱۶۰ کاراکتر باشد.');
        }

        $username = UsernameValidator::display($input['username']);
        $usernameNormalized = strtolower($username);
        $phone = PhoneValidator::normalize($input['phone']);
        $email = EmailValidator::normalizeOptional($input['email'] ?? null);
        $channel = $input['selectedChannel'];
        if (!in_array($channel, ['telegram', 'email'], true)) {
            throw new ApiProblemException(422, 'VERIFICATION_CHANNEL_INVALID', 'Unprocessable content', 'کانال تأیید باید دقیقاً یکی از Telegram یا Email باشد؛ SMS فعال نیست.');
        }
        if ($channel === 'email' && $email === null) {
            throw new ApiProblemException(422, 'EMAIL_REQUIRED_FOR_CHANNEL', 'Unprocessable content', 'برای انتخاب کانال ایمیل، آدرس ایمیل معتبر الزامی است.');
        }

        PasswordPolicy::validate($input['password']);

        return new self(
            $name,
            $username,
            $usernameNormalized,
            $phone,
            $email,
            $email,
            $channel,
            true,
            $input['password']
        );
    }

    public function passwordForHashing(): string
    {
        return $this->rawPassword;
    }

    /** @return array<string,mixed> Never serialize the raw password or any future credential field. */
    public function jsonSerialize(): array
    {
        return [
            'fullName' => $this->fullName,
            'username' => $this->username,
            'usernameNormalized' => $this->usernameNormalized,
            'phone' => $this->phone,
            'email' => $this->email,
            'emailNormalized' => $this->emailNormalized,
            'selectedChannel' => $this->selectedChannel,
            'termsAccepted' => $this->termsAccepted,
        ];
    }

    /** Exclude credentials from PHP's native serialization as well as JSON. */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /** Keep accidental debug dumps from exposing the request password. */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** DTOs are request-scoped and must never be reconstructed from serialized material. */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Registration DTO instances cannot be unserialized.');
    }
}
