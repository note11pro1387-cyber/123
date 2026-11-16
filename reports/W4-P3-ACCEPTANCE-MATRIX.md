# W4-P3 Acceptance Matrix

**Build status:** `PASS WITH NOTES`. This matrix records what this execution actually verified. A Unit/Static result is not a database Integration result.

| ID | Acceptance item | Evidence/test | Result in current runtime |
|---|---|---|---|
| P3-01 | Six-digit cryptographically secure OTP format | `tests/Unit/run-w4p3-unit.php` | PASS — `random_int`, 500 synthetic format samples |
| P3-02 | Context-bound OTP HMAC and constant-time match | `tests/Unit/run-w4p3-unit.php` | PASS — wrong code/Challenge/Purpose/channel/destination rejected |
| P3-03 | Missing/short OTP key fails closed | `tests/Unit/run-w4p3-unit.php` | PASS |
| P3-04 | TTL exactly 120 seconds; expiry boundary | `tests/Unit/run-w4p3-unit.php` | PASS — pure Clock test |
| P3-05 | Cooldown cannot open early at microsecond boundary | `tests/Unit/run-w4p3-unit.php` | PASS — pure Clock test |
| P3-06 | Delivery results are typed; default transport is not configured | `tests/Unit/run-w4p3-unit.php` | PASS at type/contract level |
| P3-07 | Raw OTP and target not exposed by API/delivery object serialization/debug | Unit contract checks, `VerificationDelivery` | PASS for JSON/debug/native serialization guard; API actual request not exercised |
| P3-08 | Delivery ledger migration 017 is one additive table and schema catalog is aligned | Wave 2/3 static checks and migration parser | PASS — source-level only |
| P3-09 | Start/verify/resend action routes use POST + existing session/CSRF + API envelope | Wave 3 static checks; code inspection | PASS at source/static level; no HTTP integration test in this runtime |
| P3-10 | Rejected/unknown/not-configured cannot activate new Challenge | `VerificationService` source contract and pure result-state tests | PASS at source/unit level; DB flow not run |
| P3-11 | Failed resend preserves prior active Challenge | `tests/Integration/run-w4p3-otp-integration.php` contains real DB scenario | BLOCKED — pdo_mysql unavailable; scenario not run |
| P3-12 | Accepted resend revokes previous Challenge and enforces 120-second TTL on replacement | DB Integration runner | BLOCKED — not run |
| P3-13 | Wrong OTP attempts increment; threshold exhausts Challenge | DB Integration runner + existing atomic repository SQL | BLOCKED — database behavior not run |
| P3-14 | Completion transaction creates User/channel and consumes Challenge/Attempt atomically | DB Integration runner | BLOCKED — not run |
| P3-15 | Verify replay and simultaneous Verify cannot create a second account | Conditional updates, row locks, named locks; integration runner | BLOCKED — DB concurrency/rollback not run |
| P3-16 | Telegram only uses trusted linked user/chat IDs; no admin destination | Source contract and service checks | PASS at source level; no real webhook/provider in scope |
| P3-17 | No real Telegram/Email provider or Fake operational fallback | `UnavailableVerificationTransport`, transport contract | PASS — real send returns not_configured by default |
| P3-18 | Legacy 600-second JSON OTP path cannot verify or deliver new codes | `tests/Unit/run-w4p3-unit.php`; `tools/wave4_p3_static_checks.py` | PASS — legacy send/verify helpers and direct send/resend handlers are fail-closed |
| P3-19 | Unknown delivery cannot be retried blindly through initial-send endpoint | `VerificationService`; DB integration scenario in `tests/Integration/run-w4p3-otp-integration.php` | PASS at source/static level; DB scenario BLOCKED because pdo_mysql unavailable |
| P3-20 | PHP lint on modified/new PHP files | `php -l` on each changed/new PHP file | PASS — 29 files, Exit 0 |
| P3-21 | Previous Wave 1–3 + W4-P2 regression suites | Unit and static runners | PASS — see `W4-P3-TEST-REPORT.md` for exact totals and exit codes |

## Deferred release items

- `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED` remains open.
- Windows/XAMPP PHP 7.4.25 was not accessible for execution; project syntax already requires PHP 8.1+ features.
- `MRNEON_OTP_HMAC_KEY` and `MRNEON_RATE_LIMIT_HMAC_KEY` must be supplied privately; the sample remains empty.
- W4-P4 must deliver real Email/Telegram adapters and maintain the transport security contract.
- Prepared delivery rows after a hard process crash require controlled reconciliation; blind resend is intentionally blocked.
