# Session Revocation on Password Reset — W4-P5

## Existing source of truth

W4-P5 reuses `AuthSessionRepository` and the existing `auth_sessions` registry. Registry rows contain a hash of the session ID, not the raw session ID. `findActive()` rejects records with `revoked_at IS NOT NULL` or expired `expires_at`.

## Reset behavior

Within the same PDO transaction that consumes the reset-only authorization and updates the user password hash, `AuthSessionRepository::revokeAllForUser($userId, 'password_reset')` marks every non-revoked registered session for that user revoked. In-flight Recovery Challenges are invalidated in that transaction too. A failure before commit rolls back the entire DB-side change, and the controller does not report a successful reset.

If the browser making the request happens to have a local session for the same user, `RecoveryController` destroys that local PHP session only after DB commit. A CSRF token is minted for the resulting anonymous session so the browser can continue to the login form without silently becoming authenticated. Other sessions are denied by the session registry on their subsequent authenticated request.

## Evidence limits

Repository and controller behavior has been reviewed and static/unit contracts passed, but this environment did not have `pdo_mysql`; the guarded W4-P5 DB Integration runner exited 77 before connecting or mutating a DB. Therefore atomic DB rollback, multi-session revocation and simultaneous reset behavior still require the final real MariaDB/MySQL Integration run. The current result must not be interpreted as live DB proof.
