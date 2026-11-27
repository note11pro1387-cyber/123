<?php
declare(strict_types=1);

final class IntegrityChecker
{
    public function __construct(private PDO $pdo, private string $migrationDirectory)
    {
    }

    public function run(bool $includeNativeTableChecks = true): array
    {
        $report = [
            'engine_version' => null,
            'schema' => ['exists' => false, 'current_version' => null, 'expected_version' => null, 'pending' => [], 'checksum_errors' => []],
            'tables' => [],
            'indexes' => [],
            'native_table_checks' => [],
            'foreign_key_orphans' => [],
            'logical_duplicates' => [],
            'row_counts' => [],
            'non_innodb_tables' => [],
            'failures' => [],
            'status' => 'FAIL',
        ];
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            $report['failures'][] = 'UNSUPPORTED_PDO_DRIVER';
            return $report;
        }
        $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();
        $report['engine_version'] = is_string($version) ? preg_replace('/[^A-Za-z0-9 ._+()-]/', '', $version) : null;
        $runner = new MigrationRunner($this->pdo, $this->migrationDirectory);
        try {
            $migrationStatus = $runner->status();
            $expectedMigrations = $runner->discover();
            $lastExpectedMigration = $expectedMigrations === [] ? null : $expectedMigrations[count($expectedMigrations) - 1];
            $expectedVersion = is_array($lastExpectedMigration) ? $lastExpectedMigration['version'] : null;
            $report['schema'] = [
                'exists' => $migrationStatus['schema_exists'],
                'current_version' => $migrationStatus['current_version'],
                'expected_version' => $expectedVersion,
                'pending' => array_column($migrationStatus['pending'], 'version'),
                'checksum_errors' => $migrationStatus['checksum_errors'],
            ];
            if (!$migrationStatus['schema_exists'] || $migrationStatus['pending'] !== [] || $migrationStatus['checksum_errors'] !== [] || $migrationStatus['current_version'] !== $expectedVersion) {
                $report['failures'][] = 'SCHEMA_VERSION_OR_CHECKSUM_MISMATCH';
            }
        } catch (Throwable) {
            $report['failures'][] = 'MIGRATION_METADATA_UNREADABLE';
            return $report;
        }

        $expectedTables = SchemaCatalog::tables();
        $tableStatement = $this->pdo->prepare('SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE()');
        $tableStatement->execute();
        $tableRows = $tableStatement->fetchAll();
        $present = [];
        foreach ($tableRows as $row) {
            $present[(string) $row['table_name']] = (string) ($row['engine'] ?? '');
        }
        foreach ($expectedTables as $table) {
            if (!isset($present[$table])) {
                $report['tables'][$table] = 'MISSING';
                $report['failures'][] = 'MISSING_TABLE:' . $table;
                continue;
            }
            $report['tables'][$table] = 'PRESENT';
            if (strcasecmp($present[$table], 'InnoDB') !== 0) $report['non_innodb_tables'][] = $table;
            $report['row_counts'][$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        if ($report['non_innodb_tables'] !== []) $report['failures'][] = 'NON_INNODB_TABLES_PRESENT';
        if (in_array('MISSING_TABLE:' . 'schema_migrations', $report['failures'], true) || count(array_filter($report['tables'], static fn(string $state): bool => $state === 'MISSING')) > 0) {
            $report['status'] = 'FAIL';
            $report['failures'] = array_values(array_unique($report['failures']));
            return $report;
        }

        $expectedIndexes = [
            'schema_migrations' => ['PRIMARY', 'uq_schema_migrations_name'],
            'users' => ['PRIMARY', 'uq_users_public_id', 'uq_users_legacy_id', 'uq_users_username_normalized', 'uq_users_phone_normalized', 'ix_users_status_created', 'ix_users_created'],
            'user_channels' => ['PRIMARY', 'uq_user_channels_type_value', 'uq_user_channels_binding', 'uq_user_channels_provider_subject', 'ix_user_channels_user_type'],
            'registration_attempts' => ['PRIMARY', 'ix_registration_attempts_expiry', 'ix_registration_attempts_username', 'ix_registration_attempts_phone'],
            'verification_challenges' => ['PRIMARY', 'uq_challenges_recovery_token_hash', 'ix_challenges_active_subject', 'ix_challenges_destination'],
            'auth_sessions' => ['PRIMARY', 'ix_auth_sessions_principal', 'ix_auth_sessions_user'],
            'projects' => ['PRIMARY', 'uq_projects_public_id', 'uq_projects_tracking_code', 'ix_projects_owner_created', 'ix_projects_status_created', 'ix_projects_owner_status'],
            'project_milestones' => ['PRIMARY', 'uq_project_milestones_code', 'ix_project_milestones_status'],
            'project_events' => ['PRIMARY', 'ix_project_events_timeline', 'ix_project_events_actor'],
            'project_messages' => ['PRIMARY', 'ix_project_messages_timeline', 'ix_project_messages_unread'],
            'internal_notes' => ['PRIMARY', 'ix_internal_notes_project_timeline', 'ix_internal_notes_admin'],
            'notifications' => ['PRIMARY', 'uq_notifications_public_id', 'ix_notifications_user_inbox', 'ix_notifications_admin_inbox', 'ix_notifications_project'],
            'outbox_events' => ['PRIMARY', 'uq_outbox_public_id', 'uq_outbox_idempotency', 'ix_outbox_due', 'ix_outbox_aggregate'],
            'idempotency_keys' => ['PRIMARY', 'uq_idempotency_scope_route_key', 'ix_idempotency_expiry'],
            'audit_logs' => ['PRIMARY', 'ix_audit_actor_timeline', 'ix_audit_subject_timeline', 'ix_audit_action_timeline', 'ix_audit_request_id'],
            'rate_limit_buckets' => ['PRIMARY', 'ix_rate_limit_reset', 'ix_rate_limit_blocked'],
        ];
        foreach ($expectedIndexes as $table => $required) {
            if (!isset($present[$table])) continue;
            $indexStmt = $this->pdo->prepare('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?');
            $indexStmt->execute([$table]);
            $found = $indexStmt->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_values(array_diff($required, array_map('strval', $found)));
            $report['indexes'][$table] = ['required' => $required, 'missing' => $missing];
            if ($missing !== []) $report['failures'][] = 'MISSING_INDEXES:' . $table;
        }
        if ($includeNativeTableChecks) {
            // MariaDB CHECK TABLE implicitly commits an active transaction. Callers that
            // need atomic data writes must run this physical check before beginning them.
            foreach ($expectedTables as $table) {
                if (!isset($present[$table])) continue;
                try {
                    $checkRows = $this->pdo->query('CHECK TABLE `' . $table . '`')->fetchAll();
                    $messages = [];
                    $ok = $checkRows !== [];
                    foreach ($checkRows as $checkRow) {
                        $type = strtolower((string) ($checkRow['Msg_type'] ?? ''));
                        $message = (string) ($checkRow['Msg_text'] ?? '');
                        $messages[] = ['type' => $type, 'message' => preg_replace('/[^A-Za-z0-9 _.,:+()-]/', '', $message)];
                        if ($type === 'error' || $type === 'warning' || ($type === 'status' && strtoupper($message) !== 'OK')) $ok = false;
                    }
                    $report['native_table_checks'][$table] = ['status' => $ok ? 'PASS' : 'FAIL', 'messages' => $messages];
                    if (!$ok) $report['failures'][] = 'NATIVE_TABLE_CHECK_FAILED:' . $table;
                } catch (Throwable) {
                    $report['native_table_checks'][$table] = ['status' => 'NOT_SUPPORTED_OR_FAILED', 'messages' => []];
                    $report['failures'][] = 'NATIVE_TABLE_CHECK_UNAVAILABLE:' . $table;
                }
            }
        } else {
            foreach ($expectedTables as $table) {
                if (isset($present[$table])) {
                    $report['native_table_checks'][$table] = ['status' => 'SKIPPED_TRANSACTION_SAFE_RESTORE', 'messages' => []];
                }
            }
        }

        $orphanQueries = [
            'user_channels_user' => 'SELECT COUNT(*) FROM user_channels c LEFT JOIN users u ON u.id = c.user_id WHERE u.id IS NULL',
            'registration_attempts_created_user' => 'SELECT COUNT(*) FROM registration_attempts r LEFT JOIN users u ON u.id = r.created_user_id WHERE r.created_user_id IS NOT NULL AND u.id IS NULL',
            'verification_challenges_user' => 'SELECT COUNT(*) FROM verification_challenges c LEFT JOIN users u ON u.id = c.user_id WHERE c.user_id IS NOT NULL AND u.id IS NULL',
            'verification_challenges_attempt' => 'SELECT COUNT(*) FROM verification_challenges c LEFT JOIN registration_attempts r ON r.id = c.registration_attempt_id WHERE c.registration_attempt_id IS NOT NULL AND r.id IS NULL',
            'verification_challenges_channel' => 'SELECT COUNT(*) FROM verification_challenges c LEFT JOIN user_channels u ON u.id = c.user_channel_id WHERE c.user_channel_id IS NOT NULL AND u.id IS NULL',
            'auth_sessions_user' => 'SELECT COUNT(*) FROM auth_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE s.user_id IS NOT NULL AND u.id IS NULL',
            'projects_owner' => 'SELECT COUNT(*) FROM projects p LEFT JOIN users u ON u.id = p.owner_user_id WHERE u.id IS NULL',
            'project_milestones_project' => 'SELECT COUNT(*) FROM project_milestones m LEFT JOIN projects p ON p.id = m.project_id WHERE p.id IS NULL',
            'project_events_project' => 'SELECT COUNT(*) FROM project_events e LEFT JOIN projects p ON p.id = e.project_id WHERE p.id IS NULL',
            'project_messages_project' => 'SELECT COUNT(*) FROM project_messages m LEFT JOIN projects p ON p.id = m.project_id WHERE p.id IS NULL',
            'internal_notes_project' => 'SELECT COUNT(*) FROM internal_notes n LEFT JOIN projects p ON p.id = n.project_id WHERE p.id IS NULL',
            'notifications_user' => 'SELECT COUNT(*) FROM notifications n LEFT JOIN users u ON u.id = n.recipient_user_id WHERE n.recipient_user_id IS NOT NULL AND u.id IS NULL',
            'notifications_project' => 'SELECT COUNT(*) FROM notifications n LEFT JOIN projects p ON p.id = n.project_id WHERE n.project_id IS NOT NULL AND p.id IS NULL',
        ];
        foreach ($orphanQueries as $name => $sql) {
            try {
                $count = (int) $this->pdo->query($sql)->fetchColumn();
                $report['foreign_key_orphans'][$name] = $count;
                if ($count !== 0) $report['failures'][] = 'ORPHAN_REFERENCE:' . $name;
            } catch (Throwable) {
                $report['foreign_key_orphans'][$name] = null;
                $report['failures'][] = 'ORPHAN_CHECK_FAILED:' . $name;
            }
        }
        $duplicateQueries = [
            'users_username' => 'SELECT COUNT(*) FROM (SELECT username_normalized FROM users GROUP BY username_normalized HAVING COUNT(*) > 1) d',
            'users_phone' => 'SELECT COUNT(*) FROM (SELECT phone_normalized FROM users GROUP BY phone_normalized HAVING COUNT(*) > 1) d',
            'user_channels_value' => 'SELECT COUNT(*) FROM (SELECT channel_type, normalized_value FROM user_channels GROUP BY channel_type, normalized_value HAVING COUNT(*) > 1) d',
            'projects_public_id' => 'SELECT COUNT(*) FROM (SELECT public_id FROM projects GROUP BY public_id HAVING COUNT(*) > 1) d',
            'projects_tracking_code' => 'SELECT COUNT(*) FROM (SELECT tracking_code FROM projects GROUP BY tracking_code HAVING COUNT(*) > 1) d',
        ];
        foreach ($duplicateQueries as $name => $sql) {
            $count = (int) $this->pdo->query($sql)->fetchColumn();
            $report['logical_duplicates'][$name] = $count;
            if ($count !== 0) $report['failures'][] = 'LOGICAL_DUPLICATE:' . $name;
        }
        $report['status'] = $report['failures'] === [] ? 'PASS' : 'FAIL';
        $report['failures'] = array_values(array_unique($report['failures']));
        return $report;
    }
}
