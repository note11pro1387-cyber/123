# W4-P3 Database Integration Runbook

This runbook is for the final disposable-DB verification only. It does not change the owner's accepted deferral for the earlier Wave 2/Wave 3 database gate.

## Safety contract

- Use a dedicated local disposable database such as `mr_neon_w4p3_test`. Do not run against Production, an unknown database, or a database with user data.
- The integration runner checks the actual `SELECT DATABASE()` result and requires `MRNEON_DB_NAME` to equal `MRNEON_TEST_DB_NAME`, the database name to contain `test` or `sandbox`, and `MRNEON_ALLOW_W4P3_TEST_MUTATION=1`.
- The runner creates only synthetic registration attempts and their associated records. It never executes `DROP DATABASE`, `TRUNCATE`, or schema creation. Its cleanup deletes only rows using IDs generated during that run. If cleanup reports a warning, inspect the clearly named test database before any manual cleanup; do not truncate it blindly.
- The test provider is defined only in `tests/Integration/run-w4p3-otp-integration.php`. Production service defaults to `UnavailableVerificationTransport` and cannot send real messages.
- Do not place real credentials, OTP values, or generated HMAC keys in the repository, ZIP, logs, or this report.

## PowerShell / XAMPP procedure

Run from the consolidated project root. First select a known XAMPP PHP CLI executable and check it:

```powershell
& 'C:\xampp\php\php.exe' -v
& 'C:\xampp\php\php.exe' -m
```

Configure the private database connection using the project's approved local environment mechanism. Do not paste a real database password into a command that will be saved in shell history. Set these process variables for the current test session only:

```powershell
$env:MRNEON_DB_NAME = 'mr_neon_w4p3_test'
$env:MRNEON_TEST_DB_NAME = 'mr_neon_w4p3_test'
$env:MRNEON_ALLOW_W4P3_TEST_MUTATION = '1'
```

`MRNEON_OTP_HMAC_KEY` and `MRNEON_RATE_LIMIT_HMAC_KEY` must be distinct private random values, each at least 32 bytes. `MRNEON_TERMS_VERSION` must match an approved server-owned Terms/Privacy version. Do not use empty keys. Configure them privately for the current PHP process using the project's normal environment setup.

Before any migration or mutation, verify the selected database by name and by the server's actual connection:

```powershell
& 'C:\xampp\php\php.exe' -r 'require "app/bootstrap.php"; $pdo = Database::connection(); echo (string) $pdo->query("SELECT DATABASE()")->fetchColumn(), PHP_EOL;'
& 'C:\xampp\php\php.exe' bin/migrate.php status
```

Stop immediately unless the first command prints exactly `mr_neon_w4p3_test` and the migration status belongs to this disposable database. If the schema is behind, apply migrations only after that identity check:

```powershell
& 'C:\xampp\php\php.exe' bin/migrate.php apply
& 'C:\xampp\php\php.exe' bin/migrate.php check
```

Then run the Integration suite:

```powershell
& 'C:\xampp\php\php.exe' tests/Integration/run-w4p3-otp-integration.php
$LASTEXITCODE
```

Preserve the actual exit code and console result. Exit `0` is a successful database Integration run; exit `77` means the run was blocked and is not PASS; any other nonzero result is a failure. The suite checks accepted delivery, OTP rejection/attempt count, atomic account/channel/Challenge/Attempt completion, replay rejection, failed resend preserving the old code, accepted resend invalidating the old code, default transport fail-closed, and the no-blind-retry barrier for an unknown delivery result.

## Current execution environment

In the build environment used to prepare this Patch, PHP CLI is `8.4.24` and `PDO::getAvailableDrivers()` returns an empty array. The Integration runner was invoked and exited `77` before database mutation because `pdo_mysql` is not installed. No Windows/XAMPP execution or live database connection is claimed. Keep `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED` open until the owner runs the procedure above and the full final Integration gate.
