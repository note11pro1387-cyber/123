<?php
declare(strict_types=1);

/** Admin-only persistence. Do not inject this repository into customer-facing query/DTO code. */
final class InternalNoteRepository extends Repository
{
    public function create(int $projectId, string $adminId, string $body): int
    {
        $this->positiveId($projectId, 'project ID');
        $adminId = $this->nonEmptyString($adminId, 128, 'admin ID');
        $body = $this->nonEmptyString($body, 60000, 'internal note');
        if ($this->utf8Length($body) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
            throw new InvalidArgumentException('Internal note must be at most 2000 Unicode code points and contain no control characters.');
        }
        $statement = $this->pdo->prepare('INSERT INTO internal_notes (project_id, admin_id, body, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $statement->execute([$projectId, $adminId, $body]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Admin-only query; authorization must be enforced by caller before invoking. */
    public function listForProject(int $projectId, int $page = 1, int $perPage = 50): array
    {
        $this->positiveId($projectId, 'project ID');
        [$offset, $limit] = $this->pageArgs($page, $perPage, 100);
        $statement = $this->pdo->prepare('SELECT id, project_id, admin_id, body, created_at, updated_at FROM internal_notes WHERE project_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        $statement->bindValue(1, $projectId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->bindValue(3, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function update(int $noteId, string $body): bool
    {
        $this->positiveId($noteId, 'note ID');
        $body = $this->nonEmptyString($body, 60000, 'internal note');
        if ($this->utf8Length($body) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
            throw new InvalidArgumentException('Internal note must be at most 2000 Unicode code points and contain no control characters.');
        }
        $statement = $this->pdo->prepare('UPDATE internal_notes SET body = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $statement->execute([$body, $noteId]);
        return $statement->rowCount() === 1;
    }
}
