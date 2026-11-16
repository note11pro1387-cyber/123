# W4-P3 Test Report

**Decision:** `PASS WITH NOTES`  
**Run date:** 2026-10-12  
**Scope:** W4-P3 OTP/Challenge services, atomic completion code, API/consumer integration, migration/catalog alignment, legacy OTP shutdown.

## Runtime facts

- PHP CLI: `8.4.24`.
- `PDO::getAvailableDrivers()` returned an empty array.
- `pdo_mysql` and `pdo_sqlite` were unavailable in this runtime.
- `pwsh`, `powershell`, `mysql`, and `mariadb` executables were not present.
- No MariaDB/MySQL connection was possible or attempted. The database Integration runner exited before database mutation.
- No Windows/XAMPP execution is claimed. The owner-reported XAMPP PHP 7.4.25 environment was not accessible.

## Executed checks

| Command / check | Actual result | Exit |
|---|---|---:|
| `php -l` for each changed/new PHP file | 29 files linted; all reported no syntax errors | 0 |
| `php tests/Unit/run-w4p3-unit.php` | PASS — 1,041 assertions, including legacy OTP fail-closed behavior | 0 |
| `php tests/Unit/run-unit.php` | PASS — 64 Wave 1 regression/contract checks | 0 |
| `php tests/Unit/run-registration-tests.php` | PASS — 62 W4-P2 DTO/Validation/Policy checks | 0 |
| `php tests/Unit/run-wave3-unit.php` | PASS — 20 Wave 3 Unit/Security contract checks | 0 |
| `python3 tools/wave1_static_checks.py` | PASS — all listed Wave 1 static checks; 17 remaining legacy action policies | 0 |
| `python3 tools/wave2_static_checks.py` | PASS — 99 checks; explicitly not a SQL runtime test | 0 |
| `python3 tools/wave3_static_checks.py` | PASS — 34/34 checks | 0 |
| `python3 tools/wave4_p2_static_checks.py` | PASS — 39 checks | 0 |
| `python3 tools/wave4_p3_static_checks.py` | PASS — 31 W4-P3 source contract checks | 0 |
| `node --check js/auth.js` | JavaScript syntax check passed | 0 |
| `php tests/Integration/run-w4p3-otp-integration.php` | BLOCKED — `pdo_mysql` unavailable, Integration NOT RUN | 77 |

## What passed only at source/Unit/Static level

- Cryptographic six-digit OTP generation and context-bound HMAC verification.
- Dedicated OTP HMAC key fail-closed behavior.
- Fixed 120-second TTL calculations and microsecond-safe cooldown boundary.
- Typed Transport result contract and default `not_configured` behavior.
- Legacy JSON-backed 600-second OTP sender/verifier disabled; direct legacy send/resend handlers return a controlled 503.
- Active API route ownership, CSRF/session checks and central API envelope at source/static level.
- Transaction/row-lock/conditional-consume logic and the test cases are present in source.
- Unknown delivery outcomes are guarded against blind resend through the initial-send endpoint.

These source/unit results do not prove actual SQL behavior, locking, rollback, migrations against MariaDB/MySQL, HTTP CSRF behavior or provider integration.

## Integration cases present but NOT RUN

The DB runner contains scenarios for: accepted send and exact TTL; wrong OTP and attempt counter; atomic User + selected Channel + Challenge + Attempt completion; replay/duplicate prevention; rejected resend preserving the old Challenge; accepted resend revoking the old Challenge; default no-provider behavior; and unknown-outcome retry barrier. All remain `BLOCKED/NOT RUN` until run against a checksum-verified, explicitly named disposable MariaDB/MySQL database using `docs/W4-P3-INTEGRATION-RUNBOOK.md`.

## Database gate and release risks

`OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED` remains open. This report does not change that decision and does not mark Wave 2, Wave 3, or W4-P3 database Integration as PASS.

Additional open release items: real Email/Telegram providers are intentionally absent until W4-P4; Telegram Attempts cannot be sent until trusted account linkage exists; `MRNEON_OTP_HMAC_KEY`, `MRNEON_RATE_LIMIT_HMAC_KEY`, and approved server-owned `MRNEON_TERMS_VERSION` must be configured privately; project source uses PHP 8.1+ language features, so owner-reported PHP 7.4.25 must not be assumed compatible; production MariaDB/MySQL and transaction/concurrency behavior must be verified in the final Gate.
