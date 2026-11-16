# W4-P2 Delta Decision Ledger

| ID | Decision / change | Basis | Status / follow-up |
|---|---|---|---|
| DLG-W4P2-001 | Record `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`; proceed with W4-P2 without claiming DB verification. | Explicit owner decision in the W4-P2 task contract. | Accepted; Wave 2/Wave 3 DB suites remain release risks until final comprehensive Integration. |
| DLG-W4P2-002 | Use existing `registration_attempts`, `users`, `user_channels`, `verification_challenges`, `audit_logs`, `rate_limit_buckets`, and Wave 2 repositories; do not add Migration. | Inspect merged Wave 2 schema/repositories; migration 004 already contains pending Attempt/password hash/channel/nonce digest/expiry/Terms fields. | Implemented; DB behavior remains unverified. |
| DLG-W4P2-003 | Centralize canonicalization and policy rather than repeat validation logic in controller/repository/client. | Existing canonical contracts (`+989...`, lowercase normalized username/email); owner DTO and policy requirements. | Implemented in `app/validators` and `RegistrationDTO`. |
| DLG-W4P2-004 | Enforce one explicit channel value: `telegram` or `email`; SMS remains invalid and inactive. | W4-P1 owner-approved product contract. | Implemented and unit-tested. |
| DLG-W4P2-005 | Use a server-configured Terms version and fail closed when absent rather than invent a legal version. | Terms/Privacy approval remained open in W4-P1. | Implemented; `MRNEON_TERMS_VERSION` remains blank in safe `.env.example`; registration unavailable until the server config is set to the approved version. |
| DLG-W4P2-006 | Use a provisional, centralized 1800-second Attempt TTL, bounded 300–86400, distinct from the 120-second OTP TTL. | Schema requires Attempt expiry but no finalized independent TTL policy was found. | Implemented with note; requires owner confirmation before Release. |
| DLG-W4P2-007 | Prefer Argon2id; use the standard PHP fallback only when unavailable and refuse >72-byte passwords in fallback to avoid bcrypt truncation. | Master Handoff password policy recommendation; runtime compatibility. | Implemented; runtime algorithm must be checked during host preflight. |
| DLG-W4P2-008 | Reuse exact matching live Attempt and, for Telegram, rotate the one-time nonce digest on retry; other identity collisions return generic 409. | Limit unbounded duplicate rows without exposing which identity field collided. | Implemented; named locks and repository transactions need MySQL Integration. |
| DLG-W4P2-009 | Acquire deterministic MySQL named locks on normalized username/phone/email, then use an explicit service-owned transaction; retain existing DB unique constraints as final authority. | `registration_attempts` lacks a unique active-identity constraint; `users` has authoritative unique identities. | Implemented; live contention and completion-time races must be tested. W4-P3 must coordinate. |
| DLG-W4P2-010 | Audit only channel/outcome; do not record password, raw nonce, email, phone or raw IP. | Security/privacy boundary. | Implemented in source; actual DB persistence audit remains unverified. |
| DLG-W4P2-011 | Activate only the real `register` POST route for pending Attempt create/reuse. Verification/OTP/recovery routes remain closed. | W4-P2 minimal API integration scope. | Implemented; HTTP/CSRF database-backed runtime test not run. |
| DLG-W4P2-012 | Keep front-end password 8-character hint unchanged in this part; record mismatch to central server 15-character policy. | User explicitly assigns client UI policy alignment to W4-P6. | Deferred to W4-P6; server enforces 15 characters now. |
| DLG-W4P2-013 | Do not add schema changes for Attempt idempotency or Terms; use present schema and central service behavior. | Existing Wave 2 Schema sufficient for the data required in this part. | No Migration created. |
| DLG-W4P2-014 | Treat PHP 7.4.25 reported on owner's XAMPP as a compatibility risk, not the source baseline. | Master Handoff says choose a supported version after host preflight; current project uses PHP 8.1+ constructs. | Open Release Risk; runtime not established here. |
| DLG-W4P2-015 | Return only `pending_verification` / `verificationStatus=not_started`; no user/channel/challenge/OTP is created. | Prevent fake success and preserve W4-P3 boundary. | Implemented; actual table outcomes await DB integration. |

## Open decisions / risks

- Final Terms and Privacy content/version are unapproved. Do not set `MRNEON_TERMS_VERSION` based on assumption.
- Attempt TTL 1800 seconds is provisional and needs product/owner confirmation.
- Final PHP target and host extensions remain undecided; PHP 7.4 is likely incompatible with the current source syntax and is EOL.
- Final DB integration and concurrency tests remain open under the accepted owner deferment.
- W4-P3 must validate Telegram linking nonce with constant-time comparison and trusted provider update before linking/verification; a username is not proof of ownership.
- W4-P3 must coordinate Attempt completion against existing `users` unique constraints and the W4-P2 named-lock convention.
- W4-P6 must align front-end password hints and clean up the legacy duplicate registration markup without changing the current active API contract.
