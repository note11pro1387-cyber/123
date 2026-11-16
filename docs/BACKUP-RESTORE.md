# MR NEON — Backup / Restore / Integrity Runbook (Wave 2)

## Scope and format

`backup-db.php` creates a compressed, logical NDJSON export of the Wave 2 allowlisted tables and an adjacent `.sha256` sidecar. It includes data, not DDL or credentials. The schema must already be fully migrated and exact-checksum verified. All tables must use InnoDB for the consistent-snapshot intent to hold. The configured directory must be an absolute path outside the project root/web-root candidate; on Unix-like systems the directory and files are restricted to owner access where supported. Windows ACLs must be reviewed separately.

Backup reports contain a basename, row counts, file size, SHA-256 and schema identity, not a credential or data-row dump. Do not move the generated backup into `data/`, public assets, the source ZIP, an issue, or a public repository.

## Configure a private directory

Set `MRNEON_BACKUP_DIR` to a private absolute path outside the project. Example path only (create it manually with access restricted to the account running PHP):

- Windows development: `C:\MRNEON-Private\database-backups`
- Linux host: `/var/lib/mrneon-private/database-backups`

Do not assume those folders exist or that filesystem ACLs have been verified. The tool creates a missing folder with owner-only mode on Unix-like systems and validates the final path.

## Backup

After the environment and DB identity are reviewed:

```powershell
php bin/migrate.php check
php bin/integrity-check.php
php bin/backup-db.php
```

Require exit code zero and a report with `status: PASS`, a nonzero file size, checksum and row counts. Verify the backup and sidecar both exist in the configured private directory. The CLI omits the full path from output intentionally; resolve the basename inside the private directory.

## Restore test

Restore is deliberately restrictive:

1. Create a **separate empty test/restore/sandbox database** with the same engine/schema version. Never select a database that could contain operational information.
2. Point `MRNEON_DB_NAME` at that target and configure private access.
3. Apply/check all migrations on the target.
4. From a private backup path, invoke:

```powershell
php bin/restore-db.php --file="C:\MRNEON-Private\database-backups\example.ndjson.gz" --target-db=mrneon_restore_test --allow-empty-target
php bin/integrity-check.php
```

The example filename is illustrative, not an existing file. `--target-db` must exactly match the database selected by the current PDO connection. Unless `--allow-non-test-target` is explicitly used after separate owner review, the target database name must contain a bounded `test`, `restore`, or `sandbox` component. The target schema migration ledger must match exactly and all application data tables must be empty. The tool does not truncate or delete rows; a nonempty target is rejected. Restore validates the sidecar checksum, format, table/column allowlist, row counts and integrity before commit.

A restore is considered validated only after a real run to an independent database and successful row-count/integrity comparison. Merely producing a backup file or having the tool present is not proof.

## Failure handling

- Nonzero exit code means the operation failed or was blocked; preserve sanitized diagnostics.
- Do not retry blindly after an interrupted DDL migration. Inspect `schema_migrations` and actual tables first.
- If Restore fails, its transactional inserts should roll back on InnoDB; this does not restore a bad backup source or prove cross-version compatibility.
- Store backups and checksum sidecars under the same private ACL, encrypt at rest as required by the eventual host, test retention and offsite recovery separately, and never record passwords or database DSNs in shell history/logs.
- Integrity checks cover migration versions/checksums, required tables/indexes, InnoDB, row counts, known orphan references and logical duplicates. A `PASS` from these checks does not certify application authorization or every business rule.

## Current execution status

Wave 2 did **not** perform a database backup or restore: no `pdo_mysql` PHP driver or MariaDB/MySQL service was available in the execution environment. The test suite exits `77` for that concrete blocker. GitHub CI now defines a separate ephemeral MariaDB integration job, but it has not been run from this environment.
