<?php
declare(strict_types=1);

final class ProjectMilestoneRepository extends Repository
{
    private const DEFAULTS = [
        ['intake', 'Brief / Intake', 10],
        ['review', 'Review', 10],
        ['design', 'Design', 20],
        ['development', 'Development', 35],
        ['qa', 'QA', 15],
        ['delivery', 'Delivery', 10],
    ];

    /** Must be called inside the same transaction as project creation. */
    public function createDefaults(int $projectId): int
    {
        $this->positiveId($projectId, 'project ID');
        $statement = $this->pdo->prepare("INSERT INTO project_milestones (project_id, code, name, status, weight, created_at, updated_at) VALUES (?, ?, ?, 'pending', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $created = 0;
        foreach (self::DEFAULTS as [$code, $name, $weight]) {
            $statement->execute([$projectId, $code, $name, $weight]);
            $created++;
        }
        return $created;
    }

    /**
     * Admin/domain-level read for a project whose access has already been authorized.
     * Customer-facing code must use listForOwnerProject() so project ownership is
     * enforced again by the milestone query itself.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForProject(int $projectId): array
    {
        $this->positiveId($projectId, 'project ID');
        $statement = $this->pdo->prepare('SELECT id, project_id, code, name, status, weight, started_at, completed_at, created_at, updated_at FROM project_milestones WHERE project_id = ? ORDER BY id');
        $statement->execute([$projectId]);
        return $statement->fetchAll();
    }

    /**
     * Customer-safe milestone read. Ownership is enforced in the same SQL query
     * that reads the milestones; a project ID alone is never sufficient authority.
     * The bounded result protects the customer detail response from an unexpectedly
     * large or malformed milestone collection while preserving the normal six-step flow.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForOwnerProject(int $projectId, int $ownerUserId): array
    {
        $this->positiveId($projectId, 'project ID');
        $this->positiveId($ownerUserId, 'owner user ID');

        $statement = $this->pdo->prepare(
            'SELECT m.id, m.project_id, m.code, m.name, m.status, m.weight, '
            . 'm.started_at, m.completed_at, m.created_at, m.updated_at '
            . 'FROM project_milestones m '
            . 'INNER JOIN projects p ON p.id = m.project_id '
            . 'WHERE m.project_id = ? AND p.owner_user_id = ? '
            . 'ORDER BY m.id ASC LIMIT 100'
        );
        $statement->execute([$projectId, $ownerUserId]);

        return $statement->fetchAll();
    }

    /**
     * Completing a project makes its milestone state and progress coherent. Must run inside
     * the same transaction as the project status transition; never called as a standalone write.
     */
    public function completeAllForProject(int $projectId): int
    {
        $this->positiveId($projectId, 'project ID');
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Completing project milestones requires an active transaction.');
        }
        $statement = $this->pdo->prepare("UPDATE project_milestones SET status = 'completed', started_at = COALESCE(started_at, UTC_TIMESTAMP(6)), completed_at = COALESCE(completed_at, UTC_TIMESTAMP(6)), updated_at = UTC_TIMESTAMP(6) WHERE project_id = ? AND (status <> 'completed' OR started_at IS NULL OR completed_at IS NULL)");
        $statement->execute([$projectId]);
        return max(0, $statement->rowCount());
    }

    public function setStatus(int $milestoneId, string $status): bool
    {
        $this->positiveId($milestoneId, 'milestone ID');
        $this->allowedValue($status, ['pending', 'active', 'completed'], 'milestone status');
        $statement = $this->pdo->prepare("UPDATE project_milestones SET status = ?, started_at = IF(? = 'active' AND started_at IS NULL, UTC_TIMESTAMP(6), started_at), completed_at = IF(? = 'completed', COALESCE(completed_at, UTC_TIMESTAMP(6)), IF(? = 'pending', NULL, completed_at)), updated_at = UTC_TIMESTAMP(6) WHERE id = ?");
        $statement->execute([$status, $status, $status, $status, $milestoneId]);
        return $statement->rowCount() === 1;
    }

    /** Progress is derived from completed weights; caller may persist it in the same transaction. */
    public function completedWeightPercent(int $projectId): int
    {
        $this->positiveId($projectId, 'project ID');
        $statement = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'completed' THEN weight ELSE 0 END), 0) FROM project_milestones WHERE project_id = ?");
        $statement->execute([$projectId]);
        return min(100, max(0, (int) $statement->fetchColumn()));
    }
}
