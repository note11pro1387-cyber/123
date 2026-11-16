<?php
declare(strict_types=1);

final class AuditLogRepository extends Repository
{
    public function append(string $actorType, ?string $actorId, string $action, ?string $subjectType = null, ?string $subjectId = null, ?string $requestId = null, ?string $ipHash = null, ?string $userAgentHash = null, array $safeMetadata = []): int
    {
        $this->allowedValue($actorType, ['user', 'admin', 'system', 'anonymous'], 'audit actor type');
        $action = $this->nonEmptyString($action, 96, 'audit action');
        foreach (['IP hash' => $ipHash, 'user-agent hash' => $userAgentHash] as $name => $hash) {
            if ($hash !== null && !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new InvalidArgumentException($name . ' must be a SHA-256 hex digest.');
            }
        }
        if ($requestId !== null && strlen($requestId) > 64) {
            throw new InvalidArgumentException('Request ID is too long.');
        }
        if ($actorId !== null && strlen($actorId) > 128) {
            throw new InvalidArgumentException('Audit actor ID is too long.');
        }
        $this->rejectSensitiveKeys($safeMetadata, 'audit metadata');
        $metadata = $safeMetadata === [] ? null : $this->jsonObject($safeMetadata, 'safe audit metadata');
        $statement = $this->pdo->prepare('INSERT INTO audit_logs (actor_type, actor_id, action, subject_type, subject_id, request_id, ip_hash, user_agent_hash, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $statement->execute([$actorType, $actorId, $action, $subjectType, $subjectId, $requestId, $ipHash, $userAgentHash, $metadata]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Admin-only safe timeline for one user's account subject; sensitive metadata and hashes are never selected. */
    public function listForUserSubject(int $userId, int $limit = 30): array
    {
        $this->positiveId($userId, 'user ID');
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('User audit limit is invalid.');
        }
        $statement = $this->pdo->prepare(
            "SELECT actor_type, action, created_at FROM audit_logs "
            . "WHERE subject_type = 'user' AND subject_id = ? "
            . 'ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $statement->bindValue(1, (string) $userId, PDO::PARAM_STR);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /**
     * Admin-only read-only timeline. The query deliberately excludes actor IDs, subject IDs,
     * request IDs, IP/UA hashes and metadata_json; only the fields needed by the audit UI are selected.
     * Caller must enforce AdminAuthMiddleware before invoking this method.
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, per_page: int, total: int}
     */
    public function listForAdminTimeline(
        int $page = 1,
        int $perPage = 25,
        string $actorType = 'all',
        string $subjectType = 'all',
        string $search = ''
    ): array {
        [$offset, $limit] = $this->pageArgs($page, $perPage, 50);
        $this->allowedValue($actorType, ['all', 'user', 'admin', 'system', 'anonymous'], 'audit actor filter');
        $this->allowedValue($subjectType, ['all', 'user', 'project', 'registration_attempt', 'none', 'other'], 'audit subject filter');
        $search = trim($search);
        if ($this->utf8Length($search) > 80 || preg_match('/[\\x00-\\x1F\\x7F]/u', $search)) {
            throw new InvalidArgumentException('Admin audit search query is invalid.');
        }

        $where = [];
        $params = [];
        if ($actorType !== 'all') {
            $where[] = 'actor_type = ?';
            $params[] = $actorType;
        }
        if ($subjectType === 'none') {
            $where[] = "(subject_type IS NULL OR subject_type = '')";
        } elseif ($subjectType === 'other') {
            $where[] = "subject_type IS NOT NULL AND subject_type <> '' AND subject_type NOT IN ('user', 'project', 'registration_attempt')";
        } elseif ($subjectType !== 'all') {
            $where[] = 'subject_type = ?';
            $params[] = $subjectType;
        }
        if ($search !== '') {
            // '=' is the explicit LIKE escape character; user-provided wildcards remain literal.
            $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search);
            $where[] = "action LIKE ? ESCAPE '='";
            $params[] = '%' . $escaped . '%';
        }
        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE ' . $whereSql);
        $count->execute($params);
        $total = max(0, (int) $count->fetchColumn());

        $statement = $this->pdo->prepare(
            'SELECT actor_type, action, subject_type, created_at FROM audit_logs WHERE ' . $whereSql
            . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
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
        ];
    }

    /** Admin-only listing; caller must authorize the actor before invoking. */
    public function listRecent(int $page = 1, int $perPage = 50, ?string $action = null): array
    {
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $where = '1 = 1';
        $params = [];
        if ($action !== null) {
            $where .= ' AND action = ?';
            $params[] = $this->nonEmptyString($action, 96, 'audit action');
        }
        $sql = 'SELECT id, actor_type, actor_id, action, subject_type, subject_id, request_id, created_at FROM audit_logs WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $i => $param) {
            $statement->bindValue($i + 1, $param, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }
}
