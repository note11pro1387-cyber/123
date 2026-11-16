<?php
declare(strict_types=1);

final class NotificationRepository extends Repository
{
    public function createForUser(int $userId, ?int $projectId, string $category, string $title, string $body, ?string $targetPath = null): string
    {
        $this->positiveId($userId, 'user ID');
        if ($projectId !== null) {
            $this->positiveId($projectId, 'project ID');
        }
        $category = $this->nonEmptyString($category, 64, 'notification category');
        $title = $this->nonEmptyString($title, 180, 'notification title');
        $body = $this->nonEmptyString($body, 1000, 'notification body');
        if ($targetPath !== null && (!str_starts_with($targetPath, '/') || str_starts_with($targetPath, '//') || preg_match('/[\r\n]/', $targetPath))) {
            throw new InvalidArgumentException('Notification target must be a relative same-origin path.');
        }
        $publicId = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare("INSERT INTO notifications (public_id, recipient_type, recipient_user_id, recipient_admin_id, project_id, category, title, body, target_path, read_at, created_at) VALUES (?, 'user', ?, NULL, ?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(6))");
        $statement->execute([$publicId, $userId, $projectId, $category, $title, $body, $targetPath]);
        return $publicId;
    }

    /**
     * Return the stable admin inbox recipient ID configured on the server, never from request data.
     * This is deliberately separate from the mutable admin login username and credentials.
     */
    public static function configuredAdminInboxId(): ?string
    {
        $candidate = getenv('MRNEON_ADMIN_INBOX_ID');
        if (!is_string($candidate)) return null;
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > 128 || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $candidate) !== 1) {
            return null;
        }
        return $candidate;
    }

    public function createForConfiguredAdmin(?int $projectId, string $category, string $title, string $body, ?string $targetPath = null): ?string
    {
        $adminInboxId = self::configuredAdminInboxId();
        if ($adminInboxId === null) return null;
        return $this->createForAdminInbox($adminInboxId, $projectId, $category, $title, $body, $targetPath);
    }

    /** Create an in-app notice for the configured stable admin inbox recipient. */
    public function createForAdminInbox(string $adminInboxId, ?int $projectId, string $category, string $title, string $body, ?string $targetPath = null): string
    {
        $adminInboxId = self::normalizeAdminInboxId($adminInboxId);
        if ($projectId !== null) $this->positiveId($projectId, 'project ID');
        $category = $this->nonEmptyString($category, 64, 'notification category');
        $title = $this->nonEmptyString($title, 180, 'notification title');
        $body = $this->nonEmptyString($body, 1000, 'notification body');
        self::assertTargetPath($targetPath);
        $publicId = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare("INSERT INTO notifications (public_id, recipient_type, recipient_user_id, recipient_admin_id, project_id, category, title, body, target_path, read_at, created_at) VALUES (?, 'admin', NULL, ?, ?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(6))");
        $statement->execute([$publicId, $adminInboxId, $projectId, $category, $title, $body, $targetPath]);
        return $publicId;
    }

    /** Admin-owned inbox query; user notifications are excluded and identity is exact-match scoped. */
    public function listForAdminInbox(string $adminInboxId, int $page = 1, int $perPage = 25, ?bool $unreadOnly = null): array
    {
        $adminInboxId = self::normalizeAdminInboxId($adminInboxId);
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $where = "recipient_type = 'admin' AND recipient_admin_id = ?";
        $params = [$adminInboxId];
        if ($unreadOnly === true) {
            $where .= ' AND read_at IS NULL';
        } elseif ($unreadOnly === false) {
            $where .= ' AND read_at IS NOT NULL';
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare('SELECT public_id, project_id, category, title, body, target_path, read_at, created_at FROM notifications WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        $statement->bindValue(1, $adminInboxId, PDO::PARAM_STR);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->bindValue(3, $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    public function countUnreadForAdminInbox(string $adminInboxId): int
    {
        $adminInboxId = self::normalizeAdminInboxId($adminInboxId);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'admin' AND recipient_admin_id = ? AND read_at IS NULL");
        $statement->execute([$adminInboxId]);
        return max(0, (int) $statement->fetchColumn());
    }

    public function markReadForAdminInbox(string $publicId, string $adminInboxId): bool
    {
        $adminInboxId = self::normalizeAdminInboxId($adminInboxId);
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new InvalidArgumentException('Notification public ID format is invalid.');
        }
        $statement = $this->pdo->prepare("UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP(6)) WHERE public_id = ? AND recipient_type = 'admin' AND recipient_admin_id = ?");
        $statement->execute([$publicId, $adminInboxId]);
        if ($statement->rowCount() === 1) return true;
        $exists = $this->pdo->prepare("SELECT 1 FROM notifications WHERE public_id = ? AND recipient_type = 'admin' AND recipient_admin_id = ? AND read_at IS NOT NULL LIMIT 1");
        $exists->execute([$publicId, $adminInboxId]);
        return $exists->fetchColumn() !== false;
    }

    /** Customer-owned inbox query; admin notifications are excluded. */
    public function listForUser(int $userId, int $page = 1, int $perPage = 25, ?bool $unreadOnly = null): array
    {
        $this->positiveId($userId, 'user ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $where = "recipient_type = 'user' AND recipient_user_id = ?";
        $params = [$userId];
        if ($unreadOnly === true) {
            $where .= ' AND read_at IS NULL';
        } elseif ($unreadOnly === false) {
            $where .= ' AND read_at IS NOT NULL';
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare('SELECT public_id, project_id, category, title, body, target_path, read_at, created_at FROM notifications WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        foreach ($params as $i => $param) {
            $statement->bindValue($i + 1, $param, PDO::PARAM_INT);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    private static function normalizeAdminInboxId(string $adminInboxId): string
    {
        $adminInboxId = trim($adminInboxId);
        if ($adminInboxId === '' || strlen($adminInboxId) > 128 || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $adminInboxId) !== 1) {
            throw new InvalidArgumentException('Admin inbox recipient identity is invalid.');
        }
        return $adminInboxId;
    }

    private static function assertTargetPath(?string $targetPath): void
    {
        if ($targetPath !== null && (!str_starts_with($targetPath, '/') || str_starts_with($targetPath, '//') || preg_match('/[\r\n]/', $targetPath))) {
            throw new InvalidArgumentException('Notification target must be a relative same-origin path.');
        }
    }

    public function countUnreadForUser(int $userId): int
    {
        $this->positiveId($userId, 'user ID');
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'user' AND recipient_user_id = ? AND read_at IS NULL");
        $statement->execute([$userId]);
        return max(0, (int) $statement->fetchColumn());
    }

    public function markReadForUser(string $publicId, int $userId): bool
    {
        $this->positiveId($userId, 'user ID');
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw new InvalidArgumentException('Notification public ID format is invalid.');
        }
        $statement = $this->pdo->prepare("UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP(6)) WHERE public_id = ? AND recipient_type = 'user' AND recipient_user_id = ?");
        $statement->execute([$publicId, $userId]);
        if ($statement->rowCount() === 1) return true;
        $exists = $this->pdo->prepare("SELECT 1 FROM notifications WHERE public_id = ? AND recipient_type = 'user' AND recipient_user_id = ? AND read_at IS NOT NULL LIMIT 1");
        $exists->execute([$publicId, $userId]);
        return $exists->fetchColumn() !== false;
    }
}
