# W4-P2 Test Report

## Environment

- PHP CLI: `8.4.24` in the current execution container.
- PDO drivers: none available; `PDO::getAvailableDrivers()` is empty.
- Node.js: `v22.16.0`.
- PowerShell/XAMPP: not present in this execution environment.
- No MariaDB/MySQL database was connected to or modified.

The owner's XAMPP PHP `7.4.25` information is treated as external owner-provided context, not a runtime observed in this environment. The merged source contains PHP 8.1+ constructs (including `readonly` promoted properties and `never` return types); PHP 7.4 compatibility is not claimed and is not a safe production assumption.

## Executed results

| Command/check | Result | Exit |
|---|---|---:|
| `php -l` on every added/changed PHP file | PASS — no syntax errors | 0 |
| `php tests/Unit/run-registration-tests.php` | PASS — 62 DTO/validation/policy assertions, synthetic only | 0 |
| `php tests/Unit/run-unit.php` | PASS — 62 existing Wave 1 unit/static-contract assertions | 0 |
| `php tests/Unit/run-wave3-unit.php` | PASS — 20 Wave 3 unit/security assertions | 0 |
| `node tests/Unit/run-api-client-tests.mjs` | PASS — 14 API-client assertions, synthetic only | 0 |
| `node --input-type=module --check < js/auth.js` | PASS — JavaScript syntax | 0 |
| `python tools/wave1_static_checks.py` | PASS — all Wave 1 static contracts | 0 |
| `python tools/wave2_static_checks.py` | PASS — 94 Wave 2 static checks; SQL runtime not implied | 0 |
| `python tools/wave3_static_checks.py` | PASS — 34/34 Wave 3 static checks | 0 |
| `python tools/wave4_p2_static_checks.py` | PASS — 39 W4-P2-specific source-contract checks; runtime DB behavior is not implied | 0 |
| `php tests/Integration/run-registration-attempt-integration.php` | BLOCKED — `pdo_mysql` unavailable; runner stopped before any DB test operation | 77 |
| `php tests/Integration/run-db-integration.php` (Wave 2) | BLOCKED — `pdo_mysql` unavailable; runner stopped before database tests | 77 |
| `php tests/Integration/run-wave3-auth-db-integration.php` (Wave 3) | BLOCKED — `pdo_mysql` unavailable; runner stopped before authentication database tests | 77 |

## Not run / blocked

- Existing Wave 2 database Integration (`tests/Integration/run-db-integration.php`) and Wave 3 database Integration (`tests/Integration/run-wave3-auth-db-integration.php`) were invoked, but each exited `77` before database tests because `pdo_mysql` is unavailable. They remain **not verified**, per `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`, and are deferred to the final comprehensive Integration stage.
- W4-P2 Registration Attempt database Integration script is prepared, but blocked by missing `pdo_mysql` (exit 77). Its assertions do not count as executions.
- No Windows PowerShell/XAMPP tests, MariaDB/MySQL transaction tests, MySQL `GET_LOCK` contention tests, concurrency stress test, live API/CSRF HTTP test, or production runtime test ran.

## Scope safety

No schema/migration, OTP Challenge, OTP generation, Telegram/Email transport, SMS implementation, account activation, recovery, W4-P3, or actual DB operation was performed. Unit/static success is not substituted for Integration success.
