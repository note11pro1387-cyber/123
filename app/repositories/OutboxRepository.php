<?php
declare(strict_types=1);

/** Queue persistence only; this Wave intentionally does not add a delivery worker. */
final class OutboxRepository extends Repository
{
    public function enqueue(string $eventType, string $aggregateType, string $aggregateId, string $channelType, array $payload, ?string $idempotencyKey = null): string
    {
        $eventType = $this->nonEmptyString($eventType, 64, 'outbox event type');
        $aggregateType = $this->nonEmptyString($aggregateType, 32, 'aggregate type');
        $aggregateId = $this->nonEmptyString($aggregateId, 128, 'aggregate ID');
        $this->allowedValue($channelType, ['telegram', 'email', 'sms'], 'outbox channel');
        if ($idempotencyKey !== null && (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $idempotencyKey))) {
            throw new InvalidArgumentException('Outbox idempotency key format is invalid.');
        }
        $this->rejectSensitiveKeys($payload, 'outbox payload');
        $publicId = bin2hex(random_bytes(16));
        $payloadJson = $this->jsonObject($payload, 'outbox payload');
        $statement = $this->pdo->prepare("INSERT INTO outbox_events (public_id, event_type, aggregate_type, aggregate_id, channel_type, status, payload_json, idempotency_key, attempts, next_attempt_at, lease_until, accepted_at, last_safe_error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, 0, UTC_TIMESTAMP(6), NULL, NULL, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $statement->execute([$publicId, $eventType, $aggregateType, $aggregateId, $channelType, $payloadJson, $idempotencyKey]);
        return $publicId;
    }

    /** Intended for future CLI workers; does not send anything. */
    public function findDue(int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Outbox batch size must be 1-100.');
        }
        $statement = $this->pdo->prepare("SELECT id, public_id, event_type, aggregate_type, aggregate_id, channel_type, status, payload_json, attempts, next_attempt_at, lease_until FROM outbox_events WHERE ((status IN ('pending','retry') AND next_attempt_at <= UTC_TIMESTAMP(6)) OR (status = 'processing' AND lease_until <= UTC_TIMESTAMP(6))) ORDER BY next_attempt_at, id LIMIT ?");
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function recordResult(int $id, string $status, int $attempts, string $nextAttemptAt, ?string $safeError = null, bool $transportAccepted = false): bool
    {
        $this->positiveId($id, 'outbox ID');
        $this->allowedValue($status, ['pending', 'processing', 'sent', 'retry', 'dead'], 'outbox status');
        if ($attempts < 0 || $attempts > 255) {
            throw new InvalidArgumentException('Outbox attempt count is out of range.');
        }
        if ($safeError !== null && strlen($safeError) > 500) {
            throw new InvalidArgumentException('Safe error summary is too long.');
        }
        $statement = $this->pdo->prepare('UPDATE outbox_events SET status = ?, attempts = ?, next_attempt_at = ?, lease_until = IF(? = \'processing\', ?, NULL), accepted_at = IF(? = 1, COALESCE(accepted_at, UTC_TIMESTAMP(6)), accepted_at), last_safe_error = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $statement->execute([$status, $attempts, $nextAttemptAt, $status, $nextAttemptAt, $transportAccepted ? 1 : 0, $safeError, $id]);
        return $statement->rowCount() === 1;
    }
}
