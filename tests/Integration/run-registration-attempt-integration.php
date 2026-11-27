<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$drivers = PDO::getAvailableDrivers();
if (!in_array('mysql', $drivers, true)) {
    fwrite(STDERR, "BLOCKED: pdo_mysql is unavailable; Registration Attempt DB tests were not run.\n");
    exit(77);
}
$configuredName = getenv('MRNEON_DB_NAME');
$expectedName = getenv('MRNEON_TEST_DB_NAME');
if (!is_string($configuredName) || !is_string($expectedName) || $expectedName === '' || $configuredName !== $expectedName
    || preg_match('/(?:test|sandbox)/i', $configuredName) !== 1
    || getenv('MRNEON_ALLOW_REGISTRATION_TEST_MUTATION') !== '1') {
    fwrite(STDERR, "BLOCKED: set matching MRNEON_DB_NAME/MRNEON_TEST_DB_NAME to a dedicated *test* or *sandbox* DB and MRNEON_ALLOW_REGISTRATION_TEST_MUTATION=1. No test mutation attempted.\n");
    exit(77);
}

$temporaryEnvNames = [
    'MRNEON_TERMS_VERSION', 'MRNEON_RATE_LIMIT_HMAC_KEY', 'MRNEON_REGISTRATION_WINDOW_SECONDS',
    'MRNEON_REGISTRATION_IP_LIMIT', 'MRNEON_REGISTRATION_USERNAME_LIMIT',
    'MRNEON_REGISTRATION_PHONE_LIMIT', 'MRNEON_REGISTRATION_DESTINATION_LIMIT',
];
$oldEnv = [];
foreach ($temporaryEnvNames as $name) $oldEnv[$name] = getenv($name);
$testKey = bin2hex(random_bytes(32));
putenv('MRNEON_TERMS_VERSION=synthetic-registration-test-v1');
putenv('MRNEON_RATE_LIMIT_HMAC_KEY=' . $testKey);
// Bound the synthetic suite's counters so it is deterministic even under local environment overrides.
putenv('MRNEON_REGISTRATION_WINDOW_SECONDS=3600');
putenv('MRNEON_REGISTRATION_IP_LIMIT=100');
putenv('MRNEON_REGISTRATION_USERNAME_LIMIT=100');
putenv('MRNEON_REGISTRATION_PHONE_LIMIT=100');
putenv('MRNEON_REGISTRATION_DESTINATION_LIMIT=100');
$pdo = null;
$attemptIds = [];
$userIds = [];
$rateIdentities = [];
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Registration integration check failed: ' . $label);
};
$assertProblem = static function (callable $operation, int $status, string $code, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ' should return ' . $code);
    } catch (ApiProblemException $exception) {
        $assert($exception->httpStatus === $status && $exception->errorCode === $code, $label . ' returns ' . $status . '/' . $code);
    }
};

try {
    $pdo = Database::connection();
    $actualName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($actualName) || $actualName !== $expectedName) {
        fwrite(STDERR, "BLOCKED: PDO database identity does not match explicit test DB; no test mutation attempted.\n");
        exit(77);
    }
    $schema = (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/database/migrations'))->verifyChecksums();
    if ($schema['pending'] !== [] || $schema['checksum_errors'] !== [] || count($schema['applied']) !== 18) {
        fwrite(STDERR, "BLOCKED: test database does not have the checksum-verified Wave 2 + W4-P3 schema 001-018; no test mutation attempted.\n");
        exit(77);
    }
    foreach (['users', 'user_channels', 'registration_attempts', 'verification_challenges', 'audit_logs', 'rate_limit_buckets'] as $table) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($count !== 0) {
            fwrite(STDERR, "BLOCKED: required test table is not empty ('" . $table . "'); no test mutation attempted. Use a fresh disposable database.\n");
            exit(77);
        }
    }

    $suffix = bin2hex(random_bytes(5));
    $username = 'p2_' . $suffix;
    $phone = '+989' . (string) random_int(100000000, 999999999);
    $email = 'p2-' . $suffix . '@example.invalid';
    $ip = '127.0.0.1';
    $password = 'Synthetic registration passphrase ' . $suffix;
    $input = [
        'fullName' => 'Synthetic Registration Test',
        'username' => $username,
        'phone' => $phone,
        'password' => $password,
        'email' => $email,
        'selectedChannel' => 'telegram',
        'termsAccepted' => true,
    ];
    $rateIdentities = [
        ['registration_ip', $ip],
        ['registration_username', strtolower($username)],
        ['registration_phone', $phone],
        ['registration_email', $email],
        ['registration_destination', 'telegram:' . $phone],
    ];
    $service = new RegistrationService($pdo);
    $challengeCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM verification_challenges WHERE purpose = 'registration'")->fetchColumn();

    $first = $service->start($input, $ip);
    $attemptIds[] = $first['attemptId'];
    $assert(($first['state'] ?? null) === 'pending_verification', 'real database attempt returns pending state');
    $assert(($first['verificationStatus'] ?? null) === 'not_started', 'no false OTP delivery claim');
    $assert(is_string($first['telegramLinkNonce'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $first['telegramLinkNonce']) === 1, 'Telegram attempt receives high-entropy one-time link nonce');
    $stored = (new RegistrationAttemptRepository($pdo))->findForCompletion($first['attemptId']);
    $assert(is_array($stored) && $stored['password_hash'] !== $password && password_verify($password, $stored['password_hash']), 'database stores only recognized password hash');
    $assert(($stored['telegram_link_nonce_hash'] ?? null) === hash('sha256', $first['telegramLinkNonce']), 'database stores nonce hash only');
    $assert(($stored['phone_normalized'] ?? null) === $phone && ($stored['email_normalized'] ?? null) === $email, 'canonical identities persisted consistently');
    $assert(($stored['terms_version'] ?? null) === 'synthetic-registration-test-v1' && !empty($stored['terms_accepted_at']), 'server-owned Terms version and server timestamp persisted');

    $repeat = $service->start($input, $ip);
    $assert($repeat['attemptId'] === $first['attemptId'] && $repeat['reusedExistingAttempt'] === true, 'identical retry is idempotent and creates no second attempt');
    $assert($repeat['telegramLinkNonce'] !== $first['telegramLinkNonce'], 'idempotent retry rotates raw Telegram nonce and stored digest together');
    $attemptCount = (int) $pdo->query('SELECT COUNT(*) FROM registration_attempts')->fetchColumn();
    $assert($attemptCount === 1, 'duplicate retry leaves exactly one registration attempt');

    $differentPassword = $input;
    $differentPassword['password'] = $password . 'different';
    $assertProblem(static fn() => $service->start($differentPassword, $ip), 409, 'REGISTRATION_CONFLICT', 'same identifier with different request');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM registration_attempts')->fetchColumn() === 1, 'conflicting request rolls back without new attempt');

    $beforeChallenges = (int) $pdo->query("SELECT COUNT(*) FROM verification_challenges WHERE purpose = 'registration'")->fetchColumn();
    $assert($beforeChallenges === $challengeCountBefore, 'attempt creation does not create OTP or verification challenge');

    // Email channel path: stores a pending attempt only, without creating a user/channel or issuing a challenge.
    $emailSuffix = bin2hex(random_bytes(5));
    $emailInput = [
        'fullName' => 'Synthetic Email Registration Test',
        'username' => 'p2e_' . $emailSuffix,
        'phone' => '+989' . (string) random_int(100000000, 999999999),
        'password' => 'Synthetic email registration passphrase ' . $emailSuffix,
        'email' => 'p2e-' . $emailSuffix . '@example.invalid',
        'selectedChannel' => 'email',
        'termsAccepted' => true,
    ];
    $rateIdentities[] = ['registration_username', strtolower($emailInput['username'])];
    $rateIdentities[] = ['registration_phone', $emailInput['phone']];
    $rateIdentities[] = ['registration_email', $emailInput['email']];
    $rateIdentities[] = ['registration_destination', 'email:' . $emailInput['email']];
    $emailAttempt = $service->start($emailInput, $ip);
    $attemptIds[] = $emailAttempt['attemptId'];
    $assert(($emailAttempt['selectedChannel'] ?? null) === 'email' && ($emailAttempt['verificationStatus'] ?? null) === 'not_started', 'email channel returns pending state without claiming delivery');
    $assert(!array_key_exists('telegramLinkNonce', $emailAttempt), 'email channel does not receive Telegram linking nonce');
    $emailStored = (new RegistrationAttemptRepository($pdo))->findForCompletion($emailAttempt['attemptId']);
    $assert(is_array($emailStored) && $emailStored['selected_channel'] === 'email' && $emailStored['email_normalized'] === $emailInput['email'], 'email destination is stored canonically on attempt');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM user_channels')->fetchColumn() === 0, 'registration attempt does not create a verified or unverified user channel');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM verification_challenges WHERE purpose = 'registration'")->fetchColumn() === $challengeCountBefore, 'email registration attempt does not create a challenge');

    // Existing user conflict path: create one synthetic user inside the disposable test DB, then verify a matching registration is denied.
    $takenUsername = 'taken_' . substr($suffix, 0, 8);
    $takenPhone = '+989' . (string) random_int(100000000, 999999999);
    $user = (new UserRepository($pdo))->create([
        'full_name' => 'Synthetic Conflict Fixture',
        'username' => $takenUsername,
        'phone' => $takenPhone,
        'password_hash' => password_hash('Synthetic existing password ' . $suffix, PASSWORD_DEFAULT),
        'account_status' => 'pending',
    ]);
    $userIds[] = (int) $user['id'];
    $takenInput = [
        'fullName' => 'Synthetic Conflict Fixture',
        'username' => $takenUsername,
        'phone' => '+989' . (string) random_int(100000000, 999999999),
        'password' => 'Neon2026',
        'email' => null,
        'selectedChannel' => 'telegram',
        'termsAccepted' => true,
    ];
    $rateIdentities[] = ['registration_username', strtolower($takenUsername)];
    $rateIdentities[] = ['registration_phone', $takenInput['phone']];
    $rateIdentities[] = ['registration_destination', 'telegram:' . $takenInput['phone']];
    $assertProblem(static fn() => $service->start($takenInput, $ip), 409, 'REGISTRATION_CONFLICT', 'existing username conflict');

    $phoneConflictInput = [
        'fullName' => 'Synthetic Phone Conflict Test',
        'username' => 'p2p_' . bin2hex(random_bytes(5)),
        'phone' => $takenPhone,
        'password' => 'Synthetic phone conflict password ' . bin2hex(random_bytes(4)),
        'email' => null,
        'selectedChannel' => 'telegram',
        'termsAccepted' => true,
    ];
    $rateIdentities[] = ['registration_username', strtolower($phoneConflictInput['username'])];
    $rateIdentities[] = ['registration_phone', $phoneConflictInput['phone']];
    $rateIdentities[] = ['registration_destination', 'telegram:' . $phoneConflictInput['phone']];
    $assertProblem(static fn() => $service->start($phoneConflictInput, $ip), 409, 'REGISTRATION_CONFLICT', 'existing phone conflict independent of username');

    // Hold one canonical identity lock on a separate DB connection to exercise bounded contention.
    $busyInput = [
        'fullName' => 'Synthetic Lock Contention Test',
        'username' => 'p2l_' . bin2hex(random_bytes(5)),
        'phone' => '+989' . (string) random_int(100000000, 999999999),
        'password' => 'Synthetic lock contention password ' . bin2hex(random_bytes(4)),
        'email' => null,
        'selectedChannel' => 'telegram',
        'termsAccepted' => true,
    ];
    $rateIdentities[] = ['registration_username', strtolower($busyInput['username'])];
    $rateIdentities[] = ['registration_phone', $busyInput['phone']];
    $rateIdentities[] = ['registration_destination', 'telegram:' . $busyInput['phone']];
    $lockName = 'mrnreg_' . substr(hash('sha256', 'phone:' . $busyInput['phone']), 0, 56);
    $lockPdo = Database::connect();
    $lockStatement = $lockPdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStatement->execute([$lockName]);
    if ((string) $lockStatement->fetchColumn() !== '1') {
        throw new RuntimeException('Could not acquire the synthetic registration lock in the Integration test.');
    }
    try {
        $attemptCountBeforeLock = (int) $pdo->query('SELECT COUNT(*) FROM registration_attempts')->fetchColumn();
        $assertProblem(static fn() => $service->start($busyInput, $ip), 409, 'REGISTRATION_REQUEST_IN_PROGRESS', 'simulated concurrent identity-lock contention');
        $assert((int) $pdo->query('SELECT COUNT(*) FROM registration_attempts')->fetchColumn() === $attemptCountBeforeLock, 'lock contention creates no attempt');
    } finally {
        $releaseLock = $lockPdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseLock->execute([$lockName]);
        $lockPdo = null;
    }

    echo 'PASS: ' . $checks . " Registration Attempt DB integration checks; synthetic data only.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: Registration Attempt DB integration failed; exception type=' . get_class($exception) . "; no secrets or payloads printed.\n");
    exit(1);
} finally {
    if ($pdo instanceof PDO) {
        // Clean up only rows created by this run. No table truncation or database deletion is performed.
        try {
            foreach (array_unique($attemptIds) as $id) {
                $stmt = $pdo->prepare('DELETE FROM verification_challenges WHERE registration_attempt_id = ?');
                $stmt->execute([$id]);
                $stmt = $pdo->prepare('DELETE FROM registration_attempts WHERE id = ? AND consumed_at IS NULL');
                $stmt->execute([$id]);
                $stmt = $pdo->prepare('DELETE FROM audit_logs WHERE subject_type = ? AND subject_id = ?');
                $stmt->execute(['registration_attempt', $id]);
            }
            foreach (array_unique($userIds) as $id) {
                $stmt = $pdo->prepare('DELETE FROM user_channels WHERE user_id = ?');
                $stmt->execute([$id]);
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND username LIKE ?');
                $stmt->execute([$id, 'taken_%']);
            }
            foreach ($rateIdentities as [$scope, $identity]) {
                $hash = hash_hmac('sha256', $scope . "\0" . $identity, $testKey);
                $stmt = $pdo->prepare('DELETE FROM rate_limit_buckets WHERE scope_type = ? AND bucket_key_hash = ?');
                $stmt->execute([$scope, $hash]);
            }
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'WARNING: synthetic test cleanup needs review; exception type=' . get_class($cleanupError) . ".\n");
        }
    }
    foreach ($oldEnv as $name => $value) {
        if ($value === false) putenv($name); else putenv($name . '=' . $value);
    }
}
