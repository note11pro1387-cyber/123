# W4-P6A Test Report

Environment observed: PHP CLI 8.4.24, Node.js v22.16.0, Python 3.13.5. PDO had no `pdo_mysql` driver. Tests used synthetic fixtures unless noted; no live email was sent and no database mutation was attempted.

## Executed commands

| Command | Result | Exit code |
|---|---|---:|
| `php tests/Unit/run-unit.php` | PASS — 64 checks | 0 |
| `php tests/Unit/run-registration-tests.php` | PASS — 62 W4-P2 checks | 0 |
| `php tests/Unit/run-wave3-unit.php` | PASS — 20 checks | 0 |
| `php tests/Unit/run-w4p3-unit.php` | PASS — 1,041 assertions | 0 |
| `php tests/Unit/run-w4p4-unit.php` | PASS — 38 assertions; mocks/local TLS fixture only | 0 |
| `php tests/Unit/run-w4p5-unit.php` | PASS — 247 assertions | 0 |
| `node tests/Unit/run-api-client-tests.mjs` | PASS — synthetic central API client behavior, including `202`/request ID regression | 0 |
| `node --check js/api-client.js` | PASS | 0 |
| `node --check js/auth.js` | PASS | 0 |
| `python tools/wave1_static_checks.py` | PASS | 0 |
| `python tools/wave2_static_checks.py` | PASS — 99 checks | 0 |
| `python tools/wave3_static_checks.py` | PASS — 34/34 checks | 0 |
| `python tools/wave4_p2_static_checks.py` | PASS — 39 checks | 0 |
| `python tools/wave4_p3_static_checks.py` | PASS — 31 checks | 0 |
| `python tools/wave4_p4_static_checks.py` | PASS — 32/32 checks | 0 |
| `python tools/wave4_p5_static_checks.py` | PASS — 44/44 checks | 0 |
| `python tools/w4p6a_contract_checks.py` | PASS — 29 checks | 0 |
| `php tests/Integration/run-db-integration.php` | BLOCKED — `pdo_mysql` unavailable; no database tests attempted | 77 |
| `php tests/Integration/run-wave3-auth-db-integration.php` | BLOCKED — `pdo_mysql` unavailable; no database tests attempted | 77 |
| `php tests/Integration/run-registration-attempt-integration.php` | BLOCKED — `pdo_mysql` unavailable | 77 |
| `php tests/Integration/run-w4p3-otp-integration.php` | BLOCKED — `pdo_mysql` unavailable | 77 |
| `php tests/Integration/run-w4p4-email-delivery-integration.php` | NOT RUN — live email opt-in/recipient absent; no email sent | 77 |
| `php tests/Integration/run-w4p4-telegram-link-integration.php` | BLOCKED — `pdo_mysql` unavailable | 77 |
| `php tests/Integration/run-w4p5-recovery-integration.php` | BLOCKED — `pdo_mysql` unavailable; no DB mutation attempted | 77 |

## Interpretation

P6A source contracts and locally executable unit/static suites pass. This does not prove real database locking/transactions, provider delivery, browser cookies/CSRF on a live server, concurrent Recovery, or complete registration and Recovery E2E. Those remain `BLOCKED` or `NOT RUN`, not PASS.
