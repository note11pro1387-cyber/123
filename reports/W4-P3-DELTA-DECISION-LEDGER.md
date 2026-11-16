# W4-P3 Delta Decision Ledger

| ID | Decision | Evidence / rationale | Status |
|---|---|---|---|
| DLG-W4P3-001 | Preserve the existing Challenge `state` enum; do not add `pending_delivery` to migration 005 | Wave 2 supports `active/verified_reset/used/revoked/exhausted`; changing old migration checksum would break migration immutability | Accepted |
| DLG-W4P3-002 | Add migration 017 for a separate transport outcome ledger | Need to distinguish `prepared`, `accepted`, `rejected`, `unknown`, `not_configured` without persisting OTP or destination plaintext | Implemented; DB runtime unverified |
| DLG-W4P3-003 | Store keyed destination fingerprint only in delivery ledger | Provider outcomes need safe correlation without exposing email/chat ID in new ledger | Implemented |
| DLG-W4P3-004 | OTP HMAC uses dedicated private `MRNEON_OTP_HMAC_KEY`, minimum 32 bytes | Must be distinct from rate-limit/session keys; absent key must fail closed | Implemented in service/config template |
| DLG-W4P3-005 | Fixed TTL is 120 seconds from post-acceptance server Clock time | Explicit owner decision; not tied to Attempt TTL and not externally configurable | Implemented; DB timestamp runtime unverified |
| DLG-W4P3-006 | Default transport is unavailable and returns `not_configured` | W4-P4 owns real provider implementation; no fake production success | Implemented |
| DLG-W4P3-007 | External transport call is outside DB transactions | Avoid long/open transactions during network I/O; prepared row protects against duplicate retry | Implemented; failure behavior needs DB runtime test |
| DLG-W4P3-008 | Unknown/ambiguous delivery cannot be blindly resent | Network timeout may mean Provider accepted the message | Implemented by ledger state and prepared/unknown handling |
| DLG-W4P3-009 | Do not revoke prior Challenge until new Provider delivery is accepted and the DB transition commits | Failed resend must not invalidate a previously usable OTP | Implemented; integration test blocked |
| DLG-W4P3-010 | Use existing Challenge row states: `revoked` for superseded, `used` for consumed, `exhausted` for attempt cap; expiry is time-derived | Avoid schema churn and retain Wave 2 purpose/state constraints | Implemented |
| DLG-W4P3-011 | Completion creates active user + selected verified channel in one transaction; no auto-login | Session behavior was not sufficiently established for a safe auto-login contract | Implemented at source level; DB not verified |
| DLG-W4P3-012 | Keep exact stable API action names and existing `data/meta` + RFC 9457 error contract | Preserve frontend consumers/API client | Implemented |
| DLG-W4P3-013 | Correct legacy browser contract to send `attemptId/challengeId`, show 120-second TTL, and not auto-login | Old consumer referenced `userId`, asserted a Telegram-only flow and 10-minute expiry | Implemented; browser end-to-end not executed |
| DLG-W4P3-014 | Do not create a migration for W4-P4 providers or recovery | Both are outside W4-P3 scope | Accepted |
| DLG-W4P3-015 | Maintain prior Wave 2/3 DB Integration deferment | Owner explicitly accepted deferment; it is not a PASS | `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED` |
| DLG-W4P3-016 | Report current status as `PASS WITH NOTES`, not `PASS` | DB transaction, concurrency, and rollback scenarios cannot run without pdo_mysql | Required |
| DLG-W4P3-017 | Disable the legacy JSON-backed OTP sender/verifier and direct legacy send/resend handlers | The old `core/auth-core.php` path created 600-second JSON OTP records and sent Telegram unconditionally; the active API is now owned by W4-P3, and legacy helpers must never accept those records | Implemented; no legacy helper or route test calls a real provider |
| DLG-W4P3-018 | Treat a latest `unknown` delivery outcome as a retry barrier, including the initial-send action | An accepted message may be delivered even when the caller sees a network/persistence failure; the initial action must not bypass Resend's retry protection | Implemented; DB behavior remains unverified |
| DLG-W4P3-019 | Ensure the DB Integration test performs scoped cleanup in `finally` before returning its result | Assertion failures after account commit must not bypass synthetic-row cleanup | Implemented in test harness; database runtime unavailable |

## Open decisions / release risks

- Runtime/Production PHP version must support project syntax (currently the merged codebase uses PHP 8.1+ features); reported XAMPP PHP 7.4.25 is not a compatible baseline unless the broader architecture/runtime decision changes.
- Production MariaDB/MySQL minimum version must be fixed and tested for CHECK constraints, named locks and `SELECT ... FOR UPDATE` semantics.
- Real transport Provider acceptance/idempotency/reconciliation behavior is a W4-P4 dependency. Exactly-once delivery is not claimed.
- If a process exits with delivery ledger `prepared`, it must be reconciled using safe provider evidence; no automatic cleanup path has been added.
- Terms/Privacy approved version must be configured by the owner before public registration.
