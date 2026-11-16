<?php
declare(strict_types=1);

/** Creates only a temporary registration attempt. OTP/challenge and external delivery are out of scope. */
final class RegistrationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function start(array $input, string $clientIp): array
    {
        // The IP bucket is consumed before full validation/hash work to bound abusive request volume.
        RateLimitMiddleware::enforce($this->pdo, 'register', $clientIp, null);
        $dto = RegistrationDTO::fromArray($input);
        $termsVersion = RegistrationPolicy::termsVersion();
        $ttl = RegistrationPolicy::attemptTtlSeconds();
        RateLimitMiddleware::enforceRegistrationIdentities(
            $this->pdo,
            $dto->usernameNormalized,
            $dto->phone,
            $dto->emailNormalized,
            $dto->selectedChannel
        );

        $passwordHash = PasswordPolicy::hash($dto->passwordForHashing());
        $telegramNonce = $dto->selectedChannel === 'telegram' ? bin2hex(random_bytes(32)) : null;
        $telegramLinked = false;
        $nonceHash = $telegramNonce === null ? null : hash('sha256', $telegramNonce);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $ttl . ' seconds');
        $expiresAtSql = $expiresAt->format('Y-m-d H:i:s');
        $locks = $this->lockNames($dto);
        $acquired = [];

        try {
            foreach ($locks as $lockName) {
                $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 3)');
                $statement->execute([$lockName]);
                $result = $statement->fetchColumn();
                if ((string) $result !== '1') {
                    throw new ApiProblemException(409, 'REGISTRATION_REQUEST_IN_PROGRESS', 'Conflict', 'درخواست ثبت‌نام مشابهی در حال پردازش است؛ کمی بعد دوباره تلاش کنید.');
                }
                $acquired[] = $lockName;
            }

            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Registration transaction could not be started.');
            }
            try {
                $users = new UserRepository($this->pdo);
                $channels = new UserChannelRepository($this->pdo);
                $attempts = new RegistrationAttemptRepository($this->pdo);
                $audit = new AuditLogRepository($this->pdo);

                if ($users->hasRegistrationIdentity($dto->usernameNormalized, $dto->phone)) {
                    throw self::conflict();
                }
                if ($dto->emailNormalized !== null && $channels->existsByNormalizedValue('email', $dto->emailNormalized)) {
                    throw self::conflict();
                }

                $existing = $attempts->findOpenConflictByIdentity(
                    $dto->usernameNormalized,
                    $dto->phone,
                    $dto->emailNormalized
                );
                $reused = false;
                if ($existing !== null) {
                    if (!$this->isSameRequest($dto, $existing, $termsVersion)) {
                        throw self::conflict();
                    }
                    if ($dto->selectedChannel === 'telegram') {
                        $telegramLinked = is_string($existing['linked_telegram_user_id'] ?? null)
                            && $existing['linked_telegram_user_id'] !== ''
                            && is_string($existing['linked_telegram_chat_id'] ?? null)
                            && $existing['linked_telegram_chat_id'] !== ''
                            && is_string($existing['telegram_linked_at'] ?? null)
                            && $existing['telegram_linked_at'] !== '';
                        if ($telegramLinked) {
                            // A proven Telegram destination is immutable for this Attempt. Do not issue a new link token.
                            $telegramNonce = null;
                            $nonceHash = null;
                        } elseif ($telegramNonce === null || $nonceHash === null || !$attempts->replaceTelegramNonceHash((string) $existing['id'], $nonceHash)) {
                            throw new RuntimeException('Telegram registration nonce could not be rotated for an idempotent retry.');
                        }
                    }
                    $attemptId = (string) $existing['id'];
                    $storedExpiry = (string) $existing['expires_at'];
                    $expiryTimestamp = strtotime($storedExpiry . ' UTC');
                    if ($expiryTimestamp !== false) {
                        $expiresAt = (new DateTimeImmutable('@' . $expiryTimestamp))->setTimezone(new DateTimeZone('UTC'));
                    }
                    $reused = true;
                } else {
                    $attemptId = $attempts->create([
                        'full_name' => $dto->fullName,
                        'username' => $dto->username,
                        'phone' => $dto->phone,
                        'email_value' => $dto->email,
                        'password_hash' => $passwordHash,
                        'selected_channel' => $dto->selectedChannel,
                        'telegram_link_nonce_hash' => $nonceHash,
                        'terms_version' => $termsVersion,
                        'terms_accepted_at' => gmdate('Y-m-d H:i:s'),
                        'expires_at' => $expiresAtSql,
                    ]);
                }

                // Audit contains only safe state metadata; no password, email, phone, nonce or raw IP.
                $audit->append(
                    'anonymous',
                    null,
                    $reused ? 'registration_attempt.reused' : 'registration_attempt.created',
                    'registration_attempt',
                    $attemptId,
                    ApiResponse::requestId(),
                    null,
                    null,
                    ['selectedChannel' => $dto->selectedChannel, 'result' => $reused ? 'reused' : 'created']
                );
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                    throw self::conflict();
                }
                throw $exception;
            }
        } finally {
            // MySQL releases named locks when the connection closes too; explicitly release every acquired lock.
            foreach (array_reverse($acquired) as $lockName) {
                try {
                    $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$lockName]);
                } catch (Throwable $releaseError) {
                    error_log('MR NEON registration lock release warning: requestId=' . ApiResponse::requestId() . ' type=' . get_class($releaseError));
                }
            }
        }

        $result = [
            'attemptId' => $attemptId,
            'state' => 'pending_verification',
            'selectedChannel' => $dto->selectedChannel,
            'verificationStatus' => 'not_started',
            'telegramLinked' => $dto->selectedChannel === 'telegram' && $telegramLinked,
            'expiresAt' => $expiresAt->format('Y-m-d\\TH:i:s\\Z'),
            'reusedExistingAttempt' => $reused,
        ];
        // This is a high-entropy Telegram linking nonce, not an OTP. Only its SHA-256 hash is persisted.
        if ($telegramNonce !== null) {
            $result['telegramLinkNonce'] = $telegramNonce;
        }
        return $result;
    }

    /** @param array<string,mixed> $existing */
    private function isSameRequest(RegistrationDTO $dto, array $existing, string $termsVersion): bool
    {
        if (($existing['full_name'] ?? null) !== $dto->fullName
            || ($existing['username_normalized'] ?? null) !== $dto->usernameNormalized
            || ($existing['phone_normalized'] ?? null) !== $dto->phone
            || ($existing['email_normalized'] ?? null) !== $dto->emailNormalized
            || ($existing['selected_channel'] ?? null) !== $dto->selectedChannel
            || ($existing['terms_version'] ?? null) !== $termsVersion
            || !is_string($existing['password_hash'] ?? null)) {
            return false;
        }
        return password_verify($dto->passwordForHashing(), $existing['password_hash']);
    }

    /** @return list<string> */
    private function lockNames(RegistrationDTO $dto): array
    {
        $identities = [
            'username:' . $dto->usernameNormalized,
            'phone:' . $dto->phone,
        ];
        if ($dto->emailNormalized !== null) {
            $identities[] = 'email:' . $dto->emailNormalized;
        }
        sort($identities, SORT_STRING);
        $locks = [];
        foreach ($identities as $identity) {
            // MySQL named lock names are limited to 64 characters; only a digest is used in the lock name.
            $locks[] = 'mrnreg_' . substr(hash('sha256', $identity), 0, 56);
        }
        return $locks;
    }

    private static function conflict(): ApiProblemException
    {
        return new ApiProblemException(409, 'REGISTRATION_CONFLICT', 'Conflict', 'درخواست ثبت‌نام با اطلاعات واردشده قابل پردازش نیست.');
    }
}
