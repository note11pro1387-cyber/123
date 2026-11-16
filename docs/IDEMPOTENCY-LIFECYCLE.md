# MR NEON — Idempotency Lifecycle and Retention

**Stage:** W5-P2-P5 — implementation only; QA intentionally not run.

## Contract

- Scope every key to the existing endpoint-specific identity. Store only a SHA-256 of the raw key, request fingerprint, state and bounded canonical response.
- The current callers use 24-hour expiry for project creation and customer messages, and 30-day expiry for Telegram update deduplication.
- `find()` exposes unexpired rows and expired `processing` rows; expired terminal rows are not replayed. Unexpired completed requests replay only when request fingerprints match. Same key with different payload is a conflict; incomplete or unreadable results must not be reported as success.
- Expiry is evaluated with database UTC time (`UTC_TIMESTAMP(6)`). Expired completed/failed rows for the exact scope may be reclaimed during a new reservation. This defines the limit of the replay guarantee: after expiry and deletion, reuse of an old key may be treated as a new request.
- Expired `processing` rows are excluded from automatic reclaim and cleanup. They are an operator-review condition because state age alone cannot prove whether a related side effect happened.
- Browser clients stop blind retries after 22 hours, before the nominal 24-hour project/message retention boundary. For old/ambiguous submissions, the user must review server-backed project/message history and explicitly confirm before a new key is generated. This browser behavior is advisory; the server remains the authorization and persistence authority.

## Cleanup command

File: `bin/cleanup-idempotency-keys.php`

Default mode is dry-run and emits aggregate counts only:

```powershell
php bin/cleanup-idempotency-keys.php --limit=200
```

The default mode does not change application rows or schema. As in the existing database factory, opening the PDO connection initializes that connection session timezone to UTC. Apply mode is intentionally guarded and must not be run until an authorized operator confirms that the configured database is the approved isolated target and final QA/owner authorization has been granted. For a production target, additionally set `MRNEON_ALLOW_PRODUCTION_IDEMPOTENCY_CLEANUP=1` for that explicit operation:

```powershell
$env:MRNEON_ENV = 'staging' # use the exact explicitly approved target environment
$env:MRNEON_ALLOW_IDEMPOTENCY_CLEANUP = '1'
$env:MRNEON_IDEMPOTENCY_CLEANUP_QA_APPROVED = '1' # only after QA and separate owner approval
php bin/cleanup-idempotency-keys.php --apply --limit=200 --confirm-db=EXACT_CONFIGURED_DATABASE_NAME
Remove-Item Env:MRNEON_ALLOW_IDEMPOTENCY_CLEANUP
Remove-Item Env:MRNEON_IDEMPOTENCY_CLEANUP_QA_APPROVED
Remove-Item Env:MRNEON_ENV
```

Replace the database-name placeholder locally; do not paste database configuration or secrets into chat/logs. Each invocation deletes at most one batch (1–500) of expired `completed`/`failed` rows. Repeat only through an approved maintenance schedule. `processing` records are never deleted by this command. No scheduler is configured in this stage, and the command was not run while preparing this implementation. For a production target, do not set the production override unless the owner has separately approved that exact maintenance operation after the required QA gate.

## Remaining operational gates

- Confirm exact MySQL/MariaDB version and DB/Auth integration in the owner environment.
- Review dry-run aggregate counts on an explicitly approved isolated database.
- Do not configure cron/Windows Task Scheduler for production until final QA, explicit owner approval, backup/restore readiness and rollback conditions are recorded.
- Tests remain `NOT RUN BY OWNER DIRECTIVE`. This document is not test evidence or release approval.
