<?php
declare(strict_types=1);

final class UserChannelRepository extends Repository
{
    public function create(int $userId, string $type, string $value, bool $verified = false, ?string $providerSubjectId = null, bool $primary = false): int
    {
        $this->positiveId($userId, 'user ID');
        $this->allowedValue($type, ['email', 'telegram'], 'channel type');
        $value = $this->nonEmptyString($value, 255, 'channel value');
        if ($type === 'email') {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Email channel value is invalid.');
            }
            $normalized = strtolower($value);
            if ($providerSubjectId !== null) {
                throw new InvalidArgumentException('Email channels cannot have a provider subject ID.');
            }
        } else {
            if ($providerSubjectId === null || trim($providerSubjectId) === '' || strlen($providerSubjectId) > 128) {
                if ($verified) {
                    throw new InvalidArgumentException('A verified Telegram channel requires a Telegram provider subject ID.');
                }
            }
            $normalized = strtolower(ltrim($value, '@'));
            if ($providerSubjectId !== null && !preg_match('/^[0-9]{1,128}$/D', $providerSubjectId)) {
                throw new InvalidArgumentException('Telegram provider subject ID format is invalid.');
            }
        }
        if ($verified && $type === 'telegram' && $providerSubjectId === null) {
            throw new InvalidArgumentException('Telegram verification must be bound to a provider subject ID.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO user_channels (user_id, channel_type, channel_value, normalized_value, provider_subject_id, is_verified, verified_at, is_primary, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, IF(? = 1, UTC_TIMESTAMP(6), NULL), ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $statement->execute([$userId, $type, $value, $normalized, $providerSubjectId, $verified ? 1 : 0, $verified ? 1 : 0, $primary ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function listForUser(int $userId): array
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare('SELECT id, channel_type, channel_value, provider_subject_id, is_verified, verified_at, is_primary, created_at FROM user_channels WHERE user_id = ? ORDER BY channel_type, is_primary DESC, id');
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    public function findVerifiedForUser(int $userId, string $type): array
    {
        $this->positiveId($userId, 'user ID');
        $this->allowedValue($type, ['email', 'telegram'], 'channel type');
        $statement = $this->pdo->prepare('SELECT id, channel_type, channel_value, normalized_value, provider_subject_id, verified_at FROM user_channels WHERE user_id = ? AND channel_type = ? AND is_verified = 1 ORDER BY is_primary DESC, id');
        $statement->execute([$userId, $type]);
        return $statement->fetchAll();
    }

    /** Checks destination uniqueness without revealing which account owns a destination. */
    public function existsByNormalizedValue(string $type, string $normalizedValue): bool
    {
        $this->allowedValue($type, ['email', 'telegram'], 'channel type');
        if (trim($normalizedValue) === '' || strlen($normalizedValue) > 255) {
            throw new InvalidArgumentException('Normalized channel value is invalid.');
        }
        if ($type === 'email' && filter_var($normalizedValue, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Normalized email channel value is invalid.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM user_channels WHERE channel_type = ? AND normalized_value = ? LIMIT 1');
        $statement->execute([$type, $normalizedValue]);
        return $statement->fetchColumn() !== false;
    }

    public function findByProviderSubject(string $type, string $providerSubjectId): ?array
    {
        $this->allowedValue($type, ['email', 'telegram'], 'channel type');
        if (trim($providerSubjectId) === '') {
            throw new InvalidArgumentException('Provider subject ID is required.');
        }
        $statement = $this->pdo->prepare('SELECT id, user_id, channel_type, is_verified, verified_at FROM user_channels WHERE channel_type = ? AND provider_subject_id = ? LIMIT 1');
        $statement->execute([$type, $providerSubjectId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
