<?php
declare(strict_types=1);

final class UserRepository extends Repository
{
    private const ACCOUNT_STATES = ['active', 'suspended', 'pending', 'closed'];

    public function create(array $user): array
    {
        $fullName = $this->nonEmptyString((string) ($user['full_name'] ?? ''), 160, 'full name');
        $username = $this->nonEmptyString((string) ($user['username'] ?? ''), 32, 'username');
        if (!preg_match('/^[A-Za-z0-9_]{4,20}$/D', $username)) {
            throw new InvalidArgumentException('Username format is invalid.');
        }
        $normalizedUsername = strtolower($username);
        $phone = (string) ($user['phone'] ?? '');
        if (!preg_match('/^\+989[0-9]{9}$/D', $phone)) {
            throw new InvalidArgumentException('Phone must be a canonical Iranian mobile number.');
        }
        $passwordHash = (string) ($user['password_hash'] ?? '');
        if ($passwordHash === '' || password_get_info($passwordHash)['algoName'] === 'unknown') {
            throw new InvalidArgumentException('A valid password hash is required.');
        }
        $status = $this->allowedValue((string) ($user['account_status'] ?? 'pending'), self::ACCOUNT_STATES, 'account status');
        $publicId = (string) ($user['public_id'] ?? bin2hex(random_bytes(16)));
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new InvalidArgumentException('Public user ID format is invalid.');
        }
        $legacyId = isset($user['legacy_id']) ? (string) $user['legacy_id'] : null;
        if ($legacyId !== null && (trim($legacyId) === '' || strlen($legacyId) > 128)) {
            throw new InvalidArgumentException('Legacy user ID is invalid.');
        }
        $sourceHash = isset($user['legacy_source_hash']) ? (string) $user['legacy_source_hash'] : null;
        if ($sourceHash !== null && !preg_match('/^[a-f0-9]{64}$/D', $sourceHash)) {
            throw new InvalidArgumentException('Legacy source fingerprint is invalid.');
        }
        $legacyTelegram = isset($user['legacy_telegram_username']) && trim((string) $user['legacy_telegram_username']) !== ''
            ? ltrim(trim((string) $user['legacy_telegram_username']), '@') : null;
        if ($legacyTelegram !== null && !preg_match('/^[A-Za-z0-9_]{5,32}$/D', $legacyTelegram)) {
            throw new InvalidArgumentException('Legacy Telegram username format is invalid.');
        }

        $sql = 'INSERT INTO users (public_id, legacy_id, legacy_source_hash, full_name, username, username_normalized, phone, phone_normalized, password_hash, legacy_verified, legacy_telegram_username, legacy_extra_json, account_status, terms_version, terms_accepted_at, created_at, updated_at, last_login_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, UTC_TIMESTAMP(6)), UTC_TIMESTAMP(6), ?)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            $publicId, $legacyId, $sourceHash, $fullName, $username, $normalizedUsername,
            $phone, $phone, $passwordHash, !empty($user['legacy_verified']) ? 1 : 0,
            $legacyTelegram, $user['legacy_extra_json'] ?? null, $status, $user['terms_version'] ?? null, $user['terms_accepted_at'] ?? null,
            $user['created_at'] ?? null, $user['last_login_at'] ?? null,
        ]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'public_id' => $publicId];
    }

    /** Returns only whether a canonical username or phone is already assigned to a user. */
    public function hasRegistrationIdentity(string $usernameNormalized, string $phoneCanonical): bool
    {
        if (!preg_match('/^[a-z0-9_]{4,20}$/D', $usernameNormalized) || !preg_match('/^\+989[0-9]{9}$/D', $phoneCanonical)) {
            throw new InvalidArgumentException('Canonical registration identity is invalid.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE username_normalized = ? OR phone_normalized = ? LIMIT 1');
        $statement->execute([$usernameNormalized, $phoneCanonical]);
        return $statement->fetchColumn() !== false;
    }

    /** Includes password_hash for authentication only; do not serialize this row to an API DTO. */
    public function findForAuthenticationByUsername(string $username): ?array
    {
        $normalized = strtolower(trim($username));
        $statement = $this->pdo->prepare('SELECT id, public_id, full_name, username, username_normalized, phone, password_hash, account_status, legacy_verified, created_at, last_login_at FROM users WHERE username_normalized = ? LIMIT 1');
        $statement->execute([$normalized]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Customer-safe fields only; email comes from the channel table. */
    public function findById(int $id): ?array
    {
        $this->positiveId($id);
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.public_id, u.full_name, u.username, u.phone, u.account_status, u.created_at, u.last_login_at, " .
            "(SELECT c.channel_value FROM user_channels c WHERE c.user_id = u.id AND c.channel_type = 'email' AND c.is_primary = 1 ORDER BY c.id LIMIT 1) AS email " .
            'FROM users u WHERE u.id = ? LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function findByLegacyId(string $legacyId): ?array
    {
        if (trim($legacyId) === '') {
            throw new InvalidArgumentException('Legacy ID is required.');
        }
        $statement = $this->pdo->prepare('SELECT id, public_id, legacy_id, legacy_source_hash, account_status FROM users WHERE legacy_id = ? LIMIT 1');
        $statement->execute([$legacyId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Serializes project-capacity checks for this active user. Must run inside the creation transaction. */
    public function lockActiveForProjectCreation(int $id): bool
    {
        $this->positiveId($id, 'user ID');
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Project-creation user lock requires an active transaction.');
        }
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id = ? AND account_status = 'active' LIMIT 1 FOR UPDATE");
        $statement->execute([$id]);
        return $statement->fetchColumn() !== false;
    }

    public function recordSuccessfulLogin(int $id): bool
    {
        $this->positiveId($id);
        $statement = $this->pdo->prepare("UPDATE users SET last_login_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND account_status = 'active'");
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    public function replacePasswordHash(int $id, string $passwordHash): bool
    {
        $this->positiveId($id, 'user ID');
        if ($passwordHash === '' || password_get_info($passwordHash)['algoName'] === 'unknown') {
            throw new InvalidArgumentException('A valid password hash is required.');
        }
        $statement = $this->pdo->prepare("UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND account_status = 'active'");
        $statement->execute([$passwordHash, $id]);
        return $statement->rowCount() === 1;
    }

    public function changeAccountStatus(int $id, string $status): bool
    {
        $this->positiveId($id);
        $this->allowedValue($status, self::ACCOUNT_STATES, 'account status');
        $statement = $this->pdo->prepare('UPDATE users SET account_status = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $statement->execute([$status, $id]);
        return $statement->rowCount() === 1;
    }

    /**
     * Resolve a Recovery target only through an already-verified channel bound to an active account.
     * For Telegram, the lookup identifier is the MR NEON account username; the actual recipient is
     * always the verified Chat ID stored in user_channels, never a client-supplied Telegram handle.
     * @return array<string,mixed>|null
     */
    public function findVerifiedRecoveryTarget(string $method, string $normalizedIdentifier): ?array
    {
        if (!in_array($method, ['email', 'telegram'], true) || trim($normalizedIdentifier) === '' || strlen($normalizedIdentifier) > 254) {
            throw new InvalidArgumentException('Recovery identifier context is invalid.');
        }
        if ($method === 'email') {
            if (filter_var($normalizedIdentifier, FILTER_VALIDATE_EMAIL) === false || !hash_equals($normalizedIdentifier, strtolower($normalizedIdentifier))) {
                throw new InvalidArgumentException('Recovery email identifier is not canonical.');
            }
            $statement = $this->pdo->prepare(
                "SELECT u.id AS user_id, u.public_id, u.username_normalized, u.account_status, " .
                "c.id AS user_channel_id, c.channel_type, c.channel_value, c.normalized_value, c.provider_subject_id " .
                "FROM users u INNER JOIN user_channels c ON c.user_id = u.id " .
                "WHERE u.account_status = 'active' AND c.channel_type = 'email' AND c.normalized_value = ? AND c.is_verified = 1 " .
                'ORDER BY c.is_primary DESC, c.id ASC LIMIT 1'
            );
            $statement->execute([$normalizedIdentifier]);
        } else {
            if (preg_match('/^[a-z0-9_]{4,20}$/D', $normalizedIdentifier) !== 1) {
                throw new InvalidArgumentException('Recovery account username is not canonical.');
            }
            $statement = $this->pdo->prepare(
                "SELECT u.id AS user_id, u.public_id, u.username_normalized, u.account_status, " .
                "c.id AS user_channel_id, c.channel_type, c.channel_value, c.normalized_value, c.provider_subject_id " .
                "FROM users u INNER JOIN user_channels c ON c.user_id = u.id " .
                "WHERE u.account_status = 'active' AND u.username_normalized = ? AND c.channel_type = 'telegram' AND c.is_verified = 1 " .
                'ORDER BY c.is_primary DESC, c.id ASC LIMIT 1'
            );
            $statement->execute([$normalizedIdentifier]);
        }
        $row = $statement->fetch();
        if (!is_array($row)) return null;
        $channel = (string) ($row['channel_type'] ?? '');
        $destination = (string) ($row['channel_value'] ?? '');
        $normalized = (string) ($row['normalized_value'] ?? '');
        if ($channel !== $method || $destination === '' || $normalized === '' || !hash_equals(strtolower(trim($destination)), strtolower(trim($normalized)))) {
            return null;
        }
        if ($channel === 'telegram' && (!preg_match('/^[0-9]{1,128}$/D', $destination)
            || !is_string($row['provider_subject_id'] ?? null)
            || !preg_match('/^[0-9]{1,128}$/D', (string) $row['provider_subject_id'])
            || !hash_equals($destination, (string) $row['provider_subject_id']))) {
            return null;
        }
        if ($channel === 'email' && filter_var($destination, FILTER_VALIDATE_EMAIL) === false) return null;
        $row['channel_type'] = $channel;
        $row['channel_value'] = $destination;
        $row['normalized_value'] = $normalized;
        return $row;
    }

    /** Revalidates and locks the exact verified destination bound to a Recovery Challenge. */
    public function lockVerifiedRecoveryChannel(int $userId, int $channelId, string $channel, string $normalizedDestination): bool
    {
        $this->positiveId($userId, 'user ID');
        $this->positiveId($channelId, 'user channel ID');
        if (!in_array($channel, ['email', 'telegram'], true) || trim($normalizedDestination) === '' || strlen($normalizedDestination) > 255) {
            throw new InvalidArgumentException('Recovery channel binding is invalid.');
        }
        $statement = $this->pdo->prepare(
            "SELECT u.id FROM users u INNER JOIN user_channels c ON c.user_id = u.id " .
            "WHERE u.id = ? AND u.account_status = 'active' AND c.id = ? AND c.channel_type = ? " .
            "AND c.is_verified = 1 AND c.normalized_value = ? " .
            "AND LOWER(TRIM(c.channel_value)) = c.normalized_value " .
            "AND (c.channel_type <> 'telegram' OR (c.provider_subject_id IS NOT NULL AND c.provider_subject_id = c.channel_value)) " .
            "LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$userId, $channelId, $channel, $normalizedDestination]);
        return $statement->fetchColumn() !== false;
    }

    /** Locks account state for the reset transaction; exposes no customer profile fields. */
    public function lockActiveForPasswordReset(int $userId): bool
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare("SELECT id FROM users WHERE id = ? AND account_status = 'active' LIMIT 1 FOR UPDATE");
        $statement->execute([$userId]);
        return $statement->fetchColumn() !== false;
    }

    /** Admin-safe user listing; never selects password hashes or authentication/session fields. */
    public function listForAdmin(int $page = 1, int $perPage = 10, ?string $status = null, string $search = '', string $sort = 'created_at', string $direction = 'desc', ?string $date = null): array
    {
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $orderBy = [
            'created_at' => 'u.created_at',
            'last_activity' => 'COALESCE(u.last_login_at, u.created_at)',
            'name' => 'u.full_name',
            'username' => 'u.username',
            'account_status' => 'u.account_status',
            'project_count' => 'project_count',
        ];
        if (!isset($orderBy[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Admin user sort option is invalid.');
        }
        $directionSql = strtoupper($direction);
        $tieDirectionSql = $direction === 'asc' ? 'ASC' : 'DESC';
        if ($status !== null) $this->allowedValue($status, self::ACCOUNT_STATES, 'account status');
        if ($date !== null && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateParts) || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]))) {
            throw new InvalidArgumentException('User creation date filter is invalid.');
        }
        $search = trim($search);
        if ($this->utf8Length($search) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $search)) {
            throw new InvalidArgumentException('User search query is invalid.');
        }
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'u.account_status = ?';
            $params[] = $status;
        }
        if ($date !== null) {
            $where[] = 'u.created_at >= ? AND u.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 00:00:00';
        }
        if ($search !== '') {
            $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search);
            $pattern = '%' . $escaped . '%';
            $where[] = "(u.full_name LIKE ? ESCAPE '=' OR u.username LIKE ? ESCAPE '=' OR u.phone LIKE ? ESCAPE '=' OR EXISTS (SELECT 1 FROM user_channels q WHERE q.user_id = u.id AND q.channel_type = 'email' AND q.channel_value LIKE ? ESCAPE '='))";
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }
        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sql = "SELECT u.id, u.public_id, u.full_name, u.username, u.phone, u.account_status, u.created_at, u.last_login_at, (SELECT c.channel_value FROM user_channels c WHERE c.user_id = u.id AND c.channel_type = 'email' AND c.is_primary = 1 ORDER BY c.id LIMIT 1) AS email, (SELECT COUNT(*) FROM projects p WHERE p.owner_user_id = u.id AND p.archived_at IS NULL) AS project_count FROM users u WHERE " . $whereSql . ' ORDER BY ' . $orderBy[$sort] . ' ' . $directionSql . ', u.id ' . $tieDirectionSql . ' LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $param) {
            $statement->bindValue($index + 1, $param, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    /** Minimal, credential-free account projection for the admin user-detail workspace. */
    public function findAdminDetailByPublicId(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new InvalidArgumentException('Public user reference is invalid.');
        }
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.public_id, u.full_name, u.username, u.phone, u.account_status, u.created_at, u.updated_at, u.last_login_at, '
            . '(SELECT COUNT(*) FROM projects p WHERE p.owner_user_id = u.id AND p.archived_at IS NULL) AS project_count '
            . 'FROM users u WHERE u.public_id = ? LIMIT 1'
        );
        $statement->execute([$publicId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** Minimal account state row for an admin-authorized, transactional state change. */
    public function findAccountStateForUpdate(int $id): ?array
    {
        $this->positiveId($id, 'user ID');
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('An account-state lock requires an active transaction.');
        }
        $statement = $this->pdo->prepare('SELECT id, public_id, account_status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function adminCounts(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) AS total_users, COALESCE(SUM(account_status = 'active'), 0) AS active_users, COALESCE(SUM(account_status = 'suspended'), 0) AS suspended_users, COALESCE(SUM(account_status = 'pending'), 0) AS pending_users, COALESCE(SUM(account_status = 'closed'), 0) AS closed_users FROM users")->fetch();
        if (!is_array($row)) throw new RuntimeException('Admin user statistics could not be read.');
        return [
            'totalUsers' => (int) $row['total_users'],
            'activeUsers' => (int) $row['active_users'],
            'suspendedUsers' => (int) $row['suspended_users'],
            'pendingUsers' => (int) $row['pending_users'],
            'closedUsers' => (int) $row['closed_users'],
        ];
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
