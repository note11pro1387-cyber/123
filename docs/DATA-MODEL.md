# MR NEON — Data Model & Repository Contract (Wave 2)

## Status

This document defines the Wave 2 database contract, not a production cutover. The implementation uses PDO with MariaDB/MySQL. The current analysis container has no `pdo_mysql` driver or running database server, so the SQL files have **not** been applied to a real DB in this execution. The repository interfaces and migrations are implemented; live DB compatibility remains unverified until the MariaDB CI job or a controlled local runtime executes.

## Engine compatibility target

- Development target: MariaDB/MySQL through `pdo_mysql`.
- Conservative syntax target: MariaDB 10.6+ or MySQL 8.0.16+ (enforced `CHECK` constraints are relied upon). This is a **minimum compatibility proposal**, not a claim that the eventual XAMPP bundle or Production host has this version.
- All schema tables explicitly use InnoDB and `utf8mb4`.
- DB times are UTC `DATETIME(6)` values; presentation converts to the user's timezone. No Solar Hijri text value is persisted as the canonical timestamp.
- Driver uses exception mode, associative fetches, native prepares, a connection timeout, and `SET time_zone = '+00:00'`.
- Remote DB hosts require a configured readable CA certificate and server-certificate verification by default. Empty DB passwords are only permitted with explicit opt-in and loopback host.

## Table inventory (16)

| Migration | Table | Responsibility |
|---|---|---|
| `001` | `schema_migrations` | Version, exact file SHA-256 checksum, applied time and execution duration. |
| `002` | `users` | Canonical account, normalized username/phone, password hash, conservative legacy metadata and account status. |
| `003` | `user_channels` | Email/Telegram destinations, verified state and provider subject identity; does not treat a telephone number as verified. |
| `004` | `registration_attempts` | Expiring registration draft, selected channel, password hash and Telegram link nonce/identity metadata until account completion. |
| `005` | `verification_challenges` | Registration/password-reset purpose-bound OTP HMAC, expiry, attempts and single-use reset-only recovery authorization hash. No raw OTP/token. |
| `006` | `auth_sessions` | Server-side session registry storing only a SHA-256 session identifier hash, principal, expiry and revocation metadata. Final session integration belongs to Wave 3. |
| `007` | `projects` | Server IDs, unique public ID/tracking code, owner, status, progress and brief payload. |
| `008` | `project_milestones` | Six default project phases and their weights; weights total 100. |
| `009` | `project_events` | Project timeline/audit of domain changes; event statuses constrained to the approved state enum. |
| `010` | `project_messages` | User/admin/system customer-visible conversation only. |
| `011` | `internal_notes` | Separate admin-only notes; never returned from the customer message repository. |
| `012` | `notifications` | In-app user/admin inbox records, separately persisted from messages and external delivery. |
| `013` | `outbox_events` | Persistent future external delivery intent, retry status and idempotency metadata. No worker or delivery is activated in Wave 2. |
| `014` | `idempotency_keys` | Scoped request-key hash, request fingerprint and bounded response state. |
| `015` | `audit_logs` | Security/domain audit metadata, hashes for IP/UA, no raw request secrets. |
| `016` | `rate_limit_buckets` | Atomic fixed-window counters and block expiry, with keyed HMAC bucket identity. |
| `017` | `verification_delivery_attempts` | Non-secret delivery outcome ledger for registration Challenge delivery. A prepared/unknown result is not treated as an active Challenge and blocks blind resend. |
| `018` | `verification_challenges.state` | Adds non-consumable `pending_delivery` before provider acceptance; activation remains atomic with the accepted delivery ledger outcome. |

## Identity, uniqueness and relations

- `users.id` is the internal unsigned numeric key. `users.public_id` is a separate 32-hex-character public identifier. `legacy_id` is nullable and unique for an imported account. Normalized usernames and canonical Iranian mobile numbers are unique.
- An imported account keeps the historic `verified` flag only as metadata (`legacy_verified`). It is imported as `pending`; a historic flag does not establish a currently verified channel or grant login eligibility.
- Phone is a required business-contact field in user creation, canonicalized as `+989XXXXXXXXX`; it is not an OTP/recovery destination while SMS is disabled.
- `user_channels` is the authoritative set of Email/Telegram channels. Unique indexes prevent reuse of a normalized destination or provider subject within a channel type. Telegram verification requires a stable numeric provider subject, not just a mutable `@username`.
- Registration challenges belong to a `registration_attempt`; reset challenges belong to a `user` and a verified `user_channel` for that same user. OTP HMAC, expiry, attempt limits, `used_at`, and an atomic state change support Wave 4's single-use 120-second contract.
- No separate `recovery_tokens` table is created. The recovery authorization is represented by a **hashed, short-lived, reset-only capability** on `verification_challenges`, purpose/state constrained and atomically consumed. This avoids duplicating the Challenge's owner/purpose/expiry. ADR: this combined representation was selected for Wave 2 because the Handoff includes `verification_challenges` but does not lock a separate recovery table; Wave 4 must re-evaluate the flow during service integration without adding another source of truth.
- `auth_sessions.session_hash` is the key; raw cookie/session identifiers are not stored. This is a proposed registry contract for Wave 3, not a replacement for PHP's actual session handler yet.
- Project owner is enforced by a foreign key and owner-scoped query methods. Public ID and Tracking Code are independently random server-generated identifiers with unique constraints. Project status is one of the Handoff enum values: `submitted`, `under_review`, `needs_information`, `in_progress`, `waiting_for_client`, `completed`, `rejected`, `cancelled`.
- Milestones are `intake`, `review`, `design`, `development`, `qa`, `delivery`; weights are 10, 10, 20, 35, 15, 10. Progress helper returns completed milestone weight, and application services should persist the resulting status/progress/event atomically in Wave 5.
- `project_messages` and `internal_notes` are separate tables. Customer message queries are owner-scoped SQL joins. The unscoped admin/domain methods require prior authorization by their caller.
- Notifications, audit events, idempotency and outbox are distinct concepts. No external worker or exactly-once delivery is implied by this schema.

## Repository catalogue

- `UserRepository`: create, authentication-only lookup (includes `password_hash` and must never be serialized), customer-safe profile lookup, lookup by legacy ID, account status and login metadata.
- `UserChannelRepository`: create/list/find verified channels and provider subjects.
- `RegistrationAttemptRepository`: create/find an open attempt, read for completion (contains a hash; internal only), atomically consume once, link stable Telegram IDs.
- `VerificationChallengeRepository`: create constrained challenge, purpose/subject-bound read, atomically count failed attempts, consume registration OTP once, convert reset OTP into reset-only authorization and spend that authorization once.
- `AuthSessionRepository`: create, lookup active hash, touch, revoke and revoke user sessions.
- `ProjectRepository`: create server identifiers/status, owner-scoped find/list/count, status update and archive only terminal projects.
- `ProjectMilestoneRepository`: create six milestones, list, set status and calculate completed-weight progress.
- `ProjectEventRepository`: append validated event/status values and list timelines; includes owner-scoped customer timeline query.
- `ProjectMessageRepository`: customer-owner-scoped and admin/domain message reads.
- `InternalNoteRepository`: separate note create/read/update methods; callers must enforce Admin authorization.
- `NotificationRepository`: user inbox list and owner-scoped read marking.
- `OutboxRepository`: enqueue due work and record a delivery attempt result; no sender/worker exists in this Wave.
- `IdempotencyKeyRepository`: reserve, find and complete a key within scope; raw client idempotency keys are not stored.
- `AuditLogRepository`: append and bounded recent query; sensitive metadata-like keys are rejected.
- `RateLimitBucketRepository`: atomic consume/block with keyed HMAC identity. Requires `MRNEON_RATE_LIMIT_HMAC_KEY` (32+ bytes), supplied only via private runtime config.

Repositories do not replace authorization or own the transaction boundary. Multi-repository changes (account completion, project creation + milestones + event + outbox) must be wrapped by an application service transaction in Wave 4/5. A repository error propagates; DB failure is never mapped to an empty array.

## Migration implementation

Migrations `001`-`017` create the application tables; migration `018` is a single additive `ALTER TABLE` that extends the Challenge state enum. The runner checks file names, contiguous version numbering, SHA-256 checksums and recorded names; uses a MySQL named lock; refuses checksum drift, missing recorded versions, or a target table that exists without a corresponding ledger entry (except the bootstrap ledger table). Re-running after success should report no pending migrations. MySQL/MariaDB DDL may auto-commit: a DDL success followed by ledger-write failure can leave a table unrecorded; the runner then stops for manual reconciliation, rather than claiming rollback. Migrations are CLI-only.

## Explicit non-goals

No JSON consumer cutover, no dual-write, no fallback to JSON on DB failure, no live OTP/Telegram/Email sends, no import of old sessions/OTPs/rate-limit buckets/logs, and no application API activation occurs in Wave 2.
