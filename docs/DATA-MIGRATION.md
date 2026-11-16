# MR NEON — Legacy JSON Data Mapping & Import (Wave 2)

## Source-of-truth and safety

The supplied original ZIP is the source snapshot. `php bin/migrate-json.php` defaults to a read-only, source-only dry-run; it never changes JSON files. Import supports persistent `users.json` records only, after the complete target schema is migrated and checksum-verified. A real import requires explicit `--apply`, plus `--allow-partial-source` because project, notification and reset source sets are missing from this snapshot, and `--allow-legacy-accounts-pending` to explicitly acknowledge that imported accounts remain `pending` until the new verification policy is completed. A legacy `verified` flag does not activate an account or verify its phone, email, or Telegram identity. No import was applied during Wave 2 execution.

## Source inventory (observed from the actual ZIP, values withheld)

| Source | Parse/status | Rows/entries | Treatment |
|---|---|---:|---|
| `data/users.json` | Parsed JSON list | 2 | Candidate for explicitly approved import into `users` and `user_channels` (Email unverified only). |
| `data/pending_registrations.json` | Parsed JSON list | 0 | Not imported; in-flight/security-sensitive drafts are not resumed. |
| `data/verification_codes.json` | Parsed JSON list | 2 | Not imported: contains legacy raw-code material; no OTP value is printed or copied. |
| `data/sessions.json` | Parsed JSON list | 2 | Not imported; old sessions must be re-authenticated and must not become active new sessions. |
| `data/rate_limits.json` | Parsed JSON list | 8 | Not imported; ephemeral counters do not survive cutover. |
| `data/php_errors.log` | Present, 1,400 bytes, 15 lines | n/a | Not imported or printed; operational log may contain sensitive context. |
| `requests.json` | `MISSING_SOURCE` | unknown | No project records can be imported from this snapshot. No empty file was invented. |
| `notifications.json` | `MISSING_SOURCE` | unknown | No notification records can be imported. No empty file was invented. |
| `password_resets.json` | Security-transient / not imported | unknown | `NOT_IMPORTED_SECURITY_TRANSIENT_RESET_TOKENS`: reset tokens are transient security capabilities and must never be imported; absence is not treated as a missing business-data source. |

These counts describe the supplied snapshot at inspection time. They do not mean the JSON files are disposable or that all application data has been migrated.

## User field mapping

| Legacy field | Destination | Policy |
|---|---|---|
| `id` | `users.legacy_id` | Stringified legacy identity; unique, idempotency basis. |
| `fullName` | `users.full_name` | Required, validated length. |
| `username` | `users.username` + `username_normalized` | ASCII username policy, lower-case comparison key; uniqueness conflicts block the transaction. |
| `phone` | `users.phone` + `phone_normalized` | Required; Persian/Arabic digits normalize to Iranian `+989XXXXXXXXX`; invalid values are not repaired by guess. |
| `password` | `users.password_hash` | Accepted only if `password_get_info()` recognizes it as a password hash; never printed in report. Compatibility with current PHP password verifier must be validated before login migration. |
| `verified` | `users.legacy_verified` | Historical metadata only; `account_status` is set to `pending` regardless of legacy flag. |
| `email` | `user_channels` | Added as unverified email only; never treated as recovery-eligible until verified by the new flow. |
| `telegramId` | `users.legacy_telegram_username` | Preserved only as unverified display metadata; not a stable Telegram provider identity and cannot authorize delivery/recovery. |
| `createdAt`, `lastLogin` | UTC timestamps | Legacy strings are interpreted in Asia/Tehran and converted to UTC. Unparseable required/optional timestamps are reported invalid. |
| Unknown fields | `legacy_extra_json` | Preserved to avoid silent field loss, but unknown credential-like fields cause the record to be held for manual review rather than copied. Field names may appear in a report; source values do not. |

Each record carries a canonical source fingerprint (`legacy_source_hash`) to distinguish a repeated identical import from a changed legacy record. Source IDs, keys and data are not rewritten. User/channel conflicts are pre-checked; the import runs in one DB transaction and rolls back on conflict rather than partially importing a dataset silently. Duplicate email as well as username/phone collisions are detected. Only explicit database constraints establish final uniqueness.

## Dry-run / Apply

Read-only inventory and mapping check:

```powershell
php bin/migrate-json.php --source="C:\private\mrneon-snapshot\data"
```

For a machine-readable report, choose a new private path outside the public web root. The command creates the report with exclusive-create semantics and private permissions where supported:

```powershell
php bin/migrate-json.php --source="C:\private\mrneon-snapshot\data" --report="C:\private\reports\wave2-import-dry-run.json"
```

Apply is intentionally not part of ordinary development setup. Only after an owner-approved test DB is created, full migrations run and the report is reviewed:

```powershell
php bin/migrate-json.php --apply --allow-partial-source --allow-legacy-accounts-pending --source="C:\private\mrneon-snapshot\data" --report="C:\private\reports\wave2-import-apply.json"
```

This applies user records only. It does not migrate projects, notifications, password resets, drafts, OTPs, sessions, rate-limit counters or logs. The command is CLI-only. No JSON/DB dual-write, fallback, cutover, JSON deletion or archive occurs here.

## Example report semantics

The source-only dry-run executed for this Wave reported: 2 source user records, 2 mappable, 0 invalid, 0 within-source duplicates; 0 inserted because dry-run does not connect/write. Destination counts remained `null`/`NOT_CHECKED_DRY_RUN` because no DB driver/server was available. Two business-data source sets (`requests.json` and `notifications.json`) are marked missing. Password-reset tokens are excluded by security policy rather than treated as a missing importable business source. This is a sanitized count-only observation, not evidence of database import.
