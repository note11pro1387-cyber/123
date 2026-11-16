<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Registration unit check failed: ' . $label);
    }
};
$expectProblem = static function (callable $operation, string $code, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ' should reject input');
    } catch (ApiProblemException $exception) {
        $assert($exception->errorCode === $code, $label . ' returns ' . $code);
    }
};

// Phone normalization: local, canonical, country prefix, allowed separators, Persian/Arabic digits.
foreach ([
    ['09121234567', '+989121234567'],
    ['9121234567', '+989121234567'],
    ['+989121234567', '+989121234567'],
    ['989121234567', '+989121234567'],
    ['00989121234567', '+989121234567'],
    ['+98 (912) 123-4567', '+989121234567'],
    ['۰۹۱۲۱۲۳۴۵۶۷', '+989121234567'],
    ['٠٩١٢١٢٣٤٥٦٧', '+989121234567'],
    ['  +98 912.123.4567  ', '+989121234567'],
] as [$input, $expected]) {
    $assert(PhoneValidator::normalize($input) === $expected, 'phone canonicalization: ' . $expected);
}
foreach (['abc09121234567', '+98912abc4567', '++989121234567', '+9809121234567', '0098912123456', '12345', '۰۹۱۲۱۲۳۴۵۶x'] as $badPhone) {
    $assert(PhoneValidator::canonicalOrNull($badPhone) === null, 'invalid phone is not repaired');
}
$expectProblem(static fn() => PhoneValidator::normalize('+98912123abc67'), 'PHONE_INVALID', 'invalid phone format');

$assert(UsernameValidator::display('  Synthetic_User  ') === 'Synthetic_User', 'username display trim');
$assert(UsernameValidator::normalize('Synthetic_User') === 'synthetic_user', 'username canonical lowercase');
$expectProblem(static fn() => UsernameValidator::display('ab c'), 'USERNAME_INVALID', 'username whitespace');
$expectProblem(static fn() => UsernameValidator::display('تستUser'), 'USERNAME_INVALID', 'username non-ASCII');

$assert(EmailValidator::normalizeOptional('  Person+tag@Example.COM ') === 'person+tag@example.com', 'email normalized lowercase without provider rewriting');
$assert(EmailValidator::normalizeOptional(null) === null && EmailValidator::normalizeOptional('  ') === null, 'email is optional for Telegram');
$expectProblem(static fn() => EmailValidator::normalizeOptional('name@@example.com'), 'EMAIL_INVALID', 'invalid email');
$expectProblem(static fn() => EmailValidator::normalizeOptional(123), 'EMAIL_INVALID', 'non-string email');
$expectProblem(static fn() => EmailValidator::requireValid(''), 'EMAIL_REQUIRED_FOR_CHANNEL', 'missing email for Email channel');

$validTelegram = [
    'fullName' => 'کاربر آزمایشی',
    'username' => 'Synthetic_User',
    'phone' => '۰۹۱۲۱۲۳۴۵۶۷',
    'password' => 'correct horse battery staple',
    'email' => 'Optional@Example.invalid',
    'selectedChannel' => 'telegram',
    'termsAccepted' => true,
];
$dto = RegistrationDTO::fromArray($validTelegram);
$assert($dto->phone === '+989121234567', 'DTO exposes canonical phone');
$assert($dto->usernameNormalized === 'synthetic_user', 'DTO exposes canonical username');
$assert($dto->email === 'optional@example.invalid', 'DTO canonicalizes optional email');
$assert($dto->selectedChannel === 'telegram' && $dto->termsAccepted, 'DTO preserves channel and consent state');
$serialized = json_encode($dto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$assert(!str_contains($serialized, 'correct horse battery staple') && !str_contains($serialized, 'password'), 'DTO serialization never exposes raw password or field');
$assert(!property_exists($dto, 'password'), 'raw password is not a public DTO property');
$nativeSerialized = serialize($dto);
$assert(!str_contains($nativeSerialized, 'correct horse battery staple') && !str_contains($nativeSerialized, 'rawPassword'), 'PHP native serialization excludes password material');
ob_start();
var_dump($dto);
$debugDump = (string) ob_get_clean();
$assert(!str_contains($debugDump, 'correct horse battery staple') && !str_contains($debugDump, 'rawPassword'), 'debug dumps exclude password material');

$emailDto = RegistrationDTO::fromArray(array_merge($validTelegram, [
    'email' => 'Selected@Example.invalid',
    'selectedChannel' => 'email',
]));
$assert($emailDto->selectedChannel === 'email' && $emailDto->email === 'selected@example.invalid', 'Email channel requires and canonicalizes email');

$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['phone' => null])), 'REGISTRATION_FIELD_TYPE_INVALID', 'null phone');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_diff_key($validTelegram, ['phone' => true])), 'REGISTRATION_FIELD_REQUIRED', 'missing phone');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['selectedChannel' => 'sms'])), 'VERIFICATION_CHANNEL_INVALID', 'SMS channel rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['selectedChannel' => 'both'])), 'VERIFICATION_CHANNEL_INVALID', 'unknown channel rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['selectedChannel' => 'email', 'email' => null])), 'EMAIL_REQUIRED_FOR_CHANNEL', 'Email channel missing email');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['selectedChannels' => ['email', 'telegram']])), 'UNEXPECTED_REGISTRATION_FIELDS', 'both channels payload rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['role' => 'admin'])), 'UNEXPECTED_REGISTRATION_FIELDS', 'client role rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['userId' => 1])), 'UNEXPECTED_REGISTRATION_FIELDS', 'client user ID rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['isVerified' => true])), 'UNEXPECTED_REGISTRATION_FIELDS', 'client verification state rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['termsAccepted' => 'true'])), 'TERMS_ACCEPTANCE_REQUIRED', 'non-boolean terms acceptance rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['termsAccepted' => false])), 'TERMS_ACCEPTANCE_REQUIRED', 'missing consent rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['password' => '12345678901234'])), 'PASSWORD_POLICY_VIOLATION', '14-character password rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['password' => str_repeat('a', 1025)])), 'PASSWORD_POLICY_VIOLATION', 'oversized password rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['fullName' => str_repeat('ن', 161)])), 'FULL_NAME_INVALID', 'oversized name rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['fullName' => '  '])), 'FULL_NAME_INVALID', 'empty name rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['selectedChannel' => 12])), 'REGISTRATION_FIELD_TYPE_INVALID', 'non-string channel rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['termsVersion' => 'client-controlled'])), 'UNEXPECTED_REGISTRATION_FIELDS', 'client Terms version rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['username' => str_repeat('a', 21)])), 'USERNAME_INVALID', 'oversized username rejected');
$expectProblem(static fn() => RegistrationDTO::fromArray(array_merge($validTelegram, ['email' => true])), 'EMAIL_INVALID', 'boolean email rejected');

PasswordPolicy::validate('correct horse battery staple');
$hash = PasswordPolicy::hash('correct horse battery staple');
$assert(password_verify('correct horse battery staple', $hash), 'central policy hash verifies');
$assert(password_get_info($hash)['algoName'] !== 'unknown', 'central policy produces recognized password hash');
$spacePassword = ' ' . 'correct horse battery staple' . ' ';
$spaceHash = PasswordPolicy::hash($spacePassword);
$assert(password_verify($spacePassword, $spaceHash) && !password_verify(trim($spacePassword), $spaceHash), 'password whitespace is preserved and never silently trimmed');
$expectProblem(static fn() => PasswordPolicy::validate("correct horse battery staple\n"), 'PASSWORD_POLICY_VIOLATION', 'ASCII control characters are rejected');

$oldTtl = getenv('MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS');
try {
    putenv('MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS');
    $assert(RegistrationPolicy::attemptTtlSeconds() === RegistrationPolicy::DEFAULT_ATTEMPT_TTL_SECONDS, 'central attempt TTL default');
    putenv('MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS=120');
    try {
        RegistrationPolicy::attemptTtlSeconds();
        $assert(false, 'TTL below configured bound rejected');
    } catch (RuntimeException) {
        $assert(true, 'TTL below configured bound rejected');
    }
} finally {
    if ($oldTtl === false) putenv('MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS');
    else putenv('MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS=' . $oldTtl);
}

$oldTerms = getenv('MRNEON_TERMS_VERSION');
try {
    putenv('MRNEON_TERMS_VERSION=synthetic-v1');
    $assert(RegistrationPolicy::termsVersion() === 'synthetic-v1', 'approved server-owned Terms version accepted');
    putenv('MRNEON_TERMS_VERSION');
    try {
        RegistrationPolicy::termsVersion();
        $assert(false, 'missing Terms policy must fail closed');
    } catch (ApiProblemException $exception) {
        $assert($exception->errorCode === 'TERMS_POLICY_UNAVAILABLE', 'missing Terms policy returns controlled error');
    }
} finally {
    if ($oldTerms === false) putenv('MRNEON_TERMS_VERSION');
    else putenv('MRNEON_TERMS_VERSION=' . $oldTerms);
}

echo 'PASS: ' . $checks . " W4-P2 registration DTO/validation/policy checks; synthetic fixtures only.\n";
