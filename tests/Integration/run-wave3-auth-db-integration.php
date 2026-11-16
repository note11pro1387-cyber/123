<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bin/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "BLOCKED: pdo_mysql driver is not installed; no authentication database tests were attempted.\n");
    exit(77);
}
$config = Database::configuration();
$dbName = (string) ($config['name'] ?? '');
if (!preg_match('/(^|_)(test|sandbox)(_|$)/i', $dbName)) {
    fwrite(STDERR, "BLOCKED: authentication integration tests require an explicitly test-scoped database name.\n");
    exit(77);
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Auth integration check failed: ' . $label);
};

function wave3Base32Decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(rtrim(trim($input), '='));
    $buffer = 0; $bits = 0; $output = '';
    foreach (str_split($input) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) throw new RuntimeException('Synthetic TOTP test secret is invalid.');
        $buffer = ($buffer << 5) | $position;
        $bits += 5;
        if ($bits >= 8) { $bits -= 8; $output .= chr(($buffer >> $bits) & 0xff); }
    }
    return $output;
}

function wave3TotpCodeAt(string $secret, int $timestamp): string
{
    $counter = intdiv($timestamp, 30);
    $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
    $hash = hash_hmac('sha1', $binaryCounter, wave3Base32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $number = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
}

$pdo = null;
$tempSessionDir = null;
$userId = null;
$userPublicId = null;
$legacyId = 'SYNTHETIC-WAVE3-AUTH-' . bin2hex(random_bytes(5));
$username = 'W3' . bin2hex(random_bytes(4));
$password = 'SYNTHETIC WAVE3 USER PASSWORD ONLY';
$adminUsername = 'SYNTHETIC-W3-ADMIN';
$adminPassword = 'SYNTHETIC WAVE3 ADMIN PASSWORD ONLY';
$totpSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$totpCounterUsed = null;
$unknownIdentity = null;
$testPassed = false;
$hmacKey = str_repeat('SYNTHETIC-WAVE3-HMAC-KEY-', 3);
$oldEnv = [];
$envNames = [
    'MRNEON_RATE_LIMIT_HMAC_KEY', 'MRNEON_ADMIN_USERNAME', 'MRNEON_ADMIN_PASSWORD_HASH',
    'MRNEON_ADMIN_TOTP_SECRET', 'MRNEON_LOGIN_IP_LIMIT', 'MRNEON_LOGIN_ACCOUNT_LIMIT',
    'MRNEON_LOGIN_WINDOW_SECONDS', 'MRNEON_ADMIN_LOGIN_IP_LIMIT',
    'MRNEON_ADMIN_LOGIN_ACCOUNT_LIMIT', 'MRNEON_ADMIN_LOGIN_WINDOW_SECONDS',
    'MRNEON_SESSION_STATUS_IP_LIMIT', 'MRNEON_SESSION_STATUS_WINDOW_SECONDS',
    'MRNEON_LOGOUT_IP_LIMIT', 'MRNEON_LOGOUT_WINDOW_SECONDS',
    'MRNEON_SESSION_COOKIE_SECURE', 'MRNEON_ENV',
];
foreach ($envNames as $name) $oldEnv[$name] = getenv($name);
$oldServer = [];
foreach (['REMOTE_ADDR', 'REQUEST_METHOD', 'HTTP_X_CSRF_TOKEN', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', 'HTTP_HOST', 'HTTPS'] as $name) $oldServer[$name] = $_SERVER[$name] ?? null;
$oldSessionPath = session_save_path();
$oldSessionName = session_name();

try {
    putenv('MRNEON_RATE_LIMIT_HMAC_KEY=' . $hmacKey);
    putenv('MRNEON_ENV=local');
    putenv('MRNEON_SESSION_COOKIE_SECURE=0');
    putenv('MRNEON_ADMIN_USERNAME=' . $adminUsername);
    putenv('MRNEON_ADMIN_PASSWORD_HASH=' . password_hash($adminPassword, PASSWORD_DEFAULT));
    putenv('MRNEON_ADMIN_TOTP_SECRET=' . $totpSecret);
    foreach (['MRNEON_LOGIN_IP_LIMIT'=>'20', 'MRNEON_LOGIN_ACCOUNT_LIMIT'=>'8', 'MRNEON_LOGIN_WINDOW_SECONDS'=>'300', 'MRNEON_ADMIN_LOGIN_IP_LIMIT'=>'8', 'MRNEON_ADMIN_LOGIN_ACCOUNT_LIMIT'=>'5', 'MRNEON_ADMIN_LOGIN_WINDOW_SECONDS'=>'300', 'MRNEON_SESSION_STATUS_IP_LIMIT'=>'60', 'MRNEON_SESSION_STATUS_WINDOW_SECONDS'=>'60', 'MRNEON_LOGOUT_IP_LIMIT'=>'30', 'MRNEON_LOGOUT_WINDOW_SECONDS'=>'60'] as $name=>$value) putenv($name . '=' . $value);

    $pdo = Database::connection();
    (new MigrationRunner($pdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->apply();
    $schemaStatus = (new MigrationRunner($pdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->verifyChecksums();
    $assert(($schemaStatus['current_version'] ?? null) === SchemaCatalog::CURRENT_SCHEMA_VERSION && ($schemaStatus['checksum_errors'] ?? ['missing']) === [], 'Wave 2 schema and migration checksums are available');

    $tempSessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mrneon-wave3-auth-session-' . bin2hex(random_bytes(5));
    if (!mkdir($tempSessionDir, 0700, true)) throw new RuntimeException('Could not create isolated synthetic session directory.');
    session_save_path($tempSessionDir);
    session_name('MRNEONSESSID');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.31';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
    SessionManager::start();
    $guestSessionId = session_id();
    $guestCsrf = Csrf::token();
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $guestCsrf;

    $created = (new UserRepository($pdo))->create([
        'full_name' => 'Synthetic Wave Three User',
        'username' => $username,
        'phone' => '+989' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'account_status' => 'active',
        'legacy_id' => $legacyId,
    ]);
    $userId = (int) $created['id'];
    $userPublicId = (string) $created['public_id'];
    $auth = new AuthService();
    $guestPayload = $auth->getSession();
    $assert(($guestPayload['authenticated'] ?? null) === false && ($guestPayload['csrfToken'] ?? null) === $guestCsrf, 'guest session bootstrap returns server CSRF only after the DB-backed IP limiter accepts');

    $badLoginRejected = false;
    try { $auth->login(['username' => $username, 'password' => 'SYNTHETIC WRONG PASSWORD']); }
    catch (ApiProblemException $e) { $badLoginRejected = $e->httpStatus === 401 && $e->errorCode === 'INVALID_CREDENTIALS'; }
    $assert($badLoginRejected && !isset($_SESSION['principal_type']), 'bad password receives generic 401 without creating a session');
    $assert(password_verify($password, (new UserRepository($pdo))->findForAuthenticationByUsername($username)['password_hash']), 'stored password hash remains valid after failed login');

    $_SERVER['HTTP_X_CSRF_TOKEN'] = $guestCsrf;
    $userSession = $auth->login(['username' => $username, 'password' => $password]);
    $userSessionId = session_id();
    $assert($userSession['authenticated'] === true && $userSession['principalType'] === 'user', 'valid active customer credentials create a user session');
    $assert($userSessionId !== $guestSessionId && $userSession['csrfToken'] !== $guestCsrf, 'successful login rotates session ID and CSRF token');
    $sessionHash = hash('sha256', $userSessionId);
    $registry = new AuthSessionRepository($pdo);
    $assert($registry->findActive($sessionHash) !== null, 'session registry stores an active hash of the rotated session ID');
    $principal = SessionManager::currentPrincipal($pdo);
    $assert(($principal['principalType'] ?? null) === 'user' && ($principal['user']['id'] ?? null) === $userId, 'current principal is revalidated from database, not request fields');
    $dto = json_encode($userSession['user'], JSON_THROW_ON_ERROR);
    $assert(!str_contains($dto, 'password_hash') && !str_contains($dto, 'account_status'), 'authenticated user DTO excludes password hash and internal status');

    $userCannotAdmin = false;
    try { AdminAuthMiddleware::requireAdmin(); }
    catch (ApiProblemException $e) { $userCannotAdmin = $e->httpStatus === 403 && $e->errorCode === 'FORBIDDEN'; }
    $assert($userCannotAdmin && (($_SESSION['principal_type'] ?? null) === 'user'), 'customer session cannot be promoted to admin via role policy');

    $_SERVER['HTTP_X_CSRF_TOKEN'] = $userSession['csrfToken'];
    $logout = $auth->logout();
    $assert($logout['authenticated'] === false && !isset($_SESSION['principal_type']), 'user logout clears authenticated PHP session state');
    $assert($registry->findActive($sessionHash) === null, 'user logout revokes the matching database session registry entry');

    (new UserRepository($pdo))->changeAccountStatus($userId, 'suspended');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
    $suspendedRejected = false;
    try { $auth->login(['username' => $username, 'password' => $password]); }
    catch (ApiProblemException $e) { $suspendedRejected = $e->httpStatus === 401 && $e->errorCode === 'INVALID_CREDENTIALS'; }
    $assert($suspendedRejected && !isset($_SESSION['principal_type']), 'suspended account cannot create an authenticated session');

    $staleCode = wave3TotpCodeAt($totpSecret, time() - 600);
    for ($offset = 0; $offset < 20 && Totp::matchingCounter($totpSecret, $staleCode) !== null; $offset++) {
        $staleCode = wave3TotpCodeAt($totpSecret, time() - (900 + ($offset * 30)));
    }
    $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
    $badTotpRejected = false;
    try { $auth->adminLogin(['username' => $adminUsername, 'password' => $adminPassword, 'totpCode' => $staleCode]); }
    catch (ApiProblemException $e) { $badTotpRejected = $e->httpStatus === 401 && $e->errorCode === 'INVALID_ADMIN_CREDENTIALS'; }
    $assert($badTotpRejected && !isset($_SESSION['principal_type']), 'invalid/stale TOTP cannot create an admin session');

    $totpCode = wave3TotpCodeAt($totpSecret, time());
    $totpCounterUsed = Totp::matchingCounter($totpSecret, $totpCode);
    if ($totpCounterUsed === null) throw new RuntimeException('Could not generate a valid synthetic TOTP code for the isolated integration test.');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
    $adminSession = $auth->adminLogin(['username' => $adminUsername, 'password' => $adminPassword, 'totpCode' => $totpCode]);
    $adminSessionId = session_id();
    $adminSessionHash = hash('sha256', $adminSessionId);
    $assert($adminSession['authenticated'] === true && $adminSession['principalType'] === 'admin' && $adminSession['user'] === null, 'independent admin login requires password plus TOTP and uses a distinct principal type');
    $assert($adminSessionId !== $guestSessionId && $adminSession['csrfToken'] !== $guestCsrf, 'admin login rotates the session ID and CSRF token');
    $adminRegistryRow = $registry->findActive($adminSessionHash);
    $assert(is_array($adminRegistryRow) && $adminRegistryRow['principal_type'] === 'admin' && $adminRegistryRow['user_id'] === null, 'admin session registry row is independent from customer user IDs');

    $replayRejected = false;
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $adminSession['csrfToken'];
    try { $auth->adminLogin(['username' => $adminUsername, 'password' => $adminPassword, 'totpCode' => $totpCode]); }
    catch (ApiProblemException $e) { $replayRejected = $e->httpStatus === 401 && $e->errorCode === 'INVALID_ADMIN_CREDENTIALS'; }
    $assert($replayRejected && session_id() === $adminSessionId, 'a successful TOTP counter cannot be replayed to create another admin session');
    $assert((SessionManager::currentPrincipal($pdo)['principalType'] ?? null) === 'admin', 'rejected TOTP replay does not silently downgrade current admin session');

    $_SESSION['auth_last_activity_at'] = time() - SessionManager::idleTimeout() - 1;
    $expired = SessionManager::currentPrincipal($pdo);
    $assert($expired === null && !isset($_SESSION['principal_type']), 'idle-expired session is invalidated locally');
    $assert($registry->findActive($adminSessionHash) === null, 'idle expiry revokes the DB session registry row');

    // Real DB-backed rate-limit behavior, scoped to one synthetic remote address.
    putenv('MRNEON_LOGIN_IP_LIMIT=1');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.99';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
    $unknown = 'UnknownW3' . bin2hex(random_bytes(2));
    $unknownIdentity = strtolower($unknown);
    $firstRejected = false;
    try { $auth->login(['username' => $unknown, 'password' => 'SYNTHETIC UNKNOWN PASSWORD']); }
    catch (ApiProblemException $e) { $firstRejected = $e->httpStatus === 401; }
    $limitRejected = false;
    try { $auth->login(['username' => $unknown, 'password' => 'SYNTHETIC UNKNOWN PASSWORD']); }
    catch (ApiProblemException $e) { $limitRejected = $e->httpStatus === 429 && isset($e->responseHeaders['Retry-After']); }
    $assert($firstRejected && $limitRejected, 'DB-backed login rate limit returns 429 and Retry-After after configured threshold');

    putenv('MRNEON_SESSION_STATUS_IP_LIMIT=1');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.21';
    $sessionStatusFirst = $auth->getSession();
    $sessionStatusRateLimited = false;
    try { $auth->getSession(); }
    catch (ApiProblemException $e) { $sessionStatusRateLimited = $e->httpStatus === 429 && isset($e->responseHeaders['Retry-After']); }
    $assert(($sessionStatusFirst['authenticated'] ?? null) === false && $sessionStatusRateLimited, 'session status endpoint has bounded DB-backed IP rate limiting');

    putenv('MRNEON_LOGOUT_IP_LIMIT=1');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.51';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
    $anonymousLogout = $auth->logout();
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $anonymousLogout['csrfToken'];
    $anonymousLogoutLimited = false;
    try { $auth->logout(); }
    catch (ApiProblemException $e) { $anonymousLogoutLimited = $e->httpStatus === 429 && isset($e->responseHeaders['Retry-After']); }
    $assert(($anonymousLogout['authenticated'] ?? true) === false && $anonymousLogoutLimited, 'anonymous session churn is rate-limited while authenticated logout remains unblocked');

    $testPassed = true;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: Wave 3 auth database integration; exception_type=' . get_class($exception) . PHP_EOL);
    $exit = 1;
} finally {
    if ($pdo instanceof PDO) {
        $cleanupFailed = false;
        try {
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['principal_type'])) SessionManager::logout($pdo);
        } catch (Throwable) { $cleanupFailed = true; }
        if ($userId !== null) {
            try {
                $delete = $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ? OR principal_id IN (?, ?)');
                $delete->execute([$userId, (string) $userPublicId, $adminUsername]);
            } catch (Throwable) { $cleanupFailed = true; }
        }
        if ($userId !== null) {
            try {
                $delete = $pdo->prepare('DELETE FROM user_channels WHERE user_id = ?');
                $delete->execute([$userId]);
            } catch (Throwable) { $cleanupFailed = true; }
        }
        if ($legacyId !== '') {
            try {
                $delete = $pdo->prepare('DELETE FROM users WHERE legacy_id = ?');
                $delete->execute([$legacyId]);
            } catch (Throwable) { $cleanupFailed = true; }
        }
        try {
            $hashes = [];
            foreach ([
                ['login_ip', '198.51.100.31'], ['login_account', strtolower($username)], ['session_status_ip', '198.51.100.31'],
                ['admin_login_ip', '198.51.100.31'], ['admin_login_account', strtolower($adminUsername)],
                ['login_ip', '192.0.2.99'], ['session_status_ip', '203.0.113.21'], ['logout_ip', '203.0.113.51'],
            ] as [$scope, $identity]) {
                $hashes[] = hash_hmac('sha256', $scope . "\0" . $identity, $hmacKey);
            }
            if ($unknownIdentity !== null) $hashes[] = hash_hmac('sha256', 'login_account' . "\0" . $unknownIdentity, $hmacKey);
            if ($totpCounterUsed !== null) $hashes[] = hash_hmac('sha256', 'totp' . "\0" . strtolower($adminUsername) . ':' . $totpCounterUsed, $hmacKey);
            $delete = $pdo->prepare('DELETE FROM rate_limit_buckets WHERE bucket_key_hash = ?');
            foreach (array_unique($hashes) as $hash) $delete->execute([$hash]);
        } catch (Throwable) { $cleanupFailed = true; }
        if ($cleanupFailed) $exit = 1;
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name($oldSessionName);
    session_save_path($oldSessionPath);
    if (is_string($tempSessionDir) && is_dir($tempSessionDir)) {
        foreach (glob($tempSessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
        @rmdir($tempSessionDir);
    }
    foreach ($oldServer as $name => $value) {
        if ($value === null) unset($_SERVER[$name]); else $_SERVER[$name] = $value;
    }
    foreach ($oldEnv as $name => $value) {
        if ($value === false) putenv($name); else putenv($name . '=' . $value);
    }
}
if (isset($exit) && $exit !== 0) exit($exit);
if ($testPassed) echo 'PASS: ' . $checks . " Wave 3 authentication/session/authorization/MFA integration checks; test-scoped DB; SYNTHETIC TEST DATA only.\n";
