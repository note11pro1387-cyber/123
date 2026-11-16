<?php
declare(strict_types=1);

/**
 * Password recovery orchestration. It reuses the existing OTP, Challenge, Transport,
 * Rate Limit, Audit and Session repositories; reset authorization is a separate capability.
 */
final class RecoveryService
{
    private const START_FLOOR_MICROSECONDS = 1000000;
    private const LOCK_WAIT_SECONDS = 3;

    private UserRepository $users;
    private VerificationChallengeRepository $challenges;
    private VerificationDeliveryAttemptRepository $deliveries;
    private OtpVerifier $otpVerifier;
    private ClockInterface $clock;

    public function __construct(
        private readonly PDO $pdo,
        private readonly VerificationTransportInterface $transport,
        ?OtpVerifier $otpVerifier = null,
        ?ClockInterface $clock = null
    ) {
        $this->users = new UserRepository($pdo);
        $this->challenges = new VerificationChallengeRepository($pdo);
        $this->deliveries = new VerificationDeliveryAttemptRepository($pdo);
        $this->otpVerifier = $otpVerifier ?? new OtpVerifier();
        $this->clock = $clock ?? new SystemClock();
    }

    /** @return array{challengeRef:string,message:string,ttlSeconds:int} */
    public function startRecovery(array $input, string $clientIp): array
    {
        self::assertAllowedFields($input, ['identifier', 'method']);
        $method = self::requiredString($input, 'method', 16);
        $identifier = self::requiredString($input, 'identifier', 254);
        $normalized = self::normalizeIdentifier($method, $identifier);
        $this->otpVerifier->assertConfigured();
        RecoveryPolicy::resetAuthorizationSeconds();

        RateLimitMiddleware::enforceRecoveryStart($this->pdo, self::safeIp($clientIp), $method . ':' . $normalized);
        $reference = $this->otpVerifier->recoveryReference($method, $normalized);
        $startedAt = hrtime(true);
        $identityLock = self::lockName('identity:' . $method . ':' . $normalized, 'i');
        $heldIdentityLock = false;
        $heldUserLock = false;
        $userLock = null;

        try {
            if (!self::acquireLock($this->pdo, $identityLock, self::LOCK_WAIT_SECONDS)) {
                return self::genericStartResponse($reference);
            }
            $heldIdentityLock = true;
            $target = $this->users->findVerifiedRecoveryTarget($method, $normalized);
            if (!is_array($target)) {
                return self::genericStartResponse($reference);
            }

            $userId = (int) ($target['user_id'] ?? 0);
            $channelId = (int) ($target['user_channel_id'] ?? 0);
            $channel = (string) ($target['channel_type'] ?? '');
            $destination = (string) ($target['channel_value'] ?? '');
            $destinationNormalized = (string) ($target['normalized_value'] ?? '');
            if ($userId < 1 || $channelId < 1 || $channel !== $method || $destination === '' || $destinationNormalized === '') {
                return self::genericStartResponse($reference);
            }

            $userLock = self::lockName('user:' . $userId, 'u');
            if (!self::acquireLock($this->pdo, $userLock, self::LOCK_WAIT_SECONDS)) {
                return self::genericStartResponse($reference);
            }
            $heldUserLock = true;

            $challengeId = bin2hex(random_bytes(16));
            $code = OtpGenerator::generate();
            $codeMac = $this->otpVerifier->mac($challengeId, 'password_reset', $channel, $destinationNormalized, $code);
            $destinationFingerprint = $this->otpVerifier->destinationFingerprint('password_reset', $channel, $destinationNormalized);
            $deliveryKind = 'initial';

            $this->beginTransaction();
            try {
                if (!$this->users->lockActiveForPasswordReset($userId)
                    || !$this->users->lockVerifiedRecoveryChannel($userId, $channelId, $channel, $destinationNormalized)) {
                    $this->pdo->rollBack();
                    return self::genericStartResponse($reference);
                }

                $active = $this->challenges->findCurrentActivePasswordResetForUser($userId, true);
                if (is_array($active)) {
                    // Do not disclose whether a matching challenge exists, and do not create parallel live OTPs.
                    $this->pdo->commit();
                    return self::genericStartResponse($reference);
                }
                if ($this->deliveries->hasPreparedPasswordResetForUser($userId)) {
                    $this->pdo->commit();
                    return self::genericStartResponse($reference);
                }
                $latest = $this->deliveries->latestPasswordResetForUser($userId);
                if (is_array($latest)) {
                    $state = (string) ($latest['state'] ?? '');
                    // UNKNOWN must never become a blind retry path. A NOT_CONFIGURED outcome can retry safely.
                    if ($state === 'unknown' || $state === 'prepared') {
                        $this->pdo->commit();
                        return self::genericStartResponse($reference);
                    }
                    if ($state !== 'not_configured') {
                        $attempts = self::parseUtc($latest['resolved_at'] ?? null) ?? self::parseUtc($latest['created_at'] ?? null);
                        if (VerificationPolicy::resendRetryAfter($attempts, $this->clock->now()) > 0) {
                            $this->pdo->commit();
                            return self::genericStartResponse($reference);
                        }
                    }
                }
                if ($this->deliveries->countPasswordResetForUser($userId) >= RecoveryPolicy::maxDeliveryAttempts()) {
                    $this->pdo->commit();
                    return self::genericStartResponse($reference);
                }
                if ($this->deliveries->countAcceptedPasswordResetForUser($userId) > 0) $deliveryKind = 'resend';

                $preparedAt = $this->clock->now();
                $this->deliveries->createPreparedPasswordReset($challengeId, $userId, $channel, $destinationFingerprint, $deliveryKind);
                $pendingChallengeId = $this->challenges->createPendingDelivery([
                    'id' => $challengeId,
                    'purpose' => 'password_reset',
                    'subject_type' => 'user',
                    'user_id' => $userId,
                    'user_channel_id' => $channelId,
                    'channel_type' => $channel,
                    'destination_value' => $destination,
                    'destination_normalized' => $destinationNormalized,
                    'code_hmac' => $codeMac,
                    'max_attempts' => VerificationPolicy::maxAttempts(),
                    'resend_count' => $deliveryKind === 'resend' ? 1 : 0,
                    'expires_at' => $preparedAt->format('Y-m-d H:i:s.u'),
                ], $preparedAt);
                if (!hash_equals($challengeId, $pendingChallengeId)) {
                    throw new RuntimeException('Persisted Recovery pending Challenge ID did not match delivery correlation ID.');
                }
                (new AuditLogRepository($this->pdo))->append(
                    'anonymous', null, 'password_recovery.delivery_prepared', 'user', (string) $userId,
                    ApiResponse::requestId(), null, null,
                    ['channel' => $channel, 'state' => 'prepared', 'deliveryKind' => $deliveryKind]
                );
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            }

            // Provider I/O deliberately happens outside an SQL transaction. The account mutex remains held.
            try {
                $result = $this->transport->deliver(new VerificationDelivery($challengeId, 'password_reset', $channel, $destination, $code));
            } catch (Throwable $exception) {
                error_log('MR NEON recovery transport exception: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
                $result = VerificationDeliveryResult::unknown('TRANSPORT_EXCEPTION');
            }
            if (!$result instanceof VerificationDeliveryResult) $result = VerificationDeliveryResult::unknown('INVALID_TRANSPORT_RESULT');

            if ($result->status !== VerificationDeliveryResult::ACCEPTED) {
                $this->resolveNonAcceptedDelivery($challengeId, $userId, $channel, $result);
                return self::genericStartResponse($reference);
            }

            $acceptedAt = $this->clock->now();
            try {
                $this->beginTransaction();
                if (!$this->users->lockActiveForPasswordReset($userId)
                    || !$this->users->lockVerifiedRecoveryChannel($userId, $channelId, $channel, $destinationNormalized)) {
                    throw new RuntimeException('Recovery target binding changed after provider acceptance.');
                }
                // A per-user connection mutex serializes Recovery actions; stale active rows are revoked before the new one is created.
                $this->challenges->revokeOtherPasswordResetChallenges($userId, $challengeId);
                if (!$this->challenges->activatePendingDelivery($challengeId, 'password_reset', $acceptedAt)) {
                    throw new RuntimeException('Accepted provider delivery could not activate the persisted Recovery Challenge.');
                }
                if (!$this->deliveries->resolvePasswordReset($challengeId, 'accepted', $result->safeCode, $acceptedAt)) {
                    throw new RuntimeException('Accepted Recovery delivery could not be resolved in the ledger.');
                }
                (new AuditLogRepository($this->pdo))->append(
                    'system', null, 'password_recovery.delivery_accepted', 'user', (string) $userId,
                    ApiResponse::requestId(), null, null,
                    ['channel' => $channel, 'state' => 'accepted', 'deliveryKind' => $deliveryKind]
                );
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $this->tryMarkAcceptedDeliveryUnknown($challengeId, $userId, $channel);
                error_log('MR NEON recovery accepted-delivery persistence failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
                return self::genericStartResponse($reference);
            }

            return self::genericStartResponse($reference);
        } finally {
            if ($heldUserLock && $userLock !== null) self::releaseLock($this->pdo, $userLock);
            if ($heldIdentityLock) self::releaseLock($this->pdo, $identityLock);
            // A small floor helps, but is not a claim of constant-time behavior while delivery is synchronous.
            $elapsedMicros = (int) ((hrtime(true) - $startedAt) / 1000);
            if ($elapsedMicros < self::START_FLOOR_MICROSECONDS) {
                usleep(self::START_FLOOR_MICROSECONDS - $elapsedMicros);
            }
        }
    }

    /** @return array{resetAuthorizationToken:string,expiresInSeconds:int,passwordResetAllowed:true} */
    public function verifyCode(array $input, string $clientIp): array
    {
        self::assertAllowedFields($input, ['identifier', 'method', 'challengeRef', 'code']);
        $method = self::requiredString($input, 'method', 16);
        $identifier = self::requiredString($input, 'identifier', 254);
        $normalized = self::normalizeIdentifier($method, $identifier);
        $reference = self::requiredString($input, 'challengeRef', 32);
        $code = self::requiredString($input, 'code', 6);
        if (!preg_match('/^[a-f0-9]{32}$/D', $reference)) throw self::invalidCodeProblem();
        RateLimitMiddleware::enforceRecoveryVerify($this->pdo, self::safeIp($clientIp), $reference);
        $this->otpVerifier->assertConfigured();
        if (!hash_equals($this->otpVerifier->recoveryReference($method, $normalized), $reference)) throw self::invalidCodeProblem();
        if (!OtpGenerator::isValidFormat($code)) throw self::invalidCodeProblem();
        $ttl = RecoveryPolicy::resetAuthorizationSeconds();

        $target = $this->users->findVerifiedRecoveryTarget($method, $normalized);
        if (!is_array($target)) throw self::invalidCodeProblem();
        $userId = (int) $target['user_id'];
        $channelId = (int) $target['user_channel_id'];
        $channel = (string) $target['channel_type'];
        $destinationNormalized = (string) $target['normalized_value'];
        $userLock = self::lockName('user:' . $userId, 'u');
        if (!self::acquireLock($this->pdo, $userLock, self::LOCK_WAIT_SECONDS)) {
            throw new ApiProblemException(409, 'RECOVERY_OPERATION_IN_PROGRESS', 'Conflict', 'درخواست بازیابی دیگری در حال پردازش است؛ دوباره تلاش کنید.');
        }

        $rawToken = null;
        try {
            $this->beginTransaction();
            try {
                if (!$this->users->lockActiveForPasswordReset($userId)
                    || !$this->users->lockVerifiedRecoveryChannel($userId, $channelId, $channel, $destinationNormalized)) {
                    throw self::invalidCodeProblem();
                }
                $active = $this->challenges->findCurrentActiveForPasswordReset($userId, $channelId, $channel, $destinationNormalized, true);
                if (!is_array($active)) throw self::invalidCodeProblem();
                $challenge = $this->challenges->findForPasswordResetVerificationForUpdate((string) $active['id']);
                if (!is_array($challenge)
                    || (int) ($challenge['user_id'] ?? 0) !== $userId
                    || (int) ($challenge['user_channel_id'] ?? 0) !== $channelId
                    || ($challenge['channel_type'] ?? null) !== $channel
                    || !hash_equals((string) ($challenge['destination_normalized'] ?? ''), $destinationNormalized)
                    || !ChallengeStateMachine::isPasswordResetConsumable($challenge, $this->clock->now())) {
                    throw self::invalidCodeProblem();
                }

                if (!$this->otpVerifier->matches((string) $challenge['code_hmac'], (string) $challenge['id'], 'password_reset', $channel, $destinationNormalized, $code)) {
                    $this->challenges->recordFailedPasswordResetAttempt((string) $challenge['id']);
                    (new AuditLogRepository($this->pdo))->append(
                        'anonymous', null, 'password_recovery.code_rejected', 'user', (string) $userId,
                        ApiResponse::requestId(), null, null, ['channel' => $channel, 'result' => 'invalid']
                    );
                    $this->pdo->commit();
                    throw self::invalidCodeProblem();
                }

                $rawToken = ResetTokenGenerator::generate();
                $tokenHash = ResetTokenGenerator::hash($rawToken);
                if (!$this->challenges->consumeForPasswordReset((string) $challenge['id'], $tokenHash, $ttl)) {
                    throw self::invalidCodeProblem();
                }
                $this->challenges->revokeOtherPasswordResetChallenges($userId, (string) $challenge['id']);
                (new AuditLogRepository($this->pdo))->append(
                    'system', null, 'password_recovery.reset_authorization_issued', 'user', (string) $userId,
                    ApiResponse::requestId(), null, null, ['channel' => $channel, 'result' => 'issued', 'ttlSeconds' => $ttl]
                );
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                // An expected invalid-code problem raised after counter commit must not trigger rollback.
                if ($exception instanceof ApiProblemException && $exception->errorCode === 'RECOVERY_CODE_INVALID_OR_EXPIRED') {
                    if ($rawToken !== null) $rawToken = null;
                    throw $exception;
                }
                throw $exception;
            }
        } finally {
            self::releaseLock($this->pdo, $userLock);
        }

        if (!is_string($rawToken)) throw new RuntimeException('Reset authorization was not issued.');
        return ['resetAuthorizationToken' => $rawToken, 'expiresInSeconds' => $ttl, 'passwordResetAllowed' => true];
    }

    /** @return array{_userId:int,passwordReset:true,authenticated:false,sessionEstablished:false,revokedSessions:int} */
    public function resetPassword(string $rawToken, array $input, string $clientIp): array
    {
        self::assertAllowedFields($input, ['newPassword']);
        if (!array_key_exists('newPassword', $input) || !is_string($input['newPassword'])) {
            throw new ApiProblemException(422, 'PASSWORD_POLICY_VIOLATION', 'Unprocessable content', 'رمز عبور جدید معتبر نیست.');
        }
        $tokenHashForLimit = hash('sha256', $rawToken);
        RateLimitMiddleware::enforceRecoveryReset($this->pdo, self::safeIp($clientIp), $tokenHashForLimit);
        if (!ResetTokenGenerator::isValid($rawToken)) throw self::invalidResetTokenProblem();
        $userId = $this->challenges->findRecoveryAuthorizationUserId($rawToken);
        if (!is_int($userId) || $userId < 1) throw self::invalidResetTokenProblem();

        PasswordPolicy::validate($input['newPassword']);
        $passwordHash = PasswordPolicy::hash($input['newPassword']);
        $userLock = self::lockName('user:' . $userId, 'u');
        if (!self::acquireLock($this->pdo, $userLock, self::LOCK_WAIT_SECONDS)) {
            throw new ApiProblemException(409, 'RECOVERY_OPERATION_IN_PROGRESS', 'Conflict', 'درخواست بازیابی دیگری در حال پردازش است؛ دوباره تلاش کنید.');
        }
        $revoked = 0;
        try {
            $this->beginTransaction();
            try {
                // Preserve a stable lock order: User row first, Challenge/token second.
                if (!$this->users->lockActiveForPasswordReset($userId)) throw self::invalidResetTokenProblem();
                $consumedUserId = $this->challenges->consumeRecoveryAuthorization($rawToken);
                if ($consumedUserId !== $userId) throw self::invalidResetTokenProblem();
                if (!$this->users->replacePasswordHash($userId, $passwordHash)) {
                    throw new RuntimeException('Password update was not committed for the active account.');
                }
                $revoked = (new AuthSessionRepository($this->pdo))->revokeAllForUser($userId, 'password_reset');
                $this->challenges->revokeForSubject('password_reset', 'user', (string) $userId);
                $this->challenges->resolvePreparedPasswordResetForUser($userId, 'RECOVERY_COMPLETED_DURING_DELIVERY', $this->clock->now());
                (new AuditLogRepository($this->pdo))->append(
                    'system', null, 'password_recovery.completed', 'user', (string) $userId,
                    ApiResponse::requestId(), null, null,
                    ['result' => 'password_changed', 'sessionRevocation' => 'complete']
                );
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            }
        } finally {
            self::releaseLock($this->pdo, $userLock);
        }
        return ['_userId' => $userId, 'passwordReset' => true, 'authenticated' => false, 'sessionEstablished' => false, 'revokedSessions' => $revoked];
    }

    public static function normalizeIdentifier(string $method, string $identifier): string
    {
        if (!in_array($method, ['email', 'telegram'], true)) {
            throw new ApiProblemException(422, 'RECOVERY_INPUT_INVALID', 'Unprocessable content', 'روش بازیابی معتبر نیست.');
        }
        $identifier = trim($identifier);
        if ($identifier === '' || strlen($identifier) > 254 || preg_match('//u', $identifier) !== 1) {
            throw new ApiProblemException(422, 'RECOVERY_INPUT_INVALID', 'Unprocessable content', 'شناسهٔ بازیابی معتبر نیست.');
        }
        if ($method === 'email') {
            $normalized = strtolower($identifier);
            if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || strlen($normalized) > 254 || preg_match('/[\r\n\x00]/', $normalized)) {
                throw new ApiProblemException(422, 'RECOVERY_INPUT_INVALID', 'Unprocessable content', 'شناسهٔ بازیابی معتبر نیست.');
            }
            return $normalized;
        }
        // This is the MR NEON account username, not a Telegram username or Chat ID.
        $candidate = str_starts_with($identifier, '@') ? substr($identifier, 1) : $identifier;
        $normalized = strtolower($candidate);
        if (preg_match('/^[a-z0-9_]{4,20}$/D', $normalized) !== 1) {
            throw new ApiProblemException(422, 'RECOVERY_INPUT_INVALID', 'Unprocessable content', 'شناسهٔ بازیابی معتبر نیست.');
        }
        return $normalized;
    }

    private function resolveNonAcceptedDelivery(string $challengeId, int $userId, string $channel, VerificationDeliveryResult $result): void
    {
        try {
            $this->beginTransaction();
            if (!$this->deliveries->resolvePasswordReset($challengeId, $result->status, $result->safeCode, $this->clock->now())) {
                throw new RuntimeException('Recovery delivery ledger could not resolve the prepared attempt.');
            }
            if (in_array($result->status, ['rejected', 'not_configured'], true)) {
                if (!$this->challenges->revokePendingDelivery($challengeId, 'password_reset')) {
                    throw new RuntimeException('Definitively rejected Recovery delivery had no pending Challenge to revoke.');
                }
            }
            (new AuditLogRepository($this->pdo))->append(
                'system', null, 'password_recovery.delivery_resolved', 'user', (string) $userId,
                ApiResponse::requestId(), null, null,
                ['channel' => $channel, 'state' => $result->status, 'result' => $result->safeCode]
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('MR NEON recovery delivery-resolution failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            // The prepared record remains and prevents blind retry.
        }
    }

    private function tryMarkAcceptedDeliveryUnknown(string $challengeId, int $userId, string $channel): void
    {
        try {
            $this->beginTransaction();
            $markedUnknown = $this->deliveries->resolvePasswordReset(
                $challengeId, 'unknown', 'ACCEPTED_BUT_PERSISTENCE_FAILED', $this->clock->now()
            );
            // The earlier COMMIT may have succeeded despite an ambiguous client result. Do not
            // create an UNKNOWN audit unless the accepted ledger row was actually transitioned.
            if ($markedUnknown) {
                (new AuditLogRepository($this->pdo))->append(
                    'system', null, 'password_recovery.delivery_resolution_uncertain', 'user', (string) $userId,
                    ApiResponse::requestId(), null, null, ['channel' => $channel, 'state' => 'unknown']
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('MR NEON Recovery reconciliation warning: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
        }
    }

    private function beginTransaction(): void
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Recovery service requires transaction ownership.');
        $this->pdo->beginTransaction();
    }

    private static function genericStartResponse(string $reference): array
    {
        return [
            'challengeRef' => $reference,
            'message' => RecoveryPolicy::GENERIC_START_MESSAGE,
            'ttlSeconds' => VerificationPolicy::OTP_TTL_SECONDS,
        ];
    }

    private static function assertAllowedFields(array $input, array $allowed): void
    {
        foreach ($input as $key => $_) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ApiProblemException(422, 'RECOVERY_INPUT_INVALID', 'Unprocessable content', 'ساختار درخواست بازیابی معتبر نیست.');
            }
        }
    }

    private static function requiredString(array $input, string $key, int $maxLength): string
    {
        if (!array_key_exists($key, $input) || !is_string($input[$key]) || $input[$key] === '' || strlen($input[$key]) > $maxLength) {
            throw new ApiProblemException(422, 'RECOVERY_INPUT_INVALID', 'Unprocessable content', 'ساختار درخواست بازیابی معتبر نیست.');
        }
        return $input[$key];
    }

    private static function safeIp(string $clientIp): string
    {
        return filter_var($clientIp, FILTER_VALIDATE_IP) !== false ? $clientIp : 'unknown';
    }

    private static function lockName(string $context, string $kind): string
    {
        return 'mrnp5_' . $kind . '_' . substr(hash('sha256', $context), 0, 52);
    }

    private static function acquireLock(PDO $pdo, string $name, int $waitSeconds): bool
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$name, $waitSeconds]);
        return (string) $statement->fetchColumn() === '1';
    }

    private static function releaseLock(PDO $pdo, string $name): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
        } catch (Throwable $exception) {
            error_log('MR NEON recovery lock release warning: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
        }
    }

    private static function parseUtc(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') return null;
        try { return new DateTimeImmutable($value, new DateTimeZone('UTC')); }
        catch (Throwable) { return null; }
    }

    private static function invalidCodeProblem(): ApiProblemException
    {
        return new ApiProblemException(422, 'RECOVERY_CODE_INVALID_OR_EXPIRED', 'Unprocessable content', 'کد بازیابی نامعتبر یا منقضی شده است؛ درخواست بازیابی را دوباره آغاز کنید.');
    }

    private static function invalidResetTokenProblem(): ApiProblemException
    {
        return new ApiProblemException(401, 'RESET_AUTHORIZATION_INVALID_OR_EXPIRED', 'Unauthorized', 'مجوز بازنشانی نامعتبر یا منقضی شده است؛ بازیابی را دوباره آغاز کنید.');
    }
}
