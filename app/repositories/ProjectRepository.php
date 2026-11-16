<?php
declare(strict_types=1);

final class ProjectRepository extends Repository
{
    private const STATUSES = ['submitted', 'under_review', 'needs_information', 'in_progress', 'waiting_for_client', 'completed', 'rejected', 'cancelled'];

    /** Creates only the project row. Caller owns the surrounding transaction and adds milestones/events/outbox. */
    public function create(int $ownerUserId, string $title, string $projectType, array $brief): array
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        $title = $this->nonEmptyString($title, 180, 'project title');
        $projectType = $this->nonEmptyString($projectType, 64, 'project type');
        $briefJson = $this->jsonObject($brief, 'project brief');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $publicId = bin2hex(random_bytes(16));
            $trackingCode = self::newTrackingCode();
            try {
                $statement = $this->pdo->prepare("INSERT INTO projects (public_id, tracking_code, owner_user_id, title, project_type, status, progress, brief_json, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'submitted', 0, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
                $statement->execute([$publicId, $trackingCode, $ownerUserId, $title, $projectType, $briefJson]);
                $projectId = (int) $this->pdo->lastInsertId();
                $created = $this->pdo->prepare('SELECT created_at FROM projects WHERE id = ? LIMIT 1');
                $created->execute([$projectId]);
                $createdAt = $created->fetchColumn();
                if (!is_string($createdAt) || $createdAt === '') {
                    throw new RuntimeException('Canonical project creation timestamp could not be read.');
                }
                return [
                    'id' => $projectId,
                    'public_id' => $publicId,
                    'tracking_code' => $trackingCode,
                    'status' => 'submitted',
                    'progress' => 0,
                    'created_at' => $createdAt,
                ];
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || (int) ($exception->errorInfo[1] ?? 0) !== 1062 || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new RuntimeException('Could not allocate a unique project identifier.');
    }

    public function findByPublicIdForOwner(string $publicId, int $ownerUserId): ?array
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new InvalidArgumentException('Public project ID format is invalid.');
        }
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.public_id, p.tracking_code, p.owner_user_id, p.title, p.project_type, p.status, p.progress, '
            . 'p.brief_json, p.submitted_at, p.created_at, p.updated_at, p.cancelled_at, p.cancelled_by, '
            . 'p.cancel_reason, p.rejection_reason, p.archived_at, '
            . "CASE WHEN p.status = 'rejected' THEN p.rejection_reason "
            . "WHEN p.status = 'cancelled' THEN p.cancel_reason "
            . "WHEN p.status = 'needs_information' THEN (SELECT e.message FROM project_events e "
            . "WHERE e.project_id = p.id AND e.event_type = 'project.status_changed' AND e.to_status = 'needs_information' "
            . 'AND e.message IS NOT NULL ORDER BY e.created_at DESC, e.id DESC LIMIT 1) ELSE NULL END AS status_reason '
            . 'FROM projects p WHERE p.public_id = ? AND p.owner_user_id = ? LIMIT 1'
        );
        $statement->execute([$publicId, $ownerUserId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string) $row['brief_json'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored project brief is invalid JSON.');
        }
        $row['brief'] = $decoded;
        unset($row['brief_json']);
        return $row;
    }

    /** Minimal project cards for an authorized admin user dossier; does not decode or return private Brief fields. */
    public function listForAdminUserDossier(int $ownerUserId, int $page = 1, int $perPage = 50): array
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 50);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE owner_user_id = ?');
        $count->execute([$ownerUserId]);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT public_id, tracking_code, title, project_type, status, progress, created_at, updated_at, archived_at '
            . 'FROM projects WHERE owner_user_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $ownerUserId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->bindValue(3, $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    public function listForOwner(int $ownerUserId, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        if ($status !== null) {
            $this->allowedValue($status, self::STATUSES, 'project status');
        }
        $where = 'owner_user_id = ?';
        $params = [$ownerUserId];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT id, public_id, tracking_code, title, project_type, status, progress, brief_json, submitted_at, created_at, updated_at, archived_at FROM projects WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        foreach ($params as $index => $param) {
            $stmt->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $decoded = json_decode((string) ($item['brief_json'] ?? ''), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Stored project brief is invalid JSON.');
            }
            $item['brief'] = $decoded;
            unset($item['brief_json']);
        }
        unset($item);
        return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    /** Canonical lifecycle states shared by admin UI and server-side transition policy. */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /** Admin-only project search; returned fields deliberately exclude credentials and raw storage metadata. */
    public function listForAdmin(int $page = 1, int $perPage = 10, ?string $status = null, ?string $projectType = null, string $search = '', string $sort = 'created_at', string $direction = 'desc', ?string $ownerFilter = null, ?string $date = null): array
    {
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $orderBy = [
            'created_at' => 'p.created_at',
            'updated_at' => 'p.updated_at',
            'tracking_code' => 'p.tracking_code',
            'status' => 'p.status',
            'owner_name' => 'u.full_name',
            'progress' => 'p.progress',
        ];
        if (!isset($orderBy[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Admin project sort option is invalid.');
        }
        $directionSql = strtoupper($direction);
        $tieDirectionSql = $direction === 'asc' ? 'ASC' : 'DESC';
        if ($status !== null) $this->allowedValue($status, self::STATUSES, 'project status');
        if ($projectType !== null && !preg_match('/^[a-z0-9_]{1,64}$/D', $projectType)) {
            throw new InvalidArgumentException('Project type filter is invalid.');
        }
        $search = trim($search);
        if ($this->utf8Length($search) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $search)) {
            throw new InvalidArgumentException('Project search query is invalid.');
        }
        if ($ownerFilter !== null && (trim($ownerFilter) === '' || $this->utf8Length($ownerFilter) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $ownerFilter))) {
            throw new InvalidArgumentException('Project owner filter is invalid.');
        }
        if ($date !== null && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateParts) || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]))) {
            throw new InvalidArgumentException('Project creation date filter is invalid.');
        }

        $where = ['p.archived_at IS NULL'];
        $params = [];
        if ($status !== null) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($projectType !== null) {
            $where[] = 'p.project_type = ?';
            $params[] = $projectType;
        }
        if ($ownerFilter !== null) {
            $where[] = '(u.username = ? OR u.public_id = ?)';
            $params[] = $ownerFilter;
            $params[] = $ownerFilter;
        }
        if ($date !== null) {
            $where[] = 'p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 00:00:00';
        }
        if ($search !== '') {
            // '=' is the explicit LIKE escape character, so typed '%' and '_' remain literal.
            $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search);
            $pattern = '%' . $escaped . '%';
            $where[] = "(p.tracking_code LIKE ? ESCAPE '=' OR p.title LIKE ? ESCAPE '=' OR p.project_type LIKE ? ESCAPE '=' OR u.full_name LIKE ? ESCAPE '=' OR u.username LIKE ? ESCAPE '=' OR u.phone LIKE ? ESCAPE '=' OR EXISTS (SELECT 1 FROM user_channels q WHERE q.user_id = u.id AND q.channel_type = 'email' AND q.channel_value LIKE ? ESCAPE '='))";
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM projects p INNER JOIN users u ON u.id = p.owner_user_id WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = "SELECT p.id, p.public_id, p.tracking_code, p.owner_user_id, p.title, p.project_type, p.status, p.progress, p.brief_json, p.submitted_at, p.created_at, p.updated_at, p.archived_at, u.public_id AS owner_public_id, u.full_name AS owner_full_name, u.username AS owner_username, u.phone AS owner_phone, u.account_status AS owner_account_status, (SELECT c.channel_value FROM user_channels c WHERE c.user_id = u.id AND c.channel_type = 'email' AND c.is_primary = 1 ORDER BY c.id LIMIT 1) AS owner_email, (SELECT n.body FROM internal_notes n WHERE n.project_id = p.id ORDER BY n.id DESC LIMIT 1) AS latest_internal_note FROM projects p INNER JOIN users u ON u.id = p.owner_user_id WHERE " . $whereSql . ' ORDER BY ' . $orderBy[$sort] . ' ' . $directionSql . ', p.id ' . $tieDirectionSql . ' LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $param) {
            $statement->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $brief = json_decode((string) ($item['brief_json'] ?? ''), true);
            $item['brief'] = is_array($brief) ? $brief : [];
            $item['brief_available'] = is_array($brief);
            unset($item['brief_json']);
        }
        unset($item);
        return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    /**
     * Admin-only server-side export using the same filters as listForAdmin().
     * The controller supplies a hard-bounded limit and rejects overflow instead of silently truncating.
     * @return array<int,array<string,mixed>>
     */
    public function listForAdminExport(?string $status = null, ?string $projectType = null, string $search = '', int $limit = 5001, ?string $ownerFilter = null, ?string $date = null, string $sort = 'created_at', string $direction = 'desc'): array
    {
        if ($limit < 1 || $limit > 5001) {
            throw new InvalidArgumentException('Admin export row bound is invalid.');
        }
        $orderBy = [
            'created_at' => 'p.created_at',
            'updated_at' => 'p.updated_at',
            'tracking_code' => 'p.tracking_code',
            'status' => 'p.status',
            'owner_name' => 'u.full_name',
            'progress' => 'p.progress',
        ];
        if (!isset($orderBy[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Admin project export sort option is invalid.');
        }
        $directionSql = strtoupper($direction);
        $tieDirectionSql = $direction === 'asc' ? 'ASC' : 'DESC';
        if ($status !== null) $this->allowedValue($status, self::STATUSES, 'project status');
        if ($projectType !== null && !preg_match('/^[a-z0-9_]{1,64}$/D', $projectType)) {
            throw new InvalidArgumentException('Project type filter is invalid.');
        }
        $search = trim($search);
        if ($this->utf8Length($search) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $search)) {
            throw new InvalidArgumentException('Project search query is invalid.');
        }
        if ($ownerFilter !== null && (trim($ownerFilter) === '' || $this->utf8Length($ownerFilter) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $ownerFilter))) {
            throw new InvalidArgumentException('Project owner filter is invalid.');
        }
        if ($date !== null && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateParts) || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]))) {
            throw new InvalidArgumentException('Project creation date filter is invalid.');
        }

        $where = ['p.archived_at IS NULL'];
        $params = [];
        if ($status !== null) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($projectType !== null) {
            $where[] = 'p.project_type = ?';
            $params[] = $projectType;
        }
        if ($ownerFilter !== null) {
            $where[] = '(u.username = ? OR u.public_id = ?)';
            $params[] = $ownerFilter;
            $params[] = $ownerFilter;
        }
        if ($date !== null) {
            $where[] = 'p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 00:00:00';
        }
        if ($search !== '') {
            $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search);
            $pattern = '%' . $escaped . '%';
            $where[] = "(p.tracking_code LIKE ? ESCAPE '=' OR p.title LIKE ? ESCAPE '=' OR p.project_type LIKE ? ESCAPE '=' OR u.full_name LIKE ? ESCAPE '=' OR u.username LIKE ? ESCAPE '=' OR u.phone LIKE ? ESCAPE '=' OR EXISTS (SELECT 1 FROM user_channels q WHERE q.user_id = u.id AND q.channel_type = 'email' AND q.channel_value LIKE ? ESCAPE '='))";
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        $whereSql = implode(' AND ', $where);
        $sql = "SELECT p.id, p.tracking_code, p.project_type, p.status, p.progress, p.created_at, p.updated_at, p.brief_json, u.full_name AS owner_full_name, u.username AS owner_username, "
            . "(SELECT n.body FROM internal_notes n WHERE n.project_id = p.id ORDER BY n.id DESC LIMIT 1) AS latest_internal_note "
            . "FROM projects p INNER JOIN users u ON u.id = p.owner_user_id WHERE " . $whereSql
            . ' ORDER BY ' . $orderBy[$sort] . ' ' . $directionSql . ', p.id ' . $tieDirectionSql . ' LIMIT ?';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $param) {
            $statement->bindValue($index + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $brief = json_decode((string) ($item['brief_json'] ?? ''), true);
            if (!is_array($brief)) {
                throw new RuntimeException('Stored project brief is invalid JSON; export was not produced.');
            }
            $item['brief'] = $brief;
            unset($item['brief_json']);
        }
        unset($item);
        return $items;
    }

    /** Admin-only canonical record lookup. Optional row lock is valid only inside a transaction. */
    public function findByIdForAdmin(int $projectId, bool $forUpdate = false): ?array
    {
        $this->positiveId($projectId, 'project ID');
        if ($forUpdate && !$this->pdo->inTransaction()) {
            throw new LogicException('An admin project lock requires an active transaction.');
        }
        $sql = "SELECT p.id, p.public_id, p.tracking_code, p.owner_user_id, p.title, p.project_type, p.status, p.progress, p.brief_json, p.submitted_at, p.created_at, p.updated_at, p.cancelled_at, p.cancelled_by, p.cancel_reason, p.rejection_reason, p.archived_at, u.public_id AS owner_public_id, u.full_name AS owner_full_name, u.username AS owner_username, u.phone AS owner_phone, u.account_status AS owner_account_status, (SELECT c.channel_value FROM user_channels c WHERE c.user_id = u.id AND c.channel_type = 'email' AND c.is_primary = 1 ORDER BY c.id LIMIT 1) AS owner_email, (SELECT n.body FROM internal_notes n WHERE n.project_id = p.id ORDER BY n.id DESC LIMIT 1) AS latest_internal_note FROM projects p INNER JOIN users u ON u.id = p.owner_user_id WHERE p.id = ? LIMIT 1";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$projectId]);
        $row = $statement->fetch();
        if (!is_array($row)) return null;
        $brief = json_decode((string) ($row['brief_json'] ?? ''), true);
        $row['brief'] = is_array($brief) ? $brief : [];
        $row['brief_available'] = is_array($brief);
        unset($row['brief_json']);
        return $row;
    }

    /** Server-side counts use the same lifecycle states as the canonical projects table. */
    public function adminStats(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS total_projects, " .
            "COALESCE(SUM(status IN ('submitted','under_review','needs_information')), 0) AS pending_projects, " .
            "COALESCE(SUM(status IN ('in_progress','waiting_for_client')), 0) AS in_progress_projects, " .
            "COALESCE(SUM(status = 'completed'), 0) AS completed_projects, " .
            "COALESCE(SUM(status = 'rejected'), 0) AS rejected_projects, " .
            "COALESCE(SUM(status = 'cancelled'), 0) AS cancelled_projects " .
            'FROM projects WHERE archived_at IS NULL'
        )->fetch();
        if (!is_array($row)) throw new RuntimeException('Admin project statistics could not be read.');
        return [
            'totalProjects' => (int) $row['total_projects'],
            'pending' => (int) $row['pending_projects'],
            'inProgress' => (int) $row['in_progress_projects'],
            'completed' => (int) $row['completed_projects'],
            'rejected' => (int) $row['rejected_projects'],
            'cancelled' => (int) $row['cancelled_projects'],
        ];
    }

    /** Current project types from real non-archived data, for a contract-aligned admin filter. */
    public function listActiveProjectTypes(): array
    {
        $statement = $this->pdo->query('SELECT DISTINCT project_type FROM projects WHERE archived_at IS NULL ORDER BY project_type');
        $types = $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($types, static fn($value): bool => is_string($value) && preg_match('/^[a-z0-9_]{1,64}$/D', $value) === 1));
    }

    public function countForOwnerByStatus(int $ownerUserId, string $status): int
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        $this->allowedValue($status, self::STATUSES, 'project status');
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE owner_user_id = ? AND status = ?');
        $statement->execute([$ownerUserId, $status]);
        return (int) $statement->fetchColumn();
    }

    public function updateStatus(int $projectId, string $status, ?string $reason = null, ?string $actorId = null): bool
    {
        $this->positiveId($projectId, 'project ID');
        $this->allowedValue($status, self::STATUSES, 'project status');
        if ($status === 'rejected' && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('A rejection reason is required.');
        }
        if ($reason !== null && $this->utf8Length($reason) > 500) {
            throw new InvalidArgumentException('Project status reason is too long.');
        }
        if ($status === 'cancelled' && ($reason === null || trim($reason) === '' || $actorId === null || trim($actorId) === '')) {
            throw new InvalidArgumentException('Cancellation requires an actor ID and reason.');
        }
        if ($status === 'needs_information' && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('A customer-visible information request is required.');
        }
        $statement = $this->pdo->prepare("UPDATE projects SET status = ?, rejection_reason = IF(? = 'rejected', ?, rejection_reason), cancel_reason = IF(? = 'cancelled', ?, cancel_reason), cancelled_by = IF(? = 'cancelled', ?, cancelled_by), cancelled_at = IF(? = 'cancelled', UTC_TIMESTAMP(6), cancelled_at), progress = IF(? = 'completed', 100, progress), updated_at = UTC_TIMESTAMP(6) WHERE id = ?");
        $statement->execute([$status, $status, $reason, $status, $reason, $status, $actorId, $status, $status, $projectId]);
        return $statement->rowCount() === 1;
    }

    public function archive(int $projectId): bool
    {
        $this->positiveId($projectId, 'project ID');
        $statement = $this->pdo->prepare("UPDATE projects SET archived_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND archived_at IS NULL AND status IN ('cancelled','completed','rejected')");
        $statement->execute([$projectId]);
        return $statement->rowCount() === 1;
    }

    public function countForOwner(int $ownerUserId): int
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE owner_user_id = ?');
        $statement->execute([$ownerUserId]);
        return (int) $statement->fetchColumn();
    }

    /** Counts active, non-archived projects using the server's state model. */
    public function countActiveForOwner(int $ownerUserId): int
    {
        $this->positiveId($ownerUserId, 'owner user ID');
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM projects WHERE owner_user_id = ? AND archived_at IS NULL AND status NOT IN ('completed','rejected','cancelled')");
        $statement->execute([$ownerUserId]);
        return (int) $statement->fetchColumn();
    }

    private static function newTrackingCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $random = random_bytes(12);
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[ord($random[$i]) % strlen($alphabet)];
        }
        return 'MN-' . $code;
    }
}
