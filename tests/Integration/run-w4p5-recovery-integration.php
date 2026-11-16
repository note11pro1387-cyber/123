<?php
declare(strict_types=1);

/** Destructive operations are limited to one explicitly named, disposable local test schema. */
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "BLOCKED W4-P5 Integration: pdo_mysql is unavailable; no database connection or mutation attempted.\n");
    exit(77);
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/auth-core.php';

$config = Database::configuration();
$dbName = (string) ($config['name'] ?? '');
$expectedDb = (string) (getenv('MRNEON_TEST_DB_NAME') ?: '');
$host = strtolower((string) ($config['host'] ?? ''));
if (getenv('MRNEON_ALLOW_W4P5_TEST_MUTATION') !== '1'
    || $expectedDb === '' || !hash_equals($expectedDb, $dbName)
    || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $dbName) !== 1
    || !preg_match('/(?:test|sandbox)/i', $dbName)
    || !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    fwrite(STDOUT, "BLOCKED W4-P5 Integration: set MRNEON_ALLOW_W4P5_TEST_MUTATION=1 and match MRNEON_TEST_DB_NAME to an explicitly named local *test*/*sandbox* schema. No DB connection attempted.\n");
    exit(78);
}

final class W4P5IntegrationAcceptedTransport implements VerificationTransportInterface
{
    public array $deliveries = [];

    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult
    {
        $this->deliveries[] = [
            'challengeId' => $delivery->challengeId(),
            'purpose' => $delivery->purpose(),
            'channel' => $delivery->channel(),
            'code' => $delivery->code(),
        ];
        return VerificationDeliveryResult::accepted();
    }
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('W4-P5 integration assertion failed: ' . $label);
};
$pdo = null;
$userId = null;
$channelId = null;
$sessionHash = null;
$bucketHashes = [];
$oldEnv = [];
$testIp = '192.0.2.' . (string) random_int(1, 250);
$identifier = 'w4p5.' . bin2hex(random_bytes(6)) . '@example.invalid';
$username = 'w4p5' . substr(bin2hex(random_bytes(8)), 0, 12);
$phone = '+989' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);
$tokenRaw = null;
$otpRaw = null;

$envValues = [
    'MRNEON_OTP_HMAC_KEY' => bin2hex(random_bytes(48)),
    'MRNEON_RATE_LIMIT_HMAC_KEY' => bin2hex(random_bytes(48)),
    'MRNEON_RECOVERY_TOKEN_TTL_SECONDS' => '600',
];
foreach ($envValues as $key => $value) {
    $oldEnv[$key] = getenv($key);
    putenv($key . '=' . $value);
}

try {
    $pdo = Database::connection();
    $actualDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!hash_equals($dbName, $actualDb)) throw new RuntimeException('Connected database identity differs from the explicit test target.');

    $migrationStatus = (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/database/migrations'))->verifyChecksums();
    if ($migrationStatus['pending'] !== [] || $migrationStatus['checksum_errors'] !== [] || (string) $migrationStatus['current_version'] !== '018') {
        throw new RuntimeException('Test schema is not at the checksum-verified migration version 018.');
    }

    $oldPassword = 'synthetic legacy password for tests';
    $newPassword = 'synthetic new password chosen for test';
    $created = (new UserRepository($pdo))->create([
        'full_name' => 'W4P5 Synthetic Recovery Test',
        'username' => $username,
        'phone' => $phone,
        'password_hash' => PasswordPolicy::hash($oldPassword),
        'account_status' => 'active',
    ]);
    $userId = (int) $created['id'];
    $channelId = (new UserChannelRepository($pdo))->create($userId, 'email', $identifier, true, null, true);
    $fakeTransport = new W4P5IntegrationAcceptedTransport();
    $service = new RecoveryService($pdo, $fakeTransport, new OtpVerifier((string) $envValues['MRNEON_OTP_HMAC_KEY']), new SystemClock());

    $start = $service->startRecovery(['identifier' => $identifier, 'method' => 'email'], $testIp);
    $assert(isset($start['challengeRef']) && preg_match('/^[a-f0-9]{32}$/D', (string) $start['challengeRef']) === 1, 'generic start returns only a pseudonymous context reference');
    $assert(($start['ttlSeconds'] ?? null) === 120, 'Recovery OTP lifetime is advertised as 120 seconds');
    $assert(count($fakeTransport->deliveries) === 1, 'one provider acceptance occurred for the synthetic verified email');
    $sent = $fakeTransport->deliveries[0];
    $otpRaw = (string) $sent['code'];

    $statement = $pdo->prepare("SELECT id, code_hmac, state, expires_at, last_sent_at, user_id, user_channel_id FROM verification_challenges WHERE purpose = 'password_reset' AND user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $statement->execute([$userId]);
    $challenge = $statement->fetch();
    $assert(is_array($challenge), 'challenge exists only after fake transport acceptance');
    $assert(($challenge['state'] ?? null) === 'active', 'accepted challenge becomes active');
    $assert((int) ($challenge['user_channel_id'] ?? 0) === $channelId, 'challenge is bound to the verified user channel');
    $assert((string) ($challenge['code_hmac'] ?? '') !== $otpRaw, 'raw OTP is not stored in code_hmac column');
    $expiry = new DateTimeImmutable((string) $challenge['expires_at'], new DateTimeZone('UTC'));
    $lastSent = new DateTimeImmutable((string) $challenge['last_sent_at'], new DateTimeZone('UTC'));
    $assert($expiry->getTimestamp() - $lastSent->getTimestamp() === 120 && $expiry->format('u') === $lastSent->format('u'), 'challenge expiry is exactly 120 seconds after provider acceptance');

    $verified = $service->verifyCode([
        'identifier' => $identifier,
        'method' => 'email',
        'challengeRef' => $start['challengeRef'],
        'code' => $otpRaw,
    ], $testIp);
    $tokenRaw = (string) ($verified['resetAuthorizationToken'] ?? '');
    $assert(ResetTokenGenerator::isValid($tokenRaw), 'valid OTP returns high-entropy reset-only capability');
    $assert(($verified['expiresInSeconds'] ?? null) === 600, 'reset-only token TTL is independent and ten minutes by default');
    $statement = $pdo->prepare('SELECT state, recovery_token_hash, recovery_expires_at, recovery_used_at FROM verification_challenges WHERE id = ?');
    $statement->execute([(string) $challenge['id']]);
    $postVerify = $statement->fetch();
    $assert(is_array($postVerify) && ($postVerify['state'] ?? null) === 'verified_reset', 'OTP is consumed into reset-only state');
    $assert(is_array($postVerify) && hash_equals((string) $postVerify['recovery_token_hash'], ResetTokenGenerator::hash($tokenRaw)), 'only token hash is persisted');
    $assert(is_array($postVerify) && $postVerify['recovery_used_at'] === null, 'reset-only capability starts unused');

    $sessionHash = hash('sha256', 'synthetic-w4p5-session:' . bin2hex(random_bytes(32)));
    (new AuthSessionRepository($pdo))->create($sessionHash, 'user', (string) $created['public_id'], $userId, gmdate('Y-m-d H:i:s', time() + 3600), 1);
    $result = $service->resetPassword($tokenRaw, ['newPassword' => $newPassword], $testIp);
    $assert(($result['passwordReset'] ?? false) === true, 'password reset commits only with valid reset-only token');
    $assert(($result['authenticated'] ?? true) === false && ($result['sessionEstablished'] ?? true) === false, 'reset does not create an authenticated session');
    $user = (new UserRepository($pdo))->findForAuthenticationByUsername($username);
    $assert(is_array($user) && password_verify($newPassword, (string) $user['password_hash']), 'new password hash is persisted and verifies');
    $assert(is_array($user) && !password_verify($oldPassword, (string) $user['password_hash']), 'previous password no longer verifies');
    $assert((new AuthSessionRepository($pdo))->findActive($sessionHash) === null, 'old registered session is revoked');
    $statement = $pdo->prepare('SELECT revoked_at FROM auth_sessions WHERE session_hash = ?');
    $statement->execute([$sessionHash]);
    $revokedAt = $statement->fetchColumn();
    $assert($revokedAt !== false && $revokedAt !== null, 'revocation timestamp is persisted');
    $statement = $pdo->prepare('SELECT state, recovery_used_at FROM verification_challenges WHERE id = ?');
    $statement->execute([(string) $challenge['id']]);
    $finalChallenge = $statement->fetch();
    $assert(is_array($finalChallenge) && ($finalChallenge['state'] ?? null) === 'used' && $finalChallenge['recovery_used_at'] !== null, 'reset-only token is atomically consumed');

    try {
        $service->resetPassword($tokenRaw, ['newPassword' => 'another synthetic valid recovery password'], $testIp);
        $assert(false, 'Reset-only token must not replay');
    } catch (ApiProblemException $exception) {
        $assert($exception->errorCode === 'RESET_AUTHORIZATION_INVALID_OR_EXPIRED', 'replay returns generic invalid/expired error');
    }

    foreach ([
        ['recovery_start_ip', $testIp],
        ['recovery_start_identity', 'email:' . $identifier],
        ['recovery_verify_ip', $testIp],
        ['recovery_verify_challenge', (string) $start['challengeRef']],
        ['recovery_reset_ip', $testIp],
        ['recovery_reset_token', hash('sha256', $tokenRaw)],
    ] as [$scope, $identity]) {
        $bucketHashes[] = hash_hmac('sha256', $scope . "\0" . $identity, (string) $envValues['MRNEON_RATE_LIMIT_HMAC_KEY']);
    }

} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL W4-P5 Integration: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    $failureCode = 1;
} finally {
    if ($pdo instanceof PDO && is_int($userId) && $userId > 0) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->beginTransaction();
            $audit = $pdo->prepare("DELETE FROM audit_logs WHERE subject_type = 'user' AND subject_id = ?");
            $audit->execute([(string) $userId]);
            $delivery = $pdo->prepare('DELETE FROM verification_delivery_attempts WHERE user_id = ?');
            $delivery->execute([$userId]);
            $challenges = $pdo->prepare('DELETE FROM verification_challenges WHERE user_id = ?');
            $challenges->execute([$userId]);
            $sessions = $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?');
            $sessions->execute([$userId]);
            $channels = $pdo->prepare('DELETE FROM user_channels WHERE user_id = ?');
            $channels->execute([$userId]);
            $users = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $users->execute([$userId]);
            if ($bucketHashes !== []) {
                $deleteBucket = $pdo->prepare('DELETE FROM rate_limit_buckets WHERE bucket_key_hash = ?');
                foreach (array_unique($bucketHashes) as $hash) $deleteBucket->execute([$hash]);
            }
            $pdo->commit();
        } catch (Throwable $cleanupException) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fwrite(STDERR, 'WARNING W4-P5 Integration cleanup needs operator review: ' . get_class($cleanupException) . "\n");
            $failureCode = 1;
        }
    }
    foreach ($oldEnv as $key => $value) {
        if ($value === false) putenv($key); else putenv($key . '=' . $value);
    }
}
if (!isset($failureCode)) {
    fwrite(STDOUT, 'PASS W4-P5 Integration: ' . $checks . " assertions\n");
}
exit($failureCode ?? 0);
