<?php
declare(strict_types=1);

/**
 * Durable idempotency reservations shared by project creation, customer messages and Telegram updates.
 * Expired completed/failed rows may be reclaimed; expired processing rows fail closed for operator review.
 */
final class IdempotencyKeyRepository extends Repository
{
    public function find(string $scopeType, string $scopeId, string $route, string $rawKey): ?array
    {
        [$scopeType, $scopeId, $route, $keyHash] = $this->normalizeIdentity($scopeType, $scopeId, $route, $rawKey);
        $statement = $this->pdo->prepare(
            'SELECT id, request_hash, state, response_status, response_json, created_at, expires_at, completed_at ' .
            'FROM idempotency_keys WHERE scope_type = ? AND scope_id = ? AND route = ? AND key_hash = ? ' .
            "AND (expires_at > UTC_TIMESTAMP(6) OR state = 'processing') LIMIT 1"
        );
        $statement->execute([$scopeType, $scopeId, $route, $keyHash]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Creates a reservation. Expired completed/failed records for this exact identity are reclaimed first.
     * The operation is designed to be called inside the same transaction as its business mutation.
     * Returns false only for a duplicate unique scoped key; other integrity errors are propagated.
     */
    public function reserve(string $scopeType, string $scopeId, string $route, string $rawKey, string $requestHash, string $expiresAt): bool
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Idempotency reservation must share a transaction with the business mutation.');
        }
        [$scopeType, $scopeId, $route, $keyHash] = $this->normalizeIdentity($scopeType, $scopeId, $route, $rawKey);
        if (!preg_match('/^[a-f0-9]{64}$/D', $requestHash)) {
            throw new InvalidArgumentException('Request fingerprint must be a SHA-256 hex digest.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $expiresAt)) {
            throw new InvalidArgumentException('Idempotency expiration timestamp is invalid.');
        }

        // The unique key is stable across retries. Reclaim only terminal, already-expired rows;
        // never remove a processing row because its outcome cannot be inferred from age alone.
        $reclaim = $this->pdo->prepare(
            "DELETE FROM idempotency_keys WHERE scope_type = ? AND scope_id = ? AND route = ? AND key_hash = ? " .
            "AND expires_at <= UTC_TIMESTAMP(6) AND state IN ('completed','failed')"
        );
        $reclaim->execute([$scopeType, $scopeId, $route, $keyHash]);

        $statement = $this->pdo->prepare(
            "INSERT INTO idempotency_keys " .
            "(scope_type, scope_id, route, key_hash, request_hash, state, response_status, response_json, created_at, expires_at, completed_at) " .
            "VALUES (?, ?, ?, ?, ?, 'processing', NULL, NULL, UTC_TIMESTAMP(6), ?, NULL)"
        );
        try {
            $statement->execute([$scopeType, $scopeId, $route, $keyHash, $requestHash, $expiresAt]);
            return true;
        } catch (PDOException $exception) {
            // MariaDB/MySQL duplicate-key error. Do not mistake other SQLSTATE 23000 conditions for a race.
            if (($exception->errorInfo[0] ?? null) === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $exception;
        }
    }

    public function complete(string $scopeType, string $scopeId, string $route, string $rawKey, string $requestHash, int $responseStatus, array $safeResponse): bool
    {
        [$scopeType, $scopeId, $route, $keyHash] = $this->normalizeIdentity($scopeType, $scopeId, $route, $rawKey);
        if (!preg_match('/^[a-f0-9]{64}$/D', $requestHash) || $responseStatus < 100 || $responseStatus > 599) {
            throw new InvalidArgumentException('Idempotency completion metadata is invalid.');
        }
        $responseJson = $this->jsonObject($safeResponse, 'safe idempotency response');
        $statement = $this->pdo->prepare(
            "UPDATE idempotency_keys SET state = 'completed', response_status = ?, response_json = ?, completed_at = UTC_TIMESTAMP(6) " .
            "WHERE scope_type = ? AND scope_id = ? AND route = ? AND key_hash = ? AND request_hash = ? " .
            "AND state = 'processing' AND expires_at > UTC_TIMESTAMP(6)"
        );
        $statement->execute([$responseStatus, $responseJson, $scopeType, $scopeId, $route, $keyHash, $requestHash]);
        return $statement->rowCount() === 1;
    }

    /** Delete at most 500 expired terminal records. Processing records are intentionally excluded. */
    public function deleteExpiredCompletedOrFailedBatch(int $limit = 200): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Idempotency cleanup batch must be between 1 and 500.');
        }
        $statement = $this->pdo->prepare(
            "DELETE FROM idempotency_keys WHERE id IN (" .
            "SELECT id FROM (SELECT id FROM idempotency_keys " .
            "WHERE expires_at <= UTC_TIMESTAMP(6) AND state IN ('completed','failed') " .
            "ORDER BY expires_at ASC, id ASC LIMIT " . $limit . ") AS expired_terminal_rows)"
        );
        $statement->execute();
        return $statement->rowCount();
    }

    /** Returns only aggregate counts; no key hashes, payloads, or user identifiers are exposed. */
    public function countExpiredByState(): array
    {
        $counts = ['processing' => 0, 'completed' => 0, 'failed' => 0];
        $statement = $this->pdo->query(
            'SELECT state, COUNT(*) AS record_count FROM idempotency_keys ' .
            'WHERE expires_at <= UTC_TIMESTAMP(6) GROUP BY state'
        );
        foreach ($statement->fetchAll() as $row) {
            $state = $row['state'] ?? null;
            if (is_string($state) && array_key_exists($state, $counts)) {
                $counts[$state] = (int) ($row['record_count'] ?? 0);
            }
        }
        return $counts;
    }

    private function normalizeIdentity(string $scopeType, string $scopeId, string $route, string $rawKey): array
    {
        foreach ([$scopeType, $scopeId, $route, $rawKey] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Idempotency scope, route and key are required.');
            }
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $scopeType) || strlen($scopeId) > 128 || strlen($route) > 160 || strlen($rawKey) > 256) {
            throw new InvalidArgumentException('Idempotency identity fields are invalid.');
        }
        if (preg_match('/[\r\n\0]/', $scopeId . $route . $rawKey)) {
            throw new InvalidArgumentException('Idempotency identity contains a control character.');
        }
        return [$scopeType, $scopeId, $route, hash('sha256', $rawKey)];
    }
}
