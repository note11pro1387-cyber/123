<?php
declare(strict_types=1);

final class RateLimitBucketRepository extends Repository
{
    /** Atomic fixed-window counter; stores only a keyed HMAC of the private bucket identity. */
    public function consume(string $scopeType, string $privateBucketIdentity, int $windowSeconds): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $scopeType) || trim($privateBucketIdentity) === '' || strlen($privateBucketIdentity) > 2048) {
            throw new InvalidArgumentException('Rate-limit scope or bucket identity is invalid.');
        }
        if ($windowSeconds < 1 || $windowSeconds > 86400) {
            throw new InvalidArgumentException('Rate-limit window must be between 1 second and 24 hours.');
        }
        $keyHash = $this->bucketKeyHash($scopeType, $privateBucketIdentity);
        $sql = 'INSERT INTO rate_limit_buckets (bucket_key_hash, scope_type, counter, window_started_at, reset_at, blocked_until, updated_at) '
            . 'VALUES (?, ?, 1, UTC_TIMESTAMP(6), TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)), NULL, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'counter = IF(reset_at <= UTC_TIMESTAMP(6), 1, counter + 1), '
            . 'blocked_until = IF(reset_at <= UTC_TIMESTAMP(6), NULL, blocked_until), '
            . 'window_started_at = IF(reset_at <= UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), window_started_at), '
            . 'reset_at = IF(reset_at <= UTC_TIMESTAMP(6), TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)), reset_at), updated_at = UTC_TIMESTAMP(6)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$keyHash, $scopeType, $windowSeconds, $windowSeconds]);
        $read = $this->pdo->prepare('SELECT counter, window_started_at, reset_at, blocked_until FROM rate_limit_buckets WHERE bucket_key_hash = ?');
        $read->execute([$keyHash]);
        $row = $read->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Rate-limit bucket could not be read after atomic update.');
        }
        return $row;
    }

    public function blockUntil(string $scopeType, string $privateBucketIdentity, string $untilUtc): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $scopeType) || trim($privateBucketIdentity) === '') {
            throw new InvalidArgumentException('Rate-limit scope or bucket identity is invalid.');
        }
        $keyHash = $this->bucketKeyHash($scopeType, $privateBucketIdentity);
        $statement = $this->pdo->prepare('UPDATE rate_limit_buckets SET blocked_until = ?, updated_at = UTC_TIMESTAMP(6) WHERE bucket_key_hash = ?');
        $statement->execute([$untilUtc, $keyHash]);
        return $statement->rowCount() === 1;
    }

    /** Remove only expired buckets, with a hard cap to bound cleanup work. */
    public function cleanupExpired(int $limit = 200): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Rate-limit cleanup batch must be between 1 and 1000.');
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM rate_limit_buckets WHERE reset_at <= UTC_TIMESTAMP(6) '
            . 'AND (blocked_until IS NULL OR blocked_until <= UTC_TIMESTAMP(6)) '
            . 'ORDER BY reset_at ASC LIMIT ' . $limit
        );
        $statement->execute();
        return $statement->rowCount();
    }

    private function bucketKeyHash(string $scopeType, string $privateBucketIdentity): string
    {
        $key = getenv('MRNEON_RATE_LIMIT_HMAC_KEY');
        if (!is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Rate-limit identity HMAC is not configured; set MRNEON_RATE_LIMIT_HMAC_KEY privately (32 bytes minimum).');
        }
        return hash_hmac('sha256', $scopeType . "\0" . $privateBucketIdentity, $key);
    }
}
