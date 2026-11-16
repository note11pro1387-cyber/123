<?php
declare(strict_types=1);

final class AuthSessionRepository extends Repository
{
    public function create(string $sessionHash, string $principalType, string $principalId, ?int $userId, string $expiresAt, int $sessionVersion = 1): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sessionHash)) {
            throw new InvalidArgumentException('Session hash must be a SHA-256 hex digest.');
        }
        $this->allowedValue($principalType, ['user', 'admin'], 'principal type');
        if (trim($principalId) === '' || strlen($principalId) > 128 || $sessionVersion < 1) {
            throw new InvalidArgumentException('Session principal or version is invalid.');
        }
        if ($principalType === 'user' && ($userId === null || $userId < 1)) {
            throw new InvalidArgumentException('User session requires a user ID.');
        }
        if ($principalType === 'admin' && $userId !== null) {
            throw new InvalidArgumentException('Admin session must not be bound to a customer user ID.');
        }
        $statement = $this->pdo->prepare('INSERT INTO auth_sessions (session_hash, principal_type, principal_id, user_id, session_version, created_at, expires_at, last_activity_at, revoked_at, revoke_reason) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), NULL, NULL)');
        $statement->execute([$sessionHash, $principalType, $principalId, $userId, $sessionVersion, $expiresAt]);
    }

    public function findActive(string $sessionHash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sessionHash)) {
            throw new InvalidArgumentException('Session hash must be a SHA-256 hex digest.');
        }
        $statement = $this->pdo->prepare('SELECT session_hash, principal_type, principal_id, user_id, session_version, created_at, expires_at, last_activity_at FROM auth_sessions WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute([$sessionHash]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function touch(string $sessionHash): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sessionHash)) {
            throw new InvalidArgumentException('Session hash must be a SHA-256 hex digest.');
        }
        $statement = $this->pdo->prepare('UPDATE auth_sessions SET last_activity_at = UTC_TIMESTAMP(6) WHERE session_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6)');
        $statement->execute([$sessionHash]);
        return $statement->rowCount() === 1;
    }

    public function revoke(string $sessionHash, string $reason): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sessionHash)) {
            throw new InvalidArgumentException('Session hash must be a SHA-256 hex digest.');
        }
        $reason = $this->nonEmptyString($reason, 64, 'revocation reason');
        $statement = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6), revoke_reason = ? WHERE session_hash = ? AND revoked_at IS NULL');
        $statement->execute([$reason, $sessionHash]);
        return $statement->rowCount() === 1;
    }

    /** Admin-only safe metadata projection; raw session hashes are intentionally excluded. */
    public function listSafeMetadataForUser(int $userId, int $limit = 20): array
    {
        $this->positiveId($userId, 'user ID');
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Session metadata limit is invalid.');
        }
        $statement = $this->pdo->prepare(
            'SELECT created_at, expires_at, last_activity_at, revoked_at, revoke_reason '
            . "FROM auth_sessions WHERE principal_type = 'user' AND user_id = ? "
            . 'ORDER BY created_at DESC, session_hash DESC LIMIT ?'
        );
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** Count expired sessions older than an explicitly owner-selected history cutoff. */
    public function countExpiredBeforeCreatedAt(string $createdBeforeUtc): int
    {
        self::assertUtcCutoff($createdBeforeUtc);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM (' .
            'SELECT ranked.session_hash FROM (' .
            'SELECT session_hash, expires_at, created_at, ' .
            'ROW_NUMBER() OVER (PARTITION BY principal_type, principal_id ORDER BY created_at DESC, session_hash DESC) AS history_rank ' .
            'FROM auth_sessions' .
            ') AS ranked WHERE ranked.expires_at <= UTC_TIMESTAMP(6) AND ranked.created_at <= ? AND ranked.history_rank > 20' .
            ') AS eligible_expired_sessions'
        );
        $statement->execute([$createdBeforeUtc]);
        return (int) $statement->fetchColumn();
    }

    /** Delete one bounded batch only; never touches unexpired sessions. Caller owns the transaction. */
    public function deleteExpiredBeforeCreatedAtBatch(string $createdBeforeUtc, int $limit = 200): int
    {
        self::assertUtcCutoff($createdBeforeUtc);
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Session cleanup batch must be between 1 and 500.');
        $statement = $this->pdo->prepare(
            'DELETE FROM auth_sessions WHERE session_hash IN (' .
            'SELECT session_hash FROM (' .
            'SELECT ranked.session_hash FROM (' .
            'SELECT session_hash, expires_at, created_at, ' .
            'ROW_NUMBER() OVER (PARTITION BY principal_type, principal_id ORDER BY created_at DESC, session_hash DESC) AS history_rank ' .
            'FROM auth_sessions' .
            ') AS ranked WHERE ranked.expires_at <= UTC_TIMESTAMP(6) AND ranked.created_at <= ? AND ranked.history_rank > 20 ' .
            'ORDER BY ranked.expires_at ASC, ranked.created_at ASC, ranked.session_hash ASC LIMIT ' . $limit .
            ') AS expired_session_batch)'
        );
        $statement->execute([$createdBeforeUtc]);
        return $statement->rowCount();
    }

    private static function assertUtcCutoff(string $value): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            throw new InvalidArgumentException('Session cleanup cutoff must be a UTC SQL timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value || $date > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Session cleanup cutoff must be a valid UTC timestamp not in the future.');
        }
    }

    public function revokeAllForUser(int $userId, string $reason, ?string $exceptHash = null): int
    {
        $this->positiveId($userId, 'user ID');
        $reason = $this->nonEmptyString($reason, 64, 'revocation reason');
        if ($exceptHash !== null && !preg_match('/^[a-f0-9]{64}$/D', $exceptHash)) {
            throw new InvalidArgumentException('Session hash must be a SHA-256 hex digest.');
        }
        $sql = 'UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP(6), revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL';
        $params = [$reason, $userId];
        if ($exceptHash !== null) {
            $sql .= ' AND session_hash <> ?';
            $params[] = $exceptHash;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }
}
