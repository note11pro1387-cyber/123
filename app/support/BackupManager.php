<?php
declare(strict_types=1);

/** Logical, compressed NDJSON backup. Includes table data, not DDL; target schema must match migrations. */
final class BackupManager
{
    public function __construct(private PDO $pdo, private string $migrationDirectory)
    {
    }

    public function create(string $backupDirectory, string $databaseName): array
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $databaseName)) {
            throw new RuntimeException('Database name is not safe to use in a backup name.');
        }
        PrivateStorage::assertOutsideProjectRoot($backupDirectory);
        $selectedDatabase = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($selectedDatabase === '' || !hash_equals($selectedDatabase, $databaseName)) {
            throw new RuntimeException('Backup database identity does not match the active PDO connection.');
        }
        $runner = new MigrationRunner($this->pdo, $this->migrationDirectory);
        $status = $runner->verifyChecksums();
        if (!$status['schema_exists'] || $status['pending'] !== [] || $status['checksum_errors'] !== [] || $status['current_version'] === null) {
            throw new RuntimeException('Backup blocked: expected schema is not fully migrated and checksum-verified.');
        }
        $this->assertTablesInnoDb();
        $migrationRows = $this->pdo->query('SELECT version, migration_name, checksum, applied_at FROM schema_migrations ORDER BY version')->fetchAll();
        $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(5));
        $filename = 'mrneon-db-' . $databaseName . '-' . $id . '.ndjson.gz';
        $finalPath = rtrim($backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $tempPath = $finalPath . '.tmp';
        $sidecarPath = $finalPath . '.sha256';
        if (file_exists($tempPath) || file_exists($finalPath) || file_exists($sidecarPath)) {
            throw new RuntimeException('A backup output path already exists; refusing to overwrite it.');
        }
        // Reserve the random path with exclusive creation, then attach the gzip writer.
        $reservation = @fopen($tempPath, 'xb');
        if ($reservation === false) throw new RuntimeException('Backup output file could not be created without overwrite.');
        if (!fclose($reservation)) { @unlink($tempPath); throw new RuntimeException('Backup output reservation could not be closed.'); }
        $handle = @gzopen($tempPath, 'wb6');
        if ($handle === false) { @unlink($tempPath); throw new RuntimeException('Backup compression writer could not be opened.'); }
        $counts = array_fill_keys(SchemaCatalog::tables(), 0);
        $committed = false;
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($tempPath, 0600)) throw new RuntimeException('Backup temporary file permissions could not be restricted.');
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $header = [
                'type' => 'header',
                'format' => 'mrneon-logical-db-backup',
                'format_version' => 1,
                'source_database' => $databaseName,
                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'schema_version' => $status['current_version'],
                'schema_migrations' => array_map(static fn(array $row): array => [
                    'version' => (string) $row['version'],
                    'migration_name' => (string) $row['migration_name'],
                    'checksum' => (string) $row['checksum'],
                ], $migrationRows),
                'tables' => SchemaCatalog::tables(),
            ];
            $this->writeJsonLine($handle, $header);
            foreach (SchemaCatalog::tables() as $table) {
                $pk = SchemaCatalog::primaryKeys()[$table];
                $sql = 'SELECT * FROM `' . $table . '` ORDER BY `' . $pk . '`';
                $statement = $this->pdo->query($sql);
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $this->writeJsonLine($handle, ['type' => 'row', 'table' => $table, 'data' => $row]);
                    $counts[$table]++;
                }
            }
            $this->writeJsonLine($handle, ['type' => 'end', 'counts' => $counts]);
            if (!gzclose($handle)) throw new RuntimeException('Backup compression stream could not be finalized.');
            $handle = null;
            if ($this->pdo->inTransaction()) $this->pdo->commit();
            $committed = true;
            if (!is_file($tempPath) || (filesize($tempPath) ?: 0) < 64) {
                throw new RuntimeException('Backup output is incomplete.');
            }
            if (!@rename($tempPath, $finalPath)) throw new RuntimeException('Completed backup could not be finalized.');
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($finalPath, 0600)) throw new RuntimeException('Backup file permissions could not be restricted.');
            $checksum = hash_file('sha256', $finalPath);
            if (!is_string($checksum)) throw new RuntimeException('Backup checksum could not be calculated.');
            $sidecar = @fopen($sidecarPath, 'xb');
            if ($sidecar === false) throw new RuntimeException('Backup checksum sidecar could not be created.');
            $sidecarLine = $checksum . '  ' . basename($finalPath) . PHP_EOL;
            $sidecarBytes = fwrite($sidecar, $sidecarLine);
            $sidecarFlushed = fflush($sidecar);
            $sidecarStat = fstat($sidecar);
            $sidecarClosed = fclose($sidecar);
            if ($sidecarBytes !== strlen($sidecarLine) || !$sidecarFlushed || !is_array($sidecarStat) || (int) ($sidecarStat['size'] ?? -1) !== strlen($sidecarLine) || !$sidecarClosed) {
                throw new RuntimeException('Backup checksum sidecar write was incomplete.');
            }
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($sidecarPath, 0600)) throw new RuntimeException('Backup checksum permissions could not be restricted.');
            return [
                'status' => 'PASS', 'file' => basename($finalPath), 'sidecar' => basename($sidecarPath),
                'bytes' => filesize($finalPath) ?: 0, 'sha256' => $checksum, 'row_counts' => $counts,
                'schema_version' => $status['current_version'], 'database' => $databaseName,
                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'path' => $finalPath,
            ];
        } catch (Throwable $exception) {
            if (is_resource($handle)) @gzclose($handle);
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            @unlink($tempPath);
            @unlink($sidecarPath);
            if ($committed && is_file($finalPath)) @unlink($finalPath);
            throw $exception;
        }
    }

    /** Restore is intentionally restrictive: target must be explicit, schema-matched and data-empty. */
    public function restore(string $backupPath, string $targetDatabase, bool $allowEmptyTarget, bool $allowNonTestTarget = false): array
    {
        if (!$allowEmptyTarget) throw new RuntimeException('Restore requires explicit --allow-empty-target.');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $targetDatabase)) throw new RuntimeException('Target database name is invalid.');
        $configuredName = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($configuredName === '' || !hash_equals($configuredName, $targetDatabase)) {
            throw new RuntimeException('--target-db must exactly match MRNEON_DB_NAME for the current PDO connection.');
        }
        if (!$allowNonTestTarget && !preg_match('/(^|_)(test|restore|sandbox)(_|$)/i', $targetDatabase)) {
            throw new RuntimeException('Restore target must be clearly named as test/restore/sandbox; use --allow-non-test-target only after explicit owner review.');
        }
        $backupPath = PrivateStorage::resolveExistingPrivateFile($backupPath);
        if (!str_ends_with($backupPath, '.ndjson.gz')) throw new RuntimeException('Only MR NEON .ndjson.gz backup files are accepted.');
        $sidecarPath = $backupPath . '.sha256';
        if (!is_file($sidecarPath) || !is_readable($sidecarPath)) throw new RuntimeException('Backup checksum sidecar is missing.');
        $sidecar = trim((string) file_get_contents($sidecarPath));
        if (!preg_match('/^([a-f0-9]{64})  ' . preg_quote(basename($backupPath), '/') . '$/D', $sidecar, $match)) {
            throw new RuntimeException('Backup checksum sidecar is malformed.');
        }
        $actualChecksum = hash_file('sha256', $backupPath);
        if (!is_string($actualChecksum) || !hash_equals($match[1], $actualChecksum)) {
            throw new RuntimeException('Backup checksum verification failed.');
        }

        $handle = @gzopen($backupPath, 'rb');
        if ($handle === false) throw new RuntimeException('Backup gzip stream cannot be opened.');
        $headerLine = gzgets($handle);
        $header = is_string($headerLine) ? json_decode($headerLine, true) : null;
        if (!is_array($header) || ($header['type'] ?? null) !== 'header' || ($header['format'] ?? null) !== 'mrneon-logical-db-backup' || ($header['format_version'] ?? null) !== 1) {
            gzclose($handle);
            throw new RuntimeException('Backup header or format version is invalid.');
        }
        if (($header['tables'] ?? null) !== SchemaCatalog::tables()) {
            gzclose($handle);
            throw new RuntimeException('Backup table catalog does not match this application version.');
        }
        $runner = new MigrationRunner($this->pdo, $this->migrationDirectory);
        $schema = $runner->verifyChecksums();
        if (!$schema['schema_exists'] || $schema['pending'] !== [] || $schema['checksum_errors'] !== [] || ($header['schema_version'] ?? null) !== $schema['current_version']) {
            gzclose($handle);
            throw new RuntimeException('Backup schema version/checksum does not match the target database.');
        }
        $currentMigrations = $this->pdo->query('SELECT version, migration_name, checksum FROM schema_migrations ORDER BY version')->fetchAll();
        $normalizedMigrations = array_map(static fn(array $row): array => ['version' => (string) $row['version'], 'migration_name' => (string) $row['migration_name'], 'checksum' => (string) $row['checksum']], $currentMigrations);
        if (($header['schema_migrations'] ?? null) !== $normalizedMigrations) {
            gzclose($handle);
            throw new RuntimeException('Backup migration ledger differs from target migration ledger.');
        }
        $this->assertTablesInnoDb();
        foreach (SchemaCatalog::dataTablesInRestoreOrder() as $table) {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            if ($count !== 0) {
                gzclose($handle);
                throw new RuntimeException('Restore target is not empty; no rows were changed.');
            }
        }

        // CHECK TABLE is an administrative statement that implicitly commits in MariaDB.
        // Run physical table checks before the restore transaction, while the target is still empty.
        $preRestoreIntegrity = (new IntegrityChecker($this->pdo, $this->migrationDirectory))->run();
        if ($preRestoreIntegrity['status'] !== 'PASS') {
            gzclose($handle);
            throw new RuntimeException('Restore target failed pre-restore schema and native integrity checks.');
        }

        $expectedCounts = array_fill_keys(SchemaCatalog::tables(), 0);
        $observedCounts = array_fill_keys(SchemaCatalog::tables(), 0);
        $lastOrder = -1;
        $tableOrder = array_flip(SchemaCatalog::tables());
        $columnsCache = [];
        $inserted = 0;
        try {
            $this->pdo->beginTransaction();
            while (($line = gzgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($record) || !isset($record['type'])) throw new RuntimeException('Backup contains a malformed record.');
                if ($record['type'] === 'end') {
                    $endCounts = $record['counts'] ?? null;
                    if (!is_array($endCounts) || $endCounts !== $observedCounts) throw new RuntimeException('Backup end counts do not match the rows read.');
                    $expectedCounts = $endCounts;
                    if (gzgets($handle) !== false) throw new RuntimeException('Unexpected content follows the backup end record.');
                    break;
                }
                if ($record['type'] !== 'row' || !is_string($record['table'] ?? null) || !in_array($record['table'], SchemaCatalog::tables(), true) || !is_array($record['data'] ?? null) || array_is_list($record['data'])) {
                    throw new RuntimeException('Backup row record structure is invalid.');
                }
                $table = $record['table'];
                $order = $tableOrder[$table];
                if ($order < $lastOrder) throw new RuntimeException('Backup tables are not in dependency-safe order.');
                $lastOrder = $order;
                $observedCounts[$table]++;
                if ($table === 'schema_migrations') continue; // target ledger is already checksum-identical.
                if (!isset($columnsCache[$table])) {
                    $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
                    $columnsCache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
                }
                $row = $record['data'];
                if (!array_key_exists(SchemaCatalog::primaryKeys()[$table], $row) || $row === []) throw new RuntimeException('Backup row is missing its primary key.');
                foreach ($row as $column => $value) {
                    if (!is_string($column) || !in_array($column, $columnsCache[$table], true) || !(is_scalar($value) || $value === null)) {
                        throw new RuntimeException('Backup row has an unknown column or unsupported value shape.');
                    }
                }
                $columns = array_keys($row);
                $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insert = $this->pdo->prepare('INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')');
                $insert->execute(array_values($row));
                $inserted++;
            }
            if (!isset($endCounts)) throw new RuntimeException('Backup end marker is missing.');
            foreach (SchemaCatalog::dataTablesInRestoreOrder() as $table) {
                $actual = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
                if ($actual !== (int) ($expectedCounts[$table] ?? -1)) throw new RuntimeException('Restored row count mismatch in table ' . $table . '.');
            }
            // Keep relational, uniqueness, row-count and schema checks inside the transaction.
            // Native CHECK TABLE was already run before BEGIN because MariaDB implicitly commits it.
            $integrity = (new IntegrityChecker($this->pdo, $this->migrationDirectory))->run(false);
            if ($integrity['status'] !== 'PASS') throw new RuntimeException('Post-restore transactional integrity checks failed; restore transaction rolled back.');
            $this->pdo->commit();
            gzclose($handle);
            return [
                'status' => 'PASS', 'target_database' => $targetDatabase, 'source_database' => (string) ($header['source_database'] ?? '(unknown)'),
                'schema_version' => $schema['current_version'], 'rows_inserted' => $inserted,
                'row_counts' => $expectedCounts, 'integrity_status' => 'PASS', 'checksum_status' => 'PASS',
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            gzclose($handle);
            throw $exception;
        }
    }

    private function assertTablesInnoDb(): void
    {
        $statement = $this->pdo->query('SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE()');
        $engines = [];
        foreach ($statement->fetchAll() as $row) $engines[(string) $row['table_name']] = (string) ($row['engine'] ?? '');
        foreach (SchemaCatalog::tables() as $table) {
            if (!isset($engines[$table])) throw new RuntimeException('Required schema table is missing: ' . $table . '.');
            if (strcasecmp($engines[$table], 'InnoDB') !== 0) throw new RuntimeException('All application tables must use InnoDB for consistent backup/transactions.');
        }
    }

    /** @param resource $handle */
    private function writeJsonLine($handle, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $written = gzwrite($handle, $line);
        if ($written !== strlen($line)) throw new RuntimeException('Backup write was incomplete.');
    }
}
