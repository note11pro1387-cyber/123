<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "BLOCKED: pdo_mysql is unavailable; W4-P3 database integration was NOT RUN (exit 77).\n");
    exit(77);
}
$configuredName = getenv('MRNEON_DB_NAME');
$expectedName = getenv('MRNEON_TEST_DB_NAME');
if (!is_string($configuredName) || !is_string($expectedName) || $expectedName === '' || $configuredName !== $expectedName
    || preg_match('/(?:test|sandbox)/i', $configuredName) !== 1
    || getenv('MRNEON_ALLOW_W4P3_TEST_MUTATION') !== '1') {
    fwrite(STDERR, "BLOCKED: requires matching MRNEON_DB_NAME/MRNEON_TEST_DB_NAME set to a dedicated *test* or *sandbox* database and MRNEON_ALLOW_W4P3_TEST_MUTATION=1. No test mutation attempted.\n");
    exit(77);
}

$pdo = null;
$envNames = [
    'MRNEON_OTP_HMAC_KEY', 'MRNEON_RATE_LIMIT_HMAC_KEY', 'MRNEON_TERMS_VERSION',
    'MRNEON_REGISTRATION_IP_LIMIT', 'MRNEON_REGISTRATION_WINDOW_SECONDS',
    'MRNEON_REGISTRATION_USERNAME_LIMIT', 'MRNEON_REGISTRATION_PHONE_LIMIT', 'MRNEON_REGISTRATION_DESTINATION_LIMIT',
    'MRNEON_OTP_SEND_IP_LIMIT_PER_HOUR', 'MRNEON_OTP_SEND_ATTEMPT_LIMIT_PER_HOUR',
    'MRNEON_OTP_RESEND_IP_LIMIT_PER_HOUR', 'MRNEON_OTP_RESEND_ATTEMPT_LIMIT_PER_HOUR',
    'MRNEON_OTP_VERIFY_IP_LIMIT_PER_HOUR', 'MRNEON_OTP_VERIFY_ATTEMPT_LIMIT_PER_HOUR', 'MRNEON_OTP_DESTINATION_LIMIT_PER_HOUR',
    'MRNEON_OTP_MAX_ATTEMPTS', 'MRNEON_OTP_RESEND_COOLDOWN_SECONDS', 'MRNEON_OTP_MAX_RESENDS',
];
$oldEnv = [];
foreach ($envNames as $name) $oldEnv[$name] = getenv($name);
$attemptIds = [];
$userIds = [];
$rateIdentities = [];
$checks = 0;
$runSucceeded = false;
$cleanupSucceeded = true;
$failureType = null;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('W4-P3 database assertion failed: ' . $label);
};
$assertProblem = static function (callable $operation, int $status, string $code, string $label) use ($assert): ApiProblemException {
    try {
        $operation();
    } catch (ApiProblemException $exception) {
        $assert($exception->httpStatus === $status && $exception->errorCode === $code, $label . ' has the expected controlled error');
        return $exception;
    }
    $assert(false, $label . ' should fail');
    throw new RuntimeException('Unreachable test assertion.');
};

final class W4P3IntegrationClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $current) {}
    public function now(): DateTimeImmutable { return $this->current; }
    public function advance(int $seconds): void { $this->current = $this->current->modify('+' . $seconds . ' seconds'); }
}

/** Test-only transport: raw codes remain in memory only and never enter output/logs. */
final class W4P3IntegrationSequenceTransport implements VerificationTransportInterface
{
    /** @var list<VerificationDeliveryResult> */
    private array $results;
    /** @var array<string,string> */
    public array $issuedCodes = [];

    public function __construct(array $results) { $this->results = array_values($results); }

    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult
    {
        $this->issuedCodes[$delivery->challengeId()] = $delivery->code();
        return array_shift($this->results) ?? VerificationDeliveryResult::accepted();
    }
}

try {
    $pdo = Database::connection();
    $actualDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($actualDatabase) || $actualDatabase !== $expectedName) {
        fwrite(STDERR, "BLOCKED: SELECT DATABASE() does not match explicit test database; no test mutation attempted.\n");
        exit(77);
    }
    $schema = (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/database/migrations'))->verifyChecksums();
    if (($schema['current_version'] ?? null) !== SchemaCatalog::CURRENT_SCHEMA_VERSION
        || count($schema['applied'] ?? []) !== 18 || ($schema['pending'] ?? ['missing']) !== []
        || ($schema['checksum_errors'] ?? ['missing']) !== []) {
        fwrite(STDERR, "BLOCKED: checksum-verified migrations 001–018 are required; no test mutation attempted.\n");
        exit(77);
    }

    // Ephemeral test-only keys/config are restored in finally. They are never printed or written to files.
    $otpKey = bin2hex(random_bytes(32));
    $rateKey = bin2hex(random_bytes(32));
    putenv('MRNEON_OTP_HMAC_KEY=' . $otpKey);
    putenv('MRNEON_RATE_LIMIT_HMAC_KEY=' . $rateKey);
    putenv('MRNEON_TERMS_VERSION=synthetic-w4p3-integration-v1');
    putenv('MRNEON_REGISTRATION_IP_LIMIT=500');
    putenv('MRNEON_REGISTRATION_WINDOW_SECONDS=3600');
    putenv('MRNEON_REGISTRATION_USERNAME_LIMIT=100');
    putenv('MRNEON_REGISTRATION_PHONE_LIMIT=100');
    putenv('MRNEON_REGISTRATION_DESTINATION_LIMIT=100');
    putenv('MRNEON_OTP_SEND_IP_LIMIT_PER_HOUR=500');
    putenv('MRNEON_OTP_SEND_ATTEMPT_LIMIT_PER_HOUR=50');
    putenv('MRNEON_OTP_RESEND_IP_LIMIT_PER_HOUR=200');
    putenv('MRNEON_OTP_RESEND_ATTEMPT_LIMIT_PER_HOUR=50');
    putenv('MRNEON_OTP_VERIFY_IP_LIMIT_PER_HOUR=500');
    putenv('MRNEON_OTP_VERIFY_ATTEMPT_LIMIT_PER_HOUR=200');
    putenv('MRNEON_OTP_DESTINATION_LIMIT_PER_HOUR=50');
    putenv('MRNEON_OTP_MAX_ATTEMPTS=5');
    putenv('MRNEON_OTP_RESEND_COOLDOWN_SECONDS=60');
    putenv('MRNEON_OTP_MAX_RESENDS=3');

    $runTag = bin2hex(random_bytes(4));
    $ipCounter = 1;
    $newIp = static function () use (&$ipCounter): string {
        $n = $ipCounter++;
        return '127.0.' . (string) (intdiv($n, 250) + 1) . '.' . (string) (($n % 250) + 1);
    };
    $createAttempt = static function (string $suffix, string $ip) use ($pdo, &$attemptIds, &$rateIdentities): array {
        $username = 'w4p3_' . strtolower(substr($suffix, 0, 12));
        $phone = '+989' . (string) random_int(100000000, 999999999);
        $email = 'w4p3-' . strtolower($suffix) . '@example.invalid';
        $input = [
            'fullName' => 'Synthetic W4P3 ' . $suffix,
            'username' => $username,
            'phone' => $phone,
            'password' => 'Synthetic W4P3 password ' . $suffix . ' secure',
            'email' => $email,
            'selectedChannel' => 'email',
            'termsAccepted' => true,
        ];
        $result = (new RegistrationService($pdo))->start($input, $ip);
        $attemptIds[] = (string) $result['attemptId'];
        $rateIdentities[] = ['registration_ip', $ip];
        $rateIdentities[] = ['registration_username', strtolower($username)];
        $rateIdentities[] = ['registration_phone', $phone];
        $rateIdentities[] = ['registration_email', $email];
        $rateIdentities[] = ['registration_destination', 'email:' . $email];
        $rateIdentities[] = ['otp_send_ip', $ip];
        $rateIdentities[] = ['otp_verify_ip', $ip];
        $rateIdentities[] = ['otp_resend_ip', $ip];
        $rateIdentities[] = ['otp_destination', 'email:' . $email];
        foreach (['otp_send_attempt', 'otp_verify_attempt', 'otp_resend_attempt'] as $scope) $rateIdentities[] = [$scope, (string) $result['attemptId']];
        return ['id' => (string) $result['attemptId'], 'username' => $username, 'phone' => $phone, 'email' => $email];
    };
    $countByAttempt = static function (string $table, string $attemptId) use ($pdo): int {
        if (!in_array($table, ['verification_challenges', 'verification_delivery_attempts'], true)) throw new InvalidArgumentException('Unsafe table name in test helper.');
        $query = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE registration_attempt_id = ?');
        $query->execute([$attemptId]);
        return (int) $query->fetchColumn();
    };
    $findChallenge = static function (string $challengeId) use ($pdo): ?array {
        $query = $pdo->prepare('SELECT id, purpose, subject_type, registration_attempt_id, channel_type, destination_normalized, code_hmac, state, attempts, max_attempts, last_sent_at, expires_at, used_at FROM verification_challenges WHERE id = ?');
        $query->execute([$challengeId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    };
    $makeService = static function (W4P3IntegrationSequenceTransport $transport, W4P3IntegrationClock $clock) use ($pdo, $otpKey): VerificationService {
        return new VerificationService($pdo, $transport, $clock, new OtpVerifier($otpKey));
    };

    // Flow A: accepted delivery -> wrong OTP counter -> correct OTP -> atomic account/channel/attempt/challenge commit -> replay denial.
    $ipA = $newIp();
    $attemptA = $createAttempt($runTag . 'a', $ipA);
    $clockA = new W4P3IntegrationClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $transportA = new W4P3IntegrationSequenceTransport([VerificationDeliveryResult::accepted()]);
    $serviceA = $makeService($transportA, $clockA);
    $issued = $serviceA->requestDelivery(['attemptId' => $attemptA['id']], $ipA, false);
    $assert(($issued['state'] ?? null) === 'active' && ($issued['ttlSeconds'] ?? null) === 120, 'only accepted test transport activates a 120-second challenge');
    $assert(!array_key_exists('code', $issued) && !array_key_exists('otp', $issued), 'API service response omits raw OTP');
    $challengeA = $findChallenge($issued['challengeId']);
    $assert(is_array($challengeA) && $challengeA['state'] === 'active' && $challengeA['purpose'] === 'registration', 'accepted challenge persisted with registration purpose');
    $assert(is_array($challengeA) && $challengeA['code_hmac'] !== $transportA->issuedCodes[$issued['challengeId']], 'stored challenge contains HMAC, not the raw code');
    $assert(is_array($challengeA) && $challengeA['destination_normalized'] === $attemptA['email'], 'challenge binds the canonical destination');
    $expiry = new DateTimeImmutable((string) $issued['expiresAt']);
    $assert($expiry->getTimestamp() - $clockA->now()->getTimestamp() === 120 && $expiry->format('u') === $clockA->now()->format('u'), 'expiry starts at provider acceptance and equals 120 seconds');
    $ledgerA = $pdo->prepare('SELECT state, result_code, destination_hmac FROM verification_delivery_attempts WHERE challenge_id = ?');
    $ledgerA->execute([$issued['challengeId']]);
    $ledgerRowA = $ledgerA->fetch();
    $assert(is_array($ledgerRowA) && $ledgerRowA['state'] === 'accepted' && $ledgerRowA['destination_hmac'] !== $attemptA['email'], 'delivery ledger has safe acceptance state and keyed destination fingerprint');

    $wrongCode = $transportA->issuedCodes[$issued['challengeId']] === '000000' ? '000001' : '000000';
    $assertProblem(fn() => $serviceA->verifyRegistration(['attemptId' => $attemptA['id'], 'challengeId' => $issued['challengeId'], 'code' => $wrongCode], $ipA), 422, 'INVALID_VERIFICATION_CODE', 'wrong OTP is rejected');
    $challengeAfterWrong = $findChallenge($issued['challengeId']);
    $assert(is_array($challengeAfterWrong) && (int) $challengeAfterWrong['attempts'] === 1 && $challengeAfterWrong['state'] === 'active', 'wrong OTP attempts increment atomically without completing registration');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username_normalized = " . $pdo->quote(strtolower($attemptA['username'])))->fetchColumn() === 0, 'wrong OTP does not create an account');

    $completedA = $serviceA->verifyRegistration(['attemptId' => $attemptA['id'], 'challengeId' => $issued['challengeId'], 'code' => $transportA->issuedCodes[$issued['challengeId']]], $ipA);
    $assert(($completedA['registered'] ?? null) === true && ($completedA['authenticated'] ?? null) === false && ($completedA['sessionEstablished'] ?? null) === false, 'completion returns registered but no automatic session');
    $selectA = $pdo->prepare('SELECT u.id, u.account_status, ra.consumed_at, ra.created_user_id, vc.state AS challenge_state, uc.is_verified FROM users u JOIN registration_attempts ra ON ra.created_user_id = u.id JOIN verification_challenges vc ON vc.registration_attempt_id = ra.id JOIN user_channels uc ON uc.user_id = u.id AND uc.channel_type = \'email\' WHERE ra.id = ?');
    $selectA->execute([$attemptA['id']]);
    $rowA = $selectA->fetch();
    $assert(is_array($rowA) && $rowA['account_status'] === 'active' && $rowA['consumed_at'] !== null && (int) $rowA['created_user_id'] === (int) $rowA['id'], 'account and attempt completion committed together');
    $assert(is_array($rowA) && $rowA['challenge_state'] === 'used' && (int) $rowA['is_verified'] === 1, 'Challenge consumed and selected email channel verified in same transaction');
    $userIds[] = (int) $rowA['id'];
    $assertProblem(fn() => $serviceA->verifyRegistration(['attemptId' => $attemptA['id'], 'challengeId' => $issued['challengeId'], 'code' => $transportA->issuedCodes[$issued['challengeId']]], $ipA), 409, 'VERIFICATION_ATTEMPT_INVALID', 'replay after successful registration is rejected');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username_normalized = " . $pdo->quote(strtolower($attemptA['username'])))->fetchColumn() === 1, 'replay never creates a duplicate user');

    // Flow B: failed resend must preserve the previously accepted code; it may still complete within its TTL.
    $ipB = $newIp();
    $attemptB = $createAttempt($runTag . 'b', $ipB);
    $clockB = new W4P3IntegrationClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $transportB = new W4P3IntegrationSequenceTransport([VerificationDeliveryResult::accepted(), VerificationDeliveryResult::rejected()]);
    $serviceB = $makeService($transportB, $clockB);
    $firstB = $serviceB->requestDelivery(['attemptId' => $attemptB['id']], $ipB, false);
    $oldCodeB = $transportB->issuedCodes[$firstB['challengeId']];
    $clockB->advance(61);
    $assertProblem(fn() => $serviceB->requestDelivery(['attemptId' => $attemptB['id']], $ipB, true), 502, 'VERIFICATION_DELIVERY_REJECTED', 'rejected resend does not produce success');
    $oldChallengeB = $findChallenge($firstB['challengeId']);
    $assert(is_array($oldChallengeB) && $oldChallengeB['state'] === 'active', 'failed resend preserves prior challenge state');
    $completedB = $serviceB->verifyRegistration(['attemptId' => $attemptB['id'], 'challengeId' => $firstB['challengeId'], 'code' => $oldCodeB], $ipB);
    $assert(($completedB['registered'] ?? false) === true, 'previous valid code still works after rejected resend');
    $userB = $pdo->prepare('SELECT id FROM users WHERE username_normalized = ?');
    $userB->execute([strtolower($attemptB['username'])]);
    $userIdB = $userB->fetchColumn();
    if ($userIdB !== false) $userIds[] = (int) $userIdB;

    // Flow C: accepted resend revokes the previous challenge and only the new code can finish.
    $ipC = $newIp();
    $attemptC = $createAttempt($runTag . 'c', $ipC);
    $clockC = new W4P3IntegrationClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $transportC = new W4P3IntegrationSequenceTransport([VerificationDeliveryResult::accepted(), VerificationDeliveryResult::accepted()]);
    $serviceC = $makeService($transportC, $clockC);
    $firstC = $serviceC->requestDelivery(['attemptId' => $attemptC['id']], $ipC, false);
    $oldCodeC = $transportC->issuedCodes[$firstC['challengeId']];
    $clockC->advance(61);
    $secondC = $serviceC->requestDelivery(['attemptId' => $attemptC['id']], $ipC, true);
    $oldChallengeC = $findChallenge($firstC['challengeId']);
    $newChallengeC = $findChallenge($secondC['challengeId']);
    $assert($firstC['challengeId'] !== $secondC['challengeId'] && is_array($oldChallengeC) && $oldChallengeC['state'] === 'revoked', 'accepted resend supersedes old challenge');
    $assert(is_array($newChallengeC) && $newChallengeC['state'] === 'active' && $secondC['ttlSeconds'] === 120, 'accepted resend starts a new active 120-second challenge');
    $assertProblem(fn() => $serviceC->verifyRegistration(['attemptId' => $attemptC['id'], 'challengeId' => $firstC['challengeId'], 'code' => $oldCodeC], $ipC), 422, 'VERIFICATION_CODE_INVALID_OR_EXPIRED', 'old code is unusable after successful resend');
    $completedC = $serviceC->verifyRegistration(['attemptId' => $attemptC['id'], 'challengeId' => $secondC['challengeId'], 'code' => $transportC->issuedCodes[$secondC['challengeId']]], $ipC);
    $assert(($completedC['registered'] ?? false) === true, 'new code completes the registration after resend');
    $userC = $pdo->prepare('SELECT id FROM users WHERE username_normalized = ?');
    $userC->execute([strtolower($attemptC['username'])]);
    $userIdC = $userC->fetchColumn();
    if ($userIdC !== false) $userIds[] = (int) $userIdC;

    // Flow D: the production default has no provider; it must not create/activate a challenge or imply delivery.
    $ipD = $newIp();
    $attemptD = $createAttempt($runTag . 'd', $ipD);
    $clockD = new W4P3IntegrationClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $serviceD = new VerificationService($pdo, new UnavailableVerificationTransport(), $clockD, new OtpVerifier($otpKey));
    $assertProblem(fn() => $serviceD->requestDelivery(['attemptId' => $attemptD['id']], $ipD, false), 503, 'VERIFICATION_DELIVERY_NOT_CONFIGURED', 'unconfigured production transport fails closed');
    $assert($countByAttempt('verification_challenges', $attemptD['id']) === 0, 'not_configured transport creates no active challenge');
    $assert($countByAttempt('verification_delivery_attempts', $attemptD['id']) === 1, 'not_configured outcome is explicitly persisted without OTP data');

    // Flow E: UNKNOWN is a reconciliation barrier. A second initial-send request must not call transport again.
    $ipE = $newIp();
    $attemptE = $createAttempt($runTag . 'e', $ipE);
    $clockE = new W4P3IntegrationClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $transportE = new W4P3IntegrationSequenceTransport([VerificationDeliveryResult::unknown(), VerificationDeliveryResult::accepted()]);
    $serviceE = $makeService($transportE, $clockE);
    $assertProblem(fn() => $serviceE->requestDelivery(['attemptId' => $attemptE['id']], $ipE, false), 503, 'VERIFICATION_DELIVERY_UNKNOWN', 'unknown outcome is not reported as a successful delivery');
    $assert(count($transportE->issuedCodes) === 1, 'unknown first delivery was the only transport invocation');
    $assertProblem(fn() => $serviceE->requestDelivery(['attemptId' => $attemptE['id']], $ipE, false), 503, 'VERIFICATION_DELIVERY_RECONCILIATION_REQUIRED', 'unknown prior delivery blocks blind initial-send retry');
    $assert(count($transportE->issuedCodes) === 1, 'reconciliation barrier prevents a duplicate transport invocation');
    $assert($countByAttempt('verification_challenges', $attemptE['id']) === 0, 'unknown result never creates an active Challenge');

    $runSucceeded = true;
} catch (Throwable $exception) {
    $runSucceeded = false;
    $failureType = get_class($exception);
} finally {
    if ($pdo instanceof PDO) {
        try {
            foreach (array_unique($attemptIds) as $attemptId) {
                // Discover any account created before a later assertion failed, so test-only cleanup
                // remains complete even when execution stops between COMMIT and bookkeeping.
                $createdUser = $pdo->prepare('SELECT created_user_id FROM registration_attempts WHERE id = ?');
                $createdUser->execute([$attemptId]);
                $createdUserId = $createdUser->fetchColumn();
                if ($createdUserId !== false && $createdUserId !== null) $userIds[] = (int) $createdUserId;
                $q = $pdo->prepare('DELETE FROM verification_challenges WHERE registration_attempt_id = ?'); $q->execute([$attemptId]);
                $q = $pdo->prepare('DELETE FROM verification_delivery_attempts WHERE registration_attempt_id = ?'); $q->execute([$attemptId]);
                $q = $pdo->prepare('UPDATE registration_attempts SET created_user_id = NULL WHERE id = ?'); $q->execute([$attemptId]);
                $q = $pdo->prepare('DELETE FROM audit_logs WHERE subject_type = ? AND subject_id = ?'); $q->execute(['registration_attempt', $attemptId]);
                $q = $pdo->prepare('DELETE FROM registration_attempts WHERE id = ?'); $q->execute([$attemptId]);
            }
            foreach (array_unique($userIds) as $userId) {
                $q = $pdo->prepare('DELETE FROM user_channels WHERE user_id = ?'); $q->execute([$userId]);
                $q = $pdo->prepare('DELETE FROM audit_logs WHERE subject_type = ? AND subject_id = ?'); $q->execute(['user', (string) $userId]);
                $q = $pdo->prepare('DELETE FROM users WHERE id = ?'); $q->execute([$userId]);
            }
            foreach ($rateIdentities as [$scope, $identity]) {
                $keyHash = hash_hmac('sha256', $scope . "\0" . $identity, (string) getenv('MRNEON_RATE_LIMIT_HMAC_KEY'));
                $q = $pdo->prepare('DELETE FROM rate_limit_buckets WHERE scope_type = ? AND bucket_key_hash = ?'); $q->execute([$scope, $keyHash]);
            }
        } catch (Throwable $cleanupException) {
            $cleanupSucceeded = false;
            fwrite(STDERR, 'WARNING W4-P3 cleanup: synthetic test rows may require scoped inspection; exception type=' . get_class($cleanupException) . ".\n");
        }
    }
    foreach ($oldEnv as $name => $value) {
        if ($value === false) putenv($name); else putenv($name . '=' . $value);
    }
}

if ($runSucceeded && $cleanupSucceeded) {
    echo 'PASS W4-P3 Database Integration: ' . $checks . " assertions; cleanup=complete; synthetic-only rows; no schema/database drop.\n";
    exit(0);
}
if ($failureType !== null) {
    fwrite(STDERR, 'FAIL W4-P3 Database Integration: exception type=' . $failureType . '; test payloads/secrets were not printed.\n');
}
if (!$cleanupSucceeded) {
    fwrite(STDERR, "FAIL W4-P3 Database Integration: cleanup incomplete; inspect the named disposable test database using row IDs from this run only.\n");
}
exit(1);
