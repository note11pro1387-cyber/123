# MR NEON — Database Operations (Wave 2)

## Environment contract

PHP does not load `.env.example` automatically. Configure the variables in the current shell/process or a private Apache/OS environment configuration outside the project web root. Never put real values in `config.php`, a tracked file, a browser asset or a command pasted into public logs.

Required variables:

- `MRNEON_DB_HOST`, `MRNEON_DB_PORT`, `MRNEON_DB_NAME`, `MRNEON_DB_USER`, `MRNEON_DB_PASSWORD`
- Optional: `MRNEON_DB_CHARSET=utf8mb4`, `MRNEON_DB_CONNECT_TIMEOUT=5`
- Remote databases require `MRNEON_DB_SSL_CA` pointing to a readable CA file and verified server certificate; `MRNEON_DB_REQUIRE_TLS=1` can also force TLS for a local tunnel.
- Empty passwords are rejected unless `MRNEON_DB_ALLOW_EMPTY_PASSWORD=1`, and even then the host must be loopback (`localhost`, `127.0.0.1`, or `::1`). This is only a local development accommodation, not a Production recommendation.
- `MRNEON_RATE_LIMIT_HMAC_KEY` must be a privately generated value with at least 32 bytes for keyed hashing of rate-limit identities. Generate privately with a cryptographic random generator and do not put the generated value in a report or repository.
- `MRNEON_BACKUP_DIR` is an absolute private path outside the project directory/document-root candidate.

## Version requirements

The conservative compatibility target is MariaDB 10.6+ or MySQL 8.0.16+ due enforced CHECK constraints. This is not confirmation of the installed XAMPP bundle or eventual Production. The actual server and PDO driver version must be recorded during Preflight.

## CLI commands

Run all commands from the project root in a local development copy:

```powershell
php bin/migrate.php status
php bin/migrate.php check
php bin/migrate.php apply
php bin/integrity-check.php
php bin/migrate-json.php --source="C:\private\mrneon-snapshot\data"
php bin/backup-db.php
```

`status`, `check` and `integrity-check` are read-only. `apply` modifies the configured database schema; inspect the configured DB identity before running it. The JSON importer is dry-run by default and only supports persistent user records. Do not use it against Production or a non-test database in Wave 2.

## Migration behavior

- Files `database/migrations/001_*.sql` through the current contiguous version (`018_*.sql` in this baseline) are ordered one-table DDL migrations. Every run records version/name/exact-file checksum and timing in `schema_migrations`.
- Named MySQL lock serializes runner instances; each file checksum and version gap are checked.
- Running `apply` twice after a clean success should not re-run recorded migrations. `check` fails on missing/pending migrations or checksum drift.
- MariaDB/MySQL DDL may auto-commit. If DDL succeeds but its ledger write fails, the runner stops and reports an unrecorded table; it does not claim that DDL was rolled back. Review the target schema manually before any retry.
- The migration runner has a CLI guard and protected Apache directory rules. Those rules are defense in depth; the selected Production web-server must deny direct access to `database/`, `bin/`, `tests/`, `docs/`, `.env*`, data, logs and backups.

## Data and cutover

The application continues to use the current JSON consumers unless a later approved Wave changes them. No dual-write and no silent DB-to-JSON fallback are permitted. Wave 2 creates an independent schema and import tool; it does not redirect `api.php` to DB. Cutover requires Wave 3-5 integration, verified backup/restore, record-count/key reconciliation, Regression tests and explicit approval.

## Idempotency record retention (W5-P2-P5)

The `idempotency_keys` table is shared by project creation, customer messaging and Telegram update deduplication. Each caller assigns its own retention window in `expires_at` (24 hours for project creation/customer messages; 30 days for Telegram updates in the current source). Runtime reads only treat records with `expires_at > UTC_TIMESTAMP(6)` as replayable. A request may reclaim only an expired `completed`/`failed` record for the exact scoped key. Expired `processing` rows are deliberately preserved and must be investigated before any manual action; age alone is not proof that a side effect did not occur.

`bin/cleanup-idempotency-keys.php` is a maintenance command, not a test. It is non-persistent/read-only by default and reports only aggregate counts. As with the existing `Database::connection()` factory, opening the PDO connection initializes that connection session timezone to UTC; the dry-run does not change application rows or schema. Apply mode deletes at most one batch of 1–500 expired `completed`/`failed` rows and never deletes `processing` rows. Apply requires `MRNEON_ALLOW_IDEMPOTENCY_CLEANUP=1`, `MRNEON_IDEMPOTENCY_CLEANUP_QA_APPROVED=1` (set only after real final QA and separate owner approval), an explicit `MRNEON_ENV` value (`local`, `test`, `staging` or `production`), and `--confirm-db=<exact-configured-database-name>`; when `MRNEON_ENV=production`, a separate `MRNEON_ALLOW_PRODUCTION_IDEMPOTENCY_CLEANUP=1` gate is also mandatory; the database name is compared internally and is not printed by the command. `.env.example` is documentation only and is not automatically loaded by PHP.

Do not run this command against any database before owner authorization, confirmed non-production isolation and the final QA gate. No cron, Task Scheduler job or production schedule is configured by this source change. A future operator may first use the default dry-run mode against the approved isolated database, review counts, and then request separate authorization before any `--apply` call. Production cleanup additionally requires the dedicated production gate for that single invocation. If expired `processing` rows are reported, do not delete them as part of routine cleanup; investigate the transaction history and underlying business mutation first.
