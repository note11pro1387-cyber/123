# W4-P5 Test Report

**Environment:** PHP CLI 8.4.24. `PDO::getAvailableDrivers()` returned an empty array. `pdo_mysql`, MariaDB/MySQL server access, Windows PowerShell and XAMPP were unavailable in this execution environment.

## Commands and observed results

| Command | Result | Exit code |
|---|---|---:|
| `php tests/Unit/run-w4p5-unit.php` | PASS — 247 assertions | 0 |
| `php tests/Unit/run-unit.php` | PASS — 64 Wave 1 unit/static contract checks | 0 |
| `php tests/Unit/run-registration-tests.php` | PASS — 62 W4-P2 checks | 0 |
| `php tests/Unit/run-wave3-unit.php` | PASS — 20 Wave 3 checks | 0 |
| `php tests/Unit/run-w4p3-unit.php` | PASS — 1,041 W4-P3 assertions | 0 |
| `php tests/Unit/run-w4p4-unit.php` | PASS — 38 W4-P4 assertions; mock provider responses and local TLS SMTP fixtures, no external Provider contacted | 0 |
| `node tests/Unit/run-api-client-tests.mjs` | PASS — 14 central API client assertions | 0 |
| `python tools/wave1_static_checks.py` | PASS — all Wave 1 static contracts / 17 action policies | 0 |
| `python tools/wave2_static_checks.py` | PASS — 99 checks | 0 |
| `python tools/wave3_static_checks.py` | PASS — 34/34 checks | 0 |
| `python tools/wave4_p2_static_checks.py` | PASS — 39 checks | 0 |
| `python tools/wave4_p3_static_checks.py` | PASS — 31 checks | 0 |
| `python tools/wave4_p4_static_checks.py` | PASS — 32/32 checks | 0 |
| `python tools/wave4_p5_static_checks.py` | PASS — 44/44 W4-P5 checks | 0 |
| `node --check js/auth.js` | PASS — syntax check | 0 |
| `php tests/Integration/run-w4p5-recovery-integration.php` | BLOCKED — `pdo_mysql` unavailable; no database connection or mutation attempted | 77 |

`php -l` over the 16 changed/new PHP files — PASS, no syntax errors. `node --check js/auth.js` — PASS. The existing central API client test is also run separately and passed 14 assertions.

## What Unit/Static does not prove

Unit tests exercise identifier normalization, reset-token entropy/shape/hash, TTL policy, OTP lifecycle boundaries, central password policy, HTTP action contract and legacy fail-closed routes. Static checks inspect source structure. These checks do not prove MariaDB/MySQL locking, transaction rollback, foreign-key behavior, session revocation under concurrent requests, HTTP cookie behavior, or end-to-end Recovery against a configured provider.

## Integration limitation and owner decision

The database integration runner contains a strict local-test-schema guard, explicit mutation opt-in, exact `SELECT DATABASE()` identity check, latest migration/checksum validation and cleanup of synthetic fixtures. On this host it stops before connecting with exit code 77. The prior owner decision remains:

`OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`

The DB run must be completed during the comprehensive final Integration pass; it is not a PASS here.
