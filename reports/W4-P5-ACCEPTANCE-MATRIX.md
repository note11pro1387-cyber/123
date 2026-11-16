# W4-P5 Acceptance Matrix

| Acceptance item | Status | Evidence / reason |
|---|---|---|
| Existing Challenge table reused; Purpose isolated to `password_reset` | PASS (code/static) | `database/migrations/005_create_verification_challenges.sql`, `VerificationChallengeRepository`, W4-P5 static checks. |
| Account target resolved server-side | PASS (code/unit/static) | `UserRepository::findVerifiedRecoveryTarget`; email must be verified on active user; Telegram lookup uses MR NEON username and server-side verified numeric Chat ID. |
| No client `userId` / arbitrary destination | PASS (code/unit/static) | Request allowlists and repository resolver; transport destination is DB-derived. |
| Account-enumeration generic start response | PASS WITH NOTES | Known/unknown valid identifiers produce same generic 202 result with no `found` field or destination. A one-second response floor is only partial timing mitigation because existing Provider I/O is synchronous. |
| OTP Purpose, channel, destination and expiry binding | PASS (code/unit) | OTP HMAC includes challenge/purpose/channel/canonical target; repository locks and revalidates exact verified channel; state machine checks expiry and attempts. Live DB behavior BLOCKED. |
| Reset-only capability uses CSPRNG and only hash is persisted | PASS (unit/code) | 32 random bytes, 64 hex characters, SHA-256 stored through existing `recovery_token_hash` column. |
| Reset-only expiry is independent of OTP TTL | PASS (unit/static) | Default 600 seconds, env-bounded 60–900; OTP stays 120 seconds. |
| Password policy and hash | PASS (unit/code) | Uses `PasswordPolicy` (minimum 15 Unicode characters, limit 1024 bytes, password hashing API, no trimming). |
| Atomic password/token/session mutation | BLOCKED (DB integration) | Implemented in a single PDO transaction, but no live MySQL test could run because `pdo_mysql` is unavailable. |
| Session registry revocation | BLOCKED (DB integration) | Uses existing `AuthSessionRepository::revokeAllForUser`; multi-session behavior not exercised against a live DB in this environment. |
| CSRF, method policy and central envelope | PASS (static/unit contract) | Existing central API client, `SessionManager`, `Csrf`, `MethodPolicy` and `ApiResponse` are reused. No end-to-end HTTP runtime test was available. |
| Legacy combined OTP+password endpoint is closed | PASS (static/unit contract) | `verifyPasswordResetCode` returns HTTP 410 `RECOVERY_FLOW_UPDATED`; legacy JSON handlers are fail-closed. |
| UI keeps reset token out of URL/storage | PASS (static/unit contract) | Token is held in the component instance, sent by `X-Reset-Authorization`, and cleared on close/completion. |
| W4-P5 DB integration | BLOCKED / NOT RUN | Runner exited 77 before opening a DB connection or mutating data. |
| External Provider integration | NOT RUN | No live Email/Telegram sends are part of this run; prior W4-P4 Unit uses synthetic/local protocol fixtures only. |
| Production runtime compatibility | NOT RUN | PHP production target is not established; this execution used PHP CLI 8.4.24. |

This matrix explicitly separates source/unit evidence from unexecuted database and external-service proof. No blocked/not-run item is considered passed.
