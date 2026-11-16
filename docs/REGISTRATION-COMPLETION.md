# Atomic Registration Completion — W4-P3

## Transaction ownership

`VerificationService::verifyRegistration()` owns the transaction. It acquires the same normalized-identity named locks as W4-P2 plus the per-Attempt lock, starts one transaction, locks Attempt and Challenge rows with `FOR UPDATE`, rechecks every binding and calls `RegistrationCompletionService::completeWithinTransaction()`.

`RegistrationCompletionService` deliberately does not begin/commit/rollback. A call outside an existing transaction throws `LogicException`.

## Authoritative inputs

- The only account identity, normalized phone, optional email, selected channel, accepted Terms version and password hash come from the locked server-side `registration_attempts` row.
- Password plaintext is never re-requested during verification and never stored by W4-P3. The W4-P2 password hash is reused for User creation.
- `attemptId`, `challengeId`, `code` are the entire allowed verification payload. Unknown fields (including `userId`, `role`, `isAdmin`, `isVerified`, destination or purpose overrides) are rejected.
- The OTP HMAC is recomputed from Challenge ID, Purpose, canonical destination, channel and supplied code. Attempt/Challenge mismatch cannot be re-bound to another Attempt.

## Atomic work

Within the same caller-owned DB transaction:

1. Revalidate the Attempt is open/unexpired and selected channel equals the Challenge channel.
2. Revalidate the Challenge is registration-only, owned by that Attempt, active, unconsumed, before its 120-second expiry and under the attempt cap.
3. Recheck existing normalized username/phone and any Email identity collision. Database unique constraints remain the last defense.
4. Create the active User from the saved password hash and server-owned Terms fields.
5. Create the selected channel as verified only because this Challenge proves control of that Email target or trusted Telegram provider identity. A phone field remains a required business-contact field, not a verified channel while SMS is disabled.
6. If Telegram was selected and an optional Email was captured, persist it only as unverified and non-primary. No Telegram channel can be created without trusted linked user/chat IDs.
7. Atomically transition Challenge to `used` with a conditional update and consume the Attempt while recording the created User ID.
8. Write safe `registration.completed` audit metadata and read back an allowlisted profile for the response.
9. Commit. Only after commit does the controller return 201 success.

If User or Channel creation, Challenge consumption, Attempt consumption, audit or profile validation throws, the transaction rolls back. Duplicate-key PDO failures are mapped to a generic `409 REGISTRATION_CONFLICT`; SQL text is not returned.

## Replay/concurrency

- Row locks and conditional `UPDATE ... WHERE state='active' AND expires_at>UTC_TIMESTAMP(6) AND attempts<max_attempts` are the single-use database guard.
- Canonical identity named locks coordinate with W4-P2 registration-attempt creation. Sorted lock acquisition avoids inconsistent lock order.
- The Attempt is consumed in the same transaction as User/Channel creation. A successful replay sees no open Attempt and cannot create another account.
- On a wrong OTP, a conditional update increments the Challenge attempts. At the cap, the existing Challenge enum transitions to `exhausted`.
- No Provider call is inside the completion transaction.

## Authentication/session decision

Successful verification intentionally returns `registered=true`, `authenticated=false`, and `sessionEstablished=false`. No session is manufactured by the frontend. User must use the established login path; auto-login is not assumed by this part.

## Schema and outstanding verification

No existing migration was edited. Migration `017` adds the non-secret transport delivery-outcome ledger, and migration `018` adds the non-consumable `pending_delivery` Challenge state required to persist the pending Challenge before provider I/O. DB-level rollback/concurrency behavior still needs the declared Integration suite on a dedicated disposable MariaDB/MySQL test database. Unit/Static success does not replace that evidence.
