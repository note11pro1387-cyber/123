<?php
declare(strict_types=1);

final class ProjectMessageRepository extends Repository
{
    public function create(int $projectId, string $senderType, ?string $senderId, string $body): int
    {
        $this->positiveId($projectId, 'project ID');
        $this->allowedValue($senderType, ['user', 'admin', 'system'], 'sender type');
        $body = $this->nonEmptyString($body, 60000, 'message body');
        if ($this->utf8Length($body) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
            throw new InvalidArgumentException('Message body must be at most 2000 Unicode code points and contain no control characters.');
        }
        $statement = $this->pdo->prepare('INSERT INTO project_messages (project_id, sender_type, sender_id, body, created_at, read_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), NULL)');
        $statement->execute([$projectId, $senderType, $senderId, $body]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Customer-scoped query: ownership is enforced in SQL and internal_notes are never joined. */
    public function listForOwnerProject(int $projectId, int $ownerUserId, int $page = 1, int $perPage = 50): array
    {
        $this->positiveId($projectId, 'project ID');
        $this->positiveId($ownerUserId, 'owner user ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.project_id, m.sender_type, m.body, m.created_at, m.read_at ' .
            'FROM project_messages m INNER JOIN projects p ON p.id = m.project_id ' .
            'WHERE m.project_id = ? AND p.owner_user_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $projectId, PDO::PARAM_INT);
        $statement->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $statement->bindValue(3, $limit, PDO::PARAM_INT);
        $statement->bindValue(4, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** Counts messages only when the project is owned by the authenticated user. */
    public function countForOwnerProject(int $projectId, int $ownerUserId): int
    {
        $this->positiveId($projectId, 'project ID');
        $this->positiveId($ownerUserId, 'owner user ID');
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM project_messages m INNER JOIN projects p ON p.id = m.project_id ' .
            'WHERE m.project_id = ? AND p.owner_user_id = ?'
        );
        $statement->execute([$projectId, $ownerUserId]);
        return (int) $statement->fetchColumn();
    }

    public function markUnreadForOwnerProject(int $projectId, int $ownerUserId): int
    {
        $this->positiveId($projectId, 'project ID');
        $this->positiveId($ownerUserId, 'owner user ID');
        $statement = $this->pdo->prepare(
            'UPDATE project_messages m INNER JOIN projects p ON p.id = m.project_id ' .
            'SET m.read_at = COALESCE(m.read_at, UTC_TIMESTAMP(6)) ' .
            "WHERE m.project_id = ? AND p.owner_user_id = ? AND m.sender_type <> 'user' AND m.read_at IS NULL"
        );
        $statement->execute([$projectId, $ownerUserId]);
        return $statement->rowCount();
    }

    /** Admin-only recent customer conversation messages across a user's projects; internal notes are excluded. */
    public function listRecentForUser(int $userId, int $limit = 30): array
    {
        $this->positiveId($userId, 'user ID');
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('User message limit is invalid.');
        }
        $statement = $this->pdo->prepare(
            'SELECT p.public_id AS project_public_id, p.tracking_code, p.title AS project_title, '
            . 'm.sender_type, LEFT(m.body, 1200) AS body_preview, m.created_at, m.read_at '
            . 'FROM project_messages m INNER JOIN projects p ON p.id = m.project_id '
            . 'WHERE p.owner_user_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT ?'
        );
        $statement->bindValue(1, $userId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /**
     * Admin-only inbox search. This query reads only customer-visible project_messages;
     * internal_notes are deliberately not joined or exposed.
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, per_page: int, total: int, unread_customer_count: int}
     */
    public function listForAdminInbox(
        int $page = 1,
        int $perPage = 25,
        string $search = '',
        string $sender = 'all',
        string $read = 'all'
    ): array {
        [$offset, $limit] = $this->pageArgs($page, $perPage, 50);
        $search = trim($search);
        if ($this->utf8Length($search) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $search)) {
            throw new InvalidArgumentException('Admin message search query is invalid.');
        }
        $this->allowedValue($sender, ['all', 'user', 'admin', 'system'], 'message sender filter');
        $this->allowedValue($read, ['all', 'unread', 'read'], 'message read filter');

        $where = [];
        $params = [];
        if ($sender !== 'all') {
            $where[] = 'm.sender_type = ?';
            $params[] = $sender;
        }
        if ($read === 'unread') {
            // The admin inbox considers only inbound customer messages unread.
            $where[] = "m.sender_type = 'user' AND m.read_at IS NULL";
        } elseif ($read === 'read') {
            $where[] = 'm.read_at IS NOT NULL';
        }
        if ($search !== '') {
            // '=' is the explicit LIKE escape character, keeping user-supplied % and _ literal.
            $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search);
            $pattern = '%' . $escaped . '%';
            $where[] = "(m.body LIKE ? ESCAPE '=' OR p.tracking_code LIKE ? ESCAPE '=' OR p.title LIKE ? ESCAPE '=' OR u.full_name LIKE ? ESCAPE '=' OR u.username LIKE ? ESCAPE '=' OR u.phone LIKE ? ESCAPE '=')";
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM project_messages m '
            . 'INNER JOIN projects p ON p.id = m.project_id '
            . 'INNER JOIN users u ON u.id = p.owner_user_id WHERE ' . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $unreadCount = $this->pdo->query(
            "SELECT COUNT(*) FROM project_messages WHERE sender_type = 'user' AND read_at IS NULL"
        );
        $unreadCustomerCount = (int) $unreadCount->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT m.id, m.project_id, m.sender_type, LEFT(m.body, 1200) AS body_preview, '
            . 'CASE WHEN CHAR_LENGTH(m.body) > 1200 THEN 1 ELSE 0 END AS body_truncated, '
            . 'm.created_at, m.read_at, p.public_id AS project_public_id, p.tracking_code, '
            . 'p.title AS project_title, p.status AS project_status, p.archived_at, '
            . 'u.full_name AS owner_full_name, u.username AS owner_username '
            . 'FROM project_messages m '
            . 'INNER JOIN projects p ON p.id = m.project_id '
            . 'INNER JOIN users u ON u.id = p.owner_user_id '
            . 'WHERE ' . $whereSql . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $param) {
            $statement->bindValue($index + 1, $param, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'unread_customer_count' => $unreadCustomerCount,
        ];
    }

    /** Admin caller must already have passed AdminAuthMiddleware. */
    public function markUnreadForAdminProject(int $projectId): int
    {
        $this->positiveId($projectId, 'project ID');
        $statement = $this->pdo->prepare(
            "UPDATE project_messages SET read_at = COALESCE(read_at, UTC_TIMESTAMP(6)) WHERE project_id = ? AND sender_type = 'user' AND read_at IS NULL"
        );
        $statement->execute([$projectId]);
        return $statement->rowCount();
    }

    public function markReadForOwner(int $messageId, int $ownerUserId): bool
    {
        $this->positiveId($messageId, 'message ID');
        $this->positiveId($ownerUserId, 'owner user ID');
        $statement = $this->pdo->prepare(
            'UPDATE project_messages m INNER JOIN projects p ON p.id = m.project_id ' .
            'SET m.read_at = COALESCE(m.read_at, UTC_TIMESTAMP(6)) ' .
            'WHERE m.id = ? AND p.owner_user_id = ? AND m.sender_type <> \'user\''
        );
        $statement->execute([$messageId, $ownerUserId]);
        return $statement->rowCount() === 1;
    }

    /** Admin query only; must be called after server-side Admin authorization. */
    public function listForAdminProject(int $projectId, int $page = 1, int $perPage = 100): array
    {
        $this->positiveId($projectId, 'project ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 200);
        $statement = $this->pdo->prepare('SELECT id, project_id, sender_type, sender_id, body, created_at, read_at FROM project_messages WHERE project_id = ? ORDER BY created_at ASC, id ASC LIMIT ? OFFSET ?');
        $statement->bindValue(1, $projectId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->bindValue(3, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }
}
