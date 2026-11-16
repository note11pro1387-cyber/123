<?php
declare(strict_types=1);

/** Persists non-secret transport outcomes so an interrupted send is never retried blindly. */
final class VerificationDeliveryAttemptRepository extends Repository
{
    public function createPreparedRegistration(string $challengeId, string $attemptId, string $channel, string $destinationHmac, string $kind): void
    {
        $this->validateChallengeId($challengeId);
        $this->validateAttemptId($attemptId);
        $this->allowedValue($channel, ['email', 'telegram'], 'delivery channel');
        $this->allowedValue($kind, ['initial', 'resend'], 'delivery kind');
        if (!preg_match('/^[a-f0-9]{64}$/D', $destinationHmac)) {
            throw new InvalidArgumentException('Delivery destination fingerprint is invalid.');
        }
        $statement = $this->pdo->prepare(
            "INSERT INTO verification_delivery_attempts (challenge_id, purpose, subject_type, registration_attempt_id, user_id, delivery_kind, channel_type, destination_hmac, state, result_code, created_at, resolved_at) " .
            "VALUES (?, 'registration', 'registration_attempt', ?, NULL, ?, ?, ?, 'prepared', NULL, UTC_TIMESTAMP(6), NULL)"
        );
        $statement->execute([$challengeId, $attemptId, $kind, $channel, $destinationHmac]);
    }

    public function resolveRegistration(string $challengeId, string $state, string $safeCode, DateTimeImmutable $resolvedAt): bool
    {
        $this->validateChallengeId($challengeId);
        $this->allowedValue($state, ['accepted', 'rejected', 'unknown', 'not_configured'], 'delivery result state');
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $safeCode)) {
            throw new InvalidArgumentException('Delivery result code is invalid.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE verification_delivery_attempts SET state = ?, result_code = ?, resolved_at = ? " .
            "WHERE challenge_id = ? AND purpose = 'registration' AND subject_type = 'registration_attempt' AND state = 'prepared' AND resolved_at IS NULL"
        );
        $statement->execute([$state, $safeCode, $resolvedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $challengeId]);
        return $statement->rowCount() === 1;
    }

    public function hasPreparedForRegistration(string $attemptId): bool
    {
        $this->validateAttemptId($attemptId);
        $statement = $this->pdo->prepare("SELECT 1 FROM verification_delivery_attempts WHERE purpose = 'registration' AND registration_attempt_id = ? AND state = 'prepared' LIMIT 1");
        $statement->execute([$attemptId]);
        return $statement->fetchColumn() !== false;
    }

    public function countForRegistration(string $attemptId): int
    {
        $this->validateAttemptId($attemptId);
        // A known not_configured result cannot have sent a message and must not burn the resend cap.
        // Rejected and unknown outcomes do count; unknown must not permit unlimited blind retries.
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM verification_delivery_attempts WHERE purpose = 'registration' AND registration_attempt_id = ? AND state <> 'not_configured'");
        $statement->execute([$attemptId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function latestForRegistration(string $attemptId): ?array
    {
        $this->validateAttemptId($attemptId);
        $statement = $this->pdo->prepare(
            "SELECT challenge_id, delivery_kind, channel_type, state, result_code, created_at, resolved_at " .
            "FROM verification_delivery_attempts WHERE purpose = 'registration' AND registration_attempt_id = ? " .
            'ORDER BY COALESCE(resolved_at, created_at) DESC, created_at DESC, challenge_id DESC LIMIT 1'
        );
        $statement->execute([$attemptId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function countAcceptedForRegistration(string $attemptId): int
    {
        $this->validateAttemptId($attemptId);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM verification_delivery_attempts WHERE purpose = 'registration' AND registration_attempt_id = ? AND state = 'accepted'");
        $statement->execute([$attemptId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function latestAcceptedForRegistration(string $attemptId): ?array
    {
        $this->validateAttemptId($attemptId);
        $statement = $this->pdo->prepare(
            "SELECT challenge_id, delivery_kind, channel_type, destination_hmac, resolved_at " .
            "FROM verification_delivery_attempts WHERE purpose = 'registration' AND registration_attempt_id = ? AND state = 'accepted' " .
            'ORDER BY resolved_at DESC, created_at DESC LIMIT 1'
        );
        $statement->execute([$attemptId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function createPreparedPasswordReset(string $challengeId, int $userId, string $channel, string $destinationHmac, string $kind): void
    {
        $this->validateChallengeId($challengeId);
        $this->positiveId($userId, 'user ID');
        $this->allowedValue($channel, ['email', 'telegram'], 'delivery channel');
        $this->allowedValue($kind, ['initial', 'resend'], 'delivery kind');
        if (!preg_match('/^[a-f0-9]{64}$/D', $destinationHmac)) throw new InvalidArgumentException('Delivery destination fingerprint is invalid.');
        $statement = $this->pdo->prepare(
            "INSERT INTO verification_delivery_attempts (challenge_id, purpose, subject_type, registration_attempt_id, user_id, delivery_kind, channel_type, destination_hmac, state, result_code, created_at, resolved_at) " .
            "VALUES (?, 'password_reset', 'user', NULL, ?, ?, ?, ?, 'prepared', NULL, UTC_TIMESTAMP(6), NULL)"
        );
        $statement->execute([$challengeId, $userId, $kind, $channel, $destinationHmac]);
    }

    public function resolvePasswordReset(string $challengeId, string $state, string $safeCode, DateTimeImmutable $resolvedAt): bool
    {
        $this->validateChallengeId($challengeId);
        $this->allowedValue($state, ['accepted', 'rejected', 'unknown', 'not_configured'], 'delivery result state');
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $safeCode)) throw new InvalidArgumentException('Delivery result code is invalid.');
        $statement = $this->pdo->prepare(
            "UPDATE verification_delivery_attempts SET state = ?, result_code = ?, resolved_at = ? " .
            "WHERE challenge_id = ? AND purpose = 'password_reset' AND subject_type = 'user' AND state = 'prepared' AND resolved_at IS NULL"
        );
        $statement->execute([$state, $safeCode, $resolvedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $challengeId]);
        return $statement->rowCount() === 1;
    }

    public function hasPreparedPasswordResetForUser(int $userId): bool
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare("SELECT 1 FROM verification_delivery_attempts WHERE purpose = 'password_reset' AND user_id = ? AND state = 'prepared' LIMIT 1");
        $statement->execute([$userId]);
        return $statement->fetchColumn() !== false;
    }

    public function countPasswordResetForUser(int $userId): int
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM verification_delivery_attempts WHERE purpose = 'password_reset' AND user_id = ? " .
            "AND state <> 'not_configured' AND created_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)"
        );
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function latestPasswordResetForUser(int $userId): ?array
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare(
            "SELECT challenge_id, delivery_kind, channel_type, destination_hmac, state, result_code, created_at, resolved_at " .
            "FROM verification_delivery_attempts WHERE purpose = 'password_reset' AND user_id = ? " .
            'ORDER BY COALESCE(resolved_at, created_at) DESC, created_at DESC, challenge_id DESC LIMIT 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function countAcceptedPasswordResetForUser(int $userId): int
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM verification_delivery_attempts WHERE purpose = 'password_reset' AND user_id = ? AND state = 'accepted'");
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    private function validateAttemptId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Registration attempt ID format is invalid.');
        }
    }

    private function validateChallengeId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Challenge ID format is invalid.');
        }
    }
}
