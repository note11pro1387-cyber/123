<?php
declare(strict_types=1);

final class AuthService
{
    private static ?string $dummyPasswordHash = null;

    /** @return array<string,mixed> */
    public function getSession(): array
    {
        SessionManager::start();
        $pdo = Database::connection();
        RateLimitMiddleware::enforce($pdo, 'getSession', ApiRequest::clientIp(), null);
        $csrf = Csrf::token();
        if (!isset($_SESSION['principal_type'])) {
            return ['authenticated' => false, 'principalType' => null, 'user' => null, 'admin' => null, 'csrfToken' => $csrf];
        }
        $principal = SessionManager::currentPrincipal($pdo);
        if ($principal === null) {
            return ['authenticated' => false, 'principalType' => null, 'user' => null, 'admin' => null, 'csrfToken' => Csrf::token()];
        }
        if ($principal['principalType'] === 'user') {
            return [
                'authenticated' => true,
                'principalType' => 'user',
                'user' => UserDTO::fromRepositoryRow($principal['user']),
                'admin' => null,
                'csrfToken' => $principal['csrfToken'],
                'expiresAt' => $principal['expiresAt'],
            ];
        }
        return [
            'authenticated' => true,
            'principalType' => 'admin',
            'user' => null,
            'admin' => $principal['admin'],
            'csrfToken' => $principal['csrfToken'],
            'expiresAt' => $principal['expiresAt'],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function login(array $input): array
    {
        SessionManager::start();
        Csrf::assertValid();
        self::assertAllowedKeys($input, ['username', 'password']);
        $username = $input['username'] ?? null;
        $password = $input['password'] ?? null;
        if (!is_string($username) || !is_string($password) || strlen($username) > 64 || strlen($password) < 1 || strlen($password) > 1024) {
            throw new ApiProblemException(422, 'INVALID_CREDENTIAL_INPUT', 'Unprocessable content', 'نام کاربری و رمز عبور را با قالب معتبر وارد کنید.');
        }
        $username = trim($username);
        if ($username === '') {
            throw new ApiProblemException(422, 'INVALID_CREDENTIAL_INPUT', 'Unprocessable content', 'نام کاربری و رمز عبور را با قالب معتبر وارد کنید.');
        }

        $pdo = Database::connection();
        $accountLimitIdentity = preg_match('/^[A-Za-z0-9_]{4,20}$/D', $username) ? $username : null;
        RateLimitMiddleware::enforce($pdo, 'login', ApiRequest::clientIp(), $accountLimitIdentity);
        $users = new UserRepository($pdo);
        $row = null;
        if (preg_match('/^[A-Za-z0-9_]{4,20}$/D', $username)) {
            $row = $users->findForAuthenticationByUsername($username);
        }
        $storedHash = is_array($row) && is_string($row['password_hash'] ?? null) ? $row['password_hash'] : self::dummyHash();
        $passwordValid = password_verify($password, $storedHash);
        if (!is_array($row) || !$passwordValid || ($row['account_status'] ?? '') !== 'active') {
            throw new ApiProblemException(401, 'INVALID_CREDENTIALS', 'Unauthorized', 'نام کاربری یا رمز عبور معتبر نیست.');
        }

        $profile = $users->findById((int) $row['id']);
        if (!is_array($profile) || ($profile['account_status'] ?? '') !== 'active') {
            throw new ApiProblemException(401, 'INVALID_CREDENTIALS', 'Unauthorized', 'نام کاربری یا رمز عبور معتبر نیست.');
        }
        $pdo->beginTransaction();
        try {
            if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                if (!$users->replacePasswordHash((int) $row['id'], password_hash($password, PASSWORD_DEFAULT))) {
                    throw new RuntimeException('Password hash refresh could not be persisted.');
                }
            }
            if (!$users->recordSuccessfulLogin((int) $row['id'])) {
                throw new ApiProblemException(401, 'ACCOUNT_NOT_ACTIVE', 'Unauthorized', 'ورود برای این حساب مجاز نیست.');
            }
            $csrf = SessionManager::establishWithinTransaction(
                $pdo,
                'user',
                (string) $row['public_id'],
                (int) $row['id']
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            SessionManager::invalidateCurrentLocally();
            throw $exception;
        }
        return [
            'authenticated' => true,
            'principalType' => 'user',
            'user' => UserDTO::fromRepositoryRow($profile),
            'admin' => null,
            'csrfToken' => $csrf,
            'expiresAt' => $_SESSION['auth_absolute_expires_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function adminLogin(array $input): array
    {
        SessionManager::start();
        Csrf::assertValid();
        self::assertAllowedKeys($input, ['username', 'password', 'totpCode']);
        $username = $input['username'] ?? null;
        $password = $input['password'] ?? null;
        $totpCode = $input['totpCode'] ?? null;
        if (!is_string($username) || !is_string($password) || !is_string($totpCode)
            || strlen($username) > 128 || $password === '' || strlen($password) > 1024
            || !preg_match('/^[0-9]{6}$/D', $totpCode)) {
            throw new ApiProblemException(422, 'INVALID_ADMIN_CREDENTIAL_INPUT', 'Unprocessable content', 'اطلاعات ورود مدیر معتبر نیست.');
        }
        $username = trim($username);
        $adminUsername = self::env('MRNEON_ADMIN_USERNAME');
        $adminPasswordHash = self::env('MRNEON_ADMIN_PASSWORD_HASH');
        $totpSecret = self::env('MRNEON_ADMIN_TOTP_SECRET');
        if ($adminUsername === '' || $adminPasswordHash === '' || $totpSecret === '' || password_get_info($adminPasswordHash)['algoName'] === 'unknown') {
            throw new ApiProblemException(503, 'ADMIN_AUTH_NOT_CONFIGURED', 'Service unavailable', 'ورود مدیر هنوز در تنظیمات خصوصی سرور کامل پیکربندی نشده است.');
        }
        $pdo = Database::connection();
        $accountIdentity = hash_equals(strtolower($adminUsername), strtolower($username)) ? $adminUsername : null;
        RateLimitMiddleware::enforce($pdo, 'adminLogin', ApiRequest::clientIp(), $accountIdentity);
        $passwordValid = password_verify($password, $adminPasswordHash);
        $usernameValid = hash_equals(strtolower($adminUsername), strtolower($username));
        $totpCounter = Totp::matchingCounter($totpSecret, $totpCode);
        if (!$passwordValid || !$usernameValid || $totpCounter === null) {
            throw new ApiProblemException(401, 'INVALID_ADMIN_CREDENTIALS', 'Unauthorized', 'اطلاعات ورود مدیر معتبر نیست.');
        }
        // A valid TOTP counter can create at most one successful login, preventing code replay.
        $totpBucket = (new RateLimitBucketRepository($pdo))->consume('totp', strtolower($adminUsername) . ':' . $totpCounter, 90);
        if ((int) ($totpBucket['counter'] ?? 0) > 1) {
            throw new ApiProblemException(401, 'INVALID_ADMIN_CREDENTIALS', 'Unauthorized', 'اطلاعات ورود مدیر معتبر نیست.');
        }
        $credentialFingerprint = hash('sha256', $adminUsername . "\0" . $adminPasswordHash . "\0" . $totpSecret);
        $pdo->beginTransaction();
        try {
            $csrf = SessionManager::establishWithinTransaction($pdo, 'admin', $adminUsername, null, [], $credentialFingerprint);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            SessionManager::invalidateCurrentLocally();
            throw $exception;
        }
        return [
            'authenticated' => true,
            'principalType' => 'admin',
            'user' => null,
            'admin' => ['username' => $adminUsername, 'role' => 'admin'],
            'csrfToken' => $csrf,
            'expiresAt' => $_SESSION['auth_absolute_expires_at'] ?? null,
        ];
    }

    public function logout(): array
    {
        SessionManager::start();
        Csrf::assertValid();
        if (!isset($_SESSION['principal_type'])) {
            // Bound anonymous session churn. An authenticated principal is always allowed
            // to terminate its session so rate limiting can never trap a signed-in user.
            $pdo = Database::connection();
            RateLimitMiddleware::enforce($pdo, 'logout', ApiRequest::clientIp(), null);
            SessionManager::logout($pdo);
        } else {
            SessionManager::logout();
        }
        return ['authenticated' => false, 'principalType' => null, 'user' => null, 'admin' => null, 'csrfToken' => Csrf::token()];
    }

    private static function assertAllowedKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ApiProblemException(422, 'UNEXPECTED_FIELD', 'Unprocessable content', 'درخواست شامل فیلدی است که پذیرفته نمی‌شود.');
            }
        }
    }

    private static function dummyHash(): string
    {
        if (self::$dummyPasswordHash === null) {
            self::$dummyPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        return self::$dummyPasswordHash;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : trim((string) $value);
    }
}
