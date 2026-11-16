<?php
declare(strict_types=1);

final class SessionManager
{
    private const COOKIE_NAME = 'MRNEONSESSID';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        if (headers_sent()) {
            throw new RuntimeException('Cannot start a session after response output.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) self::absoluteTimeout());
        session_name(self::COOKIE_NAME);
        $production = strtolower(self::env('MRNEON_ENV', 'local')) === 'production';
        $secureSetting = self::env('MRNEON_SESSION_COOKIE_SECURE', '');
        $secure = $production || $secureSetting === '1';
        if (!$production && $secureSetting === '' && !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $secure = true;
        }
        if ($secureSetting !== '' && !in_array($secureSetting, ['0', '1'], true)) {
            throw new RuntimeException('Session cookie security configuration is invalid.');
        }
        if ($production && $secureSetting === '0') {
            throw new RuntimeException('Secure session cookies cannot be disabled in production.');
        }
        if ($production && empty($_SERVER['HTTPS']) && $secureSetting !== '1') {
            // HTTPS termination/proxy trust must be configured explicitly; never infer it from X-Forwarded-Proto.
            throw new RuntimeException('Production sessions require explicit HTTPS cookie configuration.');
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Session could not be started.');
        }
        Csrf::token();
    }

    public static function idleTimeout(): int
    {
        return self::boundedEnvInt('MRNEON_SESSION_IDLE_TIMEOUT', 1800, 60, 86400);
    }

    public static function absoluteTimeout(): int
    {
        $legacyDefault = defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 86400;
        return self::boundedEnvInt('MRNEON_SESSION_ABSOLUTE_TIMEOUT', $legacyDefault, 300, 604800);
    }

    /** Returns null for a guest; authenticated principals are revalidated against the registry and account source. */
    public static function currentPrincipal(PDO $pdo): ?array
    {
        self::start();
        $type = $_SESSION['principal_type'] ?? null;
        if (!is_string($type)) return null;
        $principalId = $_SESSION['principal_id'] ?? null;
        $created = $_SESSION['auth_created_at'] ?? null;
        $lastActivity = $_SESSION['auth_last_activity_at'] ?? null;
        $absoluteExpires = $_SESSION['auth_absolute_expires_at'] ?? null;
        if (!is_string($principalId) || $principalId === '' || !is_int($created) || !is_int($lastActivity) || !is_int($absoluteExpires)) {
            self::invalidateCurrentLocally();
            return null;
        }
        $now = time();
        if (($now - $lastActivity) > self::idleTimeout() || $now >= $absoluteExpires || $created > $now + 60) {
            self::revokeCurrentBestEffort($pdo, 'session_expired');
            self::invalidateCurrentLocally();
            return null;
        }
        $sessionHash = hash('sha256', session_id());
        $registry = new AuthSessionRepository($pdo);
        $active = $registry->findActive($sessionHash);
        if (!is_array($active)
            || (string) ($active['principal_type'] ?? '') !== $type
            || !hash_equals((string) ($active['principal_id'] ?? ''), $principalId)) {
            self::invalidateCurrentLocally();
            return null;
        }
        if ($type === 'user') {
            $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($userId) || $userId < 1 || (int) ($active['user_id'] ?? 0) !== $userId) {
                self::revokeCurrentBestEffort($pdo, 'principal_mismatch');
                self::invalidateCurrentLocally();
                return null;
            }
            $user = (new UserRepository($pdo))->findById($userId);
            if (!is_array($user) || ($user['account_status'] ?? '') !== 'active'
                || !is_string($user['public_id'] ?? null) || !hash_equals($user['public_id'], $principalId)) {
                self::revokeCurrentBestEffort($pdo, 'account_inactive');
                self::invalidateCurrentLocally();
                return null;
            }
            if (!$registry->touch($sessionHash)) {
                self::invalidateCurrentLocally();
                return null;
            }
            $_SESSION['auth_last_activity_at'] = $now;
            return ['principalType' => 'user', 'principalId' => $principalId, 'user' => $user, 'csrfToken' => Csrf::token(), 'expiresAt' => $absoluteExpires];
        }
        if ($type === 'admin') {
            $adminName = self::env('MRNEON_ADMIN_USERNAME');
            $passwordHash = self::env('MRNEON_ADMIN_PASSWORD_HASH');
            $totpSecret = self::env('MRNEON_ADMIN_TOTP_SECRET');
            $fingerprint = hash('sha256', $adminName . "\0" . $passwordHash . "\0" . $totpSecret);
            if ($adminName === '' || $passwordHash === '' || $totpSecret === ''
                || !hash_equals($adminName, $principalId)
                || !is_string($_SESSION['principal_credential_fingerprint'] ?? null)
                || !hash_equals($_SESSION['principal_credential_fingerprint'], $fingerprint)) {
                self::revokeCurrentBestEffort($pdo, 'admin_credentials_changed');
                self::invalidateCurrentLocally();
                return null;
            }
            if (!$registry->touch($sessionHash)) {
                self::invalidateCurrentLocally();
                return null;
            }
            $_SESSION['auth_last_activity_at'] = $now;
            return ['principalType' => 'admin', 'principalId' => $principalId, 'admin' => ['username' => $principalId, 'role' => 'admin'], 'csrfToken' => Csrf::token(), 'expiresAt' => $absoluteExpires];
        }
        self::revokeCurrentBestEffort($pdo, 'invalid_principal_type');
        self::invalidateCurrentLocally();
        return null;
    }

    /** Must be called inside a caller-owned DB transaction. */
    public static function establishWithinTransaction(PDO $pdo, string $principalType, string $principalId, ?int $userId, array $principalData = [], ?string $credentialFingerprint = null): string
    {
        self::start();
        if (!$pdo->inTransaction()) throw new LogicException('Session registration requires a caller-owned transaction.');
        if (!in_array($principalType, ['user', 'admin'], true)) throw new InvalidArgumentException('Invalid session principal type.');
        $oldId = session_id();
        $oldHash = $oldId !== '' ? hash('sha256', $oldId) : null;
        if (isset($_SESSION['principal_type']) && is_string($_SESSION['principal_type']) && $oldHash !== null) {
            (new AuthSessionRepository($pdo))->revoke($oldHash, 'session_rotated');
        }
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Session ID rotation failed.');
        }
        $now = time();
        $absoluteExpires = $now + self::absoluteTimeout();
        $_SESSION = [
            'principal_type' => $principalType,
            'principal_id' => $principalId,
            'user_id' => $userId,
            'auth_created_at' => $now,
            'auth_last_activity_at' => $now,
            'auth_absolute_expires_at' => $absoluteExpires,
            'principal_credential_fingerprint' => $credentialFingerprint,
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
        (new AuthSessionRepository($pdo))->create(
            hash('sha256', session_id()),
            $principalType,
            $principalId,
            $userId,
            gmdate('Y-m-d H:i:s', $absoluteExpires),
            1
        );
        return Csrf::token();
    }

    public static function logout(PDO $pdoIfAvailable = null): void
    {
        self::start();
        $wasAuthenticated = isset($_SESSION['principal_type']) && is_string($_SESSION['principal_type']);
        $failure = null;
        if ($wasAuthenticated) {
            try {
                $pdo = $pdoIfAvailable ?? Database::connection();
                (new AuthSessionRepository($pdo))->revoke(hash('sha256', session_id()), 'logout');
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        }
        self::destroyAndStartAnonymous();
        if ($failure !== null) {
            throw new ApiProblemException(503, 'SESSION_REVOCATION_UNCONFIRMED', 'Service unavailable', 'خروج محلی انجام شد، اما ابطال نشست در پایگاه داده تأیید نشد؛ درخواست را دوباره بررسی کنید.');
        }
    }

    public static function invalidateCurrentLocally(): void
    {
        self::destroyAndStartAnonymous();
    }

    private static function revokeCurrentBestEffort(PDO $pdo, string $reason): void
    {
        try {
            (new AuthSessionRepository($pdo))->revoke(hash('sha256', session_id()), $reason);
        } catch (Throwable $exception) {
            error_log('MR NEON session revoke failed: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
        }
    }

    private static function destroyAndStartAnonymous(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[session_name()]);
            session_destroy();
            session_id('');
        }
        self::start();
        $_SESSION = ['csrf_token' => bin2hex(random_bytes(32))];
    }

    private static function boundedEnvInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') return $default;
        if (!ctype_digit((string) $raw)) throw new RuntimeException('Invalid session timeout configuration.');
        $value = (int) $raw;
        if ($value < $min || $value > $max) throw new RuntimeException('Session timeout configuration is outside permitted bounds.');
        return $value;
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim((string) $value);
    }
}
