<?php
declare(strict_types=1);

final class ProjectEventRepository extends Repository
{
    public function append(int $projectId, string $actorType, ?string $actorId, string $eventType, ?string $fromStatus = null, ?string $toStatus = null, ?string $message = null, array $metadata = [], ?string $requestId = null): int
    {
        $this->positiveId($projectId, 'project ID');
        $this->allowedValue($actorType, ['user', 'admin', 'system'], 'actor type');
        $eventType = $this->nonEmptyString($eventType, 64, 'event type');
        $statuses = ['submitted', 'under_review', 'needs_information', 'in_progress', 'waiting_for_client', 'completed', 'rejected', 'cancelled'];
        if ($fromStatus !== null) $this->allowedValue($fromStatus, $statuses, 'source project status');
        if ($toStatus !== null) $this->allowedValue($toStatus, $statuses, 'target project status');
        if ($message !== null && $this->utf8Length($message) > 1000) {
            throw new InvalidArgumentException('Event message is too long.');
        }
        if ($requestId !== null && strlen($requestId) > 64) {
            throw new InvalidArgumentException('Request ID is too long.');
        }
        $json = $metadata === [] ? null : $this->jsonObject($metadata, 'event metadata');
        $statement = $this->pdo->prepare('INSERT INTO project_events (project_id, actor_type, actor_id, event_type, from_status, to_status, message, metadata_json, request_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $statement->execute([$projectId, $actorType, $actorId, $eventType, $fromStatus, $toStatus, $message, $json, $requestId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Admin/domain-only unscoped read; caller must authorize before invoking. */
    public function listForProject(int $projectId, int $page = 1, int $perPage = 50): array
    {
        $this->positiveId($projectId, 'project ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $statement = $this->pdo->prepare('SELECT id, project_id, actor_type, actor_id, event_type, from_status, to_status, message, metadata_json, request_id, created_at FROM project_events WHERE project_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        $statement->bindValue(1, $projectId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->bindValue(3, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** Customer-safe event timeline scoped to a project owned by the authenticated user. */
    public function listForOwnerProject(int $projectId, int $ownerUserId, int $page = 1, int $perPage = 50): array
    {
        $this->positiveId($projectId, 'project ID');
        $this->positiveId($ownerUserId, 'owner user ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        // Only allow explicitly public project lifecycle events into the customer timeline.
        // Reasons are exposed only for the three statuses that require a customer-facing reason;
        // actor identifiers, metadata, request IDs, internal notes and unrelated event messages stay private.
        $statement = $this->pdo->prepare(
            "SELECT e.id, e.event_type, e.from_status, e.to_status, e.created_at, "
            . "CASE WHEN e.event_type = 'project.status_changed' AND e.to_status IN ('needs_information','rejected','cancelled') THEN e.message ELSE NULL END AS customer_message "
            . "FROM project_events e INNER JOIN projects p ON p.id = e.project_id "
            . "WHERE e.project_id = ? AND p.owner_user_id = ? AND e.event_type IN ('project.submitted','project.status_changed','project.milestone_changed') "
            . 'ORDER BY e.created_at DESC, e.id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $projectId, PDO::PARAM_INT);
        $statement->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $statement->bindValue(3, $limit, PDO::PARAM_INT);
        $statement->bindValue(4, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** Counts only customer-visible events for a project owned by the authenticated user. */
    public function countForOwnerProject(int $projectId, int $ownerUserId): int
    {
        $this->positiveId($projectId, 'project ID');
        $this->positiveId($ownerUserId, 'owner user ID');
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM project_events e INNER JOIN projects p ON p.id = e.project_id "
            . "WHERE e.project_id = ? AND p.owner_user_id = ? AND e.event_type IN ('project.submitted','project.status_changed','project.milestone_changed')"
        );
        $statement->execute([$projectId, $ownerUserId]);
        return (int) $statement->fetchColumn();
    }
}
