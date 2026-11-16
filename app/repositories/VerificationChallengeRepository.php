<?php
declare(strict_types=1);

final class VerificationChallengeRepository extends Repository
{
    /** Persist an OTP challenge before provider I/O; pending_delivery is never consumable. */
    public function createPendingDelivery(array $challenge, DateTimeImmutable $preparedAt): string
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Pending Challenge and prepared delivery ledger must share a caller-owned transaction.');
        }
        return $this->insertChallenge($challenge, 'pending_delivery', $preparedAt);
    }

    private function insertChallenge(array $challenge, string $state, ?DateTimeImmutable $preparedAt): string
    {
        if ($state !== 'pending_delivery') {
            throw new InvalidArgumentException('Challenge insertion state is invalid.');
        }
        $purpose = $this->allowedValue((string) ($challenge['purpose'] ?? ''), ['registration', 'password_reset'], 'challenge purpose');
        $subjectType = $this->allowedValue((string) ($challenge['subject_type'] ?? ''), ['registration_attempt', 'user'], 'challenge subject');
        $channel = $this->allowedValue((string) ($challenge['channel_type'] ?? ''), ['email', 'telegram'], 'challenge channel');
        $attemptId = isset($challenge['registration_attempt_id']) ? (string) $challenge['registration_attempt_id'] : null;
        $userId = isset($challenge['user_id']) ? (int) $challenge['user_id'] : null;
        if ($subjectType === 'registration_attempt' && (!is_string($attemptId) || !preg_match('/^[a-f0-9]{32}$/D', $attemptId) || $userId !== null || $purpose !== 'registration')) {
            throw new InvalidArgumentException('Registration challenge subject binding is invalid.');
        }
        if ($subjectType === 'user' && ($userId === null || $userId < 1 || $attemptId !== null || $purpose !== 'password_reset')) {
            throw new InvalidArgumentException('User challenge subject binding is invalid.');
        }
        $channelId = isset($challenge['user_channel_id']) ? (int) $challenge['user_channel_id'] : null;
        if ($channelId !== null && $channelId < 1) {
            throw new InvalidArgumentException('User channel ID is invalid.');
        }
        if ($purpose === 'password_reset') {
            if ($userId === null || $channelId === null) {
                throw new InvalidArgumentException('Password-reset challenges require a verified channel bound to the same user.');
            }
            $channelCheck = $this->pdo->prepare('SELECT 1 FROM user_channels WHERE id = ? AND user_id = ? AND channel_type = ? AND is_verified = 1 LIMIT 1');
            $channelCheck->execute([$channelId, $userId, $channel]);
            if ($channelCheck->fetchColumn() === false) {
                throw new InvalidArgumentException('Password-reset channel is not verified or does not belong to the user.');
            }
        } elseif ($channelId !== null || $userId !== null) {
            throw new InvalidArgumentException('Registration challenges must remain bound to the registration attempt, not an existing user.');
        }
        $destination = $this->nonEmptyString((string) ($challenge['destination_value'] ?? ''), 255, 'challenge destination');
        $normalized = strtolower(trim((string) ($challenge['destination_normalized'] ?? $destination)));
        $hmac = (string) ($challenge['code_hmac'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $hmac)) {
            throw new InvalidArgumentException('Challenge HMAC must be a SHA-256 hex digest.');
        }
        $maxAttempts = (int) ($challenge['max_attempts'] ?? 5);
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new InvalidArgumentException('Challenge attempt ceiling is invalid.');
        }
        $expiresAt = (string) ($challenge['expires_at'] ?? '');
        if ($expiresAt === '') {
            throw new InvalidArgumentException('Challenge expiry is required.');
        }
        $requestedId = isset($challenge['id']) ? (string) $challenge['id'] : '';
        if ($requestedId !== '' && !preg_match('/^[a-f0-9]{32}$/D', $requestedId)) {
            throw new InvalidArgumentException('Challenge ID format is invalid.');
        }
        $id = $requestedId !== '' ? $requestedId : bin2hex(random_bytes(16));
        if ($state === 'pending_delivery') {
            if (!$preparedAt instanceof DateTimeImmutable || !ChallengeStateMachine::canPrepareDelivery($purpose)) {
                throw new InvalidArgumentException('Pending challenge delivery preparation is invalid.');
            }
            // Pending challenges are persisted before provider I/O but are deliberately expired
            // until the accepted-provider transaction activates them with a fresh 120-second TTL.
            $lastSentAt = null;
            $expiresAt = $preparedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }
        $statement = $this->pdo->prepare(
            "INSERT INTO verification_challenges (id, purpose, subject_type, registration_attempt_id, user_id, user_channel_id, channel_type, destination_value, destination_normalized, code_hmac, state, attempts, max_attempts, resend_count, last_sent_at, expires_at, used_at, created_at) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(6))"
        );
        $statement->execute([
            $id, $purpose, $subjectType, $attemptId, $userId, $channelId, $channel,
            $destination, $normalized, $hmac, $state, $maxAttempts, (int) ($challenge['resend_count'] ?? 0),
            $lastSentAt, $expiresAt,
        ]);
        return $id;
    }

    /** Activate only the exact persisted pending challenge after the provider accepted delivery. */
    public function activatePendingDelivery(string $id, string $purpose, DateTimeImmutable $acceptedAt): bool
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Challenge activation must share the accepted-ledger transaction.');
        $this->validateId($id);
        $this->allowedValue($purpose, ['registration', 'password_reset'], 'challenge purpose');
        if (!ChallengeStateMachine::challengeTransitionAllowed($purpose, 'pending_delivery', 'active', true)) {
            throw new LogicException('Pending challenge activation is not allowed for this purpose.');
        }
        $acceptedAt = $acceptedAt->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = VerificationPolicy::expiresAt($acceptedAt)->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'active', last_sent_at = ?, expires_at = ? " .
            "WHERE id = ? AND purpose = ? AND state = 'pending_delivery' AND used_at IS NULL"
        );
        $statement->execute([$acceptedAt->format('Y-m-d H:i:s.u'), $expiresAt, $id, $purpose]);
        return $statement->rowCount() === 1;
    }

    /** A definite provider rejection can close a pending challenge; unknown outcomes remain pending. */
    public function revokePendingDelivery(string $id, string $purpose): bool
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Challenge revocation must share the delivery-outcome transaction.');
        $this->validateId($id);
        $this->allowedValue($purpose, ['registration', 'password_reset'], 'challenge purpose');
        if (!ChallengeStateMachine::challengeTransitionAllowed($purpose, 'pending_delivery', 'revoked')) {
            throw new LogicException('Pending challenge revocation is not allowed for this purpose.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'revoked' WHERE id = ? AND purpose = ? AND state = 'pending_delivery' AND used_at IS NULL"
        );
        $statement->execute([$id, $purpose]);
        return $statement->rowCount() === 1;
    }

    /** Never returns the raw OTP; callers verify the HMAC using the server-only key. */
    public function findActive(string $id, string $purpose, string $subjectType, string $subjectId): ?array
    {
        $this->validateId($id);
        $this->allowedValue($purpose, ['registration', 'password_reset'], 'challenge purpose');
        $this->allowedValue($subjectType, ['registration_attempt', 'user'], 'challenge subject');
        $statement = $this->pdo->prepare(
            "SELECT id, purpose, subject_type, registration_attempt_id, user_id, user_channel_id, channel_type, destination_normalized, code_hmac, attempts, max_attempts, resend_count, last_sent_at, expires_at, created_at " .
            "FROM verification_challenges WHERE id = ? AND purpose = ? AND subject_type = ? AND state = 'active' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts " .
            'AND ((? = \'registration_attempt\' AND registration_attempt_id = ?) OR (? = \'user\' AND user_id = CAST(? AS UNSIGNED))) LIMIT 1'
        );
        $statement->execute([$id, $purpose, $subjectType, $subjectType, $subjectId, $subjectType, $subjectId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Current active registration challenge. It is locked by caller-owned transaction when requested. */
    public function findCurrentActiveForRegistrationAttempt(string $attemptId, bool $forUpdate = false): ?array
    {
        $this->validateAttemptId($attemptId);
        $sql = "SELECT id, purpose, subject_type, registration_attempt_id, channel_type, destination_normalized, state, attempts, max_attempts, resend_count, last_sent_at, expires_at, used_at, created_at " .
            "FROM verification_challenges WHERE purpose = 'registration' AND subject_type = 'registration_attempt' AND registration_attempt_id = ? " .
            "AND state = 'active' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts ORDER BY created_at DESC, id DESC LIMIT 1";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$attemptId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Reads challenge state even when expired/consumed, so the service can return a controlled failure. */
    public function findForRegistrationVerificationForUpdate(string $id, string $attemptId): ?array
    {
        $this->validateId($id);
        $this->validateAttemptId($attemptId);
        $statement = $this->pdo->prepare(
            "SELECT id, purpose, subject_type, registration_attempt_id, channel_type, destination_normalized, code_hmac, state, attempts, max_attempts, resend_count, last_sent_at, expires_at, used_at, created_at " .
            "FROM verification_challenges WHERE id = ? AND purpose = 'registration' AND subject_type = 'registration_attempt' AND registration_attempt_id = ? LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$id, $attemptId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Supersede active registration challenges only after a new provider delivery is accepted. */
    public function supersedeActiveForRegistrationAttempt(string $attemptId): int
    {
        $this->validateAttemptId($attemptId);
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'revoked' WHERE purpose = 'registration' AND subject_type = 'registration_attempt' " .
            "AND registration_attempt_id = ? AND state = 'active' AND used_at IS NULL"
        );
        $statement->execute([$attemptId]);
        return $statement->rowCount();
    }

    public function recordFailedAttempt(string $id): bool
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = IF(attempts + 1 >= max_attempts, 'exhausted', state), attempts = attempts + 1 " .
            "WHERE id = ? AND purpose = 'registration' AND state = 'active' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts"
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    /** Atomic single-use consume; only one concurrent caller can receive true. */
    public function consumeOnce(string $id): bool
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'used', used_at = UTC_TIMESTAMP(6) " .
            "WHERE id = ? AND purpose = 'registration' AND state = 'active' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts"
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }


    /** Consume a password-reset OTP and create a hashed, short-lived, reset-only authorization atomically. */
    public function consumeForPasswordReset(string $id, string $recoveryTokenHash, int $authorizationSeconds = 300): bool
    {
        $this->validateId($id);
        if (!preg_match('/^[a-f0-9]{64}$/D', $recoveryTokenHash)) throw new InvalidArgumentException('Recovery authorization hash is invalid.');
        if ($authorizationSeconds < 60 || $authorizationSeconds > 900) throw new InvalidArgumentException('Recovery authorization lifetime must be 60-900 seconds.');
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'verified_reset', used_at = UTC_TIMESTAMP(6), recovery_token_hash = ?, recovery_expires_at = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)), recovery_used_at = NULL " .
            "WHERE id = ? AND purpose = 'password_reset' AND state = 'active' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts"
        );
        $statement->execute([$recoveryTokenHash, $authorizationSeconds, $id]);
        return $statement->rowCount() === 1;
    }

    /** Atomically spends a reset-only capability; the raw token is never stored. Returns the bound user ID once. */
    public function consumeRecoveryAuthorization(string $rawToken): ?int
    {
        if (strlen($rawToken) < 32 || strlen($rawToken) > 256) throw new InvalidArgumentException('Recovery authorization token format is invalid.');
        $tokenHash = hash('sha256', $rawToken);
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'used', recovery_used_at = UTC_TIMESTAMP(6) " .
            "WHERE recovery_token_hash = ? AND purpose = 'password_reset' AND state = 'verified_reset' AND recovery_used_at IS NULL AND recovery_expires_at > UTC_TIMESTAMP(6)"
        );
        $statement->execute([$tokenHash]);
        if ($statement->rowCount() !== 1) return null;
        $read = $this->pdo->prepare('SELECT user_id FROM verification_challenges WHERE recovery_token_hash = ? AND purpose = \'password_reset\' AND state = \'used\' AND recovery_used_at IS NOT NULL LIMIT 1');
        $read->execute([$tokenHash]);
        $userId = $read->fetchColumn();
        return $userId === false || $userId === null ? null : (int) $userId;
    }

    public function revokeForSubject(string $purpose, string $subjectType, string $subjectId): int
    {
        $this->allowedValue($purpose, ['registration', 'password_reset'], 'challenge purpose');
        $this->allowedValue($subjectType, ['registration_attempt', 'user'], 'challenge subject');
        if ($subjectType === 'registration_attempt' && !preg_match('/^[a-f0-9]{32}$/D', $subjectId)) {
            throw new InvalidArgumentException('Registration attempt ID format is invalid.');
        }
        if ($subjectType === 'user' && (!ctype_digit($subjectId) || (int) $subjectId < 1)) {
            throw new InvalidArgumentException('User ID format is invalid.');
        }
        $column = $subjectType === 'user' ? 'user_id' : 'registration_attempt_id';
        $statement = $this->pdo->prepare("UPDATE verification_challenges SET state = 'revoked', recovery_token_hash = NULL WHERE purpose = ? AND subject_type = ? AND {$column} = ? AND state IN ('pending_delivery','active','verified_reset')");
        $statement->execute([$purpose, $subjectType, $subjectType === 'user' ? (int) $subjectId : $subjectId]);
        return $statement->rowCount();
    }

    /** Loads any state of a recovery Challenge under a row lock; the client never supplies its user ID. */
    public function findForPasswordResetVerificationForUpdate(string $id): ?array
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare(
            "SELECT id, purpose, subject_type, registration_attempt_id, user_id, user_channel_id, channel_type, " .
            "destination_value, destination_normalized, code_hmac, state, attempts, max_attempts, resend_count, " .
            "last_sent_at, expires_at, used_at, recovery_token_hash, recovery_expires_at, recovery_used_at, created_at " .
            "FROM verification_challenges WHERE id = ? AND purpose = 'password_reset' AND subject_type = 'user' LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Return a currently consumable recovery Challenge only for the same user/channel/destination. */
    public function findCurrentActiveForPasswordReset(int $userId, int $channelId, string $channel, string $normalizedDestination, bool $forUpdate = false): ?array
    {
        $this->positiveId($userId, 'user ID');
        $this->positiveId($channelId, 'user channel ID');
        if (!in_array($channel, ['email', 'telegram'], true) || trim($normalizedDestination) === '') {
            throw new InvalidArgumentException('Recovery Challenge search context is invalid.');
        }
        $sql = "SELECT id, purpose, subject_type, user_id, user_channel_id, channel_type, destination_normalized, state, attempts, max_attempts, last_sent_at, expires_at, used_at, created_at " .
            "FROM verification_challenges WHERE purpose = 'password_reset' AND subject_type = 'user' AND user_id = ? AND user_channel_id = ? " .
            "AND channel_type = ? AND destination_normalized = ? AND state = 'active' AND used_at IS NULL " .
            "AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts ORDER BY created_at DESC, id DESC LIMIT 1";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$userId, $channelId, $channel, $normalizedDestination]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Find any live password-reset Challenge for a user, regardless of channel, under a caller transaction. */
    public function findCurrentActivePasswordResetForUser(int $userId, bool $forUpdate = false): ?array
    {
        $this->positiveId($userId, 'user ID');
        $sql = "SELECT id, purpose, subject_type, user_id, user_channel_id, channel_type, destination_normalized, state, attempts, max_attempts, last_sent_at, expires_at, used_at, created_at " .
            "FROM verification_challenges WHERE purpose = 'password_reset' AND subject_type = 'user' AND user_id = ? " .
            "AND state = 'active' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts " .
            'ORDER BY created_at DESC, id DESC LIMIT 1';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Non-locking, non-authorizing owner lookup used only to acquire the same per-user mutex before reset. */
    public function findRecoveryAuthorizationUserId(string $rawToken): ?int
    {
        if (!ResetTokenGenerator::isValid($rawToken)) return null;
        $statement = $this->pdo->prepare(
            "SELECT user_id FROM verification_challenges WHERE recovery_token_hash = ? AND purpose = 'password_reset' " .
            "AND subject_type = 'user' AND state = 'verified_reset' AND recovery_used_at IS NULL AND recovery_expires_at > UTC_TIMESTAMP(6) LIMIT 1"
        );
        $statement->execute([ResetTokenGenerator::hash($rawToken)]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * Fail closed any stale in-flight delivery rows when an account reset commits. The user mutex
     * prevents the normal synchronous sender from being in flight concurrently with this update.
     */
    public function resolvePreparedPasswordResetForUser(int $userId, string $safeCode, DateTimeImmutable $resolvedAt): int
    {
        $this->positiveId($userId, 'user ID');
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $safeCode)) throw new InvalidArgumentException('Delivery result code is invalid.');
        $statement = $this->pdo->prepare(
            "UPDATE verification_delivery_attempts SET state = 'unknown', result_code = ?, resolved_at = ? " .
            "WHERE purpose = 'password_reset' AND subject_type = 'user' AND user_id = ? AND state = 'prepared' AND resolved_at IS NULL"
        );
        $statement->execute([$safeCode, $resolvedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $userId]);
        return $statement->rowCount();
    }

    /** Failed-code counter for Recovery; atomically locks out the Challenge at its configured ceiling. */
    public function recordFailedPasswordResetAttempt(string $id): bool
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = IF(attempts + 1 >= max_attempts, 'exhausted', state), attempts = attempts + 1 " .
            "WHERE id = ? AND purpose = 'password_reset' AND subject_type = 'user' AND state = 'active' AND used_at IS NULL " .
            "AND expires_at > UTC_TIMESTAMP(6) AND attempts < max_attempts"
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    /** Revoke all other in-flight reset Challenges/capabilities for this user after a new OTP is proved. */
    public function revokeOtherPasswordResetChallenges(int $userId, string $exceptChallengeId): int
    {
        $this->positiveId($userId, 'user ID');
        $this->validateId($exceptChallengeId);
        $statement = $this->pdo->prepare(
            "UPDATE verification_challenges SET state = 'revoked', recovery_token_hash = NULL " .
            "WHERE purpose = 'password_reset' AND subject_type = 'user' AND user_id = ? AND id <> ? AND state IN ('pending_delivery','active','verified_reset')"
        );
        $statement->execute([$userId, $exceptChallengeId]);
        return $statement->rowCount();
    }

    private function validateAttemptId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Registration attempt ID format is invalid.');
        }
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Challenge ID format is invalid.');
        }
    }
}
