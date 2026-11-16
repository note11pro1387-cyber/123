<?php
declare(strict_types=1);

final class RegistrationAttemptRepository extends Repository
{
    public function create(array $attempt): string
    {
        foreach (['full_name', 'username', 'phone', 'password_hash', 'selected_channel'] as $key) {
            if (!isset($attempt[$key]) || trim((string) $attempt[$key]) === '') {
                throw new InvalidArgumentException('Missing registration attempt field: ' . $key . '.');
            }
        }
        $username = (string) $attempt['username'];
        if (!preg_match('/^[A-Za-z0-9_]{4,20}$/D', $username)) {
            throw new InvalidArgumentException('Username format is invalid.');
        }
        $phone = (string) $attempt['phone'];
        if (!preg_match('/^\+989[0-9]{9}$/D', $phone)) {
            throw new InvalidArgumentException('Phone must be a canonical Iranian mobile number.');
        }
        $channel = $this->allowedValue((string) $attempt['selected_channel'], ['telegram', 'email'], 'verification channel');
        $emailValue = isset($attempt['email_value']) && trim((string) $attempt['email_value']) !== '' ? strtolower(trim((string) $attempt['email_value'])) : null;
        if ($emailValue !== null && (strlen($emailValue) > 254 || filter_var($emailValue, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Email value is invalid.');
        }
        if ($channel === 'email' && $emailValue === null) {
            throw new InvalidArgumentException('Email is required when email verification is selected.');
        }
        $passwordHash = (string) $attempt['password_hash'];
        if (password_get_info($passwordHash)['algoName'] === 'unknown') {
            throw new InvalidArgumentException('A valid password hash is required.');
        }
        $id = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare(
            'INSERT INTO registration_attempts (id, full_name, username, username_normalized, phone, phone_normalized, email_value, email_normalized, password_hash, selected_channel, telegram_link_nonce_hash, terms_version, terms_accepted_at, expires_at, consumed_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $email = $emailValue;
        $emailNormalized = $email;
        $expiresAt = (string) ($attempt['expires_at'] ?? '');
        if ($expiresAt === '') {
            throw new InvalidArgumentException('Registration attempt expiry is required.');
        }
        $nonceHash = isset($attempt['telegram_link_nonce_hash']) ? (string) $attempt['telegram_link_nonce_hash'] : null;
        if ($nonceHash !== null && !preg_match('/^[a-f0-9]{64}$/D', $nonceHash)) {
            throw new InvalidArgumentException('Telegram link nonce hash is invalid.');
        }
        if ($channel === 'telegram' && $nonceHash === null) {
            throw new InvalidArgumentException('Telegram registration requires a one-time link nonce hash.');
        }
        if ($channel === 'email' && $nonceHash !== null) {
            throw new InvalidArgumentException('Email registration cannot carry a Telegram link nonce.');
        }
        $statement->execute([
            $id, (string) $attempt['full_name'], $username, strtolower($username), $phone, $phone,
            $email, $emailNormalized, $passwordHash, $channel, $nonceHash,
            $attempt['terms_version'] ?? null, $attempt['terms_accepted_at'] ?? null, $expiresAt,
        ]);
        return $id;
    }


    /** Find one live collision across canonical username, phone, or optional email. Internal-only read. */
    public function findOpenConflictByIdentity(string $usernameNormalized, string $phoneCanonical, ?string $emailNormalized): ?array
    {
        if (!preg_match('/^[a-z0-9_]{4,20}$/D', $usernameNormalized) || !preg_match('/^\+989[0-9]{9}$/D', $phoneCanonical)) {
            throw new InvalidArgumentException('Canonical registration identity is invalid.');
        }
        $where = '(username_normalized = ? OR phone_normalized = ?';
        $params = [$usernameNormalized, $phoneCanonical];
        if ($emailNormalized !== null) {
            if (filter_var($emailNormalized, FILTER_VALIDATE_EMAIL) === false || strlen($emailNormalized) > 254) {
                throw new InvalidArgumentException('Canonical email identity is invalid.');
            }
            $where .= ' OR email_normalized = ?';
            $params[] = $emailNormalized;
        }
        $where .= ')';
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, username, username_normalized, phone, phone_normalized, email_value, email_normalized, password_hash, selected_channel, telegram_link_nonce_hash, linked_telegram_user_id, linked_telegram_chat_id, telegram_linked_at, terms_version, expires_at '
            . 'FROM registration_attempts WHERE consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND ' . $where
            . ' ORDER BY created_at DESC, id LIMIT 1 FOR UPDATE'
        );
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Replace a Telegram nonce hash after a proven idempotent retry; raw nonce is never persisted. */
    public function replaceTelegramNonceHash(string $id, string $nonceHash): bool
    {
        $this->validateId($id);
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonceHash)) {
            throw new InvalidArgumentException('Telegram link nonce hash is invalid.');
        }
        $statement = $this->pdo->prepare("UPDATE registration_attempts SET telegram_link_nonce_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND selected_channel = 'telegram' AND linked_telegram_user_id IS NULL AND linked_telegram_chat_id IS NULL AND telegram_linked_at IS NULL AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)");
        $statement->execute([$nonceHash, $id]);
        return $statement->rowCount() === 1;
    }

    public function findOpen(string $id): ?array
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare('SELECT id, full_name, username, username_normalized, phone, phone_normalized, email_value, email_normalized, selected_channel, terms_version, terms_accepted_at, expires_at, created_at FROM registration_attempts WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function consumeOnce(string $id, int $createdUserId): bool
    {
        $this->validateId($id);
        $this->positiveId($createdUserId, 'created user ID');
        $statement = $this->pdo->prepare('UPDATE registration_attempts SET consumed_at = UTC_TIMESTAMP(6), created_user_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)');
        $statement->execute([$createdUserId, $id]);
        return $statement->rowCount() === 1;
    }


    /** Private service-only read for final account creation; callers must never serialize password_hash. */
    public function findForCompletion(string $id): ?array
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare('SELECT id, full_name, username, username_normalized, phone, phone_normalized, email_value, email_normalized, password_hash, selected_channel, telegram_link_nonce_hash, linked_telegram_user_id, linked_telegram_chat_id, terms_version, terms_accepted_at, expires_at, created_at FROM registration_attempts WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Locked variant for verification and final account completion; transaction ownership stays with the service. */
    public function findForCompletionForUpdate(string $id): ?array
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, username, username_normalized, phone, phone_normalized, email_value, email_normalized, password_hash, selected_channel, telegram_link_nonce_hash, linked_telegram_user_id, linked_telegram_chat_id, telegram_linked_at, terms_version, terms_accepted_at, expires_at, created_at ' .
            'FROM registration_attempts WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Find the open Telegram Attempt that owns this one-time nonce; caller owns transaction/lock. */
    public function findTelegramAttemptByNonceHashForUpdate(string $nonceHash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonceHash)) {
            throw new InvalidArgumentException('Telegram link nonce hash is invalid.');
        }
        $statement = $this->pdo->prepare(
            "SELECT id, full_name, username_normalized, phone_normalized, email_normalized, selected_channel, telegram_link_nonce_hash, linked_telegram_user_id, linked_telegram_chat_id, expires_at " .
            "FROM registration_attempts WHERE telegram_link_nonce_hash = ? AND selected_channel = 'telegram' AND linked_telegram_user_id IS NULL AND linked_telegram_chat_id IS NULL AND telegram_linked_at IS NULL AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$nonceHash]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Persist only after a validated Telegram webhook; nonce compare-and-consume is atomic. */
    public function linkTelegramIdentity(string $id, string $telegramUserId, string $telegramChatId, string $nonceHash): bool
    {
        $this->validateId($id);
        foreach ([$telegramUserId, $telegramChatId] as $value) {
            if (!preg_match('/^[1-9][0-9]{0,19}$/D', $value)) throw new InvalidArgumentException('Telegram provider IDs must be numeric strings.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonceHash)) throw new InvalidArgumentException('Telegram link nonce hash is invalid.');
        $statement = $this->pdo->prepare(
            "UPDATE registration_attempts SET linked_telegram_user_id = ?, linked_telegram_chat_id = ?, telegram_link_nonce_hash = NULL, telegram_linked_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) " .
            "WHERE id = ? AND telegram_link_nonce_hash = ? AND selected_channel = 'telegram' AND linked_telegram_user_id IS NULL AND linked_telegram_chat_id IS NULL AND telegram_linked_at IS NULL AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)"
        );
        $statement->execute([$telegramUserId, $telegramChatId, $id, $nonceHash]);
        return $statement->rowCount() === 1;
    }

    /** Do not allow one Telegram identity to be concurrently bound to unrelated live Attempts. */
    public function telegramIdentityLinkedToOtherOpenAttempt(string $exceptAttemptId, string $telegramUserId, string $telegramChatId): bool
    {
        $this->validateId($exceptAttemptId);
        foreach ([$telegramUserId, $telegramChatId] as $value) {
            if (!preg_match('/^[1-9][0-9]{0,19}$/D', $value)) throw new InvalidArgumentException('Telegram provider IDs must be numeric strings.');
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM registration_attempts WHERE id <> ? AND selected_channel = 'telegram' AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) " .
            "AND (linked_telegram_user_id = ? OR linked_telegram_chat_id = ?) LIMIT 1"
        );
        $statement->execute([$exceptAttemptId, $telegramUserId, $telegramChatId]);
        return $statement->fetchColumn() !== false;
    }

    /** Public response contains only a boolean; no Telegram IDs are exposed. */
    public function telegramLinkStatus(string $id): ?array
    {
        $this->validateId($id);
        $statement = $this->pdo->prepare(
            'SELECT selected_channel, (linked_telegram_user_id IS NOT NULL AND linked_telegram_chat_id IS NOT NULL AND telegram_linked_at IS NOT NULL) AS linked ' .
            'FROM registration_attempts WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6) LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? ['selected_channel' => (string) $row['selected_channel'], 'linked' => (bool) $row['linked']] : null;
    }

    public function countExpired(int $limit = 500): int
    {
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('Invalid cleanup limit.');
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM (SELECT id FROM registration_attempts WHERE consumed_at IS NULL AND expires_at <= UTC_TIMESTAMP(6) ORDER BY expires_at LIMIT ?) AS expired_attempts');
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        return (int) $statement->fetchColumn();
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Registration attempt ID format is invalid.');
        }
    }
}
