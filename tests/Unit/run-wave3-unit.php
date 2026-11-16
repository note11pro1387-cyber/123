<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Wave 3 unit check failed: ' . $label);
};

$success = ApiResponse::successPayload(['authenticated' => false], 'unit-request-id');
$assert(isset($success['data']) && $success['meta']['requestId'] === 'unit-request-id', 'success envelope follows Handoff data/meta contract');
$assert(!array_key_exists('ok', $success) && !array_key_exists('success', $success), 'success envelope has no competing legacy flags');
$problem = ApiResponse::problemPayload(403, 'CSRF_VALIDATION_FAILED', 'Forbidden', 'safe detail', 'unit-request-id');
$assert($problem['status'] === 403 && $problem['code'] === 'CSRF_VALIDATION_FAILED', 'problem payload carries HTTP status and stable code');
$assert($problem['requestId'] === 'unit-request-id' && str_contains($problem['type'], 'csrf-validation-failed'), 'problem payload carries correlation ID and type URI');

$exception = new ApiProblemException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'safe detail', ['Allow' => 'POST']);
$assert($exception->httpStatus === 405 && $exception->title === 'Method not allowed', 'API problem exception preserves status/title');
$assert($exception->getMessage() === 'safe detail' && $exception->responseHeaders['Allow'] === 'POST', 'API problem exception preserves safe detail and allowed headers');

try {
    MethodPolicy::enforce('login', 'GET');
    $assert(false, 'login rejects GET');
} catch (ApiProblemException $e) {
    $assert($e->httpStatus === 405 && $e->responseHeaders['Allow'] === 'POST', 'method policy returns 405 with Allow header');
}
$assert(MethodPolicy::known('getSession') && MethodPolicy::known('adminLogin') && !MethodPolicy::known('madeUp'), 'action allowlist recognizes only registered names');

$_SERVER['REMOTE_ADDR'] = '192.0.2.17';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
$assert(ApiRequest::clientIp() === '192.0.2.17', 'client IP ignores untrusted forwarded header');
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
$assert(ApiRequest::clientIp() === 'unknown', 'invalid remote address is normalized safely');

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$assert(Totp::matchingCounter($secret, '287082', 59, 0) === 1, 'TOTP verifier matches RFC 6238 six-digit vector');
$assert(Totp::verify($secret, '287082', 59, 0), 'TOTP valid six-digit vector accepted');
$assert(!Totp::verify($secret, '287083', 59, 0), 'TOTP incorrect code rejected');
$assert(Totp::matchingCounter('NOT-BASE32', '123456', 59, 0) === null, 'malformed TOTP secret rejected');
$assert(Totp::matchingCounter($secret, '12345', 59, 0) === null, 'TOTP length mismatch rejected');

$dto = UserDTO::fromRepositoryRow([
    'id' => 7, 'public_id' => str_repeat('a', 32), 'full_name' => 'Synthetic User',
    'username' => 'Synthetic_User', 'phone' => '+989121234567', 'email' => 'synthetic@example.invalid',
    'account_status' => 'active', 'password_hash' => 'SYNTHETIC_HASH_MUST_NOT_ESCAPE',
]);
$dtoJson = json_encode($dto, JSON_THROW_ON_ERROR);
$assert(isset($dto['id'], $dto['username'], $dto['role']) && $dto['role'] === 'user', 'User DTO includes allowed public fields');
$assert(!str_contains($dtoJson, 'password_hash') && !str_contains($dtoJson, 'SYNTHETIC_HASH_MUST_NOT_ESCAPE') && !str_contains($dtoJson, 'account_status'), 'User DTO excludes password hash and internal account state');

// Verify session-bound CSRF mechanics without DB access or real user data.
$tempSessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mrneon-wave3-csrf-' . bin2hex(random_bytes(5));
if (!mkdir($tempSessionDir, 0700, true)) throw new RuntimeException('Could not create temporary CSRF session directory.');
$oldSessionPath = session_save_path();
$oldSessionName = session_name();
try {
    session_save_path($tempSessionDir);
    session_name('MRNEONW3UNIT');
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) throw new RuntimeException('Could not start synthetic session.');
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
    Csrf::assertValid();
    $assert(true, 'valid CSRF token accepted for its session');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('0', 64);
    try {
        Csrf::assertValid();
        $assert(false, 'invalid CSRF token rejected');
    } catch (ApiProblemException $e) {
        $assert($e->httpStatus === 403 && $e->errorCode === 'CSRF_VALIDATION_FAILED', 'invalid CSRF token returns 403 stable code');
    }
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
    try {
        Csrf::assertValid();
        $assert(false, 'cross-site fetch metadata rejected');
    } catch (ApiProblemException $e) {
        $assert($e->httpStatus === 403 && $e->errorCode === 'CROSS_SITE_REQUEST_REJECTED', 'cross-site Fetch Metadata rejected');
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name($oldSessionName);
    session_save_path($oldSessionPath);
    foreach (glob($tempSessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    @rmdir($tempSessionDir);
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['REQUEST_METHOD']);
}

echo 'PASS: ' . $checks . " Wave 3 unit/security contract checks; synthetic values only.\n";
