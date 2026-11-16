<?php
declare(strict_types=1);

/** Registration OTP lifecycle. Delivery is synchronous only through an explicit transport contract. */
final class VerificationService
{
    private readonly VerificationTransportInterface $transport;
    private readonly ClockInterface $clock;
    private readonly OtpVerifier $otpVerifier;

    public function __construct(
        private readonly PDO $pdo,
        ?VerificationTransportInterface $transport = null,
        ?ClockInterface $clock = null,
        ?OtpVerifier $otpVerifier = null
    ) {
        $this->transport = $transport ?? new UnavailableVerificationTransport();
        $this->clock = $clock ?? new SystemClock();
        $this->otpVerifier = $otpVerifier ?? new OtpVerifier();
    }

    /** Start a delivery or request a resend. A successful response never contains the OTP itself. */
    public function requestDelivery(array $input, string $clientIp, bool $resend): array
    {
        self::assertAllowedKeys($input, ['attemptId']);
        $attemptId = $input['attemptId'] ?? null;
        if (!is_string($attemptId) || !preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new ApiProblemException(422, 'VERIFICATION_REQUEST_INVALID', 'Unprocessable content', 'شناسهٔ درخواست تأیید معتبر نیست.');
        }

        $operation = $resend ? 'resend' : 'send';
        RateLimitMiddleware::enforceVerificationIp($this->pdo, $operation, $clientIp);
        RateLimitMiddleware::enforceVerificationAttempt($this->pdo, $operation, $attemptId);
        $this->otpVerifier->assertConfigured();

        $attemptRepository = new RegistrationAttemptRepository($this->pdo);
        $preliminary = $attemptRepository->findForCompletion($attemptId);
        if (!is_array($preliminary)) {
            throw self::invalidAttempt();
        }
        $target = self::targetForAttempt($preliminary);
        if ($target === null) {
            throw new ApiProblemException(409, 'TELEGRAM_DESTINATION_NOT_LINKED', 'Conflict', 'ابتدا باید اتصال امن کانال انتخاب‌شده تکمیل شود.');
        }
        RateLimitMiddleware::enforceVerificationDestination($this->pdo, $target['channel'], $target['normalized']);
        $destinationHmac = $this->otpVerifier->destinationFingerprint('registration', $target['channel'], $target['normalized']);

        $attemptLock = self::attemptLockName($attemptId);
        $heldLocks = RegistrationIdentityLockManager::acquire($this->pdo, [$attemptLock]);
        $challengeId = bin2hex(random_bytes(16));
        $code = OtpGenerator::generate();
        $codeMac = $this->otpVerifier->mac($challengeId, 'registration', $target['channel'], $target['normalized'], $code);
        $deliveryKind = 'initial';
        $previousAcceptedCount = 0;
        $preparedPersisted = false;

        try {
            $this->beginTransaction();
            try {
                $attempt = $attemptRepository->findForCompletionForUpdate($attemptId);
                if (!is_array($attempt)) {
                    throw self::invalidAttempt();
                }
                $lockedTarget = self::targetForAttempt($attempt);
                if ($lockedTarget === null || $lockedTarget['channel'] !== $target['channel'] || !hash_equals($lockedTarget['normalized'], $target['normalized'])) {
                    throw self::invalidAttempt();
                }

                $deliveryAttempts = new VerificationDeliveryAttemptRepository($this->pdo);
                $challenges = new VerificationChallengeRepository($this->pdo);
                if ($deliveryAttempts->hasPreparedForRegistration($attemptId)) {
                    throw new ApiProblemException(503, 'VERIFICATION_DELIVERY_RECONCILIATION_REQUIRED', 'Service unavailable', 'نتیجهٔ ارسال قبلی هنوز مشخص نیست؛ ارسال خودکار تکرار نشد.');
                }
                $allDeliveryCount = $deliveryAttempts->countForRegistration($attemptId);
                if ($allDeliveryCount >= VerificationPolicy::maxDeliveryAttempts()) {
                    throw self::tooManyDeliveries();
                }
                $active = $challenges->findCurrentActiveForRegistrationAttempt($attemptId, true);
                $latest = $deliveryAttempts->latestForRegistration($attemptId);
                $latestAccepted = $deliveryAttempts->latestAcceptedForRegistration($attemptId);
                $now = $this->clock->now();

                // An UNKNOWN outcome may already have reached the user's inbox/chat. Do not let
                // the initial-send endpoint become an alternate blind-resend path.
                if (is_array($latest) && ($latest['state'] ?? null) === 'unknown') {
                    throw new ApiProblemException(503, 'VERIFICATION_DELIVERY_RECONCILIATION_REQUIRED', 'Service unavailable', 'نتیجهٔ ارسال قبلی نامشخص است؛ تا تعیین تکلیف آن ارسال دیگری انجام نمی‌شود.');
                }
                if (is_array($latestAccepted) && !hash_equals((string) $latestAccepted['destination_hmac'], $destinationHmac)) {
                    throw new ApiProblemException(409, 'VERIFICATION_DESTINATION_CHANGED', 'Conflict', 'مقصد تأیید پس از پذیرش ارسال تغییر کرده است؛ Challenge قبلی برای مقصد جدید قابل‌استفاده نیست.');
                }
                if (is_array($active) && (($active['channel_type'] ?? null) !== $target['channel']
                    || !hash_equals((string) ($active['destination_normalized'] ?? ''), $target['normalized']))) {
                    throw self::invalidAttempt();
                }
                if (is_array($active) && !$resend) {
                    $this->pdo->commit();
                    return self::activeChallengeResponse($active, $target['channel'], true);
                }
                if ($resend && !is_array($latestAccepted)) {
                    throw new ApiProblemException(409, 'VERIFICATION_NOT_RESENDABLE', 'Conflict', 'برای این درخواست هنوز ارسال تأییدشده‌ای وجود ندارد.');
                }
                if (is_array($latest)) {
                    $latestAttemptAt = self::parseUtc($latest['resolved_at'] ?? null) ?? self::parseUtc($latest['created_at'] ?? null);
                    $retryAfter = VerificationPolicy::resendRetryAfter($latestAttemptAt, $now);
                    if ($retryAfter > 0) {
                        throw new ApiProblemException(429, 'VERIFICATION_RESEND_COOLDOWN', 'Too many requests', 'برای درخواست کد جدید باید کمی صبر کنید.', ['Retry-After' => (string) $retryAfter]);
                    }
                }
                $previousAcceptedCount = $deliveryAttempts->countAcceptedForRegistration($attemptId);
                $deliveryKind = $previousAcceptedCount > 0 ? 'resend' : 'initial';
                $preparedAt = $this->clock->now();
                $deliveryAttempts->createPreparedRegistration($challengeId, $attemptId, $target['channel'], $destinationHmac, $deliveryKind);
                $pendingChallengeId = $challenges->createPendingDelivery([
                    'id' => $challengeId,
                    'purpose' => 'registration',
                    'subject_type' => 'registration_attempt',
                    'registration_attempt_id' => $attemptId,
                    'channel_type' => $target['channel'],
                    'destination_value' => $target['value'],
                    'destination_normalized' => $target['normalized'],
                    'code_hmac' => $codeMac,
                    'max_attempts' => VerificationPolicy::maxAttempts(),
                    'resend_count' => $previousAcceptedCount,
                    'expires_at' => $preparedAt->format('Y-m-d H:i:s.u'),
                ], $preparedAt);
                if (!hash_equals($challengeId, $pendingChallengeId)) {
                    throw new RuntimeException('Persisted pending Challenge ID did not match delivery correlation ID.');
                }
                (new AuditLogRepository($this->pdo))->append(
                    'anonymous', null, 'verification.delivery_prepared', 'registration_attempt', $attemptId,
                    ApiResponse::requestId(), null, null,
                    ['channel' => $target['channel'], 'deliveryKind' => $deliveryKind, 'state' => 'prepared']
                );
                $this->pdo->commit();
                $preparedPersisted = true;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            }

            // No DB transaction remains open while the provider call executes. Any thrown result is UNKNOWN.
            try {
                $result = $this->transport->deliver(new VerificationDelivery(
                    $challengeId,
                    'registration',
                    $target['channel'],
                    $target['value'],
                    $code
                ));
            } catch (Throwable $transportException) {
                error_log('MR NEON verification transport exception: requestId=' . ApiResponse::requestId() . ' type=' . get_class($transportException));
                $result = VerificationDeliveryResult::unknown('TRANSPORT_EXCEPTION');
            }
            if (!$result instanceof VerificationDeliveryResult) {
                // Runtime contract violations are not accepted as successful sends.
                $result = VerificationDeliveryResult::unknown('INVALID_TRANSPORT_RESULT');
            }

            if ($result->status !== VerificationDeliveryResult::ACCEPTED) {
                $this->recordNonAcceptedDelivery($challengeId, $attemptId, $target['channel'], $result);
                throw self::deliveryProblem($result);
            }

            $acceptedAt = $this->clock->now();
            $expiresAt = VerificationPolicy::expiresAt($acceptedAt);
            try {
                $this->beginTransaction();
                $lockedAttempt = $attemptRepository->findForCompletionForUpdate($attemptId);
                if (!is_array($lockedAttempt)) {
                    throw self::invalidAttempt();
                }
                $lockedTarget = self::targetForAttempt($lockedAttempt);
                if ($lockedTarget === null || $lockedTarget['channel'] !== $target['channel'] || !hash_equals($lockedTarget['normalized'], $target['normalized'])) {
                    throw self::invalidAttempt();
                }

                $challenges = new VerificationChallengeRepository($this->pdo);
                $deliveries = new VerificationDeliveryAttemptRepository($this->pdo);
                // Old codes are invalidated only after the replacement provider accepted delivery.
                $challenges->supersedeActiveForRegistrationAttempt($attemptId);
                if (!$challenges->activatePendingDelivery($challengeId, 'registration', $acceptedAt)) {
                    throw new RuntimeException('Accepted provider delivery could not activate the persisted pending Challenge.');
                }
                if (!$deliveries->resolveRegistration($challengeId, 'accepted', $result->safeCode, $acceptedAt)) {
                    throw new RuntimeException('Accepted transport result could not be committed to the delivery ledger.');
                }
                (new AuditLogRepository($this->pdo))->append(
                    'system', null, 'verification.delivery_accepted', 'registration_attempt', $attemptId,
                    ApiResponse::requestId(), null, null,
                    ['channel' => $target['channel'], 'challengeId' => $challengeId, 'result' => 'accepted']
                );
                $this->pdo->commit();
            } catch (Throwable $persistenceException) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                // Do not retry a code already accepted by a provider. Persist UNKNOWN if possible;
                // otherwise PREPARED remains as a fail-closed reconciliation lock.
                $this->tryMarkUnknownAfterAcceptedPersistenceFailure($challengeId, $attemptId, $target['channel']);
                error_log('MR NEON accepted OTP persistence failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($persistenceException));
                throw new ApiProblemException(503, 'VERIFICATION_DELIVERY_RECONCILIATION_REQUIRED', 'Service unavailable', 'ارسال پذیرفته شد اما ثبت امن وضعیت کامل نشد؛ درخواست ارسال خودکار تکرار نمی‌شود.');
            }

            return [
                'attemptId' => $attemptId,
                'challengeId' => $challengeId,
                'state' => 'active',
                'channel' => $target['channel'],
                'expiresAt' => $expiresAt->format('Y-m-d\TH:i:s.u\Z'),
                'ttlSeconds' => VerificationPolicy::OTP_TTL_SECONDS,
                'resendAvailableAt' => $acceptedAt->modify('+' . VerificationPolicy::resendCooldownSeconds() . ' seconds')->format('Y-m-d\TH:i:s\Z'),
                'reusedActiveChallenge' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($preparedPersisted && $exception instanceof PDOException) {
                // The prepared record blocks future sends if its final state could not be saved.
                error_log('MR NEON verification DB failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            }
            throw $exception;
        } finally {
            RegistrationIdentityLockManager::release($this->pdo, $heldLocks);
        }
    }

    /** Verify and atomically consume a registration challenge with account creation. */
    public function verifyRegistration(array $input, string $clientIp): array
    {
        self::assertAllowedKeys($input, ['attemptId', 'challengeId', 'code']);
        $attemptId = $input['attemptId'] ?? null;
        $challengeId = $input['challengeId'] ?? null;
        $code = $input['code'] ?? null;
        if (!is_string($attemptId) || !preg_match('/^[a-f0-9]{32}$/D', $attemptId)
            || !is_string($challengeId) || !preg_match('/^[a-f0-9]{32}$/D', $challengeId)
            || !is_string($code) || !OtpGenerator::isValidFormat($code)) {
            throw new ApiProblemException(422, 'VERIFICATION_REQUEST_INVALID', 'Unprocessable content', 'شناسه و کد تأیید باید با قالب معتبر ارسال شوند.');
        }

        RateLimitMiddleware::enforceVerificationIp($this->pdo, 'verify', $clientIp);
        RateLimitMiddleware::enforceVerificationAttempt($this->pdo, 'verify', $attemptId);
        $this->otpVerifier->assertConfigured();
        $attemptRepository = new RegistrationAttemptRepository($this->pdo);
        $preliminary = $attemptRepository->findForCompletion($attemptId);
        if (!is_array($preliminary)) {
            throw self::invalidAttempt();
        }
        $target = self::targetForAttempt($preliminary);
        if ($target === null) {
            throw self::invalidAttempt();
        }
        RateLimitMiddleware::enforceVerificationDestination($this->pdo, $target['channel'], $target['normalized']);

        $lockNames = RegistrationIdentityLockManager::namesFromAttempt($preliminary);
        // Same identity-lock naming as W4-P2 prevents account completion racing a new duplicate Attempt.
        $lockNames[] = self::attemptLockName($attemptId);
        $heldLocks = RegistrationIdentityLockManager::acquire($this->pdo, $lockNames);
        $failureAfterCommit = null;

        try {
            $this->beginTransaction();
            try {
                $attempt = $attemptRepository->findForCompletionForUpdate($attemptId);
                $challenges = new VerificationChallengeRepository($this->pdo);
                $challenge = $challenges->findForRegistrationVerificationForUpdate($challengeId, $attemptId);
                if (!is_array($attempt) || !is_array($challenge)) {
                    throw self::invalidAttempt();
                }
                $currentTarget = self::targetForAttempt($attempt);
                if ($currentTarget === null
                    || $currentTarget['channel'] !== $target['channel']
                    || !hash_equals($currentTarget['normalized'], $target['normalized'])
                    || $challenge['channel_type'] !== $currentTarget['channel']
                    || !hash_equals((string) $challenge['destination_normalized'], $currentTarget['normalized'])) {
                    throw self::invalidAttempt();
                }

                $now = $this->clock->now();
                if (($challenge['state'] ?? null) === 'exhausted' || (int) ($challenge['attempts'] ?? 0) >= (int) ($challenge['max_attempts'] ?? 0)) {
                    throw new ApiProblemException(429, 'VERIFICATION_TOO_MANY_ATTEMPTS', 'Too many requests', 'برای این کد بیش از حد مجاز تلاش شده است.');
                }
                if (!ChallengeStateMachine::isConsumable($challenge, $now)) {
                    $expiry = self::parseUtc($challenge['expires_at'] ?? null);
                    if ($expiry instanceof DateTimeImmutable && $now >= $expiry) {
                        throw new ApiProblemException(422, 'VERIFICATION_CODE_EXPIRED', 'Unprocessable content', 'کد تأیید منقضی شده است؛ کد جدید درخواست کنید.');
                    }
                    throw new ApiProblemException(422, 'VERIFICATION_CODE_INVALID_OR_EXPIRED', 'Unprocessable content', 'کد تأیید معتبر یا قابل‌استفاده نیست.');
                }

                $storedMac = (string) ($challenge['code_hmac'] ?? '');
                $macValid = $this->otpVerifier->matches(
                    $storedMac,
                    $challengeId,
                    'registration',
                    $currentTarget['channel'],
                    $currentTarget['normalized'],
                    $code
                );
                if (!$macValid) {
                    if (!$challenges->recordFailedAttempt($challengeId)) {
                        throw new ApiProblemException(422, 'VERIFICATION_CODE_INVALID_OR_EXPIRED', 'Unprocessable content', 'کد تأیید معتبر یا قابل‌استفاده نیست.');
                    }
                    (new AuditLogRepository($this->pdo))->append(
                        'anonymous', null, 'verification.code_rejected', 'registration_attempt', $attemptId,
                        ApiResponse::requestId(), null, null,
                        ['channel' => $currentTarget['channel'], 'result' => 'invalid_code']
                    );
                    $this->pdo->commit();
                    $failureAfterCommit = new ApiProblemException(422, 'INVALID_VERIFICATION_CODE', 'Unprocessable content', 'کد تأیید معتبر نیست.');
                } else {
                    $completion = (new RegistrationCompletionService($this->pdo))->completeWithinTransaction($attempt, $challenge);
                    $this->pdo->commit();
                    return $completion;
                }
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                    throw new ApiProblemException(409, 'REGISTRATION_CONFLICT', 'Conflict', 'درخواست ثبت‌نام با اطلاعات واردشده قابل تکمیل نیست.');
                }
                throw $exception;
            }
        } finally {
            RegistrationIdentityLockManager::release($this->pdo, $heldLocks);
        }

        if ($failureAfterCommit instanceof ApiProblemException) {
            throw $failureAfterCommit;
        }
        throw new RuntimeException('Verification exited without an explicit result.');
    }

    /** @return array{channel:string,value:string,normalized:string}|null */
    private static function targetForAttempt(array $attempt): ?array
    {
        $channel = $attempt['selected_channel'] ?? null;
        if ($channel === 'email') {
            $value = $attempt['email_value'] ?? null;
            $normalized = $attempt['email_normalized'] ?? null;
            if (!is_string($value) || !is_string($normalized) || $value === '' || $normalized === ''
                || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
                || !hash_equals(strtolower(trim($value)), strtolower($normalized))) {
                return null;
            }
            return ['channel' => 'email', 'value' => $value, 'normalized' => strtolower($normalized)];
        }
        if ($channel === 'telegram') {
            $chatId = $attempt['linked_telegram_chat_id'] ?? null;
            $userId = $attempt['linked_telegram_user_id'] ?? null;
            if (!is_string($chatId) || !preg_match('/^[0-9]{1,128}$/D', $chatId)
                || !is_string($userId) || !preg_match('/^[0-9]{1,128}$/D', $userId)) {
                return null;
            }
            return ['channel' => 'telegram', 'value' => $chatId, 'normalized' => $chatId];
        }
        return null;
    }

    private function recordNonAcceptedDelivery(string $challengeId, string $attemptId, string $channel, VerificationDeliveryResult $result): void
    {
        $state = match ($result->status) {
            VerificationDeliveryResult::REJECTED => 'rejected',
            VerificationDeliveryResult::UNKNOWN => 'unknown',
            VerificationDeliveryResult::NOT_CONFIGURED => 'not_configured',
            default => 'unknown',
        };
        try {
            $this->beginTransaction();
            $repo = new VerificationDeliveryAttemptRepository($this->pdo);
            if (!$repo->resolveRegistration($challengeId, $state, $result->safeCode, $this->clock->now())) {
                throw new RuntimeException('Delivery outcome could not be persisted.');
            }
            if (in_array($state, ['rejected', 'not_configured'], true)) {
                if (!(new VerificationChallengeRepository($this->pdo))->revokePendingDelivery($challengeId, 'registration')) {
                    throw new RuntimeException('Definitively rejected Registration delivery had no pending Challenge to revoke.');
                }
            }
            (new AuditLogRepository($this->pdo))->append(
                'system', null, 'verification.delivery_not_accepted', 'registration_attempt', $attemptId,
                ApiResponse::requestId(), null, null,
                ['channel' => $channel, 'outcome' => $state, 'resultCode' => $result->safeCode]
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('MR NEON verification delivery outcome persistence failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            throw new ApiProblemException(503, 'VERIFICATION_DELIVERY_RECONCILIATION_REQUIRED', 'Service unavailable', 'نتیجهٔ ارسال قابل ثبت نیست؛ ارسال خودکار تکرار نمی‌شود.');
        }
    }

    private function tryMarkUnknownAfterAcceptedPersistenceFailure(string $challengeId, string $attemptId, string $channel): void
    {
        try {
            if (!$this->pdo->inTransaction()) $this->pdo->beginTransaction();
            $repo = new VerificationDeliveryAttemptRepository($this->pdo);
            $markedUnknown = $repo->resolveRegistration(
                $challengeId, 'unknown', 'ACCEPTED_BUT_PERSISTENCE_FAILED', $this->clock->now()
            );
            // Commit ambiguity can mean the accepted transaction actually succeeded. Never
            // append an UNKNOWN audit unless the ledger transition itself succeeded.
            if ($markedUnknown) {
                (new AuditLogRepository($this->pdo))->append(
                    'system', null, 'verification.delivery_reconciliation_required', 'registration_attempt', $attemptId,
                    ApiResponse::requestId(), null, null,
                    ['channel' => $channel, 'result' => 'unknown', 'reason' => 'accepted_persistence_failed']
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('MR NEON verification reconciliation audit unavailable: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
        }
    }

    private function beginTransaction(): void
    {
        if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
            throw new RuntimeException('Verification transaction could not be started in a clean connection state.');
        }
    }

    private static function activeChallengeResponse(array $active, string $channel, bool $reused): array
    {
        $expiresAt = (string) ($active['expires_at'] ?? '');
        $parsed = self::parseUtc($expiresAt);
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Active challenge expiry is malformed.');
        }
        return [
            'attemptId' => (string) $active['registration_attempt_id'],
            'challengeId' => (string) $active['id'],
            'state' => 'active',
            'channel' => $channel,
            'expiresAt' => $parsed->format('Y-m-d\TH:i:s.u\Z'),
            'ttlSeconds' => VerificationPolicy::OTP_TTL_SECONDS,
            'reusedActiveChallenge' => $reused,
        ];
    }

    private static function attemptLockName(string $attemptId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new InvalidArgumentException('Registration attempt ID format is invalid.');
        }
        return 'mrnv_' . substr(hash('sha256', 'registration-otp:' . $attemptId), 0, 56);
    }

    private static function assertAllowedKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ApiProblemException(422, 'UNEXPECTED_FIELD', 'Unprocessable content', 'درخواست شامل فیلدی است که پذیرفته نمی‌شود.');
            }
        }
    }

    private static function invalidAttempt(): ApiProblemException
    {
        return new ApiProblemException(409, 'VERIFICATION_ATTEMPT_INVALID', 'Conflict', 'درخواست تأیید معتبر یا قابل تکمیل نیست.');
    }

    private static function tooManyDeliveries(): ApiProblemException
    {
        return new ApiProblemException(429, 'VERIFICATION_RESEND_LIMIT', 'Too many requests', 'سقف درخواست کد برای این ثبت‌نام به پایان رسیده است.');
    }

    private static function deliveryProblem(VerificationDeliveryResult $result): ApiProblemException
    {
        if ($result->status === VerificationDeliveryResult::NOT_CONFIGURED) {
            return new ApiProblemException(503, 'VERIFICATION_DELIVERY_NOT_CONFIGURED', 'Service unavailable', 'ارسال کد برای کانال انتخاب‌شده هنوز پیکربندی نشده است.');
        }
        if ($result->status === VerificationDeliveryResult::REJECTED && $result->retryAfterSeconds !== null) {
            return new ApiProblemException(
                503,
                'VERIFICATION_UPSTREAM_RATE_LIMITED',
                'Service unavailable',
                'سرویس ارسال موقتاً محدود شده است؛ پس از زمان اعلام‌شده دوباره تلاش کنید.',
                ['Retry-After' => (string) $result->retryAfterSeconds]
            );
        }
        if ($result->status === VerificationDeliveryResult::REJECTED) {
            return new ApiProblemException(502, 'VERIFICATION_DELIVERY_REJECTED', 'Bad gateway', 'سرویس ارسال کد درخواست را نپذیرفت؛ کد فعال نشد.');
        }
        return new ApiProblemException(503, 'VERIFICATION_DELIVERY_UNKNOWN', 'Service unavailable', 'نتیجهٔ ارسال کد مشخص نیست؛ ارسال خودکار تکرار نشده است.');
    }

    private static function parseUtc(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
