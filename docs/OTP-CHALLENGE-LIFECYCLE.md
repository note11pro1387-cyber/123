# OTP Challenge Lifecycle — W4-P3

## Purpose and scope

This document covers **registration** Challenges only. The Wave 2 Challenge schema supports a distinct `password_reset` Purpose, but recovery remains disabled for W4-P5. SMS is not a permitted channel.

## Two coordinated state machines

### Delivery ledger (`verification_delivery_attempts`)

| State | Meaning | Active Challenge created? | Automatic retry |
|---|---|---:|---|
| `prepared` | Server persisted a delivery intent before calling a Provider | No | No; blocks blind resend until reconciled |
| `accepted` | Injected transport reported provider-level acceptance and Challenge transaction committed | Yes, by matching `verification_challenges` row | Only by explicit Resend policy |
| `rejected` | Transport reported a definite rejection | No new Challenge | Not immediately; cooldown and limits apply |
| `unknown` | Timeout/exception/ambiguous result or accepted send whose state could not be committed | No new Challenge unless already committed independently | No blind retry; reconciliation required |
| `not_configured` | No real transport is configured | No | Only after cooldown and limits; still fails until configured |

Migration `017` creates the separate delivery-outcome ledger. Migration `018` adds `pending_delivery` to the Challenge state enum so the non-consumable Challenge row and prepared ledger are persisted together before provider I/O. A provider-accepted delivery activates that exact Challenge and resolves the ledger to `accepted` in one transaction. Unknown outcomes remain pending and block blind retries.

### Persisted Challenge (`verification_challenges`)

| Stored state | Logical interpretation |
|---|---|
| `active` | May be verified only if the Attempt/Challenge context matches, `expires_at` is in the future, and attempts remain |
| `used` | Consumed exactly once; never reusable |
| `revoked` | Superseded after a replacement delivery was accepted, or explicitly invalidated |
| `exhausted` | Verification attempt cap reached |
| `verified_reset` | Existing recovery-only state; not activated by W4-P3 |
| Expiry | Derived from `expires_at`; an expired row may still store `active`, but is never consumable and is rejected by server time checks |

Logical `superseded` maps to existing persisted `revoked`; no new Challenge enum state was added. The Challenge code MAC, attempts and purpose remain in the existing schema.

## Delivery protocol

1. Resolve the Attempt using a server-issued 32-character Attempt ID and verify it is unconsumed/unexpired.
2. Derive the selected channel and destination only from the Attempt's server-side values. Email must be canonical. Telegram requires trusted linked chat and user IDs; a username alone is insufficient.
3. Apply DB-backed limits for IP, Attempt and canonical destination. Acquire the per-Attempt named lock.
4. Start a short transaction; lock/revalidate Attempt; reject any unresolved `prepared` ledger record; apply resend count/cooldown; confirm prior accepted delivery uses the same keyed destination fingerprint; insert `prepared`; commit.
5. Call `VerificationTransportInterface::deliver()` **outside** a transaction. A thrown exception is treated as `unknown`, not `rejected`.
6. For `rejected`, `unknown` or `not_configured`, resolve the ledger and return a controlled problem response. Do not create/activate a Challenge and do not revoke the prior accepted Challenge.
7. For `accepted`, capture the injected server Clock time after the Provider returns. In a new transaction, lock/revalidate the Attempt and destination, revoke prior active registration Challenges, insert the Challenge with HMAC, `last_sent_at = acceptedAt`, `expires_at = acceptedAt + 120 seconds`, resolve the ledger to `accepted`, write safe audit metadata and commit.
8. If accepted delivery cannot be persisted, attempt to resolve the ledger to `unknown`; if that also fails, `prepared` remains and stops blind retries. The API returns a reconciliation-required error, never success.

The Provider's `accepted` result means the provider accepted the API request; it does **not** guarantee that the message reached a user's device.

## OTP cryptographic contract

- Generator: `random_int(0, 999999)`, formatted as exactly six ASCII digits including leading zeroes.
- MAC: HMAC-SHA-256 using `MRNEON_OTP_HMAC_KEY` with a versioned, NUL-delimited context containing Challenge ID, Purpose, channel, canonical target and OTP.
- Key is at least 32 bytes and separate from rate-limit keys. Empty/fixed fallback secrets are forbidden; missing or too-short configuration fails closed.
- Verifier recalculates the MAC and uses `hash_equals`. Raw OTP is not written to database, logs, audit, query strings, error responses, or reports.
- `VerificationDelivery` keeps code/target private, redacts debug output and refuses native serialization. Concrete adapters must not log them.

## Fixed validity and resend policy

- Validity is a fixed constant: **120 seconds exactly** from the Clock timestamp captured after typed Provider acceptance. It is not measured from opening the form or creating a prepared ledger row.
- Default cap: 5 verification attempts per Challenge (bounded to 3–10 by server configuration).
- Default resend cooldown: 60 seconds (bounded to 15–300). Calculation rounds remaining time up and accounts for microseconds so resend cannot open early.
- Default maximum deliveries: one initial request plus at most 3 resends (configured resends 1–10). DB-backed rate limits apply additionally.
- Only one active Challenge can be consumable for a given registration Attempt at a time; resend operations use per-Attempt named locks. The previous active Challenge is revoked only after replacement delivery acceptance.
- No automatic Retry is performed after an ambiguous Provider result; repeated initial-send requests are also blocked once the latest outcome is `unknown`, and the central API client has no automatic mutation retry.
- The old JSON-backed OTP sender/verifier and direct legacy send/resend handlers are fail-closed. Their prior 600-second expiry path is no longer accepted; old JSON OTP rows are not migrated or trusted by the new verifier.

## Verification and transitions

1. Validate exact input allowlist: `attemptId`, `challengeId`, `code`; reject role/status/destination overrides and unknown fields.
2. Apply DB-backed IP/Attempt/destination rate limits; acquire the same sorted canonical identity locks as W4-P2 and a per-Attempt lock.
3. Begin transaction; `SELECT ... FOR UPDATE` the Attempt and Challenge; require `purpose=registration`, `subject_type=registration_attempt`, same Attempt ID, same channel and same canonical destination.
4. Require `state=active`, `used_at IS NULL`, unexpired according to the injected server Clock, and `attempts < max_attempts`.
5. On wrong code, atomically increment `attempts`; reaching `max_attempts` moves the row to `exhausted`. Commit the attempt counter/audit before returning `INVALID_VERIFICATION_CODE`.
6. On correct code, create User + selected User Channel, atomically mark Challenge `used` and Attempt consumed, and write safe audit metadata inside the same transaction. Return registered state only after Commit.
7. A second concurrent verification/replay sees consumed Attempt/Challenge or a failed atomic conditional update and cannot create a second account.

| From | Event/guard | To | Failure handling |
|---|---|---|---|
| No delivery row | Start/resend accepted for processing | `prepared` | Commit before external call; pending blocks another send |
| `prepared` | Typed transport accepts | ledger `accepted` + Challenge `active` | The two durable changes share the second DB transaction |
| `prepared` | Definite rejection | ledger `rejected` | No new Challenge; prior Challenge retained |
| `prepared` | Timeout/exception | ledger `unknown` | No new Challenge; prior Challenge retained |
| `prepared` | No real adapter | ledger `not_configured` | No Challenge; fail closed |
| Challenge `active` | Wrong code, attempts remain | `active`, attempts + 1 | No completion |
| Challenge `active` | Wrong code reaches cap | `exhausted` | No completion |
| Challenge `active` | Correct code + full DB completion commits | `used`; Attempt consumed | User/channel writes rollback if consumption/attempt update fails |
| Challenge `active` | New resend delivery accepted | `revoked` for old row; new row `active` | Old code invalid after commit |
| Any terminal state | Verify/replay | unchanged | Controlled invalid/expired response |

## API contract

- Start: `POST ?action=sendVerificationCode` with `{ "attemptId": "<server-issued id>" }`.
- Resend: `POST ?action=resendVerificationCode` with the same `{ "attemptId": "..." }`.
- Verify: `POST ?action=verifyRegisterCode` with `{ "attemptId": "...", "challengeId": "...", "code": "123456" }`.
- Every mutation uses the existing anonymous-session CSRF contract and central API envelope.
- Successful send/resend returns `state=active`, `challengeId`, `channel`, `expiresAt`, and `ttlSeconds=120`; it does not return the OTP.
- Successful verify returns `registered=true`, `authenticated=false`, `sessionEstablished=false`, plus the allowlisted user DTO. Existing login remains a separate explicit action.
- Provider not configured/rejected/unknown, OTP config error, rate limit, expired/wrong code, too many attempts, destination change and DB failure use controlled `ApiProblemException` error codes. SQL details, secrets, code, and full destination are not returned.

## Operational caveat

A process crash between persisting `prepared` and resolving the transport result intentionally leaves a blocking ledger row. An `unknown` outcome is also a retry barrier for initial-send and resend actions. Neither is automatically retried because the previous Provider call may have succeeded. W4-P4 should supply provider-side idempotency/reconciliation where available. Until then, any manual reconciliation must inspect Provider evidence and change only a verified row; never bulk-delete prepared rows to “fix” resend.
