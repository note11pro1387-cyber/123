<?php
declare(strict_types=1);

final class MigrationRunner
{
    private const LOCK_NAME = 'mrneon_schema_migration_v1';

    public function __construct(private PDO $pdo, private string $directory)
    {
    }

    /** @return list<array{version:string,name:string,checksum:string,path:string}> */
    public function discover(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Migration directory is missing.');
        }
        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        $versions = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!preg_match('/^(\d{3,})_([a-z0-9_]+)\.sql$/D', $name, $matches)) {
                throw new RuntimeException('Invalid migration filename: ' . $name);
            }
            $version = $matches[1];
            if (isset($versions[$version])) {
                throw new RuntimeException('Duplicate migration version: ' . $version);
            }
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Migration file is empty or unreadable: ' . $name);
            }
            if (str_contains($sql, "\0")) {
                throw new RuntimeException('Migration contains an invalid byte: ' . $name);
            }
            $versions[$version] = true;
            $migrations[] = [
                'version' => $version,
                'name' => $name,
                'checksum' => hash('sha256', $sql),
                'path' => $file,
            ];
        }
        if ($migrations === []) {
            throw new RuntimeException('No migrations were found.');
        }
        foreach ($migrations as $index => $migration) {
            if ((int) $migration['version'] !== $index + 1) {
                throw new RuntimeException('Migration version sequence has a gap or does not start at 001.');
            }
        }
        return $migrations;
    }

    /** @return array{schema_exists:bool,current_version:?string,applied:list<array<string,mixed>>,pending:list<array<string,mixed>>,checksum_errors:list<string>} */
    public function status(): array
    {
        $migrations = $this->discover();
        if (!$this->tableExists('schema_migrations')) {
            return [
                'schema_exists' => false,
                'current_version' => null,
                'applied' => [],
                'pending' => $migrations,
                'checksum_errors' => [],
            ];
        }
        $rows = $this->pdo->query('SELECT version, migration_name, checksum, applied_at, execution_ms FROM schema_migrations ORDER BY version')->fetchAll();
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string) $row['version']] = $row;
        }
        $applied = [];
        $pending = [];
        $checksumErrors = [];
        foreach ($migrations as $migration) {
            $version = $migration['version'];
            if (!isset($stored[$version])) {
                $pending[] = $migration;
                continue;
            }
            $row = $stored[$version];
            if (!hash_equals((string) $row['checksum'], $migration['checksum']) || (string) $row['migration_name'] !== $migration['name']) {
                $checksumErrors[] = $version;
            }
            $applied[] = $row;
            unset($stored[$version]);
        }
        if ($stored !== []) {
            $checksumErrors[] = 'database_has_unknown_versions';
        }
        $versions = array_column($applied, 'version');
        $current = $versions === [] ? null : end($versions);
        return [
            'schema_exists' => true,
            'current_version' => $current === false ? null : $current,
            'applied' => $applied,
            'pending' => $pending,
            'checksum_errors' => $checksumErrors,
        ];
    }

    /** Applies pending files serially using a named DB lock. DDL limitations are documented. */
    public function apply(): array
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock within 10 seconds.');
        }
        $appliedNow = [];
        try {
            $status = $this->status();
            if ($status['checksum_errors'] !== []) {
                throw new RuntimeException('Migration checksum/version mismatch. No migration was executed.');
            }
            foreach ($status['pending'] as $migration) {
                $sql = file_get_contents($migration['path']);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException('A pending migration became unreadable: ' . $migration['name']);
                }
                $statement = trim($sql);
                if (substr_count($statement, ';') !== 1 || !str_ends_with($statement, ';')) {
                    throw new RuntimeException('Migration must contain exactly one SQL statement: ' . $migration['name']);
                }

                $statementType = null;
                if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $statement, $tableMatch)) {
                    $statementType = 'create';
                } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`?([a-z_]+)`?\s+/i', $statement, $tableMatch)) {
                    $statementType = 'alter';
                } else {
                    throw new RuntimeException('Migration must contain one supported CREATE TABLE or ALTER TABLE statement: ' . $migration['name']);
                }

                $targetTable = strtolower($tableMatch[1]);
                $targetExists = $this->tableExists($targetTable);
                if ($statementType === 'create' && $migration['version'] !== '001' && $targetExists) {
                    throw new RuntimeException('MIGRATION_TABLE_EXISTS_UNRECORDED:' . $targetTable);
                }
                if ($statementType === 'alter' && !$targetExists) {
                    throw new RuntimeException('MIGRATION_TABLE_MISSING:' . $targetTable);
                }
                $start = microtime(true);
                // Each migration file is exactly one supported CREATE TABLE or ALTER TABLE statement.
                // MySQL/MariaDB DDL may auto-commit, so existence and checksum guards run before execution.
                $this->pdo->exec($sql);
                $elapsedMs = max(0, (int) round((microtime(true) - $start) * 1000));
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, migration_name, checksum, applied_at, execution_ms) VALUES (?, ?, ?, UTC_TIMESTAMP(6), ?)'
                );
                $statement->execute([$migration['version'], $migration['name'], $migration['checksum'], $elapsedMs]);
                $appliedNow[] = ['version' => $migration['version'], 'name' => $migration['name'], 'execution_ms' => $elapsedMs];
            }
            $final = $this->status();
            if ($final['pending'] !== [] || $final['checksum_errors'] !== []) {
                throw new RuntimeException('Schema verification failed after migrations.');
            }
            return ['applied_now' => $appliedNow, 'status' => $final];
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote(self::LOCK_NAME) . ')');
        }
    }

    public function verifyChecksums(): array
    {
        $status = $this->status();
        if ($status['checksum_errors'] !== []) {
            throw new RuntimeException('Migration checksum/version mismatch detected.');
        }
        return $status;
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-z_]+$/D', $table)) {
            throw new InvalidArgumentException('Invalid table identifier.');
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }
}
