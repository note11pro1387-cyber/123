# W4-P4 Test Report

## Runtime and constraints observed

- PHP CLI: `8.4.24` (`/usr/bin/php`).
- OpenSSL and stream sockets available.
- `PDO::getAvailableDrivers()` was empty: no usable PDO database driver was available in this runtime.
- PowerShell/XAMPP, MySQL/MariaDB test server, live Telegram token, SMTP credentials/test inbox, and public HTTPS webhook were unavailable.
- No external Telegram API request, live SMTP message, webhook registration, or database mutation was performed.
- This project did not have Composer, `composer.json`, a `vendor/` tree, or PHPMailer installed. The Email adapter therefore uses a deliberately narrow native SMTP implementation (AUTH LOGIN only, STARTTLS/SMTPS only). This is not a claim of comprehensive production compatibility; see release risks and Decision Ledger D06/D13.

## Unit and contract tests — executed

| Command | Result | Exit code |
|---|---|---:|
| `php tests/Unit/run-w4p4-unit.php` | PASS — 38 assertions; fake Telegram result mapping, URL/body separation, secret redaction, `/start` parsing, fake Email result mapping, both local SMTPS and STARTTLS TLS fixtures; no external provider contacted | 0 |
| `php tests/Unit/run-unit.php` | PASS — 64 synthetic Wave 1 contract checks | 0 |
| `php tests/Unit/run-registration-tests.php` | PASS — 62 W4-P2 DTO/validation/policy checks | 0 |
| `php tests/Unit/run-wave3-unit.php` | PASS — 20 checks | 0 |
| `php tests/Unit/run-w4p3-unit.php` | PASS — 1,041 assertions | 0 |
| `node --check js/auth.js` | PASS — JavaScript syntax | 0 |

## Static checks — executed

| Command | Result | Exit code |
|---|---|---:|
| `python3 tools/wave1_static_checks.py` | PASS — all Wave 1 checks / 17 action policies | 0 |
| `python3 tools/wave2_static_checks.py` | PASS — 99 checks | 0 |
| `python3 tools/wave3_static_checks.py` | PASS — 34/34 checks | 0 |
| `python3 tools/wave4_p2_static_checks.py` | PASS — 39 checks | 0 |
| `python3 tools/wave4_p3_static_checks.py` | PASS — 31 checks | 0 |
| `python3 tools/wave4_p4_static_checks.py` | PASS — 32 checks after the explicit ambiguous-HTTP-result assertion was added | 0 |
| PHP lint over the 29 changed/new PHP entry, adapter, controller, integration-test and configuration files | PASS — no syntax errors | 0 |

## Integration tests — NOT RUN / BLOCKED

| Command | Result | Exit code |
|---|---|---:|
| `php tests/Integration/run-db-integration.php` | BLOCKED — no `pdo_mysql`; no database test attempted | 77 |
| `php tests/Integration/run-wave3-auth-db-integration.php` | BLOCKED — no `pdo_mysql`; no database test attempted | 77 |
| `php tests/Integration/run-registration-attempt-integration.php` | BLOCKED — no `pdo_mysql`; no database test attempted | 77 |
| `php tests/Integration/run-w4p3-otp-integration.php` | BLOCKED — no `pdo_mysql`; no database test attempted | 77 |
| `php tests/Integration/run-w4p4-telegram-link-integration.php` | BLOCKED — no `pdo_mysql`; no database test attempted | 77 |
| `php tests/Integration/run-w4p4-email-delivery-integration.php` | NOT RUN — explicit owner opt-in, test inbox and valid SMTP config were absent; no email was sent | 77 |

## Provider-specific status

- Telegram Unit tests use fake HTTP responses; no bot token was supplied and no Telegram request was made.
- The local SMTP fixtures use temporary test certificates and a local loopback server. They test protocol/TLS negotiation only, not a commercial SMTP service, inbox receipt, or message reading.
- Live `sendMessage`, SMTP delivery, `setWebhook`/`deleteWebhook`, external HTTPS reachability, and the complete DB-backed linking/idempotency flow remain `NOT RUN`.
- The owner decision remains: `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`. This report does not upgrade Wave 2/3 DB integration to PASS.

## Interpretation

Unit/static/lint results above are actual test executions. They do not prove database locks, transaction rollback, `update_id` idempotency under concurrency, a real Telegram provider response, real SMTP account compatibility, or public webhook deployment. W4-P4 is `PASS WITH NOTES`, not live-provider acceptance.
