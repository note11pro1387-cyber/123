<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/auth-core.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('W4-P5 unit assertion failed: ' . $label);
};
$expectProblem = static function (callable $operation, string $code, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ' should reject');
    } catch (ApiProblemException $exception) {
        $assert($exception->errorCode === $code, $label . ' produces expected error code');
    }
};
$expectException = static function (callable $operation, string $class, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ' should throw ' . $class);
    } catch (Throwable $exception) {
        $assert($exception instanceof $class, $label . ' throws ' . $class);
    }
};
$restoreEnv = static function (string $name, string|false $old): void {
    if ($old === false) putenv($name); else putenv($name . '=' . $old);
};

// Identifier canonicalization: only one leading @ is an optional UI notation for the MR NEON account username.
$assert(RecoveryService::normalizeIdentifier('email', 'Account@example.invalid') === 'account@example.invalid', 'email is canonical lowercase');
$assert(RecoveryService::normalizeIdentifier('telegram', '@Synthetic_User') === 'synthetic_user', 'Telegram method identifies the MR NEON username, not a Telegram handle');
$assert(RecoveryService::normalizeIdentifier('telegram', 'Synthetic_User') === 'synthetic_user', 'account username works without a leading @');
$expectProblem(static fn() => RecoveryService::normalizeIdentifier('sms', '09121234567'), 'RECOVERY_INPUT_INVALID', 'SMS recovery method is disabled');
$expectProblem(static fn() => RecoveryService::normalizeIdentifier('telegram', '@@Synthetic_User'), 'RECOVERY_INPUT_INVALID', 'multiple @ symbols are not silently stripped');
$expectProblem(static fn() => RecoveryService::normalizeIdentifier('telegram', 'ab'), 'RECOVERY_INPUT_INVALID', 'short username rejected');
$expectProblem(static fn() => RecoveryService::normalizeIdentifier('email', 'name@@example.invalid'), 'RECOVERY_INPUT_INVALID', 'malformed email rejected');
$expectProblem(static fn() => RecoveryService::normalizeIdentifier('email', "user@example.invalid\r\nBcc:other@example.invalid"), 'RECOVERY_INPUT_INVALID', 'email CRLF rejected');

// Recovery reference is stable and pseudonymous, but never the database Challenge ID or authorization capability.
$key = str_repeat('unit-w4p5-otp-pepper-', 3);
$verifier = new OtpVerifier($key);
$reference = $verifier->recoveryReference('email', 'account@example.invalid');
$assert((bool) preg_match('/^[a-f0-9]{32}$/D', $reference), 'reference is fixed-width hex');
$assert(hash_equals($reference, $verifier->recoveryReference('email', 'account@example.invalid')), 'same normalized identifier yields stable reference');
$assert(!hash_equals($reference, $verifier->recoveryReference('email', 'other@example.invalid')), 'reference is bound to identifier');
$assert(!hash_equals($reference, $verifier->recoveryReference('telegram', 'account@example.invalid')), 'reference is bound to method');
$expectException(static fn() => $verifier->recoveryReference('sms', '09121234567'), InvalidArgumentException::class, 'unsupported method fails during reference normalization');
$expectProblem(static fn() => (new OtpVerifier(''))->assertConfigured(), 'OTP_SECURITY_NOT_CONFIGURED', 'missing OTP HMAC key fails closed');

// Reset-only capability: at least 256 bits of random material, stored representation is a hash only.
$tokens = [];
for ($i = 0; $i < 50; $i++) {
    $token = ResetTokenGenerator::generate();
    $assert(ResetTokenGenerator::isValid($token), 'reset token has expected format');
    $assert(strlen($token) === 64, 'reset token carries 32 random bytes in hex');
    $hash = ResetTokenGenerator::hash($token);
    $assert((bool) preg_match('/^[a-f0-9]{64}$/D', $hash), 'stored token fingerprint is SHA-256 hex');
    $assert(!hash_equals($token, $hash), 'raw token differs from persisted fingerprint');
    $tokens[] = $token;
}
$assert(count(array_unique($tokens)) === count($tokens), 'CSPRNG produced unique tokens in the sample');
foreach (['', 'abc', str_repeat('a', 63), str_repeat('A', 64), str_repeat('a', 65)] as $badToken) {
    $assert(!ResetTokenGenerator::isValid($badToken), 'invalid reset token shape is rejected');
}
$expectException(static fn() => ResetTokenGenerator::hash('short'), InvalidArgumentException::class, 'hash refuses malformed capability');

// Centralized reset authorization TTL is separate from OTP TTL and bounded by config.
$oldTtl = getenv('MRNEON_RECOVERY_TOKEN_TTL_SECONDS');
try {
    putenv('MRNEON_RECOVERY_TOKEN_TTL_SECONDS');
    $assert(RecoveryPolicy::resetAuthorizationSeconds() === 600, 'default Reset-only TTL is ten minutes');
    putenv('MRNEON_RECOVERY_TOKEN_TTL_SECONDS=300');
    $assert(RecoveryPolicy::resetAuthorizationSeconds() === 300, 'Reset-only TTL accepts a bounded override');
    $expectProblem(static function (): void { putenv('MRNEON_RECOVERY_TOKEN_TTL_SECONDS=20'); RecoveryPolicy::resetAuthorizationSeconds(); }, 'RECOVERY_POLICY_INVALID', 'too-short Reset-only TTL rejected');
} finally {
    $restoreEnv('MRNEON_RECOVERY_TOKEN_TTL_SECONDS', $oldTtl);
}
$assert(RecoveryPolicy::otpLifetimeSeconds() === 120, 'OTP lifetime remains fixed at 120 seconds');

// Purpose, expiry and one-time state are evaluated independently from Reset-only capability.
$now = new DateTimeImmutable('2026-10-13T10:00:00.250000Z');
$active = [
    'purpose' => 'password_reset', 'subject_type' => 'user', 'state' => 'active', 'used_at' => null,
    'expires_at' => '2026-10-13 10:02:00.250000', 'attempts' => 0, 'max_attempts' => 5,
];
$assert(ChallengeStateMachine::isPasswordResetConsumable($active, $now), 'active reset challenge is consumable before expiry');
$assert(!ChallengeStateMachine::isPasswordResetConsumable($active, new DateTimeImmutable('2026-10-13T10:02:00.250000Z')), 'challenge expires at exact boundary');
$assert(!ChallengeStateMachine::isPasswordResetConsumable(array_merge($active, ['purpose' => 'registration']), $now), 'registration OTP cannot authorize Recovery');
$assert(!ChallengeStateMachine::isPasswordResetConsumable(array_merge($active, ['subject_type' => 'registration_attempt']), $now), 'registration attempt cannot become a Recovery subject');
$assert(!ChallengeStateMachine::isPasswordResetConsumable(array_merge($active, ['state' => 'used']), $now), 'consumed challenge cannot replay');
$assert(!ChallengeStateMachine::isPasswordResetConsumable(array_merge($active, ['used_at' => '2026-10-13 10:00:00.000000']), $now), 'used-at prevents re-consumption');
$assert(!ChallengeStateMachine::isPasswordResetConsumable(array_merge($active, ['attempts' => 5]), $now), 'maximum attempts enforced');
$assert(!ChallengeStateMachine::isPasswordResetConsumable(array_merge($active, ['expires_at' => 'garbage']), $now), 'malformed expiry fails closed');

// Password changes reuse the central 8–15 Unicode-character policy; whitespace is preserved.
PasswordPolicy::validate('correcthorse12');
$expectProblem(static fn() => PasswordPolicy::validate('1234567890123456'), 'PASSWORD_POLICY_VIOLATION', 'sixteen-character password rejected');
PasswordPolicy::validate(' 12345678 ');
$spacedPasswordHash = PasswordPolicy::hash(' 12345678 ');
$assert(password_verify(' 12345678 ', $spacedPasswordHash), 'password whitespace is preserved as user-selected content');
$expectProblem(static fn() => PasswordPolicy::validate("1234567890123\nxx"), 'PASSWORD_POLICY_VIOLATION', 'control characters rejected');
$longPassword = str_repeat('é', 513);
$expectProblem(static fn() => PasswordPolicy::validate($longPassword), 'PASSWORD_POLICY_VIOLATION', 'password exceeding 1024 bytes rejected');
$hash = PasswordPolicy::hash('correcthorse12');
$assert(password_verify('correcthorse12', $hash), 'new password hash verifies with central policy');
$assert(!password_verify('wronghorse12', $hash), 'wrong password does not verify');

// API contract routing: new three-step flow, legacy combined endpoint deliberately closed.
$assert(MethodPolicy::known('forgotPassword') && MethodPolicy::known('verifyResetCode') && MethodPolicy::known('resetPassword'), 'Recovery API actions are registered');
$expectProblem(static fn() => MethodPolicy::enforce('resetPassword', 'GET'), 'METHOD_NOT_ALLOWED', 'reset mutation is POST only');
$api = file_get_contents(dirname(__DIR__, 2) . '/api.php');
$assert(is_string($api) && str_contains($api, "['forgotPassword', 'verifyResetCode', 'resetPassword', 'verifyPasswordResetCode']"), 'Recovery controller is routed before legacy action policy');
$legacy = file_get_contents(dirname(__DIR__, 2) . '/core/auth-core.php');
$assert(is_string($legacy) && preg_match('/function handleForgotPassword\(\): void \{\s*\/\/ W4-P5[\s\S]*?RECOVERY_FLOW_UPDATED/s', $legacy) === 1, 'legacy JSON-backed Recovery handler is fail-closed');
$assert(is_string($legacy) && preg_match('/function handleVerifyResetCode\(\): void \{[\s\S]*?RECOVERY_FLOW_UPDATED[\s\S]*?\n\}/', $legacy) === 1, 'legacy combined OTP+password handler is fail-closed');
$controller = file_get_contents(dirname(__DIR__, 2) . '/app/http/controllers/RecoveryController.php');
$assert(is_string($controller) && str_contains($controller, "HTTP_X_RESET_AUTHORIZATION"), 'reset-only capability is accepted from header');
$assert(is_string($controller) && !str_contains($controller, "\$_POST['resetToken']") && !str_contains($controller, "\$_GET['resetToken']"), 'reset capability is not read from URL/body');
$assert(is_string($controller) && !str_contains($controller, 'SessionManager::establishWithinTransaction'), 'controller never creates a login session from Recovery');

fwrite(STDOUT, 'PASS W4-P5 Unit: ' . $checks . " assertions\n");
