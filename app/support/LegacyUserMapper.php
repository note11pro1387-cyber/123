<?php
declare(strict_types=1);

/** Maps one legacy users.json record without exposing source values in diagnostics. */
final class LegacyUserMapper
{
    private const KNOWN_FIELDS = [
        'id', 'fullName', 'phone', 'email', 'telegramId', 'username', 'password', 'verified', 'createdAt', 'lastLogin',
    ];

    /** @return array{mapped:?array,errors:list<string>,unknown_fields:list<string>} */
    public function map(array $row): array
    {
        $errors = [];
        foreach (['id', 'fullName', 'phone', 'username', 'password', 'verified', 'createdAt'] as $required) {
            if (!array_key_exists($required, $row)) {
                $errors[] = 'missing_' . $required;
            }
        }
        if ($errors !== []) {
            return ['mapped' => null, 'errors' => $errors, 'unknown_fields' => $this->unknownFields($row)];
        }

        $legacyId = is_string($row['id']) ? trim($row['id']) : '';
        $fullName = is_string($row['fullName']) ? trim($row['fullName']) : '';
        $username = is_string($row['username']) ? trim($row['username']) : '';
        $passwordHash = is_string($row['password']) ? $row['password'] : '';
        $phone = is_string($row['phone']) ? self::canonicalIranianMobile($row['phone']) : null;
        $verified = $this->legacyBoolean($row['verified']);
        $createdAt = is_string($row['createdAt']) ? self::legacyLocalTimeToUtc($row['createdAt']) : null;
        $lastLogin = null;
        if (isset($row['lastLogin']) && $row['lastLogin'] !== '') {
            $lastLogin = is_string($row['lastLogin']) ? self::legacyLocalTimeToUtc($row['lastLogin']) : null;
            if ($lastLogin === null) {
                $errors[] = 'invalid_lastLogin';
            }
        }

        if ($legacyId === '' || strlen($legacyId) > 128) $errors[] = 'invalid_id';
        if ($fullName === '' || self::utf8Length($fullName) > 160) $errors[] = 'invalid_fullName';
        if (!preg_match('/^[A-Za-z0-9_]{4,20}$/D', $username)) $errors[] = 'invalid_username';
        if ($phone === null) $errors[] = 'invalid_phone';
        if ($passwordHash === '' || password_get_info($passwordHash)['algoName'] === 'unknown') $errors[] = 'invalid_password_hash';
        if ($verified === null) $errors[] = 'invalid_verified_flag';
        if ($createdAt === null) $errors[] = 'invalid_createdAt';

        $email = null;
        if (array_key_exists('email', $row) && $row['email'] !== null && $row['email'] !== '') {
            if (!is_string($row['email']) || filter_var(trim($row['email']), FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'invalid_email';
            } else {
                $email = trim($row['email']);
            }
        }

        $legacyTelegramUsername = null;
        if (array_key_exists('telegramId', $row) && $row['telegramId'] !== null && $row['telegramId'] !== '') {
            if (!is_string($row['telegramId'])) {
                $errors[] = 'invalid_telegramId';
            } else {
                $legacyTelegramUsername = ltrim(trim($row['telegramId']), '@');
                if (!preg_match('/^[A-Za-z0-9_]{5,32}$/D', $legacyTelegramUsername)) {
                    $errors[] = 'invalid_telegramId';
                }
            }
        }

        if ($errors !== []) {
            return ['mapped' => null, 'errors' => array_values(array_unique($errors)), 'unknown_fields' => $this->unknownFields($row)];
        }

        $unknown = [];
        foreach ($row as $key => $value) {
            if (!in_array((string) $key, self::KNOWN_FIELDS, true)) {
                $unknown[(string) $key] = $value;
            }
        }
        if (self::containsSensitiveUnknownKey($unknown)) {
            return ['mapped' => null, 'errors' => ['unknown_sensitive_field_requires_manual_review'], 'unknown_fields' => $this->unknownFields($row)];
        }
        $unknownJson = $unknown === [] ? null : json_encode($unknown, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonical = [
            'legacy_id' => $legacyId,
            'full_name' => $fullName,
            'username' => $username,
            'username_normalized' => strtolower($username),
            'phone' => $phone,
            'email' => $email === null ? null : strtolower($email),
            'password_hash' => $passwordHash,
            'legacy_verified' => $verified,
            'legacy_telegram_username' => $legacyTelegramUsername,
            'created_at' => $createdAt,
            'last_login_at' => $lastLogin,
            'legacy_extra_json' => $unknownJson,
        ];
        $sourceHashPayload = $row;
        self::sortRecursive($sourceHashPayload);
        $sourceJson = json_encode($sourceHashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonical['legacy_source_hash'] = hash('sha256', $sourceJson);
        $canonical['account_status'] = 'pending'; // Do not confer active status from a legacy verification flag alone.
        $canonical['phone_normalized'] = $phone;
        $canonical['email_value'] = $email;
        $canonical['email_normalized'] = $email === null ? null : strtolower($email);
        return ['mapped' => $canonical, 'errors' => [], 'unknown_fields' => $this->unknownFields($row)];
    }

    public static function canonicalIranianMobile(string $input): ?string
    {
        // Registration and legacy import share exactly one canonical phone implementation.
        return PhoneValidator::canonicalOrNull($input);
    }

    public static function legacyLocalTimeToUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        try {
            $time = new DateTimeImmutable($value, new DateTimeZone('Asia/Tehran'));
            return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return null;
        }
    }

    private function legacyBoolean(mixed $value): ?int
    {
        if ($value === true || $value === 1 || $value === '1') return 1;
        if ($value === false || $value === 0 || $value === '0') return 0;
        return null;
    }


    private static function containsSensitiveUnknownKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (preg_match('/(?:password|passwd|otp|reset.?token|recovery.?token|session(?:.?id|.?token)?|cookie|csrf|bot.?token|smtp|api.?key|secret|credential|authorization)/i', (string) $key) === 1) {
                return true;
            }
            if (is_array($item) && self::containsSensitiveUnknownKey($item)) return true;
        }
        return false;
    }

    private function unknownFields(array $row): array
    {
        $fields = array_values(array_map('strval', array_diff(array_keys($row), self::KNOWN_FIELDS)));
        sort($fields, SORT_STRING);
        return $fields;
    }

    private static function utf8Length(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? PHP_INT_MAX : $count;
    }

    private static function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) self::sortRecursive($item);
        }
        unset($item);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
    }
}
