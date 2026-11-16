<?php
declare(strict_types=1);

/** Whitelist of application tables and dependency-safe order for validation/backup/restore. */
final class SchemaCatalog
{
    public const CURRENT_SCHEMA_VERSION = '018';
    /** @return list<string> */
    public static function tables(): array
    {
        return [
            'schema_migrations', 'users', 'user_channels', 'registration_attempts',
            'verification_challenges', 'auth_sessions', 'projects', 'project_milestones',
            'project_events', 'project_messages', 'internal_notes', 'notifications',
            'outbox_events', 'idempotency_keys', 'audit_logs', 'rate_limit_buckets',
            'verification_delivery_attempts',
        ];
    }

    /** @return list<string> */
    public static function dataTablesInRestoreOrder(): array
    {
        return array_values(array_filter(self::tables(), static fn(string $name): bool => $name !== 'schema_migrations'));
    }

    /** @return array<string,string> table => primary key column */
    public static function primaryKeys(): array
    {
        return [
            'schema_migrations' => 'version', 'users' => 'id', 'user_channels' => 'id',
            'registration_attempts' => 'id', 'verification_delivery_attempts' => 'challenge_id', 'verification_challenges' => 'id',
            'auth_sessions' => 'session_hash', 'projects' => 'id', 'project_milestones' => 'id',
            'project_events' => 'id', 'project_messages' => 'id', 'internal_notes' => 'id',
            'notifications' => 'id', 'outbox_events' => 'id', 'idempotency_keys' => 'id',
            'audit_logs' => 'id', 'rate_limit_buckets' => 'bucket_key_hash',
        ];
    }
}
