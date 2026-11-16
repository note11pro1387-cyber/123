<?php
declare(strict_types=1);

final class RateLimitMiddleware
{
    public static function enforce(PDO $pdo, string $action, string $ip, ?string $accountIdentity = null): void
    {
        if ($action === 'register') {
            $policy = [
                'ipLimit' => self::limit('MRNEON_REGISTRATION_IP_LIMIT', 10, 1, 500),
                'accountLimit' => 0,
                'window' => self::limit('MRNEON_REGISTRATION_WINDOW_SECONDS', 3600, 60, 86400),
            ];
        } elseif ($action === 'login') {
            $policy = [
                'ipLimit' => self::limit('MRNEON_LOGIN_IP_LIMIT', 20, 1, 1000),
                'accountLimit' => self::limit('MRNEON_LOGIN_ACCOUNT_LIMIT', 8, 1, 500),
                'window' => self::limit('MRNEON_LOGIN_WINDOW_SECONDS', 300, 30, 86400),
            ];
        } elseif ($action === 'adminLogin') {
            $policy = [
                'ipLimit' => self::limit('MRNEON_ADMIN_LOGIN_IP_LIMIT', 8, 1, 500),
                'accountLimit' => self::limit('MRNEON_ADMIN_LOGIN_ACCOUNT_LIMIT', 5, 1, 200),
                'window' => self::limit('MRNEON_ADMIN_LOGIN_WINDOW_SECONDS', 300, 30, 86400),
            ];
        } elseif ($action === 'getSession') {
            $policy = [
                'ipLimit' => self::limit('MRNEON_SESSION_STATUS_IP_LIMIT', 60, 5, 1000),
                'accountLimit' => 0,
                'window' => self::limit('MRNEON_SESSION_STATUS_WINDOW_SECONDS', 60, 10, 86400),
            ];
        } elseif ($action === 'logout') {
            $policy = [
                'ipLimit' => self::limit('MRNEON_LOGOUT_IP_LIMIT', 30, 5, 1000),
                'accountLimit' => 0,
                'window' => self::limit('MRNEON_LOGOUT_WINDOW_SECONDS', 60, 10, 86400),
            ];
        } else {
            return;
        }
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(200);
        $ipScope = match ($action) {
            'adminLogin' => 'admin_login_ip',
            'getSession' => 'session_status_ip',
            'logout' => 'logout_ip',
            'register' => 'registration_ip',
            default => 'login_ip',
        };
        $accountScope = $action === 'adminLogin' ? 'admin_login_account' : 'login_account';
        self::consumeOrReject($repository, $ipScope, $ip, $policy['window'], $policy['ipLimit']);
        if ($policy['accountLimit'] > 0 && $accountIdentity !== null && trim($accountIdentity) !== '') {
            self::consumeOrReject($repository, $accountScope, strtolower(trim($accountIdentity)), $policy['window'], $policy['accountLimit']);
        }
    }

    /** Rate-limits admin data access and mutation using the existing HMAC-keyed buckets. */
    public static function enforceAdminAction(PDO $pdo, string $action, string $ip, string $adminIdentity): void
    {
        $policies = [
            'getAll' => ['scope' => 'admin_projects_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'exportProjectsCsv' => ['scope' => 'admin_projects_export', 'window' => 3600, 'ipLimit' => 6, 'accountLimit' => 10],
            'getAllUsers' => ['scope' => 'admin_users_read', 'window' => 60, 'ipLimit' => 90, 'accountLimit' => 120],
            'getAdminUserDetail' => ['scope' => 'admin_user_detail', 'window' => 60, 'ipLimit' => 60, 'accountLimit' => 90],
            'getAdminStats' => ['scope' => 'admin_stats_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'getAdminNotifications' => ['scope' => 'admin_notifications_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'getAdminMessages' => ['scope' => 'admin_messages_read', 'window' => 60, 'ipLimit' => 90, 'accountLimit' => 120],
            'getAdminAuditLog' => ['scope' => 'admin_audit_read', 'window' => 60, 'ipLimit' => 60, 'accountLimit' => 90],
            'getAdminSettingsSummary' => ['scope' => 'admin_settings_read', 'window' => 60, 'ipLimit' => 30, 'accountLimit' => 45],
            'markAdminNotificationRead' => ['scope' => 'admin_notification_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'getAdminProjectDetail' => ['scope' => 'admin_project_detail', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'adminUpdate' => ['scope' => 'admin_project_update', 'window' => 60, 'ipLimit' => 45, 'accountLimit' => 60],
            'adminSendMessage' => ['scope' => 'admin_project_message', 'window' => 3600, 'ipLimit' => 90, 'accountLimit' => 120],
            'markAdminMessagesRead' => ['scope' => 'admin_message_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'adminUpdateUser' => ['scope' => 'admin_user_update', 'window' => 300, 'ipLimit' => 15, 'accountLimit' => 20],
            'deleteRequest' => ['scope' => 'admin_project_archive', 'window' => 300, 'ipLimit' => 10, 'accountLimit' => 12],
        ];
        $policy = $policies[$action] ?? null;
        if (!is_array($policy)) return;
        if (trim($adminIdentity) === '' || strlen($adminIdentity) > 128) {
            throw new InvalidArgumentException('Admin rate-limit identity is invalid.');
        }
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(100);
        self::consumeOrReject($repository, $policy['scope'] . '_ip', $ip, $policy['window'], $policy['ipLimit']);
        self::consumeOrReject($repository, $policy['scope'] . '_account', strtolower(trim($adminIdentity)), $policy['window'], $policy['accountLimit']);
    }

    /** Rate limits customer project reads and writes using the existing HMAC-keyed buckets. */
    public static function enforceCustomerAction(PDO $pdo, string $action, string $ip, string $principalPublicId): void
    {
        $policies = [
            'getProjects' => ['scope' => 'customer_projects', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'getProjectById' => ['scope' => 'customer_project_detail', 'window' => 60, 'ipLimit' => 180, 'accountLimit' => 240],
            'getNotifications' => ['scope' => 'customer_notifications', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'markNotificationRead' => ['scope' => 'customer_notif_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'markCustomerMessagesRead' => ['scope' => 'customer_message_read', 'window' => 60, 'ipLimit' => 120, 'accountLimit' => 180],
            'sendMessage' => ['scope' => 'customer_message', 'window' => 3600, 'ipLimit' => 90, 'accountLimit' => 40],
        ];
        $policy = $policies[$action] ?? null;
        if (!is_array($policy)) return;
        if (!preg_match('/^[a-f0-9]{32}$/D', $principalPublicId)) {
            throw new InvalidArgumentException('Customer rate-limit identity is invalid.');
        }
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(100);
        self::consumeOrReject($repository, $policy['scope'] . '_ip', $ip, $policy['window'], $policy['ipLimit']);
        self::consumeOrReject($repository, $policy['scope'] . '_account', $principalPublicId, $policy['window'], $policy['accountLimit']);
    }

    /** Per-IP and per-Attempt OTP request limiting. Values are HMAC-keyed by the existing repository. */
    public static function enforceVerificationIp(PDO $pdo, string $operation, string $ip): void
    {
        if (!in_array($operation, ['send', 'resend', 'verify'], true)) {
            throw new InvalidArgumentException('Unsupported verification rate-limit operation.');
        }
        $window = 3600;
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(100);
        $ipLimit = match ($operation) {
            'verify' => self::limit('MRNEON_OTP_VERIFY_IP_LIMIT_PER_HOUR', 60, 5, 500),
            'resend' => self::limit('MRNEON_OTP_RESEND_IP_LIMIT_PER_HOUR', 20, 2, 200),
            default => self::limit('MRNEON_OTP_SEND_IP_LIMIT_PER_HOUR', 20, 2, 200),
        };
        self::consumeOrReject($repository, 'otp_' . $operation . '_ip', $ip, $window, $ipLimit);
    }

    public static function enforceVerificationAttempt(PDO $pdo, string $operation, string $attemptId): void
    {
        if (!in_array($operation, ['send', 'resend', 'verify'], true) || !preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new InvalidArgumentException('Verification rate-limit context is invalid.');
        }
        $limit = match ($operation) {
            'verify' => self::limit('MRNEON_OTP_VERIFY_ATTEMPT_LIMIT_PER_HOUR', 30, 5, 200),
            'resend' => self::limit('MRNEON_OTP_RESEND_ATTEMPT_LIMIT_PER_HOUR', 8, 1, 50),
            default => self::limit('MRNEON_OTP_SEND_ATTEMPT_LIMIT_PER_HOUR', 8, 1, 50),
        };
        $repository = new RateLimitBucketRepository($pdo);
        self::consumeOrReject($repository, 'otp_' . $operation . '_attempt', $attemptId, 3600, $limit);
    }

    public static function enforceVerificationDestination(PDO $pdo, string $channel, string $canonicalDestination): void
    {
        if (!in_array($channel, ['email', 'telegram'], true) || trim($canonicalDestination) === '' || strlen($canonicalDestination) > 255) {
            throw new InvalidArgumentException('Verification destination rate-limit context is invalid.');
        }
        $limit = self::limit('MRNEON_OTP_DESTINATION_LIMIT_PER_HOUR', 5, 1, 50);
        $repository = new RateLimitBucketRepository($pdo);
        self::consumeOrReject($repository, 'otp_destination', $channel . ':' . $canonicalDestination, 3600, $limit);
    }

    /** Rate-limit repeated Telegram link-status polling without persisting raw Attempt identifiers. */
    public static function enforceTelegramLinkStatus(PDO $pdo, string $ip, string $attemptId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new InvalidArgumentException('Telegram link status context is invalid.');
        }
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(50);
        self::consumeOrReject($repository, 'telegram_link_status_ip', $ip, 60, 60);
        self::consumeOrReject($repository, 'telegram_link_status_attempt', $attemptId, 60, 120);
    }

    /**
     * Add destination/account buckets after server normalization. Raw identifiers are HMACed
     * by RateLimitBucketRepository and are never persisted in rate_limit_buckets.
     */
    public static function enforceRegistrationIdentities(PDO $pdo, string $usernameNormalized, string $phoneCanonical, ?string $emailNormalized, string $channel): void
    {
        if (!in_array($channel, ['telegram', 'email'], true)) {
            throw new InvalidArgumentException('Unsupported registration channel for rate limiting.');
        }
        $window = self::limit('MRNEON_REGISTRATION_WINDOW_SECONDS', 3600, 60, 86400);
        $usernameLimit = self::limit('MRNEON_REGISTRATION_USERNAME_LIMIT', 5, 1, 200);
        $phoneLimit = self::limit('MRNEON_REGISTRATION_PHONE_LIMIT', 5, 1, 200);
        $destinationLimit = self::limit('MRNEON_REGISTRATION_DESTINATION_LIMIT', 5, 1, 200);
        $repository = new RateLimitBucketRepository($pdo);
        self::consumeOrReject($repository, 'registration_username', $usernameNormalized, $window, $usernameLimit);
        self::consumeOrReject($repository, 'registration_phone', $phoneCanonical, $window, $phoneLimit);
        if ($emailNormalized !== null) {
            self::consumeOrReject($repository, 'registration_email', $emailNormalized, $window, $destinationLimit);
        }
        // Until Telegram is actually linked, no Telegram recipient identity is trustworthy.
        // This channel bucket is therefore keyed by the canonical phone as a conservative proxy,
        // not as a delivery destination or proof of ownership.
        $destinationIdentity = $channel === 'email' ? $emailNormalized : $phoneCanonical;
        if (!is_string($destinationIdentity) || $destinationIdentity === '') {
            throw new ApiProblemException(422, 'REGISTRATION_DESTINATION_REQUIRED', 'Unprocessable content', 'مقصد لازم برای کانال انتخابی مشخص نیست.');
        }
        self::consumeOrReject($repository, 'registration_destination', $channel . ':' . $destinationIdentity, $window, $destinationLimit);
    }

    /** Enumeration-resistant recovery start limiter; normalized identities are HMACed by the existing repository. */
    public static function enforceRecoveryStart(PDO $pdo, string $ip, string $methodAndNormalizedIdentifier): void
    {
        if (trim($methodAndNormalizedIdentifier) === '' || strlen($methodAndNormalizedIdentifier) > 320) throw new InvalidArgumentException('Recovery rate-limit identity is invalid.');
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(100);
        self::consumeOrReject($repository, 'recovery_start_ip', $ip, 3600, self::limit('MRNEON_RECOVERY_START_IP_LIMIT_PER_HOUR', 20, 2, 300));
        self::consumeOrReject($repository, 'recovery_start_identity', $methodAndNormalizedIdentifier, 3600, self::limit('MRNEON_RECOVERY_START_IDENTITY_LIMIT_PER_HOUR', 5, 1, 50));
    }

    public static function enforceRecoveryVerify(PDO $pdo, string $ip, string $challengeId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $challengeId)) throw new InvalidArgumentException('Recovery Challenge reference is invalid.');
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(50);
        self::consumeOrReject($repository, 'recovery_verify_ip', $ip, 3600, self::limit('MRNEON_RECOVERY_VERIFY_IP_LIMIT_PER_HOUR', 60, 5, 500));
        self::consumeOrReject($repository, 'recovery_verify_challenge', $challengeId, 3600, self::limit('MRNEON_RECOVERY_VERIFY_CHALLENGE_LIMIT_PER_HOUR', 15, 3, 100));
    }

    public static function enforceRecoveryReset(PDO $pdo, string $ip, string $tokenHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $tokenHash)) throw new InvalidArgumentException('Reset authorization fingerprint is invalid.');
        $repository = new RateLimitBucketRepository($pdo);
        $repository->cleanupExpired(50);
        self::consumeOrReject($repository, 'recovery_reset_ip', $ip, 3600, self::limit('MRNEON_RECOVERY_RESET_IP_LIMIT_PER_HOUR', 30, 5, 300));
        self::consumeOrReject($repository, 'recovery_reset_token', $tokenHash, 3600, self::limit('MRNEON_RECOVERY_RESET_TOKEN_LIMIT_PER_HOUR', 10, 2, 100));
    }

    private static function consumeOrReject(RateLimitBucketRepository $repository, string $scope, string $identity, int $window, int $limit): void
    {
        $bucket = $repository->consume($scope, $identity, $window);
        $reset = self::utcTimestamp($bucket['reset_at'] ?? null);
        $blockedUntil = self::utcTimestamp($bucket['blocked_until'] ?? null);
        $retry = max(1, ($blockedUntil ?? $reset ?? time() + $window) - time());
        $count = (int) ($bucket['counter'] ?? 0);
        if (($blockedUntil !== null && $blockedUntil > time()) || $count > $limit) {
            throw new ApiProblemException(429, 'RATE_LIMITED', 'Too many requests', 'تعداد تلاش‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', ['Retry-After' => (string) min($retry, 86400)]);
        }
    }

    private static function limit(string $name, int $default, int $minimum, int $maximum): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') return $default;
        if (!ctype_digit((string) $raw)) throw new RuntimeException('Rate-limit configuration is invalid.');
        $value = (int) $raw;
        if ($value < $minimum || $value > $maximum) throw new RuntimeException('Rate-limit configuration is outside permitted bounds.');
        return $value;
    }

    private static function utcTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') return null;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($value, 0, 19), new DateTimeZone('UTC'));
        return $dt instanceof DateTimeImmutable ? $dt->getTimestamp() : null;
    }
}
