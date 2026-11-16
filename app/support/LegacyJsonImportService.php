<?php
declare(strict_types=1);

final class LegacyJsonImportService
{
    public function __construct(private ?PDO $pdo, private string $sourceDirectory)
    {
    }

    /** Produces a report and optionally imports only persistent users. Never changes the source files. */
    public function run(bool $apply, bool $allowPartialSource, bool $allowLegacyAccountsPending = false): array
    {
        $inspector = new LegacyJsonSourceInspector($this->sourceDirectory);
        $inventory = $inspector->inspect();
        $usersPath = rtrim($this->sourceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'users.json';
        if (!is_file($usersPath)) {
            return $this->baseReport($inventory, 'BLOCKED_SOURCE_MISSING', 'users.json is required for the supported user import.', $apply);
        }
        $raw = file_get_contents($usersPath);
        if (!is_string($raw)) {
            return $this->baseReport($inventory, 'BLOCKED_SOURCE_READ', 'users.json could not be read.', $apply);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->baseReport($inventory, 'BLOCKED_INVALID_JSON', 'users.json is malformed; no destination writes were attempted.', $apply);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return $this->baseReport($inventory, 'BLOCKED_INVALID_ROOT', 'users.json must contain a JSON list of account records.', $apply);
        }

        $mapper = new LegacyUserMapper();
        $plans = [];
        $invalid = [];
        $duplicates = [];
        $seenLegacyIds = $seenUsernames = $seenPhones = $seenEmails = [];
        $unknownFields = [];
        foreach ($decoded as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                $invalid[] = ['record_index' => $index, 'errors' => ['record_not_object']];
                continue;
            }
            $result = $mapper->map($row);
            foreach ($result['unknown_fields'] as $field) $unknownFields[$field] = true;
            if ($result['mapped'] === null) {
                $invalid[] = ['record_index' => $index, 'errors' => $result['errors']];
                continue;
            }
            $record = $result['mapped'];
            $duplicateFields = [];
            foreach ([
                'legacy_id' => $record['legacy_id'],
                'username_normalized' => $record['username_normalized'],
                'phone_normalized' => $record['phone_normalized'],
            ] as $field => $value) {
                if (isset(($field === 'legacy_id' ? $seenLegacyIds : ($field === 'username_normalized' ? $seenUsernames : $seenPhones))[$value])) {
                    $duplicateFields[] = $field;
                }
            }
            if ($record['email_normalized'] !== null && isset($seenEmails[$record['email_normalized']])) $duplicateFields[] = 'email_normalized';
            if ($duplicateFields !== []) {
                $duplicates[] = ['record_index' => $index, 'fields' => array_values(array_unique($duplicateFields))];
                continue;
            }
            $seenLegacyIds[$record['legacy_id']] = true;
            $seenUsernames[$record['username_normalized']] = true;
            $seenPhones[$record['phone_normalized']] = true;
            if ($record['email_normalized'] !== null) $seenEmails[$record['email_normalized']] = true;
            $plans[] = ['record_index' => $index, 'mapped' => $record];
        }
        $unknownNames = array_keys($unknownFields);
        sort($unknownNames, SORT_STRING);
        $missingSourceCount = count($inventory['missing_sources']);
        $report = [
            'report_version' => 1,
            'run_id' => bin2hex(random_bytes(8)),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'source_directory_basename' => $inventory['source_directory_basename'],
            'source_inventory' => $inventory,
            'supported_import' => 'users.json -> users + unverified email user_channels; no JSON cutover',
            'legacy_account_policy' => 'PROPOSED_PENDING_UNTIL_REVERIFIED',
            'legacy_account_policy_acknowledged' => $allowLegacyAccountsPending,
            'source_counts' => ['user_records' => count($decoded), 'valid' => count($plans), 'invalid' => count($invalid), 'duplicate_in_source' => count($duplicates)],
            'invalid_records' => $invalid,
            'duplicate_records' => $duplicates,
            'unmapped_fields_preserved_in_users_legacy_extra_json' => $unknownNames,
            'target' => [
                'engine' => 'MariaDB/MySQL via PDO',
                'database' => $this->safeDatabaseName(),
                'counts_before' => null,
                'counts_after' => null,
                'status' => $apply ? 'NOT_CHECKED' : 'NOT_CHECKED_DRY_RUN',
            ],
            'destination_counts' => ['inserted_users' => 0, 'inserted_channels' => 0, 'already_imported_identical' => 0, 'conflicts' => 0, 'skipped_security_sensitive_sources' => count(LegacyJsonSourceInspector::EPHEMERAL_SOURCES)],
            'missing_source_status' => array_map(static fn(array $item): array => ['file' => $item['file'], 'status' => $item['status']], $inventory['missing_sources']),
            'cutover' => 'NOT_PERFORMED_JSON_REMAINS_SOURCE_FOR_CURRENT_CONSUMERS',
            'status' => 'DRY_RUN_READY',
            'message' => 'Dry-run is read-only; no JSON source is changed. Missing project/notification/reset sources remain missing.',
        ];
        if ($invalid !== [] || $duplicates !== []) {
            $report['status'] = 'BLOCKED_SOURCE_DATA_QUALITY';
            $report['message'] = 'Invalid or duplicate user records were found. No source changes or imports are performed until conflicts are resolved.';
            if ($apply) return $report;
        }
        if ($apply && !$allowLegacyAccountsPending) {
            $report['status'] = 'BLOCKED_LEGACY_ACCOUNT_POLICY_ACK_REQUIRED';
            $report['message'] = 'Apply requires explicit --allow-legacy-accounts-pending acknowledgement; legacy verified flags do not activate accounts.';
            return $report;
        }
        if ($apply && $missingSourceCount > 0 && !$allowPartialSource) {
            $report['status'] = 'BLOCKED_PARTIAL_SOURCE_ACK_REQUIRED';
            $report['message'] = 'Some persistent datasets are missing. Re-run with --allow-partial-source only if an explicitly partial users-only import is intended.';
            return $report;
        }
        if (!$apply) {
            $report['message'] = 'Source-only dry-run completed. Target counts require a configured reachable database; no writes were attempted.';
            return $report;
        }

        if (!$this->pdo instanceof PDO) {
            $report['status'] = 'BLOCKED_DB_UNAVAILABLE';
            $report['message'] = 'Apply requires a configured MariaDB/MySQL connection and pdo_mysql driver.';
            return $report;
        }
        $status = (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->verifyChecksums();
        if (!$status['schema_exists'] || $status['pending'] !== [] || $status['checksum_errors'] !== [] || $status['current_version'] !== SchemaCatalog::CURRENT_SCHEMA_VERSION) {
            $report['status'] = 'BLOCKED_SCHEMA_NOT_CURRENT';
            $report['message'] = 'The full expected schema must be migrated and checksum-verified before applying import.';
            return $report;
        }

        $userCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $channelCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM user_channels')->fetchColumn();
        $report['target']['counts_before'] = ['users' => $userCountBefore, 'user_channels' => $channelCountBefore];
        $userRepo = new UserRepository($this->pdo);
        $channelRepo = new UserChannelRepository($this->pdo);
        $insertedUsers = $insertedChannels = $alreadyImported = $conflicts = 0;
        try {
            $this->pdo->beginTransaction();
            foreach ($plans as $plan) {
                $user = $plan['mapped'];
                $existing = $this->findLegacyUser($user['legacy_id']);
                if ($existing !== null) {
                    if (is_string($existing['legacy_source_hash']) && hash_equals($existing['legacy_source_hash'], $user['legacy_source_hash'])) {
                        $alreadyImported++;
                        continue;
                    }
                    $conflicts++;
                    throw new RuntimeException('LEGACY_ID_CONFLICT');
                }
                if ($this->findUsername($user['username_normalized']) || $this->findPhone($user['phone_normalized'])) {
                    $conflicts++;
                    throw new RuntimeException('UNIQUE_ACCOUNT_CONFLICT');
                }
                if ($user['email_normalized'] !== null && $this->findEmail($user['email_normalized'])) {
                    $conflicts++;
                    throw new RuntimeException('UNIQUE_EMAIL_CONFLICT');
                }
                $created = $userRepo->create([
                    'public_id' => bin2hex(random_bytes(16)),
                    'legacy_id' => $user['legacy_id'],
                    'legacy_source_hash' => $user['legacy_source_hash'],
                    'full_name' => $user['full_name'],
                    'username' => $user['username'],
                    'phone' => $user['phone'],
                    'password_hash' => $user['password_hash'],
                    'legacy_verified' => $user['legacy_verified'],
                    'legacy_telegram_username' => $user['legacy_telegram_username'],
                    'legacy_extra_json' => $user['legacy_extra_json'],
                    // Conservative: preserve legacy verification as metadata but do not grant an active account automatically.
                    'account_status' => 'pending',
                    'created_at' => $user['created_at'],
                    'last_login_at' => $user['last_login_at'],
                ]);
                $insertedUsers++;
                if ($user['email_value'] !== null) {
                    $channelRepo->create($created['id'], 'email', $user['email_value'], false, null, true);
                    $insertedChannels++;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $report['destination_counts']['inserted_users'] = 0;
            $report['destination_counts']['inserted_channels'] = 0;
            $report['destination_counts']['already_imported_identical'] = 0;
            $report['destination_counts']['conflicts'] = $conflicts;
            $report['target']['counts_after'] = ['users' => $userCountBefore, 'user_channels' => $channelCountBefore];
            $report['status'] = 'IMPORT_ROLLED_BACK';
            $report['message'] = $exception instanceof RuntimeException && preg_match('/^[A-Z_]+$/D', $exception->getMessage())
                ? $exception->getMessage() : 'Import failed; the transaction was rolled back. Review server-side sanitized diagnostics.';
            return $report;
        }

        $userCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $channelCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM user_channels')->fetchColumn();
        $report['target']['counts_after'] = ['users' => $userCountAfter, 'user_channels' => $channelCountAfter];
        $report['target']['status'] = 'QUERIED';
        $report['destination_counts']['inserted_users'] = $insertedUsers;
        $report['destination_counts']['inserted_channels'] = $insertedChannels;
        $report['destination_counts']['already_imported_identical'] = $alreadyImported;
        $report['status'] = ($userCountAfter - $userCountBefore === $insertedUsers && $channelCountAfter - $channelCountBefore === $insertedChannels)
            ? 'APPLIED_PARTIAL_IMPORT' : 'POST_IMPORT_COUNT_MISMATCH';
        $report['message'] = 'Only supported persistent users were imported. Security-transient JSON was not imported; missing sources remain missing; JSON cutover was not performed.';
        return $report;
    }

    private function baseReport(array $inventory, string $status, string $message, bool $apply): array
    {
        return [
            'report_version' => 1,
            'run_id' => bin2hex(random_bytes(8)),
            'mode' => $apply ? 'APPLY' : 'DRY_RUN',
            'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'source_inventory' => $inventory,
            'source_counts' => ['user_records' => null, 'valid' => 0, 'invalid' => 0, 'duplicate_in_source' => 0],
            'destination_counts' => ['inserted_users' => 0, 'inserted_channels' => 0, 'already_imported_identical' => 0, 'conflicts' => 0],
            'target' => ['engine' => 'MariaDB/MySQL via PDO', 'database' => $this->safeDatabaseName(), 'counts_before' => null, 'counts_after' => null, 'status' => 'NOT_CHECKED'],
            'cutover' => 'NOT_PERFORMED_JSON_REMAINS_SOURCE_FOR_CURRENT_CONSUMERS',
            'status' => $status,
            'message' => $message,
        ];
    }

    private function safeDatabaseName(): string
    {
        $config = Database::configuration();
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $config['name']) ? (string) $config['name'] : '(not configured)';
    }

    private function findLegacyUser(string $legacyId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, legacy_source_hash FROM users WHERE legacy_id = ? LIMIT 1');
        $statement->execute([$legacyId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function findUsername(string $username): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE username_normalized = ? LIMIT 1');
        $statement->execute([$username]);
        return $statement->fetchColumn() !== false;
    }

    private function findPhone(string $phone): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE phone_normalized = ? LIMIT 1');
        $statement->execute([$phone]);
        return $statement->fetchColumn() !== false;
    }

    private function findEmail(string $email): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM user_channels WHERE channel_type = 'email' AND normalized_value = ? LIMIT 1");
        $statement->execute([$email]);
        return $statement->fetchColumn() !== false;
    }
}
