<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/auth-core.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('W4-P3 unit assertion failed: ' . $label);
    }
};
$expectProblem = static function (callable $operation, string $code, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ' should reject');
    } catch (ApiProblemException $exception) {
        $assert($exception->errorCode === $code, $label . ' has controlled error code');
    }
};

// Generation contract: six ASCII digits, preserving leading zeroes.
for ($i = 0; $i < 500; $i++) {
    $otp = OtpGenerator::generate();
    $assert(OtpGenerator::isValidFormat($otp), 'OTP has six ASCII digits');
    $assert(strlen($otp) === 6, 'OTP remains fixed width');
}
$assert(OtpGenerator::isValidFormat('000001'), 'leading-zero OTP is valid');
$legacyDelivery = sendVerificationCode('synthetic@example.test', 'Synthetic Test', 'register');
$assert(($legacyDelivery['code_sent'] ?? true) === false && ($legacyDelivery['error_code'] ?? null) === 'LEGACY_OTP_DISABLED', 'legacy JSON OTP sender is disabled and cannot claim delivery');
$assert(verifyCode('synthetic@example.test', '004201', 'register') === false, 'legacy ten-minute OTP verifier cannot accept a code');
foreach (['12345', '1234567', '۱۲۳۴۵۶', '12a456', '', ' 12345'] as $invalidCode) {
    $assert(!OtpGenerator::isValidFormat($invalidCode), 'invalid OTP format rejected');
}

$challengeId = str_repeat('a', 32);
$key = str_repeat('unit-only-secret-', 3);
$verifier = new OtpVerifier($key);
$code = '004201';
$mac = $verifier->mac($challengeId, 'registration', 'email', 'user@example.test', $code);
$assert(preg_match('/^[a-f0-9]{64}$/D', $mac) === 1, 'OTP MAC is SHA-256 hex');
$assert($verifier->matches($mac, $challengeId, 'registration', 'email', 'user@example.test', $code), 'same OTP/context matches');
$assert(!$verifier->matches($mac, $challengeId, 'registration', 'email', 'user@example.test', '004202'), 'wrong OTP fails');
$assert(!$verifier->matches($mac, str_repeat('b', 32), 'registration', 'email', 'user@example.test', $code), 'MAC is bound to challenge ID');
$assert(!$verifier->matches($mac, $challengeId, 'password_reset', 'email', 'user@example.test', $code), 'MAC is bound to purpose');
$assert(!$verifier->matches($mac, $challengeId, 'registration', 'telegram', 'user@example.test', $code), 'MAC is bound to channel');
$assert(!$verifier->matches($mac, $challengeId, 'registration', 'email', 'other@example.test', $code), 'MAC is bound to canonical destination');
$assert(!$verifier->matches('not-a-mac', $challengeId, 'registration', 'email', 'user@example.test', $code), 'malformed stored MAC rejected');
$assert($verifier->destinationFingerprint('registration', 'email', 'user@example.test') !== $verifier->destinationFingerprint('registration', 'email', 'other@example.test'), 'destination fingerprint distinguishes canonical targets');
$expectProblem(static fn() => (new OtpVerifier(''))->assertConfigured(), 'OTP_SECURITY_NOT_CONFIGURED', 'empty HMAC pepper fails closed');

$now = new DateTimeImmutable('2026-10-12T10:00:00.500000Z');
$expiry = VerificationPolicy::expiresAt($now);
$assert($expiry->getTimestamp() - $now->getTimestamp() === 120, 'OTP expiry uses 120 seconds');
$assert($expiry->format('u') === $now->format('u'), 'OTP expiry preserves acceptance-time microseconds');
$cooldownOld = getenv('MRNEON_OTP_RESEND_COOLDOWN_SECONDS');
try {
    putenv('MRNEON_OTP_RESEND_COOLDOWN_SECONDS=60');
    $accepted = new DateTimeImmutable('2026-10-12T10:00:00.900000Z');
    $assert(VerificationPolicy::resendRetryAfter($accepted, new DateTimeImmutable('2026-10-12T10:00:59.899999Z')) === 2, 'cooldown does not open before exact microsecond boundary');
    $assert(VerificationPolicy::resendRetryAfter($accepted, new DateTimeImmutable('2026-10-12T10:01:00.900000Z')) === 0, 'cooldown opens at exact boundary');
} finally {
    if ($cooldownOld === false) putenv('MRNEON_OTP_RESEND_COOLDOWN_SECONDS');
    else putenv('MRNEON_OTP_RESEND_COOLDOWN_SECONDS=' . $cooldownOld);
}

$active = [
    'purpose' => 'registration',
    'subject_type' => 'registration_attempt',
    'state' => 'active',
    'used_at' => null,
    'expires_at' => '2026-10-12 10:02:00.500000',
    'attempts' => 0,
    'max_attempts' => 5,
];
$assert(ChallengeStateMachine::isConsumable($active, $now), 'fresh active registration challenge is consumable');
$assert(!ChallengeStateMachine::isConsumable($active, new DateTimeImmutable('2026-10-12T10:02:00.500000Z')), 'challenge expires at exact boundary');
$assert(!ChallengeStateMachine::isConsumable(array_merge($active, ['state' => 'used']), $now), 'consumed challenge cannot replay');
$assert(!ChallengeStateMachine::isConsumable(array_merge($active, ['purpose' => 'password_reset']), $now), 'registration verifier rejects another purpose');
$assert(!ChallengeStateMachine::isConsumable(array_merge($active, ['attempts' => 5]), $now), 'attempt limit is enforced');
$assert(ChallengeStateMachine::deliveryTransitionAllowed('prepared', 'accepted'), 'prepared delivery may become accepted');
$assert(ChallengeStateMachine::deliveryTransitionAllowed('prepared', 'unknown'), 'prepared delivery may become unknown');
$assert(!ChallengeStateMachine::deliveryTransitionAllowed('accepted', 'prepared'), 'delivery outcome cannot revert to prepared');
$assert(ChallengeStateMachine::challengeTransitionAllowed('registration', 'active', 'used'), 'registration challenge can be consumed');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('registration', 'pending_delivery', 'active'), 'pending registration challenge needs accepted provider result');
$assert(ChallengeStateMachine::challengeTransitionAllowed('registration', 'pending_delivery', 'active', true), 'accepted registration delivery activates pending state');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'pending_delivery', 'active', true), 'accepted recovery delivery activates pending state');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'pending_delivery', 'revoked'), 'definite recovery delivery rejection can revoke pending state');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('registration', 'active', 'verified_reset'), 'registration cannot enter reset-only state');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'active', 'used'), 'reset challenge must pass through verified_reset');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'active', 'verified_reset'), 'password reset challenge can enter verified_reset');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'verified_reset', 'used'), 'verified reset challenge can be consumed');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('used', 'active', 'revoked'), 'unknown purpose cannot transition');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('registration', 'used', 'active'), 'consumed challenge cannot become active again');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'revoked', 'used'), 'revoked challenge cannot be consumed');

$accepted = VerificationDeliveryResult::accepted();
$assert($accepted->status === VerificationDeliveryResult::ACCEPTED && $accepted->safeCode === 'PROVIDER_ACCEPTED', 'transport accepted result is typed and safe');
$assert(VerificationDeliveryResult::unknown('provider said email@example.test')->safeCode === 'PROVIDER_RESULT_UNKNOWN', 'sensitive/invalid provider details collapse to safe code');
$assert(VerificationDeliveryResult::notConfigured()->status === VerificationDeliveryResult::NOT_CONFIGURED, 'missing provider has explicit not_configured result');
$delivery = new VerificationDelivery($challengeId, 'registration', 'email', 'private@example.test', $code);
$json = json_encode($delivery, JSON_THROW_ON_ERROR);
$assert($json === '{}' || $json === '[]', 'delivery envelope JSON serialization exposes no fields');
ob_start(); var_dump($delivery); $dump = (string) ob_get_clean();
$assert(!str_contains($dump, $code) && !str_contains($dump, 'private@example.test'), 'debug dump redacts code and target');
try {
    serialize($delivery);
    $assert(false, 'delivery envelope cannot be native-serialized');
} catch (LogicException) {
    $assert(true, 'delivery envelope explicitly refuses native serialization');
}

$oldKey = getenv('MRNEON_OTP_HMAC_KEY');
try {
    putenv('MRNEON_OTP_HMAC_KEY');
    $expectProblem(static fn() => (new OtpVerifier())->assertConfigured(), 'OTP_SECURITY_NOT_CONFIGURED', 'missing operational OTP key fails closed');
} finally {
    if ($oldKey === false) putenv('MRNEON_OTP_HMAC_KEY');
    else putenv('MRNEON_OTP_HMAC_KEY=' . $oldKey);
}

fwrite(STDOUT, 'PASS W4-P3 Unit: ' . $checks . " assertions\n");
